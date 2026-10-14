<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_SEO_VIEW')) {
    exit;
}

$social = $data['social'] ?? [];
$settings = $social['settings'] ?? [];
$examples = $social['examples'] ?? [];
$coverage = $social['coverage'] ?? [];
?>
<div class="page-header d-print-none admin-redesign-header"><div class="container-xl"><div class="content-listing-header"><div><div class="page-pretitle">SEO</div><h2 class="page-title mb-1">Social Media & Open Graph</h2><div class="content-listing-header__meta"><span>OG-Bilder: <?= (int)($coverage['og_images'] ?? 0) ?></span><span>Twitter-Titel: <?= (int)($coverage['twitter_titles'] ?? 0) ?></span></div></div><div class="admin-section-toolbar__actions"><a href="/admin/seo-meta" class="btn btn-outline-secondary btn-sm">Meta</a><a href="/admin/seo-schema" class="btn btn-outline-primary btn-sm">Schema</a></div></div></div></div>
<div class="page-body admin-redesign-page"><div class="container-xl admin-redesign-shell">
    <?php if (!empty($alert)): ?>
        <?php
        $alertData = is_array($alert ?? null) ? $alert : [];
        require dirname(__DIR__) . '/partials/flash-alert.php';
        ?>
    <?php endif; ?>
    <?php require __DIR__ . '/subnav.php'; ?>
    <div class="row row-deck row-cards mb-4">
        <div class="col-sm-4"><div class="card"><div class="card-body"><div class="subheader">OG-Bilder</div><div class="h1 mb-0"><?= (int)($coverage['og_images'] ?? 0) ?></div></div></div></div>
        <div class="col-sm-4"><div class="card"><div class="card-body"><div class="subheader">Twitter-Titel</div><div class="h1 mb-0"><?= (int)($coverage['twitter_titles'] ?? 0) ?></div></div></div></div>
        <div class="col-sm-4"><div class="card"><div class="card-body"><div class="subheader">Twitter-Beschreibungen</div><div class="h1 mb-0"><?= (int)($coverage['twitter_descriptions'] ?? 0) ?></div></div></div></div>
    </div>
    <div class="row g-4">
        <div class="col-lg-6"><div class="card h-100"><div class="card-header"><h3 class="card-title">Social Defaults</h3></div><div class="card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="action" value="save_social_defaults">
                <div class="col-12"><label class="form-label">Brand-Name</label><input class="form-control" type="text" name="brand_name" value="<?= htmlspecialchars((string)($settings['seo_social_brand_name'] ?? '')) ?>"></div>
                <div class="col-12"><label class="form-label">Standardbild</label><input class="form-control" type="text" name="default_image" value="<?= htmlspecialchars((string)($settings['seo_social_default_image'] ?? '')) ?>"><div class="form-hint">Wird im Frontend genutzt, wenn für einen Inhalt kein eigenes Open-Graph-/Twitter-Bild gepflegt ist.</div></div>
                <div class="col-md-6"><label class="form-label">OG-Type</label><select class="form-select" name="default_og_type"><?php foreach (['website','article','profile'] as $type): ?><option value="<?= htmlspecialchars($type) ?>" <?= (($settings['seo_social_default_og_type'] ?? 'website') === $type) ? 'selected' : '' ?>><?= htmlspecialchars($type) ?></option><?php endforeach; ?></select><div class="form-hint">Greift als globaler Fallback, solange kein inhaltsspezifischer OG-Type gespeichert ist.</div></div>
                <div class="col-md-6"><label class="form-label">Twitter Card</label><select class="form-select" name="default_twitter_card"><?php foreach (['summary_large_image','summary'] as $card): ?><option value="<?= htmlspecialchars($card) ?>" <?= (($settings['seo_social_default_twitter_card'] ?? 'summary_large_image') === $card) ? 'selected' : '' ?>><?= htmlspecialchars($card) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label">Facebook Page</label><input class="form-control" type="text" name="facebook_page" value="<?= htmlspecialchars((string)($settings['seo_social_facebook_page'] ?? '')) ?>"></div>
                <div class="col-md-6"><label class="form-label">Twitter-/X-Profil</label><input class="form-control" type="text" name="twitter_profile" value="<?= htmlspecialchars((string)($settings['seo_social_twitter_profile'] ?? '')) ?>" placeholder="@handle oder https://x.com/handle"><div class="form-hint">Wird als <code>twitter:site</code> und im Organisations-Schema (<code>sameAs</code>) ausgegeben.</div></div>
                <div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="pinterest_rich_pins" value="1" <?= !empty($settings['seo_social_pinterest_rich_pins']) ? 'checked' : '' ?>><span class="form-check-label">Pinterest Rich Pins berücksichtigen</span></label></div>
                <div class="col-12"><button class="btn btn-primary" type="submit">Social Defaults speichern</button></div>
            </form>
        </div></div></div>
        <div class="col-lg-6"><div class="card h-100"><div class="card-header"><h3 class="card-title">Vorschau-Beispiele</h3></div><div class="card-body">
            <?php foreach ($examples as $example): ?>
                <div class="border rounded overflow-hidden bg-light mb-3">
                    <?php $image = (string)($example['og_image'] ?: $example['featured_image'] ?? ''); ?>
                    <?php if ($image !== ''): ?><img src="<?= htmlspecialchars($image) ?>" alt="" style="width:100%;height:140px;object-fit:cover;"><?php endif; ?>
                    <div class="p-3">
                        <div class="text-uppercase small text-secondary mb-1">facebook / x</div>
                        <div class="fw-semibold mb-1"><?= htmlspecialchars((string)(($example['og_title'] ?: $example['resolved_meta_title']) ?? '')) ?></div>
                        <div class="small text-secondary"><?= htmlspecialchars((string)(($example['og_description'] ?: $example['resolved_meta_description']) ?? '')) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div></div></div>
    </div>
</div></div>
