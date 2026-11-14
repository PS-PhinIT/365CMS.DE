<?php
/**
 * Einstellungen des Newsletters (Tabelle `newsletter_settings`, Schlüssel wie im früheren Plugin plus Erweiterungen).
 *
 * @package CMS\Services\Newsletter
 */

declare(strict_types=1);

namespace CMS\Services\Newsletter;

use CMS\Database;

if (!defined('ABSPATH')) {
    exit;
}

final class NewsletterSettings
{
    /** @var array<string, string> */
    public const DEFAULTS = [
        // Absender
        'sender_name' => '',
        'sender_email' => '',
        'reply_to_email' => '',
        // Anmeldung
        'require_double_opt_in' => '1',
        'default_segment' => 'general',
        'segments' => "general|Allgemein|General",
        'show_segment_choice' => '0',
        'show_name_fields' => '1',
        'consent_text' => 'Ich möchte den Newsletter erhalten. Die Einwilligung kann ich jederzeit über den Abmeldelink in jeder Ausgabe widerrufen.',
        'consent_text_en' => 'I would like to receive the newsletter. I can withdraw my consent at any time using the unsubscribe link in every issue.',
        'confirm_expiry_days' => '14',
        // Mitgliederbereich: Widget mit An-/Abmeldung auf dem Dashboard (aus = nur Kachel mit Link zur Unterseite)
        'member_widget_enabled' => '1',
        // Öffentliche Seite
        'archive_title' => 'Newsletter',
        'archive_title_en' => 'Newsletter',
        'archive_description' => 'Bleib über neue Inhalte, Events und Produkt-Updates auf dem Laufenden.',
        'archive_description_en' => 'Stay up to date with new content, events, and product updates.',
        'subscribe_intro' => 'Melde dich für Neuigkeiten, Event-Hinweise und neue Fachbeiträge an.',
        'subscribe_intro_en' => 'Sign up for news, event updates, and new expert content.',
        'footer_note' => 'Du kannst dich jederzeit wieder mit einem Klick abmelden.',
        'footer_note_en' => 'You can unsubscribe anytime with one click.',
        'public_archive' => '1',
        'show_nav_link' => '0',
        'nav_label' => 'Newsletter',
        'nav_label_en' => 'Newsletter',
        // Versand
        'batch_size' => '50',
        'mail_footer' => '',
        'mail_footer_en' => '',
    ];

    private const BOOLEAN_KEYS = ['require_double_opt_in', 'show_segment_choice', 'show_name_fields', 'public_archive', 'show_nav_link', 'member_widget_enabled'];
    private const NUMBER_RANGES = ['confirm_expiry_days' => [1, 365], 'batch_size' => [5, 1000]];
    private const TEXTAREA_KEYS = ['segments', 'consent_text', 'consent_text_en', 'archive_description', 'archive_description_en',
        'subscribe_intro', 'subscribe_intro_en', 'footer_note', 'footer_note_en', 'mail_footer', 'mail_footer_en'];
    private const EMAIL_KEYS = ['sender_email', 'reply_to_email'];

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /** @return array<string, string> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $settings = self::DEFAULTS;
        try {
            $db = Database::instance();
            foreach ($db->execute("SELECT setting_key, setting_value FROM {$db->getPrefix()}newsletter_settings")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
            }
        } catch (\Throwable) {
            // Tabelle fehlt (noch): Standardwerte.
        }

        return self::$cache = $settings;
    }

    public static function get(string $key): string
    {
        return self::all()[$key] ?? (self::DEFAULTS[$key] ?? '');
    }

    public static function flag(string $key): bool
    {
        return self::get($key) === '1';
    }

    public static function int(string $key): int
    {
        [$min, $max] = self::NUMBER_RANGES[$key] ?? [0, PHP_INT_MAX];

        return max($min, min($max, (int) self::get($key)));
    }

    /** Text in der Sprache `$locale` (Schlüssel `<key>_en` für Englisch, Rückfall auf Deutsch). */
    public static function localized(string $key, string $locale): string
    {
        if ($locale === 'en') {
            $en = trim(self::get($key . '_en'));
            if ($en !== '') {
                return $en;
            }
        }

        return trim(self::get($key));
    }

    /** Absenderadresse: Einstellung, sonst Kontakt-/Admin-Adresse der Website. */
    public static function senderEmail(): string
    {
        $email = self::get('sender_email');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || str_ends_with(strtolower($email), '@example.com')) {
            $email = defined('ADMIN_EMAIL') ? (string) ADMIN_EMAIL : '';
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    }

    public static function senderName(): string
    {
        $name = trim(str_replace(['"', "\r", "\n"], '', self::get('sender_name')));

        return $name !== '' ? $name : (defined('SITE_NAME') ? (string) SITE_NAME : '365CMS');
    }

    /**
     * Segmente aus der Einstellung `segments` (je Zeile `schlüssel|Name DE|Name EN`).
     *
     * @return array<string, array{de: string, en: string}>
     */
    public static function segments(): array
    {
        $segments = [];
        foreach (preg_split('/\R/', self::get('segments')) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line));
            $slug = self::slug($parts[0] ?? '');
            if ($slug === '') {
                continue;
            }
            $de = ($parts[1] ?? '') !== '' ? $parts[1] : $slug;
            $segments[$slug] = ['de' => $de, 'en' => ($parts[2] ?? '') !== '' ? $parts[2] : $de];
        }
        $default = self::slug(self::get('default_segment')) ?: 'general';
        if (!isset($segments[$default])) {
            $segments = [$default => ['de' => $default, 'en' => $default]] + $segments;
        }

        return $segments;
    }

    public static function slug(string $value): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower(trim($value))), '-');

        return mb_substr($slug, 0, 80);
    }

    /**
     * Adressfelder mit nicht leerem, ungültigem Wert – save() würde sie sonst kommentarlos leeren.
     *
     * @param array<string, mixed> $input
     * @param list<string> $keys
     * @return list<string>
     */
    public static function invalidEmailKeys(array $input, array $keys): array
    {
        $invalid = [];
        foreach (array_intersect(self::EMAIL_KEYS, $keys) as $key) {
            $raw = is_scalar($input[$key] ?? '') ? trim((string) ($input[$key] ?? '')) : '';
            if ($raw !== '' && filter_var($raw, FILTER_VALIDATE_EMAIL) === false) {
                $invalid[] = $key;
            }
        }

        return $invalid;
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $keys nur diese Schlüssel speichern (Formular eines Tabs)
     */
    public static function save(array $input, array $keys): void
    {
        $db = Database::instance();
        $stmt = $db->prepare("INSERT INTO {$db->getPrefix()}newsletter_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($keys as $key) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            $raw = $input[$key] ?? '';
            $raw = is_scalar($raw) ? (string) $raw : '';
            if (in_array($key, self::BOOLEAN_KEYS, true)) {
                $value = in_array($raw, ['1', 'on', 'true'], true) ? '1' : '0';
            } elseif (isset(self::NUMBER_RANGES[$key])) {
                [$min, $max] = self::NUMBER_RANGES[$key];
                $value = (string) max($min, min($max, (int) $raw));
            } elseif (in_array($key, self::EMAIL_KEYS, true)) {
                $value = filter_var(trim($raw), FILTER_VALIDATE_EMAIL) !== false ? trim($raw) : '';
            } elseif ($key === 'default_segment') {
                $value = self::slug($raw) ?: 'general';
            } elseif (in_array($key, self::TEXTAREA_KEYS, true)) {
                $value = mb_substr(trim(strip_tags($raw)), 0, 4000);
            } else {
                $value = mb_substr(trim(strip_tags($raw)), 0, 255);
            }
            $stmt->execute([$key, $value]);
        }
        self::$cache = null;
    }

    public static function seedDefaults(): void
    {
        $db = Database::instance();
        $stmt = $db->prepare("INSERT IGNORE INTO {$db->getPrefix()}newsletter_settings (setting_key, setting_value) VALUES (?, ?)");
        foreach (self::DEFAULTS as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        // Platzhalter-Absender des Plugins (example.com) nicht weiterverwenden.
        $db->execute("UPDATE {$db->getPrefix()}newsletter_settings SET setting_value = '' WHERE setting_key IN ('sender_email', 'reply_to_email') AND setting_value LIKE '%@example.com'");
        self::$cache = null;
    }

    public static function reset(): void
    {
        self::$cache = null;
    }
}
