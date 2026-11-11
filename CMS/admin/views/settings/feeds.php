<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$feedsEscape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$feedsAlert = is_array($feedsAlert ?? null) ? $feedsAlert : null;
?>
<div class="page-wrapper">
    <div class="page-header d-print-none">
        <div class="container-xl">
            <h1 class="page-title"><?= $feedsEscape((string) __('admin.feeds.title')) ?></h1>
        </div>
    </div>
    <div class="page-body">
        <div class="container-xl">
            <?php require __DIR__ . '/partials-tabs.php'; ?>
            <?php if ($feedsAlert !== null): ?>
                <div class="alert alert-<?= $feedsEscape((string) ($feedsAlert['type'] ?? 'info')) ?> mt-3" role="status">
                    <?= $feedsEscape((string) ($feedsAlert['message'] ?? '')) ?>
                </div>
            <?php endif; ?>
            <form method="post" action="/admin/settings?tab=feeds" class="card mt-3">
                <input type="hidden" name="csrf_token" value="<?= $feedsEscape($feedsCsrfToken) ?>">
                <div class="card-header">
                    <h2 class="card-title"><?= $feedsEscape((string) __('admin.feeds.heading')) ?></h2>
                </div>
                <div class="card-body">
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" id="feeds-enabled" name="enabled" value="1"<?= !empty($feedsSettings['enabled']) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="feeds-enabled"><?= $feedsEscape((string) __('admin.feeds.enabled')) ?></label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="feeds-items"><?= $feedsEscape((string) __('admin.feeds.items_per_feed')) ?></label>
                        <input class="form-control" type="number" id="feeds-items" name="items_per_feed" min="1" max="100" required value="<?= (int) $feedsSettings['items_per_feed'] ?>">
                        <div class="form-hint"><?= $feedsEscape((string) __('admin.feeds.items_hint')) ?></div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="feeds-content-mode"><?= $feedsEscape((string) __('admin.feeds.content_mode')) ?></label>
                        <select class="form-select" id="feeds-content-mode" name="content_mode">
                            <option value="excerpt"<?= ($feedsSettings['content_mode'] ?? 'excerpt') === 'excerpt' ? ' selected' : '' ?>><?= $feedsEscape((string) __('admin.feeds.excerpt')) ?></option>
                            <option value="full"<?= ($feedsSettings['content_mode'] ?? 'excerpt') === 'full' ? ' selected' : '' ?>><?= $feedsEscape((string) __('admin.feeds.full_text')) ?></option>
                        </select>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="feeds-image" name="include_featured_image" value="1"<?= !empty($feedsSettings['include_featured_image']) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="feeds-image"><?= $feedsEscape((string) __('admin.feeds.include_featured_image')) ?></label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="feeds-categories" name="category_feeds" value="1"<?= !empty($feedsSettings['category_feeds']) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="feeds-categories"><?= $feedsEscape((string) __('admin.feeds.category_feeds')) ?></label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="feeds-tags" name="tag_feeds" value="1"<?= !empty($feedsSettings['tag_feeds']) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="feeds-tags"><?= $feedsEscape((string) __('admin.feeds.tag_feeds')) ?></label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="feeds-languages" name="language_feeds" value="1"<?= !empty($feedsSettings['language_feeds']) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="feeds-languages"><?= $feedsEscape((string) __('admin.feeds.language_feeds')) ?></label>
                    </div>
                </div>
                <div class="card-footer text-end">
                    <button class="btn btn-primary" type="submit" name="action" value="save"><?= $feedsEscape((string) __('Save Changes')) ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
