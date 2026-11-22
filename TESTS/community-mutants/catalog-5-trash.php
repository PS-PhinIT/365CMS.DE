<?php
/**
 * Katalog PR #5 (Papierkorb): `TopicTrashService`, `TrashRepository`, `CommunityContentProvider` und das Löschen in `TopicService`, Suite `community-trash`.
 *
 * Gleiches Format wie `catalog.php`: Name => [Datei relativ zu CMS/, Suchtext, Ersatz, Suite, needsDb, Regel, quick, env]
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

return [
    'trash-restore-counters' => ["core/Services/Community/Service/TopicTrashService.php", "\$this->book->topicPublished(\$forum, ['status' => 'published'] + \$topic, \$plan, \$now);", "\$now;", 'community-trash', false, "Wiederherstellen bucht die Zähler zurück (Umkehrung des Löschens, Semantik S1)", true, []],
    'trash-restore-rejected' => ["core/Services/Community/Service/TopicTrashService.php", "return (string) (\$topic['previous_status'] ?? '') === 'pending' && \$first !== null && (string) \$first['status'] === 'rejected';", "return false;", 'community-trash', false, "Abgelehnte Themen lassen sich nicht wiederherstellen", true, []],
    'trash-restore-clears-trashed' => ["core/Services/Community/Service/TopicTrashService.php", "'previous_status' => null, 'trashed_at' => null, 'trashed_by' => null, 'updated_at' => \$now]", "'previous_status' => null, 'updated_at' => \$now]", 'community-trash', false, "Wiederherstellen leert Zeitpunkt und Handelnden des Papierkorbs", true, []],
    'trash-restore-previous-status' => ["core/Services/Community/Service/TopicTrashService.php", "['status' => \$previous, 'previous_status' => null,", "['status' => 'published', 'previous_status' => null,", 'community-trash', false, "Wiederherstellen stellt den vorherigen Status her (ausgeblendet bleibt ausgeblendet, wartend bleibt wartend)", true, []],
    'trash-restore-log' => ["core/Services/Community/Service/TopicTrashService.php", "'action' => 'topic.restore',", "'action' => 'topic.hide',", 'community-trash', false, "Wiederherstellen wird als topic.restore protokolliert", false, []],
    'trash-purge-reaction-stats' => ["core/Services/Community/Service/TopicTrashService.php", "\$this->stats->bumpReactions(\$member, -(\$totals['received'][\$member] ?? 0), -(\$totals['given'][\$member] ?? 0), \$now);", "\$this->stats->bumpReactions(\$member, 0, 0, \$now);", 'community-trash', false, "Endgültig löschen bucht die Reaktionszähler der Mitglieder zurück", true, []],
    'trash-purge-files' => ["core/Services/Community/Service/TopicTrashService.php", "(\$this->removeFiles)(\$attachments);", "\$attachments;", 'community-trash', false, "Endgültig löschen entfernt die Dateien der Anhänge", true, []],
    'trash-purge-unindex' => ["core/Services/Community/Service/TopicTrashService.php", "(\$this->unindex)(\$postIds);", "\$postIds;", 'community-trash', false, "Endgültig löschen nimmt die Beiträge aus dem Suchindex", true, []],
    'trash-purge-forum-last' => ["core/Services/Community/Service/TopicTrashService.php", "if (\$forum !== null && (int) (\$forum['last_topic_id'] ?? 0) === \$topicId) {", "if (false) {", 'community-trash', false, "Zeigt das Forum noch auf das gelöschte Thema, wird „letztes Thema“ neu bestimmt", false, []],
    'trash-expired-utc' => ["core/Services/Community/CommunityContentProvider.php", "\$trashedBefore->setTimezone(new \\DateTimeZone('UTC'))->format('Y-m-d H:i:s')", "\$trashedBefore->format('Y-m-d H:i:s')", 'community-trash', false, "Die Fristgrenze des Core (lokale Zeit) wird in UTC umgerechnet (gespeichert wird UTC)", true, []],
    'trash-expired-stamp' => ["core/Services/Community/Service/TopicTrashService.php", "\$this->trash->stampMissing(gmdate('Y-m-d H:i:s', (\$this->clock)()));", "\$this->clock;", 'community-trash', false, "Themen ohne Zeitstempel bekommen beim ersten Fristlauf den aktuellen Zeitpunkt", false, []],
    'trash-list-order' => ["core/Services/Community/Repository/TrashRepository.php", "ORDER BY (trashed_at IS NULL) ASC, trashed_at DESC, id DESC LIMIT ?", "ORDER BY trashed_at ASC, id DESC LIMIT ?", 'community-trash', false, "Zuletzt gelöscht zuerst, Themen ohne Zeitstempel zuletzt", true, []],
    'trash-search-escape' => ["core/Services/Community/Repository/TrashRepository.php", "strtr(\$search, ['!' => '!!', '%' => '!%', '_' => '!_'])", "\$search", 'community-trash', false, "Die Suche behandelt % und _ als Zeichen, nicht als Platzhalter", false, []],
    'trash-purge-reports' => ["core/Services/Community/Repository/TrashRepository.php", "target_type = 'post' AND target_id IN ({\$in})\", \$chunk);", "target_type = 'x' AND target_id IN ({\$in})\", \$chunk);", 'community-trash', false, "Endgültig löschen entfernt die Meldungen zu den Beiträgen", true, []],
    'trash-purge-stubs' => ["core/Services/Community/Repository/TrashRepository.php", "status = 'moved' AND moved_to_topic_id = ? ORDER BY id ASC LIMIT ?", "status = 'zzz' AND moved_to_topic_id = ? ORDER BY id ASC LIMIT ?", 'community-trash', false, "Endgültig löschen entfernt die Verweis-Themen in anderen Foren", false, []],
    'topic-delete-trashed' => ["core/Services/Community/Service/TopicService.php", "'previous_status' => \$previous, 'trashed_at' => \$now, 'trashed_by' => \$userId, 'updated_at' => \$now]", "'previous_status' => \$previous, 'updated_at' => \$now]", 'community-trash', false, "Löschen merkt Zeitpunkt und Handelnden (Fristablauf des Papierkorbs)", true, []],
    'topic-delete-counters' => ["core/Services/Community/Service/TopicService.php", "\$this->book->topicUnpublished(\$forum, ['status' => 'deleted'] + \$topic, \$plan, \$now);", "\$now;", 'community-trash', false, "Löschen bucht Forum, Starter und Antwortautoren ab (Semantik S1, keine Drift)", true, []],
    'trash-provider-capability' => ["core/Services/Community/CommunityContentProvider.php", "return CommunityCapabilities::MANAGE;", "return 'manage_trash';", 'community-trash', false, "Der Typ ist nur für Administratoren (community.manage)", false, []],
    'trash-provider-warning' => ["core/Services/Community/CommunityContentProvider.php", "return \$reports > 0 ?", "return \$reports >= 0 ?", 'community-trash', false, "Ohne offene Meldung gibt es keine Löschwarnung (sonst bliebe alles im Fristlauf liegen)", true, []],
    'trash-provider-confirm' => ["core/Services/Community/CommunityContentProvider.php", "if (\$warnings !== [] && !\$confirmed) {", "if (\$warnings !== [] && false) {", 'community-trash', false, "Mit Warnung löscht der Papierkorb nur nach Bestätigung (der Fristlauf nie)", true, []],
];
