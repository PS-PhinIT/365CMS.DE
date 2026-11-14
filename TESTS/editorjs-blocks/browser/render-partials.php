<?php
/**
 * Rendert die Download-Partials mit Beispieldaten (ohne Datenbank) für den Browser-Test.
 *
 *   php render-partials.php block '<Blockdaten als JSON>'   HTML eines Blocks (Daten laufen durch sanitizeBlock)
 *   php render-partials.php page                             HTML-Seite mit allen vier Templates
 */

declare(strict_types=1);

use CMS\Services\Downloads\DownloadRenderer;
use CMS\Services\Downloads\DownloadSettings;

define('ABSPATH', dirname(__DIR__, 3) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'emergency');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs/');

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'CMS\\')) {
        $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
require ABSPATH . 'includes/functions/escaping.php';

/** Übersetzung direkt aus der deutschen Sprachdatei (Zeilenformat `"schlüssel": "text"`). */
function __(string $key): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        preg_match_all('/^\s*"([^"]+)":\s*"((?:[^"\\\\]|\\\\.)*)"\s*$/m', (string) file_get_contents(ABSPATH . 'lang/de.yaml'), $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $map[$match[1]] = stripcslashes($match[2]);
        }
    }

    return $map[$key] ?? $key;
}

/**
 * @param array<string,mixed> $options
 * @return array<string,mixed>
 */
function sampleModel(int $id, array $options): array
{
    $samples = [
        11 => ['Benutzerhandbuch', 'benutzerhandbuch', 'pdf', 'document', 'benutzerhandbuch-2.1.pdf', '2.1', '1,4 MB', 'Alle Funktionen des Systems Schritt für Schritt erklärt.'],
        12 => ['Installationspaket', 'installationspaket', 'zip', 'archive', '365cms-4.0.0.zip', '4.0.0', '18,2 MB', 'Vollständiges Paket für die Neuinstallation.'],
    ];
    [$title, $slug, $ext, $group, $file, $version, $size, $summary] = $samples[$id] ?? ['Hochgeladene Datei', 'hochgeladene-datei', 'pdf', 'document', 'datei.pdf', '1.0', '1 KB', ''];
    $title = (string) ($options['titleOverride'] ?? '') !== '' ? (string) $options['titleOverride'] : $title;
    $known = ['plattform' => ['Plattform', 'Windows'], 'sprache' => ['Sprache', 'Deutsch'], 'lizenznummer' => ['Lizenznummer', 'AB-123']];
    $properties = [];
    foreach ((array) ($options['showProperties'] ?? []) as $key) {
        if (isset($known[$key])) {
            $properties[] = ['key' => $key, 'label' => $known[$key][0], 'type' => 'text', 'value' => $known[$key][1], 'url' => ''];
        }
    }
    $button = (string) ($options['buttonLabel'] ?? '') !== '' ? (string) $options['buttonLabel'] : __('downloads.button.download');

    return [
        'id' => $id, 'title' => $title, 'slug' => $slug, 'summary' => $summary, 'category' => 'Handbücher', 'category_url' => '/downloads/category/handbuecher',
        'ext' => $ext, 'ext_label' => strtoupper($ext), 'file_group' => $group, 'file_name' => $file, 'is_external' => false,
        'version' => $version, 'version_pinned' => false, 'release_date' => '2026-11-02', 'release_date_label' => '02.11.2026',
        'size' => 1468006, 'size_label' => $size, 'count' => 128, 'count_label' => '128',
        'sha256' => str_repeat('a1b2c3d4', 8), 'changelog' => 'Kapitel „Downloads“ ergänzt.', 'changelog_excerpt' => 'Kapitel „Downloads“ ergänzt.',
        'requirements' => 'PHP 8.4', 'license' => 'MIT', 'author' => '', 'is_signed' => false, 'signature_info' => '', 'cover_url' => '',
        'external_links' => [], 'properties' => $properties, 'url' => '/download/' . $slug, 'track_source' => false,
        'locked' => false, 'lock_state' => '', 'lock_message' => '', 'login_url' => '', 'single_url' => '/downloads/' . $slug,
        'button_label' => $button, 'aria_label' => sprintf(__('downloads.aria.download'), $title, $file, $size),
        'badge' => 'new', 'badge_label' => __('downloads.badge.new'), 'status' => 'published', 'mode' => 'preview',
    ];
}

/**
 * @param array<string,mixed> $data
 */
function renderBlock(array $data): string
{
    $block = DownloadRenderer::sanitizeBlock($data);
    $downloads = array_map(static fn (int $id): array => sampleModel($id, $block['options']), $block['downloadIds']);
    if ($downloads === []) {
        return '';
    }
    $options = $block['options'];
    $template = $block['template'];
    $download = $downloads[0];
    $mode = 'preview';
    ob_start();
    include ABSPATH . 'views/partials/download/' . $template . '.php';

    return (string) ob_get_clean();
}

if (($argv[1] ?? '') === 'block') {
    echo renderBlock((array) json_decode((string) ($argv[2] ?? '{}'), true));
    exit;
}

echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Download-Layouts</title><link rel="stylesheet" href="/assets/css/downloads.css">'
    . '<style>body{font-family:system-ui,sans-serif;max-width:760px;margin:2rem auto;padding:0 1rem;color:#1f2933}h2{font-size:1rem;margin:2rem 0 0;color:#5b6472}</style></head><body>';
foreach (DownloadSettings::TEMPLATES as $template) {
    echo '<section data-template="' . $template . '"><h2>' . esc_html(__('downloads.template.' . $template)) . ' (' . $template . ')</h2>';
    if ($template === 'button') {
        echo '<p>Das aktuelle Handbuch steht hier bereit:</p>';
    }
    echo renderBlock(['downloadIds' => $template === 'compact' ? [11, 12] : [11], 'template' => $template, 'options' => ['showProperties' => ['plattform', 'sprache']]]);
    echo '</section>';
}
echo '</body></html>';
