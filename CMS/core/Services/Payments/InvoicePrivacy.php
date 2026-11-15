<?php
/**
 * DSGVO-Auskunft für Rechnungen (4.1.0, Bereich 5b).
 *
 * - `dsgvo_export_data($userId, $email)` (Admin → DSGVO-Anfragen → „Abschließen & exportieren“): schreibt alle
 *   Rechnungen der Person (auch gesperrte, inkl. Rechnungsdaten) als JSON in den privaten Speicher
 *   `invoices/privacy-exports/`; herunterladbar unter *Abos → Rechnungen*. Exporte werden nach 30 Tagen gelöscht.
 * - Selbstauskunft im Mitgliederbereich (`cms_member_export_data`): Schlüssel `invoices`.
 * - Gelöscht wird nichts: Rechnungen unterliegen der Aufbewahrungspflicht (§ 147 AO, § 14b UStG, 10 Jahre); eine
 *   Löschanfrage setzt nur den Sperrvermerk (`InvoiceHooks`).
 *
 * @package CMSv2\Core\Services\Payments
 */

declare(strict_types=1);

namespace CMS\Services\Payments;

use CMS\AuditLogger;
use CMS\Logger;
use CMS\Services\PrivateStorageService;

if (!defined('ABSPATH')) {
    exit;
}

final class InvoicePrivacy
{
    public const EXPORT_AREA = 'invoices/privacy-exports';
    public const FILE_PATTERN = '/^invoices-\d{8}-\d{6}-[a-f0-9]{12}\.json$/';
    public const KEEP_DAYS = 30;

    /**
     * @return array<string, mixed>
     */
    public static function document(int $userId, string $email, array $invoices): array
    {
        return [
            'type' => 'invoices',
            'generated_at' => date('c'),
            'subject' => ['user_id' => $userId > 0 ? $userId : null, 'email' => $email],
            'retention_notice' => 'Rechnungen werden aufgrund gesetzlicher Aufbewahrungspflichten (§ 147 AO, § 14b UStG) zehn Jahre aufbewahrt; eine Löschung ist bis dahin nicht möglich, die Verarbeitung wird auf Antrag eingeschränkt.',
            'invoices' => $invoices,
        ];
    }

    /** Hook `dsgvo_export_data`. Rückgabe: Dateiname oder '' (keine Rechnungen/Fehler). */
    public static function handleExport(mixed $userId = null, mixed $email = null): string
    {
        $userId = is_numeric($userId) ? (int) $userId : 0;
        $email = is_string($email) ? trim($email) : '';
        try {
            self::pruneExports();
            $invoices = InvoiceService::getInstance()->exportForPrivacy($userId, $email);
            if ($invoices === []) {
                return '';
            }
            $name = 'invoices-' . date('Ymd-His') . '-' . substr(hash('sha256', $userId . '|' . strtolower($email) . '|' . microtime(true)), 0, 12) . '.json';
            $json = json_encode(self::document($userId, $email, $invoices), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $dir = PrivateStorageService::getInstance()->path(self::EXPORT_AREA);
            if (!is_string($json) || file_put_contents($dir . $name, $json, LOCK_EX) === false) {
                throw new \RuntimeException('Export-Datei konnte nicht geschrieben werden.');
            }
            @chmod($dir . $name, 0640);
            AuditLogger::instance()->log(AuditLogger::CAT_SETTING, 'invoices.privacy.export', 'DSGVO-Auskunft: Rechnungen exportiert', 'invoice', null, ['file' => $name, 'count' => count($invoices)], 'info');

            return $name;
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('invoices')->error('DSGVO-Export der Rechnungen fehlgeschlagen.', ['message' => $e->getMessage()]);

            return '';
        }
    }

    /**
     * @return list<array{name: string, size: int, created_at: string}>
     */
    public static function listExports(): array
    {
        $files = [];
        foreach (glob(PrivateStorageService::getInstance()->path(self::EXPORT_AREA) . 'invoices-*.json') ?: [] as $path) {
            $name = basename($path);
            if (preg_match(self::FILE_PATTERN, $name) === 1) {
                $files[] = ['name' => $name, 'size' => (int) filesize($path), 'created_at' => date('Y-m-d H:i:s', (int) filemtime($path))];
            }
        }
        usort($files, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $files;
    }

    /**
     * Pfad einer Export-Datei nach Namensprüfung (kein Pfad aus der Anfrage), sonst null.
     */
    public static function exportPath(string $name): ?string
    {
        if (preg_match(self::FILE_PATTERN, $name) !== 1) {
            return null;
        }
        $path = PrivateStorageService::getInstance()->path(self::EXPORT_AREA) . $name;

        return is_file($path) ? $path : null;
    }

    public static function pruneExports(): int
    {
        $removed = 0;
        $limit = time() - self::KEEP_DAYS * 86400;
        foreach (self::listExports() as $file) {
            $path = self::exportPath($file['name']);
            if ($path !== null && (int) filemtime($path) < $limit && @unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }
}
