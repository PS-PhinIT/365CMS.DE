<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Erwartet:
 * - $seoScorePanel: array{
 *     badgeId:string,
 *     scoreLabelId:string,
 *     scoreBarId:string,
 *     rulesId:string,
 *     hintBadgeContainerId?:string,
 *     summaryCards: array<int,array{width:string,label:string,valueId?:string,valueText?:string,suffix?:string,badgeId?:string,badgeText?:string,bodyText?:string,badgeClass?:string}>
 *   }
 */

$seoScorePanel = $seoScorePanel ?? [];
$summaryCards = is_array($seoScorePanel['summaryCards'] ?? null) ? $seoScorePanel['summaryCards'] : [];
$hintBadgeContainerId = trim((string) ($seoScorePanel['hintBadgeContainerId'] ?? ''));
?>
<details class="card cms-edit-card cms-seo-score-panel cms-collapsible-card">
    <summary class="card-header cms-collapsible-card__summary">
        <div class="d-flex align-items-center justify-content-between w-100">
            <h3 class="card-title mb-0">SEO-Score &amp; Checkliste</h3>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-danger-lt text-danger" id="<?php echo htmlspecialchars((string)($seoScorePanel['badgeId'] ?? '')); ?>">Rot</span>
                <strong class="h2 mb-0" id="<?php echo htmlspecialchars((string)($seoScorePanel['scoreLabelId'] ?? '')); ?>">0</strong>
                <span class="cms-collapsible-card__chevron" aria-hidden="true"></span>
            </div>
        </div>
    </summary>
    <div class="card-body cms-seo-score-panel__body">
        <div class="progress progress-sm mb-3"><div class="progress-bar bg-danger" id="<?php echo htmlspecialchars((string)($seoScorePanel['scoreBarId'] ?? '')); ?>" data-cms-progress-start="0"></div></div>
        <div class="row g-3 mb-3 cms-seo-score-panel__summary">
            <?php $summaryIndex = 0; ?>
            <?php foreach ($summaryCards as $card): ?>
                <?php
                $isKpiTile = $summaryIndex < 4;
                $summaryItemClass = 'col-12 cms-seo-score-panel__summary-item';
                if ($isKpiTile) {
                    $summaryItemClass .= ' cms-seo-score-panel__summary-item--kpi';
                }
                $summaryIndex++;
                ?>
                <div class="<?php echo htmlspecialchars($summaryItemClass); ?>">
                    <div class="border rounded p-3 h-100 cms-seo-score-panel__summary-card">
                        <div class="text-secondary small mb-1"><?php echo htmlspecialchars((string)($card['label'] ?? '')); ?></div>
                        <?php if (!empty($card['badgeId'])): ?>
                            <span class="<?php echo htmlspecialchars((string)($card['badgeClass'] ?? 'badge bg-yellow-lt text-yellow')); ?>" id="<?php echo htmlspecialchars((string)$card['badgeId']); ?>"><?php echo htmlspecialchars((string)($card['badgeText'] ?? '')); ?></span>
                        <?php elseif (!empty($card['valueId'])): ?>
                            <div class="h3 m-0" id="<?php echo htmlspecialchars((string)$card['valueId']); ?>"><?php echo htmlspecialchars((string)($card['valueText'] ?? '0')); ?></div>
                            <?php if (!empty($card['suffix'])): ?>
                                <div class="text-secondary small"><?php echo htmlspecialchars((string)$card['suffix']); ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="small"><?php echo htmlspecialchars((string)($card['bodyText'] ?? '')); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($hintBadgeContainerId !== ''): ?>
            <div class="border rounded p-3 mb-3 cms-seo-score-panel__live-hints">
                <div class="d-flex flex-column gap-2">
                    <div>
                        <strong>Live-Hinweise</strong>
                        <div class="text-secondary small">Empfehlungen aus dem Editor – bewusst nicht blockierend.</div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 cms-seo-score-panel__hint-badges" id="<?php echo htmlspecialchars($hintBadgeContainerId); ?>"></div>
                </div>
            </div>
        <?php endif; ?>
        <div class="cms-seo-score-panel__rules" id="<?php echo htmlspecialchars((string)($seoScorePanel['rulesId'] ?? '')); ?>"></div>
    </div>
</details>
