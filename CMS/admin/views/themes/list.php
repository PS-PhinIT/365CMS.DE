<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * View: Themes – Liste aller installierten Themes
 *
 * @var array  $data
 * @var string $csrfToken
 * @var array|null $alert
 */

$themes      = $data['themes'] ?? [];
$activeSlug  = $data['activeSlug'] ?? '';
$totalThemes = $data['totalThemes'] ?? 0;
$activeCount = 0;
foreach ($themes as $themeItem) {
    if (!empty($themeItem['isActive'])) {
        $activeCount++;
    }
}
$inactiveCount = max(0, (int)$totalThemes - $activeCount);
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$resolvePreviewColor = static function (array $theme): string {
    $candidates = [
        $theme['primary_color'] ?? null,
        $theme['color_primary'] ?? null,
        $theme['accent_color'] ?? null,
        $theme['json']['primary_color'] ?? null,
        $theme['json']['color_primary'] ?? null,
    ];

    if (isset($theme['json']['settings']['color']['palette']) && is_array($theme['json']['settings']['color']['palette'])) {
        foreach ($theme['json']['settings']['color']['palette'] as $paletteColor) {
            if (is_array($paletteColor) && isset($paletteColor['color'])) {
                $candidates[] = $paletteColor['color'];
            }
        }
    }

    foreach ($candidates as $candidate) {
        $value = trim((string) $candidate);
        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1) {
            return $value;
        }
    }

    return '#64748b';
};
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">Themes</div>
                <h2 class="page-title mb-1">Theme-Verwaltung</h2>
                <div class="content-listing-header__meta">
                    <span><?php echo (int) $totalThemes; ?> installiert</span>
                    <span>Aktiv: <?php echo $escape($activeSlug !== '' ? $activeSlug : 'unbekannt'); ?></span>
                </div>
            </div>
            <div class="admin-section-toolbar__actions">
                <a href="/admin/theme-marketplace" class="btn btn-outline-primary btn-sm">Marketplace</a>
                <a href="/admin/theme-settings" class="btn btn-outline-secondary btn-sm">Design-Einstellungen</a>
            </div>
        </div>
    </div>
</div>

<div class="page-body themes-list-page">
<div class="container-xl">
    <?php if (!empty($alert)): ?>
        <?php
        $alertData = is_array($alert ?? null) ? $alert : [];
        require dirname(__DIR__) . '/partials/flash-alert.php';
        ?>
    <?php endif; ?>

    <div class="cms-admin-info-box mb-3" role="note">
        <div class="cms-admin-info-box__head">
            <h3 class="cms-admin-info-box__title">Theme-Runtime und Verwaltung</h3>
            <div class="cms-admin-info-box__actions">
                <a href="/admin/theme-editor" class="btn btn-sm btn-outline-secondary">Theme-Editor</a>
                <a href="/admin/theme-explorer" class="btn btn-sm btn-outline-secondary">Theme-Explorer</a>
            </div>
        </div>
        <p class="cms-admin-info-box__text">
            Aktiviere verfügbare Themes oder entferne nicht mehr benötigte Themes direkt aus der Übersicht.
        </p>
    </div>

    <div class="card content-listing-card mb-4">
        <div class="card-header content-listing-toolbar">
            <div class="content-listing-toolbar__label">Installierte Themes</div>
            <div class="content-listing-filters">
                <div class="content-listing-filters__actions">
                    <span class="text-secondary small">Aktiv: <strong><?php echo $activeCount; ?></strong></span>
                    <span class="text-secondary small">Inaktiv: <strong><?php echo $inactiveCount; ?></strong></span>
                    <span class="text-secondary small">Alle installierten Themes mit Aktivierungs- und Verwaltungsaktionen.</span>
                </div>
            </div>
        </div>
        <div class="card-body">
    <div class="admin-theme-list-grid">
        <?php foreach ($themes as $slug => $theme): ?>
            <?php
            $themeName = (string) ($theme['name'] ?? $slug);
            $themeFolder = (string) ($theme['folder'] ?? $slug);
            $previewCandidates = [];
            $configuredScreenshot = trim((string) ($theme['screenshot'] ?? ''));
            if ($configuredScreenshot !== '') {
                $previewCandidates[] = $configuredScreenshot;
            }
            // Lokale Vorschaubilder nur anbieten, wenn die Datei existiert (sonst 404 in der Browser-Konsole).
            foreach (['screenshot.png', 'preview.png'] as $previewFile) {
                if (is_file(ABSPATH . 'themes/' . $themeFolder . '/' . $previewFile)) {
                    $previewCandidates[] = '/themes/' . rawurlencode($themeFolder) . '/' . $previewFile;
                }
            }
            $previewCandidates = array_values(array_unique(array_filter(array_map('strval', $previewCandidates))));
            $previewPrimaryColor = $resolvePreviewColor(is_array($theme) ? $theme : []);
            $previewGradientEnd = 'rgba(15, 23, 42, 0.82)';
            ?>
            <div class="card h-100 admin-theme-list-card<?php echo !empty($theme['isActive']) ? ' border-primary' : ''; ?>">
                    <div class="card-img-top admin-theme-preview" data-theme-preview-root>
                        <img
                            src="<?php echo $escape($previewCandidates[0] ?? ''); ?>"
                            alt="<?php echo $escape($themeName); ?>"
                            class="admin-theme-preview__image"
                            data-theme-preview-image
                            data-preview-candidates="<?php echo $escape((string) json_encode($previewCandidates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>"
                            <?php echo $previewCandidates === [] ? 'hidden' : ''; ?>
                        >
                        <div class="admin-theme-preview__fallback"
                             data-theme-preview-fallback
                             style="background:linear-gradient(135deg, <?php echo $escape($previewPrimaryColor); ?> 0%, <?php echo $escape($previewGradientEnd); ?> 100%);"
                             <?php echo $previewCandidates === [] ? '' : 'hidden'; ?>>
                            <span class="admin-theme-preview__fallback-text"><?php echo $escape($themeName); ?></span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-2">
                            <h3 class="card-title mb-0"><?php echo $escape($themeName); ?></h3>
                            <?php if (!empty($theme['isActive'])): ?>
                                <span class="badge bg-primary ms-2">Aktiv</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($theme['description'])): ?>
                            <p class="text-muted mb-2"><?php echo $escape($theme['description']); ?></p>
                        <?php endif; ?>
                        <div class="text-muted small">
                            <?php if (!empty($theme['version'])): ?>
                                <span class="me-3">v<?php echo $escape($theme['version']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($theme['author'])): ?>
                                <span><?php echo $escape($theme['author']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-footer d-flex gap-2">
                        <?php if (empty($theme['isActive'])): ?>
                            <form method="post" class="d-inline" data-theme-submit-lock="1">
                                <input type="hidden" name="csrf_token" value="<?php echo $escape($csrfToken); ?>">
                                <input type="hidden" name="action" value="activate">
                                <input type="hidden" name="theme" value="<?php echo $escape($slug); ?>">
                                <button type="submit" class="btn btn-primary btn-sm" data-submitting-text="Aktiviere…">Aktivieren</button>
                            </form>
                            <form method="post"
                                  class="d-inline"
                                  data-theme-submit-lock="1"
                                  data-confirm-message="Soll das Theme &quot;<?php echo $escape($theme['name'] ?? $slug); ?>&quot; wirklich gelöscht werden? Diese Aktion kann nicht rückgängig gemacht werden."
                                  data-confirm-title="Theme löschen"
                                  data-confirm-text="Löschen"
                                  data-confirm-class="btn-danger"
                                  data-confirm-status-class="bg-danger">
                                <input type="hidden" name="csrf_token" value="<?php echo $escape($csrfToken); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="theme" value="<?php echo $escape($slug); ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm" data-submitting-text="Lösche…">
                                    Löschen
                                </button>
                            </form>
                        <?php else: ?>
                            <a href="/admin/theme-editor" class="btn btn-outline-primary btn-sm">Editor</a>
                            <a href="/admin/theme-explorer" class="btn btn-outline-secondary btn-sm">Explorer</a>
                        <?php endif; ?>
                    </div>
                </div>
        <?php endforeach; ?>
    </div>
        </div>
    </div>
</div>
</div>
