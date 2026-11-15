# LdapRecord

> **Stand:** 2026-11-15 | **Version:** 4.1.0 | **Status:** Stable
> **English summary:** LdapRecord provides the LDAP / Active Directory connection for external directories. Details in German below.

## Kurzbeschreibung

`LdapRecord` stellt die LDAP-Anbindung für externe Verzeichnisdienste bereit.

## Quellordner

- `CMS/assets/ldaprecord/`

## Verwendung in 365CMS

- direkte Nutzung in `CMS/core/Auth/LDAP/LdapAuthProvider.php`
- Einbindung über `CMS/assets/autoload.php`
- Login-Sync über `CMS/core/Auth/AuthManager.php`
- Admin-Erstsync über `CMS/admin/user-settings.php` und `CMS/admin/modules/users/UserSettingsModule.php`

## Produktive Funktionen

- Authentifizierung gegen LDAP / Active Directory
- lokale Benutzeranlage bzw. Aktualisierung bestehender CMS-Konten
- Admin-initiierter LDAP-Erstsync für bis zu 250 Einträge pro Lauf

## Hinweise

- Die technische Konfiguration erfolgt weiterhin über `CMS/config/app.php`.
- Der Admin-Sync verwendet den vorhandenen Service-Account und die konfigurierten Filter/`BASE_DN`.

## Website / GitHub

- Website: https://ldaprecord.com/
- GitHub: https://github.com/DirectoryTree/LdapRecord