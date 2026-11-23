# 365CMS 4.3: Vergleich mit anderen CMS

> **Stand:** 2026-11-23 | **Methode:** offizielle Dokumentationsquellen, keine Marketing-Vergleichstabellen

Der Vergleich beantwortet nicht „Welche Plattform gewinnt?“, sondern „Welche
gemeinsamen Fähigkeiten sind für einen allgemeinen CMS-Vertrag relevant?“. Produkt-
oder Tarifmerkmale werden nicht als allgemeiner Standard behandelt.

## Vergleichsmatrix

| Kategorie | Andere CMS: belastbarer Befund | 365CMS 4.2-Stand | 4.3-Folgerung |
|---|---|---|---|
| Draft, Revision, Review | Craft, Strapi, Directus und TYPO3 trennen Entwurf/live; Joomla/Drupal/TYPO3 modellieren Stufen und Übergänge | vorhanden (`pending`, Revision, Preview, Rechte) | keine Neuentwicklung; nur C/B- und Mehrsprachenerweiterung |
| Scheduled publishing / Scheduler | WordPress WP-Cron ist besuchergetrieben; Joomla bietet Lazy/Web/CLI; TYPO3 Scheduler Aufgaben | Planen und Cron vorhanden | CLI/Worker-Vertrag V43-01/V43-21; nicht „Scheduler fehlt“ |
| Content Modeling | Craft, Drupal und Strapi bieten definierbare Typen/Felder; WordPress ergänzt feldgebundene Blockquellen | überwiegend feste Typen und JSON-Metadaten | V43-06 bis V43-10 ist der echte Kern-Gap |
| REST/JSON | WordPress, Drupal, Joomla und Ghost koppeln API an Auth, ACL und Content-Verträge | REST-/JSON-API und JWT vorhanden | V43-16 bis V43-18 erweitern Verwaltung und Betriebsnachweis |
| Granulare Rollen/Audit | Craft/Drupal/Joomla arbeiten granular; Strapi Audit Logs ist Enterprise | RBAC, MFA, Passkeys, SSO und Audit vorhanden | kein Basis-Umbau; Integrations-Identitäten und Export prüfen |
| MFA | TYPO3 und Joomla dokumentieren MFA/WebAuthn; WordPress dokumentiert MFA ohne verifizierte Core-Funktion | vorhanden | nur Policy-/Betriebserweiterung, kein Nachrüstprojekt |
| Localization / MultiSite | WordPress Multisite, Craft Sites, TYPO3 Sites/Sprachen, Drupal Multilingual, Strapi i18n | MultiSite und feste DE/EN-Lokalisierung | V43-11 bis V43-15 für beliebige Sprachen |
| Config-as-Code | Craft Project Config und Drupal Configuration Management sind YAML-/Datei-getrieben | kein gleichwertiger verbindlicher Import/Export-Vertrag belegt | V43-03 als Architekturvorschlag, Secrets nie unverschlüsselt |
| CLI | WP-CLI, Ghost-CLI und Drush decken Wartung und Deployments ab | zentraler CLI-Pfad fehlt | V43-01 und V43-02 sind MUST |
| Webhooks / Automation | Ghost/TYPO3/Strapi haben Webhooks; Directus bietet Flow-Automation | signierte ausgehende Webhooks vorhanden | V43-18 verbessern; V43-19 nur optionaler, abgesicherter Flow-Baukasten |
| Cache | Drupal nutzt Tags/Contexts; TYPO3 unterschiedliche Cache-Strategien | Cache-/Performance-Werkzeuge vorhanden, kein dokumentierter Tag-Vertrag | V43-20 |
| Medien | TYPO3 FAL abstrahiert Speicher; Craft hat Asset-Elemente und benannte Transforms | lokale Medien, Derivate, private Speicher, S3 für Backups | V43-23/V43-24 trennen Medien-Speicher von Backup-S3 |
| Observability / Health | TYPO3 Logging Framework, WordPress Site Health, Ghost-CLI Logs | Diagnose, Logs, Status und Audit vorhanden | V43-05 fokussiert externe, redigierte Senken |
| Membership / Commerce / Newsletter | Ghost hat dies nativ; bei vielen klassischen CMS sind Teile Add-ons | ungewöhnlich breiter eigener Core vorhanden | kein Marketplace-Kopierprojekt; vorhandene Verträge sichern |
| Forms | TYPO3 bietet Form Framework; andere Systeme oft eigene Core-/Addon-Modelle | eigener Form-Baukasten vorhanden | no-gap; nur API-/Captcha-/DSGVO-Kohärenz |
| Community | kein einheitliches Core-Baseline-Modell in den geprüften Quellen | Community P1 vorhanden, P2/P3 offen | V43-26 bis V43-36 statt fremde UI zu kopieren |

## Übertragbare Prinzipien

1. **ACL gilt auch über API und Token.** Joomla dokumentiert ausdrücklich, dass ein
   API-Token ACL, Validierung und Workflows nicht umgeht.
2. **CLI braucht echtes Betriebsverhalten.** Ghost-CLI und WP-CLI zeigen, dass
   Installation/Update/Konfiguration nur als Teil eines breiteren Wartungsvertrags
   sinnvoll sind.
3. **Konfiguration und Inhalt trennen.** Craft Project Config und Drupal Configuration
   Management legen Schema/Einstellungen versionierbar ab, nicht Nutzerinhalte.
4. **Cacheabhängigkeiten statt globaler Purge.** Drupal-Cache-Tags und TYPO3-
   Cache-Strategien motivieren V43-20.
5. **Sicherheit bei Integrationen.** Ghost-Integrationsschlüssel, Strapi-Signaturen/
   Zeitstempel und Directus' eingeschränkte Flows begründen Scope, Replay-Schutz
   und Berechtigungsprüfung.

## Nicht als Standard behandeln

- Strapi Review Workflows und Audit Logs sind Enterprise-Funktionen;
- Ghost Mitglieder/Newsletter sind produktspezifisch;
- Directus-Flows sind eine Low-Code-Schicht, kein allgemeiner CMS-Muss;
- OpenTelemetry, vollständige Commerce-Suiten und eingebautes Observability-Tracing
  wurden in den geprüften Kernquellen nicht als Baseline bestätigt.

Quellen und Abrufdatum: [SOURCES.md](SOURCES.md).
