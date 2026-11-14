<?php
/**
 * Versand von Kampagnen in Blöcken.
 *
 * `start()` legt für jeden passenden aktiven Abonnenten eine Versandzeile (`newsletter_sends`, Status `queued`) an
 * und setzt die Kampagne auf `sending`. `run()` (Cron: Mail-Queue-Lauf und stündlich, zusätzlich direkt nach dem
 * Start) startet fällige geplante Kampagnen und versendet je Lauf höchstens `batch_size` Mails. Sind keine
 * Zeilen mehr offen, steht die Kampagne auf `sent`. Ein Datenbank-Lock verhindert parallele Läufe.
 *
 * Hooks: `cms_newsletter_campaign_started`, `cms_newsletter_campaign_sent` (jeweils Kampagnen-ID).
 *
 * @package CMS\Services\Newsletter
 */

declare(strict_types=1);

namespace CMS\Services\Newsletter;

use CMS\Database;
use CMS\Hooks;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class NewsletterDispatcher
{
    private const LOCK = 'cms_newsletter_dispatch';

    /** @return array{success: bool, error?: string, recipients?: int} */
    public static function start(int $campaignId): array
    {
        $repo = NewsletterRepository::instance();
        $campaign = $repo->campaign($campaignId);
        if ($campaign === null) {
            return ['success' => false, 'error' => (string) __('newsletter.error.campaign_not_found')];
        }
        if (!in_array($campaign['status'], ['draft', 'scheduled'], true)) {
            return ['success' => false, 'error' => (string) __('newsletter.error.campaign_not_sendable')];
        }
        $content = NewsletterMailer::content($campaign);
        if (trim(strip_tags($content['html'])) === '' && $content['text'] === '') {
            return ['success' => false, 'error' => (string) __('newsletter.error.campaign_empty')];
        }

        $db = Database::instance();
        $p = $db->getPrefix();
        [$where, $params] = $repo->recipientWhere((string) $campaign['segment_slug'], (string) $campaign['locale']);
        $db->execute(
            "INSERT IGNORE INTO {$p}newsletter_sends (campaign_id, subscriber_id, status) SELECT ?, id, 'queued' FROM {$p}newsletter_subscribers WHERE {$where}",
            [$campaignId, ...$params]
        );
        $recipients = (int) $db->get_var("SELECT COUNT(*) FROM {$p}newsletter_sends WHERE campaign_id = ?", [$campaignId]);
        if ($recipients === 0) {
            return ['success' => false, 'error' => (string) __('newsletter.error.no_recipients')];
        }
        // Inhalt aus der Vorlage beim Start einfrieren: spätere Änderungen an der Vorlage dürfen laufende und
        // versendete Ausgaben (Mails, Web-Ansicht, Archiv) nicht mehr verändern.
        $sets = "status = 'sending', started_at = NOW(), recipient_count = ?";
        $values = [$recipients];
        if (trim((string) ($campaign['content_html'] ?? '')) === '' && trim((string) ($campaign['content_text'] ?? '')) === '' && (int) ($campaign['template_id'] ?? 0) > 0) {
            $template = $repo->template((int) $campaign['template_id']);
            if ($template !== null) {
                $sets .= ', content_html = ?, content_text = ?';
                array_push($values, (string) ($template['content_html'] ?? ''), (string) ($template['content_text'] ?? ''));
            }
        }
        $db->execute("UPDATE {$p}newsletter_campaigns SET {$sets} WHERE id = ?", [...$values, $campaignId]);
        Hooks::doAction('cms_newsletter_campaign_started', $campaignId);
        Logger::instance()->withChannel('newsletter')->info('Newsletter-Versand gestartet.', ['campaign' => $campaignId, 'recipients' => $recipients]);

        return ['success' => true, 'recipients' => $recipients];
    }

    /** @return array{success: bool, error?: string} */
    public static function schedule(int $campaignId, string $when): array
    {
        $repo = NewsletterRepository::instance();
        $campaign = $repo->campaign($campaignId);
        if ($campaign === null || !in_array($campaign['status'], ['draft', 'scheduled'], true)) {
            return ['success' => false, 'error' => (string) __('newsletter.error.campaign_not_sendable')];
        }
        $time = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', trim($when));
        if (!$time instanceof \DateTimeImmutable || $time->getTimestamp() <= time()) {
            return ['success' => false, 'error' => (string) __('newsletter.error.schedule_past')];
        }
        $repo->setCampaignStatus($campaignId, 'scheduled', $time->format('Y-m-d H:i:00'));

        return ['success' => true];
    }

    /** Geplante oder laufende Kampagne stoppen; offene Mails werden nicht mehr versendet. */
    public static function cancel(int $campaignId): bool
    {
        $repo = NewsletterRepository::instance();
        $campaign = $repo->campaign($campaignId);
        if ($campaign === null || !in_array($campaign['status'], ['scheduled', 'sending'], true)) {
            return false;
        }
        $db = Database::instance();
        $p = $db->getPrefix();
        if ($campaign['status'] === 'scheduled') {
            $repo->setCampaignStatus($campaignId, 'draft', null);

            return true;
        }
        $db->execute("UPDATE {$p}newsletter_sends SET status = 'skipped' WHERE campaign_id = ? AND status = 'queued'", [$campaignId]);
        self::syncCounts($campaignId);
        $db->execute("UPDATE {$p}newsletter_campaigns SET status = 'cancelled' WHERE id = ?", [$campaignId]);

        return true;
    }

    /**
     * Fällige Kampagnen starten und einen Block versenden.
     *
     * @return array{started: int, sent: int, failed: int}
     */
    public static function run(?int $limit = null): array
    {
        $result = ['started' => 0, 'sent' => 0, 'failed' => 0];
        $repo = NewsletterRepository::instance();
        if (!$repo->isAvailable()) {
            return $result;
        }
        $db = Database::instance();
        if ((int) $db->get_var('SELECT GET_LOCK(?, 0)', [self::LOCK]) !== 1) {
            return $result;
        }

        try {
            $p = $db->getPrefix();
            foreach ($db->execute("SELECT id FROM {$p}newsletter_campaigns WHERE status = 'scheduled' AND scheduled_at <= NOW() ORDER BY scheduled_at ASC LIMIT 10")->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $id) {
                $outcome = self::start((int) $id);
                if (!empty($outcome['success'])) {
                    $result['started']++;
                } else {
                    // Ohne Empfänger oder Inhalt nicht endlos erneut versuchen.
                    $repo->setCampaignStatus((int) $id, 'draft', null);
                    Logger::instance()->withChannel('newsletter')->warning('Geplante Kampagne nicht gestartet.', ['campaign' => (int) $id, 'error' => $outcome['error'] ?? '']);
                }
            }

            $budget = $limit ?? NewsletterSettings::int('batch_size');
            $campaigns = [];
            $rows = $db->execute(
                "SELECT s.id AS send_id, s.campaign_id, sub.* FROM {$p}newsletter_sends s
                 JOIN {$p}newsletter_campaigns c ON c.id = s.campaign_id AND c.status = 'sending'
                 JOIN {$p}newsletter_subscribers sub ON sub.id = s.subscriber_id
                 WHERE s.status = 'queued' ORDER BY s.campaign_id ASC, s.id ASC LIMIT " . max(1, $budget)
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $campaignId = (int) $row['campaign_id'];
                $campaigns[$campaignId] ??= $repo->campaign($campaignId);
                $sendId = (int) $row['send_id'];
                if ($row['status'] !== 'active') {
                    $db->execute("UPDATE {$p}newsletter_sends SET status = 'skipped' WHERE id = ?", [$sendId]);
                    continue;
                }
                $outcome = $campaigns[$campaignId] !== null
                    ? NewsletterMailer::sendCampaignTo($campaigns[$campaignId], $row)
                    : ['success' => false, 'error' => 'campaign'];
                if ($outcome['success']) {
                    $db->execute("UPDATE {$p}newsletter_sends SET status = 'sent', sent_at = NOW(), last_error = NULL WHERE id = ?", [$sendId]);
                    $db->execute("UPDATE {$p}newsletter_subscribers SET last_sent_at = NOW() WHERE id = ?", [(int) $row['id']]);
                    $result['sent']++;
                } else {
                    $db->execute("UPDATE {$p}newsletter_sends SET status = 'failed', last_error = ? WHERE id = ?", [mb_substr((string) ($outcome['error'] ?? ''), 0, 1000), $sendId]);
                    $result['failed']++;
                }
            }

            foreach ($db->execute("SELECT id FROM {$p}newsletter_campaigns WHERE status = 'sending'")->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $id) {
                self::syncCounts((int) $id);
                $open = (int) $db->get_var("SELECT COUNT(*) FROM {$p}newsletter_sends WHERE campaign_id = ? AND status = 'queued'", [(int) $id]);
                if ($open === 0) {
                    $db->execute("UPDATE {$p}newsletter_campaigns SET status = 'sent', sent_at = NOW() WHERE id = ?", [(int) $id]);
                    Hooks::doAction('cms_newsletter_campaign_sent', (int) $id);
                    Logger::instance()->withChannel('newsletter')->info('Newsletter-Versand abgeschlossen.', ['campaign' => (int) $id]);
                }
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('newsletter')->error('Newsletter-Versandlauf fehlgeschlagen.', ['error' => $e->getMessage()]);
        } finally {
            $db->get_var('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }

        return $result;
    }

    /** Fehlgeschlagene Zustellungen einer Kampagne erneut einreihen. */
    public static function retryFailed(int $campaignId): int
    {
        $db = Database::instance();
        $p = $db->getPrefix();
        $count = $db->execute("UPDATE {$p}newsletter_sends SET status = 'queued' WHERE campaign_id = ? AND status = 'failed'", [$campaignId])->rowCount();
        if ($count > 0) {
            $db->execute("UPDATE {$p}newsletter_campaigns SET status = 'sending', sent_at = NULL WHERE id = ? AND status IN ('sent', 'sending')", [$campaignId]);
        }

        return $count;
    }

    private static function syncCounts(int $campaignId): void
    {
        $counts = NewsletterRepository::instance()->sendCounts($campaignId);
        $db = Database::instance();
        $db->execute(
            "UPDATE {$db->getPrefix()}newsletter_campaigns SET sent_count = ?, failed_count = ? WHERE id = ?",
            [$counts['sent'], $counts['failed'], $campaignId]
        );
    }
}
