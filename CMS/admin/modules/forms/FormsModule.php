<?php
declare(strict_types=1);

/**
 * Formulare – Admin-Logik für Liste, Baukasten (Felder und Einstellungen je Formular) und globale Einstellungen.
 *
 * Alle Eingaben laufen durch die Normalisierung der Services (`FormService`, `FormFieldTypes`, `FormSettings`);
 * dieses Modul ordnet nur Formularfelder zu, prüft Aktionen und protokolliert Änderungen.
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\AuditLogger;
use CMS\Services\Forms\FormFieldTypes;
use CMS\Services\Forms\FormFrontendController;
use CMS\Services\Forms\FormI18n;
use CMS\Services\Forms\FormService;
use CMS\Services\Forms\FormSettings;
use CMS\Services\Forms\FormSubmissionService;

class FormsModule
{
    public const TABS = ['forms', 'settings'];
    public const LIST_FILTERS = ['all', 'active', 'inactive', 'trash'];
    public const ACTIONS = [
        'create', 'save', 'duplicate', 'activate', 'deactivate', 'trash', 'restore', 'delete', 'save_settings',
    ];
    public const TEMPLATES = ['blank', 'contact'];
    public const MAX_FIELDS_JSON_BYTES = 262144;

    private FormService $forms;
    private FormSubmissionService $submissions;

    public function __construct()
    {
        $this->forms = FormService::getInstance();
        $this->submissions = FormSubmissionService::getInstance();
    }

    public function isAvailable(): bool
    {
        return $this->forms->isAvailable();
    }

    public static function url(array $query = []): string
    {
        $query = array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== 0);

        return '/admin/forms' . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function getListData(array $query): array
    {
        $filter = in_array($query['status'] ?? '', self::LIST_FILTERS, true) ? (string) $query['status'] : 'all';
        $search = FormI18n::cleanText(is_scalar($query['q'] ?? null) ? (string) $query['q'] : '', 100);
        $available = $this->isAvailable();
        if ($available) {
            // Stellt sicher, dass das eingebaute Kontaktformular in der Liste erscheint.
            $this->forms->ensureContactForm();
        }

        $listFilter = ['search' => $search];
        if ($filter !== 'all') {
            $listFilter['status'] = $filter;
        }
        $forms = $available ? $this->forms->list($listFilter, 0, 500) : [];
        $counts = $available ? $this->submissions->countsPerForm() : [];

        $rows = [];
        foreach ($forms as $form) {
            $inputs = 0;
            foreach ($form['fields'] as $field) {
                if (FormFieldTypes::isInput((string) $field['type'])) {
                    $inputs++;
                }
            }
            $id = (int) $form['id'];
            $rows[] = [
                'id' => $id,
                'title' => (string) $form['title'],
                'form_key' => (string) $form['form_key'],
                'status' => (string) $form['status'],
                'is_builtin' => (bool) $form['is_builtin'],
                'field_count' => $inputs,
                'submissions' => (int) ($counts[$id]['total'] ?? 0),
                'submissions_new' => (int) ($counts[$id]['new'] ?? 0),
                'retention_days' => FormSubmissionService::effectiveRetentionDays($form),
                'retention_inherited' => ($form['settings']['retention_days'] ?? null) === null,
                'store_submissions' => !empty($form['settings']['store_submissions']),
                'public_url' => FormFrontendController::formPath((string) $form['form_key'], 'de'),
                'updated_at' => (string) $form['updated_at'],
                'trashed_at' => (string) ($form['trashed_at'] ?? ''),
            ];
        }

        return [
            'tab' => 'forms',
            'view' => 'list',
            'available' => $available,
            'filter' => $filter,
            'search' => $search,
            'forms' => $rows,
            'counts' => [
                'all' => $available ? $this->forms->count() : 0,
                'active' => $available ? $this->forms->count(['status' => FormService::STATUS_ACTIVE]) : 0,
                'inactive' => $available ? $this->forms->count(['status' => FormService::STATUS_INACTIVE]) : 0,
                'trash' => $available ? $this->forms->count(['status' => FormService::STATUS_TRASH]) : 0,
            ],
            'contact_form_key' => FormSettings::string('contact_form_key', FormService::CONTACT_FORM_KEY),
            'submissions_url' => is_file(ABSPATH . 'admin/form-submissions.php') ? '/admin/form-submissions' : '',
        ];
    }

    /**
     * @return array<string, mixed>|null null = Formular nicht gefunden
     */
    public function getEditData(int $id): ?array
    {
        $form = $this->isAvailable() ? $this->forms->find($id) : null;
        if ($form === null || $form['status'] === FormService::STATUS_TRASH) {
            return null;
        }

        $typeLabels = [];
        foreach (FormFieldTypes::labelKeys() as $type => $key) {
            $typeLabels[$type] = __($key);
        }
        $limits = FormSettings::uploadLimits();

        return [
            'tab' => 'forms',
            'view' => 'edit',
            'available' => true,
            'form' => $form,
            'counts' => $this->submissions->countByStatus($id),
            'public_url' => FormFrontendController::formPath((string) $form['form_key'], 'de'),
            'public_url_en' => FormFrontendController::formPath((string) $form['form_key'], 'en'),
            'is_contact_form' => FormSettings::string('contact_form_key', FormService::CONTACT_FORM_KEY) === $form['form_key'],
            'global_retention_days' => FormSettings::retentionDays(),
            'retention_limits' => ['min' => FormSettings::MIN_RETENTION_DAYS, 'max' => FormSettings::MAX_RETENTION_DAYS],
            'default_recipient' => \CMS\Services\Forms\FormMailer::recipients(['settings' => ['recipients' => []]])[0] ?? '',
            'submissions_url' => is_file(ABSPATH . 'admin/form-submissions.php') ? '/admin/form-submissions?form=' . $id : '',
            'builder' => [
                'fields' => $form['fields'],
                'types' => $typeLabels,
                'staticTypes' => FormFieldTypes::STATIC_TYPES,
                'optionTypes' => FormFieldTypes::OPTION_TYPES,
                'widths' => FormFieldTypes::WIDTHS,
                'roles' => FormFieldTypes::ROLES,
                'maxFields' => FormFieldTypes::MAX_FIELDS,
                'maxOptions' => FormFieldTypes::MAX_OPTIONS,
                'maxFiles' => FormFieldTypes::MAX_FILES_PER_FIELD,
                'uploadTypes' => $limits['upload_types'],
                'uploadMaxKb' => $limits['upload_max_kb'],
                'i18n' => self::builderTexts(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettingsData(): array
    {
        $available = $this->isAvailable();

        return [
            'tab' => 'settings',
            'view' => 'settings',
            'available' => $available,
            'settings' => FormSettings::all(),
            'form_choices' => $available ? $this->forms->choices(true) : [],
            'retention_limits' => [
                'min' => FormSettings::MIN_RETENTION_DAYS,
                'max' => FormSettings::MAX_RETENTION_DAYS,
                'default' => FormSettings::DEFAULT_RETENTION_DAYS,
            ],
            'upload_limits' => ['max_kb' => FormSettings::MAX_UPLOAD_MAX_KB],
            'mail_defaults' => self::mailDefaults(),
            'data_retention_url' => is_file(ABSPATH . 'admin/data-retention.php') ? '/admin/data-retention' : '',
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function handlePost(array $post, int $userId): array
    {
        $action = strtolower(trim(is_scalar($post['action'] ?? null) ? (string) $post['action'] : ''));
        if (!in_array($action, self::ACTIONS, true)) {
            return ['success' => false, 'error' => __('forms.admin.error_unknown_action')];
        }
        if (!$this->isAvailable()) {
            return ['success' => false, 'error' => __('forms.admin.unavailable')];
        }

        if ($action === 'save_settings') {
            return $this->saveSettings($post);
        }
        if ($action === 'create') {
            return $this->create($post, $userId);
        }

        $id = (int) ($post['id'] ?? 0);
        $form = $this->forms->find($id);
        if ($form === null) {
            return ['success' => false, 'error' => __('forms.admin.error_not_found'), '__redirect' => self::url()];
        }
        $title = (string) $form['title'];
        $listUrl = self::url(['status' => in_array($post['return_status'] ?? '', self::LIST_FILTERS, true) && $post['return_status'] !== 'all' ? (string) $post['return_status'] : null]);

        switch ($action) {
            case 'save':
                return $this->save($form, $post);

            case 'duplicate':
                $result = $this->forms->duplicate($id, $userId);
                if (empty($result['success'])) {
                    return ['success' => false, 'error' => (string) ($result['error'] ?? __('forms.admin.error_save')), '__redirect' => $listUrl];
                }
                $this->audit('forms.duplicate', 'Formular dupliziert', (int) $result['id'], ['source_id' => $id]);

                return [
                    'success' => true,
                    'message' => sprintf(__('forms.admin.duplicated'), $title),
                    '__redirect' => self::url(['action' => 'edit', 'id' => (int) $result['id']]),
                ];

            case 'activate':
            case 'deactivate':
                $status = $action === 'activate' ? FormService::STATUS_ACTIVE : FormService::STATUS_INACTIVE;
                $result = $this->forms->update($id, ['status' => $status]);
                if (empty($result['success'])) {
                    return ['success' => false, 'error' => (string) ($result['error'] ?? __('forms.admin.error_save')), '__redirect' => $listUrl];
                }
                $this->audit('forms.status', 'Formularstatus geändert', $id, ['status' => $status]);

                return [
                    'success' => true,
                    'message' => sprintf(__($action === 'activate' ? 'forms.admin.activated' : 'forms.admin.deactivated'), $title),
                    '__redirect' => $listUrl,
                ];

            case 'trash':
                if ($form['is_builtin']) {
                    return ['success' => false, 'error' => __('forms.admin.error_builtin'), '__redirect' => $listUrl];
                }
                if (!$this->forms->trash($id, $userId)) {
                    return ['success' => false, 'error' => __('forms.admin.error_save'), '__redirect' => $listUrl];
                }
                $this->audit('forms.trash', 'Formular in den Papierkorb verschoben', $id, ['title' => $title], 'warning');
                // Zuordnung bleibt stehen, damit Wiederherstellen sie von selbst zurückbringt.
                $wasContact = FormSettings::string('contact_form_key', FormService::CONTACT_FORM_KEY) === (string) $form['form_key'];

                return [
                    'success' => true,
                    'type' => $wasContact ? 'warning' : 'success',
                    'message' => sprintf(__('forms.admin.trashed'), $title),
                    'details' => $wasContact ? [__('forms.admin.trashed_was_contact')] : [],
                    '__redirect' => $listUrl,
                ];

            case 'restore':
                if (!$this->forms->restore($id)) {
                    return ['success' => false, 'error' => __('forms.admin.error_save'), '__redirect' => $listUrl];
                }
                $this->audit('forms.restore', 'Formular wiederhergestellt', $id, ['title' => $title]);

                return ['success' => true, 'message' => __('forms.admin.restored'), '__redirect' => $listUrl];

            default: // delete
                if ($form['status'] !== FormService::STATUS_TRASH) {
                    return ['success' => false, 'error' => __('forms.admin.error_delete_requires_trash'), '__redirect' => $listUrl];
                }
                $submissionCount = $this->submissions->count(['form_id' => $id, 'status' => 'any']);
                if (!$this->forms->deletePermanently($id)) {
                    return ['success' => false, 'error' => __('forms.admin.error_save'), '__redirect' => $listUrl];
                }
                $this->audit('forms.delete', 'Formular endgültig gelöscht', $id, ['title' => $title, 'submissions' => $submissionCount], 'warning');

                return ['success' => true, 'message' => __('forms.admin.deleted'), '__redirect' => $listUrl];
        }
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function create(array $post, int $userId): array
    {
        $template = in_array($post['template'] ?? '', self::TEMPLATES, true) ? (string) $post['template'] : 'blank';
        $definition = $template === 'contact' ? FormService::defaultContactDefinition() : ['fields' => [], 'settings' => []];
        $result = $this->forms->create([
            'title' => is_scalar($post['title'] ?? null) ? (string) $post['title'] : '',
            'form_key' => is_scalar($post['form_key'] ?? null) ? (string) $post['form_key'] : '',
            'status' => FormService::STATUS_INACTIVE,
            'fields' => $definition['fields'],
            'settings' => $definition['settings'],
        ], $userId);
        if (empty($result['success'])) {
            return ['success' => false, 'error' => (string) ($result['error'] ?? __('forms.admin.error_save')), '__redirect' => self::url()];
        }
        $this->audit('forms.create', 'Formular angelegt', (int) $result['id'], ['template' => $template]);

        return [
            'success' => true,
            'message' => __('forms.admin.created'),
            '__redirect' => self::url(['action' => 'edit', 'id' => (int) $result['id']]),
        ];
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function save(array $form, array $post): array
    {
        $id = (int) $form['id'];
        $editUrl = self::url(['action' => 'edit', 'id' => $id]);
        if ($form['status'] === FormService::STATUS_TRASH) {
            return ['success' => false, 'error' => __('forms.admin.error_not_found'), '__redirect' => self::url()];
        }

        $data = [
            'title' => is_scalar($post['title'] ?? null) ? (string) $post['title'] : '',
            'status' => (string) ($post['status'] ?? $form['status']),
            'settings' => $this->settingsFromPost($post, $form['settings']),
        ];
        if (!$form['is_builtin'] && array_key_exists('form_key', $post)) {
            $data['form_key'] = is_scalar($post['form_key']) ? (string) $post['form_key'] : '';
        }

        // Felder kommen als JSON aus dem Baukasten; ohne das Feld (JavaScript aus) bleiben sie unverändert.
        if (array_key_exists('fields_json', $post)) {
            $json = is_string($post['fields_json']) ? $post['fields_json'] : '';
            if (strlen($json) > self::MAX_FIELDS_JSON_BYTES) {
                return ['success' => false, 'error' => __('forms.admin.error_fields_too_large'), '__redirect' => $editUrl];
            }
            $fields = json_decode($json !== '' ? $json : '[]', true, 12);
            if (!is_array($fields)) {
                return ['success' => false, 'error' => __('forms.admin.error_fields_invalid'), '__redirect' => $editUrl];
            }
            if (count($fields) > FormFieldTypes::MAX_FIELDS) {
                return ['success' => false, 'error' => sprintf(__('forms.admin.error_fields_max'), FormFieldTypes::MAX_FIELDS), '__redirect' => $editUrl];
            }
            $data['fields'] = $fields;
        }

        $result = $this->forms->update($id, $data);
        if (empty($result['success'])) {
            return ['success' => false, 'error' => (string) ($result['error'] ?? __('forms.admin.error_save')), '__redirect' => $editUrl];
        }

        $saved = $this->forms->find($id);
        $this->audit('forms.save', 'Formular gespeichert', $id, [
            'form_key' => (string) ($saved['form_key'] ?? $form['form_key']),
            'fields' => count($saved['fields'] ?? []),
            'status' => (string) ($saved['status'] ?? ''),
        ]);

        $warnings = $this->saveWarnings($saved ?? $form);

        $oldKey = (string) $form['form_key'];
        $newKey = (string) ($saved['form_key'] ?? $oldKey);
        if ($newKey !== $oldKey) {
            // Die Kontaktformular-Zuordnung speichert den Schlüssel und würde sonst still auf das eingebaute Formular zurückfallen.
            if (FormSettings::string('contact_form_key', FormService::CONTACT_FORM_KEY) === $oldKey) {
                \CMS\Services\SettingsService::getInstance()->setMany(FormSettings::GROUP, ['contact_form_key' => $newKey]);
                FormSettings::flush();
            }
            $this->audit('forms.key_changed', 'Formularschlüssel geändert', $id, ['old' => $oldKey, 'new' => $newKey], 'warning');
            $warnings[] = sprintf(__('forms.admin.warning_key_changed'), $oldKey);
        }

        return [
            'success' => true,
            'type' => $warnings !== [] ? 'warning' : 'success',
            'message' => __('forms.admin.saved'),
            'details' => $warnings,
            '__redirect' => $editUrl,
        ];
    }

    /**
     * Hinweise nach dem Speichern, die das Formular nicht unbrauchbar, aber unvollständig machen.
     *
     * @param array<string, mixed> $form
     * @return list<string>
     */
    private function saveWarnings(array $form): array
    {
        $warnings = [];
        $settings = $form['settings'];
        $inputs = array_filter($form['fields'], static fn (array $field): bool => FormFieldTypes::isInput((string) $field['type']));
        if ($inputs === []) {
            $warnings[] = __('forms.admin.warning_no_fields');
        }
        if (!empty($settings['notify']) && \CMS\Services\Forms\FormMailer::recipients($form) === []) {
            $warnings[] = __('forms.admin.warning_no_recipient');
        }
        if (empty($settings['notify']) && empty($settings['store_submissions'])) {
            $warnings[] = __('forms.admin.warning_no_delivery');
        }
        if (empty($settings['store_submissions']) && array_filter($form['fields'], static fn (array $field): bool => ($field['type'] ?? '') === 'file') !== []) {
            $warnings[] = __('forms.admin.warning_files_not_stored');
        }
        if (!empty($settings['confirmation']['enabled']) && FormFieldTypes::fieldByRole($form['fields'], 'email') === null) {
            $warnings[] = __('forms.admin.warning_confirmation_email');
        }

        return $warnings;
    }

    /**
     * Ordnet die Formularfelder des Editors den Formular-Einstellungen zu (Normalisierung im FormService).
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    private function settingsFromPost(array $post, array $current): array
    {
        $raw = is_array($post['settings'] ?? null) ? $post['settings'] : [];
        $text = static fn (string $key): mixed => is_array($raw[$key] ?? null) ? $raw[$key] : ['de' => '', 'en' => ''];
        $scalar = static fn (string $key): string => is_scalar($raw[$key] ?? null) ? (string) $raw[$key] : '';
        $confirmation = is_array($raw['confirmation'] ?? null) ? $raw['confirmation'] : [];

        $retentionMode = $scalar('retention_mode');
        $retention = match ($retentionMode) {
            'never' => 0,
            'custom' => max(FormSettings::MIN_RETENTION_DAYS, (int) $scalar('retention_days')),
            default => null,
        };

        return [
            'heading' => $text('heading'),
            'intro' => $text('intro'),
            'footer' => $text('footer'),
            'submit_label' => $text('submit_label'),
            'success_message' => $text('success_message'),
            'redirect_url' => $scalar('redirect_url'),
            'recipients' => $scalar('recipients'),
            'cc' => $scalar('cc'),
            'bcc' => $scalar('bcc'),
            'mail_subject' => $scalar('mail_subject'),
            'notify' => !empty($raw['notify']),
            'reply_to_sender' => !empty($raw['reply_to_sender']),
            'confirmation' => [
                'enabled' => !empty($confirmation['enabled']),
                'subject' => is_array($confirmation['subject'] ?? null) ? $confirmation['subject'] : [],
                'body' => is_array($confirmation['body'] ?? null) ? $confirmation['body'] : [],
            ],
            'antispam_level' => $scalar('antispam_level'),
            'rate_limit' => (int) $scalar('rate_limit'),
            'store_submissions' => !empty($raw['store_submissions']),
            'retention_days' => $retention,
            'layout' => $scalar('layout'),
            'custom_css' => $scalar('custom_css'),
            'show_company_info' => !empty($raw['show_company_info']),
            // Aus dem Plugin übernommene Zuordnung (?expert / ?speaker / ?company) bleibt erhalten.
            'legacy_template' => (string) ($current['legacy_template'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function saveSettings(array $post): array
    {
        $input = is_array($post['forms'] ?? null) ? $post['forms'] : [];
        $result = FormSettings::save($input);
        $redirect = self::url(['tab' => 'settings']);
        if (empty($result['saved'])) {
            return ['success' => false, 'error' => __('forms.settings.save_failed'), 'details' => $result['errors'], '__redirect' => $redirect];
        }

        AuditLogger::instance()->log(AuditLogger::CAT_SETTING, 'forms.settings.save', 'Formular-Einstellungen gespeichert', 'forms', null, [
            'retention_days' => FormSettings::retentionDays(),
            'contact_form_key' => FormSettings::string('contact_form_key'),
        ]);

        return [
            'success' => true,
            'type' => $result['errors'] !== [] ? 'warning' : 'success',
            'message' => __('forms.settings.saved'),
            'details' => $result['errors'],
            '__redirect' => $redirect,
        ];
    }

    /**
     * Standard-Mailvorlagen je Sprache (Platzhalter in den Einstellungen).
     *
     * @return array<string, string>
     */
    private static function mailDefaults(): array
    {
        $defaults = [];
        foreach (FormSettings::MAIL_TEMPLATES as $template) {
            foreach (FormI18n::LOCALES as $locale) {
                $defaults['mail_' . $template . '_' . $locale] = FormI18n::t('forms.mail.' . $template, $locale);
            }
        }

        return $defaults;
    }

    /**
     * Texte für admin-form-builder.js.
     *
     * @return array<string, string>
     */
    private static function builderTexts(): array
    {
        $keys = [
            'addField', 'emptyHint', 'edit', 'close', 'remove', 'removeConfirmTitle', 'removeConfirmMessage', 'duplicate',
            'moveUp', 'moveDown', 'dragHandle', 'required', 'requiredShort', 'untitled', 'maxFields', 'moved', 'added',
            'removed', 'type', 'label', 'labelDe', 'labelEn', 'name', 'nameHint', 'placeholder', 'help', 'width',
            'widthFull', 'widthHalf', 'widthThird', 'widthTwoThird', 'role', 'roleNone', 'roleName', 'roleEmail',
            'roleSubject', 'roleMessage', 'roleHint', 'defaultValue', 'cssClass', 'options', 'optionsHint', 'content',
            'headingLevel', 'privacyUrl', 'privacyUrlHint', 'consentHint', 'minLength', 'maxLength', 'pattern',
            'patternHint', 'patternMessage', 'min', 'max', 'step', 'minDate', 'maxDate', 'accept', 'acceptHint',
            'maxSizeKb', 'maxFiles', 'sectionBasics', 'sectionTexts', 'sectionValidation', 'sectionAdvanced',
            'unsaved', 'langDe', 'langEn',
        ];
        $texts = [];
        foreach ($keys as $key) {
            $texts[$key] = __('forms.builder.' . strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $key)));
        }

        return $texts;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function audit(string $action, string $description, int $id, array $context = [], string $severity = 'info'): void
    {
        AuditLogger::instance()->log(AuditLogger::CAT_CONTENT, $action, $description, 'form', $id, $context, $severity);
    }
}
