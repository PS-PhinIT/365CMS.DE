<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * View: Einstellungen › Admin-Menü (Übersicht)
 *
 * @var array<string, mixed>|null $adminMenuAlert
 * @var string $adminMenuCsrfToken
 * @var string $adminMenuProfileSetting  aktives Profil (classic|structured|custom)
 * @var array<string, array{key:string, label:string, available:bool, top_level:int, preview:string}> $adminMenuProfiles
 * @var list<array{key:string, label:string, url:string, has_layout:bool, version:int, updated_at:string, updated_by:int|null, updated_by_name:string}> $adminMenuScopes
 * @var array{key:string, label:string, url:string, has_layout:bool, version:int, updated_at:string, updated_by:int|null, updated_by_name:string} $adminMenuGlobalRow
 * @var bool $adminMenuSiteScopesEnabled
 * @var list<array<string, mixed>> $adminMenuSiteOverrides  Sites mit eigenem Layout
 * @var list<string> $adminMenuLockedLabels
 * @var int $adminMenuMaxUploadKb
 */

use CMS\Services\AdminMenu\LayoutValidator;
use CMS\Services\AdminMenu\ProfileLayouts;

$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$actionUrl = cms_admin_menu_settings_url();
$editorUrl = static fn (string $scope = '', string $start = ''): string => cms_admin_menu_settings_url(['view' => 'editor', 'scope' => $scope, 'start' => $start]);
$exportUrl = static fn (string $scope = ''): string => cms_admin_menu_settings_url(['action' => 'export', 'scope' => $scope]);
$formatDate = static function (string $value): string {
    if ($value === '') {
        return '';
    }
    try {
        return (new \DateTimeImmutable($value))->format('d.m.Y, H:i');
    } catch (\Throwable) {
        return $value;
    }
};
$standLabel = static function (array $row) use ($formatDate): string {
    $parts = array_filter([$formatDate((string) $row['updated_at']), (string) $row['updated_by_name']], static fn (string $part): bool => $part !== '');

    return implode(' · ', $parts);
};
$profileDescriptions = ProfileLayouts::DESCRIPTIONS;
$activeLabel = $adminMenuProfiles[$adminMenuProfileSetting]['label'] ?? $adminMenuProfileSetting;
$globalStored = $adminMenuGlobalRow['has_layout'];
$scopeOptions = $adminMenuSiteScopesEnabled ? $adminMenuScopes : [$adminMenuGlobalRow];
$scopeOptionLabel = static fn (array $row): string => $row['key'] === '' ? 'Global (alle Sites)' : 'Site: ' . $row['label'];
?>

<div class="container-xl cms-settings-page cms-am-page">
    <div class="page-header d-print-none mb-4">
        <div class="row align-items-center">
            <div class="col-auto">
                <div class="page-pretitle">Einstellungen</div>
                <h2 class="page-title">
                    <i class="ti ti-layout-sidebar icon me-2" aria-hidden="true"></i>
                    Admin-Menü
                </h2>
            </div>
        </div>
    </div>

    <div id="settings-alert-region">
        <?php
        $alertData = is_array($adminMenuAlert ?? null) ? $adminMenuAlert : [];
        $alertMarginClass = 'mb-4';
        require dirname(__DIR__) . '/partials/flash-alert.php';
        ?>
    </div>

    <div class="cms-settings-toolbar">
        <?php $settingsTabsActive = 'admin-menu'; require __DIR__ . '/partials-tabs.php'; ?>
    </div>

    <section class="card cms-am-profiles mb-4" aria-labelledby="cms-am-profiles-title">
        <div class="card-header">
            <h3 class="card-title" id="cms-am-profiles-title">Menü-Profil</h3>
            <div class="card-actions">
                <span class="cms-am-chip cms-am-chip--active">Aktiv: <?= $esc($activeLabel) ?></span>
            </div>
        </div>

        <form method="post" action="<?= $esc($actionUrl) ?>" class="card-body" id="cms-am-profile-form" data-am-profile-form>
            <input type="hidden" name="csrf_token" value="<?= $esc($adminMenuCsrfToken) ?>">
            <input type="hidden" name="action" value="profile">

            <p class="cms-am-lead">
                Das Profil legt Gruppen und Reihenfolge der Seitenleiste für alle Administratoren fest. Jede Person sieht
                nur Einträge, für die sie Rechte hat und deren Modul aktiv ist.
                <?php if ($adminMenuSiteOverrides !== []): ?>
                    <strong><?= $esc(count($adminMenuSiteOverrides) === 1 ? 'Eine Site nutzt' : count($adminMenuSiteOverrides) . ' Sites nutzen') ?> ein eigenes Layout</strong> – dort gilt diese Auswahl nicht.
                <?php endif; ?>
            </p>

            <div class="cms-am-profiles__grid">
                <fieldset class="cms-am-profiles__choices">
                    <legend class="visually-hidden">Menü-Profil wählen</legend>
                    <div class="form-selectgroup form-selectgroup-boxes d-flex flex-column">
                        <?php foreach ($adminMenuProfiles as $profileKey => $profile): ?>
                            <?php
                            $isActive = $profileKey === $adminMenuProfileSetting;
                            $descId = 'cms-am-profile-' . $profileKey . '-desc';
                            ?>
                            <label class="form-selectgroup-item flex-fill">
                                <input type="radio" name="profile" value="<?= $esc($profileKey) ?>" class="form-selectgroup-input"
                                       data-am-profile-radio aria-describedby="<?= $esc($descId) ?>"
                                       <?= $isActive ? 'checked' : '' ?><?= !$profile['available'] ? ' disabled' : '' ?>>
                                <span class="form-selectgroup-label d-flex align-items-start p-3 text-start">
                                    <span class="me-3 pt-1"><span class="form-selectgroup-check"></span></span>
                                    <span class="form-selectgroup-label-content d-block">
                                        <span class="cms-am-profile__title">
                                            <?= $esc($profile['label']) ?>
                                            <?php if ($isActive): ?>
                                                <span class="cms-am-chip cms-am-chip--active">aktiv</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="cms-am-profile__desc d-block" id="<?= $esc($descId) ?>">
                                            <?= $esc($profileDescriptions[$profileKey] ?? '') ?>
                                            <?php if (!$profile['available']): ?>
                                                <span class="d-block mt-1">Noch kein eigenes Layout gespeichert – zuerst im Menü-Editor anlegen.</span>
                                            <?php endif; ?>
                                        </span>
                                        <?php if ($profile['available']): ?>
                                            <span class="cms-am-profile__meta d-block"><?= $esc($profile['top_level'] === 1 ? '1 Hauptpunkt' : $profile['top_level'] . ' Hauptpunkte') ?> (mit allen Modulen dieser Installation)</span>
                                        <?php endif; ?>
                                    </span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <div class="cms-am-profiles__preview" data-am-previews>
                    <?php foreach ($adminMenuProfiles as $profileKey => $profile): ?>
                        <figure class="cms-am-preview" data-am-preview="<?= $esc($profileKey) ?>"<?= $profileKey !== $adminMenuProfileSetting ? ' hidden' : '' ?>>
                            <figcaption class="cms-am-preview__caption">
                                <span>Vorschau: <?= $esc($profile['label']) ?></span>
                                <?php if ($profile['available']): ?>
                                    <a class="cms-am-preview__action" href="<?= $esc($profileKey === ProfileLayouts::CUSTOM ? $editorUrl() : $editorUrl('', $profileKey)) ?>">
                                        <i class="ti ti-<?= $profileKey === ProfileLayouts::CUSTOM ? 'pencil' : 'copy' ?>" aria-hidden="true"></i>
                                        <?= $profileKey === ProfileLayouts::CUSTOM ? 'Im Menü-Editor bearbeiten' : 'Als Vorlage für ein eigenes Layout' ?>
                                    </a>
                                <?php endif; ?>
                            </figcaption>
                            <?php if ($profile['available']): ?>
                                <?php /* Rahmen scrollt (fokussierbar), nur die Navigation darin ist inert – ein inerter Scroll-Container ließe sich nicht scrollen. */ ?>
                                <div class="cms-am-preview__frame" tabindex="0" role="region" aria-label="<?= $esc('Vorschau der Seitenleiste: ' . $profile['label']) ?>">
                                    <div class="cms-am-preview__nav" inert>
                                        <?= $profile['preview'] /* serverseitig gerendert und escaped (sidebar-nav.php) */ ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="cms-am-preview__empty">
                                    <i class="ti ti-layout-sidebar-left-expand" aria-hidden="true"></i>
                                    <p>Noch kein eigenes Layout gespeichert.</p>
                                    <a class="btn btn-primary" href="<?= $esc($editorUrl('', $adminMenuProfileSetting !== ProfileLayouts::CUSTOM ? $adminMenuProfileSetting : '')) ?>">
                                        <i class="ti ti-plus me-1" aria-hidden="true"></i> Eigenes Layout anlegen
                                    </a>
                                </div>
                            <?php endif; ?>
                        </figure>
                    <?php endforeach; ?>
                </div>
            </div>
        </form>

        <div class="card-footer cms-am-profiles__footer">
            <button type="submit" form="cms-am-profile-form" class="btn btn-primary">
                <i class="ti ti-check me-1" aria-hidden="true"></i> Profil speichern
            </button>
            <a class="btn btn-outline-secondary" href="<?= $esc($editorUrl()) ?>">
                <i class="ti ti-layout-sidebar-left-expand me-1" aria-hidden="true"></i> Menü-Editor öffnen
            </a>
            <?php if ($globalStored): ?>
                <form method="post" action="<?= $esc($actionUrl) ?>" class="ms-auto"
                      data-confirm-title="Eigenes Layout verwerfen?"
                      data-confirm-message="<?= $esc('Das eigene globale Layout (Version ' . $adminMenuGlobalRow['version'] . ') wird gelöscht. Aktiv ist danach wieder das Profil, auf dem es beruht. Ein vorheriger JSON-Export kann es wiederherstellen.') ?>"
                      data-confirm-text="Layout verwerfen"
                      data-confirm-class="btn-danger"
                      data-confirm-status-class="bg-danger">
                    <input type="hidden" name="csrf_token" value="<?= $esc($adminMenuCsrfToken) ?>">
                    <input type="hidden" name="action" value="reset">
                    <input type="hidden" name="scope" value="">
                    <button type="submit" class="btn btn-outline-danger">
                        <i class="ti ti-trash me-1" aria-hidden="true"></i> Eigenes Layout verwerfen
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($adminMenuSiteScopesEnabled): ?>
        <section class="card cms-am-scopes mb-4" aria-labelledby="cms-am-scopes-title">
            <div class="card-header">
                <h3 class="card-title" id="cms-am-scopes-title">Layouts je Site</h3>
            </div>
            <div class="table-responsive">
                <table class="table card-table table-vcenter cms-am-scopes__table">
                    <caption class="visually-hidden">Menü-Layouts: global und je Site aus „MultiSites“. Ohne eigenes Layout nutzt eine Site die globale Einstellung.</caption>
                    <thead>
                        <tr>
                            <th scope="col">Bereich</th>
                            <th scope="col">Layout</th>
                            <th scope="col">Stand</th>
                            <th scope="col" class="text-end">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($adminMenuScopes as $scopeRow): ?>
                            <?php
                            $isGlobal = $scopeRow['key'] === '';
                            $scopeName = $isGlobal ? 'Global (alle Sites)' : $scopeRow['label'];
                            $layoutText = $scopeRow['has_layout']
                                ? 'Eigenes Layout · Version ' . $scopeRow['version']
                                : ($isGlobal ? 'Profil „' . $activeLabel . '“' : 'Globale Einstellung');
                            ?>
                            <tr>
                                <th scope="row">
                                    <span class="cms-am-scopes__name">
                                        <?php if (!$isGlobal): ?>
                                            <span class="cms-site-swatch" data-site-swatch="<?= $esc($scopeRow['key']) ?>" aria-hidden="true"></span>
                                        <?php endif; ?>
                                        <?= $esc($scopeName) ?>
                                    </span>
                                    <?php if ($scopeRow['url'] !== ''): ?>
                                        <span class="cms-am-scopes__url"><?= $esc(preg_replace('~^https?://~i', '', $scopeRow['url'])) ?></span>
                                    <?php endif; ?>
                                </th>
                                <td><?= $esc($layoutText) ?></td>
                                <td class="cms-am-scopes__stand"><?= $scopeRow['has_layout'] ? $esc($standLabel($scopeRow)) : '<span class="text-secondary">–</span>' ?></td>
                                <td>
                                    <div class="cms-am-scopes__actions">
                                        <a class="btn btn-icon btn-outline-primary" href="<?= $esc($editorUrl($scopeRow['key'])) ?>"
                                           aria-label="<?= $esc('Layout für ' . $scopeName . ' im Menü-Editor bearbeiten') ?>" title="Im Menü-Editor bearbeiten">
                                            <i class="ti ti-pencil" aria-hidden="true"></i>
                                        </a>
                                        <a class="btn btn-icon btn-outline-secondary" href="<?= $esc($exportUrl($scopeRow['key'])) ?>"
                                           aria-label="<?= $esc('Layout für ' . $scopeName . ' als JSON exportieren') ?>" title="Als JSON exportieren">
                                            <i class="ti ti-download" aria-hidden="true"></i>
                                        </a>
                                        <?php if ($scopeRow['has_layout']): ?>
                                            <form method="post" action="<?= $esc($actionUrl) ?>"
                                                  data-confirm-title="Layout zurücksetzen?"
                                                  data-confirm-message="<?= $esc($isGlobal
                                                      ? 'Das eigene globale Layout wird gelöscht. Aktiv ist danach wieder das Profil, auf dem es beruht.'
                                                      : 'Das eigene Layout für „' . $scopeName . '“ wird gelöscht. Die Site nutzt danach wieder die globale Einstellung.') ?>"
                                                  data-confirm-text="Zurücksetzen"
                                                  data-confirm-class="btn-danger"
                                                  data-confirm-status-class="bg-danger">
                                                <input type="hidden" name="csrf_token" value="<?= $esc($adminMenuCsrfToken) ?>">
                                                <input type="hidden" name="action" value="reset">
                                                <input type="hidden" name="scope" value="<?= $esc($scopeRow['key']) ?>">
                                                <button type="submit" class="btn btn-icon btn-outline-danger"
                                                        aria-label="<?= $esc('Layout für ' . $scopeName . ' zurücksetzen') ?>" title="Zurücksetzen">
                                                    <i class="ti ti-arrow-back-up" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer cms-am-scopes__footer">
                Ein Site-Layout gilt, solange diese Site im Site-Umschalter gewählt ist. Plugin-Menüs erscheinen dort nur, wenn das Plugin der Site zugewiesen ist.
            </div>
        </section>
    <?php endif; ?>

    <section class="card cms-am-exchange mb-4" aria-labelledby="cms-am-exchange-title">
        <div class="card-header">
            <h3 class="card-title" id="cms-am-exchange-title">Import und Export</h3>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-12 col-lg-6">
                    <form method="get" action="/admin/settings" class="cms-am-exchange__form">
                        <input type="hidden" name="tab" value="admin-menu">
                        <input type="hidden" name="action" value="export">
                        <h4 class="cms-am-exchange__title">Exportieren</h4>
                        <p class="cms-am-exchange__text">Lädt ein Layout als JSON-Datei herunter, etwa zur Sicherung oder für eine andere Installation. Ohne eigenes Layout wird das dort wirksame Profil exportiert.</p>
                        <?php if (count($scopeOptions) > 1): ?>
                            <div class="mb-3">
                                <label class="form-label" for="cms-am-export-scope">Bereich</label>
                                <select class="form-select" id="cms-am-export-scope" name="scope">
                                    <?php foreach ($scopeOptions as $scopeRow): ?>
                                        <option value="<?= $esc($scopeRow['key']) ?>"><?= $esc($scopeOptionLabel($scopeRow)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="ti ti-download me-1" aria-hidden="true"></i> JSON exportieren
                        </button>
                    </form>
                </div>
                <div class="col-12 col-lg-6">
                    <form method="post" action="<?= $esc($actionUrl) ?>" enctype="multipart/form-data" class="cms-am-exchange__form"
                          data-confirm-title="Layout importieren?"
                          data-confirm-message="Das importierte Layout ersetzt das eigene Layout des gewählten Bereichs. Ein globaler Import aktiviert das Profil „Eigenes Layout“."
                          data-confirm-text="Importieren"
                          data-confirm-class="btn-primary">
                        <input type="hidden" name="csrf_token" value="<?= $esc($adminMenuCsrfToken) ?>">
                        <input type="hidden" name="action" value="import">
                        <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int) LayoutValidator::MAX_JSON_BYTES ?>">
                        <h4 class="cms-am-exchange__title">Importieren</h4>
                        <p class="cms-am-exchange__text">Unbekannte oder doppelte Einträge werden übersprungen, gesperrte Einträge bleiben erhalten. Wer vorher prüfen möchte, importiert im Menü-Editor – dort wird erst beim Speichern übernommen.</p>
                        <?php if (count($scopeOptions) > 1): ?>
                            <div class="mb-3">
                                <label class="form-label" for="cms-am-import-scope">Bereich</label>
                                <select class="form-select" id="cms-am-import-scope" name="scope">
                                    <?php foreach ($scopeOptions as $scopeRow): ?>
                                        <option value="<?= $esc($scopeRow['key']) ?>"><?= $esc($scopeOptionLabel($scopeRow)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="scope" value="">
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label required" for="cms-am-import-file">JSON-Datei</label>
                            <input type="file" class="form-control" id="cms-am-import-file" name="layout_file" accept=".json,application/json" required
                                   aria-describedby="cms-am-import-file-hint">
                            <div class="form-hint" id="cms-am-import-file-hint">Export aus dem Menü-Editor oder von dieser Seite, höchstens <?= (int) $adminMenuMaxUploadKb ?> KB.</div>
                        </div>
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="ti ti-upload me-1" aria-hidden="true"></i> Importieren und speichern
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <section class="card cms-am-help" aria-labelledby="cms-am-help-title">
        <div class="card-header">
            <h3 class="card-title" id="cms-am-help-title">So funktioniert das Admin-Menü</h3>
        </div>
        <div class="card-body">
            <ul class="cms-am-help__list">
                <li><strong>Kachelseiten:</strong> Gruppen im Anzeigemodus „Kacheln“ (etwa Einstellungen oder Website-Zustand) sind in der Seitenleiste ein einzelner Link; ihre Seiten erscheinen dort als Kacheln.</li>
                <li><strong>Plugins:</strong> Plugin-Menüs erscheinen automatisch – an ihrem Wunschplatz, sonst in der Auffanggruppe des Layouts (Klassisch: Plugins, Strukturiert: Erweitern).</li>
                <li><strong>Neue Seiten:</strong> Einträge, die ein eigenes Layout noch nicht kennt, ordnet das CMS selbst ein. Nur ausdrücklich ausgeblendete Einträge bleiben verborgen.</li>
                <?php if ($adminMenuLockedLabels !== []): ?>
                    <li><strong>Immer erreichbar:</strong> <?= $esc(implode(', ', $adminMenuLockedLabels)) ?> – diese Einträge lassen sich nicht ausblenden oder entfernen.</li>
                <?php endif; ?>
                <li><strong>Protokoll:</strong> Profilwechsel, Speichern, Import und Zurücksetzen werden im Audit-Log festgehalten.</li>
            </ul>
        </div>
    </section>
</div>
