<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Erwartet:
 * - $advancedSeoPanel: array{
 *     hint:string,
 *     schemaTypeId:string,
 *     schemaTypeName:string,
 *     schemaTypeValue:string,
 *     schemaTypeOptions:array<int,string>,
 *     sitemapPriorityId:string,
 *     sitemapPriorityName:string,
 *     sitemapPriorityValue:string,
 *     sitemapChangefreqId:string,
 *     sitemapChangefreqName:string,
 *     sitemapChangefreqValue:string,
 *     sitemapChangefreqOptions:array<int,string>,
 *     robotsIndexId:string,
 *     robotsIndexName:string,
 *     robotsIndexChecked:bool,
 *     robotsFollowId:string,
 *     robotsFollowName:string,
 *     robotsFollowChecked:bool,
 *     hreflangGroupId:string,
 *     hreflangGroupName:string,
 *     hreflangGroupValue:string,
 *     ogTitleId:string,
 *     ogTitleValue:string,
 *     ogImageId:string,
 *     ogImageValue:string,
 *     ogDescriptionId:string,
 *     ogDescriptionValue:string,
 *     twitterTitleId:string,
 *     twitterTitleValue:string,
 *     twitterCardId:string,
 *     twitterCardName:string,
 *     twitterCardValue:string,
 *     twitterCardOptions:array<int,string>,
 *     twitterDescriptionId:string,
 *     twitterDescriptionValue:string,
 *     twitterImageId:string,
 *     twitterImageValue:string
 *   }
 */

$advancedSeoPanel = $advancedSeoPanel ?? [];
?>
<details class="card cms-edit-card cms-collapsible-card">
    <summary class="card-header cms-collapsible-card__summary">
        <div class="d-flex align-items-center justify-content-between w-100">
            <h3 class="card-title mb-0">Erweitertes SEO &amp; Social</h3>
            <div class="d-flex align-items-center gap-2">
                <span class="text-secondary small">Canonical, Robots, Social, Schema, Sitemap, Bilder</span>
                <span class="cms-collapsible-card__chevron" aria-hidden="true"></span>
            </div>
        </div>
    </summary>
    <div class="card-body">
        <div class="row g-3 mb-4">
            <div class="col-12">
                <p class="text-secondary small mb-0"><?php echo htmlspecialchars((string)($advancedSeoPanel['hint'] ?? '')); ?></p>
            </div>
            <div class="col-lg-4">
                <label class="form-label" for="<?php echo htmlspecialchars((string)($advancedSeoPanel['schemaTypeId'] ?? '')); ?>">Schema-Typ</label>
                <select class="form-select" id="<?php echo htmlspecialchars((string)($advancedSeoPanel['schemaTypeId'] ?? '')); ?>" name="<?php echo htmlspecialchars((string)($advancedSeoPanel['schemaTypeName'] ?? 'schema_type')); ?>">
                    <?php foreach (($advancedSeoPanel['schemaTypeOptions'] ?? []) as $type): ?>
                        <option value="<?php echo htmlspecialchars((string)$type); ?>" <?php echo ((string)($advancedSeoPanel['schemaTypeValue'] ?? '') === (string)$type) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$type); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="form-label mt-3" for="<?php echo htmlspecialchars((string)($advancedSeoPanel['sitemapPriorityId'] ?? '')); ?>">Sitemap Priority</label>
                <input type="text" class="form-control" id="<?php echo htmlspecialchars((string)($advancedSeoPanel['sitemapPriorityId'] ?? '')); ?>" name="<?php echo htmlspecialchars((string)($advancedSeoPanel['sitemapPriorityName'] ?? 'sitemap_priority')); ?>" value="<?php echo htmlspecialchars((string)($advancedSeoPanel['sitemapPriorityValue'] ?? '')); ?>" placeholder="0.6">
                <label class="form-label mt-3" for="<?php echo htmlspecialchars((string)($advancedSeoPanel['sitemapChangefreqId'] ?? '')); ?>">Sitemap Changefreq</label>
                <select class="form-select" id="<?php echo htmlspecialchars((string)($advancedSeoPanel['sitemapChangefreqId'] ?? '')); ?>" name="<?php echo htmlspecialchars((string)($advancedSeoPanel['sitemapChangefreqName'] ?? 'sitemap_changefreq')); ?>">
                    <?php foreach (($advancedSeoPanel['sitemapChangefreqOptions'] ?? []) as $freq): ?>
                        <option value="<?php echo htmlspecialchars((string)$freq); ?>" <?php echo ((string)($advancedSeoPanel['sitemapChangefreqValue'] ?? '') === (string)$freq) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$freq); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-4 d-flex flex-column justify-content-end gap-2">
                <?php /* Abgewählte Checkboxen werden nicht gesendet; ohne Fallback würde noindex/nofollow nie gespeichert. */ ?>
                <input type="hidden" name="<?php echo htmlspecialchars((string)($advancedSeoPanel['robotsIndexName'] ?? 'robots_index')); ?>" value="0">
                <input type="hidden" name="<?php echo htmlspecialchars((string)($advancedSeoPanel['robotsFollowName'] ?? 'robots_follow')); ?>" value="0">
                <label class="form-check"><input class="form-check-input" type="checkbox" id="<?php echo htmlspecialchars((string)($advancedSeoPanel['robotsIndexId'] ?? 'robotsIndex')); ?>" name="<?php echo htmlspecialchars((string)($advancedSeoPanel['robotsIndexName'] ?? 'robots_index')); ?>" value="1" <?php echo !empty($advancedSeoPanel['robotsIndexChecked']) ? 'checked' : ''; ?>><span class="form-check-label">index</span></label>
                <label class="form-check"><input class="form-check-input" type="checkbox" id="<?php echo htmlspecialchars((string)($advancedSeoPanel['robotsFollowId'] ?? 'robotsFollow')); ?>" name="<?php echo htmlspecialchars((string)($advancedSeoPanel['robotsFollowName'] ?? 'robots_follow')); ?>" value="1" <?php echo !empty($advancedSeoPanel['robotsFollowChecked']) ? 'checked' : ''; ?>><span class="form-check-label">follow</span></label>
                <label class="form-label mt-2" for="<?php echo htmlspecialchars((string)($advancedSeoPanel['hreflangGroupId'] ?? '')); ?>">hreflang-Gruppe</label>
                <input type="text" class="form-control" id="<?php echo htmlspecialchars((string)($advancedSeoPanel['hreflangGroupId'] ?? '')); ?>" name="<?php echo htmlspecialchars((string)($advancedSeoPanel['hreflangGroupName'] ?? 'hreflang_group')); ?>" value="<?php echo htmlspecialchars((string)($advancedSeoPanel['hreflangGroupValue'] ?? '')); ?>" placeholder="z. B. blog-ki-strategie">
            </div>
            <div class="col-lg-6">
                <label class="form-label" for="<?php echo htmlspecialchars((string)($advancedSeoPanel['ogTitleId'] ?? '')); ?>">OG-Titel</label>
                <input type="text" class="form-control" id="<?php echo htmlspecialchars((string)($advancedSeoPanel['ogTitleId'] ?? '')); ?>" name="og_title" value="<?php echo htmlspecialchars((string)($advancedSeoPanel['ogTitleValue'] ?? '')); ?>">
            </div>
            <div class="col-lg-6">
                <label class="form-label" for="<?php echo htmlspecialchars((string)($advancedSeoPanel['ogImageId'] ?? '')); ?>">OG-Bild</label>
                <input type="text" class="form-control" id="<?php echo htmlspecialchars((string)($advancedSeoPanel['ogImageId'] ?? '')); ?>" name="og_image" value="<?php echo htmlspecialchars((string)($advancedSeoPanel['ogImageValue'] ?? '')); ?>">
            </div>
            <div class="col-lg-6">
                <label class="form-label" for="<?php echo htmlspecialchars((string)($advancedSeoPanel['ogDescriptionId'] ?? '')); ?>">OG-Beschreibung</label>
                <textarea class="form-control" id="<?php echo htmlspecialchars((string)($advancedSeoPanel['ogDescriptionId'] ?? '')); ?>" name="og_description" rows="3"><?php echo htmlspecialchars((string)($advancedSeoPanel['ogDescriptionValue'] ?? '')); ?></textarea>
            </div>
            <div class="col-lg-6">
                <label class="form-label" for="<?php echo htmlspecialchars((string)($advancedSeoPanel['twitterTitleId'] ?? '')); ?>">Twitter-/X-Titel</label>
                <input type="text" class="form-control" id="<?php echo htmlspecialchars((string)($advancedSeoPanel['twitterTitleId'] ?? '')); ?>" name="twitter_title" value="<?php echo htmlspecialchars((string)($advancedSeoPanel['twitterTitleValue'] ?? '')); ?>">
                <label class="form-label mt-3" for="<?php echo htmlspecialchars((string)($advancedSeoPanel['twitterCardId'] ?? '')); ?>">Twitter Card</label>
                <select class="form-select" id="<?php echo htmlspecialchars((string)($advancedSeoPanel['twitterCardId'] ?? '')); ?>" name="<?php echo htmlspecialchars((string)($advancedSeoPanel['twitterCardName'] ?? 'twitter_card')); ?>">
                    <?php foreach (($advancedSeoPanel['twitterCardOptions'] ?? []) as $card): ?>
                        <option value="<?php echo htmlspecialchars((string)$card); ?>" <?php echo ((string)($advancedSeoPanel['twitterCardValue'] ?? '') === (string)$card) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$card); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-6">
                <label class="form-label" for="<?php echo htmlspecialchars((string)($advancedSeoPanel['twitterDescriptionId'] ?? '')); ?>">Twitter-/X-Beschreibung</label>
                <textarea class="form-control" id="<?php echo htmlspecialchars((string)($advancedSeoPanel['twitterDescriptionId'] ?? '')); ?>" name="twitter_description" rows="3"><?php echo htmlspecialchars((string)($advancedSeoPanel['twitterDescriptionValue'] ?? '')); ?></textarea>
            </div>
            <div class="col-lg-6">
                <label class="form-label" for="<?php echo htmlspecialchars((string)($advancedSeoPanel['twitterImageId'] ?? '')); ?>">Twitter-/X-Bild</label>
                <input type="text" class="form-control" id="<?php echo htmlspecialchars((string)($advancedSeoPanel['twitterImageId'] ?? '')); ?>" name="twitter_image" value="<?php echo htmlspecialchars((string)($advancedSeoPanel['twitterImageValue'] ?? '')); ?>">
            </div>
        </div>
    </div>
</details>
