<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * EditorJS Block Vorlagen – Liste mit Suche, Zahl der Verwendungen und Aktionen.
 *
 * @var array<string, mixed> $rbData
 * @var callable $rbE
 * @var callable $rbDate
 * @var string $csrfToken
 */

$rbBlocks = (array) ($rbData['blocks'] ?? []);
$rbSearch = (string) ($rbData['search'] ?? '');
?>
<div class="card mb-4">
    <div class="card-body">
        <p class="mb-0">Vorlagen sind Inhaltsbausteine, die du einmal pflegst und über den Editor.js-Block <strong>„Vorlagen“</strong> in beliebig viele Beiträge und Seiten einbindest. Änderst du eine Vorlage, zeigen alle Stellen sofort den neuen Stand.</p>
    </div>
</div>

<form method="get" action="<?= $rbE(ReusableBlocksModule::ROUTE) ?>" class="mb-3" role="search">
    <div class="input-group">
        <label class="visually-hidden" for="rbSearch">Vorlagen durchsuchen</label>
        <input type="search" class="form-control" id="rbSearch" name="q" maxlength="100" value="<?= $rbE($rbSearch) ?>" placeholder="Titel suchen …">
        <button type="submit" class="btn"><i class="ti ti-search me-1" aria-hidden="true"></i>Suchen</button>
    </div>
</form>

<div class="card">
    <?php if ($rbBlocks === []): ?>
        <div class="card-body">
            <p class="text-secondary mb-0"><?= $rbSearch !== '' ? 'Keine Vorlage passt zur Suche.' : 'Noch keine Vorlagen. Lege mit „Neue Vorlage“ die erste an.' ?></p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th scope="col">Titel</th>
                        <th scope="col">Sprachen</th>
                        <th scope="col">Verwendet in</th>
                        <th scope="col">Geändert</th>
                        <th scope="col" class="w-1"><span class="visually-hidden">Aktionen</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rbBlocks as $rbItem): ?>
                        <tr>
                            <td><a href="<?= $rbE(ReusableBlocksModule::url(['action' => 'edit', 'id' => (int) $rbItem['id']])) ?>"><?= $rbE($rbItem['title']) ?></a> <span class="text-secondary small">#<?= (int) $rbItem['id'] ?></span></td>
                            <td><?= !empty($rbItem['has_en']) ? 'DE, EN' : 'DE' ?></td>
                            <td><?= (int) $rbItem['usage_count'] === 0 ? '<span class="text-secondary">nicht verwendet</span>' : (int) $rbItem['usage_count'] . ' Stelle(n)' ?></td>
                            <td><?= $rbE($rbDate($rbItem['updated_at'])) ?></td>
                            <td>
                                <form method="post" action="<?= $rbE(ReusableBlocksModule::ROUTE) ?>" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= $rbE($csrfToken) ?>">
                                    <input type="hidden" name="action" value="duplicate">
                                    <input type="hidden" name="block_id" value="<?= (int) $rbItem['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-ghost-secondary" aria-label="„<?= $rbE($rbItem['title']) ?>“ duplizieren"><i class="ti ti-copy" aria-hidden="true"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
