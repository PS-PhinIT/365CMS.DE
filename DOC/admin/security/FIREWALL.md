# 365CMS – Projektdokumentation | Abschnitt: Admin – Firewall

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/firewall` (Alt-Route `/admin/cms-firewall` leitet um) | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_firewall` | **Core-Modul:** `security`

## English (summary)

The application firewall runs on every web request (`Bootstrap` → `CMS\Services\SecurityRuntimeService::handleRequest()`). It evaluates rules from `cms_firewall_rules` (types `block_ip`, `block_range`, `allow_ip`, `block_ua`, `block_country`; mode `enforce` or `simulate`), applies an IP rate limit and logs events to `cms_security_log`. Admin page: `CMS/admin/firewall.php` → `CMS/admin/modules/security/FirewallModule.php` → `CMS/admin/views/security/firewall.php`. Actions: `save_settings`, `apply_baseline_profile`, `add_rule`, `delete_rule`, `toggle_rule`, `set_rule_mode`.

## Deutsch

### Einstellungen

| Option | Bedeutung | Laufzeit-Standard |
|---|---|---|
| `firewall_enabled` | Firewall aktiv | aus |
| `firewall_rate_limit` | max. Anfragen je IP im Zeitfenster | 60 |
| `firewall_rate_window` | Zeitfenster in Sekunden | 60 |
| `firewall_block_duration` | Sperrdauer nach Überschreitung (s) | 3600 |
| `firewall_log_enabled` | auch erlaubte Treffer von Allow-Regeln protokollieren | aus |
| `firewall_simulation_preview_hours` | Zeitraum der Simulationsauswertung | 24 |

### Basisprofile (`apply_baseline_profile`)

| Profil | Rate-Limit / Fenster | Sperrdauer | Logging | Simulation |
|---|---|---|---|---|
| Entwicklung | 300 / 60 s | 5 min | an | 12 h |
| Staging | 120 / 60 s | 30 min | an | 24 h |
| Produktion | 60 / 60 s | 60 min | an | 48 h |

### Regeln (`add_rule`)

| Typ | Wert | Wirkung |
|---|---|---|
| `allow_ip` | einzelne IP | hat Vorrang, Request wird nicht durch Regeln blockiert |
| `block_ip` | einzelne IP | 403 |
| `block_range` | CIDR, z. B. `198.51.100.0/24` | 403 |
| `block_ua` | Teilstring des User-Agents (min. 3 Zeichen) | 403 |
| `block_country` | ISO-Ländercode (2 Buchstaben) | 403 – nur wirksam, wenn ein vorgelagerter Dienst den Header `CF-IPCountry` (Cloudflare) oder `X-AppEngine-Country` setzt |

Jede Regel hat optional Grund und Ablaufdatum (`expires_at`) und kann aktiviert/deaktiviert (`toggle_rule`) werden.

**Modus (`set_rule_mode`):**
- `enforce` – Regel blockiert.
- `simulate` – Regel blockiert **nicht**, Treffer werden als `simulated` protokolliert. So lassen sich neue Regeln gefahrlos testen; die Auswertung zeigt die Treffer der letzten `firewall_simulation_preview_hours` Stunden.

### Ablauf je Anfrage

1. Abgelaufene Laufzeitsperren entfernen.
2. Regeln auswerten (`allow_ip` vor Block-Regeln).
3. Bei Block-Treffer: HTTP 403 und Log-Eintrag.
4. Rate-Limit prüfen; bei Überschreitung temporäre Sperre (`firewall_block_duration`).
5. Fehler in der Firewall selbst blockieren die Seite nicht (Warnung im Logger-Kanal `security.runtime`).

Die Client-IP ermittelt `Security::getClientIp()`; hinter Proxys/CDN muss die Weitergabe der echten IP korrekt konfiguriert sein, sonst wird die Proxy-IP gesperrt.

### Tabellen

- `cms_firewall_rules` (`rule_type`, `rule_mode`, `value`, `reason`, `is_active`, `expires_at`)
- `cms_security_log` (`action` z. B. `blocked`, `simulated`, `allowed`, `ip_address`, `request_uri`, `user_agent`, `rule_matched`, `user_id`, `extra`)

Auswertung im Admin unter *Protokolle & Audit → Sicherheits-Audit* (`/admin/logs/security-audit`, siehe [../README.md](../README.md)).

### Verwandte Dokumente

[ANTISPAM.md](ANTISPAM.md) · [SECURITY-AUDIT.md](SECURITY-AUDIT.md) · [../../core/SECURITY.md](../../core/SECURITY.md)
