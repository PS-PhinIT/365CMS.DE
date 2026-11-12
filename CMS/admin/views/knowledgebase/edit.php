<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Knowledgebase – Eintrag anlegen/bearbeiten: Basisdaten, Kurzinhalte, acht Inhaltsbereiche (Block-Editor),
 * Verlinkungsregeln. Checkboxen senden zusätzlich „0“, damit abgewählte Werte gespeichert werden.
 *
 * @var array<string, mixed> $kbData
 * @var string $csrfToken
 * @var callable $kbE
 * @var callable $kbT
 */

use CMS\Services\EditorJs\EditorJsContentNormalizer;
use CMS\Services\EditorJsService;

$kbEntry = (array) ($kbData['entry'] ?? []);
$kbIsNew = !empty($kbData['is_new']);
$kbV = static fn (string $key, string $default = ''): string => is_scalar($kbEntry[$key] ?? null) ? (string) $kbEntry[$key] : $default;
$kbChecked = static fn (string $key, string $default): string => ($kbV($key, $default) === '1') ? ' checked' : '';
$kbCategories = (array) ($kbData['categories'] ?? []);
$kbCurrentCategory = $kbV('category');
$kbEditor = static function (string $name, string $value, string $label, int $height): string {
    $content = trim($value);
    try {
        if ($content !== '') {
            $content = (string) json_encode(EditorJsContentNormalizer::normalize($content), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return EditorJsService::getInstance()->render($name, $content, [
            'height' => $height,
            'context' => 'cms-knowledgebase',
            'aria_label' => $label,
            'content_width' => 920,
            'content_padding_x' => 28,
        ]);
    } catch (\Throwable) {
        return '<textarea class="form-control" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" rows="8" aria-label="'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</textarea>';
    }
};
?>
<?php if (!empty($kbData['trashed'])): ?>
    <div class="alert alert-warning" role="alert"><?= $kbT('knowledgebase.admin.edit_trashed') ?> <a href="/admin/trash?type=kb_entry"><?= $kbT('knowledgebase.admin.open_trash') ?></a></div>
<?php endif; ?>

<form method="post" action="<?= $kbE(KnowledgebaseModule::url(['section' => 'edit', 'id' => $kbIsNew ? null : (int) ($kbEntry['id'] ?? 0)])) ?>" class="admin-knowledgebase-editor">
    <input type="hidden" name="csrf_token" value="<?= $kbE($csrfToken) ?>">
    <input type="hidden" name="action" value="save_entry">
    <input type="hidden" name="entry_id" value="<?= $kbIsNew ? 0 : (int) ($kbEntry['id'] ?? 0) ?>">

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card mb-4">
                <div class="card-header"><h3 class="card-title"><?= $kbT('knowledgebase.admin.edit_basics') ?></h3></div>
                <div class="card-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label required" for="kbTitle"><?= $kbT('knowledgebase.admin.field_title') ?></label>
                        <input type="text" class="form-control" id="kbTitle" name="title" maxlength="255" required value="<?= $kbE($kbV('title')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required" for="kbKeyword"><?= $kbT('knowledgebase.admin.field_keyword') ?></label>
                        <input type="text" class="form-control" id="kbKeyword" name="keyword" maxlength="190" required value="<?= $kbE($kbV('keyword')) ?>" aria-describedby="kbKeywordHint">
                        <div class="form-hint" id="kbKeywordHint"><?= $kbT('knowledgebase.admin.field_keyword_hint') ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="kbSlug"><?= $kbT('knowledgebase.admin.field_slug') ?></label>
                        <div class="input-group">
                            <span class="input-group-text">/kb/</span>
                            <input type="text" class="form-control" id="kbSlug" name="slug" maxlength="190" value="<?= $kbE($kbV('slug')) ?>" placeholder="<?= $kbT('knowledgebase.admin.field_slug_auto') ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="kbCategory"><?= $kbT('knowledgebase.admin.col_category') ?></label>
                        <select class="form-select" id="kbCategory" name="category">
                            <option value=""><?= $kbT('knowledgebase.admin.field_no_category') ?></option>
                            <?php foreach ($kbCategories as $kbCategory): ?>
                                <?php $kbName = (string) ($kbCategory['category'] ?? ''); ?>
                                <option value="<?= $kbE($kbName) ?>"<?= $kbName === $kbCurrentCategory ? ' selected' : '' ?>><?= $kbE($kbName) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($kbCategories === []): ?>
                            <div class="form-hint"><a href="<?= $kbE(KnowledgebaseModule::url(['section' => 'categories'])) ?>"><?= $kbT('knowledgebase.admin.field_category_create') ?></a></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="kbExcerpt"><?= $kbT('knowledgebase.admin.field_excerpt') ?></label>
                        <textarea class="form-control" id="kbExcerpt" name="excerpt" rows="3"><?= $kbE($kbV('excerpt')) ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="kbTooltip"><?= $kbT('knowledgebase.admin.field_tooltip') ?></label>
                        <textarea class="form-control" id="kbTooltip" name="tooltip_text" rows="3" aria-describedby="kbTooltipHint"><?= $kbE($kbV('tooltip_text')) ?></textarea>
                        <div class="form-hint" id="kbTooltipHint"><?= $kbT('knowledgebase.admin.field_tooltip_hint') ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="kbSynonyms"><?= $kbT('knowledgebase.admin.field_synonyms') ?></label>
                        <textarea class="form-control" id="kbSynonyms" name="synonyms" rows="3" aria-describedby="kbSynonymsHint"><?= $kbE($kbV('synonyms')) ?></textarea>
                        <div class="form-hint" id="kbSynonymsHint"><?= $kbT('knowledgebase.admin.field_synonyms_hint') ?></div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><h3 class="card-title"><?= $kbT('knowledgebase.admin.edit_sections') ?></h3></div>
                <div class="card-body">
                    <p class="text-secondary"><?= $kbT('knowledgebase.admin.edit_sections_hint') ?></p>
                    <?php foreach ((array) ($kbData['sections'] ?? []) as $kbSectionKey => $kbSection): ?>
                        <?php
                        $kbColumn = (string) $kbSection['column'];
                        $kbLabelKey = 'knowledgebase.section.' . $kbSectionKey;
                        $kbLabel = (string) __($kbLabelKey);
                        $kbLabel = $kbLabel !== $kbLabelKey ? $kbLabel : (string) $kbSection['label'];
                        $kbHelp = (string) __($kbLabelKey . '_help');
                        $kbHelp = $kbHelp !== $kbLabelKey . '_help' ? $kbHelp : (string) $kbSection['help'];
                        ?>
                        <section class="admin-knowledgebase-section mb-4" id="kb-section-<?= $kbE($kbSectionKey) ?>" aria-labelledby="kb-section-<?= $kbE($kbSectionKey) ?>-title">
                            <h4 class="mb-1" id="kb-section-<?= $kbE($kbSectionKey) ?>-title"><?= $kbE($kbLabel) ?></h4>
                            <p class="small text-secondary mb-2"><?= $kbE($kbHelp) ?></p>
                            <?= $kbEditor($kbColumn, $kbV($kbColumn), $kbLabel, (int) ($kbSection['height'] ?? 200)) ?>
                        </section>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card mb-4 admin-knowledgebase-sticky">
                <div class="card-header"><h3 class="card-title"><?= $kbT('knowledgebase.admin.edit_linking') ?></h3></div>
                <div class="card-body">
                    <input type="hidden" name="is_active" value="0">
                    <label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1"<?= $kbChecked('is_active', '1') ?>><span class="form-check-label"><?= $kbT('knowledgebase.admin.field_active') ?></span></label>
                    <input type="hidden" name="is_whole_word" value="0">
                    <label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_whole_word" value="1"<?= $kbChecked('is_whole_word', '1') ?>><span class="form-check-label"><?= $kbT('knowledgebase.admin.field_whole_word') ?></span></label>
                    <input type="hidden" name="is_case_sensitive" value="0">
                    <label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_case_sensitive" value="1"<?= $kbChecked('is_case_sensitive', '0') ?>><span class="form-check-label"><?= $kbT('knowledgebase.admin.field_case_sensitive') ?></span></label>
                    <div class="row g-3 mt-1">
                        <div class="col-6">
                            <label class="form-label" for="kbPriority"><?= $kbT('knowledgebase.admin.col_priority') ?></label>
                            <input type="number" class="form-control" id="kbPriority" name="priority" min="1" max="9999" step="1" value="<?= (int) $kbV('priority', '100') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="kbMaxLinks"><?= $kbT('knowledgebase.admin.field_max_links') ?></label>
                            <input type="number" class="form-control" id="kbMaxLinks" name="max_links_per_page" min="1" max="20" step="1" value="<?= (int) $kbV('max_links_per_page', '1') ?>">
                        </div>
                    </div>
                    <div class="form-hint mt-2"><?= $kbT('knowledgebase.admin.field_priority_hint') ?></div>
                </div>
                <div class="card-footer d-flex gap-2">
                    <a class="btn" href="<?= $kbE(KnowledgebaseModule::url(['section' => 'entries'])) ?>"><?= $kbT('knowledgebase.admin.cancel') ?></a>
                    <button type="submit" class="btn btn-primary ms-auto"><i class="ti ti-device-floppy me-1" aria-hidden="true"></i><?= $kbT('knowledgebase.admin.save') ?></button>
                </div>
            </div>
            <?php if (!$kbIsNew): ?>
                <div class="card">
                    <div class="card-body small text-secondary">
                        <div><?= $kbT('knowledgebase.admin.created_at') ?>: <?= $kbE($kbDate($kbEntry['created_at'] ?? '')) ?></div>
                        <div><?= $kbT('knowledgebase.admin.col_updated') ?>: <?= $kbE($kbDate($kbEntry['updated_at'] ?? '')) ?></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</form>
