<?php
/**
 * Zuordnung von Daten über die Konto-E-Mail-Adresse (ohne Datenbank): Der zentrale Helfer
 * EmailVerificationService::matchableEmail() gibt die Adresse nur bei gesetztem `email_verified_at` frei,
 * und alle Selbstauskunfts-, Lösch- und Mitgliederpfade laufen über ihn. Wird von TESTS/security/run.php eingebunden.
 */

declare(strict_types=1);

use CMS\Database;
use CMS\Logger;
use CMS\Services\EmailVerificationService;

/**
 * Dienst ohne Konstruktor mit einer Datenbank-Attrappe, die genau eine Zeile (oder einen Fehler) liefert.
 *
 * @param object|null|\Throwable $row
 */
function emailMatchService(mixed $row): EmailVerificationService
{
    $db = new class ($row) extends Database {
        /** @var list<array{0: string, 1: array<int, mixed>}> */
        public array $queries = [];

        public function __construct(private mixed $row)
        {
        }

        public function get_row(string $query, array $params = []): ?object
        {
            $this->queries[] = [$query, $params];
            if ($this->row instanceof \Throwable) {
                throw $this->row;
            }

            return $this->row;
        }

        public function getPrefix(): string
        {
            return 'cms_';
        }
    };

    $reflection = new ReflectionClass(EmailVerificationService::class);
    $service = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('db')->setValue($service, $db);
    $reflection->getProperty('prefix')->setValue($service, 'cms_');
    $reflection->getProperty('logger')->setValue($service, Logger::instance()->withChannel('tests'));

    return $service;
}

T::run('E-Mail-Zuordnung: nur eine nachweislich bestätigte Konto-Adresse wird freigegeben', static function (): void {
    $verified = (object) ['email' => 'Opfer@Example.org', 'verified_at' => '2026-01-01 10:00:00'];
    $unverified = (object) ['email' => 'opfer@example.org', 'verified_at' => null];

    T::same('Opfer@Example.org', emailMatchService($verified)->matchableEmail(7), 'bestätigtes Konto: Adresse wird geliefert');
    T::same(true, emailMatchService($verified)->isEmailVerified(7), 'isEmailVerified() ist true');
    T::same('', emailMatchService($unverified)->matchableEmail(7), 'Konto ohne email_verified_at (Bestand, Registrierung ohne Pflicht, Admin-Anlage): keine Adresse');
    T::same(false, emailMatchService($unverified)->isEmailVerified(7), 'isEmailVerified() ist false');
    T::same('', emailMatchService((object) ['email' => 'a@example.org', 'verified_at' => '  '])->matchableEmail(7), 'leere Meta zählt nicht als Nachweis');
    T::same('', emailMatchService(null)->matchableEmail(7), 'unbekanntes Konto: keine Adresse');
    T::same('', emailMatchService($verified)->matchableEmail(0), 'ohne user_id: keine Adresse');
    T::same('', emailMatchService(new RuntimeException('db'))->matchableEmail(7), 'Datenbankfehler: sicherer Rückfall auf user_id');

    T::same('Opfer@Example.org', emailMatchService($verified)->matchableEmail(7, ' opfer@example.org '), 'erwartete Adresse stimmt (ohne Groß-/Kleinschreibung)');
    T::same('', emailMatchService($verified)->matchableEmail(7, 'alt@example.org'), 'im Antrag gespeicherte Adresse weicht von der Konto-Adresse ab: keine Zuordnung');
});

T::run('E-Mail-Zuordnung: Selbstauskunft, Löschung und Newsletter-Bereich nutzen den zentralen Helfer', static function (): void {
    $read = static fn (string $path): string => (string) file_get_contents(ABSPATH . $path);

    $member = $read('core/Services/MemberService.php');
    T::check(
        str_contains($member, "applyFilters('cms_member_export_data', \$export, \$userId, \$matchEmail)")
            && str_contains($member, '$matchEmail = EmailVerificationService::getInstance()->matchableEmail($userId'),
        'Selbstauskunft übergibt den Filtern nur die geprüfte Adresse'
    );
    T::check(!str_contains($member, "'cms_member_export_data', \$export, \$userId, (string)"), 'Selbstauskunft reicht die Konto-Adresse nicht mehr ungeprüft durch');

    foreach (['admin/modules/legal/DeletionRequestsModule.php' => 'dsgvo_delete_data', 'admin/modules/legal/PrivacyRequestsModule.php' => 'dsgvo_export_data'] as $path => $hook) {
        $source = $read($path);
        T::check(
            str_contains($source, "doAction('{$hook}', \$request->user_id, \$matchEmail)") && str_contains($source, '->matchableEmail((int) $request->user_id'),
            "{$hook}: Anträge aus einem Konto ordnen nur über die geprüfte Adresse zu"
        );
        T::check(!str_contains($source, "doAction('{$hook}', \$request->user_id, \$request->email)"), "{$hook}: gespeicherte Adresse wird nicht ungeprüft übergeben");
    }

    $section = $read('core/Services/Newsletter/NewsletterMemberSection.php');
    T::check(!str_contains($section, "subscriberByEmail((string) (\$user->email ?? ''))"), 'Newsletter-Bereich sucht das Abonnement nicht über die ungeprüfte Konto-Adresse');
    T::check(str_contains($section, "\$email !== '' ? \$repo->subscriberByEmail(\$email) : null"), 'Rückgriff auf die Adresse nur mit matchableEmail()');
    T::check(str_contains($section, "\$doubleOptIn = !\$verified || NewsletterSettings::flag('require_double_opt_in')"), 'unbestätigtes Konto: Anmeldung immer per Double-Opt-In');
    T::check(
        substr_count($section, "'user_id' => (int) \$user->id") === 2 && str_contains($section, "if (\$verified) {\n                    \$input['user_id'] = (int) \$user->id;"),
        'Bindung an das Konto nur bei bestätigter Adresse oder eigenem Abonnement'
    );

    // Die Hooks der Funktionsbereiche bekommen die Adresse nur von den oben geprüften Aufrufern.
    $callers = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABSPATH, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $path = str_replace('\\', '/', (string) $file);
        if (!str_ends_with($path, '.php') || str_contains($path, '/vendor/')) {
            continue;
        }
        if (preg_match("/(?:doAction|applyFilters)\\('(?:dsgvo_export_data|dsgvo_delete_data|cms_member_export_data)'/", (string) file_get_contents($path)) === 1) {
            $callers[] = substr($path, strlen(ABSPATH));
        }
    }
    sort($callers);
    T::same(
        ['admin/modules/legal/DeletionRequestsModule.php', 'admin/modules/legal/PrivacyRequestsModule.php', 'core/Services/MemberService.php'],
        $callers,
        'keine weiteren Auslöser der Auskunfts-/Lösch-Hooks ohne Prüfung'
    );
});

T::run('E-Mail-Zuordnung: Passwort-Reset trägt den Nachweis nur über den Mail-Link nach', static function (): void {
    $service = (string) file_get_contents(ABSPATH . 'core/Services/EmailVerificationService.php');
    T::check(str_contains($service, "\$this->confirmActiveAddress(\$activeId, 'password_reset')"), 'aktives Konto ohne Nachweis erhält email_verified_at nach abgeschlossenem Reset');
    $change = (string) file_get_contents(ABSPATH . 'core/Services/EmailChangeService.php');
    T::check(str_contains($change, '$this->deleteMeta($userId, [self::META_VERIFIED_AT]);'), 'Änderung ohne Bestätigung (Admin) entzieht den Nachweis');
});

T::run('E-Mail-Zuordnung: aktive Konten können ihre Adresse per Link bestätigen', static function (): void {
    $token = str_repeat('ab', 32);
    $now = 1_800_000_000;
    $entry = ['h' => hash('sha256', $token), 'e' => $now + 60, 'm' => hash('sha256', 'opfer@example.org')];

    T::check(EmailVerificationService::addressTokenMatches($entry, $token, ' Opfer@Example.org ', $now), 'passender Link für die aktuelle Adresse');
    T::check(!EmailVerificationService::addressTokenMatches($entry, str_repeat('cd', 32), 'opfer@example.org', $now), 'falscher Token');
    T::check(!EmailVerificationService::addressTokenMatches($entry, $token, 'neu@example.org', $now), 'nach Adressänderung bestätigt der alte Link nichts');
    T::check(!EmailVerificationService::addressTokenMatches($entry, $token, 'opfer@example.org', $now + 61), 'abgelaufener Link');
    T::check(!EmailVerificationService::addressTokenMatches(null, $token, 'opfer@example.org', $now), 'ohne gespeicherten Link');
    T::check(!EmailVerificationService::addressTokenMatches(['h' => $entry['h'], 'e' => $now + 60], $token, 'opfer@example.org', $now), 'Eintrag ohne Adressbindung');

    $section = (string) file_get_contents(ABSPATH . 'core/Services/Newsletter/NewsletterMemberSection.php');
    T::check(str_contains($section, '->requestAddressConfirmation('), 'Mitgliederbereich bietet den Bestätigungslink an');
});

T::run('E-Mail-Zuordnung: Admin sieht den übersprungenen Abgleich und entscheidet ausdrücklich', static function (): void {
    foreach (['DeletionRequestsModule', 'PrivacyRequestsModule'] as $module) {
        $source = (string) file_get_contents(ABSPATH . 'admin/modules/legal/' . $module . '.php');
        T::check(str_contains($source, 'if ($emailSkipped && $includeUnverifiedEmail)'), $module . ': Adresse nur mit ausdrücklicher Auswahl');
        T::check(str_contains($source, "\$row['email_unverified']"), $module . ': Kennzeichen für die Antragsliste');
    }
    $controller = (string) file_get_contents(ABSPATH . 'admin/data-requests.php');
    T::check(substr_count($controller, "!empty(\$post['include_email'])") === 2, 'Auswahl kommt nur aus dem CSRF-geschützten Admin-Formular');
    $view = (string) file_get_contents(ABSPATH . 'admin/views/legal/data-requests.php');
    T::check(substr_count($view, 'name="include_email" value="1"') === 2 && substr_count($view, "!empty(\$row['email_unverified'])") === 4, 'Hinweis und eigene Aktion nur bei unbestätigter Adresse');
});
