<?php
/**
 * Release-Prüfung 4.1.0, Performance-Befunde Cluster „blocking“ (blocking:0, 1, 2, 3, 4, 5, 9, 10, 11):
 * blockierende Loopbacks, Remote-Prüfungen, DNS, Session-Sperre, Login-Wartezeit, SMTP-Timeout,
 * Verzeichnisläufe und IndexNow nach der Antwort.
 *
 * Eingebunden von TESTS/release-4.1.0/run.php (ABSPATH, CMS-Autoloader und $src sind dort definiert).
 * Ohne Datenbank: reine Funktionen, ein Kindprozess für die Session-Sperre, sonst Quelltext-Verträge.
 */

declare(strict_types=1);

T::run('PERF blocking:4: Anmeldeverzögerung ohne sleep() im Request', static function () use ($src): void {
    T::same(0, \CMS\Auth::accountFailureDelaySeconds(4), 'die ersten fünf Fehlversuche ohne Wartezeit');
    T::same(1, \CMS\Auth::accountFailureDelaySeconds(5), 'danach 1 s');
    T::same(30, \CMS\Auth::accountFailureDelaySeconds(40), 'höchstens 30 s');

    $now = 1_000_000;
    T::same(['allow' => true, 'not_before' => null, 'wait' => 0], \CMS\Auth::resolveAccountDelayTicket(null, $now, 0), 'ohne Verzögerung frei');

    $first = \CMS\Auth::resolveAccountDelayTicket(null, $now, 8);
    T::same(['allow' => false, 'not_before' => $now + 8, 'wait' => 8], $first, 'erster Versuch startet die Wartezeit und antwortet sofort');

    $early = \CMS\Auth::resolveAccountDelayTicket($now + 8, $now + 3, 30);
    T::same(['allow' => false, 'not_before' => $now + 8, 'wait' => 5], $early, 'früher Versuch verschiebt die Freigabe nicht (auch bei gestiegener Verzögerung)');

    T::same(true, \CMS\Auth::resolveAccountDelayTicket($now + 8, $now + 8, 30)['allow'], 'nach Ablauf genau eine Prüfung frei');
    T::same(true, \CMS\Auth::resolveAccountDelayTicket($now + 8, $now + 8 + 600, 30, 600)['allow'], 'Freigabe bis zum Ende der Gültigkeit nutzbar');

    $expired = \CMS\Auth::resolveAccountDelayTicket($now + 8, $now + 8 + 601, 16, 600);
    T::same(['allow' => false, 'not_before' => $now + 8 + 601 + 16, 'wait' => 16], $expired, 'verfallene Freigabe startet eine neue Wartezeit');

    $auth = $src('core/Auth.php');
    T::check(str_contains($auth, '$accountDelayMessage = $this->delayRepeatedAccountFailures($accountKey, $username);')
        && str_contains($auth, 'return $accountDelayMessage;'), 'login() antwortet sofort mit der Wartemeldung');
    T::check(preg_match('/private function delayRepeatedAccountFailures\(string \$accountKey, string \$loginIdentifier\): \?string/', $auth) === 1, 'Verzögerung liefert eine Meldung statt zu schlafen');
    T::check(substr_count($auth, 'sleep($delay);') === 1 && str_contains($auth, 'if (session_status() !== PHP_SESSION_ACTIVE) {'), 'sleep() nur noch ohne Session (bisheriges Verhalten)');
    T::check(str_contains($auth, 'unset($tickets[$ticketId]);'), 'Freigabe wird beim Verbrauch entfernt (einmalig)');
    $verifyPos = strpos($auth, '$security->verifyPassword($password, $user->password)');
    $delayPos = strpos($auth, '$accountDelayMessage = $this->delayRepeatedAccountFailures(');
    T::check($delayPos !== false && $verifyPos !== false && $delayPos < $verifyPos, 'Wartezeit weiterhin vor der Passwortprüfung');
});

T::run('PERF blocking:3: Session-Sperre für langsame Lesezugriffe freigeben', static function () use ($src): void {
    $scratch = sys_get_temp_dir() . '/cms-perf-blocking-' . bin2hex(random_bytes(4));
    @mkdir($scratch, 0700, true);
    $script = $scratch . '/session-lock.php';
    $lockFile = ABSPATH . 'core/Http/SessionLock.php';
    file_put_contents($script, '<?php
define("ABSPATH", ' . var_export(ABSPATH, true) . ');
require ' . var_export($lockFile, true) . ';
ini_set("session.save_path", ' . var_export($scratch, true) . ');
ini_set("session.use_cookies", "0");
ini_set("session.cache_limiter", "");
session_start();
$_SESSION["a"] = 1;
$file = ' . var_export($scratch, true) . ' . "/sess_" . session_id();
$during = CMS\Http\SessionLock::withoutLock(static function () use ($file): array {
    $handle = fopen($file, "r");
    $free = flock($handle, LOCK_EX | LOCK_NB);
    flock($handle, LOCK_UN);
    fclose($handle);
    file_put_contents($file, "a|i:1;b|i:2;");
    return [session_status(), $free];
});
$_SESSION["c"] = 3;
session_write_close();
echo json_encode([$during, $_SESSION, file_get_contents($file)]);
');
    $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
    $result = json_decode($output, true);
    T::check(is_array($result), 'Kindprozess lieferte ein Ergebnis', $output);
    if (is_array($result)) {
        T::same([PHP_SESSION_NONE, true], $result[0], 'während des Callbacks ist die Session geschlossen und die Datei frei');
        T::same(['a' => 1, 'b' => 2, 'c' => 3], $result[1], 'danach wieder geöffnet: parallele Änderungen sichtbar, spätere Schreibzugriffe gehen nicht verloren');
        T::same('a|i:1;b|i:2;c|i:3;', $result[2], 'Session-Datei enthält alle Werte');
    }
    array_map('unlink', glob($scratch . '/*') ?: []);
    @rmdir($scratch);

    $module = $src('admin/modules/system/SystemInfoModule.php');
    T::check(str_contains($module, 'SessionLock::withoutLock(fn (): array => $this->buildSectionData($section))'), 'Monitoring-Bereiche laden ohne Session-Sperre');
    foreach (['warnings', 'health-check', 'response-time', 'cron'] as $section) {
        T::check(preg_match("/SESSION_RELEASE_SECTIONS = \\[[^\\]]*'" . preg_quote($section, '/') . "'/", $module) === 1, "Bereich {$section} gibt die Sperre frei");
    }
    $adminUi = $src('admin/admin-ui.php');
    $closePos = strpos($adminUi, 'session_write_close();');
    $noticePos = strpos($adminUi, 'AdminNoticeService::getInstance()->getNotices()');
    T::check($closePos !== false && $noticePos !== false && $closePos < $noticePos, 'admin-ui notices: Session vor den Systemhinweisen geschlossen');
});

T::run('PERF blocking:0: Monitoring ohne Loopback bei jedem Seitenaufruf', static function () use ($src): void {
    require_once ABSPATH . 'admin/modules/system/SystemInfoModule.php';
    T::check(\SystemInfoModule::isLoopbackUnavailable('cli-server', '') === true, 'php -S ohne Worker: Loopback nicht messbar');
    T::check(\SystemInfoModule::isLoopbackUnavailable('cli-server', '1') === true, 'php -S mit einem Worker: nicht messbar');
    T::check(\SystemInfoModule::isLoopbackUnavailable('cli-server', '4') === false, 'php -S mit mehreren Workern: messbar');
    T::check(\SystemInfoModule::isLoopbackUnavailable('fpm-fcgi', null) === false, 'PHP-FPM: messbar');

    $module = $src('admin/modules/system/SystemInfoModule.php');
    T::check(!str_contains($module, "'response_time' => \$responseTime = \$this->measureResponseTime(SITE_URL)"), 'Response-Time-Seite misst nicht mehr direkt');
    T::check(str_contains($module, '$response = $this->getResponseTimeMeasurement($forceLive);')
        && str_contains($module, '$healthEndpoint = $this->getHealthEndpointProbe($settings, $forceLive);'), 'Health-Check (und Warnzentrale) nutzen Cache bzw. Snapshot');
    T::check(str_contains($module, "private const LOOPBACK_CACHE_TTL = 600;") && str_contains($module, '$cache->set(self::RESPONSE_TIME_CACHE_KEY, $measurement, self::LOOPBACK_CACHE_TTL);'), 'Messung 10 Minuten im Cache');
    T::check(str_contains($module, "(string) (\$_GET['measure'] ?? '') === 'now'"), '„Jetzt messen“ erzwingt eine frische Messung');
    T::check(str_contains($module, "FROM {\$db->getPrefix()}monitoring_trends") && str_contains($module, 'LOOPBACK_SNAPSHOT_MAX_AGE'), 'stündlicher Cron-Snapshot als Quelle');
    T::check(substr_count($module, "'connectTimeout' => self::LOOPBACK_CONNECT_TIMEOUT,") === 2, 'kurzer Verbindungs-Timeout für beide Loopbacks');
    T::check(str_contains($src('admin/views/system/response-time.php'), 'href="?measure=now"') && str_contains($src('admin/views/system/health-check.php'), 'href="?measure=now"'), 'Schaltflächen in beiden Ansichten');
});

T::run('PERF blocking:1: Plugin-/Theme-Update-Prüfung gecacht', static function () use ($src): void {
    $keyA = \CMS\Services\UpdateService::buildPluginUpdateCacheKey(['a' => '1.0.0', 'b' => '2.0.0'], 'https://365cms.de/marketplace/plugins/index.json');
    $keyB = \CMS\Services\UpdateService::buildPluginUpdateCacheKey(['b' => '2.0.0', 'a' => '1.0.0'], 'https://365cms.de/marketplace/plugins/index.json');
    $keyC = \CMS\Services\UpdateService::buildPluginUpdateCacheKey(['a' => '1.0.1', 'b' => '2.0.0'], 'https://365cms.de/marketplace/plugins/index.json');
    T::same($keyA, $keyB, 'Schlüssel unabhängig von der Reihenfolge');
    T::check($keyA !== $keyC, 'neue Plugin-Version invalidiert den Schlüssel');
    T::check(str_starts_with($keyA, \CMS\Services\UpdateService::PLUGIN_UPDATE_CACHE_PREFIX) && strlen($keyA) <= 191, 'Schlüssel passt in cms_cache.cache_key');
    T::check(
        \CMS\Services\UpdateService::buildThemeUpdateCacheKey('cms-default', '1.0.0', 'x') !== \CMS\Services\UpdateService::buildThemeUpdateCacheKey('cms-default', '1.1.0', 'x'),
        'neue Theme-Version invalidiert den Schlüssel'
    );

    $update = $src('core/Services/UpdateService.php');
    T::check(str_contains($update, 'public function checkPluginUpdates(?bool $forceRefresh = null): array')
        && str_contains($update, 'public function checkThemeUpdates(?bool $forceRefresh = null): array')
        && str_contains($update, 'public function checkCoreUpdates(?bool $forceRefresh = null): array'), 'Prüfungen mit optionalem Force-Parameter');
    T::check(str_contains($update, "strtoupper((string) (\$_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST'"), 'POST („Jetzt prüfen“, Installation) liest frisch');
    T::check(substr_count($update, 'self::REMOTE_CHECK_FAILURE_CACHE_DURATION') >= 3, 'Fehlschläge nur kurz gecacht (Plugins, Theme, Core-Rückfall)');
    T::check(str_contains($update, '$this->setCache($cacheKey, $result, self::REMOTE_CHECK_FAILURE_CACHE_DURATION);'), 'Core: Ausfall von Marketplace und GitHub wird gemerkt');

    $module = $src('admin/modules/system/SystemInfoModule.php');
    T::check(str_contains($module, '$updateService->getCachedPluginUpdates() ?? []') && str_contains($module, '$module->hydrateUpdateSnapshot(['), 'Warnzentrale liest nur den Cache');
});

T::run('PERF blocking:2: DNS-Auflösung je Host gemerkt', static function () use ($src): void {
    \CMS\Http\Client::clearResolvedHostCache();
    T::same(['203.0.113.7'], \CMS\Http\Client::resolveHostAddresses('203.0.113.7'), 'IP-Literal ohne Auflösung');

    $property = new \ReflectionProperty(\CMS\Http\Client::class, 'resolvedHostCache');
    $property->setValue(null, ['update.example.test' => ['ips' => ['198.51.100.10'], 'at' => time()]]);
    T::same(['198.51.100.10'], \CMS\Http\Client::resolveHostAddresses('Update.Example.Test'), 'gemerkte Auflösung ohne erneute DNS-Abfrage');
    \CMS\Http\Client::clearResolvedHostCache();
    T::same([], $property->getValue(), 'Speicher lässt sich leeren');

    T::check(str_contains($src('core/Services/UpdateService.php'), 'return HttpClient::resolveHostAddresses($host);'), 'UpdateService nutzt dieselbe Auflösung wie der Client');
});

T::run('PERF blocking:5: Checkout über die Mail-Queue, SMTP mit Timeout', static function () use ($src): void {
    $orders = $src('orders.php');
    T::check(str_contains($orders, "cms_mail(\$to, \$subject, \$body, \$plainHeaders)") && !str_contains($orders, '$mail->sendPlain('), 'Bestellmails über cms_mail() (Queue)');
    $mail = $src('core/Services/MailService.php');
    T::check(str_contains($mail, 'if ($stream instanceof SocketStream) {') && str_contains($mail, '$stream->setTimeout(self::SMTP_TIMEOUT_SECONDS);'), 'SMTP-Stream mit festem Timeout');
    T::check(\CMS\Services\MailService::SMTP_TIMEOUT_SECONDS > 0 && \CMS\Services\MailService::SMTP_TIMEOUT_SECONDS <= 15.0, 'Timeout höchstens 15 s');
});

T::run('PERF blocking:9: Medien-Statistik in einem Lauf und gecacht', static function () use ($src): void {
    $dir = sys_get_temp_dir() . '/cms-perf-media-' . bin2hex(random_bytes(4));
    @mkdir($dir . '/2026/11', 0700, true);
    file_put_contents($dir . '/a.jpg', str_repeat('x', 10));
    file_put_contents($dir . '/2026/11/b.PDF', str_repeat('x', 5));
    file_put_contents($dir . '/2026/11/c.zip', str_repeat('x', 3));
    file_put_contents($dir . '/2026/11/d.txt', '');
    $stats = \CMS\Services\DashboardService::computeMediaStats($dir);
    T::same(4, $stats['total_files'], 'Dateien gezählt');
    T::same(18, $stats['total_size'], 'Größe summiert');
    T::same(['images' => 1, 'videos' => 0, 'documents' => 1, 'archives' => 1, 'other' => 1], $stats['types'], 'Typverteilung im selben Lauf');
    T::same(['total' => 0, 'total_files' => 0, 'total_size' => 0, 'total_size_mb' => 0.0, 'types' => ['images' => 0, 'videos' => 0, 'documents' => 0, 'archives' => 0, 'other' => 0]], \CMS\Services\DashboardService::computeMediaStats($dir . '/fehlt'), 'fehlendes Verzeichnis');
    foreach (['/2026/11/b.PDF', '/2026/11/c.zip', '/2026/11/d.txt', '/a.jpg'] as $file) {
        @unlink($dir . $file);
    }
    @rmdir($dir . '/2026/11');
    @rmdir($dir . '/2026');
    @rmdir($dir);

    $dashboard = $src('core/Services/DashboardService.php');
    T::check(str_contains($dashboard, '$cache->set(self::MEDIA_STATS_CACHE_KEY, $stats, self::MEDIA_STATS_CACHE_TTL);'), 'Dashboard-Statistik gecacht');
    T::check(substr_count($dashboard, 'new \RecursiveDirectoryIterator($uploadDir') === 1, 'nur noch ein Verzeichnislauf');
    T::check(str_contains($src('admin/modules/system/SystemInfoModule.php'), 'self::WARNING_CENTER_MEDIA_CACHE_KEY'), 'Warnzentrale nutzt den gecachten Medien-Scan');
});

T::run('PERF blocking:10: Cron-Hook-Scan gecacht und ohne Laufzeitverzeichnisse', static function () use ($src): void {
    require_once ABSPATH . 'admin/modules/system/SystemInfoModule.php';
    $root = sys_get_temp_dir() . '/cms-perf-cron-' . bin2hex(random_bytes(4));
    foreach (['uploads', 'core/cache', 'plugins/x/vendor', 'plugins/x/assets'] as $dir) {
        @mkdir($root . '/' . $dir, 0700, true);
    }
    $skip = static fn (string $path): bool => \SystemInfoModule::shouldSkipCronHookScanDirectory(new \SplFileInfo($path), $root);
    T::check($skip($root . '/uploads'), 'uploads/ auf oberster Ebene übersprungen');
    T::check(!$skip($root . '/core/cache'), 'gleichnamiges Code-Verzeichnis tiefer bleibt erhalten');
    T::check($skip($root . '/plugins/x/vendor') && $skip($root . '/plugins/x/assets'), 'vendor/ und assets/ auf jeder Ebene übersprungen');
    T::check(!$skip($root . '/plugins'), 'Plugins werden durchsucht');
    foreach (['plugins/x/assets', 'plugins/x/vendor', 'plugins/x', 'plugins', 'core/cache', 'core', 'uploads'] as $dir) {
        @rmdir($root . '/' . $dir);
    }
    @rmdir($root);

    $module = $src('admin/modules/system/SystemInfoModule.php');
    T::check(str_contains($module, '$mappedHooks = $this->getCronHookMap();') && str_contains($module, "(\$cached['signature'] ?? '') === \$signature"), 'Hook-Zuordnung je Code-Signatur gecacht');
    T::check(str_contains($module, 'new \RecursiveCallbackFilterIterator('), 'Verzeichnisse werden gar nicht erst betreten');
});

T::run('PERF blocking:11: IndexNow nur nach abgeschlossener Antwort', static function () use ($src): void {
    $indexing = $src('core/Services/IndexingService.php');
    T::check(str_contains($indexing, "\$responseFinished = PHP_SAPI === 'cli';")
        && str_contains($indexing, '$responseFinished = fastcgi_finish_request();')
        && str_contains($indexing, '$responseFinished = litespeed_finish_request();'), 'Antwortabschluss wird ausgewertet');
    $guard = strpos($indexing, "if (!\$responseFinished) {\n                    return;\n                }");
    $flush = strpos($indexing, "\$this->flushIndexNowQueue('auto');");
    T::check($guard !== false && $flush !== false && $guard < $flush, 'ohne fastcgi_finish_request übernimmt der stündliche Cron');
});
