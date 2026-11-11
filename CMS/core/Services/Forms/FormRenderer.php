<?php
/**
 * HTML-Ausgabe von Formularen (Kontaktseite, `/contact/<schlüssel>`, Editor.js-Block „Formular“).
 *
 * Barrierearm: sichtbare Beschriftungen, Pflichtfeld-Kennzeichnung, `aria-describedby` für Hilfe- und
 * Fehlertexte, Fehlerübersicht mit Sprungmarken, Gruppen als `fieldset`/`legend`. Die Client-Prüfung
 * (`assets/js/forms-public.js`) nutzt dieselben Regeln und Texte; maßgeblich bleibt die Server-Prüfung.
 *
 * @package CMSv2\Core\Services\Forms
 */

declare(strict_types=1);

namespace CMS\Services\Forms;

use CMS\Hooks;
use CMS\Security;

if (!defined('ABSPATH')) {
    exit;
}

final class FormRenderer
{
    private const CLIENT_MESSAGES = [
        'required', 'required_choice', 'required_check', 'required_file', 'consent', 'email', 'url', 'tel',
        'number', 'number_min', 'number_max', 'number_step', 'date', 'date_min', 'date_max', 'min_length',
        'max_length', 'pattern', 'file_type', 'file_size', 'file_count', 'summary',
    ];

    private static bool $assetsQueued = false;
    private static bool $assetsPrinted = false;
    private static int $nesting = 0;

    /** @var array<string, int> */
    private static array $instances = [];

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $options
     *        context: page|block|preview, action, return, locale, show_heading, show_intro, flash, instance
     */
    public static function render(array $form, array $options = []): string
    {
        if (self::$nesting > 0) {
            return '';
        }

        $context = in_array($options['context'] ?? 'page', ['page', 'block', 'preview'], true) ? (string) ($options['context'] ?? 'page') : 'page';
        $preview = $context === 'preview';
        $locale = FormI18n::normalizeLocale($options['locale'] ?? FormI18n::requestLocale());
        $key = (string) $form['form_key'];
        $settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
        $fields = is_array($form['fields'] ?? null) ? $form['fields'] : [];

        self::$instances[$key] = (self::$instances[$key] ?? 0) + 1;
        $instance = self::$instances[$key];
        $domId = 'cms-form-' . $key . ($instance > 1 ? '-' . $instance : '');
        $prefix = 'cf-' . $key . ($instance > 1 ? '-' . $instance : '') . '-';

        $flash = $preview ? null : ($options['flash'] ?? FormFrontendController::takeFlash($key));
        $flash = is_array($flash) ? $flash : null;
        $errors = is_array($flash['errors'] ?? null) ? $flash['errors'] : [];
        $old = is_array($flash['old'] ?? null) ? $flash['old'] : [];
        $success = ($flash['status'] ?? '') === 'success';
        if ($old === [] && !$preview && !$success) {
            $old = self::userPrefill($fields);
        }

        $layout = (string) ($settings['layout'] ?? 'standard');
        $classes = ['cms-form', 'cms-form--' . $layout, 'cms-form--' . $context];
        if ($preview) {
            $classes[] = 'cms-form--is-preview';
        }
        $style = self::styleVars();

        $html = $preview ? '' : self::assetTags();
        if (!$preview && trim((string) ($settings['custom_css'] ?? '')) !== '') {
            $html .= '<style ' . Security::instance()->nonceAttr() . '>' . str_replace('</', '<\/', (string) $settings['custom_css']) . '</style>';
        }

        $html .= '<div class="' . self::esc(implode(' ', $classes)) . '" id="' . self::esc($domId) . '" data-cms-form="' . self::esc($key) . '"'
            . ($style !== '' ? ' style="' . self::esc($style) . '"' : '') . ' lang="' . self::esc($locale) . '">';

        if ($preview) {
            $html .= '<p class="cms-form__preview-note">' . self::esc(sprintf(FormI18n::t('forms.block.preview_note', $locale), (string) $form['title'])) . '</p>';
        }

        $heading = FormI18n::pick($settings['heading'] ?? [], $locale);
        if (($options['show_heading'] ?? true) && $heading !== '') {
            $html .= '<h2 class="cms-form__heading">' . self::esc($heading) . '</h2>';
        }
        $intro = FormI18n::pick($settings['intro'] ?? [], $locale);
        if (($options['show_intro'] ?? true) && $intro !== '') {
            $html .= '<div class="cms-form__intro">' . self::richText($intro) . '</div>';
        }

        $layoutOpen = $layout === 'split' && !empty($settings['show_company_info']);
        if ($layoutOpen) {
            $html .= '<div class="cms-form__split"><div class="cms-form__main">';
        }

        if ($success) {
            $message = trim((string) ($flash['message'] ?? ''));
            if ($message === '') {
                $message = FormI18n::pick($settings['success_message'] ?? [], $locale);
            }
            if ($message === '') {
                $message = FormI18n::t('forms.success.default', $locale);
            }
            $html .= '<div class="cms-form__notice cms-form__notice--success" role="status" tabindex="-1" data-cms-form-focus>'
                . '<p>' . nl2br(self::esc($message)) . '</p>'
                . '<p class="cms-form__again"><a href="' . self::esc(self::currentPath() . '#' . $domId) . '">' . self::esc(FormI18n::t('forms.success.again', $locale)) . '</a></p>'
                . '</div>';
        } else {
            $html .= self::renderErrorSummary($flash, $errors, $fields, $prefix, $locale);
            $html .= self::renderForm($form, $fields, $settings, $options, $context, $locale, $prefix, $errors, $old);
        }

        if ($layoutOpen) {
            $html .= '</div>' . self::renderCompanyInfo($locale) . '</div>';
        }

        $footer = FormI18n::pick($settings['footer'] ?? [], $locale);
        if ($footer !== '') {
            $html .= '<div class="cms-form__footer">' . self::richText($footer) . '</div>';
        }

        $html .= '</div>';

        if (!$preview) {
            self::noStore();
        }

        return $html;
    }

    /**
     * Hinweis für nicht verfügbare Formulare (nur Vorschau bzw. Kontaktseite).
     */
    public static function unavailable(string $locale): string
    {
        return '<div class="cms-form cms-form--unavailable"><p class="cms-form__notice cms-form__notice--info">'
            . self::esc(FormI18n::t('forms.error.unavailable', $locale)) . '</p></div>';
    }

    /**
     * Stylesheet im `<head>` und Skript am Seitenende anmelden. Liefert Tags zurück, falls der Kopf schon
     * ausgegeben wurde (z. B. Theme-Template).
     */
    public static function assetTags(): string
    {
        if (self::$assetsPrinted) {
            return '';
        }

        $css = self::assetUrl('css/forms-public.css');
        $js = self::assetUrl('js/forms-public.js');
        $cssTag = '<link rel="stylesheet" href="' . self::esc($css) . '">';
        $jsTag = '<script src="' . self::esc($js) . '" defer></script>';

        if (Hooks::didAction('head') > 0) {
            self::$assetsPrinted = true;

            return $cssTag . "\n" . $jsTag . "\n";
        }
        self::enqueueAssets();

        return '';
    }

    public static function enqueueAssets(): void
    {
        if (self::$assetsQueued) {
            return;
        }
        self::$assetsQueued = true;
        $css = self::assetUrl('css/forms-public.css');
        $js = self::assetUrl('js/forms-public.js');

        Hooks::addAction('head', static function () use ($css): void {
            if (self::$assetsPrinted) {
                return;
            }
            echo '<link rel="stylesheet" href="' . self::esc($css) . '">' . "\n";
        }, 40);
        Hooks::addAction('body_end', static function () use ($js): void {
            if (self::$assetsPrinted) {
                return;
            }
            self::$assetsPrinted = true;
            echo '<script src="' . self::esc($js) . '" defer></script>' . "\n";
        }, 20);
    }

    /**
     * Einfacher Text mit Absätzen und Links im Format `[Text](https://…)`; Editor.js-JSON wird gerendert.
     */
    public static function richText(string $text): string
    {
        $trimmed = trim($text);
        if ($trimmed !== '' && $trimmed[0] === '{') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded) && isset($decoded['blocks']) && is_array($decoded['blocks'])) {
                self::$nesting++;
                try {
                    return \CMS\Services\EditorJsRenderer::getInstance()->render($decoded);
                } catch (\Throwable) {
                    return '';
                } finally {
                    self::$nesting--;
                }
            }
        }

        $paragraphs = preg_split('/\n{2,}/', str_replace(["\r\n", "\r"], "\n", $trimmed)) ?: [];
        $html = '';
        foreach ($paragraphs as $paragraph) {
            if (trim($paragraph) === '') {
                continue;
            }
            $html .= '<p>' . nl2br(self::linkify(self::esc(trim($paragraph)))) . '</p>';
        }

        return $html;
    }

    public static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $form
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $options
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private static function renderForm(array $form, array $fields, array $settings, array $options, string $context, string $locale, string $prefix, array $errors, array $old): string
    {
        $preview = $context === 'preview';
        $key = (string) $form['form_key'];
        $hasFile = false;
        foreach ($fields as $field) {
            if (($field['type'] ?? '') === 'file') {
                $hasFile = true;
            }
        }

        $action = (string) ($options['action'] ?? self::defaultAction($key, $locale));
        $messages = [];
        foreach (self::CLIENT_MESSAGES as $message) {
            $messages[$message] = $message === 'summary' ? FormI18n::t('forms.error.summary', $locale) : FormI18n::t('forms.error.' . $message, $locale);
        }

        $html = '<form class="cms-form__form" method="post" action="' . self::esc($action) . '" novalidate'
            . ($hasFile ? ' enctype="multipart/form-data"' : '')
            . " data-messages='" . self::esc(FormService::encode($messages)) . "'"
            . ' aria-label="' . self::esc((string) $form['title']) . '">';

        if (!$preview) {
            $token = Security::instance()->generateToken('form_' . $key);
            $return = (string) ($options['return'] ?? self::currentPath());
            $html .= '<input type="hidden" name="cms_form" value="' . self::esc($key) . '">'
                . '<input type="hidden" name="_token" value="' . self::esc($token) . '">'
                . '<input type="hidden" name="' . FormSpamGuard::TIME_FIELD . '" value="' . self::esc(FormSpamGuard::timestampToken()) . '">'
                . '<input type="hidden" name="_locale" value="' . self::esc($locale) . '">'
                . '<input type="hidden" name="_return" value="' . self::esc($return) . '">'
                . '<div class="cms-form__hp" aria-hidden="true"><label>' . self::esc(FormI18n::t('forms.field.honeypot', $locale))
                . '<input type="text" name="' . FormSpamGuard::HONEYPOT_FIELD . '" value="" tabindex="-1" autocomplete="off"></label></div>';
        }

        $html .= '<div class="cms-form__grid">';
        foreach ($fields as $field) {
            $html .= self::renderField($field, $settings, $locale, $prefix, $errors, $old, $preview);
        }
        $html .= '</div>';

        if (($settings['antispam_level'] ?? 'standard') === 'high' && !$preview) {
            $question = FormSpamGuard::captchaQuestion($key);
            $captchaId = $prefix . 'captcha';
            $captchaError = $errors['_captcha'] ?? '';
            $html .= '<div class="cms-form__field cms-form__field--captcha is-required' . ($captchaError !== '' ? ' has-error' : '') . '">'
                . '<label class="cms-form__label" for="' . self::esc($captchaId) . '">'
                . self::esc(sprintf(FormI18n::t('forms.captcha.question', $locale), $question['a'], $question['b']))
                . ' <span class="cms-form__req" aria-hidden="true">*</span></label>'
                . '<input class="cms-form__input" type="text" inputmode="numeric" autocomplete="off" id="' . self::esc($captchaId) . '" name="' . FormSpamGuard::CAPTCHA_FIELD . '" required aria-required="true"'
                . ($captchaError !== '' ? ' aria-invalid="true" aria-describedby="' . self::esc($captchaId . '-error') . '"' : '') . '>'
                . ($captchaError !== '' ? '<p class="cms-form__error" id="' . self::esc($captchaId . '-error') . '">' . self::esc($captchaError) . '</p>' : '')
                . '</div>';
        }

        $submit = FormI18n::pick($settings['submit_label'] ?? [], $locale);
        if ($submit === '') {
            $submit = FormI18n::t('forms.submit.default', $locale);
        }
        $html .= '<p class="cms-form__required-note">' . self::esc(FormI18n::t('forms.field.required_note', $locale)) . '</p>';
        $html .= '<div class="cms-form__actions"><button type="submit" class="cms-form__submit btn-solid"'
            . ($preview ? ' disabled' : '') . ' data-busy-label="' . self::esc(FormI18n::t('forms.submit.busy', $locale)) . '">'
            . self::esc($submit) . '</button></div>';
        $html .= '</form>';

        return $html;
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $settings
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private static function renderField(array $field, array $settings, string $locale, string $prefix, array $errors, array $old, bool $preview): string
    {
        $type = (string) $field['type'];
        $name = (string) $field['name'];
        $id = $prefix . $name;
        $width = (string) ($field['width'] ?? 'full');
        $cssClass = trim((string) ($field['css_class'] ?? ''));

        if ($type === 'heading') {
            $level = max(2, min(4, (int) ($field['level'] ?? 3)));
            $text = FormI18n::pick($field['content'] ?? [], $locale);

            return $text === '' ? '' : '<div class="cms-form__static cms-form__static--heading cms-form__field--full' . ($cssClass !== '' ? ' ' . self::esc($cssClass) : '') . '">'
                . '<h' . $level . ' class="cms-form__section-title">' . self::esc($text) . '</h' . $level . '></div>';
        }
        if ($type === 'paragraph') {
            $text = FormI18n::pick($field['content'] ?? [], $locale);

            return $text === '' ? '' : '<div class="cms-form__static cms-form__static--text cms-form__field--' . self::esc($width) . ($cssClass !== '' ? ' ' . self::esc($cssClass) : '') . '">'
                . self::richText($text) . '</div>';
        }
        if ($type === 'hidden') {
            $value = $old[$name] ?? ($field['default'] ?? '');

            return $preview ? '' : '<input type="hidden" name="fields[' . self::esc($name) . ']" value="' . self::esc(is_scalar($value) ? (string) $value : '') . '">';
        }

        $required = !empty($field['required']);
        $label = FormI18n::pick($field['label'] ?? [], $locale);
        if ($label === '') {
            $label = $type === 'consent' ? FormI18n::t('forms.field.consent_label', $locale) : $name;
        }
        $placeholder = FormI18n::pick($field['placeholder'] ?? [], $locale);
        $help = FormI18n::pick($field['help'] ?? [], $locale);
        $validation = is_array($field['validation'] ?? null) ? $field['validation'] : [];
        if ($type === 'file') {
            $limits = FormSettings::uploadLimits();
            $hint = sprintf(
                FormI18n::t('forms.field.file_hint', $locale),
                implode(', ', FormValidator::allowedExtensions($validation, $limits)),
                FormValidator::formatKb(FormValidator::maxKb($validation, $limits))
            );
            $help = trim($help . ($help !== '' ? ' ' : '') . $hint);
        }
        $error = (string) ($errors[$name] ?? '');
        $value = array_key_exists($name, $old) ? $old[$name] : ($field['default'] ?? '');

        $describedBy = [];
        if ($help !== '') {
            $describedBy[] = $id . '-help';
        }
        $describedBy[] = $id . '-error';
        $aria = ' aria-describedby="' . self::esc(implode(' ', $describedBy)) . '"'
            . ($error !== '' ? ' aria-invalid="true"' : '')
            . ($required ? ' aria-required="true"' : '');

        $wrapperClasses = ['cms-form__field', 'cms-form__field--' . $type, 'cms-form__field--' . $width];
        if ($required) {
            $wrapperClasses[] = 'is-required';
        }
        if ($error !== '') {
            $wrapperClasses[] = 'has-error';
        }
        if ($cssClass !== '') {
            $wrapperClasses[] = $cssClass;
        }
        $required_mark = $required ? ' <span class="cms-form__req" aria-hidden="true">*</span>' : '';
        $disabled = $preview ? ' disabled' : '';
        $dataAttrs = self::validationAttributes($field, $validation);

        $html = '<div class="' . self::esc(implode(' ', $wrapperClasses)) . '" data-field="' . self::esc($name) . '" data-type="' . self::esc($type) . '"' . $dataAttrs . '>';

        $isGroup = in_array($type, ['radio'], true) || ($type === 'checkbox' && ($field['options'] ?? []) !== []);
        if ($isGroup) {
            $html .= '<fieldset class="cms-form__group" id="' . self::esc($id) . '"' . $aria . '>'
                . '<legend class="cms-form__label">' . self::esc($label) . $required_mark . '</legend>';
            $selected = is_array($value) ? array_map('strval', $value) : ($value !== '' && $value !== null ? [(string) $value] : []);
            foreach ($field['options'] as $index => $option) {
                $optionId = $id . '-' . $index;
                $inputType = $type === 'radio' ? 'radio' : 'checkbox';
                $inputName = $type === 'radio' ? 'fields[' . $name . ']' : 'fields[' . $name . '][]';
                $checked = in_array((string) $option['value'], $selected, true) ? ' checked' : '';
                $html .= '<label class="cms-form__choice" for="' . self::esc($optionId) . '">'
                    . '<input type="' . $inputType . '" id="' . self::esc($optionId) . '" name="' . self::esc($inputName) . '" value="' . self::esc((string) $option['value']) . '"'
                    . $checked . ($required && $type === 'radio' ? ' required' : '') . $disabled . '>'
                    . '<span>' . self::esc(FormI18n::pick($option['label'], $locale)) . '</span></label>';
            }
            $html .= '</fieldset>';
        } elseif ($type === 'checkbox' || $type === 'consent') {
            $text = $type === 'consent' ? self::consentHtml($field, $label, $locale) : self::esc($label);
            $checked = (string) (is_scalar($value) ? $value : '') === '1' ? ' checked' : '';
            $html .= '<div class="cms-form__check">'
                . '<input type="checkbox" id="' . self::esc($id) . '" name="fields[' . self::esc($name) . ']" value="1"' . $checked
                . ($required ? ' required' : '') . $aria . $disabled . '>'
                . '<label class="cms-form__check-label" for="' . self::esc($id) . '">' . $text . $required_mark . '</label></div>';
        } else {
            $html .= '<label class="cms-form__label" for="' . self::esc($id) . '">' . self::esc($label) . $required_mark . '</label>';
            $html .= self::renderControl($field, $id, $name, $value, $placeholder, $required, $aria . $disabled, $settings, $locale);
        }

        if ($help !== '') {
            $html .= '<p class="cms-form__help" id="' . self::esc($id . '-help') . '">' . self::esc($help) . '</p>';
        }
        $html .= '<p class="cms-form__error" id="' . self::esc($id . '-error') . '"' . ($error === '' ? ' hidden' : '') . '>' . self::esc($error) . '</p>';
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $settings
     */
    private static function renderControl(array $field, string $id, string $name, mixed $value, string $placeholder, bool $required, string $extra, array $settings, string $locale): string
    {
        $type = (string) $field['type'];
        $validation = is_array($field['validation'] ?? null) ? $field['validation'] : [];
        $value = is_scalar($value) ? (string) $value : '';
        $common = ' id="' . self::esc($id) . '" class="cms-form__input" name="fields[' . self::esc($name) . ']"'
            . ($required ? ' required' : '') . $extra;
        $placeholderAttr = $placeholder !== '' ? ' placeholder="' . self::esc($placeholder) . '"' : '';

        if ($type === 'textarea') {
            $rows = ($settings['layout'] ?? '') === 'compact' ? 4 : 6;

            return '<textarea' . $common . $placeholderAttr . ' rows="' . $rows . '"' . self::lengthAttrs($field, $validation) . '>' . self::esc($value) . '</textarea>';
        }
        if ($type === 'select') {
            $html = '<select' . $common . '>';
            $html .= '<option value="">' . self::esc($placeholder !== '' ? $placeholder : FormI18n::t('forms.field.select_placeholder', $locale)) . '</option>';
            foreach ($field['options'] ?? [] as $option) {
                $selected = (string) $option['value'] === $value ? ' selected' : '';
                $html .= '<option value="' . self::esc((string) $option['value']) . '"' . $selected . '>' . self::esc(FormI18n::pick($option['label'], $locale)) . '</option>';
            }

            return $html . '</select>';
        }
        if ($type === 'file') {
            $maxFiles = max(1, (int) ($validation['max_files'] ?? 1));
            $limits = FormSettings::uploadLimits();
            $accept = array_map(static fn(string $ext): string => '.' . $ext, FormValidator::allowedExtensions($validation, $limits));

            return '<input type="file"' . str_replace('name="fields[' . self::esc($name) . ']"', 'name="fields[' . self::esc($name) . '][]"', $common)
                . ($maxFiles > 1 ? ' multiple' : '') . ' accept="' . self::esc(implode(',', $accept)) . '">';
        }

        $inputType = match ($type) {
            'email' => 'email',
            'tel' => 'tel',
            'url' => 'url',
            'number' => 'number',
            'date' => 'date',
            default => 'text',
        };
        $attrs = $placeholderAttr;
        $autocomplete = match ($field['role'] ?? '') {
            'name' => 'name',
            'email' => 'email',
            default => match ($type) {
                'email' => 'email',
                'tel' => 'tel',
                'url' => 'url',
                default => '',
            },
        };
        if ($autocomplete !== '') {
            $attrs .= ' autocomplete="' . $autocomplete . '"';
        }
        if ($type === 'number') {
            foreach (['min', 'max', 'step'] as $attr) {
                if (isset($validation[$attr]) && $validation[$attr] !== null) {
                    $attrs .= ' ' . $attr . '="' . self::esc(self::number((float) $validation[$attr])) . '"';
                }
            }
            if (!isset($validation['step']) || $validation['step'] === null) {
                $attrs .= ' step="any"';
            }
            $attrs .= ' inputmode="decimal"';
        } elseif ($type === 'date') {
            if (($validation['min_date'] ?? '') !== '') {
                $attrs .= ' min="' . self::esc((string) $validation['min_date']) . '"';
            }
            if (($validation['max_date'] ?? '') !== '') {
                $attrs .= ' max="' . self::esc((string) $validation['max_date']) . '"';
            }
        } else {
            $attrs .= self::lengthAttrs($field, $validation);
            // Kein HTML-Attribut `pattern` (wäre implizit verankert); die Client-Prüfung sucht wie der Server.
            $pattern = (string) ($validation['pattern'] ?? '');
            if ($pattern !== '') {
                $attrs .= ' data-pattern="' . self::esc($pattern) . '"';
            }
        }

        return '<input type="' . $inputType . '"' . $common . $attrs . ' value="' . self::esc($value) . '">';
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $validation
     */
    private static function lengthAttrs(array $field, array $validation): string
    {
        $attrs = '';
        if (!empty($validation['min_length'])) {
            $attrs .= ' minlength="' . (int) $validation['min_length'] . '"';
        }
        $attrs .= ' maxlength="' . FormFieldTypes::maxLength($field) . '"';

        return $attrs;
    }

    /**
     * Daten-Attribute für die Client-Prüfung (Dateien, Muster-Meldung, Gruppen).
     *
     * @param array<string, mixed> $field
     * @param array<string, mixed> $validation
     */
    private static function validationAttributes(array $field, array $validation): string
    {
        $attrs = '';
        if (!empty($field['required'])) {
            $attrs .= ' data-required="1"';
        }
        if (($field['type'] ?? '') === 'file') {
            $limits = FormSettings::uploadLimits();
            $attrs .= ' data-accept="' . self::esc(implode(',', FormValidator::allowedExtensions($validation, $limits))) . '"'
                . ' data-max-kb="' . FormValidator::maxKb($validation, $limits) . '"'
                . ' data-max-size="' . self::esc(FormValidator::formatKb(FormValidator::maxKb($validation, $limits))) . '"'
                . ' data-max-files="' . max(1, (int) ($validation['max_files'] ?? 1)) . '"';
        }
        $patternMessage = FormI18n::pick($validation['pattern_message'] ?? [], FormI18n::requestLocale());
        if ($patternMessage !== '') {
            $attrs .= ' data-pattern-message="' . self::esc($patternMessage) . '"';
        }

        return $attrs;
    }

    /**
     * Einwilligungstext mit Link zur Datenschutzerklärung (`{privacy}` im Text oder angehängt).
     *
     * @param array<string, mixed> $field
     */
    private static function consentHtml(array $field, string $label, string $locale): string
    {
        $custom = FormI18n::pick($field['label'] ?? [], $locale);
        $text = $custom !== '' ? $custom : FormSettings::consentText($locale);
        $url = (string) ($field['privacy_url'] ?? '');
        if ($url === '') {
            $url = FormSettings::privacyUrl();
        }
        $href = str_starts_with($url, '/') && defined('SITE_URL') ? rtrim((string) SITE_URL, '/') . self::localizedPath($url, $locale) : $url;
        $link = '<a href="' . self::esc($href) . '" target="_blank" rel="noopener">' . self::esc(FormI18n::t('forms.consent.privacy_link', $locale)) . '</a>';

        $escaped = self::esc($text);

        return str_contains($escaped, '{privacy}') ? str_replace('{privacy}', $link, $escaped) : $escaped . ' (' . $link . ')';
    }

    private static function renderErrorSummary(?array $flash, array $errors, array $fields, string $prefix, string $locale): string
    {
        if ($flash === null || ($flash['status'] ?? '') !== 'error') {
            return '';
        }

        $message = trim((string) ($flash['message'] ?? ''));
        if ($message === '') {
            $message = FormI18n::t('forms.error.summary', $locale);
        }
        $html = '<div class="cms-form__notice cms-form__notice--error" role="alert" tabindex="-1" data-cms-form-focus><p>' . self::esc($message) . '</p>';
        if ($errors !== []) {
            $html .= '<ul>';
            foreach ($fields as $field) {
                $name = (string) $field['name'];
                if (!isset($errors[$name])) {
                    continue;
                }
                $label = FormI18n::pick($field['label'] ?? [], $locale);
                if ($label === '') {
                    $label = $field['type'] === 'consent' ? FormI18n::t('forms.field.consent_label', $locale) : $name;
                }
                $target = $prefix . $name . (in_array($field['type'], ['radio'], true) || ($field['type'] === 'checkbox' && ($field['options'] ?? []) !== []) ? '-0' : '');
                $html .= '<li><a href="#' . self::esc($target) . '">' . self::esc($label) . ': ' . self::esc((string) $errors[$name]) . '</a></li>';
            }
            if (isset($errors['_captcha'])) {
                $html .= '<li><a href="#' . self::esc($prefix . 'captcha') . '">' . self::esc((string) $errors['_captcha']) . '</a></li>';
            }
            $html .= '</ul>';
        }

        return $html . '</div>';
    }

    private static function renderCompanyInfo(string $locale): string
    {
        $name = FormSettings::companyName();
        $email = FormSettings::string('company_email');
        $phone = FormSettings::string('company_phone');
        $address = FormSettings::string('company_address');
        $map = FormSettings::string('map_url');

        $html = '<aside class="cms-form__aside" aria-label="' . self::esc(FormI18n::t('forms.company.title', $locale)) . '">';
        $html .= '<h3 class="cms-form__aside-title">' . self::esc($name !== '' ? $name : FormI18n::t('forms.company.title', $locale)) . '</h3><dl>';
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $html .= '<dt>' . self::esc(FormI18n::t('forms.company.email', $locale)) . '</dt><dd><a href="mailto:' . self::esc($email) . '">' . self::esc($email) . '</a></dd>';
        }
        if ($phone !== '') {
            $tel = (string) preg_replace('/[^0-9+]/', '', $phone);
            $html .= '<dt>' . self::esc(FormI18n::t('forms.company.phone', $locale)) . '</dt><dd><a href="tel:' . self::esc($tel) . '">' . self::esc($phone) . '</a></dd>';
        }
        if ($address !== '') {
            $html .= '<dt>' . self::esc(FormI18n::t('forms.company.address', $locale)) . '</dt><dd>' . nl2br(self::esc($address)) . '</dd>';
        }
        $html .= '</dl>';
        if ($map !== '' && str_starts_with($map, 'https://')) {
            $html .= '<p><a href="' . self::esc($map) . '" target="_blank" rel="noopener noreferrer">' . self::esc(FormI18n::t('forms.company.map', $locale)) . '</a></p>';
        }

        return $html . '</aside>';
    }

    /**
     * Name und E-Mail angemeldeter Personen vorbelegen (Felder mit Rolle „name“/„email“).
     *
     * @param list<array<string, mixed>> $fields
     * @return array<string, string>
     */
    private static function userPrefill(array $fields): array
    {
        try {
            if (!\CMS\Auth::isLoggedIn()) {
                return [];
            }
            $user = \CMS\Auth::instance()->currentUser();
        } catch (\Throwable) {
            return [];
        }
        if ($user === null) {
            return [];
        }

        $prefill = [];
        $nameField = FormFieldTypes::fieldByRole($fields, 'name');
        $name = trim((string) ($user->display_name ?? '')) ?: trim((string) ($user->username ?? ''));
        if ($nameField !== null && $name !== '') {
            $prefill[(string) $nameField['name']] = $name;
        }
        $emailField = FormFieldTypes::fieldByRole($fields, 'email');
        if ($emailField !== null && filter_var((string) ($user->email ?? ''), FILTER_VALIDATE_EMAIL) !== false) {
            $prefill[(string) $emailField['name']] = (string) $user->email;
        }

        return $prefill;
    }

    private static function styleVars(): string
    {
        $vars = [];
        $accent = FormSettings::string('accent_color');
        if (preg_match('/^#[0-9a-f]{3,8}$/i', $accent) === 1) {
            // Konfigurierte Akzentfarbe gilt auch für Links und Schaltfläche (Standard: Theme-Farben).
            $vars[] = '--cms-form-accent:' . $accent;
            $vars[] = '--cms-form-link:' . $accent;
            $vars[] = '--cms-form-button:' . $accent;
        }
        $radius = (int) FormSettings::get('radius', 8);
        if ($radius >= 0 && $radius <= 40 && $radius !== 8) {
            $vars[] = '--cms-form-radius:' . $radius . 'px';
        }

        return implode(';', $vars);
    }

    private static function defaultAction(string $key, string $locale): string
    {
        return self::localizedPath('/contact/' . rawurlencode($key), $locale);
    }

    private static function localizedPath(string $path, string $locale): string
    {
        return $locale === 'en' && !str_starts_with($path, '/en/') ? '/en' . $path : $path;
    }

    /** Aktueller Pfad samt Abfrage (für Rücksprung nach dem Absenden). */
    public static function currentPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $query = (string) parse_url($uri, PHP_URL_QUERY);
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            $path = '/';
        }
        if ($query !== '') {
            parse_str($query, $params);
            unset($params['form_sent']);
            $query = http_build_query($params);
        }

        return $path . ($query !== '' ? '?' . $query : '');
    }

    private static function linkify(string $escaped): string
    {
        return (string) preg_replace_callback(
            '/\[([^\]]{1,200})\]\(((?:https?:\/\/|\/)[^\s)"<>]{1,500})\)/',
            static function (array $match): string {
                $url = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
                if (!FormFieldTypes::isSafeUrl($url)) {
                    return $match[0];
                }
                $external = !str_starts_with($url, '/');

                return '<a href="' . self::esc($url) . '"' . ($external ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $match[1] . '</a>';
            },
            $escaped
        );
    }

    private static function assetUrl(string $path): string
    {
        if (function_exists('cms_asset_url')) {
            return cms_asset_url($path);
        }

        return (defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '') . '/assets/' . $path;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }

    private static function noStore(): void
    {
        try {
            if (!headers_sent() && class_exists(\CMS\CacheManager::class)) {
                header_remove('ETag');
                header_remove('Last-Modified');
                \CMS\CacheManager::instance()->sendResponseHeaders('private');
            }
        } catch (\Throwable) {
        }
    }

    /** Nur für Tests. */
    public static function resetState(): void
    {
        self::$assetsQueued = false;
        self::$assetsPrinted = false;
        self::$instances = [];
        self::$nesting = 0;
    }
}
