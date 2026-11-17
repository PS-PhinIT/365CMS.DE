<?php
/**
 * Capabilities des Community-Moduls (`community.*`), ihre Standardrechte und der einmalige Update-Schritt
 * `capabilities_v27` (Schema v27, Release 4.2.0).
 *
 * Eine Stelle für die Namen: Der Rollen-Katalog (`RolesModule::CAPABILITIES`), die Standardrollen
 * (`cms_get_default_role_definitions()` in `includes/functions/roles.php`) und die Migration lesen sie hier; die Suite
 * `TESTS/schema-v27` prüft, dass die fest eingetragenen Listen mit dieser Klasse übereinstimmen.
 *
 * Die Migration läuft im Konstruktor von `Database` (über `SchemaManager` und `MigrationManager`) und darf deshalb nur
 * die übergebene Verbindung nutzen, nie den Singleton-Zugriff auf `Database` (DB-410-02).
 *
 * Entscheidungsstelle für den Zugriff ist später `CommunityAccess::can()` (PR #2); diese Klasse regelt nur, wer welche
 * globale Capability von Haus aus hat. Spezifikation: DOC/workflow/specs/community/PERMISSIONS-MODERATION.md §2.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Support;

use CMS\Database;

if (!defined('ABSPATH')) {
    exit;
}

final class CommunityCapabilities
{
    /** Community überhaupt sehen (Gäste über die Einstellung „Gäste dürfen lesen“). */
    public const VIEW = 'community.view';
    /** Schreiben grundsätzlich erlaubt, zusätzlich zu den Rechten je Forum. */
    public const PARTICIPATE = 'community.participate';
    /** Globale Moderation: Warteschlange, Meldungen, Verwarnungen, befristete Sperren. */
    public const MODERATE = 'community.moderate';
    /** Dauerhafte Sperren und Aufheben fremder Sperren. */
    public const BAN = 'community.ban';
    /** Struktur, Rechte, Ränge/Abzeichen, Einstellungen, Statistik. */
    public const MANAGE = 'community.manage';
    /** Gekürzte IP-Kennung und Bearbeitungsverlauf gelöschter Beiträge. */
    public const VIEW_PRIVATE_DATA = 'community.view_private_data';

    /**
     * Alle Capabilities in der Reihenfolge der Rollenverwaltung (Gruppe „community“).
     *
     * @var list<string>
     */
    public const ALL = [
        self::VIEW,
        self::PARTICIPATE,
        self::MODERATE,
        self::BAN,
        self::MANAGE,
        self::VIEW_PRIVATE_DATA,
    ];

    /** Gruppe im Rollen-Katalog (`RolesModule::CAPABILITIES`). */
    public const GROUP = 'community';

    /** Standardrolle für globale Moderation (neu in 4.2.0). */
    public const MODERATOR_ROLE = 'moderator';

    /** Option in `settings`: der Update-Schritt ist gelaufen. */
    public const MARKER = 'capabilities_v27';

    /**
     * Standardrechte je Rolle. „admin“ hat alle Rechte ohnehin (`Auth::hasCapability()`) und taucht hier nicht auf; seine
     * drei exklusiven Rechte (`ban`, `manage`, `view_private_data`) vergibt keine Migration an andere Rollen.
     *
     * @var array<string, list<string>>
     */
    public const DEFAULT_GRANTS = [
        'member' => [self::VIEW, self::PARTICIPATE],
        'editor' => [self::VIEW, self::PARTICIPATE],
        'author' => [self::VIEW, self::PARTICIPATE],
        self::MODERATOR_ROLE => [self::VIEW, self::PARTICIPATE, self::MODERATE],
    ];

    /**
     * Grundrechte der neuen Rolle „moderator“, wenn sie noch nicht existiert: wie „member“ (Lesen, Profil, Kommentare
     * sehen), ohne Zugang zu `/admin` (den hat nur die Rolle „admin“). Entspricht der Definition in `roles.php`.
     *
     * @var list<string>
     */
    public const MODERATOR_BASE = [
        'read', 'edit_profile', 'view_content', 'pages.view', 'posts.view', 'media.view', 'comments.view',
    ];

    /**
     * Capabilities, mit denen eine Rolle weder bei der Registrierung angeboten noch automatisch vergeben werden darf
     * (`UserService::REGISTRATION_BLOCKED_CAPABILITIES`).
     *
     * @var list<string>
     */
    public const ELEVATED = [self::MODERATE, self::BAN, self::MANAGE, self::VIEW_PRIVATE_DATA];

    /**
     * @return array<string, list<string>> Rolle => Capabilities, die diese Migration vergibt.
     */
    public static function defaultGrants(): array
    {
        return self::DEFAULT_GRANTS;
    }

    public static function isCommunityCapability(string $capability): bool
    {
        return in_array($capability, self::ALL, true);
    }

    /**
     * Update-Schritt `capabilities_v27`: einmalig die Standardrechte eintragen und die Rolle „moderator“ anlegen.
     *
     * - Läuft nur, solange der Marker `capabilities_v27` in `settings` fehlt; er wird erst nach dem erfolgreichen
     *   Eintragen gesetzt (bei einem Fehler läuft der Schritt beim nächsten Durchlauf erneut).
     * - Überschreibt nie einen vorhandenen Eintrag in `role_permissions` (`INSERT IGNORE` auf den eindeutigen Schlüssel
     *   `uk_role_cap`): ein ausdrücklich entzogenes Recht (`granted = 0`) bleibt entzogen.
     * - Existiert die Rolle „moderator“ noch nicht (keine Zeilen in `role_permissions`, kein Konto mit dieser Rolle),
     *   erhält sie zusätzlich die Grundrechte aus MODERATOR_BASE. Eine bereits vorhandene Rolle gleichen Namens bekommt nur
     *   die Community-Standards.
     * - Vergibt `community.ban`, `community.manage` und `community.view_private_data` an niemanden; „admin“ hat sie über
     *   die Rolle.
     *
     * @return list<array{0: string, 1: string}> die neu eingetragenen Paare [Rolle, Capability] (leer, wenn der Schritt
     *                                         schon gelaufen ist oder nichts zu tun war)
     *
     * @throws \Throwable bei Datenbankfehlern; der Aufrufer protokolliert und versucht es später erneut
     */
    public static function migrate(Database $db): array
    {
        $prefix = $db->getPrefix();
        $settings = $prefix . 'settings';
        $permissions = $prefix . 'role_permissions';

        if ((int) $db->get_var("SELECT COUNT(*) FROM {$settings} WHERE option_name = ?", [self::MARKER]) > 0) {
            return [];
        }

        $granted = [];

        $moderatorExists = (int) $db->get_var("SELECT COUNT(*) FROM {$permissions} WHERE role = ?", [self::MODERATOR_ROLE]) > 0
            || (int) $db->get_var("SELECT COUNT(*) FROM {$prefix}users WHERE role = ?", [self::MODERATOR_ROLE]) > 0;

        $grants = [];
        foreach (self::DEFAULT_GRANTS as $role => $capabilities) {
            if ($role === self::MODERATOR_ROLE && !$moderatorExists) {
                $capabilities = array_merge(self::MODERATOR_BASE, $capabilities);
            }
            foreach ($capabilities as $capability) {
                $grants[] = [$role, $capability];
            }
        }

        foreach ($grants as [$role, $capability]) {
            $statement = $db->execute(
                "INSERT IGNORE INTO {$permissions} (role, capability, granted) VALUES (?, ?, 1)",
                [$role, $capability]
            );
            if ($statement->rowCount() === 1) {
                $granted[] = [$role, $capability];
            }
        }

        $db->execute(
            "INSERT INTO {$settings} (option_name, option_value, autoload) VALUES (?, ?, 0)
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            [self::MARKER, gmdate('Y-m-d H:i:s')]
        );

        return $granted;
    }
}
