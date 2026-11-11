<?php
/**
 * Meridian CMS Default – Kontaktseite
 *
 * Das Formular stellt der Core bereit (Inhalte → Formulare, FormService): Prüfung, Antispam, Einwilligung,
 * Speicherung und Mails laufen dort. Diese Vorlage ordnet nur Überschrift, Formular und Kontaktdaten an.
 *
 * Vom Router bereitgestellte Variablen:
 *   $contactForm     – array|null: Formular-Definition
 *   $contactFormHtml – string: fertig gerendertes Formular inkl. Fehler- bzw. Erfolgsmeldung
 *   $isContactPage   – bool: allgemeine Kontaktseite (/kontakt, /contact) statt /contact/<schlüssel>
 *   $title           – string: Seitentitel
 *
 * @package CMSv2\Themes\CmsDefault
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$contactForm = isset($contactForm) && is_array($contactForm) ? $contactForm : null;
$contactFormHtml = isset($contactFormHtml) ? (string) $contactFormHtml : '';
$isContactPage = !isset($isContactPage) || (bool) $isContactPage;
$isEnglish = (string) ($contentLocale ?? 'de') === 'en';

$contactHeading = $isContactPage
    ? ($isEnglish ? 'Get in touch' : 'Kontakt aufnehmen')
    : trim((string) ($title ?? ($contactForm['title'] ?? '')));
$contactIntro = $isContactPage
    ? ($isEnglish ? 'Questions, feedback or a cooperation request? Write to us!' : 'Hast du Fragen, Feedback oder einen Kooperationswunsch? Schreib uns gerne!')
    : '';
$contactCrumb = $isContactPage ? ($isEnglish ? 'Contact' : 'Kontakt') : $contactHeading;

// Kontaktdaten aus den Theme-Einstellungen; E-Mail-Fallback wie bisher (Empfängeradresse, sonst ADMIN_EMAIL).
$contactInfo = [];
try {
    foreach (meridian_options(['contact_address', 'contact_phone', 'contact_email_display', 'contact_hours']) as $key => $value) {
        if ($value) {
            $contactInfo[$key] = (string) $value;
        }
    }
} catch (\Throwable $e) {
}
$contactEmail = (string) ($contactInfo['contact_email_display'] ?? '');
if ($contactEmail === '') {
    $contactEmail = (string) (filter_var(trim((string) meridian_option('contact_email', '')), FILTER_VALIDATE_EMAIL) ?: '');
}
if ($contactEmail === '' && defined('ADMIN_EMAIL')) {
    $contactEmail = (string) (filter_var(trim((string) ADMIN_EMAIL), FILTER_VALIDATE_EMAIL) ?: '');
}
$esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<style <?php echo \CMS\Security::instance()->nonceAttr(); ?>>
.contact-page{max-width:var(--max);margin:0 auto;padding:2rem 1.5rem}
.contact-page .breadcrumb{font-size:.8rem;color:var(--ink-muted);margin-bottom:2rem}
.contact-page .breadcrumb a{color:var(--ink-muted);text-decoration:none}
.contact-page .breadcrumb .sep{margin:0 .4rem}
.contact-page .breadcrumb [aria-current]{color:var(--ink)}
.contact-grid{display:grid;grid-template-columns:1fr min(420px,40%);gap:3rem;align-items:start}
.contact-page__title{font-family:var(--font-serif);font-size:clamp(1.6rem,4vw,2.2rem);margin:0 0 .5rem}
.contact-page__intro{color:var(--ink-muted);margin:0 0 2rem}
.contact-aside{background:var(--surface-tint);border-radius:var(--r);border:1px solid var(--rule);padding:1.75rem}
.contact-aside h2{font-family:var(--font-serif);font-size:1.1rem;margin:0 0 1.25rem;color:var(--ink)}
.contact-aside dl{margin:0;font-size:.88rem;line-height:1.7}
.contact-aside dt{font-weight:600;color:var(--ink-soft);margin-top:.75rem}
.contact-aside dd{margin:0;color:var(--ink-muted)}
.contact-aside dd a{color:var(--accent)}
.contact-aside__empty{color:var(--ink-ghost);font-style:italic}
.contact-aside__legal{border-top:1px solid var(--rule);margin:1.5rem 0 0;padding-top:1rem;font-size:.78rem;color:var(--ink-ghost)}
.contact-aside__legal a{color:var(--ink-muted);text-decoration:none}
@media (max-width:860px){.contact-grid{grid-template-columns:1fr;gap:2rem}}
</style>

<div class="container contact-page">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="<?php echo SITE_URL; ?>/"><?php echo $isEnglish ? 'Home' : 'Startseite'; ?></a>
        <span class="sep" aria-hidden="true">›</span>
        <span aria-current="page"><?php echo $esc($contactCrumb); ?></span>
    </nav>

    <div class="contact-grid">
        <div class="contact-page__main">
            <h1 class="contact-page__title"><?php echo $esc($contactHeading); ?></h1>
            <?php if ($contactIntro !== ''): ?>
            <p class="contact-page__intro"><?php echo $esc($contactIntro); ?></p>
            <?php endif; ?>

            <?php if ($contactFormHtml !== ''): ?>
                <?php echo $contactFormHtml; // FormRenderer: vollständig escaped ?>
            <?php else: ?>
            <p class="contact-aside__empty"><?php echo $isEnglish ? 'The contact form is currently unavailable.' : 'Das Kontaktformular ist derzeit nicht verfügbar.'; ?></p>
            <?php endif; ?>
        </div>

        <aside class="contact-aside">
            <h2><?php echo $isEnglish ? 'How to reach us' : 'So erreichst du uns'; ?></h2>
            <dl>
                <?php if ($contactEmail !== ''): ?>
                <dt><?php echo $isEnglish ? 'Email' : 'E-Mail'; ?></dt>
                <dd><a href="mailto:<?php echo $esc($contactEmail); ?>"><?php echo $esc($contactEmail); ?></a></dd>
                <?php endif; ?>

                <?php if (!empty($contactInfo['contact_phone'])): ?>
                <dt><?php echo $isEnglish ? 'Phone' : 'Telefon'; ?></dt>
                <dd><?php echo $esc($contactInfo['contact_phone']); ?></dd>
                <?php endif; ?>

                <?php if (!empty($contactInfo['contact_address'])): ?>
                <dt><?php echo $isEnglish ? 'Address' : 'Adresse'; ?></dt>
                <dd><?php echo nl2br($esc($contactInfo['contact_address'])); ?></dd>
                <?php endif; ?>

                <?php if (!empty($contactInfo['contact_hours'])): ?>
                <dt><?php echo $isEnglish ? 'Availability' : 'Erreichbarkeit'; ?></dt>
                <dd><?php echo nl2br($esc($contactInfo['contact_hours'])); ?></dd>
                <?php endif; ?>

                <?php if ($contactInfo === [] && $contactEmail === ''): ?>
                <dd class="contact-aside__empty"><?php echo $isEnglish ? 'Contact details are maintained in the CMS settings.' : 'Kontaktdaten werden in den CMS-Einstellungen hinterlegt.'; ?></dd>
                <?php endif; ?>
            </dl>

            <p class="contact-aside__legal">
                <a href="<?php echo SITE_URL; ?>/impressum"><?php echo $isEnglish ? 'Imprint' : 'Impressum'; ?></a>
                &nbsp;·&nbsp;
                <a href="<?php echo SITE_URL; ?>/datenschutz"><?php echo $isEnglish ? 'Privacy' : 'Datenschutz'; ?></a>
            </p>
        </aside>
    </div>
</div>
