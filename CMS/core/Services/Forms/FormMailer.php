<?php
/**
 * Formular-Mails: Benachrichtigung an die Empfänger (Website-Sprache) und optionale Bestätigung an die
 * absendende Person (Sprache der Absendung). Versand über die Mail-Queue, sofern aktiv, sonst direkt.
 *
 * Platzhalter: {form_title} {site_name} {site_url} {date} {name} {email} {subject} {message} {fields}
 * {page_url} {admin_url} {from_name} {field:<feldname>}. IP-Adresse und User-Agent enthalten die Mails
 * bewusst nicht (Datenminimierung).
 *
 * @package CMSv2\Core\Services\Forms
 */

declare(strict_types=1);

namespace CMS\Services\Forms;

use CMS\Services\MailQueueService;
use CMS\Services\MailService;
use CMS\Services\OptionStore;

if (!defined('ABSPATH')) {
    exit;
}

final class FormMailer
{
    /**
     * Empfänger: Formular → Theme-Option `contact_email` → `forms.default_recipient` → ADMIN_EMAIL.
     *
     * @param array<string, mixed> $form
     * @return list<string>
     */
    public static function recipients(array $form): array
    {
        $recipients = FormService::normalizeEmails($form['settings']['recipients'] ?? []);
        if ($recipients !== []) {
            return $recipients;
        }

        $candidates = [];
        try {
            $candidates[] = (string) OptionStore::getInstance()->get('contact_email', '');
        } catch (\Throwable) {
        }
        $candidates[] = FormSettings::string('default_recipient');
        $candidates[] = defined('ADMIN_EMAIL') ? (string) ADMIN_EMAIL : '';
        foreach ($candidates as $candidate) {
            $emails = FormService::normalizeEmails($candidate);
            if ($emails !== []) {
                return [$emails[0]];
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $values Bereinigte Feldwerte
     * @param array<string, mixed> $context submission_id, locale, page_url
     */
    public static function sendNotification(array $form, array $values, array $context = []): bool
    {
        if (empty($form['settings']['notify'])) {
            return true;
        }
        $recipients = self::recipients($form);
        if ($recipients === []) {
            return false;
        }

        $locale = FormI18n::siteLocale();
        $vars = self::placeholders($form, $values, $locale, $context);
        $subjectTemplate = trim((string) ($form['settings']['mail_subject'] ?? ''));
        if ($subjectTemplate === '') {
            $subjectTemplate = FormSettings::mailTemplate('notification_subject', $locale);
        }
        $subject = self::headerSafe(self::replace($subjectTemplate, $vars));
        if ($subject === '' || trim(str_replace(['[', ']', $vars['{form_title}']], '', $subject)) === '') {
            $subject = self::headerSafe(self::replace(FormSettings::mailTemplate('notification_subject', $locale), $vars));
        }
        $body = self::replace(FormSettings::mailTemplate('notification_body', $locale), $vars);

        $headers = self::fromHeader();
        $summary = FormSubmissionService::summary($form['fields'] ?? [], $values);
        if (!empty($form['settings']['reply_to_sender']) && $summary['sender_email'] !== '') {
            $headers['Reply-To'] = self::address($summary['sender_email'], $summary['sender_name']);
        }

        $ok = true;
        foreach ($recipients as $index => $recipient) {
            $mailHeaders = $headers;
            if ($index === 0) {
                $cc = array_values(array_diff(FormService::normalizeEmails($form['settings']['cc'] ?? []), $recipients));
                $bcc = array_values(array_diff(FormService::normalizeEmails($form['settings']['bcc'] ?? []), $recipients, $cc));
                if ($cc !== []) {
                    $mailHeaders['Cc'] = implode(', ', $cc);
                }
                if ($bcc !== []) {
                    $mailHeaders['Bcc'] = implode(', ', $bcc);
                }
            }
            $ok = self::dispatch($recipient, $subject, $body, $mailHeaders) && $ok;
        }

        return $ok;
    }

    /**
     * Bestätigung an die absendende Person (nur bei aktivierter Option und gültiger Adresse).
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $values
     * @param array<string, mixed> $context
     */
    public static function sendConfirmation(array $form, array $values, array $context = []): bool
    {
        $confirmation = $form['settings']['confirmation'] ?? [];
        if (empty($confirmation['enabled'])) {
            return true;
        }
        $summary = FormSubmissionService::summary($form['fields'] ?? [], $values);
        if ($summary['sender_email'] === '') {
            return false;
        }

        $locale = FormI18n::normalizeLocale($context['locale'] ?? FormI18n::siteLocale());
        $vars = self::placeholders($form, $values, $locale, $context);
        $subject = FormI18n::pick($confirmation['subject'] ?? [], $locale);
        $body = FormI18n::pick($confirmation['body'] ?? [], $locale);
        $subject = self::headerSafe(self::replace($subject !== '' ? $subject : FormSettings::mailTemplate('confirmation_subject', $locale), $vars));
        $body = self::replace($body !== '' ? $body : FormSettings::mailTemplate('confirmation_body', $locale), $vars);

        $headers = self::fromHeader();
        $recipients = self::recipients($form);
        if ($recipients !== []) {
            $headers['Reply-To'] = $recipients[0];
        }

        return self::dispatch($summary['sender_email'], $subject, $body, $headers);
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $values
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    public static function placeholders(array $form, array $values, string $locale, array $context = []): array
    {
        $fields = $form['fields'] ?? [];
        $data = FormSubmissionService::buildData($fields, $values, $locale);
        $summary = FormSubmissionService::summary($fields, $values);
        $siteUrl = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
        $submissionId = (int) ($context['submission_id'] ?? 0);

        $lines = [];
        foreach ($data as $entry) {
            $text = trim((string) $entry['text']);
            if ($text === '') {
                $text = '–';
            }
            $lines[] = str_contains($text, "\n")
                ? $entry['label'] . ":\n" . self::indent($text)
                : $entry['label'] . ': ' . $text;
        }

        $pageUrl = trim((string) ($context['page_url'] ?? ''));
        if ($pageUrl !== '' && str_starts_with($pageUrl, '/')) {
            $pageUrl = $siteUrl . $pageUrl;
        }

        $vars = [
            '{form_title}' => (string) ($form['title'] ?? ''),
            '{site_name}' => self::siteName(),
            '{site_url}' => $siteUrl,
            '{date}' => self::formatDate($locale),
            '{name}' => $summary['sender_name'],
            '{email}' => $summary['sender_email'],
            '{subject}' => $summary['subject'] !== '' ? $summary['subject'] : (string) ($form['title'] ?? ''),
            '{message}' => $summary['message'],
            '{fields}' => implode("\n", $lines),
            '{page_url}' => $pageUrl !== '' ? $pageUrl : $siteUrl . '/',
            '{admin_url}' => $submissionId > 0
                ? $siteUrl . '/admin/form-submissions?view=' . $submissionId
                : $siteUrl . '/admin/form-submissions',
            '{from_name}' => FormSettings::fromName(),
        ];
        foreach ($data as $entry) {
            $vars['{field:' . $entry['name'] . '}'] = (string) $entry['text'];
        }

        return $vars;
    }

    /**
     * @param array<string, string> $vars
     */
    public static function replace(string $template, array $vars): string
    {
        $text = strtr($template, $vars);

        // Unbekannte Feld-Platzhalter leeren (z. B. nicht ausgefüllte Felder).
        return (string) preg_replace('/\{field:[a-z0-9_]+\}/i', '', $text);
    }

    /**
     * @param array<string, string> $headers
     */
    private static function dispatch(string $to, string $subject, string $body, array $headers): bool
    {
        try {
            $queue = MailQueueService::getInstance();
            if ($queue->shouldQueue($headers)) {
                $result = $queue->enqueuePlain($to, $subject, $body, $headers, null, 'forms');

                return !empty($result['success']);
            }

            return MailService::getInstance()->sendPlain($to, $subject, $body, $headers);
        } catch (\Throwable $e) {
            error_log('FormMailer: Versand fehlgeschlagen – ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    private static function fromHeader(): array
    {
        $fromEmail = FormSettings::string('from_email');
        if ($fromEmail === '' || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            return [];
        }

        return ['From' => self::address($fromEmail, FormSettings::fromName())];
    }

    private static function address(string $email, string $name): string
    {
        $name = trim(str_replace(['"', '\\', '<', '>', ',', ';'], '', self::headerSafe($name)));

        return $name !== '' ? '"' . $name . '" <' . $email . '>' : $email;
    }

    private static function headerSafe(string $value): string
    {
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

        return function_exists('mb_substr') ? mb_substr($value, 0, 250, 'UTF-8') : substr($value, 0, 250);
    }

    private static function indent(string $text): string
    {
        return '  ' . str_replace("\n", "\n  ", $text);
    }

    private static function siteName(): string
    {
        try {
            if (function_exists('cms_get_site_title')) {
                $title = trim((string) cms_get_site_title());
                if ($title !== '') {
                    return $title;
                }
            }
        } catch (\Throwable) {
        }

        return defined('SITE_NAME') ? (string) SITE_NAME : '365CMS';
    }

    private static function formatDate(string $locale): string
    {
        return $locale === 'en' ? date('Y-m-d H:i') : date('d.m.Y H:i');
    }
}
