<?php
/**
 * Ergebnis von `CommunityAccess::can()`: unveränderlich, ohne Ausnahmen für Ablehnungen.
 *
 * - `allowed`/`reason`/`retryAfter` laut Plan (PERMISSIONS-MODERATION §1).
 * - `initialStatus` nur bei `create_topic`/`reply` und erlaubt: `published` oder `pending` (Freigabepflicht ist keine
 *   Ablehnung, sondern ein Freigabepfad).
 * - `view` nur bei `read` auf einen Beitrag: `full` oder `placeholder` (ausgeblendet/gelöscht).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Services\Community\Support\Codes;

if (!defined('ABSPATH')) {
    exit;
}

final class AccessDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly ?int $retryAfter,
        public readonly ?string $initialStatus,
        public readonly ?string $view,
    ) {
    }

    public static function allow(?string $initialStatus = null, ?string $view = null): self
    {
        return new self(true, '', null, $initialStatus, $view);
    }

    public static function deny(string $reason, ?int $retryAfter = null): self
    {
        return new self(false, $reason, $retryAfter !== null ? max(1, $retryAfter) : null, null, null);
    }

    /** Dieselbe Entscheidung mit anderem Anfangsstatus (nur wenn erlaubt). */
    public function withInitialStatus(?string $status): self
    {
        return $this->allowed ? new self(true, '', null, $status, $this->view) : $this;
    }

    /** Dieselbe Entscheidung mit anderer Ansicht (nur wenn erlaubt). */
    public function withView(?string $view): self
    {
        return $this->allowed ? new self(true, '', null, $this->initialStatus, $view) : $this;
    }

    /** HTTP-Status für die Oberfläche: 200 bei erlaubt, sonst laut Codes::ACCESS_REASONS (unbekannt: 403). */
    public function status(): int
    {
        return $this->allowed ? 200 : (Codes::ACCESS_REASONS[$this->reason] ?? 403);
    }

    /** Sprachschlüssel für die Anzeige des Grundes (leer bei erlaubt). */
    public function messageKey(): string
    {
        return $this->allowed ? '' : Codes::accessKey($this->reason);
    }
}
