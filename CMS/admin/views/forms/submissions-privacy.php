<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formulareingänge – DSGVO-Exportdateien (Auskunft nach Art. 15/20, erzeugt über Admin → DSGVO-Anfragen).
 *
 * @var array<string, mixed> $fsData
 * @var string $csrfToken
 * @var bool $fsCanManage
 * @var callable $fsE
 * @var callable $fsT
 * @var callable $fsDate
 */

$fsExports = is_array($fsData['exports'] ?? null) ? $fsData['exports'] : [];
?>

<div class="card">
    <div class="card-body">
        <p class="text-secondary mb-0"><?= $fsT('forms.privacy.intro', (int) ($fsData['keep_days'] ?? 30)) ?></p>
    </div>
    <?php if ($fsExports === []): ?>
        <div class="card-body border-top text-secondary"><?= $fsT('forms.privacy.empty') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th><?= $fsT('forms.privacy.col_file') ?></th>
                        <th><?= $fsT('forms.privacy.col_created') ?></th>
                        <th class="text-end"><?= $fsT('forms.privacy.col_size') ?></th>
                        <th class="w-1"><span class="visually-hidden"><?= $fsT('forms.admin.col_actions') ?></span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($fsExports as $fsExport): ?>
                    <tr>
                        <td><a href="<?= $fsE('/admin/form-submissions?privacy_export=' . rawurlencode((string) $fsExport['name'])) ?>"><code><?= $fsE((string) $fsExport['name']) ?></code></a></td>
                        <td class="text-nowrap"><?= $fsE($fsDate((string) $fsExport['created_at'])) ?></td>
                        <td class="text-end text-nowrap"><?= $fsE(number_format(max(1, (int) ceil(((int) $fsExport['size']) / 1024)), 0, ',', '.')) ?> KB</td>
                        <td class="text-nowrap">
                            <?php if ($fsCanManage): ?>
                                <form method="post" action="/admin/form-submissions?tab=privacy" class="d-inline"
                                      data-confirm-title="<?= $fsT('forms.privacy.delete_title') ?>" data-confirm-message="<?= $fsT('forms.privacy.delete_confirm') ?>"
                                      data-confirm-text="<?= $fsT('forms.member.delete') ?>" data-confirm-class="btn-danger">
                                    <input type="hidden" name="csrf_token" value="<?= $fsE($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete_export">
                                    <input type="hidden" name="name" value="<?= $fsE((string) $fsExport['name']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><?= $fsT('forms.member.delete') ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
