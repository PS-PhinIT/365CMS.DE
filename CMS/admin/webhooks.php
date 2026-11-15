<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Webhooks – Entry Point (4.1.0, Bereich 4b)
 * Route: /admin/webhooks
 *
 * Empfänger anlegen/ändern, aktivieren/deaktivieren, Test senden, Geheimnis neu erzeugen, löschen; Zustellprotokoll.
 * POST-Aktionen (Feld `action`): create, update, toggle, test, regenerate, delete. Capability `webhooks.manage`.
 */

use CMS\Auth;
use CMS\Services\Webhooks\WebhookService;

function cms_admin_webhooks_can_access(): bool
{
    return Auth::instance()->isAdmin() && Auth::instance()->hasCapability('webhooks.manage');
}

if (!cms_admin_webhooks_can_access()) {
    header('Location: /');
    exit;
}

/**
 * @param array<string, mixed> $post
 * @return list<string>
 */
function cms_admin_webhooks_events(array $post): array
{
    return array_values(array_filter(is_array($post['events'] ?? null) ? $post['events'] : [], 'is_string'));
}

$sectionPageConfig = [
    'section' => 'webhooks',
    'route_path' => '/admin/webhooks',
    'view_file' => __DIR__ . '/views/system/webhooks.php',
    'page_title' => 'Webhooks',
    'active_page' => 'webhooks',
    'csrf_action' => 'admin_webhooks',
    'module_factory' => static fn (): WebhookService => WebhookService::getInstance(),
    'data_loader' => static fn (WebhookService $service): array => [
        'endpoints' => $service->endpoints(),
        'deliveries' => $service->deliveries(0, 50),
        'events' => WebhookService::events(),
    ],
    'access_checker' => static fn (): bool => cms_admin_webhooks_can_access(),
    'access_denied_route' => '/',
    'redirect_path_resolver' => static fn (): string => '/admin/webhooks',
    'post_handler' => static function (WebhookService $service, string $section, array $post): array {
        $action = is_string($post['action'] ?? null) ? (string) $post['action'] : '';
        $id = max(0, (int) ($post['endpoint_id'] ?? 0));
        $text = static fn (string $key): string => is_string($post[$key] ?? null) ? (string) $post[$key] : '';
        $withSecret = static fn (array $result, string $message): array => !empty($result['success'])
            ? ['success' => true, 'message' => $message . ' Geheimnis (wird nur jetzt angezeigt): ' . (string) $result['secret']]
            : $result;

        return match ($action) {
            'create' => $withSecret(
                $service->createEndpoint($text('name'), $text('url'), cms_admin_webhooks_events($post), (int) (Auth::instance()->currentUser()->id ?? 0)),
                'Empfänger angelegt.'
            ),
            'update' => $service->updateEndpoint($id, $text('name'), $text('url'), cms_admin_webhooks_events($post), !empty($post['is_active'])) + ['message' => 'Empfänger gespeichert.'],
            'toggle' => (static function () use ($service, $id): array {
                $endpoint = $service->endpoint($id);
                if ($endpoint === null) {
                    return ['success' => false, 'error' => 'Empfänger nicht gefunden.'];
                }

                return $service->updateEndpoint($id, $endpoint['name'], $endpoint['url'], $endpoint['events'], !$endpoint['is_active'])
                    + ['message' => $endpoint['is_active'] ? 'Empfänger deaktiviert.' : 'Empfänger aktiviert.'];
            })(),
            'test' => $service->sendTest($id),
            'regenerate' => $withSecret($service->regenerateSecret($id), 'Neues Geheimnis erzeugt.'),
            'delete' => $service->deleteEndpoint($id)
                ? ['success' => true, 'message' => 'Empfänger gelöscht.']
                : ['success' => false, 'error' => 'Empfänger nicht gefunden.'],
            default => ['success' => false, 'error' => 'Unbekannte Aktion.'],
        };
    },
];

require __DIR__ . '/partials/section-page-shell.php';
