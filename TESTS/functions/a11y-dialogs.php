<?php
/**
 * Audit 4.2.0 (AP-14, Punkt 14.2): Dialoge im Admin haben einen zugänglichen Namen. Bootstrap setzt beim Öffnen role und
 * aria-modal, aber keinen Namen; 27 von 32 Dialogen hatten weder aria-label noch aria-labelledby. Quelltextvertrag
 * gegen `assets/js/admin.js` und gegen die Vorlagen; ohne Datenbank und ohne Browser.
 * Wird von TESTS/functions/run.php eingebunden.
 */

declare(strict_types=1);

T::run('AP-14 / 14.2: admin.js benennt Dialoge beim Öffnen nach ihrem Titel', static function (): void {
    $js = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/CMS/assets/js/admin.js'));

    $start = strpos($js, 'function initModalAccessibleNames()');
    $end = $start === false ? false : strpos($js, "\n}\n", $start);
    $body = $start !== false && $end !== false ? substr($js, $start, $end - $start) : '';

    T::check($body !== '', 'initModalAccessibleNames() ist vorhanden');
    T::check(str_contains($body, "'show.bs.modal'"), 'hört auf show.bs.modal (Bootstrap-Ereignis beim Öffnen)');
    T::check(str_contains($body, "hasAttribute('aria-labelledby')") && str_contains($body, "hasAttribute('aria-label')"), 'überschreibt keinen vorhandenen Namen');
    T::check(str_contains($body, "querySelector('.modal-title')") && str_contains($body, "setAttribute('aria-labelledby'"), 'nimmt .modal-title als Namen');
    T::check(preg_match('/DOMContentLoaded[^\n]*\n(?:.*\n)*?\s+initModalAccessibleNames\(\);/', $js) === 1, 'wird beim Laden der Seite aufgerufen');
});

T::run('AP-14 / 14.2: Dialoge ohne eigenen Namen haben einen .modal-title, den admin.js verwenden kann', static function (): void {
    $root = dirname(__DIR__, 2) . '/CMS/admin/views';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $withoutTitleOrName = [];
    $total = 0;
    foreach ($iterator as $file) {
        if (!str_ends_with($file->getPathname(), '.php')) {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        if (preg_match_all('/<div[^>]*class="[^"]*\bmodal\b(?![-\w])[^"]*"[^>]*>/', $source, $matches, PREG_OFFSET_CAPTURE) < 1) {
            continue;
        }
        foreach ($matches[0] as [$tag, $offset]) {
            $total++;
            $named = str_contains($tag, 'aria-labelledby') || str_contains($tag, 'aria-label=');
            // Bis zum nächsten Dialog: gibt es einen Titel?
            $rest = substr($source, $offset, 4000);
            if (!$named && !str_contains($rest, 'modal-title')) {
                $withoutTitleOrName[] = substr($file->getPathname(), strlen($root) + 1) . ' @' . $offset;
            }
        }
    }
    T::check($total > 0, $total . ' Dialoge in admin/views gefunden');
    T::same([], $withoutTitleOrName, 'jeder Dialog hat einen Namen oder einen .modal-title');
});
