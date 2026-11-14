<?php
/**
 * „Verwendungsindex neu aufbauen“ (4.1.0, Bereich 2b) als `BatchJobService`-Auftrag (`media_usage`): alle
 * Inhaltsarten der registrierten Extraktoren, je Schritt höchstens 50 Inhalte, mit Fortschritt und Abbruch unter
 * Medien → Medien-Werkzeuge; ein verlassener Lauf wird im stündlichen Cron fortgesetzt.
 *
 * @package CMSv2\Core\Services\Media
 */

declare(strict_types=1);

namespace CMS\Services\Media;

use CMS\Services\BatchJobService;

if (!defined('ABSPATH')) {
    exit;
}

final class MediaUsageRebuildService
{
    public const JOB_TYPE = 'media_usage';
    public const JOB_SCOPE = '';
    public const STEP_ITEMS = 50;
    public const CRON_SECONDS = 45.0;

    private static ?self $instance = null;

    private ?MediaUsageIndex $index = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /** Für Tests: anderen Index verwenden. */
    public function setIndex(?MediaUsageIndex $index): void
    {
        $this->index = $index;
    }

    /**
     * @return array{job: array<string, mixed>, resumed: bool}
     */
    public function start(?int $userId): array
    {
        $jobs = BatchJobService::getInstance();
        $active = $jobs->findActive(self::JOB_TYPE, self::JOB_SCOPE);
        if ($active !== null) {
            return ['job' => $active, 'resumed' => true];
        }

        return $jobs->start(self::JOB_TYPE, self::JOB_SCOPE, $this->estimateTotal(), [
            'types' => array_keys($this->index()->extractors()),
            'type_index' => 0,
            'after_id' => -1,
            'started_at' => date('Y-m-d H:i:s'),
            'indexed' => 0,
        ], $userId);
    }

    /** @return array<string, mixed> */
    public function step(int $jobId): array
    {
        $jobs = BatchJobService::getInstance();
        $job = $jobs->find($jobId);
        if ($job === null || $job['type'] !== self::JOB_TYPE) {
            throw new \InvalidArgumentException('Unbekannter Auftrag.');
        }

        return $jobs->step($jobId, fn (array $job): array => $this->runStep($job));
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

    public function continueInCron(float $budget = self::CRON_SECONDS): int
    {
        $job = $this->activeJob();
        if ($job === null) {
            return 0;
        }
        $deadline = microtime(true) + $budget;
        $before = (int) $job['processed'];
        while (microtime(true) < $deadline) {
            $job = $this->step((int) $job['id']);
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
    public function runStep(array $job): array
    {
        $state = is_array($job['state'] ?? null) ? $job['state'] : [];
        $result = $this->index()->rebuildStep($state, self::STEP_ITEMS);
        $next = $result['state'];
        $next['indexed'] = (int) ($state['indexed'] ?? 0) + $result['processed'];

        return [
            'processed' => $result['processed'],
            'failed' => 0,
            'errors' => [],
            'state' => $next,
            'done' => $result['done'],
            'message' => $result['done']
                ? sprintf(self::translate('media_tools.usage.summary'), $next['indexed'])
                : sprintf(self::translate('media_tools.usage.progress'), (string) ($next['types'][$next['type_index']] ?? '')),
        ];
    }

    /** Gesamtzahl der Inhalte für die Fortschrittsanzeige (nur IDs, in Blöcken à 1000, höchstens 200.000). */
    private function estimateTotal(): int
    {
        $total = 0;
        foreach ($this->index()->extractors() as $extractor) {
            try {
                $after = -1;
                while ($total < 200_000) {
                    $ids = $extractor->ids($after, 1000);
                    if ($ids === []) {
                        break;
                    }
                    $total += count($ids);
                    $after = (int) end($ids);
                }
            } catch (\Throwable) {
            }
        }

        return max(1, $total);
    }

    private function index(): MediaUsageIndex
    {
        return $this->index ?? MediaUsageIndex::getInstance();
    }

    private static function translate(string $key): string
    {
        return function_exists('__') ? (string) __($key) : $key;
    }
}
