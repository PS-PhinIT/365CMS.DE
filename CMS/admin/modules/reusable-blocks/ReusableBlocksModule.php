<?php
declare(strict_types=1);

/**
 * EditorJS Block Vorlagen – Admin-Modul (4.1.0, Bereich 3b): Liste, Anlegen/Bearbeiten (Editor.js DE/EN),
 * „Verwendet in“, Duplizieren, Löschen mit Warnung, Verlauf (die letzten 20 Fassungen).
 *
 * Datenzugriff ausschließlich über `CMS\Services\ReusableBlocks\ReusableBlockService`.
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\AuditLogger;
use CMS\Services\ReusableBlocks\ReusableBlockService;

final class ReusableBlocksModule
{
    public const ROUTE = '/admin/reusable-blocks';
    public const ACTIONS = ['save', 'duplicate', 'delete', 'restore_revision'];

    private ReusableBlockService $service;

    public function __construct(?ReusableBlockService $service = null)
    {
        $this->service = $service ?? ReusableBlockService::getInstance();
    }

    /**
     * @param array<string, string|int|null> $query
     */
    public static function url(array $query = []): string
    {
        $query = array_filter($query, static fn ($value): bool => $value !== null && $value !== '' && $value !== 0);

        return self::ROUTE . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /**
     * @return array<string, mixed>
     */
    public function getListData(string $search): array
    {
        return [
            'view' => 'list',
            'search' => $search,
            'blocks' => $this->service->all($search),
        ];
    }

    /**
     * @return array<string, mixed>|null null = Block existiert nicht
     */
    public function getEditData(int $id): ?array
    {
        if ($id <= 0) {
            return [
                'view' => 'edit',
                'is_new' => true,
                'block' => ['id' => 0, 'title' => '', 'content' => '', 'content_en' => null, 'updated_at' => ''],
                'usages' => [],
                'revisions' => [],
            ];
        }
        $block = $this->service->find($id);
        if ($block === null) {
            return null;
        }

        return [
            'view' => 'edit',
            'is_new' => false,
            'block' => $block,
            'usages' => $this->service->usages($id),
            'revisions' => $this->service->revisions($id),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function handlePost(array $post, int $userId): array
    {
        $action = is_string($post['action'] ?? null) ? (string) $post['action'] : '';
        if (!in_array($action, self::ACTIONS, true)) {
            return ['success' => false, 'error' => 'Unbekannte Aktion.'];
        }
        $id = ReusableBlockService::normalizeId($post['block_id'] ?? 0);
        $text = static fn (string $key): string => is_string($post[$key] ?? null) ? (string) $post[$key] : '';

        switch ($action) {
            case 'save':
                $result = $this->service->save($id, $text('title'), $text('content'), $text('content_en'), $userId);
                if (!empty($result['success'])) {
                    $this->audit($id > 0 ? 'reusable_block.update' : 'reusable_block.create', (int) $result['id'], $text('title'));
                    $result['__redirect'] = self::url(['action' => 'edit', 'id' => (int) $result['id']]);
                } else {
                    // Eingaben nicht verlieren: Editor mit den gesendeten Werten erneut anzeigen.
                    $data = $this->getEditData($id) ?? $this->getEditData(0);
                    $data['block']['title'] = $text('title');
                    $data['block']['content'] = $text('content');
                    $data['block']['content_en'] = $text('content_en');
                    $result['render_inline'] = true;
                    $result['runtime_context'] = ['section' => 'edit', 'data' => $data];
                }

                return $result;

            case 'duplicate':
                $result = $this->service->duplicate($id, $userId);
                if (!empty($result['success'])) {
                    $this->audit('reusable_block.duplicate', (int) $result['id'], '#' . $id);
                    $result['__redirect'] = self::url(['action' => 'edit', 'id' => (int) $result['id']]);
                }

                return $result;

            case 'delete':
                $result = $this->service->delete($id, $text('confirmed') === '1');
                if (!empty($result['success'])) {
                    $this->audit('reusable_block.delete', $id, '#' . $id);
                    $result['__redirect'] = self::url();
                } elseif (!empty($result['needs_confirmation'])) {
                    $result['type'] = 'warning';
                    $result['details'] = array_map(
                        static fn (array $usage): string => $usage['label'] . ': ' . $usage['title'],
                        (array) ($result['usages'] ?? [])
                    );
                    $result['__redirect'] = self::url(['action' => 'edit', 'id' => $id, 'confirm_delete' => 1]);
                }

                return $result;

            case 'restore_revision':
                $result = $this->service->restoreRevision($id, ReusableBlockService::normalizeId($post['revision_id'] ?? 0), $userId);
                if (!empty($result['success'])) {
                    $this->audit('reusable_block.restore', $id, '#' . $id);
                }
                $result['__redirect'] = self::url(['action' => 'edit', 'id' => $id]);

                return $result;
        }

        return ['success' => false, 'error' => 'Unbekannte Aktion.'];
    }

    private function audit(string $action, int $id, string $title): void
    {
        try {
            AuditLogger::instance()->log(
                AuditLogger::CAT_CONTENT,
                $action,
                sprintf('EditorJS Block Vorlage #%d %s', $id, $title),
                'reusable_blocks',
                $id,
                [],
                'info'
            );
        } catch (\Throwable) {
        }
    }
}
