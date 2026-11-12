<?php
/**
 * Datenzugriff des Newsletters: Abonnenten, Vorlagen, Kampagnen, Versandzeilen, Kennzahlen.
 *
 * Abonnenten-Status: `pending` (Anmeldung nicht bestätigt), `active`, `unsubscribed`, `bounced`.
 * Kampagnen-Status: `draft`, `scheduled`, `sending`, `sent`, `cancelled`.
 * Das Token `optin_token` dient für Bestätigung und Abmeldung (wie im früheren Plugin).
 *
 * Hooks: `cms_newsletter_subscribed` (Abonnent aktiv), `cms_newsletter_unsubscribed`, `cms_newsletter_subscriber_deleted`
 * – jeweils mit der Abonnenten-ID.
 *
 * @package CMSv2\Core\Services\Newsletter
 */

declare(strict_types=1);

namespace CMS\Services\Newsletter;

use CMS\Database;
use CMS\Hooks;
use CMS\Services\PurifierService;

if (!defined('ABSPATH')) {
    exit;
}

final class NewsletterRepository
{
    public const SUBSCRIBER_STATUSES = ['pending', 'active', 'unsubscribed', 'bounced'];
    public const CAMPAIGN_STATUSES = ['draft', 'scheduled', 'sending', 'sent', 'cancelled'];
    public const TEMPLATE_STATUSES = ['draft', 'active'];
    public const SOURCES = ['admin', 'public', 'member', 'import', 'block'];

    private static ?self $instance = null;
    private Database $db;
    private string $p;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $this->p = $this->db->getPrefix();
    }

    public function isAvailable(): bool
    {
        try {
            return $this->db->tableExists($this->p . 'newsletter_subscribers');
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Kennzahlen ──────────────────────────────────────────────────────────

    /** @return array<string, int> */
    public function stats(): array
    {
        $count = fn (string $sql): int => (int) $this->db->get_var($sql);

        return [
            'subscribers' => $count("SELECT COUNT(*) FROM {$this->p}newsletter_subscribers"),
            'active' => $count("SELECT COUNT(*) FROM {$this->p}newsletter_subscribers WHERE status = 'active'"),
            'pending' => $count("SELECT COUNT(*) FROM {$this->p}newsletter_subscribers WHERE status = 'pending'"),
            'unsubscribed' => $count("SELECT COUNT(*) FROM {$this->p}newsletter_subscribers WHERE status IN ('unsubscribed', 'bounced')"),
            'new_30' => $count("SELECT COUNT(*) FROM {$this->p}newsletter_subscribers WHERE status = 'active' AND confirmed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"),
            'templates' => $count("SELECT COUNT(*) FROM {$this->p}newsletter_templates"),
            'campaigns' => $count("SELECT COUNT(*) FROM {$this->p}newsletter_campaigns"),
            'sent_campaigns' => $count("SELECT COUNT(*) FROM {$this->p}newsletter_campaigns WHERE status = 'sent'"),
            'open_campaigns' => $count("SELECT COUNT(*) FROM {$this->p}newsletter_campaigns WHERE status IN ('scheduled', 'sending')"),
            'mails_sent' => $count("SELECT COUNT(*) FROM {$this->p}newsletter_sends WHERE status = 'sent'"),
        ];
    }

    // ── Abonnenten ──────────────────────────────────────────────────────────

    /**
     * @param array{search?: string, status?: string, segment?: string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function subscriberWhere(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (in_array($filters['status'] ?? '', self::SUBSCRIBER_STATUSES, true)) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        if (($filters['segment'] ?? '') !== '') {
            $where[] = 'segment_slug = ?';
            $params[] = (string) $filters['segment'];
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '\\%_') . '%';
            $where[] = '(email LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        return [implode(' AND ', $where), $params];
    }

    /** @param array{search?: string, status?: string, segment?: string} $filters */
    public function countSubscribers(array $filters = []): int
    {
        [$where, $params] = $this->subscriberWhere($filters);

        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->p}newsletter_subscribers WHERE {$where}", $params);
    }

    /**
     * @param array{search?: string, status?: string, segment?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function listSubscribers(array $filters = [], int $offset = 0, int $limit = 50): array
    {
        [$where, $params] = $this->subscriberWhere($filters);
        $params[] = max(1, min(5000, $limit));
        $params[] = max(0, $offset);

        return $this->db->execute(
            "SELECT * FROM {$this->p}newsletter_subscribers WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    public function subscriber(int $id): ?array
    {
        return $this->row("SELECT * FROM {$this->p}newsletter_subscribers WHERE id = ? LIMIT 1", [$id]);
    }

    /** @return array<string, mixed>|null */
    public function subscriberByEmail(string $email): ?array
    {
        return $this->row("SELECT * FROM {$this->p}newsletter_subscribers WHERE email = ? LIMIT 1", [self::normalizeEmail($email)]);
    }

    /** @return array<string, mixed>|null */
    public function subscriberByToken(string $token): ?array
    {
        $token = self::normalizeToken($token);

        return $token !== '' ? $this->row("SELECT * FROM {$this->p}newsletter_subscribers WHERE optin_token = ? LIMIT 1", [$token]) : null;
    }

    /** @return array<string, mixed>|null */
    public function subscriberByUser(int $userId): ?array
    {
        return $userId > 0 ? $this->row("SELECT * FROM {$this->p}newsletter_subscribers WHERE user_id = ? ORDER BY id ASC LIMIT 1", [$userId]) : null;
    }

    /**
     * Abonnent anlegen oder ändern (Admin, Import, Mitgliederbereich).
     *
     * @param array<string, mixed> $input email, first_name, last_name, status, segment_slug, locale, source, user_id, consent_text
     * @return array{success: bool, id?: int, error?: string, created?: bool}
     */
    public function saveSubscriber(array $input, int $id = 0): array
    {
        $email = self::normalizeEmail((string) ($input['email'] ?? ''));
        if ($email === '') {
            return ['success' => false, 'error' => (string) __('newsletter.error.email_invalid')];
        }
        $existing = $id > 0 ? $this->subscriber($id) : null;
        if ($id > 0 && $existing === null) {
            return ['success' => false, 'error' => (string) __('newsletter.error.subscriber_not_found')];
        }
        $duplicate = $this->subscriberByEmail($email);
        if ($duplicate !== null && (int) $duplicate['id'] !== $id) {
            return ['success' => false, 'error' => (string) __('newsletter.error.email_exists')];
        }

        $status = in_array($input['status'] ?? '', self::SUBSCRIBER_STATUSES, true) ? (string) $input['status'] : (string) ($existing['status'] ?? 'pending');
        $now = date('Y-m-d H:i:s');
        $previous = (string) ($existing['status'] ?? '');
        $values = [
            'email' => $email,
            'first_name' => self::text((string) ($input['first_name'] ?? ''), 120),
            'last_name' => self::text((string) ($input['last_name'] ?? ''), 120),
            'status' => $status,
            'segment_slug' => NewsletterSettings::slug((string) ($input['segment_slug'] ?? '')) ?: (NewsletterSettings::slug(NewsletterSettings::get('default_segment')) ?: 'general'),
            'locale' => ($input['locale'] ?? '') === 'en' ? 'en' : 'de',
        ];
        if ($status === 'active' && $previous !== 'active') {
            $values['confirmed_at'] = $now;
            $values['unsubscribed_at'] = null;
            $values['consent_at'] = $existing['consent_at'] ?? $now;
        } elseif ($status === 'unsubscribed' && $previous !== 'unsubscribed') {
            $values['unsubscribed_at'] = $now;
        }
        if (array_key_exists('user_id', $input)) {
            $values['user_id'] = (int) $input['user_id'] > 0 ? (int) $input['user_id'] : null;
        }
        if (is_string($input['consent_text'] ?? null) && $input['consent_text'] !== '') {
            $values['consent_text'] = self::text($input['consent_text'], 500);
            $values['consent_at'] = $now;
        }

        if ($existing !== null) {
            $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($values)));
            $this->db->execute("UPDATE {$this->p}newsletter_subscribers SET {$sets} WHERE id = ?", [...array_values($values), $id]);
            $this->fireStatusHooks($id, $previous, $status);

            return ['success' => true, 'id' => $id, 'created' => false];
        }

        $values['source'] = in_array($input['source'] ?? '', self::SOURCES, true) ? (string) $input['source'] : 'admin';
        $values['optin_token'] = self::newToken();
        $columns = array_keys($values);
        $this->db->execute(
            "INSERT INTO {$this->p}newsletter_subscribers (" . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
            array_values($values)
        );
        $newId = (int) $this->db->lastInsertId();
        $this->fireStatusHooks($newId, '', $status);

        return ['success' => true, 'id' => $newId, 'created' => true];
    }

    /**
     * Öffentliche Anmeldung (Formular, Block). Liefert, was als Nächstes passieren soll – ohne zu verraten,
     * ob die Adresse schon eingetragen ist (gleiche Meldung für alle Fälle).
     *
     * @param array{email: string, first_name?: string, last_name?: string, segment?: string, locale?: string, source?: string, consent_text?: string, user_id?: int} $input
     * @return array{success: bool, id?: int, send_confirmation?: bool, error?: string}
     */
    public function subscribe(array $input, bool $doubleOptIn): array
    {
        $email = self::normalizeEmail($input['email']);
        if ($email === '') {
            return ['success' => false, 'error' => 'invalid'];
        }
        $existing = $this->subscriberByEmail($email);
        $segment = NewsletterSettings::slug((string) ($input['segment'] ?? ''));
        if ($segment === '' || !isset(NewsletterSettings::segments()[$segment])) {
            $segment = NewsletterSettings::slug(NewsletterSettings::get('default_segment')) ?: 'general';
        }
        $locale = ($input['locale'] ?? 'de') === 'en' ? 'en' : 'de';
        $consent = self::text((string) ($input['consent_text'] ?? ''), 500);

        if ($existing !== null && $existing['status'] === 'active') {
            return ['success' => true, 'id' => (int) $existing['id'], 'send_confirmation' => false];
        }

        if ($existing !== null && $existing['status'] === 'pending') {
            // Bestätigungsmail höchstens alle 10 Minuten erneut senden (Schutz vor Mail-Bombing).
            $last = strtotime((string) ($existing['optin_sent_at'] ?? '')) ?: 0;

            return ['success' => true, 'id' => (int) $existing['id'], 'send_confirmation' => $doubleOptIn && time() - $last > 600];
        }

        $status = $doubleOptIn ? 'pending' : 'active';
        $values = [
            'email' => $email,
            'first_name' => self::text((string) ($input['first_name'] ?? ''), 120),
            'last_name' => self::text((string) ($input['last_name'] ?? ''), 120),
            'status' => $status,
            'source' => in_array($input['source'] ?? '', self::SOURCES, true) ? (string) $input['source'] : 'public',
            'segment_slug' => $segment,
            'locale' => $locale,
            'consent_text' => $consent !== '' ? $consent : null,
            'consent_at' => date('Y-m-d H:i:s'),
            'confirmed_at' => $status === 'active' ? date('Y-m-d H:i:s') : null,
            'unsubscribed_at' => null,
        ];
        if ((int) ($input['user_id'] ?? 0) > 0) {
            $values['user_id'] = (int) $input['user_id'];
        }

        if ($existing !== null) {
            // Früher abgemeldet/unzustellbar: neue Einwilligung, neues Token.
            $values['optin_token'] = self::newToken();
            $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($values)));
            $this->db->execute("UPDATE {$this->p}newsletter_subscribers SET {$sets} WHERE id = ?", [...array_values($values), (int) $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $values['optin_token'] = self::newToken();
            $columns = array_keys($values);
            $this->db->execute(
                "INSERT INTO {$this->p}newsletter_subscribers (" . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
                array_values($values)
            );
            $id = (int) $this->db->lastInsertId();
        }
        if ($status === 'active') {
            Hooks::doAction('cms_newsletter_subscribed', $id);
        }

        return ['success' => true, 'id' => $id, 'send_confirmation' => $doubleOptIn];
    }

    public function markConfirmationSent(int $id): void
    {
        $this->db->execute("UPDATE {$this->p}newsletter_subscribers SET optin_sent_at = NOW() WHERE id = ?", [$id]);
    }

    /** Bestätigt eine Anmeldung. Liefert den Abonnenten (auch wenn er schon bestätigt war) oder null. */
    public function confirm(string $token): ?array
    {
        $subscriber = $this->subscriberByToken($token);
        if ($subscriber === null || !in_array($subscriber['status'], ['pending', 'active'], true)) {
            return null;
        }
        if ($subscriber['status'] === 'pending') {
            $this->db->execute("UPDATE {$this->p}newsletter_subscribers SET status = 'active', confirmed_at = NOW(), unsubscribed_at = NULL WHERE id = ?", [(int) $subscriber['id']]);
            Hooks::doAction('cms_newsletter_subscribed', (int) $subscriber['id']);
            $subscriber['status'] = 'active';
        }

        return $subscriber;
    }

    /** Abmeldung per Token. Liefert den Abonnenten oder null (Token unbekannt). */
    public function unsubscribe(string $token): ?array
    {
        $subscriber = $this->subscriberByToken($token);
        if ($subscriber === null) {
            return null;
        }
        $this->unsubscribeById((int) $subscriber['id']);

        return $subscriber;
    }

    public function unsubscribeById(int $id): void
    {
        $subscriber = $this->subscriber($id);
        if ($subscriber === null || $subscriber['status'] === 'unsubscribed') {
            return;
        }
        $this->db->execute("UPDATE {$this->p}newsletter_subscribers SET status = 'unsubscribed', unsubscribed_at = NOW() WHERE id = ?", [$id]);
        // Noch nicht versendete Mails laufender Kampagnen nicht mehr zustellen.
        $this->db->execute("UPDATE {$this->p}newsletter_sends SET status = 'skipped' WHERE subscriber_id = ? AND status = 'queued'", [$id]);
        Hooks::doAction('cms_newsletter_unsubscribed', $id);
    }

    public function deleteSubscriber(int $id): bool
    {
        $this->db->execute("DELETE FROM {$this->p}newsletter_sends WHERE subscriber_id = ?", [$id]);
        $deleted = $this->db->execute("DELETE FROM {$this->p}newsletter_subscribers WHERE id = ?", [$id])->rowCount() > 0;
        if ($deleted) {
            Hooks::doAction('cms_newsletter_subscriber_deleted', $id);
        }

        return $deleted;
    }

    /** Sammelaktion für ausgewählte Abonnenten. */
    public function bulkSubscribers(string $action, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        $done = 0;
        foreach ($ids as $id) {
            if ($action === 'delete') {
                $done += $this->deleteSubscriber($id) ? 1 : 0;
            } elseif ($action === 'unsubscribe') {
                $this->unsubscribeById($id);
                $done++;
            } elseif ($action === 'activate') {
                $done += !empty($this->saveSubscriber(['status' => 'active'] + ($this->subscriber($id) ?? []), $id)['success']) ? 1 : 0;
            }
        }

        return $done;
    }

    /** Unbestätigte Anmeldungen älter als `$days` Tage (Löschfrist). */
    public function countExpiredPending(int $days): int
    {
        return (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->p}newsletter_subscribers WHERE status = 'pending' AND COALESCE(optin_sent_at, created_at) < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [max(1, $days)]
        );
    }

    public function deleteExpiredPending(int $days): int
    {
        $ids = $this->db->execute(
            "SELECT id FROM {$this->p}newsletter_subscribers WHERE status = 'pending' AND COALESCE(optin_sent_at, created_at) < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 500",
            [max(1, $days)]
        )->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        $deleted = 0;
        foreach ($ids as $id) {
            $deleted += $this->deleteSubscriber((int) $id) ? 1 : 0;
        }

        return $deleted;
    }

    /** @return list<array<string, mixed>> */
    public function subscribersByEmailOrUser(string $email, int $userId): array
    {
        $email = self::normalizeEmail($email);

        return $this->db->execute(
            "SELECT * FROM {$this->p}newsletter_subscribers WHERE (? <> '' AND email = ?) OR (? > 0 AND user_id = ?)",
            [$email, $email, $userId, $userId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, int> Segment => aktive Abonnenten */
    public function segmentCounts(): array
    {
        $counts = [];
        foreach ($this->db->execute("SELECT segment_slug, COUNT(*) AS n FROM {$this->p}newsletter_subscribers WHERE status = 'active' GROUP BY segment_slug")->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $counts[(string) $row['segment_slug']] = (int) $row['n'];
        }

        return $counts;
    }

    public function countRecipients(string $segment, string $locale): int
    {
        [$where, $params] = $this->recipientWhere($segment, $locale);

        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->p}newsletter_subscribers WHERE {$where}", $params);
    }

    /** @return array{0: string, 1: list<string>} */
    public function recipientWhere(string $segment, string $locale): array
    {
        $where = ["status = 'active'"];
        $params = [];
        if ($segment !== '') {
            $where[] = 'segment_slug = ?';
            $params[] = $segment;
        }
        if (in_array($locale, ['de', 'en'], true)) {
            $where[] = 'locale = ?';
            $params[] = $locale;
        }

        return [implode(' AND ', $where), $params];
    }

    // ── Vorlagen ────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function templates(bool $activeOnly = false): array
    {
        $where = $activeOnly ? "WHERE status = 'active'" : '';

        return $this->db->execute("SELECT * FROM {$this->p}newsletter_templates {$where} ORDER BY name ASC, id ASC")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    public function template(int $id): ?array
    {
        return $this->row("SELECT * FROM {$this->p}newsletter_templates WHERE id = ? LIMIT 1", [$id]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success: bool, id?: int, error?: string}
     */
    public function saveTemplate(array $input, int $id = 0): array
    {
        $name = self::text((string) ($input['name'] ?? ''), 180);
        $subject = self::text((string) ($input['subject'] ?? ''), 255);
        if ($name === '' || $subject === '') {
            return ['success' => false, 'error' => (string) __('newsletter.error.template_required')];
        }
        $values = [
            $name,
            $subject,
            self::html((string) ($input['content_html'] ?? '')),
            self::plain((string) ($input['content_text'] ?? '')),
            in_array($input['status'] ?? '', self::TEMPLATE_STATUSES, true) ? (string) $input['status'] : 'active',
        ];
        if ($id > 0) {
            if ($this->template($id) === null) {
                return ['success' => false, 'error' => (string) __('newsletter.error.template_not_found')];
            }
            $this->db->execute("UPDATE {$this->p}newsletter_templates SET name = ?, subject = ?, content_html = ?, content_text = ?, status = ? WHERE id = ?", [...$values, $id]);

            return ['success' => true, 'id' => $id];
        }
        $this->db->execute("INSERT INTO {$this->p}newsletter_templates (name, subject, content_html, content_text, status) VALUES (?, ?, ?, ?, ?)", $values);

        return ['success' => true, 'id' => (int) $this->db->lastInsertId()];
    }

    public function deleteTemplate(int $id): bool
    {
        $this->db->execute("UPDATE {$this->p}newsletter_campaigns SET template_id = NULL WHERE template_id = ?", [$id]);

        return $this->db->execute("DELETE FROM {$this->p}newsletter_templates WHERE id = ?", [$id])->rowCount() > 0;
    }

    public function seedDefaultTemplate(): void
    {
        if ((int) $this->db->get_var("SELECT COUNT(*) FROM {$this->p}newsletter_templates") > 0) {
            return;
        }
        $this->db->execute(
            "INSERT INTO {$this->p}newsletter_templates (name, subject, content_html, content_text, status) VALUES (?, ?, ?, ?, 'active')",
            [
                'Standard',
                'Neuigkeiten von {{site_name}}',
                '<h2>Hallo {{first_name}},</h2><p>hier sind die aktuellen Neuigkeiten.</p><p><a href="{{site_url}}">Zur Website</a></p>',
                "Hallo {{first_name}},\n\nhier sind die aktuellen Neuigkeiten.\n\n{{site_url}}",
            ]
        );
    }

    // ── Kampagnen ───────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function campaigns(string $status = '', int $limit = 200): array
    {
        $where = in_array($status, self::CAMPAIGN_STATUSES, true) ? 'WHERE c.status = ?' : '';

        return $this->db->execute(
            "SELECT c.*, t.name AS template_name FROM {$this->p}newsletter_campaigns c
             LEFT JOIN {$this->p}newsletter_templates t ON t.id = c.template_id
             {$where} ORDER BY COALESCE(c.sent_at, c.scheduled_at, c.updated_at) DESC, c.id DESC LIMIT " . max(1, min(1000, $limit)),
            $where !== '' ? [$status] : []
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> Öffentliches Archiv: versendete, als öffentlich markierte Ausgaben. */
    public function publicCampaigns(int $limit = 24): array
    {
        return $this->db->execute(
            "SELECT id, subject, preview_text, sent_at, locale FROM {$this->p}newsletter_campaigns WHERE status = 'sent' AND is_public = 1 ORDER BY sent_at DESC, id DESC LIMIT " . max(1, min(200, $limit))
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    public function campaign(int $id): ?array
    {
        return $this->row("SELECT * FROM {$this->p}newsletter_campaigns WHERE id = ? LIMIT 1", [$id]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success: bool, id?: int, error?: string}
     */
    public function saveCampaign(array $input, int $id = 0, int $userId = 0): array
    {
        $existing = $id > 0 ? $this->campaign($id) : null;
        if ($id > 0 && $existing === null) {
            return ['success' => false, 'error' => (string) __('newsletter.error.campaign_not_found')];
        }
        if ($existing !== null && in_array($existing['status'], ['sending', 'sent'], true)) {
            // Versendete Ausgaben bleiben unverändert; nur die Sichtbarkeit im Archiv lässt sich ändern.
            $this->db->execute("UPDATE {$this->p}newsletter_campaigns SET is_public = ? WHERE id = ?", [!empty($input['is_public']) ? 1 : 0, $id]);

            return ['success' => true, 'id' => $id];
        }
        $name = self::text((string) ($input['name'] ?? ''), 180);
        $subject = self::text((string) ($input['subject'] ?? ''), 255);
        if ($name === '' || $subject === '') {
            return ['success' => false, 'error' => (string) __('newsletter.error.campaign_required')];
        }
        $templateId = (int) ($input['template_id'] ?? 0);
        $segment = NewsletterSettings::slug((string) ($input['segment_slug'] ?? ''));
        $locale = in_array($input['locale'] ?? '', ['de', 'en'], true) ? (string) $input['locale'] : '';
        $values = [
            'template_id' => $templateId > 0 && $this->template($templateId) !== null ? $templateId : null,
            'name' => $name,
            'subject' => $subject,
            'preview_text' => self::text((string) ($input['preview_text'] ?? ''), 255),
            'content_html' => self::html((string) ($input['content_html'] ?? '')),
            'content_text' => self::plain((string) ($input['content_text'] ?? '')),
            'segment_slug' => $segment,
            'locale' => $locale,
            'is_public' => !empty($input['is_public']) ? 1 : 0,
            'recipient_count' => $this->countRecipients($segment, $locale),
        ];
        if ($existing !== null) {
            $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($values)));
            $this->db->execute("UPDATE {$this->p}newsletter_campaigns SET {$sets} WHERE id = ?", [...array_values($values), $id]);

            return ['success' => true, 'id' => $id];
        }
        $values['status'] = 'draft';
        $values['created_by'] = $userId > 0 ? $userId : null;
        $columns = array_keys($values);
        $this->db->execute(
            "INSERT INTO {$this->p}newsletter_campaigns (" . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
            array_values($values)
        );

        return ['success' => true, 'id' => (int) $this->db->lastInsertId()];
    }

    public function setCampaignStatus(int $id, string $status, ?string $scheduledAt = null): void
    {
        if (!in_array($status, self::CAMPAIGN_STATUSES, true)) {
            return;
        }
        $this->db->execute("UPDATE {$this->p}newsletter_campaigns SET status = ?, scheduled_at = ? WHERE id = ?", [$status, $scheduledAt, $id]);
    }

    public function deleteCampaign(int $id): bool
    {
        $campaign = $this->campaign($id);
        if ($campaign === null || $campaign['status'] === 'sending') {
            return false;
        }
        $this->db->execute("DELETE FROM {$this->p}newsletter_sends WHERE campaign_id = ?", [$id]);

        return $this->db->execute("DELETE FROM {$this->p}newsletter_campaigns WHERE id = ?", [$id])->rowCount() > 0;
    }

    /** @return array<string, int> Status => Anzahl Versandzeilen */
    public function sendCounts(int $campaignId): array
    {
        $counts = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($this->db->execute("SELECT status, COUNT(*) AS n FROM {$this->p}newsletter_sends WHERE campaign_id = ? GROUP BY status", [$campaignId])->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /** @return list<array<string, mixed>> */
    public function failedSends(int $campaignId, int $limit = 20): array
    {
        return $this->db->execute(
            "SELECT s.id, s.last_error, s.created_at, sub.email FROM {$this->p}newsletter_sends s
             LEFT JOIN {$this->p}newsletter_subscribers sub ON sub.id = s.subscriber_id
             WHERE s.campaign_id = ? AND s.status = 'failed' ORDER BY s.id DESC LIMIT " . max(1, min(200, $limit)),
            [$campaignId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────

    /** @param list<mixed> $params @return array<string, mixed>|null */
    private function row(string $sql, array $params): ?array
    {
        $row = $this->db->execute($sql, $params)->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function fireStatusHooks(int $id, string $previous, string $status): void
    {
        if ($status === 'active' && $previous !== 'active') {
            Hooks::doAction('cms_newsletter_subscribed', $id);
        } elseif ($status === 'unsubscribed' && $previous !== 'unsubscribed') {
            $this->db->execute("UPDATE {$this->p}newsletter_sends SET status = 'skipped' WHERE subscriber_id = ? AND status = 'queued'", [$id]);
            Hooks::doAction('cms_newsletter_unsubscribed', $id);
        }
    }

    public static function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));

        return strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    }

    public static function normalizeToken(string $token): string
    {
        $token = (string) preg_replace('/[^a-f0-9]/i', '', trim($token));

        return strlen($token) >= 32 && strlen($token) <= 120 ? strtolower($token) : '';
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    public static function text(string $value, int $max): string
    {
        $value = (string) preg_replace('/[[:cntrl:]]+/u', ' ', strip_tags($value));

        return mb_substr(trim($value), 0, $max);
    }

    public static function plain(string $value): string
    {
        $value = str_replace("\r\n", "\n", strip_tags($value));

        return mb_substr(trim((string) preg_replace('/[^\P{C}\n\t]+/u', '', $value)), 0, 100000);
    }

    /** HTML-Inhalt für Mails: Profil „default“ des Core-Purifiers (keine Skripte, keine Inline-Stile). */
    public static function html(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        // Platzhalter in Links (href="{{unsubscribe_url}}") übersteht der Purifier nicht – vorher maskieren.
        $map = [];
        $value = (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static function (array $m) use (&$map): string {
            $key = 'https://placeholder.invalid/' . $m[1];
            $map[$key] = '{{' . $m[1] . '}}';

            return $key;
        }, $value);
        $clean = PurifierService::getInstance()->purify($value, 'default');

        return mb_substr(strtr($clean, $map), 0, 200000);
    }
}
