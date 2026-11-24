<?php
/**
 * Entfernt beim endgültigen Löschen eines Kontos die Datensätze, die keine Fremdschlüssel-Regel (ON DELETE CASCADE)
 * auf `users` haben und Personenbezug tragen: Sitzungsregister (IP, User-Agent), Passkeys, SSO-Verknüpfungen,
 * Passwort-Verlauf, Abos samt Nutzung, Gruppenzuordnung und KI-Nutzung.
 *
 * Bewusst nicht dabei: Bestellungen und Rechnungen (Aufbewahrungspflicht, eigene Hooks `cms_user_deleted` /
 * `dsgvo_delete_data`), Protokolle (Nachweis), Community, Formulare, Newsletter und Feeds (eigene Hooks) sowie
 * Beiträge, Seiten und Kommentare (Inhalt bleibt, siehe Datenschutz-Einstellungen).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class UserDataEraser
{
    /** Tabellen ohne Präfix; jede hat eine Spalte `user_id`. */
    public const TABLES = [
        'sessions',
        'passkey_credentials',
        'user_identities',
        'password_history',
        'subscription_usage',
        'user_subscriptions',
        'user_group_members',
        'ai_quota_usage',
    ];

    /**
     * @return array<string, int> Tabelle (ohne Präfix) => gelöschte Zeilen; fehlende Tabellen fehlen im Ergebnis
     */
    public static function erase(\PDO $pdo, string $prefix, int $userId): array
    {
        if ($userId <= 0 || preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
            return [];
        }

        $deleted = [];
        foreach (self::TABLES as $table) {
            try {
                $statement = $pdo->prepare('DELETE FROM ' . $prefix . $table . ' WHERE user_id = ?');
                $statement->execute([$userId]);
                $deleted[$table] = $statement->rowCount();
            } catch (\PDOException) {
                // Tabelle fehlt (Altinstallation, Funktion nicht aktiv) oder ist durch eine Fremdschlüssel-Regel gesperrt.
            }
        }

        return $deleted;
    }
}
