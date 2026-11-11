<?php
/**
 * Editor.js-Block „Formular“ (`form`): bindet ein Formular aus Inhalte → Formulare in Seiten und Beiträge ein.
 *
 * Daten: `{ "form": "<schlüssel>", "showHeading": true, "showIntro": true }`. Im Frontend rendert derselbe
 * FormRenderer wie auf `/contact/<schlüssel>`; das Formular sendet an `/contact/<schlüssel>` und springt danach
 * auf die Seite mit dem Block zurück. In der Editor-Vorschau ist das Formular deaktiviert; fehlende oder
 * inaktive Formulare werden nur dort angezeigt.
 *
 * @package CMSv2\Core\Services\Forms
 */

declare(strict_types=1);

namespace CMS\Services\Forms;

if (!defined('ABSPATH')) {
    exit;
}

final class FormBlock
{
    public const TYPE = 'form';
    public const SCRIPT = 'js/editor-blocks/form-block.js';

    /**
     * @param array<string, mixed> $data
     * @return array{form: string, showHeading: bool, showIntro: bool}
     */
    public static function sanitize(array $data): array
    {
        $key = FormService::normalizeKey(is_scalar($data['form'] ?? null) ? (string) $data['form'] : '');

        return [
            'form' => $key,
            'showHeading' => self::flag($data['showHeading'] ?? true),
            'showIntro' => self::flag($data['showIntro'] ?? true),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $tunes
     * @param array{mode?: string} $context
     */
    public static function render(array $data, array $tunes = [], array $context = []): string
    {
        $data = self::sanitize($data);
        $preview = ($context['mode'] ?? 'frontend') === 'preview';
        $locale = FormI18n::requestLocale();
        $service = FormService::getInstance();

        if ($data['form'] === '' || !$service->isAvailable()) {
            return $preview ? self::notice(FormI18n::t('forms.block.choose', $locale)) : '';
        }

        $form = $service->findByKey($data['form']);
        if ($form === null || $form['status'] !== FormService::STATUS_ACTIVE) {
            return $preview
                ? self::notice(sprintf(FormI18n::t($form === null ? 'forms.block.missing' : 'forms.block.inactive', $locale), $data['form']))
                : '';
        }

        return FormRenderer::render($form, [
            'context' => $preview ? 'preview' : 'block',
            'locale' => $locale,
            'show_heading' => $data['showHeading'],
            'show_intro' => $data['showIntro'],
        ]);
    }

    private static function notice(string $text): string
    {
        return '<div class="cms-form cms-form--is-preview"><p class="cms-form__notice cms-form__notice--info">' . FormRenderer::esc($text) . '</p></div>';
    }

    private static function flag(mixed $value): bool
    {
        return !in_array($value, [false, 0, '0', 'false', 'off', ''], true);
    }
}
