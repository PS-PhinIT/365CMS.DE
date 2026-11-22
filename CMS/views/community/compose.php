<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Community – Formular für neues Thema, Antwort und Bearbeiten (POST an dieselbe Adresse, 303 zurück).
 * Variablen: $mode (new|reply|edit), $heading, $submit_label, $cancel_url, $show_title, $title, $body, $reason, $seen (letzter bekannter
 * Beitrag, nur Antwort), $edit_count (nur Bearbeiten), $quote (Beitrag, der zitiert wird, oder 0), $error, $new_posts, $readonly (nur Hinweis,
 * kein Formular), $api (JSON-API oder null), $editor (visueller Editor: mode, css, js, lang; oder null), $captcha (HTML der Captcha-Aufgabe oder leer), $login_url, $token (einmaliges form_guard-Token), $topic, $forum, $locale, $e.
 * Anhänge: $upload_token (Formular-Kennung), $pending_attachments (hochgeladen, noch nicht zugeordnet), $post_attachments (beim Bearbeiten),
 * $remove_attachments (angekreuzte IDs). Entwürfe: lokal im Browser (alle Modi) und auf dem Server (neues Thema, Antwort).
 */
?>
<?php
$t = static fn (string $key, array $vars = []): string => \CMS\Services\Community\Support\I18n::t('community.compose.' . $key, $vars);
$pendingAttachments = is_array($pending_attachments ?? null) ? $pending_attachments : [];
$postAttachments = is_array($post_attachments ?? null) ? $post_attachments : [];
$removeAttachments = array_map('intval', is_array($remove_attachments ?? null) ? $remove_attachments : []);
$draftKey = $mode === 'reply' ? 'topic_id' : ($mode === 'new' ? 'forum_id' : '');
$jsTexts = ['uploading', 'uploaded', 'upload_failed', 'insert', 'discard', 'visual_hint', 'draft_local', 'draft_server', 'draft_saved', 'draft_failed'];
$previewKey = $mode === 'reply' ? 'topic_id' : ($mode === 'edit' ? 'post_id' : 'forum_id');
$previewId = $mode === 'reply' ? (int) ($topic['id'] ?? 0) : ($mode === 'edit' ? (int) ($post['id'] ?? 0) : (int) ($forum['id'] ?? 0));
?>
<div id="main" class="cms-community cms-community--compose"<?php if (!empty($api)): ?> data-cc-api="<?= $e($api['url']) ?>" data-cc-token="<?= $e($api['token']) ?>" data-cc-messages="<?= $e($api['messages'] ?? '{}') ?>" data-cc-reasons="{}" data-cc-reason-required="0"<?php endif; ?><?php if (!empty($editor)): ?> data-cc-editor="<?= $e($editor['mode']) ?>" data-css-url="<?= $e($editor['css']) ?>" data-js-url="<?= $e($editor['js']) ?>" data-lang-url="<?= $e($editor['lang']) ?>" data-target-key="<?= $e($previewKey) ?>" data-target-id="<?= (int) $previewId ?>"<?php endif; ?><?php if (!empty($api) && empty($readonly)): ?> data-cc-compose="<?= $e($mode) ?>" data-cc-draft-key="<?= $e($draftKey) ?>" data-cc-upload-key="<?= $e($previewKey) ?>" data-cc-upload-id="<?= (int) $previewId ?>" data-cc-local-draft="<?= $e('cc-draft:' . $mode . ':' . $previewKey . ':' . $previewId) ?>" data-cc-texts="<?= $e((string) json_encode(array_combine($jsTexts, array_map($t, $jsTexts)), JSON_UNESCAPED_UNICODE)) ?>"<?php endif; ?>>
    <p id="cc-status" class="cms-community-flash" hidden></p>
    <h1><?= $e($heading) ?></h1>
    <?php if (!empty($error)): ?>
        <p class="cms-community-alert" role="alert"><?= $e($error) ?></p>
    <?php endif; ?>
    <?php if (!empty($readonly)): ?>
        <p>
            <?php if (!empty($login_url)): ?><a class="cms-community-button" href="<?= $e($login_url) ?>"><?= $e($t('login')) ?></a> <?php endif; ?>
            <a href="<?= $e($cancel_url) ?>"><?= $e($t('back')) ?></a>
        </p>
    <?php else: ?>
    <form method="post" class="cms-community-form">
        <input type="hidden" name="csrf_token" value="<?= $e($token) ?>">
        <?php if (!empty($upload_token)): ?><input type="hidden" name="upload_token" value="<?= $e($upload_token) ?>"><?php endif; ?>
        <?php if ($mode === 'reply'): ?>
            <input type="hidden" name="seen_last_post_id" value="<?= (int) $seen ?>">
            <input type="hidden" name="quote_of_post_id" value="<?= (int) ($quote ?? 0) ?>">
        <?php elseif ($mode === 'edit'): ?>
            <input type="hidden" name="edit_count" value="<?= (int) $edit_count ?>">
        <?php endif; ?>
        <?php if ($show_title): ?>
            <p><label for="cc-title"><?= $e($t('title')) ?></label>
            <input class="cms-community-input" id="cc-title" name="title" type="text" required maxlength="200" value="<?= $e($title) ?>"></p>
        <?php endif; ?>
        <p class="cms-community-draft" data-cc-draft-offer hidden>
            <span data-cc-draft-text></span>
            <button type="button" data-cc-draft-restore><?= $e($t('draft_restore')) ?></button>
            <button type="button" data-cc-draft-dismiss><?= $e($t('draft_dismiss')) ?></button>
        </p>
        <p><label for="cc-body"><?= $e($t('body')) ?></label>
        <textarea class="cms-community-input" id="cc-body" name="body" rows="12" required><?= $e($body) ?></textarea></p>
        <?php if (!empty($upload_token)): ?>
        <fieldset class="cms-community-attachments" data-cc-attachments>
            <legend><?= $e($t('attachments')) ?></legend>
            <?php if ($postAttachments !== []): ?>
                <ul class="cms-community-attachments__list">
                <?php foreach ($postAttachments as $item): ?>
                    <li><label><input type="checkbox" name="remove_attachments[]" value="<?= (int) $item['id'] ?>"<?= in_array((int) $item['id'], $removeAttachments, true) ? ' checked' : '' ?>>
                        <?= $e($t('remove_attachment', ['name' => (string) $item['name']])) ?></label></li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <ul class="cms-community-attachments__list" data-cc-attachment-list>
            <?php foreach ($pendingAttachments as $item): ?>
                <li data-cc-attachment="<?= (int) $item['id'] ?>" data-markdown="<?= $e((!empty($item['is_image']) ? '!' : '') . '[' . str_replace(['[', ']'], ['(', ')'], (string) $item['name']) . '](attachment:' . (int) $item['id'] . ')') ?>">
                    <input type="hidden" name="attachments[]" value="<?= (int) $item['id'] ?>">
                    <span><?= $e((string) $item['name']) ?></span>
                    <button type="button" data-cc-attachment-insert><?= $e($t('insert')) ?></button>
                    <button type="button" data-cc-attachment-discard><?= $e($t('discard')) ?></button>
                </li>
            <?php endforeach; ?>
            </ul>
            <p class="cms-community-dropzone" data-cc-dropzone>
                <label for="cc-files"><?= $e($t('upload_label')) ?></label>
                <input id="cc-files" type="file" multiple data-cc-file-input>
                <span class="cms-community-hint"><?= $e($t('upload_hint')) ?></span>
            </p>
        </fieldset>
        <?php endif; ?>
        <?php if ($mode === 'edit'): ?>
            <p><label for="cc-reason"><?= $e($t('reason')) ?></label>
            <input class="cms-community-input" id="cc-reason" name="reason" type="text" maxlength="200" value="<?= $e($reason) ?>"></p>
        <?php endif; ?>
        <?php if (!empty($new_posts)): ?>
            <p><label><input type="checkbox" name="force" value="1"> <?= $e($t('force')) ?></label></p>
        <?php endif; ?>
        <?php if (!empty($captcha)): ?>
            <div class="cms-community-captcha"><?= $captcha /* HTML des Captcha-Dienstes: alle Werte dort maskiert */ ?></div>
        <?php endif; ?>
        <p class="cms-community-form__actions">
            <button class="cms-community-button" type="submit"><?= $e($submit_label) ?></button>
            <?php if (!empty($api) && $previewId > 0): ?><button type="button" data-cc-action="preview" data-target-key="<?= $e($previewKey) ?>" data-target-id="<?= $previewId ?>"><?= $e($t('preview')) ?></button>
                <label class="cms-community-live"><input type="checkbox" data-cc-live-preview> <?= $e($t('live_preview')) ?></label><?php endif; ?>
            <a href="<?= $e($cancel_url) ?>"><?= $e($t('cancel')) ?></a>
            <span class="cms-community-hint" data-cc-draft-status aria-live="polite"></span>
        </p>
    </form>
    <div id="cc-preview" class="cms-community-preview cms-community-post__body" hidden aria-live="polite"></div>
    <?php endif; ?>
</div>
