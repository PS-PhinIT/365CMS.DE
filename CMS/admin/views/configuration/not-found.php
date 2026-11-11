<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * View: Kachelseite nicht gefunden (404) – unbekannte, ungültige oder im aktiven Layout nicht
 * als Kachelseite angezeigte Gruppe. Der angefragte Wert wird bewusst nicht ausgegeben.
 *
 * @var array{label:string, route:string}|null $configurationRootPage Wurzel-Kachelseite des aktiven Layouts
 * @var string $configurationSiteUrl
 */

$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>

<div class="container-xl cms-configuration-page">
    <div class="page-header d-print-none mb-4">
        <div class="row align-items-center">
            <div class="col">
                <div class="page-pretitle">Fehler 404</div>
                <h2 class="page-title">
                    <i class="ti ti-layout-grid-remove icon me-2" aria-hidden="true"></i>
                    Seite nicht gefunden
                </h2>
            </div>
        </div>
    </div>

    <div class="card cms-configuration-missing">
        <div class="card-body">
            <p class="mb-3">
                Diese Kachelseite gibt es im aktiven Admin-Menü nicht. Sie wurde entfernt, ist im gewählten
                Profil keine Kachelseite oder enthält für dein Konto keine sichtbaren Einträge.
            </p>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($configurationRootPage !== null): ?>
                    <a class="btn btn-primary" href="<?= $esc($configurationSiteUrl . $configurationRootPage['route']) ?>">
                        <i class="ti ti-layout-grid icon" aria-hidden="true"></i>
                        <?= $esc($configurationRootPage['label']) ?>
                    </a>
                <?php endif; ?>
                <a class="btn<?= $configurationRootPage === null ? ' btn-primary' : '' ?>" href="<?= $esc($configurationSiteUrl . '/admin') ?>">
                    <i class="ti ti-home icon" aria-hidden="true"></i>
                    Zum Dashboard
                </a>
            </div>
        </div>
    </div>
</div>
