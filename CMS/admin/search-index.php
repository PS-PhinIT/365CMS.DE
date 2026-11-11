<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Suchindex verwalten – Entry Point
 * Route: /admin/search-index
 *
 * Tabs: status (Index je Sprache, Neuaufbau), stats (Suchbegriffe), settings (Protokollierung, Aufbewahrung).
 * Der Neuaufbau läuft über admin/search-index-api.php (BatchJobService + admin-batch-runner.js).
 */

use CMS\Auth;
use CMS\Security;
use CMS\Services\BatchJobService;
use CMS\Services\Search\SearchIndexService;
use CMS\Services\Search\SearchTermStatsService;

const CMS_ADMIN_SEARCH_INDEX_CAPABILITY = 'manage_search_index';
const CMS_ADMIN_SEARCH_INDEX_TABS = ['status', 'stats', 'settings'];
const CMS_ADMIN_SEARCH_INDEX_ACTIONS = ['save_settings', 'delete_stats'];
const CMS_ADMIN_SEARCH_INDEX_API_TOKEN_ACTION = 'admin_search_index_api';

function cms_admin_search_index_can_access(): bool
{
    return Auth::instance()->isAdmin()
        && Auth::instance()->hasCapability(CMS_ADMIN_SEARCH_INDEX_CAPABILITY);
}

if (!cms_admin_search_index_can_access()) {
    header('Location: /');
    exit;
}

function cms_admin_search_index_normalize_tab(mixed $tab): string
{
    $tab = strtolower(trim((string) $tab));

    return in_array($tab, CMS_ADMIN_SEARCH_INDEX_TABS, true) ? $tab : 'status';
}

function cms_admin_search_index_url(string $tab, array $query = []): string
{
    $query = array_filter(
        array_merge($tab !== 'status' ? ['tab' => $tab] : [], $query),
        static fn (mixed $value): bool => $value !== null && $value !== ''
    );

    return '/admin/search-index' . ($query !== [] ? '?' . http_build_query($query) : '');
}

function cms_admin_search_index_text(string $key, string ...$args): string
{
    $text = (string) __($key);

    return $args !== [] ? vsprintf($text, $args) : $text;
}

/**
 * @return array<string, mixed>
 */
function cms_admin_search_index_job_public(?array $job): ?array
{
    return $job !== null ? BatchJobService::getInstance()->toPublic($job) : null;
}

/**
 * @return array<string, mixed>
 */
function cms_admin_search_index_load_data(string $tab, array $query): array
{
    $index = SearchIndexService::getInstance();
    $stats = SearchTermStatsService::getInstance();
    $locales = $index->locales();

    $data = [
        'tab' => $tab,
        'locales' => $locales,
        'available' => $index->isAvailable(),
        'unavailable_reason' => $index->unavailableReason(),
        'stats_available' => $stats->isAvailable(),
        'logging_enabled' => $stats->isLoggingEnabled(),
        'retention_days' => $stats->retentionDays(),
        'retention' => [
            'default' => SearchTermStatsService::DEFAULT_RETENTION_DAYS,
            'min' => SearchTermStatsService::MIN_RETENTION_DAYS,
            'max' => SearchTermStatsService::MAX_RETENTION_DAYS,
        ],
        'data_retention_url' => is_file(ABSPATH . 'admin/data-retention.php') ? '/admin/data-retention' : '',
    ];

    if ($tab === 'status') {
        $data['status'] = $data['available'] ? $index->status() : [];
        $data['active_job'] = cms_admin_search_index_job_public($index->activeJob());
        $data['latest_job'] = cms_admin_search_index_job_public($index->latestJob());
        $data['rebuild_recommended'] = $index->rebuildRecommended();
        $data['api'] = [
            'endpoint' => '/admin/search-index-api',
            'token' => Security::instance()->generateToken(CMS_ADMIN_SEARCH_INDEX_API_TOKEN_ACTION),
        ];
    }

    if ($tab === 'stats') {
        $days = (int) ($query['days'] ?? 30);
        $locale = (string) ($query['locale'] ?? '');
        $locale = in_array($locale, $locales, true) ? $locale : '';
        $data['periods'] = SearchTermStatsService::PERIODS;
        $data['stats'] = $stats->stats($days, $locale);
    }

    return $data;
}

/**
 * @return array<string, mixed>
 */
function cms_admin_search_index_handle_post(array $post): array
{
    $action = strtolower(trim((string) ($post['action'] ?? '')));
    if (!in_array($action, CMS_ADMIN_SEARCH_INDEX_ACTIONS, true)) {
        return ['success' => false, 'error' => cms_admin_search_index_text('search_index.error_unknown_action')];
    }

    $stats = SearchTermStatsService::getInstance();

    if ($action === 'save_settings') {
        $logTerms = !empty($post['log_terms']);
        $retention = (int) ($post['stats_retention_days'] ?? SearchTermStatsService::DEFAULT_RETENTION_DAYS);
        if (!$stats->saveSettings($logTerms, $retention)) {
            return ['success' => false, 'error' => cms_admin_search_index_text('search_index.settings_save_failed'), '__redirect' => cms_admin_search_index_url('settings')];
        }

        \CMS\AuditLogger::instance()->log(
            \CMS\AuditLogger::CAT_SETTING,
            'search.settings.save',
            'Suchindex-Einstellungen gespeichert',
            'search_index',
            null,
            ['log_terms' => $logTerms, 'stats_retention_days' => $stats->retentionDays()]
        );

        return ['success' => true, 'message' => cms_admin_search_index_text('search_index.settings_saved'), '__redirect' => cms_admin_search_index_url('settings')];
    }

    if (!$stats->isAvailable()) {
        return ['success' => false, 'error' => cms_admin_search_index_text('search_index.stats_unavailable'), '__redirect' => cms_admin_search_index_url('stats')];
    }

    $deleted = $stats->deleteAll();

    return [
        'success' => true,
        'message' => cms_admin_search_index_text('search_index.stats_deleted', (string) $deleted),
        '__redirect' => cms_admin_search_index_url('stats'),
    ];
}

$sectionPageConfig = [
    'section' => 'status',
    'route_path' => '/admin/search-index',
    'view_file' => __DIR__ . '/views/system/search-index.php',
    'page_title' => cms_admin_search_index_text('search_index.page_title'),
    'active_page' => 'search-index',
    'csrf_action' => 'admin_search_index',
    'module_factory' => static fn (): array => [],
    'access_checker' => static fn (): bool => cms_admin_search_index_can_access(),
    'access_denied_route' => '/',
    'request_context_resolver' => static function (): array {
        $tab = cms_admin_search_index_normalize_tab($_GET['tab'] ?? 'status');
        $assets = ['css' => [], 'js' => []];
        if ($tab === 'status') {
            $assets['js'] = [cms_asset_url('js/admin-batch-runner.js'), cms_asset_url('js/admin-search-index.js')];
        }

        return [
            'section' => $tab,
            'page_assets' => $assets,
            'data' => ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? [] : cms_admin_search_index_load_data($tab, $_GET),
        ];
    },
    'redirect_path_resolver' => static function ($module, string $section, mixed $result = null): string {
        if (is_array($result) && is_string($result['__redirect'] ?? null)) {
            return (string) $result['__redirect'];
        }

        return cms_admin_search_index_url($section);
    },
    'post_handler' => static function ($module, string $section, array $post): array {
        if (!cms_admin_search_index_can_access()) {
            return ['success' => false, 'error' => cms_admin_search_index_text('search_index.error_forbidden')];
        }

        return cms_admin_search_index_handle_post($post);
    },
    'invalid_token_message' => cms_admin_search_index_text('search_index.error_token'),
    'unknown_action_message' => cms_admin_search_index_text('search_index.error_unknown_action'),
];

require __DIR__ . '/partials/section-page-shell.php';
