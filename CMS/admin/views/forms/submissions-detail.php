<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formulareingänge – Detailansicht eines Eingangs.
 *
 * @var array<string, mixed> $fsData
 * @var string $csrfToken
 * @var bool $fsCanManage
 * @var callable $fsE
 * @var callable $fsT
 * @var callable $fsDate
 * @var array<string, string> $fsStatusBadge
 */

$fsItem = is_array($fsData['submission'] ?? null) ? $fsData['submission'] : [];
$fsId = (int) ($fsItem['id'] ?? 0);
$fsStatus = (string) ($fsItem['status'] ?? 'new');
$fsIsTrash = $fsStatus === 'trash';
$fsMeta = is_array($fsItem['meta'] ?? null) ? $fsItem['meta'] : [];
$fsConsent = is_array($fsMeta['consent'] ?? null) ? $fsMeta['consent'] : [];
$fsFiles = is_array($fsItem['files'] ?? null) ? $fsItem['files'] : [];
$fsUser = is_array($fsData['user'] ?? null) ? $fsData['user'] : null;
$fsEmail = (string) ($fsItem['sender_email'] ?? '');
$fsSubject = (string) ($fsItem['subject'] ?? '');
$fsBytes = static function (int $bytes): string {
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : number_format(max(1, (int) ceil($bytes / 1024)), 0, ',', '.') . ' KB';
};
$fsAction = static function (string $action, string $label, string $class, array $extra = [], array $confirm = []) use ($fsE, $csrfToken, $fsId): string {
    $attributes = '';
    foreach ($confirm as $name => $value) {
        $attributes .= ' data-confirm-' . $name . '="' . $fsE($value) . '"';
    }
    $fields = '';
    foreach ($extra as $name => $value) {
        $fields .= '<input type="hidden" name="' . $fsE($name) . '" value="' . $fsE($value) . '">';
    }

    return '<form method="post" action="/admin/form-submissions" class="d-inline"' . $attributes . '>'
        . '<input type="hidden" name="csrf_token" value="' . $fsE($csrfToken) . '">'
        . '<input type="hidden" name="action" value="' . $fsE($action) . '">'
        . '<input type="hidden" name="id" value="' . $fsId . '">' . $fields
        . '<button type="submit" class="btn ' . $fsE($class) . '">' . $fsE($label) . '</button></form>';
};
?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><?= $fsE($fsSubject !== '' ? $fsSubject : (string) ($fsItem['form_title'] ?? '')) ?></h3>
                <div class="card-actions"><span class="badge <?= $fsE($fsStatusBadge[$fsStatus] ?? 'bg-secondary-lt') ?>"><?= $fsT('forms.status.' . $fsStatus) ?></span></div>
            </div>
            <div class="card-body">
                <dl class="row mb-0 cms-forms-values">
                    <?php foreach (is_array($fsItem['data'] ?? null) ? $fsItem['data'] : [] as $fsEntry): ?>
                        <?php
                        $fsType = (string) ($fsEntry['type'] ?? 'text');
                        $fsText = trim((string) ($fsEntry['text'] ?? ''));
                        if ($fsType === 'file' && is_array($fsEntry['value'] ?? null)) {
                            $fsText = implode(', ', array_map('strval', $fsEntry['value']));
                        }
                        ?>
                        <dt class="col-sm-4"><?= $fsE((string) ($fsEntry['label'] ?? $fsEntry['name'] ?? '')) ?></dt>
                        <dd class="col-sm-8"><?php
                            if ($fsText === '') {
                                echo '<span class="text-secondary">–</span>';
                            } elseif ($fsType === 'email' && filter_var($fsText, FILTER_VALIDATE_EMAIL) !== false) {
                                echo '<a href="mailto:' . $fsE($fsText) . '">' . $fsE($fsText) . '</a>';
                            } elseif ($fsType === 'url' && preg_match('#^https?://#i', $fsText) === 1) {
                                echo '<a href="' . $fsE($fsText) . '" target="_blank" rel="noopener noreferrer nofollow">' . $fsE($fsText) . '</a>';
                            } else {
                                echo $fsE($fsText);
                            }
                        ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div>
            <?php if ($fsFiles !== []): ?>
                <div class="card-body border-top">
                    <h4><?= $fsT('forms.inbox.files') ?></h4>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($fsFiles as $fsFile): ?>
                            <li class="mb-1">
                                <i class="ti ti-paperclip me-1" aria-hidden="true"></i>
                                <a href="<?= $fsE('/admin/form-submissions?file=' . (int) $fsFile['id']) ?>"><?= $fsE((string) $fsFile['original_name']) ?></a>
                                <span class="text-secondary small">· <?= $fsE($fsBytes((int) $fsFile['size_bytes'])) ?> · <?= $fsE((string) $fsFile['mime_type']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="form-hint"><?= $fsT('forms.inbox.files_hint') ?></div>
                </div>
            <?php endif; ?>
            <div class="card-footer d-flex flex-wrap gap-2">
                <?php if ($fsIsTrash): ?>
                    <?= $fsAction('restore', (string) __('forms.admin.action_restore'), 'btn-primary') ?>
                    <?php if ($fsCanManage): ?>
                        <?= $fsAction('delete', (string) __('forms.admin.action_delete'), 'btn-outline-danger ms-auto', [], [
                            'title' => (string) __('forms.inbox.delete_title'),
                            'message' => (string) __('forms.inbox.delete_confirm'),
                            'text' => (string) __('forms.admin.action_delete'),
                            'class' => 'btn-danger',
                        ]) ?>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if ($fsEmail !== ''): ?>
                        <a class="btn btn-primary" href="<?= $fsE('mailto:' . $fsEmail . '?subject=' . rawurlencode('Re: ' . ($fsSubject !== '' ? $fsSubject : (string) ($fsItem['form_title'] ?? '')))) ?>">
                            <i class="ti ti-mail-forward me-1" aria-hidden="true"></i><?= $fsT('forms.inbox.reply') ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($fsStatus !== 'done'): ?>
                        <?= $fsAction('set_status', (string) __('forms.inbox.mark_done'), 'btn-success', ['status' => 'done', 'return_detail' => '1']) ?>
                    <?php else: ?>
                        <?= $fsAction('set_status', (string) __('forms.inbox.mark_read'), '', ['status' => 'read', 'return_detail' => '1']) ?>
                    <?php endif; ?>
                    <?= $fsAction('set_status', (string) __('forms.inbox.mark_new'), '', ['status' => 'new']) ?>
                    <?= $fsAction('trash', (string) __('forms.admin.action_trash'), 'btn-outline-danger ms-auto', [], [
                        'title' => (string) __('forms.inbox.trash_title'),
                        'message' => (string) __('forms.inbox.trash_confirm'),
                        'text' => (string) __('forms.admin.action_trash'),
                        'class' => 'btn-danger',
                    ]) ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?= $fsT('forms.inbox.details') ?></h3></div>
            <div class="card-body">
                <dl class="mb-0">
                    <dt><?= $fsT('forms.admin.col_form') ?></dt>
                    <dd>
                        <?php if (!empty($fsData['form_exists']) && \CMS\Auth::instance()->hasCapability('manage_forms')): ?>
                            <a href="<?= $fsE('/admin/forms?action=edit&id=' . (int) $fsItem['form_id']) ?>"><?= $fsE((string) $fsItem['form_title']) ?></a>
                        <?php else: ?>
                            <?= $fsE((string) ($fsItem['form_title'] ?? '–')) ?>
                        <?php endif; ?>
                    </dd>
                    <dt><?= $fsT('forms.inbox.col_received') ?></dt>
                    <dd><?= $fsE($fsDate((string) ($fsItem['created_at'] ?? ''))) ?></dd>
                    <?php if (!empty($fsItem['read_at'])): ?>
                        <dt><?= $fsT('forms.inbox.read_at') ?></dt>
                        <dd><?= $fsE($fsDate((string) $fsItem['read_at'])) ?></dd>
                    <?php endif; ?>
                    <?php if (!empty($fsItem['done_at'])): ?>
                        <dt><?= $fsT('forms.inbox.done_at') ?></dt>
                        <dd><?= $fsE($fsDate((string) $fsItem['done_at'])) ?></dd>
                    <?php endif; ?>
                    <?php if ($fsIsTrash && !empty($fsItem['trashed_at'])): ?>
                        <dt><?= $fsT('forms.inbox.trashed_at') ?></dt>
                        <dd><?= $fsE($fsDate((string) $fsItem['trashed_at'])) ?></dd>
                    <?php endif; ?>
                    <dt><?= $fsT('forms.inbox.language') ?></dt>
                    <dd><?= $fsT(($fsItem['locale'] ?? 'de') === 'en' ? 'forms.admin.lang_en' : 'forms.admin.lang_de') ?></dd>
                    <?php if ((string) ($fsItem['source_url'] ?? '') !== ''): ?>
                        <dt><?= $fsT('forms.inbox.source') ?></dt>
                        <dd><a href="<?= $fsE((string) $fsItem['source_url']) ?>" target="_blank" rel="noopener"><?= $fsE((string) $fsItem['source_url']) ?></a></dd>
                    <?php endif; ?>
                    <?php if ($fsUser !== null): ?>
                        <dt><?= $fsT('forms.inbox.account') ?></dt>
                        <dd><a href="<?= $fsE('/admin/users?action=edit&id=' . (int) $fsUser['id']) ?>"><?= $fsE((string) $fsUser['name']) ?></a></dd>
                    <?php endif; ?>
                    <?php if (!empty($fsItem['consent_at'])): ?>
                        <dt><?= $fsT('forms.inbox.consent') ?></dt>
                        <dd>
                            <?= $fsE($fsDate((string) $fsItem['consent_at'])) ?>
                            <?php if (trim((string) ($fsConsent['text'] ?? '')) !== ''): ?>
                                <div class="small text-secondary mt-1">„<?= $fsE((string) $fsConsent['text']) ?>“</div>
                            <?php endif; ?>
                        </dd>
                    <?php endif; ?>
                </dl>
                <p class="form-hint mb-0 mt-3"><?= $fsT('forms.inbox.privacy_note') ?></p>
            </div>
        </div>
    </div>
</div>
