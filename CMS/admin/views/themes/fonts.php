<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * View: Font Manager
 *
 * @var array  $data
 * @var string $csrfToken
 * @var array|null $alert
 */

$systemFonts  = $data['systemFonts'] ?? [];
$customFonts  = $data['customFonts'] ?? [];
$customFontRows = is_array($data['customFontRows'] ?? null) ? $data['customFontRows'] : [];
$headingFont  = $data['headingFont'] ?? 'system-ui';
$bodyFont     = $data['bodyFont'] ?? 'system-ui';
$useLocalFonts = !empty($data['useLocalFonts']);
$fontSize     = $data['fontSize'] ?? '16';
$lineHeight   = $data['lineHeight'] ?? '1.6';
$scanResults  = $data['scanResults'] ?? ['theme' => '', 'scannedFiles' => 0, 'detectedFonts' => []];
$fontCatalog  = $data['fontCatalog'] ?? [];
$activeThemeSlug = $data['activeThemeSlug'] ?? '';
$scanSummary = is_array($data['scanSummary'] ?? null) ? $data['scanSummary'] : ['scannedFiles' => 0, 'skippedFiles' => 0, 'warnings' => []];
$constraints = is_array($data['constraints'] ?? null) ? $data['constraints'] : [];
$fontUsageAnalysis = is_array($data['fontUsageAnalysis'] ?? null) ? $data['fontUsageAnalysis'] : [];
$detectedFonts = (array)($scanResults['detectedFonts'] ?? []);
$detectedInstallableFonts = array_values(array_filter($detectedFonts, static fn(array $font): bool => empty($font['installed'])));
$fontUsageStats = is_array($fontUsageAnalysis['stats'] ?? null) ? $fontUsageAnalysis['stats'] : [];
$fontUsageConfigured = is_array($fontUsageAnalysis['configured_fonts'] ?? null) ? $fontUsageAnalysis['configured_fonts'] : [];
$fontUsageEntries = is_array($fontUsageAnalysis['font_entries'] ?? null) ? $fontUsageAnalysis['font_entries'] : [];
$fontUsageWarnings = is_array($fontUsageAnalysis['warnings'] ?? null) ? $fontUsageAnalysis['warnings'] : [];
$fontManagerConfig = [
    'deleteModal' => [
        'title' => 'Schriftart löschen',
        'confirmText' => 'Löschen',
        'confirmClass' => 'btn-danger',
    ],
];
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">Themes &amp; Design</div>
                <h2 class="page-title">Font Manager</h2>
                <div class="text-secondary mt-1">Schriften prüfen, Theme-Fonts scannen und lokal self-hosten</div>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
<div class="container-xl">

    <?php if (!empty($alert)): ?>
        <?php $alertData = $alert; $alertMarginClass = 'mb-3'; require __DIR__ . '/../partials/flash-alert.php'; ?>
    <?php endif; ?>

    <div class="row">
        <div class="col-12">
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <h3 class="card-title mb-1">Schritt 1 · Theme-Fonts scannen</h3>
                        <div class="text-muted small">Aktives Theme: <code><?php echo htmlspecialchars($activeThemeSlug); ?></code></div>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($detectedInstallableFonts !== []): ?>
                            <form method="post" data-font-manager-form="download-detected-fonts">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="download_detected_fonts">
                                <button type="submit" class="btn btn-success btn-sm" data-pending-text="Lädt lokal …">Alle lokal laden</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" data-font-manager-form="scan-theme-fonts">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="action" value="scan_theme_fonts">
                            <button type="submit" class="btn btn-primary btn-sm" data-pending-text="Scan läuft …">Scan starten</button>
                        </form>
                    </div>
                </div>
                <div class="card-body">
                    <?php
                    $alertData = [
                        'type' => $useLocalFonts ? 'success' : 'warning',
                        'message' => $useLocalFonts
                            ? 'Lokale Fonts sind für das Frontend aktiv. Externe Google-Font-Requests sollten damit unterdrückt werden.'
                            : 'Lokale Fonts sind derzeit nicht fürs Frontend aktiviert. Solange der Schalter unten nicht gesetzt ist, bleibt der Google-Fonts-Fallback aktiv.',
                    ];
                    $alertDismissible = false;
                    $alertMarginClass = 'mb-3';
                    require __DIR__ . '/../partials/flash-alert.php';
                    ?>

                    <p class="text-muted">Der Scan durchsucht das aktive Theme nach Google-Font-Imports und bekannten Schriftfamilien, damit du genutzte Fonts lokal self-hosten kannst.</p>
                    <?php
                    $scanConstraintDetails = array_values(array_filter([
                        !empty($constraints['download_total_byte_limit_label']) ? 'Remote-Gesamtlimit pro Import: ' . (string) $constraints['download_total_byte_limit_label'] : '',
                        !empty($constraints['download_remote_file_byte_limit_label']) ? 'Einzeldatei-Limit: ' . (string) $constraints['download_remote_file_byte_limit_label'] : '',
                        !empty($constraints['scan_file_size_limit_label']) ? 'Scan-Dateilimit: ' . (string) $constraints['scan_file_size_limit_label'] : '',
                        !empty($constraints['scan_total_byte_limit_label']) ? 'Scan-Gesamtlimit: ' . (string) $constraints['scan_total_byte_limit_label'] : '',
                        !empty($constraints['allowed_remote_hosts_label']) ? 'Erlaubte Hosts: ' . (string) $constraints['allowed_remote_hosts_label'] : '',
                        !empty($constraints['scan_allowed_extensions_label']) ? 'Scan-Endungen: ' . (string) $constraints['scan_allowed_extensions_label'] : '',
                        !empty($constraints['scan_skipped_segments_label']) ? 'Übersprungene Segmente: ' . (string) $constraints['scan_skipped_segments_label'] : '',
                    ]));
                    ?>
                    <?php if ($scanConstraintDetails !== []): ?>
                        <?php
                        $alertData = [
                            'type' => 'info',
                            'message' => 'Theme-Scan und Remote-Downloads laufen mit festen Schutzgrenzen.',
                            'details' => $scanConstraintDetails,
                        ];
                        $alertDismissible = false;
                        $alertMarginClass = 'mb-3';
                        require __DIR__ . '/../partials/flash-alert.php';
                        ?>
                    <?php endif; ?>
                    <div class="small text-muted mb-3">
                        <?php echo (int)($scanSummary['scannedFiles'] ?? 0); ?> Dateien geprüft
                        <?php if (!empty($scanSummary['skippedFiles'])): ?>
                            · <?php echo (int)($scanSummary['skippedFiles'] ?? 0); ?> übersprungen
                        <?php endif; ?>
                        <?php if (!empty($constraints['scan_file_limit'])): ?>
                            · Limit <?php echo (int)($constraints['scan_file_limit'] ?? 0); ?> Dateien
                        <?php endif; ?>
                        <?php if (!empty($scanSummary['source'])): ?>
                            · Quelle <?php echo htmlspecialchars((string)($scanSummary['source'] ?? 'live')); ?>
                        <?php endif; ?>
                        <?php if (!empty($scanSummary['generatedAt'])): ?>
                            · Stand <?php echo htmlspecialchars((string)($scanSummary['generatedAt'] ?? '')); ?>
                        <?php endif; ?>
                        <?php if (!empty($constraints['download_remote_file_limit'])): ?>
                            · Download-Limit <?php echo (int)($constraints['download_remote_file_limit'] ?? 0); ?> Dateien
                        <?php endif; ?>
                        <?php if (!empty($constraints['scan_total_byte_limit_label'])): ?>
                            · Gesamt <?php echo htmlspecialchars((string)($constraints['scan_total_byte_limit_label'] ?? '')); ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($scanSummary['warnings']) && is_array($scanSummary['warnings'])): ?>
                        <?php
                        $alertData = [
                            'type' => 'warning',
                            'message' => 'Der Theme-Scan wurde mit Schutzgrenzen ausgeführt.',
                            'details' => array_values(array_map(static fn(mixed $warning): string => (string) $warning, $scanSummary['warnings'])),
                        ];
                        $alertDismissible = false;
                        $alertMarginClass = 'mb-3';
                        require __DIR__ . '/../partials/flash-alert.php';
                        ?>
                    <?php endif; ?>
                    <?php if ($detectedInstallableFonts !== []): ?>
                        <?php
                        $alertData = [
                            'type' => 'info',
                            'message' => count($detectedInstallableFonts) . ' erkannte Schrift' . (count($detectedInstallableFonts) === 1 ? '' : 'en') . ' sind noch extern eingebunden und können gesammelt lokal gespeichert werden.',
                        ];
                        $alertDismissible = false;
                        $alertMarginClass = 'mb-3';
                        require __DIR__ . '/../partials/flash-alert.php';
                        ?>
                    <?php endif; ?>

                    <?php if ($detectedFonts !== []): ?>
                        <div class="table-responsive">
                            <table class="table table-vcenter card-table">
                                <thead>
                                    <tr>
                                        <th>Schrift</th>
                                        <th>Typ</th>
                                        <th>Status</th>
                                        <th>Gefunden in</th>
                                        <th class="w-1">Aktion</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($detectedFonts as $font): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($font['name'] ?? ''); ?></div>
                                                <?php if (!empty($font['reason'])): ?>
                                                    <div class="text-muted small"><?php echo htmlspecialchars($font['reason']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge bg-azure-lt"><?php echo htmlspecialchars($font['style'] ?? 'Font'); ?></span></td>
                                            <td>
                                                <?php if (!empty($font['installed'])): ?>
                                                    <span class="badge bg-green">Lokal vorhanden</span>
                                                <?php else: ?>
                                                    <span class="badge bg-orange">Extern / nicht lokal</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php foreach (($font['sources'] ?? []) as $source): ?>
                                                    <div class="small"><code><?php echo htmlspecialchars($source['file'] ?? ''); ?></code> <span class="text-muted">· <?php echo htmlspecialchars($source['type'] ?? 'Quelle'); ?></span></div>
                                                <?php endforeach; ?>
                                            </td>
                                            <td>
                                                <?php if (empty($font['installed'])): ?>
                                                    <form method="post" data-font-manager-form="download-detected-single-font">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                        <input type="hidden" name="action" value="download_google_font">
                                                        <input type="hidden" name="google_font_family" value="<?php echo htmlspecialchars($font['name'] ?? ''); ?>">
                                                        <button type="submit" class="btn btn-outline-primary btn-sm" data-pending-text="Lädt …">Self-Host</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="text-muted small">Bereits lokal</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <?php
                        $alertData = [
                            'type' => 'secondary',
                            'message' => 'Noch keine bekannten externen Theme-Schriften erkannt. Starte den Scan erneut, falls du gerade Fonts im Theme geändert hast.',
                        ];
                        $alertDismissible = false;
                        $alertMarginClass = 'mb-0';
                        require __DIR__ . '/../partials/flash-alert.php';
                        ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($fontUsageAnalysis !== []): ?>
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <h3 class="card-title mb-1">Schritt 1b · Font-Nutzungsanalyse</h3>
                            <div class="text-muted small">Read-only Auswertung aus Runtime-Konfiguration, lokaler Font-Bibliothek und Theme-Scan</div>
                        </div>
                        <span class="badge <?php echo htmlspecialchars((string) ($fontUsageAnalysis['runtime_mode_badge_class'] ?? 'bg-secondary-lt')); ?>">
                            <?php echo htmlspecialchars((string) ($fontUsageAnalysis['runtime_mode_label'] ?? 'Analyse')); ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3"><?php echo htmlspecialchars((string) ($fontUsageAnalysis['runtime_mode_note'] ?? '')); ?></p>

                        <?php if ($fontUsageWarnings !== []): ?>
                            <?php
                            $alertData = [
                                'type' => 'warning',
                                'message' => 'Die Nutzungsanalyse hat konkrete Nacharbeiten erkannt.',
                                'details' => $fontUsageWarnings,
                            ];
                            $alertDismissible = false;
                            $alertMarginClass = 'mb-3';
                            require __DIR__ . '/../partials/flash-alert.php';
                            ?>
                        <?php endif; ?>

                        <?php if ($fontUsageStats !== []): ?>
                            <div class="row row-cards mb-3">
                                <?php foreach ($fontUsageStats as $stat): ?>
                                    <div class="col-sm-6 col-xl-3">
                                        <div class="card card-sm h-100">
                                            <div class="card-body">
                                                <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                                    <span class="text-muted small"><?php echo htmlspecialchars((string) ($stat['label'] ?? 'Kennzahl')); ?></span>
                                                    <span class="badge <?php echo htmlspecialchars((string) ($stat['badge_class'] ?? 'bg-secondary-lt')); ?>"><?php echo htmlspecialchars((string) ($stat['value'] ?? '0')); ?></span>
                                                </div>
                                                <?php if (!empty($stat['hint'])): ?>
                                                    <div class="small text-muted"><?php echo htmlspecialchars((string) ($stat['hint'] ?? '')); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($fontUsageConfigured !== []): ?>
                            <div class="row g-3 mb-3">
                                <?php foreach ($fontUsageConfigured as $configuredFont): ?>
                                    <div class="col-md-6">
                                        <div class="border rounded p-3 h-100">
                                            <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                                                <div>
                                                    <div class="text-muted small"><?php echo htmlspecialchars((string) ($configuredFont['slot_label'] ?? 'Konfiguration')); ?></div>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars((string) ($configuredFont['font_label'] ?? 'System UI')); ?></div>
                                                </div>
                                                <span class="badge <?php echo htmlspecialchars((string) ($configuredFont['delivery_badge_class'] ?? 'bg-secondary-lt')); ?>">
                                                    <?php echo htmlspecialchars((string) ($configuredFont['delivery_label'] ?? 'Status')); ?>
                                                </span>
                                            </div>
                                            <div class="small text-muted">
                                                <code><?php echo htmlspecialchars((string) ($configuredFont['font_key'] ?? 'system-ui')); ?></code>
                                                <?php if (!empty($configuredFont['note'])): ?>
                                                    · <?php echo htmlspecialchars((string) ($configuredFont['note'] ?? '')); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($fontUsageEntries !== []): ?>
                            <div class="table-responsive">
                                <table class="table table-vcenter card-table">
                                    <thead>
                                        <tr>
                                            <th>Schrift</th>
                                            <th>Nutzung</th>
                                            <th>Status</th>
                                            <th>Hinweise</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($fontUsageEntries as $entry): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex flex-column gap-1">
                                                        <div class="fw-semibold"><?php echo htmlspecialchars((string) ($entry['name'] ?? 'Schrift')); ?></div>
                                                        <div class="d-flex flex-wrap gap-1">
                                                            <span class="badge <?php echo htmlspecialchars((string) ($entry['type_badge_class'] ?? 'bg-secondary-lt')); ?>">
                                                                <?php echo htmlspecialchars((string) ($entry['type_label'] ?? 'Typ')); ?>
                                                            </span>
                                                            <?php foreach ((array) ($entry['roles'] ?? []) as $role): ?>
                                                                <span class="badge bg-azure-lt"><?php echo htmlspecialchars((string) $role); ?></span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if (!empty($entry['source_count'])): ?>
                                                        <div class="small fw-semibold"><?php echo (int) ($entry['source_count'] ?? 0); ?> Theme-Treffer</div>
                                                        <?php foreach ((array) ($entry['source_preview'] ?? []) as $source): ?>
                                                            <div class="small text-muted"><code><?php echo htmlspecialchars((string) ($source['file'] ?? '')); ?></code> <span>· <?php echo htmlspecialchars((string) ($source['type'] ?? 'Theme-Datei')); ?></span></div>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted small">Kein Theme-Treffer notwendig</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo htmlspecialchars((string) ($entry['status_badge_class'] ?? 'bg-secondary-lt')); ?>">
                                                        <?php echo htmlspecialchars((string) ($entry['status_label'] ?? 'Status')); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="small"><?php echo htmlspecialchars((string) ($entry['note'] ?? '')); ?></div>
                                                    <?php if (!empty($entry['recommendation'])): ?>
                                                        <div class="text-muted small mt-1"><?php echo htmlspecialchars((string) ($entry['recommendation'] ?? '')); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card mb-3">
                <div class="card-header">
                    <h3 class="card-title">Schritt 2 · Empfohlene Schriftbibliothek</h3>
                </div>
                <div class="card-body">
                    <?php
                    $alertData = [
                        'type' => 'success',
                        'message' => 'Self-Hosting statt CDN: Alle Downloads werden lokal in /uploads/fonts abgelegt, damit Themes keine externen Font-CDNs mehr brauchen.',
                    ];
                    $alertDismissible = false;
                    $alertMarginClass = 'mb-3';
                    require __DIR__ . '/../partials/flash-alert.php';
                    ?>

                    <?php if ((string) $activeThemeSlug === 'cms-phinit'): ?>
                        <?php
                        $alertData = [
                            'type' => 'info',
                            'message' => 'Empfohlener Satz für `cms-phinit`: Inter für Body/UI, Space Grotesk für Headings, Sora als weichere Display-Alternative und JetBrains Mono bzw. Fira Code für Code-Blöcke. Cascadia Code bleibt zusätzlich als lokale Windows-Mono-Option hinterlegt. Diese Familien sind jetzt auch im zentralen Font Manager hinterlegt.',
                        ];
                        $alertDismissible = false;
                        $alertMarginClass = 'mb-3';
                        require __DIR__ . '/../partials/flash-alert.php';
                        ?>
                    <?php endif; ?>
                    <div class="font-manager-search">
                        <label class="form-label mb-1" for="fontManagerSearch">Schriftensuche</label>
                        <input type="search" id="fontManagerSearch" class="form-control" placeholder="Schriften in aktiven und verfügbaren Bereichen filtern …" autocomplete="off" spellcheck="false">
                    </div>

                    <h4 class="font-manager-section-title">Aktive Schriften</h4>
                    <?php if (!empty($customFontRows)): ?>
                        <div class="list-group mb-4" data-font-manager-active-section>
                            <?php foreach ($customFontRows as $font): ?>
                                <?php
                                $fontName = (string) ($font['name'] ?? '');
                                $fontFormat = (string) ($font['format'] ?? '');
                                $fontSourceLabel = (($font['source'] ?? '') === 'google-fonts-local') ? 'Google (lokal)' : 'Manuell';
                                $fontSearchText = strtolower(trim($fontName . ' ' . $fontFormat . ' ' . $fontSourceLabel));
                                ?>
                                <div class="list-group-item" data-font-search-item data-font-section="active" data-font-search-text="<?php echo htmlspecialchars($fontSearchText, ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($fontName, ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="text-muted small">
                                                <?php echo htmlspecialchars($fontFormat, ENT_QUOTES, 'UTF-8'); ?>
                                                · <?php echo htmlspecialchars($fontSourceLabel, ENT_QUOTES, 'UTF-8'); ?>
                                                <?php if (!empty($font['file_size_label'])): ?>
                                                    · <?php echo htmlspecialchars((string) ($font['file_size_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <form method="post" data-font-manager-form="delete-font">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="delete_font">
                                            <input type="hidden" name="font_id" value="<?php echo (int)($font['id'] ?? 0); ?>">
                                            <button type="button" class="btn btn-outline-danger btn-sm js-font-delete" data-font-name="<?php echo htmlspecialchars((string) ($font['name'] ?? ''), ENT_QUOTES); ?>" data-pending-text="Löscht …">Löschen</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-muted small mb-4" data-font-manager-active-empty>Noch keine lokal aktiven Schriften vorhanden.</div>
                    <?php endif; ?>

                    <hr class="my-4">

                    <h4 class="font-manager-section-title">Verfügbare Schriften</h4>
                    <?php
                    $fontCatalogGroups = [
                        'Serif' => [],
                        'Sans-Serif' => [],
                        'Monospace' => [],
                        'Display' => [],
                    ];
                    foreach ($fontCatalog as $category => $fonts) {
                        if (!isset($fontCatalogGroups[$category]) || !is_array($fonts)) {
                            continue;
                        }
                        $fontCatalogGroups[$category] = $fonts;
                    }
                    ?>
                    <div class="accordion" id="fontCatalogAccordion" data-font-manager-available-section>
                        <?php foreach ($fontCatalogGroups as $category => $fonts): ?>
                            <?php $collapseId = 'fontCatalog-' . strtolower(str_replace(' ', '-', $category)); ?>
                            <div class="accordion-item" data-font-accordion-group>
                                <h2 class="accordion-header" id="<?php echo htmlspecialchars($collapseId . '-heading', ENT_QUOTES, 'UTF-8'); ?>">
                                    <button class="accordion-button <?php echo $category === 'Serif' ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8'); ?>" aria-expanded="<?php echo $category === 'Serif' ? 'true' : 'false'; ?>" aria-controls="<?php echo htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>
                                    </button>
                                </h2>
                                <div id="<?php echo htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8'); ?>" class="accordion-collapse collapse <?php echo $category === 'Serif' ? 'show' : ''; ?>" aria-labelledby="<?php echo htmlspecialchars($collapseId . '-heading', ENT_QUOTES, 'UTF-8'); ?>" data-bs-parent="#fontCatalogAccordion">
                                    <div class="accordion-body">
                                        <?php if ($fonts === []): ?>
                                            <div class="text-muted small">Keine Schriften in dieser Kategorie vorhanden.</div>
                                        <?php else: ?>
                                            <div class="row row-cards">
                                                <?php foreach ($fonts as $font): ?>
                                                    <?php
                                                    $fontName = (string) ($font['name'] ?? '');
                                                    $fontStyle = (string) ($font['style'] ?? '');
                                                    $fontReason = (string) ($font['reason'] ?? '');
                                                    $fontSearchText = strtolower(trim($fontName . ' ' . $fontStyle . ' ' . $fontReason . ' ' . $category));
                                                    ?>
                                                    <div class="col-md-6" data-font-search-item data-font-section="available" data-font-search-text="<?php echo htmlspecialchars($fontSearchText, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <div class="card card-sm mb-2">
                                                            <div class="card-body">
                                                                <div class="d-flex align-items-start justify-content-between gap-3">
                                                                    <div>
                                                                        <div class="fw-semibold"><?php echo htmlspecialchars($fontName, ENT_QUOTES, 'UTF-8'); ?></div>
                                                                        <div class="text-muted small mb-1"><?php echo htmlspecialchars($fontStyle, ENT_QUOTES, 'UTF-8'); ?></div>
                                                                        <div class="small"><?php echo htmlspecialchars($fontReason, ENT_QUOTES, 'UTF-8'); ?></div>
                                                                    </div>
                                                                    <div class="text-end">
                                                                        <?php if (!empty($font['installed'])): ?>
                                                                            <span class="badge bg-green mb-2">Lokal</span>
                                                                        <?php else: ?>
                                                                            <form method="post" data-font-manager-form="download-library-font">
                                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                                                <input type="hidden" name="action" value="download_google_font">
                                                                                <input type="hidden" name="google_font_family" value="<?php echo htmlspecialchars($fontName, ENT_QUOTES, 'UTF-8'); ?>">
                                                                                <button type="submit" class="btn btn-outline-primary btn-sm" data-pending-text="Lädt …">Self-Host</button>
                                                                            </form>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <form method="post" class="card mb-3" data-font-manager-form="save-assignments">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="save">
                <div class="card-header">
                    <h3 class="card-title">Schritt 3 · Typografie-Zuordnung</h3>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="headingFontSelect" class="form-label">Überschriften-Schrift</label>
                            <select name="heading_font" class="form-select" id="headingFontSelect">
                                <?php foreach ($systemFonts as $key => $label): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $headingFont === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="bodyFontSelect" class="form-label">Text-Schrift</label>
                            <select name="body_font" class="form-select" id="bodyFontSelect">
                                <?php foreach ($systemFonts as $key => $label): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $bodyFont === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php if ((string) $activeThemeSlug === 'cms-phinit'): ?>
                        <div class="form-hint mb-3">Tipp für `cms-phinit`: <strong>Body/UI = Inter</strong>, <strong>Headings = Space Grotesk</strong>. Für Code-Blöcke bleiben <strong>JetBrains Mono</strong> und <strong>Fira Code</strong> ideale Self-Host-Kandidaten; <strong>Cascadia Code</strong> ist zusätzlich als lokale Mono-Option für Windows-Setups hinterlegt.</div>
                    <?php endif; ?>
                    <div class="row">
                        <div class="col-md-6">
                            <label for="fld-font_size" class="form-label">Basis-Schriftgröße (px)</label>
                                <input id="fld-font_size" type="number" name="font_size" class="form-control" value="<?php echo (int)$fontSize; ?>" min="<?php echo (int)($constraints['font_size_min'] ?? 12); ?>" max="<?php echo (int)($constraints['font_size_max'] ?? 24); ?>" step="1" inputmode="numeric">
                        </div>
                        <div class="col-md-6">
                            <label for="fld-line_height" class="form-label">Zeilenhöhe</label>
                            <input id="fld-line_height" type="number" name="line_height" class="form-control" value="<?php echo htmlspecialchars($lineHeight); ?>" min="<?php echo htmlspecialchars((string)($constraints['line_height_min'] ?? '1.0')); ?>" max="<?php echo htmlspecialchars((string)($constraints['line_height_max'] ?? '2.5')); ?>" step="0.1" inputmode="decimal">
                        </div>
                    </div>
                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="useLocalFontsSwitch" name="use_local_fonts" value="1" <?php echo $useLocalFonts ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="useLocalFontsSwitch">
                            Lokale On-Prem-Fonts im Frontend aktivieren
                        </label>
                        <div class="form-hint">Schaltet Theme und Core auf lokal gespeicherte Schrift-CSS um und unterdrückt Google-Fonts-Requests im Frontend.</div>
                    </div>
                </div>
                <div class="card-footer text-end">
                    <button type="submit" class="btn btn-primary" data-pending-text="Speichert …">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-device-floppy" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 4h10l4 4v10a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2"/><path d="M12 14m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"/><path d="M14 4l0 4l-6 0l0 -4"/></svg>
                        Zuweisung speichern
                    </button>
                </div>
            </form>

            <div class="card mb-3">
                <div class="card-header">
                    <h3 class="card-title">Direktdownload einer Google Font</h3>
                </div>
                <div class="card-body">
                    <form method="post" data-font-manager-form="direct-download-font">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="download_google_font">
                        <div class="row g-2 align-items-end">
                            <div class="col">
                                <label for="fld-google_font_family" class="form-label">Google Font Name</label>
                                <input id="fld-google_font_family" type="text" name="google_font_family" class="form-control" placeholder="z.B. Inter, Roboto, Open Sans" required maxlength="<?php echo (int)($constraints['google_font_family_max_length'] ?? 120); ?>" pattern="[a-zA-Z0-9 ]+" title="Nur Buchstaben, Zahlen und Leerzeichen" autocomplete="off" spellcheck="false">
                                <small class="form-hint">Exakter Name von <a href="https://fonts.google.com" target="_blank" rel="noopener noreferrer">fonts.google.com</a>; pro Import maximal <?php echo (int)($constraints['download_remote_file_limit'] ?? 20); ?> Font-Dateien, je Datei bis <?php echo htmlspecialchars((string)($constraints['download_remote_file_byte_limit_label'] ?? '5,0 MB')); ?>, ausschließlich von <?php echo htmlspecialchars((string)($constraints['allowed_remote_hosts_label'] ?? 'fonts.googleapis.com, fonts.gstatic.com')); ?>.</small>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-outline-primary" data-pending-text="Lädt …">Herunterladen</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>
</div>

<script type="application/json" id="font-manager-config">
<?php echo json_encode($fontManagerConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
</script>
