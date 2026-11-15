<?php
/**
 * Freigabe-Bereich im Editor (4.1.0, Bereich 3a): Status der Prüfung, Verlauf, Kommentar, Freigeben/Zurückgeben,
 * Vorschau-Links. Die Knöpfe gehören über das `form`-Attribut zu einem eigenen Formular außerhalb des
 * Editor-Formulars (content-workflow-form.php) – „Zur Prüfung einreichen“ speichert dagegen über das Editor-Formular,
 * damit ungespeicherte Änderungen nicht verloren gehen.
 *
 * Erwartet $workflowPanel = [type, id, status, author_id, is_public (bereits öffentlich sichtbar), form_id,
 *   editor_form_id, variant ('panel'|'section')].
 */

declare(strict_types=1);

use CMS\Services\Content\ContentStatus;
use CMS\Services\Content\ContentWorkflow;

if (!defined('ABSPATH')) {
    exit;
}

$wfType = (string) ($workflowPanel['type'] ?? '');
$wfId = (int) ($workflowPanel['id'] ?? 0);
if ($wfId <= 0 || !ContentWorkflow::isSupportedType($wfType)) {
    return;
}
$wfStatus = (string) ($workflowPanel['status'] ?? '');
$wfFormId = (string) ($workflowPanel['form_id'] ?? '');
$wfEditorFormId = (string) ($workflowPanel['editor_form_id'] ?? '');
$wfVariant = (string) ($workflowPanel['variant'] ?? 'panel');
$wfService = ContentWorkflow::instance();
$wfUserId = \CMS\Services\ContentEditPermission::currentUserId();
$wfCanReview = $wfStatus === ContentStatus::PENDING && $wfService->canReview($wfType, $wfId, $wfUserId);
$wfCanPublish = $wfService->canPublish($wfType, $wfUserId, (int) ($workflowPanel['author_id'] ?? 0));
$wfHistory = $wfService->history($wfType, $wfId, 20);
$wfPreviewLinks = $wfStatus !== ContentStatus::TRASH ? $wfService->activePreviewLinks($wfType, $wfId) : [];
$wfSecondPerson = $wfService->isSecondPersonRequired();
$wfLabel = $wfType === ContentWorkflow::TYPE_PAGE ? 'Seite' : 'Beitrag';
$wfEsc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$wfDate = static function (string $value): string {
    $timestamp = strtotime($value);

    return $timestamp !== false ? date('d.m.Y H:i', $timestamp) : $value;
};
$wfCommentId = 'workflowComment' . ucfirst($wfType);
?>
<?php if ($wfVariant === 'section'): ?>
<section class="border-top pt-3 mt-3 cms-workflow-panel" id="contentWorkflowPanel" aria-labelledby="contentWorkflowPanelTitle">
    <h4 class="h4 mb-2" id="contentWorkflowPanelTitle"><i class="ti ti-users-group me-1" aria-hidden="true"></i>Freigabe</h4>
    <div>
<?php else: ?>
<details class="cms-editor-panel cms-workflow-panel" id="contentWorkflowPanel"<?php echo $wfStatus === ContentStatus::PENDING ? ' open' : ''; ?>>
    <summary class="cms-editor-panel__summary">
        <h3 class="cms-editor-panel__title"><i class="ti ti-users-group" aria-hidden="true"></i>Freigabe</h3>
        <span class="cms-editor-panel__chevron" aria-hidden="true"></span>
    </summary>
    <div class="cms-editor-panel__body">
<?php endif; ?>
        <p class="mb-2">
            <span class="badge <?php echo $wfEsc(ContentStatus::badgeClass($wfStatus)); ?>"><?php echo $wfEsc(ContentStatus::label($wfStatus)); ?></span>
            <?php if ($wfStatus === ContentStatus::PENDING && !$wfCanReview): ?>
                <span class="form-hint d-block mt-1">Wartet auf die Freigabe durch <?php echo $wfSecondPerson ? 'eine zweite Person' : 'ein Konto mit Veröffentlichungsrecht'; ?>.</span>
            <?php elseif (!$wfCanPublish && $wfStatus === ContentStatus::DRAFT): ?>
                <span class="form-hint d-block mt-1"><?php echo $wfSecondPerson ? 'Eigene Inhalte gibt eine zweite Person frei.' : 'Für die Veröffentlichung ist eine Freigabe nötig.'; ?></span>
            <?php endif; ?>
        </p>

        <div class="mb-2">
            <label class="form-label" for="<?php echo $wfEsc($wfCommentId); ?>">Kommentar</label>
            <textarea class="form-control" id="<?php echo $wfEsc($wfCommentId); ?>" name="workflow_comment" form="<?php echo $wfEsc($wfFormId); ?>" rows="2" maxlength="<?php echo ContentWorkflow::COMMENT_MAX; ?>" aria-describedby="<?php echo $wfEsc($wfCommentId); ?>Hint"></textarea>
            <div class="form-hint" id="<?php echo $wfEsc($wfCommentId); ?>Hint">Pflicht beim Zurückgeben; erscheint im Verlauf und in der Benachrichtigung.</div>
        </div>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php if ($wfCanReview): ?>
                <button type="submit" class="btn btn-sm btn-success" form="<?php echo $wfEsc($wfFormId); ?>" name="workflow_action" value="approve"><i class="ti ti-check me-1" aria-hidden="true"></i>Freigeben</button>
                <button type="submit" class="btn btn-sm btn-outline-warning" form="<?php echo $wfEsc($wfFormId); ?>" name="workflow_action" value="return"><i class="ti ti-arrow-back-up me-1" aria-hidden="true"></i>Zurückgeben</button>
            <?php elseif ($wfStatus === ContentStatus::DRAFT && $wfEditorFormId !== ''): ?>
                <button type="submit" class="btn btn-sm btn-outline-primary" form="<?php echo $wfEsc($wfEditorFormId); ?>" name="publish_intent" value="submit"><i class="ti ti-send me-1" aria-hidden="true"></i>Zur Prüfung einreichen</button>
            <?php endif; ?>
            <button type="submit" class="btn btn-sm btn-outline-secondary" form="<?php echo $wfEsc($wfFormId); ?>" name="workflow_action" value="comment"><i class="ti ti-message me-1" aria-hidden="true"></i>Kommentar senden</button>
        </div>

        <?php if ($wfStatus !== ContentStatus::TRASH && empty($workflowPanel['is_public'])): ?>
            <h4 class="h5 mb-1">Vorschau-Link</h4>
            <p class="form-hint mb-2">Für Personen ohne Login, <?php echo (int) (ContentWorkflow::PREVIEW_TTL_SECONDS / 86400); ?> Tage gültig, nicht in Suchmaschinen. Der Link wird nur direkt nach dem Erstellen angezeigt.</p>
            <div class="d-flex flex-wrap gap-2 mb-2">
                <button type="submit" class="btn btn-sm btn-outline-secondary" form="<?php echo $wfEsc($wfFormId); ?>" name="workflow_action" value="preview_link"><i class="ti ti-link me-1" aria-hidden="true"></i>Vorschau-Link erstellen</button>
                <?php if ($wfPreviewLinks !== []): ?>
                    <button type="submit" class="btn btn-sm btn-outline-danger" form="<?php echo $wfEsc($wfFormId); ?>" name="workflow_action" value="preview_revoke"><i class="ti ti-link-off me-1" aria-hidden="true"></i>Alle widerrufen (<?php echo count($wfPreviewLinks); ?>)</button>
                <?php endif; ?>
            </div>
            <?php if ($wfPreviewLinks !== []): ?>
                <ul class="list-unstyled small mb-3">
                    <?php foreach ($wfPreviewLinks as $wfLink): ?>
                        <li>Gültig bis <?php echo $wfEsc($wfDate($wfLink['expires_at'])); ?><?php echo $wfLink['created_by_name'] !== '' ? ' · von ' . $wfEsc($wfLink['created_by_name']) : ''; ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>

        <h4 class="h5 mb-1">Verlauf</h4>
        <?php if ($wfHistory === []): ?>
            <p class="form-hint mb-0">Noch keine Einträge für diese<?php echo $wfType === ContentWorkflow::TYPE_PAGE ? ' Seite' : 'n Beitrag'; ?>.</p>
        <?php else: ?>
            <ol class="list-unstyled mb-0 cms-workflow-history" aria-label="Freigabe-Verlauf: <?php echo $wfEsc($wfLabel); ?>">
                <?php foreach ($wfHistory as $wfEvent): ?>
                    <li class="mb-2">
                        <strong><?php echo $wfEsc(ContentWorkflow::actionLabel($wfEvent['action'])); ?></strong>
                        <span class="text-secondary small d-block"><?php echo $wfEsc($wfDate($wfEvent['created_at'])); ?><?php echo $wfEvent['user_name'] !== '' ? ' · ' . $wfEsc($wfEvent['user_name']) : ''; ?></span>
                        <?php if ($wfEvent['comment'] !== '' && !in_array($wfEvent['action'], [ContentWorkflow::ACTION_PREVIEW_CREATED, ContentWorkflow::ACTION_PREVIEW_REVOKED], true)): ?>
                            <span class="d-block small"><?php echo nl2br($wfEsc($wfEvent['comment'])); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
<?php if ($wfVariant === 'section'): ?>
    </div>
</section>
<?php else: ?>
    </div>
</details>
<?php endif; ?>
