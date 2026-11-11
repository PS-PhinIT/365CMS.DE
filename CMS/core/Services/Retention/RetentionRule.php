<?php
/**
 * Eine Aufbewahrungsregel: Frist, Trockenlauf-Zählung und Löschung/Anonymisierung.
 *
 * Funktionsbereiche registrieren Regeln über `DataRetentionService::registerRule()` (typisch in ihrer
 * Klasse unter `CMS\Services\FeatureHooks`). Der tägliche Cron ruft `prune()` auf, die Übersicht
 * *System → Datenaufbewahrung* zeigt Frist, Trockenlauf und letzten Lauf.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Retention;

if (!defined('ABSPATH')) {
    exit;
}

final class RetentionRule
{
    public const ACTION_DELETE = 'delete';
    public const ACTION_ANONYMIZE = 'anonymize';

    /**
     * @param string   $key         eindeutiger Schlüssel `[a-z0-9_.-]`, z. B. `form_submissions`
     * @param string   $label       übersetzte Bezeichnung
     * @param string   $description übersetzte Erläuterung (z. B. „je Formular einstellbar“), darf leer sein
     * @param \Closure $days        `fn(): int` wirksame Frist in Tagen, 0 = nie
     * @param \Closure $countDue    `fn(): int` Trockenlauf: Einträge, die jetzt gelöscht/anonymisiert würden
     * @param \Closure $prune       `fn(): int` löscht bzw. anonymisiert in Blöcken, liefert die Anzahl
     * @param string   $settingsUrl Admin-Pfad, unter dem sich die Frist ändern lässt
     * @param string   $action      `delete` oder `anonymize`
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        private readonly \Closure $days,
        private readonly \Closure $countDue,
        private readonly \Closure $prune,
        public readonly string $settingsUrl = '',
        public readonly string $action = self::ACTION_DELETE,
    ) {
        if (preg_match('/^[a-z0-9_.-]{1,64}$/', $key) !== 1) {
            throw new \InvalidArgumentException('Ungültiger Schlüssel für Aufbewahrungsregel: ' . $key);
        }
    }

    public function days(): int
    {
        return max(0, (int) ($this->days)());
    }

    public function countDue(): int
    {
        return $this->days() > 0 ? max(0, (int) ($this->countDue)()) : 0;
    }

    public function prune(): int
    {
        return $this->days() > 0 ? max(0, (int) ($this->prune)()) : 0;
    }
}
