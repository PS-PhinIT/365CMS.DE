<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$controller = $controller ?? \CMS\MemberArea\MemberController::instance();
$settings = $settings ?? $controller->getSettings();
$e ??= static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$pageAssets = $pageAssets ?? [];
$pageKey = $pageKey ?? 'dashboard';
$footerSiteName = function_exists('cms_get_site_name') ? cms_get_site_name() : (defined('SITE_NAME') ? (string) SITE_NAME : '365CMS');
$footerText = \CMS\Member\MemberSanitizer::text($settings['dashboard_footer_text'] ?? '', 200);
?>
            </div>
        </main>
        <footer class="footer footer-transparent mt-auto py-3 member-footer">
            <div class="container-xl">
                <div class="row align-items-center text-center text-md-start g-2">
                    <div class="col-12 col-md-auto text-secondary">
                        © <?= $e(date('Y')) ?> <?= $e($footerSiteName) ?>
                    </div>
                    <?php if ($footerText !== ''): ?>
                        <div class="col text-secondary"><?= $e($footerText) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </footer>
    </div>
</div>
<script src="<?= $e(cms_asset_url('tabler/js/tabler.min.js')) ?>" defer></script>
<script src="<?= $e(cms_asset_url('js/tabler-bootstrap-bridge.js')) ?>" defer></script>
<script src="<?= $e(cms_asset_url('js/member-dashboard.js')) ?>" defer></script>
<script src="<?= $e(cms_asset_url('js/cms-inline-actions.js')) ?>" defer></script>
<?php foreach ((array) ($pageAssets['js'] ?? []) as $js): ?>
    <script src="<?= $e((string) $js) ?>" defer></script>
<?php endforeach; ?>
<?php \CMS\Hooks::doAction('body_end'); ?>
<?php \CMS\Hooks::doAction('member_body_end', $pageKey); ?>
</body>
</html>
