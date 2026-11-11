<?php
/**
 * Exportfilter, den die Export/Import-Funktion an jeden `ExchangeProvider` weitergibt.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\ContentTypes;

if (!defined('ABSPATH')) {
    exit;
}

final class ExchangeFilter
{
    /**
     * @param list<string>         $statuses          leer = alle exportierbaren Status des Typs
     * @param string               $dateFrom          `Y-m-d` oder leer
     * @param string               $dateTo            `Y-m-d` oder leer (einschließlich)
     * @param bool                 $includeMediaFiles Dateien referenzierter Medien mitliefern
     * @param array<string, mixed> $options           typspezifische Optionen
     */
    public function __construct(
        public readonly array $statuses = [],
        public readonly string $dateFrom = '',
        public readonly string $dateTo = '',
        public readonly bool $includeMediaFiles = false,
        public readonly array $options = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'statuses' => $this->statuses,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'include_media_files' => $this->includeMediaFiles,
            'options' => $this->options,
        ];
    }

    /** @param array<string, mixed> $state */
    public static function fromArray(array $state): self
    {
        $statuses = array_values(array_filter(
            is_array($state['statuses'] ?? null) ? $state['statuses'] : [],
            static fn (mixed $status): bool => is_string($status) && $status !== ''
        ));

        return new self(
            $statuses,
            is_string($state['date_from'] ?? null) ? $state['date_from'] : '',
            is_string($state['date_to'] ?? null) ? $state['date_to'] : '',
            !empty($state['include_media_files']),
            is_array($state['options'] ?? null) ? $state['options'] : []
        );
    }
}
