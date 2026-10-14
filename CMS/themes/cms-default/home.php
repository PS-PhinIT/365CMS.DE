<?php
/**
 * Meridian CMS Default – Homepage Template
 *
 * Hinweis: Der Router übergibt bei der Home-Route KEINE $data,
 * daher werden alle Beiträge hier direkt via Helpers abgefragt.
 *
 * @package CMSv2\Themes\CmsDefault
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// ── Daten laden ──────────────────────────────────────────────────────────────
$homepageMode = (string) meridian_setting('homepage', 'homepage_mode', 'posts');
$heroPost = null;
$articleList = $cardPosts = $featurePosts = $recentSidebar = $sidebarCats = $tagCloud = [];

// Landing-Modus braucht keine Blog-Daten – vorher liefen alle Abfragen trotzdem.
if ($homepageMode !== 'landing') {
$db   = \CMS\Database::instance();
$pdo  = $db->getConnection();
$prefix = $db->getPrefix();
// Öffentlich sichtbar: veröffentlicht und nicht für die Zukunft geplant.
$publishedWhere = function_exists('cms_post_publication_where') ? cms_post_publication_where('p') : "p.status = 'published'";

// Hero-Post (neuester veröffentlichter Beitrag mit Bild, falls vorhanden)
$heroPost = null;
try {
    $stmt = $pdo->prepare(
        "SELECT p.*, COALESCE(NULLIF(p.author_display_name, ''), NULLIF(u.display_name, ''), u.username) AS author_name, c.name AS category_name, c.slug AS category_slug
         FROM {$prefix}posts p
         LEFT JOIN {$prefix}users u ON p.author_id = u.id
         LEFT JOIN {$prefix}post_categories c ON p.category_id = c.id
         WHERE {$publishedWhere}
         ORDER BY p.published_at DESC
         LIMIT 1"
    );
    $stmt->execute();
    $heroPost = $stmt->fetch(\PDO::FETCH_OBJ);
} catch (\Throwable $e) {
    // Tabelle existiert noch nicht oder leer → kein Hero-Post
}

// Artikel-Liste (nächste 5 Beiträge)
$articleList = [];
try {
    $excludeId = $heroPost ? (int)$heroPost->id : 0;
    $stmt = $pdo->prepare(
        "SELECT p.*, COALESCE(NULLIF(p.author_display_name, ''), NULLIF(u.display_name, ''), u.username) AS author_name, c.name AS category_name, c.slug AS category_slug
         FROM {$prefix}posts p
         LEFT JOIN {$prefix}users u ON p.author_id = u.id
         LEFT JOIN {$prefix}post_categories c ON p.category_id = c.id
         WHERE {$publishedWhere} AND p.id != :exclude
         ORDER BY p.published_at DESC
         LIMIT 5"
    );
    $stmt->execute([':exclude' => $excludeId]);
    $articleList = $stmt->fetchAll(\PDO::FETCH_OBJ);
} catch (\Throwable $e) {
    $articleList = [];
}

// Card-Grid (weitere 3 Beiträge)
$cardPosts = [];
try {
    $excludeIds = array_merge(
        $heroPost ? [(int)$heroPost->id] : [],
        array_map(fn($p) => (int)$p->id, $articleList)
    );
    $placeholders = $excludeIds ? implode(',', array_fill(0, count($excludeIds), '?')) : '0';
    $stmt = $pdo->prepare(
        "SELECT p.*, COALESCE(NULLIF(p.author_display_name, ''), NULLIF(u.display_name, ''), u.username) AS author_name, c.name AS category_name, c.slug AS category_slug
         FROM {$prefix}posts p
         LEFT JOIN {$prefix}users u ON p.author_id = u.id
         LEFT JOIN {$prefix}post_categories c ON p.category_id = c.id
         WHERE {$publishedWhere} AND p.id NOT IN ($placeholders)
         ORDER BY p.views DESC
         LIMIT 3"
    );
    $stmt->execute($excludeIds ?: [0]);
    $cardPosts = $stmt->fetchAll(\PDO::FETCH_OBJ);
} catch (\Throwable $e) {
    $cardPosts = [];
}

// Feature-Row (2 weitere Beiträge)
$featurePosts = [];
try {
    $allExclude = array_merge(
        $heroPost ? [(int)$heroPost->id] : [],
        array_map(fn($p) => (int)$p->id, $articleList),
        array_map(fn($p) => (int)$p->id, $cardPosts)
    );
    $placeholders = implode(',', array_fill(0, count($allExclude), '?'));
    $stmt = $pdo->prepare(
        "SELECT p.*, COALESCE(NULLIF(p.author_display_name, ''), NULLIF(u.display_name, ''), u.username) AS author_name, c.name AS category_name, c.slug AS category_slug
         FROM {$prefix}posts p
         LEFT JOIN {$prefix}users u ON p.author_id = u.id
         LEFT JOIN {$prefix}post_categories c ON p.category_id = c.id
         WHERE {$publishedWhere} AND p.id NOT IN ($placeholders)
         ORDER BY p.published_at DESC
         LIMIT 2"
    );
    $stmt->execute($allExclude ?: [0]);
    $featurePosts = $stmt->fetchAll(\PDO::FETCH_OBJ);
} catch (\Throwable $e) {
    $featurePosts = [];
}

// Sidebar: aktuelle Beiträge
$recentSidebar = meridian_get_recent_posts(5, $heroPost ? (int)$heroPost->id : 0);

// Sidebar: Kategorien
$sidebarCats = meridian_get_categories(8);

// Sidebar: Tags (gezählte Tag-Tabelle statt alle posts.tags-Zeilen zu laden; Links über den Slug)
$tagCloud = meridian_get_tags(20);
}

$showSidebar      = (bool) meridian_setting('layout',   'show_sidebar',        true);
$showHero         = (bool) meridian_setting('blog',     'show_hero_post',      true)
                  && (bool) meridian_setting('homepage', 'homepage_show_hero',  true);
$heroTitleOverride = (string) meridian_setting('homepage', 'homepage_hero_title',  '');
$ctaText          = (string) meridian_setting('homepage', 'homepage_cta_text',    '');
$ctaUrl           = (string) meridian_setting('homepage', 'homepage_cta_url',     '');
$numRecent        = count($recentSidebar);
?>
<?php if ($homepageMode === 'landing'): ?>
<?php require __DIR__ . '/partials/home-landing.php'; ?>

<?php else: /* ── BLOG MODUS (Standard) ── */ ?>
<div class="page-wrap<?php echo !$showSidebar ? ' page-wrap--full' : ''; ?>">

<div class="content-main">
<h1 class="sr-only"><?php echo htmlspecialchars(defined('SITE_NAME') ? (string) SITE_NAME : '365CMS', ENT_QUOTES, 'UTF-8'); ?></h1>

<!-- ── Hero Post ──────────────────────────────────────────────────────────── -->
<?php if ($showHero && $heroPost): ?>
<?php
    $heroImageReference = (string) ($heroPost->featured_image ?? '');
    $heroImage = function_exists('meridian_get_picture_sources')
        ? meridian_get_picture_sources($heroImageReference, null, 1600, 900)
        : ['url' => '', 'webp_url' => '', 'width' => 1600, 'height' => 900];
    $heroImageDimensions = function_exists('meridian_image_dimension_attributes')
        ? meridian_image_dimension_attributes($heroImageReference, 1600, 900)
        : 'width="1600" height="900"';
?>
<div class="hero-post">
    <div class="hero-image">
        <?php if ($heroImage['url'] !== ''): ?>
        <picture>
            <?php if ($heroImage['webp_url'] !== ''): ?>
            <source srcset="<?php echo htmlspecialchars($heroImage['webp_url'], ENT_QUOTES, 'UTF-8'); ?>" type="image/webp">
            <?php endif; ?>
            <img src="<?php echo htmlspecialchars($heroImage['url'], ENT_QUOTES, 'UTF-8'); ?>"
                 alt="<?php echo htmlspecialchars($heroPost->title); ?>"
                 <?php echo function_exists('meridian_image_loading_attributes') ? meridian_image_loading_attributes(true) : 'loading="eager" decoding="async"'; ?>
                 <?php echo $heroImageDimensions; ?>>
        </picture>
        <?php endif; ?>
        <?php if ($heroPost->category_slug): ?>
        <div class="hero-cat-badge"><?php echo htmlspecialchars(strtoupper($heroPost->category_slug ?? '')); ?></div>
        <?php endif; ?>
    </div>
    <div class="hero-body">
        <?php if ($heroPost->category_name): ?>
        <div class="post-cat"><?php echo htmlspecialchars($heroPost->category_name); ?></div>
        <?php endif; ?>
        <h2>
            <a href="<?php echo htmlspecialchars(meridian_post_url($heroPost), ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($heroTitleOverride ?: $heroPost->title); ?>
            </a>
        </h2>
        <?php if ($heroPost->excerpt): ?>
        <p class="excerpt"><?php echo htmlspecialchars(meridian_excerpt($heroPost->excerpt, 200)); ?></p>
        <?php endif; ?>
        <div class="post-meta">
            <?php if ($heroPost->author_name): ?>
            <div class="meta-author">
                <div class="avatar-xs"><?php echo htmlspecialchars(meridian_author_initials($heroPost->author_name)); ?></div>
                <?php echo htmlspecialchars($heroPost->author_name); ?>
            </div>
            <span class="meta-sep">·</span>
            <?php endif; ?>
            <time class="meta-date"><?php echo meridian_format_date($heroPost->published_at ?? $heroPost->created_at); ?></time>
            <?php echo meridian_post_update_badge($heroPost); ?>
            <?php if (meridian_setting('blog', 'show_reading_time', true)): ?>
            <span class="meta-sep">·</span>
            <span class="meta-read"><?php echo meridian_reading_time($heroPost->content); ?> Min. Lesezeit</span>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($ctaText && $ctaUrl): ?>
        <div class="hero-cta">
            <a href="<?php echo htmlspecialchars($ctaUrl); ?>" class="btn-hero"><?php echo htmlspecialchars($ctaText); ?></a>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Artikel-Liste ──────────────────────────────────────────────────────── -->
<?php if (!empty($articleList)): ?>
<div class="section-label"><h2>Aktuelle Artikel</h2></div>
<div class="article-list">
    <?php foreach ($articleList as $post): ?>
    <?php
        $articleImageReference = (string) ($post->featured_image ?? '');
        $articleImage = function_exists('meridian_get_picture_sources')
            ? meridian_get_picture_sources($articleImageReference, null, 320, 218)
            : ['url' => '', 'webp_url' => '', 'width' => 320, 'height' => 218];
        $articleImageDimensions = function_exists('meridian_image_dimension_attributes')
            ? meridian_image_dimension_attributes($articleImageReference, 320, 218)
            : 'width="320" height="218"';
    ?>
    <div class="article-row">
        <div class="art-thumb">
            <?php if ($articleImage['url'] !== ''): ?>
            <picture>
                <?php if ($articleImage['webp_url'] !== ''): ?>
                <source srcset="<?php echo htmlspecialchars($articleImage['webp_url'], ENT_QUOTES, 'UTF-8'); ?>" type="image/webp">
                <?php endif; ?>
                <img src="<?php echo htmlspecialchars($articleImage['url'], ENT_QUOTES, 'UTF-8'); ?>"
                     alt="<?php echo htmlspecialchars($post->title); ?>"
                     <?php echo function_exists('meridian_image_loading_attributes') ? meridian_image_loading_attributes(false) : 'loading="lazy" decoding="async"'; ?>
                     <?php echo $articleImageDimensions; ?>>
            </picture>
            <?php endif; ?>
        </div>
        <div class="art-body">
            <?php if ($post->category_name): ?>
            <div class="art-cat"><?php echo htmlspecialchars($post->category_name); ?></div>
            <?php endif; ?>
            <div class="art-title">
                <a href="<?php echo htmlspecialchars(meridian_post_url($post), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($post->title); ?>
                </a>
            </div>
            <?php $artExcerpt = $post->excerpt ?: meridian_excerpt($post->content, 120); ?>
            <?php if ($artExcerpt): ?>
            <div class="art-excerpt"><?php echo htmlspecialchars($artExcerpt); ?></div>
            <?php endif; ?>
            <div class="art-meta">
                <time><?php echo meridian_format_date($post->published_at ?? $post->created_at); ?></time>
                <?php if (meridian_setting('blog', 'show_reading_time', true)): ?>
                <span class="dot"></span>
                <span class="read-t"><?php echo meridian_reading_time($post->content); ?></span>
                <?php endif; ?>
                <?php echo meridian_post_update_badge($post); ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Card-Grid ────────────────────────────────────────────────────────── -->
<?php if (!empty($cardPosts)): ?>
<div class="section-label"><h2>Weitere Artikel</h2></div>
<div class="card-grid">
    <?php foreach ($cardPosts as $post): ?>
    <?php
        $cardImageReference = (string) ($post->featured_image ?? '');
        $cardImage = function_exists('meridian_get_picture_sources')
            ? meridian_get_picture_sources($cardImageReference, null, 960, 560)
            : ['url' => '', 'webp_url' => '', 'width' => 960, 'height' => 560];
        $cardImageDimensions = function_exists('meridian_image_dimension_attributes')
            ? meridian_image_dimension_attributes($cardImageReference, 960, 560)
            : 'width="960" height="560"';
    ?>
    <div class="card">
        <div class="card-thumb" style="background:<?php echo meridian_cat_gradient($post->category_name ?? ''); ?>">
            <?php if ($cardImage['url'] !== ''): ?>
            <picture>
                <?php if ($cardImage['webp_url'] !== ''): ?>
                <source srcset="<?php echo htmlspecialchars($cardImage['webp_url'], ENT_QUOTES, 'UTF-8'); ?>" type="image/webp">
                <?php endif; ?>
                <img src="<?php echo htmlspecialchars($cardImage['url'], ENT_QUOTES, 'UTF-8'); ?>"
                     alt="<?php echo htmlspecialchars($post->title); ?>"
                     <?php echo function_exists('meridian_image_loading_attributes') ? meridian_image_loading_attributes(false) : 'loading="lazy" decoding="async"'; ?>
                     <?php echo $cardImageDimensions; ?>>
            </picture>
            <?php endif; ?>
            <?php if ($post->category_name): ?>
            <span class="card-cat"><?php echo htmlspecialchars($post->category_name); ?></span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <h3>
                <a href="<?php echo htmlspecialchars(meridian_post_url($post), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($post->title); ?>
                </a>
            </h3>
            <p><?php echo htmlspecialchars(meridian_excerpt($post->excerpt ?: $post->content, 100)); ?></p>
            <div class="card-footer">
                <time><?php echo meridian_format_date($post->published_at ?? $post->created_at, true); ?></time>
                <?php echo meridian_post_update_badge($post); ?>
                <a href="<?php echo htmlspecialchars(meridian_post_url($post), ENT_QUOTES, 'UTF-8'); ?>" class="read-link">Lesen →</a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Feature-Row ───────────────────────────────────────────────────────── -->
<?php if (!empty($featurePosts)): ?>
<div class="section-label"><h2>Schwerpunkte</h2></div>
<div class="feature-row">
    <?php foreach ($featurePosts as $post): ?>
    <div class="feature-box">
        <h3>
            <a href="<?php echo htmlspecialchars(meridian_post_url($post), ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($post->title); ?>
            </a>
        </h3>
        <p><?php echo htmlspecialchars(meridian_excerpt($post->excerpt ?: $post->content, 120)); ?></p>
        <?php echo meridian_post_update_badge($post); ?>
        <a href="<?php echo htmlspecialchars(meridian_post_url($post), ENT_QUOTES, 'UTF-8'); ?>" class="feature-link">
            <?php echo htmlspecialchars($post->category_name ?: 'Weiterlesen'); ?> →
        </a>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$heroPost && empty($articleList)): ?>
<div class="empty-state">
    <p style="font-size:3rem;margin:0">📰</p>
    <p><strong>Noch keine Artikel veröffentlicht</strong></p>
    <p>Die ersten Artikel erscheinen hier, sobald sie veröffentlicht werden.</p>
</div>
<?php endif; ?>

</div>

<!-- ── Sidebar ────────────────────────────────────────────────────────────── -->
<?php if ($showSidebar): ?>
<aside class="sidebar" aria-label="Sidebar">

    <!-- Newsletter Widget -->
    <div class="newsletter-widget">
        <div class="widget-title">Newsletter</div>
        <h3>Kein Artikel verpassen</h3>
        <p>Die besten Artikel direkt in dein Postfach – kostenlos.</p>
        <form action="<?php echo htmlspecialchars(meridian_auth_url('register'), ENT_QUOTES, 'UTF-8'); ?>" method="GET">
            <input type="email" name="email" placeholder="deine@email.de" required autocomplete="email" aria-label="E-Mail-Adresse für Newsletter">
            <button type="submit">Jetzt abonnieren →</button>
        </form>
    </div>

    <!-- Kategorien -->
    <?php if (!empty($sidebarCats)): ?>
    <div>
        <div class="widget-title">Kategorien</div>
        <?php foreach ($sidebarCats as $cat): ?>
        <div class="cat-row">
            <a href="<?php echo htmlspecialchars(meridian_archive_url('category', (string) ($cat['slug'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($cat['name']); ?>
            </a>
            <?php if (!empty($cat['post_count'])): ?>
            <span class="cat-count"><?php echo (int)$cat['post_count']; ?></span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Zuletzt erschienen -->
    <?php if (!empty($recentSidebar)): ?>
    <div>
        <div class="widget-title">Zuletzt erschienen</div>
        <?php foreach ($recentSidebar as $i => $recent):
            $rArr = is_object($recent) ? (array)$recent : (array)$recent;
        ?>
        <div class="recent-item">
            <div class="recent-num"><?php echo str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT); ?></div>
            <div class="recent-body">
                <?php if (!empty($rArr['category_name'])): ?>
                <div class="rcat"><?php echo htmlspecialchars($rArr['category_name']); ?></div>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars(meridian_post_url($rArr), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($rArr['title'] ?? ''); ?>
                </a>
                <time><?php echo meridian_format_date($rArr['published_at'] ?? $rArr['created_at'] ?? '', true); ?></time>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Tag Cloud -->
    <?php if (!empty($tagCloud)): ?>
    <div>
        <div class="widget-title">Tags</div>
        <div class="tag-cloud">
            <?php foreach ($tagCloud as $tag): ?>
            <a href="<?php echo htmlspecialchars(meridian_archive_url('tag', (string) ($tag['slug'] !== '' ? $tag['slug'] : $tag['name'])), ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars((string) $tag['name'], ENT_QUOTES, 'UTF-8'); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</aside>
<?php endif; ?>

</div><!-- /.page-wrap -->
<?php endif; /* Ende: homepage mode */ ?>
