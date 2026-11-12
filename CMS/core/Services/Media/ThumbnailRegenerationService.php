<?php
/**
 * Vorschaubilder (und optional WebP-Fassungen) aller Bilder der Mediathek neu erzeugen – nötig, nachdem
 * sich die Bildgrößen unter Medien → Einstellungen geändert haben.
 *
 * Läuft als `BatchJobService`-Auftrag (`media_thumbnails`): Die Liste der Bilder wird beim Start ermittelt,
 * je Schritt werden höchstens STEP_ITEMS Bilder bzw. STEP_SECONDS Sekunden verarbeitet. Die Seite
 * Medien → Medien-Werkzeuge treibt den Auftrag im Browser voran; wird sie geschlossen, setzt der stündliche
 * Cron ihn fort (`continueInCron()`), bis alle Bilder verarbeitet sind.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Media;

use CMS\Services\BatchJobService;
use CMS\Services\MediaService;

if (!defined('ABSPATH')) {
    exit;
}

final class ThumbnailRegenerationService
{
    public const JOB_TYPE = 'media_thumbnails';
    public const JOB_SCOPE = '';
    public const MAX_IMAGES = 20000;
    public const STEP_ITEMS = 8;
    public const STEP_SECONDS = 8.0;
    /** Cron: Gesamtzeit je Lauf. */
    public const CRON_SECONDS = 45.0;

    private static ?self $instance = null;

    /** @var (callable(string, bool, bool): array<string, mixed>)|null */
    private $processor = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /** Für Tests: Verarbeitung eines Bildes ersetzen. */
    public function setProcessor(?callable $processor): void
    {
        $this->processor = $processor;
    }

    /**
     * Konfigurierte Bildgrößen (Name => [Breite, Höhe]).
     *
     * @return array<string, array{0:int,1:int}>
     */
    public function sizes(): array
    {
        $settings = MediaService::getInstance()->getSettings();
        $sizes = [];
        foreach (['small', 'medium', 'large', 'banner'] as $name) {
            $sizes[$name] = [(int) ($settings['thumb_' . $name . '_w'] ?? 0), (int) ($settings['thumb_' . $name . '_h'] ?? 0)];
        }

        return $sizes;
    }

    /** Anzahl der Originalbilder (ohne erzeugte Fassungen). */
    public function countImages(): int
    {
        return count(MediaService::getInstance()->collectImageDerivativeProcessingCandidates(self::MAX_IMAGES));
    }

    /**
     * Startet den Auftrag oder liefert den laufenden (`resumed` = true).
     *
     * @return array{job: array<string, mixed>, resumed: bool}
     */
    public function start(bool $webp, ?int $userId): array
    {
        $jobs = BatchJobService::getInstance();
        $active = $jobs->findActive(self::JOB_TYPE, self::JOB_SCOPE);
        if ($active !== null) {
            return ['job' => $active, 'resumed' => true];
        }

        $paths = MediaService::getInstance()->collectImageDerivativeProcessingCandidates(self::MAX_IMAGES);

        return $jobs->start(self::JOB_TYPE, self::JOB_SCOPE, count($paths), [
            'paths' => $paths,
            'cursor' => 0,
            'webp' => $webp,
            'sizes' => $this->sizes(),
            'counts' => ['done' => 0, 'skipped' => 0, 'failed' => 0],
        ], $userId);
    }

    /** @return array<string, mixed> */
    public function step(int $jobId, float $seconds = self::STEP_SECONDS): array
    {
        $jobs = BatchJobService::getInstance();
        $job = $jobs->find($jobId);
        if ($job === null || $job['type'] !== self::JOB_TYPE) {
            throw new \InvalidArgumentException('Unbekannter Auftrag.');
        }

        return $jobs->step($jobId, fn (array $job): array => $this->runStep($job, $seconds));
    }

    /** @return array<string, mixed>|null */
    public function cancel(int $jobId): ?array
    {
        $jobs = BatchJobService::getInstance();
        $job = $jobs->find($jobId);
        if ($job === null || $job['type'] !== self::JOB_TYPE) {
            return null;
        }
        $jobs->cancel($jobId);

        return $jobs->find($jobId);
    }

    /** @return array<string, mixed>|null */
    public function activeJob(): ?array
    {
        return BatchJobService::getInstance()->findActive(self::JOB_TYPE, self::JOB_SCOPE);
    }

    /** @return array<string, mixed>|null */
    public function latestJob(): ?array
    {
        return BatchJobService::getInstance()->latest(self::JOB_TYPE, self::JOB_SCOPE);
    }

    /**
     * Setzt einen laufenden Auftrag im Cron fort (höchstens CRON_SECONDS). Läuft gerade ein Schritt im
     * Browser, meldet BatchJobService `busy`; dann bleibt der Cron untätig.
     *
     * @return int verarbeitete Bilder
     */
    public function continueInCron(float $budget = self::CRON_SECONDS): int
    {
        $job = $this->activeJob();
        if ($job === null) {
            return 0;
        }
        $deadline = microtime(true) + $budget;
        $before = (int) $job['processed'];
        while (microtime(true) < $deadline) {
            $job = $this->step((int) $job['id'], min(self::STEP_SECONDS, max(1.0, $deadline - microtime(true))));
            if (!empty($job['busy']) || !in_array($job['status'], [BatchJobService::STATUS_PENDING, BatchJobService::STATUS_RUNNING], true)) {
                break;
            }
        }

        return max(0, (int) $job['processed'] - $before);
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function runStep(array $job, float $seconds): array
    {
        $state = is_array($job['state'] ?? null) ? $job['state'] : [];
        $paths = array_values(array_map('strval', (array) ($state['paths'] ?? [])));
        $cursor = max(0, (int) ($state['cursor'] ?? 0));
        $webp = !empty($state['webp']);
        $counts = array_merge(['done' => 0, 'skipped' => 0, 'failed' => 0], (array) ($state['counts'] ?? []));
        $process = $this->processor ?? static fn (string $path, bool $webp, bool $thumbs): array
            => MediaService::getInstance()->processImageDerivativeJobItem($path, $webp, $thumbs);

        $started = microtime(true);
        $processed = 0;
        $failed = 0;
        $errors = [];
        $last = '';
        while ($cursor < count($paths) && $processed < self::STEP_ITEMS && ($processed === 0 || microtime(true) - $started < $seconds)) {
            $path = $paths[$cursor];
            $cursor++;
            $processed++;
            $last = $path;
            try {
                $result = $process($path, $webp, true);
                $status = (string) ($result['status'] ?? 'failed');
            } catch (\Throwable $e) {
                $result = ['detail' => $e->getMessage()];
                $status = 'failed';
            }
            if ($status === 'failed') {
                $failed++;
                $counts['failed']++;
                $errors[] = $path . ': ' . mb_substr((string) ($result['detail'] ?? ''), 0, 200);
            } elseif ($status === 'skipped') {
                $counts['skipped']++;
            } else {
                $counts['done']++;
            }
        }

        $state['cursor'] = $cursor;
        $state['counts'] = $counts;
        $done = $cursor >= count($paths);
        if ($done) {
            // Die Pfadliste wird nach dem Lauf nicht mehr gebraucht.
            $state['paths'] = [];
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
            'errors' => $errors,
            'state' => $state,
            'done' => $done,
            'message' => $done
                ? sprintf((string) __('media_tools.thumbs.summary'), $counts['done'], $counts['skipped'], $counts['failed'])
                : ($last !== '' ? sprintf((string) __('media_tools.thumbs.progress'), $last) : ''),
        ];
    }
}
