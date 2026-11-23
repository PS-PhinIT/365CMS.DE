<?php
/**
 * Audit 4.2.0 AP-03 (03.5): Zieladressen-Prüfung des zentralen HTTP-Clients (`Http\Client::isNonPublicIp()`), ohne Netzwerk.
 * Eingebettete IPv4-Adressen (IPv4-kompatibel, IPv4-übersetzt, 6to4) und Multicast dürfen die Sperre nicht umgehen.
 * Wird von TESTS/security/run.php eingebunden.
 */

declare(strict_types=1);

use CMS\Http\Client;

T::run('Audit 4.2.0 AP-03: SSRF-Zieladressen (isNonPublicIp)', static function (): void {
    $blocked = [
        // bisher abgedeckt
        '127.0.0.1', '0.0.0.0', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1', '192.0.0.8',
        '240.0.0.1', '255.255.255.255', '::1', '::', 'fe80::1', 'fc00::1', 'fd00:ec2::254', '[::1]',
        '::ffff:127.0.0.1', '::ffff:7f00:1', '::127.0.0.1', '::ffff:169.254.169.254', '64:ff9b::7f00:1', '64:ff9b:1::1',
        '2001::1', '2001:db8::1',
        // neu: in IPv6 eingebettete IPv4-Adressen in Hex-Schreibweise
        '::7f00:1', '::a00:1', '::a9fe:a9fe',
        '::ffff:0:7f00:1', '::ffff:0:a00:1', '::ffff:0:a9fe:a9fe',
        '2002:7f00:1::', '2002:a00:1::', '2002:c0a8:101::', '2002:a9fe:a9fe::1',
        // neu: Multicast
        '224.0.0.1', '239.255.255.250', 'ff02::1', 'ff05::2',
    ];
    foreach ($blocked as $ip) {
        T::check(Client::isNonPublicIp($ip), 'gesperrt: ' . $ip);
    }

    $allowed = ['8.8.8.8', '1.1.1.1', '93.184.216.34', '2606:4700:4700::1111', '2a00:1450:4001:81b::200e', '::ffff:8.8.8.8',
        '2002:808:808::1', '::808:808', '::ffff:0:808:808'];
    foreach ($allowed as $ip) {
        T::check(!Client::isNonPublicIp($ip), 'erlaubt (öffentliche Adresse, auch eingebettet): ' . $ip);
    }
});
