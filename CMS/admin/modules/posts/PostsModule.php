<?php
declare(strict_types=1);

/**
 * Posts Module – CRUD-Logik für Beiträge
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/PostsCategoryViewModelBuilder.php';

use CMS\AuditLogger;
use CMS\Auth;
use CMS\CacheManager;
use CMS\Database;
use CMS\Hooks;
use CMS\Logger;
use CMS\Services\ContentLocalizationService;
use CMS\Services\ContentMediaPlacementService;
use CMS\Services\PermalinkService;
use CMS\Services\RedirectService;
use CMS\Services\SEOService;

if (!class_exists('PostsModule', false)) {
class PostsModule
{
    private Database $db;
    private string $prefix;
    private PostsCategoryViewModelBuilder $categoryViewModelBuilder;

    private function canManageAllPosts(): bool
    {
        return Auth::instance()->hasCapability('edit_all_posts');
    }

    private function currentUserId(): int
    {
        $user = Auth::instance()->currentUser();

        return is_object($user) ? (int) ($user->id ?? 0) : 0;
    }

    private function addOwnershipFilter(array &$where, array &$params, string $alias = 'p'): void
    {
        if ($this->canManageAllPosts()) {
            return;
        }

        $userId = $this->currentUserId();
        $where[] = "{$alias}.author_id = ?";
        $params[] = $userId;
    }

    private function canAccessPost(int $id): bool
    {
        if ($this->canManageAllPosts()) {
            return true;
        }

        $authorId = $this->db->get_var(
            "SELECT author_id FROM {$this->prefix}posts WHERE id = ? LIMIT 1",
            [$id]
        );

        return $authorId !== null && (int) $authorId === $this->currentUserId();
    }

    /**
     * @var array<int,array{root: array{slug:string,name:string,sort_order:int}, children: array<string,string>}>
     */
    private const DEFAULT_CATEGORY_TREES = [
        [
            'root' => [
                'slug' => 'microsoft-365',
                'name' => 'Microsoft 365',
                'sort_order' => 10,
            ],
            'children' => [
                'microsoft-cloud-services' => 'Microsoft Cloud Services',
                'microsoft-copilot' => 'Microsoft Copilot',
                'microsoft-teams' => 'Microsoft Teams',
                'exchange-online' => 'Exchange Online',
                'outlook' => 'Outlook',
                'sharepoint-online' => 'SharePoint Online',
                'onedrive-for-business' => 'OneDrive for Business',
                'microsoft-entra-id' => 'Microsoft Entra ID',
                'microsoft-intune' => 'Microsoft Intune',
                'microsoft-defender' => 'Microsoft Defender',
                'microsoft-purview' => 'Microsoft Purview',
                'power-platform' => 'Power Platform',
                'power-automate' => 'Power Automate',
                'power-apps' => 'Power Apps',
                'power-bi' => 'Power BI',
                'planner-to-do' => 'Planner & To Do',
                'microsoft-viva' => 'Microsoft Viva',
                'microsoft-forms' => 'Microsoft Forms',
                'microsoft-loop' => 'Microsoft Loop',
                'windows-365' => 'Windows 365',
            ],
        ],
        [
            'root' => [
                'slug' => 'technik-it',
                'name' => 'Technik & IT',
                'sort_order' => 200,
            ],
            'children' => [
                'it-infrastruktur' => 'IT-Infrastruktur',
                'cyber-security' => 'Cyber Security',
                'netzwerk-systeme' => 'Netzwerk & Systeme',
                'cloud-devops' => 'Cloud & DevOps',
                'softwareentwicklung' => 'Softwareentwicklung',
                'ki-automatisierung' => 'KI & Automatisierung',
                'hardware-devices' => 'Hardware & Devices',
                'open-source-tools' => 'Open Source & Tools',
            ],
        ],
    ];

    /** @var string[] */
    private const ALLOWED_LIST_STATUSES = ['published', 'scheduled', 'draft', 'private'];

    /** @var string[] */
    private const ALLOWED_BULK_ACTIONS = [
        'delete',
        'publish',
        'draft',
        'set_category',
        'clear_category',
        'set_author_display_name',
        'clear_author_display_name',
    ];

    public function __construct()
    {
        $this->db     = Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->categoryViewModelBuilder = new PostsCategoryViewModelBuilder();
        $this->ensureColumns();
        $this->ensureCategoryColumns();
        $this->ensureTagColumns();
        $this->ensurePostCategoryRelationTable();
        $this->ensurePostRevisionTable();
        $this->ensureDefaultCategories();
    }

    /**
     * Ergänzt fehlende Beitrags-Spalten in Altinstallationen.
     */
    private function ensureColumns(): void
    {
        $columns = [
            'slug_en' => "ALTER TABLE {$this->prefix}posts ADD COLUMN slug_en VARCHAR(255) DEFAULT NULL AFTER slug",
            'featured_image' => "ALTER TABLE {$this->prefix}posts ADD COLUMN featured_image VARCHAR(500) DEFAULT NULL AFTER excerpt",
            'title_en' => "ALTER TABLE {$this->prefix}posts ADD COLUMN title_en VARCHAR(255) DEFAULT NULL AFTER title",
            'content_en' => "ALTER TABLE {$this->prefix}posts ADD COLUMN content_en LONGTEXT DEFAULT NULL AFTER content",
            'excerpt_en' => "ALTER TABLE {$this->prefix}posts ADD COLUMN excerpt_en TEXT DEFAULT NULL AFTER excerpt",
            'meta_title' => "ALTER TABLE {$this->prefix}posts ADD COLUMN meta_title VARCHAR(255) DEFAULT NULL AFTER allow_comments",
            'meta_description' => "ALTER TABLE {$this->prefix}posts ADD COLUMN meta_description TEXT DEFAULT NULL AFTER meta_title",
            'author_display_name' => "ALTER TABLE {$this->prefix}posts ADD COLUMN author_display_name VARCHAR(150) DEFAULT NULL AFTER author_id",
            'author_display_url' => "ALTER TABLE {$this->prefix}posts ADD COLUMN author_display_url VARCHAR(500) DEFAULT NULL AFTER author_display_name",
            'post_template' => "ALTER TABLE {$this->prefix}posts ADD COLUMN post_template VARCHAR(80) DEFAULT NULL AFTER author_display_name",
            'post_meta_json' => "ALTER TABLE {$this->prefix}posts ADD COLUMN post_meta_json TEXT DEFAULT NULL AFTER post_template",
            'content_updated_at' => "ALTER TABLE {$this->prefix}posts ADD COLUMN content_updated_at DATETIME DEFAULT NULL AFTER published_at",
        ];

        foreach ($columns as $column => $sql) {
            try {
                $stmt = $this->db->query("SHOW COLUMNS FROM {$this->prefix}posts LIKE '{$column}'");
                if ($stmt instanceof \PDOStatement && !$stmt->fetch()) {
                    $this->db->query($sql);
                }
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('admin.posts')->warning('Beitrags-Spalte konnte nicht automatisch ergänzt werden.', [
                    'column' => $column,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    private function ensureCategoryColumns(): void
    {
        $columns = [
            'slug_en' => "ALTER TABLE {$this->prefix}post_categories ADD COLUMN slug_en VARCHAR(100) DEFAULT NULL AFTER slug",
            'parent_id' => "ALTER TABLE {$this->prefix}post_categories ADD COLUMN parent_id INT UNSIGNED DEFAULT NULL AFTER description",
            'sort_order' => "ALTER TABLE {$this->prefix}post_categories ADD COLUMN sort_order INT DEFAULT 0 AFTER parent_id",
            'replacement_category_id' => "ALTER TABLE {$this->prefix}post_categories ADD COLUMN replacement_category_id INT UNSIGNED DEFAULT NULL AFTER sort_order",
        ];

        foreach ($columns as $column => $sql) {
            try {
                $stmt = $this->db->query("SHOW COLUMNS FROM {$this->prefix}post_categories LIKE '{$column}'");
                if ($stmt instanceof \PDOStatement && !$stmt->fetch()) {
                    $this->db->query($sql);
                }
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('admin.posts')->warning('Beitrags-Kategoriespalte konnte nicht automatisch ergänzt werden.', [
                    'column' => $column,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Ergänzt fehlende Tag-Spalten in Altinstallationen.
     */
    private function ensureTagColumns(): void
    {
        $columns = [
            'slug_en' => "ALTER TABLE {$this->prefix}post_tags ADD COLUMN slug_en VARCHAR(100) DEFAULT NULL AFTER slug",
        ];

        foreach ($columns as $column => $sql) {
            try {
                $stmt = $this->db->query("SHOW COLUMNS FROM {$this->prefix}post_tags LIKE '{$column}'");
                if ($stmt instanceof \PDOStatement && !$stmt->fetch()) {
                    $this->db->query($sql);
                }
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('admin.posts')->warning('Beitrags-Tagspalte konnte nicht automatisch ergänzt werden.', [
                    'column' => $column,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    private function ensurePostCategoryRelationTable(): void
    {
        try {
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS {$this->prefix}post_category_rel (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    post_id BIGINT UNSIGNED NOT NULL,
                    category_id INT UNSIGNED NOT NULL,
                    UNIQUE KEY unique_post_category (post_id, category_id),
                    INDEX idx_post_id (post_id),
                    INDEX idx_category_id (category_id),
                    FOREIGN KEY (post_id) REFERENCES {$this->prefix}posts(id) ON DELETE CASCADE,
                    FOREIGN KEY (category_id) REFERENCES {$this->prefix}post_categories(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('admin.posts')->warning('Beitrags-Kategorie-Relationstabelle konnte nicht automatisch angelegt werden.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function ensurePostRevisionTable(): void
    {
        try {
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS {$this->prefix}post_revisions (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    post_id BIGINT UNSIGNED NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    title_en VARCHAR(255) DEFAULT NULL,
                    slug VARCHAR(255) DEFAULT NULL,
                    slug_en VARCHAR(255) DEFAULT NULL,
                    content LONGTEXT,
                    content_en LONGTEXT,
                    excerpt TEXT,
                    excerpt_en TEXT,
                    status VARCHAR(20) DEFAULT NULL,
                    category_id INT UNSIGNED DEFAULT NULL,
                    category_name VARCHAR(150) DEFAULT NULL,
                    tags VARCHAR(500) DEFAULT NULL,
                    author_id INT UNSIGNED DEFAULT NULL,
                    author_display_name VARCHAR(150) DEFAULT NULL,
                    author_display_url VARCHAR(500) DEFAULT NULL,
                    published_at TIMESTAMP NULL,
                        content_updated_at DATETIME DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_post_id (post_id),
                    INDEX idx_author_id (author_id),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $columns = [
                'title_en' => "ALTER TABLE {$this->prefix}post_revisions ADD COLUMN title_en VARCHAR(255) DEFAULT NULL AFTER title",
                'slug' => "ALTER TABLE {$this->prefix}post_revisions ADD COLUMN slug VARCHAR(255) DEFAULT NULL AFTER title_en",
                'slug_en' => "ALTER TABLE {$this->prefix}post_revisions ADD COLUMN slug_en VARCHAR(255) DEFAULT NULL AFTER slug",
                'content_en' => "ALTER TABLE {$this->prefix}post_revisions ADD COLUMN content_en LONGTEXT AFTER content",
                'excerpt_en' => "ALTER TABLE {$this->prefix}post_revisions ADD COLUMN excerpt_en TEXT DEFAULT NULL AFTER excerpt",
                'status' => "ALTER TABLE {$this->prefix}post_revisions ADD COLUMN status VARCHAR(20) DEFAULT NULL AFTER excerpt_en",
                'category_id' => "ALTER TABLE {$this->prefix}post_revisions ADD COLUMN category_id INT UNSIGNED DEFAULT NULL AFTER status",
                'category_name' => "ALTER TABLE {$this->prefix}post_revisions ADD COLUMN category_name VARCHAR(150) DEFAULT NULL AFTER category_id",
                'tags' => "ALTER TABLE {$this->prefix}post_revisions ADD COLUMN tags VARCHAR(500) DEFAULT NULL AFTER category_name",
                'author_display_name' => "ALTER TABLE {$this->prefix}post_revisions ADD COLUMN author_display_name VARCHAR(150) DEFAULT NULL AFTER author_id",
                'author_display_url' => "ALTER TABLE {$this->prefix}post_revisions ADD COLUMN author_display_url VARCHAR(500) DEFAULT NULL AFTER author_display_name",
                'published_at' => "ALTER TABLE {$this->prefix}post_revisions ADD COLUMN published_at TIMESTAMP NULL AFTER author_display_url",
                    'content_updated_at' => "ALTER TABLE {$this->prefix}post_revisions ADD COLUMN content_updated_at DATETIME DEFAULT NULL AFTER published_at",
            ];

            foreach ($columns as $column => $sql) {
                $stmt = $this->db->query("SHOW COLUMNS FROM {$this->prefix}post_revisions LIKE '{$column}'");
                if ($stmt instanceof \PDOStatement && !$stmt->fetch()) {
                    $this->db->query($sql);
                }
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('admin.posts')->warning('Beitrags-Revisionsschema konnte nicht automatisch sichergestellt werden.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function ensureDefaultCategories(): void
    {
        try {
            foreach (self::DEFAULT_CATEGORY_TREES as $tree) {
                $root = (array) ($tree['root'] ?? []);
                $rootSlug = (string) ($root['slug'] ?? '');
                $rootName = (string) ($root['name'] ?? '');
                $rootSortOrder = (int) ($root['sort_order'] ?? 0);

                if ($rootSlug === '' || $rootName === '') {
                    continue;
                }

                $rootId = $this->getCategoryIdBySlug($rootSlug);

                if ($rootId <= 0) {
                    $this->db->execute(
                        "INSERT INTO {$this->prefix}post_categories (name, slug, parent_id, sort_order) VALUES (?, ?, NULL, ?)",
                        [$rootName, $rootSlug, $rootSortOrder]
                    );
                    $rootId = (int) $this->db->lastInsertId();
                } else {
                    $this->db->execute(
                        "UPDATE {$this->prefix}post_categories SET name = ?, parent_id = NULL, sort_order = ? WHERE id = ?",
                        [$rootName, $rootSortOrder, $rootId]
                    );
                }

                $sortOrder = $rootSortOrder + 10;
                foreach ((array) ($tree['children'] ?? []) as $slug => $name) {
                    $categoryId = $this->getCategoryIdBySlug((string) $slug);

                    if ($categoryId <= 0) {
                        $this->db->execute(
                            "INSERT INTO {$this->prefix}post_categories (name, slug, parent_id, sort_order) VALUES (?, ?, ?, ?)",
                            [(string) $name, (string) $slug, $rootId, $sortOrder]
                        );
                    } else {
                        $this->db->execute(
                            "UPDATE {$this->prefix}post_categories SET name = ?, parent_id = ?, sort_order = ? WHERE id = ?",
                            [(string) $name, $rootId, $sortOrder, $categoryId]
                        );
                    }

                    $sortOrder += 10;
                }
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('admin.posts')->warning('Standard-Beitragskategorien konnten nicht vollständig sichergestellt werden.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function getCategoryIdBySlug(string $slug): int
    {
        return (int) ($this->db->get_var(
            "SELECT id FROM {$this->prefix}post_categories WHERE slug = ? LIMIT 1",
            [$slug]
        ) ?: 0);
    }

    private function categoryExists(int $categoryId): bool
    {
        if ($categoryId <= 0) {
            return false;
        }

        return (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}post_categories WHERE id = ?",
            [$categoryId]
        ) > 0;
    }

    private function postExists(int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }

        return (int) ($this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}posts WHERE id = ?",
            [$postId]
        ) ?: 0) > 0;
    }

    /**
     * @param array<int,int> $ids
     * @return array<int,int>
     */
    private function getExistingPostIds(array $ids, ?int $authorId = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $where = "id IN ({$placeholders})";
        $params = $ids;
        if ($authorId !== null) {
            $where .= ' AND author_id = ?';
            $params[] = $authorId;
        }

        $rows = $this->db->get_results(
            "SELECT id FROM {$this->prefix}posts WHERE {$where}",
            $params
        ) ?: [];

        $existingIds = [];
        foreach ($rows as $row) {
            $postId = (int) ($row->id ?? 0);
            if ($postId > 0) {
                $existingIds[$postId] = $postId;
            }
        }

        return array_values($existingIds);
    }

    /**
     * Daten für die Listenansicht
     */
    public function getListData(): array
    {
        $currentDateTime = date('Y-m-d H:i:s');
        $visibilityWhere = [];
        $visibilityParams = [];
        $this->addOwnershipFilter($visibilityWhere, $visibilityParams);
        $visibilityClause = $visibilityWhere ? ' WHERE ' . implode(' AND ', $visibilityWhere) : '';
        $total = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}posts p{$visibilityClause}",
            $visibilityParams
        );
        $publishedWhere = array_merge($visibilityWhere, [cms_post_publication_where('p')]);
        $published = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}posts p WHERE " . implode(' AND ', $publishedWhere),
            $visibilityParams
        );
        $scheduledWhere = array_merge($visibilityWhere, ["p.status = 'published'", 'p.published_at IS NOT NULL', 'p.published_at > ?']);
        $scheduled = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}posts p WHERE " . implode(' AND ', $scheduledWhere),
            array_merge($visibilityParams, [$currentDateTime])
        );
        $draftsWhere = array_merge($visibilityWhere, ["p.status = 'draft'"]);
        $drafts = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}posts p WHERE " . implode(' AND ', $draftsWhere),
            $visibilityParams
        );
        $privateWhere = array_merge($visibilityWhere, ["p.status = 'private'"]);
        $private = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}posts p WHERE " . implode(' AND ', $privateWhere),
            $visibilityParams
        );

        $statusFilter   = $this->normalizeListStatus((string)($_GET['status'] ?? ''));
        $categoryFilter = $this->normalizeExistingCategoryId((int)($_GET['category'] ?? 0));
        $search         = $this->sanitizeSearchTerm((string)($_GET['q'] ?? ''));

        $where  = [];
        $params = [];
        $this->addOwnershipFilter($where, $params);

        if ($statusFilter !== '') {
            if ($statusFilter === 'published') {
                $where[] = cms_post_publication_where('p');
            } elseif ($statusFilter === 'scheduled') {
                $where[] = "p.status = 'published' AND p.published_at IS NOT NULL AND p.published_at > ?";
                $params[] = $currentDateTime;
            } elseif ($statusFilter === 'draft') {
                $where[]  = 'p.status = ?';
                $params[] = $statusFilter;
            } elseif ($statusFilter === 'private') {
                $where[]  = 'p.status = ?';
                $params[] = $statusFilter;
            }
        }
        if ($categoryFilter > 0) {
            $where[]  = "(p.category_id = ? OR EXISTS (
                SELECT 1 FROM {$this->prefix}post_category_rel pcr
                WHERE pcr.post_id = p.id AND pcr.category_id = ?
            ))";
            $params[] = $categoryFilter;
            $params[] = $categoryFilter;
        }
        if ($search !== '') {
            $where[]  = '(p.title LIKE ? OR p.title_en LIKE ? OR p.slug LIKE ? OR p.slug_en LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $posts = $this->db->get_results(
                "SELECT p.id, p.title, p.title_en, p.slug, p.slug_en, p.status, p.category_id, p.published_at, p.created_at, p.updated_at,
                    u.display_name AS author,
                    c.name AS category_name
             FROM {$this->prefix}posts p
             LEFT JOIN {$this->prefix}users u ON p.author_id = u.id
             LEFT JOIN {$this->prefix}post_categories c ON p.category_id = c.id
             {$whereClause}
             ORDER BY p.created_at DESC
             LIMIT 200",
            $params
        ) ?: [];

        $categories = $this->db->get_results(
            "SELECT id, name, slug, parent_id, sort_order FROM {$this->prefix}post_categories ORDER BY sort_order ASC, name ASC"
        ) ?: [];

        $categories = $this->categoryViewModelBuilder->buildOrderedCategoryOptions(array_map(fn($c) => (array) $c, $categories));

        return [
            'posts'      => array_map(fn($p) => (array)$p, $posts),
            'categories' => $categories,
            'counts'     => compact('total', 'published', 'scheduled', 'drafts', 'private'),
            'filter'     => $statusFilter,
            'catFilter'  => $categoryFilter,
            'search'     => $search,
        ];
    }

    /**
     * Daten für Edit/Create
     */
    public function getEditData(?int $id): array
    {
        $post = null;
        if ($id !== null) {
            $post = $this->db->get_row(
                "SELECT * FROM {$this->prefix}posts WHERE id = ?"
                . ($this->canManageAllPosts() ? '' : ' AND author_id = ?'),
                $this->canManageAllPosts() ? [$id] : [$id, $this->currentUserId()]
            );
        }

        $postData = $post ? (array)$post : null;

        $categories = $this->db->get_results(
            "SELECT id, name, slug, parent_id, sort_order FROM {$this->prefix}post_categories ORDER BY sort_order ASC, name ASC"
        ) ?: [];

        $tags = $this->db->get_results(
            "SELECT id, name, slug FROM {$this->prefix}post_tags ORDER BY name ASC"
        ) ?: [];

        $postTags = [];
        if ($postData !== null) {
            $postTags = $this->db->get_results(
                "SELECT t.id, t.name, t.slug
                 FROM {$this->prefix}post_tags t
                 INNER JOIN {$this->prefix}post_tag_rel ptr ON ptr.tag_id = t.id
                 WHERE ptr.post_id = ?
                 ORDER BY t.name ASC",
                [(int)($postData['id'] ?? 0)]
            ) ?: [];
        }

        return [
            'post'       => $postData,
            'isNew'      => $post === null,
            'categories' => $this->categoryViewModelBuilder->buildOrderedCategoryOptions(array_map(fn($c) => (array)$c, $categories)),
            'assignedCategoryIds' => $postData !== null ? $this->getPostCategoryIds((int) ($postData['id'] ?? 0)) : [],
            'tags'       => array_map(fn($t) => (array)$t, $tags),
            'postTags'   => array_map(fn($t) => (array)$t, $postTags),
            'postTemplates' => $this->getPostTemplateDefinitions(),
            'seoMeta'    => $id !== null ? SEOService::getInstance()->getContentMeta('post', $id) : SEOService::getInstance()->getContentMeta('post', 0),
            'revisionHistory' => $this->buildPostRevisionHistory($postData, array_map(fn($t) => (array) $t, $postTags)),
        ];
    }

    /**
     * Daten für die Admin-Ansicht der Beitrags-Kategorien.
     */
    public function getCategoryAdminData(): array
    {
        $categories = $this->db->get_results(
            "SELECT c.id, c.name, c.slug, c.slug_en, c.description, c.parent_id, c.sort_order, c.replacement_category_id,
                    (
                        SELECT COUNT(DISTINCT p2.id)
                        FROM {$this->prefix}posts p2
                        LEFT JOIN {$this->prefix}post_category_rel pcr2 ON pcr2.post_id = p2.id
                        WHERE p2.category_id = c.id OR pcr2.category_id = c.id
                    ) AS assigned_post_count
             FROM {$this->prefix}post_categories c
             LEFT JOIN {$this->prefix}posts p ON p.category_id = c.id
               GROUP BY c.id, c.name, c.slug, c.slug_en, c.description, c.parent_id, c.sort_order, c.replacement_category_id
             ORDER BY c.sort_order ASC, c.name ASC"
        ) ?: [];

        $orderedCategories = $this->categoryViewModelBuilder->buildAdminCategoryRows(array_map(fn($category) => (array) $category, $categories));

        return [
            'categories' => $orderedCategories,
            'categoryOptions' => $this->categoryViewModelBuilder->buildOrderedCategoryOptions(array_map(fn($category) => (array) $category, $categories)),
            'counts' => [
                'total' => count($orderedCategories),
                'assigned_posts' => array_sum(array_map(static fn(array $category): int => (int) ($category['post_count_direct'] ?? 0), $orderedCategories)),
            ],
        ];
    }

    /**
     * Daten für die Admin-Ansicht der Beitrags-Tags.
     */
    public function getTagAdminData(int $editTagId = 0): array
    {
        $tags = $this->db->get_results(
            "SELECT t.id, t.name, t.slug, t.slug_en, t.description, COUNT(ptr.post_id) AS post_count
             FROM {$this->prefix}post_tags t
             LEFT JOIN {$this->prefix}post_tag_rel ptr ON ptr.tag_id = t.id
             GROUP BY t.id, t.name, t.slug, t.slug_en, t.description
             ORDER BY t.name ASC"
        ) ?: [];

        $tagRows = array_map(fn($tag) => (array) $tag, $tags);
        $editTag = null;

        if ($editTagId > 0) {
            foreach ($tagRows as $tagRow) {
                if ((int) ($tagRow['id'] ?? 0) === $editTagId) {
                    $editTag = $tagRow;
                    break;
                }
            }
        }

        return [
            'tags' => $tagRows,
            'tagOptions' => $tagRows,
            'editTag' => $editTag,
            'counts' => [
                'total' => count($tags),
                'assigned_posts' => array_sum(array_map(static fn($tag): int => (int) ($tag->post_count ?? 0), $tags)),
            ],
        ];
    }

    /**
     * Post speichern
     */
    /**
     * Speichert einen Beitrag aus dem Member-Bereich (Einreichung durch Mitglieder).
     *
     * Das Member-Formular kennt nur Titel, Slug, Auszug, Inhalt, Hauptkategorie, Beitragsbild und Tags.
     * `save()` setzt fehlende Felder auf Standardwerte zurück; bei einem bestehenden Entwurf würden
     * dadurch Beitragsvorlage, Vorlagen-Metadaten, Autor-Anzeige, Zusatzkategorien, geplanter
     * Veröffentlichungstermin, englische Fassung und SEO-Metadaten verloren gehen, die ein Admin
     * bereits gesetzt hat. Diese Methode übernimmt sie vor dem Speichern aus dem Bestand.
     * Meta-Titel/-Beschreibung folgen Titel/Auszug nur, solange sie nicht abweichend gepflegt wurden.
     *
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public function saveMemberDraft(array $post, int $userId): array
    {
        $id = (int) ($post['id'] ?? 0);
        if ($id <= 0) {
            return $this->save($post, $userId);
        }

        $existing = $this->db->get_row(
            "SELECT title, excerpt, meta_title, meta_description, author_display_name, author_display_url,
                    post_template, post_meta_json, published_at, content_updated_at
             FROM {$this->prefix}posts WHERE id = ? LIMIT 1",
            [$id]
        );
        if (!$existing) {
            return $this->save($post, $userId);
        }

        $keep = static function (string $key, mixed $value) use (&$post): void {
            if (!array_key_exists($key, $post)) {
                $post[$key] = $value;
            }
        };

        $keep('author_display_name', (string) ($existing->author_display_name ?? ''));
        $keep('author_display_url', (string) ($existing->author_display_url ?? ''));
        $keep('post_template', (string) ($existing->post_template ?? ''));
        // Wie das Admin-Formular: Listenfelder als Zeilen-Text übergeben, findUnexpectedNonScalarInput()
        // lässt in post_meta nur skalare Werte zu.
        $existingMeta = json_decode((string) ($existing->post_meta_json ?? ''), true);
        $existingMeta = is_array($existingMeta) ? $existingMeta : [];
        foreach ($existingMeta as $metaKey => $metaValue) {
            if (is_array($metaValue)) {
                $existingMeta[$metaKey] = implode("\n", array_map(
                    static fn(mixed $line): string => is_scalar($line) ? (string) $line : '',
                    $metaValue
                ));
            } elseif (!is_scalar($metaValue) && $metaValue !== null) {
                unset($existingMeta[$metaKey]);
            }
        }
        $keep('post_meta', $existingMeta);

        // Zusatzkategorien (Hauptkategorie kommt aus dem Formular).
        $primaryCategoryId = (int) ($post['category_id'] ?? 0);
        $keep('additional_category_ids', array_values(array_filter(
            $this->getPostCategoryIds($id),
            static fn(int $categoryId): bool => $categoryId !== $primaryCategoryId
        )));

        foreach (['published_at' => 'publish', 'content_updated_at' => 'content_updated'] as $column => $prefix) {
            $timestamp = strtotime((string) ($existing->{$column} ?? ''));
            if ($timestamp !== false && !empty($existing->{$column})) {
                $keep($prefix . '_date', date('Y-m-d', $timestamp));
                $keep($prefix . '_time', date('H:i', $timestamp));
            }
        }

        // Meta-Titel/-Beschreibung nur nachziehen, wenn sie bisher automatisch Titel/Auszug entsprachen.
        $existingMetaTitle = trim((string) ($existing->meta_title ?? ''));
        if ($existingMetaTitle !== '' && $existingMetaTitle !== trim((string) ($existing->title ?? ''))) {
            $post['meta_title'] = $existingMetaTitle;
        }
        $existingMetaDescription = trim((string) ($existing->meta_description ?? ''));
        if ($existingMetaDescription !== '' && $existingMetaDescription !== trim((string) ($existing->excerpt ?? ''))) {
            $post['meta_description'] = $existingMetaDescription;
        }

        // SEO-Metadaten (Canonical, Robots, Open Graph, Twitter, Schema, Sitemap) werden in save()
        // komplett aus $post neu geschrieben – Bestand übernehmen.
        try {
            foreach (SEOService::getInstance()->getContentMeta('post', $id) as $key => $value) {
                $keep((string) $key, $value);
            }
        } catch (\Throwable) {
        }

        return $this->save($post, $userId);
    }

    public function save(array $post, int $userId): array
    {
        $invalidInputField = $this->findUnexpectedNonScalarInput($post, ['additional_category_ids', 'post_meta']);
        if ($invalidInputField !== '') {
            return ['success' => false, 'error' => 'Ungültige Eingabe für das Feld „' . $invalidInputField . '“. Bitte die Seite neu laden und erneut speichern.'];
        }

        $id         = (int)($post['id'] ?? 0);
        $editorLocale = in_array(strtolower(trim((string) ($post['editor_locale'] ?? 'de'))), ['de', 'en'], true)
            ? strtolower(trim((string) ($post['editor_locale'] ?? 'de')))
            : 'de';
        $existingPost = $id > 0
            ? (array) ($this->db->get_row("SELECT title, title_en, slug, slug_en, content, content_en, excerpt, excerpt_en, author_id FROM {$this->prefix}posts WHERE id = ? LIMIT 1", [$id]) ?: [])
            : [];
        if ($id > 0 && !$this->canAccessPost($id)) {
            return ['success' => false, 'error' => 'Sie dürfen nur Ihre eigenen Beiträge bearbeiten.'];
        }
        $title      = $this->sanitizePlainText((string)($post['title'] ?? ''), 255);
        $slug       = trim((string)($post['slug'] ?? ''));
        $slugEn     = trim((string)($post['slug_en'] ?? ''));
        $defaultStatus = function_exists('get_option') ? (string)get_option('setting_post_default_status', 'draft') : 'draft';
        if (!in_array($defaultStatus, ['published', 'draft', 'private'], true)) {
            $defaultStatus = 'draft';
        }

        $status     = in_array((string)($post['status'] ?? ''), ['published', 'draft', 'private'], true) ? (string)$post['status'] : $defaultStatus;
        $content    = $this->preserveOriginalEditorContentIfUnchanged($post['content'] ?? '', $post['content_original'] ?? '');
        $titleEn    = $this->sanitizePlainText((string)($post['title_en'] ?? ''), 255);
        $contentEn  = $this->preserveOriginalEditorContentIfUnchanged($post['content_en'] ?? '', $post['content_en_original'] ?? '');
        $excerpt    = $this->sanitizePlainText((string)($post['excerpt'] ?? ''), 2000);
        $excerptEn  = $this->sanitizePlainText((string)($post['excerpt_en'] ?? ''), 2000);
        $categoryId = (int)($post['category_id'] ?? 0);
        $assignedCategoryIds = $this->normalizeSelectedCategoryIds($categoryId, $post['additional_category_ids'] ?? []);
        if ($categoryId <= 0 && $assignedCategoryIds !== []) {
            $categoryId = (int) ($assignedCategoryIds[0] ?? 0);
        }
        $featuredImage = $this->sanitizeMediaReference((string)($post['featured_image'] ?? ''));
        $featuredImageTempPath = $this->sanitizeMediaReference((string)($post['featured_image_temp_path'] ?? ''));
        $publishDateRaw = trim((string)($post['publish_date'] ?? ''));
        $publishTimeRaw = trim((string)($post['publish_time'] ?? ''));
        $metaTitle  = $this->sanitizePlainText((string)($post['meta_title'] ?? ''), 255);
        $metaDesc   = $this->sanitizePlainText((string)($post['meta_description'] ?? ''), 2000);
        $authorDisplayName = $this->sanitizeAuthorDisplayName((string)($post['author_display_name'] ?? ''));
        $authorDisplayUrl = $this->sanitizeAuthorDisplayUrl((string)($post['author_display_url'] ?? ''));
        $postTemplates = $this->getPostTemplateDefinitions();
        $postTemplate = $this->sanitizePostTemplate((string)($post['post_template'] ?? ''), $postTemplates);
        $postMetaJson = $this->sanitizePostTemplateMeta($post['post_meta'] ?? [], $postTemplate, $postTemplates);
        $rawTags    = $post['tags'] ?? '';

        if ($id > 0 && $existingPost === []) {
            return ['success' => false, 'error' => 'Der Beitrag existiert nicht mehr. Bitte Liste neu laden.'];
        }

        if ($editorLocale === 'en' && $existingPost !== []) {
            $title = $this->sanitizePlainText((string) ($existingPost['title'] ?? ''), 255);
            $slug = trim((string) ($existingPost['slug'] ?? ''));
            $content = $existingPost['content'] ?? '';
            $excerpt = $this->sanitizePlainText((string) ($existingPost['excerpt'] ?? ''), 2000);
        }

        if ($editorLocale === 'de' && $existingPost !== []) {
            $titleEn = $this->sanitizePlainText((string) ($existingPost['title_en'] ?? ''), 255);
            $slugEn = trim((string) ($existingPost['slug_en'] ?? ''));
            $contentEn = $existingPost['content_en'] ?? '';
            $excerptEn = $this->sanitizePlainText((string) ($existingPost['excerpt_en'] ?? ''), 2000);
        }

        $slugEn     = $this->normalizeSlug($slugEn);
        $slugSource = $slug !== ''
            ? $slug
            : ($slugEn !== ''
                ? $slugEn
                : $this->generateSlug($title !== '' ? $title : $titleEn));
        $slug       = $this->normalizeSlug($slugSource);
        $legacyTags = $this->serializeTagsForLegacyColumn($rawTags);

        if ($title === '' && $titleEn === '') {
            return ['success' => false, 'error' => 'Bitte mindestens einen deutschen oder englischen Titel angeben.'];
        }

        if ($slug === '') {
            return ['success' => false, 'error' => 'Bitte einen gültigen Slug angeben.'];
        }

        if ($categoryId > 0 && !$this->categoryExists($categoryId)) {
            return ['success' => false, 'error' => 'Die ausgewählte Kategorie existiert nicht mehr.'];
        }

        if ($this->isSlugTaken($slug, $id)) {
            return ['success' => false, 'error' => 'Dieser Slug ist bereits vergeben.'];
        }

        if ($slugEn !== '' && $this->isLocalizedSlugTaken($slugEn, $id)) {
            return ['success' => false, 'error' => 'Dieser englische Slug ist bereits vergeben.'];
        }

        try {
            $contentMediaPlacement = ContentMediaPlacementService::getInstance();
            [$content, $contentEn] = $contentMediaPlacement->relocateTemporaryContentMediaBatch([$content, $contentEn], 'post', $slug);
            $featuredImage = $contentMediaPlacement->relocateTemporaryFeaturedImage($featuredImage, $featuredImageTempPath, 'post', $slug);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('admin.posts')->warning('Temporäre Beitragsmedien konnten beim Speichern nicht vollständig verarbeitet werden.', [
                'post_id' => $id,
                'slug' => $slug,
                'featured_image' => $featuredImage,
                'featured_image_temp_path' => $featuredImageTempPath,
                'exception' => $e->getMessage(),
            ]);
        }

        $publishedAtInput = $this->normalizePublishedAtInput($publishDateRaw, $publishTimeRaw);
        if ($publishedAtInput['error'] !== null) {
            return ['success' => false, 'error' => (string) $publishedAtInput['error']];
        }

        $contentUpdatedAtInput = $this->normalizeContentUpdatedAtInput(
            trim((string) ($post['content_updated_date'] ?? '')),
            trim((string) ($post['content_updated_time'] ?? ''))
        );
        if ($contentUpdatedAtInput['error'] !== null) {
            return ['success' => false, 'error' => (string) $contentUpdatedAtInput['error']];
        }
        $treatUpdateAsNew = !empty($post['treat_update_as_new']);
            if ($treatUpdateAsNew && $contentUpdatedAtInput['value'] === null) {
                return ['success' => false, 'error' => 'Bitte zuerst ein Aktualisierungsdatum angeben, um den Beitrag als neu zu behandeln.'];
            }

        $savePayload = [
            'title' => $title,
            'title_en' => $titleEn,
            'slug' => $slug,
            'slug_en' => $slugEn !== '' ? $slugEn : null,
            'content' => $content,
            'content_en' => $contentEn,
            'excerpt' => $excerpt,
            'excerpt_en' => $excerptEn,
            'status' => $status,
            'category_id' => $categoryId ?: null,
            'featured_image' => $featuredImage,
            'tags' => $legacyTags,
            'meta_title' => $metaTitle,
            'meta_description' => $metaDesc,
            'author_display_name' => $authorDisplayName,
            'author_display_url' => $authorDisplayUrl,
            'post_template' => $postTemplate,
            'post_meta_json' => $postMetaJson,
            'published_at' => $publishedAtInput['value'],
            'content_updated_at' => $contentUpdatedAtInput['value'],
        ];

        $filteredPayload = Hooks::applyFilters('cms_prepare_post_save_payload', $savePayload, $post, $id, $userId);
        if (is_array($filteredPayload)) {
            $savePayload = array_merge($savePayload, $filteredPayload);
        }

        try {
            if ($id > 0) {
                $existing = $this->db->get_row("SELECT slug, slug_en, status, published_at, created_at FROM {$this->prefix}posts WHERE id = ? LIMIT 1", [$id]);
                $resolvedPublishedAt = $this->resolvePublishedAtValue((string) $savePayload['status'], $savePayload['published_at'], $existing);
                if ($treatUpdateAsNew && $contentUpdatedAtInput['value'] !== null) {
                    $resolvedPublishedAt = $contentUpdatedAtInput['value'];
                }
                $currentRevisionSource = $this->getCurrentPostRevisionSource($id);
                $revisionComparisonPayload = $savePayload;
                $revisionComparisonPayload['published_at'] = $resolvedPublishedAt;
                if ($this->hasTrackedPostRevisionChanges($currentRevisionSource, $revisionComparisonPayload) && !$this->storePostRevisionSnapshot($currentRevisionSource)) {
                    return $this->failResult(
                        'posts.revision.snapshot_failed',
                        'Beitragsrevision konnte vor dem Speichern nicht gesichert werden.',
                        null,
                        ['post_id' => $id]
                    );
                }

                $this->db->execute(
                    "UPDATE {$this->prefix}posts 
                     SET title = ?, title_en = ?, slug = ?, slug_en = ?, content = ?, content_en = ?, excerpt = ?, excerpt_en = ?, status = ?,
                         category_id = ?, featured_image = ?, tags = ?,
                         meta_title = ?, meta_description = ?, author_display_name = ?, author_display_url = ?, post_template = ?, post_meta_json = ?, published_at = ?,
                         content_updated_at = ?,
                         updated_at = NOW()
                     WHERE id = ?",
                    [
                        (string)$savePayload['title'],
                        (string)($savePayload['title_en'] ?? ''),
                        (string)$savePayload['slug'],
                        $savePayload['slug_en'],
                        (string)$savePayload['content'],
                        (string)($savePayload['content_en'] ?? ''),
                        (string)$savePayload['excerpt'],
                        (string)($savePayload['excerpt_en'] ?? ''),
                        (string)$savePayload['status'],
                        $savePayload['category_id'],
                        (string)$savePayload['featured_image'],
                        (string)($savePayload['tags'] ?? ''),
                        (string)$savePayload['meta_title'],
                        (string)$savePayload['meta_description'],
                        (string)($savePayload['author_display_name'] ?? ''),
                        (string)($savePayload['author_display_url'] ?? ''),
                        (string)($savePayload['post_template'] ?? 'default'),
                        $savePayload['post_meta_json'],
                        $resolvedPublishedAt,
                        $savePayload['content_updated_at'],
                        $id,
                    ]
                );

                SEOService::getInstance()->saveContentMeta('post', $id, $post);
                $this->syncPostTags($id, $rawTags);
                $this->syncPostCategories($id, $assignedCategoryIds !== [] ? $assignedCategoryIds : ($savePayload['category_id'] !== null ? [(int) $savePayload['category_id']] : []));
                $this->createSlugRedirectIfNeeded(
                    (string)($existing->slug ?? ''),
                    $slug,
                    (string)($existing->slug_en ?? ''),
                    (string)($savePayload['slug_en'] ?? ''),
                    [
                        'published_at' => (string)($existing->published_at ?? ''),
                        'created_at' => (string)($existing->created_at ?? ''),
                            'target_published_at' => $resolvedPublishedAt,
                            'target_created_at' => (string)($existing->created_at ?? ''),
                    ]
                );
                Hooks::doAction('cms_after_post_save', $id, $savePayload, $post);
                $this->clearContentCacheIfEnabled('post_update', $id);
                return ['success' => true, 'id' => $id, 'message' => 'Beitrag aktualisiert.'];
            } else {
                $resolvedPublishedAt = $this->resolvePublishedAtValue((string) $savePayload['status'], $savePayload['published_at'], null);
                $this->db->execute(
                    "INSERT INTO {$this->prefix}posts
                     (title, title_en, slug, slug_en, content, content_en, excerpt, excerpt_en, status, category_id, featured_image, tags, meta_title, meta_description, author_id, author_display_name, author_display_url, post_template, post_meta_json, published_at, content_updated_at, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        (string)$savePayload['title'],
                        (string)($savePayload['title_en'] ?? ''),
                        (string)$savePayload['slug'],
                        $savePayload['slug_en'],
                        (string)$savePayload['content'],
                        (string)($savePayload['content_en'] ?? ''),
                        (string)$savePayload['excerpt'],
                        (string)($savePayload['excerpt_en'] ?? ''),
                        (string)$savePayload['status'],
                        $savePayload['category_id'],
                        (string)$savePayload['featured_image'],
                        (string)($savePayload['tags'] ?? ''),
                        (string)$savePayload['meta_title'],
                        (string)$savePayload['meta_description'],
                        $userId,
                        (string)($savePayload['author_display_name'] ?? ''),
                        (string)($savePayload['author_display_url'] ?? ''),
                        (string)($savePayload['post_template'] ?? 'default'),
                        $savePayload['post_meta_json'],
                        $resolvedPublishedAt,
                        $savePayload['content_updated_at'],
                    ]
                );
                $newId = (int)$this->db->lastInsertId();
                SEOService::getInstance()->saveContentMeta('post', $newId, $post);
                $this->syncPostTags($newId, $rawTags);
                $this->syncPostCategories($newId, $assignedCategoryIds !== [] ? $assignedCategoryIds : ($savePayload['category_id'] !== null ? [(int) $savePayload['category_id']] : []));
                Hooks::doAction('cms_after_post_save', $newId, $savePayload, $post);
                $this->clearContentCacheIfEnabled('post_create', $newId);
                return ['success' => true, 'id' => $newId, 'message' => 'Beitrag erstellt.'];
            }
        } catch (\Throwable $e) {
            return $this->failResult(
                'posts.save.failed',
                'Beitrag konnte nicht gespeichert werden.',
                $e,
                ['post_id' => $id, 'status' => $status, 'category_id' => $categoryId, 'user_id' => $userId]
            );
        }
    }

    /**
     * Kopiert deutsche Beitragsinhalte serverseitig in die englischen Felder.
     *
     * Layout- und Medienreferenzen bleiben erhalten; nur Editor.js-Block-IDs werden
     * entfernt, damit die kopierte EN-Fassung eigene Block-IDs erzeugen kann.
     */
    public function copyGermanToEnglish(int $id, int $userId = 0): array
    {
        if ($id <= 0) {
            return ['success' => false, 'error' => 'Ungültige Beitrags-ID.'];
        }

        try {
            $currentPost = $this->getCurrentPostRevisionSource($id);
            if ($currentPost === []) {
                return ['success' => false, 'error' => 'Der Beitrag wurde nicht gefunden.'];
            }

            $title = $this->sanitizePlainText((string) ($currentPost['title'] ?? ''), 255);
            $content = (string) ($currentPost['content'] ?? '');
            $excerpt = $this->sanitizePlainText((string) ($currentPost['excerpt'] ?? ''), 2000);

            if ($title === '' && trim($content) === '' && $excerpt === '') {
                return ['success' => false, 'error' => 'Der deutsche Beitrag enthält keinen kopierbaren Inhalt.'];
            }

            $newData = [
                'title_en' => $title,
                'content_en' => $this->prepareEditorContentForLanguageCopy($content),
                'excerpt_en' => $excerpt,
            ];

            $comparisonPayload = array_merge($currentPost, $newData);
            if ($this->hasTrackedPostRevisionChanges($currentPost, $comparisonPayload) && !$this->storePostRevisionSnapshot($currentPost)) {
                return ['success' => false, 'error' => 'Beitragsrevision konnte vor dem Kopieren nicht gesichert werden.'];
            }

            $this->db->execute(
                "UPDATE {$this->prefix}posts
                 SET title_en = ?, content_en = ?, excerpt_en = ?, updated_at = NOW()
                 WHERE id = ?",
                [
                    (string) $newData['title_en'],
                    (string) $newData['content_en'],
                    (string) $newData['excerpt_en'],
                    $id,
                ]
            );

            Hooks::doAction('cms_after_post_copy_de_to_en', $id, $newData, $userId);
            $this->clearContentCacheIfEnabled('post_copy_de_to_en', $id);
            AuditLogger::instance()->log(
                AuditLogger::CAT_CONTENT,
                'posts.copy_de_to_en',
                'Deutsche Beitragsinhalte nach EN kopiert.',
                'posts',
                $id,
                ['post_id' => $id, 'user_id' => $userId],
                'info'
            );

            return ['success' => true, 'id' => $id, 'message' => 'Deutsche Beitragsinhalte wurden serverseitig nach EN kopiert.'];
        } catch (\Throwable $e) {
            return $this->failResult('posts.copy_de_to_en.failed', 'Beitragsinhalte konnten nicht nach EN kopiert werden.', $e, ['post_id' => $id, 'user_id' => $userId]);
        }
    }

    /**
     * Post löschen
     */
    public function delete(int $id): array
    {
        if ($id <= 0) {
            return ['success' => false, 'error' => 'Ungültige Beitrags-ID.'];
        }

        if (!$this->postExists($id)) {
            return ['success' => false, 'error' => 'Beitrag wurde nicht gefunden oder bereits gelöscht.'];
        }

        if (!$this->canAccessPost($id)) {
            return ['success' => false, 'error' => 'Sie dürfen nur Ihre eigenen Beiträge löschen.'];
        }

        try {
            $statement = $this->db->execute(
                "DELETE FROM {$this->prefix}posts WHERE id = ?"
                . ($this->canManageAllPosts() ? '' : ' AND author_id = ?')
                . " LIMIT 1",
                $this->canManageAllPosts() ? [$id] : [$id, $this->currentUserId()]
            );

            if (!$statement instanceof \PDOStatement) {
                return $this->failResult(
                    'posts.delete.failed',
                    'Beitrag konnte nicht gelöscht werden.',
                    null,
                    ['post_id' => $id, 'db_last_error' => trim((string) $this->db->last_error)]
                );
            }

            if ($statement->rowCount() < 1) {
                return ['success' => false, 'error' => 'Beitrag wurde nicht gefunden oder bereits gelöscht.'];
            }

            Hooks::doAction('post_deleted', $id);
            $this->clearContentCacheIfEnabled('post_delete', $id);

            return ['success' => true, 'message' => 'Beitrag gelöscht.'];
        } catch (\Throwable $e) {
            return $this->failResult('posts.delete.failed', 'Beitrag konnte nicht gelöscht werden.', $e, ['post_id' => $id]);
        }
    }

    /**
     * Bulk-Aktion
     */
    public function bulkAction(string $action, array $ids, array $payload = []): array
    {
        $action = $this->normalizeBulkAction($action);

        if (empty($ids)) {
            return ['success' => false, 'error' => 'Keine Einträge ausgewählt.'];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return ['success' => false, 'error' => 'Keine gültigen Beitrags-IDs ausgewählt.'];
        }

        $existingIds = $this->getExistingPostIds($ids);
        if ($existingIds === []) {
            return ['success' => false, 'error' => 'Die ausgewählten Beiträge existieren nicht mehr. Bitte Liste neu laden.'];
        }

        if (count($existingIds) !== count($ids)) {
            return ['success' => false, 'error' => 'Mindestens ein ausgewählter Beitrag existiert nicht mehr. Bitte Liste neu laden und Aktion erneut ausführen.'];
        }

        if (!$this->canManageAllPosts()) {
            $ownedIds = $this->getExistingPostIds($existingIds, $this->currentUserId());
            if (count($ownedIds) !== count($existingIds)) {
                return ['success' => false, 'error' => 'Sie dürfen nur Ihre eigenen Beiträge bearbeiten.'];
            }
            $existingIds = $ownedIds;
        }

        $ids = $existingIds;

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        try {
            switch ($action) {
                case 'delete':
                    $this->db->execute("DELETE FROM {$this->prefix}posts WHERE id IN ({$placeholders})", $ids);

                    foreach ($ids as $postId) {
                        Hooks::doAction('post_deleted', (int) $postId);
                    }

                    $this->clearContentCacheIfEnabled('post_bulk_delete', (int) $ids[0]);

                    return ['success' => true, 'message' => count($ids) . ' Beitrag/Beiträge gelöscht.'];

                case 'publish':
                    $this->db->execute("UPDATE {$this->prefix}posts SET status = 'published', published_at = COALESCE(published_at, NOW()), updated_at = NOW() WHERE id IN ({$placeholders})", $ids);
                    $this->clearContentCacheIfEnabled('post_bulk_publish', (int) $ids[0]);
                    return ['success' => true, 'message' => count($ids) . ' Beitrag/Beiträge veröffentlicht.'];

                case 'draft':
                    $this->db->execute("UPDATE {$this->prefix}posts SET status = 'draft', updated_at = NOW() WHERE id IN ({$placeholders})", $ids);
                    $this->clearContentCacheIfEnabled('post_bulk_draft', (int) $ids[0]);
                    return ['success' => true, 'message' => count($ids) . ' Beitrag/Beiträge als Entwurf gesetzt.'];

                case 'set_category':
                    $selectedCategoryIds = $this->normalizeSelectedCategoryIds(
                        (int) ($payload['bulk_category_id'] ?? 0),
                        $payload['bulk_category_ids'] ?? []
                    );
                    if ($selectedCategoryIds === []) {
                        return ['success' => false, 'error' => 'Bitte mindestens eine Kategorie für die Bulk-Aktion auswählen.'];
                    }

                    $params = array_merge([(int) ($selectedCategoryIds[0] ?? 0)], $ids);
                    $this->db->execute(
                        "UPDATE {$this->prefix}posts SET category_id = ?, updated_at = NOW() WHERE id IN ({$placeholders})",
                        $params
                    );

                    foreach ($ids as $postId) {
                        $this->syncPostCategories((int) $postId, $selectedCategoryIds);
                    }

                    $this->clearContentCacheIfEnabled('post_bulk_set_category', (int) $ids[0]);

                    return ['success' => true, 'message' => count($ids) . ' Beitrag/Beiträge den gewählten Kategorien zugewiesen.'];

                case 'clear_category':
                    $this->db->execute("UPDATE {$this->prefix}posts SET category_id = NULL, updated_at = NOW() WHERE id IN ({$placeholders})", $ids);
                    foreach ($ids as $postId) {
                        $this->syncPostCategories((int) $postId, []);
                    }
                    $this->clearContentCacheIfEnabled('post_bulk_clear_category', (int) $ids[0]);
                    return ['success' => true, 'message' => count($ids) . ' Beitrag/Beiträge aus der Kategorie entfernt.'];

                case 'set_author_display_name':
                    $authorDisplayName = $this->sanitizeAuthorDisplayName((string) ($payload['bulk_author_display_name'] ?? ''));
                    if ($authorDisplayName === '') {
                        return ['success' => false, 'error' => 'Bitte einen Anzeigenamen für die Bulk-Aktion angeben.'];
                    }

                    $params = array_merge([$authorDisplayName], $ids);
                    $this->db->execute(
                        "UPDATE {$this->prefix}posts SET author_display_name = ?, updated_at = NOW() WHERE id IN ({$placeholders})",
                        $params
                    );

                    $this->clearContentCacheIfEnabled('post_bulk_set_author_display_name', (int) $ids[0]);

                    return ['success' => true, 'message' => count($ids) . ' Beitrag/Beiträge mit neuem Autoren-Anzeigenamen aktualisiert.'];

                case 'clear_author_display_name':
                    $this->db->execute("UPDATE {$this->prefix}posts SET author_display_name = NULL, updated_at = NOW() WHERE id IN ({$placeholders})", $ids);
                    $this->clearContentCacheIfEnabled('post_bulk_clear_author_display_name', (int) $ids[0]);
                    return ['success' => true, 'message' => count($ids) . ' Beitrag/Beiträge auf den normalen 365CMS-Anzeigenamen zurückgesetzt.'];

                default:
                    return ['success' => false, 'error' => 'Unbekannte Aktion.'];
            }
        } catch (\Throwable $e) {
            return $this->failResult(
                'posts.bulk.failed',
                'Bulk-Aktion für Beiträge fehlgeschlagen.',
                $e,
                ['bulk_action' => $action, 'post_ids' => $ids]
            );
        }
    }

    /**
     * Kategorie speichern
     */
    public function saveCategory(array $post): array
    {
        $id   = (int)($post['cat_id'] ?? 0);
        $name = $this->sanitizePlainText((string)($post['cat_name'] ?? ''), 255);
        $slug = $this->normalizeSlug((string)($post['cat_slug'] ?? ''));
        $slugEn = $this->normalizeSlug((string)($post['cat_slug_en'] ?? ''));
        $description = $this->sanitizePlainText((string)($post['cat_description'] ?? ''), 2000);
        $parentId = (int) ($post['parent_id'] ?? 0);
        $replacementCategoryId = (int) ($post['replacement_category_id'] ?? 0);

        if ($name === '') {
            return ['success' => false, 'error' => 'Kategoriename darf nicht leer sein.'];
        }
        if ($slug === '') {
            $slug = $this->normalizeSlug($this->generateSlug($name));
        }

        if ($slug === '') {
            return ['success' => false, 'error' => 'Bitte einen gültigen Kategorie-Slug angeben.'];
        }

        if ($slugEn === $slug) {
            $slugEn = '';
        }

        if ($id > 0 && !$this->categoryExists($id)) {
            return ['success' => false, 'error' => 'Die zu bearbeitende Kategorie existiert nicht mehr.'];
        }

        $previousSlug = $id > 0 ? $this->getCurrentCategorySlug($id) : '';
        $previousSlugEn = $id > 0 ? $this->getCurrentCategorySlugEn($id) : '';

        if ($this->isCategorySlugTaken($slug, $id)) {
            return ['success' => false, 'error' => 'Dieser Kategorie-Slug ist bereits vergeben.'];
        }

        if ($slugEn !== '' && $this->isCategorySlugTaken($slugEn, $id)) {
            return ['success' => false, 'error' => 'Dieser englische Kategorie-Slug ist bereits vergeben.'];
        }

        if ($parentId > 0 && !$this->categoryExists($parentId)) {
            return ['success' => false, 'error' => 'Die gewählte Elternkategorie existiert nicht.'];
        }

        if ($id > 0 && $parentId === $id) {
            return ['success' => false, 'error' => 'Eine Kategorie kann nicht ihr eigenes Elternteil sein.'];
        }

        if ($id > 0 && $parentId > 0 && $this->isCategoryDescendant($parentId, $id)) {
            return ['success' => false, 'error' => 'Die gewählte Elternkategorie liegt bereits unterhalb dieser Kategorie.'];
        }

        if ($replacementCategoryId > 0) {
            if ($replacementCategoryId === $id) {
                return ['success' => false, 'error' => 'Die Ersatzkategorie darf nicht identisch mit der aktuellen Kategorie sein.'];
            }

            if (!$this->categoryExists($replacementCategoryId)) {
                return ['success' => false, 'error' => 'Die gewählte Ersatzkategorie existiert nicht.'];
            }
        }

        try {
            if ($id > 0) {
                $this->db->execute(
                    "UPDATE {$this->prefix}post_categories SET name = ?, slug = ?, slug_en = ?, description = ?, parent_id = ?, replacement_category_id = ? WHERE id = ?",
                    [$name, $slug, $slugEn !== '' ? $slugEn : null, $description !== '' ? $description : null, $parentId > 0 ? $parentId : null, $replacementCategoryId > 0 ? $replacementCategoryId : null, $id]
                );
                $redirectDetails = $this->createLocalizedTaxonomyArchiveRedirects('category', $previousSlug, $slug, $previousSlugEn, $slugEn);
                $this->clearContentCacheIfEnabled('post_category_update', $id);
                return [
                    'success' => true,
                    'message' => $redirectDetails !== [] ? 'Kategorie aktualisiert. Archiv-Weiterleitungen wurden geprüft.' : 'Kategorie aktualisiert.',
                    'details' => $redirectDetails,
                ];
            } else {
                $this->db->execute(
                    "INSERT INTO {$this->prefix}post_categories (name, slug, slug_en, description, parent_id, replacement_category_id) VALUES (?, ?, ?, ?, ?, ?)",
                    [$name, $slug, $slugEn !== '' ? $slugEn : null, $description !== '' ? $description : null, $parentId > 0 ? $parentId : null, $replacementCategoryId > 0 ? $replacementCategoryId : null]
                );
                $this->clearContentCacheIfEnabled('post_category_create', (int) $this->db->lastInsertId());
                return ['success' => true, 'message' => 'Kategorie erstellt.'];
            }
        } catch (\Throwable $e) {
            return $this->failResult(
                'posts.category.save.failed',
                'Kategorie konnte nicht gespeichert werden.',
                $e,
                ['category_id' => $id, 'parent_id' => $parentId, 'replacement_category_id' => $replacementCategoryId]
            );
        }
    }

    /**
     * Kategorie löschen
     */
    public function deleteCategory(int $id, int $replacementId = 0): array
    {
        if ($id <= 0) {
            return ['success' => false, 'error' => 'Ungültige Kategorie.'];
        }

        if ($replacementId <= 0) {
            $replacementId = $this->getStoredReplacementCategoryId($id);
        }

        return $this->deleteCategoryResolved($id, $replacementId);
    }

    public function deleteCategoriesWithStoredReplacement(): array
    {
        $categories = $this->getCategoriesWithStoredReplacement();
        if ($categories === []) {
            return ['success' => false, 'error' => 'Es sind keine Kategorien mit hinterlegter Ersatzkategorie vorhanden.'];
        }

        $eligible = [];
        $skippedInvalid = [];

        foreach ($categories as $category) {
            $categoryId = (int) ($category['id'] ?? 0);
            $categoryName = trim((string) ($category['name'] ?? 'Kategorie'));
            $replacementId = (int) ($category['replacement_category_id'] ?? 0);

            if ($categoryId <= 0 || $replacementId <= 0 || $replacementId === $categoryId || !$this->categoryExists($replacementId)) {
                $skippedInvalid[] = $categoryName;
                continue;
            }

            $eligible[$categoryId] = [
                'id' => $categoryId,
                'name' => $categoryName,
                'replacement_category_id' => $replacementId,
            ];
        }

        if ($eligible === []) {
            return ['success' => false, 'error' => 'Keine der Kategorien mit hinterlegter Ersatzkategorie ist aktuell löschbar.'];
        }

        $orderedCategories = [];
        $cyclicCategories = [];
        $pending = $eligible;

        while ($pending !== []) {
            $targetedPendingIds = [];
            foreach ($pending as $pendingCategory) {
                $targetId = (int) ($pendingCategory['replacement_category_id'] ?? 0);
                if ($targetId > 0 && isset($pending[$targetId])) {
                    $targetedPendingIds[$targetId] = true;
                }
            }

            $progress = false;
            foreach ($pending as $categoryId => $pendingCategory) {
                if (isset($targetedPendingIds[$categoryId])) {
                    continue;
                }

                $orderedCategories[] = $pendingCategory;
                unset($pending[$categoryId]);
                $progress = true;
            }

            if (!$progress) {
                foreach ($pending as $pendingCategory) {
                    $cyclicCategories[] = trim((string) ($pendingCategory['name'] ?? 'Kategorie'));
                }
                break;
            }
        }

        $deletedNames = [];
        $failedNames = [];

        foreach ($orderedCategories as $category) {
            $categoryId = (int) ($category['id'] ?? 0);
            $categoryName = trim((string) ($category['name'] ?? 'Kategorie'));
            $replacementId = (int) ($category['replacement_category_id'] ?? 0);
            $result = $this->deleteCategoryResolved($categoryId, $replacementId);

            if (!empty($result['success'])) {
                $deletedNames[] = $categoryName;
                continue;
            }

            $failedNames[] = $categoryName;
        }

        if ($deletedNames === []) {
            $details = [];
            if ($cyclicCategories !== []) {
                $details[] = 'Zyklische Ersatzketten: ' . implode(', ', $cyclicCategories) . '.';
            }
            if ($skippedInvalid !== []) {
                $details[] = 'Ungültige Ersatzkategorien: ' . implode(', ', $skippedInvalid) . '.';
            }
            if ($failedNames !== []) {
                $details[] = 'Fehlgeschlagen: ' . implode(', ', $failedNames) . '.';
            }

            return ['success' => false, 'error' => trim('Es konnte keine Kategorie gelöscht werden. ' . implode(' ', $details))];
        }

        $messageParts = [];
        $messageParts[] = count($deletedNames) . ' Kategorie/Kategorien mit Ersatzkategorie wurden gelöscht und automatisch umgestellt.';

        if ($cyclicCategories !== []) {
            $messageParts[] = count($cyclicCategories) . ' wegen zyklischer Ersatzketten übersprungen.';
        }

        if ($skippedInvalid !== []) {
            $messageParts[] = count($skippedInvalid) . ' wegen ungültiger Ersatzkategorie übersprungen.';
        }

        if ($failedNames !== []) {
            $messageParts[] = count($failedNames) . ' konnten nicht gelöscht werden.';
        }

        return ['success' => true, 'message' => implode(' ', $messageParts)];
    }

    /**
     * @param array<int|string,mixed> $categoryIds
     */
    public function bulkDeleteCategories(array $categoryIds, int $replacementId = 0): array
    {
        $requestedIds = $this->normalizePositiveIds($categoryIds);
        if ($requestedIds === []) {
            return ['success' => false, 'error' => 'Bitte mindestens eine Kategorie auswählen.'];
        }

        $existingIds = $this->getExistingCategoryIds($requestedIds);
        if (count($existingIds) !== count($requestedIds)) {
            return ['success' => false, 'error' => 'Mindestens eine ausgewählte Kategorie existiert nicht mehr. Bitte Liste neu laden.'];
        }

        $selectedLookup = array_fill_keys($existingIds, true);
        if ($replacementId > 0) {
            if (isset($selectedLookup[$replacementId])) {
                return ['success' => false, 'error' => 'Die gemeinsame Ersatzkategorie darf nicht selbst gelöscht werden.'];
            }

            if (!$this->categoryExists($replacementId)) {
                return ['success' => false, 'error' => 'Die gemeinsame Ersatzkategorie existiert nicht mehr.'];
            }
        }

        $resolvedReplacements = [];
        foreach ($existingIds as $categoryId) {
            $assignedPostCount = $this->countAssignedPostsForCategory($categoryId);
            if ($assignedPostCount <= 0) {
                $resolvedReplacements[$categoryId] = 0;
                continue;
            }

            $resolvedReplacementId = $replacementId > 0 ? $replacementId : $this->getStoredReplacementCategoryId($categoryId);
            if ($resolvedReplacementId <= 0) {
                return ['success' => false, 'error' => 'Für mindestens eine ausgewählte Kategorie mit Beiträgen fehlt eine gültige Ersatzkategorie. Bitte eine gemeinsame Ersatzkategorie wählen oder je Kategorie eine Ersatzkategorie hinterlegen.'];
            }

            if (isset($selectedLookup[$resolvedReplacementId])) {
                return ['success' => false, 'error' => 'Eine Ersatzkategorie darf nicht Teil der Lösch-Auswahl sein.'];
            }

            if (!$this->categoryExists($resolvedReplacementId)) {
                return ['success' => false, 'error' => 'Mindestens eine hinterlegte Ersatzkategorie existiert nicht mehr. Bitte Liste neu laden.'];
            }

            $resolvedReplacements[$categoryId] = $resolvedReplacementId;
        }

        $pdo = $this->db->getPdo();
        $startedTransaction = !$pdo->inTransaction();

        try {
            if ($startedTransaction) {
                $pdo->beginTransaction();
            }

            foreach ($existingIds as $categoryId) {
                $result = $this->deleteCategoryResolved($categoryId, (int) ($resolvedReplacements[$categoryId] ?? 0), false);
                if (empty($result['success'])) {
                    if ($startedTransaction && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    return ['success' => false, 'error' => (string) ($result['error'] ?? 'Bulk-Löschen der Kategorien fehlgeschlagen.')];
                }
            }

            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }

            if ($existingIds !== []) {
                $this->clearContentCacheIfEnabled('post_category_bulk_delete', (int) $existingIds[0]);
            }

            AuditLogger::instance()->log(
                AuditLogger::CAT_CONTENT,
                'posts.category.bulk_delete',
                count($existingIds) . ' Beitragskategorie(n) per Bulk-Aktion gelöscht.',
                'post_categories',
                null,
                ['category_ids' => $existingIds, 'replacement_category_id' => $replacementId],
                'info'
            );

            return ['success' => true, 'message' => count($existingIds) . ' Kategorie/Kategorien gelöscht.'];
        } catch (\Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return $this->failResult('posts.category.bulk_delete.failed', 'Bulk-Löschen der Kategorien fehlgeschlagen.', $e, ['category_ids' => $existingIds, 'replacement_category_id' => $replacementId]);
        }
    }

    private function deleteCategoryResolved(int $id, int $replacementId = 0, bool $clearCache = true): array
    {
        if ($id <= 0) {
            return ['success' => false, 'error' => 'Ungültige Kategorie.'];
        }

        if (!$this->categoryExists($id)) {
            return ['success' => false, 'error' => 'Kategorie wurde nicht gefunden oder bereits gelöscht.'];
        }

        $assignedPostCount = $this->countAssignedPostsForCategory($id);

        if ($assignedPostCount > 0) {
            if ($replacementId <= 0) {
                if ($this->countAvailableReplacementCategories($id) <= 0) {
                    return ['success' => false, 'error' => 'Es ist keine Ersatzkategorie verfügbar. Bitte lege zuerst eine weitere Kategorie an, bevor du diese löschst.'];
                }

                return ['success' => false, 'error' => 'Bitte eine neue Kategorie für die betroffenen Beiträge auswählen.'];
            }

            if ($replacementId === $id) {
                return ['success' => false, 'error' => 'Die Ersatzkategorie darf nicht identisch mit der zu löschenden Kategorie sein.'];
            }

            if (!$this->categoryExists($replacementId)) {
                return ['success' => false, 'error' => 'Die gewählte Ersatzkategorie existiert nicht mehr.'];
            }
        }

        try {
            if ($assignedPostCount > 0 && $replacementId > 0) {
                $this->reassignPostsFromDeletedCategory($id, $replacementId);
            } else {
                $this->db->execute("DELETE FROM {$this->prefix}post_category_rel WHERE category_id = ?", [$id]);
                $this->db->execute("UPDATE {$this->prefix}posts SET category_id = NULL WHERE category_id = ?", [$id]);
            }

            $this->db->execute("UPDATE {$this->prefix}post_categories SET parent_id = NULL WHERE parent_id = ?", [$id]);
            $this->db->execute("UPDATE {$this->prefix}post_categories SET replacement_category_id = NULL WHERE replacement_category_id = ?", [$id]);
            $deleteStatement = $this->db->execute("DELETE FROM {$this->prefix}post_categories WHERE id = ? LIMIT 1", [$id]);
            if (!$deleteStatement instanceof \PDOStatement) {
                return $this->failResult(
                    'posts.category.delete.failed',
                    'Kategorie konnte nicht gelöscht werden.',
                    null,
                    ['category_id' => $id, 'replacement_category_id' => $replacementId, 'db_last_error' => trim((string) $this->db->last_error)]
                );
            }

            if ($deleteStatement->rowCount() < 1) {
                return ['success' => false, 'error' => 'Kategorie wurde nicht gefunden oder bereits gelöscht.'];
            }

            if ($clearCache) {
                $this->clearContentCacheIfEnabled('post_category_delete', $id);
            }

            return ['success' => true, 'message' => 'Kategorie gelöscht.'];
        } catch (\Throwable $e) {
            return $this->failResult(
                'posts.category.delete.failed',
                'Kategorie konnte nicht gelöscht werden.',
                $e,
                ['category_id' => $id, 'replacement_category_id' => $replacementId]
            );
        }
    }

    private function countAssignedPostsForCategory(int $categoryId): int
    {
        if ($categoryId <= 0) {
            return 0;
        }

        return (int) ($this->db->get_var(
            "SELECT COUNT(DISTINCT p.id)
             FROM {$this->prefix}posts p
             LEFT JOIN {$this->prefix}post_category_rel pcr ON pcr.post_id = p.id
             WHERE p.category_id = ? OR pcr.category_id = ?",
            [$categoryId, $categoryId]
        ) ?: 0);
    }

    private function getStoredReplacementCategoryId(int $categoryId): int
    {
        if ($categoryId <= 0) {
            return 0;
        }

        $replacementId = (int) ($this->db->get_var(
            "SELECT replacement_category_id FROM {$this->prefix}post_categories WHERE id = ? LIMIT 1",
            [$categoryId]
        ) ?: 0);

        if ($replacementId <= 0 || $replacementId === $categoryId) {
            return 0;
        }

        return $this->categoryExists($replacementId) ? $replacementId : 0;
    }

    /**
     * @return array<int,array{id:int,name:string,replacement_category_id:int}>
     */
    private function getCategoriesWithStoredReplacement(): array
    {
        $rows = $this->db->get_results(
            "SELECT id, name, replacement_category_id
             FROM {$this->prefix}post_categories
             WHERE replacement_category_id IS NOT NULL AND replacement_category_id > 0
             ORDER BY name ASC"
        ) ?: [];

        $categories = [];
        foreach ($rows as $row) {
            $categoryId = (int) ($row->id ?? 0);
            if ($categoryId <= 0) {
                continue;
            }

            $categories[] = [
                'id' => $categoryId,
                'name' => trim((string) ($row->name ?? 'Kategorie')),
                'replacement_category_id' => (int) ($row->replacement_category_id ?? 0),
            ];
        }

        return $categories;
    }

    private function reassignPostsFromDeletedCategory(int $deletedCategoryId, int $replacementCategoryId): void
    {
        $postRows = $this->db->get_results(
            "SELECT DISTINCT p.id, p.category_id
             FROM {$this->prefix}posts p
             LEFT JOIN {$this->prefix}post_category_rel pcr ON pcr.post_id = p.id
             WHERE p.category_id = ? OR pcr.category_id = ?",
            [$deletedCategoryId, $deletedCategoryId]
        ) ?: [];

        foreach ($postRows as $row) {
            $postId = (int) ($row->id ?? 0);
            if ($postId <= 0) {
                continue;
            }

            $categoryIds = $this->getPostCategoryIds($postId);
            $categoryIds = array_values(array_filter(
                $categoryIds,
                static fn(int $categoryId): bool => $categoryId > 0 && $categoryId !== $deletedCategoryId
            ));

            if (!in_array($replacementCategoryId, $categoryIds, true)) {
                $categoryIds[] = $replacementCategoryId;
            }

            $newPrimaryCategoryId = (int) ($row->category_id ?? 0) === $deletedCategoryId
                ? $replacementCategoryId
                : ((int) ($row->category_id ?? 0) > 0 ? (int) $row->category_id : $replacementCategoryId);

            if (!in_array($newPrimaryCategoryId, $categoryIds, true)) {
                array_unshift($categoryIds, $newPrimaryCategoryId);
            }

            $this->db->execute(
                "UPDATE {$this->prefix}posts SET category_id = ?, updated_at = NOW() WHERE id = ?",
                [$newPrimaryCategoryId, $postId]
            );

            $this->syncPostCategories($postId, $categoryIds);
        }

        $this->db->execute("DELETE FROM {$this->prefix}post_category_rel WHERE category_id = ?", [$deletedCategoryId]);
    }

    /**
     * Tag speichern.
     */
    public function saveTag(array $post): array
    {
        $id = (int) ($post['tag_id'] ?? 0);
        $name = $this->sanitizePlainText((string) ($post['tag_name'] ?? ''), 120);
        $slug = $this->normalizeSlug((string) ($post['tag_slug'] ?? ''));
        $slugEn = $this->normalizeSlug((string) ($post['tag_slug_en'] ?? ''));
        $description = $this->sanitizePlainText((string) ($post['tag_description'] ?? ''), 2000);

        if ($name === '') {
            return ['success' => false, 'error' => 'Tag-Name darf nicht leer sein.'];
        }

        $slug = $slug !== '' ? $slug : $this->normalizeSlug($this->generateSlug($name));
        if ($slug === '') {
            return ['success' => false, 'error' => 'Bitte einen gültigen Tag-Slug angeben.'];
        }

        if ($slugEn === $slug) {
            $slugEn = '';
        }

        if ($id > 0 && !$this->tagExists($id)) {
            return ['success' => false, 'error' => 'Der zu bearbeitende Tag existiert nicht mehr.'];
        }

        $previousSlug = $id > 0 ? $this->getCurrentTagSlug($id) : '';
        $previousSlugEn = $id > 0 ? $this->getCurrentTagSlugEn($id) : '';

        if ($this->isTagSlugTaken($slug, $id)) {
            return ['success' => false, 'error' => 'Dieser Tag-Slug ist bereits vergeben.'];
        }

        if ($slugEn !== '' && $this->isTagSlugTaken($slugEn, $id)) {
            return ['success' => false, 'error' => 'Dieser englische Tag-Slug ist bereits vergeben.'];
        }

        try {
            if ($id > 0) {
                $this->db->execute(
                    "UPDATE {$this->prefix}post_tags SET name = ?, slug = ?, slug_en = ?, description = ? WHERE id = ?",
                    [$name, $slug, $slugEn !== '' ? $slugEn : null, $description !== '' ? $description : null, $id]
                );

                $redirectDetails = $this->createLocalizedTaxonomyArchiveRedirects('tag', $previousSlug, $slug, $previousSlugEn, $slugEn);

                $this->clearContentCacheIfEnabled('post_tag_update', $id);

                return [
                    'success' => true,
                    'message' => $redirectDetails !== [] ? 'Tag aktualisiert. Archiv-Weiterleitungen wurden geprüft.' : 'Tag aktualisiert.',
                    'details' => $redirectDetails,
                ];
            }

            $this->db->execute(
                "INSERT INTO {$this->prefix}post_tags (name, slug, slug_en, description) VALUES (?, ?, ?, ?)",
                [$name, $slug, $slugEn !== '' ? $slugEn : null, $description !== '' ? $description : null]
            );

            $this->clearContentCacheIfEnabled('post_tag_create', (int) $this->db->lastInsertId());

            return ['success' => true, 'message' => 'Tag erstellt.'];
        } catch (\Throwable $e) {
            return $this->failResult('posts.tag.save.failed', 'Tag konnte nicht gespeichert werden.', $e, ['tag_id' => $id]);
        }
    }

    /**
     * Tag löschen.
     */
    public function deleteTag(int $id, int $replacementId = 0): array
    {
        return $this->deleteTagInternal($id, $replacementId, true);
    }

    private function deleteTagInternal(int $id, int $replacementId = 0, bool $clearCache = true): array
    {
        if ($id <= 0) {
            return ['success' => false, 'error' => 'Ungültiger Tag.'];
        }

        if (!$this->tagExists($id)) {
            return ['success' => false, 'error' => 'Tag wurde nicht gefunden oder bereits gelöscht.'];
        }

        $assignedPostCount = $this->countAssignedPostsForTag($id);

        if ($assignedPostCount > 0) {
            if ($replacementId <= 0) {
                if ($this->countAvailableReplacementTags($id) <= 0) {
                    return ['success' => false, 'error' => 'Es ist kein Ersatztag verfügbar. Bitte lege zuerst einen weiteren Tag an, bevor du diesen löschst.'];
                }

                return ['success' => false, 'error' => 'Bitte einen Ersatztag für die betroffenen Beiträge auswählen.'];
            }

            if ($replacementId === $id) {
                return ['success' => false, 'error' => 'Der Ersatztag darf nicht identisch mit dem zu löschenden Tag sein.'];
            }

            if (!$this->tagExists($replacementId)) {
                return ['success' => false, 'error' => 'Der gewählte Ersatztag existiert nicht mehr.'];
            }
        }

        try {
            if ($assignedPostCount > 0 && $replacementId > 0) {
                $this->reassignPostsFromDeletedTag($id, $replacementId);
            } else {
                $this->db->execute("DELETE FROM {$this->prefix}post_tag_rel WHERE tag_id = ?", [$id]);
            }

            $deleteStatement = $this->db->execute("DELETE FROM {$this->prefix}post_tags WHERE id = ? LIMIT 1", [$id]);
            if (!$deleteStatement instanceof \PDOStatement) {
                return $this->failResult(
                    'posts.tag.delete.failed',
                    'Tag konnte nicht gelöscht werden.',
                    null,
                    ['tag_id' => $id, 'replacement_tag_id' => $replacementId, 'db_last_error' => trim((string) $this->db->last_error)]
                );
            }

            if ($deleteStatement->rowCount() < 1) {
                return ['success' => false, 'error' => 'Tag wurde nicht gefunden oder bereits gelöscht.'];
            }

            if ($clearCache) {
                $this->clearContentCacheIfEnabled('post_tag_delete', $id);
            }

            return ['success' => true, 'message' => 'Tag gelöscht.'];
        } catch (\Throwable $e) {
            return $this->failResult('posts.tag.delete.failed', 'Tag konnte nicht gelöscht werden.', $e, ['tag_id' => $id, 'replacement_tag_id' => $replacementId]);
        }
    }

    /**
     * @param array<int|string,mixed> $tagIds
     */
    public function bulkDeleteTags(array $tagIds, int $replacementId = 0): array
    {
        $requestedIds = $this->normalizePositiveIds($tagIds);
        if ($requestedIds === []) {
            return ['success' => false, 'error' => 'Bitte mindestens einen Tag auswählen.'];
        }

        $existingIds = $this->getExistingTagIds($requestedIds);
        if (count($existingIds) !== count($requestedIds)) {
            return ['success' => false, 'error' => 'Mindestens ein ausgewählter Tag existiert nicht mehr. Bitte Liste neu laden.'];
        }

        $selectedLookup = array_fill_keys($existingIds, true);
        $requiresReplacement = false;
        foreach ($existingIds as $tagId) {
            if ($this->countAssignedPostsForTag($tagId) > 0) {
                $requiresReplacement = true;
                break;
            }
        }

        if ($requiresReplacement) {
            if ($replacementId <= 0) {
                return ['success' => false, 'error' => 'Für Tags mit Beitragsbezug bitte einen gemeinsamen Ersatztag auswählen.'];
            }

            if (isset($selectedLookup[$replacementId])) {
                return ['success' => false, 'error' => 'Der gemeinsame Ersatztag darf nicht selbst gelöscht werden.'];
            }

            if (!$this->tagExists($replacementId)) {
                return ['success' => false, 'error' => 'Der gemeinsame Ersatztag existiert nicht mehr.'];
            }
        }

        $pdo = $this->db->getPdo();
        $startedTransaction = !$pdo->inTransaction();

        try {
            if ($startedTransaction) {
                $pdo->beginTransaction();
            }

            foreach ($existingIds as $tagId) {
                $result = $this->deleteTagInternal($tagId, $requiresReplacement ? $replacementId : 0, false);
                if (empty($result['success'])) {
                    if ($startedTransaction && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    return ['success' => false, 'error' => (string) ($result['error'] ?? 'Bulk-Löschen der Tags fehlgeschlagen.')];
                }
            }

            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }

            if ($existingIds !== []) {
                $this->clearContentCacheIfEnabled('post_tag_bulk_delete', (int) $existingIds[0]);
            }

            AuditLogger::instance()->log(
                AuditLogger::CAT_CONTENT,
                'posts.tag.bulk_delete',
                count($existingIds) . ' Beitrags-Tag(s) per Bulk-Aktion gelöscht.',
                'post_tags',
                null,
                ['tag_ids' => $existingIds, 'replacement_tag_id' => $replacementId],
                'info'
            );

            return ['success' => true, 'message' => count($existingIds) . ' Tag(s) gelöscht.'];
        } catch (\Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return $this->failResult('posts.tag.bulk_delete.failed', 'Bulk-Löschen der Tags fehlgeschlagen.', $e, ['tag_ids' => $existingIds, 'replacement_tag_id' => $replacementId]);
        }
    }

    /**
     * @param array<string,mixed>|null $postData
     * @param array<int,array<string,mixed>> $postTags
     * @return array<string,mixed>
     */
    private function buildPostRevisionHistory(?array $postData, array $postTags): array
    {
        $postId = (int) ($postData['id'] ?? 0);
        if ($postId <= 0) {
            return [
                'total' => 0,
                'displayed' => 0,
                'has_more' => false,
                'items' => [],
            ];
        }

        $currentPost = $this->buildCurrentPostComparisonState($postData, $postTags);
        $rawRevisions = $this->getPostRevisions($postId);
        $items = [];

        foreach (array_slice($rawRevisions, 0, 6) as $revision) {
            if (!is_array($revision)) {
                continue;
            }

            $comparison = $this->buildPostRevisionComparison($currentPost, $revision);
            $items[] = [
                'id' => (int) ($revision['id'] ?? 0),
                'created_at' => (string) ($revision['created_at'] ?? ''),
                'created_at_label' => $this->formatAdminTimestamp((string) ($revision['created_at'] ?? '')),
                'author_label' => $this->resolveRevisionAuthorLabel($revision),
                'changed_fields' => $comparison['changed_fields'],
                'field_diffs' => $comparison['field_diffs'],
            ];
        }

        return [
            'total' => count($rawRevisions),
            'displayed' => count($items),
            'has_more' => count($rawRevisions) > count($items),
            'items' => $items,
        ];
    }

    /**
     * @param array<string,mixed>|null $postData
     * @param array<int,array<string,mixed>> $postTags
     * @return array<string,mixed>
     */
    private function buildCurrentPostComparisonState(?array $postData, array $postTags): array
    {
        $postData = is_array($postData) ? $postData : [];
        $tagNames = [];
        foreach ($postTags as $tag) {
            $name = trim((string) ($tag['name'] ?? ''));
            if ($name !== '') {
                $tagNames[] = $name;
            }
        }

        if ($tagNames === []) {
            $tagNames = $this->normalizeTagNamesFromString((string) ($postData['tags'] ?? ''));
        }

        $categoryId = (int) ($postData['category_id'] ?? 0);

        return [
            'title' => (string) ($postData['title'] ?? ''),
            'title_en' => (string) ($postData['title_en'] ?? ''),
            'slug' => (string) ($postData['slug'] ?? ''),
            'slug_en' => (string) ($postData['slug_en'] ?? ''),
            'content' => $postData['content'] ?? '',
            'content_en' => $postData['content_en'] ?? '',
            'excerpt' => (string) ($postData['excerpt'] ?? ''),
            'excerpt_en' => (string) ($postData['excerpt_en'] ?? ''),
            'status' => (string) ($postData['status'] ?? ''),
            'category_id' => $categoryId,
            'category_name' => $this->resolveCategoryName($categoryId),
            'tags' => implode(', ', $tagNames),
            'author_display_name' => (string) ($postData['author_display_name'] ?? ''),
            'author_display_url' => (string) ($postData['author_display_url'] ?? ''),
            'published_at' => (string) ($postData['published_at'] ?? ''),
                'content_updated_at' => (string) ($postData['content_updated_at'] ?? ''),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getPostRevisions(int $postId): array
    {
        if ($postId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare(
            "SELECT pr.id,
                    pr.post_id,
                    pr.title,
                    pr.title_en,
                    pr.slug,
                    pr.slug_en,
                    pr.content,
                    pr.content_en,
                    pr.excerpt,
                    pr.excerpt_en,
                    pr.status,
                    pr.category_id,
                    pr.category_name,
                    pr.tags,
                    pr.author_id,
                    pr.author_display_name,
                    pr.author_display_url,
                    pr.published_at,
                        pr.content_updated_at,
                    pr.created_at,
                    u.username,
                    u.display_name
             FROM {$this->prefix}post_revisions pr
             LEFT JOIN {$this->prefix}users u ON u.id = pr.author_id
             WHERE pr.post_id = ?
             ORDER BY pr.created_at DESC, pr.id DESC"
        );

        $stmt->execute([$postId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function getCurrentPostRevisionSource(int $postId): array
    {
        if ($postId <= 0) {
            return [];
        }

        $row = $this->db->get_row(
            "SELECT p.id, p.title, p.title_en, p.slug, p.slug_en, p.content, p.content_en, p.excerpt, p.excerpt_en,
                    p.status, p.category_id, p.tags, p.author_id, p.author_display_name, p.author_display_url, p.published_at, p.content_updated_at,
                    c.name AS category_name
             FROM {$this->prefix}posts p
             LEFT JOIN {$this->prefix}post_categories c ON c.id = p.category_id
             WHERE p.id = ?
             LIMIT 1",
            [$postId]
        );

        if (!is_object($row)) {
            return [];
        }

        $snapshot = (array) $row;
        $tagNames = $this->getPostTagNames($postId);
        if ($tagNames !== []) {
            $snapshot['tags'] = implode(', ', $tagNames);
        } else {
            $snapshot['tags'] = implode(', ', $this->normalizeTagNamesFromString((string) ($snapshot['tags'] ?? '')));
        }

        $snapshot['category_name'] = trim((string) ($snapshot['category_name'] ?? ''));

        return $snapshot;
    }

    private function hasTrackedPostRevisionChanges(array $currentPost, array $newData): bool
    {
        foreach (['title', 'title_en', 'slug', 'slug_en', 'content', 'content_en', 'excerpt', 'excerpt_en', 'status', 'tags', 'author_display_name', 'author_display_url', 'published_at', 'content_updated_at'] as $field) {
            if (!array_key_exists($field, $newData)) {
                continue;
            }

            if ((string) ($currentPost[$field] ?? '') !== (string) ($newData[$field] ?? '')) {
                return true;
            }
        }

        if (array_key_exists('category_id', $newData) && (int) ($currentPost['category_id'] ?? 0) !== (int) ($newData['category_id'] ?? 0)) {
            return true;
        }

        return false;
    }

    private function storePostRevisionSnapshot(array $post): bool
    {
        $postId = (int) ($post['id'] ?? 0);
        if ($postId <= 0) {
            return false;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO {$this->prefix}post_revisions (
                post_id, title, title_en, slug, slug_en, content, content_en, excerpt, excerpt_en,
                status, category_id, category_name, tags, author_id, author_display_name, author_display_url, published_at, content_updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        return $stmt->execute([
            $postId,
            (string) ($post['title'] ?? ''),
            (string) ($post['title_en'] ?? ''),
            (string) ($post['slug'] ?? ''),
            (string) ($post['slug_en'] ?? ''),
            (string) ($post['content'] ?? ''),
            (string) ($post['content_en'] ?? ''),
            (string) ($post['excerpt'] ?? ''),
            (string) ($post['excerpt_en'] ?? ''),
            (string) ($post['status'] ?? ''),
            (int) ($post['category_id'] ?? 0) ?: null,
            (string) ($post['category_name'] ?? ''),
            (string) ($post['tags'] ?? ''),
            (int) ($post['author_id'] ?? 0) ?: null,
            (string) ($post['author_display_name'] ?? ''),
            (string) ($post['author_display_url'] ?? ''),
            $this->normalizeRevisionTimestampValue($post['published_at'] ?? null),
            $this->normalizeRevisionTimestampValue($post['content_updated_at'] ?? null),
        ]);
    }

    /**
     * @param array<string,mixed> $currentPost
     * @param array<string,mixed> $revision
     * @return array{changed_fields: array<int,string>, field_diffs: array<int,array<string,mixed>>}
     */
    private function buildPostRevisionComparison(array $currentPost, array $revision): array
    {
        $changedFields = [];
        $fieldDiffs = [];

        $this->appendRevisionTextDiff($changedFields, $fieldDiffs, 'Titel (DE)', $currentPost['title'] ?? '', $revision['title'] ?? '');
        $this->appendRevisionTextDiff($changedFields, $fieldDiffs, 'Titel (EN)', $currentPost['title_en'] ?? '', $revision['title_en'] ?? '');
        $this->appendRevisionTextDiff($changedFields, $fieldDiffs, 'Slug (DE)', $currentPost['slug'] ?? '', $revision['slug'] ?? '');
        $this->appendRevisionTextDiff($changedFields, $fieldDiffs, 'Slug (EN)', $currentPost['slug_en'] ?? '', $revision['slug_en'] ?? '');
        $this->appendRevisionTextDiff($changedFields, $fieldDiffs, 'Teaser (DE)', $currentPost['excerpt'] ?? '', $revision['excerpt'] ?? '');
        $this->appendRevisionTextDiff($changedFields, $fieldDiffs, 'Teaser (EN)', $currentPost['excerpt_en'] ?? '', $revision['excerpt_en'] ?? '');
        $this->appendRevisionTextDiff($changedFields, $fieldDiffs, 'Status', $currentPost['status'] ?? '', $revision['status'] ?? '');
        $this->appendRevisionTextDiff($changedFields, $fieldDiffs, 'Kategorie', $currentPost['category_name'] ?? '', $revision['category_name'] ?? '');
        $this->appendRevisionTextDiff($changedFields, $fieldDiffs, 'Tags', $currentPost['tags'] ?? '', $revision['tags'] ?? '');
        $this->appendRevisionTextDiff($changedFields, $fieldDiffs, 'Autorenname im Artikel', $currentPost['author_display_name'] ?? '', $revision['author_display_name'] ?? '');
        $this->appendRevisionTextDiff($changedFields, $fieldDiffs, 'Website des alternativen Autors', $currentPost['author_display_url'] ?? '', $revision['author_display_url'] ?? '');
        $this->appendRevisionTextDiff($changedFields, $fieldDiffs, 'Veröffentlichung', $this->formatRevisionTimestampLabel($currentPost['published_at'] ?? null), $this->formatRevisionTimestampLabel($revision['published_at'] ?? null));
        $this->appendRevisionTextDiff($changedFields, $fieldDiffs, 'Aktualisierungsdatum', $this->formatRevisionTimestampLabel($currentPost['content_updated_at'] ?? null), $this->formatRevisionTimestampLabel($revision['content_updated_at'] ?? null));
        $this->appendRevisionContentDiff($changedFields, $fieldDiffs, 'Inhalt (DE)', $currentPost['content'] ?? '', $revision['content'] ?? '');
        $this->appendRevisionContentDiff($changedFields, $fieldDiffs, 'Inhalt (EN)', $currentPost['content_en'] ?? '', $revision['content_en'] ?? '');

        return [
            'changed_fields' => array_values(array_unique($changedFields)),
            'field_diffs' => $fieldDiffs,
        ];
    }
        /**
     * @param array<int,array<string,mixed>> $fieldDiffs
     */
    private function appendRevisionTextDiff(array &$changedFields, array &$fieldDiffs, string $label, mixed $current, mixed $revision): void
    {
        $currentValue = $this->normalizeRevisionTextValue($current);
        $revisionValue = $this->normalizeRevisionTextValue($revision);

        if ($currentValue === $revisionValue) {
            return;
        }

        $changedFields[] = $label;
        $fieldDiffs[] = [
            'label' => $label,
            'type' => 'text',
            'current_label' => 'Aktuell',
            'current_value' => $this->formatRevisionDisplayText($currentValue),
            'revision_label' => 'Revision',
            'revision_value' => $this->formatRevisionDisplayText($revisionValue),
        ];
    }

    /**
     * @param array<int,string> $changedFields
     * @param array<int,array<string,mixed>> $fieldDiffs
     */
    private function appendRevisionContentDiff(array &$changedFields, array &$fieldDiffs, string $label, mixed $current, mixed $revision): void
    {
        $currentSummary = $this->summarizeEditorContentValue($current);
        $revisionSummary = $this->summarizeEditorContentValue($revision);

        if (($currentSummary['sha1'] ?? '') === ($revisionSummary['sha1'] ?? '')) {
            return;
        }

        $changedFields[] = $label;
        $fieldDiffs[] = [
            'label' => $label,
            'type' => 'content',
            'current_label' => 'Aktuell',
            'current_summary' => $this->formatContentSummaryForView($currentSummary),
            'revision_label' => 'Revision',
            'revision_summary' => $this->formatContentSummaryForView($revisionSummary),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function formatContentSummaryForView(array $summary): array
    {
        return [
            'preview' => trim((string) ($summary['preview'] ?? '')) !== '' ? (string) ($summary['preview'] ?? '') : '— leer —',
            'length' => (int) ($summary['length'] ?? 0),
            'json_blocks' => isset($summary['json_blocks']) && is_numeric($summary['json_blocks']) ? (int) $summary['json_blocks'] : null,
            'first_block_type' => trim((string) ($summary['first_block_type'] ?? '')),
            'is_empty' => !empty($summary['is_empty']),
        ];
    }

    private function normalizeRevisionTextValue(mixed $value): string
    {
        $stringValue = is_scalar($value) || $value === null ? (string) $value : '';
        $stringValue = preg_replace('/\s+/u', ' ', trim(strip_tags($stringValue))) ?? '';

        return $stringValue;
    }

    private function formatRevisionDisplayText(string $value): string
    {
        if ($value === '') {
            return '— leer —';
        }

        $maxLength = 180;
        $truncated = function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
        $fullLength = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        return $fullLength > $maxLength ? $truncated . ' …' : $truncated;
    }

    private function formatAdminTimestamp(string $value): string
    {
        if ($value === '') {
            return 'Unbekanntes Datum';
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? date('d.m.Y H:i', $timestamp) : $value;
    }

    /**
     * @param array<string,mixed> $revision
     */
    private function resolveRevisionAuthorLabel(array $revision): string
    {
        $displayName = trim((string) ($revision['display_name'] ?? ''));
        if ($displayName !== '') {
            return $displayName;
        }

        $username = trim((string) ($revision['username'] ?? ''));
        if ($username !== '') {
            return $username;
        }

        $authorId = (int) ($revision['author_id'] ?? 0);

        return $authorId > 0 ? 'Benutzer #' . $authorId : 'Unbekannt';
    }

    private function resolveCategoryName(int $categoryId): string
    {
        if ($categoryId <= 0) {
            return '';
        }

        return trim((string) ($this->db->get_var(
            "SELECT name FROM {$this->prefix}post_categories WHERE id = ? LIMIT 1",
            [$categoryId]
        ) ?: ''));
    }

    /**
     * @return array<int,string>
     */
    private function normalizeTagNamesFromString(string $value): array
    {
        $entries = preg_split('/[\r\n,]+/', $value) ?: [];
        $names = [];
        foreach ($entries as $entry) {
            $name = trim((string) $entry);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    private function normalizeRevisionTimestampValue(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function formatRevisionTimestampLabel(mixed $value): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return '— leer —';
        }

        return $this->formatAdminTimestamp($normalized);
    }

    private function sanitizeAuthorDisplayName(string $value): string
    {
        $value = trim(strip_tags($value));

        if ($value === '') {
            return '';
        }

        return function_exists('mb_substr')
            ? mb_substr($value, 0, 150)
            : substr($value, 0, 150);
    }

    private function sanitizeAuthorDisplayUrl(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = function_exists('mb_substr') ? mb_substr($value, 0, 500) : substr($value, 0, 500);

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        $scheme = strtolower((string) (parse_url($value, PHP_URL_SCHEME) ?? ''));

        return in_array($scheme, ['http', 'https'], true) ? $value : '';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getPostTemplateDefinitions(): array
    {
        $definitions = [];

        try {
            $themePath = rtrim(\CMS\ThemeManager::instance()->getThemePath(), "\\/") . DIRECTORY_SEPARATOR;
            $themeJson = $themePath . 'theme.json';
            if (is_file($themeJson)) {
                $decoded = json_decode((string) file_get_contents($themeJson), true, 512, JSON_THROW_ON_ERROR);
                $templates = is_array($decoded['post_templates'] ?? null) ? $decoded['post_templates'] : [];

                foreach ($templates as $template) {
                    if (!is_array($template)) {
                        continue;
                    }

                    $id = $this->sanitizeTemplateIdentifier((string) ($template['id'] ?? ''));
                    if ($id === '') {
                        continue;
                    }

                    $metaFields = is_array($template['meta_fields'] ?? null) ? $template['meta_fields'] : [];
                    $definitions[] = [
                        'id' => $id,
                        'label' => $this->sanitizePlainText((string) ($template['label'] ?? $id), 120),
                        'description' => $this->sanitizePlainText((string) ($template['description'] ?? ''), 500),
                        'file' => $this->sanitizePlainText((string) ($template['file'] ?? ''), 160),
                        'meta_fields' => $this->normalizeTemplateMetaFieldDefinitions($metaFields),
                    ];
                }
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('admin.posts')->warning('Beitrags-Templates konnten nicht aus dem aktiven Theme gelesen werden.', [
                'exception' => $e->getMessage(),
            ]);
        }

        if ($definitions === []) {
            $definitions[] = [
                'id' => 'default',
                'label' => 'Standard',
                'description' => '',
                'file' => 'post.php',
                'meta_fields' => [],
            ];
        }

        return $definitions;
    }

    private function sanitizeTemplateIdentifier(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]/', '', $value) ?? '';

        return $this->truncateText($value, 80);
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,array<string,mixed>>
     */
    private function normalizeTemplateMetaFieldDefinitions(array $fields): array
    {
        $normalized = [];

        foreach ($fields as $key => $field) {
            if (!is_array($field)) {
                continue;
            }

            $fieldKey = $this->sanitizeTemplateIdentifier((string) $key);
            if ($fieldKey === '') {
                continue;
            }

            $type = strtolower(trim((string) ($field['type'] ?? 'text')));
            if (!in_array($type, ['text', 'textarea', 'date', 'url', 'select', 'array'], true)) {
                $type = 'text';
            }

            $options = [];
            foreach ((array) ($field['options'] ?? []) as $optionKey => $optionValue) {
                $optionValue = is_array($optionValue) ? ($optionValue['value'] ?? '') : $optionValue;
                $option = $this->sanitizePlainText((string) (is_string($optionKey) ? $optionKey : $optionValue), 120);
                if ($option !== '') {
                    $options[] = $option;
                }
            }

            $normalized[$fieldKey] = [
                'label' => $this->sanitizePlainText((string) ($field['label'] ?? $fieldKey), 160),
                'type' => $type,
                'placeholder' => $this->sanitizePlainText((string) ($field['placeholder'] ?? ''), 200),
                'options' => array_values(array_unique($options)),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int,array<string,mixed>> $templates
     */
    private function sanitizePostTemplate(string $value, array $templates): string
    {
        $candidate = $this->sanitizeTemplateIdentifier($value);
        $allowed = [];

        foreach ($templates as $template) {
            $id = $this->sanitizeTemplateIdentifier((string) ($template['id'] ?? ''));
            if ($id !== '') {
                $allowed[$id] = true;
            }
        }

        if ($candidate !== '' && isset($allowed[$candidate])) {
            return $candidate;
        }

        return isset($allowed['default']) ? 'default' : (string) array_key_first($allowed ?: ['default' => true]);
    }

    /**
     * @param array<int,array<string,mixed>> $templates
     */
    private function sanitizePostTemplateMeta(mixed $rawMeta, string $templateId, array $templates): ?string
    {
        $selectedFields = [];
        foreach ($templates as $template) {
            if ((string) ($template['id'] ?? '') === $templateId && is_array($template['meta_fields'] ?? null)) {
                $selectedFields = $template['meta_fields'];
                break;
            }
        }

        if ($selectedFields === [] || !is_array($rawMeta)) {
            return null;
        }

        $normalized = [];
        foreach ($selectedFields as $key => $field) {
            if (!is_array($field)) {
                continue;
            }

            $fieldKey = $this->sanitizeTemplateIdentifier((string) $key);
            if ($fieldKey === '') {
                continue;
            }

            $type = (string) ($field['type'] ?? 'text');
            $value = $rawMeta[$fieldKey] ?? null;
            $normalizedValue = $this->sanitizePostTemplateMetaValue($value, $type, (array) ($field['options'] ?? []));

            if (is_array($normalizedValue)) {
                if ($normalizedValue !== []) {
                    $normalized[$fieldKey] = $normalizedValue;
                }
                continue;
            }

            if ($normalizedValue !== '') {
                $normalized[$fieldKey] = $normalizedValue;
            }
        }

        if ($normalized === []) {
            return null;
        }

        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : null;
    }

    /**
     * @param array<int,string> $options
     * @return string|array<int,string>
     */
    private function sanitizePostTemplateMetaValue(mixed $value, string $type, array $options = []): string|array
    {
        $type = strtolower(trim($type));

        if ($type === 'array') {
            $entries = is_array($value) ? $value : (preg_split('/[\r\n,;]+/', (string) $value) ?: []);
            $normalized = [];
            foreach ($entries as $entry) {
                if (!is_scalar($entry) && $entry !== null) {
                    continue;
                }
                $text = $this->sanitizePlainText((string) $entry, 180);
                if ($text !== '') {
                    $normalized[] = $text;
                }
            }

            return array_values(array_unique($normalized));
        }

        $stringValue = is_scalar($value) || $value === null ? trim((string) $value) : '';

        if ($type === 'url') {
            if ($stringValue === '') {
                return '';
            }

            $scheme = strtolower((string) parse_url($stringValue, PHP_URL_SCHEME));
            if ($scheme === '' || !in_array($scheme, ['http', 'https'], true)) {
                return '';
            }

            return filter_var($stringValue, FILTER_VALIDATE_URL) ? $this->truncateText($stringValue, 500) : '';
        }

        if ($type === 'date') {
            if ($stringValue === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $stringValue) !== 1) {
                return '';
            }

            return $stringValue;
        }

        if ($type === 'select') {
            $allowed = array_fill_keys(array_map('strval', $options), true);

            return isset($allowed[$stringValue]) ? $stringValue : '';
        }

        return $this->sanitizePlainText($stringValue, $type === 'textarea' ? 2000 : 500);
    }

    /**
     * @return array<string,mixed>
     */
    private function summarizeEditorContentValue(mixed $value): array
    {
        $stringValue = is_string($value)
            ? $value
            : (is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if (!is_string($stringValue)) {
            $stringValue = '';
        }

        $normalizedPreview = preg_replace('/\s+/u', ' ', trim($stringValue)) ?? '';
        $summary = [
            'length' => strlen($stringValue),
            'sha256' => hash('sha256', $stringValue),
            'is_empty' => trim($stringValue) === '',
            'preview' => $this->truncateText($normalizedPreview, 180),
        ];

        try {
            $decoded = json_decode($stringValue, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded) && isset($decoded['blocks']) && is_array($decoded['blocks'])) {
                $summary['json_blocks'] = count($decoded['blocks']);

                if (!empty($decoded['blocks'][0]) && is_array($decoded['blocks'][0])) {
                    $summary['first_block_type'] = (string) ($decoded['blocks'][0]['type'] ?? '');
                }
            }
        } catch (\Throwable $_error) {
            $summary['json_blocks'] = null;
        }

        return $summary;
    }

    private function sanitizePlainText(string $value, int $maxLength): string
    {
        $value = trim(strip_tags($value));
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $value) ?? '';

        return function_exists('mb_substr')
            ? mb_substr($value, 0, $maxLength)
            : substr($value, 0, $maxLength);
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $allowedArrayFields
     */
    private function findUnexpectedNonScalarInput(array $input, array $allowedArrayFields = []): string
    {
        foreach ($input as $key => $value) {
            if (!is_array($value) && !is_object($value)) {
                continue;
            }

            if (is_array($value) && in_array((string) $key, $allowedArrayFields, true)) {
                foreach ($value as $entry) {
                    if (!is_scalar($entry) && $entry !== null) {
                        $field = preg_replace('/[^a-z0-9_-]/i', '', (string) $key) ?? '';
                        return $field !== '' ? $field : 'Eingabe';
                    }
                }
                continue;
            }

            $field = preg_replace('/[^a-z0-9_-]/i', '', (string) $key) ?? '';
            return $field !== '' ? $field : 'Eingabe';
        }

        return '';
    }

    private function preserveOriginalEditorContentIfUnchanged(mixed $submittedValue, mixed $originalValue): string
    {
        $submitted = (string) $submittedValue;
        $original = (string) $originalValue;

        if ($original === '') {
            return $submitted;
        }

        if ($submitted === $original) {
            return $original;
        }

        $decodedSubmitted = json_decode(trim($submitted), true);
        $submittedIsEditorPayload = is_array($decodedSubmitted) && isset($decodedSubmitted['blocks']) && is_array($decodedSubmitted['blocks']);
        $submittedIsFallbackPayload = $submittedIsEditorPayload && (string) ($decodedSubmitted['version'] ?? '') === 'cms-editor-fallback';

        if ($submittedIsEditorPayload && !$submittedIsFallbackPayload) {
            return $submitted;
        }

        $decodedOriginal = json_decode(trim($original), true);
        if (!is_array($decodedOriginal) || !isset($decodedOriginal['blocks']) || !is_array($decodedOriginal['blocks'])) {
            return $submitted;
        }

        if ($this->extractPlainTextFromContentPayload($submitted) === $this->extractPlainTextFromContentPayload($original)) {
            return $original;
        }

        return $submitted;
    }

    private function extractPlainTextFromContentPayload(string $rawContent): string
    {
        $rawContent = trim($rawContent);
        if ($rawContent === '') {
            return '';
        }

        $decoded = json_decode($rawContent, true);
        if (!is_array($decoded) || !isset($decoded['blocks']) || !is_array($decoded['blocks'])) {
            return trim(strip_tags(str_replace('<br>', "\n", $rawContent)));
        }

        $parts = [];
        foreach ($decoded['blocks'] as $block) {
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            foreach (['text', 'message', 'title', 'code', 'caption', 'content', 'html'] as $key) {
                $value = isset($data[$key]) ? trim((string) $data[$key]) : '';
                if ($value !== '') {
                    $parts[] = trim(strip_tags(str_replace('<br>', "\n", $value)));
                    break;
                }
            }
        }

        return trim(implode("\n\n", array_filter($parts, static fn(string $part): bool => $part !== '')));
    }

    private function sanitizeMediaReference(string $value): string
    {
        $value = trim(str_replace('\\', '/', $value));
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, 500) : substr($value, 0, 500);
    }

    private function prepareEditorContentForLanguageCopy(string $content): string
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return '';
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $content;
        }

        if (!is_array($decoded) || !isset($decoded['blocks']) || !is_array($decoded['blocks'])) {
            return $content;
        }

        $decoded['blocks'] = $this->removeEditorBlockIds($decoded['blocks']);

        try {
            return (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $content;
        }
    }

    /**
     * @param array<int|string,mixed> $blocks
     * @return array<int|string,mixed>
     */
    private function removeEditorBlockIds(array $blocks): array
    {
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }

            unset($block['id']);
            if (isset($block['data']['blocks']) && is_array($block['data']['blocks'])) {
                $block['data']['blocks'] = $this->removeEditorBlockIds($block['data']['blocks']);
            }

            $blocks[$index] = $block;
        }

        return $blocks;
    }

    private function sanitizeSearchTerm(string $value): string
    {
        return $this->sanitizePlainText($value, 120);
    }

    private function normalizeListStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return in_array($status, self::ALLOWED_LIST_STATUSES, true) ? $status : '';
    }

    private function normalizeExistingCategoryId(int $categoryId): int
    {
        return $this->categoryExists($categoryId) ? $categoryId : 0;
    }

    /**
     * @param array<int|string,mixed> $ids
     * @return array<int,int>
     */
    private function normalizePositiveIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
    }

    private function normalizeBulkAction(string $action): string
    {
        $action = trim($action);

        return in_array($action, self::ALLOWED_BULK_ACTIONS, true) ? $action : '';
    }

    private function failResult(string $action, string $message, ?\Throwable $exception = null, array $context = []): array
    {
        $this->logFailure($action, $message, $exception, $context);

        return ['success' => false, 'error' => $message . ' Bitte Logs prüfen.'];
    }

    private function logFailure(string $action, string $message, ?\Throwable $exception = null, array $context = []): void
    {
        if ($exception !== null) {
            $context['exception'] = $exception->getMessage();
        }

        Logger::instance()->withChannel('admin.posts')->error($message, $context);
        AuditLogger::instance()->log(
            AuditLogger::CAT_CONTENT,
            $action,
            $message,
            'posts',
            null,
            $context,
            'error'
        );
    }

    private function clearContentCacheIfEnabled(string $reason, int $contentId): void
    {
        try {
            $enabled = (string)($this->db->get_var("SELECT option_value FROM {$this->prefix}settings WHERE option_name = 'perf_auto_clear_content_cache' LIMIT 1") ?? '1') !== '0';
            if (!$enabled) {
                return;
            }

            CacheManager::instance()->clear();
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('admin.posts')->warning('Content-Cache konnte nach Beitragsänderung nicht automatisch geleert werden.', [
                'reason' => $reason,
                'post_id' => $contentId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Slug generieren
     */
    private function generateSlug(string $text): string
    {
        $slug = $this->toLowercase($text);
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug) ?? $slug;
        $slug = preg_replace('/-+/', '-', $slug) ?? $slug;
        return trim($slug, '-');
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = $this->toLowercase(trim($slug));
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug) ?? $slug;
        $slug = preg_replace('/-+/', '-', $slug) ?? $slug;
        return trim($slug, '-');
    }

    private function toLowercase(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value)
            : strtolower($value);
    }

    private function truncateText(string $value, int $maxLength): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $maxLength)
            : substr($value, 0, $maxLength);
    }

    /**
     * @return array{value:?string,error:?string}
     */
    private function normalizePublishedAtInput(string $date, string $time): array
    {
        $date = trim($date);
        $time = trim($time);

        if ($date === '' && $time === '') {
            return ['value' => null, 'error' => null];
        }

        if ($date === '') {
            return ['value' => null, 'error' => 'Bitte ein Veröffentlichungsdatum angeben.'];
        }

        if ($time === '') {
            $time = '00:00';
        }

        $publishedAt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time);
        $errors = \DateTimeImmutable::getLastErrors();
        $hasErrors = is_array($errors) && ((int) ($errors['warning_count'] ?? 0) > 0 || (int) ($errors['error_count'] ?? 0) > 0);

        if (!$publishedAt instanceof \DateTimeImmutable || $hasErrors) {
            return ['value' => null, 'error' => 'Bitte ein gültiges Veröffentlichungsdatum mit Uhrzeit angeben.'];
        }

        return ['value' => $publishedAt->format('Y-m-d H:i:s'), 'error' => null];
    }

    /**
     * @return array{value:?string,error:?string}
     */
    private function normalizeContentUpdatedAtInput(string $date, string $time): array
    {
        $date = trim($date);
        $time = trim($time);

        if ($date === '' && $time === '') {
            return ['value' => null, 'error' => null];
        }

        if ($date === '') {
            return ['value' => null, 'error' => 'Bitte ein Aktualisierungsdatum angeben.'];
        }

        if ($time === '') {
            $time = '00:00';
        }

        $contentUpdatedAt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time);
        $errors = \DateTimeImmutable::getLastErrors();
        $hasErrors = is_array($errors) && ((int) ($errors['warning_count'] ?? 0) > 0 || (int) ($errors['error_count'] ?? 0) > 0);

        if (!$contentUpdatedAt instanceof \DateTimeImmutable || $hasErrors) {
            return ['value' => null, 'error' => 'Bitte ein gültiges Aktualisierungsdatum mit Uhrzeit angeben.'];
        }

        return ['value' => $contentUpdatedAt->format('Y-m-d H:i:s'), 'error' => null];
    }

    private function resolvePublishedAtValue(string $status, ?string $requestedPublishedAt, ?object $existing): ?string
    {
        $requestedPublishedAt = is_string($requestedPublishedAt) && trim($requestedPublishedAt) !== ''
            ? trim($requestedPublishedAt)
            : null;

        $existingPublishedAt = $existing !== null && !empty($existing->published_at)
            ? trim((string) $existing->published_at)
            : null;

        if ($status === 'draft' || $status === 'private') {
            return $requestedPublishedAt;
        }

        if ($requestedPublishedAt !== null) {
            return $requestedPublishedAt;
        }

        if ($existingPublishedAt !== null && $existingPublishedAt !== '') {
            return $existingPublishedAt;
        }

        return date('Y-m-d H:i:s');
    }

    private function isSlugTaken(string $slug, int $ignoreId = 0): bool
    {
        $params = [$slug];
        $sql = "SELECT COUNT(*) FROM {$this->prefix}posts WHERE slug = ?";

        if ($ignoreId > 0) {
            $sql .= " AND id != ?";
            $params[] = $ignoreId;
        }

        return (int)$this->db->get_var($sql, $params) > 0;
    }

    private function isLocalizedSlugTaken(string $slugEn, int $ignoreId = 0): bool
    {
        $slugEn = trim($slugEn);
        if ($slugEn === '') {
            return false;
        }

        $params = [$slugEn, $slugEn];
        $sql = "SELECT COUNT(*) FROM {$this->prefix}posts WHERE (slug_en = ? OR slug = ?)";

        if ($ignoreId > 0) {
            $sql .= " AND id != ?";
            $params[] = $ignoreId;
        }

        return (int)$this->db->get_var($sql, $params) > 0;
    }

    private function isCategorySlugTaken(string $slug, int $ignoreId = 0): bool
    {
        $params = [$slug, $slug];
        $sql = "SELECT COUNT(*) FROM {$this->prefix}post_categories WHERE (slug = ? OR slug_en = ?)";

        if ($ignoreId > 0) {
            $sql .= " AND id != ?";
            $params[] = $ignoreId;
        }

        return (int) $this->db->get_var($sql, $params) > 0;
    }

    private function isTagSlugTaken(string $slug, int $ignoreId = 0): bool
    {
        $params = [$slug, $slug];
        $sql = "SELECT COUNT(*) FROM {$this->prefix}post_tags WHERE (slug = ? OR slug_en = ?)";

        if ($ignoreId > 0) {
            $sql .= " AND id != ?";
            $params[] = $ignoreId;
        }

        return (int) $this->db->get_var($sql, $params) > 0;
    }
    private function isCategoryDescendant(int $candidateParentId, int $categoryId): bool
    {
        if ($candidateParentId <= 0 || $categoryId <= 0) {
            return false;
        }

        $currentParentId = $candidateParentId;
        $guard = 0;
        while ($currentParentId > 0 && $guard < 50) {
            if ($currentParentId === $categoryId) {
                return true;
            }

            $currentParentId = (int) ($this->db->get_var(
                "SELECT parent_id FROM {$this->prefix}post_categories WHERE id = ? LIMIT 1",
                [$currentParentId]
            ) ?: 0);
            $guard++;
        }

        return false;
    }

    private function countAvailableReplacementCategories(int $excludeId): int
    {
        return (int) ($this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}post_categories WHERE id != ?",
            [$excludeId]
        ) ?: 0);
    }

    private function tagExists(int $tagId): bool
    {
        if ($tagId <= 0) {
            return false;
        }

        return (int) ($this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}post_tags WHERE id = ?",
            [$tagId]
        ) ?: 0) > 0;
    }

    private function countAvailableReplacementTags(int $excludeId): int
    {
        return (int) ($this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}post_tags WHERE id != ?",
            [$excludeId]
        ) ?: 0);
    }

    /**
     * @param array<int,int> $tagIds
     * @return array<int,int>
     */
    private function getExistingTagIds(array $tagIds): array
    {
        $tagIds = $this->normalizePositiveIds($tagIds);
        if ($tagIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
        $rows = $this->db->get_results(
            "SELECT id FROM {$this->prefix}post_tags WHERE id IN ({$placeholders})",
            $tagIds
        ) ?: [];

        $existing = [];
        foreach ($rows as $row) {
            $existing[(int) ($row->id ?? 0)] = true;
        }

        return array_values(array_filter($tagIds, static fn(int $id): bool => isset($existing[$id])));
    }

    private function countAssignedPostsForTag(int $tagId): int
    {
        if ($tagId <= 0) {
            return 0;
        }

        return (int) ($this->db->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$this->prefix}post_tag_rel WHERE tag_id = ?",
            [$tagId]
        ) ?: 0);
    }

    private function reassignPostsFromDeletedTag(int $deletedTagId, int $replacementTagId): void
    {
        $postRows = $this->db->get_results(
            "SELECT DISTINCT post_id FROM {$this->prefix}post_tag_rel WHERE tag_id = ?",
            [$deletedTagId]
        ) ?: [];

        $postIds = [];
        foreach ($postRows as $row) {
            $postId = (int) ($row->post_id ?? 0);
            if ($postId > 0) {
                $postIds[] = $postId;
            }
        }

        if ($postIds !== []) {
            $this->db->execute(
                "INSERT IGNORE INTO {$this->prefix}post_tag_rel (post_id, tag_id)
                 SELECT DISTINCT post_id, ?
                 FROM {$this->prefix}post_tag_rel
                 WHERE tag_id = ?",
                [$replacementTagId, $deletedTagId]
            );
        }

        $this->db->execute("DELETE FROM {$this->prefix}post_tag_rel WHERE tag_id = ?", [$deletedTagId]);

        foreach ($postIds as $postId) {
            $tagNames = $this->getPostTagNames($postId);
            $this->db->execute(
                "UPDATE {$this->prefix}posts SET tags = ?, updated_at = NOW() WHERE id = ?",
                [implode(', ', $tagNames), $postId]
            );
        }
    }

    /**
     * @return array<int,string>
     */
    private function getPostTagNames(int $postId): array
    {
        if ($postId <= 0) {
            return [];
        }

        $rows = $this->db->get_results(
            "SELECT t.name
             FROM {$this->prefix}post_tags t
             INNER JOIN {$this->prefix}post_tag_rel ptr ON ptr.tag_id = t.id
             WHERE ptr.post_id = ?
             ORDER BY t.name ASC",
            [$postId]
        ) ?: [];

        $names = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row->name ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array<int, array{name:string, slug:string}>
     */
    private function normalizeTagInput(string|array $rawTags): array
    {
        $tagValues = is_array($rawTags)
            ? $rawTags
            : (preg_split('/[,\n]+/', $rawTags) ?: []);
        $normalized = [];
        $seen = [];

        foreach ($tagValues as $tagValue) {
            $name = trim((string)$tagValue);
            if ($name === '') {
                continue;
            }

            $slug = $this->normalizeSlug($this->generateSlug($name));
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }

            $seen[$slug] = true;
            $normalized[] = [
                'name' => $this->truncateText($name, 120),
                'slug' => $this->truncateText($slug, 160),
            ];
        }

        return $normalized;
    }

    private function syncPostTags(int $postId, string|array $rawTags): void
    {
        if ($postId <= 0) {
            return;
        }

        $tags = $this->normalizeTagInput($rawTags);

        $this->db->execute(
            "DELETE FROM {$this->prefix}post_tag_rel WHERE post_id = ?",
            [$postId]
        );

        if ($tags === []) {
            return;
        }

        foreach ($tags as $tag) {
            $existingTagId = (int)($this->db->get_var(
                "SELECT id FROM {$this->prefix}post_tags WHERE slug = ? LIMIT 1",
                [$tag['slug']]
            ) ?: 0);

            if ($existingTagId <= 0) {
                $this->db->execute(
                    "INSERT INTO {$this->prefix}post_tags (name, slug) VALUES (?, ?)",
                    [$tag['name'], $tag['slug']]
                );
                $existingTagId = (int)$this->db->lastInsertId();
            } else {
                $this->db->execute(
                    "UPDATE {$this->prefix}post_tags SET name = ? WHERE id = ?",
                    [$tag['name'], $existingTagId]
                );
            }

            if ($existingTagId > 0) {
                $this->db->execute(
                    "INSERT IGNORE INTO {$this->prefix}post_tag_rel (post_id, tag_id) VALUES (?, ?)",
                    [$postId, $existingTagId]
                );
            }
        }
    }

    private function serializeTagsForLegacyColumn(string|array $rawTags): string
    {
        $tags = $this->normalizeTagInput($rawTags);

        if ($tags === []) {
            return '';
        }

        return implode(', ', array_map(
            static fn(array $tag): string => (string) ($tag['name'] ?? ''),
            $tags
        ));
    }

    /**
     * @param mixed $additionalCategoryIds
     * @return array<int,int>
     */
    private function normalizeSelectedCategoryIds(int $primaryCategoryId, mixed $additionalCategoryIds): array
    {
        $requestedIds = [];
        if ($primaryCategoryId > 0) {
            $requestedIds[] = $primaryCategoryId;
        }

        foreach ((array) $additionalCategoryIds as $categoryId) {
            if (!is_scalar($categoryId) && $categoryId !== null) {
                continue;
            }
            $normalizedId = (int) $categoryId;
            if ($normalizedId > 0) {
                $requestedIds[] = $normalizedId;
            }
        }

        if ($requestedIds === []) {
            return [];
        }

        return $this->getExistingCategoryIds(array_values(array_unique($requestedIds)));
    }

    /**
     * @param array<int,int> $categoryIds
     * @return array<int,int>
     */
    private function getExistingCategoryIds(array $categoryIds): array
    {
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds), static fn(int $id): bool => $id > 0)));
        if ($categoryIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $rows = $this->db->get_results(
            "SELECT id FROM {$this->prefix}post_categories WHERE id IN ({$placeholders})",
            $categoryIds
        ) ?: [];

        $existing = [];
        foreach ($rows as $row) {
            $existing[(int) ($row->id ?? 0)] = true;
        }

        return array_values(array_filter($categoryIds, static fn(int $id): bool => isset($existing[$id])));
    }

    /**
     * @return array<int,int>
     */
    private function getPostCategoryIds(int $postId): array
    {
        if ($postId <= 0) {
            return [];
        }

        $tableExists = $this->db->tableExists($this->prefix . 'post_category_rel');
        $categoryIds = [];

        if ($tableExists) {
            $rows = $this->db->get_results(
                "SELECT category_id FROM {$this->prefix}post_category_rel WHERE post_id = ? ORDER BY id ASC",
                [$postId]
            ) ?: [];

            foreach ($rows as $row) {
                $categoryId = (int) ($row->category_id ?? 0);
                if ($categoryId > 0) {
                    $categoryIds[] = $categoryId;
                }
            }
        }

        $primaryCategoryId = (int) ($this->db->get_var(
            "SELECT category_id FROM {$this->prefix}posts WHERE id = ? LIMIT 1",
            [$postId]
        ) ?: 0);

        if ($primaryCategoryId > 0) {
            array_unshift($categoryIds, $primaryCategoryId);
        }

        return array_values(array_unique(array_filter($categoryIds, static fn(int $id): bool => $id > 0)));
    }

    /**
     * @param array<int,int> $categoryIds
     */
    private function syncPostCategories(int $postId, array $categoryIds): void
    {
        if ($postId <= 0) {
            return;
        }

        $tableExists = $this->db->tableExists($this->prefix . 'post_category_rel');
        if (!$tableExists) {
            return;
        }

        $categoryIds = $this->getExistingCategoryIds($categoryIds);
        $this->db->execute(
            "DELETE FROM {$this->prefix}post_category_rel WHERE post_id = ?",
            [$postId]
        );

        foreach ($categoryIds as $categoryId) {
            $this->db->execute(
                "INSERT IGNORE INTO {$this->prefix}post_category_rel (post_id, category_id) VALUES (?, ?)",
                [$postId, $categoryId]
            );
        }
    }

    private function getCurrentCategorySlug(int $categoryId): string
    {
        if ($categoryId <= 0) {
            return '';
        }

        return trim((string) ($this->db->get_var(
            "SELECT slug FROM {$this->prefix}post_categories WHERE id = ? LIMIT 1",
            [$categoryId]
        ) ?: ''));
    }

    private function getCurrentCategorySlugEn(int $categoryId): string
    {
        if ($categoryId <= 0) {
            return '';
        }

        return trim((string) ($this->db->get_var(
            "SELECT slug_en FROM {$this->prefix}post_categories WHERE id = ? LIMIT 1",
            [$categoryId]
        ) ?: ''));
    }

    private function getCurrentTagSlug(int $tagId): string
    {
        if ($tagId <= 0) {
            return '';
        }

        return trim((string) ($this->db->get_var(
            "SELECT slug FROM {$this->prefix}post_tags WHERE id = ? LIMIT 1",
            [$tagId]
        ) ?: ''));
    }

    private function getCurrentTagSlugEn(int $tagId): string
    {
        if ($tagId <= 0) {
            return '';
        }

        return trim((string) ($this->db->get_var(
            "SELECT slug_en FROM {$this->prefix}post_tags WHERE id = ? LIMIT 1",
            [$tagId]
        ) ?: ''));
    }

    /**
     * Legt Archiv-Weiterleitungen je Sprache an, wenn sich der effektive Slug ändert.
     *
     * DE nutzt immer den Haupt-Slug; EN nutzt den EN-Slug und fällt sonst auf den Haupt-Slug zurück.
     *
     * @return array<int, string>
     */
    private function createLocalizedTaxonomyArchiveRedirects(string $archiveType, string $oldSlug, string $newSlug, string $oldSlugEn = '', string $newSlugEn = ''): array
    {
        $details = $this->createTaxonomyArchiveRedirectsIfNeeded($archiveType, $oldSlug, $newSlug, 'de');

        $locales = function_exists('cms_get_archive_locales') ? cms_get_archive_locales() : ['de'];
        if (in_array('en', array_map('strval', $locales), true)) {
            $effectiveOldEn = $oldSlugEn !== '' ? $oldSlugEn : $oldSlug;
            $effectiveNewEn = $newSlugEn !== '' ? $newSlugEn : $newSlug;
            $details = array_merge(
                $details,
                $this->createTaxonomyArchiveRedirectsIfNeeded($archiveType, $effectiveOldEn, $effectiveNewEn, 'en')
            );
        }

        return array_values(array_unique($details));
    }

    private function createTaxonomyArchiveRedirectsIfNeeded(string $archiveType, string $oldSlug, string $newSlug, ?string $locale = null): array
    {
        $redirectPairs = $this->getTaxonomyArchiveRedirectPairs($archiveType, $oldSlug, $newSlug, $locale);
        if ($redirectPairs === []) {
            return [];
        }

        $notes = $archiveType === 'tag'
            ? 'Automatisch bei Tag-Slug-Änderung angelegt'
            : 'Automatisch bei Kategorie-Slug-Änderung angelegt';

        foreach ($redirectPairs as $redirectPair) {
            RedirectService::getInstance()->createAutomaticRedirect(
                (string) ($redirectPair['source_path'] ?? ''),
                (string) ($redirectPair['target_path'] ?? ''),
                $notes
            );
        }

        return $this->buildTaxonomyRedirectDetails($archiveType, $oldSlug, $newSlug, $redirectPairs);
    }

    /**
     * @return array<int, array{source_path:string,target_path:string}>
     */
    private function getTaxonomyArchiveRedirectPairs(string $archiveType, string $oldSlug, string $newSlug, ?string $locale = null): array
    {
        $oldSlug = $this->normalizeSlug($oldSlug);
        $newSlug = $this->normalizeSlug($newSlug);

        if ($oldSlug === '' || $newSlug === '' || $oldSlug === $newSlug) {
            return [];
        }

        if (!function_exists('cms_get_archive_locales') || !function_exists('cms_get_archive_base')) {
            return [];
        }

        $pairs = [];
        $seenPaths = [];
        $locales = $locale !== null ? [$locale] : cms_get_archive_locales();

        foreach ($locales as $archiveLocale) {
            $archiveBase = trim((string) cms_get_archive_base($archiveType, (string) $archiveLocale), '/');
            if ($archiveBase === '') {
                continue;
            }

            $sourcePath = '/' . $archiveBase . '/' . $oldSlug;
            $targetPath = '/' . $archiveBase . '/' . $newSlug;

            if (isset($seenPaths[$sourcePath])) {
                continue;
            }

            $seenPaths[$sourcePath] = true;
            $pairs[] = [
                'source_path' => $sourcePath,
                'target_path' => $targetPath,
            ];
        }

        return $pairs;
    }

    /**
     * @param array<int, array{source_path:string,target_path:string}> $redirectPairs
     * @return array<int, string>
     */
    private function buildTaxonomyRedirectDetails(string $archiveType, string $oldSlug, string $newSlug, array $redirectPairs): array
    {
        $oldSlug = $this->normalizeSlug($oldSlug);
        $newSlug = $this->normalizeSlug($newSlug);

        if ($redirectPairs === [] || $oldSlug === '' || $newSlug === '') {
            return [];
        }

        $details = array_map(
            static fn(array $redirectPair): string => (string) ($redirectPair['source_path'] ?? '') . ' → ' . (string) ($redirectPair['target_path'] ?? ''),
            $redirectPairs
        );

        $details[] = $archiveType === 'tag'
            ? 'Legacy-/Theme-Links mit ?tag=' . $oldSlug . ' bleiben weiterhin auf den aktuellen Archiv-Slug auflösbar.'
            : 'Legacy-/Theme-Links mit ?category=' . $oldSlug . ' bleiben weiterhin auf den aktuellen Archiv-Slug auflösbar.';
        $details[] = 'Die Redirect-Regeln werden weiter zentral über den Redirect-Manager geführt.';

        return $details;
    }

    /**
     * @param array<string, string> $postDates
     */
    private function createSlugRedirectIfNeeded(string $oldSlug, string $newSlug, string $oldLocalizedSlug = '', string $newLocalizedSlug = '', array $postDates = []): void
    {
        $oldSlug = trim($oldSlug);
        $newSlug = trim($newSlug);
        $oldLocalizedSlug = trim($oldLocalizedSlug);
        $newLocalizedSlug = trim($newLocalizedSlug);

        $permalinkService = PermalinkService::getInstance();
        $publishedAt = (string)($postDates['published_at'] ?? '');
        $createdAt = (string)($postDates['created_at'] ?? '');
            $targetPublishedAt = (string)($postDates['target_published_at'] ?? $publishedAt);
            $targetCreatedAt = (string)($postDates['target_created_at'] ?? $createdAt);

        if ($oldSlug !== '' && $newSlug !== '' && $oldSlug !== $newSlug) {
            $oldPath = $permalinkService->buildPostPathFromValues($oldSlug, $publishedAt, $createdAt);
                $newPath = $permalinkService->buildPostPathFromValues($newSlug, $targetPublishedAt, $targetCreatedAt);

            RedirectService::getInstance()->createAutomaticRedirect(
                $oldPath,
                $newPath,
                'Automatisch bei Beitrags-Slug-Änderung angelegt'
            );

            $legacyOldPath = $permalinkService->getLegacyPostPath($oldSlug);
            if ($legacyOldPath !== $oldPath) {
                RedirectService::getInstance()->createAutomaticRedirect(
                    $legacyOldPath,
                    $newPath,
                    'Legacy-Weiterleitung bei Beitrags-Slug-Änderung angelegt'
                );
            }
        }

        foreach (ContentLocalizationService::getInstance()->getContentLocales() as $locale) {
            if ($locale === 'de') {
                continue;
            }

            $sourceSlug = $locale === 'en' && $oldLocalizedSlug !== '' ? $oldLocalizedSlug : $oldSlug;
            $targetSlug = $locale === 'en' && $newLocalizedSlug !== '' ? $newLocalizedSlug : $newSlug;

            if ($sourceSlug === '' || $targetSlug === '' || $sourceSlug === $targetSlug) {
                continue;
            }

            $localizedOldPath = $permalinkService->buildPostPathFromValues($sourceSlug, $publishedAt, $createdAt, $locale);
                $localizedNewPath = $permalinkService->buildPostPathFromValues($targetSlug, $targetPublishedAt, $targetCreatedAt, $locale);
            RedirectService::getInstance()->createAutomaticRedirect(
                $localizedOldPath,
                $localizedNewPath,
                'Automatisch bei lokalisiertem Beitrags-Slug angelegt'
            );

            $localizedLegacyOldPath = $permalinkService->getLegacyPostPath($sourceSlug, $locale);
            if ($localizedLegacyOldPath !== $localizedOldPath) {
                RedirectService::getInstance()->createAutomaticRedirect(
                    $localizedLegacyOldPath,
                    $localizedNewPath,
                    'Legacy-Weiterleitung bei lokalisiertem Beitrags-Slug angelegt'
                );
            }
        }
    }
}
}
