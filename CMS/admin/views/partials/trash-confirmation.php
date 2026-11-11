<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ausstehende Bestätigung „Endgültig löschen“ für Einträge mit Warnungen (z. B. Medien in Verwendung,
 * zugeordnete Rechtstexte). Zeigt Einträge und Warnungen und sendet erst nach ausdrücklicher Bestätigung
 * (`cmsConfirm`-Dialog über `data-confirm-*`) erneut mit `confirmed=1`.
 *
 * Erwartet: $trashConfirmation = ['type' => string, 'items' => array<int,array{title:string,warnings:list<string>}>]
 *           $trashConfirmationForm = [
 *               'action' => string (Formular-URL), 'csrf_token' => string,
 *               'fields' => array<string,string> (versteckte Felder), 'id_field' => string (z. B. "ids[]"),
 *               'dismiss_url' => string,
 *           ]
 */

use CMS\Services\Trash\TrashSupport;

$trashConfirmationItems = is_array($trashConfirmation['items'] ?? null) ? $trashConfirmation['items'] : [];
if ($trashConfirmationItems === []) {
    return;
}

$trashConfirmationEsc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$trashConfirmationFields = is_array($trashConfirmationForm['fields'] ?? null) ? $trashConfirmationForm['fields'] : [];
$trashConfirmationIdField = (string) ($trashConfirmationForm['id_field'] ?? 'ids[]');
$trashConfirmationCount = count($trashConfirmationItems);
?>
<div class="card border-warning mb-3 trash-confirmation" role="alert">
    <div class="card-header bg-warning-lt">
        <h3 class="card-title">
            <svg xmlns="http://www.w3.org/2000/svg" class="icon me-1 text-warning" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4"/><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z"/><path d="M12 16h.01"/></svg>
            <?php echo $trashConfirmationEsc(TrashSupport::t('trash.confirmation.title')); ?>
        </h3>
    </div>
    <div class="card-body">
        <p class="text-secondary mb-2"><?php echo $trashConfirmationEsc(TrashSupport::t('trash.confirmation.intro')); ?></p>
        <ul class="mb-3">
            <?php foreach ($trashConfirmationItems as $trashConfirmationId => $trashConfirmationItem): ?>
                <li>
                    <strong><?php echo $trashConfirmationEsc((string) ($trashConfirmationItem['title'] ?? ('#' . (int) $trashConfirmationId))); ?></strong>
                    <?php $trashConfirmationWarnings = is_array($trashConfirmationItem['warnings'] ?? null) ? $trashConfirmationItem['warnings'] : []; ?>
                    <?php if ($trashConfirmationWarnings !== []): ?>
                        <ul class="small text-secondary mb-1">
                            <?php foreach ($trashConfirmationWarnings as $trashConfirmationWarning): ?>
                                <li><?php echo $trashConfirmationEsc((string) $trashConfirmationWarning); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <form method="post"
              action="<?php echo $trashConfirmationEsc((string) ($trashConfirmationForm['action'] ?? '')); ?>"
              class="d-flex flex-wrap align-items-center gap-2 m-0"
              data-confirm-title="<?php echo $trashConfirmationEsc(TrashSupport::t('trash.confirmation.modal_title')); ?>"
              data-confirm-message="<?php echo $trashConfirmationEsc(TrashSupport::t('trash.confirmation.modal_message', $trashConfirmationCount)); ?>"
              data-confirm-text="<?php echo $trashConfirmationEsc(TrashSupport::t('trash.confirmation.submit')); ?>"
              data-confirm-class="btn-danger"
              data-confirm-status-class="bg-danger">
            <input type="hidden" name="csrf_token" value="<?php echo $trashConfirmationEsc((string) ($trashConfirmationForm['csrf_token'] ?? '')); ?>">
            <input type="hidden" name="confirmed" value="1">
            <?php foreach ($trashConfirmationFields as $trashConfirmationName => $trashConfirmationValue): ?>
                <input type="hidden" name="<?php echo $trashConfirmationEsc((string) $trashConfirmationName); ?>" value="<?php echo $trashConfirmationEsc((string) $trashConfirmationValue); ?>">
            <?php endforeach; ?>
            <?php foreach (array_keys($trashConfirmationItems) as $trashConfirmationId): ?>
                <input type="hidden" name="<?php echo $trashConfirmationEsc($trashConfirmationIdField); ?>" value="<?php echo (int) $trashConfirmationId; ?>">
            <?php endforeach; ?>
            <button type="submit" class="btn btn-danger btn-sm"><?php echo $trashConfirmationEsc(TrashSupport::t('trash.confirmation.submit')); ?></button>
            <a href="<?php echo $trashConfirmationEsc((string) ($trashConfirmationForm['dismiss_url'] ?? '')); ?>" class="btn btn-link btn-sm"><?php echo $trashConfirmationEsc(TrashSupport::t('trash.confirmation.dismiss')); ?></a>
        </form>
    </div>
</div>
