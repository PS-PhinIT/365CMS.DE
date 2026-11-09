<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * E-Mail senden über den zentralen MailService (SMTP oder mail()-Fallback).
 *
 * @param string $to      Empfänger
 * @param string $subject Betreff
 * @param string $body    HTML- oder Plain-Text-Inhalt
 * @param array  $headers Zusätzliche Header; `Content-Type: text/plain` versendet den Body als reinen Text
 */
function cms_mail(string $to, string $subject, string $body, array $headers = []): bool {
    $isPlain = false;
    foreach ($headers as $name => $value) {
        $headerName = is_int($name) && is_string($value) && str_contains($value, ':')
            ? strstr($value, ':', true)
            : (string) $name;
        if (strcasecmp(trim((string) $headerName), 'Content-Type') !== 0) {
            continue;
        }

        $headerValue = is_int($name) ? substr((string) $value, strpos((string) $value, ':') + 1) : (string) $value;
        $isPlain = stripos(ltrim($headerValue), 'text/plain') === 0;
        // Der MailService setzt den Content-Type selbst passend zum Versandweg.
        unset($headers[$name]);
    }

    $queue = \CMS\Services\MailQueueService::getInstance();
    if ($queue->shouldQueue($headers)) {
        $result = $isPlain
            ? $queue->enqueuePlain($to, $subject, $body, $headers, null, 'cms_mail_helper')
            : $queue->enqueue($to, $subject, $body, $headers, null, 'cms_mail_helper');
        return !empty($result['success']);
    }

    $mailer = \CMS\Services\MailService::getInstance();

    return $isPlain
        ? $mailer->sendPlain($to, $subject, $body, $headers)
        : $mailer->send($to, $subject, $body, $headers);
}
