<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formulare – globale Einstellungen (Standards, die jedes Formular erbt).
 *
 * @var array<string, mixed> $fmData
 * @var string $csrfToken
 * @var callable $fmE
 * @var callable $fmT
 */

$fmS = is_array($fmData['settings'] ?? null) ? $fmData['settings'] : [];
$fmChoices = is_array($fmData['form_choices'] ?? null) ? $fmData['form_choices'] : [];
$fmRetentionLimits = is_array($fmData['retention_limits'] ?? null) ? $fmData['retention_limits'] : ['min' => 7, 'max' => 3650, 'default' => 365];
$fmMailDefaults = is_array($fmData['mail_defaults'] ?? null) ? $fmData['mail_defaults'] : [];
$fmRetentionUrl = (string) ($fmData['data_retention_url'] ?? '');
$fmVal = static fn (string $key): string => is_scalar($fmS[$key] ?? null) ? (string) $fmS[$key] : '';
$fmInput = static function (string $key, string $labelKey, string $type = 'text', int $max = 255, string $hintKey = '', string $placeholder = '') use ($fmVal, $fmE, $fmT): string {
    $id = 'fmGlobal_' . $key;

    return '<label class="form-label" for="' . $fmE($id) . '">' . $fmT($labelKey) . '</label>'
        . '<input type="' . $fmE($type) . '" class="form-control" id="' . $fmE($id) . '" name="forms[' . $fmE($key) . ']" maxlength="' . $max . '"'
        . ' value="' . $fmE($fmVal($key)) . '"' . ($placeholder !== '' ? ' placeholder="' . $fmE($placeholder) . '"' : '')
        . ($hintKey !== '' ? ' aria-describedby="' . $fmE($id) . 'Hint"' : '') . '>'
        . ($hintKey !== '' ? '<div class="form-hint" id="' . $fmE($id) . 'Hint">' . $fmT($hintKey) . '</div>' : '');
};
$fmLocales = ['de' => 'forms.admin.lang_de', 'en' => 'forms.admin.lang_en'];
$fmMailTemplates = [
    'notification_subject' => ['forms.settings.mail_notification_subject', false],
    'notification_body' => ['forms.settings.mail_notification_body', true],
    'confirmation_subject' => ['forms.settings.mail_confirmation_subject', false],
    'confirmation_body' => ['forms.settings.mail_confirmation_body', true],
];
?>

<form method="post" action="/admin/forms?tab=settings">
    <input type="hidden" name="csrf_token" value="<?= $fmE($csrfToken) ?>">
    <input type="hidden" name="action" value="save_settings">

    <div class="card mb-4">
        <div class="card-header"><h3 class="card-title"><?= $fmT('forms.settings.section_general') ?></h3></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="fmGlobal_contact_form_key"><?= $fmT('forms.settings.contact_form') ?></label>
                    <select class="form-select" id="fmGlobal_contact_form_key" name="forms[contact_form_key]" aria-describedby="fmGlobalContactHint">
                        <?php foreach ($fmChoices as $fmChoiceKey => $fmChoiceTitle): ?>
                            <option value="<?= $fmE($fmChoiceKey) ?>"<?= $fmVal('contact_form_key') === (string) $fmChoiceKey ? ' selected' : '' ?>><?= $fmE($fmChoiceTitle) ?> (<?= $fmE($fmChoiceKey) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint" id="fmGlobalContactHint"><?= $fmT('forms.settings.contact_form_hint') ?></div>
                </div>
                <div class="col-md-6"><?= $fmInput('default_recipient', 'forms.settings.default_recipient', 'email', 254, 'forms.settings.default_recipient_hint') ?></div>
                <div class="col-md-6"><?= $fmInput('from_name', 'forms.settings.from_name', 'text', 150, 'forms.settings.from_name_hint') ?></div>
                <div class="col-md-6"><?= $fmInput('from_email', 'forms.settings.from_email', 'email', 190, 'forms.settings.from_email_hint') ?></div>
                <div class="col-md-6">
                    <label class="form-label" for="fmGlobal_retention_days"><?= $fmT('forms.settings.retention') ?></label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="fmGlobal_retention_days" name="forms[retention_days]" min="0" max="<?= (int) $fmRetentionLimits['max'] ?>" step="1"
                               value="<?= (int) ($fmS['retention_days'] ?? $fmRetentionLimits['default']) ?>" aria-describedby="fmGlobalRetentionHint">
                        <span class="input-group-text"><?= $fmT('forms.admin.days') ?></span>
                    </div>
                    <div class="form-hint" id="fmGlobalRetentionHint">
                        <?= $fmT('forms.settings.retention_hint', (int) $fmRetentionLimits['min'], (int) $fmRetentionLimits['max']) ?>
                        <?php if ($fmRetentionUrl !== ''): ?><a href="<?= $fmE($fmRetentionUrl) ?>"><?= $fmT('forms.settings.retention_overview') ?></a><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h3 class="card-title"><?= $fmT('forms.settings.section_privacy') ?></h3></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6"><?= $fmInput('privacy_url', 'forms.settings.privacy_url', 'text', 500, 'forms.settings.privacy_url_hint', '/datenschutz') ?></div>
                <div class="col-12"></div>
                <?php foreach ($fmLocales as $fmLocale => $fmLocaleLabel): ?>
                    <div class="col-md-6">
                        <label class="form-label" for="fmGlobal_consent_<?= $fmLocale ?>"><?= $fmT('forms.settings.consent_text') ?> (<?= $fmT($fmLocaleLabel) ?>)</label>
                        <textarea class="form-control" rows="3" id="fmGlobal_consent_<?= $fmLocale ?>" name="forms[consent_text_<?= $fmLocale ?>]" maxlength="1000" lang="<?= $fmLocale ?>"
                                  placeholder="<?= $fmE(\CMS\Services\Forms\FormI18n::t('forms.consent.default_text', $fmLocale)) ?>"><?= $fmE($fmVal('consent_text_' . $fmLocale)) ?></textarea>
                    </div>
                <?php endforeach; ?>
                <div class="col-12"><div class="form-hint"><?= $fmT('forms.settings.consent_hint') ?></div></div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h3 class="card-title"><?= $fmT('forms.settings.section_uploads') ?></h3></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="fmGlobal_upload_max_kb"><?= $fmT('forms.settings.upload_max') ?></label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="fmGlobal_upload_max_kb" name="forms[upload_max_kb]" min="1" max="<?= (int) ($fmData['upload_limits']['max_kb'] ?? 51200) ?>" step="1"
                               value="<?= (int) ($fmS['upload_max_kb'] ?? 5120) ?>">
                        <span class="input-group-text">KB</span>
                    </div>
                </div>
                <div class="col-md-8"><?= $fmInput('upload_types', 'forms.settings.upload_types', 'text', 500, 'forms.settings.upload_types_hint') ?></div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h3 class="card-title"><?= $fmT('forms.settings.section_mail') ?></h3></div>
        <div class="card-body">
            <p class="text-secondary"><?= $fmT('forms.settings.mail_intro') ?> <code>{form_title} {site_name} {site_url} {date} {name} {email} {subject} {message} {fields} {page_url} {admin_url} {from_name} {field:feldname}</code></p>
            <?php foreach ($fmMailTemplates as $fmTemplate => [$fmTemplateLabel, $fmTemplateMultiline]): ?>
                <fieldset class="mb-3">
                    <legend class="form-label"><?= $fmT($fmTemplateLabel) ?></legend>
                    <div class="row g-2">
                        <?php foreach ($fmLocales as $fmLocale => $fmLocaleLabel): ?>
                            <?php $fmMailKey = 'mail_' . $fmTemplate . '_' . $fmLocale; ?>
                            <div class="col-md-6">
                                <label class="form-label small text-secondary mb-1" for="fmGlobal_<?= $fmE($fmMailKey) ?>"><?= $fmT($fmLocaleLabel) ?></label>
                                <?php if ($fmTemplateMultiline): ?>
                                    <textarea class="form-control" rows="7" id="fmGlobal_<?= $fmE($fmMailKey) ?>" name="forms[<?= $fmE($fmMailKey) ?>]" maxlength="10000" lang="<?= $fmLocale ?>"
                                              placeholder="<?= $fmE((string) ($fmMailDefaults[$fmMailKey] ?? '')) ?>"><?= $fmE($fmVal($fmMailKey)) ?></textarea>
                                <?php else: ?>
                                    <input type="text" class="form-control" id="fmGlobal_<?= $fmE($fmMailKey) ?>" name="forms[<?= $fmE($fmMailKey) ?>]" maxlength="300" lang="<?= $fmLocale ?>"
                                           placeholder="<?= $fmE((string) ($fmMailDefaults[$fmMailKey] ?? '')) ?>" value="<?= $fmE($fmVal($fmMailKey)) ?>">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php endforeach; ?>
            <div class="form-hint"><?= $fmT('forms.settings.mail_empty_hint') ?></div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h3 class="card-title"><?= $fmT('forms.settings.section_design') ?></h3></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label" for="fmGlobal_accent_color"><?= $fmT('forms.settings.accent_color') ?></label>
                    <input type="text" class="form-control" id="fmGlobal_accent_color" name="forms[accent_color]" maxlength="7" pattern="#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?"
                           placeholder="#2563eb" value="<?= $fmE($fmVal('accent_color')) ?>" aria-describedby="fmGlobalAccentHint">
                    <div class="form-hint" id="fmGlobalAccentHint"><?= $fmT('forms.settings.accent_color_hint') ?></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="fmGlobal_radius"><?= $fmT('forms.settings.radius') ?></label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="fmGlobal_radius" name="forms[radius]" min="0" max="32" step="1" value="<?= (int) ($fmS['radius'] ?? 8) ?>">
                        <span class="input-group-text">px</span>
                    </div>
                </div>
                <div class="col-12"><p class="text-secondary mb-0"><?= $fmT('forms.settings.company_intro') ?></p></div>
                <div class="col-md-6"><?= $fmInput('company_name', 'forms.settings.company_name', 'text', 200) ?></div>
                <div class="col-md-6"><?= $fmInput('company_email', 'forms.settings.company_email', 'email', 254) ?></div>
                <div class="col-md-6"><?= $fmInput('company_phone', 'forms.settings.company_phone', 'text', 64) ?></div>
                <div class="col-md-6"><?= $fmInput('map_url', 'forms.settings.map_url', 'url', 500, 'forms.settings.map_url_hint') ?></div>
                <div class="col-12">
                    <label class="form-label" for="fmGlobal_company_address"><?= $fmT('forms.settings.company_address') ?></label>
                    <textarea class="form-control" rows="3" id="fmGlobal_company_address" name="forms[company_address]" maxlength="500"><?= $fmE($fmVal('company_address')) ?></textarea>
                </div>
            </div>
        </div>
        <div class="card-footer text-end">
            <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1" aria-hidden="true"></i><?= $fmT('forms.admin.save') ?></button>
        </div>
    </div>
</form>
