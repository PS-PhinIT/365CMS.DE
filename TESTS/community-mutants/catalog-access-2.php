<?php
/**
 * Ergänzender Katalog der Mutanten für die Gegenprobe (Zugriff und Ereignisse, Nachlauf zu PR #2a).
 *
 * Gleiches Format wie `catalog.php` (der Treiber lädt `catalog.php` und alle `catalog-*.php`, Namen eindeutig):
 *
 *   Name => [Datei relativ zu CMS/, Suchtext, Ersatz, Suite, needsDb, Regel, quick, env]
 *
 * Enthält die Mutanten r1 bis r36 der unabhängigen Prüfung (Befunde B4, B5, B19: r13, r14, r15, r25, r27 und r36 überlebten) mit den
 * Namen ohne Nummer, dazu je einen Mutanten für jede Regel der Nachlauf-Änderungen (B1/B8/B11 Filterkette, B2/B12 Ziel,
 * B3/B29 Kontingent, B9/B18/B31 Abfragen der Lesepfade, B13 fail-closed, B20 Verbraucher einzeln, Rechte-Subjekte).
 * Jeder Eintrag muss von der Suite `community-access` erkannt werden (Exit 1); ein Mutant, den keine Prüfung erkennt, ist eine
 * Lücke der Suite.
 *
 * Erzeugt aus einer Liste; Änderungen am Quelltext, die einen Suchtext treffen, wie bei `catalog.php` in der betroffenen Zeile nachziehen.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

return [
    'trust-level1-threshold-strict' => [
        'core/Services/Community/Service/BasicTrustPolicy.php',
        'if ($emailVerified && $postCount >= $settings->int(\'trust_level1_posts\')) {',
        'if ($emailVerified && $postCount > $settings->int(\'trust_level1_posts\')) {',
        'community-access', false, 'Stufe 1: ab trust_level1_posts Beiträgen (Grenze eingeschlossen)', false, [],
    ],
    'trust-level2-without-level1' => [
        'core/Services/Community/Service/BasicTrustPolicy.php',
        'if ($level === 1 && $postCount >= $settings->int(\'trust_level2_posts\')) {',
        'if ($postCount >= $settings->int(\'trust_level2_posts\')) {',
        'community-access', false, 'Stufe 2 setzt Stufe 1 voraus (bestätigte E-Mail)', false, [],
    ],
    'trust-stored-level-ignored' => [
        'core/Services/Community/Service/BasicTrustPolicy.php',
        'return max($stored, $level);',
        'return $level;',
        'community-access', false, 'eine gespeicherte (manuell vergebene) Stufe wird nie unterschritten', false, [],
    ],
    'trust-locked-level-ignored' => [
        'core/Services/Community/Service/BasicTrustPolicy.php',
        'if ($storedLocked) {',
        'if (false) {',
        'community-access', false, 'eine festgesetzte Stufe gilt unverändert', false, [],
    ],
    'trust-stored-level-unclamped' => [
        'core/Services/Community/Service/BasicTrustPolicy.php',
        '$stored = max(self::MIN_LEVEL, min(self::MAX_LEVEL, $storedLevel));',
        '$stored = $storedLevel;',
        'community-access', false, 'die gespeicherte Stufe wird auf 0 bis 4 begrenzt', false, [],
    ],
    'access-locked-flag-dropped' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '!empty($stats[\'trust_level_locked\'])',
        'false',
        'community-access', false, 'CommunityAccess reicht die Festsetzung der Stufe an die Regel weiter', false, [],
    ],
    'announce-not-global-only' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'if (!$moderating || ($action === \'topic.announce\' && !$global)) {',
        'if (!$moderating) {',
        'community-access', false, 'topic.announce nur für globale Moderation, nicht für Forum-Moderatoren', false, [],
    ],
    'deleted-topic-visible-to-author' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '\'deleted\' => $moderating,' . "\n" . '            \'trash\' => $admin,',
        '\'deleted\' => $moderating || $own,' . "\n" . '            \'trash\' => $admin,',
        'community-access', false, 'gelöschte Themen sieht nur die Moderation, nicht der Autor', false, [],
    ],
    'trash-topic-visible-to-mods' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '\'trash\' => $admin,',
        '\'trash\' => $moderating,',
        'community-access', false, 'Themen im Papierkorb sieht nur admin', false, [],
    ],
    'forget-keeps-moderators' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'if (str_starts_with((string) $key, $prefix)) {' . "\n" . '                unset($this->moderators[$key]);',
        'if (false) {' . "\n" . '                unset($this->moderators[$key]);',
        'community-access', false, 'forgetUser() verwirft die zwischengespeicherte Forum-Moderation', false, [],
    ],
    'forget-keeps-permission-rows' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'if (str_starts_with((string) $key, $prefix)) {' . "\n" . '                unset($this->permissionRows[$key]);',
        'if (false) {' . "\n" . '                unset($this->permissionRows[$key]);',
        'community-access', false, 'forgetUser() verwirft die zwischengespeicherten Rechte-Zeilen', false, [],
    ],
    'fresh-keeps-tree' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$this->forgetUser($uid);' . "\n" . '            $this->forums->forgetTree();',
        '$this->forgetUser($uid);',
        'community-access', false, 'fresh liest den Forenbaum neu (ein zwischenzeitlich verborgenes Elternforum gilt sofort)', true, [],
    ],
    'ban-cache-never-refreshes' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '|| $this->banCache[$uid][\'at\'] !== $now) {',
        '|| false) {',
        'community-access', false, 'der Sperrencache gilt je Sekunde: eine andere Sekunde liest die Sperre neu', true, [],
    ],
    'readable-ignores-filter' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'if ($visible && $this->applyFilter(AccessDecision::allow(), $account[\'id\'], \'read\', [\'forum_id\' => $forumId])->allowed) {',
        'if ($visible) {',
        'community-access', false, 'die Mengen-API beachtet den Filter cms_community_can', false, [],
    ],
    'react-own-allowed' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'if ($action === \'react\' && $own) {',
        'if (false) {',
        'community-access', false, 'auf eigene Beiträge reagiert man nicht', false, [],
    ],
    'solution-any-forum-type' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '((int) ($topic[\'user_id\'] ?? 0) !== $uid || $type !== \'qa\')',
        '((int) ($topic[\'user_id\'] ?? 0) !== $uid)',
        'community-access', false, 'Lösung markiert der Themenautor nur in Foren vom Typ qa', false, [],
    ],
    'pending-cap-for-published' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'if ($initial === \'pending\' && $this->posts->countSince(',
        'if ($this->posts->countSince(',
        'community-access', false, 'der Deckel für wartende Beiträge gilt nur, wenn der neue Beitrag wartet', false, [],
    ],
    'future-clock-unclamped' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$rest = min($limit, $last + $limit - $now);',
        '$rest = $last + $limit - $now;',
        'community-access', false, 'ein Zeitpunkt in der Zukunft sperrt höchstens um die volle Grenze', false, [],
    ],
    'move-target-archived-ignored' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'if ((string) $targetForum[\'status\'] === \'archived\') {',
        'if (false) {',
        'community-access', false, 'Verschieben in ein archiviertes Zielforum ist abgelehnt', false, [],
    ],
    'move-target-moderation-ignored' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'if (!$global && !$this->isForumModerator($account, $targetForum)) {',
        'if (false) {',
        'community-access', false, 'Verschieben verlangt Moderation auch im Zielforum', false, [],
    ],
    'filter-retry-after-dropped' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'return AccessDecision::deny(\'filter_denied\', $result->retryAfter);',
        'return AccessDecision::deny(\'filter_denied\');',
        'community-access', false, 'die Wartezeit eines ablehnenden Filters bleibt erhalten', false, [],
    ],
    'report-own-allowed' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'if ($action === \'report\' && $owner > 0 && $owner === $uid) {',
        'if (false) {',
        'community-access', false, 'eigene Inhalte meldet man nicht', false, [],
    ],
    'pending-topic-author-cannot-edit' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '\'pending\' => $moderating || $editing ? null : \'topic_pending\',',
        '\'pending\' => $moderating ? null : \'topic_pending\',',
        'community-access', false, 'der Autor darf sein wartendes Thema bearbeiten und löschen', true, [],
    ],
    'limitsfor-level-zero' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$level = $this->levelOf($account[\'id\'], $this->stats->get($account[\'id\']));',
        '$level = 0;',
        'community-access', false, 'limitsFor() liefert die Grenzen der tatsächlichen Stufe', false, [],
    ],
    'edit-window-missing-created' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'if ($created === null || $run[\'now\'] - $created > $minutes * 60) {',
        'if ($created !== null && $run[\'now\'] - $created > $minutes * 60) {',
        'community-access', false, 'fail-closed: ohne gültiges created_at ist das Bearbeitungsfenster abgelaufen', true, [],
    ],
    'rate-topic-also-for-replies' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$rateTopic = $moderating || !$creating ? 0 : $this->settings->int(\'rate_topic_seconds\');',
        '$rateTopic = $moderating ? 0 : $this->settings->int(\'rate_topic_seconds\');',
        'community-access', false, 'rate_topic_seconds gilt nur beim Anlegen von Themen', false, [],
    ],
    'group-moderators-ignored' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$this->forums->moderatorRows($ancestors, $account[\'id\'], $this->groupIds($account[\'id\']), $forumId)',
        '$this->forums->moderatorRows($ancestors, $account[\'id\'], [], $forumId)',
        'community-access', false, 'Forum-Moderation über aktive Gruppen', false, [],
    ],
    'moderator-include-children-lost' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$ancestors = $forum !== null ? $this->ancestorIds($forumId) : [self::DECOY_ID];',
        '$ancestors = $forum !== null ? [$forumId] : [self::DECOY_ID];',
        'community-access', false, 'Forum-Moderation wirkt über include_children auf Unterforen', false, [],
    ],
    'limit-hourly-topics-dropped' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'if ($creating && $limits[\'topics_per_hour\'] > 0 &&',
        'if (false && $limits[\'topics_per_hour\'] > 0 &&',
        'community-access', false, 'Themen je Stunde der Vertrauensstufe', false, [],
    ],
    'ban-future-start' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$this->banCache[$uid] = [\'at\' => $now, \'ban\' => $this->bans->activeFor($uid, gmdate(\'Y-m-d H:i:s\', $now))];',
        '$this->banCache[$uid] = [\'at\' => $now, \'ban\' => $this->bans->activeFor($uid, gmdate(\'Y-m-d H:i:s\', $now + 86400))];',
        'community-access', false, 'eine Sperre wirkt ab ihrem Beginn (zur Abfragezeit ausgewertet)', false, [],
    ],
    'view-private-data-for-forum-mods' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '\'deleted\' => $moderating && $account[\'member\'] && $this->roleHas($account[\'role\'], CommunityCapabilities::VIEW_PRIVATE_DATA) ? \'full\' : \'placeholder\',',
        '\'deleted\' => $moderating ? \'full\' : \'placeholder\',',
        'community-access', false, 'gelöschte Beiträge voll sieht nur, wer community.view_private_data hat', false, [],
    ],
    'readable-skips-hidden-chain' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$visible = ($matrix[\'read\'] && !$this->hiddenInChain($forum)) ||',
        '$visible = $matrix[\'read\'] ||',
        'community-access', false, 'die Mengen-API beachtet verborgene Elternforen', false, [],
    ],
    'readable-ignores-guest-gate' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'if (!$this->settings->bool(\'guests_can_read\')) {' . "\n" . '                    return [];',
        'if (false) {' . "\n" . '                    return [];',
        'community-access', false, 'Gast-Tor guests_can_read gilt auch in der Mengen-API (auch bei einer Gast-Zeile read = 1)', true, [],
    ],
    'forget-keeps-ban-cache' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$this->verified[$userId], $this->banCache[$userId], $this->levels[$userId]);',
        '$this->verified[$userId], $this->levels[$userId]);',
        'community-access', false, 'fresh und forgetUser() verwerfen die zwischengespeicherte Sperre (Dienste erkennen so eine Sperre unter der Zeilensperre)', true, [],
    ],
    'forget-keeps-levels' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$this->banCache[$userId], $this->levels[$userId]);',
        '$this->banCache[$userId]);',
        'community-access', false, 'forgetUser() verwirft die zwischengespeicherte Vertrauensstufe (lesende Entscheidungen)', false, [],
    ],
    'flush-keeps-levels' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '        $this->banCache = [];' . "\n" . '        $this->levels = [];' . "\n" . '        $this->forums->forgetTree();',
        '        $this->banCache = [];' . "\n" . '        $this->forums->forgetTree();',
        'community-access', false, 'flush() verwirft die zwischengespeicherte Vertrauensstufe aller Mitglieder', false, [],
    ],
    'read-level-not-cached' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'return $this->levels[$uid] ??= $this->levelOf($uid, $this->stats->get($uid));',
        'return $this->levelOf($uid, $this->stats->get($uid));',
        'community-access', false, 'Lesen liest die Statistik je Instanz einmal, nicht je Beitrag (B9/B18)', true, [],
    ],
    'read-reads-stats-every-time' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '            if ($reading) {' . "\n" . '                $level = $this->readLevel($uid);',
        '            if (false) {' . "\n" . '                $level = $this->readLevel($uid);',
        'community-access', false, 'Lesen braucht die Statistik nur über die zwischengespeicherte Stufe (B9/B18)', false, [],
    ],
    'filter-denial-not-final' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'return AccessDecision::deny(\'filter_denied\', $result->retryAfter);',
        'continue;',
        'community-access', false, 'Filter cms_community_can: die erste Ablehnung steht fest, ein späterer Filter hebt sie nicht auf (B1)', true, [],
    ],
    'filter-read-denial-not-final' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '\'reason\' => $result->reason,' . "\n" . '                    ]);' . "\n" . '' . "\n" . '                    return AccessDecision::deny(\'not_found\');',
        '\'reason\' => $result->reason,' . "\n" . '                    ]);' . "\n" . '' . "\n" . '                    continue;',
        'community-access', false, 'dto. bei read und search (B1)', false, [],
    ],
    'filter-read-denial-visible' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '\'reason\' => $result->reason,' . "\n" . '                    ]);' . "\n" . '' . "\n" . '                    return AccessDecision::deny(\'not_found\');',
        '\'reason\' => $result->reason,' . "\n" . '                    ]);' . "\n" . '' . "\n" . '                    return AccessDecision::deny(\'filter_denied\', $result->retryAfter);',
        'community-access', false, 'Filter-Ablehnung bei read und search ist not_found, kein Existenzkanal (B11)', true, [],
    ],
    'filter-exception-allows' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'return $reading ? AccessDecision::deny(\'not_found\') : AccessDecision::deny(\'filter_denied\');',
        'continue;',
        'community-access', false, 'eine Ausnahme im Filter ist eine Ablehnung (fail-closed), nicht der Eingangswert (B8)', true, [],
    ],
    'filter-exception-read-visible' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'return $reading ? AccessDecision::deny(\'not_found\') : AccessDecision::deny(\'filter_denied\');',
        'return AccessDecision::deny(\'filter_denied\');',
        'community-access', false, 'eine Ausnahme im Filter bei read und search ist not_found (B8/B11)', false, [],
    ],
    'filter-gets-start-decision' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$result = call_user_func($filter, $current, $uid, $action, $context);',
        '$result = call_user_func($filter, $decision, $uid, $action, $context);',
        'community-access', false, 'jeder Filter bekommt die Entscheidung, die der vorige übrig gelassen hat (B1)', false, [],
    ],
    'filter-extension-checked-against-start' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$status = self::tightened($current->initialStatus, $result->initialStatus,',
        '$status = self::tightened($decision->initialStatus, $result->initialStatus,',
        'community-access', false, 'ein späterer Filter kann eine Verschärfung des vorigen nicht zurücknehmen (B1)', false, [],
    ],
    'events-fire-plain-doaction' => [
        'core/Services/Community/Support/Events.php',
        'Hooks::doActionIsolated($hook, static function (\\Throwable $e) use ($hook): void {' . "\n" . '                self::log($hook, $e);' . "\n" . '            }, ...$args);',
        'Hooks::doAction($hook, ...$args);',
        'community-access', false, 'Events::fire(): jeder Verbraucher einzeln abgesichert, ein kaputter hält die übrigen nicht auf (B20)', false, [],
    ],
    'events-fire-errors-not-logged' => [
        'core/Services/Community/Support/Events.php',
        'Hooks::doActionIsolated($hook, static function (\\Throwable $e) use ($hook): void {' . "\n" . '                self::log($hook, $e);' . "\n" . '            }, ...$args);',
        'Hooks::doActionIsolated($hook, null, ...$args);',
        'community-access', false, 'Events::fire(): der Fehler eines Verbrauchers wird protokolliert (B20)', false, [],
    ],
    'global-scope-not-required' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'return ($ctx[\'scope\'] ?? null) === \'global\' ? AccessDecision::allow() : AccessDecision::deny(\'not_found\');',
        'return AccessDecision::allow();',
        'community-access', false, 'read/search ohne Ziel nur mit scope = global (B2)', true, [],
    ],
    'global-scope-any-value' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'return ($ctx[\'scope\'] ?? null) === \'global\' ? AccessDecision::allow() : AccessDecision::deny(\'not_found\');',
        'return ($ctx[\'scope\'] ?? null) !== null ? AccessDecision::allow() : AccessDecision::deny(\'not_found\');',
        'community-access', false, 'nur der Wert global öffnet die ziellose Frage (B2)', false, [],
    ],
    'global-scope-needs-no-search' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '        if (in_array($action, self::READ_ACTIONS, true)) {' . "\n" . '            return ($ctx[\'scope\']',
        '        if ($action === \'read\') {' . "\n" . '            return ($ctx[\'scope\']',
        'community-access', false, 'auch search ohne Ziel ist mit scope = global erlaubt (B2)', false, [],
    ],
    'empty-target-key-is-no-target' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '            // Ein Zielschlüssel ist da, aber leer (`forum_id => null`): der Aufrufer wollte ein Ziel angeben und hat keines.' . "\n" . '            $forumRef = self::DECOY_ID;',
        '            return $result;',
        'community-access', false, 'ein Zielschlüssel ohne Wert (forum_id => null) ist ein unbekanntes Ziel, auch neben scope = global (B2)', false, [],
    ],
    'id-array-is-row' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'return is_array($id) ? self::DECOY_ID : $id;',
        'return $id;',
        'community-access', false, 'ein Array unter *_id ist nie eine Zeile, sondern ein unbekanntes Ziel (B12)', true, [],
    ],
    'unknown-forum-status-open' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'if (!in_array((string) $forum[\'status\'], self::OPEN_FORUM_STATUSES, true) || self::pathBroken($forum)) {',
        'if ((string) $forum[\'status\'] === \'hidden\' || self::pathBroken($forum)) {',
        'community-access', false, 'ein unbekannter Forumstatus gilt wie hidden (B13)', true, [],
    ],
    'broken-path-ignored' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'if (!in_array((string) $forum[\'status\'], self::OPEN_FORUM_STATUSES, true) || self::pathBroken($forum)) {',
        'if (!in_array((string) $forum[\'status\'], self::OPEN_FORUM_STATUSES, true) || false) {',
        'community-access', false, 'ein leerer oder beschädigter Pfad gilt wie hidden (B13)', true, [],
    ],
    'ancestor-unknown-status-open' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'if ($ancestorId !== $forumId && !in_array((string) ($tree[$ancestorId][\'status\'] ?? \'\'), self::OPEN_FORUM_STATUSES, true)) {',
        'if ($ancestorId !== $forumId && (string) ($tree[$ancestorId][\'status\'] ?? \'\') === \'hidden\') {',
        'community-access', false, 'ein Elternforum mit unbekanntem Status oder ohne Zeile im Forenbaum verbirgt das Unterforum (B13)', false, [],
    ],
    'path-parent-not-checked' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'return $last < 1 || $ids[$last] !== (int) $forum[\'id\'] || $ids[$last - 1] !== $parent;',
        'return $last < 1 || $ids[$last] !== (int) $forum[\'id\'];',
        'community-access', false, 'der Pfad muss das Elternforum vor der eigenen ID nennen (B13)', false, [],
    ],
    'path-own-id-not-checked' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'return $last < 1 || $ids[$last] !== (int) $forum[\'id\'] || $ids[$last - 1] !== $parent;',
        'return $last < 1 || $ids[$last - 1] !== $parent;',
        'community-access', false, 'der Pfad muss mit der eigenen ID enden (B13)', false, [],
    ],
    'path-short-not-checked' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'return $last < 1 || $ids[$last] !== (int) $forum[\'id\'] || $ids[$last - 1] !== $parent;',
        'return $ids[$last] !== (int) $forum[\'id\'] || $ids[$last - 1] !== $parent;',
        'community-access', false, 'ein Pfad nur aus der eigenen ID passt nicht zu einem Forum mit Elternforum (B13)', false, [],
    ],
    'path-segment-zero-ok' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'if (preg_match(\'/^[1-9]\\d*$/\', $segment) !== 1) {',
        'if (preg_match(\'/^\\d+$/\', $segment) !== 1) {',
        'community-access', false, 'Pfadsegmente sind positive IDs ohne führende Null (B13)', false, [],
    ],
    'parent-less-forum-path-checked' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '        if ($parent < 1) {' . "\n" . '            return false;' . "\n" . '        }' . "\n" . '        $ids = [];',
        '        $ids = [];',
        'community-access', false, 'oberste Foren ohne Elternforum sind vom Pfad unabhängig (B13)', false, [],
    ],
    'quota-post-statuses-live-only' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'private const QUOTA_POST_STATUSES = PostRepository::STATUSES;',
        'private const QUOTA_POST_STATUSES = [\'published\', \'pending\', \'hidden\'];',
        'community-access', false, 'Tageslimit, Abstand und Langsamer Modus zählen auch gelöschte und abgelehnte Beiträge (B3/B29)', true, [],
    ],
    'quota-topic-statuses-live-only' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'private const QUOTA_TOPIC_STATUSES = [\'published\', \'pending\', \'hidden\', \'deleted\', \'trash\'];',
        'private const QUOTA_TOPIC_STATUSES = [\'published\', \'pending\', \'hidden\'];',
        'community-access', false, 'Stundenlimit und Themenabstand zählen auch gelöschte Themen (B3/B29)', true, [],
    ],
    'quota-moved-topics-count' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'private const QUOTA_TOPIC_STATUSES = [\'published\', \'pending\', \'hidden\', \'deleted\', \'trash\'];',
        'private const QUOTA_TOPIC_STATUSES = [\'published\', \'pending\', \'hidden\', \'deleted\', \'trash\', \'moved\'];',
        'community-access', false, 'der Verweis auf ein verschobenes Thema (moved) ist kein neues Thema und zählt nicht', false, [],
    ],
    'quota-daily-count-live-only' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$this->posts->countSince($uid, gmdate(\'Y-m-d H:i:s\', $now - 86400), self::QUOTA_POST_STATUSES)',
        '$this->posts->countSince($uid, gmdate(\'Y-m-d H:i:s\', $now - 86400))',
        'community-access', false, 'das Tageslimit zählt auch gelöschte Beiträge (B3/B29)', false, [],
    ],
    'quota-rate-last-live-only' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$recent = $this->posts->lastOfUser($uid, gmdate(\'Y-m-d H:i:s\', $now - $ratePost), null, null, self::QUOTA_POST_STATUSES);',
        '$recent = $this->posts->lastOfUser($uid, gmdate(\'Y-m-d H:i:s\', $now - $ratePost), null, null);',
        'community-access', false, 'der Abstand zwischen Beiträgen zählt auch gelöschte Beiträge, wenn die Statistik den letzten nicht kennt (B3/B29)', false, [],
    ],
    'quota-forum-slow-live-only' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$recent = $this->posts->lastOfUser($uid, gmdate(\'Y-m-d H:i:s\', $now - $forumSlow), (int) $forum[\'id\'], null, self::QUOTA_POST_STATUSES);',
        '$recent = $this->posts->lastOfUser($uid, gmdate(\'Y-m-d H:i:s\', $now - $forumSlow), (int) $forum[\'id\'], null);',
        'community-access', false, 'der Langsame Modus eines Forums zählt auch gelöschte Beiträge (B3/B29)', false, [],
    ],
    'quota-topic-slow-live-only' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$recent = $this->posts->lastOfUser($uid, gmdate(\'Y-m-d H:i:s\', $now - $topicSlow), null, (int) $topic[\'id\'], self::QUOTA_POST_STATUSES);',
        '$recent = $this->posts->lastOfUser($uid, gmdate(\'Y-m-d H:i:s\', $now - $topicSlow), null, (int) $topic[\'id\']);',
        'community-access', false, 'der Langsame Modus eines Themas zählt auch gelöschte Beiträge (B3/B29)', false, [],
    ],
    'quota-topic-last-live-only' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$recent = $this->topics->lastOfUser($uid, gmdate(\'Y-m-d H:i:s\', $now - $rateTopic), self::QUOTA_TOPIC_STATUSES);',
        '$recent = $this->topics->lastOfUser($uid, gmdate(\'Y-m-d H:i:s\', $now - $rateTopic));',
        'community-access', false, 'der Themenabstand zählt auch gelöschte Themen (B3/B29)', false, [],
    ],
    'quota-topic-count-live-only' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$this->topics->countSince($uid, gmdate(\'Y-m-d H:i:s\', $now - 3600), self::QUOTA_TOPIC_STATUSES)',
        '$this->topics->countSince($uid, gmdate(\'Y-m-d H:i:s\', $now - 3600))',
        'community-access', false, 'das Stundenlimit zählt auch gelöschte Themen (B3/B29)', false, [],
    ],
    'slow-mode-forum-scope-dropped' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$recent = $this->posts->lastOfUser($uid, gmdate(\'Y-m-d H:i:s\', $now - $forumSlow), (int) $forum[\'id\'], null, self::QUOTA_POST_STATUSES);',
        '$recent = $this->posts->lastOfUser($uid, gmdate(\'Y-m-d H:i:s\', $now - $forumSlow), null, null, self::QUOTA_POST_STATUSES);',
        'community-access', false, 'der Langsame Modus eines Forums gilt nur für Beiträge in diesem Forum', false, [],
    ],
    'slow-mode-topic-scope-dropped' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$recent = $this->posts->lastOfUser($uid, gmdate(\'Y-m-d H:i:s\', $now - $topicSlow), null, (int) $topic[\'id\'], self::QUOTA_POST_STATUSES);',
        '$recent = $this->posts->lastOfUser($uid, gmdate(\'Y-m-d H:i:s\', $now - $topicSlow), null, null, self::QUOTA_POST_STATUSES);',
        'community-access', false, 'der Langsame Modus eines Themas gilt nur für Beiträge in diesem Thema', false, [],
    ],
    'readable-assumes-no-rows' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$anyRows = $this->forums->hasPermissionRows(array_values($chainIds));',
        '$anyRows = false;',
        'community-access', false, 'Mengen-API: gibt es in den Ketten Rechte-Zeilen, gilt die Whitelist statt der Standards (B31)', false, [],
    ],
    'readable-assumes-rows' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '$anyRows = $this->forums->hasPermissionRows(array_values($chainIds));',
        '$anyRows = true;',
        'community-access', false, 'Mengen-API: ohne Rechte-Zeilen in allen Ketten genügt eine Abfrage für alle Foren (B31)', false, [],
    ],
    'readable-global-moderator-asks-matrix' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '            $visible = $globalModerator;' . "\n" . '            if (!$visible) {',
        '            $visible = false;' . "\n" . '            if (!$visible) {',
        'community-access', false, 'Mengen-API: globale Moderation sieht jedes Forum ohne Matrix- und Moderatorenabfrage je Forum (B31)', false, [],
    ],
    'hidden-ancestor-ignored' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'if ($ancestorId !== $forumId && !in_array((string) ($tree[$ancestorId][\'status\'] ?? \'\'), self::OPEN_FORUM_STATUSES, true)) {',
        'if (false) {',
        'community-access', false, 'ein verborgenes Elternforum verbirgt seine Unterforen', false, [],
    ],
    'search-needs-target' => [
        'core/Services/Community/Service/CommunityAccess.php',
        '        if (in_array($action, self::READ_ACTIONS, true)) {' . "\n" . '            return ($ctx[\'scope\']',
        '        if ($action === \'read\') {' . "\n" . '            return ($ctx[\'scope\']',
        'community-access', false, 'search ohne Ziel mit scope = global ist erlaubt (Menü, Suche)', false, [],
    ],
    'timestamp-overflow-valid' => [
        'core/Services/Community/Service/CommunityAccess.php',
        'if ($date === false || ($problems !== false && ($problems[\'warning_count\'] > 0 || $problems[\'error_count\'] > 0))) {',
        'if ($date === false) {',
        'community-access', false, 'ein Datum, das PHP nur durch Überlauf auflöst (2026-13-45), ist ungültig (fail-closed)', false, [],
    ],
    'subject-guest-rows-ignored' => [
        'core/Services/Community/Repository/ForumRepository.php',
        '$where = ["subject_type = \'guest\'"];',
        '$where = ["subject_type = \'guest\' AND 1 = 0"];',
        'community-access', false, 'Rechte-Zeilen des Subjekts guest gelten für jeden, auch für Angemeldete', false, [],
    ],
    'subject-role-ignored' => [
        'core/Services/Community/Repository/ForumRepository.php',
        'if ($role !== \'\') {' . "\n" . '            $where[] = "(subject_type = \'role\' AND subject_key = ?)";',
        'if (false) {' . "\n" . '            $where[] = "(subject_type = \'role\' AND subject_key = ?)";',
        'community-access', false, 'Rechte-Zeilen des Subjekts role:<rolle> gelten für Mitglieder dieser Rolle', false, [],
    ],
    'subject-role-any' => [
        'core/Services/Community/Repository/ForumRepository.php',
        '$where[] = "(subject_type = \'role\' AND subject_key = ?)";',
        '$where[] = "(subject_type = \'role\' AND subject_key <> ?)";',
        'community-access', false, 'Rechte-Zeilen einer Rolle gelten nur für Mitglieder genau dieser Rolle', false, [],
    ],
    'subject-group-ignored' => [
        'core/Services/Community/Repository/ForumRepository.php',
        'if ($groupKeys !== []) {' . "\n" . '            $where[] = "(subject_type = \'group\'',
        'if (false) {' . "\n" . '            $where[] = "(subject_type = \'group\'',
        'community-access', false, 'Rechte-Zeilen des Subjekts group:<id> gelten für Mitglieder aktiver Gruppen', false, [],
    ],
    'subject-group-any' => [
        'core/Services/Community/Repository/ForumRepository.php',
        '(subject_type = \'group\' AND subject_key IN (',
        '(subject_type = \'group\' AND subject_key NOT IN (',
        'community-access', false, 'Rechte-Zeilen einer Gruppe gelten nur für ihre Mitglieder', false, [],
    ],
    'subject-trust-min-exclusive' => [
        'core/Services/Community/Repository/ForumRepository.php',
        '(int) $key > $level)) {' . "\n" . '                continue;',
        '(int) $key >= $level)) {' . "\n" . '                continue;',
        'community-access', false, 'trust:N gilt ab Stufe N (die eigene Stufe eingeschlossen)', false, [],
    ],
    'subject-trust-any-level' => [
        'core/Services/Community/Repository/ForumRepository.php',
        '(preg_match(\'/^\\d+$/\', $key) !== 1 || (int) $key > $level)',
        '(preg_match(\'/^\\d+$/\', $key) !== 1)',
        'community-access', false, 'trust:N gilt nicht für Mitglieder unter Stufe N', false, [],
    ],
];
