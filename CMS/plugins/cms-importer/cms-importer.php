<?php
/**
 * Plugin Name: CMS WordPress Importer
 * Description: Importiert WordPress-WXR-Exportdateien sowie Rank-Math-Settings-JSON mit sinnvollen SEO-Defaults und Weiterleitungen samt Beiträgen, Seiten, Kommentaren, Tabellen, SEO-Metadaten und Bildern passend nach 365CMS. Unbekannte Meta-Felder werden protokolliert und als Markdown-Bericht gespeichert.
 * Version:      3.1.0
 * Author:       365 Network
 * Author URI:   https://365network.de
 *
 * @package CMS_Importer
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (defined('CMS_IMPORTER_BOOTSTRAPPED')) {
    return;
}

define('CMS_IMPORTER_BOOTSTRAPPED', true);

// ── Plugin Constants ─────────────────────────────────────────────────────────
defined('CMS_IMPORTER_VERSION') || define('CMS_IMPORTER_VERSION', '3.1.0');
defined('CMS_IMPORTER_PLUGIN_DIR') || define('CMS_IMPORTER_PLUGIN_DIR', dirname(__FILE__) . '/');
defined('CMS_IMPORTER_PLUGIN_URL') || define('CMS_IMPORTER_PLUGIN_URL', '/plugins/cms-importer/');
defined('CMS_IMPORTER_TEXT_DOMAIN') || define('CMS_IMPORTER_TEXT_DOMAIN', 'cms-importer');

// ── Autoload ──────────────────────────────────────────────────────────────────
if (!class_exists('CMS_Importer_XML_Parser', false)) {
    require_once CMS_IMPORTER_PLUGIN_DIR . 'includes/class-xml-parser.php';
}

if (!class_exists('CMS_Importer_DB', false) || !class_exists('CMS_Importer_Service', false)) {
    require_once CMS_IMPORTER_PLUGIN_DIR . 'includes/class-importer.php';
}

if (!class_exists('CMS_Importer_Admin', false)) {
    require_once CMS_IMPORTER_PLUGIN_DIR . 'includes/class-admin.php';
}

/**
 * Haupt-Klasse des CMS WordPress Importer Plugins.
 *
 * @since 1.0.0
 */
if (!class_exists('CMS_Importer', false)) {
final class CMS_Importer
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->init_hooks();
    }

    private function init_hooks(): void
    {
        if (class_exists('CMS\Hooks')) {
            CMS\Hooks::addAction('cms_init',        [$this, 'init'],          10);
            CMS\Hooks::addAction('plugin_activated', [$this, 'on_activation'], 10);
            CMS\Hooks::addAction('cms_admin_menu',   [$this, 'register_admin_pages'], 20);
            CMS\Hooks::addAction('admin_head',       [$this, 'enqueue_styles'], 10);
            CMS\Hooks::addAction('admin_body_end',   [$this, 'enqueue_scripts'], 10);
        }
    }

    public function init(): void
    {
        // Tabellen ggf. anlegen
        CMS_Importer_DB::create_tables();
    }

    public function on_activation(string $plugin): void
    {
        if ($plugin === 'cms-importer') {
            CMS_Importer_DB::create_tables();
        }
    }

    public function register_admin_pages(): void
    {
        CMS_Importer_Admin::instance()->register_pages();
    }

    public function enqueue_styles(): void
    {
        if (!$this->is_importer_admin_request()) {
            return;
        }

        $css_file = CMS_IMPORTER_PLUGIN_DIR . 'assets/css/importer.css';
        if (file_exists($css_file)) {
            echo '<link rel="stylesheet" href="' . htmlspecialchars(CMS_IMPORTER_PLUGIN_URL . 'assets/css/importer.css')
                . '?v=' . filemtime($css_file) . '">' . "\n";
        }
    }

    public function enqueue_scripts(): void
    {
        if (!$this->is_importer_admin_request()) {
            return;
        }

        $js_file = CMS_IMPORTER_PLUGIN_DIR . 'assets/js/importer.js';
        if (file_exists($js_file)) {
            echo '<script src="' . htmlspecialchars(CMS_IMPORTER_PLUGIN_URL . 'assets/js/importer.js')
                . '?v=' . filemtime($js_file) . '" defer></script>' . "\n";
        }
    }

    private function is_importer_admin_request(): bool
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($requestUri === '') {
            return false;
        }

        return str_contains($requestUri, '/admin/plugins/cms-importer/')
            || str_contains($requestUri, '/admin/plugins/cms-importer');
    }
}
}

// ── Start ─────────────────────────────────────────────────────────────────────
if (class_exists('CMS\Hooks')) {
    CMS\Hooks::addAction('plugins_loaded', function () {
        CMS_Importer::instance();
    });
} else {
    // Fallback: direkt starten (für Entwicklung / Test)
    CMS_Importer::instance();
}
