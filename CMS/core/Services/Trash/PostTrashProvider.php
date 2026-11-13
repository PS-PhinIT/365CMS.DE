<?php
/**
 * Papierkorb-Provider für Beiträge (`posts`).
 *
 * Endgültiges Löschen entfernt zusätzlich Revisionen, Kommentare, SEO-Metadaten, Tag-/Kategorie-Zuordnungen
 * und Favoriten des Beitrags; der Hook `post_deleted` entfernt ihn aus dem Suchindex.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Trash;

if (!defined('ABSPATH')) {
    exit;
}

final class PostTrashProvider extends AbstractContentTrashProvider
{
    public const TYPE = 'post';

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return TrashSupport::t('trash.type.post');
    }

    public function capability(): string
    {
        return 'edit_all_posts';
    }

    protected function table(): string
    {
        return 'posts';
    }

    protected function slugMaxLength(): int
    {
        return 255;
    }

    protected function restorableStatuses(): array
    {
        return ['draft', 'published', 'private'];
    }

    protected function saveHook(): string
    {
        return 'cms_after_post_save';
    }

    protected function deletedHook(): string
    {
        return 'post_deleted';
    }

    protected function auditEntity(): string
    {
        return 'posts';
    }

    protected function auditNoun(): string
    {
        return 'Beitrag';
    }

    protected function manageUrl(int $id): string
    {
        return '/admin/posts?status=trash';
    }

    protected function deleteDependentData(int $id): array
    {
        return [
            'post_revisions' => $this->deleteFrom('post_revisions', 'post_id = ?', [$id]),
            'comments' => $this->deleteFrom('comments', 'post_id = ?', [$id]),
            'seo_meta' => $this->deleteFrom('seo_meta', 'content_type = ? AND content_id = ?', ['post', $id]),
            'translation_sync' => $this->deleteFrom('translation_sync', 'content_type = ? AND content_id = ?', ['post', $id]),
            'post_tag_rel' => $this->deleteFrom('post_tag_rel', 'post_id = ?', [$id]),
            'post_category_rel' => $this->deleteFrom('post_category_rel', 'post_id = ?', [$id]),
            'favorites' => $this->deleteFrom('favorites', 'post_id = ?', [$id]),
        ];
    }
}
