<?php
declare(strict_types=1);

namespace CMS\Services;

use CMS\Bootstrap;
use CMS\Hooks;

if (!defined('ABSPATH')) {
    exit;
}

final class CronRunnerService
{
    private static ?self $instance = null;
    private const HOURLY_CRON_EXPRESSION = '0 * * * *';
    private const DAILY_CRON_EXPRESSION = '15 2 * * *';

    /**
     * @return list<string>
     */
    public function getSupportedTasks(): array
    {
        return ['all', 'mail-queue', 'hourly', 'daily', 'feeds', 'cms_cron_mail_queue', 'cms_cron_hourly', 'cms_cron_daily', 'cms_cron_feeds'];
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * @param array{task?:mixed,limit?:mixed,force?:mixed,mode?:mixed,source?:mixed} $options
        * @return array{success:bool,task:string,mode:string,source:string,result:array<string,mixed>,error?:string,error_code?:string}
     */
    public function run(array $options = []): array
    {
        $task = $this->normalizeTask($options['task'] ?? 'all');
        $limit = $this->normalizeLimit($options['limit'] ?? null);
        $force = !empty($options['force']);
        $mode = trim((string) ($options['mode'] ?? (PHP_SAPI === 'cli' ? 'cli' : 'web')));
        $mode = $mode !== '' ? $mode : (PHP_SAPI === 'cli' ? 'cli' : 'web');
        $source = trim((string) ($options['source'] ?? 'cron-runner'));
        $source = $source !== '' ? $source : 'cron-runner';

        $result = [
            'mail_queue' => null,
            'mail_queue_after_hourly' => null,
            'hourly' => null,
            'daily' => null,
            'feeds' => null,
            'feed_queue_recovery' => null,
            'feed_queue' => null,
            'hook' => null,
        ];

        $supportedTasks = $this->getSupportedTasks();
        $isGenericCronHook = str_starts_with($task, 'cms_cron_');
        if (!$isGenericCronHook && !in_array($task, $supportedTasks, true)) {
            return [
                'success' => false,
                'task' => $task,
                'mode' => $mode,
                'source' => $source,
                'result' => $result,
                'error_code' => 'invalid_task',
                'error' => 'Unbekannte Cron-Task. Unterstützt werden aktuell "all", "mail-queue", "hourly", "daily", "feeds" und generische "cms_cron_*"-Hooks.',
            ];
        }

        $lockHandle = $this->acquireLock();
        if ($lockHandle === false) {
            // Sperrdatei nicht anlegbar: nicht als „bereits aktiv“ melden, sonst laufen Aufgaben unbemerkt nie.
            $this->recordRunStatus(false, $task, 'Sperrdatei nicht anlegbar');
            $lockError = function_exists('__') ? (string) __('cron.runner.lock_unavailable') : '';

            return [
                'success' => false,
                'task' => $task,
                'mode' => $mode,
                'source' => $source,
                'result' => $result,
                'error_code' => 'lock_unavailable',
                'error' => $lockError !== '' && $lockError !== 'cron.runner.lock_unavailable'
                    ? $lockError
                    : 'Cron-Sperrdatei konnte nicht angelegt werden. Details wurden intern protokolliert.',
            ];
        }

        if ($lockHandle === null) {
            return [
                'success' => false,
                'task' => $task,
                'mode' => $mode,
                'source' => $source,
                'result' => $result,
                'error_code' => 'lock_active',
                'error' => 'Cron-Lauf bereits aktiv.',
            ];
        }

        try {
            Bootstrap::instance();

            $queue = MailQueueService::getInstance();
            $settings = SettingsService::getInstance();
            $isMailQueueTask = in_array($task, ['mail-queue', 'cms_cron_mail_queue'], true);
            $isHourlyTask = in_array($task, ['hourly', 'cms_cron_hourly'], true);
            $isDailyTask = in_array($task, ['daily', 'cms_cron_daily'], true);
            $isFeedTask = in_array($task, ['feeds', 'cms_cron_feeds'], true);
            $isAllTask = $task === 'all';
            $shouldRunLegacyHourlyBridge = $task === 'mail-queue'
                && $settings->getBool('cron', 'mail_queue_triggers_hourly', true);
            $shouldRunLegacyDailyBridge = $task === 'mail-queue'
                && $settings->getBool('cron', 'mail_queue_triggers_daily', true);

            if ($isMailQueueTask || $isAllTask) {
                $result['mail_queue'] = $queue->handleCronHook([
                    'limit' => $limit,
                    'force' => $force,
                ]);

                Hooks::doAction('cms_cron_mail_queue', [
                    'limit' => $limit,
                    'force' => $force,
                    'source' => $source,
                    'mode' => $mode,
                    'mail_queue_already_handled' => true,
                ]);
            }

            if ($isHourlyTask || $isAllTask || $shouldRunLegacyHourlyBridge) {
                $result['hourly'] = $this->runHourlyHooks($settings, $force);
                if ($shouldRunLegacyHourlyBridge && is_array($result['hourly'])) {
                    $result['hourly']['compatibility_mode'] = 'mail_queue_triggers_hourly';
                }
            }

            if ($isDailyTask || $isAllTask || $shouldRunLegacyDailyBridge) {
                $result['daily'] = $this->runDailyHooks($settings, $force);
                if ($shouldRunLegacyDailyBridge && is_array($result['daily'])) {
                    $result['daily']['compatibility_mode'] = 'mail_queue_triggers_daily';
                }
            }

            if (is_array($result['hourly'])
                && !empty($result['hourly']['executed'])
                && ($queue->isEnabled() || $force)) {
                $result['mail_queue_after_hourly'] = $queue->handleCronHook([
                    'limit' => $limit,
                    'force' => $force,
                ]);
            }

            // Die Feed-Abruf-Queue des Cores hängt an `cms_cron_mail_queue`/`cms_cron_hourly` (FeatureHooks\FeedHooks)
            // und setzt hängende Aufträge selbst zurück; `feed_queue*` bleiben für die Ergebnisstruktur erhalten.

            if ($isFeedTask) {
                $result['feeds'] = $this->runFeedCronTask($limit, $force, $mode, $source);
            }

            if ($isGenericCronHook && !in_array($task, ['cms_cron_mail_queue', 'cms_cron_hourly', 'cms_cron_daily', 'cms_cron_feeds'], true)) {
                $result['hook'] = $this->runGenericHook($task, $limit, $force, $mode, $source);
            }

            $success = true;
            if (is_array($result['mail_queue']) && array_key_exists('success', $result['mail_queue'])) {
                $success = $success && !empty($result['mail_queue']['success']);
            }
            if (is_array($result['mail_queue_after_hourly']) && array_key_exists('success', $result['mail_queue_after_hourly'])) {
                $success = $success && !empty($result['mail_queue_after_hourly']['success']);
            }
            if (is_array($result['hourly']) && array_key_exists('success', $result['hourly'])) {
                $success = $success && !empty($result['hourly']['success']);
            }
            if (is_array($result['daily']) && array_key_exists('success', $result['daily'])) {
                $success = $success && !empty($result['daily']['success']);
            }
            if (is_array($result['feeds']) && array_key_exists('success', $result['feeds'])) {
                $success = $success && !empty($result['feeds']['success']);
            }
            if (is_array($result['hook']) && array_key_exists('success', $result['hook'])) {
                $success = $success && !empty($result['hook']['success']);
            }
            if (is_array($result['feed_queue']) && array_key_exists('success', $result['feed_queue'])) {
                $success = $success && !empty($result['feed_queue']['success']);
            }
            if (is_array($result['feed_queue_recovery']) && array_key_exists('success', $result['feed_queue_recovery'])) {
                $success = $success && !empty($result['feed_queue_recovery']['success']);
            }

            $failedSteps = [];
            foreach ($result as $step => $stepResult) {
                if (is_array($stepResult) && array_key_exists('success', $stepResult) && empty($stepResult['success'])) {
                    $failedSteps[] = (string) $step;
                }
            }
            $this->recordRunStatus($success, $task, $failedSteps === [] ? '' : 'Fehlgeschlagene Schritte: ' . implode(', ', $failedSteps));

            return [
                'success' => $success,
                'task' => $task,
                'mode' => $mode,
                'source' => $source,
                'result' => $result,
            ];
        } catch (\Throwable $e) {
            \CMS\Logger::instance()->withChannel('cron')->error('Cron execution failed.', [
                'exception_class' => get_class($e),
                'exception_message' => $this->truncateForLog($e->getMessage()),
                'file' => $this->truncateForLog($e->getFile(), 220),
                'line' => (int) $e->getLine(),
                'task' => $task,
                'mode' => $mode,
                'source' => $source,
            ]);

            $this->recordRunStatus(false, $task, 'Ausnahme: ' . get_class($e));

            return [
                'success' => false,
                'task' => $task,
                'mode' => $mode,
                'source' => $source,
                'result' => $result,
                'error_code' => 'exception',
                'error' => 'Cron-Lauf fehlgeschlagen. Details wurden intern protokolliert.',
            ];
        } finally {
            $this->releaseLock($lockHandle);
        }
    }

    /**
     * Letzten Cron-Lauf für Systemhinweise festhalten (Gruppe `cron`: last_run_at/_status/_task/last_error).
     */
    private function recordRunStatus(bool $success, string $task, string $error): void
    {
        try {
            $settings = SettingsService::getInstance();
            $settings->set('cron', 'last_run_at', (string) time());
            $settings->set('cron', 'last_run_status', $success ? 'ok' : 'failed');
            $settings->set('cron', 'last_run_task', $task);
            $settings->set('cron', 'last_error', $success ? '' : $this->truncateForLog($error, 300));
        } catch (\Throwable) {
            // Statusprotokoll darf den Cron-Lauf nie beeinträchtigen.
        }
    }

    private function normalizeTask(mixed $task): string
    {
        $task = strtolower(trim((string) $task));
        if ($task === '' || $task === 'default') {
            return 'all';
        }

        if (str_starts_with($task, 'hook:')) {
            $task = substr($task, 5);
        }

        $task = preg_replace('/[^a-z0-9_-]+/', '', $task) ?? '';

        return $task !== '' ? $task : 'all';
    }

    private function normalizeLimit(mixed $limit): ?int
    {
        if ($limit === null || $limit === '') {
            return null;
        }

        $normalized = filter_var($limit, FILTER_VALIDATE_INT);
        if ($normalized === false) {
            return null;
        }

        return min(100, max(1, (int) $normalized));
    }

    /**
     * Sperre unter cache/ (wie die Update-Sperre): unabhängig von sys_get_temp_dir() und open_basedir.
     *
     * @return resource|false|null Handle; false = Sperrdatei nicht anlegbar (protokolliert); null = Sperre belegt
     */
    private function acquireLock()
    {
        $lockDirectory = rtrim((string) ABSPATH, '/\\') . DIRECTORY_SEPARATOR . 'cache';
        $lockFile = $lockDirectory . DIRECTORY_SEPARATOR . '.cron-run.lock';
        $lockHandle = (is_dir($lockDirectory) || @mkdir($lockDirectory, 0755, true) || is_dir($lockDirectory))
            ? @fopen($lockFile, 'c+')
            : false;
        if (!is_resource($lockHandle)) {
            \CMS\Logger::instance()->withChannel('cron')->error('Cron lock file could not be opened.', ['path' => $lockFile]);

            return false;
        }

        if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
            @fclose($lockHandle);

            return null;
        }

        return $lockHandle;
    }

    /**
     * @param resource|null $lockHandle
     */
    private function releaseLock($lockHandle): void
    {
        if (!is_resource($lockHandle)) {
            return;
        }

        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }

    private function runHourlyHooks(SettingsService $settings, bool $forceRun): array
    {
        $scheduleExpression = $this->getHourlyScheduleExpression($settings);
        $cronAdapter = CronExpressionAdapter::getInstance();
        $lastRunRaw = $settings->getString('cron', 'hourly_last_run', '');
        $lastRunTs = $lastRunRaw !== '' ? strtotime($lastRunRaw) : false;
        $isDueResult = $this->resolveHourlyDueState($cronAdapter, $scheduleExpression, $lastRunTs, $forceRun);

        if (!$isDueResult['is_due']) {
            return [
                'success' => true,
                'executed' => false,
                'skipped' => true,
                'reason' => 'Stündlicher Hook ist noch nicht fällig.',
                'last_run' => $lastRunRaw,
                'next_due_in_seconds' => $isDueResult['next_due_in_seconds'],
                'schedule_expression' => $scheduleExpression,
                'scheduler' => $isDueResult['scheduler'],
            ];
        }

        Hooks::doAction('cms_cron_hourly');

        $executedAt = date('Y-m-d H:i:s');
        $settings->set('cron', 'hourly_last_run', $executedAt, false, 0);

        return [
            'success' => true,
            'executed' => true,
            'skipped' => false,
            'executed_at' => $executedAt,
            'schedule_expression' => $scheduleExpression,
            'scheduler' => $isDueResult['scheduler'],
            'next_due_in_seconds' => $this->computeNextDueInSeconds($cronAdapter, $scheduleExpression, time()),
        ];
    }

    private function getHourlyScheduleExpression(SettingsService $settings): string
    {
        $configured = trim($settings->getString('cron', 'hourly_expression', self::HOURLY_CRON_EXPRESSION));

        return $configured !== '' ? $configured : self::HOURLY_CRON_EXPRESSION;
    }

    /**
     * @return array{is_due:bool,next_due_in_seconds:?int,scheduler:string}
     */
    private function resolveHourlyDueState(CronExpressionAdapter $adapter, string $expression, int|false $lastRunTs, bool $forceRun): array
    {
        if ($forceRun) {
            return [
                'is_due' => true,
                'next_due_in_seconds' => 0,
                'scheduler' => 'forced',
            ];
        }

        if ($adapter->isLibraryAvailable() && $adapter->isValid($expression)) {
            if ($lastRunTs === false) {
                return [
                    'is_due' => true,
                    'next_due_in_seconds' => 0,
                    'scheduler' => 'poliander-cron',
                ];
            }

            $nextRun = $adapter->getNextRunTimestamp($expression, (int) $lastRunTs);
            if ($nextRun === null) {
                return [
                    'is_due' => true,
                    'next_due_in_seconds' => null,
                    'scheduler' => 'fallback-interval',
                ];
            }

            $remaining = max(0, $nextRun - time());
            return [
                'is_due' => $remaining === 0,
                'next_due_in_seconds' => $remaining,
                'scheduler' => 'poliander-cron',
            ];
        }

        if ($lastRunTs === false) {
            return [
                'is_due' => true,
                'next_due_in_seconds' => null,
                'scheduler' => 'fallback-interval',
            ];
        }

        $ageSeconds = max(0, time() - (int) $lastRunTs);
        return [
            'is_due' => $ageSeconds >= 3600,
            'next_due_in_seconds' => max(0, 3600 - $ageSeconds),
            'scheduler' => 'fallback-interval',
        ];
    }

    private function computeNextDueInSeconds(CronExpressionAdapter $adapter, string $expression, int $anchorTimestamp): ?int
    {
        if ($adapter->isLibraryAvailable() && $adapter->isValid($expression)) {
            $nextRun = $adapter->getNextRunTimestamp($expression, $anchorTimestamp);
            if ($nextRun !== null) {
                return max(0, $nextRun - time());
            }
        }

        return 3600;
    }

    private function runDailyHooks(SettingsService $settings, bool $forceRun): array
    {
        $scheduleExpression = $this->getDailyScheduleExpression($settings);
        $cronAdapter = CronExpressionAdapter::getInstance();
        $lastRunRaw = $settings->getString('cron', 'daily_last_run', '');
        $lastRunTs = $lastRunRaw !== '' ? strtotime($lastRunRaw) : false;
        $isDueResult = $this->resolveDailyDueState($cronAdapter, $scheduleExpression, $lastRunTs, $forceRun);

        if (!$isDueResult['is_due']) {
            return [
                'success' => true,
                'executed' => false,
                'skipped' => true,
                'reason' => 'Täglicher Hook ist noch nicht fällig.',
                'last_run' => $lastRunRaw,
                'next_due_in_seconds' => $isDueResult['next_due_in_seconds'],
                'schedule_expression' => $scheduleExpression,
                'scheduler' => $isDueResult['scheduler'],
            ];
        }

        try {
            Hooks::doAction('cms_cron_daily');
        } catch (\Throwable $e) {
            \CMS\Logger::instance()->withChannel('cron')->warning('Cron daily hook failed.', [
                'exception_class' => get_class($e),
                'exception_message' => $this->truncateForLog($e->getMessage()),
            ]);

            return [
                'success' => false,
                'executed' => false,
                'skipped' => false,
                'error' => 'Täglicher Hook fehlgeschlagen. Details wurden intern protokolliert.',
                'schedule_expression' => $scheduleExpression,
                'scheduler' => $isDueResult['scheduler'],
            ];
        }

        $executedAt = date('Y-m-d H:i:s');
        $settings->set('cron', 'daily_last_run', $executedAt, false, 0);

        return [
            'success' => true,
            'executed' => true,
            'skipped' => false,
            'executed_at' => $executedAt,
            'schedule_expression' => $scheduleExpression,
            'scheduler' => $isDueResult['scheduler'],
            'next_due_in_seconds' => $this->computeNextDailyDueInSeconds($cronAdapter, $scheduleExpression, time()),
        ];
    }

    private function getDailyScheduleExpression(SettingsService $settings): string
    {
        $configured = trim($settings->getString('cron', 'daily_expression', self::DAILY_CRON_EXPRESSION));

        return $configured !== '' ? $configured : self::DAILY_CRON_EXPRESSION;
    }

    /**
     * @return array{is_due:bool,next_due_in_seconds:?int,scheduler:string}
     */
    private function resolveDailyDueState(CronExpressionAdapter $adapter, string $expression, int|false $lastRunTs, bool $forceRun): array
    {
        if ($forceRun) {
            return [
                'is_due' => true,
                'next_due_in_seconds' => 0,
                'scheduler' => 'forced',
            ];
        }

        if ($adapter->isLibraryAvailable() && $adapter->isValid($expression)) {
            if ($lastRunTs === false) {
                return [
                    'is_due' => true,
                    'next_due_in_seconds' => 0,
                    'scheduler' => 'poliander-cron',
                ];
            }

            $nextRun = $adapter->getNextRunTimestamp($expression, (int) $lastRunTs);
            if ($nextRun === null) {
                return [
                    'is_due' => true,
                    'next_due_in_seconds' => null,
                    'scheduler' => 'fallback-interval',
                ];
            }

            $remaining = max(0, $nextRun - time());
            return [
                'is_due' => $remaining === 0,
                'next_due_in_seconds' => $remaining,
                'scheduler' => 'poliander-cron',
            ];
        }

        if ($lastRunTs === false) {
            return [
                'is_due' => true,
                'next_due_in_seconds' => null,
                'scheduler' => 'fallback-interval',
            ];
        }

        $ageSeconds = max(0, time() - (int) $lastRunTs);
        return [
            'is_due' => $ageSeconds >= 86400,
            'next_due_in_seconds' => max(0, 86400 - $ageSeconds),
            'scheduler' => 'fallback-interval',
        ];
    }

    private function computeNextDailyDueInSeconds(CronExpressionAdapter $adapter, string $expression, int $anchorTimestamp): ?int
    {
        if ($adapter->isLibraryAvailable() && $adapter->isValid($expression)) {
            $nextRun = $adapter->getNextRunTimestamp($expression, $anchorTimestamp);
            if ($nextRun !== null) {
                return max(0, $nextRun - time());
            }
        }

        return 86400;
    }

    private function runGenericHook(string $hookName, ?int $limit, bool $forceRun, string $mode, string $source): array
    {
        Hooks::doAction($hookName, [
            'limit' => $limit,
            'force' => $forceRun,
            'source' => $source,
            'mode' => $mode,
        ]);

        return [
            'success' => true,
            'executed' => true,
            'hook' => $hookName,
        ];
    }

    private function runFeedCronTask(?int $limit, bool $forceRun, string $mode, string $source): array
    {
        try {
            $context = ['force' => $forceRun];
            if ($limit !== null && $limit > 0) {
                $context['limit'] = $limit;
            }
            $feedResult = \CMS\Services\FeatureHooks\FeedHooks::runCron($context);

            return [
                'success' => !isset($feedResult['error']),
                'executed' => !empty($feedResult['executed']),
                'mode' => 'feed-cron-task',
                'source' => $source,
                'result' => $feedResult,
            ];
        } catch (\Throwable $e) {
            \CMS\Logger::instance()->withChannel('cron')->warning('Cron feed task failed.', [
                'exception' => $e,
                'mode' => $mode,
                'source' => $source,
            ]);

            return [
                'success' => false,
                'executed' => false,
                'mode' => 'feed-cron-task',
                'source' => $source,
                'error' => 'Feed-Cron fehlgeschlagen. Details wurden intern protokolliert.',
            ];
        }
    }

    private function truncateForLog(string $value, int $limit = 400): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return (string) mb_substr($value, 0, $limit, 'UTF-8');
        }

        return substr($value, 0, $limit);
    }
}