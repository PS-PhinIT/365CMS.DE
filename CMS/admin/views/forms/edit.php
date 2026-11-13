<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formulare – Baukasten: Felder, Texte, Mails, Spam-Schutz und Aufbewahrung eines Formulars.
 *
 * Die Felder verwaltet assets/js/admin-form-builder.js und übergibt sie beim Speichern als JSON (`fields_json`).
 * Ohne JavaScript bleiben die Felder unverändert; alle übrigen Einstellungen lassen sich trotzdem speichern.
 *
 * @var array<string, mixed> $fmData
 * @var string $csrfToken
 * @var callable $fmE
 * @var callable $fmT
 * @var callable $fmDays
 */

$fmForm = is_array($fmData['form'] ?? null) ? $fmData['form'] : [];
$fmSettings = is_array($fmForm['settings'] ?? null) ? $fmForm['settings'] : [];
$fmId = (int) ($fmForm['id'] ?? 0);
$fmBuiltin = !empty($fmForm['is_builtin']);
$fmCounts = is_array($fmData['counts'] ?? null) ? $fmData['counts'] : [];
$fmLimits = is_array($fmData['retention_limits'] ?? null) ? $fmData['retention_limits'] : ['min' => 7, 'max' => 3650];
$fmRetention = $fmSettings['retention_days'] ?? null;
$fmRetentionMode = $fmRetention === null ? 'inherit' : ((int) $fmRetention === 0 ? 'never' : 'custom');
$fmConfirmation = is_array($fmSettings['confirmation'] ?? null) ? $fmSettings['confirmation'] : [];
$fmLocales = ['de' => 'forms.admin.lang_de', 'en' => 'forms.admin.lang_en'];
$fmChecked = static fn (bool $on): string => $on ? ' checked' : '';
$fmSelected = static fn (bool $on): string => $on ? ' selected' : '';
$fmPick = static fn (mixed $value, string $locale): string => is_array($value) ? (string) ($value[$locale] ?? '') : '';
$fmBilingual = static function (string $key, string $labelKey, bool $multiline = false, int $max = 200, string $hintKey = '') use ($fmSettings, $fmLocales, $fmE, $fmT, $fmPick): string {
    $html = '<fieldset class="mb-3"><legend class="form-label">' . $fmT($labelKey) . '</legend><div class="row g-2">';
    foreach ($fmLocales as $locale => $localeLabel) {
        $id = 'fmSetting_' . $key . '_' . $locale;
        $name = 'settings[' . $key . '][' . $locale . ']';
        $value = $fmPick($fmSettings[$key] ?? [], $locale);
        $html .= '<div class="col-md-6"><label class="form-label small text-secondary mb-1" for="' . $fmE($id) . '">' . $fmT($localeLabel) . '</label>';
        $html .= $multiline
            ? '<textarea class="form-control" rows="3" id="' . $fmE($id) . '" name="' . $fmE($name) . '" maxlength="' . $max . '" lang="' . $locale . '">' . $fmE($value) . '</textarea>'
            : '<input type="text" class="form-control" id="' . $fmE($id) . '" name="' . $fmE($name) . '" maxlength="' . $max . '" lang="' . $locale . '" value="' . $fmE($value) . '">';
        $html .= '</div>';
    }
    $html .= '</div>' . ($hintKey !== '' ? '<div class="form-hint">' . $fmT($hintKey) . '</div>' : '') . '</fieldset>';

    return $html;
};
$fmPanes = [
    'fields' => 'forms.admin.pane_fields',
    'texts' => 'forms.admin.pane_texts',
    'mail' => 'forms.admin.pane_mail',
    'protection' => 'forms.admin.pane_protection',
    'embed' => 'forms.admin.pane_embed',
];
?>

<form method="post" action="/admin/forms?action=edit&amp;id=<?= $fmId ?>" id="fmEditForm" data-form-builder-form>
    <input type="hidden" name="csrf_token" value="<?= $fmE($csrfToken) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $fmId ?>">

    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label required" for="fmTitle"><?= $fmT('forms.admin.field_title') ?></label>
                    <input type="text" class="form-control" id="fmTitle" name="title" maxlength="255" required value="<?= $fmE((string) ($fmForm['title'] ?? '')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="fmKey"><?= $fmT('forms.admin.field_key') ?></label>
                    <input type="text" class="form-control" id="fmKey" name="form_key" maxlength="64" pattern="[a-z0-9][a-z0-9\-]{0,63}"
                           value="<?= $fmE((string) ($fmForm['form_key'] ?? '')) ?>"<?= $fmBuiltin ? ' readonly' : '' ?> aria-describedby="fmKeyHint">
                    <div class="form-hint" id="fmKeyHint"><?= $fmT($fmBuiltin ? 'forms.admin.field_key_builtin' : 'forms.admin.field_key_edit_hint') ?></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="fmStatus"><?= $fmT('forms.admin.col_status') ?></label>
                    <select class="form-select" id="fmStatus" name="status">
                        <option value="active"<?= $fmSelected(($fmForm['status'] ?? '') === 'active') ?>><?= $fmT('forms.admin.status_active') ?></option>
                        <option value="inactive"<?= $fmSelected(($fmForm['status'] ?? '') === 'inactive') ?>><?= $fmT('forms.admin.status_inactive') ?></option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs" role="tablist">
                <?php $fmFirst = true; foreach ($fmPanes as $fmPaneKey => $fmPaneLabel): ?>
                    <li class="nav-item" role="presentation">
                        <button type="button" class="nav-link<?= $fmFirst ? ' active' : '' ?>" id="fmPaneTab_<?= $fmE($fmPaneKey) ?>" data-bs-toggle="tab"
                                data-bs-target="#fmPane_<?= $fmE($fmPaneKey) ?>" role="tab" aria-controls="fmPane_<?= $fmE($fmPaneKey) ?>"
                                aria-selected="<?= $fmFirst ? 'true' : 'false' ?>"><?= $fmT($fmPaneLabel) ?></button>
                    </li>
                <?php $fmFirst = false; endforeach; ?>
            </ul>
        </div>
        <div class="card-body">
            <div class="tab-content">

                <div class="tab-pane active show" id="fmPane_fields" role="tabpanel" aria-labelledby="fmPaneTab_fields" tabindex="0">
                    <noscript><div class="alert alert-warning"><?= $fmT('forms.admin.builder_noscript') ?></div></noscript>
                    <div class="cms-fb" id="cmsFormBuilder" hidden>
                        <div class="cms-fb__toolbar">
                            <label class="form-label mb-0" for="cmsFormBuilderType"><?= $fmT('forms.builder.add_field') ?></label>
                            <select class="form-select w-auto" id="cmsFormBuilderType"></select>
                            <button type="button" class="btn btn-primary" data-fb-add><i class="ti ti-plus me-1" aria-hidden="true"></i><?= $fmT('forms.builder.add') ?></button>
                            <span class="text-secondary small ms-auto" data-fb-count></span>
                        </div>
                        <p class="text-secondary small"><?= $fmT('forms.builder.intro') ?></p>
                        <ol class="cms-fb__list" data-fb-list aria-label="<?= $fmT('forms.admin.pane_fields') ?>"></ol>
                        <p class="text-secondary" data-fb-empty hidden><?= $fmT('forms.builder.empty_hint') ?></p>
                        <div class="visually-hidden" role="status" aria-live="polite" data-fb-live></div>
                    </div>
                    <script type="application/json" id="cmsFormBuilderConfig"><?= json_encode($fmData['builder'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
                </div>

                <div class="tab-pane" id="fmPane_texts" role="tabpanel" aria-labelledby="fmPaneTab_texts" tabindex="0">
                    <?= $fmBilingual('heading', 'forms.admin.setting_heading', false, 200) ?>
                    <?= $fmBilingual('intro', 'forms.admin.setting_intro', true, 20000, 'forms.admin.setting_richtext_hint') ?>
                    <?= $fmBilingual('footer', 'forms.admin.setting_footer', true, 20000) ?>
                    <?= $fmBilingual('submit_label', 'forms.admin.setting_submit_label', false, 100) ?>
                    <?= $fmBilingual('success_message', 'forms.admin.setting_success_message', true, 2000) ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="fmRedirect"><?= $fmT('forms.admin.setting_redirect') ?></label>
                            <input type="text" class="form-control" id="fmRedirect" name="settings[redirect_url]" maxlength="500" placeholder="/danke"
                                   value="<?= $fmE((string) ($fmSettings['redirect_url'] ?? '')) ?>" aria-describedby="fmRedirectHint">
                            <div class="form-hint" id="fmRedirectHint"><?= $fmT('forms.admin.setting_redirect_hint') ?></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="fmLayout"><?= $fmT('forms.admin.setting_layout') ?></label>
                            <select class="form-select" id="fmLayout" name="settings[layout]">
                                <?php foreach (\CMS\Services\Forms\FormService::LAYOUTS as $fmLayoutKey): ?>
                                    <option value="<?= $fmE($fmLayoutKey) ?>"<?= $fmSelected(($fmSettings['layout'] ?? 'standard') === $fmLayoutKey) ?>><?= $fmT('forms.admin.layout_' . $fmLayoutKey) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <label class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="settings[show_company_info]" value="1"<?= $fmChecked(!empty($fmSettings['show_company_info'])) ?>>
                                <span class="form-check-label"><?= $fmT('forms.admin.setting_company_info') ?></span>
                            </label>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label" for="fmCss"><?= $fmT('forms.admin.setting_css') ?></label>
                        <textarea class="form-control font-monospace" rows="4" id="fmCss" name="settings[custom_css]" maxlength="4000" spellcheck="false" aria-describedby="fmCssHint"><?= $fmE((string) ($fmSettings['custom_css'] ?? '')) ?></textarea>
                        <div class="form-hint" id="fmCssHint"><?= $fmT('forms.admin.setting_css_hint', (string) ($fmForm['form_key'] ?? '')) ?></div>
                    </div>
                </div>

                <div class="tab-pane" id="fmPane_mail" role="tabpanel" aria-labelledby="fmPaneTab_mail" tabindex="0">
                    <label class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="settings[notify]" value="1"<?= $fmChecked(!empty($fmSettings['notify'])) ?>>
                        <span class="form-check-label"><?= $fmT('forms.admin.setting_notify') ?></span>
                    </label>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label" for="fmRecipients"><?= $fmT('forms.admin.setting_recipients') ?></label>
                            <input type="text" class="form-control" id="fmRecipients" name="settings[recipients]" maxlength="2000"
                                   value="<?= $fmE(implode(', ', (array) ($fmSettings['recipients'] ?? []))) ?>" aria-describedby="fmRecipientsHint">
                            <div class="form-hint" id="fmRecipientsHint">
                                <?= (string) ($fmData['default_recipient'] ?? '') !== ''
                                    ? $fmT('forms.admin.setting_recipients_hint', (string) $fmData['default_recipient'])
                                    : $fmT('forms.admin.setting_recipients_missing') ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="fmCc">CC</label>
                            <input type="text" class="form-control" id="fmCc" name="settings[cc]" maxlength="2000" value="<?= $fmE(implode(', ', (array) ($fmSettings['cc'] ?? []))) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="fmBcc">BCC</label>
                            <input type="text" class="form-control" id="fmBcc" name="settings[bcc]" maxlength="2000" value="<?= $fmE(implode(', ', (array) ($fmSettings['bcc'] ?? []))) ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="fmMailSubject"><?= $fmT('forms.admin.setting_mail_subject') ?></label>
                            <input type="text" class="form-control" id="fmMailSubject" name="settings[mail_subject]" maxlength="300"
                                   value="<?= $fmE((string) ($fmSettings['mail_subject'] ?? '')) ?>" aria-describedby="fmMailSubjectHint">
                            <div class="form-hint" id="fmMailSubjectHint"><?= $fmT('forms.admin.setting_mail_subject_hint') ?></div>
                        </div>
                        <div class="col-md-4 d-flex align-items-center">
                            <label class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="settings[reply_to_sender]" value="1"<?= $fmChecked(!empty($fmSettings['reply_to_sender'])) ?>>
                                <span class="form-check-label"><?= $fmT('forms.admin.setting_reply_to') ?></span>
                            </label>
                        </div>
                    </div>

                    <h4 class="mt-4"><?= $fmT('forms.admin.confirmation_title') ?></h4>
                    <label class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="settings[confirmation][enabled]" value="1"<?= $fmChecked(!empty($fmConfirmation['enabled'])) ?>>
                        <span class="form-check-label"><?= $fmT('forms.admin.confirmation_enabled') ?></span>
                    </label>
                    <div class="form-hint mb-3"><?= $fmT('forms.admin.confirmation_hint') ?></div>
                    <div class="row g-3">
                        <?php foreach ($fmLocales as $fmLocale => $fmLocaleLabel): ?>
                            <div class="col-md-6">
                                <label class="form-label" for="fmConfSubject_<?= $fmLocale ?>"><?= $fmT('forms.admin.confirmation_subject') ?> (<?= $fmT($fmLocaleLabel) ?>)</label>
                                <input type="text" class="form-control mb-2" id="fmConfSubject_<?= $fmLocale ?>" name="settings[confirmation][subject][<?= $fmLocale ?>]" maxlength="300" lang="<?= $fmLocale ?>"
                                       value="<?= $fmE($fmPick($fmConfirmation['subject'] ?? [], $fmLocale)) ?>">
                                <label class="form-label" for="fmConfBody_<?= $fmLocale ?>"><?= $fmT('forms.admin.confirmation_body') ?> (<?= $fmT($fmLocaleLabel) ?>)</label>
                                <textarea class="form-control" rows="6" id="fmConfBody_<?= $fmLocale ?>" name="settings[confirmation][body][<?= $fmLocale ?>]" maxlength="10000" lang="<?= $fmLocale ?>"><?= $fmE($fmPick($fmConfirmation['body'] ?? [], $fmLocale)) ?></textarea>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-hint mt-2"><?= $fmT('forms.admin.mail_placeholders') ?> <code>{form_title} {site_name} {site_url} {date} {name} {email} {subject} {message} {fields} {page_url} {field:feldname}</code></div>
                </div>

                <div class="tab-pane" id="fmPane_protection" role="tabpanel" aria-labelledby="fmPaneTab_protection" tabindex="0">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="fmAntispam"><?= $fmT('forms.admin.setting_antispam') ?></label>
                            <select class="form-select" id="fmAntispam" name="settings[antispam_level]" aria-describedby="fmAntispamHint">
                                <?php foreach (\CMS\Services\Forms\FormService::ANTISPAM_LEVELS as $fmLevel): ?>
                                    <option value="<?= $fmE($fmLevel) ?>"<?= $fmSelected(($fmSettings['antispam_level'] ?? 'standard') === $fmLevel) ?>><?= $fmT('forms.admin.antispam_' . $fmLevel) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-hint" id="fmAntispamHint"><?= $fmT('forms.admin.setting_antispam_hint') ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="fmRateLimit"><?= $fmT('forms.admin.setting_rate_limit') ?></label>
                            <input type="number" class="form-control" id="fmRateLimit" name="settings[rate_limit]" min="0" max="1000" step="1"
                                   value="<?= (int) ($fmSettings['rate_limit'] ?? 5) ?>" aria-describedby="fmRateLimitHint">
                            <div class="form-hint" id="fmRateLimitHint"><?= $fmT('forms.admin.setting_rate_limit_hint') ?></div>
                        </div>
                    </div>

                    <h4 class="mt-4"><?= $fmT('forms.admin.storage_title') ?></h4>
                    <label class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="settings[store_submissions]" value="1"<?= $fmChecked(!empty($fmSettings['store_submissions'])) ?>>
                        <span class="form-check-label"><?= $fmT('forms.admin.setting_store') ?></span>
                    </label>
                    <fieldset>
                        <legend class="form-label"><?= $fmT('forms.admin.setting_retention') ?></legend>
                        <label class="form-check">
                            <input class="form-check-input" type="radio" name="settings[retention_mode]" value="inherit"<?= $fmChecked($fmRetentionMode === 'inherit') ?>>
                            <span class="form-check-label"><?= $fmT('forms.admin.retention_inherit', $fmDays((int) ($fmData['global_retention_days'] ?? 0))) ?></span>
                        </label>
                        <label class="form-check">
                            <input class="form-check-input" type="radio" name="settings[retention_mode]" value="custom"<?= $fmChecked($fmRetentionMode === 'custom') ?>>
                            <span class="form-check-label"><?= $fmT('forms.admin.retention_custom') ?></span>
                        </label>
                        <div class="input-group w-auto ms-4 mb-2 cms-forms-retention-input">
                            <input type="number" class="form-control" name="settings[retention_days]" min="<?= (int) $fmLimits['min'] ?>" max="<?= (int) $fmLimits['max'] ?>" step="1"
                                   value="<?= $fmRetentionMode === 'custom' ? (int) $fmRetention : (int) ($fmData['global_retention_days'] ?? 365) ?>"
                                   aria-label="<?= $fmT('forms.admin.retention_custom') ?>">
                            <span class="input-group-text"><?= $fmT('forms.admin.days') ?></span>
                        </div>
                        <label class="form-check">
                            <input class="form-check-input" type="radio" name="settings[retention_mode]" value="never"<?= $fmChecked($fmRetentionMode === 'never') ?>>
                            <span class="form-check-label"><?= $fmT('forms.admin.retention_never_option') ?></span>
                        </label>
                        <div class="form-hint"><?= $fmT('forms.admin.setting_retention_hint', (int) $fmLimits['min'], (int) $fmLimits['max']) ?></div>
                    </fieldset>
                </div>

                <div class="tab-pane" id="fmPane_embed" role="tabpanel" aria-labelledby="fmPaneTab_embed" tabindex="0">
                    <dl class="row mb-0">
                        <dt class="col-sm-3"><?= $fmT('forms.admin.embed_url') ?></dt>
                        <dd class="col-sm-9">
                            <a href="<?= $fmE((string) ($fmData['public_url'] ?? '')) ?>" target="_blank" rel="noopener"><code><?= $fmE((string) ($fmData['public_url'] ?? '')) ?></code></a>
                            · <a href="<?= $fmE((string) ($fmData['public_url_en'] ?? '')) ?>" target="_blank" rel="noopener"><code><?= $fmE((string) ($fmData['public_url_en'] ?? '')) ?></code></a>
                            <?php if (($fmForm['status'] ?? '') !== 'active'): ?>
                                <span class="badge bg-secondary-lt ms-1"><?= $fmT('forms.admin.embed_inactive') ?></span>
                            <?php endif; ?>
                        </dd>
                        <dt class="col-sm-3"><?= $fmT('forms.admin.embed_block') ?></dt>
                        <dd class="col-sm-9"><?= $fmT('forms.admin.embed_block_text') ?></dd>
                        <dt class="col-sm-3"><?= $fmT('forms.admin.embed_contact') ?></dt>
                        <dd class="col-sm-9">
                            <?= $fmT(!empty($fmData['is_contact_form']) ? 'forms.admin.embed_contact_yes' : 'forms.admin.embed_contact_no') ?>
                            <a href="/admin/forms?tab=settings"><?= $fmT('forms.admin.tab_settings') ?></a>
                        </dd>
                        <dt class="col-sm-3"><?= $fmT('forms.admin.embed_api') ?></dt>
                        <dd class="col-sm-9"><code>POST /api/contact/<?= $fmE((string) ($fmForm['form_key'] ?? '')) ?>/submit</code></dd>
                        <dt class="col-sm-3"><?= $fmT('forms.admin.col_submissions') ?></dt>
                        <dd class="col-sm-9">
                            <?= $fmT('forms.admin.embed_counts', (int) ($fmCounts['new'] ?? 0), (int) ($fmCounts['read'] ?? 0), (int) ($fmCounts['done'] ?? 0)) ?>
                            <?php if ((string) ($fmData['submissions_url'] ?? '') !== ''): ?>
                                <a href="<?= $fmE((string) $fmData['submissions_url']) ?>"><?= $fmT('forms.admin.open_submissions') ?></a>
                            <?php endif; ?>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex align-items-center gap-3">
            <span class="text-warning small" data-fb-unsaved hidden><i class="ti ti-alert-triangle me-1" aria-hidden="true"></i><?= $fmT('forms.builder.unsaved') ?></span>
            <a class="btn ms-auto" href="/admin/forms"><?= $fmT('forms.admin.cancel') ?></a>
            <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1" aria-hidden="true"></i><?= $fmT('forms.admin.save') ?></button>
        </div>
    </div>
</form>
