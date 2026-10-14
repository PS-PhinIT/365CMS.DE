<?php
/**
 * Meridian CMS Default – Single Post Template
 *
 * @package CMSv2\Themes\CmsDefault
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (empty($post)) {
    header('Location: ' . SITE_URL . '/blog');
    exit;
}

// Prepare variables
$pTitleRaw = trim((string) ($post->title ?? ''));
$pTitle = htmlspecialchars($pTitleRaw, ENT_QUOTES, 'UTF-8');
$pLink = meridian_post_url($post);
$pContent = (string) ($post->content ?? '');
$pExcerptRaw = trim((string) ($post->excerpt ?? ''));
$pExcerpt = $pExcerptRaw !== '' ? htmlspecialchars($pExcerptRaw, ENT_QUOTES, 'UTF-8') : '';
$pPublishedAt = (string) ($post->published_at ?? $post->created_at ?? '');
$pDate = $pPublishedAt !== '' ? meridian_format_date($pPublishedAt, false) : '';
$pDateAgo = $pPublishedAt !== '' ? time_ago($pPublishedAt) : '';
$pContentUpdatedAt = trim((string) ($post->content_updated_at ?? ''));
$pUpdatedDate = $pContentUpdatedAt !== '' ? meridian_format_date($pContentUpdatedAt, false) : '';
$pAuthor = trim((string) ($post->author_name ?? 'Redaktion'));
$pAuthIni = meridian_author_initials($pAuthor);
$pCat = trim((string) ($post->category_name ?? 'Blog'));
$pCatSlug = trim((string) ($post->category_slug ?? 'blog'));
$pReadMinutes = !empty($post->read_time) ? max(1, (int) $post->read_time) : meridian_reading_time($pContent);
$pRead = $pReadMinutes . ' Min. Lesezeit';
$pTags = $post->tags ?? [];
$pFeaturedImageReference = (string) ($post->featured_image ?? '');
$pFeaturedImage = function_exists('meridian_get_picture_sources')
  ? meridian_get_picture_sources($pFeaturedImageReference, null, 1600, 900)
  : ['url' => '', 'webp_url' => '', 'width' => 1600, 'height' => 900];
$pFeaturedImageDimensions = function_exists('meridian_image_dimension_attributes')
  ? meridian_image_dimension_attributes($pFeaturedImageReference, 1600, 900)
  : 'width="1600" height="900"';
$pFeaturedLoading = function_exists('meridian_image_loading_attributes')
  ? meridian_image_loading_attributes(true)
  : 'loading="eager" decoding="async"';
$pViews = max(0, (int) ($post->views ?? 0));

if (is_string($pTags)) {
  $pTags = meridian_post_tags($pTags);
} elseif (is_array($pTags)) {
  $pTags = array_values(array_filter(array_map(static fn(mixed $tag): string => trim((string) $tag), $pTags)));
} else {
  $pTags = [];
}

$showRelated  = (bool) meridian_setting('blog', 'show_related_posts', true);
$showComments = (bool) meridian_setting('blog', 'show_comments', true);
$showViews    = (bool) meridian_setting('blog', 'show_views', false);
$postAllowsComments = (int) ($post->allow_comments ?? 1) === 1
  && (!class_exists('\CMS\Services\CommentService') || \CMS\Services\CommentService::getInstance()->areCommentsEnabledGlobally());
$currentUser = class_exists('\CMS\Auth') ? \CMS\Auth::getCurrentUser() : null;
$isLoggedInCommentAuthor = isset($currentUser->id) && (int) ($currentUser->id ?? 0) > 0;

$commentFormState = null;
if (isset($_SESSION['comment_form_state']) && is_array($_SESSION['comment_form_state'])) {
  $candidateCommentState = $_SESSION['comment_form_state'][(int) ($post->id ?? 0)] ?? null;
  if (is_array($candidateCommentState)) {
    $commentFormState = $candidateCommentState;
  }
  unset($_SESSION['comment_form_state'][(int) ($post->id ?? 0)]);
}

$commentFormValues = [
  'author' => '',
  'email' => '',
  'comment' => '',
  'comment_anonymous' => false,
];

if ($isLoggedInCommentAuthor) {
  $resolvedCommentAuthorName = trim((string) ($currentUser->display_name ?? $currentUser->username ?? ''));
  if ($resolvedCommentAuthorName === '') {
    $resolvedCommentAuthorName = trim((string) ($currentUser->username ?? ''));
  }

  $commentFormValues['author'] = $resolvedCommentAuthorName;
  $commentFormValues['email'] = trim((string) ($currentUser->email ?? ''));
}

if (is_array($commentFormState['values'] ?? null)) {
  $commentFormValues['author'] = trim((string) ($commentFormState['values']['author'] ?? $commentFormValues['author']));
  $commentFormValues['email'] = trim((string) ($commentFormState['values']['email'] ?? $commentFormValues['email']));
  $commentFormValues['comment'] = trim((string) ($commentFormState['values']['comment'] ?? ''));
  $commentFormValues['comment_anonymous'] = !empty($commentFormState['values']['comment_anonymous']);
}

$approvedComments = ($showComments && class_exists('\CMS\Services\CommentService'))
  ? \CMS\Services\CommentService::getInstance()->getApprovedForPost((int) ($post->id ?? 0))
  : [];
$approvedCommentCount = count($approvedComments);
$shouldRenderCommentsSection = $showComments && ($postAllowsComments || $approvedCommentCount > 0);

// Verwandte Posts: korrekte Signatur (categoryId, excludeId, limit)
$relatedPosts = ($showRelated && function_exists('meridian_get_related_posts'))
    ? meridian_get_related_posts((int)($post->category_id ?? 0), (int)($post->id ?? 0), 3)
    : [];
?>

<article class="post-detail">

  <div class="breadcrumb-bar">
    <nav class="breadcrumb-inner" aria-label="Breadcrumb">
      <a href="<?php echo SITE_URL; ?>/">Startseite</a><span class="sep">›</span>
      <a href="<?php echo SITE_URL; ?>/blog">Blog</a><span class="sep">›</span>
      <a href="<?php echo htmlspecialchars(meridian_archive_url('category', (string) ($pCatSlug)), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($pCat, ENT_QUOTES, 'UTF-8'); ?></a><span class="sep">›</span>
      <span class="cur"><?php echo $pTitle; ?></span>
    </nav>
  </div>

  <div class="post-detail-shell">
    <header class="post-hero">
      <div class="post-hero__intro">
        <div class="post-hero__eyebrow">
          <a href="<?php echo htmlspecialchars(meridian_archive_url('category', (string) ($pCatSlug)), ENT_QUOTES, 'UTF-8'); ?>" class="post-hero__category"><?php echo htmlspecialchars($pCat, ENT_QUOTES, 'UTF-8'); ?></a>
          <span class="post-hero__sep">•</span>
          <span class="post-hero__reading"><?php echo htmlspecialchars($pRead, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>

        <h1 class="post-hero__title"><?php echo $pTitle; ?></h1>

        <?php if ($pExcerpt !== ''): ?>
        <p class="post-hero__excerpt"><?php echo $pExcerpt; ?></p>
        <?php endif; ?>

        <div class="post-hero__meta">
          <div class="author-chip">
            <div class="avatar-sm"><?php echo htmlspecialchars($pAuthIni, ENT_QUOTES, 'UTF-8'); ?></div>
            <div>
              <div class="name"><?php echo htmlspecialchars($pAuthor, ENT_QUOTES, 'UTF-8'); ?></div>
              <div class="role">Redaktion &amp; Autorenprofil</div>
            </div>
          </div>

          <div class="post-hero__meta-list">
            <?php if ($pDate !== ''): ?>
            <span><strong>Veröffentlicht</strong> <?php echo htmlspecialchars($pDate, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
            <?php if ($pDateAgo !== ''): ?>
            <span><?php echo htmlspecialchars($pDateAgo, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
            <?php if ($pUpdatedDate !== ''): ?>
            <span><strong>Aktualisiert</strong> <?php echo htmlspecialchars($pUpdatedDate, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
            <?php if ($showViews && $pViews > 0): ?>
            <span><?php echo number_format($pViews, 0, ',', '.'); ?> Aufrufe</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <aside class="post-stat-card" aria-label="Artikeldetails">
        <div class="post-stat-card__item">
          <span class="post-stat-card__label">Kategorie</span>
          <span class="post-stat-card__value"><?php echo htmlspecialchars($pCat, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div class="post-stat-card__item">
          <span class="post-stat-card__label">Lesezeit</span>
          <span class="post-stat-card__value"><?php echo htmlspecialchars($pRead, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <?php if ($pDate !== ''): ?>
        <div class="post-stat-card__item">
          <span class="post-stat-card__label">Stand</span>
          <span class="post-stat-card__value"><?php echo htmlspecialchars($pDate, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <?php endif; ?>
      </aside>
    </header>

    <?php if ($pFeaturedImage['url'] !== ''): ?>
    <figure class="post-featured-figure">
      <picture>
        <?php if ($pFeaturedImage['webp_url'] !== ''): ?>
        <source srcset="<?php echo htmlspecialchars($pFeaturedImage['webp_url'], ENT_QUOTES, 'UTF-8'); ?>" type="image/webp">
        <?php endif; ?>
        <img src="<?php echo htmlspecialchars($pFeaturedImage['url'], ENT_QUOTES, 'UTF-8'); ?>"
             alt="<?php echo $pTitle; ?>"
             <?php echo $pFeaturedLoading; ?>
             <?php echo $pFeaturedImageDimensions; ?>>
      </picture>
    </figure>
    <?php endif; ?>

    <div class="post-body post-body--detail">
      <?php echo $pContent; // Content is trusted ?>
    </div>

    <?php if (!empty($pTags)): ?>
    <div class="post-footer-bar post-footer-bar--detail">
      <span class="pf-label">Schlagwörter</span>
      <div class="pf-tags">
        <?php foreach ((is_array($post->tag_items ?? null) && $post->tag_items !== [] ? $post->tag_items : $pTags) as $tag): $tagLink = meridian_tag_link_data($tag); if ($tagLink['name'] === '') { continue; } ?>
        <a href="<?php echo htmlspecialchars(meridian_archive_url('tag', $tagLink['slug']), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($tagLink['name'], ENT_QUOTES, 'UTF-8'); ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="author-box-wrap">
      <div class="author-box author-box--elevated">
        <div class="author-avatar-lg"><?php echo htmlspecialchars($pAuthIni, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="author-box-info">
          <div class="label">Über den Autor</div>
          <h2><?php echo htmlspecialchars($pAuthor, ENT_QUOTES, 'UTF-8'); ?></h2>
          <div class="arole">Redaktion · Schwerpunkt <?php echo htmlspecialchars($pCat, ENT_QUOTES, 'UTF-8'); ?></div>
          <p>Konzipiert, verdichtet und veröffentlicht Beiträge mit Fokus auf nachvollziehbare Praxis, klare Struktur und einen brauchbaren Erkenntnisgewinn statt Deko-Textwüste.</p>
        </div>
      </div>
    </div>

    <?php if ($showRelated && !empty($relatedPosts)): ?>
    <section class="related-section" aria-labelledby="related-posts-heading">
      <div class="section-label"><h2 id="related-posts-heading">Verwandte Artikel</h2></div>
      <div class="related-grid">
        <?php foreach ($relatedPosts as $rp): $rp = (array) $rp;
            $rTitleRaw = trim((string) ($rp['title'] ?? ''));
            $rTitle = htmlspecialchars($rTitleRaw, ENT_QUOTES, 'UTF-8');
            $rLink = meridian_post_url($rp);
            $rCat = trim((string) ($rp['category_name'] ?? 'Tipp'));
            $rDate = !empty($rp['published_at'] ?? $rp['created_at'] ?? '')
                ? meridian_format_date((string) ($rp['published_at'] ?? $rp['created_at']), true)
                : '';
            $rExcerptRaw = trim((string) ($rp['excerpt'] ?? ''));
            $rExcerpt = $rExcerptRaw !== ''
                ? htmlspecialchars($rExcerptRaw, ENT_QUOTES, 'UTF-8')
                : 'Mehr aus diesem Themenfeld lesen';
            $rImageRef = (string) ($rp['featured_image'] ?? '');
            $rPicture = function_exists('meridian_get_picture_sources')
                ? meridian_get_picture_sources($rImageRef, null, 720, 405)
                : ['url' => '', 'webp_url' => '', 'width' => 720, 'height' => 405];
            $rImageDimensions = function_exists('meridian_image_dimension_attributes')
                ? meridian_image_dimension_attributes($rImageRef, 720, 405)
                : 'width="720" height="405"';
        ?>
        <article class="related-card related-card--detailed">
          <?php if ($rPicture['url'] !== ''): ?>
          <a href="<?php echo htmlspecialchars($rLink, ENT_QUOTES, 'UTF-8'); ?>" class="related-card__media">
            <picture>
              <?php if ($rPicture['webp_url'] !== ''): ?>
              <source srcset="<?php echo htmlspecialchars($rPicture['webp_url'], ENT_QUOTES, 'UTF-8'); ?>" type="image/webp">
              <?php endif; ?>
              <img src="<?php echo htmlspecialchars($rPicture['url'], ENT_QUOTES, 'UTF-8'); ?>"
                   alt="<?php echo $rTitle; ?>"
                   <?php echo function_exists('meridian_image_loading_attributes') ? meridian_image_loading_attributes(false) : 'loading="lazy" decoding="async"'; ?>
                   <?php echo $rImageDimensions; ?>>
            </picture>
          </a>
          <?php else: ?>
          <a href="<?php echo htmlspecialchars($rLink, ENT_QUOTES, 'UTF-8'); ?>" class="related-card__media related-card__media--placeholder" aria-hidden="true" tabindex="-1">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          </a>
          <?php endif; ?>
          <div class="rc-body">
            <div class="rc-cat"><?php echo htmlspecialchars($rCat, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="rc-title"><a href="<?php echo htmlspecialchars($rLink, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $rTitle; ?></a></div>
            <p class="rc-excerpt"><?php echo $rExcerpt; ?></p>
            <?php if ($rDate !== ''): ?><div class="rc-meta"><?php echo htmlspecialchars($rDate, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($shouldRenderCommentsSection): ?>
    <section id="comments" class="comments-section" aria-labelledby="comments-heading">
      <div class="section-label"><h2 id="comments-heading">Kommentare<?php echo $approvedCommentCount > 0 ? ' (' . $approvedCommentCount . ')' : ''; ?></h2></div>

      <?php if ($approvedCommentCount > 0): ?>
      <div class="comment-thread" aria-label="Freigegebene Kommentare">
        <?php foreach ($approvedComments as $comment): ?>
          <?php
            $commentAuthor = trim((string) ($comment->author ?? 'Gast'));
            $commentDateRaw = (string) ($comment->post_date ?? '');
            $commentDate = $commentDateRaw !== '' ? meridian_format_date($commentDateRaw, false) : '';
            $commentDateIso = $commentDateRaw !== '' ? date(DATE_ATOM, strtotime($commentDateRaw) ?: time()) : '';
            $commentText = nl2br(htmlspecialchars((string) ($comment->content ?? ''), ENT_QUOTES, 'UTF-8'));
            $commentInitials = meridian_author_initials($commentAuthor !== '' ? $commentAuthor : 'Gast');
            $commentIsAnonymous = !empty($comment->is_anonymous);
          ?>
          <article class="comment-item" id="comment-<?php echo (int) ($comment->id ?? 0); ?>">
            <div class="comment-av"><?php echo htmlspecialchars($commentInitials, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="comment-body">
              <div class="comment-meta-row">
                <span class="comment-name"><?php echo htmlspecialchars($commentAuthor !== '' ? $commentAuthor : 'Gast', ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if ($commentIsAnonymous): ?>
                <span class="comment-author-tag">Anonym</span>
                <?php endif; ?>
                <?php if ($commentDate !== ''): ?>
                <time class="comment-date" datetime="<?php echo htmlspecialchars($commentDateIso, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($commentDate, ENT_QUOTES, 'UTF-8'); ?></time>
                <?php endif; ?>
              </div>
              <div class="comment-text"><?php echo $commentText; ?></div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <?php elseif ($postAllowsComments): ?>
      <p class="comments-empty">Noch keine Kommentare – sei der Erste.</p>
      <?php elseif (!$postAllowsComments): ?>
      <p class="comments-empty">Für diesen Beitrag sind derzeit keine Kommentare veröffentlicht.</p>
      <?php endif; ?>

      <?php if (is_array($commentFormState) && !empty($commentFormState['message'])): ?>
      <div class="alert alert-<?php echo !empty($commentFormState['type']) && $commentFormState['type'] === 'success' ? 'success' : 'error'; ?>">
        <?php echo htmlspecialchars((string) ($commentFormState['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
      </div>
      <?php endif; ?>

      <?php if ($postAllowsComments): ?>
      <div class="comment-form-wrap">
        <h3>Einen Kommentar hinterlassen</h3>
        <?php
          $commentCsrf = '';
          if (class_exists('\\CMS\\Security')) {
              $commentCsrf = \CMS\Security::instance()->generateToken('comment_' . ($post->id ?? 0));
          }
        ?>
        <form method="post" action="<?php echo SITE_URL; ?>/comments/post">
           <input type="hidden" name="post_id" value="<?php echo (int) ($post->id ?? 0); ?>">
           <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($commentCsrf, ENT_QUOTES, 'UTF-8'); ?>">
           <input type="text" name="comment_hp" value="" tabindex="-1" autocomplete="off" aria-hidden="true" class="visually-hidden">
           <input type="hidden" name="comment_started_at" value="<?php echo time(); ?>">
           <div class="form-2col">
              <div class="form-field"><label>Name <span class="form-required">*</span></label><input type="text" name="author" placeholder="Dein Name" required maxlength="100" autocomplete="name" value="<?php echo htmlspecialchars($commentFormValues['author'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo $isLoggedInCommentAuthor ? ' readonly' : ''; ?>></div>
              <div class="form-field"><label>E-Mail <span class="form-required">*</span></label><input type="email" name="email" placeholder="deine@email.de" required maxlength="200" autocomplete="email" value="<?php echo htmlspecialchars($commentFormValues['email'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo $isLoggedInCommentAuthor ? ' readonly' : ''; ?>></div>
              <div class="form-field form-full"><label>Kommentar <span class="form-required">*</span></label><textarea name="comment" placeholder="Dein Kommentar …" required maxlength="5000"><?php echo htmlspecialchars($commentFormValues['comment'], ENT_QUOTES, 'UTF-8'); ?></textarea></div>
           </div>
           <?php if ($isLoggedInCommentAuthor): ?>
           <label class="checkbox-label" style="margin-bottom: .9rem;">
             <input type="checkbox" name="comment_anonymous" value="1" <?php echo !empty($commentFormValues['comment_anonymous']) ? 'checked' : ''; ?>>
             <span>Kommentar anonym veröffentlichen</span>
           </label>
           <?php endif; ?>
           <button type="submit" class="btn-submit">Kommentar absenden →</button>
           <p class="form-text" style="margin-top: .75rem;">Kommentare werden vor der Veröffentlichung geprüft.</p>
        </form>
      </div>
      <?php else: ?>
      <p class="comments-login-hint">Die Kommentarfunktion ist für diesen Beitrag geschlossen.</p>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </div>

</article>

