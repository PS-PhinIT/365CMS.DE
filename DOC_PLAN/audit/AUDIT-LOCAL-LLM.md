# 365CMS Audit mit lokalen Modellen (AMD Radeon AI PRO R9700, 32 GB)

> **Stand:** 2026-11-23 (Quellen am selben Tag geprüft) | **Hardware:** AMD Radeon AI PRO R9700, 32 GB GDDR6 | **Mindestkontext:** 256k Token (262.144)
> **Katalog:** [AUDIT-KATALOG-4.2.0.md](AUDIT-KATALOG-4.2.0.md) · **Protokoll:** [AUDIT-AGENT-PROTOKOLL.md](AUDIT-AGENT-PROTOKOLL.md) · **Slices:** [AUDIT-SLICES.md](AUDIT-SLICES.md) · **Abläufe:** [Delta](AUDIT-ABLAUF-PLAN-DELTA.md), [Vollaudit](AUDIT-ABLAUF-PLAN-VOLLAUDIT.md)

## English (summary)

How to run 365CMS audits fully offline on a single AMD Radeon AI PRO R9700 (32 GB). Only open-weight models with a
**native context of at least 262,144 tokens** are considered, and every recommended configuration was sized so that
weights + KV cache for the full 256k window fit into 32 GB (using KV-cache quantisation where needed). Primary picks:
**Qwen3.8-27B / Qwen3.6-27B** (dense, thinking) for deep security review, **Qwen3.6-35B-A3B** (MoE, fast) for breadth
and inventory, **NVIDIA Nemotron 3 Nano 30B-A3B** (hybrid Mamba, tiny KV cache, strong RULER@256k) for
cross-reference and documentation checks, **Devstral Small 2** and **Qwen3-Coder-30B-A3B** for agentic fixing.
Local models pre-screen; every MEDIUM+ finding is verified by a second model family, HIGH/CRITICAL preferably by a
strong cloud model. Architecture numbers come from the official `config.json`/model cards; file sizes from the
unsloth GGUF repositories; throughput figures must be measured locally.

## Deutsch

### 1. Einsatzrahmen

| Lokal geeignet | Nicht lokal allein |
|---|---|
| Vorprüfung ganzer Slices, Kandidaten sammeln | Endgültige Bewertung von HIGH/CRITICAL |
| Inventar, Querverweise, Doku-Abgleich (AP-13) | Orchestrierung großer Vollaudits (lange Agentenketten) |
| Zweitmeinung (andere Modellfamilie als Cloud-Finder) | Laufzeit-/Browser-/Lastprüfungen (das machen Skripte) |
| Offline-Läufe, wenn Code das Haus nicht verlassen darf | CVE-Wissen über Bibliotheken (Trivy nutzen, AP-16) |
| LOW/MEDIUM-Korrekturen mit Test (Agent-Werkzeug) | Sicherheitskritische Korrekturen ohne Zweitprüfung |

Regel: Lokale Kandidaten ≥ MEDIUM werden von einer **anderen Modellfamilie** verifiziert (Protokoll §6), HIGH/CRITICAL
möglichst mit einem starken Cloud-Modell oder manuell.

### 2. Hardware

| Merkmal | Wert | Quelle |
|---|---|---|
| GPU | Radeon AI PRO R9700, RDNA 4, `gfx1201` | AMD, ROCm-Systemanforderungen |
| Speicher | 32 GB GDDR6, 256 Bit, 640 GB/s, 64 MB Infinity Cache, ECC (nur Linux) | AMD-Produktseite |
| ROCm (Linux) | offiziell in ROCm 7.2.0: Ubuntu 24.04.3, Ubuntu 22.04.5, RHEL 10.1, RHEL 9.7; ältere Stände (6.4.2/7.0.x) mit anderer Distributionsliste | ROCm-Doku |
| Windows | kein in diesem Dokument verifizierter ROCm-Pfad → **Vulkan**-Backend (llama.cpp, LM Studio) verwenden | – |

Praxis: Desktop-Ausgabe auf der gleichen Karte belegt 0,5–1,5 GB VRAM. Für 256k-Läufe Monitor an iGPU/zweiter Karte
oder Linux ohne grafische Sitzung betreiben. Systemspeicher ≥ 64 GB empfohlen (Modell-Laden, MoE-Auslagerung).

### 3. Software-Stack

| Werkzeug | Rolle | Hinweis |
|---|---|---|
| **llama.cpp `llama-server`** (Referenz) | OpenAI-kompatibler Server, volle Kontrolle über KV-Quantisierung, Flash Attention, MoE-Auslagerung | Backends: HIP (ROCm, Linux) oder Vulkan (Linux/Windows). Beide messen (§8) |
| LM Studio | grafische Oberfläche, Vulkan/ROCm-Laufzeit, OpenAI-API | Kontextlänge und KV-Cache-Typ in den Modell-Einstellungen setzen |
| Ollama | einfacher Betrieb | Kontext per `num_ctx`; KV-Quantisierung über Umgebungsvariablen – Unterstützung für die R9700 und aktuelle Variablen vor Einsatz in der Ollama-Doku prüfen |
| vLLM (ROCm, Docker, Linux) | hoher Durchsatz, parallele Slices | RDNA-4-Unterstützung der jeweiligen vLLM-ROCm-Images vorher prüfen; KV-Quantisierung weniger flexibel als llama.cpp |

Build von llama.cpp (Linux):

```bash
# HIP/ROCm
HIPCXX="$(hipconfig -l)/clang" HIP_PATH="$(hipconfig -R)" \
  cmake -S . -B build -DGGML_HIP=ON -DGPU_TARGETS=gfx1201 -DCMAKE_BUILD_TYPE=Release
cmake --build build --config Release -j
# Vulkan (auch Windows mit Vulkan SDK)
cmake -B build-vk -DGGML_VULKAN=ON && cmake --build build-vk --config Release -j
```

### 4. VRAM-Rechnung für 256k

**KV-Cache je Token** = Anzahl Layer mit voller Attention × 2 (K und V) × KV-Heads × Head-Dimension × Bytes je Element
(f16 = 2; `q8_0` ≈ 1,0625; `q4_0` ≈ 0,5625). **Für 262.144 Token:** KiB je Token ÷ 4 = GiB.
Gesamtbedarf ≈ Gewichte + KV-Cache + 1,5–3 GiB Rechenpuffer (abhängig von `-ub`/`-b`).

| Modell | Attention-Layer × KV-Heads × Head-Dim | KV je Token (f16) | KV @ 256k f16 / q8_0 / q4_0 | Gewichte GGUF (unsloth) | Passt in 32 GB bei 256k |
|---|---|---|---|---|---|
| Qwen3.8-27B (dense, hybrid DeltaNet) | 16 × 4 × 256 | 64 KiB | 16,0 / 8,5 / 4,5 GiB | Q4_K_M 16,6 GB · Q5_K_M 19,9 GB | **Q4_K_M oder Q5_K_M + KV `q8_0`** (≈ 26 / 29 GiB) |
| Qwen3.6-27B (dense, hybrid DeltaNet) | 16 × 4 × 256 | 64 KiB | 16,0 / 8,5 / 4,5 GiB | Q4_K_M 16,8 GB · Q5_K_M 19,5 GB | **Q4_K_M/Q5_K_M + KV `q8_0`** |
| Qwen3.6-35B-A3B (MoE, 3B aktiv) | 10 × 2 × 256 | 20 KiB | 5,0 / 2,7 / 1,4 GiB | Q4_K_M 22,1 GB · Q5_K_M 26,5 GB | **Q4_K_M + KV f16** (≈ 28 GiB) oder Q5_K_M + KV `q8_0` (≈ 29 GiB) |
| Nemotron 3 Nano 30B-A3B (Mamba-2/MoE) | 6 × 2 × 128 | 6 KiB | 1,5 / 0,8 / 0,4 GiB (+ kleiner, konstanter Mamba-Zustand) | UD-Q4_K_XL 22,8 GB · Q4_K_M 24,6 GB · Q5_K_M 26,1 GB | **bis Q5_K_M + KV f16** |
| Qwen3-Coder-30B-A3B-Instruct (MoE) | 48 × 4 × 128 | 96 KiB | 24,0 / 12,8 / 6,8 GiB | Q4_K_M 18,6 GB · UD-Q4_K_XL 17,7 GB | **nur mit KV `q4_0`** (≈ 26 GiB) oder KV `q8_0` + `--n-cpu-moe` (Experten teilweise in den RAM, langsamer) |
| Devstral Small 2 24B (dense) | 40 × 8 × 128 | 160 KiB | 40,0 / 21,3 / 11,3 GiB | Q4_K_M 14,3 GB | **nur mit KV `q4_0`** (≈ 27 GiB); für Qualität 128k + KV `q8_0` (≈ 26 GiB) |

Die Qwen3.6/3.8-Modelle enthalten einen Vision-Encoder; für Code-Audits die Projektor-Datei (`mmproj`) nicht laden.
KV-Quantisierung `q4_0` spart Speicher, kann aber die Genauigkeit über lange Distanzen senken – mit dem Nadeltest (§8)
gegen `q8_0` vergleichen.

### 5. Modellauswahl (nur ≥ 256k nativ)

| Modell | Typ | Kontext nativ | Lizenz | Stärken für das Audit | Status |
|---|---|---|---|---|---|
| **Qwen3.8-27B** | dense 27B, Thinking (abschaltbar, `reasoning_effort`) | 262.144 (bis 1 Mio. mit YaRN) | Apache 2.0 | neueste Qwen-Generation; laut Modellkarte deutlich stärker als 3.6-27B bei agentischem Coding (SWE-bench Pro 61,7 vs. 53,5) | Modellkarte geprüft |
| **Qwen3.6-27B** | dense 27B, Thinking | 262.144 (bis 1,01 Mio.) | Apache 2.0 | SWE-bench Verified 77,2; bewährter Fallback, falls 3.8 im Werkzeug noch Probleme macht | Modellkarte geprüft |
| **Qwen3.6-35B-A3B** | MoE 35B/3B aktiv, Thinking | 262.144 (bis 1,01 Mio.) | Apache 2.0 | sehr schnell (3B aktiv), kleiner KV-Cache; SWE-bench Verified 73,4 | Modellkarte geprüft |
| **Nemotron 3 Nano 30B-A3B** | Mamba-2/Transformer-Hybrid, MoE 3,5B aktiv, Reasoning | 262.144 (RULER bis 1 Mio. gemessen) | NVIDIA Open Model License | bester Langkontext-Wert der Liste (RULER-100 @256k 92,9), winziger KV-Cache; Deutsch unterstützt | Modellkarte + config geprüft |
| **Qwen3-Coder-30B-A3B-Instruct** | MoE 30,5B/3,3B aktiv, ohne Thinking | 262.144 (bis 1 Mio. mit YaRN) | Apache 2.0 | schnelle Code-Navigation, Tool-Calling für Qwen Code/Cline | Modellkarte + config geprüft |
| **Devstral Small 2 24B Instruct 2512** | dense 24B, agentisches Coding | 256k | Apache 2.0 | SWE-bench Verified 68,0; Mistral Vibe CLI/OpenHands; gute Umsetzer-Rolle | Modellkarte + config geprüft |
| Gemma 4 31B / 26B A4B / 12B | dense bzw. MoE, Sliding-Window + globale Layer | 256K | Apache 2.0 | Alternative für deutschsprachige Texte (AP-17, Doku); KV-Bedarf der globalen Layer vor Einsatz messen | Kontext laut Modellkarte; VRAM **zu verifizieren** |

**Bewusst nicht empfohlen** (Kontext < 256k nativ oder passt nicht sinnvoll in 32 GB): Modelle mit 128k-Fenster
(z. B. gpt-oss-20b, Gemma 4 E2B/E4B), sehr große MoE-Modelle wie Qwen3-Next-80B-A3B (nur mit starker Auslagerung in
den Systemspeicher, dann zu langsam für Slice-Läufe), dichte Modelle > 32B (Gewichte + 256k-KV sprengen 32 GB).

### 6. Kontextstrategie für 256k

1. **Tiefenprüfung (Sicherheit, AP-01–11):** Halb-Slices mit 64–100k Token Quellcode, selbst wenn 256k geladen sind.
   Der Rest des Fensters dient Denkspuren (Thinking kann > 20k Token erzeugen) und Antwort.
2. **Inventar/Querverweise (AP-13, AP-16, Doku):** bis ~200k Token, aber nur Listen, Signaturen, Tabellen – keine
   Bewertung einzelner Codepfade.
3. **Prompt-Aufbau:** Anweisung und Prüfpunkte **am Anfang**, Quelltext in der Mitte, die konkrete Frage am **Ende**
   wiederholen. Dateien mit Pfad-Kopfzeile (`=== CMS/core/Security.php ===`) und Zeilennummern übergeben.
4. **Ein Slice pro Sitzung**, danach Server-Slot leeren (neue Sitzung) – Ergebnisse stehen im Laufordner.
5. **`--parallel 1`** bei 256k: jeder weitere Slot braucht eigenen KV-Speicher.
6. **Thinking-Budget** begrenzen (`--reasoning-budget`), damit Antworten nicht im Denken enden.

### 7. Empfehlung je Auditbereich

Modus: **T** = Thinking an, **I** = Instruct/ohne Thinking. Slice-Größe gemäß §6.

| Auditbereich (AP) | Primär | Fallback | Modus | Konfiguration (256k) | Begründung |
|---|---|---|---|---|---|
| AP-01 Anmeldung, AP-02 Rechte/IDOR, AP-07 Kryptografie | Qwen3.8-27B | Qwen3.6-27B | T | Q4_K_M/Q5_K_M, KV `q8_0` | dichte Modelle verfolgen mehrstufige Kontrollflüsse (Router → Modul → Dienst) zuverlässiger als 3B-aktiv-MoE |
| AP-03 Injection/SSRF, AP-04 XSS/CSP, AP-05 CSRF/Header | Qwen3.8-27B | Qwen3-Coder-30B-A3B | T / I | wie oben; Coder mit KV `q4_0` | Datenfluss Quelle → Senke; Coder als schneller Zweitdurchlauf über Muster |
| AP-06 Uploads/Backups, AP-09 Install/Update | Qwen3.6-27B | Devstral Small 2 | T / I | KV `q8_0` bzw. `q4_0` | Dateisystem- und Archivlogik, Randfälle |
| AP-08 Zahlungen/Webhooks | Qwen3.8-27B | Qwen3.6-35B-A3B | T | KV `q8_0` | Zustandsautomaten, Idempotenz, Zeitzonen |
| AP-10 Datenbank/Schema | Qwen3.6-27B | Qwen3-Coder-30B-A3B | T / I | KV `q8_0` | SQL, Indizes, Migrationen; `SchemaManager` (~53k) passt komplett |
| AP-11 Community | Qwen3.6-35B-A3B (Breite, C1–C5) → Qwen3.8-27B (Tiefe für Kandidaten) | Nemotron 3 Nano | T | 35B: KV f16 | großer Bereich (~395k Token): erst schnell kartieren, dann gezielt vertiefen |
| AP-12 Leistung | Qwen3.6-27B | Nemotron 3 Nano | T | KV `q8_0` | N+1, Schleifen, Caching über mehrere Dateien |
| AP-13 Verweise, Inventare, Doku | **Nemotron 3 Nano** | Qwen3.6-35B-A3B | I/T | Q4_K_M/Q5_K_M, KV f16 | höchste Langkontext-Treue bei geringstem KV-Bedarf; ideal für 150–200k-Inventare |
| AP-14 Barrierefreiheit | Qwen3.6-35B-A3B | Gemma 4 31B (zu verifizieren) | I | KV f16 | Vorlagen-/ARIA-Prüfung; Messung mit axe-core bleibt Pflicht |
| AP-15 Best Practice | Qwen3-Coder-30B-A3B | Qwen3.6-35B-A3B | I | KV `q4_0` | schnelle Musterprüfung (Fehlerbehandlung, Typen, Logging) |
| AP-16 Bibliotheken | Skripte + Trivy; Zusammenfassung Qwen3.6-35B-A3B | Nemotron 3 Nano | I | – | Modelle kennen aktuelle CVEs nicht verlässlich |
| AP-17 Datenschutz, Rechtstexte | Qwen3.6-35B-A3B | Gemma 4 31B (zu verifizieren) | T | KV f16 | deutsche Texte und Datenflüsse; Rechtsbewertung bleibt menschlich |
| AP-18 Betrieb, AP-20 Integrationen | Qwen3.6-35B-A3B | Qwen3.6-27B | T | KV f16 | Breite über viele mittlere Dateien |
| AP-19 KI-Funktionen | Qwen3.8-27B | Qwen3.6-27B | T | KV `q8_0` | Prompt-Injection-Pfade, Ausgabe-Bereinigung |
| **Umsetzer** (LOW/MEDIUM mit Test) | Devstral Small 2 | Qwen3-Coder-30B-A3B · Qwen3.6-27B | I | KV `q4_0` bzw. 128k + `q8_0` | für agentische Werkzeuge mit Datei-Edits und Testläufen trainiert |
| **Verifizierer** lokal | andere Familie als der Finder (Qwen → Nemotron oder Devstral) | – | T | – | Kreuzprüfung reduziert gemeinsame Blindstellen |

### 8. Startbefehle

Platzhalter: `<modell>.gguf` aus den Repositories in §11. Flags gemäß aktueller `llama-server`-Doku; vor neuem Build
mit `llama-server --help` gegenprüfen. Server nur an `127.0.0.1` binden.

```bash
# Tiefenprüfung: Qwen3.8-27B (oder Qwen3.6-27B), 256k, KV q8_0
llama-server -m Qwen3.8-27B-Q4_K_M.gguf -c 262144 -ngl all -fa on \
  -ctk q8_0 -ctv q8_0 -np 1 -b 2048 -ub 512 --jinja \
  --reasoning-budget 16384 --temp 0.6 --top-p 0.95 --top-k 20 --min-p 0 \
  --host 127.0.0.1 --port 8080

# Breite/Inventar: Qwen3.6-35B-A3B, 256k, KV f16
llama-server -m Qwen3.6-35B-A3B-Q4_K_M.gguf -c 262144 -ngl all -fa on -np 1 --jinja \
  --temp 0.6 --top-p 0.95 --top-k 20 --host 127.0.0.1 --port 8081

# Langkontext-Abgleich: Nemotron 3 Nano, 256k
llama-server -m Nemotron-3-Nano-30B-A3B-Q5_K_M.gguf -c 262144 -ngl all -fa on -np 1 --jinja \
  --host 127.0.0.1 --port 8082

# Umsetzer: Devstral Small 2, 256k nur mit KV q4_0
llama-server -m Devstral-Small-2-24B-Instruct-2512-Q4_K_M.gguf -c 262144 -ngl all -fa on \
  -ctk q4_0 -ctv q4_0 -np 1 --jinja --temp 0.15 --host 127.0.0.1 --port 8083

# Qwen3-Coder mit KV q8_0 und teilweise ausgelagerten Experten (Wert N durch Messung bestimmen)
llama-server -m Qwen3-Coder-30B-A3B-Instruct-Q4_K_M.gguf -c 262144 -ngl all -fa on \
  -ctk q8_0 -ctv q8_0 --n-cpu-moe 8 -np 1 --jinja \
  --temp 0.7 --top-p 0.8 --top-k 20 --repeat-penalty 1.05 --host 127.0.0.1 --port 8084
```

Windows (PowerShell, Vulkan-Build): gleiche Argumente, Zeilenfortsetzung mit Backtick `` ` `` statt `\`.

Sampling laut Modellkarten: Qwen3.6/3.8 Thinking für präzise Coding-Aufgaben `temperature=0.6, top_p=0.95, top_k=20,
min_p=0`; Qwen3.6 ohne Thinking `temperature=0.7, top_p=0.8, top_k=20, presence_penalty=1.5`; Devstral Small 2
`temperature=0.15`. Für Nemotron 3 Nano und Gemma 4 die Werte der jeweiligen Modellkarte übernehmen.

### 9. Agent-Werkzeuge

Alle folgenden Werkzeuge sprechen die OpenAI-kompatible API von `llama-server`, LM Studio oder Ollama
(`base_url = http://127.0.0.1:<port>/v1`, beliebiger API-Schlüssel):

| Werkzeug | Eignung | Hinweis |
|---|---|---|
| OpenCode, Aider, Continue, Cline/Roo Code | Slice-Prüfer (lesend), Umsetzer | Tool-Calling braucht das Chat-Template (`--jinja`, Standard in aktuellen Builds) |
| Qwen Code | Qwen3-Coder / Qwen3.6/3.8 | auf Qwen-Funktionsaufrufe abgestimmt |
| Mistral Vibe CLI, OpenHands | Devstral Small 2 | laut Modellkarte primäre Umgebungen |

Denkspuren: `llama-server` gibt sie je nach `--reasoning-format` getrennt (`reasoning_content`) oder als `<think>`
im Text aus. Werkzeuge, die das nicht verstehen, mit `--reasoning-format deepseek` betreiben oder Thinking abschalten
(`--reasoning off`). Denkspuren nie in Befunddateien übernehmen.

Für reine Chat-Oberflächen (ohne Dateizugriff) den Slice per Skript als eine Datei mit Pfad-Kopfzeilen und
Zeilennummern erzeugen, dann Prompt P2 aus dem Protokoll voranstellen; Ergebnisse manuell in den Laufordner übernehmen.

### 10. Abnahme der lokalen Umgebung (vor dem ersten Lauf)

1. **Durchsatz:** `llama-bench -m <modell>.gguf -p 4096,32768 -n 256 -fa 1` für HIP- und Vulkan-Build; schnelleres
   Backend im Ledger festhalten (Prompt-Verarbeitung ist bei 256k-Slices wichtiger als Generierung).
2. **Volllast:** Server mit `-c 262144` starten, einen 240k-Token-Prompt senden; kein Out-of-Memory, Zeit bis zum
   ersten Token notieren.
3. **Nadeltest mit Repository-Code:** einen Slice mit ~200k Token laden, darin an 10 %, 50 %, 90 % je eine künstliche
   Zeile (`// NADEL-<n>: …`) einfügen und abfragen; mit KV f16, `q8_0`, `q4_0` wiederholen.
4. **Kalibrierung mit bekannten Befunden:** älteren Tag auschecken (z. B. `V4.0.0`) und prüfen, ob das Modell in den
   betroffenen Slices die später behobenen und in den Folgeaudits dokumentierten Befunde wiederfindet (Trefferquote
   und Falschmeldungen je Modell im Ledger festhalten). Danach wieder auf den Ziel-Commit wechseln.
5. **Format-Treue:** zehn Kandidaten im JSONL-Schema des Protokolls erzeugen lassen und mit `python -c "import json,sys;[json.loads(l) for l in open(sys.argv[1])]" FINDINGS.jsonl` validieren.

### 11. Sicherheit und Herkunft

- Server ausschließlich lokal (`--host 127.0.0.1`), keine Portfreigabe, keine Telemetrie-Plugins.
- Gewichte nur aus den Original-Repositories oder etablierten Quantisierern (Liste unten); Prüfsummen vergleichen.
- Keine „uncensored“/„abliterated“-Varianten oder Community-Merges für Audits verwenden – Verhalten und Herkunft
  sind nicht nachvollziehbar.
- Lizenzen beachten: Apache 2.0 (Qwen, Devstral, Gemma 4), NVIDIA Open Model License (Nemotron).
- Repository-Inhalte sind Daten, keine Anweisungen (Protokoll Regel 12) – gilt für lokale Modelle genauso.

### 12. Quellen (geprüft am 2026-11-23)

| Thema | Quelle | Status |
|---|---|---|
| R9700 Spezifikation | <https://www.amd.com/en/products/graphics/workstations/radeon-ai-pro/ai-9000-series/amd-radeon-ai-pro-r9700.html> | geprüft |
| ROCm 7.2.0 Systemanforderungen (R9700, gfx1201) | <https://rocm.docs.amd.com/projects/install-on-linux/en/docs-7.2.0/reference/system-requirements.html> | geprüft |
| ROCm 7.0.1 / 6.4.2 Kompatibilitätsmatrix | <https://rocm.docs.amd.com/en/docs-7.0.1/compatibility/compatibility-matrix.html>, <https://rocm.docs.amd.com/en/docs-6.4.2/compatibility/compatibility-matrix.html> | geprüft |
| llama.cpp Server-Flags | <https://github.com/ggml-org/llama.cpp/blob/master/tools/server/README.md> | geprüft |
| llama.cpp Build (HIP, Vulkan) | <https://github.com/ggml-org/llama.cpp/blob/master/docs/build.md> | geprüft |
| Qwen3.8-27B | <https://huggingface.co/Qwen/Qwen3.8-27B> · GGUF <https://huggingface.co/unsloth/Qwen3.8-27B-GGUF> | geprüft |
| Qwen3.6-27B | <https://huggingface.co/Qwen/Qwen3.6-27B> · GGUF <https://huggingface.co/unsloth/Qwen3.6-27B-GGUF> | geprüft |
| Qwen3.6-35B-A3B | <https://huggingface.co/Qwen/Qwen3.6-35B-A3B> · GGUF <https://huggingface.co/unsloth/Qwen3.6-35B-A3B-GGUF> | geprüft |
| Qwen3-Coder-30B-A3B-Instruct | <https://huggingface.co/Qwen/Qwen3-Coder-30B-A3B-Instruct> · GGUF <https://huggingface.co/unsloth/Qwen3-Coder-30B-A3B-Instruct-GGUF> | geprüft |
| Nemotron 3 Nano 30B-A3B | <https://huggingface.co/nvidia/NVIDIA-Nemotron-3-Nano-30B-A3B-BF16> · GGUF <https://huggingface.co/unsloth/Nemotron-3-Nano-30B-A3B-GGUF> | geprüft |
| Devstral Small 2 | <https://huggingface.co/mistralai/Devstral-Small-2-24B-Instruct-2512> · GGUF <https://huggingface.co/unsloth/Devstral-Small-2-24B-Instruct-2512-GGUF> | geprüft |
| Gemma 4 | <https://ai.google.dev/gemma/docs/core/model_card_4> | Kontext geprüft, VRAM zu verifizieren |

Modelle, Werkzeuge und Treiber ändern sich schnell. Vor jedem Vollaudit diese Tabelle prüfen und Abweichungen im
Ledger des Laufs vermerken; neue Modelle nur aufnehmen, wenn Kontext ≥ 262.144 Token nativ belegt ist und die
VRAM-Rechnung aus §4 für 32 GB aufgeht.
