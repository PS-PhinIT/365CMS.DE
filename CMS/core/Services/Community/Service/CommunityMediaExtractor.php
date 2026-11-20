<?php
/**
 * Medienreferenzen in Forenbeiträgen für den Verwendungsindex der Mediathek (PR #2b; Vertrag DESIGN-2b §2.1, Entwurf medien §1.6, §2.7).
 *
 * Erfasst werden Verweise auf Dateien der Mediathek (`/uploads/…`) im Markdown veröffentlichter, wartender und ausgeblendeter Beiträge
 * (die Löschwarnung soll auch diese Verwendungen sehen); gelöschte und abgelehnte Beiträge gelten nicht als Verwendung. Anhänge liegen
 * im privaten Speicher und sind keine Mediathek-Dateien. Keine Uhr, keine Abfrage beim Konstruieren. Angemeldet wird der Extraktor
 * über `MediaUsageIndex::REGISTER_ACTION` in `CommunityHooks` hinter dem Modul-Gate (W3).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Database;
use CMS\Services\Community\Repository\SearchRepository;
use CMS\Services\Community\Support\Urls;
use CMS\Services\Media\MediaReferenceExtractor;
use CMS\Services\Media\Usage\ContentTableReferenceExtractor;

if (!defined('ABSPATH')) {
    exit;
}

final class CommunityMediaExtractor implements MediaReferenceExtractor
{
    public const CONTENT_TYPE = 'community_post';
    /** Beitragsstatus, die als Verwendung zählen. */
    public const STATUSES = ['published', 'pending', 'hidden'];

    private const LABEL = 'Forenbeitrag';

    private Database $db;
    private ?SearchRepository $search = null;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    public function contentType(): string
    {
        return self::CONTENT_TYPE;
    }

    public function label(): string
    {
        return self::LABEL;
    }

    /**
     * Beitrags-IDs mit einem Status aus STATUSES nach `$afterId`, aufsteigend (Primärschlüssel); `$limit` 1 bis 1000.
     *
     * @return list<int>
     */
    public function ids(int $afterId, int $limit): array
    {
        return $this->repository()->mediaIds(max(0, $afterId), max(1, min(1000, $limit)), self::STATUSES);
    }

    /**
     * Ein Beitrag mit Titel (Thementitel, bei Antworten mit ` · #<id>`), Link zum Beitrag und dem Markdown als Inhalt; `null` bei fehlendem,
     * gelöschtem oder abgelehntem Beitrag.
     *
     * @return array{title: string, edit_url: string, values: list<array{context: string, value: mixed}>}|null
     */
    public function load(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $post = $this->repository()->mediaPost($id);
        if ($post === null || !in_array($post['status'], self::STATUSES, true)) {
            return null;
        }
        $title = ContentTableReferenceExtractor::title((string) ($post['title'] ?? ''), (string) ($post['slug'] ?? ''), self::LABEL, $id);

        return [
            'title' => $post['is_first_post'] === 1 ? $title : $title . ' · #' . $id,
            'edit_url' => Urls::post($id),
            'values' => [['context' => 'content', 'value' => $post['body']]],
        ];
    }

    private function repository(): SearchRepository
    {
        return $this->search ??= new SearchRepository($this->db);
    }
}
