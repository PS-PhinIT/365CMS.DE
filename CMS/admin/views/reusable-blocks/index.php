<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * EditorJS Block Vorlagen – Rahmen (Kopfzeile, Meldungen) für Liste und Editor.
 *
 * @var array<string, mixed> $data
 * @var string $csrfToken
 * @var array|null $alert
 */

$rbData = is_array($data ?? null) ? $data : [];
$rbView = ($rbData['view'] ?? '') === 'edit' ? 'edit' : 'list';
$rbE = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$rbDate = static function (mixed $value): string {
    $ts = is_string($value) && $value !== '' ? strtotime($value) : false;

    return $ts !== false ? date('d.m.Y H:i', $ts) : '–';
};
$rbBlock = (array) ($rbData['block'] ?? []);
?>

<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">Inhalte</div>
                <h2 class="page-title">
                    <?php if ($rbView === 'edit'): ?>
                        <?= !empty($rbData['is_new']) ? 'Neue Vorlage' : $rbE((string) ($rbBlock['title'] ?? '')) ?>
                    <?php else: ?>
                        EditorJS Block Vorlagen
                    <?php endif; ?>
                </h2>
            </div>
            <div class="col-auto ms-auto d-flex flex-wrap gap-2">
                <?php if ($rbView === 'edit'): ?>
                    <a class="btn" href="<?= $rbE(ReusableBlocksModule::url()) ?>"><i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Zur Liste</a>
                <?php endif; ?>
                <?php if ($rbView !== 'edit' || empty($rbData['is_new'])): ?>
                    <a class="btn btn-primary" href="<?= $rbE(ReusableBlocksModule::url(['action' => 'edit'])) ?>"><i class="ti ti-plus me-1" aria-hidden="true"></i>Neue Vorlage</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page admin-reusable-blocks-page">
    <div class="container-xl admin-redesign-shell">
        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <?php require __DIR__ . '/' . $rbView . '.php'; ?>
    </div>
</div>
