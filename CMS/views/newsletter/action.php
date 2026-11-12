<?php
/**
 * Newsletter – Bestätigungs- bzw. Abmeldeseite mit Knopf (Aktion erst per POST).
 * Im Theme überschreibbar: `themes/<theme>/newsletter/action.php`.
 *
 * @var array{locale: string, valid: bool, action: string, url: string, title: string} $newsletter
 */

declare(strict_types=1);

use CMS\Services\Newsletter\NewsletterI18n;

if (!defined('ABSPATH')) {
    exit;
}

$nlLocale = $newsletter['locale'];
$nlE = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$nlT = static fn (string $k): string => $nlE(NewsletterI18n::t($k, $nlLocale));
$nlAction = $newsletter['action'] === 'unsubscribe' ? 'unsubscribe' : 'confirm';
?>
<main class="cms-newsletter cms-newsletter--action">
    <h1 class="cms-newsletter__title"><?= $nlE($newsletter['title']) ?></h1>
    <?php if ($newsletter['valid']): ?>
        <p class="cms-newsletter__lead"><?= $nlT('newsletter.public.' . $nlAction . '_text') ?></p>
        <form method="post" action="<?= $nlE($newsletter['url']) ?>">
            <button type="submit" class="cms-newsletter-form__submit"><?= $nlT('newsletter.public.' . $nlAction . '_button') ?></button>
        </form>
    <?php else: ?>
        <div class="cms-newsletter__notice cms-newsletter__notice--error" role="alert"><?= $nlT('newsletter.notice.expired') ?></div>
    <?php endif; ?>
    <p><a href="<?= $nlE(NewsletterI18n::path('/newsletter', $nlLocale)) ?>"><?= $nlT('newsletter.public.back') ?></a></p>
</main>
