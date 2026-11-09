<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function theme_is_logged_in(): bool
{
    return meridian_is_logged_in();
}

function theme_get_flash(): ?array
{
    return meridian_get_flash();
}

function theme_get_menu(string $location): array
{
    try {
        return \CMS\ThemeManager::instance()->getMenu($location) ?? [];
    } catch (\Throwable $e) {
        return [];
    }
}

function theme_login_user(string $email, string $password): bool|string
{
    try {
        $auth = \CMS\Auth::instance();
        $result = $auth->login($email, $password);
        if ($result === true) {
            return true;
        }

        return is_string($result) ? $result : 'Ungültige Zugangsdaten.';
    } catch (\Throwable $e) {
        // Keine Exception-Texte (z. B. SQL-Details) an Besucher ausgeben.
        error_log('theme_login_user: ' . $e::class . ': ' . $e->getMessage());

        return 'Anmeldung fehlgeschlagen.';
    }
}

function theme_register_user(string $email, string $username, string $password): bool|string
{
    try {
        // Auth::register() prüft Benutzername und E-Mail selbst und verrät eine bereits
        // registrierte Adresse nicht (Folgeaudit OFFEN-24).
        $result = \CMS\Auth::instance()->register([
            'email' => $email,
            'username' => $username,
            'password' => $password,
            'display_name' => $username,
        ]);

        return $result === true ? true : (is_string($result) ? $result : 'Registrierung fehlgeschlagen.');
    } catch (\Throwable $e) {
        error_log('theme_register_user: ' . $e::class . ': ' . $e->getMessage());

        return 'Registrierung fehlgeschlagen.';
    }
}
