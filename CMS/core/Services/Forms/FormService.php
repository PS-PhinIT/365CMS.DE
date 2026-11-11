<?php
/**
 * Formulardefinitionen: Anlegen, Bearbeiten, Laden – inklusive eingebautem Kontaktformular.
 *
 * Ein Formular besteht aus Feldern (`fields_json`, siehe `FormFieldTypes`) und Einstellungen
 * (`settings_json`, siehe `normalizeSettings()`). Das Kontaktformular (`kontakt`) ist die erste eingebaute
 * Definition und wird bei Bedarf idempotent angelegt; es gibt keinen Kontakt-Sonderweg.
 *
 * @package CMSv2\Core\Services\Forms
 */

declare(strict_types=1);

namespace CMS\Services\Forms;

use CMS\Database;

if (!defined('ABSPATH')) {
    exit;
}

final class FormService
{
    public const CONTACT_FORM_KEY = 'kontakt';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_TRASH = 'trash';
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    public const LAYOUTS = ['standard', 'compact', 'wide', 'split'];
    public const ANTISPAM_LEVELS = ['low', 'standard', 'high'];

    public const KEY_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    private static ?self $instance = null;

    private Database $db;
    private string $table;

    /** @var array<int, array<string, mixed>> */
    private array $cache = [];

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $this->table = $this->db->getPrefix() . 'form_forms';
    }

    public function table(): string
    {
        return $this->table;
    }

    public function isAvailable(): bool
    {
        try {
            return $this->db->tableExists($this->table);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        $row = $this->db->get_row("SELECT * FROM {$this->table} WHERE id = ? LIMIT 1", [$id]);

        return $row ? ($this->cache[$id] = self::hydrate($row)) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByKey(string $key): ?array
    {
        $key = strtolower(trim($key));
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            return null;
        }

        $row = $this->db->get_row("SELECT * FROM {$this->table} WHERE form_key = ? LIMIT 1", [$key]);
        if (!$row) {
            return null;
        }
        $form = self::hydrate($row);

        return $this->cache[(int) $form['id']] = $form;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByLegacyId(int $legacyId): ?array
    {
        $row = $this->db->get_row("SELECT * FROM {$this->table} WHERE legacy_id = ? LIMIT 1", [$legacyId]);

        return $row ? self::hydrate($row) : null;
    }

    /**
     * Öffentlich nutzbares Formular (aktiv, nicht im Papierkorb).
     *
     * @return array<string, mixed>|null
     */
    public function findPublic(string $key): ?array
    {
        $form = $this->findByKey($key);

        return $form !== null && $form['status'] === self::STATUS_ACTIVE ? $form : null;
    }

    /**
     * @param array{status?: string, search?: string, include_trash?: bool} $filter
     * @return list<array<string, mixed>>
     */
    public function list(array $filter = [], int $offset = 0, int $limit = 200): array
    {
        [$where, $params] = $this->buildWhere($filter);
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $rows = $this->db->get_results(
            "SELECT * FROM {$this->table} WHERE {$where} ORDER BY is_builtin DESC, title ASC, id ASC LIMIT {$limit} OFFSET {$offset}",
            $params
        ) ?: [];

        return array_map(static fn(object $row): array => self::hydrate($row), $rows);
    }

    /**
     * @param array{status?: string, search?: string, include_trash?: bool} $filter
     */
    public function count(array $filter = []): int
    {
        [$where, $params] = $this->buildWhere($filter);

        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->table} WHERE {$where}", $params);
    }

    /**
     * Auswahlliste für Block und Filter: Schlüssel → Titel.
     *
     * @return array<string, string>
     */
    public function choices(bool $activeOnly = true): array
    {
        $choices = [];
        foreach ($this->list($activeOnly ? ['status' => self::STATUS_ACTIVE] : []) as $form) {
            $choices[(string) $form['form_key']] = (string) $form['title'];
        }

        return $choices;
    }

    /**
     * Auswahlliste für Filter im Adminbereich: ID → Titel (ohne Papierkorb).
     *
     * @return array<int, string>
     */
    public function choicesById(): array
    {
        $choices = [];
        foreach ($this->list([], 0, 500) as $form) {
            $choices[(int) $form['id']] = (string) $form['title'];
        }

        return $choices;
    }

    /**
     * Legt ein Formular an.
     *
     * @param array<string, mixed> $data title, form_key, status, fields, settings, is_builtin, legacy_id, created_at
     * @return array{success: bool, id?: int, error?: string}
     */
    public function create(array $data, int $userId = 0): array
    {
        $title = FormI18n::cleanText((string) ($data['title'] ?? ''), 255);
        if ($title === '') {
            return ['success' => false, 'error' => __('forms.admin.error_title_required')];
        }

        $key = self::normalizeKey((string) ($data['form_key'] ?? ''));
        $key = $this->uniqueKey($key !== '' ? $key : self::normalizeKey($title), 0);
        $status = in_array($data['status'] ?? '', self::STATUSES, true) ? (string) $data['status'] : self::STATUS_ACTIVE;

        $row = [
            'form_key' => $key,
            'title' => $title,
            'status' => $status,
            'is_builtin' => !empty($data['is_builtin']) ? 1 : 0,
            'fields_json' => self::encode(FormFieldTypes::normalizeFields($data['fields'] ?? [])),
            'settings_json' => self::encode(self::normalizeSettings($data['settings'] ?? [])),
            'created_by' => $userId > 0 ? $userId : null,
        ];
        if (isset($data['legacy_id']) && (int) $data['legacy_id'] > 0) {
            $row['legacy_id'] = (int) $data['legacy_id'];
        }
        if (!empty($data['created_at']) && self::isDateTime((string) $data['created_at'])) {
            $row['created_at'] = (string) $data['created_at'];
            $row['updated_at'] = (string) $data['created_at'];
        }

        try {
            $id = $this->db->insert('form_forms', $row);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => __('forms.admin.error_save')];
        }
        if (!$id) {
            return ['success' => false, 'error' => __('forms.admin.error_save')];
        }

        return ['success' => true, 'id' => (int) $id, 'form_key' => $key];
    }

    /**
     * @param array<string, mixed> $data title, form_key, status, fields, settings
     * @return array{success: bool, error?: string}
     */
    public function update(int $id, array $data): array
    {
        $form = $this->find($id);
        if ($form === null || $form['status'] === self::STATUS_TRASH) {
            return ['success' => false, 'error' => __('forms.admin.error_not_found')];
        }

        $row = [];
        if (array_key_exists('title', $data)) {
            $title = FormI18n::cleanText((string) $data['title'], 255);
            if ($title === '') {
                return ['success' => false, 'error' => __('forms.admin.error_title_required')];
            }
            $row['title'] = $title;
        }
        if (array_key_exists('form_key', $data) && !$form['is_builtin']) {
            $key = self::normalizeKey((string) $data['form_key']);
            if ($key === '') {
                return ['success' => false, 'error' => __('forms.admin.error_key_invalid')];
            }
            if ($key !== $form['form_key']) {
                if ($this->keyExists($key, $id)) {
                    return ['success' => false, 'error' => __('forms.admin.error_key_taken')];
                }
                $row['form_key'] = $key;
            }
        }
        if (array_key_exists('status', $data) && in_array($data['status'], self::STATUSES, true)) {
            $row['status'] = (string) $data['status'];
        }
        if (array_key_exists('fields', $data)) {
            $row['fields_json'] = self::encode(FormFieldTypes::normalizeFields($data['fields']));
        }
        if (array_key_exists('settings', $data)) {
            $row['settings_json'] = self::encode(self::normalizeSettings($data['settings']));
        }
        if ($row === []) {
            return ['success' => true];
        }

        $row['updated_at'] = date('Y-m-d H:i:s');
        $ok = $this->db->update('form_forms', $row, ['id' => $id]);
        unset($this->cache[$id]);

        return $ok !== false ? ['success' => true] : ['success' => false, 'error' => __('forms.admin.error_save')];
    }

    /**
     * @return array{success: bool, id?: int, error?: string}
     */
    public function duplicate(int $id, int $userId = 0): array
    {
        $form = $this->find($id);
        if ($form === null) {
            return ['success' => false, 'error' => __('forms.admin.error_not_found')];
        }

        return $this->create([
            'title' => sprintf(__('forms.admin.copy_title'), $form['title']),
            'form_key' => $form['form_key'] . '-copy',
            'status' => self::STATUS_INACTIVE,
            'fields' => $form['fields'],
            'settings' => $form['settings'],
        ], $userId);
    }

    /**
     * In den Papierkorb legen (eingebaute Formulare sind ausgenommen).
     */
    public function trash(int $id, int $userId): bool
    {
        $form = $this->find($id);
        if ($form === null || $form['is_builtin'] || $form['status'] === self::STATUS_TRASH) {
            return false;
        }

        $ok = $this->db->update('form_forms', [
            'previous_status' => $form['status'],
            'status' => self::STATUS_TRASH,
            'trashed_at' => date('Y-m-d H:i:s'),
            'trashed_by' => $userId > 0 ? $userId : null,
        ], ['id' => $id]);
        unset($this->cache[$id]);

        return $ok !== false;
    }

    public function restore(int $id): bool
    {
        $form = $this->find($id);
        if ($form === null || $form['status'] !== self::STATUS_TRASH) {
            return false;
        }

        $previous = in_array($form['previous_status'], self::STATUSES, true) ? $form['previous_status'] : self::STATUS_INACTIVE;
        $ok = $this->db->update('form_forms', [
            'status' => $previous,
            'previous_status' => null,
            'trashed_at' => null,
            'trashed_by' => null,
        ], ['id' => $id]);
        unset($this->cache[$id]);

        return $ok !== false;
    }

    /**
     * Endgültig löschen inklusive Eingänge und hochgeladener Dateien.
     */
    public function deletePermanently(int $id): bool
    {
        $form = $this->find($id);
        if ($form === null || $form['is_builtin']) {
            return false;
        }

        FormSubmissionService::getInstance()->deleteAllForForm($id);
        $ok = $this->db->delete('form_forms', ['id' => $id]);
        unset($this->cache[$id]);

        return $ok !== false;
    }

    /**
     * Legt das eingebaute Kontaktformular an, falls es fehlt (idempotent, auch bei parallelen Aufrufen).
     *
     * @return array<string, mixed>|null
     */
    public function ensureContactForm(): ?array
    {
        $existing = $this->findByKey(self::CONTACT_FORM_KEY);
        if ($existing !== null) {
            return $existing;
        }
        if (!$this->isAvailable()) {
            return null;
        }

        $definition = self::defaultContactDefinition();
        try {
            $this->db->insert('form_forms', [
                'form_key' => self::CONTACT_FORM_KEY,
                'title' => $definition['title'],
                'status' => self::STATUS_ACTIVE,
                'is_builtin' => 1,
                'fields_json' => self::encode(FormFieldTypes::normalizeFields($definition['fields'])),
                'settings_json' => self::encode(self::normalizeSettings($definition['settings'])),
            ]);
        } catch (\Throwable) {
            // Parallel angelegt (eindeutiger Schlüssel) – unten erneut laden.
        }

        return $this->findByKey(self::CONTACT_FORM_KEY);
    }

    /**
     * Formular für `/contact` bzw. `/kontakt`: Einstellung `contact_form_key`, sonst das eingebaute.
     * Optional wählt `?expert`, `?speaker` oder `?company` ein aus dem Plugin übernommenes Buchungsformular.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>|null
     */
    public function contactForm(array $query = []): ?array
    {
        $legacyTemplates = ['expert' => 'booking-expert', 'speaker' => 'booking-event', 'company' => 'booking-service'];
        foreach ($legacyTemplates as $param => $template) {
            if (empty($query[$param])) {
                continue;
            }
            foreach ($this->list(['status' => self::STATUS_ACTIVE]) as $form) {
                if (($form['settings']['legacy_template'] ?? '') === $template) {
                    return $form;
                }
            }
        }

        $key = FormSettings::string('contact_form_key', self::CONTACT_FORM_KEY);
        if ($key !== self::CONTACT_FORM_KEY) {
            $form = $this->findPublic($key);
            if ($form !== null) {
                return $form;
            }
        }

        $builtin = $this->ensureContactForm();

        return $builtin !== null && $builtin['status'] === self::STATUS_ACTIVE ? $builtin : null;
    }

    /**
     * Ausgelieferte Definition des Kontaktformulars (Felder wie bisher im Theme, ergänzt um die Einwilligung).
     *
     * @return array{title: string, fields: list<array<string, mixed>>, settings: array<string, mixed>}
     */
    public static function defaultContactDefinition(): array
    {
        return [
            'title' => 'Kontakt',
            'fields' => [
                [
                    'type' => 'text', 'name' => 'name', 'role' => 'name', 'required' => true, 'width' => 'half',
                    'label' => ['de' => 'Name', 'en' => 'Name'],
                    'placeholder' => ['de' => 'Dein Name', 'en' => 'Your name'],
                    'validation' => ['max_length' => 100],
                ],
                [
                    'type' => 'email', 'name' => 'email', 'role' => 'email', 'required' => true, 'width' => 'half',
                    'label' => ['de' => 'E-Mail', 'en' => 'Email'],
                    'placeholder' => ['de' => 'deine@email.de', 'en' => 'you@example.com'],
                    'validation' => ['max_length' => 200],
                ],
                [
                    'type' => 'text', 'name' => 'subject', 'role' => 'subject', 'required' => false, 'width' => 'full',
                    'label' => ['de' => 'Betreff', 'en' => 'Subject'],
                    'placeholder' => ['de' => 'Worum geht es?', 'en' => 'What is it about?'],
                    'validation' => ['max_length' => 200],
                ],
                [
                    'type' => 'textarea', 'name' => 'message', 'role' => 'message', 'required' => true, 'width' => 'full',
                    'label' => ['de' => 'Nachricht', 'en' => 'Message'],
                    'placeholder' => ['de' => 'Deine Nachricht …', 'en' => 'Your message …'],
                    'validation' => ['min_length' => 20, 'max_length' => 5000],
                ],
                [
                    'type' => 'consent', 'name' => 'privacy_consent', 'required' => true, 'width' => 'full',
                    'label' => ['de' => '', 'en' => ''],
                ],
            ],
            'settings' => [
                'submit_label' => ['de' => 'Nachricht senden', 'en' => 'Send message'],
                'success_message' => [
                    'de' => 'Deine Nachricht wurde gesendet. Wir melden uns in Kürze!',
                    'en' => 'Your message has been sent. We will get back to you shortly!',
                ],
                'mail_subject' => '[{form_title}] {subject}',
                'antispam_level' => 'standard',
                'rate_limit' => 5,
                'store_submissions' => true,
                'notify' => true,
            ],
        ];
    }

    /**
     * Formular-Einstellungen normalisieren (unbekannte Schlüssel fallen weg).
     *
     * @return array<string, mixed>
     */
    public static function normalizeSettings(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $raw = is_array($raw) ? $raw : [];
        $confirmation = is_array($raw['confirmation'] ?? null) ? $raw['confirmation'] : [];
        $flag = static fn(string $key, bool $default): bool => array_key_exists($key, $raw) ? !empty($raw[$key]) && $raw[$key] !== '0' : $default;
        $layout = (string) ($raw['layout'] ?? 'standard');
        $level = (string) ($raw['antispam_level'] ?? 'standard');
        $legacyTemplate = strtolower(trim((string) ($raw['legacy_template'] ?? '')));

        return [
            'heading' => FormI18n::texts($raw['heading'] ?? '', 200),
            'intro' => FormI18n::texts($raw['intro'] ?? '', 20000, true),
            'footer' => FormI18n::texts($raw['footer'] ?? '', 20000, true),
            'submit_label' => FormI18n::texts($raw['submit_label'] ?? '', 100),
            'success_message' => FormI18n::texts($raw['success_message'] ?? '', 2000, true),
            'redirect_url' => self::normalizeRedirectUrl((string) ($raw['redirect_url'] ?? '')),
            'recipients' => self::normalizeEmails($raw['recipients'] ?? []),
            'cc' => self::normalizeEmails($raw['cc'] ?? []),
            'bcc' => self::normalizeEmails($raw['bcc'] ?? []),
            'mail_subject' => FormI18n::cleanText((string) ($raw['mail_subject'] ?? ''), 300),
            'notify' => $flag('notify', true),
            'reply_to_sender' => $flag('reply_to_sender', true),
            'confirmation' => [
                'enabled' => !empty($confirmation['enabled']) && $confirmation['enabled'] !== '0',
                'subject' => FormI18n::texts($confirmation['subject'] ?? '', 300),
                'body' => FormI18n::texts($confirmation['body'] ?? '', 10000, true),
            ],
            'antispam_level' => in_array($level, self::ANTISPAM_LEVELS, true) ? $level : 'standard',
            'rate_limit' => max(0, min(1000, (int) ($raw['rate_limit'] ?? 5))),
            'store_submissions' => $flag('store_submissions', true),
            'retention_days' => array_key_exists('retention_days', $raw) ? FormSettings::normalizeRetentionDays($raw['retention_days']) : null,
            'layout' => in_array($layout, self::LAYOUTS, true) ? $layout : 'standard',
            'custom_css' => self::sanitizeCss((string) ($raw['custom_css'] ?? '')),
            'show_company_info' => $flag('show_company_info', false),
            'legacy_template' => preg_match('/^[a-z0-9-]{1,40}$/', $legacyTemplate) === 1 ? $legacyTemplate : '',
        ];
    }

    /**
     * @return list<string>
     */
    public static function normalizeEmails(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value) ?: [];
        }
        $emails = [];
        foreach (is_array($value) ? $value : [] as $email) {
            $email = strtolower(trim((string) $email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[$email] = $email;
            }
        }

        return array_slice(array_values($emails), 0, 20);
    }

    /** Weiterleitung nur auf eigene Pfade oder die eigene Domain. */
    public static function normalizeRedirectUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !FormFieldTypes::isSafeUrl($url)) {
            return '';
        }
        if (str_starts_with($url, '/')) {
            return $url;
        }

        $siteHost = defined('SITE_URL') ? strtolower((string) parse_url((string) SITE_URL, PHP_URL_HOST)) : '';
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $siteHost !== '' && $host === $siteHost ? $url : '';
    }

    public static function sanitizeCss(string $css): string
    {
        $css = trim(str_replace('<', '', strip_tags($css)));
        if ($css === '') {
            return '';
        }
        $css = function_exists('mb_substr') ? mb_substr($css, 0, 4000, 'UTF-8') : substr($css, 0, 4000);
        $css = (string) preg_replace('/@import\b[^;]*;?/i', '', $css);
        $css = (string) preg_replace('/@charset\s+[^;]+;/i', '', $css);
        $css = (string) preg_replace('/expression\s*\(/i', '', $css);
        $css = (string) preg_replace('/(?:java|vb)script\s*:/i', '', $css);
        // Nur eigene, relative Ressourcen erlauben (keine externen Abrufe, keine data:/javascript:-URLs).
        $css = (string) preg_replace_callback('/url\s*\(\s*(["\']?)(.*?)\1\s*\)/i', static function (array $match): string {
            $target = trim($match[2]);
            $local = preg_match('#^(?:/(?!/)|\./|[a-z0-9_-]+/)[a-z0-9_./%-]*$#i', $target) === 1;

            return $local ? 'url("' . $target . '")' : 'none';
        }, $css);
        $css = (string) preg_replace('/(?:behavior|-moz-binding)\s*:/i', 'blocked-property:', $css);

        return trim($css);
    }

    public static function normalizeKey(string $key): string
    {
        $key = strtr(mb_strtolower(trim($key), 'UTF-8'), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', '_' => '-', ' ' => '-']);
        $key = (string) preg_replace('/[^a-z0-9-]+/', '-', $key);
        $key = trim((string) preg_replace('/-+/', '-', $key), '-');

        return substr($key, 0, 64);
    }

    public function keyExists(string $key, int $ignoreId = 0): bool
    {
        return (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE form_key = ? AND id <> ?",
            [$key, $ignoreId]
        ) > 0;
    }

    public function uniqueKey(string $base, int $ignoreId = 0): string
    {
        $base = $base !== '' ? substr($base, 0, 58) : 'formular';
        $key = $base;
        $suffix = 2;
        while ($this->keyExists($key, $ignoreId)) {
            $key = $base . '-' . $suffix++;
        }

        return $key;
    }

    /**
     * @return array<string, mixed>
     */
    public static function hydrate(object|array $row): array
    {
        $row = (array) $row;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'form_key' => (string) ($row['form_key'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'status' => (string) ($row['status'] ?? self::STATUS_ACTIVE),
            'is_builtin' => !empty($row['is_builtin']),
            'fields' => FormFieldTypes::normalizeFields((string) ($row['fields_json'] ?? '[]')),
            'settings' => self::normalizeSettings((string) ($row['settings_json'] ?? '{}')),
            'legacy_id' => isset($row['legacy_id']) ? (int) $row['legacy_id'] : null,
            'previous_status' => isset($row['previous_status']) ? (string) $row['previous_status'] : null,
            'trashed_at' => isset($row['trashed_at']) ? (string) $row['trashed_at'] : null,
            'trashed_by' => isset($row['trashed_by']) ? (int) $row['trashed_by'] : null,
            'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    public function forgetCache(): void
    {
        $this->cache = [];
    }

    public static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function isDateTime(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1;
    }

    /**
     * @param array{status?: string, search?: string, include_trash?: bool} $filter
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(array $filter): array
    {
        $where = ['1=1'];
        $params = [];
        $status = (string) ($filter['status'] ?? '');
        if ($status === self::STATUS_TRASH) {
            $where[] = 'status = ?';
            $params[] = self::STATUS_TRASH;
        } elseif (in_array($status, self::STATUSES, true)) {
            $where[] = 'status = ?';
            $params[] = $status;
        } elseif (empty($filter['include_trash'])) {
            $where[] = 'status <> ?';
            $params[] = self::STATUS_TRASH;
        }

        $search = trim((string) ($filter['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(title LIKE ? OR form_key LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        return [implode(' AND ', $where), $params];
    }
}
