<?php
/**
 * Serverseitige Prüfung einer Formular-Eingabe gegen die Felddefinition.
 *
 * Reine Funktion ohne Datenbank: liefert bereinigte Werte und Fehlermeldungen je Feld. Die Client-Prüfung in
 * `assets/js/forms-public.js` nutzt dieselben Regeln und Texte, maßgeblich ist aber immer diese Klasse.
 *
 * @package CMSv2\Core\Services\Forms
 */

declare(strict_types=1);

namespace CMS\Services\Forms;

if (!defined('ABSPATH')) {
    exit;
}

final class FormValidator
{
    /**
     * @param list<array<string, mixed>> $fields Normalisierte Felder
     * @param array<string, mixed> $input Rohwerte (`$_POST['fields']`)
     * @param array<string, list<array<string, mixed>>> $files Hochgeladene Dateien je Feldname
     * @param array{upload_max_kb?: int, upload_types?: list<string>} $limits Globale Upload-Grenzen
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    public static function validate(array $fields, array $input, string $locale, array $files = [], array $limits = []): array
    {
        $values = [];
        $errors = [];

        foreach ($fields as $field) {
            $type = (string) ($field['type'] ?? 'text');
            if (!FormFieldTypes::isInput($type)) {
                continue;
            }

            $name = (string) $field['name'];
            if ($type === 'file') {
                $result = self::validateFiles($field, $files[$name] ?? [], $locale, $limits);
            } else {
                $result = self::validateValue($field, $input[$name] ?? null, $locale);
            }

            $values[$name] = $result['value'];
            if ($result['error'] !== null) {
                $errors[$name] = $result['error'];
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $field
     * @return array{value: mixed, error: ?string}
     */
    public static function validateValue(array $field, mixed $raw, string $locale): array
    {
        $type = (string) $field['type'];
        $required = !empty($field['required']);
        $validation = is_array($field['validation'] ?? null) ? $field['validation'] : [];

        if (FormFieldTypes::isMultiValue($field)) {
            $selected = [];
            foreach (is_array($raw) ? $raw : ($raw === null || $raw === '' ? [] : [$raw]) as $item) {
                if (is_scalar($item)) {
                    $selected[] = (string) $item;
                }
            }
            $allowed = array_column($field['options'], 'value');
            $selected = array_values(array_unique($selected));
            $invalid = array_diff($selected, $allowed);
            $selected = array_values(array_intersect($allowed, $selected));
            if ($invalid !== []) {
                return ['value' => $selected, 'error' => FormI18n::t('forms.error.option', $locale)];
            }
            if ($required && $selected === []) {
                return ['value' => $selected, 'error' => FormI18n::t('forms.error.required_choice', $locale)];
            }

            return ['value' => $selected, 'error' => null];
        }

        if (is_array($raw)) {
            $raw = '';
        }
        $multiline = $type === 'textarea';
        $value = FormI18n::cleanText(is_scalar($raw) ? (string) $raw : '', 100000, $multiline);

        if ($type === 'checkbox' || $type === 'consent') {
            $value = in_array(strtolower($value), ['1', 'on', 'yes', 'true', 'ja'], true) ? '1' : '';
            if ($required && $value === '') {
                return ['value' => '', 'error' => FormI18n::t($type === 'consent' ? 'forms.error.consent' : 'forms.error.required_check', $locale)];
            }

            return ['value' => $value, 'error' => null];
        }

        if ($type === 'hidden') {
            $value = function_exists('mb_substr') ? mb_substr($value, 0, 500, 'UTF-8') : substr($value, 0, 500);

            return ['value' => $value !== '' ? $value : (string) ($field['default'] ?? ''), 'error' => null];
        }

        if ($value === '') {
            return ['value' => '', 'error' => $required ? FormI18n::t('forms.error.required', $locale) : null];
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        $minLength = $validation['min_length'] ?? null;
        $maxLength = FormFieldTypes::maxLength($field);

        switch ($type) {
            case 'email':
                if (filter_var($value, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\s<>"\'(),;:\\\\]/', $value) === 1) {
                    return ['value' => $value, 'error' => FormI18n::t('forms.error.email', $locale)];
                }
                break;
            case 'url':
                if (filter_var($value, FILTER_VALIDATE_URL) === false || preg_match('#^https?://#i', $value) !== 1) {
                    return ['value' => $value, 'error' => FormI18n::t('forms.error.url', $locale)];
                }
                break;
            case 'tel':
                if (preg_match('/^\+?[0-9 ()\/.\-]{3,40}$/', $value) !== 1 || preg_match('/[0-9]/', $value) !== 1) {
                    return ['value' => $value, 'error' => FormI18n::t('forms.error.tel', $locale)];
                }
                break;
            case 'number':
                $normalized = str_replace(',', '.', $value);
                if (!is_numeric($normalized)) {
                    return ['value' => $value, 'error' => FormI18n::t('forms.error.number', $locale)];
                }
                $number = (float) $normalized;
                $min = $validation['min'] ?? null;
                $max = $validation['max'] ?? null;
                $step = $validation['step'] ?? null;
                if ($min !== null && $number < (float) $min) {
                    return ['value' => $value, 'error' => sprintf(FormI18n::t('forms.error.number_min', $locale), self::formatNumber((float) $min))];
                }
                if ($max !== null && $number > (float) $max) {
                    return ['value' => $value, 'error' => sprintf(FormI18n::t('forms.error.number_max', $locale), self::formatNumber((float) $max))];
                }
                if ($step !== null && (float) $step > 0) {
                    $base = $min !== null ? (float) $min : 0.0;
                    $ratio = ($number - $base) / (float) $step;
                    if (abs($ratio - round($ratio)) > 1e-7) {
                        return ['value' => $value, 'error' => FormI18n::t('forms.error.number_step', $locale)];
                    }
                }
                $value = $normalized;
                break;
            case 'date':
                if (!FormFieldTypes::isDate($value)) {
                    return ['value' => $value, 'error' => FormI18n::t('forms.error.date', $locale)];
                }
                if (($validation['min_date'] ?? '') !== '' && $value < $validation['min_date']) {
                    return ['value' => $value, 'error' => sprintf(FormI18n::t('forms.error.date_min', $locale), self::formatDate((string) $validation['min_date'], $locale))];
                }
                if (($validation['max_date'] ?? '') !== '' && $value > $validation['max_date']) {
                    return ['value' => $value, 'error' => sprintf(FormI18n::t('forms.error.date_max', $locale), self::formatDate((string) $validation['max_date'], $locale))];
                }
                break;
            case 'select':
            case 'radio':
                if (!in_array($value, array_column($field['options'] ?? [], 'value'), true)) {
                    return ['value' => '', 'error' => FormI18n::t('forms.error.option', $locale)];
                }
                break;
        }

        if (is_int($minLength) && $minLength > 0 && $length < $minLength) {
            return ['value' => $value, 'error' => sprintf(FormI18n::t('forms.error.min_length', $locale), $minLength)];
        }
        if (in_array($type, ['text', 'email', 'tel', 'url', 'textarea'], true) && $length > $maxLength) {
            return ['value' => $value, 'error' => sprintf(FormI18n::t('forms.error.max_length', $locale), $maxLength)];
        }

        $pattern = (string) ($validation['pattern'] ?? '');
        if ($pattern !== '') {
            $regex = FormFieldTypes::compilePattern($pattern);
            if ($regex !== null && preg_match($regex, $value) !== 1) {
                $message = FormI18n::pick($validation['pattern_message'] ?? [], $locale);

                return ['value' => $value, 'error' => $message !== '' ? $message : FormI18n::t('forms.error.pattern', $locale)];
            }
        }

        return ['value' => $value, 'error' => null];
    }

    /**
     * Prüft Anzahl, Endung und Größe hochgeladener Dateien. Inhalt (MIME) prüft `FormUploadService`.
     *
     * @param array<string, mixed> $field
     * @param list<array<string, mixed>> $files
     * @param array{upload_max_kb?: int, upload_types?: list<string>} $limits
     * @return array{value: list<array<string, mixed>>, error: ?string}
     */
    public static function validateFiles(array $field, array $files, string $locale, array $limits = []): array
    {
        $validation = is_array($field['validation'] ?? null) ? $field['validation'] : [];
        $present = [];
        foreach ($files as $file) {
            $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                $key = in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'forms.error.file_size' : 'forms.error.file_upload';

                return ['value' => [], 'error' => sprintf(FormI18n::t($key, $locale), self::formatKb(self::maxKb($validation, $limits)))];
            }
            $present[] = $file;
        }

        if ($present === []) {
            return ['value' => [], 'error' => !empty($field['required']) ? FormI18n::t('forms.error.required_file', $locale) : null];
        }

        $maxFiles = (int) ($validation['max_files'] ?? 1);
        if (count($present) > max(1, $maxFiles)) {
            return ['value' => [], 'error' => sprintf(FormI18n::t('forms.error.file_count', $locale), max(1, $maxFiles))];
        }

        $allowed = self::allowedExtensions($validation, $limits);
        $maxKb = self::maxKb($validation, $limits);
        foreach ($present as $file) {
            $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            if ($extension === '' || !in_array($extension, $allowed, true)) {
                return ['value' => [], 'error' => sprintf(FormI18n::t('forms.error.file_type', $locale), implode(', ', $allowed))];
            }
            if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > $maxKb * 1024) {
                return ['value' => [], 'error' => sprintf(FormI18n::t('forms.error.file_size', $locale), self::formatKb($maxKb))];
            }
        }

        return ['value' => $present, 'error' => null];
    }

    /**
     * Erlaubte Endungen: Schnittmenge aus Feld-Vorgabe und globaler Liste (global gilt immer als Obergrenze).
     *
     * @param array<string, mixed> $validation
     * @param array{upload_types?: list<string>} $limits
     * @return list<string>
     */
    public static function allowedExtensions(array $validation, array $limits): array
    {
        $global = array_values(array_filter(array_map('strval', $limits['upload_types'] ?? FormSettings::DEFAULT_UPLOAD_TYPES)));
        $field = array_values(array_filter(array_map('strval', is_array($validation['accept'] ?? null) ? $validation['accept'] : [])));
        if ($field === []) {
            return $global;
        }

        return array_values(array_intersect($field, $global));
    }

    /**
     * @param array<string, mixed> $validation
     * @param array{upload_max_kb?: int} $limits
     */
    public static function maxKb(array $validation, array $limits): int
    {
        $global = max(1, (int) ($limits['upload_max_kb'] ?? FormSettings::DEFAULT_UPLOAD_MAX_KB));
        $field = (int) ($validation['max_size_kb'] ?? 0);

        return $field > 0 ? min($field, $global) : $global;
    }

    public static function formatKb(int $kb): string
    {
        return $kb >= 1024 ? rtrim(rtrim(number_format($kb / 1024, 1, ',', ''), '0'), ',') . ' MB' : $kb . ' KB';
    }

    private static function formatNumber(float $number): string
    {
        return rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
    }

    private static function formatDate(string $date, string $locale): string
    {
        if (!FormFieldTypes::isDate($date)) {
            return $date;
        }
        [$year, $month, $day] = explode('-', $date);

        return $locale === 'en' ? $date : $day . '.' . $month . '.' . $year;
    }
}
