<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Community – Rahmen und Ansichten der Admin-Seiten (Übersicht, Freigaben, Meldungen, Themen, Sperren, Protokoll, Ränge, Einstellungen).
 *
 * Kein Inline-Skript. Jede Aktion ist ein eigenes POST-Formular mit CSRF-Token; Entscheidungen trifft der jeweilige Dienst.
 *
 * @var array<string, mixed> $data
 * @var string $section
 * @var string $csrfToken
 * @var array|null $alert
 */

use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Settings;

$cd = is_array($data ?? null) ? $data : [];
$cs = isset(CommunityAdminModule::SECTIONS[$section ?? '']) ? (string) $section : 'overview';
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$date = static fn (mixed $value): string => CommunityAdminModule::date($value);
$returnQuery = $e((string) ($_SERVER['QUERY_STRING'] ?? ''));
$can = static fn (string $cap): bool => \CMS\Auth::instance()->hasCapability($cap);

$tabs = [];
foreach (CommunityAdminModule::SECTIONS as $key => [$path, $label, $caps]) {
    foreach ($caps as $cap) {
        if ($can($cap)) {
            $tabs[$key] = [$path, CommunityAdminModule::sectionTitle($key)];
            break;
        }
    }
}

/** Formularkopf einer Aktion. */
$formOpen = static function (string $action, array $hidden = [], string $attributes = '') use ($e, $csrfToken, $returnQuery): string {
    $html = '<form method="post" class="d-inline-flex flex-wrap gap-2 align-items-start"' . $attributes . '>'
        . '<input type="hidden" name="csrf_token" value="' . $e($csrfToken) . '">'
        . '<input type="hidden" name="action" value="' . $e($action) . '">'
        . '<input type="hidden" name="return" value="' . $returnQuery . '">';
    foreach ($hidden as $name => $value) {
        $html .= '<input type="hidden" name="' . $e($name) . '" value="' . $e($value) . '">';
    }

    return $html;
};

/** Seitenwechsel (Keyset): „Weiter“, wenn ein Cursor vorliegt. */
$more = static function (?string $next, array $extra = []) use ($e, $cs): string {
    if ($next === null || $next === '') {
        return '';
    }

    return '<div class="mt-3"><a class="btn" href="' . $e(CommunityAdminModule::url($cs, $extra + ['cursor' => $next])) . '">' . $e(I18n::t('community.admin.common.more')) . ' <i class="ti ti-arrow-right" aria-hidden="true"></i></a></div>';
};

/** Fehlermeldung eines Dienstes oder null. */
$failure = static function (mixed $result): ?string {
    return is_array($result) && empty($result['success']) ? CommunityAdminModule::errorText($result['error'] ?? null) : null;
};
?>

<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">Community</div>
                <h2 class="page-title"><?= $e(CommunityAdminModule::sectionTitle($cs)) ?></h2>
            </div>
            <div class="col-auto ms-auto">
                <a class="btn" href="/<?= $e(Settings::getInstance()->string('base_path')) ?>" target="_blank" rel="noopener"><i class="ti ti-external-link me-1" aria-hidden="true"></i><?= $e(I18n::t('community.admin.common.open_forum')) ?></a>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page admin-community-page">
    <div class="container-xl admin-redesign-shell">
        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-4" aria-label="<?= $e(I18n::t('community.admin.common.sections_label')) ?>">
            <?php foreach ($tabs as $key => [$path, $label]): ?>
                <li class="nav-item">
                    <a class="nav-link<?= $key === $cs ? ' active' : '' ?>"<?= $key === $cs ? ' aria-current="page"' : '' ?> href="<?= $e($path) ?>"><?= $e($label) ?></a>
                </li>
            <?php endforeach; ?>
        </ul>

<?php if ($cs === 'overview'): ?>
    <?php $stats = (array) ($cd['stats'] ?? []); ?>
        <div class="row row-cards mb-4">
            <?php foreach ([
                [I18n::t('community.admin.overview.pending_posts'), (int) ($cd['queue']['count'] ?? 0), CommunityAdminModule::url('queue')],
                [I18n::t('community.admin.overview.open_reports'), (int) ($cd['reports'] ?? 0), CommunityAdminModule::url('reports')],
                [I18n::t('community.admin.common.forums'), (int) ($stats['forums'] ?? 0), null],
                [I18n::t('community.admin.common.topics'), (int) ($stats['topics'] ?? 0), null],
                [I18n::t('community.admin.common.posts'), (int) ($stats['posts'] ?? 0), null],
                [I18n::t('community.admin.overview.active_bans'), (int) ($stats['bans'] ?? 0), CommunityAdminModule::url('sanctions')],
            ] as [$label, $value, $href]): ?>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card"><div class="card-body">
                        <div class="subheader"><?= $e($label) ?></div>
                        <div class="h1 mb-0"><?= $href !== null ? '<a class="text-reset" href="' . $e($href) . '">' . number_format($value, 0, ',', '.') . '</a>' : number_format($value, 0, ',', '.') ?></div>
                    </div></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($cd['queue']['oldest_at'])): ?>
            <div class="alert alert-info"><?= $e(I18n::t('community.admin.overview.oldest', ['date' => $date($cd['queue']['oldest_at'])])) ?></div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?= $e(I18n::t('community.admin.overview.setup')) ?></h3></div>
            <div class="list-group list-group-flush">
                <?php foreach ((array) ($cd['checklist'] ?? []) as $item): ?>
                    <a class="list-group-item list-group-item-action d-flex align-items-center gap-2" href="<?= $e($item['link']) ?>">
                        <i class="ti ti-<?= !empty($item['ok']) ? 'circle-check text-success' : 'circle-dashed text-secondary' ?>" aria-hidden="true"></i>
                        <span><?= $e($item['text']) ?></span>
                        <span class="visually-hidden"><?= $e(I18n::t(!empty($item['ok']) ? 'community.admin.overview.done' : 'community.admin.overview.open')) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

<?php elseif ($cs === 'queue'): ?>
    <?php $result = (array) ($cd['result'] ?? []); $forums = (array) ($cd['forums'] ?? []); ?>
    <?php if ($failure($result) !== null): ?>
        <div class="alert alert-warning"><?= $e($failure($result)) ?></div>
    <?php elseif (($result['items'] ?? []) === []): ?>
        <div class="empty"><p class="empty-title"><?= $e(I18n::t('community.admin.queue.empty')) ?></p></div>
    <?php else: ?>
        <div class="vstack gap-3">
        <?php foreach ((array) $result['items'] as $post): ?>
            <div class="card"><div class="card-body">
                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                    <div>
                        <strong><?= $e($post['topic_title'] ?? '') ?></strong>
                        <span class="text-secondary">· <?= $e($forums[(int) ($post['forum_id'] ?? 0)]                         ?? 'Forum') ?> · <?= $e(I18n::t('community.admin.common.member_id', ['id' => (int) ($post['user_id'] ?? 0)])) ?> · <?= $e($date($post['created_at'] ?? '')) ?></span>
                                                <?php if (!empty($post['is_first_post'])): ?><span class="badge bg-azure-lt"><?= $e(I18n::t('community.admin.queue.new_topic')) ?></span><?php endif; ?>
                    </div>
                </div>
                <pre class="bg-body-tertiary rounded p-2 mb-3 text-wrap overflow-auto"><?= $e($post['body'] ?? '') ?></pre>
                <div class="d-flex flex-wrap gap-2">
                    <?= $formOpen('approve_post', ['id' => (int) $post['id']]) ?>
                        <button class="btn btn-success" type="submit"><i class="ti ti-check me-1"                         aria-hidden="true"></i><?= $e(I18n::t('community.admin.common.approve')) ?></button>
                    </form>
                    <?= $formOpen('reject_post', ['id' => (int) $post['id']]) ?>
                                                <label class="visually-hidden" for="rj-<?= (int) $post['id'] ?>"><?= $e(I18n::t('community.admin.queue.reject_label')) ?></label>
                                                <input class="form-control" id="rj-<?= (int) $post['id'] ?>" type="text" name="reason" maxlength="500" placeholder="<?= $e(I18n::t('community.admin.queue.reason_required')) ?>" required>
                                                <button class="btn btn-outline-danger" type="submit"><i class="ti ti-x me-1" aria-hidden="true"></i><?= $e(I18n::t('community.admin.common.reject')) ?></button>
                    </form>
                </div>
            </div></div>
        <?php endforeach; ?>
        </div>
        <?= $more($result['next'] ?? null) ?>
    <?php endif; ?>

<?php elseif ($cs === 'reports'): ?>
    <?php $result = (array) ($cd['result'] ?? []); $detail = $cd['detail'] ?? null; $forums = (array) ($cd['forums'] ?? []); ?>
    <div class="mb-3 d-flex flex-wrap gap-2" role="group" aria-label="<?= $e(I18n::t('community.admin.reports.target_type')) ?>">
        <a class="btn btn-sm<?= ($cd['type'] ?? '') === '' ? ' btn-primary' : '' ?>" href="<?= $e(CommunityAdminModule::url('reports')) ?>"><?= $e(I18n::t('community.admin.common.all')) ?></a>
        <?php foreach (['post' => I18n::t('community.admin.common.posts'), 'topic' => I18n::t('community.admin.common.topics'), 'user' => I18n::t('community.admin.common.members')] as $type => $label): ?>
            <a class="btn btn-sm<?= ($cd['type'] ?? '') === $type ? ' btn-primary' : '' ?>" href="<?= $e(CommunityAdminModule::url('reports', ['type' => $type])) ?>"><?= $e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <?php if (is_array($detail)): ?>
        <div class="card mb-4"><div class="card-header"><h3 class="card-title"><?= $e(I18n::t('community.admin.reports.detail_heading', ['type' => $detail['target_type'], 'id' => (int) $detail['target_id']])) ?></h3></div>
        <div class="card-body">
            <?php if ($failure($detail) !== null): ?>
                <div class="alert alert-warning mb-0"><?= $e($failure($detail)) ?></div>
            <?php else: ?>
                <ul class="list-unstyled vstack gap-2">
                <?php foreach ((array) ($detail['items'] ?? []) as $report): ?>
                    <li><span class="badge bg-secondary-lt"><?= $e($report['reason'] ?? '') ?></span> <?= $e(I18n::t('community.admin.common.member_id', ['id' => (int) ($report['reporter_id'] ?? 0)])) ?> · <?= $e($date($report['created_at'] ?? '')) ?>
                        <?php if (!empty($report['message'])): ?><div class="text-secondary"><?= $e($report['message']) ?></div><?php endif; ?></li>
                <?php endforeach; ?>
                </ul>
                <?php $firstId = (int) (($detail['items'][0]['id'] ?? 0)); $lastId = (int) ((end($detail['items']))['id'] ?? 0); ?>
                <?= $formOpen('resolve_report', ['id' => $firstId, 'up_to' => $lastId]) ?>
                    <label class="visually-hidden" for="rr-status"><?= $e(I18n::t('community.admin.reports.result')) ?></label>
                    <select class="form-select w-auto" id="rr-status" name="status">
                        <option value="valid"><?= $e(I18n::t('community.admin.reports.status_valid')) ?></option>
                        <option value="resolved"><?= $e(I18n::t('community.report.status.resolved')) ?></option>
                        <option value="invalid"><?= $e(I18n::t('community.admin.reports.status_invalid')) ?></option>
                    </select>
                    <label class="visually-hidden" for="rr-note"><?= $e(I18n::t('community.admin.reports.note')) ?></label>
                    <input class="form-control w-auto" id="rr-note" type="text" name="reason" maxlength="500" placeholder="<?= $e(I18n::t('community.admin.reports.note_optional')) ?>">
                    <button class="btn btn-primary" type="submit"><?= $e(I18n::t('community.admin.reports.resolve_all')) ?></button>
                </form>
            <?php endif; ?>
        </div></div>
    <?php endif; ?>
    <?php if ($failure($result) !== null): ?>
        <div class="alert alert-warning"><?= $e($failure($result)) ?></div>
    <?php elseif (($result['items'] ?? []) === []): ?>
        <div class="empty">        <p class="empty-title"><?= $e(I18n::t('community.admin.reports.empty')) ?></p></div>
    <?php else: ?>
        <div class="card"><div class="table-responsive"><table class="table table-vcenter card-table">
                    <thead><tr><th scope="col"><?= $e(I18n::t('community.admin.common.target')) ?></th><th scope="col">Forum</th><th scope="col"><?= $e(I18n::t('community.admin.reports.count')) ?></th><th scope="col"><?= $e(I18n::t('community.admin.reports.reasons')) ?></th><th scope="col"><?= $e(I18n::t('community.admin.common.since')) ?></th><th scope="col"><span class="visually-hidden"><?= $e(I18n::t('community.admin.common.action')) ?></span></th></tr></thead>
            <tbody>
            <?php foreach ((array) $result['items'] as $bundle): ?>
                <tr>
                            <td><?= $e(['post' => I18n::t('community.admin.common.post'), 'topic' => I18n::t('community.admin.common.topic'), 'user' => I18n::t('community.admin.common.member')][$bundle['target_type']] ?? $bundle['target_type']) ?> #<?= (int) $bundle['target_id'] ?></td>
                    <td><?= $e($forums[(int) ($bundle['forum_id'] ?? 0)] ?? '–') ?></td>
                            <td><?= (int) $bundle['reports'] ?> <?= $e(I18n::t('community.admin.reports.reporters', ['count' => (int) $bundle['reporters']])) ?></td>
                    <td><?php foreach ((array) ($bundle['reasons'] ?? []) as $reason => $n): ?><span class="badge bg-secondary-lt me-1"><?= $e(is_int($reason) ? ($n['reason'] ?? '') : $reason) ?><?= is_int($n) ? ' ×' . $n : '' ?></span><?php endforeach; ?></td>
                    <td><?= $e($date($bundle['first_at'])) ?></td>
                    <td class="text-end"><a class="btn btn-sm" href="<?= $e(CommunityAdminModule::url('reports', ['target_type' => $bundle['target_type'], 'target_id' => $bundle['target_id'], 'type' => $cd['type'] ?? ''])) ?>"><?= $e(I18n::t('community.admin.reports.review')) ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
        <?= $more($result['next'] ?? null, ['type' => $cd['type'] ?? '']) ?>
    <?php endif; ?>

<?php elseif ($cs === 'topics'): ?>
    <?php $forums = (array) ($cd['forums'] ?? []); $items = (array) ($cd['items'] ?? []); ?>
    <form method="get" class="row g-2 mb-3 align-items-end">
        <div class="col-md-5"><label class="form-label" for="tp-forum">Forum</label>
            <select class="form-select" id="tp-forum" name="forum_id">
                <option value=""><?= $e(I18n::t('community.admin.common.choose')) ?></option>
                <?php foreach ($forums as $id => $forum): ?>
                    <option value="<?= (int) $id ?>"<?= (int) $id === (int) ($cd['forum_id'] ?? 0) ? ' selected' : '' ?>><?= $e(str_repeat('– ', (int) ($forum['depth'] ?? 0)) . ($forum['title'] ?? '')) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="col-md-3"><label class="form-label" for="tp-status">Status</label>
            <select class="form-select" id="tp-status" name="status">
                <?php foreach (['published' => I18n::t('community.admin.topics.status_published'), 'pending' => I18n::t('community.admin.topics.status_pending'), 'hidden' => I18n::t('community.admin.topics.status_hidden')] as $value => $label): ?>
                    <option value="<?= $e($value) ?>"<?= $value === ($cd['status'] ?? '') ? ' selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="col-auto"><button class="btn btn-primary" type="submit"><?= $e(I18n::t('community.admin.common.show')) ?></button></div>
    </form>
    <?php if ((int) ($cd['forum_id'] ?? 0) === 0): ?>
        <div class="empty"><p class="empty-title"><?= $e(I18n::t('community.admin.topics.choose_forum')) ?></p></div>
    <?php elseif ($items === []): ?>
        <div class="empty"><p class="empty-title"><?= $e(I18n::t('community.admin.topics.empty')) ?></p></div>
    <?php else: ?>
        <?= $formOpen('bulk') ?>
        <div class="card w-100 mb-3"><div class="table-responsive"><table class="table table-vcenter card-table">
            <thead><tr><th scope="col"><span class="visually-hidden"><?= $e(I18n::t('community.admin.topics.selection')) ?></span></th><th scope="col"><?= $e(I18n::t('community.admin.common.topic')) ?></th><th scope="col"><?= $e(I18n::t('community.admin.topics.replies')) ?></th><th scope="col"><?= $e(I18n::t('community.admin.topics.last_post')) ?></th><th scope="col"><?= $e(I18n::t('community.admin.topics.state')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($items as $topic): ?>
                <tr>
                    <td><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $topic['id'] ?>" aria-label="<?= $e(I18n::t('community.admin.topics.select_topic', ['id' => (int) $topic['id']])) ?>"></td>
                    <td><?= $e($topic['title'] ?? '') ?></td>
                    <td><?= (int) ($topic['reply_count'] ?? 0) ?></td>
                    <td><?= $e($date($topic['last_post_at'] ?? '')) ?></td>
                    <td><?php if (!empty($topic['is_pinned'])): ?><span class="badge bg-azure-lt"><?= $e(I18n::t('community.admin.topics.pinned')) ?></span> <?php endif; ?><?php if (!empty($topic['is_locked'])): ?><span class="badge bg-orange-lt"><?= $e(I18n::t('community.admin.topics.locked')) ?></span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
        <div class="d-flex flex-wrap gap-2 align-items-start w-100">
            <label class="visually-hidden" for="bk-op"><?= $e(I18n::t('community.admin.common.action')) ?></label>
            <select class="form-select w-auto" id="bk-op" name="operation">
                <?php foreach ([
                    'topic.lock' => I18n::t('community.admin.topics.op_lock'), 'topic.unlock' => I18n::t('community.admin.topics.op_unlock'),
                    'topic.pin' => I18n::t('community.admin.topics.op_pin'), 'topic.unpin' => I18n::t('community.admin.topics.op_unpin'),
                    'topic.hide' => I18n::t('community.admin.topics.op_hide'), 'topic.restore' => I18n::t('community.admin.topics.op_restore'),
                    'topic.approve' => I18n::t('community.admin.common.approve'), 'topic.reject' => I18n::t('community.admin.common.reject'),
                    'topic.move' => I18n::t('community.admin.topics.op_move'), 'topic.delete' => I18n::t('community.admin.common.delete'),
                ] as $value => $label): ?>
                    <option value="<?= $e($value) ?>"><?= $e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="visually-hidden" for="bk-target"><?= $e(I18n::t('community.admin.topics.target_forum_label')) ?></label>
            <select class="form-select w-auto" id="bk-target" name="target_forum_id">
                <option value=""><?= $e(I18n::t('community.admin.topics.target_forum')) ?></option>
                <?php foreach ($forums as $id => $forum): ?><option value="<?= (int) $id ?>"><?= $e($forum['title'] ?? '') ?></option><?php endforeach; ?>
            </select>
            <label class="visually-hidden" for="bk-reason"><?= $e(I18n::t('community.admin.common.reason')) ?></label>
            <input class="form-control w-auto" id="bk-reason" type="text" name="reason" maxlength="500" placeholder="<?= $e(I18n::t('community.admin.common.reason')) ?>">
            <button class="btn btn-primary" type="submit"><?= $e(I18n::t('community.admin.topics.apply')) ?></button>
        </div>
        </form>
        <?= $more($cd['next'] ?? null, ['forum_id' => $cd['forum_id'] ?? 0, 'status' => $cd['status'] ?? '']) ?>
    <?php endif; ?>

<?php elseif ($cs === 'sanctions'): ?>
    <?php $result = (array) ($cd['result'] ?? []); ?>
    <div class="card mb-4"><div class="card-header"><h3 class="card-title"><?= $e(I18n::t('community.admin.sanctions.heading')) ?></h3></div><div class="card-body">
        <?= $formOpen('ban') ?>
            <div><label class="form-label" for="bn-user"><?= $e(I18n::t('community.admin.sanctions.user_id')) ?></label><input class="form-control" id="bn-user" type="number" min="1" name="user_id" required></div>
            <div><label class="form-label" for="bn-scope"><?= $e(I18n::t('community.admin.sanctions.scope')) ?></label>
                <select class="form-select" id="bn-scope" name="scope"><option value="write"><?= $e(I18n::t('community.admin.sanctions.scope_write')) ?></option><option value="full"><?= $e(I18n::t('community.admin.sanctions.scope_full')) ?></option></select></div>
            <div><label class="form-label" for="bn-days"><?= $e(I18n::t('community.admin.sanctions.duration')) ?></label>
                <select class="form-select" id="bn-days" name="days">
                    <?php foreach ((array) ($cd['ban_days'] ?? []) as $days): ?><option value="<?= (int) $days ?>"<?= $days === 7 ? ' selected' : '' ?>><?= $e($days === 0 ? I18n::t('community.admin.sanctions.permanent') : I18n::t('community.admin.sanctions.days', ['days' => $days])) ?></option><?php endforeach; ?>
                </select></div>
            <div class="flex-grow-1"><label class="form-label" for="bn-reason"><?= $e(I18n::t('community.admin.sanctions.reason_label')) ?></label><input class="form-control" id="bn-reason" type="text" name="reason" maxlength="500" required></div>
            <div class="flex-grow-1"><label class="form-label" for="bn-note"><?= $e(I18n::t('community.admin.sanctions.note')) ?></label><input class="form-control" id="bn-note" type="text" name="note" maxlength="2000"></div>
            <div class="align-self-end"><button class="btn btn-danger" type="submit"><?= $e(I18n::t('community.admin.sanctions.submit')) ?></button></div>
        </form>
    </div></div>
    <?php if ($failure($result) !== null): ?>
        <div class="alert alert-warning"><?= $e($failure($result)) ?></div>
    <?php elseif (($result['items'] ?? []) === []): ?>
        <div class="empty"><p class="empty-title"><?= $e(I18n::t('community.admin.sanctions.empty')) ?></p></div>
    <?php else: ?>
        <div class="card"><div class="table-responsive"><table class="table table-vcenter card-table">
            <thead><tr><th scope="col"><?= $e(I18n::t('community.admin.common.member')) ?></th><th scope="col"><?= $e(I18n::t('community.admin.sanctions.scope')) ?></th><th scope="col"><?= $e(I18n::t('community.admin.common.reason')) ?></th><th scope="col"><?= $e(I18n::t('community.admin.common.since')) ?></th><th scope="col"><?= $e(I18n::t('community.admin.sanctions.until')) ?></th><th scope="col"><span class="visually-hidden"><?= $e(I18n::t('community.admin.common.action')) ?></span></th></tr></thead>
            <tbody>
            <?php foreach ((array) $result['items'] as $ban): ?>
                <tr>
                    <td>#<?= (int) $ban['user_id'] ?></td>
                    <td><?= $e(I18n::t($ban['scope'] === 'full' ? 'community.admin.sanctions.scope_full' : 'community.admin.sanctions.scope_write')) ?></td>
                    <td><?= $e($ban['reason']) ?></td>
                    <td><?= $e($date($ban['starts_at'])) ?></td>
                    <td><?= !empty($ban['ends_at']) ? $e($date($ban['ends_at'])) : $e(I18n::t('community.admin.sanctions.forever')) ?></td>
                    <td class="text-end"><?= $formOpen('lift_ban', ['id' => (int) $ban['id']]) ?><button class="btn btn-sm" type="submit"><?= $e(I18n::t('community.admin.sanctions.lift')) ?></button></form></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
        <?= $more($result['next'] ?? null) ?>
    <?php endif; ?>

<?php elseif ($cs === 'log'): ?>
    <?php $result = (array) ($cd['result'] ?? []); $filter = (array) ($cd['filter'] ?? []); ?>
    <form method="get" class="row g-2 mb-3 align-items-end">
        <div class="col-md-4"><label class="form-label" for="lg-action"><?= $e(I18n::t('community.admin.common.action')) ?></label>
            <select class="form-select" id="lg-action" name="action">
                <option value=""><?= $e(I18n::t('community.admin.common.all')) ?></option>
                <?php foreach ((array) ($cd['actions'] ?? []) as $action): ?><option value="<?= $e($action) ?>"<?= $action === ($filter['action'] ?? '') ? ' selected' : '' ?>><?= $e($action) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-3"><label class="form-label" for="lg-actor">Moderator (ID)</label><input class="form-control" id="lg-actor" type="number" min="1" name="actor_id" value="<?= (int) ($filter['actor_id'] ?? 0) ?: '' ?>"></div>
        <div class="col-auto"><button class="btn btn-primary" type="submit"><?= $e(I18n::t('community.admin.log.filter')) ?></button></div>
    </form>
    <?php if ($failure($result) !== null): ?>
        <div class="alert alert-warning"><?= $e($failure($result)) ?></div>
    <?php elseif (($result['items'] ?? []) === []): ?>
        <div class="empty"><p class="empty-title"><?= $e(I18n::t('community.admin.log.empty')) ?></p></div>
    <?php else: ?>
        <div class="card"><div class="table-responsive"><table class="table table-vcenter card-table">
            <thead><tr><th scope="col"><?= $e(I18n::t('community.admin.log.time')) ?></th><th scope="col">Moderator</th><th scope="col"><?= $e(I18n::t('community.admin.common.action')) ?></th><th scope="col"><?= $e(I18n::t('community.admin.common.target')) ?></th><th scope="col"><?= $e(I18n::t('community.admin.common.reason')) ?></th></tr></thead>
            <tbody>
            <?php foreach ((array) $result['items'] as $row): ?>
                <tr>
                    <td><?= $e($date($row['created_at'] ?? '')) ?></td>
                    <td>#<?= (int) ($row['actor_id'] ?? 0) ?></td>
                    <td><code><?= $e($row['action'] ?? '') ?></code></td>
                    <td><?= $e($row['target_type'] ?? '') ?> #<?= (int) ($row['target_id'] ?? 0) ?></td>
                    <td><?= $e($row['reason'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
        <?= $more($result['next'] ?? null, ['action' => $filter['action'] ?? '', 'actor_id' => $filter['actor_id'] ?? 0]) ?>
    <?php endif; ?>

<?php elseif ($cs === 'structure'): ?>
    <?php
    $categories = (array) ($cd['categories'] ?? []);
    $forums = (array) ($cd['forums'] ?? []);
    $types = ['discussion' => I18n::t('community.admin.structure.type_discussion'), 'qa' => I18n::t('community.admin.structure.type_qa'), 'announcements' => I18n::t('community.admin.structure.type_announcements'), 'link' => 'Link'];
    $statuses = ['open' => I18n::t('community.admin.structure.status_open'), 'readonly' => I18n::t('community.admin.structure.status_readonly'), 'archived' => I18n::t('community.admin.structure.status_archived'), 'hidden' => I18n::t('community.admin.structure.status_hidden')];
    $moveForm = static function (string $kind, int $id) use ($formOpen, $e): string {
        $html = '';
        foreach (['up' => ['arrow-up', I18n::t('community.admin.structure.move_up')], 'down' => ['arrow-down', I18n::t('community.admin.structure.move_down')]] as $dir => [$icon, $label]) {
            $html .= $formOpen('move', ['kind' => $kind, 'id' => $id, 'dir' => $dir])
                . '<button class="btn btn-sm btn-icon" type="submit" aria-label="' . $e($label) . '"><i class="ti ti-' . $icon . '" aria-hidden="true"></i></button></form>';
        }

        return $html;
    };
    $deleteForm = static function (string $kind, int $id, string $title) use ($formOpen, $e): string {
        $confirm = I18n::t($kind === 'category' ? 'community.admin.structure.delete_category' : 'community.admin.structure.delete_forum', ['title' => $title]);

        return $formOpen('delete_' . $kind, ['id' => $id], ' data-confirm-message="' . $e($confirm) . '"')
            . '<button class="btn btn-sm btn-outline-danger" type="submit"><i class="ti ti-trash me-1" aria-hidden="true"></i>' . $e(I18n::t('community.admin.common.delete')) . '</button></form>';
    };
    $reorderForm = static function (string $kind, string $group) use ($formOpen, $e): string {
        return $formOpen('reorder', ['kind' => $kind, 'order' => ''], ' hidden data-cc-reorder="' . $e($kind . ':' . $group) . '"') . '</form>';
    };
    $forumGroup = static fn (array $forum): string => (int) ($forum['category_id'] ?? 0) . '-' . (int) ($forum['parent_id'] ?? 0);
    $forumFields = static function (array $forum, string $prefix) use ($e, $types, $statuses, $categories): string {
        $html = '<div class="row g-2 w-100">'
            . '<div class="col-md-4"><label class="form-label" for="' . $prefix . 'ti">' . $e(I18n::t('community.admin.common.title')) . '</label><input class="form-control" id="' . $prefix . 'ti" name="title" required maxlength="150" value="' . $e($forum['title'] ?? '') . '"></div>'
            . '<div class="col-md-3"><label class="form-label" for="' . $prefix . 'sl">' . $e(I18n::t('community.admin.structure.slug_auto')) . '</label><input class="form-control" id="' . $prefix . 'sl" name="slug" maxlength="160" value="' . $e($forum['slug'] ?? '') . '"></div>'
            . '<div class="col-md-3"><label class="form-label" for="' . $prefix . 'ca">' . $e(I18n::t('community.admin.structure.category')) . '</label><select class="form-select" id="' . $prefix . 'ca" name="category_id"><option value="0">–</option>';
        foreach ($categories as $category) {
            $html .= '<option value="' . (int) $category['id'] . '"' . ((int) ($forum['category_id'] ?? 0) === (int) $category['id'] ? ' selected' : '') . '>' . $e($category['title']) . '</option>';
        }
        $html .= '</select></div>'
            . '<div class="col-md-2"><label class="form-label" for="' . $prefix . 'ty">' . $e(I18n::t('community.admin.structure.type')) . '</label><select class="form-select" id="' . $prefix . 'ty" name="type">';
        foreach ($types as $value => $label) {
            $html .= '<option value="' . $value . '"' . (($forum['type'] ?? 'discussion') === $value ? ' selected' : '') . '>' . $label . '</option>';
        }
        $html .= '</select></div>'
            . '<div class="col-md-2"><label class="form-label" for="' . $prefix . 'st">Status</label><select class="form-select" id="' . $prefix . 'st" name="status">';
        foreach ($statuses as $value => $label) {
            $html .= '<option value="' . $value . '"' . (($forum['status'] ?? 'open') === $value ? ' selected' : '') . '>' . $label . '</option>';
        }
        $html .= '</select></div>'
            . '<div class="col-md-2"><label class="form-label" for="' . $prefix . 'sm">' . $e(I18n::t('community.admin.structure.slow_mode')) . '</label><input class="form-control" id="' . $prefix . 'sm" type="number" min="0" name="slow_mode_seconds" value="' . (int) ($forum['slow_mode_seconds'] ?? 0) . '"></div>'
            . '<div class="col-md-4"><label class="form-label" for="' . $prefix . 'de">' . $e(I18n::t('community.admin.structure.description')) . '</label><input class="form-control" id="' . $prefix . 'de" name="description" value="' . $e($forum['description'] ?? '') . '"></div>'
            . '<div class="col-md-4 d-flex align-items-end gap-3">'
            . '<label class="form-check"><input class="form-check-input" type="checkbox" name="require_approval" value="1"' . (!empty($forum['require_approval']) ? ' checked' : '') . '><span class="form-check-label">' . $e(I18n::t('community.admin.structure.require_approval')) . '</span></label>'
            . '<label class="form-check"><input class="form-check-input" type="checkbox" name="inherit_permissions" value="1"' . (!isset($forum['inherit_permissions']) || !empty($forum['inherit_permissions']) ? ' checked' : '') . '><span class="form-check-label">' . $e(I18n::t('community.admin.structure.inherit')) . '</span></label>'
            . '</div></div>';

        return $html;
    };
    ?>
    <p class="text-secondary"><?= $e(I18n::t('community.admin.structure.hint')) ?></p>

    <?= $reorderForm('category', 'all') ?>
    <div class="card mb-4"><div class="card-header"><h3 class="card-title"><?= $e(I18n::t('community.admin.structure.categories')) ?></h3></div><div class="list-group list-group-flush">
        <?php foreach ($categories as $category): ?>
            <div class="list-group-item" data-cc-sort="category:all" data-id="<?= (int) $category['id'] ?>">
                <div class="d-flex flex-wrap gap-2 align-items-end">
                    <div class="d-flex gap-1"><span class="btn btn-sm btn-icon" data-cc-grip title="<?= $e(I18n::t('community.admin.structure.drag')) ?>" aria-hidden="true"><i class="ti ti-grip-vertical"></i></span><?= $moveForm('category', (int) $category['id']) ?></div>
                    <?= $formOpen('save_category', ['id' => (int) $category['id']]) ?>
                        <div><label class="form-label" for="cat-t<?= (int) $category['id'] ?>"><?= $e(I18n::t('community.admin.common.title')) ?></label><input class="form-control" id="cat-t<?= (int) $category['id'] ?>" name="title" required maxlength="150" value="<?= $e($category['title']) ?>"></div>
                        <div><label class="form-label" for="cat-s<?= (int) $category['id'] ?>"><?= $e(I18n::t('community.admin.structure.slug')) ?></label><input class="form-control" id="cat-s<?= (int) $category['id'] ?>" name="slug" maxlength="160" value="<?= $e($category['slug']) ?>"></div>
                        <div class="align-self-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="is_visible" value="1"<?= !empty($category['is_visible']) ? ' checked' : '' ?>><span class="form-check-label"><?= $e(I18n::t('community.admin.structure.visible')) ?></span></label></div>
                        <div class="align-self-end"><button class="btn btn-sm btn-primary" type="submit"><?= $e(I18n::t('community.admin.common.save')) ?></button></div>
                    </form>
                    <div class="align-self-end"><?= $deleteForm('category', (int) $category['id'], (string) $category['title']) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="list-group-item">
            <?= $formOpen('save_category') ?>
                <div><label class="form-label" for="cat-new-t"><?= $e(I18n::t('community.admin.structure.new_category')) ?></label><input class="form-control" id="cat-new-t" name="title" required maxlength="150"></div>
                <input type="hidden" name="is_visible" value="1">
                <div class="align-self-end"><button class="btn btn-sm btn-primary" type="submit"><?= $e(I18n::t('community.admin.common.create')) ?></button></div>
            </form>
        </div>
    </div></div>

    <?php foreach (array_unique(array_map($forumGroup, $forums)) as $group): ?><?= $reorderForm('forum', $group) ?><?php endforeach; ?>
    <div class="card mb-4"><div class="card-header"><h3 class="card-title"><?= $e(I18n::t('community.admin.common.forums')) ?></h3></div><div class="list-group list-group-flush">
        <?php foreach ($forums as $forum): ?>
            <div class="list-group-item" data-cc-sort="forum:<?= $e($forumGroup($forum)) ?>" data-id="<?= (int) $forum['id'] ?>">
                <div class="d-flex flex-wrap gap-2 align-items-start">
                    <div class="d-flex gap-1"><span class="btn btn-sm btn-icon" data-cc-grip title="<?= $e(I18n::t('community.admin.structure.drag')) ?>" aria-hidden="true"><i class="ti ti-grip-vertical"></i></span><?= $moveForm('forum', (int) $forum['id']) ?></div>
                    <?= $formOpen('save_forum', ['id' => (int) $forum['id']]) ?>
                        <div class="w-100 fw-bold"><?= $e(str_repeat('— ', (int) $forum['depth'])) ?>#<?= (int) $forum['id'] ?> · <?= $e(I18n::t('community.admin.structure.topic_count', ['count' => (int) ($forum['topic_count'] ?? 0)])) ?></div>
                        <?= $forumFields($forum, 'f' . (int) $forum['id']) ?>
                        <div class="w-100"><button class="btn btn-sm btn-primary" type="submit"><?= $e(I18n::t('community.admin.common.save')) ?></button></div>
                    </form>
                    <?= $deleteForm('forum', (int) $forum['id'], (string) ($forum['title'] ?? '')) ?>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="list-group-item">
            <?= $formOpen('save_forum') ?>
                <div class="w-100 fw-bold"><?= $e(I18n::t('community.admin.structure.new_forum')) ?></div>
                <?= $forumFields([], 'fnew') ?>
                <div class="col-md-3"><label class="form-label" for="fnew-pa"><?= $e(I18n::t('community.admin.structure.parent')) ?></label>
                    <select class="form-select" id="fnew-pa" name="parent_id"><option value="0"><?= $e(I18n::t('community.admin.structure.top_level')) ?></option>
                        <?php foreach ($forums as $forum): if ((int) $forum['depth'] + 1 >= (int) ($cd['max_depth'] ?? 3)) { continue; } ?>
                            <option value="<?= (int) $forum['id'] ?>"><?= $e(str_repeat('– ', (int) $forum['depth']) . $forum['title']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="w-100"><button class="btn btn-primary" type="submit"><?= $e(I18n::t('community.admin.structure.create_forum')) ?></button></div>
            </form>
        </div>
    </div></div>

<?php elseif ($cs === 'permissions'): ?>
    <?php
    $forums = (array) ($cd['forums'] ?? []);
    $permLabels = [
        'read' => I18n::t('community.admin.permissions.short.read'), 'create_topic' => I18n::t('community.admin.permissions.short.create_topic'),
        'reply' => I18n::t('community.admin.permissions.short.reply'), 'edit_own' => I18n::t('community.admin.permissions.short.edit_own'),
        'delete_own' => I18n::t('community.admin.permissions.short.delete_own'), 'upload' => 'Upload', 'poll' => I18n::t('community.admin.permissions.short.poll'),
        'vote' => I18n::t('community.admin.permissions.short.vote'), 'react' => I18n::t('community.admin.permissions.short.react'),
        'mark_solution' => I18n::t('community.admin.permissions.short.mark_solution'), 'bypass_approval' => I18n::t('community.admin.permissions.short.bypass_approval'),
        'bypass_slow_mode' => I18n::t('community.admin.permissions.short.bypass_slow_mode'),
    ];
    $subjects = ['guest:' => I18n::t('community.admin.permissions.guests')];
    foreach ((array) ($cd['roles'] ?? []) as $role) {
        $subjects['role:' . $role] = I18n::t('community.admin.permissions.role', ['role' => $role]);
    }
    foreach ((array) ($cd['groups'] ?? []) as $groupId => $groupName) {
        $subjects['group:' . (int) $groupId] = I18n::t('community.admin.permissions.group', ['name' => $groupName]);
    }
    foreach (array_keys((array) ($cd['rows'] ?? [])) as $rowKey) {
        if (!isset($subjects[$rowKey]) && str_starts_with((string) $rowKey, 'group:')) {
            $subjects[(string) $rowKey] = I18n::t('community.admin.permissions.group_deleted', ['id' => substr((string) $rowKey, 6)]);
        }
    }
    foreach ([0, 1, 2, 3, 4] as $level) {
        $subjects['trust:' . $level] = I18n::t('community.admin.permissions.trust', ['level' => $level]);
    }
    ?>
    <form method="get" class="row g-2 mb-3 align-items-end">
        <div class="col-md-5"><label class="form-label" for="pm-forum">Forum</label>
            <select class="form-select" id="pm-forum" name="forum_id">
                <option value=""><?= $e(I18n::t('community.admin.common.choose')) ?></option>
                <?php foreach ($forums as $id => $forum): ?><option value="<?= (int) $id ?>"<?= (int) $id === (int) ($cd['forum_id'] ?? 0) ? ' selected' : '' ?>><?= $e(str_repeat('– ', (int) ($forum['depth'] ?? 0)) . ($forum['title'] ?? '')) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-auto"><button class="btn btn-primary" type="submit"><?= $e(I18n::t('community.admin.common.show')) ?></button></div>
    </form>
    <?php if ((int) ($cd['forum_id'] ?? 0) === 0): ?>
        <div class="empty"><p class="empty-title"><?= $e(I18n::t('community.admin.permissions.choose_forum')) ?></p></div>
    <?php else: ?>
        <?php $forum = $forums[(int) $cd['forum_id']]; ?>
        <?php if (!empty($forum['inherit_permissions']) && (int) ($forum['parent_id'] ?? 0) > 0): ?>
            <div class="alert alert-info"><?= $e(I18n::t('community.admin.permissions.inherits')) ?></div>
        <?php elseif ((array) ($cd['rows'] ?? []) === []): ?>
            <div class="alert alert-info"><?= $e(I18n::t('community.admin.permissions.defaults')) ?></div>
        <?php endif; ?>
        <form method="post" class="vstack gap-4">
            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
            <input type="hidden" name="action" value="save_permissions">
            <input type="hidden" name="forum_id" value="<?= (int) $cd['forum_id'] ?>">
            <input type="hidden" name="return" value="<?= $returnQuery ?>">
            <div class="card"><div class="card-body pb-0">            <p class="text-secondary"><?= $e(I18n::t('community.admin.permissions.hint')) ?></p></div>
            <div class="table-responsive"><table class="table table-vcenter card-table">
                            <thead><tr><th scope="col"><?= $e(I18n::t('community.admin.permissions.who')) ?></th><?php foreach ($permLabels as $label): ?><th scope="col"><?= $e($label) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($subjects as $key => $label): $row = (array) (($cd['rows'] ?? [])[$key] ?? []); ?>
                    <tr><th scope="row"><?= $e($label) ?></th>
                    <?php foreach ($permLabels as $perm => $permLabel): $value = array_key_exists($perm, $row) ? (string) (int) $row[$perm] : ''; ?>
                        <td><select class="form-select form-select-sm" name="perm[<?= $e($key) ?>][<?= $e($perm) ?>]" aria-label="<?= $e($label . ': ' . $permLabel) ?>">
                            <option value="">–</option><option value="1"<?= $value === '1' ? ' selected' : '' ?>><?= $e(I18n::t('community.admin.common.yes')) ?></option><option value="0"<?= $value === '0' ? ' selected' : '' ?>><?= $e(I18n::t('community.admin.common.no')) ?></option>
                        </select></td>
                    <?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div></div>
            <?php
            $modTokens = [];
            $modChildren = true;
            foreach ((array) ($cd['moderators'] ?? []) as $moderator) {
                $modTokens[] = ($moderator['subject_type'] === 'group' ? 'g' : 'u') . $moderator['subject_id'];
                $modChildren = $modChildren && !empty($moderator['include_children']);
            }
            ?>
            <div class="card"><div class="card-header"><h3 class="card-title"><?= $e(I18n::t('community.admin.permissions.moderators')) ?></h3></div><div class="card-body">
                <label class="form-label" for="pm-mods"><?= $e(I18n::t('community.admin.permissions.moderators_label')) ?></label>
                <input class="form-control mb-2" id="pm-mods" name="moderators" value="<?= $e(implode(' ', $modTokens)) ?>" placeholder="u12 u40 g3">
                <label class="form-check"><input class="form-check-input" type="checkbox" name="mod_children" value="1"<?= $modChildren ? ' checked' : '' ?>><span class="form-check-label"><?= $e(I18n::t('community.admin.permissions.children')) ?></span></label>
            </div></div>
            <div><button class="btn btn-primary" type="submit"><i class="ti ti-device-floppy me-1" aria-hidden="true"></i><?= $e(I18n::t('community.admin.permissions.save')) ?></button></div>
        </form>
    <?php endif; ?>

<?php elseif ($cs === 'ranks'): ?>
    <?php $ranks = (array) ($cd['ranks'] ?? []); ?>
    <div class="card mb-4"><div class="table-responsive"><table class="table table-vcenter card-table">
        <thead><tr><th scope="col"><?= $e(I18n::t('community.admin.common.title')) ?></th><th scope="col"><?= $e(I18n::t('community.admin.ranks.english')) ?></th><th scope="col"><?= $e(I18n::t('community.admin.ranks.min_posts')) ?></th><th scope="col"><?= $e(I18n::t('community.admin.ranks.color')) ?></th><th scope="col"><?= $e(I18n::t('community.admin.ranks.icon')) ?></th><th scope="col"><?= $e(I18n::t('community.admin.ranks.order')) ?></th><th scope="col"><span class="visually-hidden"><?= $e(I18n::t('community.admin.common.action')) ?></span></th></tr></thead>
        <tbody>
        <?php foreach ($ranks as $rank): ?>
            <tr>
                <?= $formOpen('save_rank', ['id' => (int) $rank['id']]) ?>
                <td><input class="form-control" name="title" aria-label="<?= $e(I18n::t('community.admin.common.title')) ?>" value="<?= $e($rank['title']) ?>" required maxlength="60"></td>
                <td><input class="form-control" name="title_en" aria-label="<?= $e(I18n::t('community.admin.ranks.title_en_aria')) ?>" value="<?= $e($rank['title_en'] ?? '') ?>" maxlength="60"></td>
                <td><input class="form-control" type="number" min="0" name="min_posts" aria-label="<?= $e(I18n::t('community.admin.ranks.min_posts')) ?>" value="<?= $e($rank['min_posts'] ?? '') ?>"></td>
                <td><input class="form-control" name="color" aria-label="<?= $e(I18n::t('community.admin.ranks.color')) ?>" value="<?= $e($rank['color'] ?? '') ?>" placeholder="#rrggbb" maxlength="9"></td>
                <td><input class="form-control" name="icon" aria-label="<?= $e(I18n::t('community.admin.ranks.icon')) ?>" value="<?= $e($rank['icon'] ?? '') ?>" maxlength="50"></td>
                <td><input class="form-control" type="number" min="0" name="sort_order" aria-label="<?= $e(I18n::t('community.admin.ranks.order')) ?>" value="<?= (int) $rank['sort_order'] ?>"></td>
                <td class="text-end text-nowrap"><button class="btn btn-sm btn-primary" type="submit"><?= $e(I18n::t('community.admin.common.save')) ?></button></form>
                    <?= $formOpen('delete_rank', ['id' => (int) $rank['id']]) ?><button class="btn btn-sm btn-outline-danger" type="submit"><?= $e(I18n::t('community.admin.common.delete')) ?></button></form></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
    <div class="card"><div class="card-header"><h3 class="card-title"><?= $e(I18n::t('community.admin.ranks.new')) ?></h3></div><div class="card-body">
        <?= $formOpen('save_rank') ?>
            <div><label class="form-label" for="rk-title"><?= $e(I18n::t('community.admin.common.title')) ?></label><input class="form-control" id="rk-title" name="title" required maxlength="60"></div>
            <div><label class="form-label" for="rk-en"><?= $e(I18n::t('community.admin.ranks.title_en')) ?></label><input class="form-control" id="rk-en" name="title_en" maxlength="60"></div>
            <div><label class="form-label" for="rk-min"><?= $e(I18n::t('community.admin.ranks.min_posts')) ?></label><input class="form-control" id="rk-min" type="number" min="0" name="min_posts"></div>
            <div><label class="form-label" for="rk-color"><?= $e(I18n::t('community.admin.ranks.color')) ?></label><input class="form-control" id="rk-color" name="color" placeholder="#rrggbb" maxlength="9"></div>
            <div><label class="form-label" for="rk-icon"><?= $e(I18n::t('community.admin.ranks.icon')) ?></label><input class="form-control" id="rk-icon" name="icon" maxlength="50"></div>
            <div><label class="form-label" for="rk-sort"><?= $e(I18n::t('community.admin.ranks.order')) ?></label><input class="form-control" id="rk-sort" type="number" min="0" name="sort_order" value="0"></div>
            <div class="align-self-end"><button class="btn btn-primary" type="submit"><?= $e(I18n::t('community.admin.common.create')) ?></button></div>
        </form>
    </div></div>

<?php elseif ($cs === 'settings'): ?>
    <?php
    $values = (array) ($cd['values'] ?? []);
    $labels = [
        'base_path' => I18n::t('community.admin.settings.label.base_path'), 'guests_can_read' => I18n::t('community.admin.settings.label.guests_can_read'),
        'require_verified_email' => I18n::t('community.admin.settings.label.require_verified_email'), 'topics_per_page' => I18n::t('community.admin.settings.label.topics_per_page'),
        'posts_per_page' => I18n::t('community.admin.settings.label.posts_per_page'), 'title_min' => I18n::t('community.admin.settings.label.title_min'),
        'title_max' => I18n::t('community.admin.settings.label.title_max'), 'post_min' => I18n::t('community.admin.settings.label.post_min'),
        'post_max' => I18n::t('community.admin.settings.label.post_max'), 'edit_window_minutes' => I18n::t('community.admin.settings.label.edit_window_minutes'),
        'edit_window_minutes_trusted' => I18n::t('community.admin.settings.label.edit_window_minutes_trusted'), 'rate_post_seconds' => I18n::t('community.admin.settings.label.rate_post_seconds'),
        'rate_topic_seconds' => I18n::t('community.admin.settings.label.rate_topic_seconds'), 'new_member_approval_posts' => I18n::t('community.admin.settings.label.new_member_approval_posts'),
        'max_pending_per_user' => I18n::t('community.admin.settings.label.max_pending_per_user'), 'duplicate_window_seconds' => I18n::t('community.admin.settings.label.duplicate_window_seconds'),
        'trust_level1_posts' => I18n::t('community.admin.settings.label.trust_level1_posts'), 'trust_level2_posts' => I18n::t('community.admin.settings.label.trust_level2_posts'),
        'unread_horizon_days' => I18n::t('community.admin.settings.label.unread_horizon_days'), 'use_core_antispam' => I18n::t('community.admin.settings.label.use_core_antispam'),
        'spam_duplicate_hours' => I18n::t('community.admin.settings.label.spam_duplicate_hours'), 'ip_hash_days' => I18n::t('community.admin.settings.label.ip_hash_days'),
        'moved_stub_days' => I18n::t('community.admin.settings.label.moved_stub_days'), 'moderation_reason_required' => I18n::t('community.admin.settings.label.moderation_reason_required'),
        'report_hide_threshold' => I18n::t('community.admin.settings.label.report_hide_threshold'), 'moderator_ban_max_days' => I18n::t('community.admin.settings.label.moderator_ban_max_days'),
        'notify_mail' => I18n::t('community.admin.settings.label.notify_mail'), 'attachment_max_kb' => I18n::t('community.admin.settings.label.attachment_max_kb'),
        'captcha_mode' => I18n::t('community.admin.settings.label.captcha_mode'), 'captcha_provider' => I18n::t('community.admin.settings.label.captcha_provider'),
        'erase_mode' => I18n::t('community.admin.settings.label.erase_mode'),
    ];
    $enumLabels = [
        'captcha_mode' => [
            'off' => I18n::t('community.admin.settings.enum.captcha_mode.off'), 'first_post' => I18n::t('community.admin.settings.enum.captcha_mode.first_post'),
            'trust0' => I18n::t('community.admin.settings.enum.captcha_mode.trust0'), 'always' => I18n::t('community.admin.settings.enum.captcha_mode.always'),
        ],
        'captcha_provider' => [
            'pow' => I18n::t('community.admin.settings.enum.captcha_provider.pow'), 'image' => I18n::t('community.admin.settings.enum.captcha_provider.image'),
            'math' => I18n::t('community.admin.settings.enum.captcha_provider.math'),
        ],
        'erase_mode' => ['anonymize' => I18n::t('community.admin.settings.enum.erase_mode.anonymize'), 'delete' => I18n::t('community.admin.settings.enum.erase_mode.delete')],
    ];
    $groups = [
        I18n::t('community.admin.settings.group.general') => ['base_path', 'guests_can_read', 'require_verified_email', 'topics_per_page', 'posts_per_page', 'unread_horizon_days', 'moved_stub_days', 'notify_mail'],
        I18n::t('community.admin.settings.group.content') => ['title_min', 'title_max', 'post_min', 'post_max', 'edit_window_minutes', 'edit_window_minutes_trusted', 'attachment_max_kb'],
        I18n::t('community.admin.settings.group.spam') => ['rate_post_seconds', 'rate_topic_seconds', 'duplicate_window_seconds', 'spam_duplicate_hours', 'new_member_approval_posts', 'max_pending_per_user', 'use_core_antispam', 'ip_hash_days', 'captcha_mode', 'captcha_provider'],
        I18n::t('community.admin.settings.group.moderation') => ['moderation_reason_required', 'report_hide_threshold', 'moderator_ban_max_days', 'trust_level1_posts', 'trust_level2_posts'],
        I18n::t('community.admin.settings.group.privacy') => ['erase_mode'],
    ];
    $limitLabels = [
        'posts_per_day' => I18n::t('community.admin.settings.limit.posts_per_day'), 'topics_per_hour' => I18n::t('community.admin.settings.limit.topics_per_hour'),
        'links_per_post' => I18n::t('community.admin.settings.limit.links_per_post'), 'images_per_post' => I18n::t('community.admin.settings.limit.images_per_post'),
        'attachments_per_post' => I18n::t('community.admin.settings.limit.attachments_per_post'), 'attachments_per_day' => I18n::t('community.admin.settings.limit.attachments_per_day'),
    ];
    $permLabels = [
        'read' => I18n::t('community.admin.settings.perm.read'), 'create_topic' => I18n::t('community.admin.settings.perm.create_topic'),
        'reply' => I18n::t('community.admin.settings.perm.reply'), 'edit_own' => I18n::t('community.admin.settings.perm.edit_own'),
        'delete_own' => I18n::t('community.admin.settings.perm.delete_own'), 'upload' => I18n::t('community.admin.settings.perm.upload'),
        'poll' => I18n::t('community.admin.settings.perm.poll'), 'vote' => I18n::t('community.admin.settings.perm.vote'), 'react' => I18n::t('community.admin.settings.perm.react'),
        'mark_solution' => I18n::t('community.admin.settings.perm.mark_solution'), 'bypass_approval' => I18n::t('community.admin.settings.perm.bypass_approval'),
        'bypass_slow_mode' => I18n::t('community.admin.settings.perm.bypass_slow_mode'),
    ];
    ?>
    <form method="post" class="vstack gap-4">
        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
        <input type="hidden" name="action" value="save_settings">
        <?php foreach ($groups as $title => $keys): ?>
            <div class="card"><div class="card-header"><h3 class="card-title"><?= $e($title) ?></h3></div><div class="card-body"><div class="row g-3">
                <?php foreach ($keys as $key): $def = Settings::DEFINITIONS[$key]; ?>
                    <div class="col-md-6 col-xl-4">
                        <?php if ($def['type'] === 'bool'): ?>
                            <label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="s_<?= $e($key) ?>" value="1"<?= !empty($values[$key]) ? ' checked' : '' ?>><span class="form-check-label"><?= $e($labels[$key] ?? $key) ?></span></label>
                        <?php elseif ($def['type'] === 'enum'): ?>
                            <label class="form-label" for="s_<?= $e($key) ?>"><?= $e($labels[$key] ?? $key) ?></label>
                            <select class="form-select" id="s_<?= $e($key) ?>" name="s_<?= $e($key) ?>">
                                <?php foreach ($def['values'] as $option): ?>
                                    <option value="<?= $e($option) ?>"<?= (string) ($values[$key] ?? $def['default']) === $option ? ' selected' : '' ?>><?= $e($enumLabels[$key][$option] ?? $option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <label class="form-label" for="s_<?= $e($key) ?>"><?= $e($labels[$key] ?? $key) ?></label>
                            <input class="form-control" id="s_<?= $e($key) ?>" name="s_<?= $e($key) ?>" <?= $def['type'] === 'int' ? 'type="number" min="' . (int) $def['min'] . '" max="' . (int) $def['max'] . '"' : 'type="text" pattern="[a-z0-9][a-z0-9\-]{0,39}"' ?> value="<?= $e($values[$key] ?? '') ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div></div></div>
        <?php endforeach; ?>

        <div class="card"><div class="card-header"><h3 class="card-title"><?= $e(I18n::t('community.admin.settings.limits')) ?></h3></div>
            <div class="card-body"><p class="text-secondary"><?= $e(I18n::t('community.admin.settings.limits_hint')) ?></p></div>
            <div class="table-responsive"><table class="table table-vcenter card-table">
                <thead><tr><th scope="col"><?= $e(I18n::t('community.admin.settings.level')) ?></th><?php foreach ($limitLabels as $label): ?><th scope="col"><?= $e($label) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach (Settings::TRUST_LEVELS as $level): ?>
                    <tr><th scope="row"><?= (int) $level ?></th>
                    <?php foreach ($limitLabels as $field => $label): ?>
                        <td><input class="form-control" type="number" min="-1" max="<?= (int) Settings::LIMIT_MAX ?>" name="limit[<?= (int) $level ?>][<?= $e($field) ?>]" aria-label="<?= $e(I18n::t('community.admin.settings.limit_aria', ['label' => $label, 'level' => $level])) ?>" value="<?= (int) ($values['trust_limits'][$level][$field] ?? 0) ?>"></td>
                    <?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div></div>

        <div class="card"><div class="card-header"><h3 class="card-title"><?= $e(I18n::t('community.admin.settings.default_perms')) ?></h3></div>
            <div class="table-responsive"><table class="table table-vcenter card-table">
                <thead><tr><th scope="col"><?= $e(I18n::t('community.admin.settings.permission')) ?></th><th scope="col"><?= $e(I18n::t('community.admin.settings.guest')) ?></th><th scope="col"><?= $e(I18n::t('community.admin.common.member')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($permLabels as $perm => $label): ?>
                    <tr><th scope="row"><?= $e($label) ?></th>
                    <?php foreach (Settings::PERMISSION_SUBJECTS as $subject): ?>
                        <td><input class="form-check-input" type="checkbox" name="perm[<?= $e($subject) ?>][<?= $e($perm) ?>]" value="1" aria-label="<?= $e($label . ' – ' . I18n::t($subject === 'guest' ? 'community.admin.settings.guest' : 'community.admin.common.member')) ?>"<?= !empty($values['default_permissions'][$subject][$perm]) ? ' checked' : '' ?>></td>
                    <?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div></div>

        <div class="card"><div class="card-header"><h3 class="card-title"><?= $e(I18n::t('community.admin.settings.reactions_attachments')) ?></h3></div><div class="card-body">
            <fieldset class="mb-3"><legend class="form-label"><?= $e(I18n::t('community.admin.settings.allowed_reactions')) ?></legend>
                <?php foreach (Settings::REACTION_KEYS as $reaction): ?>
                    <label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="reaction_set[]" value="<?= $e($reaction) ?>"<?= in_array($reaction, (array) ($values['reaction_set'] ?? []), true) ? ' checked' : '' ?>><span class="form-check-label"><?= $e($reaction) ?></span></label>
                <?php endforeach; ?>
            </fieldset>
            <fieldset><legend class="form-label"><?= $e(I18n::t('community.admin.settings.allowed_types')) ?></legend>
                <?php foreach (Settings::ATTACHMENT_TYPES as $ext): ?>
                    <label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="attachment_types[<?= $e($ext) ?>]" value="1"<?= !empty($values['attachment_types'][$ext]) ? ' checked' : '' ?>><span class="form-check-label"><?= $e($ext) ?></span></label>
                <?php endforeach; ?>
            </fieldset>
        </div></div>

        <div class="card"><div class="card-header"><h3 class="card-title"><?= $e(I18n::t('community.admin.settings.word_filter')) ?></h3></div><div class="card-body"><div class="row g-3">
            <?php foreach (['block' => I18n::t('community.admin.settings.wf.block'), 'review' => I18n::t('community.admin.settings.wf.review'), 'replace' => I18n::t('community.admin.settings.wf.replace')] as $list => $label): ?>
                <div class="col-md-4"><label class="form-label" for="wf_<?= $e($list) ?>"><?= $e(I18n::t('community.admin.settings.wf.label', ['label' => $label])) ?></label>
                    <textarea class="form-control" id="wf_<?= $e($list) ?>" name="wf_<?= $e($list) ?>" rows="6"><?= $e(implode("\n", (array) ($values['word_filter'][$list] ?? []))) ?></textarea></div>
            <?php endforeach; ?>
            <div class="col-md-4"><label class="form-label" for="wf_replacement"><?= $e(I18n::t('community.admin.settings.replacement')) ?></label><input class="form-control" id="wf_replacement" name="wf_replacement" value="<?= $e($values['word_filter']['replacement'] ?? '***') ?>" maxlength="20"></div>
        </div></div></div>

        <div><button class="btn btn-primary" type="submit"><i class="ti ti-device-floppy me-1" aria-hidden="true"></i><?= $e(I18n::t('community.admin.settings.save')) ?></button></div>
    </form>

    <?php
    $exports = (array) ($cd['exports'] ?? []);
    $keepDays = (int) ($cd['export_keep_days'] ?? 30);
    ?>
    <div class="card mt-4" id="community-exports">
        <div class="card-header"><h3 class="card-title"><?= $e(I18n::t('community.admin.settings.exports_heading')) ?></h3></div>
        <div class="card-body">
            <p class="text-secondary mb-0"><?= $e(I18n::t('community.admin.settings.exports_hint', ['days' => $keepDays])) ?></p>
        </div>
        <?php if ($exports === []): ?>
            <div class="card-body border-top text-secondary">Keine Exportdateien vorhanden.</div>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-vcenter card-table">
                <thead><tr><th scope="col"><?= $e(I18n::t('community.admin.settings.file')) ?></th><th scope="col"><?= $e(I18n::t('community.admin.settings.size')) ?></th><th scope="col"><?= $e(I18n::t('community.admin.settings.created')) ?></th><th scope="col"><?= $e(I18n::t('community.admin.settings.expires')) ?></th><th scope="col"><span class="visually-hidden"><?= $e(I18n::t('community.admin.settings.actions')) ?></span></th></tr></thead>
                <tbody>
                <?php foreach ($exports as $export): ?>
                    <?php
                    $createdTs = strtotime((string) $export['created_at'] . ' UTC');
                    $expires = $createdTs === false ? '' : gmdate('Y-m-d H:i:s', $createdTs + $keepDays * 86400);
                    ?>
                    <tr>
                        <td><code><?= $e((string) $export['name']) ?></code></td>
                        <td><?= $e(number_format(max(1, (int) round(((int) $export['size']) / 1024)), 0, ',', '.')) ?> KB</td>
                        <td><?= $e(CommunityAdminModule::date($export['created_at'])) ?></td>
                        <td><?= $e(CommunityAdminModule::date($expires)) ?></td>
                        <td class="text-end text-nowrap">
                            <?= $formOpen('export_download', ['file' => (string) $export['name']]) ?><button class="btn btn-sm btn-outline-primary" type="submit"><i class="ti ti-download me-1"                             aria-hidden="true"></i><?= $e(I18n::t('community.admin.settings.download')) ?></button></form>
                                                        <?= $formOpen('export_delete', ['file' => (string) $export['name']]) ?><button class="btn btn-sm btn-outline-danger" type="submit"><?= $e(I18n::t('community.admin.common.delete')) ?></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
<?php endif; ?>
    </div>
</div>
