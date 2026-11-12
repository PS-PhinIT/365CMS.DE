<?php
declare(strict_types=1);

/**
 * Newsletter – Admin-Modul (Übersicht, Abonnenten, Kampagnen, Vorlagen, Einstellungen).
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\CacheManager;
use CMS\Logger;
use CMS\Services\Newsletter\NewsletterDispatcher;
use CMS\Services\Newsletter\NewsletterMailer;
use CMS\Services\Newsletter\NewsletterPrivacy;
use CMS\Services\Newsletter\NewsletterRepository;
use CMS\Services\Newsletter\NewsletterSettings;

final class NewsletterModule
{
    public const SECTIONS = ['dashboard', 'subscribers', 'subscriber', 'campaigns', 'campaign', 'templates', 'template', 'settings'];
    public const SETTINGS_TABS = ['general', 'content', 'delivery'];
    public const PER_PAGE = 50;

    /** Felder je Einstellungs-Tab (gespeichert werden nur diese). */
    private const TAB_KEYS = [
        'general' => ['sender_name', 'sender_email', 'reply_to_email', 'require_double_opt_in', 'confirm_expiry_days', 'default_segment', 'segments', 'show_segment_choice', 'show_name_fields'],
        'content' => ['archive_title', 'archive_title_en', 'archive_description', 'archive_description_en', 'subscribe_intro', 'subscribe_intro_en',
            'consent_text', 'consent_text_en', 'footer_note', 'footer_note_en', 'public_archive', 'show_nav_link', 'nav_label', 'nav_label_en'],
        'delivery' => ['batch_size', 'mail_footer', 'mail_footer_en'],
    ];

    private NewsletterRepository $repo;

    public function __construct()
    {
        $this->repo = NewsletterRepository::instance();
    }

    /** @param array<string, scalar|null> $params */
    public static function url(array $params = []): string
    {
        $params = array_filter($params, static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== 0);

        return '/admin/newsletter' . ($params !== [] ? '?' . http_build_query($params) : '');
    }

    // ── Daten ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function getDashboardData(): array
    {
        return [
            'view' => 'dashboard',
            'stats' => $this->repo->stats(),
            'campaigns' => array_slice($this->repo->campaigns(), 0, 6),
            'recent' => $this->repo->listSubscribers([], 0, 6),
            'segments' => NewsletterSettings::segments(),
            'segment_counts' => $this->repo->segmentCounts(),
            'sender_ok' => NewsletterSettings::senderEmail() !== '',
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function getSubscribersData(array $query): array
    {
        $filters = [
            'search' => is_string($query['q'] ?? null) ? mb_substr(trim($query['q']), 0, 120) : '',
            'status' => in_array($query['status'] ?? '', NewsletterRepository::SUBSCRIBER_STATUSES, true) ? (string) $query['status'] : '',
            'segment' => is_string($query['segment'] ?? null) ? NewsletterSettings::slug($query['segment']) : '',
        ];
        $total = $this->repo->countSubscribers($filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) ($query['page'] ?? 1)));

        return [
            'view' => 'subscribers',
            'filters' => $filters,
            'subscribers' => $total > 0 ? $this->repo->listSubscribers($filters, ($page - 1) * self::PER_PAGE, self::PER_PAGE) : [],
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'segments' => NewsletterSettings::segments(),
            'exports' => NewsletterPrivacy::listExports(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function getSubscriberData(int $id): ?array
    {
        $subscriber = $id > 0 ? $this->repo->subscriber($id) : null;
        if ($id > 0 && $subscriber === null) {
            return null;
        }

        return [
            'view' => 'subscriber',
            'is_new' => $subscriber === null,
            'subscriber' => $subscriber ?? ['status' => 'active', 'segment_slug' => NewsletterSettings::get('default_segment'), 'locale' => 'de'],
            'segments' => NewsletterSettings::segments(),
            'history' => $subscriber !== null ? (NewsletterPrivacy::collect(0, (string) $subscriber['email'])[0]['issues'] ?? []) : [],
        ];
    }

    /** @return array<string, mixed> */
    public function getCampaignsData(string $status): array
    {
        return ['view' => 'campaigns', 'status' => $status, 'campaigns' => $this->repo->campaigns($status)];
    }

    /** @return array<string, mixed>|null */
    public function getCampaignData(int $id): ?array
    {
        $campaign = $id > 0 ? $this->repo->campaign($id) : null;
        if ($id > 0 && $campaign === null) {
            return null;
        }

        return [
            'view' => 'campaign',
            'is_new' => $campaign === null,
            'campaign' => $campaign ?? ['status' => 'draft', 'segment_slug' => '', 'locale' => ''],
            'templates' => $this->repo->templates(),
            'segments' => NewsletterSettings::segments(),
            'segment_counts' => $this->repo->segmentCounts(),
            'counts' => $campaign !== null ? $this->repo->sendCounts($id) : [],
            'failed' => $campaign !== null ? $this->repo->failedSends($id) : [],
            'recipients' => $campaign !== null ? $this->repo->countRecipients((string) $campaign['segment_slug'], (string) $campaign['locale']) : $this->repo->countRecipients('', ''),
            'sender_ok' => NewsletterSettings::senderEmail() !== '',
        ];
    }

    /** @return array<string, mixed> */
    public function getTemplatesData(): array
    {
        return ['view' => 'templates', 'templates' => $this->repo->templates()];
    }

    /** @return array<string, mixed>|null */
    public function getTemplateData(int $id): ?array
    {
        $template = $id > 0 ? $this->repo->template($id) : null;
        if ($id > 0 && $template === null) {
            return null;
        }

        return ['view' => 'template', 'is_new' => $template === null, 'template' => $template ?? ['status' => 'active']];
    }

    /** @return array<string, mixed> */
    public function getSettingsData(string $tab): array
    {
        $tab = in_array($tab, self::SETTINGS_TABS, true) ? $tab : 'general';

        return [
            'view' => 'settings',
            'tab' => $tab,
            'settings' => NewsletterSettings::all(),
            'dns' => $tab === 'delivery' ? $this->dnsDiagnostics() : [],
            'sender' => NewsletterSettings::senderEmail(),
        ];
    }

    // ── Aktionen ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function handlePost(array $post, int $userId, string $userEmail): array
    {
        $action = is_string($post['action'] ?? null) ? $post['action'] : '';
        $id = (int) ($post['id'] ?? 0);
        try {
            $result = match ($action) {
                'save_subscriber' => $this->saveSubscriber($post, $id),
                'delete_subscriber' => $this->simple($this->repo->deleteSubscriber($id), 'newsletter.admin.subscriber_deleted', 'newsletter.error.subscriber_not_found', ['section' => 'subscribers']),
                'bulk_subscribers' => $this->bulk($post),
                'resend_confirmation' => $this->resendConfirmation($id),
                'import_subscribers' => $this->importSubscribers($post),
                'delete_export' => $this->simple(NewsletterPrivacy::deleteExport((string) ($post['file'] ?? '')), 'newsletter.admin.export_deleted', 'newsletter.error.not_found', ['section' => 'subscribers']),
                'save_campaign' => $this->saveCampaign($post, $id, $userId),
                'duplicate_campaign' => $this->duplicateCampaign($id, $userId),
                'test_campaign' => $this->testCampaign($post, $id, $userEmail),
                'send_campaign' => $this->sendCampaign($id),
                'schedule_campaign' => $this->scheduleCampaign($post, $id),
                'cancel_campaign' => $this->simple(NewsletterDispatcher::cancel($id), 'newsletter.admin.campaign_cancelled', 'newsletter.error.campaign_not_sendable', ['section' => 'campaign', 'id' => $id]),
                'retry_failed' => $this->retryFailed($id),
                'delete_campaign' => $this->simple($this->repo->deleteCampaign($id), 'newsletter.admin.campaign_deleted', 'newsletter.error.campaign_not_deletable', ['section' => 'campaigns']),
                'save_template' => $this->saveTemplate($post, $id),
                'delete_template' => $this->simple($this->repo->deleteTemplate($id), 'newsletter.admin.template_deleted', 'newsletter.error.template_not_found', ['section' => 'templates']),
                'save_settings' => $this->saveSettings($post),
                default => ['success' => false, 'error' => (string) __('newsletter.admin.error_unknown_action')],
            };
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('newsletter')->error('Newsletter-Aktion fehlgeschlagen.', ['action' => $action, 'exception' => $e->getMessage()]);
            $result = ['success' => false, 'error' => (string) __('newsletter.admin.error_action_failed')];
        }

        return $result;
    }

    /** CSV-Export der Abonnenten (aktuelle Filter). */
    public function streamCsv(array $query): void
    {
        $data = $this->getSubscribersData($query);
        $rows = $this->repo->listSubscribers($data['filters'], 0, 5000);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="newsletter-abonnenten-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['email', 'first_name', 'last_name', 'status', 'segment', 'language', 'source', 'subscribed_at', 'consent_at', 'confirmed_at', 'unsubscribed_at'], ';');
        foreach ($rows as $row) {
            $cells = [$row['email'], $row['first_name'], $row['last_name'], $row['status'], $row['segment_slug'], $row['locale'] ?? 'de', $row['source'], $row['created_at'], $row['consent_at'] ?? '', $row['confirmed_at'] ?? '', $row['unsubscribed_at'] ?? ''];
            // Formel-Injection in Tabellenprogrammen verhindern.
            $cells = array_map(static fn (mixed $v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) === 1 ? "'" . $v : (string) $v, $cells);
            fputcsv($out, $cells, ';');
        }
        fclose($out);
        exit;
    }

    /**
     * Mail-Vorschau einer Kampagne als eigenständige Seite. Eigene, enge CSP: Inline-Stile und Bilder wie im
     * Postfach, keine Skripte, Einbettung nur in die Admin-Seite derselben Website.
     */
    public function streamPreview(int $id): never
    {
        $campaign = $this->repo->campaign($id);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/html; charset=UTF-8');
        header_remove('Content-Security-Policy-Report-Only');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src https: data:; frame-ancestors 'self'; base-uri 'none'; form-action 'none'", true);
        header('X-Frame-Options: SAMEORIGIN', true);
        header('Cache-Control: no-store');
        if ($campaign === null) {
            http_response_code(404);
            exit;
        }
        $locale = (string) ($campaign['locale'] ?: 'de');
        echo NewsletterMailer::renderCampaign($campaign, ['email' => 'max@example.org', 'first_name' => 'Max', 'last_name' => 'Muster', 'locale' => $locale, 'optin_token' => str_repeat('0', 48)])['html'];
        exit;
    }

    public function streamExport(string $name): bool
    {
        $path = NewsletterPrivacy::exportPath($name);
        if ($path === null || !is_file($path)) {
            return false;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        exit;
    }

    // ── intern ──────────────────────────────────────────────────────────────

    /** @param array<string, scalar|null> $redirect */
    private function simple(bool $ok, string $successKey, string $errorKey, array $redirect): array
    {
        return ($ok ? ['success' => true, 'message' => (string) __($successKey)] : ['success' => false, 'error' => (string) __($errorKey)])
            + ['__redirect' => self::url($redirect)];
    }

    /** @param array<string, mixed> $post */
    private function saveSubscriber(array $post, int $id): array
    {
        $result = $this->repo->saveSubscriber($post + ['source' => 'admin'], $id);
        if (empty($result['success'])) {
            return $result + ['__redirect' => self::url(['section' => 'subscriber', 'id' => $id])];
        }
        $sent = false;
        if (!empty($post['send_confirmation']) && ($row = $this->repo->subscriber((int) $result['id'])) !== null && $row['status'] === 'pending') {
            $sent = NewsletterMailer::sendConfirmation($row);
        }

        return [
            'success' => true,
            'message' => (string) __($id > 0 ? 'newsletter.admin.subscriber_updated' : ($sent ? 'newsletter.admin.subscriber_created_confirm' : 'newsletter.admin.subscriber_created')),
            '__redirect' => self::url(['section' => 'subscriber', 'id' => (int) $result['id']]),
        ];
    }

    /** @param array<string, mixed> $post */
    private function bulk(array $post): array
    {
        $action = in_array($post['bulk_action'] ?? '', ['activate', 'unsubscribe', 'delete'], true) ? (string) $post['bulk_action'] : '';
        $ids = is_array($post['ids'] ?? null) ? $post['ids'] : [];
        if ($action === '' || $ids === []) {
            return ['success' => false, 'error' => (string) __('newsletter.admin.bulk_nothing'), '__redirect' => self::url(['section' => 'subscribers'])];
        }

        return [
            'success' => true,
            'message' => sprintf((string) __('newsletter.admin.bulk_done'), $this->repo->bulkSubscribers($action, $ids)),
            '__redirect' => self::url(['section' => 'subscribers']),
        ];
    }

    private function resendConfirmation(int $id): array
    {
        $row = $this->repo->subscriber($id);
        $ok = $row !== null && $row['status'] === 'pending' && NewsletterMailer::sendConfirmation($row);

        return $this->simple($ok, 'newsletter.admin.confirmation_sent', 'newsletter.error.confirmation_failed', ['section' => 'subscriber', 'id' => $id]);
    }

    /**
     * Import aus CSV-Text oder -Datei: Spalten email;first_name;last_name;segment (Kopfzeile optional).
     *
     * @param array<string, mixed> $post
     */
    private function importSubscribers(array $post): array
    {
        $redirect = ['__redirect' => self::url(['section' => 'subscribers'])];
        $raw = is_string($post['csv'] ?? null) ? $post['csv'] : '';
        $file = $_FILES['csv_file'] ?? null;
        if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $file['tmp_name'])) {
            if ((int) $file['size'] > 5 * 1024 * 1024) {
                return ['success' => false, 'error' => (string) __('newsletter.error.import_too_large')] + $redirect;
            }
            $raw = (string) file_get_contents((string) $file['tmp_name']);
        }
        $raw = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $raw));
        if ($raw === '') {
            return ['success' => false, 'error' => (string) __('newsletter.error.import_empty')] + $redirect;
        }
        if (empty($post['consent_confirmed'])) {
            return ['success' => false, 'error' => (string) __('newsletter.error.import_consent')] + $redirect;
        }
        $mode = ($post['import_status'] ?? '') === 'pending' ? 'pending' : 'active';
        $segmentDefault = NewsletterSettings::slug((string) ($post['segment'] ?? '')) ?: NewsletterSettings::get('default_segment');
        $created = $skipped = $invalid = 0;
        $lines = preg_split('/\R/', $raw) ?: [];
        foreach (array_slice($lines, 0, 20000) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $delimiter = substr_count($line, ';') >= substr_count($line, ',') ? ';' : ',';
            $cols = array_map('trim', str_getcsv($line, $delimiter, '"', '\\'));
            $email = NewsletterRepository::normalizeEmail($cols[0] ?? '');
            if ($email === '') {
                if (!in_array(strtolower($cols[0] ?? ''), ['email', 'e-mail', 'mail'], true)) {
                    $invalid++;
                }
                continue;
            }
            if ($this->repo->subscriberByEmail($email) !== null) {
                $skipped++;
                continue;
            }
            $result = $this->repo->saveSubscriber([
                'email' => $email,
                'first_name' => $cols[1] ?? '',
                'last_name' => $cols[2] ?? '',
                'segment_slug' => ($cols[3] ?? '') !== '' ? $cols[3] : $segmentDefault,
                'status' => $mode,
                'source' => 'import',
                'consent_text' => (string) __('newsletter.admin.import_consent_note'),
            ]);
            if (!empty($result['success'])) {
                $created++;
                if ($mode === 'pending' && ($row = $this->repo->subscriber((int) $result['id'])) !== null) {
                    NewsletterMailer::sendConfirmation($row);
                }
            }
        }

        return ['success' => true, 'message' => sprintf((string) __('newsletter.admin.import_done'), $created, $skipped, $invalid)] + $redirect;
    }

    /** @param array<string, mixed> $post */
    private function saveCampaign(array $post, int $id, int $userId): array
    {
        $result = $this->repo->saveCampaign($post, $id, $userId);
        $savedId = (int) ($result['id'] ?? $id);

        return (!empty($result['success']) ? ['success' => true, 'message' => (string) __('newsletter.admin.campaign_saved')] : $result)
            + ['id' => $savedId, '__redirect' => self::url(['section' => 'campaign', 'id' => $savedId])];
    }

    private function duplicateCampaign(int $id, int $userId): array
    {
        $campaign = $this->repo->campaign($id);
        if ($campaign === null) {
            return ['success' => false, 'error' => (string) __('newsletter.error.campaign_not_found'), '__redirect' => self::url(['section' => 'campaigns'])];
        }
        $campaign['name'] = sprintf((string) __('newsletter.admin.copy_of'), (string) $campaign['name']);
        $result = $this->repo->saveCampaign($campaign, 0, $userId);

        return ['success' => !empty($result['success']), 'message' => (string) __('newsletter.admin.campaign_duplicated'), '__redirect' => self::url(['section' => 'campaign', 'id' => (int) ($result['id'] ?? 0)])];
    }

    /** @param array<string, mixed> $post */
    private function testCampaign(array $post, int $id, string $userEmail): array
    {
        $campaign = $this->repo->campaign($id);
        $to = trim((string) ($post['test_email'] ?? '')) ?: $userEmail;
        if ($campaign === null) {
            return ['success' => false, 'error' => (string) __('newsletter.error.campaign_not_found'), '__redirect' => self::url(['section' => 'campaigns'])];
        }
        $result = NewsletterMailer::sendTest($campaign, $to, (string) ($campaign['locale'] ?: 'de'));

        return ($result['success']
            ? ['success' => true, 'message' => sprintf((string) __('newsletter.admin.test_sent'), $to)]
            : ['success' => false, 'error' => sprintf((string) __('newsletter.error.test_failed'), (string) ($result['error'] ?? ''))])
            + ['__redirect' => self::url(['section' => 'campaign', 'id' => $id])];
    }

    private function sendCampaign(int $id): array
    {
        $result = NewsletterDispatcher::start($id);
        if (!empty($result['success'])) {
            NewsletterDispatcher::run();

            return ['success' => true, 'message' => sprintf((string) __('newsletter.admin.campaign_started'), (int) $result['recipients']), '__redirect' => self::url(['section' => 'campaign', 'id' => $id])];
        }

        return ['success' => false, 'error' => (string) ($result['error'] ?? ''), '__redirect' => self::url(['section' => 'campaign', 'id' => $id])];
    }

    /** @param array<string, mixed> $post */
    private function scheduleCampaign(array $post, int $id): array
    {
        $result = NewsletterDispatcher::schedule($id, (string) ($post['scheduled_at'] ?? ''));

        return ($result['success'] ? ['success' => true, 'message' => (string) __('newsletter.admin.campaign_scheduled')] : $result)
            + ['__redirect' => self::url(['section' => 'campaign', 'id' => $id])];
    }

    private function retryFailed(int $id): array
    {
        $count = NewsletterDispatcher::retryFailed($id);
        if ($count > 0) {
            NewsletterDispatcher::run();
        }

        return ['success' => $count > 0, 'message' => sprintf((string) __('newsletter.admin.retry_done'), $count), 'error' => $count > 0 ? null : (string) __('newsletter.admin.retry_none'), '__redirect' => self::url(['section' => 'campaign', 'id' => $id])];
    }

    /** @param array<string, mixed> $post */
    private function saveTemplate(array $post, int $id): array
    {
        $result = $this->repo->saveTemplate($post, $id);
        $savedId = (int) ($result['id'] ?? $id);

        return (!empty($result['success']) ? ['success' => true, 'message' => (string) __('newsletter.admin.template_saved')] : $result)
            + ['__redirect' => self::url(['section' => 'template', 'id' => $savedId])];
    }

    /** @param array<string, mixed> $post */
    private function saveSettings(array $post): array
    {
        $tab = in_array($post['settings_tab'] ?? '', self::SETTINGS_TABS, true) ? (string) $post['settings_tab'] : 'general';
        NewsletterSettings::save($post, self::TAB_KEYS[$tab]);
        if ($tab === 'general') {
            CacheManager::instance()->delete('newsletter_dns_' . md5(NewsletterSettings::senderEmail()));
        }

        return ['success' => true, 'message' => (string) __('newsletter.admin.settings_saved'), '__redirect' => self::url(['section' => 'settings', 'tab' => $tab !== 'general' ? $tab : null])];
    }

    /**
     * SPF, DMARC und BIMI der Absenderdomain (10 Minuten zwischengespeichert). DKIM braucht den Selektor des
     * Mailanbieters und wird nur als Hinweis geführt.
     *
     * @return array<string, array{ok: bool|null, host: string}>
     */
    private function dnsDiagnostics(): array
    {
        $sender = NewsletterSettings::senderEmail();
        $domain = strtolower((string) substr(strrchr($sender, '@') ?: '', 1));
        if ($domain === '' || !function_exists('dns_get_record')) {
            return [];
        }
        $cache = CacheManager::instance();
        $key = 'newsletter_dns_' . md5($sender);
        $cached = $cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $lookup = static function (string $host, string $needle): bool {
            foreach (@dns_get_record($host, DNS_TXT) ?: [] as $record) {
                if (str_contains(strtolower((string) ($record['txt'] ?? '')), $needle)) {
                    return true;
                }
            }

            return false;
        };
        $result = [
            'spf' => ['ok' => $lookup($domain, 'v=spf1'), 'host' => $domain],
            'dmarc' => ['ok' => $lookup('_dmarc.' . $domain, 'v=dmarc1'), 'host' => '_dmarc.' . $domain],
            'bimi' => ['ok' => $lookup('default._bimi.' . $domain, 'v=bimi1'), 'host' => 'default._bimi.' . $domain],
            'dkim' => ['ok' => null, 'host' => '<selector>._domainkey.' . $domain],
        ];
        $cache->set($key, $result, 600);

        return $result;
    }
}
