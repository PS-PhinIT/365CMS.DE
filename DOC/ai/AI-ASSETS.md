> **Website:** [365CMS.DE](https://365cms.de/) | **Version:** 3.5.0
> **Datum:** 2026-09-06 | **Status:** Stable – **Zuletzt aktualisiert am:** 2026-10-02
> **Kurzbeschreibung:** User and technical reference for the AI-related JavaScript, the bundled Symfony AI Platform library, autoloading, licensing, and asset maintenance boundaries.

# 365CMS AI – Assets and Platform Library

## English

### User-friendly guide

The AI administration uses two browser assets:

- `CMS/assets/js/admin-ai-services.js` powers provider form field switching, content-draft copy-to-clipboard, and provider deletion confirmation.
- `CMS/assets/js/admin-content-editor.js` connects the page/post editor with block-by-block translation (`/admin/ai-translate-editorjs`), the SEO metadata generator (`/admin/ai-generate-seo-metadata`) and preview/diff workflows.

The server-side AI foundation is bundled under `CMS/assets/ai-platform/`. It is a read-only Symfony AI Platform subtree used as a contract and adapter foundation; it is not a public browser asset and must not be edited from the admin UI.

Administrators do not install or update these files through the CMS. Use the documented admin routes and normal deployment process. If an AI screen loses its dynamic behavior, first verify that the corresponding asset is present, loaded with the current asset loader, and not blocked by the Content Security Policy.

### Technical reference

`CMS/assets/js/admin-ai-services.js` is loaded for the shared AI admin view. It reads provider catalog/value JSON from `data-*` attributes, toggles provider-specific fields, preserves configured values, handles clipboard fallback, and confirms deletion of the active provider and its stored secret. It contains no provider credentials and does not call an AI endpoint directly.

`CMS/assets/js/admin-content-editor.js` is the editor integration layer for the protected translation endpoint, preview/diff handling, localized result fields, and request state. The endpoint and CSRF contract remain server-side; JavaScript is not an authorization boundary.

`CMS/assets/ai-platform/` contains the PSR-4 namespace `Symfony\AI\Platform\` under `src/`, registered by the CMS asset autoloader. The subtree exposes model, platform, message, tool, result, structured-output, token-usage, vector, event, and JSON-schema contracts, plus provider bridge interfaces and exceptions. Its package metadata requires PHP 8.2+, `ext-fileinfo`, PSR logging, Symfony 7.3/8.0 components, and the package's serializer/property dependencies.

The bundle is marked experimental by its upstream README and is maintained as a read-only subtree split. The local `README.md`, `CHANGELOG.md`, `LICENSE`, and `composer.json` are reference files; upstream compatibility changes must be evaluated against the CMS adapters before adoption. Do not add a second copy or modify vendor source in place.

The relevant integration points are:

| Asset or integration | Responsibility |
|---|---|
| `CMS/assets/js/admin-ai-services.js` | AI settings and content-draft UI behavior |
| `CMS/assets/js/admin-content-editor.js` | Editor.js AI translation and review UI |
| `CMS/assets/ai-platform/src/` | Symfony AI Platform contracts and implementations |
| `CMS/assets/autoload.php` | PSR-4 loading for `Symfony\AI\Platform\` |
| `CMS/core/Services/AI/` | CMS provider adapters, policy, quota, execution, and pipelines |
| `CMS/admin/ai-page.php` | AI admin route shell, actions, capabilities, and CSRF |
| `CMS/core/VendorRegistry.php` | Inventory entry „Symfony AI Platform“ (diagnostics in `/admin/monitor-assets`) |

**Current usage (3.5.0):** the CMS provider adapters in `CMS/core/Services/AI/` implement their own `AiProviderInterface` and HTTP calls via `CMS\Http\Client`; they do not import `Symfony\AI\Platform` classes directly. The bundled platform is registered and inventoried as the foundation for future adapters and for plugins that want to use it.

### Maintenance and security rules

Keep the JavaScript CSP-compatible and external; do not add inline scripts or secrets. Keep AI calls in the PHP service layer, preserve private/no-index response headers, and validate every request server-side. When the platform subtree changes, review its changelog and composer constraints, run the existing PHP checks, and update the version and asset inventory documentation.

## Deutsch

### Anwenderleitfaden

Die AI-Administration verwendet zwei Browser-Assets:

- `CMS/assets/js/admin-ai-services.js` steuert Provider-Felder, das Kopieren von Content-Entwürfen und die Löschbestätigung für Provider.
- `CMS/assets/js/admin-content-editor.js` verbindet den Seiten-/Beitragseditor mit der blockweisen Übersetzung (`/admin/ai-translate-editorjs`), dem SEO-Metadaten-Generator (`/admin/ai-generate-seo-metadata`) sowie Preview-/Diff-Abläufen.

Die serverseitige AI-Grundlage liegt unter `CMS/assets/ai-platform/`. Das Verzeichnis enthält einen schreibgeschützten Symfony-AI-Platform-Baum für Verträge und Adaptergrundlagen. Es ist kein öffentliches Browser-Asset und darf nicht über die Admin-Oberfläche bearbeitet werden.

Administratoren installieren oder aktualisieren diese Dateien nicht im CMS. Verwenden Sie die dokumentierten Admin-Routen und den regulären Deployment-Prozess. Wenn eine AI-Seite ihre dynamischen Funktionen verliert, prüfen Sie zuerst das Vorhandensein des Assets, die Einbindung über den aktuellen Asset-Loader und mögliche CSP-Blockierungen.

### Technische Referenz

`CMS/assets/js/admin-ai-services.js` wird für die gemeinsame AI-Adminansicht geladen. Das Script liest Provider-Katalog- und Wertdaten aus `data-*`-Attributen, schaltet typabhängige Felder, erhält konfigurierte Werte, bietet eine Clipboard-Alternative und bestätigt das Löschen des aktiven Providers mitsamt Secret. Es enthält keine Zugangsdaten und ruft AI-Provider nicht direkt auf.

`CMS/assets/js/admin-content-editor.js` bildet die Editor-Integration für den geschützten Übersetzungsendpoint, Preview-/Diff-Verarbeitung, lokalisierte Ergebnisfelder und Request-Zustände. Endpoint- und CSRF-Vertrag bleiben serverseitig; JavaScript ist keine Berechtigungsgrenze.

`CMS/assets/ai-platform/` stellt unter `src/` den PSR-4-Namespace `Symfony\AI\Platform\` bereit, der über den CMS-Asset-Autoloader registriert ist. Der Baum enthält Verträge und Implementierungen für Models, Plattformen, Messages, Tools, Results, Structured Output, Token Usage, Vektoren, Events und JSON-Schemas sowie Provider-Schnittstellen und Exceptions. Die Paketmetadaten erfordern PHP 8.2+, `ext-fileinfo`, PSR-Logging, Symfony-Komponenten 7.3/8.0 sowie Serializer-/Property-Abhängigkeiten.

Das Bundle ist laut Upstream-README experimentell und wird als schreibgeschützter Subtree geführt. `README.md`, `CHANGELOG.md`, `LICENSE` und `composer.json` dienen als Referenz; Upstream-Kompatibilitätsänderungen müssen vor der Übernahme gegen die CMS-Adapter geprüft werden. Keine zweite Kopie anlegen und Vendor-Quellcode nicht direkt verändern.

Die relevanten Integrationspunkte sind:

| Asset oder Integration | Aufgabe |
|---|---|
| `CMS/assets/js/admin-ai-services.js` | UI-Verhalten für AI-Einstellungen und Content-Entwürfe |
| `CMS/assets/js/admin-content-editor.js` | AI-Übersetzung und Review-UI im Editor.js |
| `CMS/assets/ai-platform/src/` | Symfony-AI-Platform-Verträge und Implementierungen |
| `CMS/assets/autoload.php` | PSR-4-Laden von `Symfony\AI\Platform\` |
| `CMS/core/Services/AI/` | CMS-Adapter, Policy, Quotas, Ausführung und Pipelines |
| `CMS/admin/ai-page.php` | Admin-Routes, Actions, Capabilities und CSRF |
| `CMS/core/VendorRegistry.php` | Inventareintrag „Symfony AI Platform“ (Diagnose unter `/admin/monitor-assets`) |

**Aktuelle Nutzung (3.5.0):** Die CMS-Provider-Adapter in `CMS/core/Services/AI/` implementieren ein eigenes `AiProviderInterface` und sprechen die Provider über `CMS\Http\Client` an; sie importieren keine `Symfony\AI\Platform`-Klassen direkt. Die gebündelte Plattform ist registriert und inventarisiert – als Grundlage für künftige Adapter und für Plugins, die sie nutzen möchten.

### Wartungs- und Sicherheitsregeln

JavaScript bleibt CSP-kompatibel und extern; Inline-Scripts und Secrets sind unzulässig. AI-Aufrufe bleiben in der PHP-Service-Schicht, private/no-index-Header bleiben erhalten und jeder Request wird serverseitig validiert. Bei Änderungen am Platform-Subtree sind Changelog und Composer-Anforderungen zu prüfen, vorhandene PHP-Prüfungen auszuführen sowie Versions- und Asset-Inventar zu aktualisieren.
