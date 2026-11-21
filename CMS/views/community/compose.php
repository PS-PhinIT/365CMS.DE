<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Community – Formular für neues Thema, Antwort und Bearbeiten (POST an dieselbe Adresse, 303 zurück).
 * Variablen: $mode (new|reply|edit), $heading, $submit_label, $cancel_url, $show_title, $title, $body, $reason, $seen (letzter bekannter
 * Beitrag, nur Antwort), $edit_count (nur Bearbeiten), $quote (Beitrag, der zitiert wird, oder 0), $error, $new_posts, $readonly (nur Hinweis,
 * kein Formular), $api (JSON-API oder null), $login_url, $token (einmaliges form_guard-Token), $topic, $forum, $locale, $e.
 */
?>
<?php
$previewKey = $mode === 'reply' ? 'topic_id' : ($mode === 'edit' ? 'post_id' : 'forum_id');
$previewId = $mode === 'reply' ? (int) ($topic['id'] ?? 0) : ($mode === 'edit' ? (int) ($post['id'] ?? 0) : (int) ($forum['id'] ?? 0));
?>
<main id="main" class="cms-community cms-community--compose"<?php if (!empty($api)): ?> data-cc-api="<?= $e($api['url']) ?>" data-cc-token="<?= $e($api['token']) ?>" data-cc-reasons="{}" data-cc-reason-required="0"<?php endif; ?>>
    <p id="cc-status" class="cms-community-flash" hidden></p>
    <h1><?= $e($heading) ?></h1>
    <?php if (!empty($error)): ?>
        <p class="cms-community-alert" role="alert"><?= $e($error) ?></p>
    <?php endif; ?>
    <?php if (!empty($readonly)): ?>
        <p>
            <?php if (!empty($login_url)): ?><a class="cms-community-button" href="<?= $e($login_url) ?>">Anmelden</a> <?php endif; ?>
            <a href="<?= $e($cancel_url) ?>">Zurück</a>
        </p>
    <?php else: ?>
    <form method="post" class="cms-community-form">
        <input type="hidden" name="csrf_token" value="<?= $e($token) ?>">
        <?php if ($mode === 'reply'): ?>
            <input type="hidden" name="seen_last_post_id" value="<?= (int) $seen ?>">
            <input type="hidden" name="quote_of_post_id" value="<?= (int) ($quote ?? 0) ?>">
        <?php elseif ($mode === 'edit'): ?>
            <input type="hidden" name="edit_count" value="<?= (int) $edit_count ?>">
        <?php endif; ?>
        <?php if ($show_title): ?>
            <p><label for="cc-title">Titel</label>
            <input class="cms-community-input" id="cc-title" name="title" type="text" required maxlength="200" value="<?= $e($title) ?>"></p>
        <?php endif; ?>
        <p><label for="cc-body">Text (Markdown)</label>
        <textarea class="cms-community-input" id="cc-body" name="body" rows="12" required><?= $e($body) ?></textarea></p>
        <?php if ($mode === 'edit'): ?>
            <p><label for="cc-reason">Grund der Änderung (optional)</label>
            <input class="cms-community-input" id="cc-reason" name="reason" type="text" maxlength="200" value="<?= $e($reason) ?>"></p>
        <?php endif; ?>
        <?php if (!empty($new_posts)): ?>
            <p><label><input type="checkbox" name="force" value="1"> Trotzdem senden</label></p>
        <?php endif; ?>
        <p class="cms-community-form__actions">
            <button class="cms-community-button" type="submit"><?= $e($submit_label) ?></button>
            <?php if (!empty($api) && $previewId > 0): ?><button type="button" data-cc-action="preview" data-target-key="<?= $e($previewKey) ?>" data-target-id="<?= $previewId ?>">Vorschau</button><?php endif; ?>
            <a href="<?= $e($cancel_url) ?>">Abbrechen</a>
        </p>
    </form>
    <div id="cc-preview" class="cms-community-preview cms-community-post__body" hidden aria-live="polite"></div>
    <?php endif; ?>
</main>
