# Community: Datenmodell

> Schema `v27`, Release 4.2.0

`SchemaManager::FEATURE_TABLES_V27` ist die verbindliche Liste. In 4.2.0 sind implementiert:

1. `community_categories`
2. `community_forums`
3. `community_forum_permissions`
4. `community_forum_moderators`
5. `community_topics`
6. `community_posts`
7. `community_post_revisions`
8. `community_attachments`
9. `community_drafts`
10. `community_reactions`
11. `community_subscriptions`
12. `community_read_states`
13. `community_forum_read_marks`
14. `community_polls`
15. `community_poll_options`
16. `community_poll_votes`
17. `community_ignores`
18. `community_user_stats`
19. `community_ranks`
20. `community_badges`
21. `community_user_badges`
22. `community_reports`
23. `community_warnings`
24. `community_bans`
25. `community_moderation_log`
26. `community_stats_daily`
27. `community_redirects`
28. `community_notify_queue`

- [x] Neuinstallation und Migrationsweg verwenden dieselben Definitionen.
- [x] Pflichtspalten und Indizes werden zusätzlich geprüft.
- [x] `notifications` erhält `group_key` und `event_count`.
- [x] `community_badges.description_en` ist Teil des v27-Vertrags.
- [x] Tabellen, Indizes und Snapshot werden von `TESTS/schema-v27/` geprüft.

