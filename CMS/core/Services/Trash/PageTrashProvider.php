<?php
/**
 * Papierkorb-Provider für Seiten (`pages`).
 *
 * Endgültiges Löschen entfernt zusätzlich Revisionen, SEO-Metadaten und Seitenaufrufe der Seite; der Hook
 * `page_deleted` entfernt sie aus dem Suchindex. Ist die Seite als Rechtstext-Seite zugeordnet
 * (Impressum, Datenschutz, AGB, Widerruf), verlangt das Löschen eine ausdrückliche Bestätigung.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Trash;

if (!defined('ABSPATH')) {
    exit;
}

final class PageTrashProvider extends AbstractContentTrashProvider
{
    public const TYPE = 'page';

    /** Einstellungen, die auf eine Seiten-ID zeigen => Sprachschlüssel der Bezeichnung */
    private const PAGE_ASSIGNMENT_OPTIONS = [
        'imprint_page_id' => 'trash.assignment.imprint',
        'privacy_page_id' => 'trash.assignment.privacy',
        'terms_page_id' => 'trash.assignment.terms',
        'revocation_page_id' => 'trash.assignment.revocation',
        'cancellation_page_id' => 'trash.assignment.cancellation',
    ];

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return TrashSupport::t('trash.type.page');
    }

    public function capability(): string
    {
        return 'manage_pages';
    }

    public function deletionWarnings(int $id): array
    {
        if ($id <= 0) {
            return [];
        }

        $options = array_keys(self::PAGE_ASSIGNMENT_OPTIONS);
        $placeholders = implode(',', array_fill(0, count($options), '?'));

        try {
            $assigned = $this->db->get_col(
                "SELECT option_name FROM {$this->prefix}settings WHERE option_name IN ({$placeholders}) AND option_value = ?",
                array_merge($options, [(string) $id])
            );
        } catch (\Throwable) {
            return [];
        }

        $warnings = [];
        foreach ($assigned as $optionName) {
            $labelKey = self::PAGE_ASSIGNMENT_OPTIONS[(string) $optionName] ?? '';
            if ($labelKey !== '') {
                $warnings[] = TrashSupport::t('trash.warning.page_assigned', TrashSupport::t($labelKey));
            }
        }

        return $warnings;
    }

    protected function table(): string
    {
        return 'pages';
    }

    protected function slugMaxLength(): int
    {
        return 200;
    }

    protected function restorableStatuses(): array
    {
        return ['draft', 'published', 'private'];
    }

    protected function saveHook(): string
    {
        return 'cms_after_page_save';
    }

    protected function deletedHook(): string
    {
        return 'page_deleted';
    }

    protected function auditEntity(): string
    {
        return 'pages';
    }

    protected function auditNoun(): string
    {
        return 'Seite';
    }

    protected function manageUrl(int $id): string
    {
        return '/admin/pages?status=trash';
    }

    protected function deleteDependentData(int $id): array
    {
        return [
            'page_revisions' => $this->deleteFrom('page_revisions', 'page_id = ?', [$id]),
            'seo_meta' => $this->deleteFrom('seo_meta', 'content_type = ? AND content_id = ?', ['page', $id]),
            'page_views' => $this->deleteFrom('page_views', 'page_id = ?', [$id]),
        ];
    }
}
