<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * View: Landing Page – Tab-basierte Verwaltung
 *
 * @var array  $data
 * @var string $csrfToken
 * @var array|null $alert
 */

$activeTab = (string) ($data['activeTab'] ?? 'header');
$tabs = is_array($data['tabs'] ?? null) ? $data['tabs'] : [];
$fieldValue = static fn (array $source, string $key, string $default = ''): string => htmlspecialchars((string) ($source[$key] ?? $default), ENT_QUOTES, 'UTF-8');
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>

<div class="container-xl">
    <!-- Header -->
    <div class="page-header d-flex align-items-center mb-4">
        <div>
            <h2 class="page-title">Landing Page</h2>
            <div class="text-muted mt-1">Startseite konfigurieren</div>
        </div>
    </div>

    <?php if (!empty($alert)): ?>
        <?php $alertData = $alert; $alertMarginClass = 'mb-3'; require __DIR__ . '/../partials/flash-alert.php'; ?>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="card">
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs">
                <?php foreach ($tabs as $tabInfo): ?>
                    <?php
                    $tabKey = (string) ($tabInfo['key'] ?? 'header');
                    $tabLabel = (string) ($tabInfo['label'] ?? ucfirst($tabKey));
                    $tabUrl = (string) ($tabInfo['url'] ?? '/admin/landing-page');
                    ?>
                    <li class="nav-item">
                        <a class="nav-link<?php echo $activeTab === $tabKey ? ' active' : ''; ?>"
                           href="<?php echo htmlspecialchars($tabUrl, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($tabLabel); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="card-body">
            <?php if ($activeTab === 'header'): ?>
                <?php
                $header = $data['header'] ?? [];
                ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="save_header">
                    <input type="hidden" name="active_tab" value="header">

                    <div class="mb-3">
                        <label for="fld-title" class="form-label">Titel</label>
                        <input id="fld-title" type="text" name="title" class="form-control" value="<?php echo $fieldValue($header, 'title'); ?>">
                    </div>
                    <div class="mb-3">
                        <label for="fld-subtitle" class="form-label">Untertitel</label>
                        <input id="fld-subtitle" type="text" name="subtitle" class="form-control" value="<?php echo $fieldValue($header, 'subtitle'); ?>">
                    </div>
                    <div class="mb-3">
                        <label for="fld-badge_text" class="form-label">Badge-Text</label>
                        <input id="fld-badge_text" type="text" name="badge_text" class="form-control" value="<?php echo $fieldValue($header, 'badge_text'); ?>" maxlength="60">
                        <div class="form-hint">Freier Text für das Hero-Badge, z. B. „Beta“, „Neu“ oder „v2.7“. Wenn das Feld leer bleibt, wird das Badge im Frontend ausgeblendet.</div>
                    </div>
                    <div class="mb-3">
                        <label for="fld-description" class="form-label">Beschreibung</label>
                        <textarea id="fld-description" name="description" class="form-control" rows="3"><?php echo $fieldValue($header, 'description'); ?></textarea>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="fld-cta_text" class="form-label">CTA Button Text</label>
                            <input id="fld-cta_text" type="text" name="cta_text" class="form-control" value="<?php echo $fieldValue($header, 'cta_text'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="fld-cta_url" class="form-label">CTA Button URL</label>
                            <input id="fld-cta_url" type="text" name="cta_url" class="form-control" value="<?php echo $fieldValue($header, 'cta_url'); ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="fld-bg_image" class="form-label">Hintergrundbild URL</label>
                        <input id="fld-bg_image" type="text" name="bg_image" class="form-control" value="<?php echo $fieldValue($header, 'bg_image'); ?>">
                    </div>

                    <button type="submit" class="btn btn-primary">Speichern</button>
                </form>

            <?php elseif ($activeTab === 'content'): ?>
                <?php
                $content  = $data['content'] ?? [];
                $contentTypeOptions = is_array($data['contentTypeOptions'] ?? null) ? $data['contentTypeOptions'] : [];
                $featureCards = is_array($data['featureCards'] ?? null) ? $data['featureCards'] : [];
                ?>
                <form method="post" class="mb-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="save_content">
                    <input type="hidden" name="active_tab" value="content">

                    <div class="mb-3">
                        <label for="fld-content_type" class="form-label">Inhaltstyp</label>
                        <select id="fld-content_type" name="content_type" class="form-select">
                            <?php foreach ($contentTypeOptions as $option): ?>
                                <option value="<?php echo $escape($option['value'] ?? ''); ?>" <?php echo !empty($option['selected']) ? 'selected' : ''; ?>><?php echo $escape($option['label'] ?? ''); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="fld-content_text" class="form-label">Freitext-Inhalt</label>
                        <textarea id="fld-content_text" name="content_text" class="form-control" rows="5" placeholder="Wird nur verwendet, wenn ‚Freitext-Bereich‘ ausgewählt ist."><?php echo htmlspecialchars($content['content_text'] ?? ''); ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="fld-posts_count" class="form-label">Anzahl Beiträge</label>
                        <input id="fld-posts_count" type="number" name="posts_count" min="1" max="50" class="form-control" value="<?php echo (int)($content['posts_count'] ?? 5); ?>">
                        <div class="form-hint">Wird nur verwendet, wenn „Aktuelle Beiträge“ aktiv ist.</div>
                    </div>

                    <button type="submit" class="btn btn-primary">Content-Einstellungen speichern</button>
                </form>

                <hr class="my-4">
                <h3 class="mb-3">Features / Kacheln</h3>

                <?php if (!empty($featureCards)): ?>
                    <div class="row row-cards mb-3">
                        <?php foreach ($featureCards as $feature): ?>
                            <div class="col-md-4 mb-3">
                                <div class="card">
                                    <div class="card-body">
                                        <div class="d-flex align-items-start gap-3">
                                            <div class="fs-1 lh-1"><?php echo $escape($feature['icon'] ?? '🧩'); ?></div>
                                            <div>
                                                <h4 class="mb-1"><?php echo $escape($feature['title'] ?? ''); ?></h4>
                                                <p class="text-muted small mb-2"><?php echo $escape($feature['description'] ?? ''); ?></p>
                                                <span class="badge bg-azure-lt">Reihenfolge <?php echo (int)($feature['sort_order'] ?? 0); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-footer d-flex gap-2">
                                            <form method="post" class="d-inline"
                                                data-confirm-message="Feature wirklich löschen?"
                                                data-confirm-title="Feature löschen"
                                                data-confirm-confirm="Löschen"
                                                data-confirm-cancel="Abbrechen">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="delete_feature">
                                            <input type="hidden" name="active_tab" value="content">
                                            <input type="hidden" name="feature_id" value="<?php echo (int)($feature['id'] ?? 0); ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" <?php echo !empty($feature['delete_disabled']) ? 'disabled' : ''; ?>>Löschen</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="card bg-light">
                    <div class="card-header"><h4 class="card-title">Neues Feature hinzufügen</h4></div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="action" value="save_feature">
                            <input type="hidden" name="active_tab" value="content">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Titel</label>
                                    <input type="text" name="title" class="form-control" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="fld-icon" class="form-label">Icon (CSS-Klasse)</label>
                                    <input id="fld-icon" type="text" name="icon" class="form-control" placeholder="z.B. icon-star">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="fld-sort_order" class="form-label">Reihenfolge</label>
                                    <input id="fld-sort_order" type="number" name="sort_order" class="form-control" value="0">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Beschreibung</label>
                                <textarea name="description" class="form-control" rows="2"></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">Feature hinzufügen</button>
                        </form>
                    </div>
                </div>

            <?php elseif ($activeTab === 'footer'): ?>
                <?php
                $footer = $data['footer'] ?? [];
                ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="save_footer">
                    <input type="hidden" name="active_tab" value="footer">

                    <div class="mb-3">
                        <label class="form-check form-switch">
                            <input type="checkbox" name="show_footer" class="form-check-input" value="1" <?php echo !empty($footer['show_footer']) ? 'checked' : ''; ?>>
                            <span class="form-check-label">Landing-Footer anzeigen</span>
                        </label>
                    </div>

                    <div class="mb-3">
                        <label for="fld-footer_content" class="form-label">Footer-Inhalt</label>
                        <textarea id="fld-footer_content" name="footer_content" class="form-control" rows="5"><?php echo htmlspecialchars($footer['content'] ?? ''); ?></textarea>
                        <div class="form-hint">Kurzer CTA oder Zusammenfassung der wichtigsten 365CMS-Features.</div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="fld-footer_button_text" class="form-label">Button-Text</label>
                            <input id="fld-footer_button_text" type="text" name="footer_button_text" class="form-control" value="<?php echo $fieldValue($footer, 'button_text'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="fld-footer_button_url" class="form-label">Button-URL</label>
                            <input id="fld-footer_button_url" type="text" name="footer_button_url" class="form-control" value="<?php echo $fieldValue($footer, 'button_url'); ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="fld-footer_copyright" class="form-label">Copyright-Text</label>
                        <input id="fld-footer_copyright" type="text" name="footer_copyright" class="form-control" value="<?php echo $fieldValue($footer, 'copyright'); ?>">
                    </div>

                    <button type="submit" class="btn btn-primary">Speichern</button>
                </form>

            <?php elseif ($activeTab === 'design'): ?>
                <?php
                $design = $data['design'] ?? [];
                $colors = $data['colors'] ?? [];
                ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="save_design">
                    <input type="hidden" name="active_tab" value="design">

                    <h4 class="mb-3">Landing-Farben</h4>
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3">
                            <label for="fld-hero_gradient_start" class="form-label">Hero-Verlauf Start</label>
                            <input id="fld-hero_gradient_start" type="color" name="hero_gradient_start" class="form-control form-control-color" value="<?php echo htmlspecialchars($colors['hero_gradient_start'] ?? '#1e293b'); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="fld-hero_gradient_end" class="form-label">Hero-Verlauf Ende</label>
                            <input id="fld-hero_gradient_end" type="color" name="hero_gradient_end" class="form-control form-control-color" value="<?php echo htmlspecialchars($colors['hero_gradient_end'] ?? '#0f172a'); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="fld-hero_border" class="form-label">Hero-Rand</label>
                            <input id="fld-hero_border" type="color" name="hero_border" class="form-control form-control-color" value="<?php echo htmlspecialchars($colors['hero_border'] ?? '#3b82f6'); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="fld-hero_text" class="form-label">Hero-Text</label>
                            <input id="fld-hero_text" type="color" name="hero_text" class="form-control form-control-color" value="<?php echo htmlspecialchars($colors['hero_text'] ?? '#ffffff'); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="fld-features_bg" class="form-label">Kachel-Bereich</label>
                            <input id="fld-features_bg" type="color" name="features_bg" class="form-control form-control-color" value="<?php echo htmlspecialchars($colors['features_bg'] ?? '#f8fafc'); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="fld-feature_card_bg" class="form-label">Kachel-Hintergrund</label>
                            <input id="fld-feature_card_bg" type="color" name="feature_card_bg" class="form-control form-control-color" value="<?php echo htmlspecialchars($colors['feature_card_bg'] ?? '#ffffff'); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="fld-feature_card_hover" class="form-label">Kachel-Hover</label>
                            <input id="fld-feature_card_hover" type="color" name="feature_card_hover" class="form-control form-control-color" value="<?php echo htmlspecialchars($colors['feature_card_hover'] ?? '#3b82f6'); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="fld-primary_button" class="form-label">Primärer Button</label>
                            <input id="fld-primary_button" type="color" name="primary_button" class="form-control form-control-color" value="<?php echo htmlspecialchars($colors['primary_button'] ?? '#3b82f6'); ?>">
                        </div>
                    </div>

                    <h4 class="mb-3">Layout & Karten</h4>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label for="fld-card_icon_layout" class="form-label">Icon-Ausrichtung</label>
                            <select id="fld-card_icon_layout" name="card_icon_layout" class="form-select">
                                <option value="top" <?php echo ($design['card_icon_layout'] ?? 'top') === 'top' ? 'selected' : ''; ?>>Oben</option>
                                <option value="left" <?php echo ($design['card_icon_layout'] ?? '') === 'left' ? 'selected' : ''; ?>>Links</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="fld-feature_columns" class="form-label">Feature-Spalten</label>
                            <select id="fld-feature_columns" name="feature_columns" class="form-select">
                                <option value="auto" <?php echo ($design['feature_columns'] ?? 'auto') === 'auto' ? 'selected' : ''; ?>>Automatisch responsiv</option>
                                <option value="2" <?php echo ($design['feature_columns'] ?? '') === '2' ? 'selected' : ''; ?>>2 Spalten</option>
                                <option value="3" <?php echo ($design['feature_columns'] ?? '') === '3' ? 'selected' : ''; ?>>3 Spalten</option>
                                <option value="4" <?php echo ($design['feature_columns'] ?? '') === '4' ? 'selected' : ''; ?>>4 Spalten</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-3 mb-3">
                            <label for="fld-card_border_radius" class="form-label">Kartenradius</label>
                            <input id="fld-card_border_radius" type="number" name="card_border_radius" min="0" max="48" class="form-control" value="<?php echo (int)($design['card_border_radius'] ?? 18); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="fld-button_border_radius" class="form-label">Button-Radius</label>
                            <input id="fld-button_border_radius" type="number" name="button_border_radius" min="0" max="50" class="form-control" value="<?php echo (int)($design['button_border_radius'] ?? 12); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="fld-card_border_width" class="form-label">Kartenrand</label>
                            <select id="fld-card_border_width" name="card_border_width" class="form-select">
                                <option value="0" <?php echo ($design['card_border_width'] ?? '1px') === '0' ? 'selected' : ''; ?>>Kein Rand</option>
                                <option value="1px" <?php echo ($design['card_border_width'] ?? '1px') === '1px' ? 'selected' : ''; ?>>1px</option>
                                <option value="2px" <?php echo ($design['card_border_width'] ?? '') === '2px' ? 'selected' : ''; ?>>2px</option>
                                <option value="3px" <?php echo ($design['card_border_width'] ?? '') === '3px' ? 'selected' : ''; ?>>3px</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="fld-card_shadow" class="form-label">Karten-Schatten</label>
                            <select id="fld-card_shadow" name="card_shadow" class="form-select">
                                <option value="none" <?php echo ($design['card_shadow'] ?? 'md') === 'none' ? 'selected' : ''; ?>>Keiner</option>
                                <option value="sm" <?php echo ($design['card_shadow'] ?? '') === 'sm' ? 'selected' : ''; ?>>Klein</option>
                                <option value="md" <?php echo ($design['card_shadow'] ?? 'md') === 'md' ? 'selected' : ''; ?>>Mittel</option>
                                <option value="lg" <?php echo ($design['card_shadow'] ?? '') === 'lg' ? 'selected' : ''; ?>>Groß</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-4 mb-3">
                            <label for="fld-card_border_color" class="form-label">Karten-Randfarbe</label>
                            <input id="fld-card_border_color" type="color" name="card_border_color" class="form-control form-control-color" value="<?php echo htmlspecialchars($design['card_border_color'] ?? '#e2e8f0'); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="fld-footer_bg" class="form-label">Footer-Hintergrund</label>
                            <input id="fld-footer_bg" type="color" name="footer_bg" class="form-control form-control-color" value="<?php echo htmlspecialchars($design['footer_bg'] ?? '#0f172a'); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="fld-footer_text_color" class="form-label">Footer-Textfarbe</label>
                            <input id="fld-footer_text_color" type="color" name="footer_text_color" class="form-control form-control-color" value="<?php echo htmlspecialchars($design['footer_text_color'] ?? '#cbd5e1'); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="fld-content_section_bg" class="form-label">Content-Hintergrund</label>
                            <input id="fld-content_section_bg" type="color" name="content_section_bg" class="form-control form-control-color" value="<?php echo htmlspecialchars($design['content_section_bg'] ?? '#ffffff'); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="fld-hero_padding" class="form-label">Hero-Padding</label>
                            <select id="fld-hero_padding" name="hero_padding" class="form-select">
                                <option value="sm" <?php echo ($design['hero_padding'] ?? 'md') === 'sm' ? 'selected' : ''; ?>>Klein</option>
                                <option value="md" <?php echo ($design['hero_padding'] ?? 'md') === 'md' ? 'selected' : ''; ?>>Mittel</option>
                                <option value="lg" <?php echo ($design['hero_padding'] ?? '') === 'lg' ? 'selected' : ''; ?>>Groß</option>
                                <option value="xl" <?php echo ($design['hero_padding'] ?? '') === 'xl' ? 'selected' : ''; ?>>XL</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="fld-feature_padding" class="form-label">Feature-Padding</label>
                            <select id="fld-feature_padding" name="feature_padding" class="form-select">
                                <option value="sm" <?php echo ($design['feature_padding'] ?? 'md') === 'sm' ? 'selected' : ''; ?>>Klein</option>
                                <option value="md" <?php echo ($design['feature_padding'] ?? 'md') === 'md' ? 'selected' : ''; ?>>Mittel</option>
                                <option value="lg" <?php echo ($design['feature_padding'] ?? '') === 'lg' ? 'selected' : ''; ?>>Groß</option>
                                <option value="xl" <?php echo ($design['feature_padding'] ?? '') === 'xl' ? 'selected' : ''; ?>>XL</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Speichern</button>
                </form>

            <?php elseif ($activeTab === 'plugins'): ?>
                <?php
                $pluginCards = is_array($data['pluginCards'] ?? null) ? $data['pluginCards'] : [];
                ?>

                <?php if (empty($pluginCards)): ?>
                    <div class="text-center py-5 text-muted">
                        <p>Keine Plugins haben Landing-Page-Bereiche registriert.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($pluginCards as $plugin): ?>
                        <div class="card mb-3">
                            <div class="card-header">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <h4 class="card-title mb-0"><?php echo $escape($plugin['title'] ?? $plugin['id'] ?? ''); ?></h4>
                                    <?php if (!empty($plugin['version'])): ?>
                                        <span class="badge bg-azure-lt">v<?php echo $escape($plugin['version']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($plugin['author'])): ?>
                                        <span class="text-muted small">von <?php echo $escape($plugin['author']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($plugin['description'])): ?>
                                    <p class="text-muted mb-3"><?php echo $escape($plugin['description']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($plugin['targets'])): ?>
                                    <div class="mb-3 d-flex flex-wrap gap-2">
                                        <?php foreach ((array)($plugin['targets'] ?? []) as $target): ?>
                                            <span class="badge <?php echo !empty($target['assigned']) ? 'bg-primary-lt text-primary' : 'bg-secondary-lt'; ?>">
                                                <?php echo $escape($target['label'] ?? ''); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="mb-3 small text-muted">
                                    <?php if (!empty($plugin['assigned_labels'])): ?>
                                        Aktiv in: <?php echo $escape(implode(', ', (array)($plugin['assigned_labels'] ?? []))); ?>
                                    <?php else: ?>
                                        Aktuell ist dieses Plugin keinem Landing-Bereich zugewiesen.
                                    <?php endif; ?>
                                </div>
                                <?php if (empty($plugin['has_render_callback'])): ?>
                                    <div class="alert alert-warning" role="alert">
                                        Dieses Plugin ist zwar registriert, liefert aber noch keinen renderbaren Landing-Block. Eine Bereichs-Zuweisung bleibt deshalb deaktiviert, bis ein `render_callback` vorhanden ist.
                                    </div>
                                <?php endif; ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="action" value="save_plugin">
                                    <input type="hidden" name="active_tab" value="plugins">
                                    <input type="hidden" name="plugin_id" value="<?php echo $escape($plugin['id'] ?? ''); ?>">

                                    <div class="mb-3">
                                        <label class="form-label">Landing-Bereiche</label>
                                        <div class="d-flex flex-column gap-2">
                                            <?php foreach ((array)($plugin['targets'] ?? []) as $target): ?>
                                                <label class="form-check">
                                                    <input type="checkbox"
                                                           name="areas[]"
                                                           class="form-check-input"
                                                           value="<?php echo $escape($target['slug'] ?? ''); ?>"
                                                           <?php echo !empty($target['assigned']) ? 'checked' : ''; ?>
                                                           <?php echo empty($plugin['has_render_callback']) ? 'disabled' : ''; ?>>
                                                    <span class="form-check-label">
                                                        <?php echo $escape($target['label'] ?? ''); ?>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="form-hint mt-2">Pro Landing-Bereich ist genau ein aktives Plugin möglich. Eine neue Zuweisung ersetzt die bisherige Belegung dieses Bereichs.</div>
                                    </div>

                                    <button type="submit" class="btn btn-primary btn-sm" <?php echo empty($plugin['has_render_callback']) ? 'disabled' : ''; ?>>Zuweisung speichern</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</div>
