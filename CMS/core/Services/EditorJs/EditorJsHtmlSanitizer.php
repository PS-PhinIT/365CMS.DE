<?php
/**
 * Zentrale HTML-Sanitizing-Helfer für Editor.js Inhalte.
 *
 * @package CMSv2\Services
 */

declare(strict_types=1);

namespace CMS\Services\EditorJs;

if (!defined('ABSPATH')) {
    exit;
}

final class EditorJsHtmlSanitizer
{
    /** @var array<string,bool> */
    private const BLOCKED_TAGS = [
        'script' => true,
        'style' => true,
        'object' => true,
        'embed' => true,
        'form' => true,
        'input' => true,
        'button' => true,
        'textarea' => true,
        'select' => true,
        'option' => true,
        'noscript' => true,
        'svg' => true,
        'math' => true,
        'meta' => true,
        'link' => true,
        'base' => true,
    ];

    /** @var array<string,bool> */
    private const INLINE_TAGS = [
        'a' => true,
        'b' => true,
        'strong' => true,
        'i' => true,
        'em' => true,
        'u' => true,
        's' => true,
        'strike' => true,
        'del' => true,
        'code' => true,
        'mark' => true,
        'font' => true,
        'sub' => true,
        'sup' => true,
        'br' => true,
        'span' => true,
    ];

    /** @var array<string,bool> */
    private const RAW_BLOCK_TAGS = [
        'p' => true,
        'div' => true,
        'span' => true,
        'a' => true,
        'strong' => true,
        'b' => true,
        'em' => true,
        'i' => true,
        'u' => true,
        's' => true,
        'strike' => true,
        'del' => true,
        'code' => true,
        'mark' => true,
        'font' => true,
        'sub' => true,
        'sup' => true,
        'br' => true,
        'ul' => true,
        'ol' => true,
        'li' => true,
        'h1' => true,
        'h2' => true,
        'h3' => true,
        'h4' => true,
        'h5' => true,
        'h6' => true,
        'blockquote' => true,
        'pre' => true,
        'hr' => true,
        'figure' => true,
        'figcaption' => true,
        'table' => true,
        'thead' => true,
        'tbody' => true,
        'tr' => true,
        'td' => true,
        'th' => true,
        'img' => true,
        'iframe' => true,
    ];

    private const ROOT_ID = 'cms-editorjs-sanitize-root';

    public static function sanitizeInline(string $html): string
    {
        return self::sanitizeFragment($html, self::INLINE_TAGS, false);
    }

    public static function sanitizeRawBlock(string $html): string
    {
        return self::sanitizeFragment($html, self::RAW_BLOCK_TAGS, true);
    }

    /**
     * @param string[] $allowedSchemes
     */
    public static function sanitizeUrl(string $url, array $allowedSchemes = ['http', 'https', 'mailto', 'tel'], bool $allowRelative = true, bool $allowDataImage = false): string
    {
        $url = trim($url);
        if ($url === '' || str_contains($url, "\r") || str_contains($url, "\n") || str_contains($url, "\0")) {
            return '';
        }

        if ($allowDataImage && preg_match('#^data:image/(?:png|gif|jpe?g|webp|bmp|x-icon|vnd\.microsoft\.icon);base64,[a-z0-9+/=\s]+$#i', $url) === 1) {
            return preg_replace('/\s+/', '', $url) ?? '';
        }

        if ($allowRelative && preg_match('~^(?:/|\./|\.\./|#)[^\s<>"\']*$~', $url) === 1) {
            return $url;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, $allowedSchemes, true) ? $url : '';
    }

    public static function plainText(string $value): string
    {
        return trim(strip_tags($value));
    }

    /**
     * @param array<string,bool> $allowedTags
     */
    private static function sanitizeFragment(string $html, array $allowedTags, bool $allowRichBlocks): string
    {
        if (trim($html) === '') {
            return '';
        }

        if (!class_exists(\DOMDocument::class)) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previousState = libxml_use_internal_errors(true);
        $documentHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="' . self::ROOT_ID . '">' . $html . '</div></body></html>';
        @$doc->loadHTML($documentHtml, LIBXML_HTML_NODEFDTD | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previousState);

        $xpath = new \DOMXPath($doc);
        $root = $xpath->query('//*[@id="' . self::ROOT_ID . '"]')->item(0);
        if (!$root instanceof \DOMElement) {
            return '';
        }

        self::sanitizeChildren($root, $allowedTags, $allowRichBlocks);

        $htmlParts = [];
        foreach ($root->childNodes as $childNode) {
            $htmlParts[] = $doc->saveHTML($childNode) ?: '';
        }

        return trim(implode('', $htmlParts));
    }

    /**
     * @param array<string,bool> $allowedTags
     */
    private static function sanitizeChildren(\DOMNode $node, array $allowedTags, bool $allowRichBlocks): void
    {
        $children = [];
        foreach ($node->childNodes as $childNode) {
            $children[] = $childNode;
        }

        foreach ($children as $childNode) {
            if ($childNode instanceof \DOMComment || $childNode instanceof \DOMProcessingInstruction) {
                $node->removeChild($childNode);
                continue;
            }

            if (!$childNode instanceof \DOMElement) {
                continue;
            }

            self::sanitizeElement($childNode, $allowedTags, $allowRichBlocks);
        }
    }

    /**
     * @param array<string,bool> $allowedTags
     */
    private static function sanitizeElement(\DOMElement $element, array $allowedTags, bool $allowRichBlocks): void
    {
        $tagName = strtolower($element->tagName);

        if (isset(self::BLOCKED_TAGS[$tagName])) {
            self::removeElement($element);
            return;
        }

        self::sanitizeChildren($element, $allowedTags, $allowRichBlocks);

        if (!isset($allowedTags[$tagName])) {
            self::unwrapElement($element);
            return;
        }

        self::sanitizeAttributes($element, $tagName, $allowRichBlocks);
    }

    private static function sanitizeAttributes(\DOMElement $element, string $tagName, bool $allowRichBlocks): void
    {
        $originalAttributes = [];
        foreach ($element->attributes as $attribute) {
            $originalAttributes[strtolower($attribute->name)] = $attribute->value;
        }

        while ($element->attributes->length > 0) {
            $element->removeAttributeNode($element->attributes->item(0));
        }

        if ($tagName === 'a') {
            $href = self::sanitizeUrl((string) ($originalAttributes['href'] ?? ''));
            if ($href === '') {
                self::unwrapElement($element);
                return;
            }

            $element->setAttribute('href', $href);
            $title = self::plainText((string) ($originalAttributes['title'] ?? ''));
            if ($title !== '') {
                $element->setAttribute('title', $title);
            }

            $target = strtolower(trim((string) ($originalAttributes['target'] ?? '')));
            if (!in_array($target, ['_blank', '_self'], true)) {
                $target = '';
            }
            if (preg_match('#^https?://#i', $href) === 1 && $target === '') {
                $target = '_blank';
            }
            if ($target !== '') {
                $element->setAttribute('target', $target);
            }

            $rel = self::sanitizeRelAttribute((string) ($originalAttributes['rel'] ?? ''), $target === '_blank');
            if ($rel !== '') {
                $element->setAttribute('rel', $rel);
            }

            return;
        }

        if (in_array($tagName, ['s', 'strike', 'del'], true)) {
            $classes = preg_split('/\s+/', (string) ($originalAttributes['class'] ?? '')) ?: [];
            if (in_array('cdx-strikethrough', $classes, true) || in_array('cdx-strikethroughs', $classes, true)) {
                $element->setAttribute('class', 'cdx-strikethrough');
            }

            return;
        }

        if ($tagName === 'span') {
            $classes = preg_split('/\s+/', (string) ($originalAttributes['class'] ?? '')) ?: [];
            if (in_array('tg-spoiler', $classes, true)) {
                $element->setAttribute('class', 'tg-spoiler');
            }

            // Bewusst weiter als style-Attribut: Text- und Markerfarben wählen Redakteure frei (Farbwähler des
            // textColor-Tools), dafür gibt es keine feste Klassenliste. Die Produktiv-CSP blockiert das Attribut
            // (style-src-attr); vollständige HTML-Antworten wandelt CMS\Http\InlineStyleRewriter in Nonce-Klassen um.
            // Nur color/background-color mit geprüften Werten (sanitizeColorStyle) bleiben erhalten.
            $style = self::sanitizeColorStyle((string) ($originalAttributes['style'] ?? ''));
            if ($style !== '') {
                $element->setAttribute('style', $style);
            }

            return;
        }

        if ($tagName === 'font') {
            $color = self::sanitizeColorValue((string) ($originalAttributes['color'] ?? ''));
            if ($color !== '') {
                $element->setAttribute('color', $color);
            }

            return;
        }

        if ($tagName === 'code') {
            $class = (string) ($originalAttributes['class'] ?? '');
            if (preg_match('/\blanguage-[a-z0-9_+\-#]+\b/i', $class, $match) === 1) {
                $element->setAttribute('class', strtolower($match[0]));
            }

            return;
        }

        if (!$allowRichBlocks) {
            return;
        }

        if ($tagName === 'img') {
            $src = self::sanitizeUrl((string) ($originalAttributes['src'] ?? ''), ['http', 'https'], true, false);
            if ($src === '') {
                self::removeElement($element);
                return;
            }

            $element->setAttribute('src', $src);
            foreach (['alt', 'title'] as $attributeName) {
                $value = self::plainText((string) ($originalAttributes[$attributeName] ?? ''));
                if ($value !== '') {
                    $element->setAttribute($attributeName, $value);
                }
            }
            self::setIntegerAttribute($element, $originalAttributes, 'width', 1, 4096);
            self::setIntegerAttribute($element, $originalAttributes, 'height', 1, 4096);
            $element->setAttribute('loading', 'lazy');
            $element->setAttribute('decoding', 'async');
            return;
        }

        if ($tagName === 'iframe') {
            $src = self::sanitizeUrl((string) ($originalAttributes['src'] ?? ''), ['https'], false, false);
            if ($src === '') {
                self::removeElement($element);
                return;
            }

            $element->setAttribute('src', $src);
            $title = self::plainText((string) ($originalAttributes['title'] ?? ''));
            $element->setAttribute('title', $title !== '' ? $title : 'Eingebetteter Inhalt');
            self::setIntegerAttribute($element, $originalAttributes, 'width', 1, 4096);
            self::setIntegerAttribute($element, $originalAttributes, 'height', 1, 4096);
            $element->setAttribute('loading', 'lazy');
            $element->setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation');
            $element->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
            return;
        }

        if (in_array($tagName, ['td', 'th'], true)) {
            self::setIntegerAttribute($element, $originalAttributes, 'colspan', 1, 12);
            self::setIntegerAttribute($element, $originalAttributes, 'rowspan', 1, 12);
            return;
        }

        if ($tagName === 'ol') {
            self::setIntegerAttribute($element, $originalAttributes, 'start', 1, 10000);
            $type = (string) ($originalAttributes['type'] ?? '');
            if (in_array($type, ['1', 'a', 'A', 'i', 'I'], true)) {
                $element->setAttribute('type', $type);
            }
        }
    }

    /** @param array<string,string> $attributes */
    private static function setIntegerAttribute(\DOMElement $element, array $attributes, string $name, int $min, int $max): void
    {
        $value = (int) ($attributes[$name] ?? 0);
        if ($value < $min || $value > $max) {
            return;
        }

        $element->setAttribute($name, (string) $value);
    }

    private static function sanitizeColorValue(string $value): string
    {
        $color = trim($value);
        if ($color === '') {
            return '';
        }

        if (preg_match('/^#[0-9a-f]{3,8}$/i', $color) === 1) {
            return $color;
        }

        if (preg_match('/^rgba?\(\s*(?:\d{1,3}\s*,\s*){2}\d{1,3}(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)$/i', $color) === 1) {
            return $color;
        }

        if (preg_match('/^var\(--[a-z0-9_-]+\)$/i', $color) === 1) {
            return $color;
        }

        return '';
    }

    private static function sanitizeColorStyle(string $style): string
    {
        $declarations = [];
        foreach (explode(';', $style) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $property = strtolower(trim($parts[0]));
            if (!in_array($property, ['color', 'background-color'], true)) {
                continue;
            }

            $color = self::sanitizeColorValue($parts[1]);
            if ($color !== '') {
                $declarations[] = $property . ': ' . $color;
            }
        }

        return implode('; ', $declarations);
    }

    private static function sanitizeRelAttribute(string $rel, bool $forceBlankSafety): string
    {
        $allowed = ['noopener', 'noreferrer', 'nofollow', 'external', 'author', 'bookmark', 'license', 'tag'];
        $tokens = [];

        foreach (preg_split('/\s+/', strtolower(trim($rel))) ?: [] as $token) {
            if ($token !== '' && in_array($token, $allowed, true) && !in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }

        if ($forceBlankSafety) {
            foreach (['noopener', 'noreferrer'] as $requiredToken) {
                if (!in_array($requiredToken, $tokens, true)) {
                    $tokens[] = $requiredToken;
                }
            }
        }

        return implode(' ', $tokens);
    }

    private static function unwrapElement(\DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private static function removeElement(\DOMElement $element): void
    {
        if ($element->parentNode !== null) {
            $element->parentNode->removeChild($element);
        }
    }
}
