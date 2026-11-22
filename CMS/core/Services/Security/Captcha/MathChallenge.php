<?php
/**
 * Rechenaufgabe als barrierefreie Alternative zu jedem anderen Verfahren (und als eigenes Verfahren „math“).
 *
 * Aufgaben mit zwei Zahlen von 1 bis 9 und den Operatoren plus, minus (Ergebnis ≥ 0) und mal; die Frage steht als Text mit
 * ausgeschriebenem Operator („Was ist 7 plus 4?“), damit Screenreader sie vorlesen. Die Antwort ist eine ganze Zahl; Leerraum
 * am Rand ist egal, alles andere ergibt „falsch“.
 *
 * @package CMSv2\Core\Services\Security
 */

declare(strict_types=1);

namespace CMS\Services\Security\Captcha;

use CMS\Services\Security\CaptchaService;

if (!defined('ABSPATH')) {
    exit;
}

final class MathChallenge
{
    public const OPERATORS = ['plus', 'minus', 'times'];

    /**
     * @param \Closure(int, int): int $int
     * @return array{public: array{a: int, b: int, op: string}, answer: int}
     */
    public static function create(\Closure $int): array
    {
        $op = self::OPERATORS[$int(0, 2)];
        $a = $int(1, 9);
        $b = $int(1, 9);
        if ($op === 'minus' && $b > $a) {
            [$a, $b] = [$b, $a];
        }
        $answer = match ($op) {
            'plus' => $a + $b,
            'minus' => $a - $b,
            default => $a * $b,
        };

        return ['public' => ['a' => $a, 'b' => $b, 'op' => $op], 'answer' => $answer];
    }

    public static function check(mixed $given, int $expected): bool
    {
        if (!is_string($given) && !is_int($given)) {
            return false;
        }
        $given = trim((string) $given);

        return preg_match('/^-?\d{1,4}$/', $given) === 1 && (int) $given === $expected;
    }

    /** @param array{a: int, b: int, op: string} $public */
    public static function question(array $public): string
    {
        $word = match ($public['op']) {
            'minus' => CaptchaText::t('captcha.math.minus', 'minus'),
            'times' => CaptchaText::t('captcha.math.times', 'mal'),
            default => CaptchaText::t('captcha.math.plus', 'plus'),
        };

        return CaptchaText::t('captcha.math.question', 'Was ist %d %s %d?', (int) $public['a'], $word, (int) $public['b']);
    }

    /**
     * Feld der Rechenaufgabe.
     *
     * @param array{a: int, b: int, op: string} $public
     */
    public static function field(string $id, array $public, bool $hidden = false): string
    {
        $inputId = 'cms-captcha-answer-' . $id;

        return '<div class="cms-captcha__fallback" data-cms-captcha-fallback' . ($hidden ? ' hidden' : '') . '>'
            . '<label class="cms-captcha__label" for="' . CaptchaText::e($inputId) . '">' . CaptchaText::e(self::question($public)) . '</label>'
            . '<input class="cms-captcha__input" type="text" inputmode="numeric" autocomplete="off" maxlength="4" id="' . CaptchaText::e($inputId) . '" name="'
            . CaptchaService::FIELD_ANSWER . '">'
            . '</div>';
    }
}
