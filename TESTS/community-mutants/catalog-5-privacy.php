<?php
/**
 * Katalog PR #5 (Datenschutz): Löschfristen (`CommunityRetention`) und Auskunft/Löschung (`CommunityPrivacy`), Suite `community-privacy`.
 *
 * Gleiches Format wie `catalog.php`: Name => [Datei relativ zu CMS/, Suchtext, Ersatz, Suite, needsDb, Regel, quick, env]
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

return [
    'retention-revisions-keep-latest' => ["core/Services/Community/CommunityRetention.php", "n.post_id = r.post_id AND n.id > r.id", "n.post_id = r.post_id AND n.id >= r.id", 'community-privacy', false, "Die jüngste Fassung je Beitrag bleibt trotz abgelaufener Frist", true, []],
    'retention-reports-open' => ["core/Services/Community/CommunityRetention.php", "status <> 'open' AND handled_at IS NOT NULL AND handled_at < ?", "created_at < ?", 'community-privacy', false, "Offene Meldungen werden nie nach Frist gelöscht (nur erledigte nach handled_at)", true, []],
    'retention-warnings-open' => ["core/Services/Community/CommunityRetention.php", "(expires_at IS NULL AND created_at < ?)", "(expires_at IS NULL)", 'community-privacy', false, "Unbefristete Verwarnungen fallen erst nach der Höchstdauer an (1825 Tage nach Anlage)", false, []],
    'privacy-ip-hash-kept' => ["core/Services/Community/CommunityPrivacy.php", "SET user_id = NULL, ip_hash = NULL WHERE user_id = ?", "SET user_id = NULL WHERE user_id = ?", 'community-privacy', false, "Die Löschung entfernt die IP-Kennung der Beiträge des Mitglieds", true, []],
    'privacy-ignores-one-way' => ["core/Services/Community/CommunityPrivacy.php", "WHERE user_id = ? OR ignored_user_id = ?", "WHERE user_id = ? AND ignored_user_id = ?", 'community-privacy', false, "Die Ignorierliste wird in beide Richtungen gelöscht (auch wer das Mitglied ignoriert)", false, []],
    'privacy-reaction-counter' => ["core/Services/Community/CommunityPrivacy.php", "\$db->execute(\"UPDATE {\$p}community_posts SET reaction_count = {\$down} WHERE id = ?\", [(int) \$row['post_id']]);", "\$db->execute(\"SELECT 1\");", 'community-privacy', false, "Reaktionen werden samt Zähler des Beitrags zurückgebucht", false, []],
    'privacy-bans-kept' => ["core/Services/Community/CommunityPrivacy.php", "'bans' => [\"DELETE FROM {\$p}community_bans WHERE user_id = ?\", [\$userId]],", "'bans' => [\"SELECT 1 WHERE ? > 0\", [\$userId]],", 'community-privacy', false, "Sperren des Mitglieds werden gelöscht (Spalte NOT NULL, nicht anonymisierbar)", true, []],
];
