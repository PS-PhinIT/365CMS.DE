# 365CMS – Admin: Anmeldung mit Microsoft Entra ID (SSO)

> **Stand:** 2026-11-14 | **Version:** 4.1.0 (in Entwicklung) | **Status:** Beta | **Spezifikation:** [01-IDENTITY.md §1c](../../workflow/specs/v4.1/01-IDENTITY.md)

## English (summary)

*Settings → SSO* (`/admin/settings?tab=sso`, capability `security.sso`) connects 365CMS to Microsoft Entra ID via
OpenID Connect (authorization code flow with PKCE, state and nonce). The login page then shows “Sign in with
Microsoft”. ID tokens are verified against the tenant JWKS (RS256 only; `iss`, `aud`, `exp`/`nbf`/`iat` with 120 s
leeway, `nonce`, `tid`/`oid`, `ver`). Accounts are bound only by the immutable pair tenant ID + object ID; automatic
linking by email requires a tenant-verified address (`xms_edov`), an allowed domain and a non-privileged local account
with a verified address (lesson from GHSA-6hhv-gc4g-f4r7). Optional: auto-provisioning, role mapping from app roles or
groups, MFA from the token's `amr` claim, disabling password sign-in for linked accounts. The client secret is stored
encrypted and never shown again. No tokens are stored.

## Deutsch

### 1. App-Registrierung in Entra ID

1. *Entra Admin Center → App-Registrierungen → Neue Registrierung*, unterstützte Kontotypen: **nur dieses Organisationsverzeichnis** (Single-Tenant, empfohlen).
2. **Umleitungs-URI** (Plattform *Web*): `https://<deine-site>/cms-login/sso/entra/callback` – die exakte Adresse steht oben im Tab.
3. *Zertifikate & Geheimnisse → Neuer geheimer Clientschlüssel*: Wert sofort kopieren, Ablaufdatum notieren.
4. *Token-Konfiguration → Optionalen Anspruch hinzufügen (ID)*: `email`; für die automatische Verknüpfung zusätzlich `xms_edov`.
5. Für das Rollen-Mapping **App-Rollen** anlegen (*App-Rollen → App-Rolle erstellen*, z. B. `CMS.Editor`) und unter *Unternehmensanwendungen → Benutzer und Gruppen* zuweisen. Gruppen-IDs (`groups`-Claim) gehen auch, liefern aber ab 200 Gruppen nur einen Verweis (Overage) – dann wertet 365CMS nur App-Rollen aus.
6. Keine API-Berechtigungen nötig: 365CMS fordert nur `openid profile email` an und verwirft Zugriffstokens.

### 2. Einstellungen (`/admin/settings?tab=sso`)

| Feld | Wirkung |
|---|---|
| Knopf anzeigen | Schaltet „Mit Microsoft anmelden“ auf der Loginseite ein; aktiv erst mit Tenant-ID, Client-ID und Secret |
| Tenant-ID | Pflicht (GUID); Vorschlag aus der Mail-Konfiguration (Microsoft 365) per Klick |
| Client-ID, Client-Secret | Secret wird verschlüsselt gespeichert (`CryptoService`, Kontext `settings`) und nie zurückgegeben; leeres Feld behält es |
| Ablaufdatum des Secrets | 30 Tage vorher Hinweis in der Seitenleiste und im Tab |
| Weitere erlaubte Tenants | Multi-Tenant nur mit Allowlist (Autorität `organizations`); die automatische Verknüpfung ist dann aus |
| Bestehende Konten automatisch verknüpfen | Standard aus; Bedingungen siehe §3 |
| Neue Konten anlegen | Standard aus; Konto mit Standardrolle oder Rolle aus dem Mapping, zufälliges Passwort |
| Anmeldung mit Passwort | `all` · `disabled_for_linked` (verknüpfte Konten nur über Microsoft) · `admins_only` (nur Admins per Passwort); Admins behalten immer den Notfallzugang; wirkt nur, solange SSO eingerichtet ist |
| MFA aus Entra anerkennen | `amr` mit `mfa`, `ngcmfa`, `fido` oder `rsa` gilt für die Sitzung als zweiter Faktor (MFA-Zwang aus [LOGIN-THRESHOLDS.md](LOGIN-THRESHOLDS.md)) |
| Rollen-Mapping | erste passende Zeile gewinnt; `admin` nur mit „Admin-Rolle erlauben“ |
| Rollen synchronisieren | nur für über SSO angelegte Konten; Herabstufen erlaubt |

„Verbindung prüfen“ liest das Discovery-Dokument und ruft den Token-Endpunkt mit einem ungültigen Code auf:
`invalid_grant` bedeutet, dass Client-ID und Secret angenommen wurden; `invalid_client` heißt falsches oder
abgelaufenes Secret.

### 3. Kontozuordnung

1. **Bestehende Bindung** (`user_identities`: Anbieter `entra`, Tenant-ID, Objekt-ID) → dieses Konto. Gesperrte oder inaktive Konten werden abgelehnt und nie reaktiviert.
2. **Automatische Verknüpfung** nur, wenn alles gilt: Einstellung an, Single-Tenant, Claim `email` mit `xms_edov = true`, Domain in der Liste, genau ein aktives Konto mit dieser Adresse, dessen Adresse lokal bestätigt ist, und dessen Rolle weder `admin`, `editor`, `author` noch eine Rolle mit MFA-Zwang ist. `preferred_username` und `upn` werden nie verwendet.
3. **Kein Konto mit dieser Adresse** → mit „Neue Konten anlegen“ ein neues Konto, sonst „Kein Konto verknüpft“.
4. **Konto mit gleicher Adresse, aber Bedingungen aus 2. nicht erfüllt** → Ablehnung und Audit-Eintrag `sso_link_conflict`. Die Person meldet sich mit Passwort an und verknüpft unter *Mitgliederbereich → Sicherheit → Microsoft-Konto* („Mit Microsoft verknüpfen“, aktuelles Passwort nötig). Trennen geht dort ebenfalls nur mit Passwort.

### 4. Ablauf und Sicherheit

- Start `GET /cms-login/sso/entra/start` legt `state`, `nonce` und PKCE-Verifier (je 32 Byte) für 10 Minuten in die Sitzung; jeder `state` ist einmalig.
- Das Sitzungs-Cookie ist `SameSite=Strict` und fehlt beim Rücksprung von Microsoft. Der Callback antwortet deshalb zuerst mit einer kurzen Zwischenseite, die sich per `<meta http-equiv="refresh">` selbst mit `hop=1` aufruft; erst dieser Aufruf von der eigenen Seite trägt die Sitzung und löst den Code ein.
- Ungültiger oder wiederverwendeter `state` → 400 und Audit `sso_state_invalid`; mehr als 20 Fehlschläge je IP in 15 Minuten → 429 (`sso_callback`).
- Ohne MFA-Nachweis im Token und mit lokaler Authenticator-App folgt die gewohnte Code-Abfrage. Fehlt bei MFA-Zwang ein Faktor, landet die Sitzung auf `/mfa-setup`; dort (und beim Hinzufügen von TOTP oder Passkey im Mitgliederbereich) ersetzt eine höchstens 10 Minuten alte Microsoft-Anmeldung die Passwortabfrage.
- Gespeichert werden keine Tokens, nur Tenant-ID, Objekt-ID, Adresse zum Zeitpunkt der Verknüpfung und Zeitpunkte.

### 5. Audit-Ereignisse und Hooks

Audit (Kategorie Sicherheit): `sso_login_success`, `sso_login_failed`, `sso_login_mfa_pending`, `sso_token_invalid`,
`sso_state_invalid`, `sso_login_blocked`, `sso_link_conflict`, `sso_linked`, `sso_unlinked`, `sso_user_provisioned`,
`sso_role_changed`, `sso_groups_overage`, `sso_settings_saved`, `sso_connection_test`, `login_password_disabled`.

Hooks: `cms_sso_login` (`$userId, 'entra', $claims` ohne Token), `cms_sso_user_provisioned`, `user_role_changed`
(`$userId, $from, $to, 'sso'`) – siehe [../../core/HOOKS-REFERENCE.md](../../core/HOOKS-REFERENCE.md).

### 6. Fehlersuche

| Meldung | Ursache |
|---|---|
| „Kein Konto verknüpft“ | Keine Bindung, keine automatische Verknüpfung möglich (Adresse unbestätigt, Domain fehlt, privilegierte Rolle) und kein Provisioning – Konto aktiv verknüpfen |
| „abgelaufen oder ungültig“ (400) | Mehr als 10 Minuten bei Microsoft, Zurück-Taste oder Callback in einem anderen Browser geöffnet |
| Verbindung prüfen: `invalid_client` | Secret falsch oder abgelaufen |
| AADSTS50011 bei Microsoft | Umleitungs-URI in der App-Registrierung stimmt nicht exakt |
| Rollen werden nicht übernommen | App-Rolle nicht zugewiesen, Gruppen-Overage, oder Konto nicht über SSO angelegt (keine Synchronisation) |

Tests: `php TESTS/sso-entra/run.php` (ohne Netzwerk und Datenbank, lokal erzeugter RSA-Schlüssel).
