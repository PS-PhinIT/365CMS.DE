<?php
/**
 * Verfahren „image“: Bild-Captcha, lokal mit GD erzeugt (kein Drittanbieter, keine Einwilligung).
 *
 * Abweichung von E7: `gregwar/captcha` ließ sich beim Bau nicht beziehen (kein Zugriff auf die Quelle); das Verfahren zeichnet
 * deshalb selbst. Es ist dieselbe Schicht, keine bessere: fünf Zeichen ohne Verwechslungsgefahr (kein O/0, I/1, B/8, S/5, Q, Z),
 * je Zeichen gedreht, gestreckt und eingefärbt, Störlinien und Punkte, Wellenverzerrung. Für OCR-Bots ist das lösbar; das Verfahren
 * ersetzt weder Honeypot noch Raten noch Freigabe. Das Bild steht als `data:`-URI im HTML (CSP `img-src data:` ist gesetzt).
 * Ohne GD (oder ohne PNG) ist das Verfahren nicht verfügbar; der Dienst fällt auf „math“ zurück.
 *
 * Barrierefreiheit: Das Bild hat einen Hinweistext statt des Codes; die Rechenaufgabe steht immer daneben (eines von beiden genügt).
 *
 * @package CMSv2\Core\Services\Security
 */

declare(strict_types=1);

namespace CMS\Services\Security\Captcha;

use CMS\Services\Security\CaptchaService;

if (!defined('ABSPATH')) {
    exit;
}

final class ImageProvider implements CaptchaProvider
{
    public const ID = 'image';
    public const ALPHABET = 'ACDEFGHJKLMNPRTUVWXY34679';
    public const LENGTH = 5;
    public const WIDTH = 190;
    public const HEIGHT = 64;

    public function id(): string
    {
        return self::ID;
    }

    public function available(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagepng') && function_exists('imagestring');
    }

    public function create(\Closure $int, \Closure $bytes): array
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[$int(0, strlen(self::ALPHABET) - 1)];
        }
        $math = MathChallenge::create($int);

        return [
            'public' => ['image' => self::draw($code, $int), 'math' => $math['public']],
            'private' => ['code' => $code, 'answer' => $math['answer']],
        ];
    }

    public function render(string $id, array $public): string
    {
        $inputId = 'cms-captcha-text-' . $id;
        $alt = CaptchaText::t('captcha.image.alt', 'Sicherheitscode als Bild. Wenn du ihn nicht lesen kannst, löse stattdessen die Rechenaufgabe.');

        return '<fieldset class="cms-captcha cms-captcha--image" data-cms-captcha="image">'
            . '<legend class="cms-captcha__legend">' . CaptchaText::e(CaptchaText::t('captcha.legend', 'Sicherheitsprüfung')) . '</legend>'
            . '<input type="hidden" name="' . CaptchaService::FIELD_ID . '" value="' . CaptchaText::e($id) . '">'
            . '<div class="cms-captcha__image">'
            . '<img src="' . CaptchaText::e((string) ($public['image'] ?? '')) . '" width="' . self::WIDTH . '" height="' . self::HEIGHT . '" alt="' . CaptchaText::e($alt) . '">'
            . '</div>'
            . '<label class="cms-captcha__label" for="' . CaptchaText::e($inputId) . '">' . CaptchaText::e(CaptchaText::t('captcha.image.label', 'Zeichen aus dem Bild')) . '</label>'
            . '<input class="cms-captcha__input" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="' . self::LENGTH
            . '" id="' . CaptchaText::e($inputId) . '" name="' . CaptchaService::FIELD_TEXT . '">'
            . '<p class="cms-captcha__or">' . CaptchaText::e(CaptchaText::t('captcha.image.or', 'oder, ohne Bild:')) . '</p>'
            . MathChallenge::field($id, (array) ($public['math'] ?? ['a' => 1, 'b' => 1, 'op' => 'plus']))
            . '</fieldset>';
    }

    public function verify(array $request, array $private): bool
    {
        $text = $request[CaptchaService::FIELD_TEXT] ?? null;
        if (is_string($text)) {
            $text = strtoupper((string) preg_replace('/\s+/', '', $text));
            if ($text !== '' && hash_equals((string) ($private['code'] ?? ''), $text)) {
                return true;
            }
        }

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

    /**
     * PNG als `data:`-URI.
     *
     * @param \Closure(int, int): int $int
     */
    public static function draw(string $code, \Closure $int): string
    {
        $width = self::WIDTH;
        $height = self::HEIGHT;
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, $int(235, 252), $int(235, 252), $int(235, 252)));

        // Störlinien hinter den Zeichen
        for ($i = 0; $i < 5; $i++) {
            imageline($image, $int(0, $width), $int(0, $height), $int(0, $width), $int(0, $height), imagecolorallocate($image, $int(150, 200), $int(150, 200), $int(150, 200)));
        }

        $cell = (int) floor(($width - 20) / strlen($code));
        foreach (str_split($code) as $index => $char) {
            // Zeichen auf durchsichtigem Grund (Alphakanal), gedreht, dann skaliert in das Bild kopiert.
            $glyph = imagecreatetruecolor(16, 22);
            imagealphablending($glyph, false);
            imagesavealpha($glyph, true);
            $clear = imagecolorallocatealpha($glyph, 0, 0, 0, 127);
            imagefill($glyph, 0, 0, $clear);
            imagealphablending($glyph, true);
            $ink = imagecolorallocate($glyph, $int(0, 90), $int(0, 90), $int(0, 110));
            imagestring($glyph, 5, 3, 3, $char, $ink);
            imagestring($glyph, 5, 4, 3, $char, $ink); // fett: ein Pixel versetzt doppelt gezeichnet
            if (function_exists('imagerotate')) {
                $rotated = imagerotate($glyph, $int(-28, 28), $clear);
                if ($rotated !== false) {
                    imagealphablending($rotated, false);
                    imagesavealpha($rotated, true);
                    $glyph = $rotated;
                }
            }
            $targetWidth = $int(30, 38);
            $targetHeight = $int(40, 50);
            imagecopyresampled($image, $glyph, 10 + $index * $cell + $int(0, 4), $int(4, $height - $targetHeight - 2), 0, 0, $targetWidth, $targetHeight, imagesx($glyph), imagesy($glyph));
        }

        // Wellenverzerrung (Spalten gegeneinander verschieben)
        $wave = imagecreatetruecolor($width, $height);
        imagecopy($wave, $image, 0, 0, 0, 0, $width, $height);
        $amplitude = $int(2, 4);
        $period = $int(28, 44);
        for ($x = 0; $x < $width; $x++) {
            imagecopy($image, $wave, $x, (int) round(sin($x / $period * M_PI * 2) * $amplitude), $x, 0, 1, $height);
        }

        // Punkte und Linien vor den Zeichen
        for ($i = 0; $i < 140; $i++) {
            imagesetpixel($image, $int(0, $width - 1), $int(0, $height - 1), imagecolorallocate($image, $int(60, 180), $int(60, 180), $int(60, 180)));
        }
        for ($i = 0; $i < 2; $i++) {
            imageline($image, 0, $int(8, $height - 8), $width, $int(8, $height - 8), imagecolorallocate($image, $int(90, 160), $int(90, 160), $int(90, 160)));
        }

        ob_start();
        imagepng($image, null, 6);
        $png = (string) ob_get_clean();

        return 'data:image/png;base64,' . base64_encode($png);
    }
}
