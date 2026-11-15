<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * EditorJS Block Vorlagen – Vorlage anlegen/bearbeiten: Titel, Inhalt DE und EN (Block-Editor), „Verwendet in“,
 * Verlauf, Duplizieren und Löschen. Die Formulare für Verlauf, Duplizieren und Löschen stehen außerhalb des
 * Speichern-Formulars (keine verschachtelten Formulare).
 *
 * @var array<string, mixed> $rbData
 * @var array<string, mixed> $rbBlock
 * @var callable $rbE
 * @var callable $rbDate
 * @var string $csrfToken
 */

use CMS\Services\EditorJs\EditorJsContentNormalizer;
use CMS\Services\EditorJsService;

$rbIsNew = !empty($rbData['is_new']);
$rbId = (int) ($rbBlock['id'] ?? 0);
$rbUsages = (array) ($rbData['usages'] ?? []);
$rbRevisions = (array) ($rbData['revisions'] ?? []);
$rbEditor = static function (string $name, string $value, string $label): string {
    $content = trim($value);
    try {
        if ($content !== '') {
            $content = (string) json_encode(EditorJsContentNormalizer::normalize($content), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return EditorJsService::getInstance()->render($name, $content, [
            'height' => 320,
            'context' => 'cms-reusable-block',
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
<div class="row g-4">
    <div class="col-xl-8">
        <form method="post" action="<?= $rbE(ReusableBlocksModule::ROUTE) ?>" id="reusableBlockForm">
            <input type="hidden" name="csrf_token" value="<?= $rbE($csrfToken) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="block_id" value="<?= $rbId ?>">

            <div class="card mb-4">
                <div class="card-body">
                    <label class="form-label required" for="rbTitle">Titel</label>
                    <input type="text" class="form-control" id="rbTitle" name="title" maxlength="150" required value="<?= $rbE((string) ($rbBlock['title'] ?? '')) ?>" aria-describedby="rbTitleHint">
                    <div class="form-hint" id="rbTitleHint">Nur intern sichtbar – unter diesem Namen wählst du die Vorlage im Editor.js-Block „Vorlagen“ aus.</div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><h3 class="card-title">Inhalt · Deutsch</h3></div>
                <div class="card-body">
                    <?= $rbEditor('content', (string) ($rbBlock['content'] ?? ''), 'Inhalt der Vorlage (Deutsch)') ?>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><h3 class="card-title">Inhalt · English</h3></div>
                <div class="card-body">
                    <p class="form-hint mt-0">Optional. Leer gelassen, erscheint auf englischen Seiten die deutsche Fassung.</p>
                    <?= $rbEditor('content_en', (string) ($rbBlock['content_en'] ?? ''), 'Inhalt der Vorlage (English)') ?>
                </div>
            </div>

            <div class="d-flex gap-2 mb-4">
                <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1" aria-hidden="true"></i><?= $rbIsNew ? 'Vorlage anlegen' : 'Speichern' ?></button>
                <a class="btn" href="<?= $rbE(ReusableBlocksModule::url()) ?>">Abbrechen</a>
            </div>
        </form>
    </div>

    <div class="col-xl-4">
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">So wird sie eingebunden</h3></div>
            <div class="card-body">
                <p class="mb-0">Im Beitrags- oder Seiteneditor den Block <strong>„Vorlagen“</strong> einfügen und diese Vorlage wählen. Gespeichert wird nur der Verweis; Änderungen hier erscheinen sofort überall.</p>
            </div>
        </div>

        <?php if (!$rbIsNew): ?>
            <div class="card mb-4">
                <div class="card-header"><h3 class="card-title">Verwendet in</h3></div>
                <?php if ($rbUsages === []): ?>
                    <div class="card-body"><p class="text-secondary mb-0">Noch nirgends eingebunden.</p></div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($rbUsages as $rbUsage): ?>
                            <li class="list-group-item">
                                <span class="text-secondary small d-block"><?= $rbE($rbUsage['label']) ?></span>
                                <?php if ($rbUsage['edit_url'] !== ''): ?>
                                    <a href="<?= $rbE($rbUsage['edit_url']) ?>"><?= $rbE($rbUsage['title']) ?></a>
                                <?php else: ?>
                                    <?= $rbE($rbUsage['title']) ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="card mb-4">
                <div class="card-header"><h3 class="card-title">Verlauf</h3></div>
                <?php if ($rbRevisions === []): ?>
                    <div class="card-body"><p class="text-secondary mb-0">Noch keine früheren Fassungen. Bei jedem Speichern wird der vorherige Stand gesichert (die letzten 20).</p></div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($rbRevisions as $rbRevision): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                                <span>
                                    <?= $rbE($rbDate($rbRevision['created_at'])) ?>
                                    <?php if ($rbRevision['created_by_name'] !== ''): ?><span class="text-secondary small d-block"><?= $rbE($rbRevision['created_by_name']) ?></span><?php endif; ?>
                                </span>
                                <form method="post" action="<?= $rbE(ReusableBlocksModule::ROUTE) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $rbE($csrfToken) ?>">
                                    <input type="hidden" name="action" value="restore_revision">
                                    <input type="hidden" name="block_id" value="<?= $rbId ?>">
                                    <input type="hidden" name="revision_id" value="<?= (int) $rbRevision['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" aria-label="Fassung vom <?= $rbE($rbDate($rbRevision['created_at'])) ?> wiederherstellen">Wiederherstellen</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="card mb-4">
                <div class="card-header"><h3 class="card-title">Aktionen</h3></div>
                <div class="card-body d-flex flex-column gap-3">
                    <form method="post" action="<?= $rbE(ReusableBlocksModule::ROUTE) ?>">
                        <input type="hidden" name="csrf_token" value="<?= $rbE($csrfToken) ?>">
                        <input type="hidden" name="action" value="duplicate">
                        <input type="hidden" name="block_id" value="<?= $rbId ?>">
                        <button type="submit" class="btn btn-outline-secondary w-100"><i class="ti ti-copy me-1" aria-hidden="true"></i>Duplizieren</button>
                    </form>
                    <form method="post" action="<?= $rbE(ReusableBlocksModule::ROUTE) ?>">
                        <input type="hidden" name="csrf_token" value="<?= $rbE($csrfToken) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="block_id" value="<?= $rbId ?>">
                        <label class="form-check mb-2">
                            <input type="checkbox" class="form-check-input" name="confirmed" value="1" required>
                            <span class="form-check-label"><?= $rbUsages !== []
                                ? 'Mir ist klar, dass die Vorlage an ' . count($rbUsages) . ' Stelle(n) verwendet wird und dort danach nichts mehr erscheint.'
                                : 'Vorlage samt Verlauf endgültig löschen.' ?></span>
                        </label>
                        <button type="submit" class="btn btn-outline-danger w-100"><i class="ti ti-trash me-1" aria-hidden="true"></i>Löschen</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
