<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_SEO_VIEW')) {
    exit;
}

$redirects = $data['redirects'] ?? [];
$stats     = $data['stats'] ?? [];
$targets   = $data['targets'] ?? ['pages' => [], 'posts' => [], 'hubs' => []];
$sites     = $data['sites'] ?? [];
$alertDetails = is_array($alert['details'] ?? null) ? $alert['details'] : [];
?>

<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">SEO</div>
                <h2 class="page-title mb-1">Weiterleitungen</h2>
                <div class="content-listing-header__meta">
                    <span>Regeln: <?= (int)($stats['redirects_total'] ?? 0) ?></span>
                    <span>Aktiv: <?= (int)($stats['redirects_active'] ?? 0) ?></span>
                </div>
            </div>
            <div class="admin-section-toolbar__actions">
                <a href="<?= htmlspecialchars('/admin/not-found-monitor') ?>" class="btn btn-outline-primary">Zum 404-Monitor</a>
                <a href="<?= htmlspecialchars('/admin/redirect-manager?export=csv') ?>" class="btn btn-outline-primary">CSV exportieren</a>
                <button type="button" class="btn btn-primary js-create-redirect">Erweitert anlegen</button>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page">
    <div class="container-xl admin-redesign-shell">
        <?php if (!empty($alert)): ?>
            <?php
            $alertData = is_array($alert ?? null) ? $alert : [];
            if ($alertDetails !== []) {
                $alertData['details'] = $alertDetails;
            }
            require dirname(__DIR__) . '/partials/flash-alert.php';
            ?>
        <?php endif; ?>

        <?php require __DIR__ . '/subnav.php'; ?>

        <div class="row row-deck row-cards mb-4">
            <div class="col-sm-6 col-lg-3">
                <div class="card"><div class="card-body"><div class="subheader">Weiterleitungen</div><div class="h1 mb-0"><?= (int)($stats['redirects_total'] ?? 0) ?></div></div></div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card"><div class="card-body"><div class="subheader">Aktiv</div><div class="h1 mb-0 text-success"><?= (int)($stats['redirects_active'] ?? 0) ?></div></div></div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card"><div class="card-body"><div class="subheader">Erkannte 404-Pfade</div><div class="h1 mb-0 text-warning"><?= (int)($stats['not_found_total'] ?? 0) ?></div></div></div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card"><div class="card-body"><div class="subheader">404-Hits</div><div class="h1 mb-0"><?= (int)($stats['not_found_hits'] ?? 0) ?></div></div></div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <div>
                    <h3 class="card-title mb-1">Neue Weiterleitung</h3>
                    <div class="text-secondary small">Schnell eine Regel anlegen – ideal für direkte Korrekturen oder bekannte Alt-URLs.</div>
                </div>
            </div>
            <div class="card-body">
                <form method="post" class="row g-3 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                    <input type="hidden" name="action" value="save_redirect">
                    <input type="hidden" name="redirect_id" value="0">
                    <input type="hidden" name="target_kind" value="manual">
                    <input type="hidden" name="redirect_type" value="301">
                    <input type="hidden" name="is_active" value="1">
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label" for="quick-source-path">Quellpfad</label>
                        <input type="text" class="form-control" id="quick-source-path" name="source_path" placeholder="/alter-pfad" required>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label" for="quick-site-scope">Site</label>
                        <select class="form-select" id="quick-site-scope" name="site_scope">
                            <?php foreach ($sites as $site): ?>
                                <option value="<?= htmlspecialchars((string)($site['value'] ?? '')) ?>"><?= htmlspecialchars((string)($site['label'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-4 col-md-8">
                        <label class="form-label" for="quick-target-url">Ziel</label>
                        <input type="text" class="form-control" id="quick-target-url" name="target_url_manual" placeholder="/neuer-pfad oder https://..." required>
                    </div>
                    <div class="col-lg-2 col-md-4 d-grid">
                        <button type="submit" class="btn btn-primary">301 speichern</button>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="quick-notes">Notiz</label>
                        <input type="text" class="form-control" id="quick-notes" name="notes" placeholder="z. B. Relaunch, Tippfehler, Kampagnen-URL">
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <div>
                    <h3 class="card-title mb-1">CSV-Import</h3>
                    <div class="text-secondary small">Weiterleitungen massenweise anlegen oder anpassen – z. B. mit dem CSV-Export des 404-Monitors oder dem Export dieser Seite.</div>
                </div>
            </div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                    <input type="hidden" name="action" value="import_redirects">
                    <div class="col-md-8 col-lg-6">
                        <label class="form-label" for="redirect-csv-file">CSV-Datei</label>
                        <input type="file" class="form-control" id="redirect-csv-file" name="redirect_csv" accept=".csv,text/csv" required>
                        <div class="form-hint">
                            Spalten: <code>redirect_id;source_path;site_scope;target_url;redirect_type;is_active;notes</code> (maximal 2 MB / 5.000 Zeilen).
                            Zeilen ohne <code>target_url</code> werden übersprungen. Vorhandene Regeln werden über <code>redirect_id</code> oder Quelle + Site aktualisiert, alle übrigen neu angelegt.
                            Als Ziel genügt ein Slug wie <code>neuer-slug</code>.
                        </div>
                    </div>
                    <div class="col-md-4 col-lg-3">
                        <button type="submit" class="btn btn-primary w-100">CSV importieren</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">Weiterleitungen per Slug löschen</h3>
            </div>
            <div class="card-body">
                <form
                    method="post"
                    class="row g-3 align-items-end"
                    data-confirm-title="Slug-Regeln löschen?"
                    data-confirm-message="Alle Weiterleitungen mit diesem Slug-Muster löschen?"
                    data-confirm-text="Löschen"
                    data-confirm-class="btn-danger"
                    data-confirm-status-class="bg-danger"
                >
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                    <input type="hidden" name="action" value="delete_redirects_by_slug">
                    <div class="col-md-8 col-lg-6">
                        <label class="form-label" for="redirect-slug-filter">Slug</label>
                        <input
                            type="text"
                            class="form-control"
                            id="redirect-slug-filter"
                            name="slug_filter"
                            placeholder="z. B. kontakt oder blog"
                            required
                        >
                        <div class="form-hint">Löscht Weiterleitungen, deren Quellpfad den Slug als eigenes Segment enthält – z. B. <code>*/kontakt/*</code>, <code>/kontakt</code> oder <code>/de/kontakt/team</code>.</div>
                    </div>
                    <div class="col-md-4 col-lg-3">
                        <button
                            type="submit"
                            class="btn btn-outline-danger w-100 admin-danger-outline-btn"
                        >Slug-Regeln löschen</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
                <div>
                    <h3 class="card-title mb-1">Aktive Regeln</h3>
                    <div class="text-secondary small">Site-spezifische Regeln werden zuerst geprüft, globale Regeln dienen als Fallback.</div>
                </div>
                <a href="<?= htmlspecialchars('/admin/not-found-monitor') ?>" class="btn btn-outline-secondary btn-sm">404-Einträge prüfen</a>
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Quelle</th>
                            <th>Site</th>
                            <th>Ziel</th>
                            <th>Typ</th>
                            <th>Hits</th>
                            <th>Status</th>
                            <th class="w-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($redirects)): ?>
                            <tr><td colspan="7" class="text-center text-secondary py-4">Noch keine Weiterleitungen angelegt.</td></tr>
                        <?php else: ?>
                            <?php foreach ($redirects as $redirect): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars((string)$redirect['source_path']) ?></code></td>
                                    <td><span class="badge bg-azure-lt text-azure"><?= htmlspecialchars((string)($redirect['site_scope_label'] ?? 'Global / alle Sites')) ?></span></td>
                                    <td class="text-secondary"><?= htmlspecialchars((string)$redirect['target_url']) ?></td>
                                    <td><span class="badge <?= (int)$redirect['redirect_type'] === 301 ? 'bg-green' : 'bg-yellow' ?>"><?= (int)$redirect['redirect_type'] ?></span></td>
                                    <td><?= (int)($redirect['hits'] ?? 0) ?></td>
                                    <td><?= (int)$redirect['is_active'] === 1 ? '<span class="badge bg-success">Aktiv</span>' : '<span class="badge bg-secondary">Inaktiv</span>' ?></td>
                                    <td>
                                        <div class="dropdown">
                                            <button type="button" class="btn btn-ghost-secondary btn-icon btn-sm" data-bs-toggle="dropdown" aria-label="Aktionen öffnen">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M12 19m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M12 5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/></svg>
                                            </button>
                                            <div class="dropdown-menu dropdown-menu-end">
                                                <button type="button" class="dropdown-item js-edit-redirect" data-redirect="<?= htmlspecialchars((string) json_encode($redirect, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES) ?>">Bearbeiten</button>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                                                    <input type="hidden" name="action" value="toggle_redirect">
                                                    <input type="hidden" name="id" value="<?= (int)$redirect['id'] ?>">
                                                    <button type="submit" class="dropdown-item"><?= (int)$redirect['is_active'] === 1 ? 'Deaktivieren' : 'Aktivieren' ?></button>
                                                </form>
                                                <form
                                                    method="post"
                                                    class="d-inline"
                                                    data-confirm-title="Weiterleitung löschen?"
                                                    data-confirm-message="Diese Weiterleitung wird dauerhaft entfernt. Wirklich löschen?"
                                                    data-confirm-text="Löschen"
                                                    data-confirm-class="btn-danger"
                                                    data-confirm-status-class="bg-danger"
                                                >
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                                                    <input type="hidden" name="action" value="delete_redirect">
                                                    <input type="hidden" name="id" value="<?= (int)$redirect['id'] ?>">
                                                    <button type="submit" class="dropdown-item text-danger">Löschen</button>
                                                </form>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script type="application/json" id="seo-redirect-manager-config"><?php echo json_encode([
    'targets' => $targets,
    'sites' => $sites,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); ?></script>

<div class="modal modal-blur fade" id="redirectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                <input type="hidden" name="action" value="save_redirect">
                <input type="hidden" name="redirect_id" id="redirect-id" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="redirect-modal-title">Weiterleitung anlegen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Quellpfad</label>
                            <input type="text" name="source_path" id="redirect-source" class="form-control" placeholder="/alter-pfad" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Site</label>
                            <select name="site_scope" id="redirect-site-scope" class="form-select">
                                <?php foreach ($sites as $site): ?>
                                    <option value="<?= htmlspecialchars((string)($site['value'] ?? '')) ?>"><?= htmlspecialchars((string)($site['label'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mt-0">
                        <div class="col-md-4">
                            <label class="form-label">Zieltyp</label>
                            <select name="target_kind" id="redirect-target-kind" class="form-select">
                                <option value="manual">Freie URL / externer Slug</option>
                                <option value="page">Seite</option>
                                <option value="post">Beitrag</option>
                                <option value="hub">Hub Site</option>
                            </select>
                        </div>
                        <div class="col-md-8" id="redirect-target-manual-group">
                            <label class="form-label">Ziel-URL / Slug</label>
                            <input type="text" name="target_url_manual" id="redirect-target-manual" class="form-control" placeholder="/neuer-pfad oder https://..." required>
                        </div>
                        <div class="col-md-8" id="redirect-target-page-group" hidden>
                            <label class="form-label">Ziel-Seite</label>
                            <select name="target_page_id" id="redirect-target-page" class="form-select">
                                <option value="">Bitte Seite wählen</option>
                                <?php foreach (($targets['pages'] ?? []) as $pageTarget): ?>
                                    <option value="<?= (int)($pageTarget['id'] ?? 0) ?>"><?= htmlspecialchars((string)($pageTarget['label'] ?? '')) ?> — /<?= htmlspecialchars((string)($pageTarget['slug'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8" id="redirect-target-post-group" hidden>
                            <label class="form-label">Ziel-Beitrag</label>
                            <select name="target_post_id" id="redirect-target-post" class="form-select">
                                <option value="">Bitte Beitrag wählen</option>
                                <?php foreach (($targets['posts'] ?? []) as $postTarget): ?>
                                    <option value="<?= (int)($postTarget['id'] ?? 0) ?>"><?= htmlspecialchars((string)($postTarget['label'] ?? '')) ?> — <?= htmlspecialchars((string)($postTarget['url'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8" id="redirect-target-hub-group" hidden>
                            <label class="form-label">Ziel-Hub-Site</label>
                            <select name="target_hub_id" id="redirect-target-hub" class="form-select">
                                <option value="">Bitte Hub Site wählen</option>
                                <?php foreach (($targets['hubs'] ?? []) as $hubTarget): ?>
                                    <option value="<?= (int)($hubTarget['id'] ?? 0) ?>"><?= htmlspecialchars((string)($hubTarget['label'] ?? '')) ?> — <?= htmlspecialchars((string)($hubTarget['url'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <input type="hidden" name="target_url" id="redirect-target-hidden" value="">
                    <div class="row g-3 mt-0">
                        <div class="col-md-4">
                            <label class="form-label">Typ</label>
                            <select name="redirect_type" id="redirect-type" class="form-select">
                                <option value="301">301 permanent</option>
                                <option value="302">302 temporär</option>
                            </select>
                        </div>
                        <div class="col-md-8 d-flex align-items-end">
                            <label class="form-check form-switch mb-3">
                                <input type="checkbox" name="is_active" id="redirect-active" class="form-check-input" checked>
                                <span class="form-check-label">Sofort aktivieren</span>
                            </label>
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Notiz</label>
                        <textarea name="notes" id="redirect-notes" class="form-control" rows="3" placeholder="z. B. alte Kampagnen-URL, Relaunch, Tippfehler, Hinweis aus 404-Monitor"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn me-auto" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>
