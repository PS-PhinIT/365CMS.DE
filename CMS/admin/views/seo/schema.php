<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_SEO_VIEW')) {
    exit;
}

$schema = $data['schema'] ?? [];
$settings = $schema['settings'] ?? [];
$distribution = $schema['distribution'] ?? [];
$supportedTypes = $schema['supported_types'] ?? [];
$renderer = $schema['renderer'] ?? [];
?>
<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">SEO</div>
                <h2 class="page-title">Strukturierte Daten</h2>
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

        <div class="row g-4 mb-4">
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header">
                        <h3 class="card-title">Schema-Standards</h3>
                    </div>
                    <div class="card-body">
                        <form method="post" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="save_schema_defaults">
                            <div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="organization_enabled" value="1" <?= !empty($settings['seo_schema_organization_enabled']) ? 'checked' : '' ?>><span class="form-check-label">Organization</span></label></div>
                            <div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="breadcrumb_enabled" value="1" <?= !empty($settings['seo_schema_breadcrumb_enabled']) ? 'checked' : '' ?>><span class="form-check-label">BreadcrumbList</span></label></div>
                            <div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="person_enabled" value="1" <?= !empty($settings['seo_schema_person_enabled']) ? 'checked' : '' ?>><span class="form-check-label">Person / Author</span></label></div>
                            <div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="faq_enabled" value="1" <?= !empty($settings['seo_schema_faq_enabled']) ? 'checked' : '' ?>><span class="form-check-label">FAQPage</span></label></div>
                            <div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="howto_enabled" value="1" <?= !empty($settings['seo_schema_howto_enabled']) ? 'checked' : '' ?>><span class="form-check-label">HowTo</span></label></div>
                            <div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="review_enabled" value="1" <?= !empty($settings['seo_schema_review_enabled']) ? 'checked' : '' ?>><span class="form-check-label">Review / Rating</span></label></div>
                            <div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="event_enabled" value="1" <?= !empty($settings['seo_schema_event_enabled']) ? 'checked' : '' ?>><span class="form-check-label">Event</span></label></div>
                            <div class="col-md-6"><label for="seoSchemaOrgName" class="form-label">Organisation</label><input id="seoSchemaOrgName" class="form-control" type="text" name="org_name" value="<?= htmlspecialchars((string) ($settings['seo_schema_org_name'] ?? '')) ?>"></div>
                            <div class="col-12"><label for="seoSchemaOrgLogo" class="form-label">Logo-URL</label><input id="seoSchemaOrgLogo" class="form-control" type="text" name="org_logo" value="<?= htmlspecialchars((string) ($settings['seo_schema_org_logo'] ?? '')) ?>"></div>
                            <div class="col-12"><button class="btn btn-primary" type="submit">Schema-Defaults speichern</button></div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h3 class="card-title mb-0">Renderer &amp; Migration</h3>
                        <span class="badge <?= (($renderer['status'] ?? '') === 'aktiv') ? 'bg-success' : 'bg-danger' ?>">
                            <?= htmlspecialchars((string) ($renderer['status'] ?? 'unbekannt')) ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="mb-2"><strong>Library:</strong> <?= htmlspecialchars((string) ($renderer['name'] ?? 'melbahja/seo')) ?></div>
                        <div class="mb-2"><strong>Schema-Modus:</strong> <code><?= htmlspecialchars((string) ($renderer['schema_mode'] ?? 'Thing')) ?></code></div>
                        <div class="mb-2"><strong>Breadcrumbs:</strong> <?= !empty($renderer['breadcrumbs_enabled']) ? 'aktiv' : 'deaktiviert' ?></div>
                        <div class="text-secondary small">JSON-LD wird nicht mehr manuell zusammengesetzt, sondern zentral über das lokale SEO-Bundle generiert.</div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Unterstützte Typen</h3>
                    </div>
                    <div class="card-body d-flex flex-wrap gap-2">
                        <?php foreach ($supportedTypes as $type): ?>
                            <span class="badge bg-azure-lt text-azure"><?= htmlspecialchars((string) $type) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h3 class="card-title">Typ-Verteilung</h3>
                    </div>
                    <div class="table-responsive">
                        <table class="table card-table table-vcenter">
                            <thead>
                                <tr>
                                    <th>Typ</th>
                                    <th class="text-end">Anzahl</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($distribution as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $row['schema_type']) ?></td>
                                        <td class="text-end"><?= (int) $row['count'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h3 class="card-title">Migration-Hinweise</h3>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="fw-bold">Neu gerenderte Typen</div>
                            <div class="text-secondary small">Mindestens `WebPage`, `Article`, `BreadcrumbList` und `Organization` werden jetzt direkt über `Melbahja\Seo\Schema` + `Thing` ausgegeben.</div>
                        </div>
                        <div class="mb-3">
                            <div class="fw-bold">Kein manuelles JSON-LD mehr</div>
                            <div class="text-secondary small">Die alte, stringbasierte JSON-LD-Erzeugung wurde durch das Asset-Bundle ersetzt. Das reduziert Formatierungs- und Escaping-Risiken deutlich.</div>
                        </div>
                        <div>
                            <div class="fw-bold">Admin-Folgen</div>
                            <div class="text-secondary small">Änderungen an Organisation, Logo und Breadcrumb-Schalter greifen direkt in die neue Schema-Ausgabe ein – ohne View-spezifische Sonderlogik.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
