<?php
/**
 * Release-Prüfung 4.1.0, Performance-Cluster „frontend“: zustandsloses Kommentar-Token (keine Session auf
 * Beitragsseiten), nicht blockierende CSP-Runtime, PhotoSwipe bei Bedarf, keine WebP-Erzeugung beim Rendern,
 * Bildprioritäten, width/height für Editor.js-Bilder, Breiten-srcset für Karten und Nginx-Doku.
 *
 * Wird von TESTS/release-4.1.0/run.php eingebunden ($src, ABSPATH und Autoloader sind dort definiert).
 */

declare(strict_types=1);

T::run('PERF cache:2: Kommentarformular startet für anonyme Besucher keine Session', static function () use ($src): void {
    $guard = \CMS\Services\Forms\FormSpamGuard::class;
    $now = time();
    $token = $guard::timestampToken('comment-post|42', $now - 10);
    T::same($now - 10, $guard::verifyTimestamp($token, 'comment-post|42', $now), 'gültiges Token für denselben Beitrag');
    T::same(null, $guard::verifyTimestamp($token, 'comment-post|43', $now), 'an den Beitrag gebunden (anderer Beitrag abgelehnt)');
    T::same(null, $guard::verifyTimestamp($token, 'comment-post|42', $now + 2 * 86400), 'abgelaufenes Token abgelehnt');
    T::same(null, $guard::verifyTimestamp(substr($token, 0, -1) . (str_ends_with($token, '0') ? '1' : '0'), 'comment-post|42', $now), 'manipulierte Signatur abgelehnt');
    T::same(null, $guard::verifyTimestamp($guard::timestampToken('42', $now), 'comment-post|42', $now), 'Token eines anderen Formularschlüssels abgelehnt');

    // Anonymer Pfad prüft das zustandslose Token, ohne die Session anzufassen.
    $router = (new \ReflectionClass(\CMS\Routing\PublicRouter::class))->newInstanceWithoutConstructor();
    $verify = new \ReflectionMethod(\CMS\Routing\PublicRouter::class, 'verifyCommentToken');
    T::check($verify->invoke($router, $guard::timestampToken('comment-post|42'), 42, false) === true, 'Handler akzeptiert das signierte Token anonymer Besucher');
    T::check($verify->invoke($router, '', 42, false) === false, 'leeres Token wird abgelehnt');
    T::check($verify->invoke($router, $guard::timestampToken('comment-post|42'), 0, false) === false, 'ungültige Beitrags-ID wird abgelehnt');

    $handler = $src('core/Routing/PublicRouter.php');
    T::check(preg_match("/if \\(\\\$isLoggedIn\\) \\{\\s*return Security::instance\\(\\)->verifyToken\\(\\\$token, 'comment_' \\. \\\$postId\\);\\s*\\}/", $handler) === 1, 'angemeldete Nutzer brauchen weiterhin das Sitzungs-Token');
    T::check(str_contains($handler, '$this->verifyCommentToken($this->requestString($_POST[\'csrf_token\'] ?? \'\', 128), $postId, isset($currentUser->id) && (int) $currentUser->id > 0)'), 'handleCommentPost nutzt die kombinierte Prüfung');

    $single = $src('themes/cms-default/blog-single.php');
    T::check(str_contains($single, "if (!\$isLoggedInCommentAuthor && class_exists('\\\\CMS\\\\Services\\\\Forms\\\\FormSpamGuard')) {")
        && str_contains($single, "FormSpamGuard::timestampToken('comment-post|' . (int) (\$post->id ?? 0))"), 'Template: anonymes Formular mit signiertem Token');
    T::check(str_contains($single, 'name="comment_hp"') && str_contains($single, 'name="comment_started_at"'), 'Honeypot und Startzeit bleiben im Formular');
    $comments = $src('core/Services/CommentService.php');
    T::check(str_contains($comments, "'honeypot_value' => (string) (\$_POST['comment_hp'] ?? '')") && str_contains($comments, "'started_at' => (int) (\$_POST['comment_started_at'] ?? 0)"), 'Honeypot- und Zeitprüfung im CommentService unverändert');
});

T::run('PERF cwv:0: CSP-Runtime im Theme nicht render-blockierend', static function () use ($src): void {
    $header = $src('themes/cms-default/header.php');
    T::check(str_contains($header, 'cms_csp_runtime_tags(true)'), 'header.php nutzt die defer-Variante');
    T::check(!preg_match('/cms_csp_runtime_tags\(\)/', $header), 'keine blockierende Variante mehr im Theme-Header');
    $runtime = $src('includes/functions/options-runtime.php');
    T::check(str_contains($runtime, "' defer></script>'") && str_contains($runtime, '<script id="cms-csp-runtime"'), 'Runtime-Funktion bietet DOMPurify per defer und Inline-Runtime');
    $themeJs = $src('themes/cms-default/js/theme.js');
    T::check(substr_count($themeJs, 'innerHTML') <= 3, 'theme.js weist HTML nur in den bekannten Klick-Handlern zu');
});

T::run('PERF cwv:2: PhotoSwipe lädt nur bei passenden Bildern und den Kern erst beim Klick', static function () use ($src): void {
    $js = $src('assets/js/photoswipe-init.js');
    $query = strpos($js, "document.querySelectorAll('.post-content, .page-content, [data-photoswipe]')");
    $firstImport = strpos($js, 'await import(');
    T::check($query !== false && $firstImport !== false && $query < $firstImport, 'Container werden vor dem ersten Import gesucht');
    T::check(str_contains($js, 'if (galleries.length === 0) return;'), 'ohne Bilder kein Modul-Import');
    T::check(!str_contains($js, 'Promise.all(['), 'Kern wird nicht mehr zusammen mit der Lightbox geladen');
    T::check(str_contains($js, "const loadPhotoSwipeCore = () => import(photoswipeBase + 'photoswipe.esm.min.js');") && str_contains($js, 'pswpModule: loadPhotoSwipeCore,'), 'Kern als Lazy-Import (pswpModule-Funktion)');
    T::check(str_contains($js, "!img.closest('a') || img.closest('a[data-pswp-src]') !== null"), 'verlinkte Bilder bleiben Links');
    T::check(str_contains($src('themes/cms-default/blog-single.php'), '<div class="post-body post-body--detail" data-photoswipe>'), 'Beitragsinhalt ist als Lightbox-Container markiert');
    $lightbox = $src('assets/photoswipe/photoswipe-lightbox.esm.min.js');
    T::check(str_contains($lightbox, 'pswpModule())'), 'gebündelte Lightbox unterstützt pswpModule als Funktion');
});

T::run('PERF cwv:4: keine WebP-Erzeugung beim Rendern', static function () use ($src): void {
    $functions = $src('themes/cms-default/functions.php');
    $start = strpos($functions, 'function meridian_get_picture_sources(');
    $end = strpos($functions, "if (!function_exists('meridian_get_responsive_picture_sources'))", (int) $start);
    T::check($start !== false && $end !== false, 'Funktion gefunden');
    $body = substr($functions, (int) $start, (int) $end - (int) $start);
    T::check(!str_contains($body, 'convertToWebP') && !str_contains($body, 'ImageService'), 'kein GD-Encode im Template-Helfer');
    T::check(str_contains($body, 'if ((int) filemtime($webpPath) < (int) filemtime($sourcePath)) {'), 'veraltete WebP-Fassung wird nicht ausgeliefert');
});

T::run('PERF cwv:6: fetchpriority=high nur für das LCP-Bild', static function () use ($src): void {
    $header = $src('themes/cms-default/header.php');
    T::check(str_contains($header, 'meridian_image_loading_attributes(true, false)'), 'Header-Logo ohne fetchpriority=high');
    T::check(str_contains($header, '<?php echo $mobileLogoLoadingAttributes; ?> style="max-height:32px;width:auto;">'), 'Logo im Mobil-Panel ohne Vorrang');

    $html = \CMS\Services\EditorJsRenderer::getInstance()->render(['blocks' => [
        ['type' => 'linkTool', 'data' => ['link' => 'https://example.org', 'meta' => ['title' => 'Beispiel', 'image' => ['url' => 'https://example.org/vorschau.jpg']]]],
        ['type' => 'image', 'data' => ['file' => ['url' => 'https://example.org/bild.jpg'], 'caption' => 'Bild']],
    ]]);
    T::check(preg_match('#<img src="https://example.org/vorschau.jpg" alt="" loading="lazy" decoding="async">#', $html) === 1, 'Link-Vorschau lädt lazy und ohne fetchpriority', $html);
    T::check(preg_match('#<img src="https://example.org/bild.jpg"[^>]*loading="eager" fetchpriority="high"#', $html) === 1, 'erstes echtes Inhaltsbild behält den Eager-Platz', $html);
});

T::run('PERF cwv:7: Editor.js-Bilder mit width/height', static function () use ($src): void {
    $renderer = \CMS\Services\EditorJsRenderer::class;
    T::same([800, 600], $renderer::resolveImageDimensions('https://example.org/a.jpg', ['width' => 800, 'height' => '600']), 'Maße aus den Blockdaten');
    T::same(null, $renderer::resolveImageDimensions('https://example.org/a.jpg'), 'externe URL ohne Maße');
    T::same(null, $renderer::resolveImageDimensions('data:image/png;base64,AAAA'), 'data:-URL ohne Maße');
    T::same(null, $renderer::resolveImageDimensions('/uploads/x.jpg', ['width' => 0, 'height' => 10]), 'ungültige Blockmaße werden ignoriert (Datei fehlt)');

    $dir = sys_get_temp_dir() . '/cms-perf-frontend-' . bin2hex(random_bytes(4));
    @mkdir($dir . '/2026', 0777, true);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAMAAAACCAIAAAASFvFNAAAAEklEQVR4nGP8z8DAwMDAxMDAAAAtAAP9lYpVAAAAAElFTkSuQmCC', true);
    file_put_contents($dir . '/2026/probe.png', (string) $png);

    $property = new \ReflectionProperty(\CMS\Services\Media\AvifDerivativeService::class, 'instance');
    $previous = $property->getValue();
    $property->setValue(null, new \CMS\Services\Media\AvifDerivativeService(['uploadPath' => $dir, 'uploadUrl' => '/uploads']));
    try {
        T::same([3, 2], $renderer::resolveImageDimensions('/uploads/2026/probe.png'), 'Maße einer lokalen Upload-Datei per getimagesize');
        $html = $renderer::getInstance()->render(['blocks' => [['type' => 'image', 'data' => ['file' => ['url' => '/uploads/2026/probe.png'], 'caption' => 'Probe']]]]);
        T::check(str_contains($html, 'width="3" height="2"'), 'Bildblock gibt width/height aus', $html);
        @unlink($dir . '/2026/probe.png');
        T::same([3, 2], $renderer::resolveImageDimensions('/uploads/2026/probe.png'), 'Request-Memo spart erneutes getimagesize');
    } finally {
        $property->setValue(null, $previous);
        @unlink($dir . '/2026/probe.png');
        @rmdir($dir . '/2026');
        @rmdir($dir);
    }

    $source = $src('core/Services/EditorJsRenderer.php');
    T::check(substr_count($source, '$this->getImageDimensionAttributes(') === 4, 'Bild, Link-Vorschau, Karussell und Zeichnung mit Maßen');
    $landing = $src('themes/cms-default/partials/home-landing.php');
    T::check(str_contains($landing, 'meridian_image_dimension_attributes($lpLogoUrl)'), 'Landing-Logo über der H1 mit Maßen');
});

T::run('PERF cwv:8: Karten nutzen vorhandene Bildgrößen per srcset', static function () use ($src): void {
    $functions = $src('themes/cms-default/functions.php');
    $start = strpos($functions, 'function meridian_get_responsive_picture_sources(');
    $end = strpos($functions, "if (!function_exists('meridian_image_dimension_attributes'))", (int) $start);
    $body = substr($functions, (int) $start, (int) $end - (int) $start);
    T::check($start !== false && str_contains($body, "foreach (['small', 'medium', 'large', 'banner'] as \$suffix)"), 'erzeugte Größen werden als Kandidaten geprüft');
    T::check(str_contains($body, '(int) (filemtime($variantPath) ?: 0) < $sourceTime'), 'nur Größen, die nicht älter als das Original sind');
    T::check(!preg_match('/imagecopyresampled|imagecreate|convertTo|generateThumbnails/', $body), 'nie Skalierung zur Laufzeit');
    T::check(str_contains($functions, "if (\$single === '' || (string) (\$image['srcset'] ?? '') !== '') {"), 'volle AVIF/WebP-Fassung verdrängt keine kleinere Größe');

    foreach ([
        'themes/cms-default/partials/blog-list-cards.php' => 1,
        'themes/cms-default/partials/blog-grid-cards.php' => 1,
        'themes/cms-default/home.php' => 2,
        'themes/cms-default/blog-single.php' => 1,
        'themes/cms-default/partials/home-landing.php' => 1,
    ] as $file => $count) {
        $template = $src($file);
        T::check(substr_count($template, 'meridian_get_responsive_picture_sources(') === $count, "{$file}: responsive Bildquellen");
        T::check(substr_count($template, 'meridian_picture_img_srcset_attributes(') === $count, "{$file}: srcset/sizes am <img>");
        if ($file !== 'themes/cms-default/partials/home-landing.php') {
            T::check(substr_count($template, "meridian_picture_source_attributes(\$") === 4 * $count, "{$file}: AVIF/WebP-Quellen mit Breiten-srcset");
        }
    }
});

T::run('PERF cwv:9: Nginx-Anleitung mit Komprimierung und Cache-Regeln', static function (): void {
    $doc = (string) file_get_contents(dirname(ABSPATH) . '/DOC/INSTALLATION.md');
    T::check(substr_count($doc, 'gzip_types text/css application/javascript') === 2, 'gzip_types in englischer und deutscher Fassung');
    T::check(substr_count($doc, 'add_header Cache-Control "public, max-age=31536000, immutable" always;') === 2, 'versionierte Assets ein Jahr cachen');
    T::check(substr_count($doc, 'add_header Cache-Control "public, max-age=86400, stale-while-revalidate=604800" always;') === 2, 'Uploads kurz und nicht immutable');
});
