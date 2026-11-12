<?php
/**
 * Newsletter – Webversion einer Ausgabe. Im Theme überschreibbar: `themes/<theme>/newsletter/issue.php`.
 *
 * @var array{locale: string, campaign: array<string, mixed>|null, html: string, title: string} $newsletter
 */

declare(strict_types=1);

use CMS\Services\Newsletter\NewsletterI18n;

if (!defined('ABSPATH')) {
    exit;
}

$nlLocale = $newsletter['locale'];
$nlE = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>
<main class="cms-newsletter cms-newsletter--issue">
    <?php if ($newsletter['campaign'] === null): ?>
        <h1 class="cms-newsletter__title"><?= $nlE($newsletter['title']) ?></h1>
        <p><?= $nlE(NewsletterI18n::t('newsletter.public.not_found_text', $nlLocale)) ?></p>
    <?php else: ?>
        <article class="cms-newsletter__issue">
            <header>
                <h1 class="cms-newsletter__title"><?= $nlE((string) $newsletter['campaign']['subject']) ?></h1>
                <p class="cms-newsletter__meta"><time datetime="<?= $nlE((string) $newsletter['campaign']['sent_at']) ?>"><?= $nlE(NewsletterI18n::date((string) $newsletter['campaign']['sent_at'], $nlLocale)) ?></time></p>
            </header>
            <div class="cms-newsletter__content"><?= $newsletter['html'] ?></div>
        </article>
    <?php endif; ?>
    <p><a href="<?= $nlE(NewsletterI18n::path('/newsletter', $nlLocale)) ?>"><?= $nlE(NewsletterI18n::t('newsletter.public.back', $nlLocale)) ?></a></p>
</main>
