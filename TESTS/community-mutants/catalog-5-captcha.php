<?php
/**
 * Katalog PR #188 (Captcha): Core-Dienst `Security\CaptchaService` samt Verfahren (Suite `captcha`) und `CaptchaGate`, Einbindung und Einstellungen
 * der Community (Suite `community-captcha`).
 *
 * Gleiches Format wie `catalog.php`: Name => [Datei relativ zu CMS/, Suchtext, Ersatz, Suite, needsDb, Regel, quick, env]
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

return [
    'captcha-store-single-use' => ["core/Services/Security/Captcha/CaptchaStore.php", "unset(\$open[\$id]);", "\$open[\$id] = \$open[\$id];", 'captcha', false, "Eine Aufgabe gilt nur einmal (auch nach einer falschen Antwort verbraucht)", true, []],
    'captcha-store-expiry' => ["core/Services/Security/Captcha/CaptchaStore.php", "\$record['expired'] = (int) (\$record['expires'] ?? 0) < \$nowTs;", "\$record['expired'] = false;", 'captcha', false, "Eine abgelaufene Aufgabe besteht nicht (expired)", true, []],
    'captcha-store-limit' => ["core/Services/Security/Captcha/CaptchaStore.php", "array_slice(\$open, -self::MAX_OPEN, null, true)", "\$open", 'captcha', false, "Höchstens MAX_OPEN offene Aufgaben je Sitzung", false, []],
    'captcha-service-context' => ["core/Services/Security/CaptchaService.php", "if ((string) (\$record['context'] ?? '') !== \$context) {", "if (false) {", 'captcha', false, "Eine Aufgabe gilt nur für ihren Kontext", true, []],
    'captcha-service-fallback' => ["core/Services/Security/CaptchaService.php", "if (\$provider === null || !\$provider->available() || \$provider->requiresConsent()) {", "if (\$provider === null) {", 'captcha', false, "Nicht verfügbare oder einwilligungspflichtige Verfahren werden nie gewählt (Rückfall math)", true, []],
    'captcha-pow-hash' => ["core/Services/Security/Captcha/ProofOfWorkProvider.php", "&& hash_equals((string) (\$private['challenge'] ?? ''), hash('sha256', (string) (\$private['salt'] ?? '') . \$n));", "&& true;", 'captcha', false, "Die Zahl muss SHA-256(Salz + n) = Aufgabe erfüllen", true, []],
    'captcha-pow-max' => ["core/Services/Security/Captcha/ProofOfWorkProvider.php", "\$n <= (int) (\$private['max'] ?? 0)", "true", 'captcha', false, "Eine Zahl über der Grenze besteht nicht", false, []],
    'captcha-pow-alternative' => ["core/Services/Security/Captcha/ProofOfWorkProvider.php", "return MathChallenge::check(\$request[CaptchaService::FIELD_ANSWER] ?? null, (int) (\$private['answer'] ?? PHP_INT_MIN));", "return false;", 'captcha', false, "Die Rechenaufgabe ist eine gleichwertige Alternative zum Proof-of-Work", false, []],
    'captcha-math-strict' => ["core/Services/Security/Captcha/MathChallenge.php", "preg_match('/^-?\\d{1,4}\$/', \$given) === 1", "preg_match('/\\d+/', \$given) === 1", 'captcha', false, "Die Antwort ist eine ganze Zahl (7.0 und Text bestehen nicht)", true, []],
    'captcha-math-negative' => ["core/Services/Security/Captcha/MathChallenge.php", "if (\$op === 'minus' && \$b > \$a) {", "if (false) {", 'captcha', false, "Das Ergebnis der Rechenaufgabe ist nie negativ", false, []],
    'captcha-image-alphabet' => ["core/Services/Security/Captcha/ImageProvider.php", "'ACDEFGHJKLMNPRTUVWXY34679'", "'ACDEFGHJKLMNPRTUVWXY3467O0'", 'captcha', false, "Der Bildcode enthält keine verwechselbaren Zeichen", false, []],
    'captcha-image-case' => ["core/Services/Security/Captcha/ImageProvider.php", "\$text = strtoupper((string) preg_replace('/\\s+/', '', \$text));", "\$text = (string) \$text;", 'captcha', false, "Der Bildcode wird ohne Beachtung von Groß-/Kleinschreibung und Leerraum geprüft", false, []],
    'captcha-gate-first-post' => ["core/Services/Community/Service/CaptchaGate.php", "'first_post' => \$profile['topics'] + \$profile['posts'] === 0,", "'first_post' => \$profile['topics'] + \$profile['posts'] === 1,", 'community-captcha', false, "Modus first_post: nur bis zum ersten veröffentlichten Thema oder Beitrag", true, []],
    'captcha-gate-exempt' => ["core/Services/Community/Service/CaptchaGate.php", "\$required = !\$profile['exempt'] && match (\$mode) {", "\$required = match (\$mode) {", 'community-captcha', false, "Die globale Moderation ist vom Captcha ausgenommen", true, []],
    'captcha-gate-fail-closed' => ["core/Services/Community/Service/CaptchaGate.php", "} catch (\\Throwable) {\n                \$required = true;", "} catch (\\Throwable) {\n                \$required = false;", 'community-captcha', false, "Ein nicht lesbares Profil verlangt ein Captcha (fail-closed)", false, []],
    'captcha-gate-filter' => ["core/Services/Community/Service/CaptchaGate.php", "=== true) {\n                \$required = true;", "!== true) {\n                \$required = true;", 'community-captcha', false, "Der Filter cms_community_captcha_required darf nur verschärfen", false, []],
    'captcha-gate-log' => ["core/Services/Community/Service/CaptchaGate.php", "if (!\$result->ok && \$userId > 0) {", "if (false) {", 'community-captcha', false, "Ein Fehlschlag kommt als captcha_failed ins Sicherheitsprotokoll", true, []],
    'captcha-compose-result' => ["core/Services/Community/Http/ComposeController.php", "return \$result->ok ? null : CaptchaGate::errorText(\$result);", "return null;", 'community-captcha', false, "Der Controller weist eine falsche Antwort ab (Text für das Formular)", true, []],
    'captcha-settings-enum' => ["core/Services/Community/Support/Settings.php", "return in_array(\$value, \$values, true) ? \$value : \$default;", "return \$value;", 'community-captcha', false, "Ein unbekanntes Captcha-Verfahren oder -Modus fällt auf den Standard zurück", false, []],
];
