<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Redaktionskalender – Ansicht
 *
 * Erwartet: $data (EditorialCalendarModule::getData()), $module, $alert, $csrfToken
 * Das Raster rendert assets/js/admin-editorial-calendar.js; Filter funktionieren auch ohne JavaScript (GET).
 */

$calendarFilters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
$calendarOptions = is_array($data['options'] ?? null) ? $data['options'] : [];
$calendarAuthors = is_array($calendarOptions['authors'] ?? null) ? $calendarOptions['authors'] : [];
$calendarCategories = is_array($calendarOptions['categories'] ?? null) ? $calendarOptions['categories'] : [];
$calendarLocales = is_array($calendarOptions['locales'] ?? null) ? $calendarOptions['locales'] : [];
$calendarConfig = $module->buildClientConfig($data, (string) ($csrfToken ?? ''));
$calendarEsc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$calendarSelected = static fn (bool $selected): string => $selected ? ' selected' : '';
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle"><?php echo $calendarEsc(__('calendar.pretitle')); ?></div>
                <h2 class="page-title"><?php echo $calendarEsc(__('calendar.page_title')); ?></h2>
                <div class="text-secondary mt-1 small">
                    <?php echo $calendarEsc(sprintf(__('calendar.timezone_note'), (string) ($data['timezone'] ?? ''))); ?>
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

        <div class="card cms-ecal" id="cmsEditorialCalendar" data-view="<?php echo $calendarEsc($data['view'] ?? 'month'); ?>">
            <div class="card-header cms-ecal__toolbar">
                <div class="btn-group" role="group" aria-label="<?php echo $calendarEsc(__('calendar.nav.label')); ?>">
                    <button type="button" class="btn btn-icon" data-ecal-nav="prev" aria-label="<?php echo $calendarEsc(__('calendar.nav.prev')); ?>" title="<?php echo $calendarEsc(__('calendar.nav.prev')); ?>" hidden>
                        <i class="ti ti-chevron-left" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn" data-ecal-nav="today" hidden><?php echo $calendarEsc(__('calendar.nav.today')); ?></button>
                    <button type="button" class="btn btn-icon" data-ecal-nav="next" aria-label="<?php echo $calendarEsc(__('calendar.nav.next')); ?>" title="<?php echo $calendarEsc(__('calendar.nav.next')); ?>" hidden>
                        <i class="ti ti-chevron-right" aria-hidden="true"></i>
                    </button>
                </div>
                <h3 class="card-title cms-ecal__period" id="cmsEcalPeriod" aria-live="polite"></h3>
                <div class="btn-group ms-auto" role="group" aria-label="<?php echo $calendarEsc(__('calendar.view.label')); ?>">
                    <button type="button" class="btn" data-ecal-view="month" aria-pressed="false" hidden><?php echo $calendarEsc(__('calendar.view.month')); ?></button>
                    <button type="button" class="btn" data-ecal-view="week" aria-pressed="false" hidden><?php echo $calendarEsc(__('calendar.view.week')); ?></button>
                </div>
            </div>

            <div class="card-body border-bottom py-3">
                <form method="get" action="/admin/editorial-calendar" class="row g-2 align-items-end cms-ecal__filters" id="cmsEcalFilters">
                    <input type="hidden" name="view" value="<?php echo $calendarEsc($data['view'] ?? 'month'); ?>">
                    <input type="hidden" name="date" value="<?php echo $calendarEsc($data['anchor'] ?? ''); ?>">
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label" for="cmsEcalAuthor"><?php echo $calendarEsc(__('calendar.filter.author')); ?></label>
                        <select class="form-select" id="cmsEcalAuthor" name="author">
                            <option value="0"><?php echo $calendarEsc(__('calendar.filter.all_authors')); ?></option>
                            <?php foreach ($calendarAuthors as $author): ?>
                                <option value="<?php echo (int) ($author['id'] ?? 0); ?>"<?php echo $calendarSelected((int) ($calendarFilters['author'] ?? 0) === (int) ($author['id'] ?? 0)); ?>><?php echo $calendarEsc($author['name'] ?? ''); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label class="form-label" for="cmsEcalType"><?php echo $calendarEsc(__('calendar.filter.type')); ?></label>
                        <select class="form-select" id="cmsEcalType" name="type">
                            <option value=""><?php echo $calendarEsc(__('calendar.filter.all_types')); ?></option>
                            <option value="post"<?php echo $calendarSelected(($calendarFilters['type'] ?? '') === 'post'); ?>><?php echo $calendarEsc(__('calendar.type.posts')); ?></option>
                            <option value="page"<?php echo $calendarSelected(($calendarFilters['type'] ?? '') === 'page'); ?>><?php echo $calendarEsc(__('calendar.type.pages')); ?></option>
                        </select>
                    </div>
                    <?php if ($calendarCategories !== []): ?>
                        <div class="col-sm-6 col-lg-3">
                            <label class="form-label" for="cmsEcalCategory"><?php echo $calendarEsc(__('calendar.filter.category')); ?></label>
                            <select class="form-select" id="cmsEcalCategory" name="category">
                                <option value="0"><?php echo $calendarEsc(__('calendar.filter.all_categories')); ?></option>
                                <?php foreach ($calendarCategories as $category): ?>
                                    <option value="<?php echo (int) ($category['id'] ?? 0); ?>"<?php echo $calendarSelected((int) ($calendarFilters['category'] ?? 0) === (int) ($category['id'] ?? 0)); ?>><?php echo $calendarEsc($category['name'] ?? ''); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if (count($calendarLocales) > 1): ?>
                        <div class="col-sm-6 col-lg-2">
                            <label class="form-label" for="cmsEcalLang"><?php echo $calendarEsc(__('calendar.filter.language')); ?></label>
                            <select class="form-select" id="cmsEcalLang" name="lang">
                                <option value=""><?php echo $calendarEsc(__('calendar.filter.all_languages')); ?></option>
                                <?php foreach ($calendarLocales as $locale): ?>
                                    <option value="<?php echo $calendarEsc($locale['code'] ?? ''); ?>"<?php echo $calendarSelected(($calendarFilters['lang'] ?? '') === ($locale['code'] ?? '')); ?>><?php echo $calendarEsc($locale['label'] ?? ''); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="col-sm-6 col-lg-auto">
                        <label class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="published" value="1" id="cmsEcalPublished"<?php echo !empty($calendarFilters['published']) ? ' checked' : ''; ?>>
                            <span class="form-check-label"><?php echo $calendarEsc(__('calendar.filter.published')); ?></span>
                        </label>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-outline-primary" data-ecal-filter-submit>
                            <i class="ti ti-filter me-1" aria-hidden="true"></i><?php echo $calendarEsc(__('calendar.filter.apply')); ?>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card-body py-2 border-bottom cms-ecal__legend" aria-label="<?php echo $calendarEsc(__('calendar.legend.label')); ?>">
                <span class="cms-ecal__legend-item"><span class="cms-ecal__swatch cms-ecal__swatch--scheduled" aria-hidden="true"></span><?php echo $calendarEsc(__('calendar.kind.scheduled')); ?></span>
                <span class="cms-ecal__legend-item"><span class="cms-ecal__swatch cms-ecal__swatch--planned" aria-hidden="true"></span><?php echo $calendarEsc(__('calendar.kind.planned')); ?></span>
                <span class="cms-ecal__legend-item"><span class="cms-ecal__swatch cms-ecal__swatch--published" aria-hidden="true"></span><?php echo $calendarEsc(__('calendar.kind.published')); ?></span>
                <span class="cms-ecal__legend-item"><i class="ti ti-article" aria-hidden="true"></i><?php echo $calendarEsc(__('calendar.type.post')); ?></span>
                <span class="cms-ecal__legend-item"><i class="ti ti-file" aria-hidden="true"></i><?php echo $calendarEsc(__('calendar.type.page')); ?></span>
                <span class="cms-ecal__legend-hint text-secondary"><?php echo $calendarEsc(__('calendar.legend.move_hint')); ?></span>
            </div>

            <div class="card-body cms-ecal__body">
                <div class="cms-ecal__notice" data-ecal-notice></div>
                <div class="cms-ecal__grid-wrap">
                    <div class="cms-ecal__grid" id="cmsEcalGrid" aria-busy="true"></div>
                </div>
                <noscript>
                    <div class="alert alert-warning mb-0"><?php echo $calendarEsc(__('calendar.noscript')); ?></div>
                </noscript>
            </div>
        </div>

        <div class="visually-hidden" aria-live="polite" id="cmsEcalLive"></div>

        <div class="cms-ecal-popover card shadow" id="cmsEcalPopover" role="dialog" aria-modal="false" aria-labelledby="cmsEcalPopoverTitle" hidden>
            <form class="card-body p-3" id="cmsEcalPopoverForm" novalidate>
                <h4 class="mb-1" id="cmsEcalPopoverTitle"><?php echo $calendarEsc(__('calendar.move.popover_title')); ?></h4>
                <p class="text-secondary small mb-2" id="cmsEcalPopoverItem"></p>
                <label class="form-label" for="cmsEcalPopoverDate"><?php echo $calendarEsc(__('calendar.move.new_date')); ?></label>
                <input type="date" class="form-control mb-3" id="cmsEcalPopoverDate" required>
                <div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn" data-ecal-popover-cancel><?php echo $calendarEsc(__('calendar.move.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo $calendarEsc(__('calendar.move.submit')); ?></button>
                </div>
            </form>
        </div>

        <script type="application/json" id="cmsEditorialCalendarConfig"><?php echo json_encode($calendarConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); ?></script>
    </div>
</div>
