<?php
declare(strict_types=1);

use CMS\Member\MemberI18n;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/bootstrap.php';

$controller->handleFavoritesRequest();

$pageTitle = $t('member.nav.favorites');
$pageKey = 'favorites';
$pageAssets = [];
$favorites = $controller->getFavorites(100);
$favoritesToken = $controller->csrfToken('favorites_action');

include __DIR__ . '/partials/header.php';
?>
<section class="card" aria-labelledby="member-favorites-list-title">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title" id="member-favorites-list-title"><?= $e($t('member.favorites.list_title')) ?></h2>
        <span class="badge bg-primary-lt"><?= $e($t('member.favorites.count', ['count' => count($favorites)])) ?></span>
    </div>
    <?php if ($favorites === []): ?>
        <div class="card-body">
            <div class="member-empty">
                <span class="member-empty__icon" aria-hidden="true">⭐</span>
                <p class="member-empty__text mb-0"><?= $e($t('member.favorites.empty')) ?></p>
                <a class="btn btn-sm btn-outline-primary" href="/"><?= $e($t('member.favorites.browse')) ?></a>
            </div>
        </div>
    <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($favorites as $favorite): ?>
                <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <a class="fw-medium" href="<?= $e($favorite['url'] ?? '#') ?>"><?= $e($favorite['title'] ?? '') ?></a>
                        <div class="text-secondary small"><?= $e($t('member.favorites.saved_at', ['date' => MemberI18n::formatDate($favorite['created_at'] ?? '', false)])) ?></div>
                    </div>
                    <form method="post" action="" data-cms-confirm="<?= $e($t('member.favorites.confirm_remove')) ?>">
                        <input type="hidden" name="action" value="favorite_remove">
                        <input type="hidden" name="post_id" value="<?= (int) ($favorite['post_id'] ?? 0) ?>">
                        <input type="hidden" name="csrf_token" value="<?= $e($favoritesToken) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger"><?= $e($t('member.favorites.remove')) ?><span class="visually-hidden">: <?= $e($favorite['title'] ?? '') ?></span></button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/partials/footer.php';
