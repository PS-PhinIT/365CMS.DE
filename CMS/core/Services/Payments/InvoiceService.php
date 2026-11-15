<?php
/**
 * Rechnungen (4.1.0, Bereich 5b) – rechtssicher nach § 14 UStG.
 *
 * - **Nummernkreis** je Format (Einstellung, z. B. `RE-{YYYY}-{NNNNN}`): Platzhalter `{YYYY}`, `{YY}`, `{MM}` und
 *   genau ein Zähler `{N…}` (3–10 Stellen). Datumsplatzhalter bilden den Kreis (`sequence_key`), z. B. `RE-2026-#`:
 *   jedes Jahr beginnt bei der Startnummer. Vergabe in **einer Transaktion** mit Sperre auf den Zähler
 *   (`SELECT … FOR UPDATE` auf `invoice_sequences`, in SQLite genügt die Transaktion) zusammen mit PDF, Datei und
 *   Rechnungszeile; scheitert ein Schritt, wird alles zurückgerollt (**lückenlos**). Der Zähler sinkt nie,
 *   `UNIQUE (sequence_key, sequence_number)` und `UNIQUE (invoice_number)` verhindern eine **Wiederverwendung**.
 * - **Unveränderlich:** Rechnungsdaten als JSON-Snapshot, PDF über `PdfService` (Dompdf) im privaten Speicher
 *   `invoices/{yyyy}/` (außerhalb des Webroots), SHA-256 gespeichert und bei jedem Download geprüft.
 *   Korrektur nur über eine **Storno-Rechnung** (eigener Nummernkreis, Verweis `cancels_invoice_id`).
 * - **Pflichtangaben** (§ 14 Abs. 4 UStG, § 33 UStDV für Kleinbetragsrechnungen bis 250 € brutto) werden vor der
 *   Vergabe geprüft; fehlt eine, wird keine Nummer verbraucht. Steuermodi: Regelbesteuerung, Kleinunternehmer
 *   (§ 19 UStG), Reverse Charge (§ 13b UStG, USt-IdNr. des Empfängers Pflicht).
 * - Absenderdaten aus den Einstellungen `invoices.*`, ersatzweise aus dem Rechtstexte-Profil (`legal_profile_*`).
 * - Nach dem Erstellen: Mail mit PDF-Anhang (Mail-Warteschlange) und Hook `cms_invoice_created`.
 * - Aufbewahrung 10 Jahre: Rechnungen werden nie gelöscht; DSGVO-Löschungen setzen nur einen Sperrvermerk.
 *
 * Datenbank, Uhr, Ablage, PDF-Erzeuger, Mailversand und Einstellungen sind für Tests ersetzbar.
 *
 * @package CMSv2\Core\Services\Payments
 */

declare(strict_types=1);

namespace CMS\Services\Payments;

use CMS\Database;
use CMS\Hooks;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class InvoiceService
{
    public const TYPE_INVOICE = 'invoice';
    public const TYPE_CANCELLATION = 'cancellation';

    public const TAX_STANDARD = 'standard';
    public const TAX_SMALL_BUSINESS = 'small_business';
    public const TAX_REVERSE_CHARGE = 'reverse_charge';
    public const TAX_MODES = [
        self::TAX_STANDARD => 'Regelbesteuerung',
        self::TAX_SMALL_BUSINESS => 'Kleinunternehmer (§ 19 UStG)',
        self::TAX_REVERSE_CHARGE => 'Reverse Charge (§ 13b UStG)',
    ];

    public const DEFAULT_NUMBER_FORMAT = 'RE-{YYYY}-{NNNNN}';
    public const DEFAULT_CANCELLATION_FORMAT = 'ST-{YYYY}-{NNNNN}';
    public const PAID_STATUSES = ['paid', 'confirmed', 'completed'];
    /** § 33 UStDV: Kleinbetragsrechnung bis 250 € brutto (ohne Empfänger und Steuernummer). */
    public const SMALL_AMOUNT_LIMIT = 250.0;
    public const SNAPSHOT_VERSION = 1;
    /** Versuche je Vergabe bei Sperrkonflikten der Datenbank. */
    public const MAX_ATTEMPTS = 3;
    public const SETTINGS_GROUP = 'invoices';
    public const STORAGE_AREA = 'invoices';
    public const SMALL_BUSINESS_NOTE = 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet (Kleinunternehmerregelung).';
    public const REVERSE_CHARGE_NOTE = 'Steuerschuldnerschaft des Leistungsempfängers (Reverse Charge, § 13b UStG / Art. 196 MwStSystRL).';

    /** Einstellungen der Gruppe `invoices` mit Standardwerten (leere Absenderfelder → Rechtstexte-Profil). */
    public const SETTING_DEFAULTS = [
        'number_format' => self::DEFAULT_NUMBER_FORMAT,
        'cancellation_format' => self::DEFAULT_CANCELLATION_FORMAT,
        'start_number' => '1',
        'tax_mode' => self::TAX_STANDARD,
        'exempt_note' => '',
        'auto_create' => '1',
        'send_mail' => '1',
        'sender_name' => '',
        'sender_street' => '',
        'sender_zip' => '',
        'sender_city' => '',
        'sender_country' => '',
        'sender_email' => '',
        'tax_number' => '',
        'vat_id' => '',
        'bank_name' => '',
        'iban' => '',
        'bic' => '',
        'footer_note' => '',
    ];

    /** Absenderfeld → Schlüssel im Rechtstexte-Profil (Admin → Rechtstexte). */
    public const LEGAL_PROFILE_FALLBACK = [
        'sender_street' => 'legal_profile_street',
        'sender_zip' => 'legal_profile_postal_code',
        'sender_city' => 'legal_profile_city',
        'sender_country' => 'legal_profile_country',
        'sender_email' => 'legal_profile_email',
        'vat_id' => 'legal_profile_vat_id',
    ];

    /** EU-Mitgliedstaaten (ISO 3166-1 alpha-2) für die Erkennung von Reverse Charge. */
    private const EU_COUNTRIES = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU',
        'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'];
    private const COUNTRY_ALIASES = ['DEUTSCHLAND' => 'DE', 'GERMANY' => 'DE', 'ÖSTERREICH' => 'AT', 'OESTERREICH' => 'AT', 'AUSTRIA' => 'AT',
        'NIEDERLANDE' => 'NL', 'NETHERLANDS' => 'NL', 'FRANKREICH' => 'FR', 'FRANCE' => 'FR', 'BELGIEN' => 'BE', 'BELGIUM' => 'BE',
        'ITALIEN' => 'IT', 'ITALY' => 'IT', 'SPANIEN' => 'ES', 'SPAIN' => 'ES', 'POLEN' => 'PL', 'POLAND' => 'PL', 'LUXEMBURG' => 'LU',
        'LUXEMBOURG' => 'LU', 'DÄNEMARK' => 'DK', 'DENMARK' => 'DK', 'SCHWEDEN' => 'SE', 'SWEDEN' => 'SE', 'TSCHECHIEN' => 'CZ', 'IRLAND' => 'IE',
        'IRELAND' => 'IE', 'SCHWEIZ' => 'CH', 'SWITZERLAND' => 'CH'];

    private static ?self $instance = null;

    private Database $db;
    private string $prefix;
    /** @var \Closure(): int */
    private \Closure $clock;
    /** @var \Closure(string): string Unterordner ('' oder 'yyyy') → absoluter Ordner mit abschließendem Slash */
    private \Closure $storageDir;
    /** @var \Closure(string): string HTML → PDF-Binärdaten */
    private \Closure $pdfRenderer;
    /** @var \Closure(string, string, string, string, string): bool Empfänger, Betreff, HTML, PDF-Pfad, Dateiname */
    private \Closure $mailer;
    /** @var \Closure(): array<string, mixed> */
    private \Closure $readSettings;
    /** @var \Closure(array<string, string>): bool */
    private \Closure $writeSettings;
    /** @var \Closure(): array<string, string> */
    private \Closure $readLegalProfile;

    public function __construct(
        ?Database $db = null,
        ?\Closure $clock = null,
        ?\Closure $storageDir = null,
        ?\Closure $pdfRenderer = null,
        ?\Closure $mailer = null,
        ?\Closure $readSettings = null,
        ?\Closure $writeSettings = null,
        ?\Closure $readLegalProfile = null
    ) {
        $this->db = $db ?? Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->clock = $clock ?? static fn (): int => time();
        $this->storageDir = $storageDir ?? static fn (string $sub): string => \CMS\Services\PrivateStorageService::getInstance()
            ->path($sub === '' ? self::STORAGE_AREA : self::STORAGE_AREA . '/' . $sub);
        $this->pdfRenderer = $pdfRenderer ?? static fn (string $html): string => \CMS\Services\PdfService::getInstance()->generateFromHtml($html);
        $this->mailer = $mailer ?? \Closure::fromCallable([self::class, 'defaultMailer']);
        $this->readSettings = $readSettings ?? static fn (): array => \CMS\Services\SettingsService::getInstance()->getGroup(self::SETTINGS_GROUP);
        $this->writeSettings = $writeSettings ?? static fn (array $values): bool => \CMS\Services\SettingsService::getInstance()->setMany(self::SETTINGS_GROUP, $values);
        $this->readLegalProfile = $readLegalProfile ?? function (): array {
            $rows = $this->db->get_results(
                "SELECT option_name, option_value FROM {$this->prefix}settings WHERE option_name LIKE ?",
                ['legal_profile_%']
            ) ?: [];
            $profile = [];
            foreach ($rows as $row) {
                $profile[(string) $row->option_name] = (string) $row->option_value;
            }

            return $profile;
        };
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /** Nur für Tests und Hooks-Prüfungen: Instanz ersetzen bzw. mit null zurücksetzen. */
    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    // ── Schema ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * MySQL-CREATE-Statements der v26-Tabellen (für SchemaManager::getSchemaQueries()).
     *
     * @return array<string, string>
     */
    public static function schemaQueries(string $prefix, string $charset = 'utf8mb4'): array
    {
        $p = $prefix;
        $c = $charset;

        return [
            'invoice_sequences' => "CREATE TABLE IF NOT EXISTS {$p}invoice_sequences (
                sequence_key VARCHAR(30) NOT NULL PRIMARY KEY,
                next_number INT UNSIGNED NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET={$c} COMMENT='Rechnungs-Nummernkreise (lückenlos, nie rückwärts)'",
            'invoices' => "CREATE TABLE IF NOT EXISTS {$p}invoices (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                invoice_number VARCHAR(40) NOT NULL,
                sequence_key VARCHAR(30) NOT NULL,
                sequence_number INT UNSIGNED NOT NULL,
                type VARCHAR(12) NOT NULL DEFAULT 'invoice',
                order_id BIGINT UNSIGNED NULL,
                user_id INT UNSIGNED NULL,
                cancels_invoice_id BIGINT UNSIGNED NULL,
                issued_at DATETIME NOT NULL,
                service_period_start DATE NULL,
                service_period_end DATE NULL,
                currency CHAR(3) NOT NULL,
                net_total DECIMAL(12,2) NOT NULL,
                tax_total DECIMAL(12,2) NOT NULL,
                gross_total DECIMAL(12,2) NOT NULL,
                tax_mode VARCHAR(20) NOT NULL DEFAULT 'standard',
                snapshot_json LONGTEXT NOT NULL,
                pdf_path VARCHAR(255) NOT NULL,
                pdf_sha256 CHAR(64) NOT NULL,
                created_by INT UNSIGNED NULL,
                restricted_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_invoices_number (invoice_number),
                UNIQUE KEY uq_invoices_sequence (sequence_key, sequence_number),
                UNIQUE KEY uq_invoices_cancels (cancels_invoice_id),
                KEY idx_invoices_user (user_id, issued_at),
                KEY idx_invoices_order (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c} COMMENT='Rechnungen und Storno-Rechnungen (unveränderlich, 10 Jahre)'",
        ];
    }

    // ── Reine Regeln: Nummernkreis ──────────────────────────────────────────────────────────────────────────────

    /**
     * @return string|null Fehlermeldung oder null
     */
    public static function validateFormat(string $format): ?string
    {
        if ($format === '' || strlen($format) > 40) {
            return 'Das Nummernformat muss 1 bis 40 Zeichen lang sein.';
        }
        if (preg_match_all('/\{N{3,10}\}/', $format) !== 1) {
            return 'Das Nummernformat braucht genau einen Zähler {NNN} bis {NNNNNNNNNN}.';
        }
        $rest = (string) preg_replace('/\{(?:YYYY|YY|MM|N{3,10})\}/', '', $format);
        if (preg_match('/^[A-Za-z0-9\-_\/.]*$/', $rest) !== 1) {
            return 'Erlaubt sind Buchstaben, Ziffern, - _ / . und die Platzhalter {YYYY}, {YY}, {MM}, {NNNNN}.';
        }
        if (strlen(self::formatNumber($format, 9999999999, 0)) > 40) {
            return 'Die Rechnungsnummer würde länger als 40 Zeichen.';
        }

        return null;
    }

    public static function formatNumber(string $format, int $number, int $timestamp): string
    {
        $dated = strtr($format, ['{YYYY}' => date('Y', $timestamp), '{YY}' => date('y', $timestamp), '{MM}' => date('m', $timestamp)]);

        return (string) preg_replace_callback(
            '/\{(N{3,10})\}/',
            static fn (array $m): string => str_pad((string) $number, strlen($m[1]), '0', STR_PAD_LEFT),
            $dated
        );
    }

    /** Nummernkreis eines Formats zum Zeitpunkt: Datumsplatzhalter aufgelöst, Zähler als `#` (max. 30 Zeichen). */
    public static function sequenceKey(string $format, int $timestamp): string
    {
        $key = (string) preg_replace('/\{N{3,10}\}/', '#', strtr($format, ['{YYYY}' => date('Y', $timestamp), '{YY}' => date('y', $timestamp), '{MM}' => date('m', $timestamp)]));

        return strlen($key) <= 30 ? $key : 'h:' . substr(hash('sha256', $key), 0, 28);
    }

    // ── Reine Regeln: Pflichtangaben und Darstellung ───────────────────────────────────────────────────────────

    /**
     * Fehlende Pflichtangaben eines Snapshots (§ 14 Abs. 4 UStG; Kleinbetragsrechnung § 33 UStDV).
     *
     * @param array<string, mixed> $snapshot
     * @return list<string>
     */
    public static function missingMandatoryFields(array $snapshot): array
    {
        $missing = [];
        $seller = is_array($snapshot['seller'] ?? null) ? $snapshot['seller'] : [];
        $customer = is_array($snapshot['customer'] ?? null) ? $snapshot['customer'] : [];
        $totals = is_array($snapshot['totals'] ?? null) ? $snapshot['totals'] : [];
        $items = is_array($snapshot['items'] ?? null) ? $snapshot['items'] : [];
        $mode = (string) ($snapshot['tax_mode'] ?? '');
        $small = !empty($snapshot['small_amount']);
        $filled = static fn (array $source, string $key): bool => trim((string) ($source[$key] ?? '')) !== '';

        foreach (['name' => 'Name des Leistenden', 'street' => 'Straße des Leistenden', 'zip' => 'PLZ des Leistenden', 'city' => 'Ort des Leistenden'] as $key => $label) {
            if (!$filled($seller, $key)) {
                $missing[] = $label;
            }
        }
        if (!$small && !$filled($seller, 'tax_number') && !$filled($seller, 'vat_id')) {
            $missing[] = 'Steuernummer oder USt-IdNr. des Leistenden';
        }
        if (!$small) {
            foreach (['name' => 'Name des Leistungsempfängers', 'street' => 'Straße des Leistungsempfängers', 'zip' => 'PLZ des Leistungsempfängers', 'city' => 'Ort des Leistungsempfängers'] as $key => $label) {
                if (!$filled($customer, $key)) {
                    $missing[] = $label;
                }
            }
        }
        if (!$filled($snapshot, 'number')) {
            $missing[] = 'Rechnungsnummer';
        }
        if (!$filled($snapshot, 'issued_at')) {
            $missing[] = 'Ausstellungsdatum';
        }
        $period = is_array($snapshot['service_period'] ?? null) ? $snapshot['service_period'] : [];
        if (!$filled($period, 'start') || !$filled($period, 'end')) {
            $missing[] = 'Leistungszeitpunkt bzw. -zeitraum';
        }
        if ($items === []) {
            $missing[] = 'Menge und Art der Leistung';
        }
        foreach ($items as $item) {
            if (!is_array($item) || trim((string) ($item['description'] ?? '')) === '' || (float) ($item['quantity'] ?? 0) <= 0) {
                $missing[] = 'Menge und Art der Leistung';
                break;
            }
        }
        if (!$filled($totals, 'currency') || !isset($totals['net'], $totals['tax'], $totals['gross'])) {
            $missing[] = 'Entgelt und Steuerbetrag';
        }
        if (!isset(self::TAX_MODES[$mode])) {
            $missing[] = 'Steuermodus';
        } elseif ($mode === self::TAX_STANDARD) {
            $rates = is_array($totals['by_rate'] ?? null) ? $totals['by_rate'] : [];
            if ($rates === []) {
                $missing[] = 'Entgelt nach Steuersätzen';
            }
            $zero = array_filter($rates, static fn (mixed $rate): bool => is_array($rate) && (float) ($rate['rate'] ?? 0) == 0.0);
            if ($zero !== [] && !$filled($snapshot, 'tax_note')) {
                $missing[] = 'Hinweis auf die Steuerbefreiung';
            }
        } elseif ($mode === self::TAX_REVERSE_CHARGE && !$filled($customer, 'vat_id')) {
            $missing[] = 'USt-IdNr. des Leistungsempfängers (Reverse Charge)';
        }
        if (($snapshot['type'] ?? '') === self::TYPE_CANCELLATION) {
            $cancels = is_array($snapshot['cancels'] ?? null) ? $snapshot['cancels'] : [];
            if (!$filled($cancels, 'number') || !$filled($cancels, 'issued_at')) {
                $missing[] = 'Verweis auf die stornierte Rechnung';
            }
        }

        return array_values(array_unique($missing));
    }

    public static function formatMoney(float $amount, string $currency): string
    {
        return number_format($amount, 2, ',', '.') . ' ' . ($currency === 'EUR' ? '€' : $currency);
    }

    public static function formatDate(string $date): string
    {
        $timestamp = strtotime($date);

        return $timestamp === false ? $date : date('d.m.Y', $timestamp);
    }

    public static function formatRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',') . ' %';
    }

    /**
     * HTML des PDFs aus dem Snapshot (alle Werte escaped; keine Skripte, keine externen Ressourcen).
     *
     * @param array<string, mixed> $snapshot
     */
    public static function renderHtml(array $snapshot): string
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $seller = (array) ($snapshot['seller'] ?? []);
        $customer = (array) ($snapshot['customer'] ?? []);
        $totals = (array) ($snapshot['totals'] ?? []);
        $currency = (string) ($totals['currency'] ?? 'EUR');
        $money = static fn (mixed $amount): string => self::formatMoney((float) $amount, $currency);
        $isCancellation = ($snapshot['type'] ?? '') === self::TYPE_CANCELLATION;
        $mode = (string) ($snapshot['tax_mode'] ?? self::TAX_STANDARD);
        $title = $isCancellation ? 'Stornorechnung' : 'Rechnung';
        $period = (array) ($snapshot['service_period'] ?? []);
        $periodText = ($period['start'] ?? '') === ($period['end'] ?? '')
            ? 'Leistungsdatum: ' . self::formatDate((string) ($period['start'] ?? ''))
            : 'Leistungszeitraum: ' . self::formatDate((string) ($period['start'] ?? '')) . ' – ' . self::formatDate((string) ($period['end'] ?? ''));

        $lines = static fn (array $values): string => implode('<br>', array_map($e, array_values(array_filter($values, static fn (mixed $v): bool => trim((string) $v) !== ''))));
        $sellerLine = $lines([$seller['name'] ?? '', $seller['street'] ?? '', trim(($seller['zip'] ?? '') . ' ' . ($seller['city'] ?? '')), $seller['country'] ?? '']);
        $customerLine = $lines([$customer['company'] ?? '', $customer['name'] ?? '', $customer['street'] ?? '', trim(($customer['zip'] ?? '') . ' ' . ($customer['city'] ?? '')), $customer['country'] ?? '']);

        $taxIds = [];
        if (trim((string) ($seller['tax_number'] ?? '')) !== '') {
            $taxIds[] = 'Steuernummer: ' . $e($seller['tax_number']);
        }
        if (trim((string) ($seller['vat_id'] ?? '')) !== '') {
            $taxIds[] = 'USt-IdNr.: ' . $e($seller['vat_id']);
        }

        $meta = '<tr><td>Rechnungsnummer:</td><td><strong>' . $e($snapshot['number'] ?? '') . '</strong></td></tr>'
            . '<tr><td>Rechnungsdatum:</td><td>' . $e(self::formatDate((string) ($snapshot['issued_at'] ?? ''))) . '</td></tr>'
            . '<tr><td colspan="2">' . $e($periodText) . '</td></tr>';
        if (!empty($snapshot['order']['order_number'])) {
            $meta .= '<tr><td>Bestellnummer:</td><td>' . $e($snapshot['order']['order_number']) . '</td></tr>';
        }
        if (trim((string) ($customer['vat_id'] ?? '')) !== '') {
            $meta .= '<tr><td>USt-IdNr. Empfänger:</td><td>' . $e($customer['vat_id']) . '</td></tr>';
        }

        $rows = '';
        foreach ((array) ($snapshot['items'] ?? []) as $index => $item) {
            $item = (array) $item;
            $rows .= '<tr><td>' . ((int) $index + 1) . '</td><td>' . $e($item['description'] ?? '') . '</td><td class="r">'
                . $e(rtrim(rtrim(number_format((float) ($item['quantity'] ?? 0), 2, ',', '.'), '0'), ',')) . '</td><td class="r">' . $e($money($item['unit_net'] ?? 0))
                . '</td><td class="r">' . $e($mode === self::TAX_STANDARD ? self::formatRate((float) ($item['tax_rate'] ?? 0)) : '–')
                . '</td><td class="r">' . $e($money($item['net'] ?? 0)) . '</td></tr>';
        }

        $sum = '';
        if ($mode === self::TAX_STANDARD) {
            foreach ((array) ($totals['by_rate'] ?? []) as $rate) {
                $rate = (array) $rate;
                $label = self::formatRate((float) ($rate['rate'] ?? 0));
                $sum .= '<tr><td>Nettobetrag (' . $e($label) . '):</td><td class="r">' . $e($money($rate['net'] ?? 0)) . '</td></tr>'
                    . '<tr><td>Umsatzsteuer ' . $e($label) . ':</td><td class="r">' . $e($money($rate['tax'] ?? 0)) . '</td></tr>';
            }
        } else {
            $sum .= '<tr><td>Nettobetrag:</td><td class="r">' . $e($money($totals['net'] ?? 0)) . '</td></tr>'
                . '<tr><td>Umsatzsteuer:</td><td class="r">' . $e($money(0)) . '</td></tr>';
        }
        $sum .= '<tr class="total"><td>Gesamtbetrag:</td><td class="r">' . $e($money($totals['gross'] ?? 0)) . '</td></tr>';

        $notes = [];
        if ($isCancellation) {
            $cancels = (array) ($snapshot['cancels'] ?? []);
            $notes[] = 'Diese Stornorechnung storniert die Rechnung Nr. ' . ($cancels['number'] ?? '') . ' vom ' . self::formatDate((string) ($cancels['issued_at'] ?? '')) . '.';
            if (trim((string) ($snapshot['reason'] ?? '')) !== '') {
                $notes[] = 'Grund: ' . $snapshot['reason'];
            }
        }
        if ($mode === self::TAX_SMALL_BUSINESS) {
            $notes[] = self::SMALL_BUSINESS_NOTE;
        } elseif ($mode === self::TAX_REVERSE_CHARGE) {
            $notes[] = self::REVERSE_CHARGE_NOTE;
        }
        if (trim((string) ($snapshot['tax_note'] ?? '')) !== '' && $mode === self::TAX_STANDARD) {
            $notes[] = (string) $snapshot['tax_note'];
        }
        if (!empty($snapshot['small_amount'])) {
            $notes[] = 'Kleinbetragsrechnung (§ 33 UStDV).';
        }
        if (trim((string) ($snapshot['payment_note'] ?? '')) !== '') {
            $notes[] = (string) $snapshot['payment_note'];
        }

        $footer = [];
        if ($taxIds !== []) {
            $footer[] = implode(' · ', $taxIds);
        }
        $contact = array_filter([$seller['email'] ?? '', $seller['phone'] ?? '', $seller['website'] ?? ''], static fn (mixed $v): bool => trim((string) $v) !== '');
        if ($contact !== []) {
            $footer[] = $e(implode(' · ', $contact));
        }
        $director = trim((string) ($seller['managing_director'] ?? ''));
        $register = array_filter([$director !== '' ? 'Geschäftsführung: ' . $director : '', $seller['register'] ?? ''], static fn (mixed $v): bool => trim((string) $v) !== '');
        if ($register !== []) {
            $footer[] = $e(implode(' · ', $register));
        }
        $bank = array_filter([$seller['bank_name'] ?? '', ($seller['iban'] ?? '') !== '' ? 'IBAN ' . $seller['iban'] : '', ($seller['bic'] ?? '') !== '' ? 'BIC ' . $seller['bic'] : ''], static fn (mixed $v): bool => trim((string) $v) !== '');
        if ($bank !== []) {
            $footer[] = $e(implode(' · ', $bank));
        }
        if (trim((string) ($seller['footer_note'] ?? '')) !== '') {
            $footer[] = $e($seller['footer_note']);
        }

        $css = 'body{font-family:DejaVu Sans,sans-serif;font-size:9.5pt;color:#1e293b;margin:1.6cm 1.8cm}'
            . 'h1{font-size:16pt;margin:18pt 0 6pt}table{width:100%;border-collapse:collapse}'
            . '.sender{font-size:7.5pt;color:#64748b;border-bottom:1px solid #cbd5e1;margin-bottom:4pt}'
            . '.items th,.items td{border-bottom:1px solid #e2e8f0;padding:4pt;text-align:left}.items th{background:#f1f5f9}'
            . '.r{text-align:right}.sum{width:55%;margin-left:45%;margin-top:8pt}.sum td{padding:2pt 4pt}'
            . '.total td{font-weight:bold;border-top:1px solid #1e293b}.notes{margin-top:14pt}.foot{margin-top:24pt;font-size:7.5pt;color:#64748b}';

        return '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>' . $e($title . ' ' . ($snapshot['number'] ?? '')) . '</title><style>' . $css . '</style></head><body>'
            . '<div class="sender">' . $e(trim(($seller['name'] ?? '') . ' · ' . ($seller['street'] ?? '') . ' · ' . trim(($seller['zip'] ?? '') . ' ' . ($seller['city'] ?? '')), ' ·')) . '</div>'
            . '<table><tr><td>' . $customerLine . '</td><td><table>' . $meta . '</table></td></tr></table>'
            . '<h1>' . $e($title) . '</h1>'
            . '<table class="items"><thead><tr><th>Pos.</th><th>Leistung</th><th class="r">Menge</th><th class="r">Einzelpreis (netto)</th><th class="r">USt</th><th class="r">Betrag (netto)</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<table class="sum">' . $sum . '</table>'
            . '<div class="notes">' . implode('', array_map(static fn (string $note): string => '<p>' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</p>', $notes)) . '</div>'
            . '<div class="foot"><p>' . $sellerLine . '</p><p>' . implode('</p><p>', $footer) . '</p></div>'
            . '</body></html>';
    }

    // ── Einstellungen ──────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, string> wirksame Einstellungen (Standardwerte ergänzt)
     */
    public function settings(): array
    {
        try {
            $stored = ($this->readSettings)();
        } catch (\Throwable) {
            $stored = [];
        }
        $settings = self::SETTING_DEFAULTS;
        foreach (self::SETTING_DEFAULTS as $key => $default) {
            if (isset($stored[$key]) && is_scalar($stored[$key])) {
                $settings[$key] = trim((string) $stored[$key]);
            }
        }
        if (self::validateFormat($settings['number_format']) !== null) {
            $settings['number_format'] = self::DEFAULT_NUMBER_FORMAT;
        }
        if (self::validateFormat($settings['cancellation_format']) !== null) {
            $settings['cancellation_format'] = self::DEFAULT_CANCELLATION_FORMAT;
        }
        if (!isset(self::TAX_MODES[$settings['tax_mode']])) {
            $settings['tax_mode'] = self::TAX_STANDARD;
        }

        return $settings;
    }

    /**
     * Absenderdaten: eigene Einstellung, sonst Rechtstexte-Profil. `sources` nennt die Herkunft je Feld.
     *
     * @return array{data: array<string, string>, sources: array<string, string>}
     */
    public function seller(?array $settings = null): array
    {
        $settings ??= $this->settings();
        try {
            $profile = ($this->readLegalProfile)();
        } catch (\Throwable) {
            $profile = [];
        }
        $p = static fn (string $key): string => trim((string) ($profile[$key] ?? ''));
        $data = [];
        $sources = [];
        $take = static function (string $field, string $own, string $fallback) use (&$data, &$sources): void {
            $data[$field] = $own !== '' ? $own : $fallback;
            $sources[$field] = $own !== '' ? 'invoices' : ($fallback !== '' ? 'legal_profile' : '');
        };

        $profileName = $p('legal_profile_entity_type') === 'private' || $p('legal_profile_company_name') === ''
            ? $p('legal_profile_owner_name')
            : $p('legal_profile_company_name');
        if ($profileName !== '' && $p('legal_profile_legal_form') !== '' && $p('legal_profile_entity_type') !== 'private'
            && stripos($profileName, $p('legal_profile_legal_form')) === false) {
            $profileName .= ' ' . $p('legal_profile_legal_form');
        }
        $take('name', $settings['sender_name'], $profileName);
        foreach (['street' => 'sender_street', 'zip' => 'sender_zip', 'city' => 'sender_city', 'country' => 'sender_country', 'email' => 'sender_email', 'vat_id' => 'vat_id'] as $field => $key) {
            $take($field, $settings[$key], $p(self::LEGAL_PROFILE_FALLBACK[$key]));
        }
        $take('tax_number', $settings['tax_number'], '');
        $take('phone', '', $p('legal_profile_phone'));
        $take('website', '', $p('legal_profile_website'));
        $take('managing_director', '', $p('legal_profile_managing_director'));
        $take('register', '', trim($p('legal_profile_register_court') . ' ' . $p('legal_profile_register_number')));
        foreach (['bank_name', 'iban', 'bic', 'footer_note'] as $key) {
            $take($key, $settings[$key], '');
        }

        return ['data' => $data, 'sources' => $sources];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{success: bool, message?: string, error?: string}
     */
    public function saveSettings(array $post): array
    {
        $text = static fn (string $key, int $max): string => mb_substr(trim(strip_tags(is_scalar($post[$key] ?? null) ? (string) $post[$key] : '')), 0, $max);
        $values = [];
        foreach (['number_format', 'cancellation_format'] as $key) {
            $values[$key] = $text($key, 40);
            $error = self::validateFormat($values[$key]);
            if ($error !== null) {
                return ['success' => false, 'error' => ($key === 'number_format' ? 'Rechnungen: ' : 'Storno-Rechnungen: ') . $error];
            }
        }
        $mode = $text('tax_mode', 20);
        if (!isset(self::TAX_MODES[$mode])) {
            return ['success' => false, 'error' => 'Unbekannter Steuermodus.'];
        }
        $values['tax_mode'] = $mode;
        $values['start_number'] = (string) max(1, min(999999999, (int) ($post['start_number'] ?? 1)));
        $values['auto_create'] = !empty($post['auto_create']) ? '1' : '0';
        $values['send_mail'] = !empty($post['send_mail']) ? '1' : '0';
        foreach (['sender_name' => 150, 'sender_street' => 150, 'sender_zip' => 20, 'sender_city' => 100, 'sender_country' => 100,
            'tax_number' => 40, 'vat_id' => 20, 'bank_name' => 100, 'bic' => 11, 'exempt_note' => 300, 'footer_note' => 300] as $key => $max) {
            $values[$key] = $text($key, $max);
        }
        $email = $text('sender_email', 150);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['success' => false, 'error' => 'Bitte eine gültige Absender-E-Mail angeben.'];
        }
        $values['sender_email'] = $email;
        $iban = strtoupper((string) preg_replace('/\s+/', '', $text('iban', 40)));
        if ($iban !== '' && preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban) !== 1) {
            return ['success' => false, 'error' => 'Die IBAN hat kein gültiges Format.'];
        }
        $values['iban'] = $iban;
        $values['vat_id'] = strtoupper((string) preg_replace('/\s+/', '', $values['vat_id']));

        return ($this->writeSettings)($values)
            ? ['success' => true, 'message' => 'Rechnungseinstellungen gespeichert.']
            : ['success' => false, 'error' => 'Einstellungen konnten nicht gespeichert werden.'];
    }

    // ── Rechnungen erstellen ───────────────────────────────────────────────────────────────────────────────────

    /**
     * Rechnung für eine bezahlte Bestellung. Idempotent: Hat die Bestellung bereits eine nicht stornierte Rechnung,
     * wird diese zurückgegeben (`existing`).
     *
     * @param array{period_end?: int, tax_mode?: string, customer_vat_id?: string, send_mail?: bool, created_by?: int, source?: string} $options
     * @return array{success: bool, invoice?: array<string, mixed>, existing?: bool, error?: string, missing?: list<string>}
     */
    public function createForOrder(int $orderId, array $options = []): array
    {
        $order = $this->loadOrder($orderId);
        if ($order === null) {
            return ['success' => false, 'error' => 'Bestellung nicht gefunden.'];
        }
        if (!in_array(strtolower(trim((string) ($order->status ?? ''))), self::PAID_STATUSES, true)) {
            return ['success' => false, 'error' => 'Rechnungen werden nur für bezahlte Bestellungen erstellt.'];
        }
        $existing = $this->activeInvoiceIdForOrder($orderId);
        if ($existing !== null) {
            return ['success' => true, 'existing' => true, 'invoice' => (array) $this->invoice($existing)];
        }

        $settings = $this->settings();
        $now = ($this->clock)();
        $built = $this->buildInvoiceSnapshot($order, $settings, $options, $now);
        if (isset($built['error'])) {
            return ['success' => false, 'error' => $built['error']];
        }
        $snapshot = $built['snapshot'];

        return $this->issue(
            $snapshot,
            $settings['number_format'],
            (int) $settings['start_number'],
            [
                'type' => self::TYPE_INVOICE,
                'order_id' => $orderId,
                'user_id' => (int) ($order->user_id ?? 0) ?: null,
                'cancels_invoice_id' => null,
                'created_by' => (int) ($options['created_by'] ?? 0) ?: null,
            ],
            fn (): ?string => $this->activeInvoiceIdForOrder($orderId) !== null ? 'Für diese Bestellung wurde soeben eine Rechnung erstellt.' : null,
            $options['send_mail'] ?? ($settings['send_mail'] === '1'),
            $now
        );
    }

    /**
     * Storno-Rechnung zu einer Rechnung (eigene Nummer, Verweis, negative Beträge). Je Rechnung genau eine.
     *
     * @return array{success: bool, invoice?: array<string, mixed>, error?: string, missing?: list<string>}
     */
    public function createCancellation(int $invoiceId, string $reason = '', int $actorId = 0, ?bool $sendMail = null): array
    {
        $original = $this->invoice($invoiceId);
        if ($original === null) {
            return ['success' => false, 'error' => 'Rechnung nicht gefunden.'];
        }
        if ($original['type'] !== self::TYPE_INVOICE) {
            return ['success' => false, 'error' => 'Eine Storno-Rechnung kann nicht storniert werden.'];
        }
        if ($this->cancellationIdFor($invoiceId) !== null) {
            return ['success' => false, 'error' => 'Diese Rechnung wurde bereits storniert.'];
        }

        $settings = $this->settings();
        $now = ($this->clock)();
        $source = $original['snapshot'];
        $negate = static fn (mixed $value): float => round(-1 * (float) $value, 2);
        $items = [];
        foreach ((array) ($source['items'] ?? []) as $item) {
            $item = (array) $item;
            $items[] = ['unit_net' => $negate($item['unit_net'] ?? 0), 'net' => $negate($item['net'] ?? 0), 'tax' => $negate($item['tax'] ?? 0)] + $item;
        }
        $byRate = [];
        foreach ((array) ($source['totals']['by_rate'] ?? []) as $rate) {
            $rate = (array) $rate;
            $byRate[] = ['net' => $negate($rate['net'] ?? 0), 'tax' => $negate($rate['tax'] ?? 0)] + $rate;
        }
        $seller = $this->seller($settings)['data'];
        $snapshot = [
            'version' => self::SNAPSHOT_VERSION,
            'type' => self::TYPE_CANCELLATION,
            'number' => '',
            'issued_at' => date('Y-m-d', $now),
            'service_period' => (array) ($source['service_period'] ?? []),
            'seller' => array_filter($seller, static fn (string $v): bool => $v !== '') !== [] ? $seller : (array) ($source['seller'] ?? []),
            'customer' => (array) ($source['customer'] ?? []),
            'items' => $items,
            'totals' => [
                'currency' => (string) ($source['totals']['currency'] ?? $original['currency']),
                'net' => $negate($source['totals']['net'] ?? 0),
                'tax' => $negate($source['totals']['tax'] ?? 0),
                'gross' => $negate($source['totals']['gross'] ?? 0),
                'by_rate' => $byRate,
            ],
            'tax_mode' => (string) ($source['tax_mode'] ?? self::TAX_STANDARD),
            'tax_note' => (string) ($source['tax_note'] ?? ''),
            'small_amount' => !empty($source['small_amount']),
            'order' => (array) ($source['order'] ?? []),
            'cancels' => ['id' => $invoiceId, 'number' => $original['invoice_number'], 'issued_at' => substr($original['issued_at'], 0, 10)],
            'reason' => mb_substr(trim(strip_tags($reason)), 0, 300),
            'payment_note' => '',
        ];

        return $this->issue(
            $snapshot,
            $settings['cancellation_format'],
            (int) $settings['start_number'],
            [
                'type' => self::TYPE_CANCELLATION,
                'order_id' => $original['order_id'] ?: null,
                'user_id' => $original['user_id'] ?: null,
                'cancels_invoice_id' => $invoiceId,
                'created_by' => $actorId ?: null,
            ],
            fn (): ?string => $this->cancellationIdFor($invoiceId) !== null ? 'Diese Rechnung wurde soeben storniert.' : null,
            $sendMail ?? ($settings['send_mail'] === '1'),
            $now
        );
    }

    /**
     * Mail mit PDF erneut senden (Admin).
     *
     * @return array{success: bool, message?: string, error?: string}
     */
    public function resendMail(int $invoiceId): array
    {
        $invoice = $this->invoice($invoiceId);
        if ($invoice === null) {
            return ['success' => false, 'error' => 'Rechnung nicht gefunden.'];
        }

        return $this->mailInvoice($invoice)
            ? ['success' => true, 'message' => 'Rechnung ' . $invoice['invoice_number'] . ' wurde zum Versand eingeplant.']
            : ['success' => false, 'error' => 'Versand nicht möglich (keine gültige E-Mail-Adresse oder Datei fehlt).'];
    }

    // ── Lesen, Rechte, Download ────────────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    public function invoice(int $id): ?array
    {
        $row = $id > 0 ? $this->db->get_row("SELECT * FROM {$this->prefix}invoices WHERE id = ? LIMIT 1", [$id]) : null;

        return $row !== null ? $this->mapInvoice($row, true) : null;
    }

    /**
     * @param array{q?: string, type?: string, user_id?: int} $filter
     * @return list<array<string, mixed>>
     */
    public function listInvoices(array $filter = [], int $limit = 100, int $offset = 0): array
    {
        $where = [];
        $params = [];
        $q = trim((string) ($filter['q'] ?? ''));
        if ($q !== '') {
            $where[] = 'invoice_number LIKE ?';
            $params[] = '%' . addcslashes(mb_substr($q, 0, 40), '%_\\') . '%';
        }
        if (in_array($filter['type'] ?? '', [self::TYPE_INVOICE, self::TYPE_CANCELLATION], true)) {
            $where[] = 'type = ?';
            $params[] = $filter['type'];
        }
        if ((int) ($filter['user_id'] ?? 0) > 0) {
            $where[] = 'user_id = ?';
            $params[] = (int) $filter['user_id'];
        }
        $rows = $this->db->get_results(
            "SELECT * FROM {$this->prefix}invoices" . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY issued_at DESC, id DESC LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset),
            $params
        ) ?: [];
        $cancelled = $this->cancelledIds(array_map(static fn (object $row): int => (int) $row->id, $rows));

        return array_map(fn (object $row): array => $this->mapInvoice($row, false) + ['is_cancelled' => isset($cancelled[(int) $row->id])], $rows);
    }

    /**
     * Rechnungen des Kontos für den Mitgliederbereich (ohne gesperrte).
     *
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $rows = $this->db->get_results(
            "SELECT * FROM {$this->prefix}invoices WHERE user_id = ? AND restricted_at IS NULL ORDER BY issued_at DESC, id DESC LIMIT 500",
            [$userId]
        ) ?: [];
        $cancelled = $this->cancelledIds(array_map(static fn (object $row): int => (int) $row->id, $rows));

        return array_map(fn (object $row): array => $this->mapInvoice($row, false) + ['is_cancelled' => isset($cancelled[(int) $row->id])], $rows);
    }

    /**
     * DSGVO-Auskunft (Art. 15): alle gespeicherten Rechnungen der Person – auch gesperrte –, zugeordnet über das
     * Konto oder die E-Mail-Adresse der Bestellung. Mit den Rechnungsdaten (Snapshot), ohne Speicherpfad.
     *
     * @return list<array<string, mixed>>
     */
    public function exportForPrivacy(int $userId, string $email): array
    {
        $email = strtolower(trim($email));
        if ($userId <= 0 && $email === '') {
            return [];
        }
        $rows = $this->db->get_results(
            "SELECT * FROM {$this->prefix}invoices
             WHERE (user_id = ? AND user_id > 0)
                OR (? <> '' AND order_id IN (SELECT id FROM {$this->prefix}orders WHERE LOWER(email) = ? OR LOWER(customer_email) = ?))
             ORDER BY issued_at ASC, id ASC LIMIT 2000",
            [$userId, $email, $email, $email]
        ) ?: [];

        return array_map(function (object $row): array {
            $invoice = $this->mapInvoice($row, true);
            unset($invoice['pdf_path']);

            return $invoice;
        }, $rows);
    }

    /**
     * Darf das Konto die Rechnung sehen? Eigentümer (nicht gesperrt) oder Recht `invoices.view`.
     *
     * @param array<string, mixed> $invoice
     */
    public static function canAccess(array $invoice, int $userId, bool $canViewAll): bool
    {
        if ($canViewAll) {
            return true;
        }

        return $userId > 0
            && (int) ($invoice['user_id'] ?? 0) === $userId
            && (string) ($invoice['restricted_at'] ?? '') === '';
    }

    /**
     * Datei für den Download – nur mit Recht, vorhandener Datei im Rechnungsbereich und passender SHA-256.
     *
     * @return array{path: string, filename: string, invoice: array<string, mixed>}|null
     */
    public function downloadFor(int $invoiceId, int $userId, bool $canViewAll): ?array
    {
        $invoice = $this->invoice($invoiceId);
        if ($invoice === null || !self::canAccess($invoice, $userId, $canViewAll)) {
            return null;
        }
        $path = $this->resolvePdf((string) $invoice['pdf_path']);
        if ($path === null) {
            $this->log('warning', 'Rechnungs-PDF fehlt.', ['invoice' => $invoiceId]);

            return null;
        }
        if (!hash_equals((string) $invoice['pdf_sha256'], (string) hash_file('sha256', $path))) {
            $this->log('error', 'Rechnungs-PDF wurde verändert (SHA-256 stimmt nicht).', ['invoice' => $invoiceId]);

            return null;
        }

        return ['path' => $path, 'filename' => self::downloadName($invoice), 'invoice' => $invoice];
    }

    /** @param array<string, mixed> $invoice */
    public static function downloadName(array $invoice): string
    {
        return (string) preg_replace('/[^A-Za-z0-9\-_.]+/', '_', (string) $invoice['invoice_number']) . '.pdf';
    }

    /**
     * Bezahlte Bestellungen ohne gültige Rechnung (Admin: manuell erstellen, z. B. Rechnungskauf).
     *
     * @return list<array{id: int, order_number: string, customer: string, total: float, currency: string, created_at: string}>
     */
    public function ordersWithoutInvoice(int $limit = 50): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::PAID_STATUSES), '?'));
        try {
            $rows = $this->db->get_results(
                "SELECT o.id, o.order_number, o.forename, o.lastname, o.company, o.customer_name, o.email, o.customer_email, o.total_amount, o.amount, o.tax_amount, o.currency, o.created_at
                 FROM {$this->prefix}orders o
                 WHERE LOWER(o.status) IN ({$placeholders})
                   AND NOT EXISTS (
                     SELECT 1 FROM {$this->prefix}invoices i
                     WHERE i.order_id = o.id AND i.type = 'invoice'
                       AND NOT EXISTS (SELECT 1 FROM {$this->prefix}invoices c WHERE c.cancels_invoice_id = i.id)
                   )
                 ORDER BY o.id DESC LIMIT " . max(1, min(200, $limit)),
                self::PAID_STATUSES
            ) ?: [];
        } catch (\Throwable $e) {
            $this->log('warning', 'Bestellungen ohne Rechnung konnten nicht gelesen werden.', ['exception' => $e->getMessage()]);

            return [];
        }

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'order_number' => (string) $row->order_number,
            'customer' => trim((string) ($row->company ?? '') . ' ' . trim((string) ($row->forename ?? '') . ' ' . (string) ($row->lastname ?? ''))) ?: (string) ($row->customer_name ?? $row->email ?? $row->customer_email ?? ''),
            'total' => (float) ((float) ($row->total_amount ?? 0) > 0 ? $row->total_amount : (float) ($row->amount ?? 0) + (float) ($row->tax_amount ?? 0)),
            'currency' => (string) ($row->currency ?: 'EUR'),
            'created_at' => (string) $row->created_at,
        ], $rows);
    }

    /**
     * Nächste Nummern je Format zum aktuellen Datum (Vorschau im Admin, ohne Vergabe).
     *
     * @return array{invoice: string, cancellation: string}
     */
    public function nextNumbers(): array
    {
        $settings = $this->settings();
        $now = ($this->clock)();
        $preview = function (string $format) use ($settings, $now): string {
            $next = $this->db->get_var("SELECT next_number FROM {$this->prefix}invoice_sequences WHERE sequence_key = ?", [self::sequenceKey($format, $now)]);

            return self::formatNumber($format, $next !== null ? (int) $next : max(1, (int) $settings['start_number']), $now);
        };

        return ['invoice' => $preview($settings['number_format']), 'cancellation' => $preview($settings['cancellation_format'])];
    }

    // ── Aufbewahrung und DSGVO ─────────────────────────────────────────────────────────────────────────────────

    /**
     * DSGVO-Löschung: Rechnungen bleiben (Aufbewahrungspflicht § 147 AO, § 14b UStG; Art. 17 Abs. 3 lit. b DSGVO),
     * erhalten aber einen Sperrvermerk (Art. 18 DSGVO) – danach nur noch im Admin sichtbar.
     */
    public function restrictForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        return $this->db->execute(
            "UPDATE {$this->prefix}invoices SET restricted_at = ? WHERE user_id = ? AND restricted_at IS NULL",
            [date('Y-m-d H:i:s', ($this->clock)()), $userId]
        )->rowCount();
    }

    /** Rechnungen, deren 10-jährige Frist (ab Ende des Ausstellungsjahres) abgelaufen ist – nur Anzeige, nie Löschung. */
    public function countBeyondRetention(): int
    {
        $year = (int) date('Y', ($this->clock)()) - 10;

        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}invoices WHERE issued_at < ?", [sprintf('%04d-01-01 00:00:00', $year)]);
    }

    // ── Intern: Vergabe ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Nummer vergeben, PDF erzeugen und ablegen, Zeile schreiben – alles in einer Transaktion.
     *
     * @param array<string, mixed> $snapshot ohne Nummer
     * @param array{type: string, order_id: ?int, user_id: ?int, cancels_invoice_id: ?int, created_by: ?int} $row
     * @param \Closure(): ?string $precondition nach der Sperre geprüft (Doppelvergabe), Fehlermeldung oder null
     * @return array{success: bool, invoice?: array<string, mixed>, error?: string, missing?: list<string>}
     */
    private function issue(array $snapshot, string $format, int $startNumber, array $row, \Closure $precondition, bool $sendMail, int $now): array
    {
        // Pflichtangaben vor der Vergabe prüfen (Nummer als Platzhalter), damit keine Nummer verbraucht wird.
        $missing = self::missingMandatoryFields(['number' => 'x'] + $snapshot);
        if ($missing !== []) {
            $this->log('warning', 'Rechnung nicht erstellt: Pflichtangaben fehlen.', ['missing' => $missing, 'order' => $row['order_id']]);

            return ['success' => false, 'error' => 'Pflichtangaben fehlen: ' . implode(', ', $missing) . '.', 'missing' => $missing];
        }

        $pdo = $this->db->getPdo();
        $nested = $pdo->inTransaction();
        $mysql = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        for ($attempt = 1; ; $attempt++) {
            $written = null;
            $conflict = null;
            $nested ? $pdo->exec('SAVEPOINT cms_invoice_issue') : $pdo->beginTransaction();
            try {
                $key = self::sequenceKey($format, $now);
                $stamp = date('Y-m-d H:i:s', $now);
                $this->db->execute(
                    ($mysql ? 'INSERT IGNORE' : 'INSERT OR IGNORE') . " INTO {$this->prefix}invoice_sequences (sequence_key, next_number, updated_at) VALUES (?, ?, ?)",
                    [$key, max(1, $startNumber), $stamp]
                );
                $sequenceNumber = (int) $this->db->get_var(
                    "SELECT next_number FROM {$this->prefix}invoice_sequences WHERE sequence_key = ?" . ($mysql ? ' FOR UPDATE' : ''),
                    [$key]
                );
                if ($sequenceNumber < 1) {
                    throw new \RuntimeException('Nummernkreis konnte nicht gelesen werden.');
                }
                $conflict = $precondition();
                if ($conflict !== null) {
                    throw new \RuntimeException($conflict);
                }
                $number = self::formatNumber($format, $sequenceNumber, $now);
                if ($this->db->get_var("SELECT id FROM {$this->prefix}invoices WHERE invoice_number = ? LIMIT 1", [$number]) !== null) {
                    throw new \RuntimeException('Rechnungsnummer ' . $number . ' ist bereits vergeben (Nummernformat prüfen).');
                }
                $snapshot['number'] = $number;
                $missing = self::missingMandatoryFields($snapshot);
                if ($missing !== []) {
                    throw new \RuntimeException('Pflichtangaben fehlen: ' . implode(', ', $missing));
                }

                $pdf = ($this->pdfRenderer)(self::renderHtml($snapshot));
                if (!str_starts_with($pdf, '%PDF')) {
                    throw new \RuntimeException('Das PDF konnte nicht erzeugt werden.');
                }
                $year = date('Y', $now);
                $relative = $year . '/' . preg_replace('/[^A-Za-z0-9\-_.]+/', '_', $number) . '-' . bin2hex(random_bytes(4)) . '.pdf';
                $absolute = rtrim(($this->storageDir)($year), '/\\') . DIRECTORY_SEPARATOR . basename($relative);
                $handle = @fopen($absolute, 'xb');
                if ($handle === false) {
                    throw new \RuntimeException('Rechnungs-PDF konnte nicht gespeichert werden.');
                }
                $written = $absolute;
                $ok = fwrite($handle, $pdf) === strlen($pdf);
                fclose($handle);
                if (!$ok) {
                    throw new \RuntimeException('Rechnungs-PDF konnte nicht vollständig gespeichert werden.');
                }
                @chmod($absolute, 0640);

                $totals = (array) $snapshot['totals'];
                $period = (array) $snapshot['service_period'];
                $this->db->execute(
                    "INSERT INTO {$this->prefix}invoices (invoice_number, sequence_key, sequence_number, type, order_id, user_id, cancels_invoice_id, issued_at,
                        service_period_start, service_period_end, currency, net_total, tax_total, gross_total, tax_mode, snapshot_json, pdf_path, pdf_sha256,
                        created_by, restricted_at, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)",
                    [
                        $number, $key, $sequenceNumber, $row['type'], $row['order_id'], $row['user_id'], $row['cancels_invoice_id'], $stamp,
                        $period['start'] ?? null, $period['end'] ?? null, (string) $totals['currency'],
                        number_format((float) $totals['net'], 2, '.', ''), number_format((float) $totals['tax'], 2, '.', ''), number_format((float) $totals['gross'], 2, '.', ''),
                        (string) $snapshot['tax_mode'], (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        $relative, hash('sha256', $pdf), $row['created_by'], $stamp,
                    ]
                );
                $invoiceId = (int) $this->db->lastInsertId();
                // Vergleichen-und-Setzen: läuft parallel eine zweite Vergabe, scheitert eine der beiden hier.
                $updated = $this->db->execute(
                    "UPDATE {$this->prefix}invoice_sequences SET next_number = next_number + 1, updated_at = ? WHERE sequence_key = ? AND next_number = ?",
                    [$stamp, $key, $sequenceNumber]
                )->rowCount();
                if ($updated !== 1) {
                    throw new \RuntimeException('Nummernkreis wurde parallel geändert – bitte erneut versuchen.');
                }
                $nested ? $pdo->exec('RELEASE SAVEPOINT cms_invoice_issue') : $pdo->commit();
                break;
            } catch (\Throwable $e) {
                try {
                    if ($nested) {
                        $pdo->exec('ROLLBACK TO SAVEPOINT cms_invoice_issue');
                        $pdo->exec('RELEASE SAVEPOINT cms_invoice_issue');
                    } elseif ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (\Throwable) {
                }
                if ($written !== null && is_file($written)) {
                    @unlink($written);
                }
                // Sperrkonflikt (Deadlock, Lock-Timeout, SQLite belegt): bis zu dreimal neu versuchen – die Nummer war nie vergeben.
                if (!$nested && $conflict === null && $attempt < self::MAX_ATTEMPTS && self::isRetryable($e)) {
                    usleep(100000 * $attempt);
                    continue;
                }
                if ($conflict !== null) {
                    $existing = $row['type'] === self::TYPE_INVOICE && $row['order_id'] !== null ? $this->activeInvoiceIdForOrder((int) $row['order_id']) : null;

                    return $existing !== null
                        ? ['success' => true, 'existing' => true, 'invoice' => (array) $this->invoice($existing)]
                        : ['success' => false, 'error' => $conflict];
                }
                $this->log('error', 'Rechnung konnte nicht erstellt werden.', ['order' => $row['order_id'], 'exception' => $e->getMessage()]);

                return ['success' => false, 'error' => 'Rechnung konnte nicht erstellt werden: ' . ($e instanceof \PDOException
                    ? 'Datenbank belegt oder Fehler beim Speichern – bitte erneut versuchen.'
                    : self::cleanError($e->getMessage()))];
            }
        }

        $invoice = (array) $this->invoice($invoiceId);
        if ($sendMail) {
            $this->mailInvoice($invoice);
        }
        try {
            Hooks::doAction('cms_invoice_created', $invoiceId, (string) $row['type'], (int) ($row['order_id'] ?? 0), (int) ($row['user_id'] ?? 0));
        } catch (\Throwable $e) {
            $this->log('warning', 'Hook cms_invoice_created fehlgeschlagen.', ['exception' => $e->getMessage()]);
        }

        return ['success' => true, 'invoice' => $invoice];
    }

    /**
     * @param array{period_end?: int, tax_mode?: string, customer_vat_id?: string} $options
     * @param array<string, string> $settings
     * @return array{snapshot?: array<string, mixed>, error?: string}
     */
    private function buildInvoiceSnapshot(object $order, array $settings, array $options, int $now): array
    {
        $contact = json_decode((string) ($order->contact_data ?? ''), true);
        $contact = is_array($contact) ? $contact : [];
        $field = static function (string ...$keys) use ($order, $contact): string {
            foreach ($keys as $key) {
                $value = trim((string) ($order->{$key} ?? $contact[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }

            return '';
        };
        $vatInput = trim((string) ($options['customer_vat_id'] ?? ''));
        $customerVat = strtoupper((string) preg_replace('/\s+/', '', $vatInput !== '' ? $vatInput : $field('vat_id', 'ust_id', 'ustid', 'vat')));
        $customer = [
            'name' => trim($field('forename', 'firstname', 'first_name') . ' ' . $field('lastname', 'last_name')) ?: $field('customer_name', 'name'),
            'company' => $field('company'),
            'street' => $field('street', 'address'),
            'zip' => $field('zip', 'postal_code'),
            'city' => $field('city'),
            'country' => $field('country'),
            'email' => $field('email', 'customer_email'),
            'vat_id' => mb_substr($customerVat, 0, 20),
        ];
        if ($customer['name'] === '' && $customer['company'] !== '') {
            $customer['name'] = $customer['company'];
            $customer['company'] = '';
        }

        $currency = strtoupper(substr(trim((string) ($order->currency ?? 'EUR')) ?: 'EUR', 0, 3));
        $net = round((float) ($order->amount ?? 0), 2);
        $tax = round((float) ($order->tax_amount ?? 0), 2);
        $gross = round((float) ($order->total_amount ?? 0), 2);
        if ($gross <= 0.0) {
            $gross = round($net + $tax, 2);
        }

        $mode = (string) ($options['tax_mode'] ?? '') !== '' ? (string) $options['tax_mode'] : $settings['tax_mode'];
        if (!isset(self::TAX_MODES[$mode])) {
            return ['error' => 'Unbekannter Steuermodus.'];
        }
        // Reverse Charge automatisch: Regelbesteuerung, keine Steuer berechnet, EU-Ausland mit USt-IdNr.
        $country = self::countryCode($customer['country']);
        if ($mode === self::TAX_STANDARD && $tax == 0.0 && $customer['vat_id'] !== '' && $country !== 'DE' && in_array($country, self::EU_COUNTRIES, true)) {
            $mode = self::TAX_REVERSE_CHARGE;
        }

        if ($mode === self::TAX_SMALL_BUSINESS) {
            $net = $gross;
            $tax = 0.0;
            $rate = 0.0;
        } elseif ($mode === self::TAX_REVERSE_CHARGE) {
            if ($tax > 0.0) {
                return ['error' => 'Reverse Charge: Die Bestellung enthält Umsatzsteuer – Rechnung bitte manuell prüfen.'];
            }
            $gross = $net;
            $rate = 0.0;
        } else {
            if (abs($net + $tax - $gross) > 0.01) {
                return ['error' => 'Die Beträge der Bestellung sind widersprüchlich (Netto + Steuer ≠ Brutto).'];
            }
            $rate = $net > 0.0 ? round($tax / $net * 100, 2) : 0.0;
            if (abs($rate - round($rate)) < 0.05) {
                $rate = round($rate);
            }
        }
        if ($gross <= 0.0) {
            return ['error' => 'Die Bestellung hat keinen Rechnungsbetrag.'];
        }

        $cycle = strtolower(trim((string) ($order->billing_cycle ?? 'monthly')));
        $cycleLabel = ['monthly' => 'monatlich', 'yearly' => 'jährlich', 'lifetime' => 'einmalig (unbefristet)'][$cycle] ?? $cycle;
        $plan = trim((string) ($order->plan_name ?? ''));
        $description = ($plan !== '' ? 'Abonnement „' . $plan . '“' : 'Abonnement') . ' – ' . $cycleLabel;
        $paidAt = trim((string) ($order->paid_at ?? '')) !== '' ? (string) $order->paid_at : (string) ($order->updated_at ?? $order->created_at ?? '');
        $paidTs = strtotime($paidAt) ?: $now;

        $small = $gross <= self::SMALL_AMOUNT_LIMIT
            && ($customer['street'] === '' || $customer['zip'] === '' || $customer['city'] === '' || $customer['name'] === '');

        $snapshot = [
            'version' => self::SNAPSHOT_VERSION,
            'type' => self::TYPE_INVOICE,
            'number' => '',
            'issued_at' => date('Y-m-d', $now),
            'service_period' => self::servicePeriod($cycle, $paidTs, isset($options['period_end']) ? (int) $options['period_end'] : null),
            'seller' => $this->seller($settings)['data'],
            'customer' => $customer,
            'items' => [[
                'description' => $description,
                'quantity' => 1,
                'unit_net' => $net,
                'net' => $net,
                'tax_rate' => $rate,
                'tax' => $tax,
            ]],
            'totals' => [
                'currency' => $currency,
                'net' => $net,
                'tax' => $tax,
                'gross' => $gross,
                'by_rate' => [['rate' => $rate, 'net' => $net, 'tax' => $tax]],
            ],
            'tax_mode' => $mode,
            'tax_note' => $mode === self::TAX_STANDARD && $tax == 0.0 ? $settings['exempt_note'] : '',
            'small_amount' => $small,
            'order' => [
                'id' => (int) $order->id,
                'order_number' => (string) ($order->order_number ?? ''),
                'payment_method' => (string) ($order->payment_method ?? ''),
                'provider' => (string) ($order->provider ?? ''),
                'paid_at' => date('Y-m-d', $paidTs),
            ],
            'payment_note' => 'Der Rechnungsbetrag wurde am ' . date('d.m.Y', $paidTs) . ' beglichen.',
        ];

        return ['snapshot' => $snapshot];
    }

    /**
     * Leistungszeitraum: Verlängerung = Ende minus Abrechnungszeitraum bis Ende; sonst ab Zahlung.
     *
     * @return array{start: string, end: string}
     */
    public static function servicePeriod(string $cycle, int $paidTs, ?int $periodEnd = null): array
    {
        $interval = match ($cycle) {
            'yearly' => '+1 year',
            'monthly' => '+1 month',
            default => null,
        };
        if ($interval === null) {
            $day = date('Y-m-d', $periodEnd ?? $paidTs);

            return ['start' => $day, 'end' => $day];
        }
        if ($periodEnd !== null && $periodEnd > 0) {
            $back = str_replace('+', '-', $interval);

            return ['start' => date('Y-m-d', (int) strtotime($back, $periodEnd)), 'end' => date('Y-m-d', $periodEnd - 1)];
        }

        return ['start' => date('Y-m-d', $paidTs), 'end' => date('Y-m-d', (int) strtotime($interval, $paidTs) - 86400)];
    }

    private static function countryCode(string $country): string
    {
        $value = strtoupper(trim($country));
        if ($value === '') {
            return 'DE';
        }
        if (strlen($value) === 2) {
            return $value;
        }

        return self::COUNTRY_ALIASES[$value] ?? $value;
    }

    private function loadOrder(int $orderId): ?object
    {
        if ($orderId <= 0) {
            return null;
        }
        try {
            return $this->db->get_row(
                "SELECT o.*, p.name AS plan_name FROM {$this->prefix}orders o LEFT JOIN {$this->prefix}subscription_plans p ON p.id = o.plan_id WHERE o.id = ? LIMIT 1",
                [$orderId]
            );
        } catch (\Throwable) {
            return $this->db->get_row("SELECT * FROM {$this->prefix}orders WHERE id = ? LIMIT 1", [$orderId]);
        }
    }

    /**
     * Release-Prüfung 4.1.0 (FUN-410-27): Stornorechnung zur aktiven Rechnung einer Bestellung (Erstattung beim
     * Anbieter). Ohne Rechnung oder bei bereits storniertem Beleg nichts zu tun.
     *
     * @return array<string, mixed>
     */
    public function cancelForOrder(int $orderId, string $reason): array
    {
        $invoiceId = $this->activeInvoiceIdForOrder($orderId);
        if ($invoiceId === null) {
            return ['success' => true, 'skipped' => true];
        }

        return $this->createCancellation($invoiceId, $reason);
    }

    private function activeInvoiceIdForOrder(int $orderId): ?int
    {
        $id = $this->db->get_var(
            "SELECT i.id FROM {$this->prefix}invoices i WHERE i.order_id = ? AND i.type = ?
               AND NOT EXISTS (SELECT 1 FROM {$this->prefix}invoices c WHERE c.cancels_invoice_id = i.id)
             ORDER BY i.id DESC LIMIT 1",
            [$orderId, self::TYPE_INVOICE]
        );

        return $id !== null ? (int) $id : null;
    }

    private function cancellationIdFor(int $invoiceId): ?int
    {
        $id = $this->db->get_var("SELECT id FROM {$this->prefix}invoices WHERE cancels_invoice_id = ? LIMIT 1", [$invoiceId]);

        return $id !== null ? (int) $id : null;
    }

    /**
     * @param list<int> $ids
     * @return array<int, true>
     */
    private function cancelledIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->get_results(
            "SELECT cancels_invoice_id FROM {$this->prefix}invoices WHERE cancels_invoice_id IN (" . implode(', ', array_fill(0, count($ids), '?')) . ')',
            $ids
        ) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->cancels_invoice_id] = true;
        }

        return $result;
    }

    private function resolvePdf(string $relative): ?string
    {
        if (preg_match('#^\d{4}/[A-Za-z0-9\-_.]+\.pdf$#', $relative) !== 1 || str_contains($relative, '..')) {
            return null;
        }
        try {
            $base = realpath(($this->storageDir)(''));
        } catch (\Throwable) {
            return null;
        }
        $candidate = $base !== false ? realpath($base . DIRECTORY_SEPARATOR . $relative) : false;
        if ($base === false || $candidate === false || !is_file($candidate) || !str_starts_with($candidate, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $candidate;
    }

    /** @param array<string, mixed> $invoice */
    private function mailInvoice(array $invoice): bool
    {
        $email = trim((string) ($invoice['snapshot']['customer']['email'] ?? ''));
        $path = $this->resolvePdf((string) ($invoice['pdf_path'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $path === null) {
            return false;
        }
        $isCancellation = $invoice['type'] === self::TYPE_CANCELLATION;
        $seller = (string) ($invoice['snapshot']['seller']['name'] ?? '');
        $subject = ($isCancellation ? 'Stornorechnung ' : 'Ihre Rechnung ') . $invoice['invoice_number'] . ($seller !== '' ? ' – ' . $seller : '');
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $html = '<p>Guten Tag ' . $e((string) ($invoice['snapshot']['customer']['name'] ?? '')) . ',</p>'
            . '<p>' . ($isCancellation ? 'anbei erhalten Sie die Stornorechnung ' : 'vielen Dank für Ihre Zahlung. Anbei erhalten Sie Ihre Rechnung ')
            . '<strong>' . $e((string) $invoice['invoice_number']) . '</strong> über ' . $e(self::formatMoney((float) $invoice['gross_total'], (string) $invoice['currency'])) . ' als PDF.</p>'
            . '<p>Alle Rechnungen finden Sie auch im Mitgliederbereich unter „Abo &amp; Bestellungen → Rechnungen“.</p>'
            . ($seller !== '' ? '<p>Mit freundlichen Grüßen<br>' . $e($seller) . '</p>' : '');
        try {
            return ($this->mailer)($email, $subject, $html, $path, self::downloadName($invoice));
        } catch (\Throwable $e) {
            $this->log('warning', 'Rechnungsmail konnte nicht eingeplant werden.', ['invoice' => $invoice['id'], 'exception' => $e->getMessage()]);

            return false;
        }
    }

    private static function defaultMailer(string $to, string $subject, string $html, string $path, string $filename): bool
    {
        $mail = \CMS\Services\MailService::getInstance();
        $headers = ['X-365CMS-Test-Source' => 'invoices'];
        if (\CMS\Services\MailQueueService::getInstance()->isEnabled()) {
            return !empty($mail->queueWithAttachment($to, $subject, $html, $path, $filename, true, $headers, null, 'invoices')['success']);
        }

        return $mail->sendWithAttachment($to, $subject, $html, $path, $filename, true, $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapInvoice(object $row, bool $withSnapshot): array
    {
        $invoice = [
            'id' => (int) $row->id,
            'invoice_number' => (string) $row->invoice_number,
            'sequence_key' => (string) $row->sequence_key,
            'sequence_number' => (int) $row->sequence_number,
            'type' => (string) $row->type,
            'order_id' => (int) ($row->order_id ?? 0),
            'user_id' => (int) ($row->user_id ?? 0),
            'cancels_invoice_id' => (int) ($row->cancels_invoice_id ?? 0),
            'issued_at' => (string) $row->issued_at,
            'service_period_start' => (string) ($row->service_period_start ?? ''),
            'service_period_end' => (string) ($row->service_period_end ?? ''),
            'currency' => (string) $row->currency,
            'net_total' => (float) $row->net_total,
            'tax_total' => (float) $row->tax_total,
            'gross_total' => (float) $row->gross_total,
            'tax_mode' => (string) $row->tax_mode,
            'pdf_path' => (string) $row->pdf_path,
            'pdf_sha256' => (string) $row->pdf_sha256,
            'created_by' => (int) ($row->created_by ?? 0),
            'restricted_at' => (string) ($row->restricted_at ?? ''),
            'created_at' => (string) $row->created_at,
        ];
        $snapshot = json_decode((string) $row->snapshot_json, true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $invoice['customer_name'] = trim((string) ($snapshot['customer']['company'] ?? '') . ' ' . (string) ($snapshot['customer']['name'] ?? ''));
        $invoice['cancels_number'] = (string) ($snapshot['cancels']['number'] ?? '');
        if ($withSnapshot) {
            $invoice['snapshot'] = $snapshot;
        }

        return $invoice;
    }

    private static function isRetryable(\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $e->getCode() === '40001' || in_array($driverCode, [1205, 1213], true) || ($driverCode === 5 && str_contains($e->getMessage(), 'locked'));
    }

    private static function cleanError(string $error): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $error)), 0, 300);
    }

    /** @param array<string, mixed> $context */
    private function log(string $level, string $message, array $context = []): void
    {
        try {
            Logger::instance()->withChannel('invoices')->{$level}($message, $context);
        } catch (\Throwable) {
        }
    }
}
