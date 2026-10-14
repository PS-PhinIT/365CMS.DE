<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_SEO_VIEW')) {
    exit;
}

$technical = $data['technical'] ?? [];
$settings = $technical['settings'] ?? [];
$brokenLinks = $technical['broken_links'] ?? [];
$brokenLinkReport = $technical['broken_links_report'] ?? [];
$missingAltRows = $technical['missing_alt_rows'] ?? [];
$noindexCandidates = $technical['noindex_candidates'] ?? [];
$hreflangGroups = $technical['hreflang_groups'] ?? [];
$redirectStats = $technical['redirect_stats'] ?? [];
$indexNow = $technical['indexnow'] ?? [];
$indexNowRootTxtFiles = $indexNow['root_txt_files'] ?? [];
$indexNowValidationErrors = $indexNow['validation_errors'] ?? [];
$indexNowValidationNotes = $indexNow['validation_notes'] ?? [];
$indexNowDebug = $indexNow['debug'] ?? [];
$indexNowDebugCandidates = is_array($indexNowDebug['root_candidates'] ?? null) ? $indexNowDebug['root_candidates'] : [];
$indexNowSelectedFileReason = (string)($indexNowDebug['selected_file_reason'] ?? '');
$indexNowSelectedFileResolvedFrom = (string)($indexNowDebug['selected_file_resolved_from'] ?? '');
$brokenLinkAvailable = !empty($brokenLinkReport['available']);
$brokenLinkGeneratedAt = (string)($brokenLinkReport['generated_at_label'] ?? '');
$brokenLinkTrigger = (string)($brokenLinkReport['trigger_label'] ?? '—');
$brokenLinkDuration = (int)($brokenLinkReport['duration_ms'] ?? 0);
$brokenLinkFindingsTotal = (int)($brokenLinkReport['findings_total'] ?? count($brokenLinks));
$brokenLinkOccurrencesTotal = (int)($brokenLinkReport['occurrences_total'] ?? 0);
$brokenLinkIgnoredTotal = (int)($brokenLinkReport['ignored_total'] ?? 0);
$brokenLinkSuppressedTotal = (int)($brokenLinkReport['suppressed_total'] ?? 0);
$brokenLinkSourceStats = is_array($brokenLinkReport['source_stats'] ?? null) ? $brokenLinkReport['source_stats'] : [];
$brokenLinkIgnoredPaths = is_array($brokenLinkReport['ignored_paths'] ?? null) ? $brokenLinkReport['ignored_paths'] : [];
$brokenLinkNotes = is_array($brokenLinkReport['notes'] ?? null) ? $brokenLinkReport['notes'] : [];
?>
<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">SEO</div>
                <h2 class="page-title">Technisches SEO</h2>
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
                    <div class="subheader">Broken Links</div>
                    <div class="h1 mb-0 text-danger"><?= $brokenLinkFindingsTotal ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card">
                <div class="card-body">
                    <div class="subheader">Bilder ohne Alt</div>
                    <div class="h1 mb-0 text-warning"><?= count($missingAltRows) ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card">
                <div class="card-body">
                    <div class="subheader">noindex-Kandidaten</div>
                    <div class="h1 mb-0"><?= count($noindexCandidates) ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card">
                <div class="card-body">
                    <div class="subheader">404-Hits</div>
                    <div class="h1 mb-0"><?= (int)($redirectStats['not_found_hits'] ?? 0) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header">
                    <h3 class="card-title">Technische Standards &amp; IndexNow</h3>
                </div>
                <div class="card-body">
                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="save_technical_settings">

                        <div class="col-12">
                            <label class="form-check">
                                <input class="form-check-input" type="checkbox" name="auto_redirect_slug" value="1" <?= !empty($settings['seo_technical_auto_redirect_slug']) ? 'checked' : '' ?>>
                                <span class="form-check-label">Weiterleitung bei Slug-Änderung automatisch</span>
                            </label>
                        </div>
                        <div class="col-12">
                            <label class="form-check">
                                <input class="form-check-input" type="checkbox" name="hreflang_enabled" value="1" <?= !empty($settings['seo_technical_hreflang_enabled']) ? 'checked' : '' ?>>
                                <span class="form-check-label">hreflang-Gruppen pflegen</span>
                            </label>
                        </div>
                        <div class="col-12">
                            <label class="form-check">
                                <input class="form-check-input" type="checkbox" name="breadcrumbs_enabled" value="1" <?= !empty($settings['seo_technical_breadcrumbs_enabled']) ? 'checked' : '' ?>>
                                <span class="form-check-label">Breadcrumbs aktiv</span>
                            </label>
                        </div>
                        <div class="col-12">
                            <label class="form-check">
                                <input class="form-check-input" type="checkbox" name="image_alt_required" value="1" <?= !empty($settings['seo_technical_image_alt_required']) ? 'checked' : '' ?>>
                                <span class="form-check-label">Bild-Alt-Texte verpflichtend</span>
                            </label>
                        </div>
                        <div class="col-12">
                            <label class="form-check">
                                <input class="form-check-input" type="checkbox" name="noindex_archives" value="1" <?= !empty($settings['seo_technical_noindex_archives']) ? 'checked' : '' ?>>
                                <span class="form-check-label">Archive noindex</span>
                            </label>
                        </div>
                        <div class="col-12">
                            <label class="form-check">
                                <input class="form-check-input" type="checkbox" name="noindex_tags" value="1" <?= !empty($settings['seo_technical_noindex_tags']) ? 'checked' : '' ?>>
                                <span class="form-check-label">Tags noindex</span>
                            </label>
                        </div>
                        <div class="col-12">
                            <label class="form-check">
                                <input class="form-check-input" type="checkbox" name="pagination_rel" value="1" <?= !empty($settings['seo_technical_pagination_rel']) ? 'checked' : '' ?>>
                                <span class="form-check-label">Pagination-SEO aktiv</span>
                            </label>
                        </div>
                        <div class="col-12">
                            <label class="form-check">
                                <input class="form-check-input" type="checkbox" name="broken_link_scan" value="1" <?= !empty($settings['seo_technical_broken_link_scan']) ? 'checked' : '' ?>>
                                <span class="form-check-label">Broken-Link-Prüfung aktiv</span>
                            </label>
                        </div>

                        <div class="col-12"><hr class="my-2"></div>

                        <div class="col-12">
                            <label class="form-label" for="indexnow-key">IndexNow API-Key</label>
                            <input
                                class="form-control"
                                id="indexnow-key"
                                type="text"
                                name="indexnow_key"
                                value="<?= htmlspecialchars((string)($settings['seo_indexnow_key'] ?? '')) ?>"
                                placeholder="z. B. 75d0bf8d82694244923c1ecf56fe1e6e"
                                autocomplete="off"
                            >
                            <div class="form-hint">Der Key wird für die dynamische <code>/KEY.txt</code>-Auslieferung und für IndexNow-Submissions verwendet.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="indexnow-key-file">Root-`.txt`-Datei auswählen</label>
                            <select class="form-select" id="indexnow-key-file" name="indexnow_key_file">
                                <option value="">Keine physische Root-TXT-Datei wählen</option>
                                <?php foreach ($indexNowRootTxtFiles as $fileName): ?>
                                    <option value="<?= htmlspecialchars((string) $fileName) ?>" <?= (($settings['seo_indexnow_key_file'] ?? '') === $fileName) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string) $fileName) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-hint">
                                Root-Verzeichnis: <code><?= htmlspecialchars((string)($indexNow['root_directory'] ?? ABSPATH)) ?></code>
                            </div>
                            <?php if (empty($indexNowRootTxtFiles)): ?>
                                <div class="alert alert-warning mt-3 mb-0" role="alert">
                                    Im aktuellen CMS-Root wurde noch keine <code>.txt</code>-Datei gefunden.
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php $legacyHeaderCode = (string)($settings['seo_custom_header_code'] ?? ''); ?>
                        <?php if (trim($legacyHeaderCode) !== ''): ?>
                            <div class="col-12">
                                <div class="alert alert-warning mb-2" role="alert">
                                    <strong>Eigener Header-Code (Altbestand):</strong> Dieser Code wird weiterhin unverändert in den <code>&lt;head&gt;</code> aller Seiten ausgegeben.
                                    Neuer Head-Code wird im Theme-Customizer unter <em>Erweitert</em> gepflegt. Inline-Skripte ohne Nonce blockiert die Content-Security-Policy.
                                </div>
                                <label class="form-label" for="seo-legacy-header-code">Aktueller Inhalt (nur Anzeige)</label>
                                <textarea class="form-control font-monospace" id="seo-legacy-header-code" rows="4" readonly><?= htmlspecialchars($legacyHeaderCode) ?></textarea>
                                <label class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="clear_legacy_header_code" value="1">
                                    <span class="form-check-label">Diesen Header-Code beim Speichern entfernen</span>
                                </label>
                            </div>
                        <?php endif; ?>

                        <div class="col-12">
                            <button class="btn btn-primary" type="submit">Technische SEO speichern</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card mb-4">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h3 class="card-title mb-0">IndexNow-Prüfung</h3>
                    <div class="d-flex flex-wrap gap-2 justify-content-end">
                        <span class="badge <?= !empty($indexNow['key_available']) ? 'bg-success' : 'bg-warning text-dark' ?>">
                            API-Key <?= !empty($indexNow['key_available']) ? 'bereit' : 'fehlt' ?>
                        </span>
                        <span class="badge <?= !empty($indexNow['selected_root_file']) ? 'bg-primary' : 'bg-secondary' ?>">
                            Root-TXT <?= !empty($indexNow['selected_root_file']) ? 'gewählt' : 'nicht gewählt' ?>
                        </span>
                        <span class="badge <?= !empty($indexNow['ready_for_submission']) ? 'bg-success' : 'bg-warning text-dark' ?>">
                            Übermittlung <?= !empty($indexNow['ready_for_submission']) ? 'bereit' : 'prüfen' ?>
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
                                <span class="text-secondary">Noch nicht verfügbar</span>
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

                    <?php if (!empty($indexNowValidationErrors)): ?>
                        <div class="alert alert-warning" role="alert">
                            <div class="fw-semibold mb-1">Prüfung mit Hinweisen</div>
                            <ul class="mb-0 ps-3">
                                <?php foreach ($indexNowValidationErrors as $error): ?>
                                    <li><?= htmlspecialchars((string) $error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($indexNowValidationNotes)): ?>
                        <div class="text-secondary small">
                            <?php foreach ($indexNowValidationNotes as $note): ?>
                                <div>• <?= htmlspecialchars((string) $note) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <hr class="my-4">

                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                        <div>
                            <div class="fw-semibold">Prüfdetails: Root-Pfade</div>
                            <div class="text-secondary small">Diese Übersicht zeigt, welche Kandidaten geprüft wurden und wie die ausgewählte TXT-Datei bewertet wurde.</div>
                        </div>
                        <span class="badge bg-info-lt text-info"><?= count($indexNowDebugCandidates) ?> Pfad<?= count($indexNowDebugCandidates) === 1 ? '' : 'e' ?></span>
                    </div>

                    <div class="alert alert-info" role="alert">
                        <div class="fw-semibold mb-1">Prüf-Ergebnis für die ausgewählte TXT-Datei</div>
                        <div><?= htmlspecialchars($indexNowSelectedFileReason !== '' ? $indexNowSelectedFileReason : 'Noch keine Detailinformationen vorhanden.') ?></div>
                        <?php if (!empty($indexNow['selected_root_file_path'])): ?>
                            <div class="small mt-2">Aufgelöster Dateipfad: <code><?= htmlspecialchars((string) $indexNow['selected_root_file_path']) ?></code></div>
                        <?php elseif ($indexNowSelectedFileResolvedFrom !== ''): ?>
                            <div class="small mt-2">Gefunden unter: <code><?= htmlspecialchars($indexNowSelectedFileResolvedFrom) ?></code></div>
                        <?php endif; ?>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle">
                            <thead>
                                <tr>
                                    <th>Quelle</th>
                                    <th>Geprüfter Pfad</th>
                                    <th>Status</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($indexNowDebugCandidates)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-secondary py-3">Noch keine Root-Prüfdaten verfügbar.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($indexNowDebugCandidates as $candidate): ?>
                                        <?php
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
                                                <?php if (!empty($candidateTxtFiles)): ?>
                                                    <div class="small text-secondary mt-1">
                                                        TXT-Dateien: <?= htmlspecialchars(implode(', ', array_map(static fn($file): string => (string) $file, $candidateTxtFiles))) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
                    <div>
                        <h3 class="card-title mb-0">Broken-Link-Report</h3>
                        <div class="text-secondary small mt-1">
                            Letzter Lauf: <?= $brokenLinkAvailable ? htmlspecialchars($brokenLinkGeneratedAt) : 'noch nicht ausgeführt' ?>
                            <?php if ($brokenLinkAvailable): ?>
                                · <?= htmlspecialchars($brokenLinkTrigger) ?>
                                <?php if ($brokenLinkDuration > 0): ?>
                                    · <?= $brokenLinkDuration ?> ms
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <span class="badge <?= !empty($settings['seo_technical_broken_link_scan']) ? 'bg-success' : 'bg-secondary' ?>">
                            Cron <?= !empty($settings['seo_technical_broken_link_scan']) ? 'aktiv' : 'deaktiviert' ?>
                        </span>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="run_broken_link_scan">
                            <button type="submit" class="btn btn-outline-primary btn-sm">Prüfung jetzt ausführen</button>
                        </form>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-4">
                        <div class="col-sm-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-secondary small">Offene Zielpfade</div>
                                <div class="h2 mb-0 text-danger"><?= $brokenLinkFindingsTotal ?></div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-secondary small">Quellen-Treffer</div>
                                <div class="h2 mb-0"><?= $brokenLinkOccurrencesTotal ?></div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-secondary small">Ignoriert</div>
                                <div class="h2 mb-0 text-secondary"><?= $brokenLinkIgnoredTotal ?></div>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($brokenLinkNotes)): ?>
                        <div class="alert alert-info" role="alert">
                            <ul class="mb-0 ps-3">
                                <?php foreach ($brokenLinkNotes as $note): ?>
                                    <li><?= htmlspecialchars((string) $note) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($brokenLinkSuppressedTotal > 0): ?>
                        <div class="alert alert-secondary" role="alert">
                            <?= $brokenLinkSuppressedTotal ?> Zielpfad(e) werden aktuell über die Ignore-Liste aus der Übersicht ausgeblendet.
                        </div>
                    <?php endif; ?>

                    <div class="mb-3 d-flex flex-wrap gap-2">
                        <?php foreach ($brokenLinkSourceStats as $sourceStat): ?>
                            <span class="badge bg-azure-lt text-azure">
                                <?= htmlspecialchars((string)($sourceStat['label'] ?? 'Quelle')) ?>: <?= (int)($sourceStat['count'] ?? 0) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>

                    <div class="table-responsive">
                        <table class="table card-table table-vcenter">
                            <thead>
                                <tr>
                                    <th>Ziel</th>
                                    <th>Treffer pro Quelle</th>
                                    <th>Aktion</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$brokenLinkAvailable): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-secondary py-4">Es liegt noch kein gespeicherter Broken-Link-Report vor.</td>
                                    </tr>
                                <?php elseif (empty($brokenLinks)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-secondary py-4">Keine internen Broken Links erkannt.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($brokenLinks as $row): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><code><?= htmlspecialchars((string)($row['target_path'] ?? '')) ?></code></div>
                                                <?php if (!empty($row['target_url'])): ?>
                                                    <div class="text-secondary small mt-1"><?= htmlspecialchars((string) $row['target_url']) ?></div>
                                                <?php endif; ?>
                                                <div class="d-flex flex-wrap gap-2 mt-2">
                                                    <?php foreach ((array)($row['source_badges'] ?? []) as $badge): ?>
                                                        <span class="badge bg-azure-lt text-azure">
                                                            <?= htmlspecialchars((string)($badge['label'] ?? 'Quelle')) ?>: <?= (int)($badge['count'] ?? 0) ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                    <?php if ((int)($row['observed_404_hits'] ?? 0) > 0): ?>
                                                        <span class="badge bg-orange-lt text-orange">404-Hits: <?= (int)($row['observed_404_hits'] ?? 0) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?= (int)($row['occurrences_total'] ?? 0) ?> Treffer</div>
                                                <?php foreach ((array)($row['sources'] ?? []) as $source): ?>
                                                    <div class="small text-secondary mt-1">
                                                        <strong><?= htmlspecialchars((string)($source['kind_label'] ?? 'Quelle')) ?>:</strong>
                                                        <?= htmlspecialchars((string)($source['label'] ?? '')) ?>
                                                        <?php if (!empty($source['detail'])): ?>
                                                            · <?= htmlspecialchars((string)$source['detail']) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if (!empty($row['last_seen_at'])): ?>
                                                    <div class="small text-secondary mt-1">Zuletzt gesehen: <?= htmlspecialchars((string)$row['last_seen_at']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-nowrap">
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="ignore_broken_link_target">
                                                    <input type="hidden" name="target_path" value="<?= htmlspecialchars((string)($row['target_path'] ?? ''), ENT_QUOTES) ?>">
                                                    <button type="submit" class="btn btn-outline-secondary btn-sm">Ignorieren</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <hr class="my-4">

                    <h4 class="mb-3">Ignore-Liste</h4>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Zielpfad</th>
                                    <th>Hinzugefügt</th>
                                    <th>Aktion</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($brokenLinkIgnoredPaths)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-secondary py-3">Keine Einträge in der Ignore-Liste.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($brokenLinkIgnoredPaths as $ignoredEntry): ?>
                                        <tr>
                                            <td><code><?= htmlspecialchars((string)($ignoredEntry['path'] ?? '')) ?></code></td>
                                            <td><?= !empty($ignoredEntry['added_at']) ? htmlspecialchars((string)$ignoredEntry['added_at']) : '—' ?></td>
                                            <td class="text-nowrap">
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="unignore_broken_link_target">
                                                    <input type="hidden" name="target_path" value="<?= htmlspecialchars((string)($ignoredEntry['path'] ?? ''), ENT_QUOTES) ?>">
                                                    <button type="submit" class="btn btn-outline-primary btn-sm">Wieder aufnehmen</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">hreflang &amp; Bild-SEO</h3>
                </div>
                <div class="card-body">
                    <div class="mb-3"><strong>hreflang-Gruppen:</strong> <?= count($hreflangGroups) ?></div>
                    <div class="mb-3"><strong>404-Weiterleitungen:</strong> <?= (int)($redirectStats['redirects_total'] ?? 0) ?> Regeln, <?= (int)($redirectStats['redirects_active'] ?? 0) ?> aktiv</div>
                    <div class="text-secondary small">Inhalte mit fehlenden Bild-Alt-Texten: <?= count($missingAltRows) ?> · Inhalte mit noindex: <?= count($noindexCandidates) ?></div>
                </div>
            </div>
        </div>
    </div>
    </div>
</div>
