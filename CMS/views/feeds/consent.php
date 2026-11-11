<?php
/**
 * Feed-Aggregator – Hinweis, solange keine Einwilligung für externe Medien vorliegt.
 *
 * Theme-Override: themes/<slug>/feeds/consent.php
 *
 * @var array<string,mixed> $data lang, title, preferences_url, home_url, embed
 */

declare(strict_types=1);

use CMS\Services\Feed\FeedI18n;

if (!defined('ABSPATH')) {
    exit;
}

$fdLang = (string) ($data['lang'] ?? 'de');
$fdT = static fn (string $key): string => FeedI18n::text('feeds.public.' . $key, $fdLang);
$fdTarget = !empty($data['embed']) ? ' target="_top"' : '';
?>
<div class="fd-aggregator fd-in-page" data-feed-consent-required="1">
    <div class="fd-empty">
        <p class="fd-empty__icon" aria-hidden="true">🔒</p>
        <h2><?php echo esc_html($fdT('consent_title')); ?></h2>
        <p class="fd-empty__text"><?php echo esc_html($fdT('consent_text')); ?></p>
        <div class="fd-consent-actions">
            <a class="fd-pagination__btn" href="<?php echo esc_attr((string) $data['preferences_url']); ?>" data-cookie-settings<?php echo $fdTarget; ?>><?php echo esc_html($fdT('consent_button')); ?></a>
            <a class="fd-search-hint__reset" href="<?php echo esc_attr((string) $data['home_url']); ?>"<?php echo $fdTarget; ?>><?php echo esc_html($fdT('consent_home')); ?></a>
        </div>
    </div>
</div>
