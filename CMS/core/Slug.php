<?php
declare(strict_types=1);

namespace CMS;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gemeinsame Slug-Erzeugung für Beiträge, Kategorien, Tags und Seiten.
 *
 * Umlaute werden umgeschrieben (ä → ae, ö → oe, ü → ue, ß → ss), andere Akzente entfernt (é → e), erst danach wird
 * jedes Zeichen außerhalb von [a-z0-9-] durch „-“ ersetzt. Aus „Größe der Postfächer“ wird so
 * `groesse-der-postfaecher` statt `gr-e-der-postf-cher`.
 *
 * Reine ASCII-Eingaben ergeben dasselbe wie die frühere Ersetzung; bereits gespeicherte Slugs bleiben deshalb
 * unverändert, wenn sie erneut durch den Helfer laufen.
 */
final class Slug
{
    /**
     * Umschrift vor der Unicode-Zerlegung, sonst würde aus „ä“ nur „a“. Die Großbuchstaben stehen nur für den Fall
     * ohne mbstring dabei, in dem strtolower() sie nicht umwandelt.
     */
    private const TRANSLITERATION = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ẞ' => 'ss',
        // Buchstaben ohne Zerlegung in Grundbuchstabe und Akzent (Unicode kennt für sie keinen kombinierenden Akzent), ohne
        // diese Zeilen würden sie zu „-“ (Łódź → odz, Søren → s-ren): Polnisch, Dänisch/Norwegisch, Isländisch, Kroatisch, Türkisch.
        'ł' => 'l', 'ø' => 'o', 'æ' => 'ae', 'œ' => 'oe', 'þ' => 'th', 'đ' => 'd', 'ð' => 'd', 'ı' => 'i',
        'Ł' => 'l', 'Ø' => 'o', 'Æ' => 'ae', 'Œ' => 'oe', 'Þ' => 'th', 'Đ' => 'd', 'Ð' => 'd',
    ];

    /**
     * Ersatz für häufige Akzente, falls die Erweiterung intl (Normalizer) fehlt. intl ist nur empfohlen.
     */
    private const FALLBACK_ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ĉ' => 'c',
        'ď' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ğ' => 'g',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
        'ľ' => 'l', 'ĺ' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ō' => 'o', 'ő' => 'o',
        'ŕ' => 'r', 'ř' => 'r',
        'ś' => 's', 'š' => 's', 'ş' => 's', 'ș' => 's',
        'ť' => 't', 'ţ' => 't', 'ț' => 't',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
    ];

    private function __construct()
    {
    }

    /**
     * Erzeugt einen URL-Slug aus beliebigem Text (Titel, Name oder eingegebener Slug).
     *
     * @param int $maxLength Optional auf diese Länge kürzen (0 = ungekürzt), z. B. 100 für Kategorien und Tags.
     */
    public static function fromText(string $text, int $maxLength = 0): string
    {
        $slug = self::transliterate(trim($text));
        $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug) ?? $slug;
        $slug = preg_replace('/-+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');

        if ($maxLength > 0 && strlen($slug) > $maxLength) {
            // Nach der Umschrift ist der Slug reines ASCII, deshalb genügt substr().
            $slug = trim(substr($slug, 0, $maxLength), '-');
        }

        return $slug;
    }

    /**
     * Kleinschreibung und Umschrift ins ASCII-Alphabet, ohne Ersetzung der übrigen Zeichen.
     */
    public static function transliterate(string $text): string
    {
        $hasNormalizer = class_exists(\Normalizer::class);

        // Zerlegt eingegebene Umlaute („a“ + U+0308) erst zusammensetzen, damit die Umschrift greift.
        if ($hasNormalizer && preg_match('/[^\x00-\x7F]/', $text) === 1) {
            $composed = \Normalizer::normalize($text, \Normalizer::FORM_C);
            $text = is_string($composed) ? $composed : $text;
        }

        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = strtr($text, self::TRANSLITERATION);

        if (preg_match('/[^\x00-\x7F]/', $text) !== 1) {
            return $text;
        }

        if ($hasNormalizer) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_KD);
            if (is_string($decomposed)) {
                return preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $decomposed;
            }
        }

        return strtr($text, self::FALLBACK_ACCENTS);
    }
}
