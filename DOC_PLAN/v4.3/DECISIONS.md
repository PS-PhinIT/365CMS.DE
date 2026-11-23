# 365CMS 4.3: Entscheidungen

| ID | Entscheidung | Erforderlicher Beschluss |
|---|---|---|
| D43-01 | CLI-Unterbefehle | Namen, Exitcodes, JSON/Text, Idempotenz und Schreibschutz |
| D43-02 | CLI-Rechte | ausführender OS-Benutzer, Admin-Audit, Bestätigung destruktiver Befehle |
| D43-03 | Secret-Eingabe | Prompt, Environment, Datei-Descriptor; Log-/History-Vermeidung |
| D43-04 | Alert-Ereignis | Schwere, Quelle, Deduplizierungsschlüssel und redigierter Kontext |
| D43-05 | Alert-Kanäle | Mail, Webhook, Admin, optional externe Senke; Cooldown und Fehlerverhalten |
| D43-06 | Worker-Vertrag | Cron/Admin/CLI-Sperren, Fortschritt, Timeout und Wiederaufnahme |
| D43-07 | 4.4-Grenze | keine Custom-Field-, Sprach-, Preview- oder Integrationsschema-Migration in 4.3 |

Die früher vorgesehenen Entscheidungen zu Custom Fields, N-Sprachen, Live Preview
und Managed API liegen jetzt in [../v4.4/DECISIONS.md](../v4.4/DECISIONS.md).
