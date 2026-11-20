<?php
/**
 * Katalog der Einheit V (PR #2b, Welle 1): Renderer-Kern `Support\ContentRenderer`, Purifier-Profil `community` und
 * `QuoteService::presentable()` laut Vertrag DESIGN-2b §4.7, §7.4 und Entwurf render §6.5.
 *
 * Gleiches Format wie `catalog.php`: Name => [Datei relativ zu CMS/, Suchtext, Ersatz, Suite, needsDb, Regel, quick, env]
 *
 * Herkunft: render §6.5 (`render-*`, `purifier-*`; `render-html-allow`, `render-unsafe-links`, `render-code-class` und
 * `render-quote-ast` zielen laut Vertrag §7.4 auf `community-xss`), Vertrag §7.4 Nacharbeit (`present-keeps-raw`), Fassung 3
 * (`render-no-precheck`, `render-no-structure-limit`, `render-memory-unchecked`, `quotes-unbounded`, `hosts-raw`), Fassung 3.1
 * (`render-tags-uncounted`), Integration (`link-mailto-uncounted`), Nachbesserung N1 (`render-backslash-uncounted`, `render-at-uncounted`,
 * `render-www-uncounted`, `render-www-case`, `render-scheme-slashes`). Die übrigen Zeilen sichern Regeln dieser Einheit ohne Vorlage.
 *
 * Nicht hier: `render-quote-nested` (render §6.5) ist äquivalent. Die Quellzeile eines verschachtelten Blockzitats beginnt immer mit
 * dem Präfix des Containers („> > “, „- > “, Einrückung), `QuoteService::markerId()` erkennt sie also auch ohne die Beschränkung auf
 * `$document->children()` nie; die Regel „nur oberste Ebene“ sichern die Prüffälle in `community-render` B und `community-xss` X18.
 * Die Mutanten auf `PostRepository`, `RenderService`, `PostService` und `QuoteService::resolveMany()` gehören B1, R und T.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

$renderer = 'core/Services/Community/Support/ContentRenderer.php';
$purifier = 'core/Services/PurifierService.php';
$quotes = 'core/Services/Community/Service/QuoteService.php';

return [
    // ── CommonMark-Konfiguration und eigene Renderer (render §6.5)
    'render-html-allow' => [$renderer, "'html_input' => 'escape',", "'html_input' => 'allow',", 'community-xss', false, 'R1: Roh-HTML wird escaped, nie durchgelassen (CommonMark-Stufe allein)', true, []],
    'render-unsafe-links' => [$renderer, "'allow_unsafe_links' => false,", "'allow_unsafe_links' => true,", 'community-xss', false, 'R4: unsichere Ziele (javascript:, vbscript:, file:) ohne href schon in der CommonMark-Stufe', true, []],
    'render-nesting' => [$renderer, "'max_nesting_level' => 20,", "'max_nesting_level' => 1000,", 'community-render', false, 'Schachtelgrenze 20 (60 × „>“ ergibt höchstens 20 Ebenen)', false, []],
    'render-soft-break' => [$renderer, "'renderer' => ['soft_break' => \"<br />\\n\"],", "'renderer' => ['soft_break' => \"\\n\"],", 'community-render', false, 'R10: einfacher Zeilenumbruch → <br />', false, []],
    'render-headings' => [$renderer, "\$node->getLevel() <= 1 ? 'h3' : 'h4'", "'h' . \$node->getLevel()", 'community-render', false, 'R2: # → h3, tiefer → h4', false, []],
    'render-code-class' => [$renderer, 'self::LANGUAGES[$word] ?? null', '($word !== \'\' ? $word : null)', 'community-xss', false, 'R3: Sprachklasse nur aus der Allowlist (Info-String-Injektion)', false, []],
    'render-protocol-relative' => [$renderer, "if (str_starts_with(\$url, '//')) {", 'if (false) {', 'community-render', false, 'R4: //host wird https://host (Renderer und Zählung)', false, []],
    'render-foreign-upload' => [$renderer, "\$uploaderId !== null && (int) (\$row['user_id'] ?? 0) === \$uploaderId;", '$uploaderId !== null;', 'community-render', false, 'R5: nur eigene unverknüpfte Uploads werden eingebettet', true, []],
    'render-other-post-attachment' => [$renderer, "(int) \$row['post_id'] === \$postId) {", 'true) {', 'community-render', false, 'R5: ein verknüpfter Anhang gilt nur für seinen Beitrag', false, []],
    'render-quote-ast' => [$renderer, 'QuoteService::markerId(self::sourceLine($lines, $block))', "QuoteService::markerId('> ' . self::firstText(\$block))", 'community-xss', false, 'R6: der Marker wird an der Quellzeile erkannt, nicht am Text des Syntaxbaums (entschärfter Marker)', true, []],
    'render-spoiler-in-list' => [$renderer, "            if (!\$parent instanceof BlockQuote) {\n                return;\n            }\n            \$depth++;", '            $depth += $parent instanceof BlockQuote ? 1 : 0;', 'community-render', false, 'R7: ein Blockzitat in einer Liste ist kein Spoiler (auch eingerückt in der Fortsetzung)', false, []],
    'render-mentions-in-links' => [$renderer, "\$event->isEntering() && \$inLink === 0 && \$node instanceof Text", "\$event->isEntering() && \$node instanceof Text", 'community-render', false, 'Stats.mentions: nur Text außerhalb von Links und Bildern', false, []],
    'render-attachments-unbounded' => [$renderer, '$ids = array_slice($ids, 0, self::MAX_ATTACHMENTS);', '', 'community-render', false, 'höchstens MAX_ATTACHMENTS = 50 Anhangs-IDs je Text werden nachgeschlagen', false, []],
    'render-reentrant' => [$renderer, "        if (\$this->depth > 0) {\n            return null;\n        }", "        if (false) {\n            return null;\n        }", 'community-render', false, 'R12: verschachtelter Aufruf ergibt render_failed', false, []],
    'render-purifier-empty' => [$renderer, "(\$clean === '' && trim(\$raw) !== '')", 'false', 'community-render', false, 'R11: Purifier liefert „“ für nicht leeres HTML → html null', false, []],

    // ── Grenzen (Vertrag §4.7 Nr. 1, 2, 6, 7; Fassung 3 und 3.1)
    'render-no-precheck' => [$renderer, 'if (!$this->withinLimits($markdown)) {', 'if (false) {', 'community-render', false, 'Vorprüfung vor jedem Parsen (Bytes, Zeilen, Begrenzer, Speicher)', true, []],
    'render-no-structure-limit' => [$renderer, 'if ($nodes > self::MAX_NODES || $cells > self::MAX_TABLE_CELLS) {', 'if (false) {', 'community-render', false, 'Strukturgrenze nach dem Parsen (Knoten, Tabellenzellen)', false, []],
    'render-memory-unchecked' => [$renderer, 'if ($free !== null && $free < self::memoryNeed(', 'if (false && $free < self::memoryNeed(', 'community-render', false, 'Speicherschätzung vor dem Parsen gegen headroom', false, []],
    'render-tags-uncounted' => [$renderer, "(int) preg_match_all('~<[A-Za-z/!?]~', \$markdown)", '0', 'community-render', false, 'Tag-Anfänge zählen als Begrenzer (<b> × 24.000 vor dem Parsen abgelehnt)', false, []],
    'quotes-unbounded' => [$renderer, 'if (count($quoteIds) >= self::MAX_QUOTES) {', 'if (false) {', 'community-render', false, 'Zitatgrenze: nur 20 Zitat-Blöcke erhalten data-quote-post, Überlauf ohne Quelltext', false, []],
    'hosts-raw' => [$renderer, 'return strtolower(rawurldecode($host));', 'return strtolower($host);', 'community-render', false, 'normalizeHost(): Prozent-Kodierung wird aufgelöst (Sperrliste, Wortfilter)', false, []],
    'link-mailto-uncounted' => [$renderer, "if (\$scheme === 'mailto') {", 'if (false) {', 'community-render', false, 'external_links zählt mailto: (Vertrag K4)', false, []],

    // ── Nachbesserung N1: weitere Begrenzer (Speicherschätzung deckt die Spitze), Schema ohne // (R4, Hostregel)
    'render-backslash-uncounted' => [$renderer, "private const DELIMITER_CHARS = '[]*_~`&@\\\\';", "private const DELIMITER_CHARS = '[]*_~`&@';", 'community-render', false, '\\ zählt als Begrenzer (\\\\ × 100.000 vor dem Parsen abgelehnt; Schätzung deckt die Spitze)', true, []],
    'render-at-uncounted' => [$renderer, "private const DELIMITER_CHARS = '[]*_~`&@\\\\';", "private const DELIMITER_CHARS = '[]*_~`&\\\\';", 'community-render', false, '@ zählt als Begrenzer (E-Mail-Autolinks, 33.000 Adressen vor dem Parsen abgelehnt)', false, []],
    'render-www-uncounted' => [$renderer, "\$delimiters += (int) preg_match_all('~www\\.~i', \$markdown);", '', 'community-render', false, 'www. zählt als Begrenzer (www. × 50.000 vor dem Parsen abgelehnt)', false, []],
    'render-www-case' => [$renderer, "preg_match_all('~www\\.~i', \$markdown)", "preg_match_all('~www\\.~', \$markdown)", 'community-render', false, 'www. zählt ohne Groß-/Kleinschreibung (wie InlineParserMatch::oneOf())', false, []],
    'render-scheme-slashes' => [$renderer, "return (string) preg_replace('#^(https?):[\\\\\\\\/]*#i', '\$1://', \$url);", 'return $url;', 'community-render', false, 'R4: http(s) ohne // nach dem Schema wird scheme:// (gezählter Host = Host der Ausgabe, nie der Site-Host mit angehängtem Text)', true, []],

    // ── Purifier-Profil community (render §1.3.5, §6.5)
    'purifier-ugc' => [$purifier, "['strict', 'minimal', 'community']", "['strict', 'minimal']", 'community-render', false, 'Profil community ist nutzergeneriert: externe Links erhalten nofollow ugc', true, []],
    'purifier-external' => [$purifier, "'external_resources' => false,", "'external_resources' => true,", 'community-render', false, 'keine fremden Bilder (URI.DisableExternalResources)', true, []],
    'purifier-schemes' => [$purifier, "'schemes'    => ['http', 'https', 'mailto'],", "'schemes'    => ['http', 'https', 'mailto', 'tel'],", 'community-render', false, 'Profil community: nur http, https, mailto', false, []],
    'purifier-scheme-leak' => [$purifier, "\$config->set('URI.OverrideAllowedSchemes', false);", '', 'community-render', false, 'eigene Schema-Liste wirkt prozessweit (tel: aus strict gilt nicht für community)', false, []],
    'purifier-classes' => [$purifier, "if (isset(\$profileConfig['classes'])) {", 'if (false) {', 'community-render', false, 'Profil community: nur Klassen der Allowlist', false, []],
    'purifier-protocol-relative' => [$purifier, "if (str_starts_with(\$uri, '//')) {", 'if (false) {', 'community-render', false, 'isForeignUrl(): //host gilt als fremd (nofollow ugc)', false, []],

    // ── Ausgaberegel (Vertrag §1 Nr. 9, K44)
    'present-keeps-raw' => [$quotes, "unset(\$item['body_html']);", '', 'community-render', false, 'presentable(): body_html verlässt keinen Dienst', true, []],
    'present-body-raw' => [$quotes, "\$item['body'] = self::neutralize(\$item['body'], \$visible);", '', 'community-render', false, 'presentable(): body nur neutralisiert', false, []],
    'present-placeholder-body' => [$quotes, "            unset(\$item['body']);\n", '', 'community-render', false, 'presentable(): Platzhalter tragen keinen Text (fail-closed)', false, []],
];
