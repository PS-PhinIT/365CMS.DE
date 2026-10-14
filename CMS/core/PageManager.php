<?php
/**
 * Page Manager
 * 
 * Handles Pages, Content, Revisions and Search
 * 
 * @package CMSv2\Core
 */

declare(strict_types=1);

namespace CMS;

if (!defined('ABSPATH')) {
    exit;
}

class PageManager
{
    private static ?self $instance = null;
    private Database $db;
    private string $prefix;
    
    /**
     * Singleton instance
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->db->runSchemaOnce('page_manager', __FILE__, fn (): bool => $this->ensureColumns() && $this->ensureRevisionColumns());
    }

    /**
     * Migrate: add hide_title column if missing (safe for existing installs)
     */
    private function ensureColumns(): bool
    {
        try {
            $columns = [
                'slug_en' => "ALTER TABLE {$this->prefix}pages ADD COLUMN slug_en VARCHAR(200) DEFAULT NULL AFTER slug",
                'hide_title' => "ALTER TABLE {$this->prefix}pages ADD COLUMN hide_title TINYINT(1) NOT NULL DEFAULT 0",
                'show_title_toc' => "ALTER TABLE {$this->prefix}pages ADD COLUMN show_title_toc TINYINT(1) NOT NULL DEFAULT 0 AFTER hide_title",
                'featured_image' => "ALTER TABLE {$this->prefix}pages ADD COLUMN featured_image VARCHAR(500) DEFAULT NULL AFTER show_title_toc",
                'meta_title' => "ALTER TABLE {$this->prefix}pages ADD COLUMN meta_title VARCHAR(255) DEFAULT NULL AFTER featured_image",
                'meta_description' => "ALTER TABLE {$this->prefix}pages ADD COLUMN meta_description TEXT DEFAULT NULL AFTER meta_title",
                'title_en' => "ALTER TABLE {$this->prefix}pages ADD COLUMN title_en VARCHAR(255) DEFAULT NULL AFTER title",
                'content_en' => "ALTER TABLE {$this->prefix}pages ADD COLUMN content_en LONGTEXT DEFAULT NULL AFTER content",
                'category_id' => "ALTER TABLE {$this->prefix}pages ADD COLUMN category_id INT UNSIGNED DEFAULT NULL AFTER author_id",
                'content_updated_at' => "ALTER TABLE {$this->prefix}pages ADD COLUMN content_updated_at DATETIME DEFAULT NULL AFTER published_at",
                // Seitenvorlagen aus theme.json (`page_templates`) samt validierten Zusatzfeldern.
                'page_template' => "ALTER TABLE {$this->prefix}pages ADD COLUMN page_template VARCHAR(80) DEFAULT NULL AFTER category_id",
                'page_meta_json' => "ALTER TABLE {$this->prefix}pages ADD COLUMN page_meta_json TEXT DEFAULT NULL AFTER page_template",
            ];

            foreach ($columns as $column => $sql) {
                if (!$this->db->columnExists($this->prefix . 'pages', $column)) {
                    $this->db->query($sql);
                }
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('pages')->warning('Page schema compatibility columns could not be ensured.', [
                'exception' => $e,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Migrate: expand page revision snapshots for DE/EN compare views.
     */
    private function ensureRevisionColumns(): bool
    {
        try {
            $this->db->query("CREATE TABLE IF NOT EXISTS {$this->prefix}page_revisions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                page_id INT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                title_en VARCHAR(255) DEFAULT NULL,
                slug VARCHAR(200) DEFAULT NULL,
                slug_en VARCHAR(200) DEFAULT NULL,
                content LONGTEXT,
                content_en LONGTEXT,
                excerpt TEXT,
                status VARCHAR(20) DEFAULT NULL,
                author_id INT UNSIGNED,
                content_updated_at DATETIME DEFAULT NULL,
                page_template VARCHAR(80) DEFAULT NULL,
                page_meta_json TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_page_id (page_id),
                INDEX idx_author (author_id),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $columns = [
                'title_en' => "ALTER TABLE {$this->prefix}page_revisions ADD COLUMN title_en VARCHAR(255) DEFAULT NULL AFTER title",
                'slug' => "ALTER TABLE {$this->prefix}page_revisions ADD COLUMN slug VARCHAR(200) DEFAULT NULL AFTER title_en",
                'slug_en' => "ALTER TABLE {$this->prefix}page_revisions ADD COLUMN slug_en VARCHAR(200) DEFAULT NULL AFTER slug",
                'content_en' => "ALTER TABLE {$this->prefix}page_revisions ADD COLUMN content_en LONGTEXT AFTER content",
                'status' => "ALTER TABLE {$this->prefix}page_revisions ADD COLUMN status VARCHAR(20) DEFAULT NULL AFTER excerpt",
                'content_updated_at' => "ALTER TABLE {$this->prefix}page_revisions ADD COLUMN content_updated_at DATETIME DEFAULT NULL AFTER author_id",
                'page_template' => "ALTER TABLE {$this->prefix}page_revisions ADD COLUMN page_template VARCHAR(80) DEFAULT NULL AFTER content_updated_at",
                'page_meta_json' => "ALTER TABLE {$this->prefix}page_revisions ADD COLUMN page_meta_json TEXT DEFAULT NULL AFTER page_template",
            ];

            foreach ($columns as $column => $sql) {
                if (!$this->db->columnExists($this->prefix . 'page_revisions', $column)) {
                    $this->db->query($sql);
                }
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('pages')->warning('Page revision compatibility columns could not be ensured.', [
                'exception' => $e,
            ]);
            return false;
        }

        return true;
    }
    
    /**
     * Create Page
     */
    public function createPage(string $title, string $content, string $status, int $authorId, int $hideTitle = 0): int
    {
        $slug = $this->generateSlug($title);
        // Ensure unique slug
        $originalSlug = $slug;
        $count = 1;
        while ($this->getPageBySlug($slug)) {
            $slug = $originalSlug . '-' . $count++;
        }
        
        $sql = "INSERT INTO {$this->prefix}pages (title, content, slug, status, hide_title, author_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$title, $content, $slug, $status, $hideTitle, $authorId]);
        
        return (int)$this->db->insert_id();
    }
    
    /**
     * Update Page
     */
    public function updatePage(int $id, array $data): bool
    {
        $currentPage = $this->getPage($id);
        if ($currentPage === null) {
            return false;
        }

        $fields = [];
        $values = [];
        
        foreach ($data as $key => $value) {
            if (in_array($key, ['title', 'title_en', 'content', 'content_en', 'status', 'slug', 'slug_en', 'hide_title', 'show_title_toc', 'featured_image', 'meta_title', 'meta_description', 'category_id', 'content_updated_at', 'page_template', 'page_meta_json'], true)) {
                $fields[] = "$key = ?";
                $values[] = $value;
            }
        }
        
        if (empty($fields)) {
            return false;
        }

        if ($this->hasTrackedRevisionChanges($currentPage, $data) && !$this->storeRevisionSnapshot($currentPage)) {
            return false;
        }
        
        $fields[] = "updated_at = NOW()";
        $values[] = $id;
        
        $sql = "UPDATE {$this->prefix}pages SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($values);
    }

    /**
     * Prüft, ob sich revisionsrelevante Inhaltsfelder geändert haben.
     */
    private function hasTrackedRevisionChanges(array $currentPage, array $newData): bool
    {
        foreach (['title', 'title_en', 'slug', 'slug_en', 'content', 'content_en', 'excerpt', 'status', 'content_updated_at', 'page_template', 'page_meta_json'] as $field) {
            if (!array_key_exists($field, $newData)) {
                continue;
            }

            if ((string)($currentPage[$field] ?? '') !== (string)$newData[$field]) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * Delete Page
     */
    public function deletePage(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->prefix}pages WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Generate a URL-safe slug from a title, with German umlaut handling
     */
    public function generateSlug(string $title): string
    {
        // German umlauts and common accented chars → ASCII equivalents
        $title = str_replace(
            ['ä','ö','ü','Ä','Ö','Ü','ß','é','è','ê','à','â','î','ï','ô','ù','û'],
            ['ae','oe','ue','ae','oe','ue','ss','e','e','e','a','a','i','i','o','u','u'],
            $title
        );
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
        return $slug !== '' ? $slug : 'seite';
    }

    /**
     * Get Page by ID (returns Array)
     */
    public function getPage(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->prefix}pages WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }
    
    /**
     * Get Page by Slug (returns Array)
     */
    public function getPageBySlug(string $slug, string $locale = 'de'): ?array
    {
        $normalizedLocale = strtolower(trim($locale));
        if ($normalizedLocale === 'en') {
            $stmt = $this->db->prepare("SELECT * FROM {$this->prefix}pages WHERE (slug_{$normalizedLocale} = ? OR slug = ?) LIMIT 1");
            $stmt->execute([$slug, $slug]);
        } else {
            $stmt = $this->db->prepare("SELECT * FROM {$this->prefix}pages WHERE slug = ? LIMIT 1");
            $stmt->execute([$slug]);
        }
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function pageMatchesLocaleAvailability(array $page, string $locale): bool
    {
        $locale = strtolower(trim($locale));
        if ($locale === '' || $locale === 'de') {
            return $this->pageHasBaseContent($page);
        }

        return $this->pageHasLocalizedContent($page, $locale);
    }

    private function pageHasBaseContent(array $page): bool
    {
        foreach (['title', 'content'] as $field) {
            if (trim((string) ($page[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function pageHasLocalizedContent(array $page, string $locale): bool
    {
        $locale = preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $locale) === 1 ? $locale : 'en';
        foreach (['title_' . $locale, 'content_' . $locale] as $field) {
            if (trim((string) ($page[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
    
    /**
     * Search Pages
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        // Platzhalter im Suchbegriff wörtlich nehmen („%“ lieferte sonst alle Seiten).
        $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
        // Öffentliche Suche: geplante Seiten (published_at in der Zukunft) und interne Spalten
        // wie page_meta_json/author_id nicht ausliefern.
        $stmt = $this->db->prepare("SELECT id, slug, slug_en, title, title_en, excerpt, content, content_en, featured_image, meta_title, meta_description, status, created_at, updated_at, published_at FROM {$this->prefix}pages WHERE (title LIKE ? ESCAPE '!' OR content LIKE ? ESCAPE '!' OR title_en LIKE ? ESCAPE '!' OR content_en LIKE ? ESCAPE '!') AND status = 'published' AND (published_at IS NULL OR published_at <= NOW()) ORDER BY created_at DESC LIMIT 20");
        $stmt->execute([$term, $term, $term, $term]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Speichert einen Revisions-Snapshot der aktuellen Seite.
     */
    private function storeRevisionSnapshot(array $page): bool
    {
        $pageId = (int)($page['id'] ?? 0);
        if ($pageId <= 0) {
            return false;
        }

        $sql = "INSERT INTO {$this->prefix}page_revisions (page_id, title, title_en, slug, slug_en, content, content_en, excerpt, status, author_id, content_updated_at, page_template, page_meta_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            $pageId,
            (string)($page['title'] ?? ''),
            (string)($page['title_en'] ?? ''),
            (string)($page['slug'] ?? ''),
            (string)($page['slug_en'] ?? ''),
            (string)($page['content'] ?? ''),
            (string)($page['content_en'] ?? ''),
            (string)($page['excerpt'] ?? ''),
            (string)($page['status'] ?? 'draft'),
            (int)($page['author_id'] ?? 0),
                trim((string)($page['content_updated_at'] ?? '')) ?: null,
            trim((string)($page['page_template'] ?? '')) ?: null,
            trim((string)($page['page_meta_json'] ?? '')) ?: null,
        ]);
    }

    /**
     * Gibt gespeicherte Revisionen einer Seite zurück.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRevisions(int $pageId): array
    {
        if ($pageId <= 0) {
            return [];
        }

        $sql = "SELECT pr.id,
                       pr.page_id,
                       pr.title,
                       pr.title_en,
                       pr.slug,
                       pr.slug_en,
                       pr.content,
                       pr.content_en,
                       pr.excerpt,
                       pr.status,
                       pr.author_id,
                       pr.content_updated_at,
                       pr.page_template,
                       pr.page_meta_json,
                       pr.created_at,
                       u.username,
                       u.display_name
                FROM {$this->prefix}page_revisions pr
                LEFT JOIN {$this->prefix}users u ON pr.author_id = u.id
                WHERE pr.page_id = ?
                ORDER BY pr.created_at DESC, pr.id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$pageId]);
        $revisions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $revisions ?: [];
    }
}
