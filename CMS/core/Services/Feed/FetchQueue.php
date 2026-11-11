<?php
/**
 * Abruf-Queue des Feed-Aggregators: fällige Quellen einreihen, blockweise abarbeiten, Protokoll bereinigen.
 *
 * Ein Lauf ist über eine Datenbank-Sperre (`GET_LOCK`) exklusiv; Aufträge werden zusätzlich per Claim-Token
 * beansprucht, hängende Aufträge nach 20 Minuten erneut eingereiht.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Feed;

use CMS\Database;

if (!defined('ABSPATH')) {
    exit;
}

final class FetchQueue
{
    private const STALE_MINUTES = 20;

    public function __construct(
        private readonly AggregatorRepository $repository,
        private readonly SourceFetcher $fetcher,
    ) {
    }

    /**
     * @param array{force?:bool,limit?:int} $context `force` = alle aktiven Quellen statt nur fälliger
     * @return array<string, mixed>
     */
    public function tick(array $context = []): array
    {
        $result = [
            'executed' => false,
            'mode' => 'feed-cron-tick',
            'locked' => false,
            'queued' => 0,
            'requeued' => 0,
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'new_items' => 0,
            'cleaned_up' => 0,
        ];

        if (!$this->acquireLock()) {
            $result['locked'] = true;

            return $result;
        }

        try {
            $result['executed'] = true;
            $result['requeued'] = $this->repository->releaseStale(self::STALE_MINUTES);
            $force = !empty($context['force']);
            $result['queued'] = $this->repository->enqueue(
                $force ? $this->repository->activeSourceIds() : $this->repository->dueSourceIds(),
                $force ? 'manual' : 'cron'
            );

            $limit = isset($context['limit']) ? (int) $context['limit'] : (int) AggregatorSettings::get('cron_batch_size');
            foreach ($this->repository->claim(max(1, min(25, $limit))) as $task) {
                $outcome = $this->runTask($task);
                $result['processed']++;
                $result[$outcome['success'] ? 'success' : 'failed']++;
                $result['new_items'] += $outcome['new_items'];
            }

            $result['cleaned_up'] = $this->repository->cleanupQueue((int) AggregatorSettings::get('log_retention_days'))
                + $this->repository->pruneItems((int) AggregatorSettings::get('item_retention_days'));
        } finally {
            $this->releaseLock();
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $task
     * @return array{success:bool,new_items:int,filtered:int,error:?string}
     */
    public function runTask(array $task): array
    {
        $outcome = $this->fetcher->fetch((int) $task['source_id']);
        $this->repository->finishTask((int) $task['id'], $outcome['success'], $outcome['new_items'], $outcome['error']);

        return $outcome;
    }

    /**
     * Sofortabruf einer Quelle (Admin-Aktion, Batch) mit Eintrag im Abrufprotokoll.
     *
     * @return array{success:bool,new_items:int,filtered:int,error:?string}
     */
    public function fetchNow(int $sourceId, string $trigger = 'manual'): array
    {
        $outcome = $this->fetcher->fetch($sourceId);
        if ($this->repository->source($sourceId) !== null) {
            $this->repository->logFetch($sourceId, $trigger, $outcome['success'], $outcome['new_items'], $outcome['error']);
        }

        return $outcome;
    }

    private function lockName(): string
    {
        return substr(Database::instance()->getPrefix() . 'feeds_fetch_tick', 0, 64);
    }

    private function acquireLock(): bool
    {
        try {
            return (int) Database::instance()->get_var('SELECT GET_LOCK(?, 0)', [$this->lockName()]) === 1;
        } catch (\Throwable) {
            return true;
        }
    }

    private function releaseLock(): void
    {
        try {
            Database::instance()->get_var('SELECT RELEASE_LOCK(?)', [$this->lockName()]);
        } catch (\Throwable) {
        }
    }
}
