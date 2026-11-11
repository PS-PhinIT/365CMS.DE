<?php
/**
 * Mitgliederbereich „Kontaktanfragen“: eigene Formulareingänge ansehen und löschen
 * (ehemals Plugin cms-contact, Bereich `/member/plugin/contact`).
 *
 * Angezeigt werden nur Eingänge, die mit dem angemeldeten Konto abgesendet wurden. Löschen läuft per POST mit
 * dem Core-Token der Registry (`csrf => core`).
 *
 * @package CMSv2\Core\Services\Forms
 */

declare(strict_types=1);

namespace CMS\Services\Forms;

use CMS\Member\PluginDashboardRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class FormMemberSection
{
    public const SLUG = 'contact';
    private const LIST_LIMIT = 50;

    public static function register(PluginDashboardRegistry $registry): void
    {
        if (!FormService::getInstance()->isAvailable()) {
            return;
        }

        $registry->register([
            // Plugin-Slug bleibt erhalten, damit die Sichtbarkeits-Einstellung des Mitgliederbereichs weiter gilt.
            'plugin' => 'cms-contact',
            'slug' => self::SLUG,
            'label' => FormI18n::t('forms.member.title'),
            'icon' => '📩',
            'category' => 'plugins',
            'priority' => 80,
            'csrf' => 'core',
            'dashboard_widget' => [
                'title' => FormI18n::t('forms.member.title'),
                'description' => FormI18n::t('forms.member.widget_description'),
                'color' => '#2563eb',
                'stats_callback' => [self::class, 'stats'],
            ],
            'render_callback' => [self::class, 'render'],
            'post_callback' => [self::class, 'handlePost'],
        ]);
    }

    /**
     * @return array{count: int, label: string}
     */
    public static function stats(object $user): array
    {
        $count = FormSubmissionService::getInstance()->count(['user_id' => (int) ($user->id ?? 0)]);

        return ['count' => $count, 'label' => FormI18n::t($count === 1 ? 'forms.member.count_one' : 'forms.member.count_many')];
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function handlePost(object $user, array $params = []): void
    {
        $userId = (int) ($user->id ?? 0);
        $id = (int) ($_POST['submission_id'] ?? 0);
        $notice = null;

        if ((string) ($_POST['member_contact_action'] ?? '') === 'delete_submission' && $userId > 0 && $id > 0) {
            $deleted = FormSubmissionService::getInstance()->deleteForUser($id, $userId);
            $notice = $deleted
                ? ['success', FormI18n::t('forms.member.deleted')]
                : ['danger', FormI18n::t('forms.member.delete_failed')];
        }

        self::renderList($userId, $notice);
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function render(object $user, array $params = []): void
    {
        $userId = (int) ($user->id ?? 0);
        $viewId = (int) ($_GET['view'] ?? 0);
        if ($viewId > 0) {
            $submission = FormSubmissionService::getInstance()->find($viewId);
            if ($submission !== null && $submission['user_id'] === $userId && $submission['status'] !== FormSubmissionService::STATUS_TRASH) {
                self::renderDetail($submission);

                return;
            }
            self::renderList($userId, ['danger', FormI18n::t('forms.member.not_found')]);

            return;
        }

        self::renderList($userId, null);
    }

    /**
     * @param array{0: string, 1: string}|null $notice
     */
    private static function renderList(int $userId, ?array $notice): void
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $submissions = FormSubmissionService::getInstance()->listForUser($userId, self::LIST_LIMIT);
        $base = '/member/plugin/' . self::SLUG;

        if ($notice !== null) {
            echo '<div class="alert alert-' . $e($notice[0]) . '" role="status">' . $e($notice[1]) . '</div>';
        }
        if ($submissions === []) {
            echo '<p class="text-secondary mb-0">' . $e(FormI18n::t('forms.member.empty')) . '</p>';

            return;
        }

        echo '<div class="table-responsive"><table class="table table-vcenter"><thead><tr>'
            . '<th>' . $e(FormI18n::t('forms.member.col_date')) . '</th>'
            . '<th>' . $e(FormI18n::t('forms.member.col_form')) . '</th>'
            . '<th>' . $e(FormI18n::t('forms.member.col_subject')) . '</th>'
            . '<th class="w-1"></th></tr></thead><tbody>';
        foreach ($submissions as $submission) {
            $subject = $submission['subject'] !== '' ? $submission['subject'] : '–';
            echo '<tr><td class="text-nowrap">' . $e(self::formatDate((string) $submission['created_at'])) . '</td>'
                . '<td>' . $e($submission['form_title']) . '</td>'
                . '<td><a href="' . $e($base . '?view=' . (int) $submission['id']) . '">' . $e($subject) . '</a></td>'
                . '<td class="text-nowrap">' . self::deleteForm((int) $submission['id']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * @param array<string, mixed> $submission
     */
    private static function renderDetail(array $submission): void
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $base = '/member/plugin/' . self::SLUG;

        echo '<p><a href="' . $e($base) . '">← ' . $e(FormI18n::t('forms.member.back')) . '</a></p>';
        echo '<p class="text-secondary">' . $e($submission['form_title']) . ' · ' . $e(self::formatDate((string) $submission['created_at'])) . '</p>';
        echo '<dl class="row">';
        foreach ($submission['data'] as $entry) {
            if (($entry['type'] ?? '') === 'hidden') {
                continue;
            }
            $text = trim((string) ($entry['text'] ?? ''));
            echo '<dt class="col-sm-4">' . $e($entry['label'] ?? '') . '</dt><dd class="col-sm-8">' . ($text !== '' ? nl2br($e($text)) : '–') . '</dd>';
        }
        echo '</dl>';
        echo self::deleteForm((int) $submission['id']);
    }

    private static function deleteForm(int $id): string
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        return '<form method="post" action="/member/plugin/' . self::SLUG . '" class="d-inline"'
            . ' data-confirm-title="' . $e(FormI18n::t('forms.member.delete')) . '"'
            . ' data-confirm-message="' . $e(FormI18n::t('forms.member.delete_confirm')) . '"'
            . ' data-confirm-text="' . $e(FormI18n::t('forms.member.delete')) . '" data-confirm-class="btn-danger">'
            . PluginDashboardRegistry::instance()->csrfField(self::SLUG)
            . '<input type="hidden" name="member_contact_action" value="delete_submission">'
            . '<input type="hidden" name="submission_id" value="' . $id . '">'
            . '<button type="submit" class="btn btn-sm btn-outline-danger">' . $e(FormI18n::t('forms.member.delete')) . '</button></form>';
    }

    private static function formatDate(string $value): string
    {
        $time = strtotime($value);

        return $time !== false ? date('d.m.Y H:i', $time) : $value;
    }
}
