<?php
/**
 * Formulareingänge: Speichern, Lesen, Status, Papierkorb, CSV, Aufbewahrung und Personenbezug (DSGVO).
 *
 * Ein Eingang speichert die Feldwerte als Momentaufnahme (`data_json`: Name, Beschriftung, Typ, Wert, Text),
 * damit spätere Änderungen am Formular alte Eingänge nicht verfälschen. IP-Adresse und User-Agent werden
 * nicht gespeichert; das Rate-Limit nutzt ausschließlich die kurzlebige Tabelle `login_attempts`.
 *
 * @package CMSv2\Core\Services\Forms
 */

declare(strict_types=1);

namespace CMS\Services\Forms;

use CMS\Database;

if (!defined('ABSPATH')) {
    exit;
}

final class FormSubmissionService
{
    public const STATUS_NEW = 'new';
    public const STATUS_READ = 'read';
    public const STATUS_DONE = 'done';
    public const STATUS_TRASH = 'trash';
    public const STATUSES = [self::STATUS_NEW, self::STATUS_READ, self::STATUS_DONE];

    public const RETENTION_RULE = 'forms.submissions';

    private static ?self $instance = null;

    private Database $db;
    private string $table;
    private string $filesTable;
    private string $formsTable;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $prefix = $this->db->getPrefix();
        $this->table = $prefix . 'form_submissions';
        $this->filesTable = $prefix . 'form_submission_files';
        $this->formsTable = $prefix . 'form_forms';
    }

    public function table(): string
    {
        return $this->table;
    }

    /**
     * Speichert einen geprüften Eingang.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $values Bereinigte Werte aus `FormValidator` (Dateien: Liste der Originalnamen)
     * @param array<string, mixed> $context user_id, locale, source_url, consent_text, privacy_url, status, created_at, legacy_id, meta
     */
    public function create(array $form, array $values, array $context = []): int
    {
        $locale = FormI18n::normalizeLocale($context['locale'] ?? FormI18n::siteLocale());
        $data = self::buildData($form['fields'] ?? [], $values, FormI18n::siteLocale());
        $summary = self::summary($form['fields'] ?? [], $values);
        $status = in_array($context['status'] ?? '', [...self::STATUSES, self::STATUS_TRASH], true) ? (string) $context['status'] : self::STATUS_NEW;
        $hasConsent = false;
        foreach ($form['fields'] ?? [] as $field) {
            if (($field['type'] ?? '') === 'consent' && ($values[$field['name']] ?? '') === '1') {
                $hasConsent = true;
            }
        }

        $meta = [
            'form_key' => (string) ($form['form_key'] ?? ''),
            'form_title' => (string) ($form['title'] ?? ''),
        ];
        if ($hasConsent) {
            $meta['consent'] = [
                'text' => (string) ($context['consent_text'] ?? ''),
                'privacy_url' => (string) ($context['privacy_url'] ?? FormSettings::privacyUrl()),
            ];
        }
        foreach (is_array($context['meta'] ?? null) ? $context['meta'] : [] as $key => $value) {
            $meta[(string) $key] = $value;
        }

        $createdAt = !empty($context['created_at']) && FormService::isDateTime((string) $context['created_at'])
            ? (string) $context['created_at']
            : date('Y-m-d H:i:s');
        $row = [
            'form_id' => (int) $form['id'],
            'status' => $status,
            'user_id' => !empty($context['user_id']) ? (int) $context['user_id'] : null,
            'sender_name' => self::cut($summary['sender_name'], 255),
            'sender_email' => self::cut($summary['sender_email'], 254),
            'subject' => self::cut($summary['subject'], 500),
            'data_json' => FormService::encode($data),
            'meta_json' => FormService::encode($meta),
            'locale' => $locale,
            'source_url' => self::cut(self::cleanSourceUrl((string) ($context['source_url'] ?? '')), 500),
            'consent_at' => $hasConsent ? (string) ($context['consent_at'] ?? $createdAt) : null,
            'created_at' => $createdAt,
            'updated_at' => (string) ($context['updated_at'] ?? $createdAt),
        ];
        foreach (['read_at', 'done_at', 'trashed_at', 'previous_status'] as $optional) {
            if (!empty($context[$optional])) {
                $row[$optional] = (string) $context[$optional];
            }
        }
        if (!empty($context['legacy_id'])) {
            $row['legacy_id'] = (int) $context['legacy_id'];
        }

        $id = $this->db->insert('form_submissions', $row);

        return $id ? (int) $id : 0;
    }

    /**
     * Momentaufnahme der Feldwerte.
     *
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed> $values
     * @return list<array{name: string, label: string, type: string, value: mixed, text: string}>
     */
    public static function buildData(array $fields, array $values, string $locale): array
    {
        $data = [];
        foreach ($fields as $field) {
            $type = (string) ($field['type'] ?? 'text');
            if (!FormFieldTypes::isInput($type)) {
                continue;
            }
            $name = (string) $field['name'];
            if (!array_key_exists($name, $values)) {
                continue;
            }
            $value = $values[$name];
            if ($type === 'file') {
                $value = array_values(array_map(static fn(mixed $file): string => is_array($file) ? (string) ($file['name'] ?? '') : (string) $file, is_array($value) ? $value : []));
            }

            $label = FormI18n::pick($field['label'] ?? [], $locale);
            if ($type === 'consent' && $label === '') {
                $label = FormI18n::t('forms.field.consent_label', $locale);
            }
            $data[] = [
                'name' => $name,
                'label' => $label !== '' ? $label : $name,
                'type' => $type,
                'value' => $value,
                'text' => self::displayValue($field, $value, $locale),
            ];
        }

        return $data;
    }

    /**
     * Lesbarer Text eines Feldwerts (Optionsbeschriftungen statt Werte, Ja/Nein für Häkchen).
     *
     * @param array<string, mixed> $field
     */
    public static function displayValue(array $field, mixed $value, string $locale): string
    {
        $type = (string) ($field['type'] ?? 'text');
        $labels = [];
        foreach ($field['options'] ?? [] as $option) {
            $labels[(string) $option['value']] = FormI18n::pick($option['label'], $locale);
        }

        if (is_array($value)) {
            $items = array_map(static fn(mixed $item): string => $labels[(string) $item] ?? (string) $item, $value);

            return implode(', ', array_filter($items, static fn(string $item): bool => $item !== ''));
        }
        $value = (string) $value;
        if ($type === 'checkbox' && ($field['options'] ?? []) === [] || $type === 'consent') {
            return FormI18n::t($value === '1' ? 'forms.value.yes' : 'forms.value.no', $locale);
        }

        return $labels[$value] ?? $value;
    }

    /**
     * Absender, Antwortadresse und Betreff aus den Feldrollen.
     *
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed> $values
     * @return array{sender_name: string, sender_email: string, subject: string, message: string}
     */
    public static function summary(array $fields, array $values): array
    {
        $pick = static function (string $role) use ($fields, $values): string {
            $field = FormFieldTypes::fieldByRole($fields, $role);
            if ($field === null) {
                return '';
            }
            $value = $values[$field['name']] ?? '';

            return is_array($value) ? '' : self::displayValue($field, $value, FormI18n::siteLocale());
        };

        $email = $pick('email');

        return [
            'sender_name' => $pick('name'),
            'sender_email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '',
            'subject' => $pick('subject'),
            'message' => $pick('message'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->db->get_row(
            "SELECT s.*, f.title AS form_title, f.form_key AS form_key
               FROM {$this->table} s LEFT JOIN {$this->formsTable} f ON f.id = s.form_id
              WHERE s.id = ? LIMIT 1",
            [$id]
        );
        if (!$row) {
            return null;
        }
        $submission = self::hydrate($row);
        $submission['files'] = $this->files($id);

        return $submission;
    }

    /**
     * @param array{form_id?: int, status?: string, search?: string, user_id?: int, ids?: list<int>, order?: string} $filter
     * @return list<array<string, mixed>>
     */
    public function list(array $filter = [], int $offset = 0, int $limit = 25): array
    {
        [$where, $params] = $this->buildWhere($filter);
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        $order = ($filter['order'] ?? '') === 'trashed' ? 's.trashed_at DESC, s.id DESC' : 's.created_at DESC, s.id DESC';
        $rows = $this->db->get_results(
            "SELECT s.*, f.title AS form_title, f.form_key AS form_key,
                    (SELECT COUNT(*) FROM {$this->filesTable} sf WHERE sf.submission_id = s.id) AS file_count
               FROM {$this->table} s LEFT JOIN {$this->formsTable} f ON f.id = s.form_id
              WHERE {$where}
              ORDER BY {$order}
              LIMIT {$limit} OFFSET {$offset}",
            $params
        ) ?: [];

        return array_map(static fn(object $row): array => self::hydrate($row), $rows);
    }

    /**
     * @param array{form_id?: int, status?: string, search?: string, user_id?: int, ids?: list<int>} $filter
     */
    public function count(array $filter = []): int
    {
        [$where, $params] = $this->buildWhere($filter);

        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->table} s WHERE {$where}", $params);
    }

    /**
     * Anzahl je Status (inklusive Papierkorb) – optional für ein Formular.
     *
     * @return array<string, int>
     */
    public function countByStatus(int $formId = 0): array
    {
        $counts = array_fill_keys([...self::STATUSES, self::STATUS_TRASH], 0);
        $sql = "SELECT status, COUNT(*) AS c FROM {$this->table}" . ($formId > 0 ? ' WHERE form_id = ?' : '') . ' GROUP BY status';
        foreach ($this->db->get_results($sql, $formId > 0 ? [$formId] : []) ?: [] as $row) {
            $status = (string) $row->status;
            if (isset($counts[$status])) {
                $counts[$status] = (int) $row->c;
            }
        }

        return $counts;
    }

    /**
     * Anzahl Eingänge je Formular (ohne Papierkorb) und davon neue.
     *
     * @return array<int, array{total: int, new: int}>
     */
    public function countsPerForm(): array
    {
        $result = [];
        $rows = $this->db->get_results(
            "SELECT form_id, COUNT(*) AS total, SUM(status = 'new') AS new_count
               FROM {$this->table} WHERE status <> 'trash' GROUP BY form_id"
        ) ?: [];
        foreach ($rows as $row) {
            $result[(int) $row->form_id] = ['total' => (int) $row->total, 'new' => (int) $row->new_count];
        }

        return $result;
    }

    public function countNew(): int
    {
        try {
            return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->table} WHERE status = 'new'");
        } catch (\Throwable) {
            return 0;
        }
    }

    public function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $sql = match ($status) {
            self::STATUS_NEW => "UPDATE {$this->table} SET status = 'new', read_at = NULL, done_at = NULL, updated_at = ? WHERE id = ? AND status <> 'trash'",
            self::STATUS_READ => "UPDATE {$this->table} SET status = 'read', read_at = COALESCE(read_at, ?), done_at = NULL WHERE id = ? AND status <> 'trash'",
            self::STATUS_DONE => "UPDATE {$this->table} SET status = 'done', read_at = COALESCE(read_at, ?), done_at = NOW() WHERE id = ? AND status <> 'trash'",
        };

        return $this->db->execute($sql, [$now, $id])->rowCount() > 0;
    }

    /** Beim Öffnen im Admin: neu → gelesen. */
    public function markRead(int $id): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET status = 'read', read_at = COALESCE(read_at, NOW()) WHERE id = ? AND status = 'new'",
            [$id]
        );
    }

    public function trash(int $id, int $userId): bool
    {
        return $this->db->execute(
            "UPDATE {$this->table}
                SET previous_status = status, status = 'trash', trashed_at = NOW(), trashed_by = ?
              WHERE id = ? AND status <> 'trash'",
            [$userId > 0 ? $userId : null, $id]
        )->rowCount() > 0;
    }

    public function restore(int $id): bool
    {
        return $this->db->execute(
            "UPDATE {$this->table}
                SET status = CASE WHEN previous_status IN ('new', 'read', 'done') THEN previous_status ELSE 'read' END,
                    previous_status = NULL, trashed_at = NULL, trashed_by = NULL
              WHERE id = ? AND status = 'trash'",
            [$id]
        )->rowCount() > 0;
    }

    /**
     * Endgültig löschen inklusive hochgeladener Dateien.
     */
    public function deletePermanently(int $id): bool
    {
        $uploads = FormUploadService::getInstance();
        foreach ($this->files($id) as $file) {
            $uploads->deleteStoredFile($file);
        }

        return $this->db->execute("DELETE FROM {$this->table} WHERE id = ?", [$id])->rowCount() > 0;
    }

    /**
     * @param list<int> $ids
     */
    public function deleteMany(array $ids): int
    {
        $deleted = 0;
        foreach (array_unique(array_filter(array_map('intval', $ids))) as $id) {
            if ($this->deletePermanently($id)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public function deleteAllForForm(int $formId): int
    {
        $deleted = 0;
        do {
            $ids = array_map('intval', $this->db->get_col("SELECT id FROM {$this->table} WHERE form_id = ? LIMIT 200", [$formId]) ?: []);
            $deleted += $this->deleteMany($ids);
        } while ($ids !== []);

        return $deleted;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function files(int $submissionId): array
    {
        $rows = $this->db->get_results(
            "SELECT * FROM {$this->filesTable} WHERE submission_id = ? ORDER BY id ASC",
            [$submissionId]
        ) ?: [];

        return array_map(static fn(object $row): array => [
            'id' => (int) $row->id,
            'submission_id' => (int) $row->submission_id,
            'field_name' => (string) $row->field_name,
            'original_name' => (string) $row->original_name,
            'stored_name' => (string) $row->stored_name,
            'mime_type' => (string) $row->mime_type,
            'size_bytes' => (int) $row->size_bytes,
            'created_at' => (string) $row->created_at,
        ], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findFile(int $fileId): ?array
    {
        $row = $this->db->get_row("SELECT * FROM {$this->filesTable} WHERE id = ? LIMIT 1", [$fileId]);
        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'submission_id' => (int) $row->submission_id,
            'field_name' => (string) $row->field_name,
            'original_name' => (string) $row->original_name,
            'stored_name' => (string) $row->stored_name,
            'mime_type' => (string) $row->mime_type,
            'size_bytes' => (int) $row->size_bytes,
            'created_at' => (string) $row->created_at,
        ];
    }

    /**
     * Zeilen für den CSV-Export. Bei Filter auf ein Formular eine Spalte je Feld, sonst eine Inhaltsspalte.
     *
     * @param array{form_id?: int, status?: string, search?: string} $filter
     * @return array{columns: list<string>, rows: \Generator<int, array<string, string>>}
     */
    public function csvExport(array $filter): array
    {
        $base = [
            __('forms.csv.id'), __('forms.csv.form'), __('forms.csv.status'), __('forms.csv.created'),
            __('forms.csv.name'), __('forms.csv.email'), __('forms.csv.subject'),
        ];
        $fieldColumns = [];
        $formId = (int) ($filter['form_id'] ?? 0);
        if ($formId > 0) {
            $form = FormService::getInstance()->find($formId);
            foreach ($form['fields'] ?? [] as $field) {
                if (FormFieldTypes::isInput((string) $field['type'])) {
                    $label = FormI18n::pick($field['label'], FormI18n::siteLocale());
                    $fieldColumns[(string) $field['name']] = $label !== '' ? $label : (string) $field['name'];
                }
            }
            // Spaltennamen eindeutig halten (gleiche Beschriftungen).
            $seen = [];
            foreach ($fieldColumns as $name => $label) {
                $column = isset($seen[$label]) || in_array($label, $base, true) ? $label . ' (' . $name . ')' : $label;
                $seen[$label] = true;
                $fieldColumns[$name] = $column;
            }
        }
        $contentColumn = __('forms.csv.content');
        $columns = [...$base, ...array_values($fieldColumns)];
        if ($fieldColumns === []) {
            $columns[] = $contentColumn;
        }

        $generator = (function () use ($filter, $base, $fieldColumns, $contentColumn): \Generator {
            $offset = 0;
            do {
                $batch = $this->list($filter, $offset, 500);
                foreach ($batch as $submission) {
                    $row = array_combine($base, [
                        (string) $submission['id'],
                        (string) $submission['form_title'],
                        self::statusLabel((string) $submission['status']),
                        (string) $submission['created_at'],
                        (string) $submission['sender_name'],
                        (string) $submission['sender_email'],
                        (string) $submission['subject'],
                    ]);
                    $byName = [];
                    $lines = [];
                    foreach ($submission['data'] as $entry) {
                        $byName[(string) $entry['name']] = (string) ($entry['text'] ?? '');
                        $lines[] = $entry['label'] . ': ' . ($entry['text'] ?? '');
                    }
                    if ($fieldColumns !== []) {
                        foreach ($fieldColumns as $name => $column) {
                            $row[$column] = $byName[$name] ?? '';
                        }
                    } else {
                        $row[$contentColumn] = implode("\n", $lines);
                    }
                    yield $row;
                }
                $offset += count($batch);
            } while (count($batch) === 500);
        })();

        return ['columns' => $columns, 'rows' => $generator];
    }

    public static function statusLabel(string $status): string
    {
        return in_array($status, [...self::STATUSES, self::STATUS_TRASH], true) ? __('forms.status.' . $status) : $status;
    }

    // ── Aufbewahrung ─────────────────────────────────────────────────────────

    /**
     * Wirksame Aufbewahrung eines Formulars in Tagen (0 = unbegrenzt).
     *
     * @param array<string, mixed> $form
     */
    public static function effectiveRetentionDays(array $form): int
    {
        $own = $form['settings']['retention_days'] ?? null;

        return $own === null ? FormSettings::retentionDays() : max(0, (int) $own);
    }

    /**
     * Fällige Eingänge je Formular: [form_id => Stichtag].
     *
     * @return array<int, string>
     */
    public function retentionCutoffs(): array
    {
        $cutoffs = [];
        foreach (FormService::getInstance()->list(['include_trash' => true], 0, 500) as $form) {
            $days = self::effectiveRetentionDays($form);
            if ($days > 0) {
                $cutoffs[(int) $form['id']] = date('Y-m-d H:i:s', time() - $days * 86400);
            }
        }

        return $cutoffs;
    }

    public function countDue(): int
    {
        $total = 0;
        foreach ($this->retentionCutoffs() as $formId => $cutoff) {
            $total += (int) $this->db->get_var(
                "SELECT COUNT(*) FROM {$this->table} WHERE form_id = ? AND created_at < ?",
                [$formId, $cutoff]
            );
        }

        return $total;
    }

    /**
     * Löscht fällige Eingänge (alle Status inklusive Papierkorb) samt Dateien.
     */
    public function pruneDue(int $limit = 500): int
    {
        $deleted = 0;
        foreach ($this->retentionCutoffs() as $formId => $cutoff) {
            while ($deleted < $limit) {
                $batch = min(100, $limit - $deleted);
                $ids = array_map('intval', $this->db->get_col(
                    "SELECT id FROM {$this->table} WHERE form_id = ? AND created_at < ? ORDER BY id ASC LIMIT {$batch}",
                    [$formId, $cutoff]
                ) ?: []);
                if ($ids === []) {
                    break;
                }
                $deleted += $this->deleteMany($ids);
            }
            if ($deleted >= $limit) {
                break;
            }
        }

        return $deleted;
    }

    // ── Personenbezug (DSGVO, Mitgliederbereich) ─────────────────────────────

    /**
     * Eingänge einer Person: Konto-ID oder E-Mail-Adresse (ohne Beachtung der Groß-/Kleinschreibung).
     *
     * @return list<array<string, mixed>>
     */
    public function findForPerson(int $userId, string $email, int $limit = 5000): array
    {
        [$where, $params] = $this->personWhere($userId, $email);
        if ($where === '') {
            return [];
        }
        $limit = max(1, min(10000, $limit));
        $rows = $this->db->get_results(
            "SELECT s.*, f.title AS form_title, f.form_key AS form_key
               FROM {$this->table} s LEFT JOIN {$this->formsTable} f ON f.id = s.form_id
              WHERE {$where} ORDER BY s.created_at ASC, s.id ASC LIMIT {$limit}",
            $params
        ) ?: [];

        $result = [];
        foreach ($rows as $row) {
            $submission = self::hydrate($row);
            $submission['files'] = $this->files((int) $submission['id']);
            $result[] = $submission;
        }

        return $result;
    }

    public function countForPerson(int $userId, string $email): int
    {
        [$where, $params] = $this->personWhere($userId, $email);

        return $where === '' ? 0 : (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->table} s WHERE {$where}", $params);
    }

    public function deleteForPerson(int $userId, string $email): int
    {
        [$where, $params] = $this->personWhere($userId, $email);
        if ($where === '') {
            return 0;
        }

        $deleted = 0;
        do {
            $ids = array_map('intval', $this->db->get_col("SELECT s.id FROM {$this->table} s WHERE {$where} LIMIT 200", $params) ?: []);
            $count = $this->deleteMany($ids);
            $deleted += $count;
        } while ($ids !== [] && $count > 0);

        return $deleted;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId, int $limit = 50): array
    {
        return $userId > 0 ? $this->list(['user_id' => $userId], 0, $limit) : [];
    }

    public function deleteForUser(int $id, int $userId): bool
    {
        $owner = (int) $this->db->get_var("SELECT user_id FROM {$this->table} WHERE id = ? LIMIT 1", [$id]);

        return $userId > 0 && $owner === $userId && $this->deletePermanently($id);
    }

    /**
     * @return array<string, mixed>
     */
    public static function hydrate(object|array $row): array
    {
        $row = (array) $row;
        $data = json_decode((string) ($row['data_json'] ?? '[]'), true);
        $meta = json_decode((string) ($row['meta_json'] ?? '{}'), true);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'form_id' => (int) ($row['form_id'] ?? 0),
            'form_title' => (string) ($row['form_title'] ?? ($meta['form_title'] ?? '')),
            'form_key' => (string) ($row['form_key'] ?? ($meta['form_key'] ?? '')),
            'status' => (string) ($row['status'] ?? self::STATUS_NEW),
            'previous_status' => isset($row['previous_status']) ? (string) $row['previous_status'] : null,
            'trashed_at' => isset($row['trashed_at']) ? (string) $row['trashed_at'] : null,
            'trashed_by' => isset($row['trashed_by']) ? (int) $row['trashed_by'] : null,
            'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
            'sender_name' => (string) ($row['sender_name'] ?? ''),
            'sender_email' => (string) ($row['sender_email'] ?? ''),
            'subject' => (string) ($row['subject'] ?? ''),
            'data' => is_array($data) ? array_values(array_filter($data, 'is_array')) : [],
            'meta' => is_array($meta) ? $meta : [],
            'locale' => (string) ($row['locale'] ?? 'de'),
            'source_url' => (string) ($row['source_url'] ?? ''),
            'consent_at' => isset($row['consent_at']) ? (string) $row['consent_at'] : null,
            'read_at' => isset($row['read_at']) ? (string) $row['read_at'] : null,
            'done_at' => isset($row['done_at']) ? (string) $row['done_at'] : null,
            'legacy_id' => isset($row['legacy_id']) ? (int) $row['legacy_id'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'file_count' => (int) ($row['file_count'] ?? 0),
        ];
    }

    /**
     * @param array{form_id?: int, status?: string, search?: string, user_id?: int, ids?: list<int>} $filter
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(array $filter): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filter['form_id'])) {
            $where[] = 's.form_id = ?';
            $params[] = (int) $filter['form_id'];
        }
        $status = (string) ($filter['status'] ?? '');
        if (in_array($status, [...self::STATUSES, self::STATUS_TRASH], true)) {
            $where[] = 's.status = ?';
            $params[] = $status;
        } elseif ($status !== 'any') {
            $where[] = "s.status <> 'trash'";
        }
        if (!empty($filter['user_id'])) {
            $where[] = 's.user_id = ?';
            $params[] = (int) $filter['user_id'];
        }
        if (!empty($filter['ids']) && is_array($filter['ids'])) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $filter['ids']))));
            if ($ids === []) {
                $where[] = '1=0';
            } else {
                $where[] = 's.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                array_push($params, ...$ids);
            }
        }
        $search = trim((string) ($filter['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(s.sender_name LIKE ? OR s.sender_email LIKE ? OR s.subject LIKE ? OR s.data_json LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function personWhere(int $userId, string $email): array
    {
        $email = trim($email);
        $parts = [];
        $params = [];
        if ($userId > 0) {
            $parts[] = 's.user_id = ?';
            $params[] = $userId;
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $parts[] = 'LOWER(s.sender_email) = LOWER(?)';
            $params[] = $email;
        }

        return [$parts === [] ? '' : '(' . implode(' OR ', $parts) . ')', $params];
    }

    private static function cleanSourceUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $path = (string) parse_url($url, PHP_URL_PATH);

        return FormFieldTypes::isSafeUrl($path) ? $path : '';
    }

    private static function cut(string $value, int $max): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }
}
