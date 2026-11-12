<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Medien-Werkzeuge – JSON-Endpunkt „Vorschaubilder neu erzeugen“
 * Route: /admin/media-tools-api (POST, Token `admin_media_tools_api`, Capability `manage_media`)
 *
 * Aktionen: start (webp=0|1; übernimmt einen laufenden Auftrag), step, cancel, status.
 * Vertrag von step/cancel: assets/js/admin-batch-runner.js.
 */

use CMS\AuditLogger;
use CMS\Auth;
use CMS\Logger;
use CMS\Security;
use CMS\Services\BatchJobService;
use CMS\Services\Media\ThumbnailRegenerationService;

\CMS\CacheManager::instance()->sendResponseHeaders('private');

header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');

function cms_admin_media_tools_api_respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cms_admin_media_tools_api_error(int $status, string $key): never
{
    cms_admin_media_tools_api_respond($status, ['success' => false, 'error' => (string) __($key)]);
}

if (!Auth::instance()->isAdmin() || !Auth::instance()->hasCapability('manage_media')) {
    cms_admin_media_tools_api_error(403, 'media_tools.error.forbidden');
}
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    cms_admin_media_tools_api_error(405, 'media_tools.error.post_only');
}
if (!Security::instance()->verifyPersistentToken((string) ($_POST['csrf_token'] ?? ''), 'admin_media_tools_api')) {
    cms_admin_media_tools_api_error(403, 'media_tools.error.token');
}

$userId = (int) (Auth::instance()->getCurrentUser()->id ?? 0);
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$jobId = max(0, (int) ($_POST['job_id'] ?? 0));
$service = ThumbnailRegenerationService::getInstance();
$jobs = BatchJobService::getInstance();
$public = static fn (?array $job): ?array => $job !== null ? $jobs->toPublic($job) : null;

try {
    switch ($action) {
        case 'start':
            if (!\CMS\Services\ImageService::getInstance()->isAvailable()) {
                cms_admin_media_tools_api_error(409, 'media_tools.thumbs.no_library');
            }
            $webp = !empty($_POST['webp']) && (string) $_POST['webp'] !== '0';
            $started = $service->start($webp, $userId > 0 ? $userId : null);
            if (!$started['resumed']) {
                AuditLogger::instance()->log(AuditLogger::CAT_SYSTEM, 'media.thumbnails.start', 'Vorschaubilder neu erzeugen gestartet', 'batch_job', (int) $started['job']['id'], ['total' => (int) $started['job']['total'], 'webp' => $webp]);
            }
            cms_admin_media_tools_api_respond(200, ['success' => true, 'resumed' => $started['resumed'], 'job' => $public($started['job'])]);

        case 'step':
            cms_admin_media_tools_api_respond(200, ['success' => true, 'job' => $public($service->step($jobId))]);

        case 'cancel':
            $job = $service->cancel($jobId);
            if ($job === null) {
                cms_admin_media_tools_api_error(404, 'media_tools.error.unknown_job');
            }
            AuditLogger::instance()->log(AuditLogger::CAT_SYSTEM, 'media.thumbnails.cancel', 'Vorschaubilder neu erzeugen abgebrochen', 'batch_job', $jobId, ['processed' => (int) $job['processed'], 'total' => (int) $job['total']], 'warning');
            cms_admin_media_tools_api_respond(200, ['success' => true, 'job' => $public($job)]);

        case 'status':
            cms_admin_media_tools_api_respond(200, ['success' => true, 'active_job' => $public($service->activeJob()), 'latest_job' => $public($service->latestJob())]);
    }

    cms_admin_media_tools_api_error(400, 'media_tools.error.unknown_action');
} catch (\InvalidArgumentException) {
    cms_admin_media_tools_api_error(404, 'media_tools.error.unknown_job');
} catch (\Throwable $e) {
    Logger::instance()->withChannel('media')->error('Medien-Werkzeuge: Aktion fehlgeschlagen.', ['action' => $action, 'job_id' => $jobId, 'exception' => $e->getMessage()]);
    cms_admin_media_tools_api_error(500, 'media_tools.error.internal');
}
