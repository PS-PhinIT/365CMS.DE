<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Posts – Listenansicht
 *
 * Erwartet: $data (aus PostsModule::getListData())
 *           $alert (Session-Alert)
 */

$posts      = is_array($data['posts'] ?? null) ? $data['posts'] : [];
$categories = is_array($data['categories'] ?? null) ? $data['categories'] : [];
$counts     = is_array($data['counts'] ?? null) ? $data['counts'] : [];
$filter     = (string)($data['filter'] ?? '');
$catFilter  = (int)($data['catFilter'] ?? 0);
$search     = (string)($data['search'] ?? '');
$statusLabels = [
    'published' => ['Veröffentlicht', 'bg-green-lt text-green'],
    'scheduled' => ['Geplant', 'bg-azure-lt text-azure'],
    'draft'     => ['Entwurf', 'bg-yellow-lt text-yellow'],
    'private'   => ['Privat', 'bg-purple-lt text-purple'],
];
?>

<?php
$postsHasActiveFilters = ($filter !== '') || ($catFilter > 0) || ($search !== '');
$postsAuthorKeys = [];
foreach ($posts as $postAuthorRow) {
    $postAuthorKey = strtolower(trim((string)($postAuthorRow['author'] ?? '')));
    if ($postAuthorKey !== '') {
        $postsAuthorKeys[$postAuthorKey] = true;
    }
}
$postsHasMultipleAuthors = count($postsAuthorKeys) > 1;
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">Seiten &amp; Beiträge</div>
                <h2 class="page-title mb-1">Beiträge</h2>
                <div class="content-listing-header__meta">
                    <span><?php echo (int)($counts['total'] ?? 0); ?> Einträge</span>
                    <span>Veröffentlicht: <?php echo (int)($counts['published'] ?? 0); ?></span>
                    <span>Geplant: <?php echo (int)($counts['scheduled'] ?? 0); ?></span>
                    <span>Entwurf: <?php echo (int)($counts['drafts'] ?? 0); ?></span>
                    <span>Privat: <?php echo (int)($counts['private'] ?? 0); ?></span>
                </div>
            </div>
            <a href="/admin/posts?action=edit" class="btn btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14"/><path d="M5 12l14 0"/></svg>
                Neuer Beitrag
            </a>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">

        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-3'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <div class="card content-listing-card">
            <div class="card-header content-listing-toolbar">
                <div class="content-listing-toolbar__label">Filter &amp; Suche</div>
                <form method="get" action="/admin/posts" id="postsFilterForm" class="content-listing-filters">
                    <div class="content-listing-filters__group">
                        <label for="statusFilter" class="form-label mb-0 small text-secondary">Status</label>
                        <select class="form-select form-select-sm" id="statusFilter" name="status" data-cms-call="applyFilters" data-cms-call-on="change">
                            <option value="">Alle Status</option>
                            <option value="published" <?php if ($filter === 'published') echo 'selected'; ?>>Veröffentlicht</option>
                            <option value="scheduled" <?php if ($filter === 'scheduled') echo 'selected'; ?>>Geplant</option>
                            <option value="draft" <?php if ($filter === 'draft') echo 'selected'; ?>>Entwurf</option>
                            <option value="private" <?php if ($filter === 'private') echo 'selected'; ?>>Privat</option>
                        </select>
                    </div>
                    <div class="content-listing-filters__group">
                        <label for="categoryFilter" class="form-label mb-0 small text-secondary">Kategorie</label>
                        <select class="form-select form-select-sm" id="categoryFilter" name="category" data-cms-call="applyFilters" data-cms-call-on="change">
                            <option value="0">Alle Kategorien</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo (int)$cat['id']; ?>" <?php if ($catFilter === (int)$cat['id']) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars((string) ($cat['option_label'] ?? $cat['name'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="content-listing-filters__search">
                        <label for="searchInput" class="form-label mb-0 small text-secondary">Suche</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="10" cy="10" r="7"/><path d="M21 21l-6 -6"/></svg>
                            </span>
                            <input type="text" class="form-control form-control-sm" id="searchInput" name="q" placeholder="Titel, Slug oder Autor suchen"
                                   value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                                   data-cms-enter-call="applyFilters">
                            <button type="button" class="btn btn-outline-secondary" data-cms-call="applyFilters">Suchen</button>
                        </div>
                    </div>
                    <div class="content-listing-filters__actions">
                        <?php if ($postsHasActiveFilters): ?>
                            <a href="/admin/posts" class="btn btn-sm btn-outline-secondary">Filter zurücksetzen</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="card-body py-2 d-none content-listing-bulkbar" id="bulkBarPosts">
                <form method="post" action="/admin/posts" id="bulkFormPosts" class="d-flex flex-wrap align-items-center gap-2">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="bulk">
                    <span class="text-secondary"><strong id="selectedCountPosts">0</strong> ausgewählt</span>
                    <label for="bulkActionPosts" class="form-label mb-0 small text-secondary">Status ändern</label>
                    <select name="bulk_action" id="bulkActionPosts" class="form-select form-select-sm w-auto">
                        <option value="">Status wählen…</option>
                        <option value="publish">Veröffentlichen</option>
                        <option value="draft">Als Entwurf setzen</option>
                        <option value="set_category">Kategorie(n) setzen</option>
                        <option value="clear_category">Kategorie entfernen</option>
                        <option value="set_author_display_name">Autoren-Anzeigenamen setzen</option>
                        <option value="clear_author_display_name">Autoren-Anzeigenamen zurücksetzen</option>
                    </select>
                    <select name="bulk_category_ids[]" id="bulkCategoryPosts" class="form-select form-select-sm w-auto d-none" multiple size="6" aria-label="Bulk-Kategorien auswählen">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo (int) ($cat['id'] ?? 0); ?>"><?php echo htmlspecialchars((string) ($cat['option_label'] ?? $cat['name'] ?? '')); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text"
                           name="bulk_author_display_name"
                           id="bulkAuthorDisplayNamePosts" aria-label="Neuer Anzeigename für ausgewählte Beiträge"
                           class="form-control form-control-sm w-auto d-none"
                           maxlength="150"
                           placeholder="Neuer Anzeigename für ausgewählte Beiträge">
                    <button type="submit" id="bulkSubmitPosts" class="btn btn-sm btn-primary" disabled>Anwenden</button>
                    <button type="button" id="bulkDeletePosts" class="btn btn-sm btn-danger">Löschen</button>
                    <button type="button" id="bulkCancelPosts" class="btn btn-sm btn-link p-0 text-decoration-none">Abbrechen</button>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table table-vcenter card-table content-listing-table js-content-listing-table" id="postsListingTable">
                    <thead>
                        <tr>
                            <th class="w-1">
                                <input class="form-check-input" type="checkbox" id="postsSelectAll" aria-label="Alle Beiträge auswählen">
                            </th>
                            <th class="content-listing-table__title-col">
                                <button type="button" class="content-listing-sort js-content-sort" data-sort-key="title">
                                    Titel <i class="ti ti-arrows-sort" aria-hidden="true"></i>
                                </button>
                            </th>
                            <th>Slug</th>
                            <th class="text-nowrap">Kategorie</th>
                            <th class="text-nowrap">
                                <button type="button" class="content-listing-sort js-content-sort" data-sort-key="status">
                                    Status <i class="ti ti-arrows-sort" aria-hidden="true"></i>
                                </button>
                            </th>
                            <?php if ($postsHasMultipleAuthors): ?>
                                <th class="text-nowrap">Autor</th>
                            <?php endif; ?>
                            <th class="text-nowrap">
                                <button type="button" class="content-listing-sort js-content-sort" data-sort-key="updated">
                                    Aktualisiert <i class="ti ti-arrows-sort" aria-hidden="true"></i>
                                </button>
                            </th>
                            <th class="w-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($posts)): ?>
                        <?php
                        $emptyStateColspan = $postsHasMultipleAuthors ? 8 : 7;
                        $emptyStateMessage = 'Keine Beiträge gefunden.';
                        $emptyStateSubtitle = 'Prüfen Sie Filter oder Suche – die serverseitige Liste liefert aktuell keine Einträge.';
                        $emptyStateIcon = 'file-text';
                        require __DIR__ . '/../partials/empty-table-row.php';
                        ?>
                    <?php else: ?>
                        <?php foreach ($posts as $post): ?>
                            <?php
                            $postId = (int)($post['id'] ?? 0);
                            $postTitle = trim((string)($post['title'] ?? $post['title_en'] ?? ''));
                            $postSlug = trim((string)($post['slug'] ?? $post['slug_en'] ?? ''));
                            $postSlugEn = trim((string)($post['slug_en'] ?? ''));
                            $postStatus = (string)($post['status'] ?? 'draft');
                            $publishedAt = trim((string)($post['published_at'] ?? ''));
                            if ($postStatus === 'published' && $publishedAt !== '') {
                                $publishedTimestamp = strtotime($publishedAt);
                                if ($publishedTimestamp !== false && $publishedTimestamp > time()) {
                                    $postStatus = 'scheduled';
                                }
                            }
                            [$postStatusLabel, $postStatusClass] = $statusLabels[$postStatus] ?? [$postStatus, 'bg-secondary-lt text-secondary'];
                            $postCategory = trim((string)($post['category_name'] ?? ''));
                            $postAuthor = trim((string)($post['author'] ?? ''));
                            $postUpdatedAt = trim((string)($post['updated_at'] ?? $post['created_at'] ?? ''));
                            $postUpdatedAtLabel = $postUpdatedAt;
                            if ($postUpdatedAt !== '') {
                                $postUpdatedTimestamp = strtotime($postUpdatedAt);
                                if ($postUpdatedTimestamp !== false) {
                                    $postUpdatedAtLabel = date('d.m.Y · H:i', $postUpdatedTimestamp);
                                }
                            }
                            $postUpdatedSort = $postUpdatedTimestamp ?? strtotime((string)($post['created_at'] ?? '')) ?? 0;
                            $postHasEnglishVariant = $postSlugEn !== '' && $postSlugEn !== $postSlug;
                            $postAuthorEmail = filter_var($postAuthor, FILTER_VALIDATE_EMAIL) ? $postAuthor : '';
                            $postAuthorLabel = $postAuthorEmail !== '' ? explode('@', $postAuthorEmail)[0] : $postAuthor;
                            $postAuthorForInitials = trim((string)$postAuthorLabel) !== '' ? (string)$postAuthorLabel : 'N A';
                            $postAuthorParts = preg_split('/\s+/', trim($postAuthorForInitials)) ?: [];
                            $postAuthorInitials = '';
                            foreach ($postAuthorParts as $postAuthorPart) {
                                if ($postAuthorPart !== '') {
                                    $postAuthorInitials .= strtoupper(substr($postAuthorPart, 0, 1));
                                }
                                if (strlen($postAuthorInitials) >= 2) {
                                    break;
                                }
                            }
                            if ($postAuthorInitials === '') {
                                $postAuthorInitials = strtoupper(substr($postAuthorForInitials, 0, 2));
                            }
                            $postAuthorSeed = strtolower(trim((string)($postAuthorEmail !== '' ? $postAuthorEmail : $postAuthor)));
                            $postAuthorHue = abs((int)crc32($postAuthorSeed !== '' ? $postAuthorSeed : 'na')) % 360;
                            ?>
                            <tr class="content-listing-table__row"
                                data-edit-url="/admin/posts?action=edit&id=<?php echo $postId; ?>"
                                data-sort-title="<?php echo htmlspecialchars(strtolower((string)($postTitle !== '' ? $postTitle : 'Ohne Titel')), ENT_QUOTES, 'UTF-8'); ?>"
                                data-sort-status="<?php echo htmlspecialchars(strtolower((string)$postStatusLabel), ENT_QUOTES, 'UTF-8'); ?>"
                                data-sort-updated="<?php echo (int)$postUpdatedSort; ?>">
                                <td>
                                    <input class="form-check-input" type="checkbox" name="ids[]" value="<?php echo $postId; ?>" form="bulkFormPosts" aria-label="Beitrag #<?php echo $postId; ?> auswählen">
                                </td>
                                <td class="content-listing-table__title-cell">
                                    <a href="/admin/posts?action=edit&id=<?php echo $postId; ?>" class="text-reset fw-medium">
                                        <?php echo htmlspecialchars($postTitle !== '' ? $postTitle : 'Ohne Titel', ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                    <span class="content-listing-id-badge">#<?php echo $postId; ?></span>
                                    <?php if ($postHasEnglishVariant): ?>
                                        <span class="badge bg-secondary-lt ms-2">EN</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-secondary content-listing-table__slug-cell">
                                    /blog/<?php echo htmlspecialchars($postSlug, ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ($postHasEnglishVariant): ?>
                                        <div class="small mt-1">/en/blog/<?php echo htmlspecialchars($postSlugEn, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($postCategory !== ''): ?>
                                        <span class="badge bg-azure-lt"><?php echo htmlspecialchars($postCategory, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php else: ?>
                                        <span class="text-secondary">–</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge <?php echo htmlspecialchars($postStatusClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($postStatusLabel, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <?php if ($postsHasMultipleAuthors): ?>
                                    <td>
                                        <div class="content-listing-author">
                                            <span class="content-listing-author__avatar"
                                                  style="background-color:hsl(<?php echo $postAuthorHue; ?> 45% 42%);"
                                                  title="<?php echo htmlspecialchars($postAuthorEmail !== '' ? $postAuthorEmail : $postAuthor, ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php echo htmlspecialchars($postAuthorInitials !== '' ? $postAuthorInitials : 'NA', ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                            <span class="content-listing-author__name"><?php echo htmlspecialchars($postAuthorLabel !== '' ? $postAuthorLabel : '–', ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                    </td>
                                <?php endif; ?>
                                <td class="text-secondary"><?php echo htmlspecialchars($postUpdatedAtLabel !== '' ? $postUpdatedAtLabel : '–', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="table-actions content-listing-table__actions-cell">
                                    <div class="table-row-actions table-row-actions--icons">
                                        <a href="/admin/posts?action=edit&id=<?php echo $postId; ?>" class="btn btn-outline-primary btn-sm btn-icon js-row-action" aria-label="Beitrag bearbeiten" title="Bearbeiten">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 7h10v10H7z" opacity="0"/><path d="M16.474 5.408a2.077 2.077 0 1 1 2.937 2.937l-9.19 9.19a6 6 0 0 1 -2.52 1.51l-2.093 .698l.698 -2.093a6 6 0 0 1 1.51 -2.52z"/><path d="M14.474 7.408l2.118 2.118"/></svg>
                                        </a>
                                        <form method="post" action="/admin/posts" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $postId; ?>">
                                            <button type="button" class="btn btn-ghost-danger btn-sm btn-icon content-listing-delete-action js-row-action" aria-label="Beitrag löschen" title="Löschen" data-cms-confirm-modal data-cms-confirm-title="Beitrag löschen?" data-cms-confirm-message="Dieser Beitrag wird unwiderruflich gelöscht." data-cms-confirm-text="Löschen" data-cms-confirm-class="btn-danger">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7l16 0"/><path d="M10 11l0 6"/><path d="M14 11l0 6"/><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12"/><path d="M9 7l1 -3h4l1 3"/></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="<?php echo $postsHasMultipleAuthors ? 8 : 7; ?>">
                                <div class="content-listing-table-footer">
                                    <div class="content-listing-table-footer__summary" id="postsRangeSummary">Zeige 0-0 von 0 Einträgen</div>
                                    <div class="content-listing-table-footer__controls">
                                        <label for="postsPageSize" class="small text-secondary mb-0">Pro Seite</label>
                                        <select id="postsPageSize" class="form-select form-select-sm">
                                            <option value="10">10</option>
                                            <option value="25" selected>25</option>
                                            <option value="50">50</option>
                                        </select>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="postsPrevPage">Zurück</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="postsNextPage">Weiter</button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

    </div>
</div>

<script <?= \CMS\Security::instance()->nonceAttr() ?>>

function applyFilters() {
    var s = document.getElementById('statusFilter').value;
    var c = document.getElementById('categoryFilter').value;
    var q = document.getElementById('searchInput').value.trim();
    var url = '/admin/posts?';
    var params = [];
    if (s) params.push('status=' + encodeURIComponent(s));
    if (c && c !== '0') params.push('category=' + encodeURIComponent(c));
    if (q) params.push('q=' + encodeURIComponent(q));
    window.location.href = url + params.join('&');
}

(function() {
    var bulkForm = document.getElementById('bulkFormPosts');
    var bulkBar = document.getElementById('bulkBarPosts');
    var countEl = document.getElementById('selectedCountPosts');
    var bulkActionSelect = bulkForm ? bulkForm.querySelector('[name="bulk_action"]') : null;
    var bulkAuthorDisplayName = document.getElementById('bulkAuthorDisplayNamePosts');
    var bulkCategorySelect = document.getElementById('bulkCategoryPosts');
    var bulkSubmit = document.getElementById('bulkSubmitPosts');
    var bulkDelete = document.getElementById('bulkDeletePosts');
    var bulkCancel = document.getElementById('bulkCancelPosts');
    var selectAll = document.getElementById('postsSelectAll');
    var table = document.getElementById('postsListingTable');
    var sortButtons = Array.prototype.slice.call(document.querySelectorAll('#postsListingTable .js-content-sort'));
    var pageSizeSelect = document.getElementById('postsPageSize');
    var prevPageButton = document.getElementById('postsPrevPage');
    var nextPageButton = document.getElementById('postsNextPage');
    var rangeSummary = document.getElementById('postsRangeSummary');
    var currentPage = 1;
    var currentSortKey = '';
    var currentSortDirection = 'asc';

    if (!bulkForm || !bulkBar || !countEl) {
        return;
    }

    function bulkSubmitMeta(action) {
        switch (action) {
            case 'publish':
                return { text: 'Beiträge veröffentlichen', className: 'btn btn-sm btn-primary' };
            case 'draft':
                return { text: 'Als Entwurf setzen', className: 'btn btn-sm btn-warning' };
            case 'set_category':
                return { text: 'Kategorien setzen', className: 'btn btn-sm btn-primary' };
            case 'clear_category':
                return { text: 'Kategorien entfernen', className: 'btn btn-sm btn-outline-secondary' };
            case 'set_author_display_name':
                return { text: 'Autorenname setzen', className: 'btn btn-sm btn-primary' };
            case 'clear_author_display_name':
                return { text: 'Autorenname zurücksetzen', className: 'btn btn-sm btn-outline-secondary' };
            case 'delete':
                return { text: 'Beiträge löschen', className: 'btn btn-sm btn-danger' };
            default:
                return { text: 'Anwenden', className: 'btn btn-sm btn-primary' };
        }
    }

    function getRows() {
        if (!table || !table.tBodies.length) {
            return [];
        }
        return Array.prototype.slice.call(table.tBodies[0].querySelectorAll('tr.content-listing-table__row'));
    }

    function isInteractiveTarget(target) {
        return !!target.closest('a, button, input, select, textarea, label, .js-row-action');
    }

    function applyPagination() {
        var rows = getRows();
        var pageSize = pageSizeSelect ? parseInt(pageSizeSelect.value, 10) || 25 : rows.length || 1;
        var totalRows = rows.length;
        var totalPages = Math.max(1, Math.ceil(totalRows / pageSize));
        if (currentPage > totalPages) {
            currentPage = totalPages;
        }
        if (currentPage < 1) {
            currentPage = 1;
        }
        var startIndex = (currentPage - 1) * pageSize;
        var endIndex = Math.min(startIndex + pageSize, totalRows);
        rows.forEach(function(row, index) {
            row.style.display = (index >= startIndex && index < endIndex) ? '' : 'none';
        });
        var from = totalRows === 0 ? 0 : startIndex + 1;
        var to = totalRows === 0 ? 0 : endIndex;
        if (rangeSummary) {
            rangeSummary.textContent = 'Seite ' + currentPage + '/' + totalPages + ' · Zeige ' + from + '-' + to + ' von ' + totalRows + ' Einträgen';
        }
        if (prevPageButton) {
            prevPageButton.disabled = currentPage <= 1;
        }
        if (nextPageButton) {
            nextPageButton.disabled = currentPage >= totalPages;
        }
    }

    function applySort() {
        if (!table || !table.tBodies.length || !currentSortKey) {
            applyPagination();
            return;
        }
        var tbody = table.tBodies[0];
        var rows = getRows();
        rows.sort(function(rowA, rowB) {
            var key = 'sort' + currentSortKey.charAt(0).toUpperCase() + currentSortKey.slice(1);
            var valA = rowA.dataset[key] || '';
            var valB = rowB.dataset[key] || '';
            if (currentSortKey === 'updated') {
                var numA = parseInt(valA, 10) || 0;
                var numB = parseInt(valB, 10) || 0;
                return currentSortDirection === 'asc' ? numA - numB : numB - numA;
            }
            var cmp = String(valA).localeCompare(String(valB), 'de', { sensitivity: 'base', numeric: true });
            return currentSortDirection === 'asc' ? cmp : -cmp;
        });
        rows.forEach(function(row) {
            tbody.appendChild(row);
        });
        applyPagination();
    }

    function syncSortIndicators() {
        sortButtons.forEach(function(button) {
            var icon = button.querySelector('i');
            var isActive = button.dataset.sortKey === currentSortKey;
            button.classList.toggle('is-active', isActive);
            if (!icon) {
                return;
            }
            icon.className = 'ti ' + (isActive
                ? (currentSortDirection === 'asc' ? 'ti-arrow-up' : 'ti-arrow-down')
                : 'ti-arrows-sort');
        });
    }

    function updateBulkActionUi() {
        if (!bulkActionSelect || !bulkAuthorDisplayName || !bulkCategorySelect) {
            return;
        }

        var requiresName = bulkActionSelect.value === 'set_author_display_name';
        var requiresCategory = bulkActionSelect.value === 'set_category';
        bulkAuthorDisplayName.classList.toggle('d-none', !requiresName);
        bulkAuthorDisplayName.required = requiresName;
        bulkCategorySelect.classList.toggle('d-none', !requiresCategory);

        if (!requiresName) {
            bulkAuthorDisplayName.value = '';
        }
        if (!requiresCategory) {
            Array.prototype.forEach.call(bulkCategorySelect.options, function(option) {
                option.selected = false;
            });
        }

        updateBulkSubmitState();
    }

    function getRowCheckboxes() {
        return Array.prototype.slice.call(document.querySelectorAll('input[name="ids[]"][form="bulkFormPosts"]'));
    }

    function updateBulkState() {
        var rowCheckboxes = getRowCheckboxes();
        var checkedCount = rowCheckboxes.filter(function(checkbox) { return checkbox.checked; }).length;

        countEl.textContent = String(checkedCount);
        bulkBar.classList.toggle('d-none', checkedCount === 0);
        updateBulkSubmitState();

        if (selectAll) {
            selectAll.checked = rowCheckboxes.length > 0 && checkedCount === rowCheckboxes.length;
            selectAll.indeterminate = checkedCount > 0 && checkedCount < rowCheckboxes.length;
        }
    }

    function updateBulkSubmitState() {
        if (!bulkSubmit || !bulkActionSelect) {
            return;
        }

        var action = bulkActionSelect.value || '';
        var requiresName = action === 'set_author_display_name';
        var requiresCategory = action === 'set_category';
        var nameReady = !requiresName || (bulkAuthorDisplayName && bulkAuthorDisplayName.value.trim() !== '');
        var categoryReady = !requiresCategory || (bulkCategorySelect && bulkCategorySelect.selectedOptions.length > 0);
        var meta = bulkSubmitMeta(action);

        bulkSubmit.textContent = meta.text;
        bulkSubmit.className = meta.className;
        var selectedCount = getRowCheckboxes().filter(function(checkbox) { return checkbox.checked; }).length;
        bulkSubmit.disabled = selectedCount === 0 || action === '' || !nameReady || !categoryReady;
        if (bulkDelete) {
            bulkDelete.disabled = selectedCount === 0;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            getRowCheckboxes().forEach(function(checkbox) {
                checkbox.checked = selectAll.checked;
            });
            updateBulkState();
        });
    }

    getRowCheckboxes().forEach(function(checkbox) {
        checkbox.addEventListener('change', updateBulkState);
    });

    getRows().forEach(function(row) {
        row.addEventListener('click', function(event) {
            if (isInteractiveTarget(event.target)) {
                return;
            }
            var editUrl = row.getAttribute('data-edit-url');
            if (editUrl) {
                window.location.href = editUrl;
            }
        });
    });

    bulkForm.addEventListener('submit', function(event) {
        var checkedBoxes = getRowCheckboxes().filter(function(checkbox) { return checkbox.checked; });

        if (checkedBoxes.length === 0) {
            event.preventDefault();
            return;
        }

        if (bulkActionSelect && !bulkActionSelect.value) {
            event.preventDefault();
            bulkActionSelect.focus();
            return;
        }

        if (bulkActionSelect && bulkActionSelect.value === 'set_author_display_name' && bulkAuthorDisplayName && !bulkAuthorDisplayName.value.trim()) {
            event.preventDefault();
            bulkAuthorDisplayName.focus();
            return;
        }

        if (bulkActionSelect && bulkActionSelect.value === 'set_category' && bulkCategorySelect && bulkCategorySelect.selectedOptions.length === 0) {
            event.preventDefault();
            bulkCategorySelect.focus();
            return;
        }

        if (bulkActionSelect && bulkActionSelect.value === 'delete') {
            var deleteMessage = checkedBoxes.length === 1
                ? 'Soll der ausgewählte Beitrag wirklich gelöscht werden?'
                : 'Sollen die ' + checkedBoxes.length + ' ausgewählten Beiträge wirklich gelöscht werden?';

            if (bulkForm.dataset.bulkDeleteConfirmed === '1') {
                bulkForm.dataset.bulkDeleteConfirmed = '0';
                return;
            }
            event.preventDefault();
            if (typeof cmsConfirm === 'function') {
                cmsConfirm({
                    title: 'Beiträge gesammelt löschen?',
                    message: deleteMessage,
                    confirmText: 'Löschen',
                    confirmClass: 'btn-danger',
                    onConfirm: function () {
                        bulkForm.dataset.bulkDeleteConfirmed = '1';
                        cmsSubmitFormSafely(bulkForm);
                    }
                });
                return;
            }
            if (window.confirm(deleteMessage)) {
                bulkForm.dataset.bulkDeleteConfirmed = '1';
                cmsSubmitFormSafely(bulkForm);
            }
            return;
        }
    });

    if (bulkActionSelect) {
        bulkActionSelect.addEventListener('change', updateBulkActionUi);
    }
    if (bulkAuthorDisplayName) {
        bulkAuthorDisplayName.addEventListener('input', updateBulkSubmitState);
    }
    if (bulkCategorySelect) {
        bulkCategorySelect.addEventListener('change', updateBulkSubmitState);
    }

    if (bulkDelete) {
        bulkDelete.addEventListener('click', function() {
            if (!bulkActionSelect || bulkDelete.disabled) {
                return;
            }
            bulkActionSelect.value = 'delete';
            updateBulkActionUi();
            if (bulkForm) {
                bulkForm.requestSubmit();
            }
        });
    }

    if (bulkCancel) {
        bulkCancel.addEventListener('click', function() {
            getRowCheckboxes().forEach(function(checkbox) {
                checkbox.checked = false;
            });
            if (bulkActionSelect) {
                bulkActionSelect.value = '';
            }
            updateBulkActionUi();
            updateBulkState();
        });
    }

    sortButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            var key = button.dataset.sortKey || '';
            if (key === '') {
                return;
            }
            if (currentSortKey === key) {
                currentSortDirection = currentSortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                currentSortKey = key;
                currentSortDirection = 'asc';
            }
            currentPage = 1;
            syncSortIndicators();
            applySort();
        });
    });

    if (pageSizeSelect) {
        pageSizeSelect.addEventListener('change', function() {
            currentPage = 1;
            applyPagination();
        });
    }
    if (prevPageButton) {
        prevPageButton.addEventListener('click', function() {
            currentPage -= 1;
            applyPagination();
        });
    }
    if (nextPageButton) {
        nextPageButton.addEventListener('click', function() {
            currentPage += 1;
            applyPagination();
        });
    }
    updateBulkActionUi();
    updateBulkState();
    syncSortIndicators();
    applySort();
})();
</script>
