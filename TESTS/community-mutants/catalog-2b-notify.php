<?php
/**
 * Katalog der Einheit N (PR #2b, Welle 2): `Service\NotificationDispatcher`, `SubscriptionService`, `ReactionService`, `RankService`,
 * `Repository\SubscriptionRepository` und die Core-Teile `NotificationService::notifyGrouped()`/`markGroupRead()` sowie
 * `MailQueueService::enqueuePlainStrict()` laut Vertrag DESIGN-2b §4.5, §7.4 und Entwurf interaktion §6.4.
 *
 * Gleiches Format wie `catalog.php`: Name => [Datei relativ zu CMS/, Suchtext, Ersatz, Suite, needsDb, Regel, quick, env]
 *
 * Herkunft: interaktion §6.4 (`notify-*`, `token-*`, `unsubscribe-*`, `react-*`, `rank-*`, `core-*`), Vertrag §7.4 (`notify-claim-order`,
 * `notify-mail-quota`, `notify-report-optout`, `notify-moved-leak`, `ban-notice-unverified`, `core-hook-in-tx`, `mail-strict-swallow`;
 * Fassung 3: `notify-quote-extractrefs`, `notify-quote-unchecked`, `notify-reaction-first`, `notify-reaction-unthrottled`,
 * `mail-defang-off`, `summaries-unchecked`, `who-placeholder`). `notify-mutex` mit dem Suchtext aus §7.4 (`settingsForUpdate`).
 *
 * Abweichung: `notify-mutex`, `notify-claim-cas` und `core-group-cas` (im Entwurf Datenbank-Mutanten) erkennt die DB-freie Suite
 * deterministisch (Sperrfolge-Log, CAS mit geändertem Wert zwischen Lesen und Schreiben, Text zum neuen Zähler); sie zielen deshalb auf
 * `community-notify`. Die Wettläufe in `sections-notify.php` prüfen dieselben Regeln zusätzlich unter Last.
 *
 * Nicht hier: `react-published-only` (Katalog von A, `CommunityAccess.php`), `hook-report-resolved`, `hook-user-banned`,
 * `hook-topic-moved`, `hook-prune-throttle` (H in W3, `part-hooks.php`).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

$dispatcher = 'core/Services/Community/Service/NotificationDispatcher.php';
$subscriptions = 'core/Services/Community/Service/SubscriptionService.php';
$reactions = 'core/Services/Community/Service/ReactionService.php';
$ranks = 'core/Services/Community/Service/RankService.php';
$repo = 'core/Services/Community/Repository/SubscriptionRepository.php';
$core = 'core/Services/NotificationService.php';
$mailQueue = 'core/Services/MailQueueService.php';
$suite = 'community-notify';

return [
    // ── Empfänger (N1, N3)
    'notify-actor-self' => [$dispatcher, 'if ($uid === $actorId) {', 'if (false) {', $suite, false, 'Der Akteur erhält keinen Hinweis auf eigene Ereignisse', true, []],
    'notify-can-skipped' => [$dispatcher, '$decision->allowed && $decision->view === \'full\'', 'true', $suite, false, 'Je Empfänger can(read) mit view = full (Lecktest, Moderation ohne Leserecht allgemein)', true, []],
    'notify-inactive' => [$dispatcher, '(string) $user[\'status\'] === \'active\'', 'true', $suite, false, 'Nur aktive Konten erhalten Hinweise (inaktiv mit guests_can_read)', false, []],
    'notify-ignore' => [$dispatcher, 'isset($ignoring[$uid])', 'false', $suite, false, 'Wer den Akteur ignoriert, erhält nichts', false, []],
    'notify-ignore-moderation' => [$dispatcher, '$kind !== \'moderation\' && !$actorModerates', '!$actorModerates', $suite, false, 'Moderationshinweise ignorieren die Ignorieren-Liste nicht', false, []],
    'notify-already-read' => [$dispatcher, '$lastRead >= $postId', 'false', $suite, false, 'Schon gelesene Antworten erzeugen keinen Hinweis', false, []],
    // ── Tor und Mail (N2, N4, N5)
    'notify-gate-strict' => [$dispatcher, '$seen >= $expected', '$seen > $expected', $suite, false, 'Tor Thema: Besuch zur selben Sekunde wie die letzte Mail öffnet (>=)', false, []],
    'notify-forum-gate' => [$dispatcher, '$lastSeen >= $expected', 'false', $suite, false, 'Tor Forum: touchSeen() nach der letzten Mail öffnet', false, []],
    'notify-mail-none' => [$dispatcher, '$phase === self::PHASE_INSTANT', 'true', $suite, false, 'Mail nur in Phase 1 (Abos instant), nie für Abos none', true, []],
    'notify-mail-setting' => [$dispatcher, '$this->settings->bool(\'notify_mail\')', 'true', $suite, false, 'Einstellung notify_mail aus: keine Mail, kein Tor', false, []],
    'notify-mail-quota' => [$dispatcher, '$sent < self::MAIL_PER_RECIPIENT_DAY', 'true', $suite, false, 'Höchstens 50 Community-Mails je Empfänger und UTC-Tag', false, []],
    'notify-release-mail' => [$dispatcher, '$this->queue->releaseMail(', '(fn () => false)(', $suite, false, 'Fachlich abgelehnte Mail gibt das Tor frei (notified_at zurück)', false, []],
    'notify-claim-order' => [$dispatcher, "\$claimed = \$wantsMail && \$this->claim(\$uid, \$plan['target'], \$plan['expected'], \$nowUtc);\n            \$note = (\$this->notify)(\$uid, \$plan['type'], \$plan['group'], \$plan['texts']);", "\$note = (\$this->notify)(\$uid, \$plan['type'], \$plan['group'], \$plan['texts']);\n            \$claimed = \$wantsMail && \$this->claim(\$uid, \$plan['target'], \$plan['expected'], \$nowUtc);", $suite, false, 'claimMail (Ebene 7) vor dem Hinweis (Ebene 8), Sperrfolge §3.10', false, []],
    'notify-mutex' => [$dispatcher, '$this->stats->settingsForUpdate($uid)', '$this->stats->settingsMany([$uid])[$uid][\'settings\'] ?? []', $suite, false, 'Empfänger-Transaktion sperrt zuerst die Statistikzeile des Empfängers (Mutex)', false, []],
    // ── Warteschlange
    'notify-lease-check' => [$dispatcher, 'if (!$this->queue->advance(', 'if (false && !$this->queue->advance(', $suite, false, 'Cursor in der Empfänger-Transaktion; verlorene Lease nimmt den Hinweis zurück', false, []],
    'notify-expiry' => [$dispatcher, '$t0 - self::MAX_AGE_HOURS * 3600', '$t0', $suite, false, 'Aufträge verfallen erst nach 48 Stunden', false, []],
    'notify-reaction-first' => [$dispatcher, 'foreach (self::TIERS as $kinds) {', 'foreach ([array_merge(...self::TIERS)] as $kinds) {', $suite, false, 'Stufen: Moderation und Antworten vor Reaktionen (S8)', true, []],
    'notify-reaction-unthrottled' => [$dispatcher, 'if (!($this->throttle)(\'react_notify\', $userId)[\'allowed\']) {', 'if (false) {', $suite, false, 'Reaktionsaufträge nur innerhalb der Drossel react_notify (D3)', false, []],
    'notify-cursor-gte' => [$repo, 'AND user_id > ? ORDER BY user_id ASC', 'AND user_id >= ? ORDER BY user_id ASC', $suite, false, 'Keyset der Empfänger ausschließlich: kein Empfänger doppelt über Stapelgrenzen', false, []],
    'notify-claim-null' => [$repo, '\' AND notified_at IS NULL\'', '\'\'', $suite, false, 'Nie beanspruchtes Tor: CAS auf NULL (gleichzeitig beansprucht ⇒ keine zweite Mail)', false, []],
    'notify-claim-cas' => [$repo, '\' AND notified_at = ?\'', '\' AND ? IS NOT NULL\'', $suite, false, 'Beanspruchen per CAS auf den gelesenen Zeitpunkt', false, []],
    // ── Zitate (S7)
    'notify-quote-limit' => [$dispatcher, 'array_slice($refs, 0, self::MAX_QUOTES)', '$refs', $suite, false, 'Höchstens 10 Zitierte je Auftrag', false, []],
    'notify-quote-extractrefs' => [$dispatcher, 'ContentRenderer::instance()->analyze((string) $post[\'body\'])[\'stats\'][\'quotes\']', 'QuoteService::extractRefs((string) $post[\'body\'])', $suite, false, 'Zitierte nur aus Zitat-Blöcken (analyze), nie aus Code', false, []],
    'notify-quote-unchecked' => [$dispatcher, 'if (!$this->quotes->isQuotable($quotedId, $actorId)) {', 'if (false) {', $suite, false, 'Quelle muss für den Akteur zur Verarbeitungszeit zitierbar sein', false, []],
    // ── Moderation, Meldungen, Sperren (G1 bis G3)
    'notify-report-optout' => [$dispatcher, '(int) $report[\'notify_reporter\'] === 1', 'true', $suite, false, 'Rückmeldung nur mit notify_reporter = 1', false, []],
    'notify-moved-leak' => [$dispatcher, '$readable ? \'community.notify.moderation.moved\' : \'community.notify.moderation.generic\'', '\'community.notify.moderation.moved\'', $suite, false, 'Verschiebehinweis mit Titel nur bei Leserecht am Ziel', false, []],
    'ban-notice-unverified' => [$dispatcher, '$verified && filter_var($email, FILTER_VALIDATE_EMAIL) !== false', 'filter_var($email, FILTER_VALIDATE_EMAIL) !== false', $suite, false, 'Sperrmail nur an eine bestätigte Adresse', false, []],
    'mail-defang-off' => [$dispatcher, 'return array_map(self::defang(...), $vars);', 'return $vars;', $suite, false, 'Titel und Namen in Mails entschärft (S16)', false, []],
    // ── Abos und Abmeldelink (N6)
    'token-user-unbound' => [$subscriptions, '"v1|{$userId}|{$scope}"', '"v1|{$scope}"', $suite, false, 'Signatur bindet das Mitglied (vertauschter Benutzer ⇒ ungültig)', false, []],
    'unsubscribe-mode-none' => [$subscriptions, 'setMode($userId, $type, $targetId, \'none\')', 'delete($userId, $type, $targetId)', $suite, false, 'Abmelden per Link setzt none (In-App bleibt)', false, []],
    // ── Reaktionen (D3, S17)
    'react-set-filter' => [$reactions, 'in_array($reaction, $this->available(), true)', 'true', $suite, false, 'Nur Arten aus reaction_set', false, []],
    'react-topic-counter' => [$reactions, '$this->topics->bumpReactions($topicId, $delta)', '$this->topics->bumpReactions($topicId, 0)', $suite, false, 'topics.reaction_count folgt den Reaktionen', false, []],
    'react-lock-topic' => [$reactions, '$topic = $this->topics->lockForUpdate($topicId);', '$topic = $this->topics->find($topicId);', $suite, false, 'Sperrfolge Thema → Beitrag → Statistik', false, []],
    'summaries-unchecked' => [$reactions, 'if (($item[\'view\'] ?? null) !== \'full\') {', 'if (false) {', $suite, false, 'summaries() nur für Einträge mit view = full', false, []],
    'who-placeholder' => [$reactions, 'if (!$decision->allowed || $decision->view !== \'full\') {', 'if (!$decision->allowed) {', $suite, false, 'who() auf Platzhalter (ausgeblendet) ⇒ not_found', false, []],
    // ── Ränge
    'rank-boundary' => [$ranks, '$min <= $postCount', '$min < $postCount', $suite, false, 'Rang ab genau min_posts Beiträgen', false, []],
    'rank-reseed' => [$ranks, 'if ($this->stats->countRanks() === 0) {', 'if (true) {', $suite, false, 'Säen bei befüllter Tabelle setzt nur die Marke', false, []],
    // ── Core
    'core-group-cas' => [$core, 'AND is_read = 0 AND event_count = ?', 'AND is_read = 0 AND ? > 0', $suite, false, 'Bündelung per CAS auf den gelesenen Zähler (Text zum neuen Zähler)', false, []],
    'core-group-read-key' => [$core, 'WHERE user_id = ? AND group_key = ? AND is_read = 0"', 'WHERE user_id = ? AND ? <> \'\' AND is_read = 0"', $suite, false, 'markGroupRead() markiert nur die Gruppe', false, []],
    'core-hook-in-tx' => [$core, 'return [\'id\' => $id, \'inserted\' => true];', '$this->announceCreated($id, $userId, $type); return [\'id\' => $id, \'inserted\' => true];', $suite, false, 'notifyGrouped() löst keinen Hook aus (Hook erst nach dem Commit)', false, []],
    'mail-strict-swallow' => [$mailQueue, '$this->db->execute($sql, $params);', 'try { $this->db->execute($sql, $params); } catch (\\PDOException) { return 0; }', $suite, false, 'enqueuePlainStrict() reicht Datenbankfehler durch', true, []],
];
