<?php
/**
 * Katalog der Einheit K (PR #2b, Welle 2): `Service\ReportService` und `Service\BanService` laut Vertrag DESIGN-2b §4.4, §7.4 und
 * Entwurf sanktionen §6.5.
 *
 * Gleiches Format wie `catalog.php`: Name => [Datei relativ zu CMS/, Suchtext, Ersatz, Suite, needsDb, Regel, quick, env]
 *
 * Herkunft: sanktionen §6.5 (`report-*`, `ban-*` der Dienste und die Datenbank-Mutanten), Vertrag §7.4 (`report-lock-topic`,
 * `report-recheck`, `resolve-scope-first`, `resolve-outside-same`, `lift-gate-first`; Fassung 3: `lift-no-rank`, `lift-issuer-rank`,
 * `ban-active-foreign`) und `ban-equal-rank` (Suchtext in `CommunityAccess.php`, Hinweis von A und J1). `resolve-no-lock` zielt auf
 * `ReportRepository.php` mit dem Suchtext aus `lockOpenByIds()` (Vertrag §3.7, Hinweis von B2 und J1). Die übrigen Zeilen
 * (`report-no-fresh-can` bis `lift-rights-not-fresh`) sichern Regeln dieser Einheit ohne Vorlage.
 *
 * Nicht hier: `ban-temp-cap-ban`, `member-report-self`, `member-report-inactive`, `authority-manage-rank`, `moderated-banned-sees`,
 * `mod-assign-no-groups` (Katalog von A), `report-lock-closed`, `report-count-old`, `ban-lift-repeat` (Katalog von B2),
 * `moderated-no-children` (entfällt, Vertrag §7.4). `race-report-threshold` und `race-report-move` folgen in W4.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

$report = 'core/Services/Community/Service/ReportService.php';
$ban = 'core/Services/Community/Service/BanService.php';
$access = 'core/Services/Community/Service/CommunityAccess.php';
$reportRepo = 'core/Services/Community/Repository/ReportRepository.php';
$suite = 'community-sanctions';

return [
    // ── Melden (M8, M9)
    'report-limit-off-by-one' => [$report, 'if ($recent[\'count\'] >= self::MAX_PER_HOUR) {', 'if ($recent[\'count\'] > self::MAX_PER_HOUR) {', $suite, false, 'Meldegrenze: die 11. Meldung in 3600 s ist rate_limited', true, []],
    'report-window-exclusive' => [$report, '$nowTs - self::LIMIT_WINDOW)', '$nowTs - self::LIMIT_WINDOW + 1)', $suite, false, 'Fenster der Meldegrenze einschließlich: eine Meldung genau vor 3600 s zählt', false, []],
    'report-no-merge' => [$report, '$open = $this->reports->openOfReporter($reporterId, $targetType, $targetId);', '$open = null;', $suite, false, 'Zweite Meldung desselben Melders zum selben Ziel wird zusammengeführt (keine Zeile, kein Hook)', false, []],
    'report-hidden-reportable' => [$report, 'if ((string) $post[\'status\'] !== \'published\' ||', 'if (false ||', $suite, false, 'Nur veröffentlichte Beiträge sind meldbar (ausgeblendet, gelöscht, wartend ⇒ not_reportable)', false, []],
    'report-lock-topic' => [$report, '$this->topics->lockForUpdate($topicId);', '$this->topics->find($topicId);', $suite, false, 'create sperrt bei post/topic zuerst das Thema, dann die Statistik des Melders (K43, Sperrfolge)', true, []],
    'report-recheck' => [$report, 'if ((int) $post[\'topic_id\'] !== $topicId) {', 'if (false) {', $suite, false, 'Beitrag zwischen Lesen und Sperre verschoben ⇒ neuer Versuch, die Meldung trägt das aktuelle Forum', false, []],
    'report-no-fresh-can' => [$report, '($this->can)($reporterId, \'report\', $context + [\'fresh\' => true])', 'AccessDecision::allow()', $suite, false, 'can(fresh) unter den Sperren: eine Sperre zwischen Vorprüfung und Transaktion wirkt', false, []],
    'report-merge-overlength' => [$report, 'mb_strlen($merged, \'UTF-8\') <= self::MESSAGE_MAX', 'true', $suite, false, 'Zusammenführen hängt nur bis MESSAGE_MAX Zeichen an', false, []],
    'report-notify-ignored' => [$report, '\'notify_reporter\' => $notify ? 1 : 0,', '\'notify_reporter\' => 1,', $suite, false, 'notify_reporter speichert den Wunsch des Melders', false, []],

    // ── Schwelle (M10)
    'report-trust-any' => [$report, '>= self::TRUST_MIN', '>= 0', $suite, false, 'Schwelle zählt nur Melder ab Stufe 2', false, []],
    'report-threshold-zero-on' => [$report, '$threshold > 0 &&', '$threshold >= 0 &&', $suite, false, 'report_hide_threshold 0 = nie automatisch ausblenden', false, []],
    'report-threshold-off-by-one' => [$report, '$trusted >= $threshold', '$trusted > $threshold', $suite, false, 'Schwelle erreicht bei genau report_hide_threshold vertrauenswürdigen Meldern', false, []],
    'report-staff-not-protected' => [$report, '$authorAuthority < CommunityAccess::AUTHORITY_MODERATOR', '$authorAuthority <= CommunityAccess::AUTHORITY_ADMIN', $suite, false, 'Inhalte der Moderation und Administration blendet die Schwelle nicht aus', false, []],

    // ── Erledigen und Listen (M12, E3)
    'resolve-scope-first' => [$report, 'if (!$scope[\'global\'] && $scope[\'forum_ids\'] === []) {', 'if (false) {', $suite, false, 'Ohne Moderationsbereich no_permission vor jeder Abfrage der Meldung (404-Regel)', false, []],
    'resolve-outside-same' => [$report, 'return self::failure(\'report_not_found\'); // außerhalb', 'return self::failure(\'no_permission\');', $suite, false, 'Außerhalb des Bereichs wie fehlend: report_not_found', false, []],
    'report-resolve-any-forum' => [$report, 'in_array($forumId, $forums, true)', 'true', $suite, false, 'Forum-Moderatoren erledigen nur Meldungen ihrer Foren (aktuelles Forum des Ziels)', true, []],
    'report-resolve-own-target' => [$report, '$ownerId === $moderatorId', '$ownerId === -1', $suite, false, 'Über eigene Ziele entscheidet die Moderation nicht (no_permission)', false, []],
    'report-restore-not-only-invalid' => [$report, '$status === \'invalid\' && !$remaining', '$status !== \'valid\' && !$remaining', $suite, false, 'Nur invalid ohne offene Restmeldung stellt eine automatische Ausblendung wieder her', false, []],
    'report-list-all-forums' => [$report, '$this->reports->openBundles($forums,', '$this->reports->openBundles(null,', $suite, false, 'listOpen zeigt Forum-Moderatoren nur ihre Foren und keine Mitgliedermeldungen', false, []],
    'report-resolve-upto-ignored' => [$report, '$this->reports->openIdsForTarget($type, $targetId, $upToReportId, ReportRepository::BATCH + 1)', '$this->reports->openIdsForTarget($type, $targetId, null, ReportRepository::BATCH + 1)', $suite, false, 'resolve erledigt nur bis upToReportId; neuere Meldungen bleiben offen', false, []],
    'report-list-filter-unchecked' => [$report, 'if ($forumFilter !== null && !in_array($forumFilter, $scope[\'forum_ids\'], true)) {', 'if (false) {', $suite, false, 'listOpen: Forenfilter außerhalb des Bereichs ⇒ not_found (keine fremden Bündel)', false, []],
    'report-count-all-forums' => [$report, '$this->reports->countOpen($scope[\'global\'] ? null : $scope[\'forum_ids\'], self::COUNT_CAP)', '$this->reports->countOpen(null, self::COUNT_CAP)', $suite, false, 'countOpen zählt nur Meldungen im Moderationsbereich', false, []],

    // ── Sperren (M11)
    'ban-equal-rank' => [$access, 'return $actor !== null && $target !== null && $actor > $target;', 'return $actor !== null && $target !== null && $actor >= $target;', $suite, false, 'Gleichrangige sperren einander nicht (outranks echt größer)', false, []],
    'ban-permanent-moderator' => [$ban, '$needsBan = $durationSeconds === null || $durationSeconds > $maxModerator;', '$needsBan = $durationSeconds !== null && $durationSeconds > $maxModerator;', $suite, false, 'Dauerhafte Sperren nur mit community.ban', true, []],
    'ban-max-days-ignored' => [$ban, '$maxModerator = $this->settings->int(\'moderator_ban_max_days\') * 86400;', '$maxModerator = self::MAX_DURATION;', $suite, false, 'moderator_ban_max_days begrenzt befristete Sperren der Moderation', false, []],
    'ban-cover-permanent-ignored' => [$ban, 'if ($existing[\'ends_at\'] === null) {', 'if (false) {', $suite, false, 'Eine dauerhafte Sperre deckt jede gleich strenge neue (already_banned)', false, []],
    'ban-cover-scope' => [$ban, '(self::SCOPE_RANK[$existing[\'scope\']] ?? 2) < self::SCOPE_RANK[$scope]', '(self::SCOPE_RANK[$existing[\'scope\']] ?? 2) > self::SCOPE_RANK[$scope]', $suite, false, 'Deckung nur bei mindestens gleich strengem Umfang (write deckt full nicht)', false, []],
    'ban-duplicate-any-actor' => [$ban, '$existing[\'issued_by\'] !== $actorId ||', 'false ||', $suite, false, 'duplicate nur für denselben Akteur, sonst already_banned', false, []],
    'ban-fresh-gate-skipped' => [$ban, '$gate = $this->banGate($actorId, $durationSeconds, [\'fresh\' => true, \'now\' => $nowTs]);', '$gate = null;', $suite, false, 'ban prüft Rechte unter der Sperre frisch', false, []],
    'ban-no-forget' => [$ban, '$this->access()->forgetUser($userId);', ';', $suite, false, 'Nach Sperre und Aufheben wirkt can() sofort (forgetUser)', false, []],
    'ban-note-leak' => [$ban, '$withNote = $mayView && $this->access()->outranks($viewerId, $userId);', '$withNote = true;', $suite, false, 'Interne Notiz nur für Ranghöhere', false, []],
    'ban-active-foreign' => [$ban, 'if ($viewerId !== $userId && !$this->mayViewBans($viewerId, $nowTs)) {', 'if (false) {', $suite, false, 'active(): Grund und Ende nur für das Mitglied selbst oder die Moderation', false, []],

    // ── Aufheben (M11, Fassung 3 S11)
    'lift-gate-first' => [$ban, 'if (!$temp->allowed && !$full->allowed) {', 'if (false) {', $suite, false, 'lift: Rechte-Tor vor ban_not_found (fehlende = vorhandene Sperre für Unberechtigte)', false, []],
    'ban-lift-foreign' => [$ban, '$own = (int) ($ban[\'issued_by\'] ?? 0) === $actorId;', '$own = true;', $suite, false, 'Fremde Sperren hebt nur community.ban auf', false, []],
    'lift-no-rank' => [$ban, 'if (!$this->access()->outranks($actorId, (int) $ban[\'user_id\'])) {', 'if (false) {', $suite, false, 'Aufheben nur mit Rang über dem Betroffenen', true, []],
    'lift-issuer-rank' => [$ban, 'if ($foreign && ($this->access()->authority($actorId) ?? 0) < ($this->access()->authority($issuer) ?? 0)) {', 'if (false) {', $suite, false, 'Fremde Sperre: Rang mindestens wie der Aussteller', false, []],
    'lift-expired-lifted' => [$ban, 'if ($ban[\'ends_at\'] !== null && (string) $ban[\'ends_at\'] <= $now) {', 'if (false) {', $suite, false, 'Abgelaufene Sperren bleiben unverändert (changed false, kein Protokoll)', false, []],
    'lift-rights-not-fresh' => [$ban, '[$temp, $full] = $this->rights($actorId, [\'fresh\' => true, \'now\' => $nowTs]);', '[$temp, $full] = $this->rights($actorId, [\'now\' => $nowTs]);', $suite, false, 'lift prüft Rechte unter der Sperre frisch', false, []],

    // ── MariaDB (community-db, sections-sanctions.php)
    'report-no-reporter-lock' => [$report, '$this->stats->lockForUpdate($reporterId, $now);', '$this->stats->get($reporterId);', 'community-db', true, 'Meldegrenze exakt unter der Statistiksperre des Melders (8 gleichzeitige Meldungen am Limit: genau 1 Erfolg)', false, ['CMS_TEST_DB_SECTIONS' => 'race-report-limit']],
    'report-read-before-lock' => [$report, '$this->stats->lockForUpdate($reporterId, $now);', '$this->reports->recentByReporter($reporterId, $now); $this->stats->lockForUpdate($reporterId, $now);', 'community-db', true, 'Keine Leseanweisung vor der Sperre (sonst veralteter Schnappschuss, Probe 17 statt 10)', false, ['CMS_TEST_DB_SECTIONS' => 'race-report-limit']],
    'ban-no-target-lock' => [$ban, '$this->stats->lockForUpdate($userId, $now);', '$this->stats->get($userId);', 'community-db', true, 'Sperren serialisiert an der Statistikzeile des Betroffenen (8 Moderatoren: genau eine Sperre)', false, ['CMS_TEST_DB_SECTIONS' => 'race-ban']],
    'lift-no-target-lock' => [$ban, '$this->stats->lockForUpdate($targetId, $now);', '$this->stats->get($targetId);', 'community-db', true, 'Aufheben und Sperren desselben Mitglieds laufen nacheinander', false, ['CMS_TEST_DB_SECTIONS' => 'race-lift-ban']],
    'resolve-no-lock' => [$reportRepo, '" AND status = \'open\' ORDER BY id ASC" . self::forUpdate()', '" AND status = \'open\' ORDER BY id ASC"', 'community-db', true, 'Gleichzeitiges Erledigen desselben Bündels: genau ein Erfolg, die übrigen report_closed', false, ['CMS_TEST_DB_SECTIONS' => 'race-resolve']],
    'ban-local-time' => [$ban, 'return gmdate(\'Y-m-d H:i:s\', $ts);', 'return date(\'Y-m-d H:i:s\', $ts);', 'community-db', true, 'Sperrzeiten in UTC (Sitzung +01:00, PHP Berlin)', false, ['CMS_TEST_DB_SECTIONS' => 'utc-sanctions']],
    'report-local-time' => [$report, 'return gmdate(\'Y-m-d H:i:s\', $ts);', 'return date(\'Y-m-d H:i:s\', $ts);', 'community-db', true, 'Meldezeiten in UTC (Sitzung +01:00, PHP Berlin)', false, ['CMS_TEST_DB_SECTIONS' => 'utc-sanctions']],
];
