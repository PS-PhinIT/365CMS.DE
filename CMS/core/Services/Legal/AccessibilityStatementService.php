<?php
/**
 * Erklärung zur Barrierefreiheit (Rechtstexte → Legal Sites).
 *
 * Hält die Angaben der Erklärung (Settings-Gruppe `legal_a11y`), baut daraus die deutsche und
 * englische Fassung, pflegt den optionalen Footer-Link im Theme-Menü (`footer_legal`, sonst `footer`) und
 * liefert die jährliche Prüf-Erinnerung für `AdminNoticeService` (registriert in
 * `CMS\Services\FeatureHooks\SystemFeatureHooks`). Seitenanlage und Zuordnung (`accessibility_page_id`)
 * übernimmt das Legal-Modul wie bei Impressum, Datenschutz, AGB und Widerruf.
 *
 * Die erzeugten Texte sind eine Vorlage und keine Rechtsberatung.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Legal;

use CMS\Logger;
use CMS\PageManager;
use CMS\Services\AdminNoticeService;
use CMS\Services\OptionStore;
use CMS\Services\SettingsService;
use CMS\ThemeManager;

if (!defined('ABSPATH')) {
    exit;
}

final class AccessibilityStatementService
{
    public const SETTINGS_GROUP = 'legal_a11y';
    public const PAGE_ID_OPTION = 'accessibility_page_id';
    public const FOOTER_URL_KEY = 'footer_url';
    public const ADMIN_URL = '/admin/legal-sites#legal-accessibility';
    public const NOTICE_ID = 'legal_accessibility_review';
    public const NOTICE_LEAD_DAYS = 30;
    public const FOOTER_LABEL = 'Barrierefreiheit';
    public const TITLE_EN = 'Accessibility statement';
    public const SLUG_EN = 'accessibility';
    public const FOOTER_LOCATIONS = ['footer_legal', 'footer'];
    public const STATUSES = ['full', 'partial', 'none'];
    public const REVIEW_METHODS = ['self', 'external'];
    public const MAX_ISSUES = 50;

    /** @var array<string, string> */
    public const DEFAULTS = [
        'status' => 'partial',
        'standard' => 'WCAG 2.1 AA (EN 301 549)',
        'legal_basis' => '',
        'issues_de' => '',
        'issues_en' => '',
        'created_at' => '',
        'reviewed_at' => '',
        'review_method' => 'self',
        'feedback_name' => '',
        'feedback_email' => '',
        'feedback_phone' => '',
        'feedback_url' => '',
        'enforcement_name' => '',
        'enforcement_address' => '',
        'enforcement_email' => '',
        'enforcement_url' => '',
        'footer_link' => '1',
    ];

    private const TEXTAREA_KEYS = ['issues_de', 'issues_en', 'enforcement_address'];
    private const MAX_TEXT_LENGTH = 300;
    private const MAX_TEXTAREA_LENGTH = 4000;
    private const MONTHS_EN = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * Bringt Eingaben auf die bekannten Felder (Steuerzeichen entfernt, Längen begrenzt, Auswahlwerte geprüft).
     * Formate (Datum, E-Mail, URL) prüft `validate()`.
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public static function normalize(array $input): array
    {
        $fields = [];
        foreach (self::DEFAULTS as $key => $default) {
            $raw = array_key_exists($key, $input) ? $input[$key] : $default;
            $value = is_scalar($raw) ? (string) $raw : $default;
            $isTextarea = in_array($key, self::TEXTAREA_KEYS, true);
            $value = str_replace(["\r\n", "\r"], "\n", $value);
            $value = (string) preg_replace($isTextarea ? '/[\x00-\x09\x0B-\x1F\x7F]+/u' : '/[\x00-\x1F\x7F]+/u', $isTextarea ? '' : ' ', $value);
            $value = trim($value);
            $limit = $isTextarea ? self::MAX_TEXTAREA_LENGTH : self::MAX_TEXT_LENGTH;
            $fields[$key] = function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
        }

        if (!in_array($fields['status'], self::STATUSES, true)) {
            $fields['status'] = self::DEFAULTS['status'];
        }
        if (!in_array($fields['review_method'], self::REVIEW_METHODS, true)) {
            $fields['review_method'] = self::DEFAULTS['review_method'];
        }
        $fields['footer_link'] = in_array(strtolower($fields['footer_link']), ['1', 'true', 'on', 'yes'], true) ? '1' : '0';
        $fields['feedback_email'] = strtolower($fields['feedback_email']);
        $fields['enforcement_email'] = strtolower($fields['enforcement_email']);

        return $fields;
    }

    /**
     * Prüft normalisierte Angaben. Liefert Fehlercodes (Sprachschlüssel `a11y.validation.<code>`), leer = gültig.
     *
     * @param array<string, string> $fields
     * @return list<string>
     */
    public static function validate(array $fields, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $errors = [];

        $created = self::parseDate($fields['created_at'] ?? '');
        $reviewed = self::parseDate($fields['reviewed_at'] ?? '');
        if (($fields['created_at'] ?? '') === '') {
            $errors[] = 'created_required';
        } elseif ($created === null) {
            $errors[] = 'created_invalid';
        }
        if (($fields['reviewed_at'] ?? '') !== '' && $reviewed === null) {
            $errors[] = 'reviewed_invalid';
        }
        if (($created !== null && $created > $today) || ($reviewed !== null && $reviewed > $today)) {
            $errors[] = 'date_future';
        }
        if ($created !== null && $reviewed !== null && $reviewed < $created) {
            $errors[] = 'review_before_created';
        }

        if (($fields['status'] ?? 'partial') !== 'full' && self::parseIssues((string) ($fields['issues_de'] ?? '')) === []) {
            $errors[] = 'issues_required';
        }

        if (($fields['feedback_email'] ?? '') === '' && ($fields['feedback_url'] ?? '') === '' && ($fields['feedback_phone'] ?? '') === '') {
            $errors[] = 'feedback_required';
        }
        foreach (['feedback_email', 'enforcement_email'] as $key) {
            if (($fields[$key] ?? '') !== '' && !self::isEmail($fields[$key])) {
                $errors[] = $key . '_invalid';
            }
        }
        foreach (['feedback_url', 'enforcement_url'] as $key) {
            if (($fields[$key] ?? '') !== '' && !self::isHttpUrl($fields[$key])) {
                $errors[] = $key . '_invalid';
            }
        }

        return $errors;
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    public static function errorMessages(array $codes): array
    {
        return array_map(static fn (string $code): string => __('a11y.validation.' . $code), $codes);
    }

    /**
     * Eine Zeile je nicht barrierefreiem Inhalt, Begründung optional nach „|“.
     *
     * @return list<array{content:string,reason:string}>
     */
    public static function parseIssues(string $text): array
    {
        $issues = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            $line = trim((string) preg_replace('/^[-*•]\s*/u', '', trim($line)));
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line, 2));
            if ($parts[0] === '') {
                continue;
            }
            $issues[] = ['content' => $parts[0], 'reason' => (string) ($parts[1] ?? '')];
            if (count($issues) >= self::MAX_ISSUES) {
                break;
            }
        }

        return $issues;
    }

    /**
     * Baut die Erklärung als HTML (nur p, a, strong, ul, li, br, h2, h3 – passend zur Legal-Sites-Bereinigung).
     * Gliederung nach der Mustererklärung der EU (Durchführungsbeschluss (EU) 2018/1523).
     *
     * @param array<string, mixed> $fields Angaben (werden normalisiert)
     * @param array{provider?:string,website?:string} $context Anbieter und Website aus den Legal-Standardwerten
     */
    public static function buildStatement(array $fields, array $context, string $locale = 'de'): string
    {
        $en = strtolower($locale) === 'en';
        $fields = self::normalize($fields);
        $provider = trim((string) ($context['provider'] ?? ''));
        $website = trim((string) ($context['website'] ?? ''));
        $siteHtml = self::isHttpUrl($website)
            ? ($en ? 'the website ' : 'die Website ') . '<a href="' . self::esc($website) . '">' . self::esc($website) . '</a>'
            : ($en ? 'this website' : 'diese Website');
        $legalBasis = $fields['legal_basis'];

        $html = '<h2>' . ($en ? 'Accessibility statement' : 'Erklärung zur Barrierefreiheit') . '</h2>';
        if ($en) {
            $html .= '<p>' . ($provider !== '' ? self::esc($provider) . ' is' : 'We are') . ' committed to making ' . $siteHtml . ' accessible.'
                . ($legalBasis !== '' ? ' Legal basis: ' . self::esc($legalBasis) . '.' : '') . '</p>';
            $html .= '<p>This accessibility statement applies to ' . $siteHtml . '.</p>';
        } else {
            $html .= '<p>' . ($provider !== '' ? self::esc($provider) . ' ist' : 'Wir sind') . ' bemüht, ' . $siteHtml . ' barrierefrei zugänglich zu machen.'
                . ($legalBasis !== '' ? ' Rechtsgrundlage: ' . self::esc($legalBasis) . '.' : '') . '</p>';
            $html .= '<p>Diese Erklärung zur Barrierefreiheit gilt für ' . $siteHtml . '.</p>';
        }

        $requirements = $fields['standard'] !== ''
            ? ($en ? 'the requirements of ' : 'den Anforderungen nach ') . self::esc($fields['standard'])
            : ($en ? 'the applicable accessibility requirements' : 'den geltenden Anforderungen an die Barrierefreiheit');
        $listedBelow = $en
            ? ' The non-compliances and exemptions are listed below.'
            : ' Die Unvereinbarkeiten und Ausnahmen sind nachstehend aufgeführt.';
        $html .= '<h3>' . ($en ? 'Compliance status' : 'Stand der Vereinbarkeit mit den Anforderungen') . '</h3>';
        $html .= '<p>' . match ($fields['status']) {
            'full' => $en
                ? 'This website is fully compliant with ' . $requirements . '.'
                : 'Diese Website ist mit ' . $requirements . ' vollständig vereinbar.',
            'none' => ($en
                ? 'This website is not compliant with ' . $requirements . '.'
                : 'Diese Website ist mit ' . $requirements . ' nicht vereinbar.') . $listedBelow,
            default => ($en
                ? 'This website is partially compliant with ' . $requirements . '.'
                : 'Diese Website ist mit ' . $requirements . ' teilweise vereinbar.') . $listedBelow,
        } . '</p>';

        $issues = self::parseIssues($en && $fields['issues_en'] !== '' ? $fields['issues_en'] : $fields['issues_de']);
        if ($issues !== []) {
            $html .= '<h3>' . ($en ? 'Non-accessible content' : 'Nicht barrierefreie Inhalte') . '</h3>';
            $html .= '<p>' . ($en
                ? 'The content listed below is non-accessible for the following reasons:'
                : 'Die nachstehend aufgeführten Inhalte sind aus den folgenden Gründen nicht barrierefrei:') . '</p><ul>';
            foreach ($issues as $issue) {
                $html .= '<li><strong>' . self::esc($issue['content']) . '</strong>'
                    . ($issue['reason'] !== '' ? ': ' . self::esc($issue['reason']) : '') . '</li>';
            }
            $html .= '</ul>';
        }

        $created = self::parseDate($fields['created_at']);
        $reviewed = self::parseDate($fields['reviewed_at']);
        if ($created !== null) {
            $html .= '<h3>' . ($en ? 'Preparation of this statement' : 'Erstellung dieser Erklärung') . '</h3><p>';
            $html .= $en
                ? 'This statement was prepared on ' . self::formatDate($created, 'en') . '. '
                    . ($fields['review_method'] === 'external' ? 'It is based on an assessment carried out by a third party.' : 'It is based on a self-assessment.')
                : 'Diese Erklärung wurde am ' . self::formatDate($created, 'de') . ' erstellt. '
                    . ($fields['review_method'] === 'external' ? 'Sie beruht auf einer Prüfung durch Dritte.' : 'Sie beruht auf einer Selbstbewertung.');
            if ($reviewed !== null && $reviewed > $created) {
                $html .= $en
                    ? ' The statement was last reviewed on ' . self::formatDate($reviewed, 'en') . '.'
                    : ' Die Erklärung wurde zuletzt am ' . self::formatDate($reviewed, 'de') . ' überprüft.';
            }
            $html .= '</p>';
        }

        $contactLines = self::contactLines(
            $fields['feedback_name'],
            '',
            $fields['feedback_email'],
            $fields['feedback_phone'],
            $fields['feedback_url'],
            $en,
            $en ? 'Contact form' : 'Kontaktformular'
        );
        if ($contactLines !== []) {
            $html .= '<h3>' . ($en ? 'Feedback and contact information' : 'Feedback und Kontaktangaben') . '</h3>';
            $html .= '<p>' . ($en
                ? 'If you notice any barriers to accessing content on this website or need information in an accessible format, please contact:'
                : 'Sind Ihnen Mängel beim barrierefreien Zugang zu Inhalten dieser Website aufgefallen oder benötigen Sie Informationen in barrierefreier Form? Dann wenden Sie sich bitte an:') . '</p>';
            $html .= '<p>' . implode('<br>', $contactLines) . '</p>';
        }

        $enforcementLines = self::contactLines(
            $fields['enforcement_name'],
            $fields['enforcement_address'],
            $fields['enforcement_email'],
            '',
            $fields['enforcement_url'],
            $en,
            'Website'
        );
        if ($enforcementLines !== []) {
            $html .= '<h3>' . ($en ? 'Enforcement procedure' : 'Durchsetzungsverfahren') . '</h3>';
            $html .= '<p>' . ($en
                ? 'If you do not receive a satisfactory response to your feedback, you can contact the following body:'
                : 'Erhalten Sie auf Ihre Rückmeldung keine zufriedenstellende Antwort, können Sie sich an die folgende Stelle wenden:') . '</p>';
            $html .= '<p>' . implode('<br>', $enforcementLines) . '</p>';
        }

        return $html;
    }

    /**
     * Stand der jährlichen Prüfung: `missing` (kein Datum), `ok`, `due` (fällig innerhalb von 30 Tagen) oder `overdue`.
     * Basis ist das letzte Prüfdatum, sonst das Erstellungsdatum.
     *
     * @param array<string, string> $fields
     * @return array{state:string,base:?string,due:?string}
     */
    public static function reviewState(array $fields, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $base = self::parseDate((string) ($fields['reviewed_at'] ?? '')) ?? self::parseDate((string) ($fields['created_at'] ?? ''));
        if ($base === null) {
            return ['state' => 'missing', 'base' => null, 'due' => null];
        }

        $due = $base->modify('+1 year');
        $state = 'ok';
        if ($today >= $due) {
            $state = 'overdue';
        } elseif ($today >= $due->modify('-' . self::NOTICE_LEAD_DAYS . ' days')) {
            $state = 'due';
        }

        return ['state' => $state, 'base' => $base->format('Y-m-d'), 'due' => $due->format('Y-m-d')];
    }

    public static function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof \DateTimeImmutable ? $date : null;
    }

    public static function formatDate(\DateTimeImmutable $date, string $locale = 'de'): string
    {
        return strtolower($locale) === 'en'
            ? $date->format('j') . ' ' . self::MONTHS_EN[(int) $date->format('n') - 1] . ' ' . $date->format('Y')
            : $date->format('d.m.Y');
    }

    /** @return array<string, string> */
    public function getFields(): array
    {
        try {
            $stored = SettingsService::getInstance()->getGroup(self::SETTINGS_GROUP);
        } catch (\Throwable) {
            $stored = [];
        }

        $input = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (array_key_exists($key, $stored) && is_scalar($stored[$key])) {
                $input[$key] = (string) $stored[$key];
            }
        }

        return self::normalize($input);
    }

    /** @param array<string, mixed> $fields */
    public function saveFields(array $fields): bool
    {
        $saved = SettingsService::getInstance()->setMany(self::SETTINGS_GROUP, self::normalize($fields));
        $this->clearNoticeCache();

        return $saved;
    }

    public function getPageId(): int
    {
        try {
            return max(0, (int) (OptionStore::getInstance()->get(self::PAGE_ID_OPTION) ?? '0'));
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array<string, mixed>|null veröffentlichte, nicht im Papierkorb liegende Seite */
    public function getPublishedPage(?int $pageId = null): ?array
    {
        $pageId ??= $this->getPageId();
        if ($pageId <= 0) {
            return null;
        }

        try {
            $page = PageManager::instance()->getPage($pageId);
        } catch (\Throwable) {
            return null;
        }

        if ($page === null || (string) ($page['status'] ?? '') !== 'published' || !empty($page['trashed_at'])) {
            return null;
        }

        return $page;
    }

    public function getPagePath(?int $pageId = null): string
    {
        $page = $this->getPublishedPage($pageId);
        $slug = trim((string) ($page['slug'] ?? ''), '/');

        return $slug !== '' ? '/' . $slug : '';
    }

    /**
     * Englische Seitenfelder; die EN-Fassung ist über `/en/<slug_en>` erreichbar.
     *
     * @param array{provider?:string,website?:string} $context
     * @return array{title_en:string,slug_en:string,content_en:string}
     */
    public function localizedPageFields(array $context): array
    {
        return [
            'title_en' => self::TITLE_EN,
            'slug_en' => self::SLUG_EN,
            'content_en' => sanitize_html(self::buildStatement($this->getFields(), $context, 'en'), 'default'),
        ];
    }

    /** @return array{slug:string,label:string}|null */
    public function getFooterLocation(): ?array
    {
        try {
            $locations = ThemeManager::instance()->getMenuLocations();
        } catch (\Throwable) {
            return null;
        }

        $known = [];
        foreach ($locations as $location) {
            if (is_array($location) && isset($location['slug'])) {
                $known[(string) $location['slug']] = (string) ($location['label'] ?? $location['slug']);
            }
        }
        foreach (self::FOOTER_LOCATIONS as $slug) {
            if (isset($known[$slug])) {
                return ['slug' => $slug, 'label' => $known[$slug]];
            }
        }

        return null;
    }

    public function isFooterLinked(?int $pageId = null): bool
    {
        $location = $this->getFooterLocation();
        $path = $this->getPagePath($pageId);
        if ($location === null || $path === '') {
            return false;
        }

        return self::findMenuItem(ThemeManager::instance()->getMenu($location['slug']), [$path]) !== null;
    }

    /**
     * Gleicht den Footer-Link mit Einstellung und Seite ab: hinzufügen, URL nachziehen oder entfernen.
     * Der Eintrag wird über seine URL erkannt (der Menü-Editor verwirft unbekannte Zusatzfelder). Ein noch leeres
     * Menü bleibt unberührt, damit Theme-Standardmenüs bzw. Theme-Fallback-Links nicht verdrängt werden.
     *
     * @return array{status:string,location:?string} status: added|updated|present|removed|absent|no_page|menu_empty|no_location|failed
     */
    public function syncFooterLink(?int $pageId = null): array
    {
        $location = $this->getFooterLocation();
        if ($location === null) {
            return ['status' => 'no_location', 'location' => null];
        }

        $settings = SettingsService::getInstance();
        $wantedByUser = $this->getFields()['footer_link'] === '1';
        $path = $this->getPagePath($pageId);
        $previousPath = $settings->getString(self::SETTINGS_GROUP, self::FOOTER_URL_KEY);
        $menu = ThemeManager::instance()->getMenu($location['slug']);
        $candidates = array_values(array_unique(array_filter([$path, $previousPath], static fn (string $url): bool => $url !== '')));
        $index = $candidates !== [] ? self::findMenuItem($menu, $candidates) : null;
        $wanted = $wantedByUser && $path !== '';

        if ($wanted) {
            if ($index === null && $menu === []) {
                return ['status' => 'menu_empty', 'location' => $location['slug']];
            }
            if ($index === null) {
                $menu[] = ['label' => self::FOOTER_LABEL, 'url' => $path, 'target' => '_self'];
                $status = 'added';
            } elseif (self::normalizeMenuUrl((string) ($menu[$index]['url'] ?? '')) !== $path) {
                $menu[$index]['url'] = $path;
                $status = 'updated';
            } else {
                $status = 'present';
            }
        } elseif ($index !== null) {
            array_splice($menu, $index, 1);
            $status = 'removed';
        } else {
            $status = $wantedByUser ? 'no_page' : 'absent';
        }

        if (in_array($status, ['added', 'updated', 'removed'], true) && !ThemeManager::instance()->saveMenu($location['slug'], array_values($menu))) {
            Logger::instance()->withChannel('legal.accessibility')->warning('Footer-Link der Barrierefreiheitserklärung konnte nicht gespeichert werden.', [
                'location' => $location['slug'],
            ]);

            return ['status' => 'failed', 'location' => $location['slug']];
        }

        $settings->set(self::SETTINGS_GROUP, self::FOOTER_URL_KEY, $wanted ? $path : '');

        return ['status' => $status, 'location' => $location['slug']];
    }

    /**
     * Prüfung für `AdminNoticeService`: jährliche Prüf-Erinnerung, sobald eine veröffentlichte Erklärung existiert;
     * Warnung, wenn der gesetzte Footer-Link auf eine nicht mehr veröffentlichte Seite zeigt.
     */
    public function reviewNotice(?\DateTimeImmutable $today = null): ?array
    {
        if ($this->getPublishedPage() === null) {
            $footerUrl = SettingsService::getInstance()->getString(self::SETTINGS_GROUP, self::FOOTER_URL_KEY);

            return $footerUrl !== '' ? AdminNoticeService::makeNotice(
                self::NOTICE_ID,
                'warning',
                __('a11y.notice.page_missing_title'),
                sprintf(__('a11y.notice.page_missing_text'), $footerUrl),
                self::ADMIN_URL,
                __('a11y.notice.link')
            ) : null;
        }

        $review = self::reviewState($this->getFields(), $today);
        $due = $review['due'] !== null ? self::parseDate($review['due']) : null;
        $dueText = $due !== null ? self::formatDate($due) : '';

        return match ($review['state']) {
            'overdue' => AdminNoticeService::makeNotice(
                self::NOTICE_ID,
                'warning',
                __('a11y.notice.overdue_title'),
                sprintf(__('a11y.notice.overdue_text'), $dueText),
                self::ADMIN_URL,
                __('a11y.notice.link')
            ),
            'due' => AdminNoticeService::makeNotice(
                self::NOTICE_ID,
                'info',
                __('a11y.notice.due_title'),
                sprintf(__('a11y.notice.due_text'), $dueText),
                self::ADMIN_URL,
                __('a11y.notice.link')
            ),
            'missing' => AdminNoticeService::makeNotice(
                self::NOTICE_ID,
                'warning',
                __('a11y.notice.missing_title'),
                __('a11y.notice.missing_text'),
                self::ADMIN_URL,
                __('a11y.notice.link')
            ),
            default => null,
        };
    }

    public function clearNoticeCache(): void
    {
        try {
            AdminNoticeService::getInstance()->clearCache();
        } catch (\Throwable) {
            // Der Hinweis-Cache läuft nach spätestens fünf Minuten ohnehin ab.
        }
    }

    /**
     * @param array<int|string, mixed> $menu
     * @param list<string> $paths
     */
    private static function findMenuItem(array $menu, array $paths): ?int
    {
        $paths = array_map([self::class, 'normalizeMenuUrl'], $paths);
        foreach (array_values($menu) as $index => $item) {
            if (is_array($item) && in_array(self::normalizeMenuUrl((string) ($item['url'] ?? '')), $paths, true)) {
                return $index;
            }
        }

        return null;
    }

    private static function normalizeMenuUrl(string $url): string
    {
        $url = trim($url);
        $siteUrl = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
        if ($siteUrl !== '' && str_starts_with($url, $siteUrl)) {
            $url = substr($url, strlen($siteUrl));
        }
        $path = trim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/');

        return '/' . $path;
    }

    /** @return list<string> */
    private static function contactLines(string $name, string $address, string $email, string $phone, string $url, bool $en, string $urlLabel): array
    {
        $lines = [];
        if ($name !== '') {
            $lines[] = '<strong>' . self::esc($name) . '</strong>';
        }
        foreach (explode("\n", $address) as $addressLine) {
            if (trim($addressLine) !== '') {
                $lines[] = self::esc(trim($addressLine));
            }
        }
        if ($email !== '' && self::isEmail($email)) {
            $lines[] = ($en ? 'Email: ' : 'E-Mail: ') . '<a href="mailto:' . self::esc($email) . '">' . self::esc($email) . '</a>';
        }
        if ($phone !== '') {
            $lines[] = ($en ? 'Phone: ' : 'Telefon: ') . self::esc($phone);
        }
        if ($url !== '' && self::isHttpUrl($url)) {
            $lines[] = $urlLabel . ': <a href="' . self::esc($url) . '">' . self::esc($url) . '</a>';
        }

        return $lines;
    }

    private static function isEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function isHttpUrl(string $value): bool
    {
        return preg_match('#^https?://#i', $value) === 1 && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
