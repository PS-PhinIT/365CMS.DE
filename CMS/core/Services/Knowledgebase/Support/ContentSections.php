<?php

declare(strict_types=1);

namespace CMS\Services\Knowledgebase\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Zentrale Registry der strukturierten Inhaltsbereiche eines KB-Eintrags.
 *
 * Jeder Bereich besitzt eine eigene Spalte in `{prefix}kb_entries` und wird im
 * Admin über einen eigenen EditorJS-Block-Editor gepflegt. Die Reihenfolge der
 * Definitionen bestimmt die Reihenfolge im Editor und auf der Public-Seite.
 */
final class ContentSections
{
    /**
     * @return array<string, array{column: string, label: string, help: string, heading_de: string, heading_en: string, height: int, legacy_headings: array<int, string>}>
     */
    public static function definitions(): array
    {
        return [
            'overview' => [
                'column' => 'content',
                'label' => 'Allgemeine Infos',
                'help' => 'Hauptinhalt: Was ist der Begriff, wofür wird er verwendet? Kurz, verständlich und praxisnah erklären.',
                'heading_de' => 'Allgemeine Infos',
                'heading_en' => 'Overview',
                'height' => 260,
                'legacy_headings' => ['allgemeine infos', 'allgemein', 'einleitung', 'definition', 'beschreibung', 'überblick', 'ueberblick', 'was ist'],
            ],
            'details' => [
                'column' => 'content_details',
                'label' => 'Funktionsweise & technische Details',
                'help' => 'Wie funktioniert es? Architektur, Komponenten, Voraussetzungen, Konfiguration.',
                'heading_de' => 'Funktionsweise & Details',
                'heading_en' => 'How it works',
                'height' => 220,
                'legacy_headings' => ['funktionsweise', 'technische details', 'details', 'so funktioniert', 'wie funktioniert', 'voraussetzungen', 'architektur', 'konfiguration'],
            ],
            'benefits' => [
                'column' => 'content_benefits',
                'label' => 'Vorteile & Nutzen',
                'help' => 'Konkreter Mehrwert, z. B. als Liste mit Vorteilen.',
                'heading_de' => 'Vorteile & Nutzen',
                'heading_en' => 'Benefits',
                'height' => 200,
                'legacy_headings' => ['vorteile', 'nutzen', 'mehrwert', 'vorteile & nutzen', 'vorteile und nutzen'],
            ],
            'practice' => [
                'column' => 'content_practice',
                'label' => 'Praxisbeispiel & Einsatz',
                'help' => 'Typisches Szenario aus dem Alltag: Wann und wie wird es eingesetzt?',
                'heading_de' => 'Praxisbeispiel',
                'heading_en' => 'Practical example',
                'height' => 200,
                'legacy_headings' => ['praxisbeispiel', 'praxis', 'beispiel', 'typischer einsatz', 'einsatz', 'anwendung', 'anwendungsfälle', 'anwendungsfaelle', 'use case', 'use cases'],
            ],
            'notes' => [
                'column' => 'content_notes',
                'label' => 'Hinweise & Best Practices',
                'help' => 'Worauf sollte man achten? Stolperfallen, Sicherheit, Empfehlungen.',
                'heading_de' => 'Worauf sollte man achten?',
                'heading_en' => 'Things to keep in mind',
                'height' => 200,
                'legacy_headings' => ['worauf sollte man achten', 'hinweise', 'hinweis', 'praxis-hinweis', 'praxishinweis', 'best practices', 'best practice', 'tipps', 'stolperfallen'],
            ],
            'license' => [
                'column' => 'content_license',
                'label' => 'Lizenz & Verfügbarkeit',
                'help' => 'In welchen Lizenzen/Plänen enthalten, Kosten, Verfügbarkeit.',
                'heading_de' => 'Lizenz & Verfügbarkeit',
                'heading_en' => 'Licensing & availability',
                'height' => 160,
                'legacy_headings' => ['lizenz & verfügbarkeit', 'lizenz und verfügbarkeit', 'lizenz', 'lizenzierung', 'verfügbarkeit', 'verfuegbarkeit', 'kosten'],
            ],
            'conclusion' => [
                'column' => 'content_conclusion',
                'label' => 'Fazit',
                'help' => 'Kernaussage und klare Empfehlung bzw. Einordnung.',
                'heading_de' => 'Fazit',
                'heading_en' => 'Conclusion',
                'height' => 160,
                'legacy_headings' => ['fazit', 'zusammenfassung', 'empfehlung'],
            ],
            'links' => [
                'column' => 'content_links',
                'label' => 'Weiterführende Links',
                'help' => 'Quellen, Dokumentation (z. B. Microsoft Learn) – idealerweise als Liste mit Links.',
                'heading_de' => 'Weiterführende Links',
                'heading_en' => 'Further reading',
                'height' => 160,
                'legacy_headings' => ['weiterführende links', 'weiterfuehrende links', 'links', 'quellen', 'weblinks', 'ms learn / weblinks', 'mehr erfahren', 'dokumentation'],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function columns(): array
    {
        return array_values(array_map(
            static fn(array $definition): string => $definition['column'],
            self::definitions()
        ));
    }

    /**
     * Spalten, die per Migration neu angelegt werden (`content` existiert bereits).
     *
     * @return array<int, string>
     */
    public static function additionalColumns(): array
    {
        return array_values(array_filter(self::columns(), static fn(string $column): bool => $column !== 'content'));
    }

    /**
     * Ordnet eine Legacy-Überschrift einem Bereich zu oder liefert `null`.
     */
    public static function matchLegacyHeading(string $heading): ?string
    {
        $normalized = mb_strtolower(trim(html_entity_decode(strip_tags($heading), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 'UTF-8');
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $normalized), " \t\n\r\0\x0B:?!.");
        if ($normalized === '') {
            return null;
        }

        foreach (self::definitions() as $key => $definition) {
            if (in_array($normalized, $definition['legacy_headings'], true)) {
                return $key;
            }
        }

        foreach (self::definitions() as $key => $definition) {
            foreach ($definition['legacy_headings'] as $candidate) {
                if (mb_strlen($candidate, 'UTF-8') >= 5 && str_starts_with($normalized, $candidate)) {
                    return $key;
                }
            }
        }

        return null;
    }
}
