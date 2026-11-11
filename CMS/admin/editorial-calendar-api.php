<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Redaktionskalender-API (JSON) – Route: /admin/editorial-calendar-api
 *
 * GET  ?action=events&start=Y-m-d&end=Y-m-d[&author&type&category&lang&published]
 *      Termine im Website-Datumsbereich (inklusive Endtag, höchstens 62 Tage)
 * GET  ?action=token                                  frisches CSRF-Token (nach Ablauf)
 * POST action=move, csrf_token, type, id, date[, publish_now=1]
 *      Termin auf ein Website-Datum verschieben. Liegt der neue Termin eines geplanten Beitrags nicht mehr in der
 *      Zukunft, antwortet die API mit 409 (`needs_confirmation: publish_now`); erst mit publish_now=1 wird sofort
 *      veröffentlicht.
 *
 * Zugriff: Admin-Rolle + `view_editorial_calendar`; Verschieben zusätzlich das Bearbeitungsrecht für den Inhalt
 * (EditorialCalendarService::move()). Schreibende Aktionen mit CSRF-Token (Aktion admin_editorial_calendar).
 */

use CMS\Auth;
use CMS\Logger;
use CMS\Security;
use CMS\Services\EditorialCalendarService;

const CMS_ADMIN_EDITORIAL_CALENDAR_API_CAPABILITY = 'view_editorial_calendar';
const CMS_ADMIN_EDITORIAL_CALENDAR_API_CSRF_ACTION = 'admin_editorial_calendar';

\CMS\CacheManager::instance()->sendResponseHeaders('private');
header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');

$cmsEditorialCalendarApiRespond = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

$auth = Auth::instance();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string) ($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? ''))));

if (!$auth->isLoggedIn() || !$auth->isAdmin() || !$auth->hasCapability(CMS_ADMIN_EDITORIAL_CALENDAR_API_CAPABILITY)) {
    $cmsEditorialCalendarApiRespond(403, ['success' => false, 'code' => 'forbidden', 'error' => __('calendar.error.forbidden')]);
}

$readActions = ['events', 'token'];
$writeActions = ['move'];

if (in_array($action, $readActions, true)) {
    if ($method !== 'GET') {
        $cmsEditorialCalendarApiRespond(405, ['success' => false, 'code' => 'method', 'error' => __('calendar.error.method')]);
    }
} elseif (in_array($action, $writeActions, true)) {
    if ($method !== 'POST') {
        $cmsEditorialCalendarApiRespond(405, ['success' => false, 'code' => 'method', 'error' => __('calendar.error.method')]);
    }
    if (!Security::instance()->verifyPersistentToken((string) ($_POST['csrf_token'] ?? ''), CMS_ADMIN_EDITORIAL_CALENDAR_API_CSRF_ACTION)) {
        $cmsEditorialCalendarApiRespond(403, ['success' => false, 'code' => 'csrf', 'error' => __('calendar.error.csrf')]);
    }
} else {
    $cmsEditorialCalendarApiRespond(400, ['success' => false, 'code' => 'unknown_action', 'error' => __('calendar.error.unknown_action')]);
}

if ($action === 'token') {
    $fetchSite = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
        $cmsEditorialCalendarApiRespond(403, ['success' => false, 'code' => 'forbidden', 'error' => __('calendar.error.forbidden')]);
    }

    $cmsEditorialCalendarApiRespond(200, ['success' => true, 'token' => Security::instance()->generateToken(CMS_ADMIN_EDITORIAL_CALENDAR_API_CSRF_ACTION)]);
}

$service = EditorialCalendarService::getInstance();
$scalar = static fn (array $source, string $key): string => is_scalar($source[$key] ?? null) ? trim((string) $source[$key]) : '';

try {
    if ($action === 'events') {
        $filters = EditorialCalendarService::normalizeFilters($_GET, $service->getFilterLocales());
        $result = $service->getEvents($scalar($_GET, 'start'), $scalar($_GET, 'end'), $filters);
        if (!$result['success']) {
            $cmsEditorialCalendarApiRespond(422, ['success' => false, 'code' => 'range', 'error' => (string) ($result['error'] ?? __('calendar.error.load'))]);
        }

        $cmsEditorialCalendarApiRespond(200, [
            'success' => true,
            'events' => $result['events'],
            'truncated' => $result['truncated'],
            'today' => $service->todaySite(),
        ]);
    }

    $move = $service->move(
        $scalar($_POST, 'type'),
        (int) $scalar($_POST, 'id'),
        $scalar($_POST, 'date'),
        in_array($scalar($_POST, 'publish_now'), ['1', 'true'], true)
    );
    $cmsEditorialCalendarApiRespond($move['status'], $move['body']);
} catch (\Throwable $e) {
    Logger::instance()->withChannel('content.calendar')->error('Redaktionskalender-API: Aktion fehlgeschlagen.', [
        'action' => $action,
        'exception_class' => get_debug_type($e),
        'exception' => $e->getMessage(),
    ]);
    $cmsEditorialCalendarApiRespond(500, [
        'success' => false,
        'code' => 'error',
        'error' => $action === 'move' ? __('calendar.move.error_failed') : __('calendar.error.load'),
    ]);
}
