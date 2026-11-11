<?php
/**
 * Downloads – Hinweisseite vor einem externen Download (Einstellung „Hinweis vor externen Zielen“).
 *
 * Theme-Override: themes/<slug>/downloads/external.php
 *
 * @var array<string,mixed> $data item, host, continue_url (signiert, 15 Minuten gültig), back_url
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="cms-dl-external">
    <p><?php echo esc_html(sprintf(__('downloads.external.text'), (string) ($data['item']['title'] ?? ''), (string) ($data['host'] ?? ''))); ?></p>
    <p class="cms-dl__actions">
        <a class="cms-dl__button" href="<?php echo esc_attr(esc_url((string) $data['continue_url'])); ?>" rel="nofollow noopener noreferrer"><?php echo esc_html(sprintf(__('downloads.external.continue'), (string) ($data['host'] ?? ''))); ?></a>
        <a class="cms-dl__link" href="<?php echo esc_attr(esc_url((string) $data['back_url'])); ?>"><?php echo esc_html(__('downloads.external.back')); ?></a>
    </p>
</div>
