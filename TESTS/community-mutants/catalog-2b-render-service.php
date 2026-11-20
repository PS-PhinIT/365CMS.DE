<?php
/**
 * Katalog der Einheit R (PR #2b, Welle 2): `Service\RenderService` und die Mengen-API von `Service\QuoteService`
 * (`resolveMany()`, `stateOf()`, `quotableMany()`) laut Vertrag DESIGN-2b §4.3, §4.7, §7.4 und Entwurf render §6.5.
 *
 * Gleiches Format wie `catalog.php`: Name => [Datei relativ zu CMS/, Suchtext, Ersatz, Suite, needsDb, Regel, quick, env]
 *
 * Herkunft: render §6.5 (`lazy-budget`, `serve-below-min`, `filter-unchecked`, `job-all`, `quote-removed`, `quote-batch`,
 * `render-race-no-guard`), Vertrag §7.4 Nacharbeit (`fallback-raw-body`, `decisions-skip-body-refs`) und Fassung 3
 * (`preview-read-only`, `lazy-guest-budget`, `quote-lookups-unbounded`, `quote-removed-before-allowed`). Die übrigen Zeilen sichern
 * Regeln dieser Einheit ohne Vorlage. `clear-rendered-keeps-count` liegt bei B1 (`catalog-2b-repo-content.php`).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

$render = 'core/Services/Community/Service/RenderService.php';
$quotes = 'core/Services/Community/Service/QuoteService.php';
$posts = 'core/Services/Community/Repository/PostRepository.php';

return [
    // ── Anzeige: Cache, Budget, Ausgaberegel (render §6.5, Vertrag §4.3, §4.7 Nr. 4)
    'lazy-budget' => [$render, 'public const LAZY_MAX_POSTS = 20;', 'public const LAZY_MAX_POSTS = 1000;', 'community-render', false, 'Nachrendern: höchstens 20 Beiträge je Instanz für Mitglieder', false, []],
    'lazy-guest-budget' => [$render, '$viewerId === null ? self::LAZY_GUEST_POSTS : self::LAZY_MAX_POSTS', 'self::LAZY_MAX_POSTS', 'community-render', false, 'Nachrendern für Gäste: höchstens 5 Beiträge je Instanz (Vertrag §4.7 Nr. 4)', false, []],
    'lazy-chars-unbounded' => [$render, ' || $this->lazyChars + $chars > $maxChars', '', 'community-render', false, 'Nachrendern: Zeichenbudget je Instanz (Mitglieder 200.000, Gäste 50.000)', false, []],
    'serve-below-min' => [$render, '>= ContentRenderer::MIN_SERVE_VERSION', '>= 0', 'community-render', false, 'gespeichertes HTML unter MIN_SERVE_VERSION wird nie ausgeliefert', true, []],
    'store-in-transaction' => [$render, 'if ($this->db->getPdo()->inTransaction() === false) {', 'if (true) {', 'community-render', false, 'Nachrendern speichert nur außerhalb einer offenen Transaktion (Autocommit)', false, []],
    'present-not-presentable' => [$render, '$presented = QuoteService::presentable($item, $decide);', '$presented = $item;', 'community-render', false, 'present(): kein body_html in der Ausgabe, body nur neutralisiert (Vertrag §1 Nr. 9)', false, []],
    'fallback-raw-body' => [$render, 'ContentRenderer::fallbackHtml(QuoteService::neutralize($body, $decide))', 'ContentRenderer::fallbackHtml($body)', 'community-render', false, 'Ausweichpfad gibt den Text nur neutralisiert aus (S2)', true, []],
    'decisions-skip-body-refs' => [$render, 'QuoteService::extractRefs(', '(static fn (string $b): array => [])(', 'community-render', false, 'die Entscheidungen umfassen die Markerzeilen des Textes (lesbare Zitate bleiben im Ausweichpfad lesbar)', false, []],
    'quote-lookups-unbounded' => [$render, 'array_slice($ids, 0, self::MAX_QUOTE_LOOKUPS)', '$ids', 'community-render', false, 'höchstens 200 Zitat-IDs je present() (feste Kosten je Seite, S9)', false, []],
    'quote-removed-shown' => [$render, '            if ($state === true) {' . "\n" . '                $source =', '            if ($state !== false) {' . "\n" . '                $source =', 'community-render', false, 'entfernte Quelle (ausgeblendet, gelöscht) erscheint als Platzhalter, nie mit Text', false, []],
    'quote-span-nested' => [$render, 'if ($open !== null && $depth === $open[\'depth\']) {', 'if ($open !== null) {', 'community-render', false, 'quoteSpans(): ein Zitat-Block endet am passenden </blockquote> (verschachtelte Blockzitate werden mit ersetzt)', false, []],
    'filter-unchecked' => [$render, 'is_string($filtered) ? $filtered : $html', '$filtered', 'community-render', false, 'Filter cms_community_render_post: Rückgabe ohne String ⇒ Eingang', false, []],

    // ── Vorschau (Vertrag §4.7 Nr. 5)
    'preview-read-only' => [$render, '$decision = ($this->can)($userId, self::PREVIEW_ACTIONS[$key], $target + [\'now\' => $now]);', '$decision = ($this->can)($userId, \'read\', [\'scope\' => \'global\', \'now\' => $now]);', 'community-render', false, 'Vorschau verlangt das Schreibrecht am Ziel (Schreibsperre ⇒ banned_write)', false, []],
    'preview-limit-denied' => [$render, 'if (!$decision->allowed && !in_array($decision->reason, TopicService::LIMIT_REASONS, true)) {', 'if (!$decision->allowed) {', 'community-render', false, 'Vorschau: Grenzgründe gelten als erlaubt (vorbereiten trotz Tageskontingent)', false, []],
    'preview-unthrottled' => [$render, '$gate = ($this->throttle)(\'preview\', $userId);', '$gate = [\'allowed\' => true];', 'community-render', false, 'Vorschau: Drossel preview (10 je 60 s) im Dienst', false, []],

    // ── Job (render §2.4)
    'job-all' => [$render, '$all ? ContentRenderer::VERSION + 1 : ContentRenderer::VERSION', 'ContentRenderer::VERSION', 'community-render', false, 'Job-Modus all erneuert auch aktuelle Zeilen', false, []],
    'job-window-unbounded' => [$render, '$until = min($after + self::STEP_WINDOW, $max);', '$until = $max;', 'community-render', false, 'ein Job-Schritt liest höchstens das ID-Fenster STEP_WINDOW', false, []],
    'job-chars-unbounded' => [$render, 'if ($last > $after && $chars + strlen($body) > self::STEP_MAX_CHARS) {', 'if (false) {', 'community-render', false, 'ein Job-Schritt endet nach STEP_MAX_CHARS Zeichen innerhalb des Fensters', false, []],
    'cron-ignores-done' => [$render, 'if (is_array($latest) && ($latest[\'status\'] ?? \'\') === BatchJobService::STATUS_DONE) {', 'if (false) {', 'community-render', false, 'cronTick(): ein fertiger Job dieser VERSION startet keinen neuen', false, []],

    // ── QuoteService: Zuordnung und Mengenabfragen (render §6.5, Vertrag §4.7 Nr. 9)
    'quote-removed' => [$quotes, "'hidden', 'deleted' => self::REMOVED,", "'hidden', 'deleted' => true,", 'community-render', false, 'ausgeblendete und gelöschte Quellen gelten als entfernt, nie als sichtbar', false, []],
    'quote-removed-before-allowed' => [$quotes, 'if (!$d->allowed) {', 'if (false) {', 'community-render', false, 'stateOf(): das Recht vor dem Status (ausgeblendet im nicht lesbaren Forum ⇒ nicht sichtbar, nie entfernt)', false, []],
    'quote-batch' => [$quotes, '$rows = $this->posts->findMany($ids);', '$rows = array_filter(array_map(fn (int $id): ?array => $this->posts->find($id), array_combine($ids, $ids)));', 'community-render', false, 'resolveMany(): eine findMany-Abfrage für alle Zitate einer Seite', false, []],
    'resolve-many-open' => [$quotes, "self::warn('resolveMany', \$e, count(\$ids));\n\n            return array_fill_keys(\$ids, false);", "self::warn('resolveMany', \$e, count(\$ids));\n\n            return array_fill_keys(\$ids, true);", 'community-render', false, 'resolveMany(): Datenbankfehler ⇒ alle Zitate nicht sichtbar (fail-closed)', false, []],
    'quotable-many-open' => [$quotes, "self::warn('quotableMany', \$e, count(\$ids));\n\n            return array_fill_keys(\$ids, false);", "self::warn('quotableMany', \$e, count(\$ids));\n\n            return array_fill_keys(\$ids, true);", 'community-render', false, 'quotableMany(): Datenbankfehler ⇒ nicht zitierbar (fail-closed)', false, []],
    'quotable-many-unchecked' => [$quotes, '$quotable[$id] = isset($found[$id]) && $this->quotableRow($found[$id], $userId, $now);', '$quotable[$id] = isset($found[$id]);', 'community-render', false, 'quotableMany(): je ID die Zitierregel (lesbar, veröffentlicht oder ausgeblendet für die Moderation)', false, []],

    // ── MariaDB (render §6.2)
    'render-race-no-guard' => [$posts, 'AND edit_count = ? AND rendered_version < ?', 'AND edit_count >= ? AND rendered_version < ?', 'community-db', true, 'Wettlauf Nachrendern gegen Bearbeiten: storeRendered schreibt nur bei unverändertem edit_count', false, ['CMS_TEST_DB_SECTIONS' => 'render-race']],
];
