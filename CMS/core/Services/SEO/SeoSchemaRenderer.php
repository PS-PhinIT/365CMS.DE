<?php
declare(strict_types=1);

namespace CMS\Services\SEO;

use CMS\VendorRegistry;
use Melbahja\Seo\Schema;
use Melbahja\Seo\Schema\Thing;

if (!defined('ABSPATH')) {
    exit;
}

VendorRegistry::instance()->loadPackage('melbahja-seo');

final class SeoSchemaRenderer
{
    /** Schema-Typen, die dieser Renderer tatsächlich ausgibt. */
    public const SUPPORTED_TYPES = ['Article', 'BlogPosting', 'NewsArticle', 'WebPage', 'BreadcrumbList', 'Organization'];

    /**
     * Auswahl im Editor je Inhaltstyp; der erste Eintrag ist der Standard. FAQPage oder HowTo
     * werden bewusst nicht angeboten: ohne Frage-/Schritt-Struktur wären sie ungültig.
     */
    private const TYPE_OPTIONS = [
        'post' => ['Article', 'BlogPosting', 'NewsArticle'],
        'page' => ['WebPage', 'Article', 'Organization'],
    ];

    public function __construct(private readonly SeoSettingsStore $settings)
    {
    }

    /** @return list<string> */
    public static function typeOptionsFor(string $contentType): array
    {
        return self::TYPE_OPTIONS[$contentType === 'post' ? 'post' : 'page'];
    }

    public static function defaultTypeFor(string $contentType): string
    {
        return self::typeOptionsFor($contentType)[0];
    }

    /** Für den Inhaltstyp wählbarer Schema-Typ, sonst der Standard (beim Speichern). */
    public static function selectableTypeFor(string $schemaType, string $contentType): string
    {
        $schemaType = trim($schemaType);

        return in_array($schemaType, self::typeOptionsFor($contentType), true) ? $schemaType : self::defaultTypeFor($contentType);
    }

    /**
     * Tatsächlich ausgegebener Schema-Typ. Nicht unterstützte Altwerte (z. B. FAQPage oder HowTo
     * aus älteren Versionen und Importen) fallen auf den Standard des Inhaltstyps zurück.
     */
    public static function effectiveTypeFor(string $schemaType, string $contentType): string
    {
        $schemaType = trim($schemaType);

        return in_array($schemaType, self::SUPPORTED_TYPES, true) ? $schemaType : self::defaultTypeFor($contentType);
    }

    public function generateOrganizationSchema(): string
    {
        return $this->renderSchemaGraph([$this->buildOrganizationThing()]);
    }

    public function generateWebSiteSchema(): string
    {
        $schema = new Thing(
            type: 'WebSite',
            props: [
                'name' => SITE_NAME,
                'url' => SITE_URL,
                'potentialAction' => new Thing(
                    type: 'SearchAction',
                    props: [
                        'target' => SITE_URL . '/search?q={search_term_string}',
                        'query-input' => 'required name=search_term_string',
                    ]
                ),
            ]
        );

        return $this->renderSchemaGraph([$schema]);
    }

    public function generateWebPageSchema(string $title, string $description, string $url): string
    {
        return $this->renderSchemaGraph([
            $this->buildWebPageThing([
                'title' => $title,
                'description' => $description,
                'canonical_url' => $url,
                'url' => $url,
            ]),
        ]);
    }

    public function renderSchemaForPayload(array $payload): string
    {
        $schemaType = self::effectiveTypeFor((string) ($payload['schema_type'] ?? ''), (string) ($payload['content_type'] ?? ''));
        $things = [];

        if ($schemaType === 'BreadcrumbList') {
            $breadcrumb = $this->buildBreadcrumbThing(
                (string) ($payload['canonical_url'] ?? $payload['url'] ?? SITE_URL),
                trim((string) ($payload['content_title'] ?? '')) ?: (string) ($payload['title'] ?? SITE_NAME)
            );
            if ($breadcrumb !== null) {
                $things[] = $breadcrumb;
            }
        } else {
            $primary = $this->buildPrimarySchemaThing($schemaType, $payload);
            if ($primary !== null) {
                $things[] = $primary;
            }

            if ($this->shouldIncludeBreadcrumbSchema()) {
                $breadcrumb = $this->buildBreadcrumbThing(
                    (string) ($payload['canonical_url'] ?? $payload['url'] ?? SITE_URL),
                    trim((string) ($payload['content_title'] ?? '')) ?: (string) ($payload['title'] ?? SITE_NAME)
                );
                if ($breadcrumb !== null) {
                    $things[] = $breadcrumb;
                }
            }
        }

        if ($schemaType !== 'Organization' && $this->isOrganizationSchemaEnabled()) {
            $things[] = $this->buildOrganizationThing();
        }

        return $this->renderSchemaGraph($things);
    }

    private function buildPrimarySchemaThing(string $schemaType, array $payload): ?Thing
    {
        return match ($schemaType) {
            'Article', 'BlogPosting', 'NewsArticle' => $this->buildArticleThing($payload, $schemaType),
            'Organization' => $this->buildOrganizationThing(),
            default => $this->buildWebPageThing($payload, $schemaType),
        };
    }

    private function buildWebPageThing(array $payload, string $type = 'WebPage'): Thing
    {
        $props = [
            'url' => $payload['canonical_url'] ?? ($payload['url'] ?? SITE_URL),
            'name' => trim((string) ($payload['content_title'] ?? '')) ?: ($payload['title'] ?? SITE_NAME),
            'description' => $payload['description'] ?? '',
            'inLanguage' => $this->resolveLanguageTag($payload),
            'isPartOf' => new Thing(
                type: 'WebSite',
                props: [
                    'name' => SITE_NAME,
                    'url' => SITE_URL,
                ]
            ),
        ];

        if (!empty($payload['og_image'])) {
            $props['primaryImageOfPage'] = new Thing(
                type: 'ImageObject',
                props: [
                    'url' => (string) $payload['og_image'],
                ]
            );
        }

        return new Thing(type: $type, props: $this->filterEmptyProps($props));
    }

    private function buildArticleThing(array $payload, string $type = 'Article'): Thing
    {
        $url = (string) ($payload['canonical_url'] ?? $payload['url'] ?? SITE_URL);
        // Überschrift ist der Inhaltstitel, nicht der Dokumenttitel mit „| Website-Name“.
        $headline = trim((string) ($payload['content_title'] ?? '')) ?: (string) ($payload['title'] ?? SITE_NAME);
        $props = [
            'headline' => $headline,
            'name' => $headline,
            'description' => $payload['description'] ?? '',
            'url' => $url,
            'dateModified' => $this->normalizeSchemaDate((string) ($payload['updated_at'] ?? date(DATE_W3C))),
            'datePublished' => $this->normalizeSchemaDate((string) ($payload['published_at'] ?? '')),
            'inLanguage' => $this->resolveLanguageTag($payload),
            'mainEntityOfPage' => new Thing(
                type: 'WebPage',
                props: [
                    'url' => $url,
                    'name' => $headline,
                ]
            ),
            'isPartOf' => new Thing(
                type: 'WebSite',
                props: [
                    'name' => SITE_NAME,
                    'url' => SITE_URL,
                ]
            ),
            'publisher' => $this->buildOrganizationThing(),
        ];

        $authorName = trim((string) ($payload['author_name'] ?? ''));
        if ($authorName !== '') {
            $props['author'] = new Thing(type: 'Person', props: ['name' => $authorName]);
        }

        if (!empty($payload['og_image'])) {
            $props['image'] = [(string) $payload['og_image']];
        }

        return new Thing(type: $type, props: $this->filterEmptyProps($props));
    }

    private function buildOrganizationThing(): Thing
    {
        $name = $this->settings->getSetting('schema_org_name', defined('SITE_NAME') ? SITE_NAME : '365CMS');
        $logo = trim((string) $this->settings->getSetting('schema_org_logo', ''));
        if ($logo === '' && function_exists('get_option')) {
            try {
                $logo = trim((string) \get_option('site_logo', ''));
            } catch (\Throwable) {
                $logo = '';
            }
        }
        if ($logo !== '' && preg_match('#^https?://#i', $logo) !== 1) {
            $logo = rtrim((string) SITE_URL, '/') . '/' . ltrim($logo, '/');
        }
        if ($logo === '') {
            $logo = SITE_URL . '/assets/images/LOGO_365CMS-150px.png';
        }
        $sameAs = [];
        $twitterHandle = $this->settings->getTwitterHandle();
        if ($twitterHandle !== '') {
            $sameAs[] = 'https://x.com/' . $twitterHandle;
        }

        $facebook = trim($this->settings->getSetting('social_facebook_page', ''));
        if ($facebook !== '' && preg_match('#^https?://#i', $facebook) === 1) {
            $sameAs[] = $facebook;
        }

        $props = [
            'name' => $name !== '' ? $name : SITE_NAME,
            'url' => SITE_URL,
            'logo' => $logo,
            'description' => $this->settings->getSetting('meta_description', '365CMS SEO Integration'),
            'email' => defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'info@' . (parse_url(SITE_URL, PHP_URL_HOST) ?: 'example.com'),
            'sameAs' => $sameAs !== [] ? $sameAs : null,
        ];

        return new Thing(type: 'Organization', props: $this->filterEmptyProps($props));
    }

    private function buildBreadcrumbThing(string $url, string $title = ''): ?Thing
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $segment): bool => $segment !== ''));

        $items = [
            new Thing(
                type: 'ListItem',
                props: [
                    'position' => 1,
                    'name' => SITE_NAME,
                    'item' => SITE_URL . '/',
                ]
            ),
        ];

        $position = 2;
        $currentPath = '';
        foreach ($segments as $index => $segment) {
            $currentPath .= '/' . $segment;
            $name = $index === array_key_last($segments) && $title !== ''
                ? $title
                : ucwords(str_replace(['-', '_'], ' ', $segment));

            $items[] = new Thing(
                type: 'ListItem',
                props: [
                    'position' => $position++,
                    'name' => $name,
                    'item' => SITE_URL . $currentPath,
                ]
            );
        }

        return new Thing(type: 'BreadcrumbList', props: ['itemListElement' => $items]);
    }

    /**
     * @param array<int, Thing> $things
     */
    private function renderSchemaGraph(array $things): string
    {
        if ($things === []) {
            return '';
        }

        $json = json_encode(
            (new Schema(...$things))->jsonSerialize(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE
        );

        // JSON_HEX_TAG maskiert `<`/`>`: Inhalte wie `</script>` oder `<!--` können den Datenblock nicht verlassen.
        return is_string($json) ? '<script type="application/ld+json">' . $json . '</script>' : '';
    }

    /** @param array<string,mixed> $payload */
    private function resolveLanguageTag(array $payload): string
    {
        $locale = strtolower(trim((string) ($payload['locale'] ?? '')));
        if ($locale === '') {
            $path = (string) (strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/');
            $sitePath = rtrim((string) parse_url((string) SITE_URL, PHP_URL_PATH), '/');
            if ($sitePath !== '' && str_starts_with($path, $sitePath)) {
                $path = substr($path, strlen($sitePath)) ?: '/';
            }
            $locale = preg_match('#^/en(?:/|$)#', $path) === 1 ? 'en' : 'de';
        }

        return str_starts_with($locale, 'en') ? 'en' : 'de-DE';
    }

    private function shouldIncludeBreadcrumbSchema(): bool
    {
        return $this->settings->getSetting('schema_breadcrumb_enabled', '1') === '1';
    }

    private function isOrganizationSchemaEnabled(): bool
    {
        return $this->settings->getSetting('schema_organization_enabled', '1') === '1';
    }

    private function normalizeSchemaDate(string $value): string
    {
        if (trim($value) === '') {
            return '';
        }

        $timestamp = strtotime($value);
        return $timestamp !== false ? date(DATE_W3C, $timestamp) : date(DATE_W3C);
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private function filterEmptyProps(array $props): array
    {
        return array_filter($props, static function (mixed $value): bool {
            if ($value === null) {
                return false;
            }

            if (is_string($value)) {
                return trim($value) !== '';
            }

            if (is_array($value)) {
                return $value !== [];
            }

            return true;
        });
    }
}
