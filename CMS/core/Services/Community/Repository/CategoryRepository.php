<?php
/**
 * Datenzugriff der Community-Kategorien (`community_categories`): oberste Gliederung der Startseite.
 *
 * Der deutsche Slug ist per Index eindeutig (`uq_community_categories_slug`); der englische Slug ist es nicht und fällt
 * beim Auflösen auf den deutschen zurück, wenn er fehlt.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Repository;

use CMS\Services\Community\Support\Transaction;

if (!defined('ABSPATH')) {
    exit;
}

final class CategoryRepository extends AbstractRepository
{
    private const TABLE = 'community_categories';

    /** Spalten, die `create()` setzen darf. */
    private const INSERTABLE = [
        'title', 'title_en', 'slug', 'slug_en', 'description', 'description_en', 'sort_order', 'is_visible',
    ];

    /**
     * Grenzen der Spalten laut Schema (`TESTS/schema-v27/golden.json`): `title`/`title_en` VARCHAR(150), Beschreibungen TEXT
     * (65.535 Bytes), `sort_order` INT UNSIGNED. Die Slugs prüft `validSlug()` (höchstens 160 Zeichen). Format: siehe
     * `AbstractRepository::exceeds()`.
     */
    private const LIMITS = [
        'title' => ['chars', 150, false], 'title_en' => ['chars', 150, true],
        'description' => ['bytes', 65535, true], 'description_en' => ['bytes', 65535, true],
        'sort_order' => ['uint'], 'is_visible' => ['flag'],
    ];

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM ' . $this->table(self::TABLE) . ' WHERE id = ? LIMIT 1', [$id]);
    }

    /**
     * Auflösen über den Slug: `de` über `slug`, `en` über `slug_en` (fehlt er, über `slug`).
     *
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug, string $locale = 'de'): ?array
    {
        $table = $this->table(self::TABLE);
        if ($locale === 'en') {
            return $this->fetchOne(
                "SELECT * FROM {$table} WHERE slug_en = ? OR (slug_en IS NULL AND slug = ?) ORDER BY id ASC LIMIT 1",
                [$slug, $slug]
            );
        }

        return $this->fetchOne("SELECT * FROM {$table} WHERE slug = ? LIMIT 1", [$slug]);
    }

    /**
     * Sichtbare Kategorien in Anzeigereihenfolge (`idx_community_categories_sort`).
     *
     * @return list<array<string, mixed>>
     */
    public function listVisible(): array
    {
        return $this->fetchAll('SELECT * FROM ' . $this->table(self::TABLE) . ' WHERE is_visible = 1 ORDER BY sort_order ASC, id ASC');
    }

    /**
     * Legt eine Kategorie an. Fachfehler: `invalid_input` (Slug leer oder ungültig, Text zu lang, Zahl außerhalb des Bereichs der
     * Spalte), `slug_taken`.
     *
     * @param array<string, mixed> $data Spalten aus INSERTABLE
     * @return array{success: bool, id?: int, error?: string}
     */
    public function create(array $data, string $nowUtc): array
    {
        $data = self::pick($data, self::INSERTABLE);
        $data['slug_en'] = ($data['slug_en'] ?? '') === '' ? null : $data['slug_en'];
        if (!self::validSlug((string) ($data['slug'] ?? '')) || ($data['slug_en'] !== null && !self::validSlug((string) $data['slug_en'])) || self::exceeds($data, self::LIMITS)) {
            return ['success' => false, 'error' => 'invalid_input'];
        }
        if (array_key_exists('is_visible', $data)) {
            $data['is_visible'] = !empty($data['is_visible']) ? 1 : 0;
        }
        if ($this->slugTaken((string) $data['slug'], null)) {
            return ['success' => false, 'error' => 'slug_taken'];
        }
        $data['created_at'] = $nowUtc;
        $data['updated_at'] = $nowUtc;
        try {
            $id = $this->insertRow(self::TABLE, $data, [...self::INSERTABLE, 'created_at', 'updated_at']);
        } catch (\PDOException $e) {
            if (self::isDuplicateKey($e)) {
                return ['success' => false, 'error' => 'slug_taken'];
            }
            throw $e;
        }

        return ['success' => true, 'id' => $id];
    }

    /**
     * Ändert Spalten aus INSERTABLE. Fachfehler: `invalid_input` (unbekannte ID, ungültiger Slug, Text zu lang, Zahl außerhalb des
     * Bereichs der Spalte), `slug_taken`.
     *
     * @param array<string, mixed> $data
     * @return array{success: bool, error?: string}
     */
    public function update(int $id, array $data, string $nowUtc): array
    {
        $data = self::pick($data, self::INSERTABLE);
        if ($data === [] || $this->find($id) === null) {
            return ['success' => false, 'error' => 'invalid_input'];
        }
        if (array_key_exists('slug_en', $data) && $data['slug_en'] === '') {
            $data['slug_en'] = null;
        }
        if ((isset($data['slug']) && !self::validSlug((string) $data['slug']))
            || (isset($data['slug_en']) && !self::validSlug((string) $data['slug_en']))
            || self::exceeds($data, self::LIMITS)) {
            return ['success' => false, 'error' => 'invalid_input'];
        }
        if (array_key_exists('is_visible', $data)) {
            $data['is_visible'] = !empty($data['is_visible']) ? 1 : 0;
        }
        if (isset($data['slug']) && $this->slugTaken((string) $data['slug'], $id)) {
            return ['success' => false, 'error' => 'slug_taken'];
        }
        $data['updated_at'] = $nowUtc;
        $set = implode(', ', array_map(static fn (string $column): string => $column . ' = ?', array_keys($data)));
        try {
            $this->run('UPDATE ' . $this->table(self::TABLE) . " SET {$set} WHERE id = ?", [...array_values($data), $id]);
        } catch (\PDOException $e) {
            if (self::isDuplicateKey($e)) {
                return ['success' => false, 'error' => 'slug_taken'];
            }
            throw $e;
        }

        return ['success' => true];
    }

    /**
     * Löscht eine Kategorie ohne Foren (Foren vorher löschen oder in eine andere Kategorie verschieben). Fachfehler:
     * `invalid_input` (unbekannte ID), `invalid_state` (enthält Foren).
     *
     * @return array{success: bool, error?: string}
     */
    public function delete(int $id): array
    {
        return Transaction::run($this->db, function () use ($id): array {
            if ($this->fetchOne('SELECT id FROM ' . $this->table(self::TABLE) . ' WHERE id = ? LIMIT 1' . self::forUpdate(), [$id]) === null) {
                return ['success' => false, 'error' => 'invalid_input'];
            }
            if ((int) $this->fetchValue('SELECT COUNT(*) FROM ' . $this->table('community_forums') . ' WHERE category_id = ?', [$id]) > 0) {
                return ['success' => false, 'error' => 'invalid_state'];
            }
            $this->run('DELETE FROM ' . $this->table(self::TABLE) . ' WHERE id = ?', [$id]);

            return ['success' => true];
        });
    }

    private function slugTaken(string $slug, ?int $exceptId): bool
    {
        $sql = 'SELECT id FROM ' . $this->table(self::TABLE) . ' WHERE slug = ?';
        $params = [$slug];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        return $this->fetchOne($sql . ' LIMIT 1', $params) !== null;
    }
}
