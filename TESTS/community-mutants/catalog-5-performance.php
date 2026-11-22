<?php
/**
 * Katalog PR #5 (Leistung und SEO): Lesebereich (`ReadScope`) und Abfragebudget der Seiten (Suite `community-performance`), Meta-Beschreibung der
 * Seiten (Suite `community-lighthouse`). Die Suite `community-a11y` prüft Vorlagen, Stile und Skripte (keine erlaubten Mutanten-Ziele) und
 * steht in `CM_EXCLUDED_SUITES` des Treibers.
 *
 * Gleiches Format wie `catalog.php`: Name => [Datei relativ zu CMS/, Suchtext, Ersatz, Suite, needsDb, Regel, quick, env]
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

$controller = 'core/Services/Community/Http/PublicController.php';
$scope = 'core/Services/Community/Support/ReadScope.php';
$member = 'core/Services/Community/CommunityMemberSection.php';

return [
    // ── Lesebereich ─────────────────────────────────────────────────────────────────────────────────────────────
    'scope-posts-not-forgotten' => [$scope, "'community_posts' => ['post'],", "'community_posts' => [],", 'community-performance', false, 'Ein Schreiben in die Beiträge verwirft die gemerkten Beitragszeilen (sonst zeigt die Seite alte Zeilen)', true, []],
    'scope-null-not-remembered' => [$scope, 'if (!array_key_exists($key, self::$memo)) {', 'if (!isset(self::$memo[$key])) {', 'community-performance', false, 'Auch eine fehlende Zeile (null) wird im Lesebereich gemerkt: eine Abfrage, nicht eine je Aufruf', false, []],
    'scope-memo-kept' => [$scope, "if (--self::\$depth === 0) {\n                self::\$memo = [];\n            }", '--self::$depth;', 'community-performance', false, 'Nach dem Ende des äußeren Bereichs ist alles vergessen: die nächste Anfrage liest neu', false, []],
    'scope-key-ignores-id' => [$scope, "return spl_object_id(\$db) . ':' . \$what . ':' . \$id;", "return spl_object_id(\$db) . ':' . \$what;", 'community-performance', false, 'Der Schlüssel enthält die ID: jede Zeile bleibt ihre eigene', false, []],
    'scope-key-ignores-connection' => [$scope, "return spl_object_id(\$db) . ':' . \$what . ':' . \$id;", "return \$what . ':' . \$id;", 'community-performance', false, 'Der Schlüssel enthält die Verbindung: zwei Verbindungen teilen keine Zeilen', false, []],
    'scope-unreadable-keeps-memo' => [$scope, "            self::\$memo = [];\n\n            return;\n        }\n        \$table = strtolower(\$match[1]);", "            return;\n        }\n        \$table = strtolower(\$match[1]);", 'community-performance', false, 'Eine Anweisung, die sich nicht lesen lässt, verwirft alles (sicherer Standard)', false, []],
    'scope-put-outside' => [$scope, "if (self::\$depth > 0) {\n            self::\$memo[\$key] = \$value;", "if (true) {\n            self::\$memo[\$key] = \$value;", 'community-performance', false, 'Außerhalb eines Bereichs wird nichts abgelegt (sonst bliebe es bis zur nächsten Anfrage)', false, []],
    'repo-write-keeps-rows' => ['core/Services/Community/Repository/AbstractRepository.php', 'ReadScope::forget($sql);', '', 'community-performance', false, 'Jede Schreibanweisung eines Repositories verwirft die gemerkten Zeilen ihrer Tabelle', true, []],
    'topic-find-no-scope' => ['core/Services/Community/Repository/TopicRepository.php', "ReadScope::key(\$this->db, 'topic', \$id),", "ReadScope::key(\$this->db, 'topic', 0),", 'community-performance', false, 'TopicRepository::find() merkt je ID eine Zeile (kein gemeinsamer Schlüssel für alle Themen)', false, []],
    'post-list-no-prime' => ['core/Services/Community/Repository/PostRepository.php', 'ReadScope::put(ReadScope::key($this->db, \'post\', (int) $row[\'id\']), $row);', '', 'community-performance', false, 'listPublished() legt die Zeilen der Seite vor: sonst liest die Seite jeden Beitrag einzeln (N+1)', false, []],
    'controller-topic-no-scope' => [$controller, 'ReadScope::run(fn () => $this->topicPage($ref, $pageSegment))', '$this->topicPage($ref, $pageSegment)', 'community-performance', false, 'Die Themenseite läuft im Lesebereich', true, []],
    'controller-index-no-scope' => [$controller, 'ReadScope::run(fn () => $this->indexPage())', '$this->indexPage()', 'community-performance', false, 'Die Startseite läuft im Lesebereich', false, []],
    'controller-forum-no-scope' => [$controller, 'ReadScope::run(fn () => $this->forumPage($slug, $pageSegment))', '$this->forumPage($slug, $pageSegment)', 'community-performance', false, 'Die Forumsseite läuft im Lesebereich', false, []],
    'controller-search-no-scope' => [$controller, 'ReadScope::run(fn () => $this->searchPage())', '$this->searchPage()', 'community-performance', false, 'Die Suche läuft im Lesebereich', false, []],

    // ── Mitgliederbereich: je Seite eine Abfrage statt je Zeile ────────────────────────────────────────────────
    'member-posts-per-row' => [$member, "\$topic = \$titles[(int) \$row['topic_id']] ?? null;", "\$topic = \$topics->find((int) \$row['topic_id']);", 'community-performance', false, 'Meine Antworten liest die Themen der Seite in einer Sammelabfrage, nicht eines je Zeile', true, []],
    'member-subs-topic-per-row' => [$member, "\$row = \$isTopic ? (\$topicRows[(int) \$item['target_id']] ?? null) : (\$forums[(int) \$item['target_id']] ?? null);", "\$row = \$isTopic ? \$topics->find((int) \$item['target_id']) : (\$forums[(int) \$item['target_id']] ?? null);", 'community-performance', false, 'Abos liest die Themen der Seite in einer Sammelabfrage', false, []],
    'topic-find-many-chunk' => ['core/Services/Community/Repository/TopicRepository.php', "foreach (array_chunk(\$ids, self::CHUNK) as \$chunk) {\n            foreach (\$this->fetchAll('SELECT * FROM '", "foreach (array_chunk(\$ids, 1) as \$chunk) {\n            foreach (\$this->fetchAll('SELECT * FROM '", 'community-performance', false, 'findMany() liest die Themen in Stücken zu CHUNK IDs, nicht einzeln', false, []],

    // ── Meta-Beschreibung (SEO) ─────────────────────────────────────────────────────────────────────────────────
    'meta-description-missing' => [$controller, "if (!empty(\$meta['description']) && empty(\$meta['noindex'])) {", 'if (false) {', 'community-lighthouse', false, 'Indexierbare Seiten tragen eine Meta-Beschreibung (Lighthouse-SEO)', true, []],
    'meta-description-on-noindex' => [$controller, "if (!empty(\$meta['description']) && empty(\$meta['noindex'])) {", "if (!empty(\$meta['description'])) {", 'community-lighthouse', false, 'Seiten ohne Indexwert (noindex) tragen keine Beschreibung', true, []],
    'meta-description-unescaped' => [$controller, "content=\"' . \$e((string) \$meta['description']) . '\">'", "content=\"' . (string) \$meta['description'] . '\">'", 'community-lighthouse', false, 'Die Beschreibung ist maskiert (kein Ausbruch aus dem Attribut)', true, []],
    'excerpt-keeps-markup' => [$controller, "html_entity_decode(strip_tags(\$text), ENT_QUOTES | ENT_HTML5, 'UTF-8')", "html_entity_decode(\$text, ENT_QUOTES | ENT_HTML5, 'UTF-8')", 'community-lighthouse', false, 'Die Beschreibung enthält kein Markup aus dem Beitrag', true, []],
    'excerpt-not-truncated' => [$controller, "if (mb_strlen(\$text, 'UTF-8') <= \$max) {", "if (mb_strlen(\$text, 'UTF-8') <= \$max * 3) {", 'community-lighthouse', false, 'Die Beschreibung ist höchstens 155 Zeichen lang', false, []],
    'excerpt-mid-word' => [$controller, "if (\$space !== false && \$space > (int) (\$max * 0.6)) {", 'if (false) {', 'community-lighthouse', false, 'Die Beschreibung wird an einer Wortgrenze gekürzt', false, []],
    'topic-description-empty' => [$controller, "'description' => self::excerpt((string) (array_values(array_filter(\$items, static fn (array \$p): bool => (\$p['view'] ?? '') === 'full'))[0]['html'] ?? '')),", "'description' => self::excerpt(''),", 'community-lighthouse', false, 'Die Beschreibung eines Themas ist der Anfang des ersten gezeigten Beitrags', false, []],
    'forum-description-ignored' => [$controller, "'description' => self::excerpt(\$this->localized(\$forum, 'description', \$locale)) ?:", "'description' => '' ?:", 'community-lighthouse', false, 'Die eigene Beschreibung eines Forums hat Vorrang vor dem Ersatztext', false, []],
];
