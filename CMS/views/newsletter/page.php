<?php
/**
 * Newsletter – öffentliche Seite `/newsletter`: Anmeldung und Archiv versendeter Ausgaben.
 * Im Theme überschreibbar: `themes/<theme>/newsletter/page.php`.
 *
 * @var array{locale: string, notice: string, form: string, archive: list<array<string, mixed>>, title: string} $newsletter
 */

declare(strict_types=1);

use CMS\Services\Newsletter\NewsletterI18n;
use CMS\Services\Newsletter\NewsletterSettings;

if (!defined('ABSPATH')) {
    exit;
}

$nlLocale = $newsletter['locale'];
$nlE = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$nlT = static fn (string $k, mixed ...$a): string => $nlE(NewsletterI18n::t($k, $nlLocale, ...$a));
$nlErrors = ['invalid', 'consent', 'rate-limit', 'error', 'expired'];
?>
<main class="cms-newsletter" id="newsletter">
    <header class="cms-newsletter__header">
        <h1 class="cms-newsletter__title"><?= $nlE($newsletter['title']) ?></h1>
        <?php foreach (['archive_description', 'subscribe_intro'] as $nlKey): ?>
            <?php $nlText = NewsletterSettings::localized($nlKey, $nlLocale); ?>
            <?php if ($nlText !== ''): ?><p class="cms-newsletter__lead"><?= nl2br($nlE($nlText)) ?></p><?php endif; ?>
        <?php endforeach; ?>
    </header>

    <?php if ($newsletter['notice'] !== ''): ?>
        <div class="cms-newsletter__notice<?= in_array($newsletter['notice'], $nlErrors, true) ? ' cms-newsletter__notice--error' : '' ?>" role="<?= in_array($newsletter['notice'], $nlErrors, true) ? 'alert' : 'status' ?>">
            <?= $nlT('newsletter.notice.' . $newsletter['notice']) ?>
        </div>
    <?php endif; ?>

    <section class="cms-newsletter__signup" aria-labelledby="newsletter-signup-title">
        <h2 id="newsletter-signup-title"><?= $nlT('newsletter.public.signup_title') ?></h2>
        <?= $newsletter['form'] ?>
    </section>

    <?php if ($newsletter['archive'] !== []): ?>
        <section class="cms-newsletter__archive" aria-labelledby="newsletter-archive-title">
            <h2 id="newsletter-archive-title"><?= $nlT('newsletter.public.archive_title') ?></h2>
            <ul class="cms-newsletter__issues">
                <?php foreach ($newsletter['archive'] as $nlIssue): ?>
                    <li>
                        <a href="<?= $nlE(NewsletterI18n::path('/newsletter/issue/' . (int) $nlIssue['id'], $nlLocale)) ?>"><?= $nlE((string) $nlIssue['subject']) ?></a>
                        <time datetime="<?= $nlE((string) $nlIssue['sent_at']) ?>"><?= $nlE(NewsletterI18n::date((string) $nlIssue['sent_at'], $nlLocale)) ?></time>
                        <?php if ((string) ($nlIssue['preview_text'] ?? '') !== ''): ?><p><?= $nlE((string) $nlIssue['preview_text']) ?></p><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</main>
