<?php
/**
 * Eigenes Formular der Freigabe-Knöpfe (content-workflow-panel.php) – steht außerhalb des Editor-Formulars,
 * weil HTML keine verschachtelten Formulare erlaubt.
 *
 * Erwartet $workflowPanel (form_id, id) sowie $workflowFormAction und $workflowFormCsrfToken.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if ((int) ($workflowPanel['id'] ?? 0) <= 0) {
    return;
}
?>
<form method="post" action="<?php echo htmlspecialchars((string) $workflowFormAction, ENT_QUOTES, 'UTF-8'); ?>" id="<?php echo htmlspecialchars((string) ($workflowPanel['form_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" hidden>
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $workflowFormCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="_action" value="workflow">
    <input type="hidden" name="id" value="<?php echo (int) $workflowPanel['id']; ?>">
    <input type="hidden" name="editor_locale" value="<?php echo htmlspecialchars((string) ($workflowPanel['editor_locale'] ?? 'de'), ENT_QUOTES, 'UTF-8'); ?>">
</form>
