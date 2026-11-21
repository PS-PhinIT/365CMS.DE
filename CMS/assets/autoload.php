<?php
/**
 * 365CMS Vendor Autoloader (Produktiv)
 *
 * Zentraler Autoloader für alle externen PHP-Libraries in CMS/assets/.
 * Dieses Verzeichnis wird komplett aufs Shared Hosting deployed.
 *
 * ASSETS/ (Repo-Root) ist nur lokale Entwicklungs-Ablage und wird NICHT deployed.
 * Alle benötigten Library-Dateien liegen hier in CMS/assets/.
 *
 * Verwendung (automatisch via Bootstrap.php):
 *   require_once ABSPATH . 'assets/autoload.php';
 *
 * @package 365CMS\Vendor
 */

declare(strict_types=1);

if (!defined('CMS_VENDOR_PATH')) {
    define('CMS_VENDOR_PATH', __DIR__ . DIRECTORY_SEPARATOR);
}

if (!function_exists('cms_vendor_psr4_map')) {
    /**
     * PSR-4-Zuordnung Namespace-Präfix → Verzeichnis(se) relativ zu CMS/assets/.
     * Längere Präfixe werden zuerst geprüft, damit z. B. Symfony\Component\Mailer\
     * nicht von einem allgemeineren Präfix verschluckt wird.
     *
     * @return array<string, list<string>>
     */
    function cms_vendor_psr4_map(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $map = [
            // ─── PSR-Interfaces ───────────────────────────────────────────
            'Psr\\Log\\'             => ['psr/Log'],
            'Psr\\EventDispatcher\\' => ['psr/EventDispatcher'],
            'Psr\\Container\\'       => ['psr/Container'],
            'Psr\\Clock\\'           => ['psr/Clock'],
            'Psr\\SimpleCache\\'     => ['psr/SimpleCache'],

            // ─── Symfony Contracts (symfony/contracts 3.6.1, vollständig) ─
            'Symfony\\Contracts\\' => ['symfony-contracts'],

            // ─── Symfony Polyfills (greifen nur ohne passende PHP-Extension) ─
            'Symfony\\Polyfill\\Mbstring\\'         => ['polyfill-mbstring'],
            'Symfony\\Polyfill\\Ctype\\'            => ['polyfill-ctype'],
            'Symfony\\Polyfill\\Intl\\Idn\\'        => ['polyfill-intl-idn'],
            'Symfony\\Polyfill\\Intl\\Normalizer\\' => ['polyfill-intl-normalizer'],
            'Symfony\\Polyfill\\Intl\\Grapheme\\'   => ['polyfill-intl-grapheme'],
            'Symfony\\Polyfill\\Uuid\\'             => ['polyfill-uuid'],

            // ─── Symfony Komponenten (8.0.x) ──────────────────────────────
            'Symfony\\Component\\Mailer\\'          => ['mailer'],
            'Symfony\\Component\\Mime\\'            => ['mime'],
            'Symfony\\Component\\Translation\\'     => ['translation'],
            'Symfony\\Component\\Yaml\\'            => ['yaml'],
            'Symfony\\Component\\Clock\\'           => ['clock'],
            'Symfony\\Component\\EventDispatcher\\' => ['event-dispatcher'],
            'Symfony\\Component\\Serializer\\'      => ['serializer'],
            'Symfony\\Component\\PropertyInfo\\'    => ['property-info'],
            'Symfony\\Component\\PropertyAccess\\'  => ['property-access'],
            'Symfony\\Component\\TypeInfo\\'        => ['type-info'],
            'Symfony\\Component\\Uid\\'             => ['uid'],
            'Symfony\\Component\\String\\'          => ['string'],
            'Symfony\\AI\\Platform\\'               => ['ai-platform/src'],

            // ─── Mail-Validierung (Pflicht für Symfony Mime Address/SMTP) ─
            'Egulias\\EmailValidator\\' => ['egulias-email-validator'],
            'Doctrine\\Common\\Lexer\\' => ['doctrine-lexer'],
            'Doctrine\\Deprecations\\'  => ['doctrine-deprecations'],

            // ─── AI-Platform-Abhängigkeiten ───────────────────────────────
            'OskarStark\\Enum\\'      => ['oskarstark-enum-helper'],
            'phpDocumentor\\Reflection\\' => [
                'phpdocumentor-reflection-docblock',
                'phpdocumentor-type-resolver',
                'phpdocumentor-reflection-common',
            ],
            'PHPStan\\PhpDocParser\\' => ['phpstan-phpdoc-parser'],
            'Webmozart\\Assert\\'     => ['webmozart-assert'],

            // ─── Fach-Libraries ───────────────────────────────────────────
            'Melbahja\\Seo\\'     => ['melbahja-seo/src'],
            'Poliander\\Cron\\'   => ['cron'],
            'TeamTNT\\TNTSearch\\' => ['tntsearchsrc'],
            'Carbon\\'            => ['Carbon/src/Carbon'],
            'lbuchs\\WebAuthn\\'  => ['webauthn'],
            'RobThree\\Auth\\'    => ['twofactorauth'],
            'BaconQrCode\\'       => ['bacon-qr-code'],
            'DASPRiD\\Enum\\'     => ['dasprid-enum'],
            'LdapRecord\\'        => ['ldaprecord'],
            'Firebase\\JWT\\'     => ['php-jwt'],
        ];

        uksort($map, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return $map;
    }
}

spl_autoload_register(static function (string $class): void {
    static $classMap = [
        // polyfill-intl-normalizer: globale Normalizer-Klasse nur ohne ext-intl
        'Normalizer' => 'polyfill-intl-normalizer/Resources/stubs/Normalizer.php',
    ];

    if (isset($classMap[$class])) {
        $file = CMS_VENDOR_PATH . str_replace('/', DIRECTORY_SEPARATOR, $classMap[$class]);
        if (is_file($file)) {
            require_once $file;
        }
        return;
    }

    // Gebündelte Einzeldateien: gescopte Namensräume (CMS\Vendor\…), eine Datei je Bündel, geladen beim ersten Bedarf.
    // Erzeugt von .github/deps/build-commonmark-bundle.php; Prüfsumme in DOC/assets/commonmark/README.md.
    static $bundles = [
        'CMS\\Vendor\\League\\CommonMark\\'     => 'commonmark/commonmark.php',
        'CMS\\Vendor\\League\\Config\\'         => 'commonmark/commonmark.php',
        'CMS\\Vendor\\Dflydev\\DotAccessData\\' => 'commonmark/commonmark.php',
        'CMS\\Vendor\\Nette\\'                  => 'commonmark/commonmark.php',
    ];
    foreach ($bundles as $prefix => $bundle) {
        if (strncmp($prefix, $class, strlen($prefix)) === 0) {
            $file = CMS_VENDOR_PATH . str_replace('/', DIRECTORY_SEPARATOR, $bundle);
            if (is_file($file)) {
                require_once $file;
            }
            return;
        }
    }

    foreach (cms_vendor_psr4_map() as $prefix => $dirs) {
        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            continue;
        }

        $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
        foreach ($dirs as $dir) {
            $file = CMS_VENDOR_PATH . str_replace('/', DIRECTORY_SEPARATOR, $dir) . DIRECTORY_SEPARATOR . $relative;
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
    }
});

// ─── Composer-"files"-Einträge (Funktionen/Polyfills) ──────────────────────
// Reihenfolge wie bei Composer: Polyfills vor den Paketen, die sie nutzen.
foreach ([
    'polyfill-ctype/bootstrap.php',
    'polyfill-mbstring/bootstrap.php',
    'polyfill-intl-normalizer/bootstrap.php',
    'polyfill-intl-idn/bootstrap.php',
    'polyfill-intl-grapheme/bootstrap.php',
    'polyfill-uuid/bootstrap.php',
    'symfony-contracts/Deprecation/function.php',
    'clock/Resources/now.php',
    'string/Resources/functions.php',
    'tntsearchhelper/helpers.php',
] as $_cmsVendorFile) {
    $_cmsVendorFile = CMS_VENDOR_PATH . str_replace('/', DIRECTORY_SEPARATOR, $_cmsVendorFile);
    if (is_file($_cmsVendorFile)) {
        require_once $_cmsVendorFile;
    }
}
unset($_cmsVendorFile);

// ─── HTMLPurifier (bringt eigenen Autoloader mit) ──────────────────────────
$_htmlPurifierLib = CMS_VENDOR_PATH . 'htmlpurifier' . DIRECTORY_SEPARATOR . 'HTMLPurifier.auto.php';
if (is_file($_htmlPurifierLib)) {
    require_once $_htmlPurifierLib;
}
unset($_htmlPurifierLib);
