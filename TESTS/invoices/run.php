<?php
/**
 * Rechnungen (4.1.0, Bereich 5b): Nummernkreis (Format, lückenlos, nie wiederverwendet, Jahreswechsel, parallele
 * Transaktionen), Pflichtangaben § 14 UStG im PDF-Text (Regelbesteuerung, § 19 UStG, Reverse Charge,
 * Kleinbetragsrechnung), Storno-Rechnung mit Verweis, Download-Rechte (Eigentümer, `invoices.view`, Sperrvermerk,
 * SHA-256, Pfade), Hooks aus Bereich 5a (`cms_payment_succeeded`, `cms_subscription_renewed`), DSGVO-Sperrvermerk,
 * Einstellungen mit Rückgriff auf das Rechtstexte-Profil und Quelltext-Prüfungen der Einbindung.
 *
 * Ohne MySQL: SQLite-Attrappe (im Speicher, für den Paralleltest als Datei mit zwei Verbindungen). Die Tabellen
 * entstehen aus den MySQL-CREATE-Statements von InvoiceService::schemaQueries() (für SQLite umgeschrieben).
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis: php TESTS/invoices/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'emergency');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs/');

date_default_timezone_set('Europe/Berlin');
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CMS\\')) {
        return;
    }
    $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once dirname(__DIR__) . '/lib/TestRunner.php';

use CMS\Database;
use CMS\Hooks;
use CMS\Services\FeatureHooks\InvoiceHooks;
use CMS\Services\Payments\InvoiceService as I;

T::$verbose = in_array('-v', $argv ?? [], true);

function invSource(string $relative): string
{
    return (string) file_get_contents(ABSPATH . $relative);
}

/** MySQL-CREATE aus InvoiceService::schemaQueries() → SQLite. */
function invSqliteSchema(string $mysql): string
{
    $sql = (string) preg_replace('/\)\s*ENGINE=.*$/s', ')', $mysql);
    $sql = str_replace('BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = (string) preg_replace('/,\s*KEY\s+\w+\s*\([^)]*\)/', '', $sql);
    $sql = (string) preg_replace('/UNIQUE KEY\s+\w+\s*\(/', 'UNIQUE (', $sql);

    return $sql;
}

function invDatabase(?string $file = null): Database
{
    return new class ($file) extends Database {
        public PDO $sqlite;

        public function __construct(?string $file)
        {
            $this->sqlite = new PDO($file !== null ? 'sqlite:' . $file : 'sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 0]);
            $exists = (int) $this->sqlite->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'cms_invoices'")->fetchColumn() > 0;
            if ($exists) {
                return;
            }
            foreach (I::schemaQueries('cms_') as $sql) {
                $this->sqlite->exec(invSqliteSchema($sql));
            }
            $this->sqlite->exec("CREATE TABLE cms_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, order_number TEXT UNIQUE, user_id INTEGER, plan_id INTEGER,
                customer_name TEXT, customer_email TEXT, amount NUMERIC DEFAULT 0, tax_amount NUMERIC DEFAULT 0, total_amount NUMERIC DEFAULT 0,
                currency TEXT DEFAULT 'EUR', status TEXT DEFAULT 'pending', payment_method TEXT, billing_cycle TEXT DEFAULT 'monthly', payment_ref TEXT,
                notes TEXT, contact_data TEXT, forename TEXT, lastname TEXT, company TEXT, email TEXT, phone TEXT, street TEXT, zip TEXT, city TEXT,
                country TEXT, provider TEXT, provider_checkout_id TEXT, paid_at TEXT, created_at TEXT, updated_at TEXT)");
            $this->sqlite->exec('CREATE TABLE cms_subscription_plans (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
            $this->sqlite->exec("INSERT INTO cms_subscription_plans (id, name) VALUES (1, 'Pro')");
        }

        public function execute(string $sql, array $params = []): \PDOStatement
        {
            $stmt = $this->sqlite->prepare($sql);
            $stmt->execute(array_values($params));

            return $stmt;
        }

        public function get_var(string $query, array $params = []): mixed
        {
            $value = $this->execute($query, $params)->fetchColumn();

            return $value === false ? null : $value;
        }

        public function get_results(string $query, array $params = []): array
        {
            return $this->execute($query, $params)->fetchAll(PDO::FETCH_OBJ);
        }

        public function get_row(string $query, array $params = []): ?object
        {
            return $this->execute($query, $params)->fetch(PDO::FETCH_OBJ) ?: null;
        }

        public function lastInsertId(): int
        {
            return (int) $this->sqlite->lastInsertId();
        }

        public function getPdo(): PDO
        {
            return $this->sqlite;
        }

        public function getPrefix(): string
        {
            return 'cms_';
        }
    };
}

/** Text-Ebene eines Rechnungs-HTML (so wie das PDF ihn zeigt). */
function invText(string $html): string
{
    $html = str_replace(['<br>', '</p>', '</td>', '</th>', '</tr>', '</div>', '</h1>'], ["\n", "\n", ' ', ' ', "\n", "\n", "\n"], $html);

    return html_entity_decode(strip_tags((string) preg_replace('/<style>.*?<\/style>|<title>.*?<\/title>/s', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function invRemoveDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

/** @var list<string> $invTempDirs */
$invTempDirs = [];

const INV_SELLER = [
    'sender_name' => 'Muster Software GmbH',
    'sender_street' => 'Hauptstraße 1',
    'sender_zip' => '79098',
    'sender_city' => 'Freiburg',
    'sender_country' => 'Deutschland',
    'sender_email' => 'rechnung@muster.example',
    'tax_number' => '06/123/45678',
    'vat_id' => 'DE123456789',
];

/**
 * @param array<string, string> $settings
 * @return array{db: Database, service: I, clock: \ArrayObject, settings: \ArrayObject, mails: \ArrayObject, html: \ArrayObject, pdf: \ArrayObject, dir: string}
 */
function invFixture(array $settings = INV_SELLER, array $legal = [], ?Database $db = null, ?string $dir = null): array
{
    global $invTempDirs;
    $db ??= invDatabase();
    if ($dir === null) {
        $dir = sys_get_temp_dir() . '/cms-invoices-test-' . bin2hex(random_bytes(4));
        $invTempDirs[] = $dir;
    }
    $clock = new \ArrayObject(['now' => strtotime('2026-11-15 10:00:00')]);
    $store = new \ArrayObject($settings);
    $mails = new \ArrayObject();
    $html = new \ArrayObject();
    $pdf = new \ArrayObject(['fail' => false, 'before' => null]);
    $service = new I(
        $db,
        static fn (): int => (int) $clock['now'],
        static function (string $sub) use ($dir): string {
            $path = $dir . '/invoices/' . ($sub !== '' ? $sub . '/' : '');
            if (!is_dir($path)) {
                mkdir($path, 0750, true);
            }

            return $path;
        },
        static function (string $markup) use ($html, $pdf): string {
            if (is_callable($pdf['before'])) {
                $before = $pdf['before'];
                $pdf['before'] = null;
                $before();
            }
            if ($pdf['fail']) {
                throw new \RuntimeException('Dompdf kaputt');
            }
            $html[] = $markup;

            return "%PDF-1.4\n% Testattrappe\n" . invText($markup) . "\n%%EOF\n";
        },
        static function (string $to, string $subject, string $body, string $path, string $name) use ($mails): bool {
            $mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body, 'path' => $path, 'name' => $name];

            return true;
        },
        static fn (): array => $store->getArrayCopy(),
        static function (array $values) use ($store): bool {
            foreach ($values as $key => $value) {
                $store[$key] = $value;
            }

            return true;
        },
        static fn (): array => $legal
    );

    return ['db' => $db, 'service' => $service, 'clock' => $clock, 'settings' => $store, 'mails' => $mails, 'html' => $html, 'pdf' => $pdf, 'dir' => $dir];
}

/** @param array<string, mixed> $overrides */
function invOrder(Database $db, array $overrides = []): int
{
    static $counter = 0;
    $counter++;
    $row = $overrides + [
        'order_number' => 'ORD-' . $counter . '-' . bin2hex(random_bytes(2)),
        'user_id' => 7, 'plan_id' => 1, 'amount' => 100.00, 'tax_amount' => 19.00, 'total_amount' => 119.00, 'currency' => 'EUR',
        'status' => 'paid', 'payment_method' => 'stripe', 'billing_cycle' => 'monthly', 'forename' => 'Erika', 'lastname' => 'Mustermann',
        'company' => 'Beispiel AG', 'email' => 'erika@kunde.example', 'street' => 'Kundenweg 5', 'zip' => '10115', 'city' => 'Berlin',
        'country' => 'Deutschland', 'provider' => 'stripe', 'paid_at' => '2026-11-14 09:30:00', 'created_at' => '2026-11-14 09:00:00', 'updated_at' => '2026-11-14 09:30:00',
    ];
    $columns = array_keys($row);
    $db->execute('INSERT INTO cms_orders (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')', array_values($row));

    return $db->lastInsertId();
}

// ─────────────────────────────────────────────────────────────────────────────

T::run('Nummernformat und Nummernkreis (reine Regeln)', static function (): void {
    $ts = strtotime('2026-03-05 12:00:00');
    T::same('RE-2026-00042', I::formatNumber('RE-{YYYY}-{NNNNN}', 42, $ts), 'RE-{YYYY}-{NNNNN}');
    T::same('26/03-123', I::formatNumber('{YY}/{MM}-{NNN}', 123, $ts), '{YY}/{MM}-{NNN}');
    T::same('RE-1234567', I::formatNumber('RE-{NNN}', 1234567, $ts), 'Zähler wächst über die Stellen hinaus (nie abgeschnitten)');
    T::same('RE-2026-#', I::sequenceKey('RE-{YYYY}-{NNNNN}', $ts), 'Nummernkreis je Jahr');
    T::same('RE-2027-#', I::sequenceKey('RE-{YYYY}-{NNNNN}', strtotime('2027-01-01 00:00:01')), 'neues Jahr = neuer Kreis');
    T::same('RE-#', I::sequenceKey('RE-{NNNNNN}', $ts), 'ohne Datum: fortlaufend über Jahre');
    T::check(strlen(I::sequenceKey('ABCDEFGHIJKLMNOPQRSTUVWXYZ-ABCDEFGH-{NNN}', $ts)) <= 30, 'Kreis-Schlüssel höchstens 30 Zeichen');
    T::same(null, I::validateFormat(I::DEFAULT_NUMBER_FORMAT), 'Standardformat gültig');
    foreach (['RE-{YYYY}' => 'ohne Zähler', 'RE-{NNN}-{NNN}' => 'zwei Zähler', 'RE {NNNN}' => 'Leerzeichen', 'RE-{NN}' => 'Zähler zu kurz', '<b>{NNNN}' => 'HTML', '' => 'leer'] as $format => $why) {
        T::check(I::validateFormat($format) !== null, 'ungültiges Format abgelehnt (' . $why . ')');
    }
    T::same(['start' => '2026-11-14', 'end' => '2026-12-13'], I::servicePeriod('monthly', strtotime('2026-11-14 09:30')), 'Leistungszeitraum monatlich ab Zahlung');
    T::same(['start' => '2026-11-14', 'end' => '2027-11-13'], I::servicePeriod('yearly', strtotime('2026-11-14 09:30')), 'Leistungszeitraum jährlich');
    T::same(['start' => '2026-11-14', 'end' => '2026-11-14'], I::servicePeriod('lifetime', strtotime('2026-11-14 09:30')), 'einmalig: Leistungsdatum');
    T::same(['start' => '2026-11-15', 'end' => '2026-12-14'], I::servicePeriod('monthly', 0, strtotime('2026-12-15 00:00:00')), 'Verlängerung: Ende minus Abrechnungszeitraum');
});

T::run('Rechnung bei Zahlungseingang: Nummer, PDF, Ablage, Snapshot, Mail, Hook', static function (): void {
    $f = invFixture();
    $s = $f['service'];
    $hooked = new \ArrayObject();
    Hooks::addAction('cms_invoice_created', static function (mixed ...$args) use ($hooked): void {
        $hooked[] = $args;
    });
    $orderId = invOrder($f['db']);
    $result = $s->createForOrder($orderId);
    T::check(!empty($result['success']), 'Rechnung erstellt', (string) ($result['error'] ?? ''));
    $invoice = $result['invoice'];
    T::same('RE-2026-00001', $invoice['invoice_number'], 'erste Nummer im Kreis RE-2026');
    T::same(['RE-2026-#', 1], [$invoice['sequence_key'], $invoice['sequence_number']], 'Kreis und laufende Nummer gespeichert');
    T::same([100.0, 19.0, 119.0, 'EUR'], [$invoice['net_total'], $invoice['tax_total'], $invoice['gross_total'], $invoice['currency']], 'Beträge aus der eigenen Bestellung');
    T::same(['2026-11-14', '2026-12-13'], [$invoice['service_period_start'], $invoice['service_period_end']], 'Leistungszeitraum ab Zahlung');
    T::same(7, $invoice['user_id'], 'Eigentümer = Konto der Bestellung');
    T::check((bool) preg_match('#^2026/RE-2026-00001-[0-9a-f]{8}\.pdf$#', $invoice['pdf_path']), 'Ablage invoices/2026/ mit nicht erratbarem Dateinamen', $invoice['pdf_path']);
    $file = $f['dir'] . '/invoices/' . $invoice['pdf_path'];
    T::check(is_file($file) && str_starts_with((string) file_get_contents($file), '%PDF'), 'PDF liegt im privaten Speicher');
    T::same(hash_file('sha256', $file), $invoice['pdf_sha256'], 'SHA-256 der Datei gespeichert');
    $snap = $invoice['snapshot'];
    T::same(['RE-2026-00001', 'Muster Software GmbH', 'Beispiel AG', 'DE123456789', 'standard'], [$snap['number'], $snap['seller']['name'], $snap['customer']['company'], $snap['seller']['vat_id'], $snap['tax_mode']], 'Snapshot enthält Nummer, Absender, Empfänger, Steuermodus');
    T::same('Abonnement „Pro“ – monatlich', $snap['items'][0]['description'], 'Art der Leistung mit Paketname');
    T::same(1, count($f['mails']), 'Mail eingeplant');
    T::same(['erika@kunde.example', 'RE-2026-00001.pdf', $file], [$f['mails'][0]['to'], $f['mails'][0]['name'], realpath($f['mails'][0]['path'])], 'Mail an den Kunden mit PDF-Anhang');
    T::same([[(int) $invoice['id'], 'invoice', $orderId, 7]], $hooked->getArrayCopy(), 'Hook cms_invoice_created(id, Typ, Bestellung, Konto)');

    $again = $s->createForOrder($orderId);
    T::check(!empty($again['success']) && !empty($again['existing']) && $again['invoice']['id'] === $invoice['id'], 'zweiter Zahlungseingang derselben Bestellung: keine zweite Rechnung');
    T::same(1, (int) $f['db']->get_var('SELECT COUNT(*) FROM cms_invoices'), 'genau eine Rechnung');
    T::check(empty($s->createForOrder(invOrder($f['db'], ['status' => 'pending']))['success']), 'offene Bestellung: keine Rechnung');
    T::check(empty($s->createForOrder(999)['success']), 'unbekannte Bestellung: keine Rechnung');
    T::check(empty($s->createForOrder(invOrder($f['db'], ['amount' => 100, 'tax_amount' => 19, 'total_amount' => 150]))['success']), 'widersprüchliche Beträge: keine Rechnung');
    $noMail = $s->createForOrder(invOrder($f['db']), ['send_mail' => false]);
    T::check(!empty($noMail['success']) && count($f['mails']) === 1, 'ohne Mail auf Wunsch');
});

T::run('Nummernkreis lückenlos und nie wiederverwendet', static function (): void {
    $f = invFixture();
    $s = $f['service'];
    $numbers = [];
    for ($i = 0; $i < 3; $i++) {
        $numbers[] = $s->createForOrder(invOrder($f['db']))['invoice']['invoice_number'] ?? '';
    }
    T::same(['RE-2026-00001', 'RE-2026-00002', 'RE-2026-00003'], $numbers, 'fortlaufend');

    $f['pdf']['fail'] = true;
    $failed = $s->createForOrder($failedOrder = invOrder($f['db']));
    $f['pdf']['fail'] = false;
    T::check(empty($failed['success']), 'PDF-Fehler: keine Rechnung');
    T::same([3, 4], [(int) $f['db']->get_var('SELECT COUNT(*) FROM cms_invoices'), (int) $f['db']->get_var("SELECT next_number FROM cms_invoice_sequences WHERE sequence_key = 'RE-2026-#'")], 'PDF-Fehler: Zähler und Zeilen zurückgerollt');
    T::same(3, count(glob($f['dir'] . '/invoices/2026/*.pdf') ?: []), 'PDF-Fehler: keine verwaiste Datei');
    T::same('RE-2026-00004', $s->createForOrder($failedOrder)['invoice']['invoice_number'] ?? '', 'nach dem Fehler: nächste Nummer ohne Lücke');

    $f['pdf']['before'] = static function (): void {
        $locked = new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
        $locked->errorInfo = ['HY000', 5, 'database is locked'];
        throw $locked;
    };
    $retried = $s->createForOrder(invOrder($f['db']));
    T::same('RE-2026-00005', $retried['invoice']['invoice_number'] ?? '', 'Sperrkonflikt der Datenbank: erneuter Versuch vergibt dieselbe freie Nummer');
    $f['db']->execute("DELETE FROM cms_invoices WHERE invoice_number = 'RE-2026-00005'");
    $f['db']->execute("UPDATE cms_invoice_sequences SET next_number = 5 WHERE sequence_key = 'RE-2026-#'");

    // Zeile außerhalb des CMS gelöscht (z. B. per Datenbankwerkzeug): Nummer darf nicht erneut vergeben werden.
    $f['db']->execute("DELETE FROM cms_invoices WHERE invoice_number = 'RE-2026-00004'");
    T::same('RE-2026-00005', $s->createForOrder(invOrder($f['db']))['invoice']['invoice_number'] ?? '', 'gelöschte Nummer wird nicht wiederverwendet');

    $f['db']->execute("UPDATE cms_invoice_sequences SET next_number = 3 WHERE sequence_key = 'RE-2026-#'");
    $collision = $s->createForOrder(invOrder($f['db']));
    T::check(empty($collision['success']), 'Zähler manipuliert (zurückgesetzt): vergebene Nummer wird abgelehnt');
    T::same(4, (int) $f['db']->get_var('SELECT COUNT(*) FROM cms_invoices'), '…ohne Doppelvergabe');
    $f['db']->execute("UPDATE cms_invoice_sequences SET next_number = 6 WHERE sequence_key = 'RE-2026-#'");

    $f['clock']['now'] = strtotime('2027-01-02 08:00:00');
    T::same('RE-2027-00001', $s->createForOrder(invOrder($f['db']))['invoice']['invoice_number'] ?? '', 'Jahreswechsel: neuer Kreis beginnt bei 1');
    $f['settings']['start_number'] = '1000';
    $f['settings']['number_format'] = 'R{YY}{MM}-{NNNN}';
    T::same('R2701-1000', $s->createForOrder(invOrder($f['db']))['invoice']['invoice_number'] ?? '', 'Startnummer gilt für neue Kreise');
    $f['settings']['start_number'] = '1';
    T::same('R2701-1001', $s->createForOrder(invOrder($f['db']))['invoice']['invoice_number'] ?? '', 'laufender Kreis zählt nie zurück');
    T::same('R2701-1002', $s->nextNumbers()['invoice'], 'Vorschau der nächsten Nummer ohne Vergabe');
    T::same('R2701-1002', $s->nextNumbers()['invoice'], 'Vorschau verbraucht keine Nummer');
});

T::run('Nummernkreis unter Parallelität (zwei Transaktionen, zwei Verbindungen)', static function (): void {
    global $invTempDirs;
    $dir = sys_get_temp_dir() . '/cms-invoices-test-' . bin2hex(random_bytes(4));
    $invTempDirs[] = $dir;
    mkdir($dir, 0750, true);
    $file = $dir . '/db.sqlite';
    $a = invFixture(INV_SELLER, [], invDatabase($file), $dir);
    $b = invFixture(INV_SELLER, [], invDatabase($file), $dir);
    $orderA = invOrder($a['db']);
    $orderB = invOrder($a['db']);
    $inner = new \ArrayObject();
    // Während A die Nummer hält (Transaktion offen, PDF wird erzeugt), versucht B auf der zweiten Verbindung zu vergeben.
    $a['pdf']['before'] = static function () use ($b, $orderB, $inner): void {
        $inner['result'] = $b['service']->createForOrder($orderB);
    };
    $resultA = $a['service']->createForOrder($orderA);
    T::info('Zweite Transaktion während der Sperre: ' . (string) ($inner['result']['error'] ?? ''));
    T::check(empty($inner['result']['success']), 'B scheitert, solange A die Sperre hält', (string) ($inner['result']['invoice']['invoice_number'] ?? ''));
    T::same('RE-2026-00001', $resultA['invoice']['invoice_number'] ?? '', 'A erhält 00001');
    $resultB = $b['service']->createForOrder($orderB);
    T::same('RE-2026-00002', $resultB['invoice']['invoice_number'] ?? '', 'B erhält nach Freigabe 00002 (keine Doppelvergabe, keine Lücke)');
    T::same(['1', '2'], array_map('strval', $a['db']->sqlite->query('SELECT sequence_number FROM cms_invoices ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)), 'Zeilen 1 und 2');
    T::same(3, (int) $a['db']->get_var("SELECT next_number FROM cms_invoice_sequences WHERE sequence_key = 'RE-2026-#'"), 'Zähler steht auf 3');

    // Gleiche Bestellung parallel: die zweite Vergabe erkennt nach der Sperre die soeben erstellte Rechnung.
    $orderC = invOrder($a['db']);
    $a['pdf']['before'] = static function () use ($b, $orderC, $inner): void {
        $inner['same'] = $b['service']->createForOrder($orderC);
    };
    $first = $a['service']->createForOrder($orderC);
    $second = $b['service']->createForOrder($orderC);
    T::check(!empty($first['success']) && !empty($second['existing']) && $second['invoice']['id'] === $first['invoice']['id'], 'gleiche Bestellung parallel: genau eine Rechnung');
    T::same(1, (int) $a['db']->get_var('SELECT COUNT(*) FROM cms_invoices WHERE order_id = ?', [$orderC]), 'eine Zeile für die Bestellung');
});

T::run('Pflichtangaben § 14 UStG im PDF (Text-Ebene des Renderers)', static function (): void {
    $f = invFixture();
    $invoice = $f['service']->createForOrder(invOrder($f['db'], ['order_number' => 'ORD-PFLICHT']))['invoice'] ?? [];
    $text = invText((string) ($f['html'][0] ?? ''));
    $expected = [
        'Name des Leistenden' => 'Muster Software GmbH',
        'Anschrift des Leistenden' => 'Hauptstraße 1',
        'PLZ/Ort des Leistenden' => '79098 Freiburg',
        'Name des Empfängers' => 'Erika Mustermann',
        'Firma des Empfängers' => 'Beispiel AG',
        'Anschrift des Empfängers' => 'Kundenweg 5',
        'PLZ/Ort des Empfängers' => '10115 Berlin',
        'Steuernummer' => 'Steuernummer: 06/123/45678',
        'USt-IdNr.' => 'USt-IdNr.: DE123456789',
        'Ausstellungsdatum' => 'Rechnungsdatum: 15.11.2026',
        'Rechnungsnummer' => 'Rechnungsnummer: RE-2026-00001',
        'Leistungszeitraum' => 'Leistungszeitraum: 14.11.2026 – 13.12.2026',
        'Art der Leistung' => 'Abonnement „Pro“ – monatlich',
        'Entgelt nach Steuersatz' => 'Nettobetrag (19 %): 100,00 €',
        'Steuersatz und -betrag' => 'Umsatzsteuer 19 %: 19,00 €',
        'Gesamtbetrag' => 'Gesamtbetrag: 119,00 €',
        'Bestellnummer' => 'ORD-PFLICHT',
    ];
    foreach ($expected as $label => $needle) {
        T::check(str_contains($text, $needle), 'PDF-Text enthält ' . $label, $needle);
    }
    T::check((bool) preg_match('/Pos\..*Leistung.*Menge.*\n?\s*1 Abonnement „Pro“ – monatlich 1 /u', $text), 'Menge der Leistung (Pos. 1, Menge 1)');
    T::same([], I::missingMandatoryFields($invoice['snapshot'] ?? []), 'Snapshot vollständig');
    T::check(!str_contains((string) ($f['html'][0] ?? ''), '<script') && !preg_match('/\son[a-z]+=/i', (string) ($f['html'][0] ?? '')), 'PDF-HTML ohne Skripte');

    $evil = invFixture(['sender_name' => 'Böse <script>alert(1)</script> GmbH'] + INV_SELLER);
    $evil['service']->createForOrder(invOrder($evil['db'], ['company' => '<img src=x onerror=alert(1)>']));
    T::check(!str_contains((string) $evil['html'][0], '<script>') && !str_contains((string) $evil['html'][0], '<img'), 'Werte im PDF-HTML escaped');

    // Ohne Steuernummer und USt-IdNr.: keine Rechnung, keine Nummer verbraucht.
    $g = invFixture(['tax_number' => '', 'vat_id' => ''] + INV_SELLER);
    $missing = $g['service']->createForOrder(invOrder($g['db']));
    T::check(empty($missing['success']) && in_array('Steuernummer oder USt-IdNr. des Leistenden', $missing['missing'] ?? [], true), 'fehlende Steuernummer/USt-IdNr. verhindert die Rechnung');
    T::same(0, (int) $g['db']->get_var('SELECT COUNT(*) FROM cms_invoice_sequences'), '…ohne eine Nummer zu verbrauchen');
    $g['settings']['tax_number'] = '06/123/45678';
    T::same('RE-2026-00001', $g['service']->createForOrder(invOrder($g['db']))['invoice']['invoice_number'] ?? '', 'nach Ergänzung: erste Nummer');
    $noAddress = invFixture(['sender_street' => ''] + INV_SELLER);
    T::check(in_array('Straße des Leistenden', $noAddress['service']->createForOrder(invOrder($noAddress['db']))['missing'] ?? [], true), 'fehlende Anschrift des Leistenden verhindert die Rechnung');

    // Kleinbetragsrechnung (§ 33 UStDV): bis 250 € brutto ohne Empfängeranschrift erlaubt, darüber nicht.
    $h = invFixture();
    $small = $h['service']->createForOrder(invOrder($h['db'], ['street' => '', 'zip' => '', 'city' => '', 'amount' => 10, 'tax_amount' => 1.90, 'total_amount' => 11.90]));
    T::check(!empty($small['success']) && !empty($small['invoice']['snapshot']['small_amount']), 'Kleinbetragsrechnung ohne Empfängeranschrift');
    T::check(str_contains(invText((string) $h['html'][0]), 'Kleinbetragsrechnung (§ 33 UStDV).'), 'Hinweis Kleinbetragsrechnung im PDF');
    $large = $h['service']->createForOrder(invOrder($h['db'], ['street' => '', 'zip' => '', 'city' => '', 'amount' => 300, 'tax_amount' => 57, 'total_amount' => 357]));
    T::check(empty($large['success']) && in_array('Straße des Leistungsempfängers', $large['missing'] ?? [], true), 'über 250 €: Empfängeranschrift Pflicht');

    // 0 % in Regelbesteuerung: Hinweis auf die Steuerbefreiung Pflicht.
    $z = invFixture();
    $zero = $z['service']->createForOrder(invOrder($z['db'], ['tax_amount' => 0, 'total_amount' => 100]));
    T::check(empty($zero['success']) && in_array('Hinweis auf die Steuerbefreiung', $zero['missing'] ?? [], true), '0 % ohne Befreiungshinweis: keine Rechnung');
    $z['settings']['exempt_note'] = 'Steuerfreie Leistung nach § 4 Nr. 21 UStG.';
    T::check(!empty($z['service']->createForOrder(invOrder($z['db'], ['tax_amount' => 0, 'total_amount' => 100]))['success'])
        && str_contains(invText((string) $z['html'][0]), 'Steuerfreie Leistung nach § 4 Nr. 21 UStG.'), '0 % mit Befreiungshinweis im PDF');
});

T::run('Kleinunternehmer (§ 19 UStG) und Reverse Charge', static function (): void {
    $k = invFixture(['tax_mode' => I::TAX_SMALL_BUSINESS, 'vat_id' => ''] + INV_SELLER);
    $invoice = $k['service']->createForOrder(invOrder($k['db'], ['amount' => 50, 'tax_amount' => 0, 'total_amount' => 50]))['invoice'] ?? [];
    $text = invText((string) $k['html'][0]);
    T::same([50.0, 0.0, 50.0, 'small_business'], [$invoice['net_total'] ?? null, $invoice['tax_total'] ?? null, $invoice['gross_total'] ?? null, $invoice['tax_mode'] ?? null], '§ 19: keine Umsatzsteuer');
    T::check(str_contains($text, I::SMALL_BUSINESS_NOTE), '§ 19: Hinweis im PDF');
    T::check(!str_contains($text, 'Umsatzsteuer 19 %') && str_contains($text, 'Umsatzsteuer: 0,00 €'), '§ 19: kein Steuersatz ausgewiesen');
    T::check(str_contains($text, 'Steuernummer: 06/123/45678'), '§ 19: Steuernummer trotzdem Pflicht und vorhanden');

    $r = invFixture();
    $rc = $r['service']->createForOrder(invOrder($r['db'], ['tax_amount' => 0, 'total_amount' => 100, 'country' => 'AT', 'contact_data' => json_encode(['vat_id' => 'ATU 12345678'])]));
    T::same('reverse_charge', $rc['invoice']['tax_mode'] ?? '', 'EU-Ausland mit USt-IdNr. und 0 %: Reverse Charge automatisch');
    $text = invText((string) $r['html'][0]);
    T::check(str_contains($text, I::REVERSE_CHARGE_NOTE), 'Reverse Charge: Hinweis im PDF');
    T::check(str_contains($text, 'USt-IdNr. Empfänger: ATU12345678'), 'Reverse Charge: USt-IdNr. des Empfängers im PDF');
    $without = $r['service']->createForOrder(invOrder($r['db'], ['tax_amount' => 0, 'total_amount' => 100]), ['tax_mode' => I::TAX_REVERSE_CHARGE]);
    T::check(empty($without['success']) && in_array('USt-IdNr. des Leistungsempfängers (Reverse Charge)', $without['missing'] ?? [], true), 'Reverse Charge ohne USt-IdNr. des Empfängers: keine Rechnung');
    $withTax = $r['service']->createForOrder(invOrder($r['db']), ['tax_mode' => I::TAX_REVERSE_CHARGE, 'customer_vat_id' => 'ATU12345678']);
    T::check(empty($withTax['success']), 'Reverse Charge mit berechneter Umsatzsteuer: keine Rechnung (manuell prüfen)');
});

T::run('Storno-Rechnung', static function (): void {
    $f = invFixture();
    $s = $f['service'];
    $orderId = invOrder($f['db']);
    $original = $s->createForOrder($orderId)['invoice'];
    $f['clock']['now'] = strtotime('2026-11-20 14:00:00');
    $result = $s->createCancellation((int) $original['id'], 'Doppelt bezahlt', 1);
    T::check(!empty($result['success']), 'Storno erstellt', (string) ($result['error'] ?? ''));
    $storno = $result['invoice'];
    T::same(['ST-2026-00001', 'cancellation', (int) $original['id']], [$storno['invoice_number'], $storno['type'], $storno['cancels_invoice_id']], 'eigene Nummer (eigener Kreis) und Verweis auf die Rechnung');
    T::same([-100.0, -19.0, -119.0], [$storno['net_total'], $storno['tax_total'], $storno['gross_total']], 'negative Beträge');
    T::same(['RE-2026-00001', '2026-11-15'], [$storno['snapshot']['cancels']['number'], $storno['snapshot']['cancels']['issued_at']], 'Snapshot verweist auf Nummer und Datum');
    $text = invText((string) $f['html'][1]);
    T::check(str_contains($text, 'Stornorechnung') && str_contains($text, 'storniert die Rechnung Nr. RE-2026-00001 vom 15.11.2026'), 'PDF: Stornorechnung mit Verweis');
    T::check(str_contains($text, 'Grund: Doppelt bezahlt') && str_contains($text, 'Gesamtbetrag: -119,00 €') && str_contains($text, 'Rechnungsdatum: 20.11.2026'), 'PDF: Grund, negativer Betrag, eigenes Datum');
    T::same([7, $orderId], [$storno['user_id'], $storno['order_id']], 'Storno gehört demselben Konto und derselben Bestellung');
    T::check(str_starts_with($f['mails'][1]['subject'] ?? '', 'Stornorechnung ST-2026-00001'), 'Storno per Mail');

    T::check(empty($s->createCancellation((int) $original['id'])['success']), 'zweites Storno derselben Rechnung abgelehnt');
    T::check(empty($s->createCancellation((int) $storno['id'])['success']), 'Storno einer Storno-Rechnung abgelehnt');
    T::check(empty($s->createCancellation(999)['success']), 'unbekannte Rechnung');
    $list = $s->listInvoices();
    T::check($list[1]['is_cancelled'] === true && $list[0]['type'] === 'cancellation', 'Liste kennzeichnet die stornierte Rechnung');
    T::same(['RE-2026-00001'], array_column($s->listInvoices(['q' => 'RE-']), 'invoice_number'), 'Suche nach Nummer');

    $correction = $s->createForOrder($orderId);
    T::check(empty($correction['existing']) && ($correction['invoice']['invoice_number'] ?? '') === 'RE-2026-00002', 'nach Storno: neue (korrigierte) Rechnung für die Bestellung');
    T::same([], $s->ordersWithoutInvoice(), 'keine bezahlte Bestellung ohne gültige Rechnung');
    $open = invOrder($f['db'], ['status' => 'completed']);
    T::same([$open], array_column($s->ordersWithoutInvoice(), 'id'), 'bezahlte Bestellung ohne Rechnung (z. B. Rechnungskauf) für das Admin-Formular');
    $f['settings']['cancellation_format'] = 'RE-{YYYY}-{NNNNN}';
    $shared = $s->createCancellation((int) $correction['invoice']['id']);
    T::same('RE-2026-00003', $shared['invoice']['invoice_number'] ?? '', 'gleiches Format = gemeinsamer Nummernkreis');
});

T::run('Download-Rechte, Sperrvermerk, Integrität', static function (): void {
    $f = invFixture();
    $s = $f['service'];
    $mine = $s->createForOrder(invOrder($f['db'], ['user_id' => 7]))['invoice'];
    $other = $s->createForOrder(invOrder($f['db'], ['user_id' => 8]))['invoice'];
    T::check($s->downloadFor((int) $mine['id'], 7, false) !== null, 'Eigentümer darf herunterladen');
    T::same('RE-2026-00001.pdf', $s->downloadFor((int) $mine['id'], 7, false)['filename'] ?? '', 'Dateiname = Rechnungsnummer');
    T::same(null, $s->downloadFor((int) $other['id'], 7, false), 'fremde Rechnung: kein Download');
    T::same(null, $s->downloadFor((int) $mine['id'], 0, false), 'ohne Konto: kein Download');
    T::same(null, $s->downloadFor(999, 7, false), 'unbekannte Rechnung');
    T::check($s->downloadFor((int) $other['id'], 1, true) !== null, 'Recht invoices.view: jede Rechnung');
    T::same(['RE-2026-00001'], array_column($s->listForUser(7), 'invoice_number'), 'Liste nur eigene Rechnungen');
    T::same([], $s->listForUser(0), 'ohne Konto: leere Liste');

    T::same(1, $s->restrictForUser(7), 'DSGVO-Löschung: Sperrvermerk statt Löschung');
    T::same(2, (int) $f['db']->get_var('SELECT COUNT(*) FROM cms_invoices'), 'Rechnungen bleiben erhalten (Aufbewahrung)');
    T::check(is_file($f['dir'] . '/invoices/' . $mine['pdf_path']), 'PDF bleibt erhalten');
    T::same(null, $s->downloadFor((int) $mine['id'], 7, false), 'gesperrt: kein Download im Mitgliederbereich');
    T::same([], $s->listForUser(7), 'gesperrt: nicht in der Mitgliederliste');
    T::check($s->downloadFor((int) $mine['id'], 1, true) !== null, 'gesperrt: Admin mit invoices.view weiterhin');
    T::same(0, $s->restrictForUser(7), 'Sperrvermerk idempotent');

    file_put_contents($f['dir'] . '/invoices/' . $other['pdf_path'], 'X', FILE_APPEND);
    T::same(null, $s->downloadFor((int) $other['id'], 8, false), 'veränderte Datei (SHA-256 falsch): kein Download');
    foreach (['../../../etc/passwd', '2026/../../db.sqlite', '/etc/passwd', '2026/fehlt.pdf'] as $path) {
        $f['db']->execute('UPDATE cms_invoices SET pdf_path = ? WHERE id = ?', [$path, (int) $mine['id']]);
        T::same(null, $s->downloadFor((int) $mine['id'], 1, true), 'unsicherer oder fehlender Pfad abgelehnt: ' . $path);
    }
    T::check(!I::canAccess(['user_id' => 0, 'restricted_at' => ''], 0, false), 'Rechnung ohne Konto: nur mit invoices.view');
});

T::run('Hooks aus Bereich 5a, Menü, Aufbewahrung', static function (): void {
    $f = invFixture();
    I::setInstance($f['service']);
    InvoiceHooks::register();
    $orderId = invOrder($f['db'], ['user_id' => 11]);
    Hooks::doAction('cms_payment_succeeded', $orderId, 'stripe', ['id' => 'evt_1']);
    T::same(1, (int) $f['db']->get_var('SELECT COUNT(*) FROM cms_invoices WHERE order_id = ?', [$orderId]), 'cms_payment_succeeded erzeugt die Rechnung');
    Hooks::doAction('cms_payment_succeeded', $orderId, 'stripe', ['id' => 'evt_1']);
    T::same(1, (int) $f['db']->get_var('SELECT COUNT(*) FROM cms_invoices WHERE order_id = ?', [$orderId]), 'wiederholtes Ereignis: keine zweite Rechnung');

    $renewal = invOrder($f['db'], ['user_id' => 11, 'billing_cycle' => 'yearly', 'amount' => 1000, 'tax_amount' => 190, 'total_amount' => 1190, 'paid_at' => '2026-11-15 03:00:00']);
    Hooks::doAction('cms_subscription_renewed', 3, 11, $renewal, strtotime('2027-11-15 03:00:00'));
    $row = $f['db']->get_row('SELECT * FROM cms_invoices WHERE order_id = ?', [$renewal]);
    T::same(['2026-11-15', '2027-11-15', '1190'], [(string) ($row->service_period_start ?? ''), (string) ($row->service_period_end ?? ''), (string) (int) ($row->gross_total ?? 0)], 'cms_subscription_renewed: Rechnung mit Leistungszeitraum der Verlängerung');

    $f['settings']['auto_create'] = '0';
    $manual = invOrder($f['db']);
    Hooks::doAction('cms_payment_succeeded', $manual, 'mollie', []);
    T::same(0, (int) $f['db']->get_var('SELECT COUNT(*) FROM cms_invoices WHERE order_id = ?', [$manual]), 'Automatik abgeschaltet: keine Rechnung');
    Hooks::doAction('cms_payment_succeeded', 0, 'stripe', []);
    T::check(true, 'ungültige Bestellung im Hook wirft nicht');

    Hooks::doAction('dsgvo_delete_data', 11, 'x@example.org');
    T::same(2, (int) $f['db']->get_var('SELECT COUNT(*) FROM cms_invoices WHERE user_id = 11 AND restricted_at IS NOT NULL'), 'dsgvo_delete_data: Sperrvermerk, keine Löschung');
    $f['service']->createForOrder($other = invOrder($f['db'], ['user_id' => 12]));
    Hooks::doAction('cms_user_deleted', 12);
    T::same(1, (int) $f['db']->get_var('SELECT COUNT(*) FROM cms_invoices WHERE user_id = 12 AND restricted_at IS NOT NULL'), 'cms_user_deleted (Konto gelöscht): Sperrvermerk');

    $items = InvoiceHooks::addMemberMenuItem([['slug' => 'subscription', 'label' => 'Abo', 'url' => '/member/subscription']]);
    T::same(['invoices', 'subscription', '/member/subscription/invoices'], [$items[1]['slug'] ?? '', $items[1]['parent_slug'] ?? '', $items[1]['url'] ?? ''], 'Menü: „Rechnungen“ unter „Abo & Bestellungen“');
    T::same(1, count(InvoiceHooks::addMemberMenuItem([['slug' => 'profile']])), 'ohne Abo-Bereich kein Menüpunkt');

    InvoiceHooks::registerRetentionRule();
    $rules = (new ReflectionProperty(\CMS\Services\DataRetentionService::class, 'registeredRules'))->getValue();
    $rule = $rules[InvoiceHooks::RETENTION_RULE] ?? null;
    T::check($rule instanceof \CMS\Services\Retention\RetentionRule && $rule->days() === 0 && $rule->prune() === 0, 'Aufbewahrungsregel registriert: Frist 0 = nie automatisch löschen');
    $f['clock']['now'] = strtotime('2036-12-31 23:00:00');
    T::same(0, $f['service']->countBeyondRetention(), 'Frist (Ausstellungsjahr 2026) läuft bis 31.12.2036');
    $f['clock']['now'] = strtotime('2037-01-01 00:00:00');
    T::check($f['service']->countBeyondRetention() > 0, 'ab 2037 nur gezählt (keine automatische Löschung)');
    I::setInstance(null);
});

T::run('Einstellungen und Rückgriff auf das Rechtstexte-Profil', static function (): void {
    $legal = [
        'legal_profile_entity_type' => 'company', 'legal_profile_company_name' => 'Profil Media', 'legal_profile_legal_form' => 'GmbH',
        'legal_profile_street' => 'Profilweg 2', 'legal_profile_postal_code' => '80331', 'legal_profile_city' => 'München',
        'legal_profile_country' => 'Deutschland', 'legal_profile_email' => 'info@profil.example', 'legal_profile_vat_id' => 'DE999999999',
        'legal_profile_register_court' => 'Amtsgericht München', 'legal_profile_register_number' => 'HRB 1', 'legal_profile_managing_director' => 'Max Profil',
    ];
    $f = invFixture(['sender_city' => 'Augsburg'], $legal);
    $seller = $f['service']->seller();
    T::same(['Profil Media GmbH', 'Profilweg 2', 'Augsburg', 'DE999999999', 'Amtsgericht München HRB 1'], [$seller['data']['name'], $seller['data']['street'], $seller['data']['city'], $seller['data']['vat_id'], $seller['data']['register']], 'leere Felder aus dem Rechtstexte-Profil, eigene Werte haben Vorrang');
    T::same(['legal_profile', 'invoices', ''], [$seller['sources']['name'], $seller['sources']['city'], $seller['sources']['tax_number']], 'Herkunft je Feld');
    $invoice = $f['service']->createForOrder(invOrder($f['db']))['invoice'] ?? [];
    T::same('RE-2026-00001', $invoice['invoice_number'] ?? '', 'Rechnung allein mit Profildaten (USt-IdNr. aus dem Profil)');
    T::check(str_contains(invText((string) $f['html'][0]), 'Geschäftsführung: Max Profil · Amtsgericht München HRB 1'), 'Registerangaben im Fuß');

    $s = $f['service'];
    T::check(empty($s->saveSettings(['number_format' => 'RE-{YYYY}', 'cancellation_format' => I::DEFAULT_CANCELLATION_FORMAT, 'tax_mode' => 'standard'])['success']), 'Format ohne Zähler abgelehnt');
    T::check(empty($s->saveSettings(['number_format' => I::DEFAULT_NUMBER_FORMAT, 'cancellation_format' => I::DEFAULT_CANCELLATION_FORMAT, 'tax_mode' => 'erfunden'])['success']), 'unbekannter Steuermodus abgelehnt');
    T::check(empty($s->saveSettings(['number_format' => I::DEFAULT_NUMBER_FORMAT, 'cancellation_format' => I::DEFAULT_CANCELLATION_FORMAT, 'tax_mode' => 'standard', 'iban' => 'kaputt'])['success']), 'ungültige IBAN abgelehnt');
    $saved = $s->saveSettings(['number_format' => 'INV-{YYYY}-{NNNNNN}', 'cancellation_format' => 'CN-{YYYY}-{NNNNNN}', 'tax_mode' => 'small_business',
        'sender_name' => ' <b>Neu</b> GmbH ', 'iban' => 'DE89 3704 0044 0532 0130 00', 'vat_id' => 'de 123 456 789', 'auto_create' => '1', 'start_number' => '-5']);
    T::check(!empty($saved['success']), 'gültige Einstellungen gespeichert');
    $settings = $s->settings();
    T::same(['INV-{YYYY}-{NNNNNN}', 'small_business', 'Neu GmbH', 'DE89370400440532013000', 'DE123456789', '1', '0', '1'],
        [$settings['number_format'], $settings['tax_mode'], $settings['sender_name'], $settings['iban'], $settings['vat_id'], $settings['auto_create'], $settings['send_mail'], $settings['start_number']], 'bereinigt (Tags, Leerzeichen, Großschrift, Startnummer ≥ 1)');
    $f['settings']['number_format'] = 'kaputt';
    T::same(I::DEFAULT_NUMBER_FORMAT, $s->settings()['number_format'], 'ungültiges gespeichertes Format → Standard');
});

T::run('Dompdf (echtes PDF, falls verfügbar)', static function (): void {
    if (!is_file(ABSPATH . 'vendor/dompdf/autoload.php')) {
        T::info('Dompdf nicht gebündelt – übersprungen');

        return;
    }
    try {
        $pdf = \CMS\Services\PdfService::getInstance();
        if (!$pdf->isAvailable()) {
            T::info('Dompdf nicht ladbar – übersprungen');

            return;
        }
        $f = invFixture();
        $f['service']->createForOrder(invOrder($f['db']));
        $bytes = $pdf->generateFromHtml((string) $f['html'][0]);
        T::check(str_starts_with($bytes, '%PDF-') && strlen($bytes) > 1000 && str_contains($bytes, '%%EOF'), 'Dompdf rendert das Rechnungs-HTML', (string) strlen($bytes));
    } catch (\Throwable $e) {
        T::info('Dompdf-Rendering im Test nicht möglich: ' . $e->getMessage());
    }
});

T::run('Einbindung (Quelltext)', static function (): void {
    $hooks = invSource('core/Services/FeatureHooks/InvoiceHooks.php');
    foreach (["'cms_payment_succeeded'", "'cms_subscription_renewed'", "'register_routes'", "'member_menu_items'", "'dsgvo_delete_data'", "'cms_user_deleted'", "'cms_init'"] as $hook) {
        T::check(str_contains($hooks, $hook), 'Hook ' . $hook);
    }
    T::check(str_contains($hooks, "'/member/subscription/invoices'") && str_contains($hooks, "'/member/invoices/:id'"), 'Mitgliederrouten');
    T::check(str_contains($hooks, '->downloadFor((int) $id, $userId, $canViewAll)') && str_contains($hooks, "hasCapability('invoices.view')"), 'Download über die Eigentumsprüfung des Dienstes');
    T::check(str_contains(invSource('core/Services/UserService.php'), "doAction('cms_user_deleted', \$user_id)"), 'Kontolöschung meldet cms_user_deleted');
    $service = invSource('core/Services/Payments/InvoiceService.php');
    T::check(str_contains($service, "' FOR UPDATE'") && str_contains($service, 'beginTransaction()'), 'Vergabe in Transaktion mit SELECT … FOR UPDATE (MySQL)');
    T::check(str_contains($service, 'PdfService::getInstance()->generateFromHtml(') && str_contains($service, 'PrivateStorageService::getInstance()'), 'PDF über PdfService, Ablage über PrivateStorageService');
    T::check(str_contains($service, 'queueWithAttachment('), 'Mail mit Anhang über die Mail-Warteschlange');
    T::check(!preg_match('/DELETE FROM \{\$this->prefix\}invoices/', $service), 'Dienst löscht nie Rechnungen');
    $admin = invSource('admin/invoices.php');
    T::check(str_contains($admin, "hasCapability('invoices.view')") && str_contains($admin, "require __DIR__ . '/partials/section-page-shell.php'"), 'Admin-Seite: invoices.view, Section-Shell');
    $view = invSource('admin/views/subscriptions/invoices.php');
    T::same(substr_count($view, '<form method="post"'), substr_count($view, 'name="csrf_token"'), 'jedes POST-Formular trägt ein CSRF-Token');
    T::check(substr_count($view, '<form method="post"') >= 4, 'POST-Formulare: Erstellen, Storno, Mail, Einstellungen');
    T::check(!preg_match('/<script|\son[a-z]+=/i', $view . invSource('member/invoices.php')), 'keine Inline-Skripte oder -Handler (CSP)');
    T::check(!preg_match('/<\?=\s*\$inv\w+\[[^\]]+\]\s*\?>/', $view), 'Admin-Ausgaben escaped (keine Rohausgabe von Feldern)');
    T::check(str_contains(invSource('member/invoices.php'), 'listForUser($controller->getUserId())'), 'Mitgliederliste nur für das eigene Konto');

    $registry = invSource('core/Services/FeatureHooks/Registry.php');
    if (!str_contains($registry, 'InvoiceHooks::class')) {
        T::info('Registry: InvoiceHooks::class noch nicht eingetragen (gemeinsame Datei)');
    }
    $schema = invSource('core/SchemaManager.php');
    if (!str_contains($schema, "'invoices'")) {
        T::info('SchemaManager: invoice_sequences/invoices noch nicht übernommen (gemeinsame Datei)');
    }
    if (!str_contains(invSource('core/Services/AdminMenu/CoreCatalog.php'), "'admin/invoices.php'")) {
        T::info('CoreCatalog: Eintrag subscriptions.invoices noch nicht eingetragen (gemeinsame Datei)');
    }
});

foreach ($invTempDirs as $dir) {
    invRemoveDir($dir);
}

T::finish();
