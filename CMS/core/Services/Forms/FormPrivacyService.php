<?php
/**
 * DSGVO-Anbindung der Formulareingänge: Auskunft (Art. 15/20) und Löschung (Art. 17).
 *
 * - `dsgvo_export_data($userId, $email)` (Admin → DSGVO-Anfragen → „Abschließen & exportieren“) schreibt alle
 *   Eingänge der Person (Konto-ID oder E-Mail-Adresse) als JSON in die private Ablage
 *   (`private/forms/privacy-exports/`). Die Datei steht unter Inhalte → Formulareingänge → „DSGVO-Exporte“
 *   zum Herunterladen bereit und wird nach EXPORT_KEEP_DAYS Tagen automatisch entfernt.
 * - `dsgvo_delete_data($userId, $email)` löscht die Eingänge der Person samt hochgeladener Dateien.
 * - `MemberService::exportUserData()` (Selbstauskunft im Mitgliederbereich) erhält die Eingänge des Kontos.
 *
 * @package CMSv2\Core\Services\Forms
 */

declare(strict_types=1);

namespace CMS\Services\Forms;

use CMS\AuditLogger;
use CMS\Logger;
use CMS\Services\PrivateStorageService;

if (!defined('ABSPATH')) {
    exit;
}

final class FormPrivacyService
{
    public const EXPORT_AREA = 'forms/privacy-exports';
    public const EXPORT_KEEP_DAYS = 30;
    private const FILE_PATTERN = '/^forms-\d{8}-\d{6}-[a-f0-9]{12}\.json$/';

    /**
     * Strukturierte Auskunft über alle Eingänge einer Person.
     *
     * @return list<array<string, mixed>>
     */
    public static function collect(int $userId, string $email): array
    {
        if (!FormService::getInstance()->isAvailable()) {
            return [];
        }

        $result = [];
        foreach (FormSubmissionService::getInstance()->findForPerson($userId, $email) as $submission) {
            $fields = [];
            foreach ($submission['data'] as $entry) {
                $fields[] = [
                    'name' => (string) ($entry['name'] ?? ''),
                    'label' => (string) ($entry['label'] ?? ''),
                    'value' => (string) ($entry['text'] ?? (is_scalar($entry['value'] ?? null) ? $entry['value'] : '')),
                ];
            }
            $files = [];
            foreach ($submission['files'] ?? [] as $file) {
                $files[] = [
                    'field' => (string) $file['field_name'],
                    'name' => (string) $file['original_name'],
                    'size' => (int) ($file['size_bytes'] ?? 0),
                    'type' => (string) ($file['mime_type'] ?? ''),
                ];
            }
            $result[] = [
                'id' => (int) $submission['id'],
                'form' => (string) $submission['form_title'],
                'form_key' => (string) $submission['form_key'],
                'status' => (string) $submission['status'],
                'created_at' => (string) $submission['created_at'],
                'locale' => (string) $submission['locale'],
                'source_url' => (string) $submission['source_url'],
                'consent_at' => $submission['consent_at'],
                'consent_text' => (string) ($submission['meta']['consent']['text'] ?? ''),
                'sender_name' => (string) $submission['sender_name'],
                'sender_email' => (string) $submission['sender_email'],
                'subject' => (string) $submission['subject'],
                'fields' => $fields,
                'files' => $files,
            ];
        }

        return $result;
    }

    /**
     * Hook `dsgvo_export_data`: Export-Datei schreiben. Rückgabe: Dateiname oder '' (keine Eingänge/Fehler).
     */
    public static function handleExport(mixed $userId = null, mixed $email = null): string
    {
        $userId = is_numeric($userId) ? (int) $userId : 0;
        $email = is_string($email) ? trim($email) : '';
        try {
            $entries = self::collect($userId, $email);
            if ($entries === []) {
                return '';
            }

            $dir = self::exportDir();
            $name = 'forms-' . date('Ymd-His') . '-' . substr(hash('sha256', $userId . '|' . strtolower($email) . '|' . microtime(true)), 0, 12) . '.json';
            $payload = [
                'type' => 'form_submissions',
                'generated_at' => date('c'),
                'subject' => ['user_id' => $userId > 0 ? $userId : null, 'email' => $email],
                'count' => count($entries),
                'submissions' => $entries,
            ];
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($json) || file_put_contents($dir . $name, $json, LOCK_EX) === false) {
                throw new \RuntimeException('Export-Datei konnte nicht geschrieben werden.');
            }
            @chmod($dir . $name, 0640);

            AuditLogger::instance()->log(
                AuditLogger::CAT_SETTING,
                'forms.privacy.export',
                'DSGVO-Auskunft: Formulareingänge exportiert',
                'form_submission',
                null,
                ['count' => count($entries), 'file' => $name, 'user_id' => $userId > 0 ? $userId : null],
                'info'
            );

            return $name;
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('forms')->error('DSGVO-Export der Formulareingänge fehlgeschlagen.', ['message' => $e->getMessage()]);

            return '';
        }
    }

    /**
     * Hook `dsgvo_delete_data`: Eingänge der Person endgültig löschen.
     */
    public static function handleDelete(mixed $userId = null, mixed $email = null): int
    {
        $userId = is_numeric($userId) ? (int) $userId : 0;
        $email = is_string($email) ? trim($email) : '';
        try {
            if (!FormService::getInstance()->isAvailable()) {
                return 0;
            }
            $deleted = FormSubmissionService::getInstance()->deleteForPerson($userId, $email);
            if ($deleted > 0) {
                AuditLogger::instance()->log(
                    AuditLogger::CAT_SETTING,
                    'forms.privacy.delete',
                    'DSGVO-Löschung: Formulareingänge gelöscht',
                    'form_submission',
                    null,
                    ['count' => $deleted, 'user_id' => $userId > 0 ? $userId : null],
                    'warning'
                );
            }

            return $deleted;
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('forms')->error('DSGVO-Löschung der Formulareingänge fehlgeschlagen.', ['message' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Vorhandene Export-Dateien (neueste zuerst).
     *
     * @return list<array{name: string, size: int, created_at: string}>
     */
    public static function listExports(): array
    {
        $files = [];
        foreach (glob(self::exportDir() . 'forms-*.json') ?: [] as $path) {
            $name = basename($path);
            if (preg_match(self::FILE_PATTERN, $name) !== 1) {
                continue;
            }
            $files[] = ['name' => $name, 'size' => (int) filesize($path), 'created_at' => date('Y-m-d H:i:s', (int) filemtime($path))];
        }
        usort($files, static fn(array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $files;
    }

    public static function exportPath(string $name): ?string
    {
        if (preg_match(self::FILE_PATTERN, $name) !== 1) {
            return null;
        }

        return PrivateStorageService::getInstance()->resolve(self::EXPORT_AREA, $name);
    }

    public static function deleteExport(string $name): bool
    {
        $path = self::exportPath($name);

        return $path !== null && @unlink($path);
    }

    /** Täglicher Lauf: alte Export-Dateien entfernen. */
    public static function pruneExports(int $days = self::EXPORT_KEEP_DAYS): int
    {
        $deleted = 0;
        $cutoff = time() - max(1, $days) * 86400;
        foreach (self::listExports() as $file) {
            $path = self::exportPath($file['name']);
            if ($path !== null && (int) filemtime($path) < $cutoff && @unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private static function exportDir(): string
    {
        return PrivateStorageService::getInstance()->path(self::EXPORT_AREA);
    }
}
