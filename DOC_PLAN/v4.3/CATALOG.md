# 365CMS 4.3: Vollständiger Funktionskatalog

> **Stand:** 2026-11-23 | **Baseline:** 4.2.0 | **Ziel:** 4.3.0

## 1. Betrieb, CLI und Zuverlässigkeit

| ID | Pri. | Status | Fähigkeit | Nutzen | Abhängigkeiten / Kernoberflächen |
|---|---|---|---|---|---|
| V43-01 | MUST | [ ] | Zentrale CLI unter `CMS/cli.php` | reproduzierbare Wartung, CI, Cron ohne Web-Request | Auth-/Rollenmodell für CLI, Settings, Cache, Update, Backup, Import, Tests |
| V43-02 | MUST | [ ] | Echtzeit-Alerting über `alert.raised` | erkennbare Betriebsfehler statt stiller Logeinträge | V43-01; Eventmodell, Deduplizierung, Cooldown, Mail/Webhooks, `alert_state` |
| V43-03 | SHOULD | PROPOSED | Maschinenlesbare Konfigurations-Snapshots | Staging-/Produktionsabgleich und nachvollziehbare Deployments | Settings-Katalog, verschlüsselte Secrets, Validierung, Import/Rollback |
| V43-04 | SHOULD | PROPOSED | Einheitliches Deployment-/Preflight-Protokoll für CLI, UI und CI | weniger unterschiedliche Updatepfade | V43-01, V43-03, Release-Pakete, Systemvoraussetzungen |
| V43-05 | SHOULD | PROPOSED | Strukturierte externe Log-Senken und Site-Health-Export | Betrieb in Monitoring-/Audit-Systemen | Logger-Vertrag, redigierte Felder, Zieladapter, Backpressure |

**V43-01/V43-02 Akzeptanz:**

- Web-SAPI verweigert CLI-Aufrufe;
- sichere Passwort-/Secret-Eingabe, keine Secrets in CLI-Ausgabe oder Alerts;
- dokumentierte Exitcodes, JSON-Modus und lesbarer Modus;
- Schreibbefehle werden auditiert und durch Rechte/Bestätigung geschützt;
- Alert-Quellen sind dedupliziert, cooldown-fähig und testbar;
- Cron-, Backup-, Mail-, Update-, Queue- und Firewall-Pfade liefern verwertbare
  Betriebsereignisse.

## 2. Inhaltsplattform B: Felder und eigene Inhaltstypen

| ID | Pri. | Status | Fähigkeit | Nutzwert / Entscheidung |
|---|---|---|---|---|
| V43-06 | MUST | [ ] | Wiederverwendbare Feldgruppen und Feldtypen | strukturierte Zusatzdaten für bestehende Seiten/Beiträge |
| V43-07 | MUST | [ ] | Eigene Inhaltstypen mit Status, Revision, Suche, Trash und Exchange | Referenzen, Teams, Veranstaltungen, Produkte ohne Core-Sondercode |
| V43-08 | MUST | [ ] | Verwaltungs- und Code-Vertrag für Typen/Felder | deterministische Konfliktlösung zwischen UI-Definition, Datei-Definition und Plugin |
| V43-09 | MUST | [ ] | Editor-, Theme- und API-Integration neuer Typen | kein leerer Verwaltungstyp ohne Ausgabe oder Endpunkte |
| V43-10 | MUST | [ ] | Migration, Export/Import und Vorschau | Daten bleiben portabel und Änderungen rückrollbar |

Pflichtoberflächen: `SchemaManager`/Migration, `ContentTypeRegistry`, Editor.js,
Routing, Theme-Fallback, Suche, Sitemap, Papierkorb, Exchange, Berechtigungen,
Freigabe, Revision, Mehrsprachigkeit und Release-Pakete.

## 3. Inhaltsplattform C: beliebig viele Sprachen

| ID | Pri. | Status | Fähigkeit | Nutzwert |
|---|---|---|---|---|
| V43-11 | MUST vor C | [ ] | Sprachmodell und Migrator von festen `*_en`-Spalten | belastbarer Übergang ohne Inhaltsverlust |
| V43-12 | MUST | [ ] | Sprachverwaltung und Locale-Regeln | Quellsprache, Fallback, Publikationsstatus und Rechte je Sprache |
| V43-13 | SHOULD | [ ] | Sprachspezifische Routen, Canonical, Hreflang, Sitemap und Suche | vollständige öffentliche Auslieferung |
| V43-14 | SHOULD | [ ] | Übersetzungsstatus, Kopie und Lückenberichte für beliebige Sprachen | operierbare Redaktionsarbeit |
| V43-15 | SHOULD | [ ] | Migration weiterer Funktionsbereiche | Formulare, Downloads, Knowledgebase, Newsletter, Feeds und Metadaten |

C beginnt erst, wenn V43-06 bis V43-10 abgenommen sind.

## 4. Integrationen und API

| ID | Pri. | Status | Fähigkeit | Begründung aus dem CMS-Vergleich |
|---|---|---|---|---|
| V43-16 | SHOULD | PROPOSED | Widerrufbare Integrationsschlüssel mit Scope, Ablauf und Letztnutzung | häufiges Muster für Admin/API-Zugriff; bestehende Bearer-JWTs reichen nicht als verwaltete Integrationsidentität |
| V43-17 | SHOULD | PROPOSED | Generierte API-Beschreibung/OpenAPI aus denselben Route-Verträgen wie die Doku | weniger Drift zwischen Code, Clients und Tests |
| V43-18 | SHOULD | PROPOSED | Webhook-Inspektor mit Replay-Schutz, Signatur-/Zeitfensterprüfung und kontrolliertem Wiederholen | 365CMS hat Webhooks; Ziel ist ein stärkerer operativer Vertrag statt eines zweiten Webhook-Systems |
| V43-19 | COULD | PROPOSED | Ereignisrezepte bzw. Flow-Automation auf geprüften Triggern | in Headless-CMS verbreitet, aber nicht allgemeiner Baseline-Standard; nur mit Rechte- und Missbrauchsgrenzen |

Nicht in 4.3: ein neuer API-Autentifizierungsweg, der ACL, Freigaben oder
Capability-Prüfungen umgeht.

## 5. Performance, Cache und Queueing

| ID | Pri. | Status | Fähigkeit | Nutzen |
|---|---|---|---|---|
| V43-20 | SHOULD | PROPOSED | Cache-Abhängigkeiten/Tags statt nur pauschaler Invalidierung | präzise, beobachtbare Invalidierung bei Inhalten, Menüs, Sites und Community |
| V43-21 | SHOULD | PROPOSED | Ein gemeinsamer Worker-Vertrag für Batch-Jobs, Cron und CLI | einheitliche Sperren, Limits, Fortschritt, Retry und Abbruch |
| V43-22 | COULD | PROPOSED | Query-/Cache-Budget im Diagnose- und Release-Protokoll | Performance-Regressionen werden quantifizierbar |

Bestehende Datei-/Request-Caches, Batch-Jobs und Performance-Werkzeuge bleiben die
Ausgangsbasis. Es entsteht keine zweite Queue.

## 6. Medien und Assets

| ID | Pri. | Status | Fähigkeit | Nutzen |
|---|---|---|---|---|
| V43-23 | SHOULD | PROPOSED | Abstrakte Medienspeicher-Treiber | lokaler Speicher, optional S3-kompatibel, kontrollierbare URL-/Rechteverträge |
| V43-24 | SHOULD | PROPOSED | Konfigurierbare Transformationsprofile | ein benanntes, versioniertes Profil statt impliziter Einzeloptionen |
| V43-25 | COULD | PROPOSED | Prüfung von Bestand/Derivaten gegen Manifest | Wiederherstellung und Drift-Erkennung über Medienbibliothek |

Voraussetzung: private Anhänge, öffentliche Bilder, Downloads und Backups bleiben
klar getrennte Sicherheitsdomänen. S3-Offsite-Backup allein ist kein Beleg für
einen produktiven Medienspeicher-Treiber.

## 7. Community P2

| ID | Pri. | Status | Fähigkeit | Entscheidung |
|---|---|---|---|---|
| V43-26 | MUST | [ ] | Verwarnungen und Schwellenmaßnahmen | Abhängigkeit Moderationslog, Rechte, Retention |
| V43-27 | MUST | [ ] | Themen zusammenführen / teilen | Redirects, Zähler, Revisionen, Benachrichtigungen und Transaktionssicherheit |
| V43-28 | SHOULD | [ ] | Erwähnungen | Benachrichtigung, Datenschutz, Rate-Limit, Privatsphäre |
| V43-29 | SHOULD | [ ] | E-Mail-Digests | Queue, Gruppierung, Abmeldung, Lokalisierung, Frequenz |
| V43-30 | SHOULD | [ ] | Lesezeichen und Ignorieren | Präferenzen, Member-Ansicht, Such-/Benachrichtigungswirkung |
| V43-31 | SHOULD | VERIFY | Umfragen | zuerst 4.2.X-07 klären; nicht doppelt implementieren |
| V43-32 | COULD | [ ] | ähnliche Themen, Feeds, Online-Status und Statistik | nur nach klarer Bedeutung und Datenschutzbewertung |

## 8. Community P3 und Erweiterungen

| ID | Pri. | Status | Fähigkeit | Freigaberegel |
|---|---|---|---|---|
| V43-33 | COULD | PROPOSED | Reputation, Abzeichen, Tags/Präfixe | klare Anti-Gaming-, Rechte- und Exportregeln |
| V43-34 | COULD | PROPOSED | Struktur-Export/-Import und Community-Webhooks | Provider- und Eventvertrag vor Implementierung |
| V43-35 | COULD | PROPOSED | KI-Signale für Moderation/Qualität | nur Vorschlag/Assistenz, kein automatischer Sanktionseffekt ohne Review |
| V43-36 | OUT | – | Gruppen-Nachrichten und OAuth | eigene Kernvorhaben; nur bei separater Freigabe in 4.3 ziehen |

## 9. Redaktion und UX

| ID | Pri. | Status | Fähigkeit | Abgrenzung |
|---|---|---|---|---|
| V43-37 | COULD | PROPOSED | Konflikterkennung bei paralleler Bearbeitung | nicht mit Autosave gleichsetzen; Version/Fingerprint, Warnung und Recovery |
| V43-38 | COULD | PROPOSED | Konfigurierbare Workflow-Stufen je Inhaltstyp | vorhandenen `pending`-Workflow erweitern, nicht ersetzen |
| V43-39 | SHOULD | PROPOSED | Preview-/Freigabe-Verträge auf eigene Inhaltstypen und Sprachen erweitern | setzt V43-07 und V43-12 voraus |

## Explizit nicht als 4.3-Lücke

- Revisionen, einfacher Freigabeablauf und Vorschau-Links: vorhanden;
- MFA/TOTP/Passkeys, RBAC, SSO, Audit und DSGVO-Export: vorhanden;
- Formulare, Newsletter, Mitglieder, Zahlungen und Abos: vorhanden;
- Community-P1, lokale Captchas, privater Anhangspeicher und JSON-LD: vorhanden;
- Grundformen von CLI-ähnlicher Wartung in Web/Admin: werden durch V43-01 erweitert,
  nicht als bereits erledigte CLI bezeichnet.
