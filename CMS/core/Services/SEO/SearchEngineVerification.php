<?php
/**
 * Website-Verifizierung für Webmaster-Tools der Suchmaschinen (Meta-Tag-Methode).
 *
 * @package CMSv2\Core\Services\SEO
 */

declare(strict_types=1);

namespace CMS\Services\SEO;

use CMS\Services\OptionStore;

if (!defined('ABSPATH')) {
    exit;
}

final class SearchEngineVerification
{
    /**
     * Unterstützte Suchmaschinen mit Meta-Name, Option und Webmaster-Tool.
     *
     * @var array<string, array{label:string, meta_name:string, option:string, tool_label:string, tool_url:string, indexnow:bool}>
     */
    public const ENGINES = [
        'google' => [
            'label' => 'Google',
            'meta_name' => 'google-site-verification',
            'option' => 'seo_verification_google',
            'tool_label' => 'Google Search Console',
            'tool_url' => 'https://search.google.com/search-console',
            'indexnow' => false,
        ],
        'bing' => [
            'label' => 'Bing',
            'meta_name' => 'msvalidate.01',
            'option' => 'seo_verification_bing',
            'tool_label' => 'Bing Webmaster Tools',
            'tool_url' => 'https://www.bing.com/webmasters',
            'indexnow' => true,
        ],
        'yandex' => [
            'label' => 'Yandex',
            'meta_name' => 'yandex-verification',
            'option' => 'seo_verification_yandex',
            'tool_label' => 'Yandex Webmaster',
            'tool_url' => 'https://webmaster.yandex.com',
            'indexnow' => true,
        ],
        'seznam' => [
            'label' => 'Seznam',
            'meta_name' => 'seznam-wmt',
            'option' => 'seo_verification_seznam',
            'tool_label' => 'Seznam Webmaster',
            'tool_url' => 'https://webmaster.seznam.cz',
            'indexnow' => true,
        ],
        'naver' => [
            'label' => 'Naver',
            'meta_name' => 'naver-site-verification',
            'option' => 'seo_verification_naver',
            'tool_label' => 'Naver Search Advisor',
            'tool_url' => 'https://searchadvisor.naver.com',
            'indexnow' => true,
        ],
        'baidu' => [
            'label' => 'Baidu',
            'meta_name' => 'baidu-site-verification',
            'option' => 'seo_verification_baidu',
            'tool_label' => 'Baidu Ziyuan',
            'tool_url' => 'https://ziyuan.baidu.com',
            'indexnow' => false,
        ],
    ];

    private const TOKEN_PATTERN = '/^[A-Za-z0-9._:+\/=-]{4,200}$/';

    /**
     * Normalisiert einen Verifizierungscode. Akzeptiert wird der reine Code oder der komplette Meta-Tag
     * aus dem Webmaster-Tool, aus dem der `content`-Wert übernommen wird.
     *
     * @return array{value:string, error:string}
     */
    public static function normalizeCode(string $engine, string $input): array
    {
        $definition = self::ENGINES[$engine] ?? null;
        if ($definition === null) {
            return ['value' => '', 'error' => 'Unbekannte Suchmaschine.'];
        }

        $input = trim($input);
        if ($input === '') {
            return ['value' => '', 'error' => ''];
        }

        if (stripos($input, '<meta') !== false) {
            if (preg_match('/\bname\s*=\s*(["\'])(.*?)\1/i', $input, $nameMatch) === 1
                && strcasecmp(trim($nameMatch[2]), $definition['meta_name']) !== 0) {
                return [
                    'value' => '',
                    'error' => $definition['label'] . ': Der eingefügte Meta-Tag gehört zu „' . trim($nameMatch[2]) . '“, erwartet wird „' . $definition['meta_name'] . '“.',
                ];
            }

            if (preg_match('/\bcontent\s*=\s*(["\'])(.*?)\1/i', $input, $contentMatch) !== 1) {
                return ['value' => '', 'error' => $definition['label'] . ': Im eingefügten Meta-Tag wurde kein content-Wert gefunden.'];
            }

            $input = trim($contentMatch[2]);
        }

        if (preg_match('/\.html?$/i', $input) === 1) {
            return [
                'value' => '',
                'error' => $definition['label'] . ': Bitte die Meta-Tag-Methode verwenden – HTML-Bestätigungsdateien werden hier nicht unterstützt.',
            ];
        }

        if (preg_match(self::TOKEN_PATTERN, $input) !== 1) {
            return [
                'value' => '',
                'error' => $definition['label'] . ': Der Verifizierungscode enthält ungültige Zeichen oder hat eine unzulässige Länge.',
            ];
        }

        return ['value' => $input, 'error' => ''];
    }

    /**
     * @return array<string, string> Gespeicherte Codes je Suchmaschine
     */
    public static function getCodes(): array
    {
        $options = [];
        foreach (self::ENGINES as $engine => $definition) {
            $options[$engine] = $definition['option'];
        }

        $values = OptionStore::getInstance()->getMany(array_values($options), '');
        $codes = [];
        foreach ($options as $engine => $option) {
            $code = trim((string) ($values[$option] ?? ''));
            $codes[$engine] = preg_match(self::TOKEN_PATTERN, $code) === 1 ? $code : '';
        }

        return $codes;
    }

    public static function renderTags(): string
    {
        $html = '';
        foreach (self::getCodes() as $engine => $code) {
            if ($code === '') {
                continue;
            }

            $html .= '<meta name="' . htmlspecialchars(self::ENGINES[$engine]['meta_name'], ENT_QUOTES, 'UTF-8')
                . '" content="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }

        return $html;
    }
}
