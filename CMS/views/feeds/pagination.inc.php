<?php
/**
 * Feed-Aggregator – Seitenblättern (eingebunden aus archive.php und category.php).
 *
 * @var array<string,mixed> $data
 * @var \Closure $fdT
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if ((int) ($data['pages'] ?? 1) > 1) : ?>
    <nav class="fd-pagination" aria-label="<?php echo esc_attr($fdT('pagination')); ?>">
        <?php if ((string) ($data['prev_url'] ?? '') !== '') : ?>
            <a href="<?php echo esc_attr((string) $data['prev_url']); ?>" class="fd-pagination__btn" rel="prev"><?php echo esc_html($fdT('prev')); ?></a>
        <?php endif; ?>
        <span class="fd-pagination__info"><?php echo esc_html($fdT('page_of', (int) $data['page'], (int) $data['pages'])); ?></span>
        <?php if ((string) ($data['next_url'] ?? '') !== '') : ?>
            <a href="<?php echo esc_attr((string) $data['next_url']); ?>" class="fd-pagination__btn" rel="next"><?php echo esc_html($fdT('next')); ?></a>
        <?php endif; ?>
    </nav>
<?php endif;
