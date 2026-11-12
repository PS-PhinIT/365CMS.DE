<?php
/**
 * Papierkorb-Aktionen der Inhaltslisten (Beiträge, Seiten, Hub-Sites): in den Papierkorb verschieben,
 * wiederherstellen, endgültig löschen und leeren. Liefert das Ergebnisformat der Admin-Module
 * (`success`, `message`/`error`, `details`, `type`) und merkt Einträge mit Warnungen für die ausdrückliche
 * Bestätigung vor (`TrashConfirmationStore`).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Trash;

if (!defined('ABSPATH')) {
    exit;
}

final class TrashListActions
{
    public function __construct(
        private readonly \CMS\Services\ContentTypes\TrashProvider $provider,
        private readonly string $confirmationContext
    ) {
    }

    /**
     * @param list<int> $ids
     * @return array<string, mixed>
     */
    public function trash(array $ids, int $userId): array
    {
        return $this->run(TrashService::ACTION_TRASH, $ids, $userId, false, 'trash.list.result.trashed');
    }

    /**
     * @param list<int> $ids
     * @return array<string, mixed>
     */
    public function restore(array $ids, int $userId): array
    {
        return $this->run(TrashService::ACTION_RESTORE, $ids, $userId, false, 'trash.list.result.restored');
    }

    /**
     * @param list<int> $ids
     * @return array<string, mixed>
     */
    public function delete(array $ids, int $userId, bool $confirmed): array
    {
        return $this->run(TrashService::ACTION_DELETE, $ids, $userId, $confirmed, 'trash.list.result.deleted');
    }

    /**
     * Löscht die übergebenen Papierkorb-Einträge (bereits nach Berechtigung gefiltert, höchstens
     * `TrashService::EMPTY_BATCH_LIMIT`) und protokolliert das Leeren.
     *
     * @param list<int>      $ids
     * @param callable(): int $remaining Anzahl der danach noch sichtbaren Papierkorb-Einträge
     * @return array<string, mixed>
     */
    public function emptyTrash(array $ids, int $userId, bool $confirmed, callable $remaining, string $scope = 'all'): array
    {
        if ($ids === []) {
            return ['success' => false, 'error' => TrashSupport::t('trash.list.result.nothing_to_empty'), 'type' => 'info'];
        }

        $summary = ['done' => 0, 'failed' => 0, 'messages' => [], 'confirmations' => []];
        foreach (array_chunk(array_slice($ids, 0, TrashService::EMPTY_BATCH_LIMIT), TrashService::BULK_LIMIT) as $chunk) {
            $part = TrashService::getInstance()->bulk($this->provider, TrashService::ACTION_DELETE, $chunk, $userId, $confirmed);
            $summary['done'] += $part['done'];
            $summary['failed'] += $part['failed'];
            $summary['messages'] = array_slice(array_merge($summary['messages'], $part['messages']), 0, 10);
            $summary['confirmations'] += $part['confirmations'];
        }

        $left = max(0, $remaining());
        TrashService::getInstance()->auditEmpty($this->provider, $summary, $left, $confirmed, $userId, $scope);

        $result = $this->toResult($summary, 'trash.list.result.deleted', count($ids) === 1);
        $pending = $left - $summary['failed'] - count($summary['confirmations']);
        if ($pending > 0) {
            $result['message'] = trim((string) ($result['message'] ?? '') . ' ' . TrashSupport::t('trash.list.result.remaining', $pending));
            if (($result['type'] ?? '') === 'success') {
                $result['type'] = 'warning';
            }
        }

        return $result;
    }

    /**
     * @param list<int> $ids
     * @return array<string, mixed>
     */
    private function run(string $action, array $ids, int $userId, bool $confirmed, string $summaryKey): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return ['success' => false, 'error' => TrashSupport::t('trash.error.not_found')];
        }

        $summary = TrashService::getInstance()->bulk($this->provider, $action, $ids, $userId, $confirmed);

        return $this->toResult($summary, $summaryKey, count($ids) === 1);
    }

    /**
     * @param array{done:int,failed:int,messages:list<string>,confirmations:array<int,array{title:string,warnings:list<string>}>} $summary
     * @return array<string, mixed>
     */
    private function toResult(array $summary, string $summaryKey, bool $single): array
    {
        $done = (int) $summary['done'];
        $failed = (int) $summary['failed'];
        $confirmations = $summary['confirmations'];
        $messages = $summary['messages'];

        if ($confirmations !== []) {
            TrashConfirmationStore::remember($this->confirmationContext, $this->provider->type(), $confirmations);
        }

        if ($single && $confirmations === []) {
            $message = $messages[0] ?? TrashSupport::t($summaryKey, $done);
            if ($done === 1) {
                return ['success' => true, 'message' => $message, 'type' => 'success'];
            }

            return ['success' => false, 'error' => $message, 'type' => 'danger'];
        }

        $parts = [];
        if ($done > 0 || ($failed === 0 && $confirmations === [])) {
            $parts[] = TrashSupport::t($summaryKey, $done);
        }
        if ($failed > 0) {
            $parts[] = TrashSupport::t('trash.list.result.failed', $failed);
        }
        if ($confirmations !== []) {
            $parts[] = TrashSupport::t('trash.list.result.needs_confirmation', count($confirmations));
        }
        $message = implode(' ', $parts);

        // Einzelmeldungen nur bei Fehlern bzw. beim Wiederherstellen (z. B. geänderter Slug).
        $details = $failed > 0 || $summaryKey === 'trash.list.result.restored' ? $messages : [];

        $type = 'success';
        if ($failed > 0 || $confirmations !== []) {
            $type = $done > 0 || $confirmations !== [] ? 'warning' : 'danger';
        }

        $result = [
            'success' => $done > 0 || $confirmations !== [],
            'message' => $message,
            'details' => $details,
            'type' => $type,
        ];
        if (!$result['success']) {
            $result['error'] = $message;
        }

        return $result;
    }
}
