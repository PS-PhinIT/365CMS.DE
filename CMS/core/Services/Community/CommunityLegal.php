<?php
/**
 * Rechtstext-Baustein der Community (PR #5; SECURITY-PRIVACY §6, Legal-Modul): der Abschnitt „Forum (Community)“ der Datenschutzerklärung.
 *
 * - `facts()` liest die wirksamen Einstellungen und Fristen (`Settings`, `CommunityRetention::days()`): Sichtbarkeit für Gäste, IP-Kennung, Captcha,
 *   Benachrichtigungen per E-Mail, den Löschmodus (`erase_mode`) und die Löschfristen. Alle Werte sind Zahlen oder Auswahlwerte aus festen Listen, nie freier Text.
 * - `privacySection()` ist rein (ohne Datenbank): aus den Fakten wird ein HTML-Abschnitt `<h3>8a. Forum (Community)</h3>` mit Absätzen und Listen.
 *   Er steht nur im Text, wenn das Community-Modul aktiv ist (`LegalSitesModule::buildPrivacyTemplate()`), und folgt den Einstellungen: ein Captcha
 *   wird nur genannt, wenn es verlangt wird, die IP-Kennung nur, wenn sie gespeichert wird, die Benachrichtigungsmails nur, wenn sie an sind. Die
 *   Fristen sind dieselben Zahlen wie in den Aufbewahrungsregeln (`CommunityRetention`): Text und Löschung können nicht auseinanderlaufen.
 * - Der Text ist eine Vorlage, keine Rechtsberatung; wie die übrigen Vorlagen des Legal-Moduls muss ihn der Betreiber prüfen.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community;

use CMS\Services\Community\Support\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class CommunityLegal
{
    /** Fristen (Tage), die der Text nennt; 0 = ohne feste Frist. */
    private const RETENTION_KEYS = ['ip_hash', 'read_states', 'orphan_uploads', 'drafts', 'reports', 'moderation_log', 'warnings', 'bans', 'revisions'];

    /**
     * Fakten für den Abschnitt aus den wirksamen Einstellungen.
     *
     * @param array<string, int>|null $days Fristen je Regel (Standard: `CommunityRetention::days()`)
     * @return array{guests_can_read: bool, notify_mail: bool, captcha_mode: string, erase_mode: string, days: array<string, int>}
     */
    public static function facts(?Settings $settings = null, ?array $days = null): array
    {
        $settings ??= Settings::getInstance();

        return [
            'guests_can_read' => $settings->bool('guests_can_read'),
            'notify_mail' => $settings->bool('notify_mail'),
            'captcha_mode' => $settings->string('captcha_mode'),
            'erase_mode' => $settings->string('erase_mode'),
            'days' => $days ?? (new CommunityRetention(null, null, $settings))->days(),
        ];
    }

    /**
     * Abschnitt der Datenschutzerklärung als HTML.
     *
     * @param array{guests_can_read?: bool, notify_mail?: bool, captcha_mode?: string, erase_mode?: string, days?: array<string, int>} $facts
     */
    public static function privacySection(array $facts): string
    {
        $days = [];
        foreach (self::RETENTION_KEYS as $key) {
            $days[$key] = max(0, (int) ($facts['days'][$key] ?? 0));
        }
        $mode = in_array($facts['captcha_mode'] ?? 'off', Settings::CAPTCHA_MODES, true) ? (string) ($facts['captcha_mode'] ?? 'off') : 'off';

        $html = '<h3>8a. Forum (Community)</h3>';
        $html .= '<p>Auf dieser Website gibt es ein Forum. Wer dort mitschreibt, hat ein Benutzerkonto. Im Forum werden folgende Daten verarbeitet:</p><ul>'
            . '<li>Kontodaten (Benutzername, Anzeigename), Mitgliedsstatus und Vertrauensstufe im Forum;</li>'
            . '<li>Themen, Beiträge und deren Bearbeitungsverlauf (frühere Fassungen), Reaktionen, Lösungsmarkierungen und hochgeladene Anhänge mit ihren Metadaten (Dateiname, Größe, Typ);</li>'
            . '<li>Abonnements von Themen und Foren, der Lesestatus (zuletzt gelesener Beitrag je Thema) und auf dem Server gespeicherte Entwürfe;</li>';
        if ($days['ip_hash'] > 0) {
            $html .= '<li>eine gekürzte und nicht umkehrbar verschlüsselte Kennung der IP-Adresse (keine Klartext-IP) an Beiträgen, ausschließlich für die Spam-Abwehr und Moderation, '
                . 'für ' . self::days($days['ip_hash']) . ';</li>';
        }
        $html .= '<li>Meldungen anderer Mitglieder, Verwarnungen, Sperren und das Moderationsprotokoll (ohne IP-Adressen).</li></ul>';

        $html .= '<p>Zwecke und Rechtsgrundlagen: Die Bereitstellung des Forums und Ihrer Beiträge beruht auf dem Nutzungsverhältnis (Art. 6 Abs. 1 lit. b DSGVO). '
            . 'Spam-Schutz, Moderation und die Sicherheit des Forums beruhen auf unserem berechtigten Interesse an einem geordneten und missbrauchsfreien Forum (Art. 6 Abs. 1 lit. f DSGVO).</p>';

        $html .= '<p>Sichtbarkeit: ' . (!empty($facts['guests_can_read'])
            ? 'Beiträge in öffentlichen Foren können ohne Anmeldung gelesen werden; Foren mit eingeschränktem Zugang sind nur für berechtigte Mitglieder sichtbar.'
            : 'Beiträge sind nur für angemeldete und berechtigte Mitglieder sichtbar.')
            . ' Was Sie veröffentlichen, kann von anderen gelesen werden. Bitte geben Sie in Beiträgen keine personenbezogenen Daten Dritter oder besonders sensible Angaben preis.</p>';

        $html .= '<p>Moderation: Moderatorinnen und Moderatoren sehen auch wartende, gemeldete und ausgeblendete Beiträge und können Beiträge freigeben, ausblenden, verschieben oder löschen sowie Verwarnungen und Sperren aussprechen. Gemeldete Inhalte werden dazu mit dem Meldegrund an die Moderation weitergegeben.</p>';

        if ($mode !== 'off') {
            $when = match ($mode) {
                'first_post' => 'vor dem ersten Beitrag eines neuen Mitglieds',
                'trust0' => 'solange ein Mitglied die niedrigste Vertrauensstufe hat',
                default => 'vor jedem neuen Thema und jeder Antwort',
            };
            $html .= '<p>Sicherheitsprüfung (Captcha): Zum Schutz vor automatisierten Beiträgen wird ' . $when . ' eine Sicherheitsprüfung verlangt. '
                . 'Sie läuft vollständig auf diesem Server: Es werden keine Daten an Dritte übermittelt und keine Cookies von Drittanbietern gesetzt. '
                . 'Dafür wird eine Aufgabe vorübergehend in Ihrer Sitzung gespeichert (technisch erforderlich, Art. 6 Abs. 1 lit. f DSGVO); '
                . 'als Alternative zur automatischen Prüfung steht eine Rechenaufgabe zur Verfügung.</p>';
        }

        if (!empty($facts['notify_mail'])) {
            $html .= '<p>Benachrichtigungen: Wenn Sie ein Thema abonnieren oder jemand auf Ihren Beitrag antwortet, ihn zitiert oder auf ihn reagiert, können wir Sie per E-Mail benachrichtigen. '
                . 'Dazu wird Ihre E-Mail-Adresse für den Versand verwendet. Die Benachrichtigungen lassen sich im Mitgliederbereich einstellen und abbestellen.</p>';
        }

        $html .= '<p>Speicherdauer im Forum: Beiträge bleiben bestehen, solange das Forum besteht oder bis sie gelöscht werden. Für Begleitdaten gelten diese Fristen:</p><ul>'
            . '<li>Lesestatus: ' . self::after($days['read_states'], 'der letzten Änderung') . ';</li>'
            . '<li>Entwürfe auf dem Server: ' . self::after($days['drafts'], 'der letzten Änderung') . ';</li>'
            . '<li>Anhänge, die keinem Beitrag zugeordnet wurden: ' . self::after($days['orphan_uploads'], 'dem Hochladen') . ';</li>'
            . '<li>erledigte Meldungen: ' . self::after($days['reports'], 'der Bearbeitung') . ';</li>'
            . '<li>Moderationsprotokoll: ' . self::after($days['moderation_log'], 'dem Eintrag') . ';</li>'
            . '<li>Verwarnungen: ' . self::after($days['warnings'], 'ihrem Ablauf') . ' (Verwarnungen ohne Ablauf höchstens fünf Jahre nach der Anlage);</li>'
            . '<li>Sperren: ' . self::after($days['bans'], 'der Aufhebung oder dem Ablauf') . ' (unbefristete Sperren bleiben bis zur Aufhebung);</li>'
            . '<li>frühere Fassungen bearbeiteter Beiträge: ' . self::after($days['revisions'], 'der Bearbeitung') . ' (die jüngste Fassung bleibt).</li></ul>';

        $html .= '<p>Löschung und Auskunft: Wird Ihr Konto gelöscht, '
            . (($facts['erase_mode'] ?? 'anonymize') === 'delete'
                ? 'wird der Inhalt Ihrer Beiträge samt früherer Fassungen und Anhänge gelöscht (an ihrer Stelle steht ein Hinweis auf die Löschung); die Titel Ihrer Themen bleiben ohne Bezug zu Ihrem Konto stehen, Zitate in Beiträgen anderer Mitglieder bleiben unverändert; '
                : 'werden Ihre Beiträge anonymisiert (Anzeige „Gelöschtes Mitglied“, ohne Bezug zu Ihrem Konto); ')
            . 'Reaktionen, Abonnements, Lesestatus, Entwürfe, Abstimmungen, Verwarnungen und Sperren werden gelöscht. '
            . 'Auskunft über die zu Ihrem Konto gespeicherten Forumsdaten erhalten Sie als Datenexport im Rahmen Ihrer Datenschutz-Anfrage.</p>';

        return $html;
    }

    /** „30 Tage“ / „1 Tag“. */
    private static function days(int $days): string
    {
        return $days . ($days === 1 ? ' Tag' : ' Tage');
    }

    /** „nach 30 Tagen ab der letzten Änderung“-Wendung bzw. „ohne feste Frist“ bei 0. */
    private static function after(int $days, string $event): string
    {
        if ($days === 0) {
            return 'ohne feste Frist';
        }

        return self::days($days) . ' nach ' . $event;
    }
}
