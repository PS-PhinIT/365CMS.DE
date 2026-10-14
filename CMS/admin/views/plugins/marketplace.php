<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$pluginMarketplaceConfig = [
    'searchInputId' => 'pluginSearch',
    'categoryFilterId' => 'categoryFilter',
    'statusFilterId' => 'statusFilter',
    'gridSelector' => '#pluginGrid',
    'cardSelector' => '.plugin-card',
    'emptyStateSelector' => '#pluginMarketplaceEmptyState',
    'installFormSelector' => 'form[data-marketplace-install-form]',
];

$filters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
$categoryOptions = is_array($filters['categories'] ?? null) ? $filters['categories'] : [];
$statusOptions = is_array($filters['statuses'] ?? null) ? $filters['statuses'] : [];
$source = is_array($data['source'] ?? null) ? $data['source'] : [];
$constraints = is_array($data['constraints'] ?? null) ? $data['constraints'] : [];
$allowedHosts = is_array($constraints['allowed_marketplace_hosts'] ?? null) ? $constraints['allowed_marketplace_hosts'] : [];
$allowedArchiveExtensions = is_array($constraints['allowed_archive_extensions'] ?? null) ? $constraints['allowed_archive_extensions'] : [];
$endpoints = is_array($data['endpoints'] ?? null) ? $data['endpoints'] : [];
$marketplaceOverviewUrl = (string) ($endpoints['marketplace_public_url'] ?? '');
$marketplaceSubmitUrl = (string) ($endpoints['marketplace_submit_url'] ?? '');
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>

<!-- Plugin Marketplace -->
<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row align-items-center">
            <div class="col-auto">
                <h2 class="page-title"><?php echo htmlspecialchars($pageTitle); ?></h2>
                <div class="text-secondary mt-1">Verfügbare Plugins durchsuchen und installieren</div>
            </div>
            <div class="col-auto ms-auto d-flex flex-wrap gap-2">
                <?php if ($marketplaceOverviewUrl !== ''): ?>
                    <a href="<?php echo $escape($marketplaceOverviewUrl); ?>" class="btn btn-outline-secondary" target="_blank" rel="noopener noreferrer">Marketplace-Übersicht</a>
                <?php endif; ?>
                <?php if ($marketplaceSubmitUrl !== ''): ?>
                    <a href="<?php echo $escape($marketplaceSubmitUrl); ?>" class="btn btn-outline-secondary" target="_blank" rel="noopener noreferrer">Plugin einreichen</a>
                <?php endif; ?>
                <a href="/admin/plugins" class="btn btn-outline-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14"/><path d="M5 12l14 0"/></svg>
                    Installierte Plugins
                </a>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">

        <?php if (!empty($alert)): ?>
            <?php
            $alertData = is_array($alert ?? null) ? $alert : [];
            require dirname(__DIR__) . '/partials/flash-alert.php';
            ?>
        <?php endif; ?>

        <?php if (!empty($source['message'])): ?>
            <?php
            $alertData = [
                'type' => ($source['status'] ?? 'info') === 'success' ? 'success' : (($source['status'] ?? 'info') === 'warning' ? 'warning' : 'info'),
                'message' => (string) ($source['message'] ?? ''),
                'details' => array_values(array_filter(array_merge(
                    !empty($source['url']) ? ['Quelle: ' . (string) $source['url']] : [],
                    is_array($source['details'] ?? null) ? array_map(static fn (mixed $detail): string => (string) $detail, $source['details']) : []
                ))),
            ];
            $alertMarginClass = 'mb-3';
            require dirname(__DIR__) . '/partials/flash-alert.php';
            ?>
        <?php endif; ?>

        <!-- KPI Cards -->
        <div class="row row-deck row-cards mb-4">
            <div class="col-sm-6 col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="subheader">Verfügbar</div>
                        </div>
                        <div class="h1 mb-0 mt-2"><?php echo (int)($data['stats']['available'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="subheader">Installiert</div>
                        </div>
                        <div class="h1 mb-0 mt-2"><?php echo (int)($data['stats']['installed'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="subheader">Installierbar</div>
                        </div>
                        <div class="h1 mb-0 mt-2"><?php echo (int)($data['stats']['installable'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="subheader">Nur manuell / Anfrage</div>
                        </div>
                        <div class="h1 mb-0 mt-2"><?php echo (int)($data['stats']['manual_only'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search -->
        <div class="card mb-4">
            <div class="card-body">
                <?php if (!empty($constraints['package_size_limit_label']) || !empty($constraints['registry_cache_ttl'])): ?>
                    <div class="small text-muted mb-3">
                        <?php if (!empty($constraints['package_size_limit_label'])): ?>
                            Auto-Install bis <?php echo $escape((string) ($constraints['package_size_limit_label'] ?? '')); ?> Paketgröße
                        <?php endif; ?>
                        <?php if (!empty($constraints['package_size_limit_label']) && !empty($constraints['registry_cache_ttl'])): ?>
                            ·
                        <?php endif; ?>
                        <?php if (!empty($constraints['registry_cache_ttl'])): ?>
                            Registry-Cache: <?php echo (int) ($constraints['registry_cache_ttl'] ?? 0); ?> Sekunden TTL
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($allowedHosts !== [] || !empty($constraints['manifest_max_bytes_label']) || !empty($constraints['archive_uncompressed_limit_label'])): ?>
                    <details class="admin-plugin-marketplace-rules mb-3">
                        <summary class="small text-muted">Technische Installationsregeln ▸</summary>
                        <div class="small text-muted mt-2">
                            <?php if ($allowedHosts !== []): ?>
                                Erlaubte Hosts: <?php echo $escape(implode(', ', $allowedHosts)); ?>
                            <?php endif; ?>
                            <?php if ($allowedHosts !== [] && (!empty($constraints['manifest_max_bytes_label']) || !empty($constraints['archive_uncompressed_limit_label']))): ?>
                                <br>
                            <?php endif; ?>
                            <?php if (!empty($constraints['manifest_max_bytes_label'])): ?>
                                Manifest bis <?php echo $escape((string) ($constraints['manifest_max_bytes_label'] ?? '')); ?>
                            <?php endif; ?>
                            <?php if (!empty($constraints['manifest_max_bytes_label']) && !empty($constraints['archive_uncompressed_limit_label'])): ?>
                                ·
                            <?php endif; ?>
                            <?php if (!empty($constraints['archive_uncompressed_limit_label'])): ?>
                                Archiv entpackt bis <?php echo $escape((string) ($constraints['archive_uncompressed_limit_label'] ?? '')); ?>
                            <?php endif; ?>
                            <?php if ($allowedArchiveExtensions !== []): ?>
                                <br>Erlaubte Archiv-Endungen: <?php echo $escape(implode(', ', $allowedArchiveExtensions)); ?>
                            <?php endif; ?>
                        </div>
                    </details>
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-8">
                        <div class="input-icon">
                            <span class="input-icon-addon">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="10" cy="10" r="7"/><line x1="21" y1="21" x2="15" y2="15"/></svg>
                            </span>
                            <input type="text" id="pluginSearch" aria-label="Plugin suchen" class="form-control" placeholder="Plugin suchen…">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <select id="categoryFilter" aria-label="Nach Kategorie filtern" class="form-select">
                            <option value="">Alle Kategorien</option>
                            <?php foreach ($categoryOptions as $cat): ?>
                                <option value="<?php echo $escape($cat); ?>"><?php echo $escape($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <select id="statusFilter" aria-label="Nach Status filtern" class="form-select">
                            <option value="">Alle Stati</option>
                            <?php foreach ($statusOptions as $statusOption): ?>
                                <option value="<?php echo $escape($statusOption['value'] ?? ''); ?>"><?php echo $escape($statusOption['label'] ?? ''); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Plugin Grid -->
        <div class="row row-deck row-cards" id="pluginGrid">
            <?php if (empty($data['plugins'])): ?>
                <div class="col-12">
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <div class="text-secondary">Keine Plugins im Marketplace verfügbar. Hinterlege optional eine Registry-URL oder eine lokale `index.json` oberhalb von `/plugins`.</div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($data['plugins'] as $plugin):
                    $slug        = $escape($plugin['slug'] ?? '');
                    $name        = $escape($plugin['name'] ?? $slug);
                    $description = $escape($plugin['description'] ?? '');
                    $version     = $escape($plugin['version'] ?? '-');
                    $author      = $escape($plugin['author'] ?? '-');
                    $category    = $escape($plugin['category'] ?? '');
                    $purchaseUrl = $escape((string)($plugin['purchase_url'] ?? ''));
                    $priceAmount = $escape((string)($plugin['price_amount'] ?? ''));
                    $priceCurrency = $escape((string)($plugin['price_currency'] ?? 'EUR'));
                    $requiresCms = $escape((string)($plugin['requires_cms'] ?? ''));
                    $requiresPhp = $escape((string)($plugin['requires_php'] ?? ''));
                    $isInstalled = !empty($plugin['installed']);
                    $isPaid = !empty($plugin['is_paid']);
                    $autoInstallSupported = !empty($plugin['auto_install_supported']);
                    $installReason = $escape((string)($plugin['install_reason'] ?? ''));
                    $hashPresent = !empty($plugin['integrity_hash_present']);
                    $hashShort = $escape((string)($plugin['integrity_hash_short'] ?? ''));
                    $compatibilityReason = $escape((string)($plugin['compatibility_reason'] ?? ''));
                    $packageSizeLabel = $escape((string)($plugin['package_size_label'] ?? ''));
                    $packageSizeAllowed = !empty($plugin['package_size_allowed']);
                    $downloadHostAllowed = !empty($plugin['download_host_allowed']);
                    $downloadExtensionAllowed = !empty($plugin['download_extension_allowed']);
                    $downloadHost = $escape((string)($plugin['download_host'] ?? ''));
                    $status = $isInstalled ? 'installed' : ($autoInstallSupported ? 'installable' : 'manual');
                ?>
                <div class="col-sm-6 col-lg-4 plugin-card" data-name="<?php echo $name; ?>" data-category="<?php echo $category; ?>" data-status="<?php echo $escape($status); ?>">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-3">
                                <div class="avatar bg-primary-lt me-3">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7l0 10"/><path d="M20 7l0 10"/><path d="M12 3l0 18"/><path d="M3 17l4 -4l-4 -4"/><path d="M21 17l-4 -4l4 -4"/><path d="M11 7l2 -4l2 4"/></svg>
                                </div>
                                <div>
                                    <h3 class="card-title mb-0"><?php echo $name; ?></h3>
                                    <?php if ($category): ?>
                                        <span class="badge bg-azure-lt"><?php echo $category; ?></span>
                                    <?php endif; ?>
                                    <?php if ($isPaid): ?>
                                        <span class="badge bg-orange-lt">Kostenpflichtig</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <p class="text-secondary"><?php echo $description; ?></p>
                            <?php if ($priceAmount !== ''): ?>
                                <div class="mb-2"><span class="badge bg-orange-lt"><?php echo $priceAmount . ' ' . $priceCurrency; ?></span></div>
                            <?php endif; ?>
                            <?php if ($requiresCms !== '' || $requiresPhp !== ''): ?>
                                <div class="text-muted small mb-2">
                                    <?php if ($requiresCms !== ''): ?>365CMS ab <?php echo $requiresCms; ?><?php endif; ?>
                                    <?php if ($requiresCms !== '' && $requiresPhp !== ''): ?> · <?php endif; ?>
                                    <?php if ($requiresPhp !== ''): ?>PHP ab <?php echo $requiresPhp; ?><?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($packageSizeLabel !== '' || $downloadHost !== ''): ?>
                                <div class="text-muted small mb-2">
                                    <?php if ($packageSizeLabel !== ''): ?>Paket: <?php echo $packageSizeLabel; ?><?php endif; ?>
                                    <?php if ($packageSizeLabel !== '' && $downloadHost !== ''): ?> · <?php endif; ?>
                                    <?php if ($downloadHost !== ''): ?>Host: <?php echo $downloadHost; ?><?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="text-muted small">
                                    v<?php echo $version; ?> · <?php echo $author; ?>
                                    <?php if ($hashPresent): ?>
                                        <div class="mt-1"><span class="badge bg-green-lt">SHA-256 <?php echo $hashShort; ?></span></div>
                                    <?php else: ?>
                                        <div class="mt-1"><span class="badge bg-warning-lt">Auto-Install gesperrt</span></div>
                                    <?php endif; ?>
                                    <?php if (!$packageSizeAllowed): ?>
                                        <div class="mt-1"><span class="badge bg-warning-lt">Paket zu groß für Auto-Install</span></div>
                                    <?php endif; ?>
                                    <?php if ($downloadHost !== '' && !$downloadHostAllowed): ?>
                                        <div class="mt-1"><span class="badge bg-warning-lt">Host nicht freigegeben</span></div>
                                    <?php endif; ?>
                                    <?php if (!$downloadExtensionAllowed && $downloadHost !== ''): ?>
                                        <div class="mt-1"><span class="badge bg-warning-lt">Archiv-Endung nicht erlaubt</span></div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($isInstalled): ?>
                                    <span class="badge bg-green-lt">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm me-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10"/></svg>
                                        Installiert
                                    </span>
                                <?php elseif ($autoInstallSupported): ?>
                                    <form method="POST" style="display:inline;" data-marketplace-install-form="1" data-confirm-message="Plugin '<?php echo $name; ?>' installieren?" data-confirm-title="Plugin installieren" data-confirm-text="Installieren" data-confirm-class="btn-primary" data-confirm-status-class="bg-primary">
                                        <input type="hidden" name="csrf_token" value="<?php echo $escape($csrfToken); ?>">
                                        <input type="hidden" name="action" value="install">
                                        <input type="hidden" name="slug" value="<?php echo $slug; ?>">
                                        <button type="submit" class="btn btn-primary btn-sm" data-marketplace-submit-button data-submitting-text="Installiere…">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm me-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/><polyline points="7 11 12 16 17 11"/><line x1="12" y1="4" x2="12" y2="16"/></svg>
                                            Installieren
                                        </button>
                                    </form>
                                <?php elseif ($isPaid && $purchaseUrl !== ''): ?>
                                    <div class="text-end">
                                        <a href="<?php echo $purchaseUrl; ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm mb-2">
                                            Anfragen / Kaufen
                                        </a>
                                        <?php if ($compatibilityReason !== ''): ?>
                                            <div class="text-warning small mb-2" style="max-width: 220px;"><?php echo $compatibilityReason; ?></div>
                                        <?php endif; ?>
                                        <div class="text-muted small" style="max-width: 220px;"><?php echo $installReason; ?></div>
                                    </div>
                                <?php else: ?>
                                    <div class="text-end">
                                        <span class="badge bg-secondary-lt mb-2">Nur manuell</span>
                                        <?php if ($compatibilityReason !== ''): ?>
                                            <div class="text-warning small mb-2" style="max-width: 220px;"><?php echo $compatibilityReason; ?></div>
                                        <?php endif; ?>
                                        <div class="text-muted small" style="max-width: 220px;"><?php echo $installReason; ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card d-none mt-4" id="pluginMarketplaceEmptyState">
            <div class="card-body text-center py-5 text-secondary">
                Keine Plugins für den aktuellen Such-/Filterzustand gefunden.
            </div>
        </div>

    </div>
</div>

<script type="application/json" id="plugin-marketplace-config"><?php echo json_encode($pluginMarketplaceConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
