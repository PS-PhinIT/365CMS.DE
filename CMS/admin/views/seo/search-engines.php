<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_SEO_VIEW')) {
    exit;
}

$searchEngines = is_array($data['search_engines'] ?? null) ? $data['search_engines'] : [];
$settings = is_array($searchEngines['settings'] ?? null) ? $searchEngines['settings'] : [];
$indexNow = is_array($searchEngines['indexnow'] ?? null) ? $searchEngines['indexnow'] : [];
$queue = is_array($searchEngines['queue'] ?? null) ? $searchEngines['queue'] : [];
$verification = is_array($searchEngines['verification'] ?? null) ? $searchEngines['verification'] : [];
$verificationEngines = is_array($verification['engines'] ?? null) ? $verification['engines'] : [];
$verificationCodes = is_array($verification['codes'] ?? null) ? $verification['codes'] : [];
$keyLength = is_array($searchEngines['key_length'] ?? null) ? $searchEngines['key_length'] : [];
$responseCodes = is_array($searchEngines['response_codes'] ?? null) ? $searchEngines['response_codes'] : [];
$recentContentRanges = is_array($searchEngines['recent_content_ranges'] ?? null) ? $searchEngines['recent_content_ranges'] : [];

$indexNowKeyValid = !empty($indexNow['key_protocol_valid']);
$indexNowReady = !empty($indexNow['ready_for_submission']);
$indexNowKeySource = (string)($indexNow['key_source'] ?? '');
$indexNowRootTxtFiles = is_array($indexNow['root_txt_files'] ?? null) ? $indexNow['root_txt_files'] : [];
$indexNowValidationErrors = is_array($indexNow['validation_errors'] ?? null) ? $indexNow['validation_errors'] : [];
$indexNowValidationNotes = is_array($indexNow['validation_notes'] ?? null) ? $indexNow['validation_notes'] : [];
$indexNowDebug = is_array($indexNow['debug'] ?? null) ? $indexNow['debug'] : [];
$indexNowDebugCandidates = is_array($indexNowDebug['root_candidates'] ?? null) ? $indexNowDebug['root_candidates'] : [];
$indexNowSelectedFileReason = (string)($indexNowDebug['selected_file_reason'] ?? '');
$indexNowSelectedFileResolvedFrom = (string)($indexNowDebug['selected_file_resolved_from'] ?? '');
$autoSubmitEnabled = (string)($settings['seo_indexnow_auto_submit'] ?? '0') === '1';

$queueCount = (int)($queue['count'] ?? 0);
$queueMax = (int)($queue['max'] ?? 0);
$queueUrls = is_array($queue['urls'] ?? null) ? $queue['urls'] : [];
$queueLastResult = is_array($queue['last_result'] ?? null) ? $queue['last_result'] : [];
$resubmitMinutes = max(1, intdiv((int)($queue['resubmit_interval'] ?? 300), 60));
$queueTriggerLabels = ['auto' => 'nach dem Speichern', 'cron' => 'stündlicher Cron', 'manual' => 'manuell'];

$googleTokenAvailable = !empty($searchEngines['google_access_token_available']);
$gscProperty = (string)($settings['seo_analytics_gsc_property'] ?? '');
$searchConsoleUrl = (string)($searchEngines['search_console_url'] ?? 'https://search.google.com/search-console');
$verifiedCount = count(array_filter($verificationCodes, static fn(mixed $code): bool => is_string($code) && $code !== ''));

$keyLengthMin = (int)($keyLength['min'] ?? 8);
$keyLengthMax = (int)($keyLength['max'] ?? 128);
$keyLengthDefault = (int)($keyLength['default'] ?? 32);
$keyLengthOptions = array_values(array_filter(
    [16, 32, 64, 128],
    static fn(int $length): bool => $length >= $keyLengthMin && $length <= $keyLengthMax
));
$maxManualUrls = (int)($searchEngines['max_manual_urls'] ?? 100);
$maxUrlsPerRequest = (int)($searchEngines['max_urls_per_request'] ?? 10000);

$runtimeBaseUrl = function_exists('home_url') ? rtrim((string) home_url(), '/') : rtrim((string) SITE_URL, '/');
$sitemapUrl = $runtimeBaseUrl . '/sitemap.xml';

if ($indexNowReady) {
    $indexNowStateLabel = 'Bereit';
    $indexNowStateClass = 'text-success';
} elseif ($indexNowKeyValid) {
    $indexNowStateLabel = 'Prüfen';
    $indexNowStateClass = 'text-warning';
} else {
    $indexNowStateLabel = 'Kein Key';
    $indexNowStateClass = 'text-secondary';
}
?>
<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">SEO</div>
                <h2 class="page-title">Suchmaschinen</h2>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page">
    <div class="container-xl admin-redesign-shell">
    <?php if (!empty($alert)): ?>
        <?php
        $alertData = is_array($alert ?? null) ? $alert : [];
        require dirname(__DIR__) . '/partials/flash-alert.php';
        ?>
    <?php endif; ?>
    <?php require __DIR__ . '/subnav.php'; ?>
        <div class="row row-deck row-cards mb-4">
            <div class="col-sm-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">IndexNow</div>
                        <div class="h1 mb-0 <?= $indexNowStateClass ?>"><?= htmlspecialchars($indexNowStateLabel) ?></div>
                        <div class="small text-secondary mt-1">
                            <?= $indexNowKeyValid ? 'Key aus: ' . htmlspecialchars((string)($indexNow['key_source_label'] ?? '')) : 'Noch kein gültiger API-Key' ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Warteschlange</div>
                        <div class="h1 mb-0"><?= $queueCount ?></div>
                        <div class="small text-secondary mt-1">Automatische Meldung <?= $autoSubmitEnabled ? 'aktiv' : 'aus' ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Google Search Console</div>
                        <div class="h1 mb-0 <?= $gscProperty !== '' ? 'text-success' : 'text-secondary' ?>"><?= $gscProperty !== '' ? 'Hinterlegt' : 'Offen' ?></div>
                        <div class="small text-secondary mt-1">Indexing-API-Token <?= $googleTokenAvailable ? 'gespeichert' : 'nicht gespeichert' ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Verifizierungen</div>
                        <div class="h1 mb-0"><?= $verifiedCount ?> / <?= count($verificationEngines) ?></div>
                        <div class="small text-secondary mt-1">Meta-Tags im <code>&lt;head&gt;</code></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Einstellungen</h3>
                    </div>
                    <div class="card-body">
                        <form method="post" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="save_search_engine_settings">

                            <div class="col-12">
                                <div class="fw-semibold">IndexNow</div>
                                <div class="text-secondary small">Meldet neue, geänderte und gelöschte URLs sofort an alle teilnehmenden Suchmaschinen (u. a. Bing, Yandex, Seznam, Naver, Yep).</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="indexnow-key">IndexNow API-Key</label>
                                <input
                                    class="form-control"
                                    id="indexnow-key"
                                    type="text"
                                    name="indexnow_key"
                                    value="<?= htmlspecialchars((string)($settings['seo_indexnow_key'] ?? '')) ?>"
                                    placeholder="z. B. 75d0bf8d82694244923c1ecf56fe1e6e"
                                    pattern="[A-Za-z0-9\-]{8,128}"
                                    minlength="8"
                                    maxlength="128"
                                    autocomplete="off"
                                >
                                <div class="form-hint">8–128 Zeichen, erlaubt sind nur <code>a-z</code>, <code>A-Z</code>, <code>0-9</code> und <code>-</code>. Einen neuen Key erzeugen Sie unter „IndexNow-Key erzeugen“.</div>
                                <?php if ($indexNowKeySource !== '' && $indexNowKeySource !== 'database'): ?>
                                    <div class="alert alert-info mt-2 mb-0" role="status">
                                        Aktiver Key stammt aus: <strong><?= htmlspecialchars((string)($indexNow['key_source_label'] ?? '')) ?></strong>. Ein hier gespeicherter Key hat Vorrang.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="indexnow-key-file">Root-TXT-Datei</label>
                                <select class="form-select" id="indexnow-key-file" name="indexnow_key_file">
                                    <option value="">Keine – Keydatei dynamisch ausliefern</option>
                                    <?php foreach ($indexNowRootTxtFiles as $fileName): ?>
                                        <option value="<?= htmlspecialchars((string) $fileName) ?>" <?= (($settings['seo_indexnow_key_file'] ?? '') === $fileName) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string) $fileName) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-hint">
                                    Optional: physische Datei <code>{key}.txt</code> im Root-Verzeichnis <code><?= htmlspecialchars((string)($indexNow['root_directory'] ?? ABSPATH)) ?></code>.
                                    Ohne Auswahl liefert das CMS <code>/{key}.txt</code> dynamisch aus.
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-check form-switch mb-1">
                                    <input class="form-check-input" type="checkbox" name="indexnow_auto_submit" value="1" <?= $autoSubmitEnabled ? 'checked' : '' ?>>
                                    <span class="form-check-label">Änderungen automatisch per IndexNow melden</span>
                                </label>
                                <div class="form-hint">
                                    Meldet veröffentlichte, indexierbare Seiten und Beiträge (DE und EN) beim Speichern – nach Abschluss der Antwort, ohne das Speichern zu verzögern.
                                    Der stündliche Cron meldet zusätzlich geplante Beiträge, sobald sie erscheinen, sowie Massen-Veröffentlichungen und wiederholt fehlgeschlagene Meldungen.
                                    Dieselbe URL wird frühestens nach <?= $resubmitMinutes ?> Minuten erneut gemeldet.
                                </div>
                            </div>

                            <div class="col-12"><hr class="my-2"></div>

                            <div class="col-12">
                                <div class="fw-semibold">Google Search Console</div>
                                <div class="text-secondary small">Google nimmt nicht an IndexNow teil. Neue Inhalte findet Google über die Sitemap, die Sie einmalig in der Search Console einreichen.</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="seo-gsc-property">Search-Console-Property</label>
                                <input
                                    class="form-control"
                                    id="seo-gsc-property"
                                    type="text"
                                    name="gsc_property"
                                    value="<?= htmlspecialchars($gscProperty) ?>"
                                    placeholder="sc-domain:example.de oder https://www.example.de/"
                                    maxlength="255"
                                    autocomplete="off"
                                >
                                <div class="form-hint">
                                    Domain-Property (<code>sc-domain:…</code>, Bestätigung per DNS-TXT-Eintrag) oder URL-Präfix-Property (Bestätigung auch per Meta-Tag unten).
                                    Wird für den Direktlink in die Search Console verwendet.
                                </div>
                                <div class="d-flex flex-wrap gap-2 mt-2">
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($searchConsoleUrl) ?>" target="_blank" rel="noopener noreferrer">Search Console öffnen</a>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($sitemapUrl) ?>" target="_blank" rel="noopener noreferrer">Sitemap-URL</a>
                                </div>
                            </div>

                            <div class="col-12"><hr class="my-2"></div>

                            <div class="col-12">
                                <div class="fw-semibold">Website-Verifizierung</div>
                                <div class="text-secondary small">
                                    Code oder kompletten Meta-Tag aus dem jeweiligen Webmaster-Tool einfügen. Das CMS gibt die Tags im <code>&lt;head&gt;</code> aller Frontend-Seiten aus.
                                    HTML-Bestätigungsdateien werden nicht benötigt.
                                </div>
                            </div>

                            <?php foreach ($verificationEngines as $engine => $definition): ?>
                                <?php
                                $engineKey = (string) $engine;
                                $engineLabel = (string)($definition['label'] ?? $engineKey);
                                $engineMetaName = (string)($definition['meta_name'] ?? '');
                                $engineOption = (string)($definition['option'] ?? '');
                                $engineCode = (string)($settings[$engineOption] ?? '');
                                $engineActive = (string)($verificationCodes[$engineKey] ?? '') !== '';
                                $engineFieldId = 'seo-verification-' . preg_replace('/[^a-z0-9_-]/', '', $engineKey);
                                ?>
                                <div class="col-12">
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                                        <label class="form-label mb-0" for="<?= htmlspecialchars($engineFieldId) ?>">
                                            <?= htmlspecialchars($engineLabel) ?>
                                            <?php if ($engineActive): ?>
                                                <span class="badge bg-success-lt text-success ms-1">aktiv</span>
                                            <?php endif; ?>
                                        </label>
                                        <a class="small" href="<?= htmlspecialchars((string)($definition['tool_url'] ?? '')) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string)($definition['tool_label'] ?? '')) ?></a>
                                    </div>
                                    <input
                                        class="form-control"
                                        id="<?= htmlspecialchars($engineFieldId) ?>"
                                        type="text"
                                        name="verification[<?= htmlspecialchars($engineKey) ?>]"
                                        value="<?= htmlspecialchars($engineCode) ?>"
                                        placeholder="<?= htmlspecialchars('<meta name="' . $engineMetaName . '" content="…">') ?>"
                                        maxlength="500"
                                        autocomplete="off"
                                        spellcheck="false"
                                    >
                                </div>
                            <?php endforeach; ?>

                            <div class="col-12">
                                <button class="btn btn-primary" type="submit">Einstellungen speichern</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h3 class="card-title mb-0">IndexNow-Key erzeugen</h3>
                        <span class="badge <?= !empty($indexNow['key_file_directory_writable']) ? 'bg-success' : 'bg-warning text-dark' ?>">
                            Root <?= !empty($indexNow['key_file_directory_writable']) ? 'beschreibbar' : 'schreibgeschützt' ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <p class="text-secondary small">
                            Erzeugt einen kryptografisch zufälligen, hexadezimalen Key (PHP <code>random_bytes()</code>) und speichert ihn.
                            Auf Wunsch wird die UTF-8-Keydatei <code>{key}.txt</code> mit dem Key als Inhalt direkt im Root-Verzeichnis
                            <code><?= htmlspecialchars((string)($indexNow['key_file_directory'] ?? '')) ?></code> abgelegt (vom Protokoll empfohlene Option 1).
                            Ein neuer Key ersetzt den bisherigen – bereits gemeldete URLs bleiben davon unberührt.
                        </p>
                        <form method="post" class="row g-3 align-items-end"<?= !empty($indexNow['key_available']) ? ' data-confirm-message="Der bisherige IndexNow-Key wird ersetzt. Fortfahren?"' : '' ?>>
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="generate_indexnow_key">
                            <div class="col-sm-4">
                                <label class="form-label" for="indexnow-key-length">Länge</label>
                                <select class="form-select" id="indexnow-key-length" name="indexnow_key_length">
                                    <?php foreach ($keyLengthOptions as $keyLengthOption): ?>
                                        <option value="<?= $keyLengthOption ?>" <?= $keyLengthOption === $keyLengthDefault ? 'selected' : '' ?>><?= $keyLengthOption ?> Zeichen<?= $keyLengthOption === $keyLengthDefault ? ' (empfohlen)' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-8">
                                <label class="form-check mb-1">
                                    <input class="form-check-input" type="checkbox" name="indexnow_write_root_file" value="1" <?= !empty($indexNow['key_file_directory_writable']) ? 'checked' : '' ?>>
                                    <span class="form-check-label">Keydatei im Root-Verzeichnis ablegen und auswählen</span>
                                </label>
                                <label class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" name="indexnow_remove_previous_file" value="1" checked>
                                    <span class="form-check-label">Keydatei des bisherigen Keys entfernen</span>
                                </label>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary" type="submit">Neuen Key erzeugen</button>
                            </div>
                        </form>
                        <?php if ($indexNowKeyValid && empty($indexNow['selected_root_file_valid'])): ?>
                            <form method="post" class="mt-3">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="write_indexnow_key_file">
                                <button class="btn btn-outline-primary" type="submit">Keydatei für aktuellen Key im Root ablegen</button>
                                <div class="form-hint">Legt <code><?= htmlspecialchars((string)($indexNow['key'] ?? '')) ?>.txt</code> an und wählt die Datei als Root-TXT-Datei aus.</div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h3 class="card-title mb-0">IndexNow-Prüfung</h3>
                        <div class="d-flex flex-wrap gap-2 justify-content-end">
                            <span class="badge <?= !empty($indexNow['key_available']) ? 'bg-success' : 'bg-warning text-dark' ?>">
                                API-Key <?= !empty($indexNow['key_available']) ? 'vorhanden' : 'fehlt' ?>
                            </span>
                            <span class="badge <?= !empty($indexNow['selected_root_file']) ? 'bg-primary' : 'bg-secondary' ?>">
                                Root-TXT <?= !empty($indexNow['selected_root_file']) ? 'gewählt' : 'dynamisch' ?>
                            </span>
                            <span class="badge <?= $indexNowReady ? 'bg-success' : 'bg-warning text-dark' ?>">
                                Übermittlung <?= $indexNowReady ? 'bereit' : 'prüfen' ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <div class="small text-secondary mb-1">Dynamische Keydatei</div>
                                <?php if (!empty($indexNow['dynamic_key_file_url'])): ?>
                                    <a href="<?= htmlspecialchars((string) $indexNow['dynamic_key_file_url']) ?>" target="_blank" rel="noopener noreferrer">
                                        <code><?= htmlspecialchars((string) $indexNow['dynamic_key_file_url']) ?></code>
                                    </a>
                                <?php else: ?>
                                    <span class="text-secondary">Erst mit gültigem Key verfügbar</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <div class="small text-secondary mb-1">Ausgewählte Root-TXT-Datei</div>
                                <?php if (!empty($indexNow['selected_root_file'])): ?>
                                    <div><code><?= htmlspecialchars((string) $indexNow['selected_root_file']) ?></code></div>
                                    <?php if (!empty($indexNow['selected_root_file_url'])): ?>
                                        <a href="<?= htmlspecialchars((string) $indexNow['selected_root_file_url']) ?>" target="_blank" rel="noopener noreferrer" class="small">
                                            <?= htmlspecialchars((string) $indexNow['selected_root_file_url']) ?>
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-secondary">Keine Datei ausgewählt</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($indexNow['selected_root_file'])): ?>
                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <div class="small text-secondary mb-1">Datei vorhanden</div>
                                    <span class="badge <?= !empty($indexNow['selected_root_file_exists']) ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= !empty($indexNow['selected_root_file_exists']) ? 'Ja' : 'Nein' ?>
                                    </span>
                                </div>
                                <div class="col-md-4">
                                    <div class="small text-secondary mb-1">Dateiname = API-Key</div>
                                    <span class="badge <?= !empty($indexNow['selected_root_file_matches_key']) ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= !empty($indexNow['selected_root_file_matches_key']) ? 'Ja' : 'Nein' ?>
                                    </span>
                                </div>
                                <div class="col-md-4">
                                    <div class="small text-secondary mb-1">Dateiinhalt = API-Key</div>
                                    <span class="badge <?= !empty($indexNow['selected_root_file_content_matches_key']) ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= !empty($indexNow['selected_root_file_content_matches_key']) ? 'Ja' : 'Nein' ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($indexNowValidationErrors !== []): ?>
                            <div class="alert alert-warning" role="alert">
                                <div class="fw-semibold mb-1">Prüfung mit Hinweisen</div>
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($indexNowValidationErrors as $validationError): ?>
                                        <li><?= htmlspecialchars((string) $validationError) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if ($indexNowValidationNotes !== []): ?>
                            <div class="text-secondary small">
                                <?php foreach ($indexNowValidationNotes as $validationNote): ?>
                                    <div>• <?= htmlspecialchars((string) $validationNote) ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <details class="mt-3">
                            <summary class="fw-semibold">Prüfdetails: Root-Pfade (<?= count($indexNowDebugCandidates) ?>)</summary>
                            <div class="text-secondary small mt-2 mb-3">Welche Root-Kandidaten geprüft wurden und wie die ausgewählte TXT-Datei bewertet wurde.</div>

                            <div class="alert alert-info" role="status">
                                <div class="fw-semibold mb-1">Prüf-Ergebnis für die ausgewählte TXT-Datei</div>
                                <div><?= htmlspecialchars($indexNowSelectedFileReason !== '' ? $indexNowSelectedFileReason : 'Noch keine Detailinformationen vorhanden.') ?></div>
                                <?php if (!empty($indexNow['selected_root_file_path'])): ?>
                                    <div class="small mt-2">Aufgelöster Dateipfad: <code><?= htmlspecialchars((string) $indexNow['selected_root_file_path']) ?></code></div>
                                <?php elseif ($indexNowSelectedFileResolvedFrom !== ''): ?>
                                    <div class="small mt-2">Gefunden unter: <code><?= htmlspecialchars($indexNowSelectedFileResolvedFrom) ?></code></div>
                                <?php endif; ?>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-sm table-bordered align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Quelle</th>
                                            <th>Geprüfter Pfad</th>
                                            <th>Status</th>
                                            <th>Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($indexNowDebugCandidates === []): ?>
                                            <tr>
                                                <td colspan="4" class="text-center text-secondary py-3">Noch keine Root-Prüfdaten verfügbar.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($indexNowDebugCandidates as $candidate): ?>
                                                <?php
                                                $candidate = is_array($candidate) ? $candidate : [];
                                                $candidateSource = (string)($candidate['source'] ?? 'unbekannt');
                                                $candidatePath = (string)($candidate['normalized_path'] ?? $candidate['original_path'] ?? '');
                                                $candidateUsable = !empty($candidate['usable']);
                                                $candidateReason = (string)($candidate['reason'] ?? '');
                                                $candidateTxtFiles = is_array($candidate['txt_files'] ?? null) ? $candidate['txt_files'] : [];
                                                $candidateSelectedExists = !empty($candidate['selected_file_exists']);
                                                $candidateSelectedPath = (string)($candidate['selected_file_path'] ?? '');
                                                ?>
                                                <tr>
                                                    <td><code><?= htmlspecialchars($candidateSource) ?></code></td>
                                                    <td>
                                                        <code><?= htmlspecialchars($candidatePath !== '' ? $candidatePath : '—') ?></code>
                                                        <?php if ($candidateSelectedExists && $candidateSelectedPath !== ''): ?>
                                                            <div class="small text-success mt-1">Ausgewählte Datei gefunden: <code><?= htmlspecialchars($candidateSelectedPath) ?></code></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge <?= $candidateUsable ? 'bg-success-lt text-success' : 'bg-danger-lt text-danger' ?>">
                                                            <?= $candidateUsable ? 'geprüft' : 'übersprungen' ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div><?= htmlspecialchars($candidateReason !== '' ? $candidateReason : 'Keine Details vorhanden.') ?></div>
                                                        <?php if ($candidateTxtFiles !== []): ?>
                                                            <div class="small text-secondary mt-1">
                                                                TXT-Dateien: <?= htmlspecialchars(implode(', ', array_map(static fn(mixed $file): string => (string) $file, $candidateTxtFiles))) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h3 class="card-title mb-0">URLs manuell melden</h3>
                        <div class="d-flex flex-wrap gap-2 justify-content-end">
                            <span class="badge <?= $indexNowKeyValid ? 'bg-success' : 'bg-warning text-dark' ?>">
                                IndexNow <?= $indexNowKeyValid ? 'bereit' : 'ohne Key' ?>
                            </span>
                            <span class="badge <?= $googleTokenAvailable ? 'bg-success' : 'bg-secondary' ?>">
                                Google-Token <?= $googleTokenAvailable ? 'gespeichert' : 'fehlt' ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <p class="text-secondary small">
                            Eine URL pro Zeile, maximal <?= $maxManualUrls ?> URLs dieser Website je Meldung. Melden Sie nur neue, geänderte oder gelöschte URLs –
                            gelöschte Seiten sollten <code>404</code> oder <code>410</code> liefern. Dieselbe URL frühestens nach <?= $resubmitMinutes ?> Minuten erneut melden.
                        </p>
                        <form method="post" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="submit_indexing_urls">
                            <div class="col-12">
                                <label for="seoIndexingUrls" class="form-label">URLs zur Übermittlung</label>
                                <textarea id="seoIndexingUrls" class="form-control" name="urls" rows="6" placeholder="<?= htmlspecialchars($runtimeBaseUrl) ?>/&#10;<?= htmlspecialchars($runtimeBaseUrl) ?>/blog/beispiel-artikel"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="submission_target[]" value="indexnow" <?= $indexNowKeyValid ? 'checked' : '' ?>>
                                    <span class="form-check-label">An IndexNow senden</span>
                                </label>
                            </div>
                            <div class="col-md-6">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="submission_target[]" value="google" <?= $googleTokenAvailable ? 'checked' : '' ?>>
                                    <span class="form-check-label">An Google Indexing API senden</span>
                                </label>
                            </div>
                            <div class="col-12">
                                <label for="seoIndexingGoogleAccessToken" class="form-label">Google Access-Token (optional)</label>
                                <input id="seoIndexingGoogleAccessToken" class="form-control" type="password" name="google_access_token" autocomplete="off" placeholder="ya29...">
                                <div class="form-hint">Nur für diese Meldung. Leer lassen, um das dauerhaft gespeicherte Token zu verwenden<?= $googleTokenAvailable ? ' (vorhanden)' : ' (aktuell keines gespeichert)' ?>.</div>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary" type="submit">URLs jetzt übermitteln</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <h3 class="card-title">Kürzlich veröffentlichte Inhalte melden</h3>
                    </div>
                    <div class="card-body">
                        <?php $recentContentAnyTargetAvailable = $indexNowKeyValid || $googleTokenAvailable; ?>
                        <?php if (!$recentContentAnyTargetAvailable): ?>
                            <div class="alert alert-warning mb-3" role="alert">
                                Weder IndexNow noch Google sind konfiguriert. Bitte zuerst einen IndexNow-API-Key oder ein Google-Access-Token hinterlegen.
                            </div>
                        <?php else: ?>
                            <div class="text-secondary small mb-3">
                                Meldet alle im gewählten Zeitraum veröffentlichten Beiträge und geänderten Seiten (inkl. EN-Fassung, ohne noindex-Inhalte) in einem Schritt – praktisch nach Importen,
                                Massenänderungen oder für Änderungen aus der Zeit vor der IndexNow-Einrichtung.
                            </div>
                        <?php endif; ?>
                        <form method="post" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="submit_recent_content_indexnow">
                            <div class="col-md-8">
                                <label for="seoRecentContentRange" class="form-label">Zeitraum</label>
                                <select id="seoRecentContentRange" class="form-select" name="recent_content_range" <?= !$recentContentAnyTargetAvailable ? 'disabled' : '' ?>>
                                    <?php foreach ($recentContentRanges as $rangeKey => $rangeLabel): ?>
                                        <option value="<?= htmlspecialchars((string) $rangeKey) ?>"><?= htmlspecialchars((string) $rangeLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button class="btn btn-primary w-100" type="submit" <?= !$recentContentAnyTargetAvailable ? 'disabled' : '' ?>>Jetzt melden</button>
                            </div>
                            <div class="col-md-6">
                                <label class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" name="recent_content_target[]" value="indexnow" <?= $indexNowKeyValid ? 'checked' : 'disabled' ?>>
                                    <span class="form-check-label">An IndexNow melden</span>
                                </label>
                            </div>
                            <div class="col-md-6">
                                <label class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" name="recent_content_target[]" value="google" <?= $googleTokenAvailable ? 'checked' : 'disabled' ?>>
                                    <span class="form-check-label">An Google Indexing API melden</span>
                                </label>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Google: URL aus dem Index entfernen</h3>
                    </div>
                    <div class="card-body">
                        <form method="post" class="row g-3" data-cms-confirm="URL wirklich bei Google zur Entfernung aus dem Index melden?">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_google_url">
                            <div class="col-md-7">
                                <label for="seoGoogleDeleteUrl" class="form-label">URL</label>
                                <input id="seoGoogleDeleteUrl" class="form-control" type="url" name="google_delete_url" placeholder="<?= htmlspecialchars($runtimeBaseUrl) ?>/veraltete-seite">
                            </div>
                            <div class="col-md-5">
                                <label for="seoGoogleDeleteAccessToken" class="form-label">Google Access-Token (optional)</label>
                                <input id="seoGoogleDeleteAccessToken" class="form-control" type="password" name="google_access_token" autocomplete="off" placeholder="ya29...">
                                <div class="form-hint">Leer lassen, um das gespeicherte Token zu verwenden.</div>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-outline-danger" type="submit">URL bei Google entfernen</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h3 class="card-title mb-0">Automatische Meldung</h3>
                        <span class="badge <?= $autoSubmitEnabled ? 'bg-success' : 'bg-secondary' ?>"><?= $autoSubmitEnabled ? 'aktiv' : 'aus' ?></span>
                    </div>
                    <div class="card-body">
                        <?php if ($autoSubmitEnabled && !$indexNowKeyValid): ?>
                            <div class="alert alert-warning" role="alert">Die automatische Meldung ist eingeschaltet, startet aber erst mit einem gültigen IndexNow-Key.</div>
                        <?php endif; ?>
                        <div class="d-flex align-items-baseline justify-content-between mb-2">
                            <div class="fw-semibold">Warteschlange</div>
                            <div class="small text-secondary"><?= $queueCount ?> von max. <?= $queueMax ?> URLs</div>
                        </div>
                        <?php if ($queueUrls !== []): ?>
                            <ul class="small ps-3 mb-2">
                                <?php foreach ($queueUrls as $queuedUrl): ?>
                                    <li class="text-break"><code><?= htmlspecialchars((string) $queuedUrl) ?></code></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if ($queueCount > count($queueUrls)): ?>
                                <div class="small text-secondary mb-2">… und <?= $queueCount - count($queueUrls) ?> weitere.</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="small text-secondary mb-2">Keine vorgemerkten URLs.</div>
                        <?php endif; ?>

                        <?php if ($queueLastResult !== []): ?>
                            <?php
                            $lastResultSuccess = !empty($queueLastResult['success']);
                            $lastResultStatus = (int)($queueLastResult['status'] ?? 0);
                            $lastResultTrigger = (string)($queueLastResult['trigger'] ?? '');
                            ?>
                            <div class="alert <?= $lastResultSuccess ? 'alert-success' : 'alert-warning' ?> small mb-3" role="status">
                                <div class="fw-semibold mb-1">
                                    Letzter Lauf: <?= htmlspecialchars((string)($queueLastResult['time'] ?? '')) ?>
                                    (<?= htmlspecialchars($queueTriggerLabels[$lastResultTrigger] ?? $lastResultTrigger) ?><?= $lastResultStatus > 0 ? ', HTTP ' . $lastResultStatus : '' ?>)
                                </div>
                                <div><?= htmlspecialchars((string)($queueLastResult['message'] ?? '')) ?></div>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex flex-wrap gap-2">
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="flush_indexnow_queue">
                                <button class="btn btn-primary" type="submit" <?= $queueCount === 0 || !$indexNowKeyValid ? 'disabled' : '' ?>>Jetzt senden</button>
                            </form>
                            <form method="post" data-cms-confirm="Alle vorgemerkten URLs verwerfen, ohne sie zu melden?">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="clear_indexnow_queue">
                                <button class="btn btn-outline-danger" type="submit" <?= $queueCount === 0 ? 'disabled' : '' ?>>Warteschlange leeren</button>
                            </form>
                        </div>
                        <div class="form-hint mt-2">
                            Nach dem Speichern wird sofort gesendet; der stündliche Cron wiederholt vorübergehend fehlgeschlagene Meldungen (Netzwerk, 403, 429, 5xx).
                            Bei <code>400</code>/<code>422</code> werden die URLs verworfen. Gelöschte, depublizierte oder umgeleitete URLs bitte manuell melden.
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h3 class="card-title mb-0">Google Indexing API</h3>
                        <span class="badge <?= $googleTokenAvailable ? 'bg-success' : 'bg-secondary' ?>">Token <?= $googleTokenAvailable ? 'gespeichert' : 'fehlt' ?></span>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info small" role="note">
                            Google erlaubt die Indexing API offiziell nur für Seiten mit <code>JobPosting</code>- oder <code>BroadcastEvent</code>-Markup.
                            Für alle anderen Inhalte genügen Sitemap und Search Console.
                        </div>
                        <div class="text-secondary small mb-2">
                            Ein hier gespeichertes OAuth-Access-Token (verschlüsselt) wird automatisch für alle Google-Meldungen verwendet. Access-Tokens laufen nach kurzer Zeit ab und müssen erneuert werden.
                        </div>
                        <form method="post" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="save_google_access_token">
                            <div class="col-md-8">
                                <input class="form-control" type="password" name="google_access_token" aria-label="Google Access-Token" autocomplete="off" placeholder="ya29...">
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-outline-primary w-100" type="submit">Speichern</button>
                            </div>
                        </form>
                        <?php if ($googleTokenAvailable): ?>
                            <form method="post" class="mt-2" data-cms-confirm="Gespeichertes Google-Token wirklich entfernen?">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="clear_google_access_token">
                                <button class="btn btn-sm btn-outline-danger" type="submit">Gespeichertes Token entfernen</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <h3 class="card-title">Suchmaschinen im Überblick</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-bordered align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Suchmaschine</th>
                                        <th>IndexNow</th>
                                        <th>Verifizierung</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($verificationEngines as $engine => $definition): ?>
                                        <tr>
                                            <td>
                                                <a href="<?= htmlspecialchars((string)($definition['tool_url'] ?? '')) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string)($definition['label'] ?? $engine)) ?></a>
                                            </td>
                                            <td>
                                                <?php if (!empty($definition['indexnow'])): ?>
                                                    <span class="badge bg-success-lt text-success">ja</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-lt text-secondary">nein</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ((string)($verificationCodes[$engine] ?? '') !== ''): ?>
                                                    <span class="badge bg-success-lt text-success">aktiv</span>
                                                <?php else: ?>
                                                    <span class="text-secondary small">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr>
                                        <td>Yep</td>
                                        <td><span class="badge bg-success-lt text-success">ja</span></td>
                                        <td><span class="text-secondary small">nicht nötig</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <ul class="small mb-0 ps-3">
                            <li>Eine IndexNow-Meldung an einen Teilnehmer wird an alle teilnehmenden Suchmaschinen weitergegeben.</li>
                            <li>Yahoo und DuckDuckGo greifen weitgehend auf den Bing-Index zurück und profitieren von Bing Webmaster Tools und IndexNow.</li>
                            <li>Google und Baidu nehmen nicht an IndexNow teil: Sitemap im jeweiligen Webmaster-Tool einreichen.</li>
                            <li>Sitemap-Pings wurden eingestellt (Bing 2022, Google 2023). <code>robots.txt</code> verweist automatisch auf <a href="<?= htmlspecialchars($sitemapUrl) ?>" target="_blank" rel="noopener noreferrer"><code>sitemap.xml</code></a>.</li>
                            <li>Änderungen aus der Zeit vor der IndexNow-Einrichtung erfassen die Suchmaschinen über das <code>lastmod</code> der Sitemap.</li>
                        </ul>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">IndexNow-Protokoll</h3>
                    </div>
                    <div class="card-body">
                        <ul class="small mb-3 ps-3">
                            <li>Übermittlung per <code>POST</code> (JSON, UTF-8) an <code><?= htmlspecialchars((string)($indexNow['endpoint'] ?? 'https://api.indexnow.org/indexnow')) ?></code> mit <code>host</code>, <code>key</code>, <code>keyLocation</code> und <code>urlList</code> – bis zu <?= number_format($maxUrlsPerRequest, 0, ',', '.') ?> URLs je Anfrage.</li>
                            <li>Key: 8–128 Zeichen, nur <code>a-z</code>, <code>A-Z</code>, <code>0-9</code> und <code>-</code>. Nur Sie und die Suchmaschinen sollten den Key kennen.</li>
                            <li>Besitznachweis: UTF-8-Datei <code>{key}.txt</code> mit dem Key als Inhalt im Root des Hosts (empfohlen). Abweichende Speicherorte gelten nur für URLs unterhalb ihres Verzeichnisses.</li>
                            <li>
                                Aktuelle <code>keyLocation</code>:
                                <?php if (!empty($indexNow['key_location'])): ?>
                                    <code class="text-break"><?= htmlspecialchars((string) $indexNow['key_location']) ?></code>
                                <?php else: ?>
                                    <span class="text-secondary">kein gültiger Key</span>
                                <?php endif; ?>
                            </li>
                            <li>HTTP <code>200</code>/<code>202</code> bestätigt nur den Empfang, nicht die Indexierung.</li>
                        </ul>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>HTTP</th>
                                        <th>Antwort</th>
                                        <th>Bedeutung</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($responseCodes as $responseCode => $responseInfo): ?>
                                        <tr>
                                            <td><span class="badge <?= !empty($responseInfo['success']) ? 'bg-success' : 'bg-warning text-dark' ?>"><?= (int) $responseCode ?></span></td>
                                            <td><?= htmlspecialchars((string)($responseInfo['label'] ?? '')) ?></td>
                                            <td><?= htmlspecialchars((string)($responseInfo['description'] ?? '')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
