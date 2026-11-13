<?php
/**
 * Öffentliche Formular-Verarbeitung: Absenden (PRG), JSON-API und Rücksprung.
 *
 * Ablauf einer Absendung: Token → Honeypot → Feldprüfung → Antispam (Zeit, Rate-Limit, Captcha,
 * AntispamService) → Dateiinhalt → Speichern (optional) → Dateien → Mails → Hooks.
 * Routen siehe `ThemeRouter` (Kontaktseite, `/contact/<schlüssel>`) und `FormHooks` (API).
 *
 * @package CMSv2\Core\Services\Forms
 */

declare(strict_types=1);

namespace CMS\Services\Forms;

use CMS\Auth;
use CMS\Hooks;
use CMS\Logger;
use CMS\Router;
use CMS\Security;

if (!defined('ABSPATH')) {
    exit;
}

final class FormFrontendController
{
    public const FLASH_SESSION = 'cms_forms_flash';
    private const FLASH_TTL = 900;
    private const MAX_OLD_LENGTH = 10000;

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** POST eines Core-Formulars (Kennung `cms_form`)? */
    public static function isFormPost(): bool
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST'
            && is_string($_POST['cms_form'] ?? null)
            && $_POST['cms_form'] !== '';
    }

    /**
     * Rückmeldung nach dem Absenden für ein Formular abholen (einmalig).
     *
     * @return array<string, mixed>|null
     */
    public static function takeFlash(string $key): ?array
    {
        if (!isset($_SESSION) || !is_array($_SESSION[self::FLASH_SESSION] ?? null) || !isset($_SESSION[self::FLASH_SESSION][$key])) {
            return null;
        }
        $flash = $_SESSION[self::FLASH_SESSION][$key];
        unset($_SESSION[self::FLASH_SESSION][$key]);
        if ($_SESSION[self::FLASH_SESSION] === []) {
            unset($_SESSION[self::FLASH_SESSION]);
        }

        return is_array($flash) && (int) ($flash['time'] ?? 0) >= time() - self::FLASH_TTL ? $flash : null;
    }

    /**
     * Formular per POST verarbeiten und zurückleiten (Post/Redirect/Get).
     *
     * @param array<string, mixed> $form
     */
    public function handlePost(array $form, string $fallbackReturn = ''): void
    {
        $locale = FormI18n::normalizeLocale($_POST['_locale'] ?? FormI18n::requestLocale());
        $fallback = $fallbackReturn !== '' ? $fallbackReturn : self::formPath((string) $form['form_key'], $locale);
        $return = self::safeReturnPath($_POST['_return'] ?? '', $fallback);
        $result = $this->process($form, $_POST, $_FILES, ['locale' => $locale, 'source' => $return]);
        $anchor = '#cms-form-' . $form['form_key'];

        if ($result['status'] !== 'error') {
            $redirect = (string) ($form['settings']['redirect_url'] ?? '');
            if ($redirect !== '' && ($target = self::localTarget($redirect)) !== '') {
                $this->redirect($target);
            }
            $this->storeFlash((string) $form['form_key'], ['status' => 'success', 'message' => $result['message']]);
            $this->redirect($return . $anchor);
        }

        $this->storeFlash((string) $form['form_key'], [
            'status' => 'error',
            'message' => $result['message'],
            'errors' => $result['errors'],
            'old' => $result['old'],
        ]);
        $this->redirect($return . $anchor);
    }

    /**
     * JSON-Schnittstelle (kompatibel zu `POST /api/contact/<slug>/submit` des früheren Plugins).
     */
    public function handleApi(string $key): void
    {
        $locale = FormI18n::normalizeLocale($_POST['_locale'] ?? FormI18n::requestLocale());
        $form = FormService::getInstance()->findPublic(FormService::normalizeKey($key));
        if ($form === null) {
            $this->json(404, ['success' => false, 'error' => FormI18n::t('forms.error.not_found', $locale)]);
        }

        $result = $this->process($form, $_POST, $_FILES, [
            'locale' => $locale,
            'source' => self::safeReturnPath($_POST['_return'] ?? '', self::formPath((string) $form['form_key'], $locale)),
        ]);
        if ($result['status'] !== 'error') {
            $payload = ['success' => true, 'message' => $result['message']];
            $redirect = (string) ($form['settings']['redirect_url'] ?? '');
            if ($redirect !== '') {
                $payload['redirect'] = $redirect;
            }
            $this->json(200, $payload);
        }

        $this->json(422, [
            'success' => false,
            'error' => $result['message'],
            'errors' => (object) $result['errors'],
            'token' => Security::instance()->generateToken('form_' . $form['form_key']),
            // Die signierte Startzeit läuft ab – für den erneuten Versuch eine frische mitgeben.
            'ts' => FormSpamGuard::timestampToken((string) $form['form_key']),
        ]);
    }

    /**
     * Kernablauf einer Absendung (ohne Ausgabe).
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files `$_FILES`
     * @param array<string, mixed> $options locale, source, skip_token (nur Tests), require_uploaded
     * @return array{status: 'success'|'silent'|'error', message: string, errors: array<string, string>, old: array<string, mixed>, submission_id: int}
     */
    public function process(array $form, array $post, array $files = [], array $options = []): array
    {
        $locale = FormI18n::normalizeLocale($options['locale'] ?? FormI18n::requestLocale());
        $key = (string) $form['form_key'];
        $fields = is_array($form['fields'] ?? null) ? $form['fields'] : [];
        $settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
        $input = is_array($post['fields'] ?? null) ? $post['fields'] : [];
        $old = self::oldValues($fields, $input);
        $requireUploaded = (bool) ($options['require_uploaded'] ?? true);

        if (empty($options['skip_token'])) {
            $token = is_string($post['_token'] ?? null) ? $post['_token'] : (is_string($post['csrf_token'] ?? null) ? $post['csrf_token'] : '');
            if (!Security::instance()->verifyToken($token, 'form_' . $key)) {
                FormSpamGuard::logRejection('csrf', $form, 'form_rejected');

                return self::failure(FormI18n::t('forms.error.expired', $locale), [], $old);
            }
        }

        $uploads = FormUploadService::getInstance();
        $limits = FormSettings::uploadLimits();
        $validation = FormValidator::validate($fields, $input, $locale, $uploads->collect($files['fields'] ?? null), $limits);
        $values = $validation['values'];

        $honeypot = $post[FormSpamGuard::HONEYPOT_FIELD] ?? '';
        if ($validation['errors'] !== [] && !(is_scalar($honeypot) && trim((string) $honeypot) !== '')) {
            return self::failure(FormI18n::t('forms.error.summary', $locale), $validation['errors'], $old);
        }

        $spam = FormSpamGuard::check($form, $post, $values, $locale);
        if ($spam['status'] === 'silent') {
            return ['status' => 'silent', 'message' => self::successMessage($settings, $locale), 'errors' => [], 'old' => [], 'submission_id' => 0];
        }
        if ($spam['status'] === 'error') {
            $errors = $spam['reason'] === 'captcha' ? ['_captcha' => $spam['message']] : [];

            return self::failure($spam['message'], $errors, $old);
        }

        $fileErrors = $uploads->checkContents($fields, $values, $locale, $limits, $requireUploaded);
        if ($fileErrors !== []) {
            return self::failure(FormI18n::t('forms.error.summary', $locale), $fileErrors, $old);
        }

        $sourceUrl = (string) ($options['source'] ?? '');
        $consentText = self::consentText($fields, $locale);
        $userId = self::currentUserId();
        $submissionId = 0;
        $storedFiles = [];
        $fileCount = FormUploadService::countFiles($fields, $values);

        // Ohne gespeicherten Eingang gibt es keine Ablage (die Mail nennt nur Dateinamen):
        // lieber ablehnen als die Datei bei Erfolgsmeldung zu verwerfen.
        if ($fileCount > 0 && empty($settings['store_submissions'])) {
            Logger::instance()->withChannel('forms')->error('Formular mit Datei-Feld speichert keine Eingänge – Absendung mit Datei abgelehnt.', ['form' => $key]);

            return self::failure(FormI18n::t('forms.error.save', $locale), [], $old);
        }

        if (!empty($settings['store_submissions'])) {
            $submissionId = FormSubmissionService::getInstance()->create($form, $values, [
                'user_id' => $userId,
                'locale' => $locale,
                'source_url' => $sourceUrl,
                'consent_text' => $consentText,
                'privacy_url' => self::privacyUrl($fields),
            ]);
            if ($submissionId <= 0) {
                Logger::instance()->withChannel('forms')->error('Formulareingang konnte nicht gespeichert werden.', ['form' => $key]);

                return self::failure(FormI18n::t('forms.error.save', $locale), [], $old);
            }
            $storedFiles = $uploads->storeAll($submissionId, $fields, $values, $requireUploaded);
            if (count($storedFiles) < $fileCount) {
                // Eingang samt bereits abgelegter Dateien zurücknehmen, statt ihn ohne Datei als Erfolg zu melden.
                FormSubmissionService::getInstance()->deletePermanently($submissionId);
                Logger::instance()->withChannel('forms')->error('Hochgeladene Dateien konnten nicht abgelegt werden.', [
                    'form' => $key,
                    'expected' => $fileCount,
                    'stored' => count($storedFiles),
                ]);

                return self::failure(FormI18n::t('forms.error.save', $locale), [], $old);
            }
        }

        $mailContext = [
            'submission_id' => $submissionId,
            'locale' => $locale,
            'page_url' => $sourceUrl !== '' && defined('SITE_URL') ? rtrim((string) SITE_URL, '/') . $sourceUrl : '',
            'files' => $storedFiles,
        ];
        $notified = true;
        if (!empty($settings['notify'])) {
            $notified = FormMailer::sendNotification($form, $values, $mailContext);
        }
        if (!$notified && $submissionId <= 0) {
            return self::failure(FormI18n::t('forms.error.send', $locale), [], $old);
        }
        if (!$notified) {
            Logger::instance()->withChannel('forms')->warning('Benachrichtigung zu einem Formulareingang konnte nicht versendet werden.', [
                'form' => $key,
                'submission_id' => $submissionId,
            ]);
        }
        // Das Rate-Limit zählt bereits FormSpamGuard::check() – vor Speichern und Versand.
        FormMailer::sendConfirmation($form, $values, $mailContext + ['user_email' => self::currentUserEmail()]);

        $data = FormSubmissionService::buildData($fields, $values, FormI18n::siteLocale());
        try {
            Hooks::doAction('cms_form_submitted', $submissionId, $form, $data);
            Hooks::doAction('contact_submitted', $submissionId, (int) $form['id'], self::legacyHookMeta($fields, $values, $consentText !== ''));
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('forms')->warning('Hook nach Formular-Absendung fehlgeschlagen.', ['form' => $key, 'exception' => $e->getMessage()]);
        }

        return ['status' => 'success', 'message' => self::successMessage($settings, $locale), 'errors' => [], 'old' => [], 'submission_id' => $submissionId];
    }

    /**
     * Nur eigene Pfade als Rücksprung (keine fremden Hosts, kein `//`, keine Steuerzeichen).
     */
    public static function safeReturnPath(mixed $value, string $fallback): string
    {
        $value = Security::safeLocalPath(is_string($value) ? trim($value) : '');
        if ($value === '' || preg_match('#^/(?:admin|api)(?:[/?\#]|$)#i', $value) === 1) {
            return $fallback;
        }
        $value = (string) preg_replace('/#.*$/', '', $value);

        return $value !== '' ? $value : $fallback;
    }

    /** Öffentlicher Pfad eines Formulars (`/contact/<schlüssel>`, englisch mit `/en`). */
    public static function formPath(string $key, string $locale): string
    {
        return ($locale === 'en' ? '/en' : '') . '/contact/' . rawurlencode($key);
    }

    /**
     * @param array<string, mixed> $flash
     */
    private function storeFlash(string $key, array $flash): void
    {
        $flash['time'] = time();
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return;
        }
        $_SESSION[self::FLASH_SESSION][$key] = $flash;
    }

    private function redirect(string $target): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
        }
        Router::instance()->redirect($target, 303);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(int $status, array $payload): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, private');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Weiterleitungsziel nach Erfolg: eigener Pfad bzw. eigene Domain → Pfad.
     */
    private static function localTarget(string $url): string
    {
        $url = FormService::normalizeRedirectUrl($url);
        if ($url === '' || str_starts_with($url, '/')) {
            return $url;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = (string) parse_url($url, PHP_URL_QUERY);
        $fragment = (string) parse_url($url, PHP_URL_FRAGMENT);

        return ($path !== '' ? $path : '/') . ($query !== '' ? '?' . $query : '') . ($fragment !== '' ? '#' . $fragment : '');
    }

    /**
     * Eingaben für die erneute Anzeige (nur Text, gekürzt; keine Dateien).
     *
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function oldValues(array $fields, array $input): array
    {
        $old = [];
        foreach ($fields as $field) {
            $type = (string) ($field['type'] ?? '');
            if (!FormFieldTypes::isInput($type) || $type === 'file') {
                continue;
            }
            $name = (string) $field['name'];
            $value = $input[$name] ?? null;
            if (is_array($value)) {
                $old[$name] = array_slice(array_values(array_map(
                    static fn(mixed $item): string => is_scalar($item) ? mb_substr((string) $item, 0, 500) : '',
                    $value
                )), 0, FormFieldTypes::MAX_OPTIONS);
            } elseif (is_scalar($value)) {
                $old[$name] = mb_substr((string) $value, 0, self::MAX_OLD_LENGTH);
            }
        }

        return $old;
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     * @return array{status: 'error', message: string, errors: array<string, string>, old: array<string, mixed>, submission_id: int}
     */
    private static function failure(string $message, array $errors, array $old): array
    {
        return ['status' => 'error', 'message' => $message, 'errors' => $errors, 'old' => $old, 'submission_id' => 0];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function successMessage(array $settings, string $locale): string
    {
        $message = FormI18n::pick($settings['success_message'] ?? [], $locale);

        return $message !== '' ? $message : FormI18n::t('forms.success.default', $locale);
    }

    /**
     * Wortlaut der Einwilligung zum Zeitpunkt der Absendung (Nachweis).
     *
     * @param list<array<string, mixed>> $fields
     */
    private static function consentText(array $fields, string $locale): string
    {
        foreach ($fields as $field) {
            if (($field['type'] ?? '') !== 'consent') {
                continue;
            }
            $text = FormI18n::pick($field['label'] ?? [], $locale);
            if ($text === '') {
                $text = FormSettings::consentText($locale);
            }

            return str_replace('{privacy}', FormI18n::t('forms.consent.privacy_link', $locale), $text);
        }

        return '';
    }

    /**
     * @param list<array<string, mixed>> $fields
     */
    private static function privacyUrl(array $fields): string
    {
        foreach ($fields as $field) {
            if (($field['type'] ?? '') === 'consent' && (string) ($field['privacy_url'] ?? '') !== '') {
                return (string) $field['privacy_url'];
            }
        }

        return FormSettings::privacyUrl();
    }

    /**
     * Zusatzwerte im Format des früheren Plugin-Hooks `contact_submitted` (ohne Namen/E-Mail/Betreff/Nachricht).
     *
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function legacyHookMeta(array $fields, array $values, bool $consent): array
    {
        $meta = [];
        foreach ($fields as $field) {
            $type = (string) ($field['type'] ?? '');
            if (!FormFieldTypes::isInput($type) || in_array((string) ($field['role'] ?? ''), ['name', 'email', 'subject', 'message'], true)) {
                continue;
            }
            $value = $values[$field['name']] ?? null;
            if ($type === 'file') {
                $value = array_map(static fn(mixed $file): string => is_array($file) ? (string) ($file['name'] ?? '') : '', is_array($value) ? $value : []);
            }
            if ($value !== null && $value !== '' && $value !== []) {
                $meta[(string) $field['name']] = $value;
            }
        }
        if ($consent) {
            $meta['privacy_consent'] = '1';
            $meta['privacy_consent_confirmed_at'] = date('Y-m-d H:i:s');
        }

        return $meta;
    }

    private static function currentUserId(): int
    {
        try {
            if (Auth::isLoggedIn()) {
                $user = Auth::instance()->currentUser();

                return $user !== null ? (int) ($user->id ?? 0) : 0;
            }
        } catch (\Throwable) {
        }

        return 0;
    }

    /** E-Mail-Adresse des angemeldeten Kontos oder ''. */
    private static function currentUserEmail(): string
    {
        try {
            if (Auth::isLoggedIn()) {
                $user = Auth::instance()->currentUser();

                return $user !== null ? trim((string) ($user->email ?? '')) : '';
            }
        } catch (\Throwable) {
        }

        return '';
    }
}
