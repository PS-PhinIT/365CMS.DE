<?php
/**
 * Katalog der Einheit D, Teil Suche (PR #2b, Welle 2): `Service\CommunitySearch` (Suche, Index, Pflege, Sitemap), `Repository\SearchRepository`
 * und `Service\CommunityMediaExtractor` laut Vertrag DESIGN-2b §4.8, §7.4 und Entwurf medien §6.4.
 *
 * Gleiches Format wie `catalog.php`: Name => [Datei relativ zu CMS/, Suchtext, Ersatz, Suite, needsDb, Regel, quick, env, requires]
 *
 * Herkunft: medien §6.4 (`search-*`, `index-journal-lost`, `index-build-target`, `sitemap-*`, `extractor-statuses`; Ziel-Suite umbenannt
 * in `community-search-seo`, die Sitemap-Mutanten zielen auf `CommunitySearch`), Vertrag §7.4 (`search-500-limit` mit neuem Suchtext;
 * Fassung 3: `search-guest-like`, `search-doc-cap`, `search-truncated-leak`, `index-fixed-name`; Fassung 3.1: `search-no-hits-index`,
 * `index-stale-kept`). Die übrigen Zeilen sichern Regeln dieser Einheit ohne Vorlage.
 *
 * Nicht hier: `index-topic-expand` (`CommunityHooks`, H in W3, `catalog-2b-hooks.php`), `access-*` (A, W1).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

$search = 'core/Services/Community/Service/CommunitySearch.php';
$repo = 'core/Services/Community/Repository/SearchRepository.php';
$extractor = 'core/Services/Community/Service/CommunityMediaExtractor.php';

return [
    // ── Rechte (D1, E23)
    'search-topic-status' => [$repo, "AND t.status = 'published' AND t.forum_id IN (", 'AND t.forum_id IN (', 'community-search-seo', false, 'Treffer nur in veröffentlichten Themen', true, []],
    'search-post-status' => [$repo, "\" AND p.status = 'published' AND t.status", '" AND t.status', 'community-search-seo', false, 'Treffer nur veröffentlichte Beiträge', false, []],
    'search-post-forum' => [$repo, 'AND t.forum_id IN (";', 'AND p.forum_id IN (";', 'community-search-seo', false, 'Rechte über t.forum_id (p.forum_id ist nach dem Verschieben veraltet)', false, []],
    'search-all-forums' => [$search, '$forums = ($this->readable)($userId);', '$forums = array_keys((new ForumRepository($this->db))->tree());', 'community-search-seo', false, 'Suche nur in den lesbaren Foren (Mengen-API)', true, []],
    'search-throttle-skipped' => [$search, "\$gate = (\$this->throttle)('search', \$userId);", "\$gate = ['allowed' => true, 'retry_after' => 0];", 'community-search-seo', false, 'Drossel search je Gast und Mitglied', false, []],

    // ── Grenzen (Vertrag §4.8 Nr. 2 bis 4)
    'search-500-limit' => [$search, '$tnt->engine->maxDocs = self::MAX_DOCS_PER_WORD;', 'null;', 'community-search-seo', false, 'TNT liest je Wort bis zu 20.000 Dokumente (Standard 500 verliert Treffer auf Rang > 500)', false, []],
    'search-doc-cap' => [$search, '$tnt->engine->maxDocs = self::MAX_DOCS_PER_WORD;', '$tnt->setFuzzyNoLimit(true);', 'community-search-seo', false, 'höchstens 20.000 Dokumente je Wort (ohne Grenze 400 MB je Wort bei einer Million Dokumenten)', false, []],
    'search-no-hits-index' => [$search, '$pdo->exec(self::HITS_INDEX_SQL);', '', 'community-search-seo', false, 'Verbundindex doclist_term_hits nach createIndex() und in reconcile()', false, []],
    'search-truncated-leak' => [$search, '$truncated = count($topics) >= self::MAX_TOPICS && $remaining;', '$truncated = $remaining;', 'community-search-seo', false, 'truncated nur bei MAX_TOPICS (kein Hinweis auf unsichtbare Kandidaten)', false, []],
    'search-guest-like' => [$search, 'if ($userId === null) { // kein LIKE für Gäste', 'if (false) {', 'community-search-seo', false, 'Gäste ohne Index: source unavailable, kein LIKE-Rückfall', false, []],
    'search-like-window' => [$search, '$min = max(0, $max - self::LIKE_WINDOW);', '$min = 0;', 'community-search-seo', false, 'LIKE-Rückfall nur über die jüngsten LIKE_WINDOW Beiträge', false, []],
    'search-like-escape' => [$repo, "strtr(\$word, ['!' => '!!', '%' => '!%', '_' => '!_'])", '$word', 'community-search-seo', false, 'LIKE-Muster maskiert %, _ und !', false, []],

    // ── Ergebnis
    'search-quotes-indexed' => [$search, '->plainText($markdown, false)', '->plainText($markdown, true)', 'community-search-seo', false, 'Zitatblöcke werden nicht indexiert (zitierter Text findet nur die Quelle)', false, []],
    'search-title-bonus' => [$search, "\$hit['score'] = (float) \$hit['score'] + self::TITLE_BONUS;", "\$hit['score'] = (float) \$hit['score'];", 'community-search-seo', false, 'Titeltreffer im ersten Beitrag erhalten den Titelbonus', false, []],

    // ── Index, Pflege und Dateinamen (Vertrag §4.8 Nr. 5)
    'index-journal-lost' => [$search, '$this->appendJournal($pending);', 'null;', 'community-search-seo', false, 'bei belegter Sperre landen Vormerkungen im Journal (keine verlorene Löschung)', false, []],
    'index-build-target' => [$search, 'foreach ($this->targets() as $file) {', 'foreach ([self::INDEX_FILE] as $file) {', 'community-search-seo', false, 'jede Änderung landet in Live- und Aufbaudatei', false, []],
    'index-fixed-name' => [$search, "return substr(hash_hmac('sha256', 'community-index', \$key), 0, 16);", "return 'index';", 'community-search-seo', false, 'Indexdateien tragen ein Kennzeichen aus dem Geheimnis', false, []],
    'index-secret-unchecked' => [$search, 'IpHash::usableSecret($secret) ? self::tagFor(', 'true ? self::tagFor(', 'community-search-seo', false, 'ohne brauchbares Geheimnis gibt es keinen Index', false, []],
    'index-stale-kept' => [$search, 'foreach ($this->staleIndexFiles($tag) as $stale) {', 'foreach ([] as $stale) {', 'community-search-seo', false, 'Neuaufbau löscht Index und Journal mit fremdem Kennzeichen (Schlüsselwechsel)', false, []],

    // ── Sitemap (E26)
    'sitemap-member-view' => [$search, '$forums = ($this->readable)(null);', '$forums = array_keys((new ForumRepository($this->db))->tree());', 'community-search-seo', false, 'Sitemap in Gastsicht', false, []],
    'sitemap-link-forums' => [$search, "if ((string) \$forum['type'] === 'link' || (int) \$forum['topic_count'] < 1) {", 'if (false) {', 'community-search-seo', false, 'keine Verweis- und leeren Foren in der Sitemap', false, []],
    'sitemap-unbounded' => [$search, '$this->search->sitemapTopics($ids, self::SITEMAP_MAX_ENTRIES - count($entries))', '$this->search->sitemapTopics($ids, 5000)', 'community-search-seo', false, 'höchstens SITEMAP_MAX_ENTRIES Einträge samt Startseite und Foren', false, []],

    // ── Medienreferenzen (E27)
    'extractor-statuses' => [$extractor, "public const STATUSES = ['published', 'pending', 'hidden'];", "public const STATUSES = ['published'];", 'community-search-seo', false, 'auch wartende und ausgeblendete Beiträge gelten als Verwendung', false, []],
];
