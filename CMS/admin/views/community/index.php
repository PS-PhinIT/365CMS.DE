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
            $tabs[$key] = [$path, $label];
            break;
        }
    }
}

/** Formularkopf einer Aktion. */
$formOpen = static function (string $action, array $hidden = []) use ($e, $csrfToken, $returnQuery): string {
    $html = '<form method="post" class="d-inline-flex flex-wrap gap-2 align-items-start">'
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

    return '<div class="mt-3"><a class="btn" href="' . $e(CommunityAdminModule::url($cs, $extra + ['cursor' => $next])) . '">Weiter <i class="ti ti-arrow-right" aria-hidden="true"></i></a></div>';
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
                <h2 class="page-title"><?= $e(CommunityAdminModule::SECTIONS[$cs][1]) ?></h2>
            </div>
            <div class="col-auto ms-auto">
                <a class="btn" href="/<?= $e(Settings::getInstance()->string('base_path')) ?>" target="_blank" rel="noopener"><i class="ti ti-external-link me-1" aria-hidden="true"></i>Forum öffnen</a>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page admin-community-page">
    <div class="container-xl admin-redesign-shell">
        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-4" aria-label="Community-Bereiche">
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
                ['Wartende Beiträge', (int) ($cd['queue']['count'] ?? 0), CommunityAdminModule::url('queue')],
                ['Offene Meldungen', (int) ($cd['reports'] ?? 0), CommunityAdminModule::url('reports')],
                ['Foren', (int) ($stats['forums'] ?? 0), null],
                ['Themen', (int) ($stats['topics'] ?? 0), null],
                ['Beiträge', (int) ($stats['posts'] ?? 0), null],
                ['Aktive Sperren', (int) ($stats['bans'] ?? 0), CommunityAdminModule::url('sanctions')],
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
            <div class="alert alert-info">Ältester wartender Beitrag: <?= $e($date($cd['queue']['oldest_at'])) ?> (UTC-Zeit des Servers umgerechnet).</div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header"><h3 class="card-title">Einrichtung</h3></div>
            <div class="list-group list-group-flush">
                <?php foreach ((array) ($cd['checklist'] ?? []) as $item): ?>
                    <a class="list-group-item list-group-item-action d-flex align-items-center gap-2" href="<?= $e($item['link']) ?>">
                        <i class="ti ti-<?= !empty($item['ok']) ? 'circle-check text-success' : 'circle-dashed text-secondary' ?>" aria-hidden="true"></i>
                        <span><?= $e($item['text']) ?></span>
                        <span class="visually-hidden"><?= !empty($item['ok']) ? '(erledigt)' : '(offen)' ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

<?php elseif ($cs === 'queue'): ?>
    <?php $result = (array) ($cd['result'] ?? []); $forums = (array) ($cd['forums'] ?? []); ?>
    <?php if ($failure($result) !== null): ?>
        <div class="alert alert-warning"><?= $e($failure($result)) ?></div>
    <?php elseif (($result['items'] ?? []) === []): ?>
        <div class="empty"><p class="empty-title">Keine Beiträge in der Warteschlange.</p></div>
    <?php else: ?>
        <div class="vstack gap-3">
        <?php foreach ((array) $result['items'] as $post): ?>
            <div class="card"><div class="card-body">
                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                    <div>
                        <strong><?= $e($post['topic_title'] ?? '') ?></strong>
                        <span class="text-secondary">· <?= $e($forums[(int) ($post['forum_id'] ?? 0)] ?? 'Forum') ?> · Mitglied #<?= (int) ($post['user_id'] ?? 0) ?> · <?= $e($date($post['created_at'] ?? '')) ?></span>
                        <?php if (!empty($post['is_first_post'])): ?><span class="badge bg-azure-lt">Neues Thema</span><?php endif; ?>
                    </div>
                </div>
                <pre class="bg-body-tertiary rounded p-2 mb-3 text-wrap overflow-auto"><?= $e($post['body'] ?? '') ?></pre>
                <div class="d-flex flex-wrap gap-2">
                    <?= $formOpen('approve_post', ['id' => (int) $post['id']]) ?>
                        <button class="btn btn-success" type="submit"><i class="ti ti-check me-1" aria-hidden="true"></i>Freigeben</button>
                    </form>
                    <?= $formOpen('reject_post', ['id' => (int) $post['id']]) ?>
                        <label class="visually-hidden" for="rj-<?= (int) $post['id'] ?>">Grund der Ablehnung</label>
                        <input class="form-control" id="rj-<?= (int) $post['id'] ?>" type="text" name="reason" maxlength="500" placeholder="Grund (Pflicht)" required>
                        <button class="btn btn-outline-danger" type="submit"><i class="ti ti-x me-1" aria-hidden="true"></i>Ablehnen</button>
                    </form>
                </div>
            </div></div>
        <?php endforeach; ?>
        </div>
        <?= $more($result['next'] ?? null) ?>
    <?php endif; ?>

<?php elseif ($cs === 'reports'): ?>
    <?php $result = (array) ($cd['result'] ?? []); $detail = $cd['detail'] ?? null; $forums = (array) ($cd['forums'] ?? []); ?>
    <div class="mb-3 d-flex flex-wrap gap-2" role="group" aria-label="Zielart">
        <a class="btn btn-sm<?= ($cd['type'] ?? '') === '' ? ' btn-primary' : '' ?>" href="<?= $e(CommunityAdminModule::url('reports')) ?>">Alle</a>
        <?php foreach (['post' => 'Beiträge', 'topic' => 'Themen', 'user' => 'Mitglieder'] as $type => $label): ?>
            <a class="btn btn-sm<?= ($cd['type'] ?? '') === $type ? ' btn-primary' : '' ?>" href="<?= $e(CommunityAdminModule::url('reports', ['type' => $type])) ?>"><?= $e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <?php if (is_array($detail)): ?>
        <div class="card mb-4"><div class="card-header"><h3 class="card-title">Meldungen zu <?= $e($detail['target_type']) ?> #<?= (int) $detail['target_id'] ?></h3></div>
        <div class="card-body">
            <?php if ($failure($detail) !== null): ?>
                <div class="alert alert-warning mb-0"><?= $e($failure($detail)) ?></div>
            <?php else: ?>
                <ul class="list-unstyled vstack gap-2">
                <?php foreach ((array) ($detail['items'] ?? []) as $report): ?>
                    <li><span class="badge bg-secondary-lt"><?= $e($report['reason'] ?? '') ?></span> Mitglied #<?= (int) ($report['reporter_id'] ?? 0) ?> · <?= $e($date($report['created_at'] ?? '')) ?>
                        <?php if (!empty($report['message'])): ?><div class="text-secondary"><?= $e($report['message']) ?></div><?php endif; ?></li>
                <?php endforeach; ?>
                </ul>
                <?php $firstId = (int) (($detail['items'][0]['id'] ?? 0)); $lastId = (int) ((end($detail['items']))['id'] ?? 0); ?>
                <?= $formOpen('resolve_report', ['id' => $firstId, 'up_to' => $lastId]) ?>
                    <label class="visually-hidden" for="rr-status">Ergebnis</label>
                    <select class="form-select w-auto" id="rr-status" name="status">
                        <option value="valid">Berechtigt (Maßnahme ergriffen)</option>
                        <option value="resolved">Erledigt</option>
                        <option value="invalid">Unbegründet</option>
                    </select>
                    <label class="visually-hidden" for="rr-note">Notiz</label>
                    <input class="form-control w-auto" id="rr-note" type="text" name="reason" maxlength="500" placeholder="Notiz (optional)">
                    <button class="btn btn-primary" type="submit">Alle erledigen</button>
                </form>
            <?php endif; ?>
        </div></div>
    <?php endif; ?>
    <?php if ($failure($result) !== null): ?>
        <div class="alert alert-warning"><?= $e($failure($result)) ?></div>
    <?php elseif (($result['items'] ?? []) === []): ?>
        <div class="empty"><p class="empty-title">Keine offenen Meldungen.</p></div>
    <?php else: ?>
        <div class="card"><div class="table-responsive"><table class="table table-vcenter card-table">
            <thead><tr><th scope="col">Ziel</th><th scope="col">Forum</th><th scope="col">Meldungen</th><th scope="col">Gründe</th><th scope="col">Seit</th><th scope="col"><span class="visually-hidden">Aktion</span></th></tr></thead>
            <tbody>
            <?php foreach ((array) $result['items'] as $bundle): ?>
                <tr>
                    <td><?= $e(['post' => 'Beitrag', 'topic' => 'Thema', 'user' => 'Mitglied'][$bundle['target_type']] ?? $bundle['target_type']) ?> #<?= (int) $bundle['target_id'] ?></td>
                    <td><?= $e($forums[(int) ($bundle['forum_id'] ?? 0)] ?? '–') ?></td>
                    <td><?= (int) $bundle['reports'] ?> (<?= (int) $bundle['reporters'] ?> Melder)</td>
                    <td><?php foreach ((array) ($bundle['reasons'] ?? []) as $reason => $n): ?><span class="badge bg-secondary-lt me-1"><?= $e(is_int($reason) ? ($n['reason'] ?? '') : $reason) ?><?= is_int($n) ? ' ×' . $n : '' ?></span><?php endforeach; ?></td>
                    <td><?= $e($date($bundle['first_at'])) ?></td>
                    <td class="text-end"><a class="btn btn-sm" href="<?= $e(CommunityAdminModule::url('reports', ['target_type' => $bundle['target_type'], 'target_id' => $bundle['target_id'], 'type' => $cd['type'] ?? ''])) ?>">Prüfen</a></td>
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
                <option value="">– wählen –</option>
                <?php foreach ($forums as $id => $forum): ?>
                    <option value="<?= (int) $id ?>"<?= (int) $id === (int) ($cd['forum_id'] ?? 0) ? ' selected' : '' ?>><?= $e(str_repeat('– ', (int) ($forum['depth'] ?? 0)) . ($forum['title'] ?? '')) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="col-md-3"><label class="form-label" for="tp-status">Status</label>
            <select class="form-select" id="tp-status" name="status">
                <?php foreach (['published' => 'Veröffentlicht', 'pending' => 'Wartend', 'hidden' => 'Ausgeblendet'] as $value => $label): ?>
                    <option value="<?= $e($value) ?>"<?= $value === ($cd['status'] ?? '') ? ' selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="col-auto"><button class="btn btn-primary" type="submit">Anzeigen</button></div>
    </form>
    <?php if ((int) ($cd['forum_id'] ?? 0) === 0): ?>
        <div class="empty"><p class="empty-title">Forum wählen, um die Themen zu sehen.</p></div>
    <?php elseif ($items === []): ?>
        <div class="empty"><p class="empty-title">Keine Themen mit diesem Status.</p></div>
    <?php else: ?>
        <?= $formOpen('bulk') ?>
        <div class="card w-100 mb-3"><div class="table-responsive"><table class="table table-vcenter card-table">
            <thead><tr><th scope="col"><span class="visually-hidden">Auswahl</span></th><th scope="col">Thema</th><th scope="col">Antworten</th><th scope="col">Letzter Beitrag</th><th scope="col">Zustand</th></tr></thead>
            <tbody>
            <?php foreach ($items as $topic): ?>
                <tr>
                    <td><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $topic['id'] ?>" aria-label="Thema <?= (int) $topic['id'] ?> auswählen"></td>
                    <td><?= $e($topic['title'] ?? '') ?></td>
                    <td><?= (int) ($topic['reply_count'] ?? 0) ?></td>
                    <td><?= $e($date($topic['last_post_at'] ?? '')) ?></td>
                    <td><?php if (!empty($topic['is_pinned'])): ?><span class="badge bg-azure-lt">angeheftet</span> <?php endif; ?><?php if (!empty($topic['is_locked'])): ?><span class="badge bg-orange-lt">geschlossen</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
        <div class="d-flex flex-wrap gap-2 align-items-start w-100">
            <label class="visually-hidden" for="bk-op">Aktion</label>
            <select class="form-select w-auto" id="bk-op" name="operation">
                <?php foreach (['topic.lock' => 'Schließen', 'topic.unlock' => 'Öffnen', 'topic.pin' => 'Anheften', 'topic.unpin' => 'Anheften aufheben', 'topic.hide' => 'Ausblenden', 'topic.restore' => 'Wiederherstellen', 'topic.approve' => 'Freigeben', 'topic.reject' => 'Ablehnen', 'topic.move' => 'Verschieben …', 'topic.delete' => 'Löschen'] as $value => $label): ?>
                    <option value="<?= $e($value) ?>"><?= $e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="visually-hidden" for="bk-target">Zielforum (nur bei „Verschieben“)</label>
            <select class="form-select w-auto" id="bk-target" name="target_forum_id">
                <option value="">Zielforum …</option>
                <?php foreach ($forums as $id => $forum): ?><option value="<?= (int) $id ?>"><?= $e($forum['title'] ?? '') ?></option><?php endforeach; ?>
            </select>
            <label class="visually-hidden" for="bk-reason">Grund</label>
            <input class="form-control w-auto" id="bk-reason" type="text" name="reason" maxlength="500" placeholder="Grund">
            <button class="btn btn-primary" type="submit">Auf Auswahl anwenden</button>
        </div>
        </form>
        <?= $more($cd['next'] ?? null, ['forum_id' => $cd['forum_id'] ?? 0, 'status' => $cd['status'] ?? '']) ?>
    <?php endif; ?>

<?php elseif ($cs === 'sanctions'): ?>
    <?php $result = (array) ($cd['result'] ?? []); ?>
    <div class="card mb-4"><div class="card-header"><h3 class="card-title">Mitglied sperren</h3></div><div class="card-body">
        <?= $formOpen('ban') ?>
            <div><label class="form-label" for="bn-user">Mitglieds-ID</label><input class="form-control" id="bn-user" type="number" min="1" name="user_id" required></div>
            <div><label class="form-label" for="bn-scope">Umfang</label>
                <select class="form-select" id="bn-scope" name="scope"><option value="write">Nur Schreiben</option><option value="full">Vollsperre</option></select></div>
            <div><label class="form-label" for="bn-days">Dauer</label>
                <select class="form-select" id="bn-days" name="days">
                    <?php foreach ((array) ($cd['ban_days'] ?? []) as $days): ?><option value="<?= (int) $days ?>"<?= $days === 7 ? ' selected' : '' ?>><?= $days === 0 ? 'Dauerhaft' : $days . ' Tag(e)' ?></option><?php endforeach; ?>
                </select></div>
            <div class="flex-grow-1"><label class="form-label" for="bn-reason">Grund (Pflicht, für das Mitglied sichtbar)</label><input class="form-control" id="bn-reason" type="text" name="reason" maxlength="500" required></div>
            <div class="flex-grow-1"><label class="form-label" for="bn-note">Interne Notiz</label><input class="form-control" id="bn-note" type="text" name="note" maxlength="2000"></div>
            <div class="align-self-end"><button class="btn btn-danger" type="submit">Sperren</button></div>
        </form>
    </div></div>
    <?php if ($failure($result) !== null): ?>
        <div class="alert alert-warning"><?= $e($failure($result)) ?></div>
    <?php elseif (($result['items'] ?? []) === []): ?>
        <div class="empty"><p class="empty-title">Keine aktiven Sperren.</p></div>
    <?php else: ?>
        <div class="card"><div class="table-responsive"><table class="table table-vcenter card-table">
            <thead><tr><th scope="col">Mitglied</th><th scope="col">Umfang</th><th scope="col">Grund</th><th scope="col">Seit</th><th scope="col">Bis</th><th scope="col"><span class="visually-hidden">Aktion</span></th></tr></thead>
            <tbody>
            <?php foreach ((array) $result['items'] as $ban): ?>
                <tr>
                    <td>#<?= (int) $ban['user_id'] ?></td>
                    <td><?= $ban['scope'] === 'full' ? 'Vollsperre' : 'Nur Schreiben' ?></td>
                    <td><?= $e($ban['reason']) ?></td>
                    <td><?= $e($date($ban['starts_at'])) ?></td>
                    <td><?= !empty($ban['ends_at']) ? $e($date($ban['ends_at'])) : 'dauerhaft' ?></td>
                    <td class="text-end"><?= $formOpen('lift_ban', ['id' => (int) $ban['id']]) ?><button class="btn btn-sm" type="submit">Aufheben</button></form></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
        <?= $more($result['next'] ?? null) ?>
    <?php endif; ?>

<?php elseif ($cs === 'log'): ?>
    <?php $result = (array) ($cd['result'] ?? []); $filter = (array) ($cd['filter'] ?? []); ?>
    <form method="get" class="row g-2 mb-3 align-items-end">
        <div class="col-md-4"><label class="form-label" for="lg-action">Aktion</label>
            <select class="form-select" id="lg-action" name="action">
                <option value="">Alle</option>
                <?php foreach ((array) ($cd['actions'] ?? []) as $action): ?><option value="<?= $e($action) ?>"<?= $action === ($filter['action'] ?? '') ? ' selected' : '' ?>><?= $e($action) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-3"><label class="form-label" for="lg-actor">Moderator (ID)</label><input class="form-control" id="lg-actor" type="number" min="1" name="actor_id" value="<?= (int) ($filter['actor_id'] ?? 0) ?: '' ?>"></div>
        <div class="col-auto"><button class="btn btn-primary" type="submit">Filtern</button></div>
    </form>
    <?php if ($failure($result) !== null): ?>
        <div class="alert alert-warning"><?= $e($failure($result)) ?></div>
    <?php elseif (($result['items'] ?? []) === []): ?>
        <div class="empty"><p class="empty-title">Keine Einträge.</p></div>
    <?php else: ?>
        <div class="card"><div class="table-responsive"><table class="table table-vcenter card-table">
            <thead><tr><th scope="col">Zeit</th><th scope="col">Moderator</th><th scope="col">Aktion</th><th scope="col">Ziel</th><th scope="col">Grund</th></tr></thead>
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

<?php elseif ($cs === 'ranks'): ?>
    <?php $ranks = (array) ($cd['ranks'] ?? []); ?>
    <div class="card mb-4"><div class="table-responsive"><table class="table table-vcenter card-table">
        <thead><tr><th scope="col">Titel</th><th scope="col">Englisch</th><th scope="col">Ab Beiträgen</th><th scope="col">Farbe</th><th scope="col">Symbol</th><th scope="col">Reihenfolge</th><th scope="col"><span class="visually-hidden">Aktion</span></th></tr></thead>
        <tbody>
        <?php foreach ($ranks as $rank): ?>
            <tr>
                <?= $formOpen('save_rank', ['id' => (int) $rank['id']]) ?>
                <td><input class="form-control" name="title" aria-label="Titel" value="<?= $e($rank['title']) ?>" required maxlength="60"></td>
                <td><input class="form-control" name="title_en" aria-label="Titel englisch" value="<?= $e($rank['title_en'] ?? '') ?>" maxlength="60"></td>
                <td><input class="form-control" type="number" min="0" name="min_posts" aria-label="Ab Beiträgen" value="<?= $e($rank['min_posts'] ?? '') ?>"></td>
                <td><input class="form-control" name="color" aria-label="Farbe" value="<?= $e($rank['color'] ?? '') ?>" placeholder="#rrggbb" maxlength="9"></td>
                <td><input class="form-control" name="icon" aria-label="Symbol" value="<?= $e($rank['icon'] ?? '') ?>" maxlength="50"></td>
                <td><input class="form-control" type="number" min="0" name="sort_order" aria-label="Reihenfolge" value="<?= (int) $rank['sort_order'] ?>"></td>
                <td class="text-end text-nowrap"><button class="btn btn-sm btn-primary" type="submit">Speichern</button></form>
                    <?= $formOpen('delete_rank', ['id' => (int) $rank['id']]) ?><button class="btn btn-sm btn-outline-danger" type="submit">Löschen</button></form></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
    <div class="card"><div class="card-header"><h3 class="card-title">Neuer Rang</h3></div><div class="card-body">
        <?= $formOpen('save_rank') ?>
            <div><label class="form-label" for="rk-title">Titel</label><input class="form-control" id="rk-title" name="title" required maxlength="60"></div>
            <div><label class="form-label" for="rk-en">Titel (englisch)</label><input class="form-control" id="rk-en" name="title_en" maxlength="60"></div>
            <div><label class="form-label" for="rk-min">Ab Beiträgen</label><input class="form-control" id="rk-min" type="number" min="0" name="min_posts"></div>
            <div><label class="form-label" for="rk-color">Farbe</label><input class="form-control" id="rk-color" name="color" placeholder="#rrggbb" maxlength="9"></div>
            <div><label class="form-label" for="rk-icon">Symbol</label><input class="form-control" id="rk-icon" name="icon" maxlength="50"></div>
            <div><label class="form-label" for="rk-sort">Reihenfolge</label><input class="form-control" id="rk-sort" type="number" min="0" name="sort_order" value="0"></div>
            <div class="align-self-end"><button class="btn btn-primary" type="submit">Anlegen</button></div>
        </form>
    </div></div>

<?php elseif ($cs === 'settings'): ?>
    <?php
    $values = (array) ($cd['values'] ?? []);
    $labels = [
        'base_path' => 'Basis-Adresse (z. B. forum)', 'guests_can_read' => 'Gäste dürfen lesen', 'require_verified_email' => 'Schreiben nur mit bestätigter E-Mail',
        'topics_per_page' => 'Themen je Seite', 'posts_per_page' => 'Beiträge je Seite', 'title_min' => 'Titel: Mindestlänge', 'title_max' => 'Titel: Höchstlänge',
        'post_min' => 'Beitrag: Mindestlänge', 'post_max' => 'Beitrag: Höchstlänge', 'edit_window_minutes' => 'Bearbeitungsfenster (Minuten)',
        'edit_window_minutes_trusted' => 'Bearbeitungsfenster vertraute Mitglieder (Minuten)', 'rate_post_seconds' => 'Mindestabstand Beiträge (Sekunden)',
        'rate_topic_seconds' => 'Mindestabstand Themen (Sekunden)', 'new_member_approval_posts' => 'Freigabepflicht: erste Beiträge neuer Mitglieder',
        'max_pending_per_user' => 'Wartende Beiträge je Mitglied (höchstens)', 'duplicate_window_seconds' => 'Doppelte Beiträge: Fenster (Sekunden)',
        'trust_level1_posts' => 'Vertrauensstufe 1 ab Beiträgen', 'trust_level2_posts' => 'Vertrauensstufe 2 ab Beiträgen', 'unread_horizon_days' => 'Ungelesen-Horizont (Tage)',
        'use_core_antispam' => 'Core-Antispam zusätzlich nutzen', 'spam_duplicate_hours' => 'Spam: Duplikat-Fenster (Stunden)', 'ip_hash_days' => 'IP-Kennung speichern (Tage, 0 = nie)',
        'moved_stub_days' => 'Verweis nach Verschieben (Tage)', 'moderation_reason_required' => 'Moderation: Grund ist Pflicht', 'report_hide_threshold' => 'Meldungen bis zum vorläufigen Ausblenden (0 = aus)',
        'moderator_ban_max_days' => 'Moderatoren: längste Sperre (Tage)', 'notify_mail' => 'Benachrichtigungen auch per E-Mail', 'attachment_max_kb' => 'Anhang: Höchstgröße (KB)',
    ];
    $groups = [
        'Allgemein' => ['base_path', 'guests_can_read', 'require_verified_email', 'topics_per_page', 'posts_per_page', 'unread_horizon_days', 'moved_stub_days', 'notify_mail'],
        'Inhalte' => ['title_min', 'title_max', 'post_min', 'post_max', 'edit_window_minutes', 'edit_window_minutes_trusted', 'attachment_max_kb'],
        'Schutz vor Spam' => ['rate_post_seconds', 'rate_topic_seconds', 'duplicate_window_seconds', 'spam_duplicate_hours', 'new_member_approval_posts', 'max_pending_per_user', 'use_core_antispam', 'ip_hash_days'],
        'Moderation und Vertrauen' => ['moderation_reason_required', 'report_hide_threshold', 'moderator_ban_max_days', 'trust_level1_posts', 'trust_level2_posts'],
    ];
    $limitLabels = ['posts_per_day' => 'Beiträge/Tag', 'topics_per_hour' => 'Themen/Std.', 'links_per_post' => 'Links/Beitrag', 'images_per_post' => 'Bilder/Beitrag', 'attachments_per_post' => 'Anhänge/Beitrag', 'attachments_per_day' => 'Anhänge/Tag'];
    $permLabels = ['read' => 'Lesen', 'create_topic' => 'Thema anlegen', 'reply' => 'Antworten', 'edit_own' => 'Eigene bearbeiten', 'delete_own' => 'Eigene löschen', 'upload' => 'Hochladen', 'poll' => 'Umfrage', 'vote' => 'Abstimmen', 'react' => 'Reagieren', 'mark_solution' => 'Lösung markieren', 'bypass_approval' => 'Ohne Freigabe', 'bypass_slow_mode' => 'Ohne Langsamen Modus'];
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
                        <?php else: ?>
                            <label class="form-label" for="s_<?= $e($key) ?>"><?= $e($labels[$key] ?? $key) ?></label>
                            <input class="form-control" id="s_<?= $e($key) ?>" name="s_<?= $e($key) ?>" <?= $def['type'] === 'int' ? 'type="number" min="' . (int) $def['min'] . '" max="' . (int) $def['max'] . '"' : 'type="text" pattern="[a-z0-9][a-z0-9\-]{0,39}"' ?> value="<?= $e($values[$key] ?? '') ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div></div></div>
        <?php endforeach; ?>

        <div class="card"><div class="card-header"><h3 class="card-title">Grenzen je Vertrauensstufe</h3></div>
            <div class="card-body"><p class="text-secondary">−1 = unbegrenzt, 0 = keine.</p></div>
            <div class="table-responsive"><table class="table table-vcenter card-table">
                <thead><tr><th scope="col">Stufe</th><?php foreach ($limitLabels as $label): ?><th scope="col"><?= $e($label) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach (Settings::TRUST_LEVELS as $level): ?>
                    <tr><th scope="row"><?= (int) $level ?></th>
                    <?php foreach ($limitLabels as $field => $label): ?>
                        <td><input class="form-control" type="number" min="-1" max="<?= (int) Settings::LIMIT_MAX ?>" name="limit[<?= (int) $level ?>][<?= $e($field) ?>]" aria-label="<?= $e($label . ', Stufe ' . $level) ?>" value="<?= (int) ($values['trust_limits'][$level][$field] ?? 0) ?>"></td>
                    <?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div></div>

        <div class="card"><div class="card-header"><h3 class="card-title">Standardrechte (Foren ohne eigene Rechte)</h3></div>
            <div class="table-responsive"><table class="table table-vcenter card-table">
                <thead><tr><th scope="col">Recht</th><th scope="col">Gast</th><th scope="col">Mitglied</th></tr></thead>
                <tbody>
                <?php foreach ($permLabels as $perm => $label): ?>
                    <tr><th scope="row"><?= $e($label) ?></th>
                    <?php foreach (Settings::PERMISSION_SUBJECTS as $subject): ?>
                        <td><input class="form-check-input" type="checkbox" name="perm[<?= $e($subject) ?>][<?= $e($perm) ?>]" value="1" aria-label="<?= $e($label . ' – ' . ($subject === 'guest' ? 'Gast' : 'Mitglied')) ?>"<?= !empty($values['default_permissions'][$subject][$perm]) ? ' checked' : '' ?>></td>
                    <?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div></div>

        <div class="card"><div class="card-header"><h3 class="card-title">Reaktionen und Anhänge</h3></div><div class="card-body">
            <fieldset class="mb-3"><legend class="form-label">Erlaubte Reaktionen</legend>
                <?php foreach (Settings::REACTION_KEYS as $reaction): ?>
                    <label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="reaction_set[]" value="<?= $e($reaction) ?>"<?= in_array($reaction, (array) ($values['reaction_set'] ?? []), true) ? ' checked' : '' ?>><span class="form-check-label"><?= $e($reaction) ?></span></label>
                <?php endforeach; ?>
            </fieldset>
            <fieldset><legend class="form-label">Erlaubte Dateitypen</legend>
                <?php foreach (Settings::ATTACHMENT_TYPES as $ext): ?>
                    <label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="attachment_types[<?= $e($ext) ?>]" value="1"<?= !empty($values['attachment_types'][$ext]) ? ' checked' : '' ?>><span class="form-check-label"><?= $e($ext) ?></span></label>
                <?php endforeach; ?>
            </fieldset>
        </div></div>

        <div class="card"><div class="card-header"><h3 class="card-title">Wortfilter</h3></div><div class="card-body"><div class="row g-3">
            <?php foreach (['block' => 'Blockieren (Beitrag wird abgelehnt)', 'review' => 'Prüfen (Beitrag wartet auf Freigabe)', 'replace' => 'Ersetzen'] as $list => $label): ?>
                <div class="col-md-4"><label class="form-label" for="wf_<?= $e($list) ?>"><?= $e($label) ?> – ein Eintrag je Zeile</label>
                    <textarea class="form-control" id="wf_<?= $e($list) ?>" name="wf_<?= $e($list) ?>" rows="6"><?= $e(implode("\n", (array) ($values['word_filter'][$list] ?? []))) ?></textarea></div>
            <?php endforeach; ?>
            <div class="col-md-4"><label class="form-label" for="wf_replacement">Ersatztext</label><input class="form-control" id="wf_replacement" name="wf_replacement" value="<?= $e($values['word_filter']['replacement'] ?? '***') ?>" maxlength="20"></div>
        </div></div></div>

        <div><button class="btn btn-primary" type="submit"><i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Einstellungen speichern</button></div>
    </form>
<?php endif; ?>
    </div>
</div>
