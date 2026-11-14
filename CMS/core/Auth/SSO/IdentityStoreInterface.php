<?php
/**
 * Datenzugriff des `IdentityLinker` (Tabelle `user_identities` und Konten) – austauschbar für `TESTS/sso-entra`.
 *
 * Benutzerobjekte haben mindestens: id, username, email, role, status.
 *
 * @package CMSv2\Core\Auth\SSO
 */

declare(strict_types=1);

namespace CMS\Auth\SSO;

if (!defined('ABSPATH')) {
    exit;
}

interface IdentityStoreInterface
{
    /** @return array{id:int,user_id:int,provisioned:bool,email_at_link:string,linked_at:string,last_login_at:string}|null */
    public function findIdentity(string $provider, string $tenantId, string $subject): ?array;

    /** @return array{id:int,user_id:int,tenant_id:string,subject:string,provisioned:bool,email_at_link:string,linked_at:string,last_login_at:string}|null */
    public function findIdentityForUser(int $userId, string $provider): ?array;

    public function findUser(int $userId): ?object;

    /** @return list<object> alle Konten mit dieser Adresse (ohne Groß-/Kleinschreibung) */
    public function findUsersByEmail(string $email): array;

    public function isEmailVerified(int $userId): bool;

    public function usernameExists(string $username): bool;

    /** @return int neue Benutzer-ID, 0 bei Fehler */
    public function createUser(string $username, string $email, string $displayName, string $role, bool $emailVerified): int;

    public function deleteUser(int $userId): void;

    public function createIdentity(int $userId, string $provider, string $tenantId, string $subject, string $email, bool $provisioned): bool;

    public function deleteIdentity(int $userId, string $provider): bool;

    public function touchIdentity(int $identityId): void;

    public function updateRole(int $userId, string $role): bool;
}
