<?php
/**
 * Katalog der Einheit A (PR #2b, Welle 1): `CommunityAccess` und `ForumRepository` laut Vertrag DESIGN-2b §3.5 und §3.7.
 *
 * Gleiches Format wie `catalog.php`: Name => [Datei relativ zu CMS/, Suchtext, Ersatz, Suite, needsDb, Regel, quick, env]
 *
 * Herkunft der Zeilen: medien §6.4 (`access-category-*`, `access-bulk-*`), moderation §6.4 (`access-move-*`, `access-*-deleted-topic`,
 * `access-unannounce-local`), sanktionen §6.5 (`ban-temp-cap-ban`, `member-report-*`, `authority-manage-rank`, `moderated-banned-sees`,
 * `mod-assign-no-groups`), interaktion §6.4 (`react-published-only`), schutz §6.4 (`content-*`, `review-*`, `edit-review-ignored`,
 * `limit-zero-unlimited`), Vertrag §7.4 (`move-target-invisible`, `scope-archived-dropped`). Zusammengelegt (Vertrag K5):
 * `access-scope-children` und `moderated-no-children` gehen in `access-bulk-children` auf. Die übrigen Zeilen sichern Regeln dieser
 * Einheit ohne Vorlage im Entwurf. Nicht hier: `ban-equal-rank` (Ziel `community-sanctions`, Katalog der Einheit K),
 * `limit-below-minus-one-unlimited` (Ziel `Support/Settings.php`, Einheit S; `community-access` erkennt sie im Abschnitt
 * „Wirkung: trust_limits“), `race-review-cap-db` (braucht die Dienste, W4).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

$access = 'core/Services/Community/Service/CommunityAccess.php';
$forums = 'core/Services/Community/Repository/ForumRepository.php';

return [
    // ── Kategorie und Mengen-API (medien §2.8)
    'access-category-ignored' => [$access, 'if ((int) ($tree[$id][\'category_visible\'] ?? 0) !== 1) {', 'if (false) {', 'community-access', false, 'eine unsichtbare oder fehlende Kategorie des Forums oder eines Vorfahren verbirgt das Forum', true, []],
    'access-category-missing-open' => [$forums, 'WHEN c.id IS NULL THEN 0', 'WHEN c.id IS NULL THEN 1', 'community-access', false, 'eine fehlende Kategorie verbirgt das Forum (fail-closed)', false, []],
    'access-category-zero-hidden' => [$forums, 'WHEN f.category_id = 0 THEN 1', 'WHEN f.category_id = 0 THEN 0', 'community-access', false, 'Foren ohne Kategorie (category_id = 0) sind sichtbar', false, []],
    'access-bulk-has-rows' => [$access, '$has = array_intersect($chain, $withRows) !== [];', '$has = true;', 'community-access', false, 'Mengen-API: ohne Rechte-Zeilen in der Kette gelten die Standards', false, []],
    'access-bulk-children' => [$forums, '$assignment[\'include_children\'] === 1', 'true', 'community-access', false, 'eine Zuordnung auf einem Vorfahren gilt nur mit include_children = 1 (Mengen-API, Moderationsbereich)', false, []],
    'access-bulk-assignments-per-forum' => [$access, '$assignments ??= $this->forums->moderatorAssignments(', '$assignments = $this->forums->moderatorAssignments(', 'community-access', false, 'Mengen-API: Moderator-Zuordnungen einmal je Aufruf, höchstens zehn Abfragen unabhängig von der Forenzahl', false, []],

    // ── Moderationsaktionen und Ziel des Verschiebens (moderation §6.4, Vertrag §3.5 Nr. 5)
    'access-move-same-forum' => [$access, 'if ($action === \'topic.move\' && $targetId === (int) $forum[\'id\']) {', 'if (false) {', 'community-access', false, 'topic.move mit Ziel = Quelle ergibt invalid_target', false, []],
    'access-move-link-target' => [$access, 'if ((string) $targetForum[\'type\'] === \'link\') {', 'if (false) {', 'community-access', false, 'ein Zielforum vom Typ link ergibt forum_link', false, []],
    'access-mod-deleted-topic' => [$access, '\'deleted\', \'trash\' => $action === \'post.reject\' ? null : \'topic_deleted\',', '\'deleted\', \'trash\' => null,', 'community-access', false, 'Moderationsaktionen in gelöschten Themen ergeben topic_deleted', false, []],
    'access-reject-deleted-topic' => [$access, '$action === \'post.reject\' ? null : \'topic_deleted\'', '\'topic_deleted\'', 'community-access', false, 'post.reject bleibt in gelöschten Themen möglich', false, []],
    'access-unannounce-local' => [$access, 'GLOBAL_ONLY = [\'topic.announce\', \'topic.unannounce\'];', 'GLOBAL_ONLY = [\'topic.announce\'];', 'community-access', false, 'topic.unannounce nur für die globale Moderation', false, []],
    'move-target-invisible' => [$access, 'return AccessDecision::deny(\'not_found\'); // Zielforum unsichtbar', 'return AccessDecision::deny(\'no_permission\');', 'community-access', false, 'ein unsichtbares Zielforum ergibt not_found wie ein fehlendes (404-Regel)', false, []],
    'move-target-id-loose' => [$access, 'return is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : 0);', 'return (int) $value;', 'community-access', false, 'Ziel- und Mitglieds-IDs nur als int oder Ziffernfolge (Text mit Rest, Array, Kommazahl = Köder)', false, []],

    // ── Sperren, Melden, Rang (sanktionen §6.5)
    'ban-temp-cap-ban' => [$access, '\'ban_temporary\' => CommunityCapabilities::MODERATE,', '\'ban_temporary\' => CommunityCapabilities::BAN,', 'community-access', false, 'ban_temporary braucht community.moderate (globale Moderation)', false, []],
    'member-report-self' => [$access, 'if ($memberId === $uid) {', 'if ($memberId === -1) {', 'community-access', false, 'man meldet sich nicht selbst', false, []],
    'member-report-inactive' => [$access, 'if (!$target[\'member\']) {', 'if ($target[\'id\'] < 0) {', 'community-access', false, 'nur aktive Konten sind meldbar; unbekannt und inaktiv ergeben not_found', false, []],
    'member-report-extra-target' => [$access, 'if (array_intersect(self::TARGET_KEYS, array_keys($ctx)) !== []) {', 'if (false) {', 'community-access', false, 'report mit member_id erlaubt keinen weiteren Zielschlüssel', false, []],
    'member-report-fresh' => [$access, 'unset($this->accounts[$memberId]);', '', 'community-access', false, 'fresh liest beim Melden auch das Zielkonto neu', false, []],
    'authority-manage-rank' => [$access, '|| $this->roleCapability($role, CommunityCapabilities::MANAGE)', '|| false', 'community-access', false, 'community.manage allein ist Rangstufe 3', false, []],
    'authority-error-member' => [$access, '$this->log(\'Rang nicht ermittelbar: {exception}\', [\'exception\' => $e]);' . "\n\n" . '            return null;', 'return self::AUTHORITY_MEMBER;', 'community-access', false, 'ein Fehler bei der Rangermittlung ergibt null, nie einen Rang (fail-closed)', false, []],
    'moderated-banned-sees' => [$access, 'if ($gate->reason !== \'no_permission\') {', 'if (false) {', 'community-access', false, 'Modul aus, Sperre, E-Mail-Pflicht oder fehlendes community.view: leerer Moderationsbereich', false, []],

    // ── Moderationsbereich (Vertrag §3.5)
    'scope-archived-dropped' => [$access, 'if (ForumRepository::moderates($this->ancestorIds($forumId), $forumId, $assignments)', 'if ((string) ($this->forums->tree()[$forumId][\'status\'] ?? \'\') !== \'archived\' && ForumRepository::moderates($this->ancestorIds($forumId), $forumId, $assignments)', 'community-access', false, 'archivierte Foren bleiben im Moderationsbereich (K6)', false, []],
    'scope-context-unfiltered' => [$access, '$ctx = array_intersect_key($context, self::SCOPE_CONTEXT);', '$ctx = $context;', 'community-access', false, 'moderationScope: Kontext nur now und fresh, ein Ziel macht keine globale Moderation', false, []],
    'scope-filter-skipped' => [$access, 'return $this->applyFilter(AccessDecision::allow(), $uid, \'moderate\', [\'forum_id\' => $forumId] + $filterContext)->allowed;', 'return true;', 'community-access', false, 'moderationScope: der Filter cms_community_can mit moderate entfernt Foren', false, []],
    'scope-global-despite-filter' => [$access, '\'global\' => count($forumIds) === count($tree)', '\'global\' => true', 'community-access', false, 'entfernt der Filter ein Forum, ist der Bereich nicht mehr global', false, []],

    // ── Inhalt, Prüfpflicht, Reaktionen (schutz §6.4, interaktion §6.4)
    'react-published-only' => [$access, '$action === \'react\' && ((string) ($post[\'status\'] ?? \'\') !== \'published\'', 'false && ((string) ($post[\'status\'] ?? \'\') !== \'published\'', 'community-access', false, 'Reaktionen nur auf veröffentlichte Beiträge in veröffentlichten Themen, auch für die Moderation', false, []],
    'content-limit-off-by-one' => [$access, 'if ($count > $allowed) {', 'if ($count > $allowed + 1) {', 'community-access', false, 'Inhaltsgrenze: die Grenze selbst ist erlaubt, eins darüber nicht', true, []],
    'content-unlimited-as-zero' => [$access, 'if ($limit === Settings::UNLIMITED) {', 'if ($limit <= 0) {', 'community-access', false, 'Inhaltsgrenze 0 heißt keine, nur -1 ist unbegrenzt', false, []],
    'content-moderation-not-exempt' => [$access, 'if (!$moderating && in_array($action, self::CONTENT_LIMITED_ACTIONS, true)) {', 'if (in_array($action, self::CONTENT_LIMITED_ACTIONS, true)) {', 'community-access', false, 'Moderation unterliegt keinen Inhaltsgrenzen und keiner Prüfpflicht', false, []],
    'content-edit-no-grandfather' => [$access, '$allowed = max($limit, self::contentCount($before, $key, 0));', '$allowed = $limit;', 'community-access', false, 'Bearbeiten: Grenze ist max(Grenze, Vorfassung)', false, []],
    'content-malformed-open' => [$access, 'self::contentCount($content, $key, PHP_INT_MAX)', 'self::contentCount($content, $key, 0)', 'community-access', false, 'unvollständiger oder ungültiger Inhalt wird abgelehnt (fail-closed)', false, []],
    'content-before-not-only-edit' => [$access, '$before = $action === \'edit\' ? $run[\'content_before\'] : null;', '$before = $run[\'content_before\'];', 'community-access', false, 'content_before gilt nur beim Bearbeiten', false, []],
    'content-missing-checked' => [$access, 'if (!$run[\'has_content\']) {', 'if (false) {', 'community-access', false, 'ohne content im Kontext keine Inhaltsprüfung (Menüabfrage)', false, []],
    'review-ignored' => [$access, 'if ($review) {' . "\n" . '            return \'pending\';', 'if (false) {' . "\n" . '            return \'pending\';', 'community-access', false, 'review macht neue Inhalte wartend', true, []],
    'review-below-bypass' => [$access, 'if ($review) {' . "\n" . '            return \'pending\';' . "\n" . '        }' . "\n" . '        if ($matrix[\'bypass_approval\']) {' . "\n" . '            return \'published\';' . "\n" . '        }', 'if ($matrix[\'bypass_approval\']) {' . "\n" . '            return \'published\';' . "\n" . '        }' . "\n" . '        if ($review) {' . "\n" . '            return \'pending\';' . "\n" . '        }', 'community-access', false, 'review geht bypass_approval vor', false, []],
    'review-beats-moderation' => [$access, 'if ($moderating) {' . "\n" . '            return \'published\';' . "\n" . '        }' . "\n" . '        if ($review) {' . "\n" . '            return \'pending\';' . "\n" . '        }', 'if ($review) {' . "\n" . '            return \'pending\';' . "\n" . '        }' . "\n" . '        if ($moderating) {' . "\n" . '            return \'published\';' . "\n" . '        }', 'community-access', false, 'Moderation geht review vor', false, []],
    'review-loose' => [$access, '\'review\' => ($ctx[\'review\'] ?? false) === true,', '\'review\' => !empty($ctx[\'review\']),', 'community-access', false, 'review wirkt nur bei === true', false, []],
    'edit-review-ignored' => [$access, 'if ($action === \'edit\' && $run[\'review\'] && (string) ($post[\'status\'] ?? \'\') !== \'pending\') {', 'if (false) {', 'community-access', false, 'Bearbeiten mit Prüfpflicht an einem nicht wartenden Beitrag ergibt content_review (I-33)', false, []],
    'limit-zero-unlimited' => [$access, '$limits[\'posts_per_day\'] !== Settings::UNLIMITED', '$limits[\'posts_per_day\'] > 0', 'community-access', false, 'posts_per_day = 0 heißt keine Beiträge, nur -1 ist unbegrenzt', false, []],

    // ── ForumRepository (Vertrag §3.7)
    'mod-assign-no-groups' => [$forums, '" OR (subject_type = \'group\' AND subject_id IN ("', '" OR (1 = 0 AND subject_id IN ("', 'community-repositories', false, 'moderatorAssignments: Zuordnungen über Gruppen zählen', false, []],
    'mod-assign-children-overwritten' => [$forums, '$merged[$forumId] = max($merged[$forumId] ?? 0, (int) $row[\'include_children\'] === 1 ? 1 : 0);', '$merged[$forumId] = (int) $row[\'include_children\'] === 1 ? 1 : 0;', 'community-repositories', false, 'moderatorAssignments: je Forum eine Zeile, include_children = 1, wenn eine Zuordnung es setzt', false, []],
    'permission-forums-cache-kept' => [$forums, '$this->permissionForums = null; // Foren mit Rechte-Zeilen neu lesen', '', 'community-repositories', false, 'savePermissions leert den Zwischenspeicher von permissionForumIds', true, []],
    'first-per-subject-foreign' => [$forums, 'if (!isset($rank[$forumId])) {', 'if (false) {', 'community-repositories', false, 'firstPerSubject: Zeilen von Foren außerhalb der Kette zählen nicht', true, []],
    'forum-touch-unsorted' => [$forums, 'sort($ids);', '', 'community-repositories', false, 'touch: IDs aufsteigend (Sperrreihenfolge)', false, []],
];
