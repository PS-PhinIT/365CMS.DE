<?php
/**
 * Translation Service
 *
 * Internationalisierung über Symfony Translation (wenn verfügbar)
 * mit robustem Fallback für Shared-Hosting-Umgebungen.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;

if (!defined('ABSPATH')) {
    exit;
}

final class TranslationService
{
    private static ?self $instance = null;

    private readonly Database $db;
    private readonly string $prefix;
    private readonly string $langPath;

    private string $locale = 'de';

    /** @var array<string, array<string, array<string, string>>> */
    private array $fallbackCatalog = [];

    /** @var object|null */
    private ?object $translator = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->langPath = ABSPATH . 'lang/';

        $this->locale = $this->resolveLocale();
        $this->initTranslator();
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * @return string[]
     */
    public function getAvailableLocales(): array
    {
        if (!is_dir($this->langPath)) {
            return ['de', 'en'];
        }

        $files = glob($this->langPath . '*.yaml') ?: [];
        $locales = [];

        foreach ($files as $file) {
            $locale = pathinfo($file, PATHINFO_FILENAME);
            if (preg_match('/^[a-z]{2}(?:_[A-Z]{2})?$/', $locale) === 1) {
                $locales[] = $locale;
            }
        }

        $locales = array_values(array_unique($locales));
        sort($locales);

        return $locales !== [] ? $locales : ['de', 'en'];
    }

    public function translate(string $message, string $domain = 'default', array $parameters = []): string
    {
        if ($this->translatorHas($message, $domain)) {
            try {
                /** @var string $result */
                $result = $this->translator->trans($message, $parameters, $domain, $this->locale);
                return $result;
            } catch (\Throwable) {
                $this->translator = null;
            }
        }

        $translated = $this->fallbackCatalog[$this->locale][$domain][$message]
            ?? $this->fallbackCatalog[$this->locale]['default'][$message]
            ?? $message;

        if ($parameters !== []) {
            $translated = strtr($translated, $parameters);
        }

        return $translated;
    }

    public function translatePlural(string $single, string $plural, int $number, string $domain = 'default'): string
    {
        $key = $single . '|' . $plural;
        if ($this->translatorHas($key, $domain)) {
            try {
                /** @var string $result */
                $result = $this->translator->trans($key, ['%count%' => $number], $domain, $this->locale);
                return $result;
            } catch (\Throwable) {
                $this->translator = null;
            }
        }

        $selected = ($number === 1) ? $single : $plural;
        return $this->translate($selected, $domain, ['%count%' => (string)$number]);
    }

    private function resolveLocale(): string
    {
        $available = $this->getAvailableLocales();

        try {
            // `language` schreibt Einstellungen → Allgemein; `setting_language` ist ein Altschlüssel.
            $options = OptionStore::getInstance();
            foreach (['language', 'setting_language'] as $optionName) {
                $value = (string) ($options->get($optionName) ?? '');
                if ($value !== '' && in_array($value, $available, true)) {
                    return $value;
                }
            }
        } catch (\Throwable) {
            // Fallback zu Default
        }

        return in_array('de', $available, true) ? 'de' : ($available[0] ?? 'de');
    }

    /**
     * Prüft, ob der Symfony-Translator den Key im aktuellen Locale kennt.
     * Unbekannte Keys laufen bewusst über den Fallback-Katalog, damit
     * Plural-Keys nicht als unübersetzter Original-Text zurückkommen.
     */
    private function translatorHas(string $key, string $domain): bool
    {
        if ($this->translator === null) {
            return false;
        }

        try {
            return $this->translator->getCatalogue($this->locale)->has($key, $domain);
        } catch (\Throwable) {
            $this->translator = null;
            return false;
        }
    }

    private function initTranslator(): void
    {
        foreach ($this->getAvailableLocales() as $locale) {
            $file = $this->langPath . $locale . '.yaml';
            if (!is_file($file)) {
                continue;
            }
            $this->fallbackCatalog[$locale] = $this->parseCatalogFile($file);
        }

        $translatorClass = '\\Symfony\\Component\\Translation\\Translator';
        $arrayLoaderClass = '\\Symfony\\Component\\Translation\\Loader\\ArrayLoader';

        if (!class_exists($translatorClass) || !class_exists($arrayLoaderClass)) {
            return;
        }

        try {
            /** @var object $translator */
            $translator = new $translatorClass($this->locale);
            $translator->addLoader('array', new $arrayLoaderClass());

            // Die Sprachdateien gruppieren Keys unter Domain-Schlüsseln (`default:`).
            // Der YamlFileLoader würde daraus `default.Key` flach klopfen, deshalb
            // wird der bereits geparste Katalog je Domain direkt übergeben.
            foreach ($this->fallbackCatalog as $locale => $domains) {
                foreach ($domains as $domain => $messages) {
                    if ($messages !== []) {
                        $translator->addResource('array', $messages, $locale, $domain);
                    }
                }
            }

            $this->translator = $translator;
        } catch (\Throwable) {
            $this->translator = null;
        }
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function parseCatalogFile(string $file): array
    {
        $yamlClass = '\\Symfony\\Component\\Yaml\\Yaml';
        if (class_exists($yamlClass)) {
            try {
                $parsed = $yamlClass::parseFile($file);
                if (is_array($parsed)) {
                    return $this->normalizeCatalog($parsed);
                }
            } catch (\Throwable) {
                // Fallback auf den minimalen Parser
            }
        }

        return $this->parseSimpleYamlCatalog($file);
    }

    /**
     * @param array<mixed> $parsed
     * @return array<string, array<string, string>>
     */
    private function normalizeCatalog(array $parsed): array
    {
        $catalog = ['default' => []];

        foreach ($parsed as $domainOrKey => $value) {
            if (is_array($value)) {
                foreach ($value as $key => $message) {
                    if (is_scalar($message)) {
                        $catalog[(string) $domainOrKey][(string) $key] = (string) $message;
                    }
                }
                continue;
            }

            if (is_scalar($value)) {
                $catalog['default'][(string) $domainOrKey] = (string) $value;
            }
        }

        return $catalog;
    }

    /**
     * Minimaler YAML-Parser für flache Domain-Kataloge.
     * Unterstützt das Format:
     *
     * default:
     *   "Key": "Value"
     */
    private function parseSimpleYamlCatalog(string $file): array
    {
        $catalog = ['default' => []];
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        $currentDomain = 'default';

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^([a-zA-Z0-9_\-]+):\s*$/', $trimmed, $m) === 1) {
                $currentDomain = $m[1];
                if (!isset($catalog[$currentDomain])) {
                    $catalog[$currentDomain] = [];
                }
                continue;
            }

            if (preg_match('/^\s*["\'](.+?)["\']\s*:\s*["\'](.*)["\']\s*$/', $line, $m) === 1) {
                $catalog[$currentDomain][$m[1]] = stripcslashes($m[2]);
                continue;
            }

            if (preg_match('/^\s*([^:\"\']+)\s*:\s*(.+)\s*$/', $line, $m) === 1) {
                $key = trim($m[1]);
                $value = trim($m[2], " \t\"'");
                $catalog[$currentDomain][$key] = stripcslashes($value);
            }
        }

        return $catalog;
    }
}
