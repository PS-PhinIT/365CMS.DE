<?php
/**
 * Globale Formular-Einstellungen (Settings-Gruppe `forms`).
 *
 * Enthält Standards, die jedes Formular erbt: Empfänger, Absender, Aufbewahrung, Datenschutz-Link und
 * Einwilligungstext, Upload-Grenzen, Mailvorlagen (de/en) sowie Kontaktangaben für das Layout „geteilt“.
 *
 * @package CMSv2\Core\Services\Forms
 */

declare(strict_types=1);

namespace CMS\Services\Forms;

use CMS\Services\SettingsService;

if (!defined('ABSPATH')) {
    exit;
}

final class FormSettings
{
    public const GROUP = 'forms';

    public const DEFAULT_RETENTION_DAYS = 365;
    public const MIN_RETENTION_DAYS = 7;
    public const MAX_RETENTION_DAYS = 3650;

    public const DEFAULT_UPLOAD_MAX_KB = 5120;
    public const MAX_UPLOAD_MAX_KB = 51200;

    /** Endungen, die ein Formular höchstens annehmen darf (Obergrenze für Feld-Vorgaben). */
    public const DEFAULT_UPLOAD_TYPES = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt', 'csv', 'docx', 'xlsx', 'odt', 'ods'];

    /** Endungen, die nie angenommen werden, auch wenn sie eingetragen sind. */
    public const BLOCKED_UPLOAD_TYPES = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash',
        'exe', 'com', 'bat', 'cmd', 'msi', 'dll', 'js', 'mjs', 'jsp', 'asp', 'aspx', 'html', 'htm', 'xhtml', 'shtml', 'svg',
        'svgz', 'xml', 'htaccess', 'htpasswd', 'ini', 'jar', 'vbs', 'ps1', 'scr', 'hta', 'swf',
    ];

    public const MAIL_TEMPLATES = ['notification_subject', 'notification_body', 'confirmation_subject', 'confirmation_body'];

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $defaults = [
            'default_recipient' => '',
            'from_name' => '',
            'from_email' => '',
            'retention_days' => self::DEFAULT_RETENTION_DAYS,
            'privacy_url' => '/datenschutz',
            'consent_text_de' => '',
            'consent_text_en' => '',
            'upload_max_kb' => self::DEFAULT_UPLOAD_MAX_KB,
            'upload_types' => implode(',', self::DEFAULT_UPLOAD_TYPES),
            'company_name' => '',
            'company_email' => '',
            'company_phone' => '',
            'company_address' => '',
            'map_url' => '',
            'accent_color' => '',
            'radius' => 8,
            'contact_form_key' => FormService::CONTACT_FORM_KEY,
        ];
        foreach (self::MAIL_TEMPLATES as $template) {
            foreach (FormI18n::LOCALES as $locale) {
                $defaults['mail_' . $template . '_' . $locale] = '';
            }
        }

        return $defaults;
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $values = self::defaults();
        try {
            $stored = SettingsService::getInstance()->getGroup(self::GROUP);
        } catch (\Throwable) {
            $stored = [];
        }
        foreach ($stored as $key => $value) {
            $key = (string) $key;
            if (str_starts_with($key, self::GROUP . '.')) {
                $key = substr($key, strlen(self::GROUP) + 1);
            }
            if (array_key_exists($key, $values)) {
                $values[$key] = $value;
            }
        }

        return self::$cache = $values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * Speichert Einstellungen aus dem Admin-Formular (nur bekannte Schlüssel, bereinigt).
     *
     * @param array<string, mixed> $input
     * @return array{saved: bool, errors: list<string>}
     */
    public static function save(array $input): array
    {
        $errors = [];
        $values = [];
        $defaults = self::defaults();

        foreach (['default_recipient', 'from_email', 'company_email'] as $key) {
            $email = trim((string) ($input[$key] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = sprintf(__('forms.settings.invalid_email'), $email);
                $email = '';
            }
            $values[$key] = $email;
        }

        foreach (['from_name' => 150, 'company_name' => 200, 'company_phone' => 64] as $key => $max) {
            $values[$key] = FormI18n::cleanText((string) ($input[$key] ?? ''), $max);
        }
        $values['company_address'] = FormI18n::cleanText((string) ($input['company_address'] ?? ''), 500, true);

        $mapUrl = trim((string) ($input['map_url'] ?? ''));
        $values['map_url'] = $mapUrl !== '' && FormFieldTypes::isSafeUrl($mapUrl) && preg_match('#^https://#i', $mapUrl) === 1 ? $mapUrl : '';

        $privacyUrl = trim((string) ($input['privacy_url'] ?? ''));
        $values['privacy_url'] = FormFieldTypes::isSafeUrl($privacyUrl) ? $privacyUrl : (string) $defaults['privacy_url'];

        $values['retention_days'] = self::normalizeRetentionDays($input['retention_days'] ?? self::DEFAULT_RETENTION_DAYS, false) ?? self::DEFAULT_RETENTION_DAYS;

        $maxKb = (int) ($input['upload_max_kb'] ?? self::DEFAULT_UPLOAD_MAX_KB);
        $values['upload_max_kb'] = max(1, min(self::MAX_UPLOAD_MAX_KB, $maxKb > 0 ? $maxKb : self::DEFAULT_UPLOAD_MAX_KB));
        $values['upload_types'] = implode(',', self::normalizeUploadTypes($input['upload_types'] ?? ''));

        $color = trim((string) ($input['accent_color'] ?? ''));
        $values['accent_color'] = preg_match('/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', $color) === 1 ? strtolower($color) : '';
        $values['radius'] = max(0, min(32, (int) ($input['radius'] ?? 8)));

        $contactKey = trim((string) ($input['contact_form_key'] ?? FormService::CONTACT_FORM_KEY));
        $values['contact_form_key'] = preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $contactKey) === 1 ? $contactKey : FormService::CONTACT_FORM_KEY;

        foreach (FormI18n::LOCALES as $locale) {
            $values['consent_text_' . $locale] = FormI18n::cleanText((string) ($input['consent_text_' . $locale] ?? ''), 1000);
            foreach (self::MAIL_TEMPLATES as $template) {
                $key = 'mail_' . $template . '_' . $locale;
                $multiline = str_ends_with($template, '_body');
                $values[$key] = FormI18n::cleanText((string) ($input[$key] ?? ''), $multiline ? 10000 : 300, $multiline);
            }
        }

        $saved = SettingsService::getInstance()->setMany(self::GROUP, $values);
        self::flush();

        return ['saved' => $saved, 'errors' => $errors];
    }

    /**
     * Aufbewahrungstage normalisieren. Formulare dürfen `null` (globaler Standard) nutzen, `0` heißt „nie löschen“.
     */
    public static function normalizeRetentionDays(mixed $value, bool $allowInherit = true): ?int
    {
        if ($value === null || $value === '' || $value === 'inherit') {
            return $allowInherit ? null : self::DEFAULT_RETENTION_DAYS;
        }
        if (!is_numeric($value)) {
            return $allowInherit ? null : self::DEFAULT_RETENTION_DAYS;
        }
        $days = (int) $value;
        if ($days <= 0) {
            return 0;
        }

        return max(self::MIN_RETENTION_DAYS, min(self::MAX_RETENTION_DAYS, $days));
    }

    public static function retentionDays(): int
    {
        return self::normalizeRetentionDays(self::get('retention_days', self::DEFAULT_RETENTION_DAYS), false) ?? self::DEFAULT_RETENTION_DAYS;
    }

    public static function privacyUrl(): string
    {
        $url = self::string('privacy_url', '/datenschutz');

        return FormFieldTypes::isSafeUrl($url) ? $url : '/datenschutz';
    }

    /** Einwilligungstext mit Platzhalter `{privacy}` für den Link zur Datenschutzerklärung. */
    public static function consentText(string $locale): string
    {
        $locale = FormI18n::normalizeLocale($locale);
        $text = self::string('consent_text_' . $locale);

        return $text !== '' ? $text : FormI18n::t('forms.consent.default_text', $locale);
    }

    /**
     * @return array{upload_max_kb: int, upload_types: list<string>}
     */
    public static function uploadLimits(): array
    {
        $maxKb = (int) self::get('upload_max_kb', self::DEFAULT_UPLOAD_MAX_KB);

        return [
            'upload_max_kb' => max(1, min(self::MAX_UPLOAD_MAX_KB, $maxKb > 0 ? $maxKb : self::DEFAULT_UPLOAD_MAX_KB)),
            'upload_types' => self::normalizeUploadTypes(self::get('upload_types', '')),
        ];
    }

    /**
     * @return list<string>
     */
    public static function normalizeUploadTypes(mixed $value): array
    {
        $items = is_array($value) ? $value : (preg_split('/[\s,;|]+/', (string) $value) ?: []);
        $types = [];
        foreach ($items as $item) {
            $item = strtolower(ltrim(trim((string) $item), '.*'));
            if ($item === '' || preg_match('/^[a-z0-9]{1,10}$/', $item) !== 1 || in_array($item, self::BLOCKED_UPLOAD_TYPES, true)) {
                continue;
            }
            $types[$item] = $item;
        }

        return $types !== [] ? array_values($types) : self::DEFAULT_UPLOAD_TYPES;
    }

    /**
     * Mailvorlage in einer Sprache; leer gespeicherte Vorlagen fallen auf den Sprachstandard zurück.
     */
    public static function mailTemplate(string $template, string $locale): string
    {
        $locale = FormI18n::normalizeLocale($locale);
        $stored = self::get('mail_' . $template . '_' . $locale, '');
        $stored = is_scalar($stored) ? trim((string) $stored) : '';

        return $stored !== '' ? $stored : FormI18n::t('forms.mail.' . $template, $locale);
    }

    public static function fromName(): string
    {
        $name = self::string('from_name');
        if ($name !== '') {
            return $name;
        }

        return defined('SITE_NAME') ? (string) SITE_NAME : '365CMS';
    }

    public static function companyName(): string
    {
        $name = self::string('company_name');

        return $name !== '' ? $name : (defined('SITE_NAME') ? (string) SITE_NAME : '');
    }
}
