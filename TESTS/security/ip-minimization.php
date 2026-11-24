<?php
/**
 * Audit 4.2.0 (AP-17, Punkt 17.3): Der 404-Monitor speichert gekürzte statt voller IP-Adressen. Ohne Datenbank.
 * Wird von TESTS/security/run.php eingebunden.
 */

declare(strict_types=1);

use CMS\Services\TrackingService;

T::run('AP-17 / 17.3: 404-Monitor speichert die IP-Adresse nur gekürzt', static function (): void {
    $source = str_replace("\r\n", "\n", (string) file_get_contents(ABSPATH . 'core/Services/RedirectService.php'));
    $start = strpos($source, 'public function logNotFound(');
    $end = $start === false ? false : strpos($source, 'private function writeNotFoundLog(', $start);
    $method = $start !== false && $end !== false ? substr($source, $start, $end - $start) : '';

    T::check($method !== '', 'logNotFound() gefunden');
    T::check(str_contains($method, "TrackingService::anonymizeIp((string)(\$context['ip_address'] ?? ''))"), 'IP wird vor dem Speichern gekürzt');
    T::check(!preg_match("/'ip_address'\s*=>\s*\\\$this->limitString\(\(string\)\(\\\$context\['ip_address'\]/", $method), 'keine ungekürzte Übernahme der Adresse');

    // Die Kürzung selbst (Grundlage der Zusage): letztes IPv4-Oktett 0, IPv6 nur die ersten 48 Bit.
    T::same('203.0.113.0', TrackingService::anonymizeIp('203.0.113.77'), 'IPv4');
    T::same('2001:db8:1::', TrackingService::anonymizeIp('2001:db8:1:2:3:4:5:6'), 'IPv6');
    T::same('', TrackingService::anonymizeIp(''), 'leer bleibt leer');
    T::same('', TrackingService::anonymizeIp('kein-ip'), 'ungültig bleibt leer');
});
