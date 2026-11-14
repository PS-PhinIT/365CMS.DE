<?php
/**
 * Datenbank-Umsetzung von `IdentityStoreInterface` (nur gebundene Parameter).
 *
 * @package CMSv2\Core\Auth\SSO
 */

declare(strict_types=1);

namespace CMS\Auth\SSO;

use CMS\Database;
use CMS\Security;
use CMS\Services\EmailVerificationService;

if (!defined('ABSPATH')) {
    exit;
}

final class DatabaseIdentityStore implements IdentityStoreInterface
{
    private Database $db;
    private string $prefix;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    public function findIdentity(string $provider, string $tenantId, string $subject): ?array
    {
        $row = $this->db->get_row(
            "SELECT id, user_id, provisioned, email_at_link, linked_at, last_login_at FROM {$this->prefix}user_identities
             WHERE provider = ? AND tenant_id = ? AND subject = ? LIMIT 1",
            [$provider, $tenantId, $subject]
        );

        return $row === null ? null : $this->identityRow($row);
    }

    public function findIdentityForUser(int $userId, string $provider): ?array
    {
        $row = $this->db->get_row(
            "SELECT id, user_id, tenant_id, subject, provisioned, email_at_link, linked_at, last_login_at FROM {$this->prefix}user_identities
             WHERE user_id = ? AND provider = ? LIMIT 1",
            [$userId, $provider]
        );
        if ($row === null) {
            return null;
        }

        return $this->identityRow($row) + [
            'tenant_id' => (string) ($row->tenant_id ?? ''),
            'subject' => (string) ($row->subject ?? ''),
        ];
    }

    public function findUser(int $userId): ?object
    {
        return $this->db->get_row(
            "SELECT id, username, email, display_name, role, status FROM {$this->prefix}users WHERE id = ? LIMIT 1",
            [$userId]
        );
    }

    public function findUsersByEmail(string $email): array
    {
        return array_values($this->db->get_results(
            "SELECT id, username, email, display_name, role, status FROM {$this->prefix}users WHERE LOWER(email) = LOWER(?) LIMIT 5",
            [$email]
        ));
    }

    public function isEmailVerified(int $userId): bool
    {
        return EmailVerificationService::getInstance()->isEmailVerified($userId);
    }

    public function usernameExists(string $username): bool
    {
        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}users WHERE username = ?", [$username]) > 0;
    }

    public function createUser(string $username, string $email, string $displayName, string $role, bool $emailVerified): int
    {
        // Zufälliges, nie mitgeteiltes Passwort: Das Konto meldet sich über Entra an (oder setzt per „Passwort vergessen“ eins).
        $id = $this->db->insert('users', [
            'username' => $username,
            'email' => $email,
            'password' => Security::hashPassword(bin2hex(random_bytes(32))),
            'display_name' => $displayName,
            'role' => $role,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $id = is_int($id) ? $id : 0;
        if ($id > 0 && $emailVerified) {
            $this->db->execute(
                "INSERT INTO {$this->prefix}user_meta (user_id, meta_key, meta_value) VALUES (?, 'email_verified_at', ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                [$id, date('Y-m-d H:i:s')]
            );
        }

        return $id;
    }

    public function deleteUser(int $userId): void
    {
        $this->db->execute("DELETE FROM {$this->prefix}users WHERE id = ?", [$userId]);
    }

    public function createIdentity(int $userId, string $provider, string $tenantId, string $subject, string $email, bool $provisioned): bool
    {
        $id = $this->db->insert('user_identities', [
            'user_id' => $userId,
            'provider' => $provider,
            'tenant_id' => $tenantId,
            'subject' => $subject,
            'email_at_link' => $email !== '' ? mb_substr($email, 0, 150) : null,
            'provisioned' => $provisioned ? 1 : 0,
            'linked_at' => date('Y-m-d H:i:s'),
        ]);

        return is_int($id) && $id > 0;
    }

    public function deleteIdentity(int $userId, string $provider): bool
    {
        $stmt = $this->db->execute(
            "DELETE FROM {$this->prefix}user_identities WHERE user_id = ? AND provider = ?",
            [$userId, $provider]
        );

        return $stmt->rowCount() > 0;
    }

    public function touchIdentity(int $identityId): void
    {
        $this->db->execute(
            "UPDATE {$this->prefix}user_identities SET last_login_at = ? WHERE id = ?",
            [date('Y-m-d H:i:s'), $identityId]
        );
    }

    public function updateRole(int $userId, string $role): bool
    {
        return $this->db->update('users', ['role' => $role], ['id' => $userId]);
    }

    /**
     * @return array{id:int,user_id:int,provisioned:bool,email_at_link:string,linked_at:string,last_login_at:string}
     */
    private function identityRow(object $row): array
    {
        return [
            'id' => (int) ($row->id ?? 0),
            'user_id' => (int) ($row->user_id ?? 0),
            'provisioned' => (int) ($row->provisioned ?? 0) === 1,
            'email_at_link' => (string) ($row->email_at_link ?? ''),
            'linked_at' => (string) ($row->linked_at ?? ''),
            'last_login_at' => (string) ($row->last_login_at ?? ''),
        ];
    }
}
