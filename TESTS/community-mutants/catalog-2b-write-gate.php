<?php
/**
 * Katalog der Schutzschichten in den Schreibdiensten (PR #2b, Nachlauf): `Service\WriteGate` und die Verdrahtung in `TopicService::create()`,
 * `PostService::reply()` und `PostService::edit()`. Alle gegen die Suite `community-spam` (`part-services.php`).
 *
 * Gleiches Format wie `catalog.php`: Name => [Datei relativ zu CMS/, Suchtext, Ersatz, Suite, needsDb, Regel, quick, env]
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

return [
    'wg-context-dropped' => ['core/Services/Community/Service/WriteGate.php', '$context = [\'content\' => $check[\'content\']];', '$context = [];', 'community-spam', false, 'WriteGate: content (Zählung) geht in jedes can() des Vorgangs (Link-, Bild-Grenzen der Stufe)', true, []],
    'wg-review-not-forwarded' => ['core/Services/Community/Service/WriteGate.php', 'if ($screen !== null && ($screen[\'review\'] ?? false) === true) {', 'if (false) {', 'community-spam', false, 'WriteGate: review aus screen() geht in das can(fresh) (review ⇒ pending, content_review)', false, []],
    'wg-before-ignored' => ['core/Services/Community/Service/WriteGate.php', '$check[\'content_before\'] = self::measure($check[\'guard\'], $previousBody)[0];', '$check[\'content_before\'] = self::measure($check[\'guard\'], \'\')[0];', 'community-spam', false, 'WriteGate: content_before ist die Zählung der Vorfassung (vorhandener Inhalt zählt beim Bearbeiten nicht doppelt)', false, []],
    'wg-hosts-dropped' => ['core/Services/Community/Service/WriteGate.php', '\'content\' => $content, \'hosts\' => $hosts,', '\'content\' => $content, \'hosts\' => [],', 'community-spam', false, 'WriteGate: Stats.hosts gehen an screen() (evil%2Ecom trifft evil.com)', false, []],
    'wg-reject-not-logged' => ['core/Services/Community/Service/WriteGate.php', '$check[\'guard\']->logRejection($code, $userId);', 'null;', 'community-spam', false, 'WriteGate::reject() ruft logRejection() (Sicherheitsprotokoll nach dem Ende des Vorgangs)', false, []],
    'topic-screen-skipped' => ['core/Services/Community/Service/TopicService.php', '$screen = $this->gate->screen($check, $userId, \'create_topic\', [\'forum_id\' => $forumId], $nowTs);', '$screen = [\'ok\' => true, \'review\' => false, \'signals\' => [], \'ip_hash\' => null];', 'community-spam', false, 'TopicService::create() ruft screen() nach erlaubtem can() (word_blocked, spam_rejected, review)', true, []],
    'topic-review-not-fresh' => ['core/Services/Community/Service/TopicService.php', '$this->gate->context($check, $screen)', '$this->gate->context($check)', 'community-spam', false, 'TopicService: das can(fresh) unter den Sperren bekommt review (review ⇒ pending)', false, []],
    'topic-ip-hash-dropped' => ['core/Services/Community/Service/TopicService.php', '\'ip_hash\' => $screen[\'ip_hash\'] ?? null,', '\'ip_hash\' => null,', 'community-spam', false, 'TopicService::create() speichert screen()[ip_hash] am ersten Beitrag', false, []],
    'topic-replaced-length-unchecked' => ['core/Services/Community/Service/TopicService.php', '$body = $check[\'body\'];
            $error = $this->lengthError($title, $body);', '$body = $check[\'body\'];
            $error = null;', 'community-spam', false, 'TopicService::create() prüft die Längen nach dem Ersetzen erneut (title_too_short)', false, []],
    'topic-denial-not-logged' => ['core/Services/Community/Service/TopicService.php', '$this->gate->reject($check, $decision->reason, $userId);', 'null;', 'community-spam', false, 'TopicService::create() protokolliert Ablehnungen von can() (too_many_links, too_many_images)', false, []],
    'post-reply-screen-skipped' => ['core/Services/Community/Service/PostService.php', '$screen = $this->gate->screen($check, $userId, \'reply\', [\'topic_id\' => $topicId, \'forum_id\' => (int) ($this->topics->find($topicId)[\'forum_id\'] ?? 0)], $nowTs);', '$screen = [\'ok\' => true, \'review\' => false, \'signals\' => [], \'ip_hash\' => null];', 'community-spam', false, 'PostService::reply() ruft screen() nach erlaubtem can()', false, []],
    'post-edit-screen-skipped' => ['core/Services/Community/Service/PostService.php', '$screen = $this->gate->screen($check, $userId, \'edit\', [\'post_id\' => $postId], $nowTs);', '$screen = [\'ok\' => true, \'review\' => false, \'signals\' => [], \'ip_hash\' => null];', 'community-spam', false, 'PostService::edit() ruft screen() nach erlaubtem can()', false, []],
    'post-reply-ip-hash-dropped' => ['core/Services/Community/Service/PostService.php', '\'ip_hash\' => $screen[\'ip_hash\'] ?? null,', '\'ip_hash\' => null,', 'community-spam', false, 'PostService::reply() speichert screen()[ip_hash]', false, []],
    'post-reply-review-not-fresh' => ['core/Services/Community/Service/PostService.php', '[\'topic_id\' => $topicId, \'now\' => $nowTs, \'fresh\' => true] + $this->gate->context($check, $screen)', '[\'topic_id\' => $topicId, \'now\' => $nowTs, \'fresh\' => true] + $this->gate->context($check)', 'community-spam', false, 'PostService::reply(): das can(fresh) bekommt review (review ⇒ pending)', true, []],
    'post-edit-review-not-fresh' => ['core/Services/Community/Service/PostService.php', '[\'post_id\' => $postId, \'now\' => $nowTs, \'fresh\' => true] + $this->gate->context($check, $screen)', '[\'post_id\' => $postId, \'now\' => $nowTs, \'fresh\' => true] + $this->gate->context($check)', 'community-spam', false, 'PostService::edit(): das can(fresh) bekommt review (content_review bei veröffentlichten Beiträgen)', false, []],
    'post-edit-before-dropped' => ['core/Services/Community/Service/PostService.php', '$check = $previous === null ? $check : $this->gate->withBefore($check, (string) ($previous[\'body\'] ?? \'\'));', '$check = $check;', 'community-spam', false, 'PostService::edit() übergibt content_before (Korrektur scheitert nicht an vorhandenem Inhalt)', false, []],
];
