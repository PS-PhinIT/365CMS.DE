<?php
/**
 * Feldtypen und Normalisierung von Formularfeldern.
 *
 * Eine Felddefinition wird überall (Builder, Renderer, Validierung, Migration, Import) über
 * `normalizeField()` in dieselbe Form gebracht. Freitexte sind zweisprachig (`['de' => …, 'en' => …]`).
 *
 * @package CMSv2\Core\Services\Forms
 */

declare(strict_types=1);

namespace CMS\Services\Forms;

if (!defined('ABSPATH')) {
    exit;
}

final class FormFieldTypes
{
    public const TYPES = [
        'text', 'email', 'tel', 'url', 'textarea', 'number', 'date',
        'select', 'radio', 'checkbox', 'consent', 'hidden', 'file', 'heading', 'paragraph',
    ];

    /** Typen ohne Eingabe (nur Darstellung). */
    public const STATIC_TYPES = ['heading', 'paragraph'];

    public const OPTION_TYPES = ['select', 'radio', 'checkbox'];

    public const WIDTHS = ['full', 'half', 'third', 'two-third'];

    /** Rollen verbinden Felder mit Absendername, Antwortadresse, Betreff und Nachricht. */
    public const ROLES = ['', 'name', 'email', 'subject', 'message'];

    public const MAX_FIELDS = 60;
    public const MAX_OPTIONS = 100;
    public const MAX_FILES_PER_FIELD = 10;

    private const DEFAULT_MAX_LENGTH = ['text' => 255, 'email' => 254, 'tel' => 64, 'url' => 2048, 'textarea' => 10000, 'hidden' => 500];

    /**
     * Sprachschlüssel der Typnamen (für Builder und Listen).
     *
     * @return array<string, string>
     */
    public static function labelKeys(): array
    {
        $keys = [];
        foreach (self::TYPES as $type) {
            $keys[$type] = 'forms.type.' . $type;
        }

        return $keys;
    }

    public static function isType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public static function isInput(string $type): bool
    {
        return self::isType($type) && !in_array($type, self::STATIC_TYPES, true);
    }

    public static function hasOptions(string $type): bool
    {
        return in_array($type, self::OPTION_TYPES, true);
    }

    /** Checkbox mit Optionen ist eine Mehrfachauswahl, ohne Optionen ein einzelnes Ja/Nein-Feld. */
    public static function isMultiValue(array $field): bool
    {
        return ($field['type'] ?? '') === 'checkbox' && ($field['options'] ?? []) !== [];
    }

    public static function maxLength(array $field): int
    {
        $configured = $field['validation']['max_length'] ?? null;
        $default = self::DEFAULT_MAX_LENGTH[$field['type'] ?? 'text'] ?? 255;

        return is_int($configured) && $configured > 0 ? $configured : $default;
    }

    /**
     * Feldname (`[a-z][a-z0-9_]{0,63}`) aus einem beliebigen Text ableiten.
     */
    public static function normalizeName(string $name, string $fallback = 'feld'): string
    {
        $name = strtr(mb_strtolower(trim($name), 'UTF-8'), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', '-' => '_', ' ' => '_']);
        $name = (string) preg_replace('/[^a-z0-9_]+/', '_', $name);
        $name = trim((string) preg_replace('/_+/', '_', $name), '_');
        if ($name === '' || preg_match('/^[a-z]/', $name) !== 1) {
            $name = $fallback . ($name !== '' ? '_' . $name : '');
        }

        return substr($name, 0, 64);
    }

    /**
     * Regulären Ausdruck in die gespeicherte Form bringen: Rumpf ohne Begrenzer, Flag `i` als `(?i)`.
     * Ungültige Ausdrücke werden verworfen (leerer String).
     */
    public static function normalizePattern(mixed $pattern): string
    {
        $pattern = trim(is_scalar($pattern) ? (string) $pattern : '');
        if ($pattern === '' || strlen($pattern) > 500) {
            return '';
        }

        if (preg_match('/^([\/#~!@%|])(.*)\1([imsxuU]*)$/s', $pattern, $match) === 1) {
            $body = $match[2];
            $flags = array_intersect(str_split($match[3]), ['i', 'm', 's', 'x']);
            if ($flags !== []) {
                $body = '(?' . implode('', array_unique($flags)) . ')' . $body;
            }
            $pattern = $body;
        }

        return self::compilePattern($pattern) !== null ? $pattern : '';
    }

    /** Vollständiger PCRE-Ausdruck für einen gespeicherten Rumpf oder null, wenn er nicht kompiliert. */
    public static function compilePattern(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        $regex = '~' . str_replace('~', '\~', $body) . '~u';
        set_error_handler(static fn(): bool => true);
        try {
            $valid = preg_match($regex, '') !== false;
        } finally {
            restore_error_handler();
        }

        return $valid ? $regex : null;
    }

    /**
     * Auswahloptionen normalisieren: akzeptiert `["A","B"]`, `[{value,label}]` und Zeilen `wert|Label`.
     *
     * @return list<array{value: string, label: array{de: string, en: string}}>
     */
    public static function normalizeOptions(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            } else {
                $raw = preg_split('/\R/', $raw) ?: [];
            }
        }
        if (!is_array($raw)) {
            return [];
        }

        $options = [];
        $seen = [];
        foreach ($raw as $key => $option) {
            if (is_array($option)) {
                $label = $option['label'] ?? ($option['text'] ?? ($option['value'] ?? ''));
                $value = $option['value'] ?? (is_array($label) ? ($label['de'] ?? '') : $label);
            } elseif (is_scalar($option)) {
                $text = trim((string) $option);
                if (is_string($key) && $key !== '') {
                    $value = $key;
                    $label = $text;
                } elseif (str_contains($text, '|')) {
                    [$value, $label] = array_map('trim', explode('|', $text, 2));
                } else {
                    $value = $text;
                    $label = $text;
                }
            } else {
                continue;
            }

            $value = FormI18n::cleanText(is_scalar($value) ? (string) $value : '', 200);
            $labels = FormI18n::texts($label, 200);
            if ($value === '' && $labels['de'] === '' && $labels['en'] === '') {
                continue;
            }
            if ($value === '') {
                $value = $labels['de'] !== '' ? $labels['de'] : $labels['en'];
            }
            if ($labels['de'] === '' && $labels['en'] === '') {
                $labels['de'] = $value;
            }
            if (isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $options[] = ['value' => $value, 'label' => $labels];
            if (count($options) >= self::MAX_OPTIONS) {
                break;
            }
        }

        return $options;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function normalizeField(mixed $raw, int $position = 0): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $type = strtolower(trim((string) ($raw['type'] ?? 'text')));
        if (!self::isType($type)) {
            $type = match ($type) {
                'phone' => 'tel',
                'dropdown' => 'select',
                'upload' => 'file',
                'privacy', 'gdpr' => 'consent',
                'html', 'text_block', 'static' => 'paragraph',
                default => 'text',
            };
        }

        $label = FormI18n::texts($raw['label'] ?? '', 300);
        $fallbackName = $label['de'] !== '' ? $label['de'] : ($label['en'] !== '' ? $label['en'] : $type . '_' . ($position + 1));
        $name = self::normalizeName((string) ($raw['name'] ?? ''), 'feld');
        if (trim((string) ($raw['name'] ?? '')) === '') {
            $name = self::normalizeName($fallbackName, $type);
        }

        $width = strtolower(str_replace('_', '-', trim((string) ($raw['width'] ?? 'full'))));
        $width = match ($width) {
            '50', '1/2', 'half' => 'half',
            '33', '1/3', 'third' => 'third',
            '66', '2/3', 'two-third', 'two-thirds' => 'two-third',
            default => 'full',
        };

        $role = strtolower(trim((string) ($raw['role'] ?? '')));
        $field = [
            'type' => $type,
            'name' => $name,
            'label' => $label,
            'placeholder' => FormI18n::texts($raw['placeholder'] ?? '', 200),
            'help' => FormI18n::texts($raw['help'] ?? '', 500),
            'required' => !empty($raw['required']),
            'width' => $width,
            'default' => FormI18n::cleanText(is_scalar($raw['default'] ?? null) ? (string) $raw['default'] : '', 500),
            'role' => in_array($role, self::ROLES, true) ? $role : '',
            'css_class' => self::normalizeCssClass((string) ($raw['css_class'] ?? '')),
            'options' => [],
            'validation' => [],
        ];

        if (in_array($type, self::STATIC_TYPES, true)) {
            $field['content'] = FormI18n::texts($raw['content'] ?? ($raw['label'] ?? ''), $type === 'heading' ? 200 : 2000, $type === 'paragraph');
            $field['required'] = false;
            $field['role'] = '';
            $field['placeholder'] = ['de' => '', 'en' => ''];
            $level = (int) ($raw['level'] ?? 3);
            $field['level'] = $level >= 2 && $level <= 4 ? $level : 3;
            if ($type === 'heading') {
                $field['label'] = $field['content'];
            }

            return $field;
        }

        if (self::hasOptions($type)) {
            $field['options'] = self::normalizeOptions($raw['options'] ?? []);
        }
        if ($type === 'consent') {
            $field['required'] = true;
            $field['role'] = '';
            $privacyUrl = trim((string) ($raw['privacy_url'] ?? ''));
            $field['privacy_url'] = self::isSafeUrl($privacyUrl) ? $privacyUrl : '';
        }
        if ($type === 'hidden') {
            $field['required'] = false;
            $field['width'] = 'full';
        }
        if ($field['role'] === 'email' && $type !== 'email') {
            $field['role'] = '';
        }

        $field['validation'] = self::normalizeValidation($type, is_array($raw['validation'] ?? null) ? $raw['validation'] : []);

        return $field;
    }

    /**
     * Feldliste normalisieren (JSON oder Array), eindeutige Namen sichern und Rollen ergänzen.
     *
     * @return list<array<string, mixed>>
     */
    public static function normalizeFields(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }

        $fields = [];
        $used = [];
        foreach (array_values($raw) as $position => $item) {
            $field = self::normalizeField($item, $position);
            if ($field === null) {
                continue;
            }

            $base = $field['name'];
            $name = $base;
            $suffix = 2;
            while (isset($used[$name])) {
                $name = substr($base, 0, 60) . '_' . $suffix++;
            }
            $field['name'] = $name;
            $used[$name] = true;
            $fields[] = $field;
            if (count($fields) >= self::MAX_FIELDS) {
                break;
            }
        }

        return self::assignRoles($fields);
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return array<string, mixed>|null
     */
    public static function fieldByRole(array $fields, string $role): ?array
    {
        foreach ($fields as $field) {
            if (($field['role'] ?? '') === $role) {
                return $field;
            }
        }

        return null;
    }

    public static function isSafeUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return preg_match('/[\x00-\x1F\x7F\s"<>\\\\]/', $url) !== 1;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $url) === 1;
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return list<array<string, mixed>>
     */
    private static function assignRoles(array $fields): array
    {
        $taken = [];
        foreach ($fields as $index => $field) {
            $role = $field['role'];
            if ($role === '') {
                continue;
            }
            if (isset($taken[$role])) {
                $fields[$index]['role'] = '';
                continue;
            }
            $taken[$role] = true;
        }

        $candidates = [
            'name' => static fn(array $f): bool => in_array($f['type'], ['text'], true) && in_array($f['name'], ['name', 'full_name', 'fullname', 'your_name', 'sender_name'], true),
            'email' => static fn(array $f): bool => $f['type'] === 'email',
            'subject' => static fn(array $f): bool => in_array($f['type'], ['text', 'select', 'radio'], true) && in_array($f['name'], ['subject', 'betreff'], true),
            'message' => static fn(array $f): bool => $f['type'] === 'textarea' && in_array($f['name'], ['message', 'nachricht'], true),
        ];
        foreach ($candidates as $role => $matches) {
            if (isset($taken[$role])) {
                continue;
            }
            foreach ($fields as $index => $field) {
                if ($field['role'] === '' && $matches($field)) {
                    $fields[$index]['role'] = $role;
                    $taken[$role] = true;
                    break;
                }
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function normalizeValidation(string $type, array $raw): array
    {
        $validation = [];
        $int = static function (mixed $value, int $min, int $max): ?int {
            if ($value === null || $value === '' || !is_numeric($value)) {
                return null;
            }
            $value = (int) $value;

            return $value >= $min && $value <= $max ? $value : null;
        };
        $num = static function (mixed $value): ?float {
            if ($value === null || $value === '' || !is_numeric($value)) {
                return null;
            }

            return (float) $value;
        };

        if (in_array($type, ['text', 'tel', 'url', 'textarea', 'email', 'hidden'], true)) {
            $validation['min_length'] = $int($raw['min_length'] ?? null, 0, 100000);
            $validation['max_length'] = $int($raw['max_length'] ?? null, 1, 100000);
            if ($validation['min_length'] !== null && $validation['max_length'] !== null && $validation['min_length'] > $validation['max_length']) {
                $validation['min_length'] = null;
            }
        }
        if (in_array($type, ['text', 'tel', 'url', 'textarea', 'email'], true)) {
            $validation['pattern'] = self::normalizePattern($raw['pattern'] ?? '');
            $validation['pattern_message'] = FormI18n::texts($raw['pattern_message'] ?? '', 200);
        }
        if ($type === 'number') {
            $validation['min'] = $num($raw['min'] ?? null);
            $validation['max'] = $num($raw['max'] ?? null);
            $step = $num($raw['step'] ?? null);
            $validation['step'] = $step !== null && $step > 0 ? $step : null;
            if ($validation['min'] !== null && $validation['max'] !== null && $validation['min'] > $validation['max']) {
                $validation['max'] = null;
            }
        }
        if ($type === 'date') {
            foreach (['min_date', 'max_date'] as $key) {
                $value = trim((string) ($raw[$key] ?? ''));
                $validation[$key] = self::isDate($value) ? $value : '';
            }
        }
        if ($type === 'file') {
            $accept = $raw['accept'] ?? [];
            if (is_string($accept)) {
                $accept = preg_split('/[\s,;|]+/', $accept) ?: [];
            }
            $extensions = [];
            foreach (is_array($accept) ? $accept : [] as $extension) {
                $extension = strtolower(ltrim(trim((string) $extension), '.*'));
                if ($extension !== '' && preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1) {
                    $extensions[$extension] = $extension;
                }
            }
            $validation['accept'] = array_values($extensions);
            $validation['max_size_kb'] = $int($raw['max_size_kb'] ?? null, 1, 102400);
            $validation['max_files'] = $int($raw['max_files'] ?? null, 1, self::MAX_FILES_PER_FIELD) ?? 1;
        }

        return $validation;
    }

    public static function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }

    private static function normalizeCssClass(string $classes): string
    {
        $result = [];
        foreach (preg_split('/\s+/', trim($classes)) ?: [] as $class) {
            if ($class !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,60}$/', $class) === 1) {
                $result[] = $class;
            }
        }

        return implode(' ', array_slice(array_unique($result), 0, 5));
    }
}
