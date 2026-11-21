<?php
/**
 * HTML Purifier Service
 *
 * Wrapper um HTMLPurifier für sichere HTML-Sanitierung.
 * Ersetzt die rudimentäre strip_tags()-basierte Lösung in wp_kses_post().
 *
 * Verwendung:
 *   $safe = PurifierService::getInstance()->purify($unsafeHtml);
 *   $safe = PurifierService::getInstance()->purify($html, 'strict');
 *
 * Profile:
 *   - 'default'  → Standard CMS-Whitelist (Posts, Seiten)
 *   - 'hub'      → Hub-Seiten mit Struktur-Tags (section, article, nav …)
 *   - 'table'    → Tabellen-/Beschreibungstexte mit Struktur-Tags
 *   - 'table_cell' → Nur Inline-Formatierung und Links für Tabellenzellen
 *   - 'strict'   → Nur Text-Formatierung (Kommentare, User-Bio)
 *   - 'minimal'  → Nur Inline-Tags (Nachrichten)
 *   - 'community' → Community-Beiträge nach CommonMark (ContentRenderer): eigene Schema- und Klassenliste, keine fremden Bilder
 *
 * Externe Links redaktioneller Profile bleiben „follow“; nur nutzergenerierte Inhalte
 * ('strict', 'minimal', 'community') erhalten rel="nofollow ugc". Links auf die eigene Domain (exakter Host aus SITE_URL)
 * gelten als intern; protokollrelative Ziele (`//host`) gelten wie https.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

if (!defined('ABSPATH')) {
    exit;
}

class PurifierService
{
    private static ?self $instance = null;
    private const HTML_DEFINITION_REVISION = 2026112001;

    /** Profile für nutzergenerierte Inhalte: externe Links erhalten rel="nofollow ugc". */
    private const UGC_PROFILES = ['strict', 'minimal', 'community'];

    /** @var array<string, \HTMLPurifier> Gecachte Purifier-Instanzen pro Profil */
    private array $purifiers = [];

    /** @var bool Ob HTMLPurifier verfügbar ist */
    private readonly bool $available;

    /** @var string Cache-Verzeichnis für HTMLPurifier-Serializer */
    private readonly string $cacheDir;

    private ?string $siteHost = null;

    /** URI-Schemata aller Profile ohne eigene Angabe. */
    private const DEFAULT_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * Bekannte Sanitierungs-Profile mit erlaubten HTML-Elementen
     *
     * Optionale Schlüssel (nur gesetzte wirken, die übrigen Profile bleiben unverändert): `schemes` (URI.AllowedSchemes statt
     * DEFAULT_SCHEMES), `classes` (Attr.AllowedClasses), `external_resources` (false → URI.DisableExternalResources).
     *
     * @var array<string, array{elements: string, attributes: string, schemes?: list<string>, classes?: list<string>, external_resources?: bool}>
     */
    private const PROFILES = [
        'default' => [
            'elements'   => 'section,p,a,strong,b,em,i,u,ul,ol,li,br,h1,h2,h3,h4,h5,h6,blockquote,cite,pre,code,img,table,caption,thead,tbody,tfoot,tr,th,td,hr,span,div,figure,figcaption,dl,dt,dd,sub,sup,abbr,mark,del,ins,details,summary,video,source,audio',
            'attributes' => 'a.href,a.title,a.target,a.rel,a.class,a.aria-label,img.src,img.alt,img.width,img.height,img.loading,img.fetchpriority,img.decoding,img.class,figure.class,figcaption.class,caption.class,td.colspan,td.rowspan,td.class,th.colspan,th.rowspan,th.class,span.class,span.aria-hidden,div.class,div.id,div.role,div.aria-hidden,div.data-height,div.data-cms-editorjs-spacing,div.data-cms-editorjs-align,section.class,section.id,p.class,ul.class,ol.class,li.class,blockquote.class,cite.class,table.class,thead.class,tbody.class,tfoot.class,tr.class,pre.class,code.class,h1.id,h1.class,h2.id,h2.class,h3.id,h3.class,h4.id,h4.class,h5.id,h5.class,h6.id,h6.class,video.src,video.controls,video.width,video.height,source.src,source.type,audio.src,audio.controls,details.open',
        ],
        'hub' => [
            'elements'   => 'section,article,nav,aside,header,footer,main,p,a,strong,b,em,i,u,ul,ol,li,br,h1,h2,h3,h4,h5,h6,blockquote,cite,pre,code,img,table,caption,thead,tbody,tfoot,tr,th,td,hr,span,div,figure,figcaption,dl,dt,dd,sub,sup,abbr,mark,del,ins,details,summary,video,source,audio,small',
            'attributes' => 'a.href,a.title,a.target,a.rel,a.class,a.aria-label,a.aria-current,img.src,img.alt,img.width,img.height,img.loading,img.fetchpriority,img.decoding,img.class,td.colspan,td.rowspan,td.class,th.colspan,th.rowspan,th.class,span.class,span.aria-hidden,div.class,div.id,div.role,div.aria-hidden,div.data-height,div.data-cms-editorjs-spacing,div.data-cms-editorjs-align,p.class,section.class,section.id,article.class,article.id,nav.class,nav.id,nav.aria-label,aside.class,aside.id,header.class,header.id,footer.class,footer.id,main.class,main.id,figure.class,figcaption.class,ul.class,ol.class,li.class,blockquote.class,cite.class,pre.class,code.class,h1.id,h1.class,h2.id,h2.class,h3.id,h3.class,h4.id,h4.class,h5.id,h5.class,h6.id,h6.class,details.open,details.class,summary.class,video.src,video.controls,video.width,video.height,source.src,source.type,audio.src,audio.controls,table.class,thead.class,tbody.class,tfoot.class,tr.class,caption.class',
        ],
        'table' => [
            'elements'   => 'p,a,strong,b,em,i,u,ul,ol,li,br,blockquote,pre,code,img,span,div,small,mark,sub,sup,table,thead,tbody,tfoot,tr,th,td,caption',
            'attributes' => 'a.href,a.title,a.target,a.rel,a.class,img.src,img.alt,img.width,img.height,img.loading,img.class,td.colspan,td.rowspan,td.class,th.colspan,th.rowspan,th.class,span.class,div.class,p.class,table.class,caption.class,pre.class,code.class',
        ],
        'table_cell' => [
            'elements'   => 'a,strong,b,em,i,u',
            'attributes' => 'a.href,a.title,a.target,a.rel',
        ],
        'strict' => [
            'elements'   => 'p,a,strong,b,em,i,u,br,ul,ol,li,blockquote,code,pre',
            'attributes' => 'a.href,a.title,a.rel',
        ],
        'minimal' => [
            'elements'   => 'strong,b,em,i,u,br,code,a',
            'attributes' => 'a.href',
        ],
        // Community-Beiträge (Markdown → CommonMark → hier). Keine Klassen außer der Allowlist (Sprachen aus ContentRenderer::LANGUAGES,
        // Ausrichtung, Aufgaben, Zitate inkl. Überlauf der Zitatgrenze, Spoiler), keine fremden Bilder, nur http/https/mailto;
        // Kennzeichen der Zitat-Blöcke (data-quote-post) für die Neutralisierung je Betrachter.
        'community' => [
            'elements'   => 'p,br,strong,em,del,a,code,pre,blockquote,ul,ol,li,h3,h4,hr,table,thead,tbody,tr,th,td,img,span,details,summary',
            'attributes' => 'a.href,a.title,a.rel,ol.start,code.class,th.class,td.class,img.src,img.alt,img.width,img.height,img.loading,img.decoding,span.class,blockquote.class,blockquote.data-quote-post,details.class',
            'schemes'    => ['http', 'https', 'mailto'],
            'classes'    => [
                'language-php', 'language-javascript', 'language-typescript', 'language-xml', 'language-css', 'language-sql', 'language-bash',
                'language-powershell', 'language-json', 'language-yaml', 'language-python', 'language-csharp', 'language-diff', 'language-ini',
                'language-plaintext', 'text-start', 'text-center', 'text-end', 'cms-task', 'cms-task--done', 'cms-quote', 'cms-quote--hidden', 'cms-spoiler',
            ],
            'external_resources' => false,
        ],
    ];

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->available = class_exists('HTMLPurifier', true);
        $this->cacheDir  = $this->resolveCacheDirectory();
    }

    /**
     * HTML sanitieren.
     *
     * @param string $dirty   Unsicherer HTML-String
     * @param string $profile Sanitierungs-Profil ('default', 'strict', 'minimal')
     * @return string          Sauberer HTML-String
     */
    public function purify(string $dirty, string $profile = 'default'): string
    {
        if ($dirty === '') {
            return '';
        }

        // Fallback wenn HTMLPurifier nicht geladen ist
        if (!$this->available) {
            return $this->hardenAnchorLinks($this->fallbackSanitize($dirty, $profile), $profile);
        }

        $purifier = $this->getPurifier($profile);
        return $this->hardenAnchorLinks($purifier->purify($dirty), $profile);
    }

    /**
     * Mehrere Strings auf einmal sanitieren.
     *
     * @param array<string> $dirtyArray
     * @param string        $profile
     * @return array<string>
     */
    public function purifyArray(array $dirtyArray, string $profile = 'default'): array
    {
        if (!$this->available) {
            return array_map(fn(string $s) => $this->hardenAnchorLinks($this->fallbackSanitize($s, $profile), $profile), $dirtyArray);
        }

        $purifier = $this->getPurifier($profile);
        return array_map(fn(string $s) => $this->hardenAnchorLinks($s, $profile), $purifier->purifyArray($dirtyArray));
    }

    /**
     * Prüft ob HTMLPurifier verfügbar ist.
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * HTMLPurifier-Instanz für ein Profil erzeugen / aus Cache laden.
     */
    private function getPurifier(string $profile): \HTMLPurifier
    {
        if (isset($this->purifiers[$profile])) {
            return $this->purifiers[$profile];
        }

        $profileConfig = self::PROFILES[$profile] ?? self::PROFILES['default'];

        $config = \HTMLPurifier_Config::createDefault();

        // Cache-Verzeichnis für Serializer
        if ($this->cacheDir !== '') {
            $config->set('Cache.SerializerPath', $this->cacheDir);
        }

        // Encoding
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.DefinitionID', '365cms-' . preg_replace('/[^a-z0-9_-]/i', '-', $profile));
        $config->set('HTML.DefinitionRev', self::HTML_DEFINITION_REVISION);

        // Erlaubte HTML-Elemente und -Attribute
        $config->set('HTML.Allowed', $this->buildAllowedString($profileConfig));

        // Externe Links öffnen in neuem Tab (noopener/noreferrer). „nofollow“ nur für
        // nutzergenerierte Inhalte – redaktionelle Links auf fremde Seiten bleiben „follow“.
        $config->set('HTML.Nofollow', in_array($profile, self::UGC_PROFILES, true));
        $config->set('HTML.TargetBlank', true);
        // Vom Autor gesetzte Link-Beziehungen (z. B. rel="sponsored" oder "nofollow") bleiben erhalten.
        $config->set('Attr.AllowedRel', ['nofollow', 'sponsored', 'ugc', 'noopener', 'noreferrer', 'external', 'author', 'bookmark', 'license', 'tag']);

        // Absolute Links auf die eigene Domain sind intern (kein neuer Tab, kein nofollow).
        $siteHost = $this->resolveSiteHost();
        if ($siteHost !== '') {
            $config->set('URI.Host', $siteHost);
        }

        // URI-Schema einschränken (Profil kann enger sein)
        $config->set('URI.AllowedSchemes', array_fill_keys($profileConfig['schemes'] ?? self::DEFAULT_SCHEMES, true));
        if (isset($profileConfig['schemes'])) {
            // Sonst liefert die prozessweite Schema-Registry von HTMLPurifier ein Schema, das ein anderes Profil (z. B. tel:
            // in 'strict') im selben Prozess schon geladen hat, ohne URI.AllowedSchemes erneut zu prüfen.
            $config->set('URI.OverrideAllowedSchemes', false);
        }
        if (isset($profileConfig['classes'])) {
            $config->set('Attr.AllowedClasses', $profileConfig['classes']);
        }
        if (($profileConfig['external_resources'] ?? true) === false) {
            // Bilder u. a. nur vom eigenen Host (URI.Host) oder relativ; Links bleiben unberührt.
            $config->set('URI.DisableExternalResources', true);
        }

        // AutoFormat
        $config->set('AutoFormat.RemoveEmpty', true);
        $config->set('AutoFormat.AutoParagraph', false);

        $this->configureHtmlDefinition($config);

        $this->purifiers[$profile] = new \HTMLPurifier($config);
        return $this->purifiers[$profile];
    }

    private function configureHtmlDefinition(\HTMLPurifier_Config $config): void
    {
        $definition = $config->maybeGetRawHTMLDefinition();
        if (!$definition instanceof \HTMLPurifier_HTMLDefinition) {
            return;
        }

        foreach (['section', 'article', 'nav', 'aside', 'header', 'footer', 'main', 'figure', 'figcaption'] as $element) {
            $this->ensureHtmlElement($definition, $element, 'Block', 'Flow', 'Common');
        }

        $this->ensureHtmlElement($definition, 'details', 'Block', 'Flow', 'Common', [
            'open' => 'Bool',
        ]);

        foreach (['summary', 'mark'] as $element) {
            $this->ensureHtmlElement($definition, $element, 'Inline', 'Inline', 'Common');
        }

        $this->ensureHtmlElement($definition, 'video', 'Block', 'Flow', 'Common', [
            'src'      => 'URI',
            'controls' => 'Bool',
            'width'    => 'Length',
            'height'   => 'Length',
        ]);
        $this->ensureHtmlElement($definition, 'audio', 'Block', 'Flow', 'Common', [
            'src'      => 'URI',
            'controls' => 'Bool',
        ]);
        $this->ensureHtmlElement($definition, 'source', 'Inline', 'Empty', 'Common', [
            'src'  => 'URI',
            'type' => 'Text',
        ]);

        $this->ensureHtmlAttribute($definition, 'img', 'loading', 'Enum#lazy,eager,auto');
        $this->ensureHtmlAttribute($definition, 'img', 'fetchpriority', 'Enum#high,low,auto');
        $this->ensureHtmlAttribute($definition, 'img', 'decoding', 'Enum#async,sync,auto');

        $this->ensureHtmlAttribute($definition, 'a', 'aria-label', 'Text');
        $this->ensureHtmlAttribute($definition, 'a', 'aria-current', 'Text');
        $this->ensureHtmlAttribute($definition, 'nav', 'aria-label', 'Text');
        $this->ensureHtmlAttribute($definition, 'div', 'role', 'Text');
        $this->ensureHtmlAttribute($definition, 'div', 'aria-hidden', 'Enum#true,false');
        $this->ensureHtmlAttribute($definition, 'div', 'data-height', 'Text');
        $this->ensureHtmlAttribute($definition, 'div', 'data-cms-editorjs-spacing', 'Text');
        $this->ensureHtmlAttribute($definition, 'div', 'data-cms-editorjs-align', 'Text');
        $this->ensureHtmlAttribute($definition, 'span', 'aria-hidden', 'Enum#true,false');
        // Community: Kennzeichen eines Zitat-Blocks (Beitrags-ID), nur im Profil 'community' erlaubt.
        $this->ensureHtmlAttribute($definition, 'blockquote', 'data-quote-post', 'Number');
    }

    /**
     * @param array<string,string> $attributes
     */
    private function ensureHtmlElement(\HTMLPurifier_HTMLDefinition $definition, string $element, string $type, string $contents, string $attrCollections, array $attributes = []): void
    {
        if (isset($definition->info[$element])) {
            foreach ($attributes as $attribute => $attributeDefinition) {
                $this->ensureHtmlAttribute($definition, $element, $attribute, $attributeDefinition);
            }
            return;
        }

        $definition->addElement($element, $type, $contents, $attrCollections, $attributes);
    }

    private function ensureHtmlAttribute(\HTMLPurifier_HTMLDefinition $definition, string $element, string $attribute, string $attributeDefinition): void
    {
        if (isset($definition->info[$element]->attr[$attribute])) {
            return;
        }

        $definition->addAttribute($element, $attribute, $attributeDefinition);
    }

    /**
     * Baut den HTML.Allowed-String aus dem Profil.
     */
    private function buildAllowedString(array $profileConfig): string
    {
        $elements = $profileConfig['elements'] ?? '';
        $attributes = $profileConfig['attributes'] ?? '';

        // HTMLPurifier erwartet: "p,a[href|title],strong,em,img[src|alt|width|height]"
        // Wir bauen das aus unserer Kurzform
        $elementList = array_map('trim', explode(',', $elements));
        $attrMap = [];

        if (!empty($attributes)) {
            foreach (explode(',', $attributes) as $pair) {
                $pair = trim($pair);
                if (str_contains($pair, '.')) {
                    [$tag, $attr] = explode('.', $pair, 2);
                    $attrMap[$tag][] = $attr;
                }
            }
        }

        $parts = [];
        foreach ($elementList as $el) {
            if (isset($attrMap[$el])) {
                $parts[] = $el . '[' . implode('|', $attrMap[$el]) . ']';
            } else {
                $parts[] = $el;
            }
        }

        return implode(',', $parts);
    }

    /**
     * Fallback-Sanitierung wenn HTMLPurifier nicht verfügbar ist.
     * Verwendet strip_tags() mit einer Whitelist — weniger sicher als HTMLPurifier.
     */
    private function fallbackSanitize(string $dirty, string $profile): string
    {
        $profileConfig = self::PROFILES[$profile] ?? self::PROFILES['default'];
        $elements = array_map('trim', explode(',', $profileConfig['elements'] ?? ''));

        // strip_tags Whitelist aufbauen
        $allowedTags = implode('', array_map(fn(string $el): string => "<{$el}>", $elements));

        $stripped = strip_tags($dirty, $allowedTags);

        return $this->sanitizeFallbackAttributes($stripped, $profileConfig);
    }

    /**
     * Bereinigt Attribute im Fallback-Pfad. strip_tags() entfernt zwar Tags,
     * lässt aber gefährliche Attribute wie onclick oder javascript:-URLs stehen.
     *
     * @param array{elements:string,attributes:string} $profileConfig
     */
    private function sanitizeFallbackAttributes(string $html, array $profileConfig): string
    {
        if ($html === '' || !str_contains($html, '<')) {
            return $html;
        }

        if (!class_exists('DOMDocument')) {
            return (string) preg_replace('/<([a-z][a-z0-9]*)(?:\s+[^>]*)>/i', '<$1>', $html);
        }

        $allowedAttributes = $this->buildAllowedAttributeMap($profileConfig);

        return $this->transformHtmlFragment($html, function (\DOMDocument $document) use ($allowedAttributes): void {
            foreach ($document->getElementsByTagName('*') as $element) {
                if (!$element instanceof \DOMElement || $element->getAttribute('data-cms-fragment-root') === '1') {
                    continue;
                }

                $tag = strtolower($element->tagName);
                $attributes = [];
                foreach ($element->attributes ?? [] as $attribute) {
                    $attributes[] = strtolower($attribute->name);
                }

                foreach ($attributes as $attributeName) {
                    $allowed = in_array($attributeName, $allowedAttributes[$tag] ?? [], true);
                    if (!$allowed || str_starts_with($attributeName, 'on')) {
                        $element->removeAttribute($attributeName);
                        continue;
                    }

                    if (in_array($attributeName, ['href', 'src'], true) && !$this->isSafeUri($element->getAttribute($attributeName))) {
                        $element->removeAttribute($attributeName);
                    }
                }
            }
        });
    }

    /**
     * Erzwingt sichere Link-Ziele und schützt target="_blank" gegen Tabnabbing.
     * Externe Links in nutzergenerierten Inhalten erhalten rel="nofollow ugc".
     */
    private function hardenAnchorLinks(string $html, string $profile = 'default'): string
    {
        if ($html === '' || stripos($html, '<a') === false || !class_exists('DOMDocument')) {
            return $html;
        }

        $isUgc = in_array($profile, self::UGC_PROFILES, true);

        return $this->transformHtmlFragment($html, function (\DOMDocument $document) use ($isUgc): void {
            foreach ($document->getElementsByTagName('a') as $link) {
                if (!$link instanceof \DOMElement) {
                    continue;
                }

                if ($link->hasAttribute('href') && !$this->isSafeUri($link->getAttribute('href'))) {
                    $link->removeAttribute('href');
                }

                if ($isUgc && $link->hasAttribute('href') && $this->isForeignUrl($link->getAttribute('href'))) {
                    $this->ensureRelTokens($link, ['nofollow', 'ugc']);
                }

                if ($link->hasAttribute('target')) {
                    $target = strtolower(trim($link->getAttribute('target')));
                    if (!in_array($target, ['_blank', '_self', '_parent', '_top'], true)) {
                        $link->removeAttribute('target');
                    } elseif ($target === '_blank') {
                        $this->ensureRelTokens($link, ['noopener', 'noreferrer']);
                    }
                }
            }
        });
    }

    /**
     * @param array{elements:string,attributes:string} $profileConfig
     * @return array<string,list<string>>
     */
    private function buildAllowedAttributeMap(array $profileConfig): array
    {
        $map = [];
        foreach (explode(',', $profileConfig['attributes'] ?? '') as $pair) {
            $pair = trim($pair);
            if (!str_contains($pair, '.')) {
                continue;
            }

            [$tag, $attribute] = explode('.', $pair, 2);
            $tag = strtolower(trim($tag));
            $attribute = strtolower(trim($attribute));
            if ($tag === '' || $attribute === '') {
                continue;
            }

            $map[$tag][] = $attribute;
        }

        return array_map(static fn(array $attributes): array => array_values(array_unique($attributes)), $map);
    }

    /** Host der eigenen Website aus SITE_URL (klein geschrieben); leer, wenn unbekannt. */
    private function resolveSiteHost(): string
    {
        if ($this->siteHost === null) {
            $this->siteHost = defined('SITE_URL') ? strtolower((string) parse_url((string) SITE_URL, PHP_URL_HOST)) : '';
        }

        return $this->siteHost;
    }

    /** Absolute http(s)-URL auf eine fremde Domain? */
    private function isForeignUrl(string $uri): bool
    {
        $uri = trim(html_entity_decode($uri, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (str_starts_with($uri, '//')) {
            $uri = 'https:' . $uri; // protokollrelativ: wie https behandeln (fremder Host → nofollow ugc)
        }
        if (!in_array(strtolower((string) parse_url($uri, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) parse_url($uri, PHP_URL_HOST));

        return $host !== '' && $host !== $this->resolveSiteHost();
    }

    private function isSafeUri(string $uri): bool
    {
        $uri = trim(html_entity_decode($uri, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($uri === '') {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $uri) === 1) {
            return false;
        }

        if (str_starts_with($uri, '#') || str_starts_with($uri, '/') || str_starts_with($uri, './') || str_starts_with($uri, '../')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($uri, PHP_URL_SCHEME));
        if ($scheme === '') {
            return !str_starts_with($uri, '//');
        }

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
    }

    /**
     * @param list<string> $requiredTokens
     */
    private function ensureRelTokens(\DOMElement $link, array $requiredTokens): void
    {
        $tokens = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel')))) ?: [];
        $tokens = array_values(array_filter($tokens, static fn(string $token): bool => $token !== ''));

        foreach ($requiredTokens as $token) {
            if (!in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }

        $link->setAttribute('rel', implode(' ', array_unique($tokens)));
    }

    private function transformHtmlFragment(string $html, callable $callback): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="UTF-8"><div data-cms-fragment-root="1">' . $html . '</div>';
        $options = defined('LIBXML_HTML_NOIMPLIED') && defined('LIBXML_HTML_NODEFDTD')
            ? LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
            : 0;

        if (!$document->loadHTML($wrapped, $options)) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            return '';
        }

        $callback($document);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = null;
        foreach ($document->getElementsByTagName('div') as $candidate) {
            if ($candidate instanceof \DOMElement && $candidate->getAttribute('data-cms-fragment-root') === '1') {
                $root = $candidate;
                break;
            }
        }

        if (!$root instanceof \DOMElement) {
            return '';
        }

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child) ?: '';
        }

        return $result;
    }

    private function resolveCacheDirectory(): string
    {
        $candidates = [];

        if (defined('ABSPATH')) {
            $candidates[] = rtrim(ABSPATH, '\\/') . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'htmlpurifier';
        }

        $candidates[] = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . '365cms-htmlpurifier';

        foreach ($candidates as $candidate) {
            if ($this->ensureDirectory($candidate)) {
                return $candidate;
            }
        }

        \CMS\Logger::instance()->withChannel('purifier')->warning('No writable cache directory is available for HTMLPurifier.', [
            'candidates' => $candidates,
        ]);
        return '';
    }

    private function ensureDirectory(string $dir): bool
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        return is_writable($dir);
    }
}
