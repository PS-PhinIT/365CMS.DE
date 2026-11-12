<?php
declare(strict_types=1);

/**
 * Knowledgebase – Admin-Modul (Übersicht, Einträge, Editor, Kategorien, Einstellungen).
 *
 * Datenzugriff über `CMS\Services\Knowledgebase\Repository\EntryRepository` und
 * `CMS\Services\Knowledgebase\Service\StandardPackages`. Einträge wandern beim Löschen in den
 * Papierkorb (`/admin/trash`, Provider `kb_entry`); endgültig gelöscht wird nur dort oder per Hardreset.
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Database;
use CMS\Logger;
use CMS\Services\Knowledgebase\KnowledgebaseContentProvider;
use CMS\Services\Knowledgebase\Repository\EntryRepository;
use CMS\Services\Knowledgebase\Service\StandardPackages;
use CMS\Services\Knowledgebase\Support\ContentSections;
use CMS\Services\Knowledgebase\Support\Defaults;
use CMS\Services\Search\SearchIndexService;

final class KnowledgebaseModule
{
    public const SECTIONS = ['dashboard', 'entries', 'edit', 'categories', 'settings'];
    public const SETTINGS_TABS = ['general', 'design', 'import', 'system'];
    public const PER_PAGE = 25;
    private const DRAFT_SESSION_KEY = 'admin_knowledgebase_entry_draft';

    private EntryRepository $repository;

    public function __construct()
    {
        $this->repository = EntryRepository::instance();
    }

    /**
     * @param array<string, scalar|null> $params
     */
    public static function url(array $params = []): string
    {
        $params = array_filter($params, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== 0);

        return '/admin/knowledgebase' . ($params !== [] ? '?' . http_build_query($params) : '');
    }

    // ── Daten für die Ansichten ─────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function getDashboardData(): array
    {
        $db = Database::instance();
        $table = $db->getPrefix() . 'kb_entries';
        $latest = $db->execute(
            "SELECT id, title, slug, category, is_active, updated_at FROM {$table} WHERE trashed_at IS NULL ORDER BY updated_at DESC, id DESC LIMIT 8"
        )->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'view' => 'dashboard',
            'stats' => $this->repository->getDashboardStats(),
            'trashed' => (int) $db->get_var("SELECT COUNT(*) FROM {$table} WHERE trashed_at IS NOT NULL"),
            'latest' => is_array($latest) ? $latest : [],
            'settings' => $this->repository->getSettings(),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function getEntriesData(array $query): array
    {
        $filters = [
            'search' => is_string($query['q'] ?? null) ? mb_substr(trim($query['q']), 0, 120) : '',
            'category' => is_string($query['category'] ?? null) ? trim($query['category']) : '',
            'status' => in_array($query['status'] ?? '', ['active', 'inactive'], true) ? (string) $query['status'] : '',
        ];
        $total = $this->repository->countEntries($filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) ($query['page'] ?? 1)));
        $entries = $total > 0
            ? $this->repository->getEntryList($filters + ['limit' => self::PER_PAGE, 'offset' => ($page - 1) * self::PER_PAGE])
            : [];

        return [
            'view' => 'entries',
            'filters' => $filters,
            'entries' => $entries,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'categories' => $this->repository->getCategories(),
        ];
    }

    /**
     * @return array<string, mixed>|null null, wenn die ID nicht existiert
     */
    public function getEditData(int $id): ?array
    {
        $entry = null;
        if ($id > 0) {
            $entry = $this->repository->getEntry($id);
            if ($entry === null) {
                return null;
            }
        }

        $draft = $_SESSION[self::DRAFT_SESSION_KEY] ?? null;
        unset($_SESSION[self::DRAFT_SESSION_KEY]);
        $values = $entry ?? [];
        if (is_array($draft) && (int) ($draft['id'] ?? -1) === $id) {
            unset($draft['id']);
            $values = array_replace($values, $draft);
        }

        return [
            'view' => 'edit',
            'is_new' => $entry === null,
            'entry' => $values,
            'trashed' => $entry !== null && !empty($entry['trashed_at']),
            'categories' => $this->repository->getCategories(),
            'sections' => ContentSections::definitions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getCategoriesData(int $editId): array
    {
        return [
            'view' => 'categories',
            'categories' => $this->repository->getCategories(),
            'edit' => $editId > 0 ? $this->repository->getCategory($editId) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettingsData(string $tab): array
    {
        $tab = in_array($tab, self::SETTINGS_TABS, true) ? $tab : 'general';
        $packages = StandardPackages::instance();

        return [
            'view' => 'settings',
            'tab' => $tab,
            'settings' => $this->repository->getSettings(),
            'stats' => $this->repository->getDashboardStats(),
            'design_choices' => Defaults::designChoices(),
            'packages' => $tab === 'import' ? $packages->getPackages() : [],
            'csv_warnings' => $tab === 'import' ? $packages->getCsvFilenameWarnings() : [],
            'template_columns' => $tab === 'import' ? $packages->templateColumns() : [],
            'template_available' => $tab === 'import' && is_readable($packages->templateCsvPath()),
        ];
    }

    // ── Aktionen ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function handlePost(array $post, int $userId): array
    {
        $action = is_string($post['action'] ?? null) ? $post['action'] : '';

        try {
            $result = match ($action) {
                'save_entry' => $this->saveEntry($post),
                'trash_entry' => $this->repository->trashEntry((int) ($post['entry_id'] ?? 0), $userId)
                    + ['__redirect' => self::url(['section' => 'entries'])],
                'save_category' => $this->repository->saveCategory($post)
                    + ['__redirect' => self::url(['section' => 'categories'])],
                'delete_category' => $this->repository->deleteCategory((int) ($post['category_id'] ?? 0))
                    + ['__redirect' => self::url(['section' => 'categories'])],
                'save_settings' => $this->saveSettings($post),
                'import_package' => $this->withIndexRefresh(static fn (): array => StandardPackages::instance()->importPackage((string) ($post['package_key'] ?? '')))
                    + ['__redirect' => self::url(['section' => 'settings', 'tab' => 'import'])],
                'import_all_packages' => $this->withIndexRefresh(static fn (): array => StandardPackages::instance()->importAllPackages())
                    + ['__redirect' => self::url(['section' => 'settings', 'tab' => 'import'])],
                'hard_reset' => $this->hardReset(false),
                'hard_reset_import' => $this->hardReset(true),
                default => ['success' => false, 'error' => (string) __('knowledgebase.admin.error_unknown_action')],
            };
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('knowledgebase')->error('Knowledgebase-Aktion fehlgeschlagen.', ['action' => $action, 'exception' => $e->getMessage()]);
            $result = ['success' => false, 'error' => (string) __('knowledgebase.admin.error_action_failed')];
        }

        // Fehlermeldungen kommen bei einigen Diensten als `message` (z. B. Import ohne Treffer).
        if (empty($result['success']) && empty($result['error']) && !empty($result['message'])) {
            $result['error'] = (string) $result['message'];
        }

        return $result;
    }

    /** Liefert die Beispiel-CSV aus; false, wenn sie fehlt. */
    public function streamCsvTemplate(): bool
    {
        $path = StandardPackages::instance()->templateCsvPath();
        if ($path === '' || !is_readable($path)) {
            return false;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . StandardPackages::TEMPLATE_FILE_NAME . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        readfile($path);
        exit;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function saveEntry(array $post): array
    {
        $id = (int) ($post['entry_id'] ?? 0);
        $result = $this->repository->saveEntry($post, false);
        $savedId = (int) ($result['id'] ?? $id);
        if (empty($result['success'])) {
            // Eingaben für das erneut angezeigte Formular aufbewahren (nur skalare Formularfelder).
            $_SESSION[self::DRAFT_SESSION_KEY] = ['id' => $id] + array_map(
                'strval',
                array_filter($post, static fn (mixed $value, mixed $key): bool => is_string($key) && is_scalar($value) && !in_array($key, ['csrf_token', 'action'], true), ARRAY_FILTER_USE_BOTH)
            );
        }
        $result['__redirect'] = self::url(['section' => 'edit', 'id' => $savedId]);

        return $result;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function saveSettings(array $post): array
    {
        $tab = in_array($post['settings_tab'] ?? '', ['general', 'design'], true) ? (string) $post['settings_tab'] : 'general';
        // Das Repository speichert nur die Felder des abgeschickten Tabs (Checkboxen anderer Tabs bleiben erhalten).
        $post['redirect_section'] = $tab;

        return $this->repository->saveSettings($post) + ['__redirect' => self::url(['section' => 'settings', 'tab' => $tab])];
    }

    /**
     * CSV-Importe schreiben ohne Eintrags-Hooks; der Suchindex wird deshalb im Anschluss für alle
     * dabei geänderten Einträge aktualisiert.
     *
     * @param \Closure(): array<string, mixed> $import
     * @return array<string, mixed>
     */
    private function withIndexRefresh(\Closure $import): array
    {
        $since = (string) Database::instance()->get_var('SELECT DATE_SUB(NOW(), INTERVAL 1 SECOND)');
        $result = $import();
        try {
            $ids = (new KnowledgebaseContentProvider())->changedDocumentIds($since, date('Y-m-d H:i:s'), 5000);
            SearchIndexService::getInstance()->refreshMany(KnowledgebaseContentProvider::TYPE, $ids);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('knowledgebase')->warning('Suchindex nach CSV-Import nicht aktualisiert.', ['exception' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function hardReset(bool $reimport): array
    {
        $db = Database::instance();
        $ids = array_map('intval', $db->execute('SELECT id FROM ' . $db->getPrefix() . 'kb_entries')->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        $result = $this->repository->hardResetEntries();
        foreach ($ids as $id) {
            try {
                SearchIndexService::getInstance()->remove(KnowledgebaseContentProvider::TYPE, $id);
            } catch (\Throwable) {
                break;
            }
        }
        $redirect = self::url(['section' => 'settings', 'tab' => 'system']);
        if (!$reimport || empty($result['success'])) {
            return $result + ['__redirect' => $redirect];
        }

        $import = $this->withIndexRefresh(static fn (): array => StandardPackages::instance()->importAllPackages());

        return [
            'success' => !empty($import['success']),
            'message' => trim((string) ($result['message'] ?? '') . ' ' . (string) ($import['message'] ?? '')),
            'error' => empty($import['success']) ? trim((string) ($result['message'] ?? '') . ' ' . (string) ($import['error'] ?? $import['message'] ?? '')) : null,
            '__redirect' => $redirect,
        ];
    }
}
