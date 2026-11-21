<?php
/**
 * Katalog PR #4 (Frontend, Teil 1): Routenmuster, Seitensegmente und Seitenadresse `seite-N` (`TESTS/community-frontend`).
 *
 * Gleiches Format wie `catalog.php`: Name => [Datei relativ zu CMS/, Suchtext, Ersatz, Suite, needsDb, Regel, quick, env]
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

$urls = 'core/Services/Community/Support/Urls.php';
$post = 'core/Services/Community/Service/PostService.php';
$repo = 'core/Services/Community/Repository/PostRepository.php';
$suite = 'community-frontend';
$api = 'core/Services/Community/Http/ApiController.php';
$apiSuite = 'community-api';

return [
    'route-topic-pattern' => [$urls, "'topic' => \$path('/' . \$topic . '/:ref'),", "'topic' => \$path('/' . \$topic . '/:ref/x'),", $suite, false, 'Das Themenmuster trifft /forum/thema/<id>-<slug>', true, []],
    'route-topic-after-forum' => [$urls, "'topic_page' => \$path('/' . \$topic . '/:ref/:page'),", "'topic_page' => \$path('/:slug/:ref/:page'),", $suite, false, 'Feste Segmente stehen vor dem Forum-Slug (Themen mit Seite werden nicht zum Forum)', false, []],
    'page-segment-lax' => [$urls, "preg_match('/^[1-9]\\d{0,5}\$/D'", "preg_match('/^\\d{0,5}\$/D'", $suite, false, 'Seitensegment: nur positive Zahl ohne führende Null (seite-0, seite-01 ungültig)', true, []],
    'page-segment-locale' => [$urls, "\$prefix = self::segment('page', \$locale) . '-';", "\$prefix = 'seite-';", $suite, false, 'Seitensegment der Sprache (page-N unter /en)', false, []],
    'cursor-off-by-one' => [$post, "return \$this->posts->idAtPosition(\$topicId, (\$page - 1) * \$this->settings->int('posts_per_page'));", "return \$this->posts->idAtPosition(\$topicId, \$page * \$this->settings->int('posts_per_page'));", $suite, false, 'Seite N beginnt hinter dem letzten Beitrag der Seite N-1', true, []],
    'route-new-topic-after-forum' => [$urls, "'new_topic' => \$path('/:slug/' . self::segment('new_topic', \$locale)),", "'new_topic' => \$path('/:slug/' . self::segment('new_topic', \$locale) . '/x'),", $suite, false, 'Das Muster „Neues Thema“ trifft /forum/<slug>/neues-thema', true, []],
    'route-reply-pattern' => [$urls, "'reply' => \$path('/' . \$topic . '/:id/' . self::segment('reply', \$locale)),", "'reply' => \$path('/' . \$topic . '/:id/' . self::segment('post', \$locale)),", $suite, false, 'Das Antwortmuster trifft /forum/thema/<id>/antworten (Segment der Sprache)', false, []],
    'position-past-end' => [$repo, 'if (count($rows) < $position) {', 'if (false) {', $suite, false, 'Eine Seite hinter dem Ende hat keinen Cursor (404 statt letzter Seite)', false, []],

    // ── JSON-API (community-api)
    'api-op-allowlist' => [$api, 'if (!in_array($op, self::MODERATION_OPS, true) || $id < 1) {', 'if ($id < 1) {', $apiSuite, false, 'Moderationsaktionen der API nur aus der Allowlist (kein Löschen über die API)', true, []],
    'api-guest-allowed' => [$api, 'if ($userId < 1) {', 'if (false) {', $apiSuite, false, 'Ohne Anmeldung antwortet jede Aktion mit 401, bevor ein Dienst läuft', true, []],
    'api-token-any-path' => [$api, "|| \\CMS\\Services\\Community\\Support\\Urls::isApiPath(\$uri) === false) {", ') {', $apiSuite, false, 'Das dauerhafte Token gilt nur für Adressen der API', false, []],
    'api-status-flat' => [$api, 'Codes::ACCESS_REASONS[$code] ?? (', '422 ?? (', $apiSuite, false, 'Ablehnungsgründe behalten ihren HTTP-Status (401, 429, 404)', false, []],
    'api-int-lax' => [$api, "preg_match('/^[1-9]\\d{0,17}\$/D', \$value) === 1 ? (int) \$value", "is_numeric(\$value) ? (int) \$value", $apiSuite, false, 'IDs sind positive Ziffernfolgen (kein \"1; DROP\")', false, []],
];
