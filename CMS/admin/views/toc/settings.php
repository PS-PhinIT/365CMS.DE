<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * TOC – Einstellungen View
 *
 * Erwartet: $settings (Array), $csrfToken, $alert
 */

$settings = is_array($settings ?? null)
    ? $settings
    : (is_array($data['settings'] ?? null) ? $data['settings'] : []);
$s = $settings;
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">Seiten &amp; Beiträge</div>
                <h2 class="page-title mb-1">Inhaltsverzeichnis</h2>
                <div class="content-listing-header__meta">
                    <span>Zentrale TOC-Standards</span>
                    <span>Darstellung, Scroll-Verhalten und Sonderregeln</span>
                </div>
            </div>
            <button type="submit" form="tocSettingsForm" class="btn btn-primary">Einstellungen speichern</button>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl cms-settings-page">

        <?php if (!empty($alert)): ?>
            <?php
            $alertData = is_array($alert ?? null) ? $alert : [];
            $alertMarginClass = 'mb-3';
            require dirname(__DIR__) . '/partials/flash-alert.php';
            unset($alertMarginClass);
            ?>
        <?php endif; ?>

        <form method="post" action="<?php echo htmlspecialchars('/admin/table-of-contents'); ?>" id="tocSettingsForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="save">

            <div class="cms-settings-actions">
                <span class="text-secondary small me-auto">TOC-Standardverhalten zuerst festlegen, Darstellung und Sonderfälle danach.</span>
                <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
            </div>

            <div class="row">
                <!-- Hauptbereich -->
                <div class="col-12">
                    <h3 class="cms-settings-section-heading">Primäre Einstellungen</h3>

                    <!-- Allgemein -->
                    <div class="card mb-3">
                        <div class="card-header">
                            <h3 class="card-title">Allgemeine Einstellungen</h3>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Unterstützte Inhaltstypen</label>
                                <div>
                                    <?php foreach (['post' => 'Beiträge', 'page' => 'Seiten'] as $val => $label): ?>
                                        <label class="form-check form-check-inline">
                                            <input class="form-check-input" type="checkbox" name="support_types[]" value="<?php echo $val; ?>"
                                                <?php echo in_array($val, $s['support_types'] ?? []) ? 'checked' : ''; ?>>
                                            <span class="form-check-label"><?php echo $label; ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Automatisch einfügen bei</label>
                                <div>
                                    <?php foreach (['post' => 'Beiträge', 'page' => 'Seiten'] as $val => $label): ?>
                                        <label class="form-check form-check-inline">
                                            <input class="form-check-input" type="checkbox" name="auto_insert_types[]" value="<?php echo $val; ?>"
                                                <?php echo in_array($val, $s['auto_insert_types'] ?? []) ? 'checked' : ''; ?>>
                                            <span class="form-check-label"><?php echo $label; ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="position">Position</label>
                                    <select class="form-select" id="position" name="position">
                                        <option value="before" <?php echo ($s['position'] ?? '') === 'before' ? 'selected' : ''; ?>>Vor der ersten Überschrift</option>
                                        <option value="after" <?php echo ($s['position'] ?? '') === 'after' ? 'selected' : ''; ?>>Nach der ersten Überschrift</option>
                                    </select>
                                    <span class="form-hint">Für eine exakte Platzierung im Fließtext kannst du weiterhin <code>[cms_toc]</code> verwenden.</span>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="show_limit">Mindestanzahl Überschriften</label>
                                    <input type="number" class="form-control" id="show_limit" name="show_limit" min="1" max="20" value="<?php echo (int)($s['show_limit'] ?? 4); ?>">
                                    <span class="form-hint">TOC nur anzeigen, wenn mindestens X Überschriften vorhanden</span>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Überschriften-Ebenen</label>
                                <div>
                                    <?php foreach (['h2', 'h3', 'h4', 'h5', 'h6'] as $h): ?>
                                        <label class="form-check form-check-inline">
                                            <input class="form-check-input" type="checkbox" name="headings[]" value="<?php echo $h; ?>"
                                                <?php echo in_array($h, $s['headings'] ?? []) ? 'checked' : ''; ?>>
                                            <span class="form-check-label"><?php echo strtoupper($h); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Darstellung -->
                    <div class="card mb-3">
                        <div class="card-header">
                            <h3 class="card-title">Darstellung</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="header_label">Überschrift</label>
                                    <input type="text" class="form-control" id="header_label" name="header_label" value="<?php echo htmlspecialchars($s['header_label'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="theme">Theme</label>
                                    <select class="form-select" id="theme" name="theme">
                                        <?php foreach (['grey' => 'Grau', 'light' => 'Hell', 'dark' => 'Dunkel', 'transparent' => 'Transparent', 'custom' => 'Benutzerdefiniert'] as $val => $label): ?>
                                            <option value="<?php echo $val; ?>" <?php echo ($s['theme'] ?? '') === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="width">Breite</label>
                                    <select class="form-select" id="width" name="width">
                                        <?php foreach (['auto' => 'Automatisch', '100%' => '100%', '75%' => '75%', '50%' => '50%'] as $val => $label): ?>
                                            <option value="<?php echo $val; ?>" <?php echo ($s['width'] ?? '') === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="alignment">Ausrichtung</label>
                                    <select class="form-select" id="alignment" name="alignment">
                                        <?php foreach (['none' => 'Keine', 'left' => 'Links', 'center' => 'Zentriert', 'right' => 'Rechts'] as $val => $label): ?>
                                            <option value="<?php echo $val; ?>" <?php echo ($s['alignment'] ?? '') === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Checkboxen -->
                            <div class="row">
                                <?php
                                $toggles = [
                                    'show_header_label' => 'Überschrift anzeigen',
                                    'allow_toggle'      => 'Ein-/Ausklappen erlauben',
                                    'show_hierarchy'    => 'Hierarchie anzeigen',
                                    'show_counter'      => 'Nummerierung anzeigen',
                                    'sticky_toggle'     => 'Sticky TOC-Toggle',
                                ];
                                foreach ($toggles as $key => $label): ?>
                                    <div class="col-md-6">
                                        <label class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" name="<?php echo $key; ?>" value="1"
                                                <?php echo !empty($s[$key]) ? 'checked' : ''; ?>>
                                            <span class="form-check-label"><?php echo $label; ?></span>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Custom Colors (wenn theme = custom) -->
                    <div class="card mb-3" id="customColorsCard">
                        <div class="card-header">
                            <h3 class="card-title">Benutzerdefinierte Farben</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php
                                $colorFields = [
                                    'custom_bg_color'     => 'Hintergrundfarbe',
                                    'custom_border_color' => 'Rahmenfarbe',
                                    'custom_title_color'  => 'Titel-Farbe',
                                    'custom_link_color'   => 'Link-Farbe',
                                ];
                                foreach ($colorFields as $key => $label): ?>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="<?php echo $key; ?>"><?php echo $label; ?></label>
                                        <div class="input-group">
                                            <input type="color" class="form-control form-control-color" id="<?php echo $key; ?>" name="<?php echo $key; ?>"
                                                   value="<?php echo htmlspecialchars($s[$key] ?? '#000000'); ?>">
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($s[$key] ?? ''); ?>"
                                                   aria-label="<?php echo htmlspecialchars($label . ' (Hex-Wert)', ENT_QUOTES, 'UTF-8'); ?>"
                                                   data-cms-sync-target="<?php echo htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8'); ?>"
                                                   pattern="#[0-9a-fA-F]{6}" maxlength="7">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Sidebar -->
                <div class="col-12">
                    <h3 class="cms-settings-section-heading">Erweiterte Optionen</h3>

                    <!-- Scroll -->
                    <div class="card mb-3">
                        <div class="card-header">
                            <h3 class="card-title">Scroll-Verhalten</h3>
                        </div>
                        <div class="card-body">
                            <label class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="smooth_scroll" value="1"
                                    <?php echo !empty($s['smooth_scroll']) ? 'checked' : ''; ?>>
                                <span class="form-check-label">Smooth Scroll aktivieren</span>
                            </label>
                            <div class="mb-3">
                                <label class="form-label" for="smooth_scroll_offset">Scroll-Offset (px)</label>
                                <input type="number" class="form-control" id="smooth_scroll_offset" name="smooth_scroll_offset" min="0" max="200" value="<?php echo (int)($s['smooth_scroll_offset'] ?? 30); ?>">
                            </div>
                            <div class="mb-0">
                                <label class="form-label" for="mobile_scroll_offset">Mobile Offset (px)</label>
                                <input type="number" class="form-control" id="mobile_scroll_offset" name="mobile_scroll_offset" min="0" max="200" value="<?php echo (int)($s['mobile_scroll_offset'] ?? 0); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Erweitert -->
                    <div class="card mb-3">
                        <div class="card-header">
                            <h3 class="card-title">Erweitert</h3>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label" for="anchor_prefix">Anker-Prefix</label>
                                <input type="text" class="form-control" id="anchor_prefix" name="anchor_prefix" value="<?php echo htmlspecialchars($s['anchor_prefix'] ?? ''); ?>" placeholder="z.B. toc-">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="exclude_headings">Ausgeschlossene Überschriften</label>
                                <input type="text" class="form-control" id="exclude_headings" name="exclude_headings" value="<?php echo htmlspecialchars($s['exclude_headings'] ?? ''); ?>" placeholder="Beispiel: FAQ|Fragen oder FAQ, Fragen">
                                <span class="form-hint">Unterstützt Pipe- oder Komma-getrennte Textfragmente.</span>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="limit_path">Pfad-Begrenzung</label>
                                <input type="text" class="form-control" id="limit_path" name="limit_path" value="<?php echo htmlspecialchars($s['limit_path'] ?? ''); ?>" placeholder="/blog/">
                                <span class="form-hint">TOC nur auf bestimmten Pfaden</span>
                            </div>

                            <?php
                            $advToggles = [
                                'lowercase'        => 'Anker in Kleinbuchstaben',
                                'hyphenate'         => 'Leerzeichen in Ankern mit Bindestrichen ersetzen',
                                'homepage_toc'      => 'TOC auf Homepage anzeigen',
                                'exclude_css'       => 'Internes CSS deaktivieren',
                                'remove_toc_links'  => 'TOC-Links aus Inhalt entfernen',
                            ];
                            foreach ($advToggles as $key => $label): ?>
                                <label class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="<?php echo $key; ?>" value="1"
                                        <?php echo !empty($s[$key]) ? 'checked' : ''; ?>>
                                    <span class="form-check-label"><?php echo $label; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div>
            </div>

            <div class="cms-settings-actions cms-settings-actions-bottom">
                <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
            </div>

        </form>
    </div>
</div>

<script <?= \CMS\Security::instance()->nonceAttr() ?>>
// Custom-Colors Card nur bei theme=custom zeigen
(function() {
    var themeSelect = document.getElementById('theme');
    var colorCard   = document.getElementById('customColorsCard');
    function toggle() {
        colorCard.style.display = themeSelect.value === 'custom' ? '' : 'none';
    }
    toggle();
    themeSelect.addEventListener('change', toggle);
})();
</script>
