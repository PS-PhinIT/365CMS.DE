<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;
use CMS\AuditLogger;
use CMS\Services\FeedService;
use CMS\Security;
use CMS\Services\SettingsService;

const CMS_ADMIN_FEEDS_CAPABILITY = 'manage_feeds';
const CMS_ADMIN_FEEDS_CSRF_ACTION = 'admin_feeds_settings';

if (!Auth::instance()->isAdmin() || !Auth::instance()->hasCapability(CMS_ADMIN_FEEDS_CAPABILITY)) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/../modules/feeds/FeedAggregatorModule.php';

$feedsSection = in_array($_GET['section'] ?? '', FeedAggregatorModule::SECTIONS, true) ? (string) $_GET['section'] : 'overview';
$feedsModule = new FeedAggregatorModule();
$feedsReady = $feedsModule->isReady();
if (!$feedsReady && $feedsSection !== 'rss') {
    $feedsSection = 'overview';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['export'] ?? '') === 'opml' && $feedsReady) {
    $feedsModule->streamOpml();
}

$feedsSettingsService = SettingsService::getInstance();
$feedsDefaults = [
    'enabled' => true,
    'items_per_feed' => 25,
    'content_mode' => 'excerpt',
    'include_featured_image' => false,
    'category_feeds' => true,
    'tag_feeds' => true,
    'language_feeds' => true,
];
$feedsSettings = array_replace($feedsDefaults, $feedsSettingsService->getGroup('feeds'));
$feedsAlert = $_SESSION['admin_alert'] ?? null;
unset($_SESSION['admin_alert']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $validToken = Security::instance()->verifyToken(
        (string) ($_POST['csrf_token'] ?? ''),
        CMS_ADMIN_FEEDS_CSRF_ACTION
    );
    $feedsRedirect = '/admin/settings?tab=feeds&section=rss';

    if (!$validToken) {
        $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => __('admin.feeds.error.csrf')];
        $feedsRedirect = FeedAggregatorModule::url(['section' => $feedsSection !== 'overview' ? $feedsSection : null]);
    } elseif ($feedsSection !== 'rss') {
        if (!$feedsReady) {
            $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => FeedAggregatorModule::t('not_ready')];
            $feedsRedirect = FeedAggregatorModule::url();
        } else {
            try {
                $feedsResult = $feedsModule->handlePost($feedsSection, $_POST, $_FILES);
            } catch (\Throwable $feedsError) {
                \CMS\Logger::instance()->withChannel('feeds')->error('Feed-Verwaltung: Aktion fehlgeschlagen.', ['exception' => $feedsError->getMessage()]);
                $feedsResult = ['success' => false, 'error' => FeedAggregatorModule::t('error.save')];
            }
            $_SESSION['admin_alert'] = !empty($feedsResult['success'])
                ? ['type' => 'success', 'message' => (string) ($feedsResult['message'] ?? '')]
                : ['type' => 'danger', 'message' => (string) ($feedsResult['error'] ?? FeedAggregatorModule::t('error.save'))];
            $feedsRedirect = is_string($feedsResult['__redirect'] ?? null)
                ? (string) $feedsResult['__redirect']
                : FeedAggregatorModule::url(['section' => $feedsSection !== 'overview' ? $feedsSection : null]);
        }
    } else {
        $itemsPerFeed = filter_var($_POST['items_per_feed'] ?? null, FILTER_VALIDATE_INT);
        $contentMode = is_string($_POST['content_mode'] ?? null) ? $_POST['content_mode'] : '';
        if ($itemsPerFeed === false || $itemsPerFeed < 1 || $itemsPerFeed > 100 || !in_array($contentMode, ['excerpt', 'full'], true)) {
            $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => __('admin.feeds.error.validation')];
        } else {
            $newFeedsSettings = [
                'enabled' => isset($_POST['enabled']),
                'items_per_feed' => $itemsPerFeed,
                'content_mode' => $contentMode,
                'include_featured_image' => isset($_POST['include_featured_image']),
                'category_feeds' => isset($_POST['category_feeds']),
                'tag_feeds' => isset($_POST['tag_feeds']),
                'language_feeds' => isset($_POST['language_feeds']),
            ];

            if (!$feedsSettingsService->setMany('feeds', $newFeedsSettings)) {
                $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => __('admin.feeds.error.save')];
            } else {
                if ($newFeedsSettings !== array_intersect_key($feedsSettings, $newFeedsSettings)) {
                    AuditLogger::instance()->log(
                        AuditLogger::CAT_SETTING,
                        'feeds.settings_updated',
                        'RSS-Feed-Einstellungen geändert.',
                        'settings',
                        null,
                        ['settings' => $newFeedsSettings]
                    );
                }
                FeedService::invalidatePublicFeedCache();
                $_SESSION['admin_alert'] = ['type' => 'success', 'message' => __('admin.feeds.saved')];
            }
        }
    }

    header('Location: ' . $feedsRedirect, true, 303);
    exit;
}

$feedsSettings = array_replace($feedsDefaults, $feedsSettingsService->getGroup('feeds'));
$feedsCsrfToken = Security::instance()->generateToken(CMS_ADMIN_FEEDS_CSRF_ACTION);
$feedsData = $feedsSection !== 'rss' && $feedsReady ? $feedsModule->data($feedsSection, $_GET) : [];
$pageTitle = __('admin.feeds.title');
$activePage = 'settings-feeds';
$settingsTabsActive = 'feeds';
$pageAssets = [
    'css' => [cms_asset_url('css/admin-feeds.css')],
    'js' => [cms_asset_url('js/admin-feeds.js')],
];

require __DIR__ . '/../partials/header.php';
require __DIR__ . '/../partials/sidebar.php';
require __DIR__ . '/../views/settings/feeds.php';
require __DIR__ . '/../partials/footer.php';
