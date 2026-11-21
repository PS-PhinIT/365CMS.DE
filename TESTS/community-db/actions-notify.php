<?php
/**
 * Worker-Aktionen der Einheit N (PR #2b, Interaktion) für `TESTS/community-db/worker.php`; Abschnitte in `sections-notify.php`.
 * Geladen im Gültigkeitsbereich des Workers (`$db`, `$settings`, `$access`, `$can`, `$job`).
 *
 *   notify.run        –                         `NotificationDispatcher::runQueue()` mit echter `NotificationService` und echter
 *                                               `MailQueueService::enqueuePlainStrict()` auf derselben Verbindung (Uhr: echte Zeit)
 *   notify.grouped    user, group, times        `NotificationService::notifyGrouped()` ohne Mutex, `times`-mal
 *   reaction.cycle    users, post, steps        je Mitglied der Liste: `set like`, dann `steps` (Liste aus `love`, `remove`, `like`)
 *
 * Kein Datenbank-Singleton im Worker: `NotificationService` und `MailQueueService` entstehen per Reflection auf `$db`, der
 * Optionsspeicher ist vorbelegt (keine Abfrage der Einstellungen).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

use CMS\Logger;
use CMS\Services\Community\Service\NotificationDispatcher;
use CMS\Services\Community\Service\ReactionService;
use CMS\Services\Community\Service\SubscriptionService;
use CMS\Services\Community\Support\CommunityModule;
use CMS\Services\MailQueueService;
use CMS\Services\NotificationService;
use CMS\Services\OptionStore;
use CMS\Services\SettingsService;

/** @var MariaDbTestDatabase $db */
/** @var \CMS\Services\Community\Support\Settings $settings */
/** @var \Closure $can */

$notifyInstance = static function (string $class, array $properties): object {
    $object = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    \Closure::bind(function () use ($properties): void {
        foreach ($properties as $name => $value) {
            $this->{$name} = $value;
        }
    }, $object, $class)();

    return $object;
};

$notifyCore = static function () use ($db, $notifyInstance): array {
    $core = $notifyInstance(NotificationService::class, ['db' => $db, 'prefix' => 'cms_']);
    (new \ReflectionProperty(NotificationService::class, 'instance'))->setValue(null, $core);
    \Closure::bind(function (): void {
        $this->values = ['mail.queue_cron_token' => 'worker-token'];
        $this->autoloaded = true;
        $this->complete = true;
    }, OptionStore::getInstance(), OptionStore::class)();
    $queue = $notifyInstance(MailQueueService::class, ['db' => $db, 'prefix' => 'cms_', 'settings' => $notifyInstance(SettingsService::class, []), 'logger' => Logger::instance()->withChannel('mail.queue')]);

    return [$core, $queue];
};

return [
    'notify.run' => static function (array $args) use ($db, $settings, $can, $notifyCore): array {
        CommunityModule::setGate(static fn (): bool => true);
        [, $queue] = $notifyCore();
        $subscriptions = new SubscriptionService($db, $settings, null, $can, static function (): void {
        }, null, static fn (): string => 'cdb-notify-secret-0123456789abcdef');
        $dispatcher = new NotificationDispatcher(
            $db,
            $settings,
            null,
            $can,
            null,
            static fn (string $to, string $subject, string $body, array $headers): bool => $queue->enqueuePlainStrict($to, $subject, $body, $headers, NotificationDispatcher::MAIL_SOURCE) > 0,
            static fn (): bool => true,
            null,
            static fn (): ?float => null,
            static function (): void {
            },
            $subscriptions
        );

        return ['success' => true, 'run' => $dispatcher->runQueue()];
    },
    'notify.grouped' => static function (array $args) use ($notifyCore): array {
        [$core] = $notifyCore();
        $inserted = 0;
        for ($i = 0; $i < (int) $args['times']; $i++) {
            $note = $core->notifyGrouped((int) $args['user'], 'community.reply', (string) $args['group'], ['title' => 'Eins', 'title_many' => '{count} neue']);
            $inserted += $note['inserted'] ? 1 : 0;
        }

        return ['success' => true, 'inserted' => $inserted];
    },
    'reaction.cycle' => static function (array $args) use ($db, $settings, $can): array {
        $service = new ReactionService($db, $settings, null, $can, static function (): void {
        }, static fn (): array => ['allowed' => true, 'retry_after' => 0]);
        $errors = [];
        foreach ((array) $args['users'] as $user) {
            foreach (['like', ...(array) $args['steps']] as $step) {
                $result = $step === 'remove' ? $service->remove((int) $user, (int) $args['post']) : $service->set((int) $user, (int) $args['post'], (string) $step);
                if (empty($result['success'])) {
                    $errors[] = (string) ($result['error'] ?? '?');
                }
            }
        }

        return ['success' => $errors === [], 'errors' => array_values(array_unique($errors))];
    },
];
