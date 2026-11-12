<?php

declare(strict_types=1);

namespace CMS\Services\Knowledgebase\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class Defaults
{
    /**
     * @return array<string, string>
     */
    public static function settings(): array
    {
        return [
            'enable_autolink' => '1',
            'enable_tooltips' => '1',
            'enable_output_buffer' => '0',
            'max_links_per_page' => '6',
            'nofollow_links' => '0',
            'open_links_new_tab' => '0',
            'archive_title' => 'Knowledgebase',
            'archive_intro' => 'Hilfreiche Begriffe, Hintergründe und kurze Erklärungen aus deinem CMS direkt im Kontext verlinkt.',
            'archive_title_en' => 'Knowledgebase',
            'archive_intro_en' => 'Helpful terms, background knowledge and concise explanations from your CMS, linked directly in context.',
            'glossary_title' => 'Glossar',
            'glossary_intro' => 'Alle wichtigen Begriffe kompakt erklärt – ideal zum schnellen Nachschlagen und für interne Verlinkungen.',
            'glossary_title_en' => 'Glossary',
            'glossary_intro_en' => 'All key terms explained in a compact way - ideal for quick lookup and internal linking.',
            'show_search' => '1',
            'show_category_sidebar' => '1',
            'show_keyword_badges' => '1',
            'show_related_entries' => '1',
            'related_posts_limit' => '4',
            'enable_structured_data' => '1',
            'show_nav_link' => '0',
            'nav_label' => 'Knowledgebase',
            'nav_label_en' => 'Knowledgebase',
            'content_max_width' => '1200',
            'sidebar_width' => '300',
            'design_accent_color' => '#0d9488',
            'design_accent_hover_color' => '#0f766e',
            'design_surface_color' => '#ffffff',
            'design_border_color' => '#dbe1ea',
            'design_border_radius' => '14',
            'tooltip_background_color' => '#111827',
            'tooltip_text_color' => '#f8fafc',
            'archive_hero_style' => 'tinted',
            'archive_columns' => 'auto',
            'archive_card_style' => 'elevated',
            'archive_card_accent_bar' => '1',
            'archive_excerpt_lines' => '3',
            'archive_card_bg_color' => '#ffffff',
            'single_hero_style' => 'tinted',
            'single_section_style' => 'cards',
            'single_section_tints' => '1',
            'single_section_icons' => '1',
            'single_section_bg_color' => '#ffffff',
            'single_sidebar_style' => 'plain',
            'single_sidebar_position' => 'right',
            'single_sidebar_sticky' => '1',
            'single_sidebar_bg_color' => '#f8fafc',
        ];
    }

    /**
     * Erlaubte Werte der Auswahlfelder im Design-Tab (Wert => Admin-Label).
     *
     * @return array<string, array<string, string>>
     */
    public static function designChoices(): array
    {
        return [
            'archive_hero_style' => [
                'tinted' => 'Getönt (Akzent dezent)',
                'surface' => 'Neutral (Fläche)',
                'accent' => 'Akzentfarbe (kräftig)',
            ],
            'archive_columns' => [
                'auto' => 'Automatisch',
                '2' => '2 Spalten',
                '3' => '3 Spalten',
                '4' => '4 Spalten',
            ],
            'archive_card_style' => [
                'elevated' => 'Erhaben (Schatten)',
                'outline' => 'Nur Rahmen',
                'tinted' => 'Getönte Fläche',
            ],
            'single_hero_style' => [
                'tinted' => 'Getönt (Akzent dezent)',
                'surface' => 'Neutral (Fläche)',
                'accent' => 'Akzentfarbe (kräftig)',
            ],
            'single_section_style' => [
                'cards' => 'Eigene Karte je Bereich',
                'panel' => 'Ein Panel mit Trennlinien',
                'minimal' => 'Ohne Rahmen (schlicht)',
            ],
            'single_sidebar_style' => [
                'plain' => 'Ohne Rahmen',
                'card' => 'Eine Karte',
                'sections' => 'Karte je Block',
            ],
            'single_sidebar_position' => [
                'right' => 'Rechts',
                'left' => 'Links',
            ],
        ];
    }

    public static function designChoice(array $settings, string $key): string
    {
        $choices = self::designChoices()[$key] ?? [];
        $value = (string) ($settings[$key] ?? '');
        if (isset($choices[$value])) {
            return $value;
        }

        $default = (string) (self::settings()[$key] ?? '');

        return isset($choices[$default]) ? $default : (string) array_key_first($choices);
    }
}
