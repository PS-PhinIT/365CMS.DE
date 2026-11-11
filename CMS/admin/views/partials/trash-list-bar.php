<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Papierkorb-Leiste einer Inhaltsliste: Anzahl, Aufbewahrungshinweis und „Papierkorb leeren“.
 *
 * Erwartet: $trashBar = [
 *     'count' => int, 'retention_days' => int, 'form_action' => string, 'csrf_token' => string,
 *     'fields' => array<string,string> (versteckte Felder, z. B. action=empty_trash),
 *     'can_empty' => bool (optional, Standard true),
 *     'central_link' => bool (optional; Link nur, wenn die zentrale Seite ausgeliefert ist),
 * ]
 */

use CMS\Services\Trash\TrashSupport;

$trashBarCount = max(0, (int) ($trashBar['count'] ?? 0));
$trashBarRetention = max(0, (int) ($trashBar['retention_days'] ?? 0));
$trashBarFields = is_array($trashBar['fields'] ?? null) ? $trashBar['fields'] : [];
$trashBarEsc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<div class="card-body py-2 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2 trash-list-bar">
    <div class="text-secondary small">
        <?php echo $trashBarEsc(TrashSupport::t('trash.list.bar.count', $trashBarCount)); ?>
        <?php echo $trashBarEsc($trashBarRetention > 0
            ? TrashSupport::t('trash.list.bar.retention', $trashBarRetention)
            : TrashSupport::t('trash.list.bar.retention_off')); ?>
        <?php $trashBarCentralUrl = !empty($trashBar['central_link']) ? TrashSupport::centralPageUrl() : ''; ?>
        <?php if ($trashBarCentralUrl !== ''): ?>
            <a href="<?php echo $trashBarEsc($trashBarCentralUrl); ?>" class="ms-1"><?php echo $trashBarEsc(TrashSupport::t('trash.list.bar.central_link')); ?></a>
        <?php endif; ?>
    </div>
    <?php if ($trashBarCount > 0 && ($trashBar['can_empty'] ?? true)): ?>
        <form method="post"
              action="<?php echo $trashBarEsc((string) ($trashBar['form_action'] ?? '')); ?>"
              class="m-0"
              data-confirm-title="<?php echo $trashBarEsc(TrashSupport::t('trash.action.empty_confirm_title')); ?>"
              data-confirm-message="<?php echo $trashBarEsc(TrashSupport::t('trash.action.empty_confirm_message')); ?>"
              data-confirm-text="<?php echo $trashBarEsc(TrashSupport::t('trash.action.empty')); ?>"
              data-confirm-class="btn-danger"
              data-confirm-status-class="bg-danger">
            <input type="hidden" name="csrf_token" value="<?php echo $trashBarEsc((string) ($trashBar['csrf_token'] ?? '')); ?>">
            <?php foreach ($trashBarFields as $trashBarName => $trashBarValue): ?>
                <input type="hidden" name="<?php echo $trashBarEsc((string) $trashBarName); ?>" value="<?php echo $trashBarEsc((string) $trashBarValue); ?>">
            <?php endforeach; ?>
            <button type="submit" class="btn btn-outline-danger btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon me-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7l16 0"/><path d="M10 11l0 6"/><path d="M14 11l0 6"/><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12"/><path d="M9 7l1 -3h4l1 3"/></svg>
                <?php echo $trashBarEsc(TrashSupport::t('trash.action.empty')); ?>
            </button>
        </form>
    <?php endif; ?>
</div>
