<?php
/**
 * „AVIF nachgenerieren“ (4.1.0, Bereich 2a) für alle Bilder der Mediathek – als `BatchJobService`-Auftrag
 * (`media_avif`) in Schritten à 20 Bilder, mit Fortschritt und Abbruch unter Medien → Medien-Werkzeuge.
 *
 * Die Liste der Originalbilder wird beim Start ermittelt (wie bei „Vorschaubilder neu erzeugen“). Jeder Schritt ist
 * idempotent: Vorhandene, aktuelle AVIF-Dateien bleiben stehen; ein abgebrochener oder wiederholter Lauf erzeugt
 * nichts doppelt. Wird die Seite geschlossen, setzt der stündliche Cron den Auftrag fort.
 *
 * @package CMSv2\Core\Services\Media
 */

declare(strict_types=1);

namespace CMS\Services\Media;

use CMS\Services\BatchJobService;
use CMS\Services\MediaService;

if (!defined('ABSPATH')) {
    exit;
}

final class AvifRegenerationService
{
    public const JOB_TYPE = 'media_avif';
    public const JOB_SCOPE = '';
    public const MAX_IMAGES = ThumbnailRegenerationService::MAX_IMAGES;
    public const STEP_ITEMS = 20;
    public const STEP_SECONDS = 8.0;
    public const CRON_SECONDS = 45.0;

    private static ?self $instance = null;

    /** @var (callable(string): array<string, mixed>)|null */
    private $processor = null;

    /** @var (callable(): list<string>)|null */
    private $candidates = null;

    private ?BatchJobService $jobs = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Für Tests: Verarbeitung, Kandidatenliste und Auftragsdienst ersetzen.
     *
     * @param (callable(string): array<string, mixed>)|null $processor
     * @param (callable(): list<string>)|null $candidates
     */
    public function setDependencies(?callable $processor, ?callable $candidates = null, ?BatchJobService $jobs = null): void
    {
        $this->processor = $processor;
        $this->candidates = $candidates;
        $this->jobs = $jobs;
    }

    public function countImages(): int
    {
        return count($this->collect());
    }

    /**
     * @return array{job: array<string, mixed>, resumed: bool}
     */
    public function start(?int $userId): array
    {
        $jobs = $this->jobs();
        $active = $jobs->findActive(self::JOB_TYPE, self::JOB_SCOPE);
        if ($active !== null) {
            return ['job' => $active, 'resumed' => true];
        }
        $paths = $this->collect();

        return $jobs->start(self::JOB_TYPE, self::JOB_SCOPE, count($paths), [
            'paths' => $paths,
            'cursor' => 0,
            'counts' => ['done' => 0, 'skipped' => 0, 'failed' => 0],
        ], $userId);
    }

    /** @return array<string, mixed> */
    public function step(int $jobId, float $seconds = self::STEP_SECONDS): array
    {
        $jobs = $this->jobs();
        $job = $jobs->find($jobId);
        if ($job === null || $job['type'] !== self::JOB_TYPE) {
            throw new \InvalidArgumentException('Unbekannter Auftrag.');
        }

        return $jobs->step($jobId, fn (array $job): array => $this->runStep($job, $seconds));
    }

    /** @return array<string, mixed>|null */
    public function cancel(int $jobId): ?array
    {
        $jobs = $this->jobs();
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
        return $this->jobs()->findActive(self::JOB_TYPE, self::JOB_SCOPE);
    }

    /** @return array<string, mixed>|null */
    public function latestJob(): ?array
    {
        return $this->jobs()->latest(self::JOB_TYPE, self::JOB_SCOPE);
    }

    /** @return int verarbeitete Bilder */
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
     * Ein Schritt: höchstens STEP_ITEMS Bilder (mindestens eins, auch bei knapper Zeit).
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    public function runStep(array $job, float $seconds): array
    {
        $state = is_array($job['state'] ?? null) ? $job['state'] : [];
        $paths = array_values(array_map('strval', (array) ($state['paths'] ?? [])));
        $cursor = max(0, (int) ($state['cursor'] ?? 0));
        $counts = array_merge(['done' => 0, 'skipped' => 0, 'failed' => 0], (array) ($state['counts'] ?? []));
        $process = $this->processor ?? static fn (string $path): array => MediaService::getInstance()->processAvifJobItem($path);

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
                $result = $process($path);
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
            $state['paths'] = [];
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
            'errors' => $errors,
            'state' => $state,
            'done' => $done,
            'message' => $done
                ? sprintf(self::translate('media_tools.avif.summary'), $counts['done'], $counts['skipped'], $counts['failed'])
                : ($last !== '' ? sprintf(self::translate('media_tools.thumbs.progress'), $last) : ''),
        ];
    }

    /** @return list<string> */
    private function collect(): array
    {
        $candidates = $this->candidates
            ?? static fn (): array => MediaService::getInstance()->collectImageDerivativeProcessingCandidates(self::MAX_IMAGES);

        return array_values(array_filter(
            array_map('strval', $candidates()),
            static fn (string $path): bool => in_array(strtolower((string) pathinfo($path, PATHINFO_EXTENSION)), AvifDerivativeService::SOURCE_EXTENSIONS, true)
        ));
    }

    private function jobs(): BatchJobService
    {
        return $this->jobs ?? BatchJobService::getInstance();
    }

    private static function translate(string $key): string
    {
        return function_exists('__') ? (string) __($key) : $key;
    }
}
