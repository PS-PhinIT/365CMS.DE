<?php
declare(strict_types=1);

/**
 * Redaktionskalender – Seitendaten (Filter, Startansicht, Konfiguration für admin-editorial-calendar.js).
 *
 * Termine lädt das Skript über /admin/editorial-calendar-api (EditorialCalendarService).
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Services\EditorialCalendarService;
use CMS\Services\TranslationService;

class EditorialCalendarModule
{
    public const API_PATH = '/admin/editorial-calendar-api';
    public const CSRF_ACTION = 'admin_editorial_calendar';

    private EditorialCalendarService $service;

    public function __construct()
    {
        $this->service = EditorialCalendarService::getInstance();
    }

    /**
     * @param array<string,mixed> $query Rohwerte aus $_GET
     * @return array<string,mixed>
     */
    public function getData(array $query): array
    {
        $locales = $this->service->getFilterLocales();
        $filters = EditorialCalendarService::normalizeFilters($query, $locales);
        $today = $this->service->todaySite();
        $requestedDate = EditorialCalendarService::parseDate(is_scalar($query['date'] ?? null) ? (string) $query['date'] : '');

        return [
            'view' => (is_scalar($query['view'] ?? null) && (string) $query['view'] === 'week') ? 'week' : 'month',
            'anchor' => $requestedDate?->format('Y-m-d') ?? $today,
            'today' => $today,
            'timezone' => $this->service->getSiteTimezone()->getName(),
            'filters' => $filters,
            'options' => $this->service->getFilterOptions(),
        ];
    }

    /**
     * Konfiguration für das Skript (als JSON in der Seite).
     *
     * @param array<string,mixed> $data Ergebnis von getData()
     * @return array<string,mixed>
     */
    public function buildClientConfig(array $data, string $csrfToken): array
    {
        $uiLocale = 'de';
        try {
            $uiLocale = str_replace('_', '-', TranslationService::getInstance()->getLocale());
        } catch (\Throwable) {
        }

        return [
            'apiUrl' => self::API_PATH,
            'pageUrl' => '/admin/editorial-calendar',
            'csrfToken' => $csrfToken,
            'view' => $data['view'],
            'anchor' => $data['anchor'],
            'today' => $data['today'],
            'timezone' => $data['timezone'],
            'uiLocale' => $uiLocale !== '' ? $uiLocale : 'de',
            'filters' => $data['filters'],
            'i18n' => [
                'weekLabel' => __('calendar.week_label'),
                'loading' => __('calendar.loading'),
                'loadError' => __('calendar.error.load'),
                'truncated' => __('calendar.truncated'),
                'noEvents' => __('calendar.no_events'),
                'eventsCount' => __('calendar.events_count'),
                'typePost' => __('calendar.type.post'),
                'typePage' => __('calendar.type.page'),
                'kindScheduled' => __('calendar.kind.scheduled'),
                'kindPlanned' => __('calendar.kind.planned'),
                'kindPublished' => __('calendar.kind.published'),
                'author' => __('calendar.event.author'),
                'category' => __('calendar.event.category'),
                'moveButton' => __('calendar.move.button'),
                'moveConfirmTitle' => __('calendar.move.confirm_title'),
                'moveConfirmMessage' => __('calendar.move.confirm_message'),
                'moveConfirmButton' => __('calendar.move.confirm_button'),
                'publishNowTitle' => __('calendar.move.publish_now_title'),
                'publishNowButton' => __('calendar.move.publish_now_button'),
                'moveFailed' => __('calendar.move.error_failed'),
                'moveSameDay' => __('calendar.move.same_day'),
                'checklistMissing' => __('calendar.move.checklist_missing'),
                'csrfExpired' => __('calendar.error.csrf'),
            ],
        ];
    }
}
