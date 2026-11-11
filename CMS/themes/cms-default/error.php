<?php
/**
 * Meridian CMS Default – Allgemeines Fehler-Template
 *
 * @package CMSv2\Themes\CmsDefault
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Der Router übergibt `error_code`, `error_title`, `error_message`; ältere Aufrufer nutzen die camelCase-Namen.
$errorCode    = $errorCode    ?? $error_code    ?? 500;
$errorTitle   = $errorTitle   ?? $error_title   ?? 'Serverfehler';
$errorMessage = $errorMessage ?? $error_message ?? 'Ein unerwarteter Fehler ist aufgetreten. Bitte versuche es später erneut.';

if (!headers_sent()) {
    http_response_code((int)$errorCode);
}
?>

<div class="container">
    <div class="error-page">
        <div class="error-page-inner">
            <div class="error-code"><?php echo (int)$errorCode; ?></div>
            <h1 class="error-title"><?php echo htmlspecialchars($errorTitle); ?></h1>
            <p class="error-desc"><?php echo htmlspecialchars($errorMessage); ?></p>
            <div class="error-actions">
                <a href="<?php echo SITE_URL; ?>/" class="btn-solid">Zur Startseite</a>
                <?php
                $errorBackUrl = (string) ($_SERVER['HTTP_REFERER'] ?? '');
                $errorSiteHost = (string) parse_url(SITE_URL, PHP_URL_HOST);
                if ($errorBackUrl === '' || $errorSiteHost === '' || strcasecmp((string) parse_url($errorBackUrl, PHP_URL_HOST), $errorSiteHost) !== 0
                    || !in_array(strtolower((string) parse_url($errorBackUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                    $errorBackUrl = '';
                }
                ?>
                <?php if ($errorBackUrl !== ''): ?>
                <a href="<?php echo htmlspecialchars($errorBackUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn-ghost">Zurück</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div><!-- /.container -->
