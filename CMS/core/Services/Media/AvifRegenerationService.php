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
        // Release-Prüfung 4.1.0 (FUN-410-18): Ein Fatal Error (Speicher, Zeitlimit) beendete den Lauf, bevor der Cursor
        // gespeichert war; jeder Folgelauf blieb am selben Bild hängen. Die Absturzmarke nennt das Bild in Arbeit.
        $jobId = (int) ($job['id'] ?? 0);
        $crashed = $this->readCrashMarker($jobId);
        if ($crashed !== null && $crashed === $cursor && $cursor < count($paths)) {
            $counts['failed']++;
            $failed++;
            $errors[] = $paths[$cursor] . ': ' . self::translate('media_tools.avif.aborted');
            $cursor++;
        }
        while ($cursor < count($paths) && $processed < self::STEP_ITEMS && ($processed === 0 || microtime(true) - $started < $seconds)) {
            $path = $paths[$cursor];
            $this->writeCrashMarker($jobId, $cursor);
            $cursor++;
            $processed++;
            $last = $path;
            try {
                $tooLarge = self::exceedsMemoryBudget($path);
                if ($tooLarge !== null) {
                    $result = ['detail' => $tooLarge];
                    $status = 'failed';
                } else {
                    $result = $process($path);
                    $status = (string) ($result['status'] ?? 'failed');
                }
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

        $this->clearCrashMarker();
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

    /**
     * Release-Prüfung 4.1.0 (FUN-410-18): Bilder, deren entpackte Pixel (4 Byte je Pixel, Faktor 2 für Quelle und
     * Ziel) den freien Speicher übersteigen, werden übersprungen statt den Lauf per Fatal Error zu beenden.
     */
    public static function exceedsMemoryBudget(string $path, ?int $limitBytes = null): ?string
    {
        $file = str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1 ? $path : ABSPATH . 'uploads/' . ltrim($path, '/');
        $info = is_file($file) ? @getimagesize($file) : false;
        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            return null;
        }
        $limit = $limitBytes ?? self::memoryLimitBytes();
        if ($limit <= 0) {
            return null;
        }
        $needed = (int) $info[0] * (int) $info[1] * 4 * 2;
        $free = $limit - memory_get_usage(true);

        return $needed > $free
            ? sprintf('%d x %d Pixel brauchen etwa %d MB, frei sind %d MB (memory_limit)', (int) $info[0], (int) $info[1], (int) ceil($needed / 1048576), (int) max(0, floor($free / 1048576)))
            : null;
    }

    private static function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return 0;
        }
        $value = (int) $raw;
        $unit = strtolower(substr($raw, -1));

        return match ($unit) {
            'g' => $value * 1073741824,
            'm' => $value * 1048576,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private static function crashMarkerFile(): string
    {
        return ABSPATH . 'cache/avif-regeneration.current';
    }

    private function writeCrashMarker(int $jobId, int $index): void
    {
        if ($jobId > 0 && $this->processor === null) {
            @file_put_contents(self::crashMarkerFile(), $jobId . ':' . $index, LOCK_EX);
        }
    }

    private function readCrashMarker(int $jobId): ?int
    {
        $raw = is_file(self::crashMarkerFile()) ? (string) @file_get_contents(self::crashMarkerFile()) : '';
        if (preg_match('/^(\d+):(\d+)$/', trim($raw), $m) !== 1 || (int) $m[1] !== $jobId) {
            return null;
        }

        return (int) $m[2];
    }

    private function clearCrashMarker(): void
    {
        if (is_file(self::crashMarkerFile())) {
            @unlink(self::crashMarkerFile());
        }
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
