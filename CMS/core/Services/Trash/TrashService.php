<?php
/**
 * Zentrale Papierkorb-Logik über alle registrierten Inhaltstyp-Provider.
 *
 * Grundlage ist `ContentTypeRegistry::trashProviders()`: Typen, die später von weiteren Bereichen
 * registriert werden (Downloads, Formulare …), erscheinen ohne Codeänderung in der zentralen Seite,
 * in Sammelaktionen und im automatischen Leeren. Sichtbar ist ein Typ, wenn der Benutzer
 * `manage_trash` und die Capability des Providers besitzt.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Trash;

use CMS\AuditLogger;
use CMS\Auth;
use CMS\Logger;
use CMS\Services\ContentTypes\ContentTypeRegistry;
use CMS\Services\ContentTypes\TrashProvider;
use CMS\Services\ContentTypes\TrashResult;
use CMS\Services\SettingsService;

if (!defined('ABSPATH')) {
    exit;
}

final class TrashService
{
    public const CAPABILITY = 'manage_trash';

    public const SETTINGS_GROUP = 'trash';

    public const RETENTION_KEY = 'retention_days';

    public const DEFAULT_RETENTION_DAYS = 30;

    public const MAX_RETENTION_DAYS = 3650;

    /** Höchstzahl endgültiger Löschungen je Provider und Cron-Lauf (der nächste Lauf setzt fort). */
    public const PURGE_BATCH_LIMIT = 200;

    /** Höchstzahl endgültiger Löschungen je Anfrage beim Leeren eines Typs. */
    public const EMPTY_BATCH_LIMIT = 500;

    /** Höchstzahl Einträge je Sammelaktion. */
    public const BULK_LIMIT = 200;

    public const ACTION_RESTORE = 'restore';

    public const ACTION_DELETE = 'delete_permanently';

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * @return array<string, TrashProvider>
     */
    public function providers(): array
    {
        return ContentTypeRegistry::getInstance()->trashProviders();
    }

    public function provider(string $type): ?TrashProvider
    {
        return ContentTypeRegistry::getInstance()->trashProvider($type);
    }

    public function canManage(?TrashProvider $provider = null): bool
    {
        $auth = Auth::instance();
        if (!$auth->isLoggedIn() || !$auth->hasCapability(self::CAPABILITY)) {
            return false;
        }

        return $provider === null || $auth->hasCapability($provider->capability());
    }

    /**
     * Provider, die der aktuelle Benutzer im zentralen Papierkorb sieht.
     *
     * @return array<string, TrashProvider>
     */
    public function visibleProviders(): array
    {
        if (!$this->canManage()) {
            return [];
        }

        return array_filter($this->providers(), fn (TrashProvider $provider): bool => $this->canManage($provider));
    }

    public function retentionDays(): int
    {
        $days = SettingsService::getInstance()->getInt(self::SETTINGS_GROUP, self::RETENTION_KEY, self::DEFAULT_RETENTION_DAYS);

        return self::clampRetentionDays($days);
    }

    public static function clampRetentionDays(int $days): int
    {
        return max(0, min(self::MAX_RETENTION_DAYS, $days));
    }

    /**
     * Speichert die Frist für das automatische Leeren (0 = nie) und protokolliert die Änderung.
     */
    public function saveRetentionDays(int $days, int $userId): int
    {
        $previous = $this->retentionDays();
        $days = self::clampRetentionDays($days);
        if (!SettingsService::getInstance()->set(self::SETTINGS_GROUP, self::RETENTION_KEY, $days)) {
            throw new \RuntimeException('Papierkorb-Frist konnte nicht gespeichert werden.');
        }

        if ($previous !== $days) {
            AuditLogger::instance()->log(
                AuditLogger::CAT_SETTING,
                'trash.retention_days',
                sprintf('Papierkorb: automatisches Leeren auf %s gesetzt.', $days > 0 ? $days . ' Tage' : 'nie'),
                'setting',
                null,
                ['previous' => $previous, 'days' => $days, 'user_id' => $userId]
            );
        }

        return $days;
    }

    /**
     * Wiederherstellen oder endgültig löschen für mehrere Einträge eines Typs.
     *
     * @param list<int> $ids
     * @return array{done:int,failed:int,messages:list<string>,confirmations:array<int,array{title:string,warnings:list<string>}>}
     */
    public function bulk(TrashProvider $provider, string $action, array $ids, int $userId, bool $confirmed = false): array
    {
        $summary = ['done' => 0, 'failed' => 0, 'messages' => [], 'confirmations' => []];
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0))), 0, self::BULK_LIMIT);

        foreach ($ids as $id) {
            $result = $action === self::ACTION_RESTORE
                ? $this->safely(static fn (): TrashResult => $provider->restore($id, $userId))
                : $this->safely(static fn (): TrashResult => $provider->deletePermanently($id, $userId, $confirmed));

            $this->collect($summary, $result, $id, $provider);
        }

        return $summary;
    }

    /**
     * Leert den Papierkorb eines Typs (höchstens EMPTY_BATCH_LIMIT Einträge je Aufruf). Einträge mit
     * Warnungen bleiben ohne Bestätigung erhalten und werden mit ihren Warnungen zurückgemeldet.
     *
     * @return array{done:int,failed:int,messages:list<string>,confirmations:array<int,array{title:string,warnings:list<string>}>,remaining:int}
     */
    public function emptyTrash(TrashProvider $provider, int $userId, bool $confirmed = false): array
    {
        $summary = ['done' => 0, 'failed' => 0, 'messages' => [], 'confirmations' => []];
        $offset = 0;
        $processed = 0;

        while ($processed < self::EMPTY_BATCH_LIMIT) {
            $items = $provider->listTrashed($offset, min(100, self::EMPTY_BATCH_LIMIT - $processed));
            if ($items === []) {
                break;
            }

            foreach ($items as $item) {
                $processed++;
                $result = $this->safely(static fn (): TrashResult => $provider->deletePermanently($item->id, $userId, $confirmed));
                if (!$result->ok) {
                    $offset++;
                }
                $this->collect($summary, $result, $item->id, $provider, $item->title);
            }
        }

        $summary['remaining'] = $provider->countTrashed();

        AuditLogger::instance()->log(
            AuditLogger::CAT_CONTENT,
            'trash.empty',
            sprintf('Papierkorb „%s“ geleert: %d endgültig gelöscht, %d übersprungen.', $provider->type(), $summary['done'], $summary['failed'] + count($summary['confirmations'])),
            'trash',
            null,
            [
                'type' => $provider->type(),
                'deleted' => $summary['done'],
                'failed' => $summary['failed'],
                'needs_confirmation' => array_keys($summary['confirmations']),
                'remaining' => $summary['remaining'],
                'confirmed' => $confirmed,
                'user_id' => $userId,
            ],
            'warning'
        );

        return $summary;
    }

    /**
     * Stichtag für das automatische Leeren; null, wenn es deaktiviert ist.
     */
    public function expiryThreshold(?\DateTimeImmutable $now = null): ?\DateTimeImmutable
    {
        $days = $this->retentionDays();
        if ($days <= 0) {
            return null;
        }

        return ($now ?? new \DateTimeImmutable('now'))->modify('-' . $days . ' days');
    }

    /**
     * Anzahl abgelaufener Einträge aller Typen (Trockenlauf der Aufbewahrungsregel).
     */
    public function countExpired(?\DateTimeImmutable $now = null): int
    {
        $threshold = $this->expiryThreshold($now);
        if ($threshold === null) {
            return 0;
        }

        $count = 0;
        foreach ($this->providers() as $type => $provider) {
            try {
                $count += count($provider->expiredIds($threshold, 1000));
            } catch (\Throwable $e) {
                $this->logFailure('count_expired', $type, $e);
            }
        }

        return $count;
    }

    /**
     * Automatisches Leeren (täglicher Cron über die Aufbewahrungsregel `trash`). Einträge mit Warnungen
     * (z. B. noch verwendete Medien, zugeordnete Rechtstexte) bleiben stehen.
     *
     * @return array{deleted:int,skipped:int,failed:int,types:array<string,array{deleted:int,skipped:int,failed:int}>}
     */
    public function purgeExpired(?\DateTimeImmutable $now = null): array
    {
        $report = ['deleted' => 0, 'skipped' => 0, 'failed' => 0, 'types' => []];
        $threshold = $this->expiryThreshold($now);
        if ($threshold === null) {
            return $report;
        }

        foreach ($this->providers() as $type => $provider) {
            $typeReport = ['deleted' => 0, 'skipped' => 0, 'failed' => 0];

            try {
                $ids = $provider->expiredIds($threshold, self::PURGE_BATCH_LIMIT);
            } catch (\Throwable $e) {
                $this->logFailure('expired_ids', $type, $e);
                $typeReport['failed']++;
                $ids = [];
            }

            foreach ($ids as $id) {
                $result = $this->safely(static fn (): TrashResult => $provider->deletePermanently((int) $id, 0, false));
                if ($result->ok) {
                    $typeReport['deleted']++;
                } elseif ($result->code === TrashResult::CODE_CONFIRMATION_REQUIRED) {
                    $typeReport['skipped']++;
                } else {
                    $typeReport['failed']++;
                }
            }

            if (array_sum($typeReport) > 0) {
                $report['types'][$type] = $typeReport;
                $report['deleted'] += $typeReport['deleted'];
                $report['skipped'] += $typeReport['skipped'];
                $report['failed'] += $typeReport['failed'];
            }
        }

        if ($report['types'] !== []) {
            AuditLogger::instance()->log(
                AuditLogger::CAT_CONTENT,
                'trash.auto_empty',
                sprintf(
                    'Papierkorb automatisch geleert (älter als %d Tage): %d gelöscht, %d mit Warnungen behalten, %d fehlgeschlagen.',
                    $this->retentionDays(),
                    $report['deleted'],
                    $report['skipped'],
                    $report['failed']
                ),
                'trash',
                null,
                $report + ['threshold' => $threshold->format('Y-m-d H:i:s')],
                $report['failed'] > 0 ? 'warning' : 'info'
            );
        }

        return $report;
    }

    /**
     * @param array{done:int,failed:int,messages:list<string>,confirmations:array<int,array{title:string,warnings:list<string>}>} $summary
     */
    private function collect(array &$summary, TrashResult $result, int $id, TrashProvider $provider, string $title = ''): void
    {
        if ($result->ok) {
            $summary['done']++;
            if ($result->message !== '' && count($summary['messages']) < 10) {
                $summary['messages'][] = $result->message;
            }

            return;
        }

        if ($result->code === TrashResult::CODE_CONFIRMATION_REQUIRED) {
            $warnings = is_array($result->data['warnings'] ?? null) ? array_values(array_map('strval', $result->data['warnings'])) : [];
            $summary['confirmations'][$id] = [
                'title' => $title !== '' ? $title : $this->titleFor($provider, $id),
                'warnings' => $warnings,
            ];

            return;
        }

        $summary['failed']++;
        if (count($summary['messages']) < 10) {
            $summary['messages'][] = ($title !== '' ? $title . ': ' : '#' . $id . ': ') . $result->message;
        }
    }

    private function titleFor(TrashProvider $provider, int $id): string
    {
        // Titel nur für Rückmeldungen; die Provider-Schnittstelle kennt keinen Einzelabruf.
        return '#' . $id . ' (' . $provider->label() . ')';
    }

    /**
     * @param callable(): TrashResult $operation
     */
    private function safely(callable $operation): TrashResult
    {
        try {
            return $operation();
        } catch (\Throwable $e) {
            $this->logFailure('operation', '', $e);

            return TrashResult::failure(TrashResult::CODE_ERROR, TrashSupport::t('trash.error.failed'));
        }
    }

    private function logFailure(string $operation, string $type, \Throwable $e): void
    {
        Logger::instance()->withChannel('trash')->error('Papierkorb-Vorgang fehlgeschlagen.', [
            'operation' => $operation,
            'type' => $type,
            'exception_class' => get_debug_type($e),
            'exception' => $e->getMessage(),
        ]);
    }
}
