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
    'legal-captcha-only-when-required' => ["core/Services/Community/CommunityLegal.php", "if (\$mode !== 'off') {", "if (true) {", 'community-privacy', false, "Das Captcha steht nur im Text, wenn es verlangt wird", true, []],
    'legal-ip-hash-only-when-stored' => ["core/Services/Community/CommunityLegal.php", "if (\$days['ip_hash'] > 0) {", "if (true) {", 'community-privacy', false, "Die IP-Kennung steht nur im Text, wenn sie gespeichert wird", true, []],
    'legal-notify-mail' => ["core/Services/Community/CommunityLegal.php", "if (!empty(\$facts['notify_mail'])) {", "if (true) {", 'community-privacy', false, "Benachrichtigungsmails stehen nur im Text, wenn sie an sind", false, []],
    'legal-guests' => ["core/Services/Community/CommunityLegal.php", "(!empty(\$facts['guests_can_read'])", "(empty(\$facts['guests_can_read'])", 'community-privacy', false, "Die Sichtbarkeit im Text folgt guests_can_read", false, []],
    'legal-zero-days' => ["core/Services/Community/CommunityLegal.php", "if (\$days === 0) {\n            return 'ohne feste Frist';", "if (\$days === -1) {\n            return 'ohne feste Frist';", 'community-privacy', false, "Eine Frist von 0 heißt „ohne feste Frist“", false, []],
    'legal-revisions-days' => ["core/Services/Community/CommunityLegal.php", "self::after(\$days['revisions'], 'der Bearbeitung')", "self::after(\$days['reports'], 'der Bearbeitung')", 'community-privacy', false, "Jede Frist des Textes ist die der Aufbewahrungsregel", false, []],
];
