<?php
/**
 * Rendert die echte Vorlage `views/community/compose.php` für die Browser-Prüfung in Dateien (new.html, reply.html, edit.html).
 *
 *   php TESTS/community-editor/render.php <Zielverzeichnis>
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
// Die Vorlage liest ihre Texte über `Support\I18n` (Sprachkatalog der Community); Klassen des Cores werden bei Bedarf geladen.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'CMS\\') && is_file($file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php')) {
        require_once $file;
    }
});
$target = rtrim((string) ($argv[1] ?? ''), '/');
if ($target === '' || !is_dir($target)) {
    fwrite(STDERR, "Zielverzeichnis fehlt\n");
    exit(2);
}

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$api = ['url' => '/forum/api/', 'token' => 'testtoken', 'reasons' => '{}', 'reason_required' => false];
$editorBase = ['css' => '/assets/suneditor/css/suneditor.min.css', 'js' => '/assets/suneditor/suneditor.min.js', 'lang' => '/assets/suneditor/lang/de.js'];
$base = [
    'heading' => 'Test', 'submit_label' => 'Senden', 'cancel_url' => '/forum', 'title' => '', 'body' => '', 'reason' => '', 'seen' => 0, 'edit_count' => 0,
    'error' => null, 'new_posts' => false, 'token' => 'formtoken', 'api' => $api, 'locale' => 'de',
];
$pages = [
    'new' => $base + ['mode' => 'new', 'show_title' => true, 'forum' => ['id' => 3], 'editor' => $editorBase + ['mode' => 'visual']],
    'reply' => array_merge($base, ['mode' => 'reply', 'show_title' => false, 'seen' => 9, 'quote' => 5, 'topic' => ['id' => 7], 'body' => "> [quote=post:5]\n> Zitierter Text\n\n", 'editor' => $editorBase + ['mode' => 'visual']]),
    'edit' => array_merge($base, ['mode' => 'edit', 'show_title' => false, 'edit_count' => 1, 'post' => ['id' => 11], 'topic' => ['id' => 7], 'body' => "Vorher\n\n| a | b |\n|---|---|\n| 1 | 2 |", 'editor' => $editorBase + ['mode' => 'markdown']]),
];
// Je Seite ein eigener Gültigkeitsbereich: sonst überschreibt `extract()` nichts, was die Vorlage der vorigen Seite gesetzt hat.
$render = static function (array $vars) use ($e): string {
    extract($vars, EXTR_SKIP);
    ob_start();
    include ABSPATH . 'views/community/compose.php';

    return (string) ob_get_clean();
};
foreach ($pages as $name => $vars) {
    file_put_contents($target . '/' . $name . '.html', $render($vars));
}
