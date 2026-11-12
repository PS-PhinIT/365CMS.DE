<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Import/Export – JSON-Endpunkt für die Fortschrittsanzeige (BatchJobService + admin-batch-runner.js)
 * Route: /admin/content-exchange-api (POST, CSRF `admin_content_exchange_api`, Capability `import_export`)
 *
 * Aktionen: start_export, start_import, step (kind=export|import), cancel, result.
 */

use CMS\Auth;
use CMS\Logger;
use CMS\Security;
use CMS\Services\BatchJobService;
use CMS\Services\ContentTypes\ExchangeFilter;
use CMS\Services\ContentTypes\ImportContext;
use CMS\Services\Exchange\ContentExchangeService;

\CMS\CacheManager::instance()->sendResponseHeaders('private');
header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');

$respond = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (!Auth::instance()->isAdmin() || !Auth::instance()->hasCapability('import_export')) {
    $respond(403, ['success' => false, 'error' => (string) __('exchange.error.forbidden')]);
}
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    $respond(405, ['success' => false, 'error' => (string) __('exchange.error.post_only')]);
}
if (!Security::instance()->verifyPersistentToken((string) ($_POST['csrf_token'] ?? ''), 'admin_content_exchange_api')) {
    $respond(403, ['success' => false, 'code' => 'csrf', 'error' => (string) __('exchange.error.token')]);
}

$userId = (int) (Auth::instance()->getCurrentUser()->id ?? 0);
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$service = ContentExchangeService::getInstance();
$jobs = BatchJobService::getInstance();
$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$jobId = max(0, (int) ($_POST['job_id'] ?? 0));
$list = static fn (string $key): array => array_values(array_filter(array_map(
    static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
    is_array($_POST[$key] ?? null) ? $_POST[$key] : []
), static fn (string $value): bool => $value !== ''));
$ownJob = static function (int $id, string $type) use ($jobs, $respond): array {
    $job = $id > 0 ? $jobs->find($id) : null;
    if ($job === null || $job['type'] !== $type) {
        $respond(404, ['success' => false, 'error' => (string) __('exchange.error.job')]);
    }

    return $job;
};

try {
    switch ($action) {
        case 'start_export':
            $date = static fn (string $key): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST[$key] ?? '')) === 1 ? (string) $_POST[$key] : '';
            $filter = new ExchangeFilter(
                array_values(array_intersect(['published', 'draft', 'private', 'scheduled', 'pending'], $list('statuses'))),
                $date('date_from'),
                $date('date_to'),
                !empty($_POST['include_media_files'])
            );
            $started = $service->startExport($list('types'), $filter, $userId);
            $respond(200, ['success' => true, 'resumed' => $started['resumed'], 'job' => $jobs->toPublic($started['job'])]);

        case 'start_import':
            $authorMap = [];
            foreach (is_array($_POST['author_map'] ?? null) ? $_POST['author_map'] : [] as $old => $new) {
                if ((int) $old > 0 && (int) $new > 0) {
                    $authorMap[(int) $old] = (int) $new;
                }
            }
            $strategy = (string) ($_POST['strategy'] ?? '');
            $started = $service->startImport((string) ($_POST['token'] ?? ''), [
                'strategy' => in_array($strategy, ImportContext::STRATEGIES, true) ? $strategy : ImportContext::STRATEGY_SKIP,
                'draft' => !empty($_POST['draft']),
                'author_map' => $authorMap,
                'types' => $list('types'),
            ], $userId);
            $respond(200, ['success' => true, 'resumed' => $started['resumed'], 'job' => $jobs->toPublic($started['job'])]);

        case 'step':
            $kind = (string) ($_POST['kind'] ?? '');
            $ownJob($jobId, $kind === 'import' ? ContentExchangeService::JOB_IMPORT : ContentExchangeService::JOB_EXPORT);
            $job = $kind === 'import' ? $service->stepImport($jobId) : $service->stepExport($jobId);
            $respond(200, ['success' => true, 'job' => $jobs->toPublic($job)]);

        case 'cancel':
            $job = $jobs->find($jobId);
            if ($job === null || !in_array($job['type'], [ContentExchangeService::JOB_EXPORT, ContentExchangeService::JOB_IMPORT], true)) {
                $respond(404, ['success' => false, 'error' => (string) __('exchange.error.job')]);
            }
            $service->cancel($jobId);
            $respond(200, ['success' => true, 'job' => $jobs->toPublic((array) $jobs->find($jobId))]);

        case 'result':
            $job = $jobs->find($jobId);
            if ($job === null || !in_array($job['type'], [ContentExchangeService::JOB_EXPORT, ContentExchangeService::JOB_IMPORT], true)) {
                $respond(404, ['success' => false, 'error' => (string) __('exchange.error.job')]);
            }
            $state = (array) $job['state'];
            $payload = ['success' => true, 'job' => $jobs->toPublic($job)];
            if ($job['type'] === ContentExchangeService::JOB_EXPORT && (string) ($state['file'] ?? '') !== '') {
                $payload['download_url'] = '/admin/content-exchange?download=' . rawurlencode((string) $state['file']);
                $payload['counts'] = (array) ($state['counts'] ?? []);
            }
            if ($job['type'] === ContentExchangeService::JOB_IMPORT) {
                $payload['report'] = (array) ($state['report'] ?? []);
            }
            $respond(200, $payload);

        default:
            $respond(400, ['success' => false, 'error' => (string) __('exchange.error.action')]);
    }
} catch (\InvalidArgumentException $e) {
    $respond(422, ['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    Logger::instance()->withChannel('exchange')->error('Import/Export-Aktion fehlgeschlagen.', ['action' => $action, 'exception' => $e->getMessage()]);
    $respond(500, ['success' => false, 'error' => (string) __('exchange.error.internal')]);
}
