<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

return [
    'header' => [
        'title' => '🖼️ Header & Logo',
        'sections' => [
            'logo_type' => [
                'label' => 'Logo Typ',
                'type' => 'select',
                'options' => ['text' => 'Nur Text', 'image' => 'Bild-Logo'],
                'default' => 'text',
            ],
            'logo_url' => [
                'label' => 'Logo Bild',
                'description' => 'Bild hochladen oder URL eingeben. Gilt nur bei Typ = Bild-Logo.',
                'type' => 'image_upload',
                'default' => '',
            ],
            'logo_height' => [
                'label' => 'Logo Höhe (px)',
                'description' => 'Maximale Höhe des Logo-Bildes im Header.',
                'type' => 'number',
                'default' => 40,
            ],
            'logo_text' => [
                'label' => 'Logo Text',
                'description' => 'Wird angezeigt, wenn Typ = Nur Text.',
                'type' => 'text',
                'default' => '365CMS',
            ],
            'logo_tagline' => [
                'label' => 'Tagline / Untertitel',
                'description' => 'Kleine Zeile rechts neben dem Text-Logo.',
                'type' => 'text',
                'default' => '',
            ],
            'header_title' => [
                'label' => 'Titel rechts neben Logo',
                'description' => 'Optionaler Seitentitel, der rechts neben dem Logo im Header angezeigt wird.',
                'type' => 'text',
                'default' => '',
            ],
            'show_search_btn' => [
                'label' => 'Such-Button im Header anzeigen',
                'type' => 'checkbox',
                'default' => true,
            ],
            'show_login_btn' => [
                'label' => 'Anmelden-Button anzeigen',
                'type' => 'checkbox',
                'default' => true,
            ],
            'show_register_btn' => [
                'label' => 'Registrieren-Button anzeigen',
                'type' => 'checkbox',
                'default' => true,
            ],
            'header_stripe_enabled' => [
                'label' => 'Farbstreifen oben am Header anzeigen',
                'type' => 'checkbox',
                'default' => true,
            ],
        ],
    ],
    'navigation' => [
        'title' => '🗂️ Navigation',
        'sections' => [
            'header_bar_mode' => [
                'label' => 'Leiste unter Header',
                'type' => 'select',
                'options' => [
                    'none' => 'Nicht anzeigen',
                    'categories' => 'Kategorien automatisch',
                    'menu' => 'Sekundäres Menü',
                ],
                'default' => 'categories',
                'description' => 'Bei "Sekundäres Menü" muss ein Menü der Position "Sekundäres Menü" (Admin → Menüs) zugewiesen sein.',
            ],
            'nav_font_size' => [
                'label' => 'Navigation Schriftgröße (px)',
                'description' => 'Schriftgröße der Hauptmenü-Links.',
                'type' => 'number',
                'default' => 14,
            ],
            'nav_uppercase' => [
                'label' => 'Navigation in Großbuchstaben',
                'description' => 'Menü-Links in Kapitälchen/Großbuchstaben anzeigen.',
                'type' => 'checkbox',
                'default' => false,
            ],
            'nav_letter_spacing' => [
                'label' => 'Navigation Buchstabenabstand',
                'description' => 'CSS letter-spacing, z. B. 0.05em oder 0.',
                'type' => 'text',
                'default' => '0',
            ],
        ],
    ],
    'layout' => [
        'title' => '📐 Layout & Design',
        'sections' => [
            'max_width' => [
                'label' => 'Maximale Seiten-Breite (px)',
                'type' => 'number',
                'default' => 1140,
            ],
            'sticky_header' => [
                'label' => 'Sticky Header (bleibt beim Scrollen sichtbar)',
                'type' => 'checkbox',
                'default' => true,
            ],
            'content_layout' => [
                'label' => 'Inhalts-Layout',
                'type' => 'select',
                'options' => [
                    'with_sidebar' => 'Mit Sidebar (Haupt + Seitenleiste)',
                    'full_width' => 'Volle Breite (keine Sidebar)',
                ],
                'default' => 'with_sidebar',
                'description' => 'Gilt für Blog, Startseite, Archive, Kategorien, Schlagwörter und Autorenseiten.',
            ],
            'post_col_width' => [
                'label' => 'Text-Spalten-Breite (px)',
                'description' => 'Maximale Breite des Haupt-Textbereichs (z. B. Blogartikel-Text).',
                'type' => 'number',
                'default' => 680,
            ],
            'border_radius' => [
                'label' => 'Eck-Radius (px)',
                'description' => 'Abrundung von Karten, Buttons und Eingabefeldern.',
                'type' => 'number',
                'default' => 3,
            ],
            'card_gap' => [
                'label' => 'Karten-Abstand (px)',
                'description' => 'Abstand zwischen Grid-Karten und Artikel-Elementen.',
                'type' => 'number',
                'default' => 24,
            ],
            'show_back_to_top' => [
                'label' => 'Zurück-nach-oben-Button anzeigen',
                'description' => 'Schwebendes Icon zum Zurückspringen an den Seitenanfang.',
                'type' => 'checkbox',
                'default' => true,
            ],
        ],
    ],
    'colors' => [
        'title' => '🎨 Farben',
        'sections' => [
            'accent_color' => [
                'label' => 'Akzentfarbe (Haupt-Highlight)',
                'type' => 'color',
                'default' => '#c0862a',
            ],
            'accent_dark_color' => [
                'label' => 'Akzentfarbe Hover/Dunkel',
                'description' => 'Wird bei Hover-Effekten verwendet.',
                'type' => 'color',
                'default' => '#a06b18',
            ],
            'ink_color' => [
                'label' => 'Textfarbe (Primär)',
                'type' => 'color',
                'default' => '#1a1a18',
            ],
            'ink_soft_color' => [
                'label' => 'Textfarbe (Weich)',
                'description' => 'Für sekundäre Texte, Nav-Items.',
                'type' => 'color',
                'default' => '#3d3d3a',
            ],
            'ink_muted_color' => [
                'label' => 'Textfarbe (Gedämpft)',
                'description' => 'Für Meta-Infos, Datumsangaben, Labels.',
                'type' => 'color',
                'default' => '#7a7a74',
            ],
            'ground_color' => [
                'label' => 'Seiten-Hintergrundfarbe',
                'description' => 'Haupt-Hintergrund der Website.',
                'type' => 'color',
                'default' => '#f7f6f2',
            ],
            'surface_color' => [
                'label' => 'Karten-/Flächen-Farbe (Surface)',
                'description' => 'Für weiße Karteninhalte.',
                'type' => 'color',
                'default' => '#ffffff',
            ],
            'surface_tint_color' => [
                'label' => 'Surface Tint',
                'description' => 'Leicht getönter Hintergrund für Tabellenköpfe, etc.',
                'type' => 'color',
                'default' => '#f2f1ec',
            ],
            'rule_color' => [
                'label' => 'Trennlinien-Farbe',
                'description' => 'Linien zwischen Elementen, Rahmen.',
                'type' => 'color',
                'default' => '#e2e0d8',
            ],
            'header_bg_color' => [
                'label' => 'Header-Hintergrundfarbe',
                'type' => 'color',
                'default' => '#ffffff',
            ],
            'header_stripe_color' => [
                'label' => 'Header-Akzentstreifen Farbe',
                'description' => 'Der dünne farbige Streifen ganz oben am Header.',
                'type' => 'color',
                'default' => '#1a1a18',
            ],
            'link_color' => [
                'label' => 'Link-Farbe (Content)',
                'description' => 'Textfarbe für Hyperlinks im Content-Bereich.',
                'type' => 'color',
                'default' => '#c0862a',
            ],
            'link_hover_color' => [
                'label' => 'Link Hover-Farbe',
                'description' => 'Link-Farbe beim Überfahren mit der Maus.',
                'type' => 'color',
                'default' => '#a06b18',
            ],
            'category_bar_bg' => [
                'label' => 'Kategorie-Leiste Hintergrundfarbe',
                'description' => 'Hintergrundfarbe der Kategorie-/Menüleiste unter dem Header.',
                'type' => 'color',
                'default' => '#f2f1ec',
            ],
            'category_bar_text' => [
                'label' => 'Kategorie-Leiste Textfarbe',
                'description' => 'Textfarbe der Links in der Kategorie-Leiste.',
                'type' => 'color',
                'default' => '#3d3d3a',
            ],
        ],
    ],
    'typography' => [
        'title' => '✏️ Typografie',
        'sections' => [
            'font_size_base' => [
                'label' => 'Basis Schriftgröße (px)',
                'description' => 'Standard-Schriftgröße für Fließtext.',
                'type' => 'number',
                'default' => 15,
            ],
            'line_height' => [
                'label' => 'Zeilenhöhe',
                'description' => 'z. B. 1.6 für entspanntes Lesen (Dezimalwert).',
                'type' => 'text',
                'default' => '1.6',
            ],
            'heading_weight' => [
                'label' => 'Überschriften Schriftstärke',
                'type' => 'select',
                'options' => ['600' => '600 (Semi-Bold)', '700' => '700 (Bold)', '800' => '800 (Extra-Bold)', '900' => '900 (Black)'],
                'default' => '700',
            ],
            'google_fonts' => [
                'label' => 'Google Fonts als Fallback laden',
                'description' => 'Erlaubt externe Google-Fonts im Frontend, solange keine lokalen Fonts aktiv sind. Standardmäßig aus (DSGVO: Übermittlung der Besucher-IP an Google). Empfohlen: Schriften im Font Manager lokal einbinden.',
                'type' => 'checkbox',
                'default' => false,
            ],
            'font_family_body' => [
                'label' => 'Schriftart Fließtext',
                'description' => 'Schriftfamilie für Absätze und Fließtext.',
                'type' => 'select',
                'options' => [
                    'dm-sans' => 'DM Sans (Standard)',
                    'system-ui' => 'System-Schrift (kein Google Fonts)',
                    'georgia' => 'Georgia (klassische Serif)',
                    'inter' => 'Inter',
                    'times-new-roman' => 'Times New Roman',
                ],
                'default' => 'dm-sans',
            ],
            'font_family_heading' => [
                'label' => 'Schriftart Überschriften',
                'description' => 'Schriftfamilie für h1–h6.',
                'type' => 'select',
                'options' => [
                    'libre-baskerville' => 'Libre Baskerville (Standard)',
                    'georgia' => 'Georgia',
                    'playfair-display' => 'Playfair Display',
                    'merriweather' => 'Merriweather',
                    'system-ui' => 'System-Schrift',
                ],
                'default' => 'libre-baskerville',
            ],
            'letter_spacing_headings' => [
                'label' => 'Buchstabenabstand Überschriften',
                'description' => 'CSS letter-spacing, z. B. -0.02em, 0.05em oder 0.',
                'type' => 'text',
                'default' => '0',
            ],
            'h1_size' => [
                'label' => 'H1 Schriftgröße (px)',
                'type' => 'number',
                'default' => 38,
            ],
            'h2_size' => [
                'label' => 'H2 Schriftgröße (px)',
                'type' => 'number',
                'default' => 28,
            ],
            'h3_size' => [
                'label' => 'H3 Schriftgröße (px)',
                'type' => 'number',
                'default' => 22,
            ],
        ],
    ],
    'footer' => [
        'title' => '🔻 Footer',
        'sections' => [
            'footer_description' => [
                'label' => 'Footer Beschreibungstext (Brand-Spalte)',
                'type' => 'textarea',
                'default' => 'Aktuelle Themen, fundierte Analysen und persönliche Geschichten – täglich neu.',
            ],
            'footer_bg_color' => [
                'label' => 'Footer Hintergrundfarbe',
                'type' => 'color',
                'default' => '#1a1a18',
            ],
            'footer_text_color' => [
                'label' => 'Footer Textfarbe',
                'type' => 'color',
                'default' => '#9a9a94',
            ],
            'footer_accent_color' => [
                'label' => 'Footer Link-Farbe',
                'type' => 'color',
                'default' => '#c0862a',
            ],
            'col1_title' => [
                'label' => 'Titel Link-Spalte 1',
                'type' => 'text',
                'default' => 'Rubriken',
            ],
            'col2_title' => [
                'label' => 'Titel Link-Spalte 2',
                'type' => 'text',
                'default' => 'Ressourcen',
            ],
            'col3_title' => [
                'label' => 'Titel Link-Spalte 3',
                'type' => 'text',
                'default' => 'Über',
            ],
            'show_social_icons' => [
                'label' => 'Social Icons anzeigen',
                'type' => 'checkbox',
                'default' => true,
            ],
            'copyright_text' => [
                'label' => 'Copyright Text',
                'description' => 'Platzhalter: {year}, {site_title}',
                'type' => 'text',
                'default' => '© {year} 365CMS. Alle Rechte vorbehalten.',
            ],
            'social_twitter' => [
                'label' => 'Twitter / X URL',
                'type' => 'text',
                'default' => '',
            ],
            'social_instagram' => [
                'label' => 'Instagram URL',
                'type' => 'text',
                'default' => '',
            ],
            'social_linkedin' => [
                'label' => 'LinkedIn URL',
                'type' => 'text',
                'default' => '',
            ],
            'social_youtube' => [
                'label' => 'YouTube URL',
                'type' => 'text',
                'default' => '',
            ],
        ],
    ],
    'blog' => [
        'title' => '📰 Blog & Artikel',
        'sections' => [
            'show_hero_post' => [
                'label' => 'Hero-Post anzeigen',
                'description' => 'Den neuesten Artikel als großes Hero-Element oben darstellen.',
                'type' => 'checkbox',
                'default' => true,
            ],
            'show_reading_time' => [
                'label' => 'Lesezeit anzeigen',
                'description' => 'Geschätzte Lesezeit bei Artikeln anzeigen (z. B. „3 min Lesezeit").',
                'type' => 'checkbox',
                'default' => true,
            ],
            'show_author' => [
                'label' => 'Autor anzeigen',
                'description' => 'Autorenname in der Artikel-Meta-Zeile anzeigen.',
                'type' => 'checkbox',
                'default' => true,
            ],
            'show_views' => [
                'label' => 'Aufrufe anzeigen',
                'description' => 'Aufruf-Zähler bei Artikeln anzeigen.',
                'type' => 'checkbox',
                'default' => false,
            ],
            'show_comments' => [
                'label' => 'Kommentarformular anzeigen',
                'description' => 'Kommentarformular unterhalb von Blog-Artikeln anzeigen.',
                'type' => 'checkbox',
                'default' => true,
            ],
            'show_related_posts' => [
                'label' => 'Verwandte Artikel anzeigen',
                'description' => 'Ähnliche Artikel am Ende eines Blog-Posts zeigen.',
                'type' => 'checkbox',
                'default' => true,
            ],
        ],
    ],
    'homepage' => [
        'title' => '🏠 Startseite',
        'sections' => [
            'homepage_mode' => [
                'label' => 'Startseiten-Modus',
                'type' => 'select',
                'options' => [
                    'posts' => '📰 Beitragsübersicht (Blog)',
                    'landing' => '🎯 Statische Landing Page',
                ],
                'default' => 'posts',
                'description' => 'Legt fest, was auf der Startseite angezeigt wird.',
            ],
            'homepage_show_hero' => [
                'label' => 'Hero-Artikel anzeigen',
                'type' => 'checkbox',
                'default' => true,
                'description' => 'Den ersten/angehefteten Beitrag groß als Hero darstellen.',
            ],
            'homepage_hero_title' => [
                'label' => 'Hero Überschrift (optional)',
                'type' => 'text',
                'default' => '',
                'description' => 'Überschreibt den Artikeltitel im Hero-Bereich. Leer = Artikeltitel.',
            ],
            'homepage_cta_text' => [
                'label' => 'CTA Button Text',
                'type' => 'text',
                'default' => '',
                'description' => 'Text des Call-to-Action Buttons. Leer = Button ausgeblendet.',
            ],
            'homepage_cta_url' => [
                'label' => 'CTA Button URL',
                'type' => 'text',
                'default' => '',
                'description' => 'Ziel-URL des CTA-Buttons (z. B. /blog oder https://...).',
            ],
        ],
    ],
    'advanced' => [
        'title' => '🔧 Erweitert',
        'sections' => [
            'custom_css' => [
                'label' => 'Eigenes CSS',
                'description' => 'Wird nach allen anderen Styles geladen.',
                'type' => 'textarea',
                'default' => '',
            ],
            'custom_head_code' => [
                'label' => 'Custom Head Code (Tracking, Meta)',
                'description' => 'Wird im <head> ausgegeben. Nur vertrauenswürdigen Code einfügen!',
                'type' => 'textarea',
                'default' => '',
            ],
            'custom_footer_code' => [
                'label' => 'Custom Footer Code (Analytics, Chat-Widgets)',
                'description' => 'Wird vor </body> ausgegeben.',
                'type' => 'textarea',
                'default' => '',
            ],
        ],
    ],
];
