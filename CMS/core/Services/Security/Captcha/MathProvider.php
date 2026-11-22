<?php
/**
 * Verfahren „math“: nur die Rechenaufgabe (Standard-Rückfall: läuft überall, ohne JavaScript, ohne Bild).
 *
 * @package CMSv2\Core\Services\Security
 */

declare(strict_types=1);

namespace CMS\Services\Security\Captcha;

use CMS\Services\Security\CaptchaService;

if (!defined('ABSPATH')) {
    exit;
}

final class MathProvider implements CaptchaProvider
{
    public const ID = 'math';

    public function id(): string
    {
        return self::ID;
    }

    public function available(): bool
    {
        return true;
    }

    public function create(\Closure $int, \Closure $bytes): array
    {
        $math = MathChallenge::create($int);

        return ['public' => $math['public'], 'private' => ['answer' => $math['answer']]];
    }

    public function render(string $id, array $public): string
    {
        return '<fieldset class="cms-captcha cms-captcha--math" data-cms-captcha="math">'
            . '<legend class="cms-captcha__legend">' . CaptchaText::e(CaptchaText::t('captcha.legend', 'Sicherheitsprüfung')) . '</legend>'
            . '<input type="hidden" name="' . CaptchaService::FIELD_ID . '" value="' . CaptchaText::e($id) . '">'
            . MathChallenge::field($id, $public)
            . '</fieldset>';
    }

    public function verify(array $request, array $private): bool
    {
        return MathChallenge::check($request[CaptchaService::FIELD_ANSWER] ?? null, (int) ($private['answer'] ?? PHP_INT_MIN));
    }

    public function requiresConsent(): bool
    {
        return false;
    }

    public function cspSources(): array
    {
        return [];
    }
}
