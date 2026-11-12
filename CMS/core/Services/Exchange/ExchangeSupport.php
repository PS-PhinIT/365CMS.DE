<?php
/**
 * Gemeinsame Hilfen der Export/Import-Provider: Text- und Slug-Bereinigung, Editor.js-Bereinigung und das
 * Umschreiben von Medienadressen (Quell-Website → diese Installation, umbenannte Dateien).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Exchange;

use CMS\Services\ContentTypes\ImportContext;
use CMS\Services\EditorJs\EditorJsSanitizer;

if (!defined('ABSPATH')) {
    exit;
}

final class ExchangeSupport
{
    public static function text(mixed $value, int $max, bool $multiline = false): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        $value = $multiline
            ? (preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]+/u', '', $value) ?? '')
            : trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)) ?? '');

        return mb_substr($value, 0, $max);
    }

    public static function slug(string $value, string $fallback): string
    {
        $value = trim($value) !== '' ? $value : $fallback;
        $value = strtr(mb_strtolower(trim($value), 'UTF-8'), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $value = preg_replace('/[^a-z0-9\-\/]+/', '-', $value) ?? '';
        $value = trim(preg_replace('/-{2,}/', '-', $value) ?? '', '-/');

        return mb_substr($value, 0, 190);
    }

    /** Editor.js-JSON über den Core-Sanitizer; leere oder ungültige Werte bleiben leer. */
    public static function editorJson(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '';
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded) || !isset($decoded['blocks']) || !is_array($decoded['blocks'])) {
            return '';
        }

        try {
            return (new EditorJsSanitizer())->sanitize($value);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Schreibt Medienverweise um: Upload-Adresse der Quelle → eigene, umbenannte Dateien auf ihren neuen Pfad.
     */
    public static function rewriteMedia(string $value, ImportContext $context): string
    {
        if ($value === '') {
            return $value;
        }
        $targetBase = rtrim(defined('UPLOAD_URL') ? (string) UPLOAD_URL : '/uploads', '/');
        $replacements = [];
        foreach ($context->mapOf('media_path') as $oldPath => $newPath) {
            if ((string) $oldPath !== (string) $newPath) {
                $replacements['/uploads/' . $oldPath] = '/uploads/' . $newPath;
            }
        }
        if ($replacements !== []) {
            // Längere Pfade zuerst, damit „bild.jpg“ nicht in „bild.jpg.webp“ ersetzt wird.
            uksort($replacements, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            $value = strtr($value, $replacements + array_combine(
                array_map(static fn (string $key): string => str_replace('/', '\/', $key), array_keys($replacements)),
                array_map(static fn (string $val): string => str_replace('/', '\/', $val), array_values($replacements))
            ));
        }

        $sourceBase = rtrim((string) ($context->manifest['upload_url'] ?? ''), '/');
        if ($sourceBase !== '' && $sourceBase !== $targetBase) {
            $value = str_replace(
                [$sourceBase . '/', str_replace('/', '\/', $sourceBase) . '\/'],
                [$targetBase . '/', str_replace('/', '\/', $targetBase) . '\/'],
                $value
            );
        }

        return $value;
    }
}
