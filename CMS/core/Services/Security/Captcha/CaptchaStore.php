<?php
/**
 * Ablage offener Aufgaben in der Sitzung (`$_SESSION['cms_captcha']`) oder, für Tests, im Speicher.
 *
 * - Je Aufgabe ein Datensatz unter ihrer Kennung (32 Hex-Zeichen); höchstens MAX_OPEN offene Aufgaben je Sitzung (die ältesten
 *   fallen weg), damit ein Skript keine Sitzung füllen kann. Abgelaufene Aufgaben entfernt `purge()` bei jedem Zugriff.
 * - `take()` liefert den Datensatz und löscht ihn (einmalige Verwendung, auch bei falscher Antwort).
 * - Sitzung wie `FormSpamGuard`: aktiv oder verzögert (Lazy Sessions, index.php startet sie am Request-Ende); ohne beides ist die
 *   Ablage nicht verfügbar (`available()` false): das Formular kann dann keine Aufgabe prüfen.
 *
 * @package CMSv2\Core\Services\Security
 */

declare(strict_types=1);

namespace CMS\Services\Security\Captcha;

use CMS\Security;

if (!defined('ABSPATH')) {
    exit;
}

final class CaptchaStore
{
    public const SESSION_KEY = 'cms_captcha';
    public const MAX_OPEN = 8;

    /** @var array<string, array<string, mixed>>|null null = Sitzung */
    private ?array $memory;

    private function __construct(?array $memory)
    {
        $this->memory = $memory;
    }

    public static function session(): self
    {
        return new self(null);
    }

    /** Nur für Tests: Ablage im Speicher. */
    public static function memory(): self
    {
        return new self([]);
    }

    public function available(): bool
    {
        return $this->memory !== null || session_status() === PHP_SESSION_ACTIVE || Security::isSessionDeferred();
    }

    /** @param array<string, mixed> $record muss `expires` (Unix-Zeit) enthalten */
    public function put(string $id, array $record, int $nowTs): void
    {
        $open = $this->read();
        $open = self::purge($open, $nowTs);
        $open[$id] = $record;
        uasort($open, static fn (array $a, array $b): int => (int) ($a['created'] ?? 0) <=> (int) ($b['created'] ?? 0));
        $this->write(array_slice($open, -self::MAX_OPEN, null, true));
    }

    /**
     * Datensatz holen und löschen; `null`, wenn es keinen gibt. Ein abgelaufener Datensatz wird als solcher geliefert (`expired` = true).
     *
     * @return array<string, mixed>|null
     */
    public function take(string $id, int $nowTs): ?array
    {
        $open = $this->read();
        if (!isset($open[$id]) || !is_array($open[$id])) {
            return null;
        }
        $record = $open[$id];
        unset($open[$id]);
        $this->write(self::purge($open, $nowTs));
        $record['expired'] = (int) ($record['expires'] ?? 0) < $nowTs;

        return $record;
    }

    public function count(): int
    {
        return count($this->read());
    }

    /**
     * @param array<string, array<string, mixed>> $open
     * @return array<string, array<string, mixed>>
     */
    private static function purge(array $open, int $nowTs): array
    {
        return array_filter($open, static fn (mixed $record): bool => is_array($record) && (int) ($record['expires'] ?? 0) >= $nowTs);
    }

    /** @return array<string, array<string, mixed>> */
    private function read(): array
    {
        $open = $this->memory ?? ($_SESSION[self::SESSION_KEY] ?? []);

        return is_array($open) ? $open : [];
    }

    /** @param array<string, array<string, mixed>> $open */
    private function write(array $open): void
    {
        if ($this->memory !== null) {
            $this->memory = $open;

            return;
        }
        $_SESSION[self::SESSION_KEY] = $open;
    }
}
