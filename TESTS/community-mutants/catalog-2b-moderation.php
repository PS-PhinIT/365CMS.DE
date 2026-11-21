<?php
/**
 * Mutantenkatalog der Einheit M (PR #2b, W2): Moderation und Zählerbuchung (Entwurf moderation §6.4, Vertrag DESIGN-2b §7.4).
 * Die Suchtexte sind Pflichtschreibweisen und stehen in ihrer Datei genau einmal. Gleiches Format wie `catalog.php`:
 * `[Datei relativ zu CMS/, Suchtext, Ersatz, Suite, needsDb, Regel, quick, env, requires]`.
 *
 * Vergeben in anderen Katalogen (deshalb hier nicht): `mod-pin-count-ignores-until`, `mod-pin-count-published-only`, `mod-moved-posts-forum-kept`,
 * `stub-delete-any`, `expire-boundary`, `log-unknown-action`, `log-keyset-ties` (B1, community-repositories), `stats-lock-desc` (B2), `access-*`,
 * `move-target-invisible`, `scope-archived-dropped` (A). `reply-books-pending-topic` und `delete-log-author` legt T in W3 fest; die verschobenen
 * #2a-Mutanten (`post-delete-last-at-null`, `post-delete-last-not-recomputed`, `topic-delete-post-count`) zieht J3 nach `CounterBook.php`.
 * Ohne DB-Variante, mit Grund: `db-mod-move-forums-desc` (moderation §6.4) ist für Deadlocks äquivalent. Alle Pfade sortieren dasselbe
 * Forenpaar mit derselben Hilfe `ascending()`; eine absteigende Folge bleibt in sich gleich und erzeugt in `race-move-cross` keinen Zyklus
 * (Probe: 20 Runden ohne Fehler). Die Regel „aufsteigend“ prüft `community-moderation` an der Sperrfolge (`mod-move-forums-desc`).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

$mod = 'core/Services/Community/Service/ModerationService.php';
$book = 'core/Services/Community/Service/CounterBook.php';
$stats = 'core/Services/Community/Repository/UserStatsRepository.php';
$suite = 'community-moderation';

return [
    // ── ModerationService (moderation §6.4) ──
    'mod-pin-limit-off-by-one' => [$mod, 'if ($pins >= TopicRepository::MAX_PINNED) {', 'if ($pins > TopicRepository::MAX_PINNED) {', $suite, false, 'Anheften: das 51. wirksame Pin eines Forums wird abgelehnt (pin_limit)', false, []],
    'mod-reason-not-required' => [$mod, 'if ($required && $reason === null) {', 'if (false && $reason === null) {', $suite, false, 'Pflichtgrund bei post.hide, post.reject, topic.hide, topic.reject (moderation_reason_required)', true, []],
    'mod-stub-always' => [$mod, 'if ($stub && $topic[\'status\'] === \'published\') {', 'if ($stub) {', $suite, false, 'Verweis-Thema nur für veröffentlichte Themen', false, []],
    'mod-stub-days-hours' => [$mod, 'int(\'moved_stub_days\') * 86400', 'int(\'moved_stub_days\') * 3600', $suite, false, 'Ablauf des Verweises in Tagen (moved_stub_days × 86400 s)', false, []],
    'mod-move-forums-desc' => [$mod, 'return $a < $b ? [$a, $b] : [$b, $a];', 'return $a > $b ? [$a, $b] : [$b, $a];', $suite, false, 'Sperrfolge beim Verschieben: Foren und Themen aufsteigend nach ID', false, []],
    'mod-move-posts-forum-kept' => [$mod, '$this->posts->setTopicForum($topicId, $targetId);', '', $suite, false, 'Thema verschieben: alle Beiträge tragen das Zielforum (posts.forum_id = topics.forum_id)', false, []],
    'mod-restore-author-deleted' => [$mod, '(string) $post[\'status_reason\'] === \'moderator\'', 'true', $suite, false, 'Wiederherstellen: vom Mitglied Gelöschtes bleibt gelöscht (I-20)', false, []],
    'mod-restore-always-published' => [$mod, '(string) $first[\'status\'] === \'pending\' ? \'pending\' : \'published\'', '\'published\'', $suite, false, 'ein nie freigegebenes Thema kehrt nach pending zurück', false, []],
    'mod-bulk-limit' => [$mod, 'if (count($ids) > self::MAX_BULK) {', 'if (count($ids) > self::MAX_BULK + 1) {', $suite, false, 'Sammelaktion: höchstens 100 Ziele (bulk_limit ohne Anweisung)', false, []],
    'mod-bulk-stops-on-error' => [$mod, '$failed++;', '$failed++; break;', $suite, false, 'Sammelaktion: ein Fehler bricht die übrigen Elemente nicht ab', false, []],
    'mod-log-skipped' => [$mod, '$logId = $this->log->add($entry);', '$logId = 0;', $suite, false, 'Protokolleintrag je Aktion als letzte Anweisung der Transaktion', true, []],
    'mod-queue-all-forums' => [$mod, '$forumIds = $scope[\'global\'] ? null : $scope[\'forum_ids\'];', '$forumIds = null;', $suite, false, 'Warteschlange: Forum-Moderatoren sehen nur ihre Foren', false, []],

    // ── ModerationService (Vertrag §7.4, Nacharbeit) ──
    'moveposts-decoy' => [$mod, '$sourceId = $mixed ? CommunityAccess::DECOY_ID : $sourceId;', '', $suite, false, '404-Regel: fehlende oder gemischte Beiträge laufen als Köder-ID durch can()', true, []],
    'iphash-capability' => [$mod, 'if (!$gate->allowed) { // view_private_data', 'if (false) {', $suite, false, 'ip_hash nur mit community.view_private_data', false, []],
    'reassign-before-stats' => [$mod, '$this->stats->lockMany($solutionPlan, $now);', '$this->reports->reassignPosts($ids, $targetForumId); $this->stats->lockMany($solutionPlan, $now);', $suite, false, 'Sperrfolge: reassign* der Meldungen erst nach allen Sperren der Ebenen 1 bis 5', false, []],

    // ── ModerationService (weitere Regeln dieser Einheit) ──
    'queue-raw-without-render' => [$mod, 'QuoteService::presentable($item, static fn (int $id): bool => false)', '$item', $suite, false, 'Warteschlange ohne Renderer: kein body_html, kein Rohtext fremder Zitate (fail-closed, §1 Nr. 9)', false, []],
    'mod-move-old-stub-kept' => [$mod, 'foreach ($this->topics->stubIdsFor($targetId, $topicId) as $oldStub) {', 'foreach ([] as $oldStub) {', $suite, false, 'Zurückverschieben löscht den alten Verweis im Ziel auf dieses Thema', false, []],
    'mod-move-reports-kept' => [$mod, '$this->reports->reassignTopic($topicId, $targetId);', '', $suite, false, 'offene Meldungen folgen dem verschobenen Thema (reassignTopic)', false, []],
    'mod-reject-repeat-denied' => [$mod, 'if ($action === \'topic.reject\' && $decision->reason === \'topic_deleted\') {', 'if (false) {', $suite, false, 'Idempotenz: wiederholtes Ablehnen eines Themas ergibt changed = false statt topic_deleted', false, []],
    'mod-move-repeat-denied' => [$mod, 'if ($decision->reason === \'invalid_target\') {', 'if (false) {', $suite, false, 'Idempotenz: wiederholtes Verschieben ins aktuelle Forum ergibt changed = false nach erlaubtem Rechte-Tor', false, []],
    'mod-system-restore-any' => [$mod, 'if ($allowed && $actorId === null && !$this->autoHidden(\'post\', $postId)) {', 'if (false) {', $suite, false, 'systemAction(restore): nur automatisch ausgeblendete Beiträge', false, []],
    'mod-system-in-transaction' => [$mod, 'if ($this->db->getPdo()->inTransaction()) {', 'if (false) {', $suite, false, 'systemAction nie innerhalb einer Transaktion (Hooks nach dem Commit)', false, []],
    'mod-iphash-scope' => [$mod, 'if (in_array((int) $row[\'forum_id\'], $scope[\'forum_ids\'], true)) {', 'if (true) {', $suite, false, 'ipHashes: nur Beiträge im Moderationsbereich', false, []],
    'mod-solution-log-own' => [$mod, '$foreign = $starter !== $actorId;', '$foreign = true;', $suite, false, 'Lösung: Protokoll nur, wenn der Handelnde nicht der Themenstarter ist', false, []],
    'mod-hide-solution-hook' => [$mod, '$hooks[] = [Events::SOLUTION_UNMARKED, $topicId, $postId, $actor];', '', $suite, false, 'Ausblenden der Lösung löst solution_unmarked aus', false, []],
    'mod-covers-skipped' => [$mod, 'if (!$this->book->covers($topicId, $plan)) {', 'if (false) {', $suite, false, 'Planprüfung unter der Sperre: neuer Autor ⇒ neuer Versuch', false, []],
    'mod-plan-forum-unchecked' => [$mod, 'if ((int) $topic[\'forum_id\'] !== $forumId) {', 'if (false) {', $suite, false, 'Planprüfung unter der Sperre: Forumswechsel ⇒ neuer Versuch', false, []],
    'mod-expire-no-touch' => [$mod, '$this->forums->touch($forumIds, $now);', '', $suite, false, 'expire(): version der betroffenen Foren + 1 (Cache der Listen)', false, []],
    'mod-move-plan-unchecked' => [$mod, 'if ((int) $topic[\'forum_id\'] !== $sourceId) {', 'if (false) {', $suite, false, 'Thema verschieben: Forumswechsel zwischen Plan und Sperre ⇒ neuer Versuch', false, []],
    'mod-moveposts-plan-unchecked' => [$mod, 'if ((int) $source[\'forum_id\'] !== $sourceForumId || ', 'if (false && (int) $source[\'forum_id\'] !== $sourceForumId || ', $suite, false, 'Beiträge verschieben: Forumswechsel der Quelle zwischen Plan und Sperre ⇒ neuer Versuch', false, []],
    'mod-moveposts-solution-unchecked' => [$mod, '|| (int) ($source[\'solution_post_id\'] ?? 0) !== $plan[\'solution\'] ||', '|| false ||', $suite, false, 'Beiträge verschieben: Lösungswechsel zwischen Plan und Sperre ⇒ neuer Versuch mit gesperrtem Lösungsautor', false, []],
    'mod-moveposts-left-unchecked' => [$mod, 'if ((int) $post[\'topic_id\'] !== $plan[\'source\']) {', 'if (false) {', $suite, false, 'Beiträge verschieben: jeder Beitrag liegt unter der Sperre noch in der Quelle', false, []],
    'mod-post-plan-unchecked' => [$mod, 'if ((int) $post[\'topic_id\'] !== $topicId || ($author !== null && $lockedAuthor !== $author)) {', 'if (false) {', $suite, false, 'Statuswechsel einer Antwort: Ortswechsel zwischen Lesen und Sperre ⇒ neuer Versuch', false, []],
    'mod-stub-before-reassign' => [$mod, '$this->reports->reassignTopic($topicId, $targetId);', 'foreach ($this->topics->stubIdsFor($targetId, $topicId) as $early) { $this->topics->deleteStub($early); } $this->reports->reassignTopic($topicId, $targetId);', $suite, false, 'Sperrfolge: Verweis-Themen erst nach dem Umhängen der Meldungen (Vertrag §3.10)', false, []],

    // ── CounterBook (moderation §6.4, Vertrag §7.4) ──
    'book-counts-any-topic' => [$book, 'return (string) $topic[\'status\'] === \'published\';', 'return true;', $suite, false, 'S1: Forum und Mitglied zählen nur Beiträge in veröffentlichten Themen', true, []],
    'book-double-booking' => [$book, '$this->refreshForum((int) $forum[\'id\'], 1, $authors[\'total\'], $nowUtc);', '$this->refreshForum((int) $forum[\'id\'], 1, $authors[\'total\'] + (int) $topic[\'reply_count\'], $nowUtc);', $suite, false, 'Freigabe eines Themas: keine Doppelbuchung der Moderationsantworten', false, []],
    'book-last-not-recomputed' => [$book, '$last = $this->posts->lastPublishedInTopic((int) $topic[\'id\']);', '$last = null;', $suite, false, 'last_* des Themas nach jedem Statuswechsel neu bestimmt', false, []],
    'book-one-member-only' => [$book, 'foreach ($members as $userId) {', 'foreach (array_slice($members, 0, 1) as $userId) {', $suite, false, 'Themenstatuswechsel bucht alle Autoren (S1)', false, []],
    'book-solution-kept' => [$book, '$cleared = (int) ($topic[\'solution_post_id\'] ?? 0) === (int) $post[\'id\'];', '$cleared = false;', $suite, false, 'Invariante: die Lösung ist ein veröffentlichter Beitrag (Ausblenden hebt sie auf)', false, []],
    'book-solutions-count' => [$book, '$userId === $authors[\'solution_user\'] ? $sign : 0', '0', $suite, false, 'solutions_count beim Themenstatuswechsel', false, []],
    'book-moved-all-count' => [$book, 'static fn (array $p): bool => (string) $p[\'status\'] === \'published\'', 'static fn (array $p): bool => true', $suite, false, 'Beiträge verschieben: nur veröffentlichte zählen', false, []],
    'book-prepare-skipped' => [$book, '$this->stats->ensureMany($userIds, $nowUtc);', '', $suite, false, 'Statistikzeilen entstehen vor BEGIN (kein INSERT unter Sperren)', false, []],
    'counter-ensure-in-tx' => [$book, '$this->stats->bumpLocked(', '$this->stats->bump(', $suite, false, 'CounterBook bucht nur über bumpLocked (INSERT IGNORE nur vor BEGIN)', false, []],
    'postsmoved-reactions' => [$book, '$this->topics->bumpReactions((int) $toTopic[\'id\'], $reactions, $nowUtc);', '', $suite, false, 'Beiträge verschieben: Σ reaction_count wandert zum Ziel (Orakel)', false, []],
    'attachments-delta-never' => [$book, 'if ($lostAttachment) {', 'if (false) {', $suite, false, 'has_attachments: Verlust eines Beitrags mit Anhang bestimmt das Kennzeichen neu (Orakel)', false, []],
    'book-attachment-gain-skipped' => [$book, 'if ($gained && (int) ($topic[\'has_attachments\'] ?? 0) !== 1) {', 'if (false) {', $suite, false, 'has_attachments: ein veröffentlichter Beitrag mit Anhang setzt das Kennzeichen', false, []],
    'book-moved-topic-counts-always' => [$book, '$topics = self::counts($topic) ? 1 : 0;', '$topics = 1;', $suite, false, 'Thema verschieben: nur ein veröffentlichtes Thema wandert in den Forenzählern', false, []],

    // ── MariaDB (community-db, sections-moderation.php) ──
    'db-mod-pin-forum-unlocked' => [$mod, '$forum = $this->forums->lockForUpdate($forumId);', '$forum = $this->forums->find($forumId);', 'community-db', true, 'Anheften unter der Forensperre: von 8 gleichzeitigen bei 49 Pins genau einer', false, ['CMS_TEST_DB_SECTIONS' => 'race-pin-limit']],
    'db-mod-lockmany-rows' => [$stats, '$missing = array_values(array_diff($chunk, $this->userIds($sql, $chunk)));', '$shuffled = $chunk; shuffle($shuffled); foreach ($shuffled as $one) { $this->lockForUpdate($one, $nowUtc); usleep(2000); } $missing = [];', 'community-db', true, 'Statistikzeilen als IN-Sperre je Stück in Schlüsselfolge (zeilenweise in fremder Folge: Deadlocks)', false, ['CMS_TEST_DB_SECTIONS' => 'race-hide-overlap']],
];
