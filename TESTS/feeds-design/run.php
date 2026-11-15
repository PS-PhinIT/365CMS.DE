<?php
/**
 * Feed-Seiten und Übersichten (4.1.0, Nachtrag N5): Design-Einstellungen wirken über CSS-Variablen (nicht nur
 * Farben), das Raster nutzt die Spalten-Einstellung, Übersichtsseiten des Themes zeigen keine Lesezeit, der
 * Einzelbeitrag folgt der Theme-Einstellung, virtuelle Seiten (Feed-Übersicht) zeigen keine Meta-Zeile.
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis: php TESTS/feeds-design/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');

require_once dirname(__DIR__) . '/lib/TestRunner.php';
require_once ABSPATH . 'core/Services/Feed/AggregatorSettings.php';

use CMS\Services\Feed\AggregatorSettings as A;

T::$verbose = in_array('-v', $argv ?? [], true);

function fdSource(string $relative): string
{
    return (string) file_get_contents(ABSPATH . $relative);
}

T::run('Design-Einstellungen → CSS-Variablen', static function (): void {
    $css = A::tokenCss(A::normalize([]));
    foreach (['--fd-grid-columns', '--fd-text', '--fd-bg', '--fd-font:inherit', '--fd-image-ratio:16/9', '--fd-shadow', '--fd-card-border-width:1px', '--fd-hdr-bg:linear-gradient'] as $needle) {
        T::check(str_contains($css, $needle), 'Standard enthält ' . $needle);
    }
    $custom = A::tokenCss(A::normalize([
        'font_family' => 'serif', 'card_style' => 'flat', 'image_ratio' => '1/1', 'header_style' => 'none',
        'color_text' => '#112233', 'color_bg' => '#ffffff', 'grid_columns' => '2',
    ]));
    T::check(str_contains($custom, "--fd-font:Georgia"), 'Schrift „Serifen“');
    T::check(str_contains($custom, '--fd-card-border-width:0') && str_contains($custom, '--fd-shadow:none'), 'Kartenstil „flach“: ohne Rahmen und Schatten');
    T::check(str_contains($custom, '--fd-image-ratio:1/1'), 'Bildformat 1:1');
    T::check(str_contains($custom, '--fd-hdr-bg:transparent') && str_contains($custom, '--fd-hdr-fg:#112233'), 'Kopfbereich ohne Hintergrund nutzt die Textfarbe');
    T::check(str_contains($custom, '--fd-grid-columns:repeat(2, minmax(0, 1fr))'), 'Spalten im Raster');
    $evil = A::normalize(['font_family' => 'x;}body{display:none', 'image_ratio' => '1/1;}', 'card_style' => '<b>', 'header_style' => 'url(x)', 'color_bg' => 'red;}']);
    T::same(['theme', '16/9', 'shadow', 'gradient', '#f1f5f9'], [$evil['font_family'], $evil['image_ratio'], $evil['card_style'], $evil['header_style'], $evil['color_bg']], 'ungültige Werte fallen auf Standard zurück (keine CSS-Einschleusung)');
});

T::run('CSS und Formular', static function (): void {
    $css = fdSource('assets/css/feeds.css');
    T::check(str_contains($css, 'grid-template-columns: var(--fd-grid-columns'), 'Raster nutzt die Spalten-Einstellung');
    T::check(str_contains($css, 'aspect-ratio: var(--fd-image-ratio)'), 'Bildformat aus der Einstellung');
    T::check(str_contains($css, 'border: var(--fd-card-border-width) solid') && str_contains($css, 'box-shadow: var(--fd-shadow);'), 'Kartenstil aus der Einstellung');
    T::check(str_contains($css, 'background: var(--fd-hdr-bg,'), 'Kopfbereich aus der Einstellung');
    T::check(str_contains($css, 'prefers-reduced-motion'), 'Bewegung abschaltbar');
    $form = fdSource('admin/views/settings/feeds/settings.php');
    foreach (['font_family', 'card_style', 'image_ratio', 'header_style', 'color_text', 'color_bg'] as $key) {
        T::check(str_contains($form, "'" . $key . "'"), 'Formular: ' . $key);
    }
    foreach (['de', 'en'] as $lang) {
        $yaml = fdSource('lang/' . $lang . '.yaml');
        T::check(str_contains($yaml, '"feeds.admin.settings.image_ratio_16_9"') && str_contains($yaml, '"feeds.admin.settings.header_style_none"'), 'Übersetzungen ' . $lang);
    }
});

T::run('Keine Lesezeit auf Übersichtsseiten', static function (): void {
    foreach (['themes/cms-default/partials/blog-list-cards.php', 'themes/cms-default/index.php', 'themes/cms-default/home.php'] as $file) {
        $source = fdSource($file);
        T::check(!preg_match('/read_time|reading_time|read-t\b|meta-read|Lesezeit/', $source), $file . ': keine Lesezeit');
    }
    $single = fdSource('themes/cms-default/blog-single.php');
    T::check(str_contains($single, "\$pShowRead = (bool) meridian_setting('blog', 'show_reading_time', true);") && substr_count($single, '<?php if ($pShowRead): ?>') === 2, 'Einzelbeitrag folgt der Einstellung „Lesezeit anzeigen“');
    T::check(str_contains(fdSource('themes/cms-default/page.php'), "if ((int) (\$page['id'] ?? 0) > 0)"), 'virtuelle Seiten (Feed-Übersicht) ohne Meta-Zeile „Slug“');
});

T::finish();
