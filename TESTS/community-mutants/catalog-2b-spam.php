<?php
/**
 * Katalog der Einheit S (PR #2b, Welle 2): `Support\IpHash`, `Service\ContentGuard` und die Wortfilter-Normalisierung in
 * `Support\Settings` laut Vertrag DESIGN-2b §3.13, §4.7 Nr. 7, §4.9, §7.4 und Entwurf schutz §6.4.
 *
 * Gleiches Format wie `catalog.php`: Name => [Datei relativ zu CMS/, Suchtext, Ersatz, Suite, needsDb, Regel, quick, env]
 *
 * Herkunft: schutz §6.4 (`word-*`, `dup-*`, `spam-filter-*`, `antispam-*`, `iphash-*`, `throttle-*`, `reject-log-unthrottled`,
 * `limit-below-minus-one-unlimited`), Vertrag §7.4 (`spam-filter-throw-open`, Fassung 3: `throttle-count-first`, `search-guest-global`,
 * `hosts-filter-skipped`; Fassung 3.1: `ignorable-kept`). Die übrigen Zeilen sichern Regeln dieser Einheit ohne Vorlage.
 *
 * Nicht hier: `content-*`, `review-*`, `edit-review-ignored`, `limit-zero-unlimited` (CommunityAccess, Katalog von A), `service-*`,
 * `edit-unthrottled`, `content-gate-skipped`, `analyze-before-gate` (T in W3, `catalog-2b-services.php`), `race-review-cap-db` (W4).
 * `Security::pruneDbRateLimitAttempts()` liegt in `core/Security.php`, das nicht in der Allowlist des Treibers steht; seine Regeln prüft
 * `community-db` (`race-throttle`) und `community-spam` (SQL und Parameter).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

$guard = 'core/Services/Community/Service/ContentGuard.php';
$ipHash = 'core/Services/Community/Support/IpHash.php';
$settings = 'core/Services/Community/Support/Settings.php';

return [
    // ── Einstellungen (Settings::normalize, Schema trust_limits und word_filter)
    'limit-below-minus-one-unlimited' => [$settings, 'return $parsed < self::UNLIMITED ? $default : $parsed;', 'return max(self::UNLIMITED, $parsed);', 'community-access', false, 'trust_limits: Werte unter -1 ergeben den Standard des Feldes (fail-safe), nicht „unbegrenzt“', false, []],
    'word-filter-cap-ignored' => [$settings, 'array_slice($entries, 0, self::WORD_FILTER_MAX)', '$entries', 'community-spam', false, 'word_filter: höchstens WORD_FILTER_MAX = 500 Einträge in Vorrangreihenfolge', false, []],
    'word-replacement-letters' => [$settings, "preg_match('/[\\p{L}\\p{N}\\p{Cc}]/u', \$raw) === 1", 'false', 'community-spam', false, 'word_filter: Ersatztext ohne Buchstaben, Ziffern und Steuerzeichen (sonst ***)', false, []],

    // ── Wortfilter und Vergleichsfassung (S1, S2; Vertrag §4.9)
    'word-not-whole-word' => [$guard, "'/(?<![\\p{L}\\p{N}_])(?:'", "'/(?:'", 'community-spam', false, 'Wortfilter trifft nur ganze Wörter (Scunthorpe, class, passage)', true, []],
    'word-fold-skipped' => [$guard, ' || self::matchesAny($foldedPatterns, $folded)', '', 'community-spam', false, 'Wortfilter prüft zusätzlich die Vergleichsfassung (STRASSE/Straße, Vollbreite)', false, []],
    'word-pcre-error-allows' => [$guard, "'verdict' => self::REVIEW, 'replaced' => false]; // PCRE-Fehler", "'verdict' => self::ALLOW, 'replaced' => false]; // PCRE-Fehler", 'community-spam', false, 'PCRE-Fehler im Wortfilter ergibt review, nie stilles Durchlassen', false, []],
    'word-replace-folded-unchecked' => [$guard, ' || self::matchesAny($this->patterns()[\'replace\'][1], $folded)', '', 'community-spam', false, 'ein replace-Eintrag, der nur in der Vergleichsfassung vorkommt, ergibt review', false, []],
    'ignorable-kept' => [$guard, 'return (string) preg_replace(self::IGNORABLE, \'\', $text);', 'return $text;', 'community-spam', false, 'Vergleichsfassung ohne unsichtbare Default-Ignorable-Zeichen (CGJ, VS16, VS1, Hangul-Füller)', false, []],
    'hosts-filter-skipped' => [$guard, '$hostVerdict = $this->filterHosts($input[\'hosts\'] ?? []);', '$hostVerdict = self::ALLOW;', 'community-spam', false, 'screen() prüft die normalisierten Hosts mit dem Wortfilter (evil%2Ecom trifft evil.com)', true, []],

    // ── Zählung
    'content-links-all' => [$guard, "array_key_exists('external_links', \$result) ? \$result['external_links'] : (\$result['links'] ?? null)", "(\$result['links'] ?? null)", 'community-spam', false, 'content(): Links = external_links des Renderers (fremde Links und mailto), nicht alle Links', false, []],
    'content-failed-ignored' => [$guard, "            \$signals[] = 'inspect_failed';\n", '', 'community-spam', false, 'fehlgeschlagene Zählung ergibt das Signal inspect_failed (review)', false, []],

    // ── Core-Antispam (S5)
    'antispam-in-cli' => [$guard, "!\$client['web'] || ", '', 'community-spam', false, 'Core-Antispam nur mit Web-Anfrage', false, []],
    'antispam-scheme-kept' => [$guard, "'~\\b(?:https?|ftp)://~i'", "'~(?!)~'", 'community-spam', false, 'Antispam-Inhalt ohne Schemata (die Core-Linkgrenze zählt Markdown falsch)', false, []],
    'antispam-throw-blocks' => [$guard, 'return false; // Antispam fail-open', 'return true; // Antispam fail-open', 'community-spam', false, 'Ausnahme im Core-Antispam lässt den Beitrag durch (fail-open) und wird protokolliert', false, []],
    'antispam-hosts-missing' => [$guard, "(\$input['hosts'] !== [] ? \"\\n\" . implode(\"\\n\", \$input['hosts']) : '')", "''", 'community-spam', false, 'Antispam-Inhalt enthält die normalisierten Hosts (Sperrwort evil.com trifft evil%2Ecom)', false, []],

    // ── Spam-Dublette (S4)
    'dup-local-time' => [$guard, "gmdate('Y-m-d H:i:s', \$nowTs - \$hours * 3600)", "date('Y-m-d H:i:s', \$nowTs - \$hours * 3600)", 'community-spam', false, 'Fenster der Spam-Dublette in UTC', true, []],
    'dup-min-length-ignored' => [$guard, '< self::DUPLICATE_MIN_CHARS', '< 0', 'community-spam', false, 'kürzere Fingerabdrücke als DUPLICATE_MIN_CHARS werden nicht verglichen („Danke!“)', false, []],
    'dup-author-deleted-counts' => [$guard, "(string) \$row['status'] === 'deleted' && \$row['status_reason'] !== 'moderator'", 'false', 'community-spam', false, 'vom Autor gelöschte Beiträge zählen nicht als Dublette', false, []],
    'dup-self-on-edit' => [$guard, "\$row['id'] === \$postId", 'false', 'community-spam', false, 'Bearbeiten vergleicht nicht mit dem Beitrag selbst', false, []],
    'dup-whitespace-kept' => [$guard, "trim((string) preg_replace('/\\s+/u', ' ', self::fold(\$body)))", 'self::fold($body)', 'community-spam', false, 'Fingerabdruck fasst Leerraum zusammen', false, []],

    // ── Filter cms_community_spam_check (S6)
    'spam-filter-loosens' => [$guard, 'if ($verdict === self::REVIEW && $result === self::ALLOW) {', 'if (false) {', 'community-spam', false, 'Filter darf nur verschärfen: allow nach review wird verworfen', false, []],
    'spam-filter-throw-open' => [$guard, '$verdict = self::REVIEW; // Ausnahme im Filter', '// Ausnahme im Filter', 'community-spam', false, 'Ausnahme im Filter ergibt review (fail-closed)', false, []],

    // ── ip_hash
    'iphash-days-ignored' => [$guard, "\$this->settings->int('ip_hash_days') > 0", 'true', 'community-spam', false, 'ip_hash_days = 0 speichert keinen ip_hash', false, []],
    'iphash-on-edit' => [$guard, "\$action !== 'edit' && ", '', 'community-spam', false, 'ip_hash nur beim Anlegen und Antworten, nie beim Bearbeiten', false, []],
    'iphash-mapped-ignored' => [$ipHash, 'substr($packed, 0, 12) === self::MAPPED_PREFIX', 'false', 'community-spam', false, 'IPv4 in IPv6 (::ffff:0:0/96) zählt als IPv4', true, []],
    'iphash-v4-not-24' => [$ipHash, 'substr($packed, 0, 3) . "\\0"', '$packed', 'community-spam', false, 'ip_hash über das IPv4-Netz /24', false, []],
    'iphash-v6-not-48' => [$ipHash, 'substr($packed, 0, 6)', 'substr($packed, 0, 16)', 'community-spam', false, 'ip_hash über das IPv6-Netz /48', false, []],
    'iphash-rate-not-64' => [$ipHash, 'substr($packed, 0, 8) . str_repeat("\\0", 8)', '$packed', 'community-spam', false, 'Rate-Kennung über das IPv6-Netz /64', false, []],
    'iphash-no-hkdf' => [$ipHash, "hash_hkdf('sha256', \$secret, 32, self::INFO)", '$secret', 'community-spam', false, 'ip_hash mit HKDF-Schlüssel (Domänentrennung), nicht mit dem rohen Geheimnis', false, []],
    'iphash-rate-same-key' => [$ipHash, "hash_hkdf('sha256', \$secret, 32, self::RATE_INFO)", "hash_hkdf('sha256', \$secret, 32, self::INFO)", 'community-spam', false, 'Rate-Kennung mit eigenem HKDF-Schlüssel', false, []],
    'iphash-placeholder-accepted' => [$ipHash, "str_contains(\$value, 'REPLACE_VIA_INSTALLER')", 'false', 'community-spam', false, 'Platzhalter des Installers gilt nicht als Geheimnis', false, []],
    'iphash-zero-hashed' => [$ipHash, '$packed === "\\0\\0\\0\\0" || $packed === str_repeat("\\0", 16)', 'false', 'community-spam', false, 'Nulladressen (0.0.0.0, ::; CLI-Wert) ergeben keinen ip_hash', false, []],

    // ── Request-Grenzen (S7, Vertrag §3.13)
    'throttle-count-first' => [$guard, 'return Security::countDbIdentifierAttempts($subject, $name, $window) <= $max;', 'return true;', 'community-db', true, 'Request-Grenze zählt nach dem Eintrag nach: gleichzeitig nie mehr als die Grenze', false, ['CMS_TEST_DB_SECTIONS' => 'race-throttle']],
    'throttle-precheck-records' => [$guard, 'if (Security::countDbIdentifierAttempts($subject, $name, $window) >= $max) {', 'if (false) {', 'community-spam', false, 'volle Grenze: ablehnen ohne Eintrag (Dauerbeschuss lässt login_attempts nicht wachsen)', false, []],
    'throttle-guest-shared' => [$guard, "'community:g:' . \$key", "'community:g:x'", 'community-spam', false, 'Gäste je rateKey ein eigener Eimer', false, []],
    'throttle-member-as-guest' => [$guard, '$max = $rule[1];', '$max = $rule[0];', 'community-spam', false, 'Mitglieder zählen mit der Mitgliedergrenze', false, []],
    'throttle-retry-zero' => [$guard, "'retry_after' => \$allowed ? 0 : \$window", "'retry_after' => 0", 'community-spam', false, 'Ablehnung liefert retry_after = Fenster', false, []],
    'search-guest-global' => [$guard, "\$this->limit('search', 'community:g:all', self::SEARCH_GUESTS_TOTAL, 60)", 'true', 'community-spam', false, 'Gäste der Suche zählen zusätzlich gemeinsam (120 je 60 s)', false, []],

    // ── Sicherheitsprotokoll
    'reject-log-unthrottled' => [$guard, "if (!\$this->throttle('reject_log', \$userId)['allowed']) {", 'if (false) {', 'community-spam', false, 'Sicherheitsprotokoll gedrosselt (30 je Stunde und Konto)', false, []],
    'reject-log-cli' => [$guard, "if (!\$client['web']) {", 'if (false) {', 'community-spam', false, 'Sicherheitsprotokoll nur mit Web-Anfrage', false, []],
    'reject-log-query' => [$guard, "parse_url((string) (\$_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH)", "(string) (\$_SERVER['REQUEST_URI'] ?? '/')", 'community-spam', false, 'security_log speichert den Pfad ohne Query (keine Token)', false, []],
];
