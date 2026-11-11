<?php
declare(strict_types=1);

/**
 * Löschfristen – Übersicht aller Aufbewahrungsregeln (Core und registrierte Regeln der Funktionsbereiche),
 * Probelauf, letzter Lauf je Regel, manuelle Ausführung und Einstellungen der Kommentar-IP-Regel.
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\AuditLogger;
use CMS\Services\DataRetentionService;
use CMS\Services\Retention\CommentIpRetention;
use CMS\Services\Retention\RetentionRule;

final class DataRetentionModule
{
    private const RULE_KEY_PATTERN = '/^[a-z0-9_.-]{1,64}$/';

    private DataRetentionService $service;

    public function __construct()
    {
        $this->service = DataRetentionService::getInstance();
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $dryRun = $this->service->dryRun();
        $runs = $this->service->getRuleRuns();
        $rows = [];
        $activeCount = 0;
        $dueTotal = 0;

        foreach ($this->service->getRules() as $key => $rule) {
            try {
                $days = $rule->days();
            } catch (\Throwable) {
                $days = 0;
            }

            $due = array_key_exists($key, $dryRun) ? $dryRun[$key] : null;
            $activeCount += $days > 0 ? 1 : 0;
            $dueTotal += is_int($due) ? $due : 0;

            $rows[] = [
                'key' => (string) $key,
                'label' => $rule->label !== '' ? $rule->label : (string) $key,
                'description' => $rule->description,
                'days' => $days,
                'action' => $rule->action === RetentionRule::ACTION_ANONYMIZE ? RetentionRule::ACTION_ANONYMIZE : RetentionRule::ACTION_DELETE,
                'settings_url' => $this->sanitizeAdminUrl($rule->settingsUrl),
                'core' => $this->service->isCoreRule((string) $key) || $key === CommentIpRetention::RULE_KEY,
                'due' => $due,
                'last_run' => $runs[$key] ?? null,
            ];
        }

        return [
            'rules' => $rows,
            'summary' => [
                'total' => count($rows),
                'active' => $activeCount,
                'due' => $dueTotal,
            ],
            'last_full_run' => $this->service->getLastRun(),
            'comment_ips' => CommentIpRetention::settings(),
            'comment_ip_max_days' => CommentIpRetention::MAX_DAYS,
            'comment_ip_anchor' => CommentIpRetention::SETTINGS_ANCHOR,
        ];
    }

    /**
     * @return array{success:bool,message?:string,error?:string}
     */
    public function runRule(string $key): array
    {
        $key = trim($key);
        if (preg_match(self::RULE_KEY_PATTERN, $key) !== 1) {
            return ['success' => false, 'error' => __('retention.error.unknown_rule')];
        }

        $result = $this->service->runRule($key);
        if ($result === null) {
            return ['success' => false, 'error' => __('retention.error.unknown_rule')];
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SYSTEM,
            'retention.rule_run',
            sprintf('Aufbewahrungsregel „%s“ manuell ausgeführt: %d Einträge.', $key, $result['count']),
            'retention_rule',
            null,
            ['rule' => $key, 'count' => $result['count'], 'error' => $result['error']],
            $result['error'] ? 'warning' : 'info'
        );

        if ($result['error']) {
            return ['success' => false, 'error' => __('retention.error.run_failed')];
        }

        return ['success' => true, 'message' => sprintf(__('retention.notice.rule_done'), $result['count'])];
    }

    /**
     * @return array{success:bool,message?:string,error?:string}
     */
    public function runAll(): array
    {
        $result = $this->service->run();
        $total = array_sum($result);
        $lastRun = $this->service->getLastRun();
        $errors = $lastRun['errors'] ?? [];

        AuditLogger::instance()->log(
            AuditLogger::CAT_SYSTEM,
            'retention.run_all',
            sprintf('Alle Aufbewahrungsregeln manuell ausgeführt: %d Einträge.', $total),
            'retention_rule',
            null,
            ['results' => $result, 'errors' => $errors],
            $errors !== [] ? 'warning' : 'info'
        );

        if ($errors !== []) {
            return ['success' => false, 'error' => sprintf(__('retention.error.run_all_partial'), $total, implode(', ', $errors))];
        }

        return ['success' => true, 'message' => sprintf(__('retention.notice.all_done'), $total)];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{success:bool,message?:string,error?:string}
     */
    public function saveCommentIpSettings(array $post): array
    {
        $before = CommentIpRetention::settings();
        $saved = CommentIpRetention::save($post['comment_ip_days'] ?? 0, $post['comment_ip_mode'] ?? '');
        if ($saved === null) {
            return ['success' => false, 'error' => __('retention.error.save_failed')];
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'retention.comment_ips_saved',
            sprintf('Frist für Kommentar-IP-Adressen gespeichert: %d Tage (%s).', $saved['days'], $saved['mode']),
            'setting',
            null,
            ['before' => $before, 'after' => $saved]
        );

        return ['success' => true, 'message' => __('retention.notice.settings_saved')];
    }

    private function sanitizeAdminUrl(string $url): string
    {
        $url = trim($url);

        return preg_match('~^/admin(?:[/?#][^\s"\'<>\\\\]*)?$~', $url) === 1 && !str_starts_with($url, '//') ? $url : '';
    }
}
