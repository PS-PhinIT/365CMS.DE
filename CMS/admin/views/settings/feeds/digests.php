<?php
/**
 * Feeds → E-Mail-Digests: Zusammenfassungen ausgewählter Bereiche an feste Adressen (1–4× täglich oder wöchentlich).
 */

declare(strict_types=1);

use CMS\Services\Feed\LegacyNormalizer;

if (!defined('ABSPATH')) {
    exit;
}

$fdDigest = is_array($feedsData['digest'] ?? null) ? $feedsData['digest'] : null;
$fdCategoryNames = [];
foreach ($feedsData['categories'] ?? [] as $fdCategory) {
    $fdCategoryNames[(int) $fdCategory['id']] = (string) $fdCategory['name'];
}
$fdFrequencies = [1, 2, 3, 4, 7];

if (!empty($feedsData['form'])) :
    $fdId = (int) ($fdDigest['id'] ?? 0);
    $fdSelected = array_flip(LegacyNormalizer::idList($fdDigest['category_ids'] ?? '[]')); ?>
    <form method="post" action="<?= $feedsUrl(['section' => 'digests']) ?>" class="card mt-3">
        <?= $feedsCsrfField ?>
        <input type="hidden" name="action" value="save_digest">
        <input type="hidden" name="id" value="<?= $fdId ?>">
        <div class="card-header"><h2 class="card-title"><?= $fdId > 0 ? $feedsT('digests.edit_title', (string) $fdDigest['name']) : $feedsT('digests.new') ?></h2></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label required" for="fd-digest-name"><?= $feedsT('digests.name') ?></label>
                    <input class="form-control" type="text" id="fd-digest-name" name="name" required maxlength="150" value="<?= $feedsEscape((string) ($fdDigest['name'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label required" for="fd-digest-email"><?= $feedsT('digests.email') ?></label>
                    <input class="form-control" type="email" id="fd-digest-email" name="email" required maxlength="255" value="<?= $feedsEscape((string) ($fdDigest['email'] ?? '')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="fd-digest-frequency"><?= $feedsT('digests.frequency') ?></label>
                    <select class="form-select" id="fd-digest-frequency" name="frequency">
                        <?php foreach ($fdFrequencies as $fdFrequency) : ?>
                            <option value="<?= $fdFrequency ?>"<?= (int) ($fdDigest['frequency'] ?? 1) === $fdFrequency ? ' selected' : '' ?>><?= $feedsEscape(\CMS\Services\Feed\FeedI18n::admin('feeds.digest.frequency.' . $fdFrequency)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="fd-digest-language"><?= $feedsT('digests.language') ?></label>
                    <select class="form-select" id="fd-digest-language" name="language">
                        <option value="de"<?= ($fdDigest['language'] ?? 'de') === 'de' ? ' selected' : '' ?>>Deutsch</option>
                        <option value="en"<?= ($fdDigest['language'] ?? 'de') === 'en' ? ' selected' : '' ?>>English</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <label class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1"<?= $fdDigest === null || (int) $fdDigest['is_active'] === 1 ? ' checked' : '' ?>>
                        <span class="form-check-label"><?= $feedsT('digests.active') ?></span>
                    </label>
                </div>
                <fieldset class="col-12">
                    <legend class="form-label required"><?= $feedsT('digests.categories') ?></legend>
                    <div class="row">
                        <?php foreach ($fdCategoryNames as $fdCatId => $fdCatName) : ?>
                            <div class="col-md-4">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="category_ids[]" value="<?= $fdCatId ?>"<?= isset($fdSelected[$fdCatId]) ? ' checked' : '' ?>>
                                    <span class="form-check-label"><?= $feedsEscape($fdCatName) ?></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-between">
            <a class="btn btn-link" href="<?= $feedsUrl(['section' => 'digests']) ?>"><?= $feedsT('back') ?></a>
            <button class="btn btn-primary" type="submit"><?= $feedsEscape((string) __('Save Changes')) ?></button>
        </div>
    </form>
    <?php return;
endif;
?>
<div class="card mt-3">
    <div class="card-header">
        <div class="me-auto">
            <h2 class="card-title"><?= $feedsT('nav.digests') ?></h2>
            <div class="text-secondary small"><?= $feedsT('digests.intro') ?></div>
        </div>
        <a class="btn btn-primary btn-sm" href="<?= $feedsUrl(['section' => 'digests', 'new' => 1]) ?>"><?= $feedsT('digests.new') ?></a>
    </div>
    <?php if (($feedsData['digests'] ?? []) === []) : ?>
        <div class="card-body text-secondary"><?= $feedsT('digests.empty') ?></div>
    <?php else : ?>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th><?= $feedsT('digests.name') ?></th>
                        <th><?= $feedsT('digests.email') ?></th>
                        <th><?= $feedsT('digests.categories') ?></th>
                        <th><?= $feedsT('digests.frequency') ?></th>
                        <th><?= $feedsT('digests.last_sent') ?></th>
                        <th class="w-1"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($feedsData['digests'] as $fdRow) :
                        $fdNames = array_map(static fn (int $id): string => $fdCategoryNames[$id] ?? '#' . $id, LegacyNormalizer::idList($fdRow['category_ids'] ?? '[]')); ?>
                        <tr>
                            <td>
                                <a class="fw-bold" href="<?= $feedsUrl(['section' => 'digests', 'edit' => (int) $fdRow['id']]) ?>"><?= $feedsEscape((string) $fdRow['name']) ?></a>
                                <?php if ((int) $fdRow['is_active'] !== 1) : ?><span class="badge bg-secondary-lt ms-1"><?= $feedsT('digests.paused') ?></span><?php endif; ?>
                            </td>
                            <td><?= $feedsEscape((string) $fdRow['email']) ?></td>
                            <td><?= $feedsEscape(implode(', ', $fdNames)) ?></td>
                            <td><?= $feedsEscape(\CMS\Services\Feed\FeedI18n::admin('feeds.digest.frequency.' . LegacyNormalizer::digestFrequency($fdRow['frequency'] ?? 1))) ?></td>
                            <td class="text-nowrap"><?= $feedsEscape($feedsDate($fdRow['last_sent_at'] ?? '')) ?></td>
                            <td class="text-nowrap">
                                <form method="post" action="<?= $feedsUrl(['section' => 'digests']) ?>" class="d-inline">
                                    <?= $feedsCsrfField ?>
                                    <input type="hidden" name="action" value="test_digest">
                                    <input type="hidden" name="id" value="<?= (int) $fdRow['id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $feedsT('digests.test') ?></button>
                                </form>
                                <form method="post" action="<?= $feedsUrl(['section' => 'digests']) ?>" class="d-inline"
                                      data-confirm-title="<?= $feedsT('digests.delete') ?>" data-confirm-message="<?= $feedsT('digests.delete_confirm', (string) $fdRow['name']) ?>" data-confirm-text="<?= $feedsT('digests.delete') ?>" data-confirm-class="btn-danger">
                                    <?= $feedsCsrfField ?>
                                    <input type="hidden" name="action" value="delete_digest">
                                    <input type="hidden" name="id" value="<?= (int) $fdRow['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit"><?= $feedsT('digests.delete') ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
