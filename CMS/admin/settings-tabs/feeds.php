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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $validToken = Security::instance()->verifyToken(
        (string) ($_POST['csrf_token'] ?? ''),
        CMS_ADMIN_FEEDS_CSRF_ACTION
    );

    $itemsPerFeed = filter_var($_POST['items_per_feed'] ?? null, FILTER_VALIDATE_INT);
    $contentMode = is_string($_POST['content_mode'] ?? null) ? $_POST['content_mode'] : '';
    if (!$validToken) {
        $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => __('admin.feeds.error.csrf')];
    } elseif ($itemsPerFeed === false || $itemsPerFeed < 1 || $itemsPerFeed > 100 || !in_array($contentMode, ['excerpt', 'full'], true)) {
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

    header('Location: /admin/settings?tab=feeds');
    exit;
}

$feedsSettings = array_replace($feedsDefaults, $feedsSettingsService->getGroup('feeds'));
$feedsCsrfToken = Security::instance()->generateToken(CMS_ADMIN_FEEDS_CSRF_ACTION);
$pageTitle = __('admin.feeds.title');
$activePage = 'settings-feeds';
$settingsTabsActive = 'feeds';

require __DIR__ . '/../partials/header.php';
require __DIR__ . '/../partials/sidebar.php';
require __DIR__ . '/../views/settings/feeds.php';
require __DIR__ . '/../partials/footer.php';
