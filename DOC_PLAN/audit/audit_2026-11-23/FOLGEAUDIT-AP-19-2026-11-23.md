# AP-19 – KI-Funktionen (Version 4.2.19)

**Stand 24.11.2026.** Geprüft wurde `main` nach `12701b8` (Version 4.2.0, Schema v27), statisch durch Quelltextlesung, Skriptsuchen und Funktionsaufrufe
ohne Datenbank. Maßstab: [Audit-Katalog, AP-19](../AUDIT-KATALOG-4.2.0.md#ap-19-ki-funktionen). Der Bericht betrifft nur AP-19; er ist keine Freigabe des
gesamten Bereichs. Es wurden keine Browser, Konten oder Produktivsysteme verwendet. Fundstellen sind relativ zur Repository-Wurzel.

**Ergebnis:** Kein Befund in den gelesenen Teilen (Anbieter-Aufrufe). Prompt-Injection, Quoten und Richtlinie sind nicht geprüft.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 19.1 Schlüssel, Ziele, Limits | ja (Stichprobe) | Anbieter-Aufrufe laufen über `CMS\Http\Client` mit Zeitlimit (mindestens 5 s, Standard 25 s; `CMS/core/Services/AI/AiProviderFactory.php:44–75`). Standard-Endpunkte sind fest (`AiSettingsService.php:726–820`); nur der Ollama-Eintrag erlaubt standardmäßig `127.0.0.1` und `localhost` als interne Hosts (`:786`). Die Liste `allowed_internal_hosts` ist je Anbieter in den Einstellungen änderbar (`:490`, `:1152`), also eine Administrator-Entscheidung. Zum Schlüssel siehe AP-07.4. | Antwortgröße je Anbieter, Ablage der Schlüssel der KI-Anbieter. |
| 19.2 Prompt-Injection | offen | Nicht geprüft. | Fremdinhalte als Daten, Bereinigung der Ausgaben, keine automatischen Schreibaktionen. |
| 19.3 Quoten | offen | Nicht geprüft. | `ai_quota_usage`, viele Anfragen. |
| 19.4 Richtlinie | offen | Nicht geprüft. | Datenabfluss benannt und abschaltbar, Logs ohne Geheimnisse. |

## Abweichungen

Keine.

## Hinweise ohne Änderung

- **INFO-1:** `allowed_internal_hosts` ist für jeden Anbieter einstellbar, nicht nur für Ollama. Ein Administrator kann so interne Ziele für KI-Aufrufe freigeben (z. B. ein internes Gateway); das ist beabsichtigt, aber in der Oberfläche nicht als Risiko erklärt.

## Testlauf und Übergabe

Quelltextlesung. Gesamtlauf aller Suiten grün (darunter die fünf `ai-*`-Suiten).

**Offen:** 19.2, 19.3, 19.4 vollständig.
