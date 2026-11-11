<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * View: Einstellungen › Admin-Menü › Menü-Editor
 *
 * Das Layout bearbeitet admin-sidebar-editor.js im Browser; gespeichert wird per Formular (POST action=save).
 * Die Vorschau rendert der Server (POST action=preview, JSON) mit denselben Bausteinen wie die Seitenleiste.
 *
 * @var array<string, mixed>|null $adminMenuAlert
 * @var string $adminMenuCsrfToken
 * @var string $adminMenuScope
 * @var array<string, mixed>|null $adminMenuStored     gespeichertes Layout des Bereichs (layout, version, …)
 * @var array<string, mixed>|null $adminMenuScopeRow   Zeile aus AdminMenuService::scopeOverview()
 * @var array<string, mixed> $adminMenuEditorData      Startdaten für admin-sidebar-editor.js
 */

use CMS\Services\AdminMenu\LayoutValidator;
use CMS\Services\AdminMenu\ProfileLayouts;

$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$actionUrl = cms_admin_menu_settings_url();
$overviewUrl = cms_admin_menu_settings_url();
$scopeName = (string) $adminMenuEditorData['scopeLabel'];
$hasStored = $adminMenuStored !== null;
$isGlobal = $adminMenuScope === '';
$storedStand = '';
if ($hasStored && is_array($adminMenuScopeRow)) {
    try {
        $storedStand = (new \DateTimeImmutable((string) $adminMenuScopeRow['updated_at']))->format('d.m.Y, H:i');
    } catch (\Throwable) {
        $storedStand = (string) $adminMenuScopeRow['updated_at'];
    }
    if ((string) ($adminMenuScopeRow['updated_by_name'] ?? '') !== '') {
        $storedStand .= ' · ' . $adminMenuScopeRow['updated_by_name'];
    }
}
$editorJson = json_encode(
    $adminMenuEditorData,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
);
?>

<div class="container-xl cms-settings-page cms-am-page cms-am-editor-page">
    <div class="page-header d-print-none mb-4">
        <div class="row align-items-center g-3">
            <div class="col-12 col-sm">
                <div class="page-pretitle">
                    <a href="<?= $esc($overviewUrl) ?>">Einstellungen › Admin-Menü</a>
                </div>
                <h2 class="page-title">
                    <i class="ti ti-layout-sidebar-left-expand icon me-2" aria-hidden="true"></i>
                    Menü-Editor
                    <span class="cms-am-chip cms-am-chip--scope"><?= $esc($scopeName) ?></span>
                    <span class="cms-unsaved-indicator" data-am-dirty hidden>
                        <span class="cms-unsaved-indicator__dot" aria-hidden="true"></span><span>Ungespeicherte Änderungen</span>
                    </span>
                </h2>
                <p class="cms-am-editor__subtitle">
                    <?php if ($hasStored): ?>
                        Eigenes Layout, Version <?= (int) ($adminMenuStored['version'] ?? 0) ?><?= $storedStand !== '' ? ' · ' . $esc($storedStand) : '' ?>.
                    <?php elseif ($isGlobal): ?>
                        Noch kein eigenes Layout gespeichert. Beim Speichern wird das Profil „Eigenes Layout“ aktiv.
                    <?php else: ?>
                        Noch kein eigenes Layout für diese Site. Bis zum Speichern gilt die globale Einstellung.
                    <?php endif; ?>
                </p>
            </div>
            <div class="col-12 col-sm-auto">
                <a class="btn btn-outline-secondary" href="<?= $esc($overviewUrl) ?>">
                    <i class="ti ti-arrow-left me-1" aria-hidden="true"></i> Zur Übersicht
                </a>
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

    <noscript>
        <div class="alert alert-warning mb-4" role="note">
            Der Menü-Editor benötigt JavaScript. Profilwahl, Import und Export funktionieren auch ohne –
            <a href="<?= $esc($overviewUrl) ?>">zur Übersicht</a>.
        </div>
    </noscript>

    <form method="post" action="<?= $esc($actionUrl) ?>" id="cms-am-save-form" class="cms-am-editor" data-am-editor hidden>
        <input type="hidden" name="csrf_token" value="<?= $esc($adminMenuCsrfToken) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="return" value="editor">
        <input type="hidden" name="scope" value="<?= $esc($adminMenuScope) ?>">
        <input type="hidden" name="origin" value="" data-am-origin>
        <input type="hidden" name="layout" value="" data-am-layout-field>

        <div class="cms-am-toolbar" role="toolbar" aria-label="Menü-Editor">
            <div class="cms-am-toolbar__group">
                <label class="visually-hidden" for="cms-am-start">Ausgangslage laden</label>
                <select class="form-select cms-am-toolbar__select" id="cms-am-start" data-am-start>
                    <option value="">Ausgangslage laden …</option>
                    <?php if ($hasStored): ?>
                        <option value="stored">Gespeichertes Layout</option>
                    <?php endif; ?>
                    <option value="<?= $esc(ProfileLayouts::CLASSIC) ?>">Profil „Klassisch“</option>
                    <option value="<?= $esc(ProfileLayouts::STRUCTURED) ?>">Profil „Strukturiert“</option>
                    <option value="empty">Leeres Layout</option>
                </select>
            </div>
            <div class="cms-am-toolbar__group">
                <button type="button" class="btn btn-outline-secondary" data-am-add-group>
                    <i class="ti ti-folder-plus me-1" aria-hidden="true"></i> Gruppe
                </button>
                <button type="button" class="btn btn-outline-secondary" data-am-add-separator>
                    <i class="ti ti-separator-horizontal me-1" aria-hidden="true"></i> Abschnitt
                </button>
            </div>
            <div class="cms-am-toolbar__group">
                <button type="button" class="btn btn-outline-secondary" data-am-export title="Aktuellen Stand als JSON-Datei herunterladen">
                    <i class="ti ti-download me-1" aria-hidden="true"></i> Export
                </button>
                <label class="btn btn-outline-secondary mb-0" title="JSON-Datei laden und prüfen – übernommen wird erst beim Speichern">
                    <i class="ti ti-upload me-1" aria-hidden="true"></i> Import
                    <input type="file" class="visually-hidden" accept=".json,application/json" data-am-import>
                </label>
            </div>
            <div class="cms-am-toolbar__group cms-am-toolbar__group--end">
                <button type="button" class="btn btn-outline-secondary" data-am-discard disabled>
                    <i class="ti ti-arrow-back me-1" aria-hidden="true"></i> Verwerfen
                </button>
                <button type="submit" class="btn btn-primary" data-am-save>
                    <i class="ti ti-device-floppy me-1" aria-hidden="true"></i> Speichern
                </button>
            </div>
        </div>

        <div class="cms-am-workspace">
            <section class="card cms-am-pane cms-am-pane--available" aria-labelledby="cms-am-available-title">
                <div class="card-header">
                    <h3 class="card-title" id="cms-am-available-title">Verfügbare Einträge</h3>
                    <div class="card-actions"><span class="cms-am-count" data-am-available-count></span></div>
                </div>
                <div class="card-body cms-am-pane__body">
                    <p class="cms-am-hint">
                        Nicht platzierte Einträge ordnet das CMS automatisch ein (siehe Vorschau). Verbergen lässt sich ein
                        Eintrag nur über <strong>Ausblenden</strong>.
                    </p>
                    <div class="cms-am-available__controls">
                        <div>
                            <label class="form-label" for="cms-am-search">Suchen</label>
                            <input type="search" class="form-control" id="cms-am-search" placeholder="Name, ID oder Adresse" autocomplete="off" data-am-search>
                        </div>
                        <div>
                            <label class="form-label" for="cms-am-target">Hinzufügen zu</label>
                            <select class="form-select" id="cms-am-target" data-am-target></select>
                        </div>
                    </div>
                    <div class="cms-am-available" data-am-available data-am-dropzone="available"></div>
                </div>
            </section>

            <section class="card cms-am-pane cms-am-pane--layout" aria-labelledby="cms-am-layout-title">
                <div class="card-header">
                    <h3 class="card-title" id="cms-am-layout-title">Layout</h3>
                    <div class="card-actions cms-am-pane__actions">
                        <span class="cms-am-count" data-am-layout-count></span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-am-expand-all hidden>
                            <i class="ti ti-chevrons-down me-1" aria-hidden="true"></i><span data-am-expand-all-label>Alle aufklappen</span>
                        </button>
                    </div>
                </div>
                <div class="card-body cms-am-pane__body">
                    <p class="cms-am-hint">
                        Ziehen und ablegen oder die Pfeile nutzen. Gruppen enthalten nur Einträge; Abschnitte gliedern die erste Ebene.
                        Über einer eingeklappten Gruppe kurz verweilen, um sie beim Ziehen aufzuklappen.
                    </p>
                    <p class="visually-hidden" id="cms-am-tree-help">Auswählen, um die Eigenschaften zu bearbeiten. Mit Alt und Pfeiltaste nach oben oder unten verschieben.</p>
                    <ol class="cms-am-tree" data-am-tree aria-label="Menü-Layout"></ol>

                    <fieldset class="cms-am-settings">
                        <legend class="cms-am-settings__title">Layout-Einstellungen</legend>
                        <div class="mb-3">
                            <label class="form-label" for="cms-am-base">Basisprofil</label>
                            <select class="form-select" id="cms-am-base" data-am-base aria-describedby="cms-am-base-hint">
                                <option value="<?= $esc(ProfileLayouts::CLASSIC) ?>">Klassisch</option>
                                <option value="<?= $esc(ProfileLayouts::STRUCTURED) ?>">Strukturiert</option>
                            </select>
                            <div class="form-hint" id="cms-am-base-hint">Nicht platzierte Core-Einträge landen in der gleichnamigen Gruppe dieses Profils, sofern das Layout sie enthält.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="cms-am-fallback">Auffanggruppe für Core-Einträge</label>
                            <select class="form-select" id="cms-am-fallback" data-am-fallback aria-describedby="cms-am-fallback-hint"></select>
                            <div class="form-hint" id="cms-am-fallback-hint">Hier erscheinen Core-Einträge, deren Gruppe im Layout fehlt; ohne Auffanggruppe die Gruppe „Weitere“. Plugin-Seiten stehen im Abschnitt „Aktive Plugins“, sofern du sie nicht selbst platzierst.</div>
                        </div>
                        <div class="mb-0">
                            <label class="form-label" for="cms-am-root">Start-Kachelseite</label>
                            <select class="form-select" id="cms-am-root" data-am-root aria-describedby="cms-am-root-hint"></select>
                            <div class="form-hint" id="cms-am-root-hint">Kachelgruppe, die <code>/admin/configuration</code> ohne Gruppenangabe zeigt.</div>
                        </div>
                    </fieldset>
                </div>
            </section>

            <div class="cms-am-side">
                <section class="card cms-am-pane cms-am-pane--inspector" aria-labelledby="cms-am-inspector-title">
                    <div class="card-header">
                        <h3 class="card-title" id="cms-am-inspector-title">Eigenschaften</h3>
                    </div>
                    <div class="card-body" data-am-inspector></div>
                </section>

                <section class="card cms-am-pane cms-am-pane--preview" aria-labelledby="cms-am-preview-title">
                    <div class="card-header">
                        <h3 class="card-title" id="cms-am-preview-title">Vorschau</h3>
                        <div class="card-actions"><span class="cms-am-preview-state" data-am-preview-state aria-live="polite"></span></div>
                    </div>
                    <div class="card-body cms-am-pane__preview">
                        <div class="cms-am-preview__frame" tabindex="0" role="region" aria-label="Vorschau der Seitenleiste">
                            <div class="cms-am-preview__nav" data-am-preview inert></div>
                        </div>
                        <ul class="cms-am-preview__warnings" data-am-preview-warnings hidden></ul>
                    </div>
                </section>
            </div>
        </div>
    </form>

    <?php if ($hasStored): ?>
        <form method="post" action="<?= $esc($actionUrl) ?>" class="cms-am-editor__reset" data-am-reset-form
              data-confirm-title="Layout zurücksetzen?"
              data-confirm-message="<?= $esc($isGlobal
                  ? 'Das eigene globale Layout wird gelöscht. Aktiv ist danach wieder das Profil, auf dem es beruht. Nicht gespeicherte Änderungen gehen verloren.'
                  : 'Das eigene Layout für ' . $scopeName . ' wird gelöscht. Die Site nutzt danach wieder die globale Einstellung. Nicht gespeicherte Änderungen gehen verloren.') ?>"
              data-confirm-text="Zurücksetzen"
              data-confirm-class="btn-danger"
              data-confirm-status-class="bg-danger">
            <input type="hidden" name="csrf_token" value="<?= $esc($adminMenuCsrfToken) ?>">
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="return" value="editor">
            <input type="hidden" name="scope" value="<?= $esc($adminMenuScope) ?>">
            <button type="submit" class="btn btn-outline-danger">
                <i class="ti ti-arrow-back-up me-1" aria-hidden="true"></i> Gespeichertes Layout zurücksetzen
            </button>
            <span class="cms-am-hint mb-0">Löscht das eigene Layout dieses Bereichs. Ein JSON-Export vorher sichert es.</span>
        </form>
    <?php endif; ?>

    <div class="visually-hidden" role="status" aria-live="polite" data-am-status></div>
    <datalist id="cms-am-icon-list" data-am-icon-list></datalist>
    <script type="application/json" id="cms-am-editor-data"><?= is_string($editorJson) ? $editorJson : '{}' ?></script>
</div>
