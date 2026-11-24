<?php
/**
 * Audit 4.2.0 (AP-17, Punkt 17.1): Beim endgültigen Löschen eines Kontos gehen die Datensätze ohne Fremdschlüssel mit.
 * Läuft gegen SQLite im Speicher, ohne MariaDB. Wird von TESTS/security/run.php eingebunden.
 */

declare(strict_types=1);

use CMS\Services\UserDataEraser;

T::run('AP-17 / 17.1: Konto-Löschung räumt Tabellen ohne Fremdschlüssel auf', static function (): void {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        T::info('pdo_sqlite fehlt: Prüfung übersprungen');

        return;
    }
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $prefix = 'cms_';
    $existing = array_slice(UserDataEraser::TABLES, 0, -1); // die letzte Tabelle fehlt absichtlich (Altinstallation)
    foreach ($existing as $table) {
        $pdo->exec("CREATE TABLE {$prefix}{$table} (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, note TEXT)");
        $pdo->exec("INSERT INTO {$prefix}{$table} (user_id, note) VALUES (7, 'weg'), (7, 'weg'), (8, 'bleibt')");
    }
    $pdo->exec("CREATE TABLE {$prefix}orders (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL)");
    $pdo->exec("INSERT INTO {$prefix}orders (user_id) VALUES (7)");

    $result = UserDataEraser::erase($pdo, $prefix, 7);

    foreach ($existing as $table) {
        T::same(2, $result[$table] ?? null, $table . ': beide Zeilen des Kontos gelöscht');
        T::same(['8'], array_map('strval', $pdo->query("SELECT user_id FROM {$prefix}{$table}")->fetchAll(PDO::FETCH_COLUMN)), $table . ': Zeilen anderer Konten bleiben');
    }
    T::check(!array_key_exists(UserDataEraser::TABLES[count(UserDataEraser::TABLES) - 1], $result), 'fehlende Tabelle bricht nicht ab und fehlt im Ergebnis');
    T::same('1', (string) $pdo->query("SELECT COUNT(*) FROM {$prefix}orders")->fetchColumn(), 'Bestellungen (Aufbewahrungspflicht) bleiben unberührt');
    T::same([], UserDataEraser::erase($pdo, $prefix, 0), 'Konto-ID 0 löscht nichts');
    T::same([], UserDataEraser::erase($pdo, "cms_'; DROP TABLE x; --", 7), 'unzulässiges Präfix löscht nichts');
});

T::run('AP-17 / 17.1: UserService ruft die Bereinigung beim endgültigen Löschen auf', static function (): void {
    $source = str_replace("\r\n", "\n", (string) file_get_contents(ABSPATH . 'core/Services/UserService.php'));
    $start = strpos($source, 'public function deleteUser(');
    $hard = $start === false ? false : strpos($source, 'if ($hard_delete) {', $start);
    $erase = $hard === false ? false : strpos($source, 'UserDataEraser::erase(', $hard);
    $hook = $hard === false ? false : strpos($source, "Hooks::doAction('cms_user_deleted'", $hard);
    T::check($erase !== false && $hook !== false && $erase < $hook, 'Bereinigung läuft im Zweig „endgültig löschen“ vor dem Hook cms_user_deleted');
    $soft = $hard === false ? false : strpos($source, '} else {', (int) $hook);
    T::check($soft !== false && $erase < $soft, 'nicht im Zweig „deaktivieren“');
});
