<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Suchindex – JSON-Endpunkt für Neuaufbau und Status
 * Route: /admin/search-index-api (POST)
 *
 * Aktionen: start (startet oder übernimmt den laufenden Neuaufbau), step, cancel, status.
 * Vertrag von step/cancel: assets/js/admin-batch-runner.js.
 */

use CMS\AuditLogger;
use CMS\Auth;
use CMS\Logger;
use CMS\Security;
use CMS\Services\BatchJobService;
use CMS\Services\Search\SearchIndexService;

\CMS\CacheManager::instance()->sendResponseHeaders('private');

header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');

function cms_admin_search_index_api_respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cms_admin_search_index_api_error(int $status, string $key): never
{
    cms_admin_search_index_api_respond($status, ['success' => false, 'error' => (string) __($key)]);
}

if (!Auth::instance()->isAdmin() || !Auth::instance()->hasCapability('manage_search_index')) {
    cms_admin_search_index_api_error(403, 'search_index.error_forbidden');
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    cms_admin_search_index_api_error(405, 'search_index.error_post_only');
}

if (!Security::instance()->verifyPersistentToken((string) ($_POST['csrf_token'] ?? ''), 'admin_search_index_api')) {
    cms_admin_search_index_api_error(403, 'search_index.error_token');
}

$userId = (int) (Auth::instance()->getCurrentUser()->id ?? 0);

// Lange Schritte sollen andere Admin-Anfragen derselben Sitzung nicht blockieren.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$jobId = max(0, (int) ($_POST['job_id'] ?? 0));
$service = SearchIndexService::getInstance();
$jobs = BatchJobService::getInstance();
$public = static fn (?array $job): ?array => $job !== null ? $jobs->toPublic($job) : null;

try {
    switch ($action) {
        case 'start':
            if (!$service->isAvailable()) {
                cms_admin_search_index_api_error(409, 'search_index.unavailable');
            }
            $started = $service->startRebuild($userId > 0 ? $userId : null);
            if (!$started['resumed']) {
                AuditLogger::instance()->log(
                    AuditLogger::CAT_SYSTEM,
                    'search.index.rebuild_start',
                    'Suchindex-Neuaufbau gestartet',
                    'batch_job',
                    (int) $started['job']['id'],
                    ['total' => (int) $started['job']['total']]
                );
            }
            cms_admin_search_index_api_respond(200, [
                'success' => true,
                'resumed' => $started['resumed'],
                'job' => $public($started['job']),
            ]);

        case 'step':
            cms_admin_search_index_api_respond(200, ['success' => true, 'job' => $public($service->step($jobId))]);

        case 'cancel':
            $job = $service->cancel($jobId);
            if ($job === null) {
                cms_admin_search_index_api_error(404, 'search_index.error_unknown_job');
            }
            AuditLogger::instance()->log(
                AuditLogger::CAT_SYSTEM,
                'search.index.rebuild_cancel',
                'Suchindex-Neuaufbau abgebrochen',
                'batch_job',
                $jobId,
                ['processed' => (int) $job['processed'], 'total' => (int) $job['total']],
                'warning'
            );
            cms_admin_search_index_api_respond(200, ['success' => true, 'job' => $public($job)]);

        case 'status':
            cms_admin_search_index_api_respond(200, [
                'success' => true,
                'available' => $service->isAvailable(),
                'status' => $service->isAvailable() ? $service->status() : [],
                'active_job' => $public($service->activeJob()),
                'latest_job' => $public($service->latestJob()),
                'rebuild_recommended' => $service->rebuildRecommended(),
            ]);
    }

    cms_admin_search_index_api_error(400, 'search_index.error_unknown_action');
} catch (\InvalidArgumentException) {
    cms_admin_search_index_api_error(404, 'search_index.error_unknown_job');
} catch (\Throwable $e) {
    Logger::instance()->withChannel('search')->error('Suchindex-Aktion fehlgeschlagen.', [
        'action' => $action,
        'job_id' => $jobId,
        'exception' => $e->getMessage(),
    ]);
    cms_admin_search_index_api_error(500, 'search_index.error_internal');
}
