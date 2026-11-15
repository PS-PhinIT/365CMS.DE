<?php
/**
 * Editor-Felder „Gültigkeit“ (4.1.0, Bereich 3c): Ablaufdatum und Wiedervorlage für Beiträge und Seiten.
 * Gespeichert über FeatureHooks\ContentExpiryHooks nach dem Speichern; geprüft in PostsModule/PagesModule.
 *
 * Erwartet $expiryFields = ['prefix' => 'post'|'page', 'expires_at' => ?string, 'review_due_at' => ?string, 'is_new' => bool].
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$exPrefix = preg_replace('/[^a-z]/', '', (string) ($expiryFields['prefix'] ?? 'post')) ?: 'post';
$exSplit = static function (?string $value): array {
    $ts = $value !== null && $value !== '' && !str_starts_with($value, '0000') ? strtotime($value) : false;

    return $ts !== false ? [date('Y-m-d', $ts), date('H:i', $ts)] : ['', ''];
};
[$exDate, $exTime] = $exSplit(isset($expiryFields['expires_at']) ? (string) $expiryFields['expires_at'] : null);
[$exReviewDate] = $exSplit(isset($expiryFields['review_due_at']) ? (string) $expiryFields['review_due_at'] : null);
$exReviewDue = $exReviewDate !== '' && strcmp((string) $expiryFields['review_due_at'], date('Y-m-d H:i:s')) <= 0;
$exE = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<fieldset class="cms-editor-panel__group mb-3">
    <legend class="form-label">Gültigkeit</legend>
    <label class="form-label small mb-1" for="<?= $exE($exPrefix) ?>ExpiresDate">Ablaufdatum</label>
    <div class="row g-2 mb-1">
        <div class="col-7">
            <input type="date" class="form-control" id="<?= $exE($exPrefix) ?>ExpiresDate" name="expires_date" value="<?= $exE($exDate) ?>" aria-describedby="<?= $exE($exPrefix) ?>ExpiresHint">
        </div>
        <div class="col-5">
            <input type="time" class="form-control" id="<?= $exE($exPrefix) ?>ExpiresTime" name="expires_time" value="<?= $exE($exTime) ?>" step="60" aria-label="Ablaufuhrzeit" aria-describedby="<?= $exE($exPrefix) ?>ExpiresHint">
        </div>
    </div>
    <div class="form-hint mb-2" id="<?= $exE($exPrefix) ?>ExpiresHint">Optional. Danach wird der Inhalt automatisch auf Entwurf gesetzt (oder nur markiert, je nach Einstellung).</div>
    <label class="form-label small mb-1" for="<?= $exE($exPrefix) ?>ReviewDate">Wiedervorlage</label>
    <input type="date" class="form-control mb-1" id="<?= $exE($exPrefix) ?>ReviewDate" name="review_due_date" value="<?= $exE($exReviewDate) ?>" aria-describedby="<?= $exE($exPrefix) ?>ReviewHint">
    <div class="form-hint" id="<?= $exE($exPrefix) ?>ReviewHint">Optional. Am Stichtag erhält der Autor einen Hinweis; der Inhalt bleibt unverändert.</div>
    <?php if ($exReviewDate !== ''): ?>
        <label class="form-check mt-2 mb-0">
            <input type="checkbox" class="form-check-input" name="review_done" value="1">
            <span class="form-check-label">Als geprüft markieren<?= $exReviewDue ? ' (fällig)' : '' ?> – setzt die nächste Wiedervorlage</span>
        </label>
    <?php endif; ?>
</fieldset>
