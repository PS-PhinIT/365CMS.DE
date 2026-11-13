<?php
declare(strict_types=1);

/**
 * DeletionRequestsModule – DSGVO Art. 17 Löschanträge
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\AuditLogger;
use CMS\Services\MailQueueService;

class DeletionRequestsModule
{
    private const REQUEST_TYPE = 'deletion';
    private const MAX_AUDIT_STRING_LENGTH = 240;
    private const DEFAULT_DEADLINE_DAYS = 30;
    private const WARNING_BEFORE_DAYS = 7;

    private readonly \CMS\Database $db;
    private readonly string $prefix;

    public function __construct()
    {
        $this->db     = \CMS\Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        // Nutzt die gleiche Tabelle wie PrivacyRequests (type = 'deletion')
        $this->db->getPdo()->exec(
            "CREATE TABLE IF NOT EXISTS {$this->prefix}privacy_requests (
                id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                type          VARCHAR(20) NOT NULL DEFAULT 'export',
                user_id       INT UNSIGNED DEFAULT NULL,
                email         VARCHAR(255) NOT NULL,
                name          VARCHAR(255) DEFAULT NULL,
                status        VARCHAR(20) NOT NULL DEFAULT 'pending',
                reject_reason TEXT DEFAULT NULL,
                processed_at  DATETIME DEFAULT NULL,
                completed_at  DATETIME DEFAULT NULL,
                execute_after DATETIME DEFAULT NULL,
                created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_type   (type),
                INDEX idx_execute_after (execute_after)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        try {
            $column = $this->db->get_row("SHOW COLUMNS FROM {$this->prefix}privacy_requests LIKE 'execute_after'");

            if (!$column) {
                $this->db->getPdo()->exec(
                    "ALTER TABLE {$this->prefix}privacy_requests ADD COLUMN execute_after DATETIME DEFAULT NULL AFTER completed_at"
                );
                $this->db->getPdo()->exec(
                    "ALTER TABLE {$this->prefix}privacy_requests ADD INDEX idx_execute_after (execute_after)"
                );
            }
        } catch (\Throwable) {
        }
    }

    public function getData(): array
    {
        $requests = $this->db->get_results(
            "SELECT r.*, u.username FROM {$this->prefix}privacy_requests r
             LEFT JOIN {$this->prefix}users u ON r.user_id = u.id
             WHERE r.type = '" . self::REQUEST_TYPE . "'
             ORDER BY r.created_at DESC"
        ) ?: [];

        $pending = 0;
        $processing = 0;
        $completed = 0;
        $rejected = 0;
        $dueSoon = 0;
        $overdue = 0;
        $enrichedRequests = [];

        foreach ($requests as $r) {
            if ($r->status === 'pending') {
                $pending++;
            }
            if ($r->status === 'processing') {
                $processing++;
            }
            if ($r->status === 'completed') {
                $completed++;
            }
            if ($r->status === 'rejected') {
                $rejected++;
            }

            $row = (array)$r;
            $row['deadline'] = $this->buildDeadlineState($r);
            $row['email_unverified'] = in_array((string) $r->status, ['pending', 'processing'], true) && $this->hasUnverifiedAccountEmail($r);
            if (($row['deadline']['state'] ?? '') === 'due_soon') {
                $dueSoon++;
            }
            if (($row['deadline']['state'] ?? '') === 'overdue') {
                $overdue++;
            }
            $enrichedRequests[] = $row;
        }

        return [
            'requests' => $enrichedRequests,
            'stats'    => [
                'total'      => count($requests),
                'pending'    => $pending,
                'processing' => $processing,
                'completed'  => $completed,
                'rejected'   => $rejected,
                'open'       => $pending + $processing,
                'due_soon'   => $dueSoon,
                'overdue'    => $overdue,
                'deadline_days' => self::DEFAULT_DEADLINE_DAYS,
                'warning_before_days' => self::WARNING_BEFORE_DAYS,
                'admin_email' => $this->getAdminEmail(),
            ],
        ];
    }

    public function processRequest(int $id): array
    {
        if ($id <= 0) return ['success' => false, 'error' => 'Ungültige ID.'];
        $request = $this->getRequestById($id);
        if ($request === null) {
            return ['success' => false, 'error' => 'Löschantrag nicht gefunden.'];
        }

        $this->db->update('privacy_requests', [
            'status'       => 'processing',
            'processed_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'legal.data_request.deletion.process',
            'Löschantrag in Bearbeitung gesetzt',
            'privacy_request',
            $id,
            $this->buildAuditContext($request, ['status' => 'processing']),
            'info'
        );

        return ['success' => true, 'message' => 'Löschantrag wird geprüft.'];
    }

    public function executeDeletion(int $id, bool $includeUnverifiedEmail = false): array
    {
        if ($id <= 0) return ['success' => false, 'error' => 'Ungültige ID.'];

        $request = $this->getRequestById($id);
        if (!$request) {
            return ['success' => false, 'error' => 'Löschantrag nicht gefunden.'];
        }

        $executeAfter = trim((string)($request->execute_after ?? ''));
        if ($executeAfter !== '') {
            $executeTimestamp = strtotime($executeAfter);
            if ($executeTimestamp !== false && $executeTimestamp > time()) {
                return [
                    'success' => false,
                    'error' => 'Die Löschfrist ist noch nicht abgelaufen. Früheste Ausführung ab ' . date('d.m.Y H:i', $executeTimestamp) . '.',
                ];
            }
        }

        // Trigger DSGVO delete hook
        $matchEmail = '';
        $emailSkipped = false;
        if (class_exists('\CMS\Hooks')) {
            // Antrag aus einem Konto: Daten ohne Kontobezug nur über eine nachweislich bestätigte Adresse
            // mitlöschen, sonst träfe die Löschung fremde Eingänge/Abonnements. Reine E-Mail-Anträge
            // (ohne user_id) hat der Admin selbst geprüft; bei unbestätigter Konto-Adresse entscheidet er
            // ausdrücklich ($includeUnverifiedEmail).
            $matchEmail = (int) ($request->user_id ?? 0) > 0
                ? \CMS\Services\EmailVerificationService::getInstance()->matchableEmail((int) $request->user_id, (string) ($request->email ?? ''))
                : (string) ($request->email ?? '');
            $emailSkipped = $this->hasUnverifiedAccountEmail($request);
            if ($emailSkipped && $includeUnverifiedEmail) {
                $matchEmail = trim((string) $request->email);
                $emailSkipped = false;
            }
            \CMS\Hooks::doAction('dsgvo_delete_data', $request->user_id, $matchEmail);
        }

        // Benutzerkonto vollständig löschen wenn vorhanden
        if ($request->user_id) {
            try {
                $deleteResult = \CMS\Services\UserService::getInstance()->deleteUser((int)$request->user_id, true);
                if ($deleteResult instanceof \CMS\WP_Error) {
                    if (class_exists('\CMS\Logger')) {
                        \CMS\Logger::instance()->log('error', 'DSGVO-Löschung per UserService fehlgeschlagen für User ' . $request->user_id . ': ' . $deleteResult->get_error_message());
                    }

                    return ['success' => false, 'error' => 'Löschung fehlgeschlagen. Bitte Logs prüfen und den Vorgang erneut versuchen.'];
                }
            } catch (\Throwable $e) {
                if (class_exists('\CMS\Logger')) {
                    \CMS\Logger::instance()->log('error', 'DSGVO-Löschung fehlgeschlagen für User ' . $request->user_id . ': ' . $e->getMessage());
                }
                return ['success' => false, 'error' => 'Löschung fehlgeschlagen. Bitte Logs prüfen und den Vorgang erneut versuchen.'];
            }
        }

        $this->db->update('privacy_requests', [
            'status'       => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        AuditLogger::instance()->log(
            AuditLogger::CAT_USER,
            'legal.data_request.deletion.execute',
            'Löschantrag ausgeführt',
            'privacy_request',
            $id,
            $this->buildAuditContext($request, ['status' => 'completed', 'email_match' => $matchEmail !== '']),
            'warning'
        );

        return ['success' => true, 'message' => 'Löschung durchgeführt. Das Benutzerkonto wurde entfernt.'
            . ($emailSkipped ? ' Hinweis: Die Adresse des Kontos war nicht bestätigt – Daten ohne Kontobezug (z. B. Newsletter-Abonnement, anonyme Formulareingänge) wurden nicht gelöscht.' : '')];
    }

    public function rejectRequest(int $id, string $reason): array
    {
        if ($id <= 0) return ['success' => false, 'error' => 'Ungültige ID.'];
        $request = $this->getRequestById($id);
        if ($request === null) {
            return ['success' => false, 'error' => 'Löschantrag nicht gefunden.'];
        }

        $reason = trim(strip_tags($reason));
        if ($reason === '') {
            return ['success' => false, 'error' => 'Eine Begründung für die Ablehnung ist erforderlich.'];
        }

        $this->db->update('privacy_requests', [
            'status'        => 'rejected',
            'reject_reason' => $reason,
            'completed_at'  => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'legal.data_request.deletion.reject',
            'Löschantrag abgelehnt',
            'privacy_request',
            $id,
            $this->buildAuditContext($request, ['status' => 'rejected', 'reason' => $this->sanitizeAuditString($reason, 160)]),
            'warning'
        );

        return ['success' => true, 'message' => 'Löschantrag abgelehnt.'];
    }

    public function escalateRequest(int $id): array
    {
        if ($id <= 0) return ['success' => false, 'error' => 'Ungültige ID.'];
        $request = $this->getRequestById($id);
        if ($request === null) {
            return ['success' => false, 'error' => 'Löschantrag nicht gefunden.'];
        }

        $deadline = $this->buildDeadlineState($request);
        if (!in_array((string)($deadline['state'] ?? ''), ['due_soon', 'overdue'], true)) {
            return ['success' => false, 'error' => 'Dieser Antrag ist noch nicht im Eskalationsfenster.'];
        }

        $recipient = $this->getAdminEmail();
        if ($recipient === '') {
            return ['success' => false, 'error' => 'Keine gültige Admin-E-Mail für die Eskalation konfiguriert.'];
        }

        try {
            $result = MailQueueService::getInstance()->enqueuePlain(
                $recipient,
                '[365CMS] DSGVO-Löschantrag fristkritisch',
                $this->buildEscalationMailBody($request, $deadline),
                ['X-365CMS-Source' => 'legal-data-request-escalation'],
                null,
                'legal.data_requests.deadline'
            );
        } catch (\Throwable) {
            return ['success' => false, 'error' => 'Eskalationsmail konnte nicht in die Mail-Queue aufgenommen werden.'];
        }

        if (empty($result['success'])) {
            return ['success' => false, 'error' => (string)($result['error'] ?? 'Eskalationsmail konnte nicht queued werden.')];
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'legal.data_request.deletion.escalate',
            'Fristkritischer Löschantrag an Admin-Mail eskaliert',
            'privacy_request',
            $id,
            $this->buildAuditContext($request, ['deadline_state' => (string)($deadline['state'] ?? ''), 'queue_id' => (int)($result['id'] ?? 0)]),
            'warning'
        );

        return ['success' => true, 'message' => 'Eskalation wurde an die Admin-Mail-Queue übergeben.'];
    }

    public function deleteRequest(int $id): array
    {
        if ($id <= 0) return ['success' => false, 'error' => 'Ungültige ID.'];
        $request = $this->getRequestById($id);
        if ($request === null) {
            return ['success' => false, 'error' => 'Löschantrag nicht gefunden.'];
        }

        $this->db->delete('privacy_requests', ['id' => $id]);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'legal.data_request.deletion.delete',
            'Löschantrag gelöscht',
            'privacy_request',
            $id,
            $this->buildAuditContext($request, ['status' => 'deleted']),
            'warning'
        );

        return ['success' => true, 'message' => 'Antrag gelöscht.'];
    }

    /**
     * true, wenn der Antrag aus einem Konto stammt, dessen Adresse nicht nachweislich bestätigt ist. Daten ohne
     * Kontobezug (Newsletter-Abonnement, anonyme Formulareingänge) werden dann nur einbezogen, wenn der Admin
     * die Adresse geprüft hat und das ausdrücklich auswählt.
     */
    private function hasUnverifiedAccountEmail(object $request): bool
    {
        return (int) ($request->user_id ?? 0) > 0
            && trim((string) ($request->email ?? '')) !== ''
            && \CMS\Services\EmailVerificationService::getInstance()->matchableEmail((int) $request->user_id, (string) $request->email) === '';
    }

    private function getRequestById(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $request = $this->db->get_row(
            "SELECT * FROM {$this->prefix}privacy_requests WHERE id = ? AND type = ? LIMIT 1",
            [$id, self::REQUEST_TYPE]
        );

        return is_object($request) ? $request : null;
    }

    /** @return array<string, int|string|bool|null> */
    private function buildDeadlineState(object $request): array
    {
        $status = (string)($request->status ?? '');
        $createdAt = $this->parseDateTime((string)($request->created_at ?? ''));
        if ($createdAt === null) {
            return [
                'state' => 'unknown',
                'label' => 'Frist unbekannt',
                'due_at' => null,
                'days_remaining' => null,
                'is_closed' => in_array($status, ['completed', 'rejected'], true),
                'needs_escalation' => false,
            ];
        }

        $dueAt = $createdAt->modify('+' . self::DEFAULT_DEADLINE_DAYS . ' days');
        $now = new \DateTimeImmutable('now');
        $isClosed = in_array($status, ['completed', 'rejected'], true);
        $daysRemaining = (int)$now->setTime(0, 0)->diff($dueAt->setTime(0, 0))->format('%r%a');

        if ($isClosed) {
            $state = 'closed';
            $label = 'Abgeschlossen';
        } elseif ($daysRemaining < 0) {
            $state = 'overdue';
            $label = 'Überfällig seit ' . abs($daysRemaining) . ' Tag(en)';
        } elseif ($daysRemaining <= self::WARNING_BEFORE_DAYS) {
            $state = 'due_soon';
            $label = 'Fällig in ' . $daysRemaining . ' Tag(en)';
        } else {
            $state = 'ok';
            $label = 'Fällig in ' . $daysRemaining . ' Tag(en)';
        }

        return [
            'state' => $state,
            'label' => $label,
            'due_at' => $dueAt->format('Y-m-d H:i:s'),
            'days_remaining' => $daysRemaining,
            'is_closed' => $isClosed,
            'needs_escalation' => in_array($state, ['due_soon', 'overdue'], true),
        ];
    }

    private function parseDateTime(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function getAdminEmail(): string
    {
        $email = '';
        try {
            $value = $this->db->get_var(
                "SELECT option_value FROM {$this->prefix}settings WHERE option_name = 'admin_email' LIMIT 1"
            );
            $email = is_scalar($value) ? trim((string)$value) : '';
        } catch (\Throwable) {
            $email = '';
        }

        if ($email === '' && defined('ADMIN_EMAIL')) {
            $email = trim((string)ADMIN_EMAIL);
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /** @param array<string, int|string|bool|null> $deadline */
    private function buildEscalationMailBody(object $request, array $deadline): string
    {
        return implode("\n", [
            'Ein DSGVO-Löschantrag ist fristkritisch.',
            '',
            'Typ: Löschung nach Art. 17 DSGVO',
            'Anfrage-ID: ' . (int)($request->id ?? 0),
            'Status: ' . $this->sanitizeAuditString((string)($request->status ?? ''), 40),
            'Eingegangen: ' . $this->sanitizeAuditString((string)($request->created_at ?? ''), 80),
            'Pflichtfrist: ' . $this->sanitizeAuditString((string)($deadline['due_at'] ?? ''), 80),
            'Fristenlage: ' . $this->sanitizeAuditString((string)($deadline['label'] ?? ''), 120),
            '',
            'Bitte im Adminbereich unter /admin/data-requests prüfen und dokumentiert bearbeiten.',
        ]);
    }

    /** @param array<string, scalar|null> $extra */
    private function buildAuditContext(object $request, array $extra = []): array
    {
        return array_merge([
            'type' => self::REQUEST_TYPE,
            'user_id' => (int) ($request->user_id ?? 0),
            'email' => $this->sanitizeAuditString((string) ($request->email ?? ''), 160),
            'status' => $this->sanitizeAuditString((string) ($request->status ?? ''), 40),
        ], $extra);
    }

    private function sanitizeAuditString(string $value, int $maxLength = self::MAX_AUDIT_STRING_LENGTH): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($value)) ?? '';

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }
}
