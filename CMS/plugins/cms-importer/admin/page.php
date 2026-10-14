<?php
/**
 * Admin-Template: WordPress Importer Hauptseite
 *
 * @var string|null $message         Feedback-Meldung
 * @var string      $msg_type        success|error|warning
 * @var array|null  $result          Import-Ergebnis-Array
 * @var string      $nonce           CSRF-Nonce (cms-importer-upload)
 * @var string      $nonce_download  CSRF-Nonce (cms-importer-download)
 * @var string      $nonce_cleanup   CSRF-Nonce (cms-importer-cleanup)
 * @var array       $log_entries     Letzte Import-Logs
 * @var array       $import_files    XML-/JSON-Dateien aus allen Import-Quellen
 * @var string      $import_dir_url  URL zum Import-Ordner
 * @var array       $cleanup_stats   Zähler für Bereinigung
 * @var array       $available_authors Verfügbare 365CMS-Autoren
 * @var int         $selected_author_id Vorausgewählter Zielautor
 * @var string      $selected_author_display_name Optionaler Autoren-Anzeigename
 */

if (!defined('ABSPATH')) {
    exit;
}

$esc_nonce          = htmlspecialchars($nonce ?? '');
$esc_nonce_download = htmlspecialchars($nonce_download ?? '');
$esc_nonce_cleanup  = htmlspecialchars($nonce_cleanup ?? '');
$selectedAuthorId = (int) ($selected_author_id ?? 0);
$selectedAuthorDisplayName = htmlspecialchars($selected_author_display_name ?? '', ENT_QUOTES);
?>
<div class="cms-importer-wrap ci-admin-shell">

    <div class="admin-page-header">
        <div>
            <h2>📥 WordPress Importer</h2>
            <p>Importiere WXR-Exporte, Kommentare, Tabellen, SEO-Daten, Bilder sowie Rank-Math-SEO-Settings und Weiterleitungen nach 365CMS.</p>
        </div>
        <div class="header-actions">
            <a href="/admin/plugins/cms-importer/cms-importer-log" class="btn btn-secondary">📋 Protokoll</a>
        </div>
    </div>

    <div class="ci-card ci-card--danger">
        <div class="ci-card__head ci-card__head--stack-mobile">
            <div>
                <div class="ci-card__eyebrow ci-card__eyebrow--danger">Vorbereitung</div>
                <h2 class="ci-card__title ci-card__title--danger">Importer-Bereinigung gezielt ausf&uuml;hren</h2>
                <p class="ci-muted">Du kannst jetzt gezielt nur <strong>Beitr&auml;ge</strong>, nur <strong>Seiten</strong>, nur <strong>SEO-Daten</strong> oder nur <strong>Tabellen</strong> bereinigen. Die L&ouml;schaktionen arbeiten absichtlich global auf dem jeweiligen Bereich.</p>
            </div>
            <div class="ci-inline-actions ci-inline-actions--wrap">
                <button type="button"
                        class="ci-btn ci-btn--danger js-cleanup-trigger"
                        data-cleanup-action="cleanup_posts"
                        data-cleanup-title="Alle Beitr&auml;ge l&ouml;schen"
                        data-cleanup-body="Es werden alle Beitr&auml;ge im CMS gel&ouml;scht. Zus&auml;tzlich entfernt der Importer zugeh&ouml;rige Kommentare, Tag-Zuordnungen, SEO-Metadaten und Import-Mappings f&uuml;r Beitr&auml;ge. Diese Aktion ist destruktiv und kann nicht r&uuml;ckg&auml;ngig gemacht werden.">
                    🧨 Nur Beitr&auml;ge l&ouml;schen
                </button>
                <button type="button"
                        class="ci-btn ci-btn--danger js-cleanup-trigger"
                        data-cleanup-action="cleanup_pages"
                        data-cleanup-title="Alle Seiten l&ouml;schen"
                        data-cleanup-body="Es werden alle Seiten im CMS gel&ouml;scht. Zus&auml;tzlich entfernt der Importer zugeh&ouml;rige SEO-Metadaten und Import-Mappings f&uuml;r Seiten. Diese Aktion ist destruktiv und kann nicht r&uuml;ckg&auml;ngig gemacht werden.">
                    🧱 Nur Seiten l&ouml;schen
                </button>
                <button type="button"
                        class="ci-btn ci-btn--danger js-cleanup-trigger"
                        data-cleanup-action="cleanup_seo"
                        data-cleanup-title="SEO-Daten bereinigen"
                        data-cleanup-body="Es werden die globalen 365CMS-SEO-Settings aus Importen sowie alle gespeicherten SEO-Metadaten entfernt. Redirect-Regeln bleiben erhalten. Diese Aktion ist destruktiv und kann nicht r&uuml;ckg&auml;ngig gemacht werden.">
                    🧹 Nur SEO bereinigen
                </button>
                <button type="button"
                        class="ci-btn ci-btn--danger js-cleanup-trigger"
                        data-cleanup-action="cleanup_tables"
                        data-cleanup-title="Alle Tabellen l&ouml;schen"
                        data-cleanup-body="Es werden alle Tabellen im CMS gel&ouml;scht. Zus&auml;tzlich entfernt der Importer zugeh&ouml;rige Tabellen-Mappings. Diese Aktion ist destruktiv und kann nicht r&uuml;ckg&auml;ngig gemacht werden.">
                    🗂️ Nur Tabellen l&ouml;schen
                </button>
                <button type="button"
                        class="ci-btn ci-btn--ghost-danger js-cleanup-trigger"
                        data-cleanup-action="cleanup_history"
                        data-cleanup-title="Importer-Verlauf l&ouml;schen"
                        data-cleanup-body="Es werden das komplette Import-Protokoll, alle Import-Mappings, Import-Meta-Daten und gespeicherten Berichte des Plugins entfernt. Dateien in den Import-Ordnern bleiben erhalten.">
                    🗑️ Importer-Verlauf l&ouml;schen
                </button>
            </div>
        </div>

        <div class="ci-cleanup-stats">
            <div class="ci-cleanup-stat">
                <span class="ci-cleanup-stat__value"><?php echo (int) ($cleanup_stats['posts'] ?? 0); ?></span>
                <span class="ci-cleanup-stat__label">Beitr&auml;ge gesamt</span>
            </div>
            <div class="ci-cleanup-stat">
                <span class="ci-cleanup-stat__value"><?php echo (int) ($cleanup_stats['pages'] ?? 0); ?></span>
                <span class="ci-cleanup-stat__label">Seiten gesamt</span>
            </div>
            <div class="ci-cleanup-stat">
                <span class="ci-cleanup-stat__value"><?php echo (int) ($cleanup_stats['tables'] ?? 0); ?></span>
                <span class="ci-cleanup-stat__label">Tabellen gesamt</span>
            </div>
            <div class="ci-cleanup-stat">
                <span class="ci-cleanup-stat__value"><?php echo (int) ($cleanup_stats['seo_total'] ?? 0); ?></span>
                <span class="ci-cleanup-stat__label">SEO-Datens&auml;tze</span>
            </div>
            <div class="ci-cleanup-stat">
                <span class="ci-cleanup-stat__value"><?php echo (int) ($cleanup_stats['logs'] ?? 0); ?></span>
                <span class="ci-cleanup-stat__label">Import-Protokolle</span>
            </div>
            <div class="ci-cleanup-stat">
                <span class="ci-cleanup-stat__value"><?php echo (int) ($cleanup_stats['seo_settings'] ?? 0); ?></span>
                <span class="ci-cleanup-stat__label">SEO-Settings</span>
            </div>
            <div class="ci-cleanup-stat">
                <span class="ci-cleanup-stat__value"><?php echo (int) ($cleanup_stats['mappings'] ?? 0); ?></span>
                <span class="ci-cleanup-stat__label">Mappings</span>
            </div>
            <div class="ci-cleanup-stat">
                <span class="ci-cleanup-stat__value"><?php echo (int) ($cleanup_stats['meta'] ?? 0); ?></span>
                <span class="ci-cleanup-stat__label">Meta-Eintr&auml;ge</span>
            </div>
            <div class="ci-cleanup-stat">
                <span class="ci-cleanup-stat__value"><?php echo (int) ($cleanup_stats['seo_meta'] ?? 0); ?></span>
                <span class="ci-cleanup-stat__label">SEO-Meta</span>
            </div>
            <div class="ci-cleanup-stat">
                <span class="ci-cleanup-stat__value"><?php echo (int) ($cleanup_stats['reports'] ?? 0); ?></span>
                <span class="ci-cleanup-stat__label">Berichte</span>
            </div>
        </div>
    </div>

        <div id="js-import-notice"
            class="alert alert-<?php echo htmlspecialchars(($msg_type ?? 'success') === 'error' ? 'error' : (($msg_type ?? 'success') === 'warning' ? 'warning' : 'success')); ?> ci-import-notice"
            <?php if (!($message ?? null)): ?>hidden<?php endif; ?>>
        <?php echo htmlspecialchars($message ?? ''); ?>
        <?php if (($result ?? null) && !empty($result['meta_report_download_url'])): ?>
            &nbsp;<a class="ci-notice__link"
               href="<?php echo htmlspecialchars((string) $result['meta_report_download_url']); ?>">
                &#128196; Bericht &ouml;ffnen
            </a>
            <?php if (!empty($result['meta_report_markdown_url'])): ?>
                &nbsp;<a class="ci-notice__link"
                   href="<?php echo htmlspecialchars((string) $result['meta_report_markdown_url']); ?>">
                    &#128221; Markdown (.md)
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="ci-card ci-card--full">
        <div class="ci-card__head ci-card__head--stack-mobile">
            <div>
                <div class="ci-card__eyebrow">Autor-Zuordnung</div>
                <h2 class="ci-card__title">365CMS-Autor und Anzeige-Name festlegen</h2>
                <p class="ci-muted">Du kannst alle importierten Artikel einem vorhandenen 365CMS-Account zuweisen. Optional legst du zus&auml;tzlich fest, unter welchem Namen dieser Autor im Artikel angezeigt wird. Wenn das Feld leer bleibt, nutzt 365CMS wie gewohnt den normalen Anzeigenamen des gew&auml;hlten Accounts.</p>
            </div>
        </div>

        <div class="ci-options__grid">
            <label class="ci-option ci-option--stack" for="js-assigned-author-id">
                <span class="ci-option__label">Zugewiesener 365CMS-Autor</span>
                <select class="ci-form-control" id="js-assigned-author-id">
                    <option value="0">Automatisch aus dem WordPress-Autor ableiten</option>
                    <?php foreach (($available_authors ?? []) as $author): ?>
                        <?php
                        $authorId = (int) ($author['id'] ?? 0);
                        $authorName = trim((string) ($author['display_name'] ?? ''));
                        $authorUsername = trim((string) ($author['username'] ?? ''));
                        $authorRole = trim((string) ($author['role'] ?? ''));
                        $authorLabel = $authorName !== '' ? $authorName : $authorUsername;
                        if ($authorUsername !== '' && $authorUsername !== $authorLabel) {
                            $authorLabel .= ' (@' . $authorUsername . ')';
                        }
                        if ($authorRole !== '') {
                            $authorLabel .= ' – ' . $authorRole;
                        }
                        ?>
                        <option value="<?php echo $authorId; ?>" <?php echo $selectedAuthorId === $authorId ? 'selected' : ''; ?>><?php echo htmlspecialchars($authorLabel, ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="ci-option ci-option--stack" for="js-author-display-name">
                <span class="ci-option__label">Anzeigename im Artikel (optional)</span>
                <input type="text"
                       class="ci-form-control"
                       id="js-author-display-name"
                       value="<?php echo $selectedAuthorDisplayName; ?>"
                       maxlength="150"
                       placeholder="z. B. Redaktion 365, Max Musterautor oder Team Knowledge Base">
                <span class="ci-options__hint">Leer lassen = 365CMS-Anzeigename des gew&auml;hlten Autors verwenden.</span>
            </label>
        </div>
    </div>

    <div class="dashboard-grid ci-result-grid" id="js-stats-box" <?php if (!($result ?? null)): ?>hidden<?php endif; ?>>
        <div class="stat-card"><div class="stat-icon">📦</div><div class="stat-number"><?php echo (int)($result['total'] ?? 0); ?></div><div class="stat-label">Gesamt</div></div>
        <div class="stat-card"><div class="stat-icon">✅</div><div class="stat-number"><?php echo (int)($result['imported'] ?? 0); ?></div><div class="stat-label">Importiert</div></div>
        <div class="stat-card"><div class="stat-icon">⏭️</div><div class="stat-number"><?php echo (int)($result['skipped'] ?? 0); ?></div><div class="stat-label">Übersprungen</div></div>
        <div class="stat-card"><div class="stat-icon">⚠️</div><div class="stat-number"><?php echo (int)($result['errors'] ?? 0); ?></div><div class="stat-label">Fehler</div></div>
        <div class="stat-card"><div class="stat-icon">🖼️</div><div class="stat-number"><?php echo (int)($result['images_downloaded'] ?? 0); ?></div><div class="stat-label">Bilder</div></div>
        <div class="stat-card"><div class="stat-icon">🧬</div><div class="stat-number"><?php echo (int)($result['meta_keys'] ?? 0); ?></div><div class="stat-label">Meta-Keys</div></div>
    </div>

    <!-- Tabs -->
    <div class="ci-tabs">
        <button class="ci-tab ci-tab--active" data-tab="upload" type="button">
            &#128193; Datei hochladen
        </button>
        <button class="ci-tab" data-tab="folder" type="button">
            &#128194; Aus Import-Ordner
            <?php if (!empty($import_files)): ?>
                <span class="ci-tab-badge"><?php echo count($import_files); ?></span>
            <?php endif; ?>
        </button>
    </div>

    <!-- Tab: Upload -->
    <div class="ci-tab-panel ci-tab-panel--active" id="tab-upload">
        <div class="ci-layout-grid">
            <div class="ci-card ci-card--hero">
                <div class="ci-card__eyebrow">Direktimport</div>
                <h2 class="ci-card__title">WordPress-Exportdatei hochladen</h2>

                <form id="js-import-form" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="_nonce"     value="<?php echo $esc_nonce; ?>">
                    <input type="hidden" name="cms_action" value="cms_importer_upload_only">
                    <input type="hidden" id="js-uploaded-file" name="import_file" value="">
                    <input type="hidden" name="assigned_author_id" value="<?php echo $selectedAuthorId; ?>" data-shared-author-id-target>
                    <input type="hidden" name="author_display_name" value="<?php echo $selectedAuthorDisplayName; ?>" data-shared-author-display-target>

                    <!-- Verstecktes File-Input -->
                          <input type="file" name="wxr_file" id="wxr_file" aria-label="WordPress-Exportdatei auswählen"
                              accept=".xml,.json,text/xml,application/xml,application/json,text/json"
                           hidden style="display:none">

                    <div class="ci-wizard-wrap">
                        <div class="ci-wizard-intro">
                            <p class="ci-wizard-intro__title">In drei Schritten zum Import</p>
                            <p class="ci-wizard-intro__text">Datei ausw&auml;hlen, hochladen, erst Vorschau pr&uuml;fen und dann sicher importieren.</p>
                        </div>

                        <div class="ci-wizard">

                            <!-- Schritt 1: Datei auswählen -->
                            <div class="ci-wizard-step ci-wizard-step--active" id="ci-step-1">
                                <div class="ci-wizard-step__badge"><span>1</span></div>
                                <div class="ci-wizard-step__content">
                                    <p class="ci-wizard-step__title">Datei&nbsp;ausw&auml;hlen</p>
                                    <button type="button" class="ci-btn ci-btn--ghost" id="js-btn-select">
                                        &#128193;&nbsp;XML&nbsp;ausw&auml;hlen
                                    </button>
                                    <span class="ci-upload-filename" id="js-filename"></span>
                                </div>
                            </div>

                            <div class="ci-wizard-sep" id="ci-sep-1"></div>

                            <!-- Schritt 2: Hochladen -->
                            <div class="ci-wizard-step" id="ci-step-2">
                                <div class="ci-wizard-step__badge"><span>2</span></div>
                                <div class="ci-wizard-step__content">
                                    <p class="ci-wizard-step__title">Hochladen</p>
                                    <button type="button" class="ci-btn ci-btn--ghost" id="js-btn-upload" disabled>
                                        &#8679;&nbsp;Hochladen
                                    </button>
                                    <span id="js-upload-status" class="ci-wizard-step__status"></span>
                                </div>
                            </div>

                            <div class="ci-wizard-sep" id="ci-sep-2"></div>

                            <!-- Schritt 3: Import starten -->
                            <div class="ci-wizard-step" id="ci-step-3">
                                <div class="ci-wizard-step__badge"><span>3</span></div>
                                <div class="ci-wizard-step__content">
                                    <p class="ci-wizard-step__title">Vorschau&nbsp;&amp;&nbsp;Import</p>
                                    <div class="ci-action-row">
                                        <button type="button" class="ci-btn ci-btn--ghost" id="js-preview-btn" disabled>
                                            <span id="js-preview-text">&#128065;&nbsp;Dry&nbsp;Run</span>
                                            <span id="js-preview-spin" hidden>&#8635;&nbsp;Pr&uuml;fe&hellip;</span>
                                        </button>
                                        <button type="button" class="ci-btn ci-btn--primary" id="js-submit-btn" disabled>
                                            <span id="js-btn-text">&#9654;&nbsp;Import&nbsp;starten</span>
                                            <span id="js-btn-spin" hidden>&#8635;&nbsp;Importiere&hellip;</span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <div class="ci-options">
                        <p class="ci-options__title">Import-Optionen</p>
                        <div class="ci-options__grid">
                            <label class="ci-option">
                                <input type="checkbox" name="skip_duplicates"     value="1" checked>
                                <span>Duplikate &uuml;berspringen (gleicher Slug)</span>
                            </label>
                            <label class="ci-option">
                                <input type="checkbox" name="import_drafts"       value="1" checked>
                                <span>Entw&uuml;rfe importieren</span>
                            </label>
                            <label class="ci-option">
                                <input type="checkbox" name="import_trashed"      value="1">
                                <span>Gel&ouml;schte Beitr&auml;ge importieren</span>
                            </label>
                            <label class="ci-option">
                                <input type="checkbox" name="import_custom_types" value="1" checked>
                                <span>Benutzerdefinierte Post-Types importieren</span>
                            </label>
                            <label class="ci-option">
                                <input type="checkbox" name="import_only_en" value="1" data-shared-en-filter-source>
                                <span>Nur englische <code>/en/</code>-Beitr&auml;ge und -Seiten importieren</span>
                            </label>
                            <label class="ci-option">
                                <input type="checkbox" name="generate_report"     value="1" checked>
                                <span>Bericht f&uuml;r unbekannte Meta-Felder erstellen (HTML + Markdown)</span>
                            </label>
                            <label class="ci-option">
                                <input type="checkbox" name="download_images"     value="1" checked>
                                <span>Original-Bilddateien herunterladen und lokale URLs eintragen</span>
                            </label>
                            <label class="ci-option">
                                <input type="checkbox" name="convert_table_shortcodes" value="1" checked>
                                <span>WordPress-Tabellen-Shortcodes zu 365CMS-Shortcodes umwandeln</span>
                            </label>
                        </div>
                        <p class="ci-options__hint">Empfohlen f&uuml;r komplette Migrationen: Bilder aktiv lassen und Tabellen-Shortcodes umwandeln.</p>
                    </div>

                    <div class="ci-progress" id="js-progress" hidden>
                        <div class="ci-progress__bar">
                            <div class="ci-progress__fill" id="js-prog-fill"></div>
                        </div>
                        <p class="ci-progress__label" id="js-prog-label">Wird verarbeitet&hellip;</p>
                    </div>

                </form>
            </div>

            <aside class="ci-card ci-card--side">
                <div class="ci-card__eyebrow">Kurz &amp; klar</div>
                <h3 class="ci-card__title">Import-Checkliste</h3>
                <div class="ci-side-stack">
                    <div class="ci-mini-card">
                        <span class="ci-mini-card__icon">&#128221;</span>
                        <div>
                            <strong>XML oder Rank-Math JSON</strong>
                            <p>Nutze eine echte WXR-Datei aus dem WordPress-Export oder eine Rank-Math-Settings-JSON mit SEO-Defaults und Weiterleitungen.</p>
                        </div>
                    </div>
                    <div class="ci-mini-card">
                        <span class="ci-mini-card__icon">&#128065;</span>
                        <div>
                            <strong>Immer erst Dry Run</strong>
                            <p>Pr&uuml;fe vor dem Schreiben, welche Slugs, Bilder, Tabellen und Metas &uuml;bernommen werden.</p>
                        </div>
                    </div>
                    <div class="ci-mini-card">
                        <span class="ci-mini-card__icon">&#128206;</span>
                        <div>
                            <strong>Meta-Bericht aktiv lassen</strong>
                            <p>So bleiben Sonderdaten und nicht direkt gemappte WordPress-Felder nachvollziehbar.</p>
                        </div>
                    </div>
                </div>

                <div class="ci-side-callout">
                    <h4>Empfohlener Ablauf</h4>
                    <ol class="ci-ordered-list">
                        <li>Datei hochladen</li>
                        <li>Dry Run und Ziele kontrollieren</li>
                        <li>Erst danach importieren</li>
                    </ol>
                </div>
            </aside>
        </div>
    </div>

    <!-- Tab: Import-Ordner -->
    <div class="ci-tab-panel" id="tab-folder">
        <div class="ci-layout-grid">
            <div class="ci-card ci-card--hero">
                <div class="ci-card__head">
                    <h2 class="ci-card__title">
                        Dateien aus Import-Quellen
                        <?php if (!empty($import_files)): ?>
                            <span class="ci-badge"><?php echo count($import_files); ?></span>
                        <?php endif; ?>
                    </h2>
                    <span class="ci-muted">Quellen: <code>uploads/import/</code>, <code>wp_import_files/</code> &amp; <code>wp_import/</code></span>
                </div>

                <div id="js-folder-notice" hidden></div>

                <?php if (empty($import_files)): ?>
                    <div class="ci-empty">
                        <div class="ci-empty__icon">&#128194;</div>
                        <p>Keine XML-/JSON-Dateien in den bekannten Import-Quellen vorhanden.</p>
                        <p class="ci-muted">Nutze Uploads unter <code>uploads/import/</code> oder lege Test-/Exportdateien unter <code>wp_import_files/</code> bzw. <code>wp_import/</code> im Plugin ab.</p>
                    </div>
                <?php else: ?>
                    <div class="ci-table-wrap">
                        <table class="ci-table">
                            <thead>
                                <tr>
                                    <th>Quelle</th>
                                    <th>Dateiname</th>
                                    <th>Gr&ouml;&szlig;e</th>
                                    <th>Datum</th>
                                    <th>Aktion</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($import_files as $f): ?>
                                <tr id="row-<?php echo htmlspecialchars($f['name']); ?>">
                                    <td>
                                        <span class="ci-source-badge"><?php echo htmlspecialchars((string) ($f['source_label'] ?? 'Import')); ?></span>
                                        <?php if (!empty($f['source_hint'])): ?>
                                            <div class="ci-source-hint"><?php echo htmlspecialchars((string) $f['source_hint']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><code><?php echo htmlspecialchars($f['name']); ?></code></td>
                                    <td><?php echo htmlspecialchars($f['size_human']); ?></td>
                                    <td><?php echo htmlspecialchars($f['date']); ?></td>
                                    <td>
                                        <form class="js-folder-import-form"
                                              method="POST"
                                              data-filename="<?php echo htmlspecialchars($f['name']); ?>">
                                            <input type="hidden" name="_nonce"               value="<?php echo $esc_nonce; ?>">
                                            <input type="hidden" name="cms_action"           value="cms_importer_folder_import">
                                            <input type="hidden" name="import_file"          value="<?php echo htmlspecialchars($f['name']); ?>">
                                            <input type="hidden" name="import_source"        value="<?php echo htmlspecialchars((string) ($f['source_key'] ?? 'uploads')); ?>">
                                            <input type="hidden" name="skip_duplicates"      value="1">
                                            <input type="hidden" name="import_drafts"        value="1">
                                            <input type="hidden" name="import_custom_types"  value="1">
                                            <input type="hidden" name="import_only_en" value="0" data-shared-en-filter-target>
                                            <input type="hidden" name="generate_report"      value="1">
                                            <input type="hidden" name="download_images"      value="1">
                                            <input type="hidden" name="convert_table_shortcodes" value="1">
                                            <input type="hidden" name="assigned_author_id" value="<?php echo $selectedAuthorId; ?>" data-shared-author-id-target>
                                            <input type="hidden" name="author_display_name" value="<?php echo $selectedAuthorDisplayName; ?>" data-shared-author-display-target>
                                            <div class="ci-inline-actions">
                                                <button type="button"
                                                        class="ci-btn ci-btn--ghost ci-btn--sm js-folder-preview-btn">
                                                    &#128065; Vorschau
                                                </button>
                                                <button type="submit"
                                                        class="ci-btn ci-btn--primary ci-btn--sm js-folder-import-btn">
                                                    &#9654; Importieren
                                                </button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <aside class="ci-card ci-card--side">
                <div class="ci-card__eyebrow">Import-Ordner</div>
                <h3 class="ci-card__title">Quellen &amp; Hinweise</h3>

                <div class="ci-side-stack">
                    <div class="ci-mini-card">
                        <span class="ci-mini-card__icon">&#128193;</span>
                        <div>
                            <strong>Uploads / import</strong>
                            <p>Ideal f&uuml;r neue Dateien, die du direkt im CMS oder per Dateiupload ablegst.</p>
                        </div>
                    </div>
                    <div class="ci-mini-card">
                        <span class="ci-mini-card__icon">&#128230;</span>
                        <div>
                            <strong>Plugin / wp_import_files &amp; wp_import</strong>
                            <p>Perfekt f&uuml;r wiederkehrende Tests, Migrationspakete, Rank-Math-JSONs und feste Demo-Exporte.</p>
                        </div>
                    </div>
                </div>

                <div class="ci-side-callout">
                    <h4>Gut f&uuml;r Stapelarbeit</h4>
                    <p>Nutze zuerst die Vorschau pro Datei. So erkennst du doppelte Slugs, Bilder und Tabellen-Mappings vor dem Import.</p>
                </div>
            </aside>
        </div>
    </div>

    <div class="ci-card ci-card--full ci-preview-panel" id="js-preview-panel" hidden>
        <div class="ci-preview-panel__head">
            <h3 class="ci-preview-panel__title">Dry-Run Vorschau</h3>
            <p class="ci-preview-panel__sub">So w&uuml;rde der Import aktuell in 365CMS landen &ndash; noch ohne Schreibzugriff.</p>
        </div>
        <div class="ci-preview-summary" id="js-preview-summary"></div>
        <div class="ci-preview-reasons" id="js-preview-reasons" hidden></div>
        <div class="ci-table-wrap" id="js-preview-table-wrap" hidden>
            <table class="ci-table ci-preview-table">
                <thead>
                    <tr>
                        <th>Quelle</th>
                        <th>Ziel</th>
                        <th>Status</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody id="js-preview-table-body"></tbody>
            </table>
        </div>
        <p class="ci-preview-note" id="js-preview-note" hidden></p>
    </div>

    <!-- Info-Grid -->
    <div class="ci-info-grid">
        <div class="ci-info-card">
            <h3>&#9989; Was wird importiert?</h3>
            <ul>
                        <li>Beitr&auml;ge (<code>post</code>) &amp; Seiten (<code>page</code>)</li>
                <li>Kommentare aus WordPress-Beitr&auml;gen &rarr; <code>cms_comments</code></li>
                <li>Benutzerdefinierte Post-Types (optional)</li>
                <li>TablePress-Tabellen &rarr; <code>cms_site_tables</code></li>
                <li>Kategorien, Tags und SEO-Meta (Yoast, Rank Math, SEOPress)</li>
                <li>Bilder &rarr; lokale Import-Pfade inkl. Featured-Image-Zuordnung</li>
                        <li>Rank Math JSON &rarr; globale SEO-Defaults nach <code>cms_settings</code> und Eintr&auml;ge aus <code>redirections</code> nach <code>cms_redirect_rules</code></li>
            </ul>
        </div>
        <div class="ci-info-card">
            <h3>&#9888;&#65039; Was wird NICHT importiert?</h3>
            <ul>
                <li>Kommentare an WordPress-Seiten (365CMS-Kommentare sind beitragsbasiert)</li>
                <li>Benutzerkonten</li>
                <li>Men&uuml;s &amp; Navigation</li>
                <li>Rank-Math-Bereiche ohne 365CMS-Ziel, z. B. Analytics-, Role-Manager- oder geheime App-Credentials</li>
                <li>Exotische Plugin-Daten ohne Mapping (werden dokumentiert)</li>
            </ul>
        </div>
        <div class="ci-info-card">
            <h3>&#128247; Bild-Download</h3>
            <p>Attachment- und Inhaltsbilder werden nach M&ouml;glichkeit von der Original-URL geladen,
            lokal registriert und im Inhalt auf die neue 365CMS-URL umgeschrieben.</p>
        </div>
        <div class="ci-info-card">
            <h3>&#128203; Tabellen-Migration</h3>
            <p>WordPress-Shortcodes wie <code>[table id=5 /]</code> werden beim Import zu
            <code>[site-table id=&quot;X&quot;]</code> umgeschrieben, sobald die Tabelle vorhanden ist.</p>
        </div>
        <div class="ci-info-card">
            <h3>&#10145;&#65039; Redirect-Import</h3>
            <p>Bei Rank-Math-JSON &uuml;bernimmt der Importer die sinnvollen globalen SEO-Defaults f&uuml;r 365CMS und importiert zus&auml;tzlich Eintr&auml;ge aus <code>redirections</code>. Exakte Quellpfade werden &uuml;bernommen; komplexe Vergleichstypen werden in der Vorschau bzw. beim Import sauber &uuml;bersprungen.</p>
        </div>
    </div>

    <!-- Letzte Imports -->
    <?php if (!empty($log_entries)): ?>
    <div class="ci-card ci-card--full">
        <h2 class="ci-card__title">Zuletzt importiert</h2>
        <div class="users-table-container">
            <table class="users-table ci-table ci-table--embedded">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Dateiname</th>
                        <th>Gesamt</th>
                        <th>Importiert</th>
                        <th>&Uuml;bergangen</th>
                        <th>Fehler</th>
                        <th>Bilder</th>
                        <th>Datum</th>
                        <th>Bericht</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($log_entries as $log): ?>
                    <tr>
                        <td><?php echo (int)$log->id; ?></td>
                        <td><code class="ci-code"><?php echo htmlspecialchars($log->filename); ?></code></td>
                        <td><?php echo (int)$log->total; ?></td>
                        <td class="ci-ok"><?php echo (int)$log->imported; ?></td>
                        <td class="ci-warn"><?php echo (int)$log->skipped; ?></td>
                        <td class="ci-err"><?php echo (int)$log->errors; ?></td>
                        <td><?php echo (int)($log->images_downloaded ?? 0); ?></td>
                        <td><?php echo htmlspecialchars(substr($log->started_at ?? '', 0, 16)); ?></td>
                        <td>
                            <?php if (!empty($log->meta_report_path)): ?>
                                          <a href="/admin/plugins/cms-importer/cms-importer?action=download_report&amp;log_id=<?php echo (int)$log->id; ?>&amp;_nonce=<?php echo $esc_nonce_download; ?>&amp;format=html"
                                              class="ci-link">&#128196; Bericht</a>
                                          <span class="ci-muted"> / </span>
                                          <a href="/admin/plugins/cms-importer/cms-importer?action=download_report&amp;log_id=<?php echo (int)$log->id; ?>&amp;_nonce=<?php echo $esc_nonce_download; ?>&amp;format=md"
                                              class="ci-link">.md</a>
                            <?php else: ?>
                                <span class="ci-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /.cms-importer-wrap -->

<div class="ci-modal" id="js-cleanup-modal" hidden>
    <div class="ci-modal__backdrop" data-close-cleanup-modal></div>
    <div class="ci-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="js-cleanup-modal-title">
        <div class="ci-modal__header">
            <h3 id="js-cleanup-modal-title">Bereinigung best&auml;tigen</h3>
            <button type="button" class="ci-modal__close" data-close-cleanup-modal aria-label="Modal schlie&szlig;en">&times;</button>
        </div>
        <div class="ci-modal__body">
            <p id="js-cleanup-modal-text">Diese Aktion kann nicht r&uuml;ckg&auml;ngig gemacht werden.</p>
            <label class="ci-option ci-option--stack" style="margin-top:1rem;">
                <input type="checkbox" name="reset_cleanup_sequences" id="js-cleanup-reset-sequences" value="1">
                <span>Import-Log-/Mapping-IDs optional mit zur&uuml;cksetzen, wenn die jeweilige Import-Tabelle nach der Bereinigung leer ist</span>
            </label>
            <p class="ci-options__hint" style="margin:0.5rem 0 0;">Bei Verlaufsbereinigungen betrifft das die Import-Logs, Mappings und Meta-Eintr&auml;ge. Bei Bereichsbereinigungen werden leere Mapping-Tabellen wieder auf die erste ID gesetzt.</p>
        </div>
        <div class="ci-modal__footer">
            <button type="button" class="ci-btn ci-btn--ghost" data-close-cleanup-modal>Abbrechen</button>
            <form method="POST" id="js-cleanup-form">
                <input type="hidden" name="cms_admin_action" id="js-cleanup-action" value="">
                <input type="hidden" name="_cleanup_nonce" value="<?php echo $esc_nonce_cleanup; ?>">
                <button type="submit" class="ci-btn ci-btn--danger" id="js-cleanup-submit">Jetzt ausf&uuml;hren</button>
            </form>
        </div>
    </div>
</div>