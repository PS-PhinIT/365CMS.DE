<?php
/**
 * Keyset-Cursor für Listen, die nach (Zeitstempel, ID) absteigend sortiert sind.
 *
 * Form: base64url("<Y-m-d H:i:s UTC>|<id>"). Der Cursor ist nur eine Position, kein Recht: Rechte und Sichtbarkeit stehen
 * immer in der WHERE-Klausel; ein manipulierter Cursor springt höchstens an eine andere Stelle derselben, ohnehin
 * sichtbaren Liste. Ungültige Eingaben → null (erste Seite), nie eine Ausnahme. Das Datum muss ein wirklicher Zeitpunkt sein
 * (kein 9999-99-99, kein 0000-00-00, kein 30. Februar): MariaDB vergliche solche Werte nur mit einer Warnung (und falsch),
 * MySQL 8 meldet bei manchen Konstanten einen SQL-Fehler. Die ID passt in INT UNSIGNED (Themen- und Beitrags-IDs).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class Cursor
{
    public const MAX_LENGTH = 64;
    /** Größte ID einer INT-UNSIGNED-Spalte (`community_topics.id`); jeder größere Cursor kann keine Zeile bezeichnen. */
    public const MAX_ID = 4294967295;

    public function __construct(public readonly string $at, public readonly int $id)
    {
    }

    public static function decode(?string $raw): ?self
    {
        if ($raw === null || $raw === '' || strlen($raw) > self::MAX_LENGTH || preg_match('/^[A-Za-z0-9_-]+$/', $raw) !== 1) {
            return null;
        }
        $plain = base64_decode(strtr($raw, '-_', '+/'), true);
        if (!is_string($plain) || preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|([1-9]\d{0,19})$/', $plain, $m) !== 1) {
            return null;
        }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $m[1], new \DateTimeZone('UTC'));
        if ($time === false || $time->format('Y-m-d H:i:s') !== $m[1] || strlen($m[2]) > 10 || (int) $m[2] > self::MAX_ID) {
            return null;
        }

        return new self($m[1], (int) $m[2]);
    }

    public function encode(): string
    {
        return rtrim(strtr(base64_encode($this->at . '|' . $this->id), '+/', '-_'), '=');
    }
}
