<?php
/**
 * Meridian CMS Default – Index / Home Template
 *
 * @package CMSv2\Themes\CmsDefault
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/header.php';

// Data fetching
$heroPost     = [];
$latestPosts  = [];
$featurePosts = []; // For card grid

// Example fetch logic (adjust to actual CMS API)
if (function_exists('meridian_get_posts')) {
    // 1. Hero: Sticky or latest
    $heroResults = meridian_get_posts(['limit' => 1, 'sticky' => true]); 
    if (empty($heroResults)) {
        $heroResults = meridian_get_posts(['limit' => 1]);
    }
    $heroPost = $heroResults[0] ?? null;

    // 2. Main List: Next 4 posts
    $offset = $heroPost ? 1 : 0;
    $latestPosts = meridian_get_posts(['limit' => 4, 'offset' => $offset]);

    // 3. Card Grid: Next 3 posts
    $offset += 4;
    $featurePosts = meridian_get_posts(['limit' => 3, 'offset' => $offset]);
}
?>

<div class="page-wrap">
    <div class="content-main">

        <!-- Featured Post -->
        <?php if ($heroPost): ?>
            <?php
            $hTitle   = htmlspecialchars($heroPost['title'] ?? '');
            $hLink    = isset($heroPost['slug']) ? htmlspecialchars(meridian_post_url($heroPost), ENT_QUOTES, 'UTF-8') : '#';
            $hExcerpt = htmlspecialchars($heroPost['excerpt'] ?? '');
            $hDate    = meridian_format_date((string) ($heroPost['published_at'] ?? $heroPost['created_at'] ?? ''));
            $hCat     = $heroPost['category_name'] ?? 'Allgemein';
            $hAuthor  = $heroPost['author_name'] ?? 'Redaktion';
            $hRead    = isset($heroPost['read_time']) ? $heroPost['read_time'] . ' Min.' : '5 Min.';
            ?>
            <div class="hero-post">
                <div class="hero-image">
                    <div class="hero-image-icon">
                        <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    </div>
                    <div class="hero-cat-badge"><?php echo htmlspecialchars($hCat); ?></div>
                </div>
                <div class="hero-body">
                    <div class="post-cat"><?php echo htmlspecialchars($hCat); ?></div>
                    <h2><a href="<?php echo $hLink; ?>"><?php echo $hTitle; ?></a></h2>
                    <p class="excerpt"><?php echo $hExcerpt; ?></p>
                    <div class="post-meta">
                        <?php if (meridian_show_author()): ?>
                        <div class="meta-author">
                            <div class="avatar-xs"><?php echo htmlspecialchars(mb_substr((string) $hAuthor, 0, 2), ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php echo htmlspecialchars($hAuthor); ?>
                        </div>
                        <span class="meta-sep">·</span>
                        <?php endif; ?>
                        <time class="meta-date"><?php echo $hDate; ?></time>
                        <span class="meta-sep">·</span>
                        <span class="meta-read"><?php echo $hRead; ?></span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Aktuelle Artikel -->
        <div class="section-label"><h3>Aktuelle Artikel</h3></div>

        <div class="article-list">
            <?php if (!empty($latestPosts)): ?>
                <?php foreach ($latestPosts as $post): 
                    $pTitle  = htmlspecialchars($post['title'] ?? '');
                    $pLink   = isset($post['slug']) ? htmlspecialchars(meridian_post_url($post), ENT_QUOTES, 'UTF-8') : '#';
                    $pExcerpt= htmlspecialchars($post['excerpt'] ?? '');
                    $pDate   = meridian_format_date((string) ($post['published_at'] ?? $post['created_at'] ?? ''));
                    $pCat    = $post['category_name'] ?? 'Blog';
                    $pRead   = isset($post['read_time']) ? $post['read_time'] . ' Min.' : '3 Min.';
                ?>
                <div class="article-row">
                    <div class="art-thumb">
                        <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </div>
                    <div class="art-body">
                        <div class="art-cat"><?php echo htmlspecialchars($pCat); ?></div>
                        <div class="art-title"><a href="<?php echo $pLink; ?>"><?php echo $pTitle; ?></a></div>
                        <div class="art-excerpt"><?php echo $pExcerpt; ?></div>
                        <div class="art-meta">
                            <time><?php echo $pDate; ?></time>
                            <span class="dot"></span>
                            <span class="read-t"><?php echo $pRead; ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state" style="padding:2rem 0; text-align:center;">
                    <p style="color:var(--ink-muted);">Keine weiteren Artikel gefunden.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Feature Boxes (Static/Widget Area) -->
        <div class="section-label"><h3>Schwerpunkte</h3></div>
        <div class="feature-row">
            <div class="feature-box">
                <h3>Microsoft 365 Anleitungen</h3>
                <p>Schritt-für-Schritt Tutorials für Teams, SharePoint und alle Microsoft 365 Dienste. Von der Grundkonfiguration bis zu erweiterten Admin-Aufgaben mit PowerShell.</p>
                <a href="<?php echo htmlspecialchars(meridian_archive_url('category', 'm365'), ENT_QUOTES, 'UTF-8'); ?>" class="feature-link">Alle Anleitungen →</a>
            </div>
            <div class="feature-box">
                <h3>Script-Bibliothek &amp; GitHub</h3>
                <p>Über 25 PowerShell-basierte Repositorys für Microsoft-Umgebungen. Exchange, Entra ID, Azure, Intune – code-signed und produktionsreif.</p>
                <a href="https://github.com/phinit" target="_blank" rel="noopener" class="feature-link">Zum Repository →</a>
            </div>
        </div>

        <!-- Card Grid (More Articles) -->
        <?php if (!empty($featurePosts)): ?>
        <div class="section-label"><h3>Weitere Artikel</h3></div>
        <div class="card-grid">
            <?php foreach ($featurePosts as $post): 
                $cTitle = htmlspecialchars($post['title'] ?? '');
                $cLink  = isset($post['slug']) ? htmlspecialchars(meridian_post_url($post), ENT_QUOTES, 'UTF-8') : '#';
                $cExcerpt = htmlspecialchars($post['excerpt'] ?? '');
                $cDate  = meridian_format_date((string) ($post['published_at'] ?? $post['created_at'] ?? ''));
                $cCat   = $post['category_name'] ?? 'Tipp';
            ?>
            <div class="card">
                <div class="card-thumb" style="background:linear-gradient(135deg,#1a2a3a,#1a1a18);">
                    <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                    <span class="card-cat"><?php echo htmlspecialchars($cCat); ?></span>
                </div>
                <div class="card-body">
                    <h4><a href="<?php echo $cLink; ?>"><?php echo $cTitle; ?></a></h4>
                    <p><?php echo $cExcerpt; ?></p>
                    <div class="card-footer">
                        <time><?php echo $cDate; ?></time>
                        <a href="<?php echo $cLink; ?>" class="read-link">Lesen →</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>

    <!-- Sidebar -->
    <aside class="sidebar">

        <!-- Newsletter Widget -->
        <div class="newsletter-widget">
            <div class="widget-title">Newsletter</div>
            <h3>Kein Artikel verpassen</h3>
            <p>Praxiswissen direkt ins Postfach – kein Spam, jederzeit abbestellbar.</p>
            <form action="<?php echo htmlspecialchars(meridian_auth_url('register'), ENT_QUOTES, 'UTF-8'); ?>" method="GET">
                <input type="email" name="email" placeholder="deine@email.de" required autocomplete="email" aria-label="E-Mail-Adresse für Newsletter">
                <button type="submit">Jetzt abonnieren →</button>
            </form>
        </div>

        <!-- Categories Widget -->
        <div>
            <div class="widget-title">Kategorien</div>
            <?php 
            $cats = function_exists('meridian_get_categories') ? meridian_get_categories(8) : [];
            foreach ($cats as $cat): 
            ?>
            <div class="cat-row">
                <a href="<?php echo htmlspecialchars(meridian_archive_url('category', (string) ($cat['slug'])), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($cat['name']); ?>
                </a>
                <span class="cat-count"><?php echo (int) ($cat['count'] ?? 0); ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Recent/Sticky Widget -->
        <div>
            <div class="widget-title">Zuletzt erschienen</div>
            <?php 
            // Reuse latest posts or fetch new small list
            $recentWidgetParams = ['limit' => 4];
            $recentWidget = function_exists('meridian_get_posts') ? meridian_get_posts($recentWidgetParams) : [];
            $i = 1;
            foreach ($recentWidget as $rp): ?>
            <div class="recent-item">
                <div class="recent-num"><?php echo str_pad((string)$i++, 2, '0', STR_PAD_LEFT); ?></div>
                <div class="recent-body">
                    <div class="rcat"><?php echo htmlspecialchars($rp['category_name'] ?? 'Blog'); ?></div>
                    <a href="<?php echo htmlspecialchars(meridian_post_url($rp), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) ($rp['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></a>
                    <time><?php echo meridian_format_date((string) ($rp['published_at'] ?? $rp['created_at'] ?? '')); ?></time>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Tags Widget -->
        <div>
            <div class="widget-title">Tags</div>
            <div class="tag-cloud">
               <?php 
               $tags = function_exists('meridian_get_tags') ? meridian_get_tags(15) : [];
               foreach ($tags as $tag): ?>
               <a href="<?php echo htmlspecialchars(meridian_archive_url('tag', (string) ($tag['slug'])), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($tag['name']); ?></a>
               <?php endforeach; ?>
            </div>
        </div>

    </aside>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
