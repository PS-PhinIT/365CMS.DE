<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * View: Einstellungen › MultiSites
 *
 * @var array<string, array{key:string,label:string,url:string,accent:string,accent_soft:string,accent_strong:string,hosts:list<string>}> $multisitesSites
 * @var bool $multisitesConfigured    true, sobald Sites gespeichert sind
 * @var string $multisitesCurrentKey  aktiver Site-Kontext
 * @var string $multisitesCsrfToken
 * @var array{label:string,url:string,accent:string,hosts:string} $formValues
 * @var array<string, string> $formErrors
 * @var string $formKey               Schlüssel der bearbeiteten Site ('' = neue Site)
 * @var array|null $alert
 */

$siteContext = \CMS\Services\AdminSiteContextService::getInstance();
$multisitesPath = \CMS\Services\AdminSiteContextService::ADMIN_PATH;
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$isEdit = $formKey !== '' && isset($multisitesSites[$formKey]);
$siteCount = count($multisitesSites);
$siteKeys = array_keys($multisitesSites);
$fieldClass = static fn (string $field): string => isset($formErrors[$field]) ? ' is-invalid' : '';
$describedBy = static fn (string $field): string => 'cms-multisite-' . $field . '-hint' . (isset($formErrors[$field]) ? ' cms-multisite-' . $field . '-error' : '');
$accentPresets = [
    '#4f46e5' => 'Indigo',
    '#1d4ed8' => 'Blau',
    '#0e7490' => 'Cyan',
    '#0f766e' => 'Petrol',
    '#15803d' => 'Grün',
    '#b45309' => 'Bernstein',
    '#c2410c' => 'Orange',
    '#be123c' => 'Rose',
    '#7e22ce' => 'Violett',
    '#44403c' => 'Anthrazit',
];
?>

<div class="container-xl cms-settings-page cms-multisites-page">
    <div class="page-header d-print-none mb-4">
        <div class="row align-items-center">
            <div class="col-auto">
                <div class="page-pretitle">Einstellungen</div>
                <h2 class="page-title">
                    <i class="ti ti-world-www icon me-2" aria-hidden="true"></i>
                    MultiSites
                </h2>
            </div>
        </div>
    </div>

    <div id="settings-alert-region">
        <?php
        $alertData = is_array($alert ?? null) ? $alert : [];
        $alertMarginClass = 'mb-4';
        require dirname(__DIR__) . '/partials/flash-alert.php';
        ?>
    </div>

    <div class="cms-settings-toolbar">
        <?php $settingsTabsActive = 'multisites'; require __DIR__ . '/partials-tabs.php'; ?>
    </div>

    <?php if (!$multisitesConfigured): ?>
        <div class="alert alert-info cms-multisites-notice mb-4" role="note">
            <strong>Noch keine MultiSites gespeichert.</strong>
            Aktuell gilt die Haupt-URL dieser Installation (Einstellungen › Allgemein) als einzige Site.
            Sobald hier eine Site bearbeitet oder hinzugefügt wird, wird die Liste gespeichert.
        </div>
    <?php endif; ?>

    <div class="row row-cards">
        <div class="col-12 col-xl-7">
            <section class="card cms-multisites" aria-labelledby="cms-multisites-title">
                <div class="card-header">
                    <h3 class="card-title" id="cms-multisites-title">Sites</h3>
                    <div class="card-actions">
                        <span class="cms-multisites__count"><?= $esc($siteCount === 1 ? '1 Site' : $siteCount . ' Sites') ?></span>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table card-table table-vcenter cms-multisites-table">
                        <caption class="visually-hidden">Sites der Administration in ihrer Reihenfolge. Die erste Site ist die Standard-Site.</caption>
                        <thead>
                            <tr>
                                <th scope="col">Site</th>
                                <th scope="col">Hostnamen</th>
                                <th scope="col" class="text-end">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($multisitesSites as $siteKey => $site): ?>
                                <?php
                                $siteKey = (string) $siteKey;
                                $position = array_search($siteKey, $siteKeys, true);
                                $isFirst = $position === 0;
                                $isLast = $position === $siteCount - 1;
                                $siteLabel = (string) $site['label'];
                                ?>
                                <tr class="cms-multisites-table__row<?= $siteKey === $formKey ? ' is-editing' : '' ?>">
                                    <th scope="row" class="cms-multisites-table__site-cell">
                                        <div class="cms-multisites-table__site">
                                            <span class="cms-site-swatch cms-multisites-table__swatch" data-site-swatch="<?= $esc($siteKey) ?>" aria-hidden="true"></span>
                                            <span class="cms-multisites-table__identity">
                                                <span class="cms-multisites-table__name">
                                                    <?= $esc($siteLabel) ?>
                                                    <?php if ($isFirst): ?>
                                                        <span class="cms-multisites-badge cms-multisites-badge--default">Standard</span>
                                                    <?php endif; ?>
                                                    <?php if ($siteKey === $multisitesCurrentKey): ?>
                                                        <span class="cms-multisites-badge">aktuell</span>
                                                    <?php endif; ?>
                                                </span>
                                                <?php if ((string) $site['url'] !== ''): ?>
                                                    <a class="cms-multisites-table__url" href="<?= $esc($site['url']) ?>" target="_blank" rel="noopener">
                                                        <?= $esc(preg_replace('~^https?://~i', '', (string) $site['url'])) ?>
                                                        <i class="ti ti-external-link" aria-hidden="true"></i>
                                                        <span class="visually-hidden">(öffnet in neuem Tab)</span>
                                                    </a>
                                                <?php endif; ?>
                                                <span class="cms-multisites-table__meta">
                                                    Akzent <code><?= $esc($site['accent']) ?></code> · Schlüssel <code><?= $esc($siteKey) ?></code>
                                                </span>
                                            </span>
                                        </div>
                                    </th>
                                    <td class="cms-multisites-table__hosts">
                                        <?php if ($site['hosts'] === []): ?>
                                            <span class="text-secondary">–</span>
                                        <?php else: ?>
                                            <ul class="cms-multisites-hosts">
                                                <?php foreach ($site['hosts'] as $host): ?>
                                                    <li><code><?= $esc($host) ?></code></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </td>
                                    <td class="cms-multisites-table__actions">
                                        <div class="cms-multisites-actions">
                                            <form method="post" action="<?= $esc($multisitesPath) ?>">
                                                <input type="hidden" name="csrf_token" value="<?= $esc($multisitesCsrfToken) ?>">
                                                <input type="hidden" name="action" value="move">
                                                <input type="hidden" name="site_key" value="<?= $esc($siteKey) ?>">
                                                <input type="hidden" name="direction" value="up">
                                                <button type="submit" class="btn btn-icon btn-outline-secondary"<?= $isFirst ? ' disabled' : '' ?>
                                                        aria-label="<?= $esc($siteLabel . ' nach oben verschieben') ?>" title="Nach oben">
                                                    <i class="ti ti-arrow-up" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                            <form method="post" action="<?= $esc($multisitesPath) ?>">
                                                <input type="hidden" name="csrf_token" value="<?= $esc($multisitesCsrfToken) ?>">
                                                <input type="hidden" name="action" value="move">
                                                <input type="hidden" name="site_key" value="<?= $esc($siteKey) ?>">
                                                <input type="hidden" name="direction" value="down">
                                                <button type="submit" class="btn btn-icon btn-outline-secondary"<?= $isLast ? ' disabled' : '' ?>
                                                        aria-label="<?= $esc($siteLabel . ' nach unten verschieben') ?>" title="Nach unten">
                                                    <i class="ti ti-arrow-down" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                            <a class="btn btn-icon btn-outline-primary" href="<?= $esc($multisitesPath . '?edit=' . rawurlencode($siteKey) . '#cms-multisite-form') ?>"
                                               aria-label="<?= $esc($siteLabel . ' bearbeiten') ?>" title="Bearbeiten">
                                                <i class="ti ti-pencil" aria-hidden="true"></i>
                                            </a>
                                            <?php if ($siteCount > 1): ?>
                                                <form method="post" action="<?= $esc($multisitesPath) ?>"
                                                      data-confirm-title="Site löschen?"
                                                      data-confirm-message="<?= $esc('Die Site „' . $siteLabel . '“ wird aus der Administration entfernt. Plugin-Zuweisungen für diese Site entfallen.') ?>"
                                                      data-confirm-text="Site löschen"
                                                      data-confirm-class="btn-danger"
                                                      data-confirm-status-class="bg-danger">
                                                    <input type="hidden" name="csrf_token" value="<?= $esc($multisitesCsrfToken) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="site_key" value="<?= $esc($siteKey) ?>">
                                                    <button type="submit" class="btn btn-icon btn-outline-danger"
                                                            aria-label="<?= $esc($siteLabel . ' löschen') ?>" title="Löschen">
                                                        <i class="ti ti-trash" aria-hidden="true"></i>
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
                <div class="card-footer cms-multisites__footer">
                    Die erste Site ist die <strong>Standard-Site</strong>: Sie gilt, wenn weder im Site-Umschalter eine Site gewählt ist noch ein Hostname passt.
                </div>
            </section>
        </div>

        <div class="col-12 col-xl-5">
            <form method="post" action="<?= $esc($multisitesPath) ?>" class="card cms-multisites-form" id="cms-multisite-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $esc($multisitesCsrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="site_key" value="<?= $esc($isEdit ? $formKey : '') ?>">

                <div class="card-header">
                    <h3 class="card-title">
                        <?= $isEdit ? 'Site bearbeiten: ' . $esc($multisitesSites[$formKey]['label']) : 'Site hinzufügen' ?>
                    </h3>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label required" for="cms-multisite-label">Name</label>
                        <input type="text" class="form-control<?= $fieldClass('label') ?>" id="cms-multisite-label" name="label"
                               value="<?= $esc($formValues['label']) ?>" maxlength="<?= \CMS\Services\AdminSiteContextService::MAX_LABEL_LENGTH ?>" required
                               autocomplete="off" placeholder="z. B. 365Network" aria-describedby="<?= $esc($describedBy('label')) ?>"<?= isset($formErrors['label']) ? ' aria-invalid="true"' : '' ?>>
                        <?php if (isset($formErrors['label'])): ?>
                            <div class="invalid-feedback" id="cms-multisite-label-error"><?= $esc($formErrors['label']) ?></div>
                        <?php endif; ?>
                        <div class="form-hint" id="cms-multisite-label-hint">Erscheint im Site-Umschalter der Seitenleiste und in der Plugin-Zuweisung.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label required" for="cms-multisite-url">URL</label>
                        <input type="url" class="form-control<?= $fieldClass('url') ?>" id="cms-multisite-url" name="url"
                               value="<?= $esc($formValues['url']) ?>" required inputmode="url" autocomplete="off" spellcheck="false"
                               placeholder="https://example.com" aria-describedby="<?= $esc($describedBy('url')) ?>"<?= isset($formErrors['url']) ? ' aria-invalid="true"' : '' ?>>
                        <?php if (isset($formErrors['url'])): ?>
                            <div class="invalid-feedback" id="cms-multisite-url-error"><?= $esc($formErrors['url']) ?></div>
                        <?php endif; ?>
                        <div class="form-hint" id="cms-multisite-url-hint">Protokoll und Domain, ohne Pfad. Domain und www-Variante werden automatisch als Hostnamen erkannt.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label required" for="cms-multisite-accent">Akzentfarbe</label>
                        <div class="cms-multisites-accent">
                            <input type="color" class="form-control form-control-color<?= $fieldClass('accent') ?>" id="cms-multisite-accent" name="accent"
                                   value="<?= $esc($formValues['accent'] !== '' ? $formValues['accent'] : \CMS\Services\AdminSiteContextService::DEFAULT_ACCENT) ?>"
                                   list="cms-multisite-accent-presets" aria-describedby="<?= $esc($describedBy('accent')) ?>"<?= isset($formErrors['accent']) ? ' aria-invalid="true"' : '' ?>>
                            <datalist id="cms-multisite-accent-presets">
                                <?php foreach ($accentPresets as $presetColor => $presetLabel): ?>
                                    <option value="<?= $esc($presetColor) ?>"><?= $esc($presetLabel) ?></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <?php if (isset($formErrors['accent'])): ?>
                            <div class="invalid-feedback d-block" id="cms-multisite-accent-error"><?= $esc($formErrors['accent']) ?></div>
                        <?php endif; ?>
                        <div class="form-hint" id="cms-multisite-accent-hint">Färbt Schaltflächen, Links und die aktive Navigation, solange diese Site gewählt ist. Weiße Schrift darauf und Links auf dem grauen Seitenhintergrund müssen mindestens 4,5:1 Kontrast erreichen (WCAG AA).</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="cms-multisite-hosts">Weitere Hostnamen</label>
                        <textarea class="form-control<?= $fieldClass('hosts') ?>" id="cms-multisite-hosts" name="hosts" rows="3"
                                  spellcheck="false" autocomplete="off" placeholder="staging.example.com"
                                  aria-describedby="<?= $esc($describedBy('hosts')) ?>"<?= isset($formErrors['hosts']) ? ' aria-invalid="true"' : '' ?>><?= $esc($formValues['hosts']) ?></textarea>
                        <?php if (isset($formErrors['hosts'])): ?>
                            <div class="invalid-feedback" id="cms-multisite-hosts-error"><?= $esc($formErrors['hosts']) ?></div>
                        <?php endif; ?>
                        <div class="form-hint" id="cms-multisite-hosts-hint">Optional, ein Hostname pro Zeile – etwa Staging- oder Alias-Domains. Wird die Administration über einen dieser Hostnamen aufgerufen, ist die Site automatisch aktiv.</div>
                    </div>

                    <?php if ($isEdit): ?>
                        <p class="cms-multisites-form__key mb-0">
                            Schlüssel <code><?= $esc($formKey) ?></code> – bleibt beim Ändern der URL erhalten, damit Plugin-Zuweisungen und Benutzereinstellungen gültig bleiben.
                        </p>
                    <?php endif; ?>
                </div>
                <div class="card-footer cms-multisites-form__footer">
                    <?php if ($isEdit): ?>
                        <a class="btn btn-outline-secondary" href="<?= $esc($multisitesPath) ?>">Abbrechen</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="ti ti-check me-1" aria-hidden="true"></i> Änderungen speichern
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-primary">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i> Site hinzufügen
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <section class="card cms-multisites-help mt-4" aria-labelledby="cms-multisites-help-title">
        <div class="card-header">
            <h3 class="card-title" id="cms-multisites-help-title">So funktionieren MultiSites</h3>
        </div>
        <div class="card-body">
            <ul class="cms-multisites-help__list">
                <li><strong>Site-Umschalter:</strong> Ab zwei Sites erscheint oben in der Seitenleiste ein Umschalter. Die Auswahl wird je Benutzer gespeichert.</li>
                <li><strong>Automatische Erkennung:</strong> Ohne Auswahl gilt die Site, zu der der aufgerufene Hostname gehört – sonst die Standard-Site.</li>
                <li><strong>Akzentfarbe:</strong> Jede Site färbt die Administration in ihrer Akzentfarbe ein, damit sofort erkennbar ist, welche Site gerade bearbeitet wird.</li>
                <li><strong>Plugins:</strong> Welche Plugin-Menüs je Site erscheinen, legt <a href="<?= $esc(\CMS\Services\PluginSiteAssignmentService::ADMIN_PATH) ?>">Einstellungen › Plugins</a> fest.</li>
            </ul>
        </div>
    </section>
</div>
