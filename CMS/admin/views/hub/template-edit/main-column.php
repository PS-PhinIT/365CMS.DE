<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="col-lg-8">
    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Basisdaten</h3></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-7">
                    <label for="fld-template_label" class="form-label required">Template-Name</label>
                    <input id="fld-template_label" type="text" class="form-control" name="template_label" value="<?php echo htmlspecialchars((string)($template['label'] ?? '')); ?>" required>
                    <div class="form-hint">Der sichtbare Name darf geändert werden, der interne Key bleibt stabil — keine unnötigen Hub-Site-Explosionen inklusive.</div>
                </div>
                <div class="col-md-5">
                    <label for="fld-base_template" class="form-label">Basis-Layout</label>
                    <select id="fld-base_template" class="form-select" name="base_template">
                        <?php foreach ($baseTemplateOptions as $value => $label): ?>
                            <option value="<?php echo htmlspecialchars((string)$value); ?>" <?php echo (($template['base_template'] ?? 'general-it') === $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label for="fld-template_summary" class="form-label">Kurzbeschreibung</label>
                    <textarea id="fld-template_summary" class="form-control" name="template_summary" rows="3"><?php echo htmlspecialchars((string)($template['summary'] ?? '')); ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Template-Meta & Beschriftungen</h3></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="fld-template_label_audience" class="form-label">Meta-Label: Zielgruppe</label>
                    <input id="fld-template_label_audience" type="text" class="form-control" name="template_label_audience" value="<?php echo htmlspecialchars((string)($templateMetaLabels['audience'] ?? 'Zielgruppe')); ?>">
                </div>
                <div class="col-md-6">
                    <label for="fld-template_meta_audience" class="form-label">Meta-Wert</label>
                    <input id="fld-template_meta_audience" type="text" class="form-control" name="template_meta_audience" value="<?php echo htmlspecialchars((string)($templateMeta['audience'] ?? '')); ?>">
                </div>
                <div class="col-md-6">
                    <label for="fld-template_label_owner" class="form-label">Meta-Label: Verantwortlich</label>
                    <input id="fld-template_label_owner" type="text" class="form-control" name="template_label_owner" value="<?php echo htmlspecialchars((string)($templateMetaLabels['owner'] ?? 'Verantwortlich')); ?>">
                </div>
                <div class="col-md-6">
                    <label for="fld-template_meta_owner" class="form-label">Meta-Wert</label>
                    <input id="fld-template_meta_owner" type="text" class="form-control" name="template_meta_owner" value="<?php echo htmlspecialchars((string)($templateMeta['owner'] ?? '')); ?>">
                </div>
                <div class="col-md-4">
                    <label for="fld-template_label_update_cycle" class="form-label">Meta-Label: Update-Zyklus</label>
                    <input id="fld-template_label_update_cycle" type="text" class="form-control" name="template_label_update_cycle" value="<?php echo htmlspecialchars((string)($templateMetaLabels['update_cycle'] ?? 'Update-Zyklus')); ?>">
                </div>
                <div class="col-md-4">
                    <label for="fld-template_label_focus" class="form-label">Meta-Label: Fokus</label>
                    <input id="fld-template_label_focus" type="text" class="form-control" name="template_label_focus" value="<?php echo htmlspecialchars((string)($templateMetaLabels['focus'] ?? 'Fokus')); ?>">
                </div>
                <div class="col-md-4">
                    <label for="fld-template_label_kpi" class="form-label">Meta-Label: KPI</label>
                    <input id="fld-template_label_kpi" type="text" class="form-control" name="template_label_kpi" value="<?php echo htmlspecialchars((string)($templateMetaLabels['kpi'] ?? 'KPI')); ?>">
                </div>
                <div class="col-md-4">
                    <label for="fld-template_meta_update_cycle" class="form-label">Meta-Wert: Update-Zyklus</label>
                    <input id="fld-template_meta_update_cycle" type="text" class="form-control" name="template_meta_update_cycle" value="<?php echo htmlspecialchars((string)($templateMeta['update_cycle'] ?? '')); ?>">
                </div>
                <div class="col-md-4">
                    <label for="fld-template_meta_focus" class="form-label">Meta-Wert: Fokus</label>
                    <input id="fld-template_meta_focus" type="text" class="form-control" name="template_meta_focus" value="<?php echo htmlspecialchars((string)($templateMeta['focus'] ?? '')); ?>">
                </div>
                <div class="col-md-4">
                    <label for="fld-template_meta_kpi" class="form-label">Meta-Wert: KPI</label>
                    <input id="fld-template_meta_kpi" type="text" class="form-control" name="template_meta_kpi" value="<?php echo htmlspecialchars((string)($templateMeta['kpi'] ?? '')); ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Farben & Flächen</h3></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6"><label for="fld-template_color_hero_start" class="form-label">Hero Verlauf Start</label><input id="fld-template_color_hero_start" type="color" class="form-control form-control-color" name="template_color_hero_start" value="<?php echo htmlspecialchars((string)($templateColors['hero_start'] ?? '#1f2937')); ?>"></div>
                <div class="col-md-6"><label for="fld-template_color_hero_end" class="form-label">Hero Verlauf Ende</label><input id="fld-template_color_hero_end" type="color" class="form-control form-control-color" name="template_color_hero_end" value="<?php echo htmlspecialchars((string)($templateColors['hero_end'] ?? '#0f172a')); ?>"></div>
                <div class="col-md-4"><label for="fld-template_color_accent" class="form-label">Akzent / Buttons</label><input id="fld-template_color_accent" type="color" class="form-control form-control-color" name="template_color_accent" value="<?php echo htmlspecialchars((string)($templateColors['accent'] ?? '#2563eb')); ?>"></div>
                <div class="col-md-4"><label for="fld-template_color_surface" class="form-label">Oberfläche</label><input id="fld-template_color_surface" type="color" class="form-control form-control-color" name="template_color_surface" value="<?php echo htmlspecialchars((string)($templateColors['surface'] ?? '#ffffff')); ?>"></div>
                <div class="col-md-4"><label for="fld-template_color_section_background" class="form-label">Bereichs-Hintergrund</label><input id="fld-template_color_section_background" type="color" class="form-control form-control-color" name="template_color_section_background" value="<?php echo htmlspecialchars((string)($templateColors['section_background'] ?? '#ffffff')); ?>"></div>
                <div class="col-md-6"><label for="fld-template_color_card_background" class="form-label">Card-Hintergrund</label><input id="fld-template_color_card_background" type="color" class="form-control form-control-color" name="template_color_card_background" value="<?php echo htmlspecialchars((string)($templateColors['card_background'] ?? '#ffffff')); ?>"></div>
                <div class="col-md-6"><label for="fld-template_color_card_text" class="form-label">Card-Text</label><input id="fld-template_color_card_text" type="color" class="form-control form-control-color" name="template_color_card_text" value="<?php echo htmlspecialchars((string)($templateColors['card_text'] ?? '#0f172a')); ?>"></div>
                <div class="col-md-6"><label for="fld-template_color_table_header_start" class="form-label">Tabellenkopf Verlauf Start</label><input id="fld-template_color_table_header_start" type="color" class="form-control form-control-color" name="template_color_table_header_start" value="<?php echo htmlspecialchars((string)($templateColors['table_header_start'] ?? $templateColors['hero_start'] ?? '#1f2937')); ?>"></div>
                <div class="col-md-6"><label for="fld-template_color_table_header_end" class="form-label">Tabellenkopf Verlauf Ende</label><input id="fld-template_color_table_header_end" type="color" class="form-control form-control-color" name="template_color_table_header_end" value="<?php echo htmlspecialchars((string)($templateColors['table_header_end'] ?? $templateColors['hero_end'] ?? '#0f172a')); ?>"></div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Navigation & TOC</h3></div>
        <div class="card-body">
            <label class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" name="template_toc_enabled" value="1" <?php echo !empty($template['navigation']['toc_enabled']) ? 'checked' : ''; ?>>
                <span class="form-check-label">TOC unter dem Header anzeigen</span>
            </label>
            <div class="form-hint mb-0">Erstellt im Public ein aufklappbares Inhaltsverzeichnis direkt aus den sichtbaren Card-Titeln und springt zu den jeweiligen Karten bzw. Tabellen.</div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Kachel-Schema</h3></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Kacheln pro Reihe</label>
                    <input type="hidden" name="template_card_columns" value="<?php echo (int)($cardSchema['columns'] ?? 2); ?>">
                    <div class="hub-template-switcher" data-switcher="template_card_columns">
                        <?php foreach ([1, 2, 3] as $columnCount): ?>
                            <button type="button" class="hub-template-switcher__btn <?php echo ((int)($cardSchema['columns'] ?? 2) === $columnCount) ? 'is-active' : ''; ?>" data-value="<?php echo $columnCount; ?>">
                                <span class="hub-template-switcher__icon hub-template-switcher__icon--columns-<?php echo $columnCount; ?>">
                                    <?php for ($i = 0; $i < $columnCount; $i++): ?>
                                        <span class="hub-template-switcher__icon-cell"></span>
                                    <?php endfor; ?>
                                </span>
                                <span class="hub-template-switcher__label"><?php echo $columnCount; ?>er</span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Card-Layout</label>
                    <input type="hidden" name="hub_card_layout" value="<?php echo htmlspecialchars((string)($cardDesign['layout'] ?? 'standard')); ?>">
                    <div class="hub-template-switcher" data-switcher="hub_card_layout">
                        <button type="button" class="hub-template-switcher__btn <?php echo (($cardDesign['layout'] ?? 'standard') === 'standard') ? 'is-active' : ''; ?>" data-value="standard">
                            <span class="hub-template-switcher__icon hub-template-switcher__icon--layout hub-template-switcher__icon--standard"></span>
                            <span class="hub-template-switcher__label">Standard</span>
                        </button>
                        <button type="button" class="hub-template-switcher__btn <?php echo (($cardDesign['layout'] ?? '') === 'feature') ? 'is-active' : ''; ?>" data-value="feature">
                            <span class="hub-template-switcher__icon hub-template-switcher__icon--layout hub-template-switcher__icon--feature"><span></span></span>
                            <span class="hub-template-switcher__label">Feature</span>
                        </button>
                        <button type="button" class="hub-template-switcher__btn <?php echo (($cardDesign['layout'] ?? '') === 'compact') ? 'is-active' : ''; ?>" data-value="compact">
                            <span class="hub-template-switcher__icon hub-template-switcher__icon--layout hub-template-switcher__icon--compact"><span></span></span>
                            <span class="hub-template-switcher__label">Compact</span>
                        </button>
                    </div>
                </div>
                <div class="col-md-4"><label for="fld-hub_card_meta_layout" class="form-label">Meta-Layout</label><select id="fld-hub_card_meta_layout" class="form-select" name="hub_card_meta_layout"><option value="split" <?php echo (($cardDesign['meta_layout'] ?? 'split') === 'split') ? 'selected' : ''; ?>>Links / Rechts</option><option value="stacked" <?php echo (($cardDesign['meta_layout'] ?? '') === 'stacked') ? 'selected' : ''; ?>>Gestapelt</option></select></div>
                <div class="col-md-4">
                    <label for="fld-template_card_radius" class="form-label">Card-Rundung (px)</label>
                    <div class="hub-card-radius-control" data-hub-card-radius-control>
                        <div class="input-group input-group-sm">
                            <input id="fld-template_card_radius" type="number" class="form-control" name="template_card_radius" min="0" max="48" step="1" value="<?php echo (int)($cardDesign['card_radius'] ?? 20); ?>" data-hub-card-radius-number>
                            <span class="input-group-text">px</span>
                        </div>
                        <input type="range" class="form-range mt-2" min="0" max="48" step="1" value="<?php echo (int)($cardDesign['card_radius'] ?? 20); ?>" aria-label="Card-Rundung als Slider" data-hub-card-radius-range>
                        <div class="d-flex align-items-center justify-content-between gap-2 form-hint mb-0">
                            <span>0 = eckig, 48 = sehr rund.</span>
                            <span class="badge bg-azure-lt" data-hub-card-radius-label><?php echo (int)($cardDesign['card_radius'] ?? 20); ?> px</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <label for="fld-template_card_row_gap" class="form-label">Card-Reihenabstand (px)</label>
                    <div class="input-group input-group-sm">
                        <input id="fld-template_card_row_gap" type="number" class="form-control" name="template_card_row_gap" min="30" max="160" step="1" value="<?php echo (int)($cardDesign['card_row_gap'] ?? 30); ?>">
                        <span class="input-group-text">px</span>
                    </div>
                    <div class="form-hint">Mindestens 30 px. Gilt als Template-Default für vertikale Abstände zwischen HubSite-Card-Reihen.</div>
                </div>
                <div class="col-md-4">
                    <label for="fld-template_feature_image_width" class="form-label">Featured-Bildbreite (%)</label>
                    <div class="input-group input-group-sm">
                        <input id="fld-template_feature_image_width" type="number" class="form-control" name="template_feature_image_width" min="20" max="60" step="1" value="<?php echo (int)($cardDesign['feature_image_width'] ?? 34); ?>">
                        <span class="input-group-text">%</span>
                    </div>
                    <div class="form-hint">Desktop-Breite des Bildbereichs in Featured-Kacheln. 34 % ist der PHINIT-Standard.</div>
                </div>
                <div class="col-md-4">
                    <label for="fld-template_feature_image_height" class="form-label">Featured-Bildhöhe (px)</label>
                    <div class="input-group input-group-sm">
                        <input id="fld-template_feature_image_height" type="number" class="form-control" name="template_feature_image_height" min="160" max="520" step="10" value="<?php echo (int)($cardDesign['feature_image_height'] ?? 260); ?>">
                        <span class="input-group-text">px</span>
                    </div>
                    <div class="form-hint">Mindesthöhe der Featured-Kachel am Desktop. Das Bild wird mittig ausgerichtet.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Bildposition</label>
                    <input type="hidden" name="hub_card_image_position" value="<?php echo htmlspecialchars((string)($cardDesign['image_position'] ?? 'top')); ?>">
                    <div class="hub-template-switcher" data-switcher="hub_card_image_position">
                        <button type="button" class="hub-template-switcher__btn <?php echo (($cardDesign['image_position'] ?? 'top') === 'top') ? 'is-active' : ''; ?>" data-value="top">
                            <span class="hub-template-switcher__icon hub-template-switcher__icon--media hub-template-switcher__icon--media-top"><span class="hub-template-switcher__icon-media"></span><span class="hub-template-switcher__icon-copy"></span></span>
                            <span class="hub-template-switcher__label">Oben</span>
                        </button>
                        <button type="button" class="hub-template-switcher__btn <?php echo (($cardDesign['image_position'] ?? '') === 'left') ? 'is-active' : ''; ?>" data-value="left">
                            <span class="hub-template-switcher__icon hub-template-switcher__icon--media hub-template-switcher__icon--media-left"><span class="hub-template-switcher__icon-media"></span><span class="hub-template-switcher__icon-copy"></span></span>
                            <span class="hub-template-switcher__label">Links</span>
                        </button>
                        <button type="button" class="hub-template-switcher__btn <?php echo (($cardDesign['image_position'] ?? '') === 'right') ? 'is-active' : ''; ?>" data-value="right">
                            <span class="hub-template-switcher__icon hub-template-switcher__icon--media hub-template-switcher__icon--media-right"><span class="hub-template-switcher__icon-copy"></span><span class="hub-template-switcher__icon-media"></span></span>
                            <span class="hub-template-switcher__label">Rechts</span>
                        </button>
                    </div>
                </div>
                <div class="col-md-4"><label for="fld-hub_card_image_fit" class="form-label">Bilddarstellung</label><select id="fld-hub_card_image_fit" class="form-select" name="hub_card_image_fit"><option value="cover" <?php echo (($cardDesign['image_fit'] ?? 'cover') === 'cover') ? 'selected' : ''; ?>>Cover</option><option value="contain" <?php echo (($cardDesign['image_fit'] ?? '') === 'contain') ? 'selected' : ''; ?>>Contain</option></select></div>
                <div class="col-md-4"><label for="fld-hub_card_image_ratio" class="form-label">Bildformat</label><select id="fld-hub_card_image_ratio" class="form-select" name="hub_card_image_ratio"><option value="wide" <?php echo (($cardDesign['image_ratio'] ?? 'wide') === 'wide') ? 'selected' : ''; ?>>Breit</option><option value="square" <?php echo (($cardDesign['image_ratio'] ?? '') === 'square') ? 'selected' : ''; ?>>Quadratisch</option><option value="portrait" <?php echo (($cardDesign['image_ratio'] ?? '') === 'portrait') ? 'selected' : ''; ?>>Hochformat</option></select></div>
                <div class="col-md-6"><label for="fld-template_card_title_label" class="form-label">Feldlabel: Titel</label><input id="fld-template_card_title_label" type="text" class="form-control" name="template_card_title_label" value="<?php echo htmlspecialchars((string)($cardSchema['title_label'] ?? 'Titel')); ?>"></div>
                <div class="col-md-6"><label for="fld-template_card_summary_label" class="form-label">Feldlabel: Kurzbeschreibung</label><input id="fld-template_card_summary_label" type="text" class="form-control" name="template_card_summary_label" value="<?php echo htmlspecialchars((string)($cardSchema['summary_label'] ?? 'Kurzbeschreibung')); ?>"></div>
                <div class="col-md-6"><label for="fld-template_card_badge_label" class="form-label">Feldlabel: Badge</label><input id="fld-template_card_badge_label" type="text" class="form-control" name="template_card_badge_label" value="<?php echo htmlspecialchars((string)($cardSchema['badge_label'] ?? 'Badge')); ?>"></div>
                <div class="col-md-6"><label for="fld-template_card_meta_left_label" class="form-label">Feldlabel: Meta links</label><input id="fld-template_card_meta_left_label" type="text" class="form-control" name="template_card_meta_left_label" value="<?php echo htmlspecialchars((string)($cardSchema['meta_left_label'] ?? 'Meta links')); ?>"></div>
                <div class="col-md-6"><label for="fld-template_card_meta_right_label" class="form-label">Feldlabel: Meta rechts</label><input id="fld-template_card_meta_right_label" type="text" class="form-control" name="template_card_meta_right_label" value="<?php echo htmlspecialchars((string)($cardSchema['meta_right_label'] ?? 'Meta rechts')); ?>"></div>
                <div class="col-md-6"><label for="fld-template_card_image_label" class="form-label">Feldlabel: Bild-URL</label><input id="fld-template_card_image_label" type="text" class="form-control" name="template_card_image_label" value="<?php echo htmlspecialchars((string)($cardSchema['image_label'] ?? 'Bild-URL')); ?>"></div>
                <div class="col-md-6"><label for="fld-template_card_image_alt_label" class="form-label">Feldlabel: Bild-Alt</label><input id="fld-template_card_image_alt_label" type="text" class="form-control" name="template_card_image_alt_label" value="<?php echo htmlspecialchars((string)($cardSchema['image_alt_label'] ?? 'Bild-Alt')); ?>"></div>
                <div class="col-md-6"><label for="fld-template_card_button_text_label" class="form-label">Feldlabel: Button-Text</label><input id="fld-template_card_button_text_label" type="text" class="form-control" name="template_card_button_text_label" value="<?php echo htmlspecialchars((string)($cardSchema['button_text_label'] ?? 'Button-Text')); ?>"></div>
                <div class="col-md-6"><label for="fld-template_card_button_link_label" class="form-label">Feldlabel: Button-Link</label><input id="fld-template_card_button_link_label" type="text" class="form-control" name="template_card_button_link_label" value="<?php echo htmlspecialchars((string)($cardSchema['button_link_label'] ?? 'Button-Link')); ?>"></div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h3 class="card-title mb-0">Starter-Kacheln</h3>
            <button type="button" class="btn btn-outline-primary btn-sm" id="addStarterCard">Starter-Kachel hinzufügen</button>
        </div>
        <div class="card-body p-0">
            <div id="starterCardsContainer"></div>
            <div class="text-center text-secondary py-4 d-none" id="starterCardsEmpty">Keine Starter-Kacheln definiert. Mindestens eine lohnt sich meistens.</div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h3 class="card-title mb-0">Header-Links</h3>
            <button type="button" class="btn btn-outline-primary btn-sm" id="addTemplateLink">Link hinzufügen</button>
        </div>
        <div class="card-body p-0">
            <div id="templateLinksContainer"></div>
            <div class="text-center text-secondary py-4 d-none" id="templateLinksEmpty">Noch keine Header-Links vorhanden.</div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h3 class="card-title mb-0">Designbereiche</h3>
            <button type="button" class="btn btn-outline-primary btn-sm" id="addTemplateSection">Bereich hinzufügen</button>
        </div>
        <div class="card-body p-0">
            <div id="templateSectionsContainer"></div>
            <div class="text-center text-secondary py-4 d-none" id="templateSectionsEmpty">Noch keine Bereiche vorhanden.</div>
        </div>
    </div>
</div>
