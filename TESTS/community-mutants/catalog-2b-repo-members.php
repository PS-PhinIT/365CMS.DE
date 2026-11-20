<?php
/**
 * Katalog der Mutanten der Einheit B2 (PR #2b Welle 1): Mitglieder-Repositories `UserStatsRepository`, `MemberRepository`,
 * `ReadStateRepository`, `BanRepository`, `ReportRepository`.
 *
 * Format wie `catalog.php` (Name => [Datei relativ zu CMS/, Suchtext, Ersatz, Suite, needsDb, Regel, quick, env, requires]); die Namen sind
 * über alle Kataloge eindeutig. Jeder Suchtext ist eine Pflichtschreibweise und steht genau einmal in seiner Datei (Vertrag DESIGN-2b §1 Nr. 5).
 * Die Zeilen aus moderation §6.4 (`stats-lock-desc`) und sanktionen §6.5 (`ban-lift-repeat`, `report-lock-closed`, `report-count-old`)
 * behalten ihre Namen; Ziel ist in Welle 1 die Suite `community-repositories` (Teildatei `part-2b-members.php`), weil `community-moderation`
 * und `community-sanctions` erst in Welle 2 entstehen. `report-lock-closed` zielt auf die Regel „nur offene Meldungen“ von
 * `openIdsForTarget()` (statt `lockOpenForTarget()`, Vertrag §3.7). Die Sperre über IDs prüft `report-lock-ids-unlocked` ohne Datenbank
 * am Protokoll; die Datenbank-Mutanten `resolve-no-lock` (sanktionen) und `db-mod-lockmany-rows` (moderation) brauchen die Wettläufe von
 * K bzw. M und gehören in deren Kataloge.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

$stats = 'core/Services/Community/Repository/UserStatsRepository.php';
$members = 'core/Services/Community/Repository/MemberRepository.php';
$reads = 'core/Services/Community/Repository/ReadStateRepository.php';
$bans = 'core/Services/Community/Repository/BanRepository.php';
$reports = 'core/Services/Community/Repository/ReportRepository.php';
$suite = 'community-repositories';

return [
    // ── UserStatsRepository: Mengen und Sperren (Vertrag §1 Nr. 3, §3.10 Ebene 4, §3.11)
    'stats-lock-desc' => [$stats, 'sort($ids);', 'rsort($ids);', $suite, false, 'lockMany sperrt aufsteigend nach user_id (Sperrfolge Ebene 4, über alle Stücke)', false, []],
    'stats-lock-unchunked' => [$stats, 'foreach (array_chunk($ids, self::CHUNK) as $chunk) {', 'foreach ($ids === [] ? [] : [$ids] as $chunk) {', $suite, false, 'lockMany sperrt in Stücken zu höchstens 100 IDs', false, []],
    'stats-lock-no-lock' => [$stats, "' ORDER BY user_id ASC' . self::forUpdate();", "' ORDER BY user_id ASC';", $suite, false, 'lockMany sperrt die Zeilen (Zeilensperre je Stück)', false, []],
    'stats-lock-missing-thrown' => [$stats, '$this->ensureMany($missing, $nowUtc);', '', $suite, false, 'lockMany legt eine fehlende Zeile an und sperrt erneut (Ausnahmefall wie lockForUpdate)', false, []],
    'stats-batches-unbounded' => [$stats, 'return array_chunk($ids, self::CHUNK);', 'return $ids === [] ? [] : [$ids];', $suite, false, 'ensureMany, statsMany und settingsMany arbeiten in Stücken zu höchstens 100 IDs', false, []],
    'stats-bumplocked-ensures' => [$stats, 'int $solutions = 0): void' . "\n" . '    {' . "\n" . '        $this->run(', 'int $solutions = 0): void' . "\n" . '    {' . "\n" . '        $this->ensure($userId, $nowUtc);' . "\n" . '        $this->run(', $suite, false, 'bumpLocked schreibt kein INSERT (keine Lückensperre unter Sperren, R12)', false, []],
    'stats-solutions-ignored' => [$stats, '...self::counterParams($solutions), $nowUtc, $userId]', '...self::counterParams(0), $nowUtc, $userId]', $suite, false, 'bump/bumpLocked buchen solutions_count', false, []],
    'stats-reactions-given-ignored' => [$stats, '...self::counterParams($received), ...self::counterParams($given)', '...self::counterParams($received), ...self::counterParams(0)', $suite, false, 'bumpReactions bucht erhaltene und gegebene Reaktionen', false, []],

    // ── UserStatsRepository: Einstellungen, letzter Besuch, Ränge (interaktion §2.3, §2.6, §2.10; Vertrag §4.5)
    'stats-settings-no-lock' => [$stats, "' WHERE user_id = ? LIMIT 1' . self::forUpdate(), [\$userId]);", "' WHERE user_id = ? LIMIT 1', [\$userId]);", $suite, false, 'settingsForUpdate sperrt die Zeile des Empfängers (Mutex je Empfänger)', false, []],
    'stats-settings-missing-empty' => [$stats, "throw new \\RuntimeException('community_user_stats: Zeile für die Einstellungen fehlt (ensure() vor der Transaktion).');", 'return [];', $suite, false, 'settingsForUpdate ohne Zeile wirft (keine Sperre auf eine Lücke)', false, []],
    'stats-settings-list-kept' => [$stats, '($decoded === [] || !array_is_list($decoded))', 'true', $suite, false, 'settings_json als Liste statt Zuordnung ergibt []', false, []],
    'stats-settings-size' => [$stats, 'if (strlen($json) > self::MAX_SETTINGS_BYTES) {', 'if (false) {', $suite, false, 'saveSettings lehnt mehr als MAX_SETTINGS_BYTES ab', false, []],
    'stats-touch-seen-always' => [$stats, 'AND (last_seen_at IS NULL OR last_seen_at < ?)', 'AND (last_seen_at IS NULL OR last_seen_at < ? OR 1 = 1)', $suite, false, 'touchSeen schreibt höchstens einmal je Fenster', false, []],
    'rank-seed-repeat' => [$stats, '[self::RANK_SEED_MARKER, $nowUtc]' . "\n" . '        ) > 0;', '[self::RANK_SEED_MARKER, $nowUtc]' . "\n" . '        ) >= 0;', $suite, false, 'claimRankSeed gelingt nur einmal (INSERT IGNORE auf UNIQUE option_name)', false, []],
    'rank-order-id' => [$stats, "' ORDER BY sort_order ASC, id ASC LIMIT ?'", "' ORDER BY id ASC LIMIT ?'", $suite, false, 'Ränge nach sort_order, dann id', false, []],
    'rank-update-missing-found' => [$stats, "' WHERE id = ? LIMIT 1', [\$rankId]) !== null;", "' WHERE id = ? LIMIT 1', [\$rankId]) !== null || true;", $suite, false, 'updateRank auf einen fehlenden Rang liefert false', false, []],

    // ── MemberRepository (Vertrag §3.7, K14)
    'accounts-select-all' => [$members, "'SELECT id, username, email, display_name, status, role FROM '", "'SELECT * FROM '", $suite, false, 'accounts liest nur die nötigen Spalten (nie das Passwort)', false, []],
    'accounts-unbounded' => [$members, 'return array_chunk($ids, self::CHUNK);', 'return $ids === [] ? [] : [$ids];', $suite, false, 'accounts und metaMany arbeiten in Stücken zu höchstens 100 IDs', false, []],
    'meta-keys-unchecked' => [$members, 'if ($foreign !== []) {', 'if (false) {', $suite, false, 'metaMany liest nur Schlüssel aus META_KEYS', false, []],

    // ── ReadStateRepository (interaktion §2.8 Schritt 5)
    'for-users-any-topic' => [$reads, "' WHERE topic_id = ? AND user_id IN ('", "' WHERE topic_id > 0 * ? AND user_id IN ('", $suite, false, 'forUsers liest nur den Lesestatus des Themas', false, []],
    'latest-marks-no-global' => [$reads, '$chain = [0, ...', '$chain = [...', $suite, false, 'latestMarksFor berücksichtigt immer die globale Marke (forum_id 0)', false, []],
    'latest-marks-min' => [$reads, "'SELECT user_id, MAX(marked_at) AS marked_at FROM '", "'SELECT user_id, MIN(marked_at) AS marked_at FROM '", $suite, false, 'latestMarksFor liefert die jüngste Marke', false, []],

    // ── BanRepository (sanktionen §1.3, §2.9 B1 bis B7)
    'ban-lift-repeat' => [$bans, 'WHERE id = ? AND lifted_at IS NULL', 'WHERE id = ?', $suite, false, 'lift hebt nur offene Sperren auf (zweites Aufheben: false, Zeit und Aufhebender bleiben)', false, []],
    'ban-note-select' => [$bans, "return self::COLUMNS . (\$withNote ? ', internal_note' : '');", "return self::COLUMNS . ', internal_note';", $suite, false, 'find/forUser selektieren die interne Notiz nur auf Wunsch', false, []],
    'ban-note-leak-row' => [$bans, '        if ($withNote) {' . "\n" . '            $ban[\'internal_note\']', '        if (true) {' . "\n" . '            $ban[\'internal_note\']', $suite, false, 'find/forUser liefern die interne Notiz nur auf Wunsch', false, []],
    'ban-list-keyset' => [$bans, "' AND id < ? ORDER BY id DESC LIMIT ?'", "' AND id <= ? ORDER BY id DESC LIMIT ?'", $suite, false, 'listActive: Keyset id < before (keine Doppelung über Seiten)', false, []],
    'ban-insert-scope-unchecked' => [$bans, "if (!in_array(\$data['scope'], self::SCOPES, true)) {", 'if (false) {', $suite, false, 'insert lehnt unbekannte Umfänge ab', false, []],
    'ban-insert-end-before-start' => [$bans, 'if ($ends !== null && $ends <= $starts) {', 'if (false) {', $suite, false, 'insert lehnt ein Ende vor oder gleich dem Beginn ab', false, []],

    // ── ReportRepository (sanktionen §1.1, §2.9 R1 bis R15; Vertrag §3.7, §3.10 Ebene 6, R11)
    'report-lock-closed' => [$reports, "\"target_type = ? AND target_id = ? AND status = 'open'\"", '"target_type = ? AND target_id = ?"', $suite, false, 'openIdsForTarget, hasOpen, openReporters und openForTarget liefern nur offene Meldungen', false, []],
    'report-count-old' => [$reports, 'WHERE reporter_id = ? AND created_at >= ?', 'WHERE reporter_id = ? AND created_at >= ? OR 1 = 1', $suite, false, 'recentByReporter zählt nur Meldungen des Melders im Fenster', false, []],
    'report-recent-exclusive' => [$reports, "AND created_at >= ?',", "AND created_at > ?',", $suite, false, 'Fenster der Meldegrenze einschließlich der Grenze', false, []],
    'report-lock-ids-unlocked' => [$reports, "\" AND status = 'open' ORDER BY id ASC\" . self::forUpdate()", "\" AND status = 'open' ORDER BY id ASC\"", $suite, false, 'lockOpenByIds sperrt die Meldungen (Zeilensperre über PRIMARY)', false, []],
    'report-lock-ids-closed' => [$reports, "\" AND status = 'open' ORDER BY id ASC\"", '" ORDER BY id ASC"', $suite, false, 'lockOpenByIds prüft unter der Sperre erneut, dass die Meldungen offen sind', true, []],
    'report-upto-ignored' => [$reports, "' AND id <= ?'", "' AND ? > 0'", $suite, false, 'openIdsForTarget beachtet upTo (ungesehene neuere Meldungen bleiben offen)', false, []],
    'report-close-closed' => [$reports, "\") AND status = 'open'\",", '")",', $suite, false, 'close schließt nur offene Meldungen', false, []],
    'report-append-closed' => [$reports, "\" SET message = ? WHERE id = ? AND status = 'open'\"", '" SET message = ? WHERE id = ?"', $suite, false, 'appendMessage ändert nur offene Meldungen', false, []],
    'report-reassign-closed' => [$reports, "\" AND status = 'open' AND (forum_id IS NULL OR forum_id <> ?)\"", '" AND (forum_id IS NULL OR forum_id <> ?)"', $suite, false, 'reassignTopic/reassignPosts hängen nur offene Meldungen um (erledigte behalten ihr Forum)', false, []],
    'report-reassign-topic-posts' => [$reports, "\" WHERE p.topic_id = ? AND r.target_type = 'post' AND r.status = 'open' ORDER BY r.id ASC\"", '" WHERE p.topic_id = ? AND 1 = 0 ORDER BY r.id ASC"', $suite, false, 'reassignTopic hängt auch die Meldungen zu Beiträgen des Themas um', false, []],
    'report-bundles-keyset' => [$reports, 'HAVING MIN(id) > ?', 'HAVING MIN(id) >= ?', $suite, false, 'openBundles: Keyset first_id > after', false, []],
    'report-forum-filter-off' => [$reports, "return [' AND forum_id IN (' . self::placeholders(count(\$ids)) . ')', \$ids];", "return ['', []];", $suite, false, 'openBundles und countOpen filtern auf die Foren des Moderationsbereichs', false, []],
    'report-count-uncapped' => [$reports, '[...$params, max(1, min(self::COUNT_MAX, $cap))]', '[...$params, self::COUNT_MAX]', $suite, false, 'countOpen zählt höchstens bis zum Deckel', false, []],
    'report-reporters-null' => [$reports, "' AND reporter_id IS NOT NULL ORDER BY reporter_id ASC LIMIT ?'", "' ORDER BY reporter_id ASC LIMIT ?'", $suite, false, 'openReporters ohne gelöschte Melder (NULL)', false, []],
    'report-reason-unchecked' => [$reports, "if (!in_array(\$data['reason'], self::REASONS, true)) {", 'if (false) {', $suite, false, 'insert lehnt unbekannte Gründe ab', false, []],
    'report-target-type-unchecked' => [$reports, 'if (!in_array($targetType, self::TARGET_TYPES, true)) {', 'if (false) {', $suite, false, 'unbekannte Zielart ist ein Programmfehler (InvalidArgumentException)', false, []],
];
