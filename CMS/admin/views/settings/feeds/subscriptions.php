<?php
/**
 * Feeds → Mitglieder-Abos: Übersicht der Abos aus dem Mitgliederbereich (/member/plugin/feeds).
 */

declare(strict_types=1);

use CMS\Services\Feed\DigestMailer;
use CMS\Services\Feed\FeedI18n;
use CMS\Services\Feed\LegacyNormalizer;

if (!defined('ABSPATH')) {
    exit;
}

$fdSourceNames = $feedsData['source_names'] ?? [];
?>
<div class="card mt-3">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= $feedsT('nav.subscriptions') ?></h2>
            <div class="text-secondary small"><?= $feedsT('subscriptions.intro', (int) ($feedsData['active'] ?? 0), (int) ($feedsData['total'] ?? 0)) ?></div>
        </div>
    </div>
    <?php if (($feedsData['subscriptions'] ?? []) === []) : ?>
        <div class="card-body text-secondary"><?= $feedsT('subscriptions.empty') ?></div>
    <?php else : ?>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th><?= $feedsT('subscriptions.member') ?></th>
                        <th><?= $feedsT('nav.sources') ?></th>
                        <th><?= $feedsT('subscriptions.plan') ?></th>
                        <th><?= $feedsT('digests.last_sent') ?></th>
                        <th class="w-1"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($feedsData['subscriptions'] as $fdRow) :
                        $fdIds = LegacyNormalizer::idList($fdRow['source_ids'] ?? '[]');
                        $fdNames = array_map(static fn (int $id): string => (string) ($fdSourceNames[$id] ?? '#' . $id), $fdIds);
                        $fdMember = trim((string) ($fdRow['display_name'] ?? '')) !== '' ? (string) $fdRow['display_name'] : (string) ($fdRow['username'] ?? ('#' . (int) $fdRow['user_id'])); ?>
                        <tr>
                            <td>
                                <?= $feedsEscape($fdMember) ?>
                                <?php if ((int) $fdRow['is_active'] !== 1) : ?><span class="badge bg-secondary-lt ms-1"><?= $feedsT('digests.paused') ?></span><?php endif; ?>
                                <div class="small text-secondary"><?= $feedsEscape((string) ($fdRow['email'] ?? '')) ?></div>
                            </td>
                            <td><?= $feedsEscape($fdNames === [] ? '–' : implode(', ', array_slice($fdNames, 0, 6)) . (count($fdNames) > 6 ? ' …' : '')) ?></td>
                            <td><?= $feedsEscape(DigestMailer::planLabel($fdRow, FeedI18n::systemLocale())) ?></td>
                            <td class="text-nowrap"><?= $feedsEscape($feedsDate($fdRow['last_sent_at'] ?? '')) ?></td>
                            <td>
                                <form method="post" action="<?= $feedsUrl(['section' => 'subscriptions']) ?>"
                                      data-confirm-title="<?= $feedsT('subscriptions.delete') ?>" data-confirm-message="<?= $feedsT('subscriptions.delete_confirm', $fdMember) ?>" data-confirm-text="<?= $feedsT('subscriptions.delete') ?>" data-confirm-class="btn-danger">
                                    <?= $feedsCsrfField ?>
                                    <input type="hidden" name="action" value="delete_subscription">
                                    <input type="hidden" name="user_id" value="<?= (int) $fdRow['user_id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit"><?= $feedsT('subscriptions.delete') ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
