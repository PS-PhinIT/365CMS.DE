<?php
declare(strict_types=1);

/**
 * 365CMS Datenbank-Updater
 *
 * Web: Nur für angemeldete Administratoren mit manage_settings und CSRF-Schutz.
 * CLI: php update.php [--status|--dry-run]
 *
 * @package 365CMS
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
}

define('CMS_UPDATE_RUNNING', true);

require_once __DIR__ . '/config.php';
require_once CORE_PATH . 'autoload.php';

use CMS\Auth;
use CMS\Database;
use CMS\DatabaseUpdateRunner;
use CMS\Security;

$runner = new DatabaseUpdateRunner(Database::instance());

if (PHP_SAPI === 'cli') {
    $arguments = array_values(array_slice($_SERVER['argv'] ?? [], 1));
    $statusOnly = in_array('--status', $arguments, true) || in_array('--dry-run', $arguments, true);
    $result = $statusOnly
        ? ['success' => true, 'message' => 'Update-Status ermittelt.', 'before' => $runner->getStatus(), 'after' => $runner->getStatus()]
        : $runner->run();

    fwrite(STDOUT, (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(!empty($result['success']) ? 0 : 1);
}

$security = Security::instance();
$security->init();
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');

$auth = Auth::instance();
// SEC-410-08: Direkt ausgeliefert, also ohne Router – MFA-Zwang und Passwort-Ablauf hier selbst prüfen.
\CMS\Services\Security\MfaEnforcementService::enforceOnEntryPoint('/update.php');
if (!$auth->isAdmin() || !$auth->hasCapability('manage_settings')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Zugriff verweigert. Bitte als Administrator anmelden.';
    exit;
}

$flash = is_array($_SESSION['cms_database_update_flash'] ?? null)
    ? $_SESSION['cms_database_update_flash']
    : null;
unset($_SESSION['cms_database_update_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = trim((string) ($_POST['csrf_token'] ?? ''));
    if (!$security->verifyToken($csrfToken, 'database_update')) {
        $_SESSION['cms_database_update_flash'] = [
            'type' => 'error',
            'message' => 'Sicherheitsprüfung fehlgeschlagen. Bitte die Seite neu laden und erneut versuchen.',
        ];
    } else {
        $result = $runner->run();
        $_SESSION['cms_database_update_flash'] = [
            'type' => !empty($result['success']) ? 'success' : 'error',
            'message' => (string) ($result['message'] ?? 'Datenbank-Update konnte nicht abgeschlossen werden.'),
        ];
    }

    $currentPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/update.php'), PHP_URL_PATH) ?? '/update.php');
    header('Location: ' . ($currentPath !== '' ? $currentPath : '/update.php'), true, 303);
    exit;
}

$status = $runner->getStatus();
$csrfToken = $security->generateToken('database_update');
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$statusInstalledVersion = $status['installed_version'] !== '' ? $status['installed_version'] : 'Unbekannt';
$statusInstalledSchema = $status['installed_schema_version'] !== '' ? $status['installed_schema_version'] : 'Unbekannt';
$nonceAttr = $security->nonceAttr();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>365CMS Datenbank-Update</title>
    <style <?php echo $nonceAttr; ?>>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 2rem; background: #0f172a; color: #0f172a; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { width: min(100%, 760px); padding: clamp(1.5rem, 5vw, 3rem); border-radius: 18px; background: #fff; box-shadow: 0 24px 72px rgba(0, 0, 0, .35); }
        h1 { margin: 0 0 .5rem; font-size: clamp(1.6rem, 4vw, 2.2rem); }
        p { color: #475569; line-height: 1.6; }
        .warning { margin: 1.5rem 0; padding: 1rem; border-left: 4px solid #f59e0b; border-radius: 8px; background: #fffbeb; color: #78350f; }
        .status { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: .75rem; margin: 1.5rem 0; }
        .status div { padding: 1rem; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; }
        .status span { display: block; color: #64748b; font-size: .76rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; }
        .status strong { display: block; margin-top: .3rem; font-size: 1rem; }
        .flash { margin: 1rem 0; padding: 1rem; border-radius: 8px; }
        .flash--success { background: #ecfdf5; color: #065f46; }
        .flash--error { background: #fef2f2; color: #991b1b; }
        form { margin-top: 1.5rem; }
        button { width: 100%; padding: .95rem 1.25rem; border: 0; border-radius: 9px; background: #0f766e; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
        button:hover { background: #115e59; }
        .back { display: inline-block; margin-top: 1.25rem; color: #0f766e; font-weight: 600; text-decoration: none; }
    </style>
</head>
<body>
<main>
    <h1>365CMS Datenbank-Update</h1>
    <p>Dieses Werkzeug führt ausschließlich die zentralen, idempotenten Schema-Migrationen aus. Inhalte, Benutzerkonten und Einstellungen werden nicht gelöscht.</p>

    <?php if (is_array($flash)): ?>
        <div class="flash flash--<?php echo $escape($flash['type'] ?? 'error'); ?>"><?php echo $escape($flash['message'] ?? ''); ?></div>
    <?php endif; ?>

    <?php if (!empty($status['downgrade_detected'])): ?>
        <div class="warning">Die Datenbank ist laut Installationsprotokoll neuer als dieser Core. Ein automatischer Downgrade ist gesperrt.</div>
    <?php endif; ?>

    <section class="status" aria-label="Update-Status">
        <div><span>Installierte CMS-Version</span><strong><?php echo $escape($statusInstalledVersion); ?></strong></div>
        <div><span>Zielversion</span><strong><?php echo $escape($status['target_version']); ?></strong></div>
        <div><span>Installiertes Schema</span><strong><?php echo $escape($statusInstalledSchema); ?></strong></div>
        <div><span>Zielschema</span><strong><?php echo $escape($status['target_schema_version']); ?></strong></div>
    </section>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $escape($csrfToken); ?>">
        <button type="submit"<?php echo !empty($status['downgrade_detected']) ? ' disabled' : ''; ?>>Schema prüfen und Update ausführen</button>
    </form>
    <a class="back" href="/admin/updates">← Zurück zu Updates</a>
</main>
</body>
</html>
