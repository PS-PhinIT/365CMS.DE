<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Erwartet:
 * - $previewCard: array{
 *     serpTitleId:string,
 *     serpTitle:string,
 *     serpUrlId:string,
 *     serpUrl:string,
 *     serpDescriptionId:string,
 *     serpDescription:string,
 *     socialImageId:string,
 *     socialImage:string,
 *     socialImageVisible:bool,
 *     socialTitleId:string,
 *     socialTitle:string,
 *     socialDescriptionId:string,
 *     socialDescription:string
 *   }
 */

$previewCard = $previewCard ?? [];
$socialImageVisible = !empty($previewCard['socialImageVisible']);
?>
<details class="card cms-edit-card h-100 w-100 cms-seo-preview-card cms-collapsible-card">
    <summary class="card-header cms-collapsible-card__summary">
        <h3 class="card-title mb-0">SERP- &amp; Social-Vorschau</h3>
        <span class="cms-collapsible-card__chevron" aria-hidden="true"></span>
    </summary>
    <div class="card-body d-flex flex-column gap-4">
        <div>
            <div class="text-uppercase text-secondary small mb-2">Google Desktop</div>
            <div class="border rounded p-3 bg-light">
                <div id="<?php echo htmlspecialchars((string)($previewCard['serpTitleId'] ?? '')); ?>" data-seo-preview-bind="serp-title" class="fw-semibold text-primary mb-1 cms-seo-preview-card__text"><?php echo htmlspecialchars((string)($previewCard['serpTitle'] ?? '')); ?></div>
                <div id="<?php echo htmlspecialchars((string)($previewCard['serpUrlId'] ?? '')); ?>" data-seo-preview-bind="serp-url" class="small text-success mb-1 cms-seo-preview-card__text"><?php echo htmlspecialchars((string)($previewCard['serpUrl'] ?? '')); ?></div>
                <div id="<?php echo htmlspecialchars((string)($previewCard['serpDescriptionId'] ?? '')); ?>" data-seo-preview-bind="serp-description" class="small text-secondary cms-seo-preview-card__text"><?php echo htmlspecialchars((string)($previewCard['serpDescription'] ?? '')); ?></div>
            </div>
        </div>

        <div>
            <div class="text-uppercase text-secondary small mb-2">Google Mobile</div>
            <div class="border rounded p-3 bg-light cms-seo-preview-card__serp">
                <div class="small text-success mb-1 cms-seo-preview-card__text" data-seo-preview-bind="serp-url"><?php echo htmlspecialchars((string)($previewCard['serpUrl'] ?? '')); ?></div>
                <div class="fw-semibold text-primary mb-1 cms-seo-preview-card__text" data-seo-preview-bind="serp-title"><?php echo htmlspecialchars((string)($previewCard['serpTitle'] ?? '')); ?></div>
                <div class="small text-secondary cms-seo-preview-card__text" data-seo-preview-bind="serp-description"><?php echo htmlspecialchars((string)($previewCard['serpDescription'] ?? '')); ?></div>
            </div>
        </div>

        <div>
            <div class="text-uppercase text-secondary small mb-2">Social / OG</div>
            <div class="border rounded overflow-hidden bg-light">
                <img id="<?php echo htmlspecialchars((string)($previewCard['socialImageId'] ?? '')); ?>"
                     data-seo-preview-bind="social-image"
                     src="<?php echo htmlspecialchars((string)($previewCard['socialImage'] ?? '')); ?>"
                     alt=""
                     class="cms-seo-preview-card__social-image"<?php echo $socialImageVisible ? '' : ' hidden'; ?>>
                <div class="p-3">
                    <div class="text-uppercase text-secondary small mb-1">facebook / x</div>
                    <div id="<?php echo htmlspecialchars((string)($previewCard['socialTitleId'] ?? '')); ?>" data-seo-preview-bind="social-title" class="fw-semibold mb-1 cms-seo-preview-card__text"><?php echo htmlspecialchars((string)($previewCard['socialTitle'] ?? '')); ?></div>
                    <div id="<?php echo htmlspecialchars((string)($previewCard['socialDescriptionId'] ?? '')); ?>" data-seo-preview-bind="social-description" class="small text-secondary cms-seo-preview-card__text"><?php echo htmlspecialchars((string)($previewCard['socialDescription'] ?? '')); ?></div>
                </div>
            </div>
        </div>
    </div>
</details>
