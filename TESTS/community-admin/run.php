<?php
/**
 * Community-Admin (PR #3, Teil 1): Seiten `/admin/community*` – Einstiegsdateien, Capability-Gates, Ansicht ohne Inline-Skript,
 * CSRF in jedem POST-Formular, Einstellungsformular (Rundlauf über `Settings`), unbekannte Aktionen.
 * Die Entscheidungen selbst (Freigeben, Sperren, Erledigen …) prüfen `community-moderation` und `community-sanctions`.
 *
 *   php TESTS/community-admin/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'warning');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs-community-admin-' . getmypid() . '/');
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CMS\\')) {
        return;
    }
    $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once dirname(__DIR__) . '/lib/TestRunner.php';
require_once ABSPATH . 'admin/modules/community/CommunityAdminModule.php';

use CMS\Services\Community\Support\Settings;

$read = static fn (string $file): string => (string) file_get_contents(ABSPATH . $file);

T::run('Einstiegsdateien und Gates', static function () use ($read): void {
    $manage = ['ranks', 'settings', 'structure', 'permissions'];
    foreach (CommunityAdminModule::SECTIONS as $section => [$path, $title, $caps]) {
        $file = 'admin/' . substr($path, strlen('/admin/')) . '.php';
        T::check(is_file(ABSPATH . $file), $section . ': Einstiegsdatei ' . $file);
        $source = $read($file);
        T::check(str_contains($source, "'" . $section . "'"), $section . ': Bereichskennung in der Einstiegsdatei');
        T::check(str_contains($source, 'function cms_admin_community_can_access(): bool') && str_contains($source, 'isAdmin()'), $section . ': Gate mit isAdmin()');
        foreach ($caps as $cap) {
            T::check(str_contains($source, "hasCapability('" . $cap . "')"), $section . ': Gate prüft ' . $cap);
        }
        T::same(in_array($section, $manage, true) ? ['community.manage'] : true, in_array($section, $manage, true) ? $caps : true, $section . ': Einstellungen und Ränge nur mit community.manage');
    }
    $entry = $read('admin/modules/community/entry.php');
    T::check(str_contains($entry, 'CommunityModule::isEnabled()'), 'entry.php: Modul-Gate (ohne Modul zur Modulverwaltung)');
    T::check(str_contains($entry, "hasCapability(\$manage ? 'community.manage' : 'community.moderate')"), 'entry.php: Capability je Aktion beim POST');
    T::check(str_contains($entry, "'csrf_action' => 'admin_community'"), 'entry.php: CSRF-Aktion');
    T::check(str_contains($entry, 'array_flip([\'cursor\', \'type\', \'forum_id\', \'status\', \'action\', \'actor_id\'])'), 'entry.php: Rücksprung nur mit erlaubten Abfrageschlüsseln');
});

T::run('Rollen-Hooks und Hinweise (Quelltext)', static function () use ($read): void {
    $roles = $read('admin/modules/users/RolesModule.php');
    T::check(preg_match("/announceRoleChanges\\([^;]*ROLE_SOURCE_ROLE_RENAMED\\);\\s*\\\$this->announceRole\\('cms_role_renamed', \\\$currentRole, \\\$newRole\\)/", $roles) === 1, 'updateRole feuert cms_role_renamed nach dem Commit');
    T::check(preg_match("/announceRoleChanges\\([^;]*ROLE_SOURCE_ROLE_DELETED\\);\\s*\\\$this->announceRole\\('cms_role_deleted', \\\$role, \\\$fallbackRole\\)/", $roles) === 1, 'deleteRole feuert cms_role_deleted nach dem Commit');
    $hooks = $read('core/Services/FeatureHooks/CommunityHooks.php');
    $gate = strpos($hooks, 'if (!CommunityModule::isEnabled())');
    T::check($gate !== false && strpos($hooks, "'cms_role_renamed'") < $gate && strpos($hooks, "'cms_role_deleted'") < $gate, 'Listener für Rollen liegen vor dem Modul-Gate (wirken auch bei abgeschaltetem Modul)');
    T::check(strpos($hooks, "registerCheck('Community-Warteschlange'") > $gate, 'Hinweis „Warteschlange älter als 24 Stunden“ nur bei aktivem Modul');
    T::check(str_contains((string) file_get_contents(dirname(ABSPATH) . '/DOC/core/HOOKS-REFERENCE.md'), '`cms_role_renamed`') && str_contains((string) file_get_contents(dirname(ABSPATH) . '/DOC/core/HOOKS-REFERENCE.md'), '`cms_role_deleted`'), 'Hooks in HOOKS-REFERENCE.md dokumentiert');
});

T::run('Ansicht', static function () use ($read): void {
    $view = $read('admin/views/community/index.php');
    T::check(stripos($view, '<script') === false, 'kein Inline-Skript');
    T::check(preg_match('/\son[a-z]+\s*=\s*"/i', $view) !== 1, 'keine Inline-Ereignisattribute');
    T::check(substr_count($view, 'name="csrf_token"') === substr_count($view, '<form method="post"'), 'jedes POST-Formular (Einstellungen, Aktionen) trägt csrf_token');
    T::check(str_contains($view, '$formOpen = static function') && str_contains($view, 'name="csrf_token"'), 'Hilfsfunktion für Aktionsformulare trägt csrf_token');
    T::check(preg_match_all('/<form(?![^>]*method="(?:post|get)")/', $view) === 0, 'jedes Formular nennt seine Methode');
});

T::run('Aktionen', static function (): void {
    $module = new CommunityAdminModule('settings');
    $r = $module->handlePost(['action' => 'gibt-es-nicht'], 1);
    T::check($r['success'] === false && is_string($r['error'] ?? null), 'unbekannte Aktion wird abgelehnt');
    $r = $module->handlePost([], 1);
    T::check($r['success'] === false, 'fehlende Aktion wird abgelehnt');
    $r = (new CommunityAdminModule('topics'))->handlePost(['action' => 'bulk', 'operation' => 'topic.lock', 'ids' => []], 1);
    T::check($r['success'] === false, 'Sammelaktion ohne Auswahl wird abgelehnt, ohne einen Dienst anzufassen');
    $r = (new CommunityAdminModule('sanctions'))->handlePost(['action' => 'ban', 'user_id' => 5, 'days' => 5, 'reason' => 'x'], 1);
    T::check($r['success'] === false, 'Sperrdauer außerhalb der Auswahl wird abgelehnt');
    T::same('/admin/community-queue?cursor=abc', CommunityAdminModule::url('queue', ['cursor' => 'abc', 'type' => '']), 'url(): leere Parameter entfallen');
});

T::run('Struktur: Ziehen, Löschen und Gruppen in der Rechte-Matrix', static function () use ($read): void {
    foreach (['reorder', 'delete_category', 'delete_forum'] as $action) {
        T::check(in_array($action, CommunityAdminModule::MANAGE_ACTIONS, true), $action . ' verlangt community.manage');
    }
    $module = new CommunityAdminModule('structure');
    T::same(false, $module->handlePost(['action' => 'reorder', 'kind' => 'forum', 'order' => ''], 1)['success'] ?? null, 'reorder ohne IDs wird abgelehnt');
    T::same(false, $module->handlePost(['action' => 'reorder', 'kind' => 'thema', 'order' => '3,1'], 1)['success'] ?? null, 'reorder mit unbekannter Art wird abgelehnt');

    $view = $read('admin/views/community/index.php');
    T::check(str_contains($view, "\$formOpen('delete_' . \$kind") && str_contains($view, 'data-confirm-message='), 'Löschen über Aktionsformular mit Rückfrage');
    T::check(str_contains($view, "\$formOpen('reorder'") && str_contains($view, 'data-cc-sort="forum:') && str_contains($view, 'data-cc-grip'), 'Ziehen: versteckte Formulare je Ebene und Griff je Eintrag');
    T::check(str_contains($view, "\$subjects['group:' . (int) \$groupId]"), 'Rechte-Matrix: Benutzergruppen als Subjekt');
    $entry = $read('admin/modules/community/entry.php');
    T::check(str_contains($entry, "js/admin-community-structure.js"), 'Skript nur über page_assets (kein Inline-Skript)');
    $js = $read('assets/js/admin-community-structure.js');
    T::check(str_contains($js, 'form[data-cc-reorder]') && str_contains($js, 'sameGroup('), 'Skript: Ablegen nur auf derselben Ebene, Absenden über das Formular');

    $forumRepo = $read('core/Services/Community/Repository/ForumRepository.php');
    $delete = substr($forumRepo, (int) strpos($forumRepo, 'public function delete(int $id)'), 2200);
    T::check(strpos($delete, 'lockForUpdate($id)') < strpos($delete, 'WHERE parent_id = ?') && str_contains($delete, "'invalid_state'"), 'Forum löschen: Sperre, dann Prüfung auf Unterforen und Themen');
    $categoryRepo = $read('core/Services/Community/Repository/CategoryRepository.php');
    T::check(str_contains($categoryRepo, "community_forums') . ' WHERE category_id = ?'"), 'Kategorie löschen: nur ohne Foren');
});

T::run('Exportdateien der DSGVO-Auskunft: Liste, Herunterladen und Löschen nur für community.manage, nur gültige Namen', static function () use ($read): void {
    T::check(in_array('export_download', CommunityAdminModule::MANAGE_ACTIONS, true) && in_array('export_delete', CommunityAdminModule::MANAGE_ACTIONS, true), 'beide Aktionen verlangen community.manage (nicht die Moderation)');
    $module = new CommunityAdminModule('settings');
    foreach (['', '../../etc/passwd', 'community-x.json', "community-20260101-000000-abcdefabcdef.json\n", 'community-20260101-000000-abcdefabcdef.json/../x', 'COMMUNITY-20260101-000000-abcdefabcdef.json', 'community-20260101-000000-abcdefabcdeg.json', ['community-20260101-000000-abcdefabcdef.json'], null] as $bad) {
        $label = json_encode($bad);
        $download = $module->handlePost(['action' => 'export_download', 'file' => $bad], 1);
        $delete = $module->handlePost(['action' => 'export_delete', 'file' => $bad], 1);
        T::same([false, false], [$download['success'] ?? null, $delete['success'] ?? null], 'ungültiger Dateiname ' . $label . ': weder Download noch Löschen');
        T::same(null, \CMS\Services\Community\CommunityPrivacy::exportPath(is_string($bad) ? $bad : ''), 'exportPath(' . $label . '): null');
    }
    T::same(false, \CMS\Services\Community\CommunityPrivacy::deleteExport('../x.json'), 'deleteExport(): ungültiger Name');

    $privacy = $read('core/Services/Community/CommunityPrivacy.php');
    T::check(str_contains($privacy, "\\.json\$/D'"), 'der Dateiname wird streng geprüft (D: kein Zeilenumbruch am Ende)');
    $moduleSource = $read('admin/modules/community/CommunityAdminModule.php');
    $download = substr($moduleSource, (int) strpos($moduleSource, 'private function exportDownload'), 1700);
    T::check(str_contains($download, 'CommunityPrivacy::exportPath($name)') && strpos($download, 'AuditLogger') < strpos($download, 'readfile($path)'), 'Download: Pfad nur über exportPath(), Audit-Eintrag vor dem Senden');
    foreach (['Content-Type: application/json; charset=utf-8', 'Content-Disposition: attachment', 'X-Content-Type-Options: nosniff', 'Cache-Control: private, no-store'] as $header) {
        T::check(str_contains($download, $header), 'Download-Kopf: ' . $header);
    }
    $view = $read('admin/views/community/index.php');
    T::check(str_contains($view, 'id="community-exports"') && str_contains($view, "\$formOpen('export_download'") && str_contains($view, "\$formOpen('export_delete'"), 'Ansicht: Karte mit Herunterladen und Löschen über die Aktionsformulare (csrf_token)');
    T::check(str_contains($view, "(\$cd['exports'] ?? [])") && str_contains($view, 'Keine Exportdateien vorhanden.'), 'Ansicht: Liste und Leerzustand');
    T::check(str_contains($moduleSource, "'exports' => CommunityPrivacy::listExports()"), 'Einstellungsdaten enthalten die Dateiliste');
});

T::run('Einstellungen: Rundlauf', static function (): void {
    $store = [];
    Settings::setInstance(new Settings(static function () use (&$store): array {
        return $store;
    }, static function (array $changed) use (&$store): bool {
        $store = array_merge($store, $changed);

        return true;
    }));
    try {
        $post = [
            'action' => 'save_settings',
            's_base_path' => 'diskussion',
            's_guests_can_read' => '1',
            's_topics_per_page' => '9999',
            's_title_min' => '3',
            'limit' => [0 => ['posts_per_day' => '7', 'topics_per_hour' => '2']],
            'perm' => ['member' => ['read' => '1', 'reply' => '1']],
            'reaction_set' => ['like', 'bogus'],
            'attachment_types' => ['pdf' => '1'],
            'wf_block' => "foo\n\n bar \n",
            'wf_replacement' => '###',
        ];
        $r = (new CommunityAdminModule('settings'))->handlePost($post, 1);
        T::check(!empty($r['success']), 'Speichern gelingt');
        $s = Settings::getInstance();
        T::same('diskussion', $s->string('base_path'), 'Textwert übernommen');
        T::same(true, $s->bool('guests_can_read'), 'Schalter an');
        T::same(false, $s->bool('require_verified_email'), 'nicht angekreuzter Schalter wird zu aus');
        T::same(100, $s->int('topics_per_page'), 'Zahl auf Höchstwert geklemmt');
        T::same(3, $s->int('title_min'), 'Zahl übernommen');
        T::same(['like'], $s->map('reaction_set'), 'unbekannte Reaktion verworfen');
        T::same(1, $s->map('attachment_types')['pdf'] ?? null, 'erlaubter Dateityp');
        T::same(0, $s->map('attachment_types')['jpg'] ?? null, 'nicht angekreuzter Dateityp aus');
        T::same(['foo', 'bar'], $s->map('word_filter')['block'] ?? null, 'Wortfilter: Zeilen getrimmt, leere entfallen');
        T::same('###', $s->map('word_filter')['replacement'] ?? null, 'Ersatztext');
        T::same(7, $s->map('trust_limits')[0]['posts_per_day'] ?? null, 'Grenze je Vertrauensstufe');
        T::same(1, $s->map('default_permissions')['member']['reply'] ?? null, 'Standardrecht gesetzt');
        T::same(0, $s->map('default_permissions')['member']['upload'] ?? null, 'Standardrecht ohne Haken aus');
    } finally {
        Settings::setInstance(null);
    }
});

T::finish();
