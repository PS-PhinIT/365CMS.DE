<?php
/**
 * Liefert für eine Inhaltsart die Werte, in denen Medien verwendet werden können (4.1.0, Bereich 2b).
 *
 * Der `MediaUsageIndex` findet darin die Medienpfade (`MediaPathNormalizer`) und unterscheidet bei Editor.js-Inhalten
 * zusätzlich Galerie- und Anhang-Blöcke. Registrierung: Action `cms_register_media_reference_extractors`
 * (`MediaUsageIndex $index` → `$index->register(new MeinExtractor())`), wie bei den Inhaltstyp-Providern.
 *
 * @package CMSv2\Core\Services\Media
 */

declare(strict_types=1);

namespace CMS\Services\Media;

if (!defined('ABSPATH')) {
    exit;
}

interface MediaReferenceExtractor
{
    /** Kurzname der Inhaltsart, höchstens 30 Zeichen (z. B. `post`, `hub_site`, `theme`). */
    public function contentType(): string;

    /** Anzeigename (z. B. „Beitrag“). */
    public function label(): string;

    /**
     * IDs in aufsteigender Reihenfolge, größer als `$afterId` (für den Neuaufbau in Schritten).
     *
     * @return list<int>
     */
    public function ids(int $afterId, int $limit): array;

    /**
     * Ein Inhalt mit Titel, Editor-Link und den zu durchsuchenden Werten; null = existiert nicht (mehr).
     * Kontexte: `content`, `content_en`, `featured`, `gallery`, `attachment`, `setting`.
     *
     * @return array{title:string, edit_url:string, values:list<array{context:string, value:mixed}>}|null
     */
    public function load(int $id): ?array;
}
