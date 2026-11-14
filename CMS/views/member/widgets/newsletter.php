<?php
/**
 * Newsletter-Widget des Mitgliederbereichs (Dashboard-Widget und Unterseite `/member/plugin/newsletter`).
 *
 * Im Theme überschreibbar als `<theme>/member/widgets/newsletter.php` (Theme-Pfad aus ThemeManager::getThemePath()).
 * Eingebunden von NewsletterMemberSection::renderTemplate(); die Ausgabe wird als fertiges Markup weitergereicht,
 * daher muss jede Ausgabe hier escaped sein.
 *
 * Vertrag (stabil, auch für Theme-Overrides):
 *
 * @var array{status: string, email: string, segment: string, segment_label: string, locale: string,
 *            confirmed_at: ?string, optin_sent_at: ?string, can_resend: bool, resend_wait: int, verified: bool,
 *            double_opt_in: bool, subscriber_id: int} $status
 *        Ergebnis von NewsletterMemberService::statusFor(); `status` ist none | unverified | pending | active |
 *        unsubscribed | bounced; `resend_wait` = Sekunden bis „Erneut senden“ wieder möglich ist.
 * @var string $csrfField    Fertiges Hidden-Feld `csrf_token` (ein Token je Seitenaufruf für alle Formulare).
 * @var string $actionUrl    Ziel aller Formulare: '/member/plugin/newsletter'.
 * @var array<string, string> $segments Themen slug => Name; leer, wenn die Themenauswahl abgeschaltet ist.
 * @var array<string, string> $locales  Sprachen code => Name (aktive Inhaltssprachen, die der Newsletter kennt).
 * @var string $consentText  Einwilligungstext in der Sprache der Oberfläche (Pflicht-Checkbox `newsletter_consent`).
 * @var array{type: string, message: string}|null $notice Rückmeldung nach dem POST (type success|danger|warning|info).
 * @var string $returnTo     'dashboard' (Widget im Dashboard, eigene Karte mit Anker) oder 'section' (Unterseite).
 *
 * Formularfelder: `newsletter_action` (subscribe | update | resend | unsubscribe), `return_to`, `newsletter_consent`,
 * `segment`, `locale`, `csrf_token`.
 */

declare(strict_types=1);

use CMS\Services\Newsletter\NewsletterI18n;
use CMS\Services\Newsletter\NewsletterSettings;

if (!defined('ABSPATH')) {
    exit;
}

$nlLocale = NewsletterI18n::requestLocale();
$nlE = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$nlT = static fn (string $key, string|int|float ...$args): string => htmlspecialchars(NewsletterI18n::t('newsletter.member.' . $key, $nlLocale, ...$args), ENT_QUOTES, 'UTF-8');
$nlDate = static fn (?string $value): string => $value !== null && $value !== '' ? NewsletterI18n::date($value, $nlLocale) : '';

$nlState = (string) ($status['status'] ?? 'none');
$nlIsWidget = $returnTo === 'dashboard';
$nlId = $nlIsWidget ? 'nl-dash' : 'nl-sec';
$nlEmail = (string) ($status['email'] ?? '');
$nlBadge = [
    'none' => ['badge_none', 'bg-secondary-lt'],
    'unverified' => ['badge_none', 'bg-secondary-lt'],
    'pending' => ['badge_pending', 'bg-warning-lt'],
    'active' => ['badge_active', 'bg-success-lt'],
    'unsubscribed' => ['badge_unsubscribed', 'bg-secondary-lt'],
    'bounced' => ['badge_bounced', 'bg-danger-lt'],
][$nlState] ?? ['badge_none', 'bg-secondary-lt'];
$nlCanSubscribe = in_array($nlState, ['none', 'unverified', 'unsubscribed', 'bounced'], true);
$nlCurrentLocale = (string) ($status['locale'] ?? 'de');
$nlCurrentSegment = (string) ($status['segment'] ?? '');

/** Gemeinsame versteckte Felder jedes Formulars. */
$nlHidden = static fn (string $action): string => $csrfField
    . '<input type="hidden" name="newsletter_action" value="' . $nlE($action) . '">'
    . '<input type="hidden" name="return_to" value="' . $nlE($returnTo === 'dashboard' ? 'dashboard' : 'section') . '">';

/** Auswahl für Thema und Sprache (nur, wenn es etwas zu wählen gibt). */
$nlChoices = static function () use ($segments, $locales, $nlCurrentSegment, $nlCurrentLocale, $nlId, $nlE): string {
    $html = '';
    if (count($segments) > 1) {
        $html .= '<div class="mb-3"><label class="form-label" for="' . $nlId . '-segment">' . $nlE(NewsletterI18n::t('newsletter.public.segment', NewsletterI18n::requestLocale())) . '</label>'
            . '<select class="form-select" id="' . $nlId . '-segment" name="segment">';
        foreach ($segments as $slug => $label) {
            $html .= '<option value="' . $nlE($slug) . '"' . ((string) $slug === $nlCurrentSegment ? ' selected' : '') . '>' . $nlE($label) . '</option>';
        }
        $html .= '</select></div>';
    }
    if (count($locales) > 1) {
        $html .= '<div class="mb-3"><label class="form-label" for="' . $nlId . '-locale">' . $nlE(NewsletterI18n::t('newsletter.member.language', NewsletterI18n::requestLocale())) . '</label>'
            . '<select class="form-select" id="' . $nlId . '-locale" name="locale">';
        foreach ($locales as $code => $label) {
            $html .= '<option value="' . $nlE($code) . '" lang="' . $nlE($code) . '"' . ((string) $code === $nlCurrentLocale ? ' selected' : '') . '>' . $nlE($label) . '</option>';
        }
        $html .= '</select></div>';
    } elseif ($locales !== []) {
        $html .= '<input type="hidden" name="locale" value="' . $nlE((string) array_key_first($locales)) . '">';
    }

    return $html;
};
$nlIntro = NewsletterSettings::localized('subscribe_intro', $nlLocale);
?>
<?php if ($nlIsWidget): ?>
<section class="card cms-nl-widget cms-nl-widget--dashboard" id="newsletter" aria-labelledby="<?= $nlId ?>-title" tabindex="-1">
    <div class="card-header">
        <h3 class="card-title" id="<?= $nlId ?>-title"><span aria-hidden="true">✉️</span> <?= $nlT('title') ?></h3>
        <div class="card-actions"><span class="badge <?= $nlE($nlBadge[1]) ?> cms-nl-widget__badge"><span class="visually-hidden"><?= $nlT('status_prefix') ?> </span><?= $nlT($nlBadge[0]) ?></span></div>
    </div>
    <div class="card-body">
<?php else: ?>
<div class="cms-nl-widget cms-nl-widget--section" id="newsletter">
    <p class="cms-nl-widget__status"><?= $nlT('status_prefix') ?> <span class="badge <?= $nlE($nlBadge[1]) ?> cms-nl-widget__badge"><?= $nlT($nlBadge[0]) ?></span></p>
<?php endif; ?>

        <?php if (is_array($notice) && (string) ($notice['message'] ?? '') !== ''): ?>
            <?php $nlNoticeType = in_array($notice['type'] ?? '', ['success', 'danger', 'warning', 'info'], true) ? (string) $notice['type'] : 'info'; ?>
            <div class="alert alert-<?= $nlE($nlNoticeType) ?> cms-nl-widget__notice" role="<?= in_array($nlNoticeType, ['danger', 'warning'], true) ? 'alert' : 'status' ?>">
                <?= $nlE($notice['message']) ?>
            </div>
        <?php endif; ?>

        <?php if ($nlState === 'pending'): ?>
            <p><?= $status['optin_sent_at'] !== null ? $nlT('pending_since', $nlDate($status['optin_sent_at']), $nlEmail) : $nlT('state_pending', $nlEmail) ?></p>
            <div class="cms-nl-widget__actions">
                <form method="post" action="<?= $nlE($actionUrl) ?>">
                    <?= $nlHidden('resend') ?>
                    <button type="submit" class="btn btn-primary"<?= empty($status['can_resend']) ? ' disabled aria-describedby="' . $nlId . '-resend-hint"' : '' ?>><?= $nlT('resend') ?></button>
                </form>
                <form method="post" action="<?= $nlE($actionUrl) ?>" data-cms-confirm="<?= $nlT('withdraw_confirm') ?>">
                    <?= $nlHidden('unsubscribe') ?>
                    <button type="submit" class="btn btn-outline-secondary"><?= $nlT('withdraw') ?></button>
                </form>
            </div>
            <?php if (empty($status['can_resend'])): ?>
                <p class="form-hint mt-2" id="<?= $nlId ?>-resend-hint"><?= $nlT('resend_locked', max(1, (int) ceil(((int) ($status['resend_wait'] ?? 0)) / 60))) ?></p>
            <?php endif; ?>

        <?php elseif ($nlState === 'active'): ?>
            <p><?= $status['confirmed_at'] !== null ? $nlT('active_since', $nlEmail, $nlDate($status['confirmed_at'])) : $nlT('state_active', $nlEmail) ?></p>
            <?php if ((string) ($status['segment_label'] ?? '') !== '' && count($segments) < 2): ?>
                <p class="text-secondary small"><?= $nlE(NewsletterI18n::t('newsletter.public.segment', $nlLocale)) ?>: <?= $nlE($status['segment_label']) ?></p>
            <?php endif; ?>
            <?php $nlChoiceHtml = $nlChoices(); ?>
            <?php if (str_contains($nlChoiceHtml, '<select')): ?>
                <form method="post" action="<?= $nlE($actionUrl) ?>" class="mb-3">
                    <?= $nlHidden('update') ?>
                    <?= $nlChoiceHtml ?>
                    <button type="submit" class="btn btn-primary"><?= $nlT('save') ?></button>
                </form>
            <?php endif; ?>
            <form method="post" action="<?= $nlE($actionUrl) ?>" data-cms-confirm="<?= $nlT('unsubscribe_confirm') ?>">
                <?= $nlHidden('unsubscribe') ?>
                <button type="submit" class="btn btn-outline-danger"><?= $nlT('unsubscribe') ?></button>
            </form>

        <?php elseif ($nlCanSubscribe): ?>
            <?php if ($nlState === 'unsubscribed'): ?>
                <p><?= $nlT('state_unsubscribed', $nlEmail) ?></p>
            <?php elseif ($nlState === 'bounced'): ?>
                <p><?= $nlT('state_bounced', $nlEmail) ?></p>
            <?php elseif ($nlState === 'unverified'): ?>
                <p><?= $nlT('state_unverified', $nlEmail) ?></p>
            <?php elseif ($nlIntro !== ''): ?>
                <p><?= $nlE($nlIntro) ?></p>
            <?php endif; ?>
            <form method="post" action="<?= $nlE($actionUrl) ?>" class="cms-nl-widget__form">
                <?= $nlHidden('subscribe') ?>
                <div class="mb-3">
                    <label class="form-label" for="<?= $nlId ?>-email"><?= $nlT('email_label') ?></label>
                    <input type="email" class="form-control" id="<?= $nlId ?>-email" value="<?= $nlE($nlEmail) ?>" readonly aria-describedby="<?= $nlId ?>-email-hint" autocomplete="email">
                    <p class="form-hint" id="<?= $nlId ?>-email-hint"><?= $nlT('email_hint') ?> <a href="/member/profile"><?= $nlT('profile_link') ?></a></p>
                </div>
                <?= $nlChoices() ?>
                <div class="mb-3">
                    <label class="form-check">
                        <input class="form-check-input" type="checkbox" name="newsletter_consent" value="1" required aria-required="true" aria-describedby="<?= $nlId ?>-consent-hint">
                        <span class="form-check-label"><?= $nlE($consentText) ?></span>
                    </label>
                    <p class="form-hint" id="<?= $nlId ?>-consent-hint"><?= $nlT('consent_hint') ?><?= !empty($status['double_opt_in']) ? ' ' . $nlT('doi_hint') : '' ?></p>
                </div>
                <button type="submit" class="btn btn-primary"><?= $nlT(in_array($nlState, ['unsubscribed', 'bounced'], true) ? 'resubscribe' : 'subscribe') ?></button>
            </form>
        <?php endif; ?>

<?php if ($nlIsWidget): ?>
    </div>
    <div class="card-footer"><a href="<?= $nlE($actionUrl) ?>" class="cms-nl-widget__more"><?= $nlT('open_section') ?></a></div>
</section>
<?php else: ?>
</div>
<?php endif; ?>
