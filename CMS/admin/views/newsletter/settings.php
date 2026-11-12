<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Newsletter – Einstellungen: Allgemein (Absender, Anmeldung, Segmente), Inhalte (Texte DE/EN, Archiv, Menü),
 * Versand (Blockgröße, Mail-Fußzeile, Absender-Domain).
 *
 * @var array<string, mixed> $nlData
 * @var callable $nlE
 * @var callable $nlT
 * @var string $nlCsrf
 */

$nlTab = (string) ($nlData['tab'] ?? 'general');
$nlS = (array) ($nlData['settings'] ?? []);
$nlV = static fn (string $k): string => is_scalar($nlS[$k] ?? null) ? (string) $nlS[$k] : '';
$nlSwitch = static fn (string $k, string $label): string => '<label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="' . $nlE($k) . '" value="1"' . ($nlV($k) === '1' ? ' checked' : '') . '><span class="form-check-label">' . $nlT($label) . '</span></label>';
$nlInput = static fn (string $k, string $label, string $type = 'text', string $extra = ''): string => '<label class="form-label" for="nlSet_' . $nlE($k) . '">' . $nlT($label) . '</label><input type="' . $type . '" class="form-control" id="nlSet_' . $nlE($k) . '" name="' . $nlE($k) . '" value="' . $nlE($nlV($k)) . '"' . $extra . '>';
$nlArea = static fn (string $k, string $label, int $rows = 3): string => '<label class="form-label" for="nlSet_' . $nlE($k) . '">' . $nlT($label) . '</label><textarea class="form-control" id="nlSet_' . $nlE($k) . '" name="' . $nlE($k) . '" rows="' . $rows . '" maxlength="4000">' . $nlE($nlV($k)) . '</textarea>';
$nlTabs = ['general' => 'newsletter.admin.settings_general', 'content' => 'newsletter.admin.settings_content', 'delivery' => 'newsletter.admin.settings_delivery'];
?>
<ul class="nav nav-pills mb-4">
    <?php foreach ($nlTabs as $nlKey => $nlLabel): ?>
        <li class="nav-item"><a class="nav-link<?= $nlTab === $nlKey ? ' active' : '' ?>"<?= $nlTab === $nlKey ? ' aria-current="page"' : '' ?> href="<?= $nlE(NewsletterModule::url(['section' => 'settings', 'tab' => $nlKey !== 'general' ? $nlKey : null])) ?>"><?= $nlT($nlLabel) ?></a></li>
    <?php endforeach; ?>
</ul>
<form method="post" action="<?= $nlE(NewsletterModule::url(['section' => 'settings', 'tab' => $nlTab !== 'general' ? $nlTab : null])) ?>">
    <?= $nlCsrf ?>
    <input type="hidden" name="action" value="save_settings">
    <input type="hidden" name="settings_tab" value="<?= $nlE($nlTab) ?>">
    <?php if ($nlTab === 'general'): ?>
        <div class="row g-4">
            <div class="col-lg-6"><div class="card h-100">
                <div class="card-header"><h3 class="card-title"><?= $nlT('newsletter.admin.set_group_sender') ?></h3></div>
                <div class="card-body row g-3">
                    <div class="col-12"><?= $nlInput('sender_name', 'newsletter.admin.set_sender_name', 'text', ' maxlength="120"') ?></div>
                    <div class="col-12"><?= $nlInput('sender_email', 'newsletter.admin.set_sender_email', 'email', ' maxlength="190"') ?><div class="form-hint"><?= $nlT('newsletter.admin.set_sender_email_hint', (string) ($nlData['sender'] ?? '–')) ?></div></div>
                    <div class="col-12"><?= $nlInput('reply_to_email', 'newsletter.admin.set_reply_to', 'email', ' maxlength="190"') ?></div>
                </div>
            </div></div>
            <div class="col-lg-6"><div class="card h-100">
                <div class="card-header"><h3 class="card-title"><?= $nlT('newsletter.admin.set_group_signup') ?></h3></div>
                <div class="card-body">
                    <?= $nlSwitch('require_double_opt_in', 'newsletter.admin.set_double_opt_in') ?>
                    <div class="form-hint mb-2"><?= $nlT('newsletter.admin.set_double_opt_in_hint') ?></div>
                    <?= $nlSwitch('show_name_fields', 'newsletter.admin.set_show_names') ?>
                    <?= $nlSwitch('show_segment_choice', 'newsletter.admin.set_segment_choice') ?>
                    <div class="mt-3 col-sm-6 px-0"><?= $nlInput('confirm_expiry_days', 'newsletter.admin.set_expiry', 'number', ' min="1" max="365"') ?><div class="form-hint"><?= $nlT('newsletter.admin.set_expiry_hint') ?></div></div>
                </div>
            </div></div>
            <div class="col-12"><div class="card">
                <div class="card-header"><h3 class="card-title"><?= $nlT('newsletter.admin.set_group_segments') ?></h3></div>
                <div class="card-body row g-3">
                    <div class="col-md-8"><?= $nlArea('segments', 'newsletter.admin.set_segments', 5) ?><div class="form-hint"><?= $nlT('newsletter.admin.set_segments_hint') ?></div></div>
                    <div class="col-md-4"><?= $nlInput('default_segment', 'newsletter.admin.set_default_segment', 'text', ' maxlength="80" pattern="[a-z0-9-]+"') ?></div>
                </div>
            </div></div>
        </div>
    <?php elseif ($nlTab === 'content'): ?>
        <div class="row g-4">
            <div class="col-12"><div class="card">
                <div class="card-header"><h3 class="card-title"><?= $nlT('newsletter.admin.set_group_page') ?></h3></div>
                <div class="card-body row g-3">
                    <div class="col-md-6"><?= $nlInput('archive_title', 'newsletter.admin.set_title', 'text', ' maxlength="120"') ?></div>
                    <div class="col-md-6"><?= $nlInput('archive_title_en', 'newsletter.admin.set_title_en', 'text', ' maxlength="120"') ?></div>
                    <div class="col-md-6"><?= $nlArea('archive_description', 'newsletter.admin.set_description') ?></div>
                    <div class="col-md-6"><?= $nlArea('archive_description_en', 'newsletter.admin.set_description_en') ?></div>
                    <div class="col-md-6"><?= $nlArea('subscribe_intro', 'newsletter.admin.set_intro') ?></div>
                    <div class="col-md-6"><?= $nlArea('subscribe_intro_en', 'newsletter.admin.set_intro_en') ?></div>
                    <div class="col-md-6"><?= $nlArea('consent_text', 'newsletter.admin.set_consent') ?></div>
                    <div class="col-md-6"><?= $nlArea('consent_text_en', 'newsletter.admin.set_consent_en') ?></div>
                    <div class="col-md-6"><?= $nlArea('footer_note', 'newsletter.admin.set_note', 2) ?></div>
                    <div class="col-md-6"><?= $nlArea('footer_note_en', 'newsletter.admin.set_note_en', 2) ?></div>
                    <div class="col-12"><?= $nlSwitch('public_archive', 'newsletter.admin.set_public_archive') ?></div>
                </div>
            </div></div>
            <div class="col-12"><div class="card">
                <div class="card-header"><h3 class="card-title"><?= $nlT('newsletter.admin.set_group_nav') ?></h3></div>
                <div class="card-body row g-3">
                    <div class="col-12"><?= $nlSwitch('show_nav_link', 'newsletter.admin.set_nav_link') ?></div>
                    <div class="col-sm-6"><?= $nlInput('nav_label', 'newsletter.admin.set_nav_label', 'text', ' maxlength="40"') ?></div>
                    <div class="col-sm-6"><?= $nlInput('nav_label_en', 'newsletter.admin.set_nav_label_en', 'text', ' maxlength="40"') ?></div>
                </div>
            </div></div>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <div class="col-lg-7"><div class="card">
                <div class="card-header"><h3 class="card-title"><?= $nlT('newsletter.admin.set_group_delivery') ?></h3></div>
                <div class="card-body row g-3">
                    <div class="col-sm-6"><?= $nlInput('batch_size', 'newsletter.admin.set_batch_size', 'number', ' min="5" max="1000"') ?><div class="form-hint"><?= $nlT('newsletter.admin.set_batch_size_hint') ?></div></div>
                    <div class="col-12"><?= $nlArea('mail_footer', 'newsletter.admin.set_mail_footer', 4) ?><div class="form-hint"><?= $nlT('newsletter.admin.set_mail_footer_hint') ?></div></div>
                    <div class="col-12"><?= $nlArea('mail_footer_en', 'newsletter.admin.set_mail_footer_en', 4) ?></div>
                </div>
            </div></div>
            <div class="col-lg-5"><div class="card">
                <div class="card-header"><h3 class="card-title"><?= $nlT('newsletter.admin.dns_title') ?></h3></div>
                <?php if (empty($nlData['dns'])): ?>
                    <div class="card-body text-secondary"><?= $nlT('newsletter.admin.dns_unavailable') ?></div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ((array) $nlData['dns'] as $nlKey => $nlCheck): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between gap-2"><strong><?= $nlE(strtoupper($nlKey)) ?></strong>
                                    <span class="badge <?= $nlCheck['ok'] === null ? 'bg-secondary-lt' : ($nlCheck['ok'] ? 'bg-green-lt' : ($nlKey === 'bimi' ? 'bg-secondary-lt' : 'bg-red-lt')) ?>"><?= $nlT($nlCheck['ok'] === null ? 'newsletter.admin.dns_manual' : ($nlCheck['ok'] ? 'newsletter.admin.dns_found' : 'newsletter.admin.dns_missing')) ?></span></div>
                                <div class="small text-secondary text-break"><code><?= $nlE($nlCheck['host']) ?></code></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="card-footer small text-secondary"><?= $nlT('newsletter.admin.dns_hint') ?></div>
                <?php endif; ?>
            </div></div>
        </div>
    <?php endif; ?>
    <div class="d-flex mt-4"><button type="submit" class="btn btn-primary ms-auto"><i class="ti ti-device-floppy me-1" aria-hidden="true"></i><?= $nlT('newsletter.admin.save_settings') ?></button></div>
</form>
