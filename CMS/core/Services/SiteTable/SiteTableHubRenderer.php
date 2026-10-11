<?php
declare(strict_types=1);

namespace CMS\Services\SiteTable;

use CMS\Database;
use CMS\Hooks;
use CMS\Services\ContentLocalizationService;
use CMS\Services\PurifierService;

if (!defined('ABSPATH')) {
    exit;
}

final class SiteTableHubRenderer
{
    public static function isHubRequestUri(string $requestUri): bool
    {
        $path = self::normalizeRequestPath($requestUri);

        if (self::isExcludedRequestPath($path) || self::isPostRequestPath($path)) {
            return false;
        }

        if ($path === '/') {
            return self::isHubDomainRequest();
        }

        $slug = trim($path, '/');
        if ($slug === '' || str_contains($slug, '/')) {
            return false;
        }

        try {
            return \CMS\Services\SiteTableService::getInstance()->hubExistsBySlug($slug);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function isHubDomainRequest(): bool
    {
        $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? ''), '.'));
        if ($host === '') {
            return false;
        }

        try {
            return \CMS\Services\SiteTableService::getInstance()->getHubPageByDomain($host, 'de') !== null
                || \CMS\Services\SiteTableService::getInstance()->getHubPageByDomain($host, 'en') !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function __construct(private SiteTableTemplateRegistry $templateRegistry)
    {
    }

    private static function normalizeRequestPath(string $requestUri): string
    {
        $path = (string) (strtok($requestUri !== '' ? $requestUri : '/', '?') ?: '/');

        try {
            $context = ContentLocalizationService::getInstance()->resolveRequestContext($path);
            $baseUri = trim((string) ($context['base_uri'] ?? ''));

            return $baseUri !== '' ? $baseUri : $path;
        } catch (\Throwable $e) {
            return $path;
        }
    }

    private static function isExcludedRequestPath(string $path): bool
    {
        return in_array($path, ['/login', '/register', '/cms-login', '/cms-register', '/cms-password-forgot', '/search', '/404', '/error', '/blog'], true)
            || str_starts_with($path, '/member')
            || str_starts_with($path, '/dashboard')
            || \cms_is_archive_request_path($path, 'category')
            || \cms_is_archive_request_path($path, 'tag')
            || str_starts_with($path, '/author/');
    }

    private static function isPostRequestPath(string $path): bool
    {
        return preg_match('#^/blog/[^/]+$#', $path) === 1;
    }

    public function buildHubPage(array $table, string $slug, string $locale = 'de'): array
    {
        $settings = array_merge($this->templateRegistry->getDefaultSettings(), $table['settings'] ?? []);
        $localizedSettings = ContentLocalizationService::getInstance()->localizeHubSettings($settings, $locale, [
            'slug' => $slug,
            'table' => $table,
        ]);

        return [
            'id' => (int) ($table['id'] ?? 0),
            'title' => trim((string) ($localizedSettings['hub_hero_title'] ?? '')) !== ''
                ? (string) $localizedSettings['hub_hero_title']
                : (string) ($table['name'] ?? 'Hub Site'),
            'slug' => $slug,
            'content_type' => 'hub',
            'content_locale' => $locale,
            'content' => $this->renderHubMarkup($table, $locale),
            'hub_settings' => $settings,
            'meta_description' => $this->buildHubMetaDescription(
                trim((string) ($localizedSettings['hub_hero_text'] ?? '')) !== ''
                    ? (string) $localizedSettings['hub_hero_text']
                    : (string) ($localizedSettings['hub_description'] ?? $table['description'] ?? '')
            ),
            'updated_at' => (string) ($table['updated_at'] ?? ''),
        ];
    }

    public function renderHubMarkup(array $table, string $locale = 'de'): string
    {
        $rawSettings = is_array($table['settings'] ?? null) ? $table['settings'] : [];
        $settings = array_merge($this->templateRegistry->getDefaultSettings(), $rawSettings);
        $settings = ContentLocalizationService::getInstance()->localizeHubSettings($settings, $locale, ['table' => $table]);
        $templateKey = (string) ($settings['hub_template'] ?? 'general-it');
        $templateProfile = $this->templateRegistry->getTemplateProfile($templateKey);
        $templateProfile = Hooks::applyFilters('cms_hub_template_profile', $templateProfile, $templateKey, $locale, $table);
        if (!is_array($templateProfile)) {
            $templateProfile = $this->templateRegistry->getTemplateProfile($templateKey);
        }

        $template = (string) ($templateProfile['base_template'] ?? $templateKey ?: 'general-it');
        $pageSlug = trim((string) ($settings['hub_slug'] ?? ''));
        $heroTitle = trim((string) ($settings['hub_hero_title'] ?? '')) ?: (string) ($table['name'] ?? 'Hub Site');
        $heroText = trim((string) ($settings['hub_hero_text'] ?? ''))
            ?: trim((string) ($settings['hub_description'] ?? $table['description'] ?? ''));
        if ($heroText === '') {
            $heroText = trim((string) ($templateProfile['summary'] ?? ''));
        }
        $heroBadge = trim((string) ($settings['hub_badge'] ?? ''));
        $ctaLabel = trim((string) ($settings['hub_cta_label'] ?? ''));
        $ctaUrl = trim((string) ($settings['hub_cta_url'] ?? ''));
        $cards = $this->normalizeHubCards($table['rows'] ?? []);
        if ($cards === []) {
            $templateStarterCards = is_array($templateProfile['starter_cards'] ?? null) ? $templateProfile['starter_cards'] : [];
            $cards = $this->normalizeHubCards($templateStarterCards);
        }
        $cards = ContentLocalizationService::getInstance()->localizeHubCards($cards, $locale, ['table' => $table]);
        $featureCardInterval = max(0, (int) ($settings['hub_feature_card_interval'] ?? 0));
        $featureCards = $this->normalizeFeatureCards($settings['hub_feature_cards_json'] ?? []);
        $featureCards = $this->localizeFeatureCards($featureCards, $locale);
        $quickLinks = $this->templateRegistry->resolveHubLinks($settings, $templateProfile, $template, $locale);
        $tocEntries = $this->buildCardTocEntries($cards);
        $sections = $this->templateRegistry->resolveHubSections($settings, $templateProfile, $template, $locale);
        $navigationProfile = is_array($templateProfile['navigation'] ?? null) ? $templateProfile['navigation'] : [];
        $tocEnabled = !empty($navigationProfile['toc_enabled']);
        $metaSettings = array_merge([
            'hub_meta_audience' => (string) ($templateProfile['meta']['audience'] ?? ''),
            'hub_meta_owner' => (string) ($templateProfile['meta']['owner'] ?? ''),
            'hub_meta_update_cycle' => (string) ($templateProfile['meta']['update_cycle'] ?? ''),
            'hub_meta_focus' => (string) ($templateProfile['meta']['focus'] ?? ''),
            'hub_meta_kpi' => (string) ($templateProfile['meta']['kpi'] ?? ''),
        ], $settings);
        foreach (['hub_meta_audience', 'hub_meta_owner', 'hub_meta_update_cycle', 'hub_meta_focus', 'hub_meta_kpi'] as $metaKey) {
            if (trim((string) ($metaSettings[$metaKey] ?? '')) === '') {
                $metaSettings[$metaKey] = (string) ($templateProfile['meta'][str_replace('hub_meta_', '', $metaKey)] ?? $templateProfile['meta'][match ($metaKey) {
                    'hub_meta_update_cycle' => 'update_cycle',
                    default => str_replace('hub_meta_', '', $metaKey),
                }] ?? '');
            }
        }
        $metaItems = $this->templateRegistry->buildHubMetaItems($metaSettings, $template, $templateProfile, $locale);
        $cardDesign = $this->templateRegistry->resolveHubCardDesign($rawSettings, $templateProfile, $template);
        $cardSchema = is_array($templateProfile['card_schema'] ?? null) ? $templateProfile['card_schema'] : [];
        $colorSettings = is_array($templateProfile['colors'] ?? null) ? $templateProfile['colors'] : [];
        $styleVariables = $this->templateRegistry->buildHubStyleVariables($colorSettings, $cardDesign);
        $contentLanguage = array_merge([
            'quicklinks_label' => $locale === 'en' ? 'Hub navigation' : 'Hub-Navigation',
            'toc_label' => $locale === 'en' ? 'Table of contents' : 'Inhaltsverzeichnis',
            'toc_eyebrow' => $locale === 'en' ? 'Jump to the right section' : 'Schnell zum richtigen Abschnitt',
            'toc_empty' => $locale === 'en'
                ? 'No cards with titles available for the table of contents.'
                : 'Keine Karten mit Titel für das Inhaltsverzeichnis vorhanden.',
            'card_cta_label' => $locale === 'en' ? '… read more' : '… weiterlesen',
        ], $this->templateRegistry->getTemplateContentLanguage($template, $locale));

        $templateClass = htmlspecialchars($template, ENT_QUOTES, 'UTF-8');
        $html = '<section class="hubsite hubsite--' . $templateClass . ' cms-hub-site cms-hub-site--' . $templateClass . '"';
        if ($pageSlug !== '') {
            $html .= ' data-hub-slug="' . htmlspecialchars($pageSlug, ENT_QUOTES, 'UTF-8') . '"';
        }
        if ($styleVariables !== '') {
            $html .= ' style="' . htmlspecialchars($styleVariables, ENT_QUOTES, 'UTF-8') . '"';
        }
        $html .= '>';
        $html .= $this->renderHero($heroBadge, $heroTitle, $heroText, $metaItems, $ctaLabel, $ctaUrl, (int) ($table['id'] ?? 0));
        $html .= $this->renderTableOfContents($tocEntries, $tocEnabled, $contentLanguage);
        if (!$tocEnabled) {
            $html .= $this->renderQuickLinks($quickLinks, $contentLanguage);
        }
        $html .= $this->renderSections($sections, $template, $contentLanguage);
        $html .= $this->renderCards($cards, $featureCards, $featureCardInterval, $tocEntries, $cardDesign, $cardSchema, (int) ($table['id'] ?? 0), $template, $contentLanguage);
        $html .= '</section>';

        return $html;
    }

    private function renderHero(string $heroBadge, string $heroTitle, string $heroText, array $metaItems, string $ctaLabel, string $ctaUrl, int $currentTableId): string
    {
        $html = '<div class="hubsite-hero cms-hub-site__hero"><div class="hubsite-hero__inner cms-hub-site__hero-inner">';
        if ($heroBadge !== '') {
            $html .= '<span class="hubsite-hero__breadcrumb cms-hub-site__badge">' . htmlspecialchars($heroBadge, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        $html .= '<h1 class="hubsite-hero__title cms-hub-site__title">' . htmlspecialchars($heroTitle, ENT_QUOTES, 'UTF-8') . '</h1>';
        if ($heroText !== '') {
            $heroTextHtml = $this->renderCardSummary($heroText, $currentTableId);
            if ($heroTextHtml !== '') {
                $html .= '<div class="hubsite-hero__intro cms-hub-site__lead">' . $heroTextHtml . '</div>';
            }
        }
        if ($metaItems !== []) {
            $html .= '<div class="cms-hub-site__meta">';
            foreach ($metaItems as $metaItem) {
                $html .= '<span class="cms-hub-site__meta-chip cms-hub-site__meta-chip--' . htmlspecialchars((string) ($metaItem['key'] ?? 'meta'), ENT_QUOTES, 'UTF-8') . '">';
                if (!empty($metaItem['icon'])) {
                    $html .= '<span class="cms-hub-site__meta-chip-icon" aria-hidden="true">' . htmlspecialchars((string) $metaItem['icon'], ENT_QUOTES, 'UTF-8') . '</span>';
                }
                $html .= '<span class="cms-hub-site__meta-chip-label">' . htmlspecialchars((string) $metaItem['label'], ENT_QUOTES, 'UTF-8') . ':</span>';
                $html .= '<span class="cms-hub-site__meta-chip-value">' . htmlspecialchars((string) $metaItem['value'], ENT_QUOTES, 'UTF-8') . '</span>';
                $html .= '</span>';
            }
            $html .= '</div>';
        }
        if ($ctaLabel !== '' && $ctaUrl !== '') {
            $html .= '<a class="hubsite-card__cta hubsite-hero__cta cms-hub-site__cta" href="' . htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($ctaLabel, ENT_QUOTES, 'UTF-8') . '</a>';
        }

        return $html . '</div></div>';
    }

    private function buildHubMetaDescription(string $value): string
    {
        $editorPayload = json_decode($value, true);
        if (is_array($editorPayload) && isset($editorPayload['blocks']) && is_array($editorPayload['blocks'])) {
            $value = \CMS\Services\EditorJsRenderer::getInstance()->render($editorPayload);
        }

        $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return mb_substr($plain, 0, 320);
    }

    private function renderQuickLinks(array $quickLinks, array $contentLanguage): string
    {
        if ($quickLinks === []) {
            return '';
        }

        $navLabel = (string) ($contentLanguage['quicklinks_label'] ?? 'Hub-Navigation');
        $html = '<nav class="cms-hub-site__quicklinks" aria-label="' . htmlspecialchars($navLabel, ENT_QUOTES, 'UTF-8') . '">';
        foreach ($quickLinks as $link) {
            $html .= '<a class="cms-hub-site__quicklink" href="' . htmlspecialchars((string) $link['url'], ENT_QUOTES, 'UTF-8') . '">';
            $html .= '<span class="cms-hub-site__quicklink-label">' . htmlspecialchars((string) $link['label'], ENT_QUOTES, 'UTF-8') . '</span>';
            $html .= '</a>';
        }

        return $html . '</nav>';
    }

    private function renderTableOfContents(array $tocEntries, bool $enabled, array $contentLanguage): string
    {
        if (!$enabled) {
            return '';
        }

        $label = (string) ($contentLanguage['toc_label'] ?? 'Inhaltsverzeichnis');
        $eyebrow = (string) ($contentLanguage['toc_eyebrow'] ?? 'Schnell zum richtigen Abschnitt');
        $emptyMessage = (string) ($contentLanguage['toc_empty'] ?? 'Keine Karten mit Titel für das Inhaltsverzeichnis vorhanden.');
        $html = '<details class="cms-hub-site__toc">';
        $html .= '<summary class="cms-hub-site__toc-summary">';
        $html .= '<span class="cms-hub-site__toc-summary-copy">';
        $html .= '<span class="cms-hub-site__toc-eyebrow">' . htmlspecialchars($eyebrow, ENT_QUOTES, 'UTF-8') . '</span>';
        $html .= '<strong class="cms-hub-site__toc-title">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</strong>';
        $html .= '</span>';
        $html .= '<span class="cms-hub-site__toc-summary-icon" aria-hidden="true">⌄</span>';
        $html .= '</summary>';
        $html .= '<nav class="cms-hub-site__toc-panel" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">';
        if ($tocEntries === []) {
            $html .= '<div class="cms-hub-site__toc-empty">' . htmlspecialchars($emptyMessage, ENT_QUOTES, 'UTF-8') . '</div>';
            $html .= '</nav></details>';

            return $html;
        }

        $html .= '<ol class="cms-hub-site__toc-list">';

        foreach ($tocEntries as $index => $entry) {
            $html .= '<li class="cms-hub-site__toc-row">';
            $html .= '<a class="cms-hub-site__toc-item" href="#' . htmlspecialchars((string) ($entry['anchor'] ?? ''), ENT_QUOTES, 'UTF-8') . '">';
            $html .= '<span class="cms-hub-site__toc-label">' . htmlspecialchars((string) ($entry['label'] ?? ''), ENT_QUOTES, 'UTF-8') . '</span>';
            $html .= '</a>';
            $html .= '</li>';
        }

        $html .= '</ol></nav></details>';

        return $html;
    }

    private function renderSections(array $sections, string $template, array $contentLanguage): string
    {
        if ($sections === []) {
            return '';
        }

        $html = '<div class="cms-hub-site__sections cms-hub-site__sections--' . htmlspecialchars($template, ENT_QUOTES, 'UTF-8') . '">';
        foreach ($sections as $index => $section) {
            $sectionModifier = $contentLanguage['section_modifiers'][$index] ?? 'default';
            $sectionEyebrow = $contentLanguage['section_eyebrows'][$index] ?? 'Section';
            $sectionIcon = $contentLanguage['section_icons'][$index] ?? '◆';
            $sectionNote = $contentLanguage['section_notes'][$index] ?? '';

            $html .= '<article class="cms-hub-site__section-card cms-hub-site__section-card--' . htmlspecialchars($sectionModifier, ENT_QUOTES, 'UTF-8') . '">';
            $html .= '<div class="cms-hub-site__section-head">';
            $html .= '<span class="cms-hub-site__section-eyebrow">' . htmlspecialchars((string) $sectionEyebrow, ENT_QUOTES, 'UTF-8') . '</span>';
            $html .= '<span class="cms-hub-site__section-icon" aria-hidden="true">' . htmlspecialchars((string) $sectionIcon, ENT_QUOTES, 'UTF-8') . '</span>';
            $html .= '</div>';
            $html .= '<h3 class="cms-hub-site__section-title">' . htmlspecialchars((string) $section['title'], ENT_QUOTES, 'UTF-8') . '</h3>';
            if ((string) ($section['text'] ?? '') !== '') {
                $html .= '<p class="cms-hub-site__section-text">' . nl2br(htmlspecialchars((string) $section['text'], ENT_QUOTES, 'UTF-8')) . '</p>';
            }
            if ((string) ($section['actionLabel'] ?? '') !== '' && (string) ($section['actionUrl'] ?? '') !== '') {
                $html .= '<a class="cms-hub-site__section-link" href="' . htmlspecialchars((string) $section['actionUrl'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) $section['actionLabel'], ENT_QUOTES, 'UTF-8') . '</a>';
            }
            if ($sectionNote !== '') {
                $html .= '<div class="cms-hub-site__section-note">' . htmlspecialchars((string) $sectionNote, ENT_QUOTES, 'UTF-8') . '</div>';
            }
            $html .= '</article>';
        }

        return $html . '</div>';
    }

    private function renderCards(array $cards, array $featureCards, int $featureCardInterval, array $tocEntries, array $cardDesign, array $cardSchema, int $currentTableId, string $template, array $contentLanguage = []): string
    {
        if ($cards === []) {
            return '';
        }

        $cardLayout = $this->normalizeOption((string) ($cardDesign['layout'] ?? 'standard'), ['standard', 'feature', 'compact'], 'standard');
        $cardImagePosition = $this->normalizeOption((string) ($cardDesign['image_position'] ?? 'top'), ['top', 'left', 'right'], 'top');
        $cardImageFit = $this->normalizeOption((string) ($cardDesign['image_fit'] ?? 'cover'), ['cover', 'contain'], 'cover');
        $cardImageRatio = $this->normalizeOption((string) ($cardDesign['image_ratio'] ?? 'wide'), ['wide', 'square', 'portrait'], 'wide');
        $cardMetaLayout = $this->normalizeOption((string) ($cardDesign['meta_layout'] ?? 'split'), ['split', 'stacked'], 'split');
        $cardColumns = max(1, min(3, (int) ($cardSchema['columns'] ?? 2)));
        $isTableTemplate = str_ends_with($template, '-table');
        $defaultCardCtaLabel = trim((string) ($contentLanguage['card_cta_label'] ?? '… weiter Lesen'));
        $supportsFeatureCards = $this->templateRegistry->templateSupportsFeatureCards($template);
        $featureCardInsertMap = $this->buildFeatureCardInsertMap($featureCards, $featureCardInterval, $supportsFeatureCards);
        $renderedStandardCards = 0;
        $priorityImageRendered = false;

        $html = '<div class="hubsite-grid hubsite-grid--' . htmlspecialchars($cardLayout, ENT_QUOTES, 'UTF-8') . ' hubsite-grid--cols-' . $cardColumns . ' cms-hub-site__grid cms-hub-site__grid--' . htmlspecialchars($cardLayout, ENT_QUOTES, 'UTF-8') . ' cms-hub-site__grid--cols-' . $cardColumns . '">';
        foreach ($cards as $index => $card) {
            $isFeatureCard = $supportsFeatureCards && $this->isFeatureCard($card);

            if ($isFeatureCard) {
                $html .= $this->renderFeatureCard($card, $currentTableId, $defaultCardCtaLabel, $cardDesign, !$priorityImageRendered);
                if ($this->normalizeRenderableImageUrl((string) ($card['image_url'] ?? '')) !== '') {
                    $priorityImageRendered = true;
                }

                continue;
            }

            $url = htmlspecialchars((string) ($card['url'] ?? '#'), ENT_QUOTES, 'UTF-8');
            $title = htmlspecialchars((string) ($card['title'] ?? ''), ENT_QUOTES, 'UTF-8');
            $summary = $this->renderCardSummary((string) ($card['summary'] ?? ''), $currentTableId);
            $badge = htmlspecialchars((string) ($card['badge'] ?? ''), ENT_QUOTES, 'UTF-8');
            $meta = htmlspecialchars((string) ($card['meta'] ?? ''), ENT_QUOTES, 'UTF-8');
            $metaLeft = htmlspecialchars((string) ($card['meta_left'] ?? ''), ENT_QUOTES, 'UTF-8');
            $metaRight = htmlspecialchars((string) ($card['meta_right'] ?? ''), ENT_QUOTES, 'UTF-8');
            $buttonText = trim((string) ($card['button_text'] ?? ''));
            $buttonLink = trim((string) ($card['button_link'] ?? ''));
            $imageUrl = $this->normalizeRenderableImageUrl((string) ($card['image_url'] ?? ''));
            $imageAlt = htmlspecialchars((string) ($card['image_alt'] ?? $card['title'] ?? ''), ENT_QUOTES, 'UTF-8');
            $imageDimensions = $this->buildCardImageDimensionAttributes($cardImageRatio);
            $hasImage = $imageUrl !== '';
            $imageLoadingAttributes = $this->buildImageLoadingAttributes($hasImage && !$priorityImageRendered);
            $cardTarget = $buttonLink !== '' ? $buttonLink : html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $cardTarget = trim($cardTarget) !== '' ? trim($cardTarget) : '#';
            $cardButtonLabel = $buttonText !== '' ? $buttonText : ($cardTarget !== '#' ? $defaultCardCtaLabel : '');
            $cardArticleClass = 'hubsite-card hubsite-card--layout-' . htmlspecialchars($cardLayout, ENT_QUOTES, 'UTF-8') . ' cms-hub-site__card cms-hub-site__card--layout-' . htmlspecialchars($cardLayout, ENT_QUOTES, 'UTF-8');
            $cardLinkClass = 'cms-hub-site__card-link';

            if ($hasImage) {
                $cardArticleClass .= ' cms-hub-site__card--image-' . htmlspecialchars($cardImagePosition, ENT_QUOTES, 'UTF-8');
                $cardLinkClass .= ' cms-hub-site__card-link--image-' . htmlspecialchars($cardImagePosition, ENT_QUOTES, 'UTF-8');
            }
            $cardArticleClass .= ' cms-hub-site__card--meta-' . htmlspecialchars($cardMetaLayout, ENT_QUOTES, 'UTF-8');
            if ($isTableTemplate) {
                $cardArticleClass .= ' hubsite-card--table cms-hub-site__card--table';
            }

            $cardAnchor = trim((string) ($tocEntries[$index]['anchor'] ?? ''));
            $html .= '<article class="' . $cardArticleClass . '"';
            if ($cardAnchor !== '') {
                $html .= ' id="' . htmlspecialchars($cardAnchor, ENT_QUOTES, 'UTF-8') . '"';
            }
            $html .= '>';
            if ($isTableTemplate) {
                $html .= '<div class="hubsite-card__header cms-hub-site__card-table-head">';
                if ($badge !== '') {
                    $html .= '<span class="cms-hub-site__card-badge cms-hub-site__card-badge--table">' . $badge . '</span>';
                }
                $html .= '<h3 class="cms-hub-site__card-title cms-hub-site__card-title--table">';
                if ($url !== '#') {
                    $html .= '<a class="cms-hub-site__card-title-link cms-hub-site__card-title-link--table" href="' . $url . '">' . $title . '</a>';
                } else {
                    $html .= $title;
                }
                $html .= '</h3>';
                $html .= '</div>';
            }
            $html .= '<div class="' . $cardLinkClass . '">';
            if ($hasImage) {
                if ($url !== '#') {
                    $html .= '<a class="cms-hub-site__card-media-link" href="' . $url . '">';
                }
                $html .= '<div class="cms-hub-site__card-media cms-hub-site__card-media--' . htmlspecialchars($cardImageRatio, ENT_QUOTES, 'UTF-8') . ' cms-hub-site__card-media--fit-' . htmlspecialchars($cardImageFit, ENT_QUOTES, 'UTF-8') . '">';
                $html .= '<img src="' . htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . $imageAlt . '"' . $imageLoadingAttributes . $imageDimensions . '>';
                $html .= '</div>';
                if ($url !== '#') {
                    $html .= '</a>';
                }
                $priorityImageRendered = true;
            }
            $html .= '<div class="hubsite-card__body cms-hub-site__card-content">';
            if ($badge !== '' && !$isTableTemplate) {
                $html .= '<span class="cms-hub-site__card-badge">' . $badge . '</span>';
            }
            if (!$isTableTemplate) {
                $html .= '<h3 class="hubsite-card__header cms-hub-site__card-title">';
                if ($url !== '#') {
                    $html .= '<a class="cms-hub-site__card-title-link" href="' . $url . '">' . $title . '</a>';
                } else {
                    $html .= $title;
                }
                $html .= '</h3>';
            }
            if ($summary !== '') {
                $html .= '<div class="hubsite-card__content cms-hub-site__card-summary">' . $summary . '</div>';
            }
            $html .= '<div class="hubsite-card__footer cms-hub-site__card-footer cms-hub-site__card-footer--' . htmlspecialchars($cardMetaLayout, ENT_QUOTES, 'UTF-8') . '">';
            $html .= '<div class="cms-hub-site__card-meta-row">';
            if ($metaLeft !== '') {
                $html .= '<span class="cms-hub-site__card-meta cms-hub-site__card-meta--left">' . $metaLeft . '</span>';
            } elseif ($meta !== '') {
                $html .= '<span class="cms-hub-site__card-meta cms-hub-site__card-meta--left">' . $meta . '</span>';
            }
            if ($metaRight !== '') {
                $html .= '<span class="cms-hub-site__card-meta cms-hub-site__card-meta--right">' . $metaRight . '</span>';
            }
            $html .= '</div>';
            if ($cardButtonLabel !== '') {
                $buttonLabel = htmlspecialchars($cardButtonLabel, ENT_QUOTES, 'UTF-8');
                $buttonHref = htmlspecialchars($cardTarget, ENT_QUOTES, 'UTF-8');
                if ($buttonHref !== '#') {
                    $html .= '<a class="hubsite-card__cta cms-hub-site__card-button" href="' . $buttonHref . '">' . $buttonLabel . '</a>';
                } else {
                    $html .= '<span class="hubsite-card__cta cms-hub-site__card-button">' . $buttonLabel . '</span>';
                }
            }
            $html .= '</div>';
            $html .= '</div></div></article>';

            $renderedStandardCards++;
            if (isset($featureCardInsertMap[$renderedStandardCards])) {
                foreach ($featureCardInsertMap[$renderedStandardCards] as $featureCard) {
                    $html .= $this->renderFeatureCard($featureCard, $currentTableId, $defaultCardCtaLabel, $cardDesign, !$priorityImageRendered);
                    if ($this->normalizeRenderableImageUrl((string) ($featureCard['image_url'] ?? '')) !== '') {
                        $priorityImageRendered = true;
                    }
                }
            }
        }

        foreach ($featureCardInsertMap as $position => $queuedFeatureCards) {
            if ($position <= $renderedStandardCards) {
                continue;
            }

            foreach ($queuedFeatureCards as $featureCard) {
                $html .= $this->renderFeatureCard($featureCard, $currentTableId, $defaultCardCtaLabel, $cardDesign, !$priorityImageRendered);
                if ($this->normalizeRenderableImageUrl((string) ($featureCard['image_url'] ?? '')) !== '') {
                    $priorityImageRendered = true;
                }
            }
        }

        return $html . '</div>';
    }

    private function buildCardTocEntries(array $cards): array
    {
        $entries = [];
        $usedAnchors = [];

        foreach ($cards as $index => $card) {
            if (!is_array($card)) {
                continue;
            }

            if ($this->isFeatureCard($card)) {
                continue;
            }

            $title = trim((string) ($card['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $entries[$index] = [
                'anchor' => $this->buildCardAnchorId($title, $index, $usedAnchors),
                'label' => $title,
            ];
        }

        return $entries;
    }

    /**
     * @return array<int, list<array<string, mixed>>>
     */
    private function buildFeatureCardInsertMap(array $featureCards, int $featureCardInterval, bool $supportsFeatureCards): array
    {
        if (!$supportsFeatureCards || $featureCards === []) {
            return [];
        }

        $insertMap = [];
        $hasExplicitPositions = false;

        foreach ($featureCards as $featureCard) {
            $insertAfter = max(0, (int) ($featureCard['insert_after'] ?? 0));
            if ($insertAfter > 0) {
                $hasExplicitPositions = true;
                $insertMap[$insertAfter] ??= [];
                $insertMap[$insertAfter][] = $featureCard;
            }
        }

        if ($hasExplicitPositions || $featureCardInterval <= 0) {
            ksort($insertMap);

            return $insertMap;
        }

        foreach (array_values($featureCards) as $index => $featureCard) {
            $legacyPosition = ($index + 1) * $featureCardInterval;
            $insertMap[$legacyPosition] ??= [];
            $insertMap[$legacyPosition][] = $featureCard;
        }

        ksort($insertMap);

        return $insertMap;
    }


    private function renderFeatureCard(array $featureCard, int $currentTableId, string $defaultCardCtaLabel = '', array $cardDesign = [], bool $isPriorityImage = false): string
    {
        $title = htmlspecialchars((string) ($featureCard['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $badge = htmlspecialchars((string) ($featureCard['badge'] ?? ''), ENT_QUOTES, 'UTF-8');
        $text = $this->renderCardSummary((string) ($featureCard['text'] ?? $featureCard['summary'] ?? ''), $currentTableId);
        $imageUrl = $this->normalizeRenderableImageUrl((string) ($featureCard['image_url'] ?? ''));
        $imageAlt = htmlspecialchars((string) ($featureCard['image_alt'] ?? $featureCard['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $imageDimensions = $this->buildFeatureImageDimensionAttributes($cardDesign);
        $imageLoadingAttributes = $this->buildImageLoadingAttributes($isPriorityImage);
        $featureSpacingTop = max(0, min(240, (int) ($featureCard['feature_spacing_top'] ?? 0)));
        $buttonText = trim((string) ($featureCard['button_text'] ?? ''));
        $buttonLink = trim((string) ($featureCard['button_link'] ?? ''));
        if ($buttonLink === '') {
            $buttonLink = trim((string) ($featureCard['url'] ?? ''));
        }
        $buttonTarget = $buttonLink !== '' ? $buttonLink : '#';
        $buttonLabel = $buttonText !== ''
            ? $buttonText
            : ($buttonTarget !== '#' ? $defaultCardCtaLabel : '');

        if ($title === '' && $text === '' && $imageUrl === '') {
            return '';
        }

        $html = '<article class="hubsite-card hubsite-card--featured cms-hub-site__feature-card"';
        if ($featureSpacingTop > 0) {
            $html .= ' style="margin-top: ' . $featureSpacingTop . 'px"';
        }
        $html .= '>';
        if ($imageUrl !== '') {
            $html .= '<div class="hubsite-card__media cms-hub-site__feature-card-media">';
            $html .= '<img class="hubsite-card__image" src="' . htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . $imageAlt . '"' . $imageLoadingAttributes . $imageDimensions . '>';
            $html .= '</div>';
        }
        $html .= '<div class="hubsite-card__body cms-hub-site__feature-card-content">';
        if ($badge !== '') {
            $html .= '<span class="cms-hub-site__card-badge">' . $badge . '</span>';
        }
        if ($title !== '') {
            $html .= '<h3 class="hubsite-card__title cms-hub-site__feature-card-title">' . $title . '</h3>';
        }
        if ($text !== '') {
            $html .= '<div class="hubsite-card__content cms-hub-site__feature-card-text">' . $text . '</div>';
        }
        if ($buttonLabel !== '') {
            $html .= '<div class="cms-hub-site__feature-card-actions">';
            if ($buttonTarget !== '#') {
                $html .= '<a class="hubsite-card__cta cms-hub-site__card-button" href="' . htmlspecialchars($buttonTarget, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($buttonLabel, ENT_QUOTES, 'UTF-8') . '</a>';
            } else {
                $html .= '<span class="hubsite-card__cta cms-hub-site__card-button">' . htmlspecialchars($buttonLabel, ENT_QUOTES, 'UTF-8') . '</span>';
            }
            $html .= '</div>';
        }
        $html .= '</div></article>';

        return $html;
    }

    private function buildCardImageDimensionAttributes(string $imageRatio): string
    {
        [$width, $height] = match ($imageRatio) {
            'square' => [1000, 1000],
            'portrait' => [800, 1000],
            default => [1600, 900],
        };

        return ' width="' . $width . '" height="' . $height . '"';
    }

    private function buildFeatureImageDimensionAttributes(array $cardDesign): string
    {
        $featureWidthPercent = max(20, min(60, (int) ($cardDesign['feature_image_width'] ?? 34)));
        $featureImageHeight = max(160, min(520, (int) ($cardDesign['feature_image_height'] ?? 260)));
        $featureImageWidth = max(240, min(960, (int) round(1180 * ($featureWidthPercent / 100))));

        return ' width="' . $featureImageWidth . '" height="' . $featureImageHeight . '"';
    }

    private function buildImageLoadingAttributes(bool $isPriorityImage): string
    {
        if ($isPriorityImage) {
            return ' loading="eager" fetchpriority="high" decoding="async"';
        }

        return ' loading="lazy" decoding="async"';
    }

    private function normalizeRenderableImageUrl(string $value): string
    {
        $value = trim($value);

        return $value === '#' ? '' : $value;
    }

    private function isFeatureCard(array $card): bool
    {
        return !empty($card['is_feature']) || (string) ($card['display_variant'] ?? '') === 'feature';
    }

    private function buildCardAnchorId(string $title, int $index, array &$usedAnchors): string
    {
        $normalized = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = strtr($normalized, [
            'Ä' => 'Ae',
            'Ö' => 'Oe',
            'Ü' => 'Ue',
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'ß' => 'ss',
        ]);
        $normalized = mb_strtolower($normalized, 'UTF-8');
        $normalized = preg_replace('/[^a-z0-9]+/u', '-', $normalized) ?? '';
        $normalized = trim($normalized, '-');

        $baseAnchor = $normalized !== '' ? 'hub-card-' . $normalized : 'hub-card-' . ($index + 1);
        $anchor = $baseAnchor;
        $suffix = 2;

        while (isset($usedAnchors[$anchor])) {
            $anchor = $baseAnchor . '-' . $suffix;
            $suffix++;
        }

        $usedAnchors[$anchor] = true;

        return $anchor;
    }

    private function renderCardSummary(string $value, int $currentTableId): string
    {
        if ($value === '') {
            return '';
        }

        $editorPayload = json_decode($value, true);
        if (is_array($editorPayload) && isset($editorPayload['blocks']) && is_array($editorPayload['blocks'])) {
            return $this->renderEditorJsSummary($editorPayload, $currentTableId);
        }

        $placeholders = [];
        $prepared = (string) preg_replace_callback(
            '/\[(?:site-table|table|hub-site)\s+id\s*=\s*["\']?(\d+)["\']?\s*\/?\]/i',
            static function (array $matches) use (&$placeholders, $currentTableId): string {
                $targetTableId = (int) ($matches[1] ?? 0);
                if ($targetTableId <= 0 || $targetTableId === $currentTableId) {
                    return '';
                }

                $token = '%%CMS_HUB_CARD_CONTENT_' . count($placeholders) . '%%';
                $placeholders[$token] = \CMS\Services\SiteTableService::getInstance()->renderTableById($targetTableId);

                return $token;
            },
            $value
        );

        $containsHtml = $this->containsHtml($prepared);
        $html = $containsHtml
            ? $this->sanitizeRichHtml($prepared)
            : nl2br(htmlspecialchars($prepared, ENT_QUOTES, 'UTF-8'));

        foreach ($placeholders as $token => $replacement) {
            $html = str_replace(
                $containsHtml ? $token : htmlspecialchars($token, ENT_QUOTES, 'UTF-8'),
                $replacement,
                $html
            );
        }

        return trim($html);
    }

    private function renderEditorJsSummary(array $payload, int $currentTableId): string
    {
        $placeholders = [];
        $replaceShortcodes = function (&$node) use (&$replaceShortcodes, &$placeholders, $currentTableId): void {
            if (!is_array($node)) {
                return;
            }

            foreach ($node as &$value) {
                if (is_array($value)) {
                    $replaceShortcodes($value);
                    continue;
                }
                if (!is_string($value)) {
                    continue;
                }

                $value = (string) preg_replace_callback(
                    '/\[(?:site-table|table|hub-site)\s+id\s*=\s*["\']?(\d+)["\']?\s*\/?\]/i',
                    static function (array $matches) use (&$placeholders, $currentTableId): string {
                        $targetTableId = (int) ($matches[1] ?? 0);
                        if ($targetTableId <= 0 || $targetTableId === $currentTableId) {
                            return '';
                        }

                        $token = '%%CMS_HUB_CARD_CONTENT_' . count($placeholders) . '%%';
                        $placeholders[$token] = \CMS\Services\SiteTableService::getInstance()->renderTableById($targetTableId);

                        return $token;
                    },
                    $value
                );
            }
            unset($value);
        };
        $replaceShortcodes($payload);
        $html = \CMS\Services\EditorJsRenderer::getInstance()->render($payload);

        foreach ($placeholders as $token => $replacement) {
            $html = str_replace($token, $replacement, $html);
        }

        return trim($html);
    }

    private function normalizeHubCards(array $rows): array
    {
        $cards = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? $row['Titel'] ?? ''));
            $url = trim((string) ($row['url'] ?? $row['URL'] ?? '#'));
            if ($title === '') {
                continue;
            }

            $cards[] = [
                'is_feature' => !empty($row['is_feature']) || !empty($row['isFeature']) || (string) ($row['display_variant'] ?? '') === 'feature',
                'feature_spacing_top' => max(0, min(240, (int) ($row['feature_spacing_top'] ?? $row['featureSpacingTop'] ?? 0))),
                'title' => mb_substr($title, 0, 160),
                'title_en' => mb_substr(trim((string) ($row['title_en'] ?? $row['titleEn'] ?? '')), 0, 160),
                'url' => $url !== '' ? $url : '#',
                'summary' => mb_substr(trim((string) ($row['summary'] ?? $row['Beschreibung'] ?? '')), 0, 4000),
                'summary_en' => mb_substr(trim((string) ($row['summary_en'] ?? $row['summaryEn'] ?? '')), 0, 4000),
                'badge' => mb_substr(trim((string) ($row['badge'] ?? $row['Kategorie'] ?? '')), 0, 80),
                'badge_en' => mb_substr(trim((string) ($row['badge_en'] ?? $row['badgeEn'] ?? '')), 0, 80),
                'meta' => mb_substr(trim((string) ($row['meta'] ?? $row['Meta'] ?? '')), 0, 120),
                'meta_en' => mb_substr(trim((string) ($row['meta_en'] ?? $row['metaEn'] ?? '')), 0, 120),
                'meta_left' => mb_substr(trim((string) ($row['meta_left'] ?? $row['metaLeft'] ?? $row['Meta links'] ?? $row['meta'] ?? '')), 0, 120),
                'meta_left_en' => mb_substr(trim((string) ($row['meta_left_en'] ?? $row['metaLeftEn'] ?? '')), 0, 120),
                'meta_right' => mb_substr(trim((string) ($row['meta_right'] ?? $row['metaRight'] ?? $row['Meta rechts'] ?? '')), 0, 120),
                'meta_right_en' => mb_substr(trim((string) ($row['meta_right_en'] ?? $row['metaRightEn'] ?? '')), 0, 120),
                'image_url' => mb_substr(trim((string) ($row['image_url'] ?? $row['imageUrl'] ?? $row['Bild'] ?? '')), 0, 500),
                'image_alt' => mb_substr(trim((string) ($row['image_alt'] ?? $row['imageAlt'] ?? '')), 0, 160),
                'image_alt_en' => mb_substr(trim((string) ($row['image_alt_en'] ?? $row['imageAltEn'] ?? '')), 0, 160),
                'button_text' => mb_substr(trim((string) ($row['button_text'] ?? $row['buttonText'] ?? $row['Button-Text'] ?? '')), 0, 80),
                'button_text_en' => mb_substr(trim((string) ($row['button_text_en'] ?? $row['buttonTextEn'] ?? '')), 0, 80),
                'button_link' => mb_substr(trim((string) ($row['button_link'] ?? $row['buttonLink'] ?? $row['Button-Link'] ?? '')), 0, 500),
            ];
        }

        return $cards;
    }

    private function normalizeFeatureCards(array|string $rows): array
    {
        if (is_string($rows)) {
            $decoded = \CMS\Json::decodeArray($rows, []);
            $rows = is_array($decoded) ? $decoded : [];
        }

        $featureCards = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? ''));
            $titleEn = trim((string) ($row['title_en'] ?? $row['titleEn'] ?? ''));
            $text = trim((string) ($row['text'] ?? ''));
            $textEn = trim((string) ($row['text_en'] ?? $row['textEn'] ?? ''));
            $imageUrl = trim((string) ($row['image_url'] ?? $row['imageUrl'] ?? ''));

            if ($title === '' && $titleEn === '' && $text === '' && $textEn === '' && $imageUrl === '') {
                continue;
            }

            $featureCards[] = [
                'insert_after' => max(0, (int) ($row['insert_after'] ?? $row['insertAfter'] ?? 0)),
                'feature_spacing_top' => max(0, min(240, (int) ($row['feature_spacing_top'] ?? $row['featureSpacingTop'] ?? 0))),
                'title' => mb_substr($title, 0, 160),
                'title_en' => mb_substr($titleEn, 0, 160),
                'text' => mb_substr($text, 0, 4000),
                'text_en' => mb_substr($textEn, 0, 4000),
                'image_url' => mb_substr($imageUrl, 0, 500),
                'image_alt' => mb_substr(trim((string) ($row['image_alt'] ?? $row['imageAlt'] ?? '')), 0, 160),
                'image_alt_en' => mb_substr(trim((string) ($row['image_alt_en'] ?? $row['imageAltEn'] ?? '')), 0, 160),
            ];
        }

        return $featureCards;
    }

    private function localizeFeatureCards(array $featureCards, string $locale): array
    {
        if ($locale === 'de') {
            return $featureCards;
        }

        return array_map(static function (array $card): array {
            if (trim((string) ($card['title_en'] ?? '')) !== '') {
                $card['title'] = (string) $card['title_en'];
            }
            if (trim((string) ($card['text_en'] ?? '')) !== '') {
                $card['text'] = (string) $card['text_en'];
            }
            if (trim((string) ($card['image_alt_en'] ?? '')) !== '') {
                $card['image_alt'] = (string) $card['image_alt_en'];
            }

            return $card;
        }, $featureCards);
    }

    private function normalizeOption(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function containsHtml(string $value): bool
    {
        return preg_match('/<\s*\/?\s*[a-z][^>]*>/i', $value) === 1;
    }

    private function sanitizeRichHtml(string $value): string
    {
        return PurifierService::getInstance()->purify($value, 'table');
    }
}
