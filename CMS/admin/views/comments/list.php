<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Comments – Listenansicht (Moderation)
 *
 * Erwartet: $data (aus CommentsModule::getListData())
 *           $alert, $csrfToken
 */

$comments = $data['comments'] ?? [];
$counts   = $data['counts'] ?? [];
$status   = $data['status'] ?? 'all';
$tabs = $data['tabs'] ?? [];
$summaryCards = is_array($data['summaryCards'] ?? null) ? $data['summaryCards'] : [];
$filters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
$filterMeta = is_array($data['filterMeta'] ?? null) ? $data['filterMeta'] : [];
$activeFilters = array_values(array_filter((array) ($filterMeta['active_filters'] ?? []), static fn (mixed $entry): bool => is_array($entry)));
$contentView = (string) ($data['contentView'] ?? ($filters['content_view'] ?? 'excerpt'));
$showFullCommentContent = $contentView === 'full';
$canModerate = (bool)($data['canModerate'] ?? false);
$canDelete = (bool)($data['canDelete'] ?? false);
$canBulkActions = $canModerate || $canDelete;
$showActionColumn = $canModerate || $canDelete;
$commentsBaseUrl = '/admin/comments';

$renderCommentIcon = static function (string $icon): string {
    return match ($icon) {
        'alert' => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4"/><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z"/><path d="M12 16h.01"/>',
        'check' => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10"/>',
        'ban' => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M5.7 5.7l12.6 12.6"/>',
        'trash' => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7l16 0"/><path d="M10 11l0 6"/><path d="M14 11l0 6"/><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12"/><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3"/>',
        default => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 20l1.3 -3.9c-2.324 -3.437 -1.426 -7.872 2.1 -10.374c3.526 -2.501 8.59 -2.296 11.845 .48c3.255 2.777 3.695 7.266 1.029 10.501c-2.666 3.235 -7.615 4.215 -11.574 2.293l-4.7 1"/>',
    };
};

$actionClass = static function (string $variant): string {
    return match ($variant) {
        'warning' => 'dropdown-item text-warning',
        'danger' => 'dropdown-item text-danger',
        default => 'dropdown-item',
    };
};
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">Seiten &amp; Beiträge</div>
                <h2 class="page-title mb-1">Kommentare</h2>
                <div class="content-listing-header__meta">
                    <span><?php echo (int) ($counts['total'] ?? 0); ?> gesamt</span>
                    <span><?php echo (int) ($counts['pending'] ?? 0); ?> offen</span>
                    <span><?php echo (int) ($counts['approved'] ?? 0); ?> freigegeben</span>
                    <span><?php echo (int) ($counts['spam'] ?? 0); ?> Spam</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">

        <?php
        $alertData = $alert ?? [];
        $alertDismissible = true;
        $alertMarginClass = 'mb-3';
        require __DIR__ . '/../partials/flash-alert.php';
        ?>

        <div class="card content-listing-card">
            <div class="card-header content-listing-toolbar">
                <div class="content-listing-toolbar__label">Status</div>
                <ul class="nav nav-tabs card-header-tabs">
                    <?php foreach ($tabs as $tab): ?>
                        <?php
                        $tabStatus = (string)($tab['status'] ?? 'all');
                        $tabCount = (int)($tab['count'] ?? 0);
                        $tabUrl = (string)($tab['url'] ?? $commentsBaseUrl);
                        $tabBadgeClass = (string)($tab['badge_class'] ?? 'bg-secondary');
                        ?>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $status === $tabStatus ? 'active' : ''; ?>"
                               href="<?php echo htmlspecialchars($tabUrl); ?>">
                                <?php echo htmlspecialchars((string)($tab['label'] ?? '')); ?>
                                <?php if ($tabCount > 0): ?>
                                    <span class="badge <?php echo htmlspecialchars($tabBadgeClass); ?> ms-1"><?php echo $tabCount; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php if ($status === 'trash' && $canDelete): ?>
                <div class="card-body py-2 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="text-secondary small"><?php echo (int) ($counts['trash'] ?? 0); ?> Kommentar(e) im Papierkorb.</div>
                    <?php if ((int) ($counts['trash'] ?? 0) > 0): ?>
                        <form method="post"
                              action="<?php echo htmlspecialchars($commentsBaseUrl); ?>"
                              class="m-0"
                              data-confirm-title="Papierkorb leeren"
                              data-confirm-message="Alle Kommentare im Papierkorb endgültig löschen? Dies kann nicht rückgängig gemacht werden."
                              data-confirm-text="Papierkorb leeren"
                              data-confirm-class="btn-danger"
                              data-confirm-status-class="bg-danger">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="action" value="empty_trash">
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon me-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><?php echo $renderCommentIcon('trash'); ?></svg>
                                Papierkorb leeren
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="card-body border-bottom">
                <div class="content-listing-toolbar__label">Filter &amp; Suche</div>
                <form method="get" action="<?php echo htmlspecialchars($commentsBaseUrl); ?>" class="content-listing-filters comments-listing-filters">
                    <div class="content-listing-filters__search">
                        <label for="comment-search" class="form-label mb-0 small text-secondary">Schnellsuche</label>
                        <input
                            type="search"
                            class="form-control form-control-sm"
                            id="comment-search"
                            name="q"
                            maxlength="80"
                            value="<?php echo htmlspecialchars((string) ($filters['query'] ?? ''), ENT_QUOTES); ?>"
                            placeholder="Autor, E-Mail, Kommentartext oder Beitrag suchen">
                    </div>
                    <div class="content-listing-filters__group">
                        <label for="comment-author-scope" class="form-label mb-0 small text-secondary">Autorentyp</label>
                        <select class="form-select form-select-sm" id="comment-author-scope" name="author_scope">
                            <?php foreach ((array) ($filterMeta['author_scope_options'] ?? []) as $scopeValue => $scopeLabel): ?>
                                <option value="<?php echo htmlspecialchars((string) $scopeValue, ENT_QUOTES); ?>" <?php echo ((string) ($filters['author_scope'] ?? 'all') === (string) $scopeValue) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $scopeLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="content-listing-filters__group">
                        <label for="comment-link-scope" class="form-label mb-0 small text-secondary">Beitragsbezug</label>
                        <select class="form-select form-select-sm" id="comment-link-scope" name="link_scope">
                            <?php foreach ((array) ($filterMeta['link_scope_options'] ?? []) as $scopeValue => $scopeLabel): ?>
                                <option value="<?php echo htmlspecialchars((string) $scopeValue, ENT_QUOTES); ?>" <?php echo ((string) ($filters['link_scope'] ?? 'all') === (string) $scopeValue) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $scopeLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="content-listing-filters__group">
                        <label for="comment-content-view" class="form-label mb-0 small text-secondary">Kommentarinhalt</label>
                        <select class="form-select form-select-sm" id="comment-content-view" name="content_view">
                            <?php foreach ((array) ($filterMeta['content_view_options'] ?? []) as $scopeValue => $scopeLabel): ?>
                                <option value="<?php echo htmlspecialchars((string) $scopeValue, ENT_QUOTES); ?>" <?php echo ($contentView === (string) $scopeValue) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $scopeLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="content-listing-filters__actions">
                        <?php if ($status !== 'all'): ?>
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars((string) $status, ENT_QUOTES); ?>">
                        <?php endif; ?>
                        <button type="submit" class="btn btn-sm btn-primary">Filtern</button>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                        <?php if ($activeFilters !== []): ?>
                            <span class="text-secondary small"><?php echo (int) ($filterMeta['active_filter_count'] ?? count($activeFilters)); ?> aktive Filter:</span>
                            <?php foreach ($activeFilters as $activeFilter): ?>
                                <span class="badge bg-secondary-lt text-secondary-emphasis"><?php echo htmlspecialchars((string) ($activeFilter['label'] ?? 'Filter')); ?>: <?php echo htmlspecialchars((string) ($activeFilter['value'] ?? '')); ?></span>
                            <?php endforeach; ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo htmlspecialchars($status === 'all' ? $commentsBaseUrl : $commentsBaseUrl . '?status=' . rawurlencode((string) $status), ENT_QUOTES); ?>">Filter zurücksetzen</a>
                        <?php else: ?>
                            <span class="text-secondary small">Keine zusätzlichen Filter aktiv. Status-Tabs und Schnellfilter lassen sich kombinieren.</span>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Bulk-Aktionen -->
            <?php if ($canBulkActions): ?>
                <div class="card-body py-2 d-none content-listing-bulkbar" id="bulkBar">
                    <form method="post" id="bulkForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="bulk">
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-secondary"><strong id="selectedCount">0</strong> ausgewählt</span>
                            <label for="bulkActionComments" class="form-label mb-0 small text-secondary">Aktion</label>
                            <select name="bulk_action" id="bulkActionComments" class="form-select form-select-sm w-auto">
                                <option value="">Aktion wählen…</option>
                                <?php if ($canModerate): ?>
                                    <option value="approve">Freigeben</option>
                                    <option value="spam">Als Spam</option>
                                    <option value="trash">Papierkorb</option>
                                <?php endif; ?>
                                <?php if ($canDelete): ?>
                                    <option value="delete">Endgültig löschen</option>
                                <?php endif; ?>
                            </select>
                            <button type="submit" id="bulkSubmitComments" class="btn btn-sm btn-primary" disabled>Anwenden</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Tabelle -->
            <div class="table-responsive comments-table-responsive">
                <table class="table table-vcenter card-table content-listing-table">
                    <thead>
                        <tr>
                            <?php if ($canBulkActions): ?>
                                <th class="w-1"><input class="form-check-input" type="checkbox" id="selectAll" aria-label="Alle Kommentare auswählen"></th>
                            <?php endif; ?>
                            <th>Autor</th>
                            <th>Kommentar</th>
                            <th>Beitrag</th>
                            <th>Status</th>
                            <th>Datum</th>
                            <?php if ($showActionColumn): ?>
                                <th class="w-1"></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($comments)): ?>
                        <?php
                        $emptyStateColspan = 5 + ($canBulkActions ? 1 : 0) + ($showActionColumn ? 1 : 0);
                        $emptyStateMessage = 'Keine Kommentare in dieser Ansicht.';
                        $emptyStateSubtitle = 'Passen Sie den Statusfilter an oder warten Sie auf neue Moderationsfälle.';
                        $emptyStateIcon = 'comments';
                        require __DIR__ . '/../partials/empty-table-row.php';
                        ?>
                    <?php else: ?>
                        <?php foreach ($comments as $c): ?>
                            <?php
                            $cId        = (int)($c['id'] ?? 0);
                            $cAuthor    = (string)($c['author'] ?? '');
                            $cEmail     = (string)($c['author_email'] ?? '');
                            $cStatus    = (string)($c['status'] ?? '');
                            $cPostTitle = (string)($c['post_title'] ?? '');
                            $cPostUrl   = (string)($c['post_url'] ?? '');
                            ?>
                            <tr class="content-listing-table__row">
                                <?php if ($canBulkActions): ?>
                                    <td>
                                        <input class="form-check-input row-check" type="checkbox" name="ids[]" value="<?php echo $cId; ?>" form="bulkForm" aria-label="Kommentar #<?php echo $cId; ?> auswählen">
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="avatar avatar-sm me-2"><?php echo htmlspecialchars((string)($c['author_initials'] ?? 'KO')); ?></span>
                                        <div>
                                            <div class="font-weight-medium"><?php echo htmlspecialchars($cAuthor); ?></div>
                                            <div class="text-secondary small"><?php echo htmlspecialchars($cEmail); ?></div>
                                            <div class="text-secondary small"><?php echo htmlspecialchars((string) ($c['author_scope_label'] ?? '')); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($showFullCommentContent): ?>
                                        <div class="small text-wrap" style="max-width: 420px; white-space: pre-wrap; overflow-wrap: anywhere;">
                                            <?php echo nl2br(htmlspecialchars((string)($c['content'] ?? ''), ENT_QUOTES, 'UTF-8')); ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-truncate" style="max-width:300px;"><?php echo htmlspecialchars((string)($c['excerpt'] ?? '')); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($cPostTitle && !empty($c['has_post_link'])): ?>
                                        <a href="<?php echo htmlspecialchars($cPostUrl); ?>" class="text-reset" target="_blank" rel="noopener noreferrer">
                                            <?php echo htmlspecialchars($cPostTitle); ?>
                                        </a>
                                        <?php if (empty($c['has_linked_post'])): ?>
                                            <div class="text-warning small">Beitragsdatensatz fehlt</div>
                                        <?php endif; ?>
                                    <?php elseif ($cPostTitle): ?>
                                        <span class="text-reset"><?php echo htmlspecialchars($cPostTitle); ?></span>
                                        <?php if (empty($c['has_linked_post'])): ?>
                                            <div class="text-warning small">Beitragsdatensatz fehlt</div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-secondary">–</span>
                                        <?php if (empty($c['has_linked_post'])): ?>
                                            <div class="text-warning small">verwaist</div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?php echo htmlspecialchars((string)($c['status_badge'] ?? 'bg-secondary-lt')); ?>">
                                        <?php echo htmlspecialchars((string)($c['status_label'] ?? $cStatus)); ?>
                                    </span>
                                </td>
                                <td class="text-secondary"><?php echo htmlspecialchars((string)($c['formatted_date'] ?? '–')); ?></td>
                                <?php if ($showActionColumn): ?>
                                    <td class="table-actions content-listing-table__actions-cell">
                                        <div class="dropdown">
                                            <button type="button" class="btn btn-ghost-secondary btn-icon btn-sm comment-row-menu-trigger" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Aktionen öffnen">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/><circle cx="12" cy="5" r="1"/></svg>
                                            </button>
                                            <div class="dropdown-menu dropdown-menu-end">
                                                <?php foreach ((array) ($c['actions'] ?? []) as $action): ?>
                                                    <?php
                                                    $actionType = (string) ($action['type'] ?? 'status');
                                                    $buttonClass = $actionClass((string) ($action['variant'] ?? 'default'));
                                                    $iconName = (string) ($action['icon'] ?? 'check');
                                                    $actionCommentId = (int) ($action['comment_id'] ?? $cId);
                                                    $actionStatus = (string) ($action['status'] ?? '');
                                                    $isDeleteAction = $actionType === 'delete';
                                                    ?>
                                                    <form method="post"
                                                          class="m-0"
                                                          <?php if ($isDeleteAction): ?>data-confirm-title="Kommentar löschen" data-confirm-message="Kommentar wirklich löschen? Dies kann nicht rückgängig gemacht werden." data-confirm-text="Löschen" data-confirm-class="btn-danger" data-confirm-status-class="bg-danger"<?php endif; ?>>
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                        <input type="hidden" name="action" value="<?php echo $isDeleteAction ? 'delete' : 'status'; ?>">
                                                        <input type="hidden" name="id" value="<?php echo $actionCommentId; ?>">
                                                        <?php if (!$isDeleteAction): ?>
                                                            <input type="hidden" name="new_status" value="<?php echo htmlspecialchars($actionStatus); ?>">
                                                        <?php endif; ?>
                                                        <button type="submit" class="<?php echo htmlspecialchars($buttonClass); ?> w-100 text-start">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="icon dropdown-item-icon<?php echo str_contains($buttonClass, 'text-') ? ' ' . trim(str_replace('dropdown-item', '', $buttonClass)) : ''; ?>" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><?php echo $renderCommentIcon($iconName); ?></svg>
                                                            <?php echo htmlspecialchars((string) ($action['label'] ?? 'Aktion')); ?>
                                                        </button>
                                                    </form>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

