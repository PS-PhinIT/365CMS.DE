<?php
/**
 * Übernahme der Daten des Plugins cms-contact in die Core-Formulare.
 *
 * Quelle: `contact_forms`, `contact_fields`, `contact_submissions`, `contact_submission_meta`, `contact_settings`.
 * Die Plugin-Tabellen bleiben unverändert stehen. Idempotent über `legacy_id` (Formulare, Eingänge) und einen
 * gespeicherten Fortschritt; Eingänge werden in Blöcken übernommen.
 *
 * Nicht übernommen werden IP-Adresse und User-Agent der Eingänge sowie die Sicherheitsereignisse des Plugins
 * (Datenminimierung; abgewiesene Versuche stehen künftig im Sicherheitsprotokoll des Cores).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\PluginMigration;

use CMS\Database;
use CMS\Logger;
use CMS\Services\Forms\FormFieldTypes;
use CMS\Services\Forms\FormService;
use CMS\Services\Forms\FormSettings;
use CMS\Services\Forms\FormSubmissionService;
use CMS\Services\SettingsService;

if (!defined('ABSPATH')) {
    exit;
}

final class ContactPluginMigrator implements PluginMigrator
{
    private const STATE_OPTION = 'forms_contact_migration';
    private const BATCH = 200;

    private const STATUS_MAP = ['unread' => 'new', 'read' => 'read', 'replied' => 'done', 'archived' => 'done', 'spam' => 'trash'];

    private const LAYOUT_MAP = [
        'classic' => 'standard', 'modern' => 'standard', 'minimal' => 'compact', 'fullwidth' => 'wide',
        'split' => 'split', 'business' => 'split',
    ];

    private Database $db;
    private string $prefix;

    /** @var array<string, int> */
    private array $report = ['forms' => 0, 'submissions' => 0];

    public function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    public function pluginSlug(): string
    {
        return 'cms-contact';
    }

    public function hasSourceData(): bool
    {
        try {
            return $this->db->tableExists($this->prefix . 'contact_forms');
        } catch (\Throwable) {
            return false;
        }
    }

    public function migrate(): bool
    {
        $forms = FormService::getInstance();
        if (!$forms->isAvailable()) {
            return false;
        }

        $state = $this->state();
        $this->report = [
            'forms' => (int) ($state['forms'] ?? 0),
            'submissions' => (int) ($state['submissions'] ?? 0),
            'forms_failed' => (int) ($state['forms_failed'] ?? 0),
            'submissions_skipped' => (int) ($state['submissions_skipped'] ?? 0),
        ];
        $pluginSettings = $this->pluginSettings();

        if (empty($state['settings_done'])) {
            $this->migrateSettings($pluginSettings);
            $state['settings_done'] = true;
            $this->saveState($state);
        }

        if (empty($state['forms_done'])) {
            $this->migrateForms($pluginSettings);
            $state['forms_done'] = true;
            $state['forms'] = $this->report['forms'];
            $state['forms_failed'] = $this->report['forms_failed'];
            $this->saveState($state);
        }

        if (empty($state['submissions_done'])) {
            $cursor = (int) ($state['submission_cursor'] ?? 0);
            $next = $this->migrateSubmissions($cursor);
            $state['submissions'] = $this->report['submissions'];
            $state['submissions_skipped'] = $this->report['submissions_skipped'];
            if ($next === null) {
                $state['submissions_done'] = true;
            } else {
                $state['submission_cursor'] = $next;
            }
            $this->saveState($state);

            return $next === null;
        }

        return true;
    }

    public function report(): array
    {
        return $this->report;
    }

    // ── Einstellungen ────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $plugin
     */
    private function migrateSettings(array $plugin): void
    {
        if ($plugin === []) {
            return;
        }

        $current = FormSettings::all();
        $defaults = FormSettings::defaults();
        $values = [];
        // Nur Werte übernehmen, die im Core noch auf dem Standard stehen (erneuter Lauf überschreibt nichts).
        $take = static function (string $key, string $value) use (&$values, $current, $defaults): void {
            if ($value !== '' && (string) $current[$key] === (string) $defaults[$key]) {
                $values[$key] = $value;
            }
        };

        // Das Plugin nutzte zuerst `admin_email` (Einstellungsseite ab 3.x), dann das ältere `global_recipient`.
        $recipient = '';
        foreach (['admin_email', 'global_recipient'] as $recipientKey) {
            $candidate = trim((string) ($plugin[$recipientKey] ?? ''));
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                $recipient = $candidate;
                break;
            }
        }
        $take('default_recipient', $recipient);
        $take('from_name', trim((string) ($plugin['from_name'] ?? '')));
        $take('privacy_url', FormFieldTypes::isSafeUrl(trim((string) ($plugin['privacy_policy_url'] ?? ''))) ? trim((string) $plugin['privacy_policy_url']) : '');
        $color = trim((string) ($plugin['primary_color'] ?? ''));
        $take('accent_color', preg_match('/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', $color) === 1 ? strtolower($color) : '');
        if (isset($plugin['auto_delete_days']) && is_numeric($plugin['auto_delete_days'])) {
            $days = FormSettings::normalizeRetentionDays((int) $plugin['auto_delete_days'], false);
            if ($days !== null && (int) $current['retention_days'] === FormSettings::DEFAULT_RETENTION_DAYS) {
                $values['retention_days'] = $days;
            }
        }

        if ($values !== []) {
            SettingsService::getInstance()->setMany(FormSettings::GROUP, $values);
            FormSettings::flush();
        }
    }

    // ── Formulare ────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $pluginSettings
     */
    private function migrateForms(array $pluginSettings): void
    {
        $forms = FormService::getInstance();
        $rows = $this->db->get_results("SELECT * FROM {$this->prefix}contact_forms ORDER BY id ASC") ?: [];
        $hasFields = $this->db->tableExists($this->prefix . 'contact_fields');
        $requireConsent = ($pluginSettings['require_privacy_consent'] ?? '1') === '1';
        $store = ($pluginSettings['store_submissions'] ?? '1') === '1';
        $notify = ($pluginSettings['enable_notifications'] ?? '1') === '1';

        foreach ($rows as $row) {
            $legacyId = (int) $row->id;
            if ($forms->findByLegacyId($legacyId) !== null) {
                continue;
            }

            $fields = [];
            if ($hasFields) {
                $fieldRows = $this->db->get_results(
                    "SELECT * FROM {$this->prefix}contact_fields WHERE form_id = ? ORDER BY field_order ASC, id ASC",
                    [$legacyId]
                ) ?: [];
                foreach ($fieldRows as $field) {
                    $fields[] = $this->mapField($field);
                }
            }
            if ($requireConsent) {
                $fields[] = ['type' => 'consent', 'name' => 'privacy_consent', 'required' => true, 'label' => ['de' => '', 'en' => '']];
            }

            $settings = $this->mapSettings($row, $store, $notify, ($pluginSettings['send_confirmation'] ?? '0') === '1', (string) ($pluginSettings['from_name'] ?? ''));
            $key = FormService::normalizeKey((string) ($row->slug ?? ''));
            $status = (string) ($row->status ?? 'active') === 'active' ? FormService::STATUS_ACTIVE : FormService::STATUS_INACTIVE;

            // Das Plugin-Formular „kontakt“ ersetzt die noch unveränderte eingebaute Definition.
            $builtin = $key === FormService::CONTACT_FORM_KEY ? $forms->findByKey($key) : null;
            if ($builtin !== null && $builtin['is_builtin'] && $builtin['legacy_id'] === null) {
                $forms->update((int) $builtin['id'], [
                    'title' => (string) $row->title,
                    'status' => $status,
                    'fields' => $fields,
                    'settings' => $settings,
                ]);
                $this->db->update('form_forms', ['legacy_id' => $legacyId], ['id' => (int) $builtin['id']]);
                $forms->forgetCache();
                $this->report['forms']++;
                continue;
            }

            $result = $forms->create([
                'title' => (string) $row->title !== '' ? (string) $row->title : 'Formular ' . $legacyId,
                'form_key' => $key,
                'status' => $status,
                'fields' => $fields,
                'settings' => $settings,
                'legacy_id' => $legacyId,
                'created_at' => (string) ($row->created_at ?? ''),
            ]);
            if (!empty($result['success'])) {
                $this->report['forms']++;
            } else {
                $this->report['forms_failed']++;
                Logger::instance()->withChannel('plugins.integration')->warning('cms-contact: Formular konnte nicht übernommen werden.', ['legacy_id' => $legacyId]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mapField(object $field): array
    {
        $options = [];
        $rawOptions = (string) ($field->options_json ?? '');
        if ($rawOptions !== '') {
            $decoded = json_decode($rawOptions, true);
            $options = is_array($decoded) ? $decoded : [];
        }

        return [
            'type' => (string) ($field->field_type ?? 'text'),
            'name' => (string) ($field->field_name ?? ''),
            'label' => ['de' => (string) ($field->field_label ?? ''), 'en' => ''],
            'placeholder' => ['de' => (string) ($field->placeholder ?? ''), 'en' => ''],
            'help' => ['de' => (string) ($field->description ?? ''), 'en' => ''],
            'default' => (string) ($field->default_value ?? ''),
            'required' => !empty($field->is_required),
            'width' => (string) ($field->field_width ?? 'full'),
            'css_class' => (string) ($field->css_class ?? ''),
            'options' => $options,
            'validation' => ['pattern' => (string) ($field->validation ?? '')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapSettings(object $row, bool $store, bool $notify, bool $confirmation = false, string $fromName = ''): array
    {
        $template = strtolower(trim((string) ($row->template ?? 'classic')));
        $layout = self::LAYOUT_MAP[$template] ?? 'standard';
        $prefix = trim((string) ($row->subject_prefix ?? ''));
        $level = !empty($row->enable_captcha) ? 'high' : (!empty($row->enable_honeypot) ? 'standard' : 'low');
        $rateLimit = (int) ($row->rate_limit ?? 3);
        if ($confirmation) {
            // Eine Bestätigung an frei eingegebene Adressen nie ohne Mindestzeit und Rate-Limit übernehmen.
            $level = $level === 'low' ? 'standard' : $level;
            $rateLimit = $rateLimit > 0 ? $rateLimit : 3;
        }

        return [
            'heading' => ['de' => (string) ($row->title ?? ''), 'en' => ''],
            'intro' => ['de' => (string) ($row->description ?? ''), 'en' => ''],
            'footer' => ['de' => (string) ($row->footer_description ?? ''), 'en' => ''],
            'success_message' => ['de' => (string) ($row->success_message ?? ''), 'en' => ''],
            'redirect_url' => (string) ($row->redirect_url ?? ''),
            'recipients' => (string) ($row->recipient ?? ''),
            'cc' => (string) ($row->cc_recipients ?? ''),
            'mail_subject' => $prefix !== '' ? $prefix . ' {subject}' : '',
            'notify' => $notify,
            'reply_to_sender' => true,
            // Das Plugin schickte – global geschaltet – eine feste Eingangsbestätigung an den Absender.
            'confirmation' => [
                'enabled' => $confirmation,
                'subject' => ['de' => 'Ihre Kontaktanfrage wurde empfangen', 'en' => ''],
                'body' => ['de' => "Hallo {name},\n\nvielen Dank für Ihre Nachricht! Wir haben Ihre Anfrage erhalten und werden uns schnellstmöglich bei Ihnen melden.\n\nMit freundlichen Grüßen\n" . (trim($fromName) !== '' ? trim($fromName) : '{site_name}'), 'en' => ''],
            ],
            'antispam_level' => $level,
            'rate_limit' => $rateLimit,
            'store_submissions' => $store,
            'layout' => $layout,
            'show_company_info' => $layout === 'split',
            'custom_css' => (string) ($row->custom_css ?? ''),
            'legacy_template' => str_starts_with($template, 'booking-') ? $template : '',
        ];
    }

    // ── Eingänge ─────────────────────────────────────────────────────────────

    /**
     * Übernimmt den nächsten Block. Liefert den neuen Cursor oder null, wenn alles übernommen ist.
     */
    private function migrateSubmissions(int $cursor): ?int
    {
        if (!$this->db->tableExists($this->prefix . 'contact_submissions')) {
            return null;
        }

        $rows = $this->db->get_results(
            "SELECT * FROM {$this->prefix}contact_submissions WHERE id > ? ORDER BY id ASC LIMIT " . self::BATCH,
            [$cursor]
        ) ?: [];
        if ($rows === []) {
            return null;
        }

        $forms = FormService::getInstance();
        $submissions = FormSubmissionService::getInstance();
        $hasMeta = $this->db->tableExists($this->prefix . 'contact_submission_meta');
        $formCache = [];

        foreach ($rows as $row) {
            $legacyId = (int) $row->id;
            $cursor = $legacyId;
            $exists = (int) $this->db->get_var("SELECT COUNT(*) FROM {$submissions->table()} WHERE legacy_id = ?", [$legacyId]);
            if ($exists > 0) {
                continue;
            }

            $formLegacyId = (int) $row->form_id;
            $form = $formCache[$formLegacyId] ??= $forms->findByLegacyId($formLegacyId);
            if ($form === null) {
                // Formular wurde nicht übernommen: Eingang zählen, damit die Lücke im Bericht sichtbar ist.
                $this->report['submissions_skipped']++;
                continue;
            }

            $meta = [];
            if ($hasMeta) {
                foreach ($this->db->get_results(
                    "SELECT meta_key, meta_value FROM {$this->prefix}contact_submission_meta WHERE submission_id = ? ORDER BY id ASC",
                    [$legacyId]
                ) ?: [] as $metaRow) {
                    $meta[(string) $metaRow->meta_key] = (string) ($metaRow->meta_value ?? '');
                }
            }

            $source = [
                'name' => (string) ($row->sender_name ?? ''),
                'email' => (string) ($row->sender_email ?? ''),
                'subject' => (string) ($row->subject ?? ''),
                'message' => (string) ($row->message ?? ''),
            ] + $meta;

            $values = [];
            $known = [];
            foreach ($form['fields'] as $field) {
                $name = (string) $field['name'];
                $type = (string) $field['type'];
                $known[$name] = true;
                if (!FormFieldTypes::isInput($type) || $type === 'file') {
                    continue;
                }
                if ($type === 'consent') {
                    $values[$name] = ($meta['privacy_consent'] ?? '') === '1' ? '1' : '';
                    continue;
                }
                if (!array_key_exists($name, $source)) {
                    continue;
                }
                $value = $source[$name];
                if (FormFieldTypes::isMultiValue($field)) {
                    $decoded = json_decode($value, true);
                    $value = is_array($decoded) ? array_map('strval', $decoded) : array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
                }
                $values[$name] = $value;
            }

            // Werte früherer, inzwischen entfernter Felder bleiben als Zusatzangabe erhalten.
            $extra = [];
            foreach ($source as $name => $value) {
                if (!isset($known[$name]) && $value !== '' && !in_array($name, ['privacy_consent', 'privacy_consent_confirmed_at'], true)) {
                    $extra[$name] = $value;
                }
            }

            $isSpam = !empty($row->is_spam) || (string) ($row->status ?? '') === 'spam';
            $status = $isSpam ? 'trash' : (self::STATUS_MAP[(string) ($row->status ?? 'unread')] ?? 'new');
            $createdAt = (string) ($row->created_at ?? '');
            $context = [
                'user_id' => (int) ($row->user_id ?? 0),
                'locale' => 'de',
                'status' => $status,
                'created_at' => $createdAt,
                'legacy_id' => $legacyId,
                'consent_at' => (string) ($meta['privacy_consent_confirmed_at'] ?? $createdAt),
                'read_at' => (string) ($row->read_at ?? ''),
                'done_at' => $status === 'done' ? (string) ($row->replied_at ?? $createdAt) : '',
                'meta' => ['migrated_from' => 'cms-contact'] + ($extra !== [] ? ['legacy_values' => $extra] : []) + ($isSpam ? ['legacy_spam' => true] : []),
            ];
            if ($status === 'trash') {
                $context['trashed_at'] = date('Y-m-d H:i:s');
                $context['previous_status'] = 'read';
            }

            try {
                if ($submissions->create($form, $values, $context) > 0) {
                    $this->report['submissions']++;
                }
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('plugins.integration')->warning('cms-contact: Eingang konnte nicht übernommen werden.', [
                    'legacy_id' => $legacyId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return count($rows) < self::BATCH ? null : $cursor;
    }

    // ── Hilfen ───────────────────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private function pluginSettings(): array
    {
        $settings = [];
        try {
            if ($this->db->tableExists($this->prefix . 'contact_settings')) {
                foreach ($this->db->get_results("SELECT setting_key, setting_value FROM {$this->prefix}contact_settings") ?: [] as $row) {
                    $settings[(string) $row->setting_key] = (string) ($row->setting_value ?? '');
                }
            }
        } catch (\Throwable) {
        }

        return $settings;
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        try {
            $raw = $this->db->get_var("SELECT option_value FROM {$this->prefix}settings WHERE option_name = ? LIMIT 1", [self::STATE_OPTION]);
            $state = is_string($raw) ? json_decode($raw, true) : null;
        } catch (\Throwable) {
            $state = null;
        }

        return is_array($state) ? $state : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function saveState(array $state): void
    {
        $json = (string) json_encode($state);
        $this->db->execute(
            "INSERT INTO {$this->prefix}settings (option_name, option_value, autoload) VALUES (?, ?, 0)
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            [self::STATE_OPTION, $json]
        );
    }
}
