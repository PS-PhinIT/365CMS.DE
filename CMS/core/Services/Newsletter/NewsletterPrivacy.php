<?php
/**
 * DSGVO-Anbindung des Newsletters.
 *
 * - `dsgvo_export_data($userId, $email)`: Abonnement(s) der Person samt Einwilligungsnachweis und Versandverlauf als
 *   JSON in die private Ablage (`private/newsletter/privacy-exports/`); Download unter Newsletter → Abonnenten,
 *   automatische Entfernung nach EXPORT_KEEP_DAYS Tagen.
 * - `dsgvo_delete_data($userId, $email)`: Abonnement(s) samt Versandzeilen löschen.
 * - Filter `cms_member_export_data`: Abonnement in der Selbstauskunft des Mitgliederbereichs.
 *
 * @package CMS\Services\Newsletter
 */

declare(strict_types=1);

namespace CMS\Services\Newsletter;

use CMS\AuditLogger;
use CMS\Database;
use CMS\Logger;
use CMS\Services\PrivateStorageService;

if (!defined('ABSPATH')) {
    exit;
}

final class NewsletterPrivacy
{
    public const EXPORT_AREA = 'newsletter/privacy-exports';
    public const EXPORT_KEEP_DAYS = 30;
    private const FILE_PATTERN = '/^newsletter-\d{8}-\d{6}-[a-f0-9]{12}\.json$/';

    /**
     * Daten der Person (Konto-ID oder E-Mail-Adresse).
     *
     * @return list<array<string, mixed>>
     */
    public static function collect(int $userId, string $email): array
    {
        $repo = NewsletterRepository::instance();
        if (!$repo->isAvailable()) {
            return [];
        }
        $db = Database::instance();
        $p = $db->getPrefix();
        $result = [];
        foreach ($repo->subscribersByEmailOrUser($email, $userId) as $row) {
            $sends = $db->execute(
                "SELECT c.subject, s.status, s.sent_at FROM {$p}newsletter_sends s LEFT JOIN {$p}newsletter_campaigns c ON c.id = s.campaign_id
                 WHERE s.subscriber_id = ? ORDER BY s.id ASC",
                [(int) $row['id']]
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $result[] = [
                'email' => $row['email'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'status' => $row['status'],
                'segment' => $row['segment_slug'],
                'language' => $row['locale'] ?? 'de',
                'source' => $row['source'],
                'subscribed_at' => $row['created_at'],
                'consent_at' => $row['consent_at'] ?? null,
                'consent_text' => $row['consent_text'] ?? null,
                'confirmed_at' => $row['confirmed_at'],
                'unsubscribed_at' => $row['unsubscribed_at'] ?? null,
                'issues' => $sends,
            ];
        }

        return $result;
    }

    /** Hook `dsgvo_export_data`. Rückgabe: Dateiname oder ''. */
    public static function handleExport(mixed $userId = null, mixed $email = null): string
    {
        $userId = is_numeric($userId) ? (int) $userId : 0;
        $email = is_string($email) ? trim($email) : '';
        try {
            $entries = self::collect($userId, $email);
            if ($entries === []) {
                return '';
            }
            $name = 'newsletter-' . date('Ymd-His') . '-' . substr(hash('sha256', $userId . '|' . strtolower($email) . '|' . microtime(true)), 0, 12) . '.json';
            $json = json_encode([
                'type' => 'newsletter',
                'generated_at' => date('c'),
                'subject' => ['user_id' => $userId > 0 ? $userId : null, 'email' => $email],
                'subscriptions' => $entries,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $dir = PrivateStorageService::getInstance()->path(self::EXPORT_AREA);
            if (!is_string($json) || file_put_contents($dir . $name, $json, LOCK_EX) === false) {
                throw new \RuntimeException('Export-Datei konnte nicht geschrieben werden.');
            }
            @chmod($dir . $name, 0640);
            AuditLogger::instance()->log(AuditLogger::CAT_SETTING, 'newsletter.privacy.export', 'DSGVO-Auskunft: Newsletter-Abonnement exportiert', 'newsletter_subscriber', null, ['file' => $name], 'info');

            return $name;
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('newsletter')->error('DSGVO-Export des Newsletters fehlgeschlagen.', ['message' => $e->getMessage()]);

            return '';
        }
    }

    /** Hook `dsgvo_delete_data`. */
    public static function handleDelete(mixed $userId = null, mixed $email = null): int
    {
        $repo = NewsletterRepository::instance();
        if (!$repo->isAvailable()) {
            return 0;
        }
        $deleted = 0;
        foreach ($repo->subscribersByEmailOrUser(is_string($email) ? $email : '', is_numeric($userId) ? (int) $userId : 0) as $row) {
            $deleted += $repo->deleteSubscriber((int) $row['id']) ? 1 : 0;
        }
        if ($deleted > 0) {
            AuditLogger::instance()->log(AuditLogger::CAT_SETTING, 'newsletter.privacy.delete', 'DSGVO-Löschung: Newsletter-Abonnement gelöscht', 'newsletter_subscriber', null, ['count' => $deleted], 'warning');
        }

        return $deleted;
    }

    /** @return list<array{name: string, size: int, created_at: string}> */
    public static function listExports(): array
    {
        $files = [];
        foreach (glob(PrivateStorageService::getInstance()->path(self::EXPORT_AREA) . 'newsletter-*.json') ?: [] as $path) {
            $name = basename($path);
            if (preg_match(self::FILE_PATTERN, $name) === 1) {
                $files[] = ['name' => $name, 'size' => (int) filesize($path), 'created_at' => date('Y-m-d H:i:s', (int) filemtime($path))];
            }
        }
        usort($files, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $files;
    }

    public static function exportPath(string $name): ?string
    {
        return preg_match(self::FILE_PATTERN, $name) === 1 ? PrivateStorageService::getInstance()->resolve(self::EXPORT_AREA, $name) : null;
    }

    public static function deleteExport(string $name): bool
    {
        $path = self::exportPath($name);

        return $path !== null && is_file($path) && @unlink($path);
    }

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
}
