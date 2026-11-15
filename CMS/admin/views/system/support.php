<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_SYSTEM_VIEW')) {
    exit;
}

/**
 * @var array $data Support-Daten
 */

$docs    = $data['docs'];
$faq     = $data['faq'];
$version = $data['version'];

$tablerIcons = [
    'rocket'       => '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 13a8 8 0 0 1 7 7a6 6 0 0 0 3 -5a9 9 0 0 0 6 -8a3 3 0 0 0 -3 -3a9 9 0 0 0 -8 6a6 6 0 0 0 -5 3"/><path d="M7 14a6 6 0 0 0 -3 6a6 6 0 0 0 6 -3"/><path d="M15 9m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/></svg>',
    'palette'      => '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 21a9 9 0 0 1 0 -18c4.97 0 9 3.582 9 8c0 1.06 -.474 2.078 -1.318 2.828c-.844 .75 -1.989 1.172 -3.182 1.172h-2.5a2 2 0 0 0 -1 3.75a1.3 1.3 0 0 1 -1 2.25"/><path d="M8.5 10.5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M12.5 7.5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M16.5 10.5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/></svg>',
    'puzzle'       => '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7h3a1 1 0 0 0 1 -1v-1a2 2 0 0 1 4 0v1a1 1 0 0 0 1 1h3a1 1 0 0 1 1 1v3a1 1 0 0 0 1 1h1a2 2 0 0 1 0 4h-1a1 1 0 0 0 -1 1v3a1 1 0 0 1 -1 1h-3a1 1 0 0 1 -1 -1v-1a2 2 0 0 0 -4 0v1a1 1 0 0 1 -1 1h-3a1 1 0 0 1 -1 -1v-3a1 1 0 0 1 1 -1h1a2 2 0 0 0 0 -4h-1a1 1 0 0 1 -1 -1v-3a1 1 0 0 1 1 -1"/></svg>',
    'shield-check' => '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M11.46 20.846a12 12 0 0 1 -7.96 -14.846a12 12 0 0 0 8.5 -3a12 12 0 0 0 8.5 3a12 12 0 0 1 -.09 7.06"/><path d="M15 19l2 2l4 -4"/></svg>',
];
?>

<div class="container-xl">
    <div class="page-header d-print-none mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h2 class="page-title">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-lifebuoy me-2" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M15 15l3.35 3.35"/><path d="M9 15l-3.35 3.35"/><path d="M5.65 5.65l3.35 3.35"/><path d="M18.35 5.65l-3.35 3.35"/></svg>
                    Support & Dokumentation
                </h2>
                <div class="page-subtitle">365CMS Version <?php echo htmlspecialchars($version); ?></div>
            </div>
        </div>
    </div>

    <!-- Dokumentation -->
    <div class="row mb-4">
        <?php foreach ($docs as $section): ?>
            <div class="col-sm-6 col-lg-3 mb-4">
                <div class="card card-sm h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <span class="avatar bg-primary-lt me-3">
                                <?php echo $tablerIcons[$section['icon']] ?? ''; ?>
                            </span>
                            <h3 class="card-title mb-0"><?php echo htmlspecialchars($section['title']); ?></h3>
                        </div>
                        <p class="text-secondary mb-3"><?php echo htmlspecialchars($section['description']); ?></p>
                        <div class="list-group list-group-flush">
                            <?php foreach ($section['links'] as $link): ?>
                                <a href="<?php echo htmlspecialchars($link['url']); ?>" class="list-group-item list-group-item-action border-0 px-0 py-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-chevron-right me-1" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 6l6 6l-6 6"/></svg>
                                    <?php echo htmlspecialchars($link['label']); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- FAQ -->
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title">Häufig gestellte Fragen</h3>
        </div>
        <div class="card-body">
            <div class="accordion" id="faq-accordion">
                <?php foreach ($faq as $i => $item): ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-<?php echo $i; ?>">
                                <?php echo htmlspecialchars($item['question']); ?>
                            </button>
                        </h2>
                        <div id="faq-<?php echo $i; ?>" class="accordion-collapse collapse" data-bs-parent="#faq-accordion">
                            <div class="accordion-body text-secondary">
                                <?php echo htmlspecialchars($item['answer']); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Kontakt & Links -->
    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Nützliche Links</h3></div>
                <div class="list-group list-group-flush">
                    <a href="https://github.com/PS-PhinIT/365CMS.DE" target="_blank" rel="noopener noreferrer" class="list-group-item list-group-item-action">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon me-2" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 19c-4.3 1.4 -4.3 -2.5 -6 -3m12 5v-3.5c0 -1 .1 -1.4 -.5 -2c2.8 -.3 5.5 -1.4 5.5 -6a4.6 4.6 0 0 0 -1.3 -3.2a4.2 4.2 0 0 0 -.1 -3.2s-1.1 -.3 -3.5 1.3a12.3 12.3 0 0 0 -6.2 0c-2.4 -1.6 -3.5 -1.3 -3.5 -1.3a4.2 4.2 0 0 0 -.1 3.2a4.6 4.6 0 0 0 -1.3 3.2c0 4.6 2.7 5.7 5.5 6c-.6 .6 -.6 1.2 -.5 2v3.5"/></svg>
                        GitHub Repository
                    </a>
                    <a href="https://365network.de" target="_blank" rel="noopener noreferrer" class="list-group-item list-group-item-action">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon me-2" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0"/><path d="M3.6 9h16.8"/><path d="M3.6 15h16.8"/><path d="M11.5 3a17 17 0 0 0 0 18"/><path d="M12.5 3a17 17 0 0 1 0 18"/></svg>
                        365 Network Webseite
                    </a>
                </div>
            </div>
        </div>
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header"><h3 class="card-title">System-Informationen</h3></div>
                <div class="card-body">
                    <div class="datagrid">
                        <div class="datagrid-item">
                            <div class="datagrid-title">CMS-Version</div>
                            <div class="datagrid-content"><?php echo htmlspecialchars($version); ?></div>
                        </div>
                        <div class="datagrid-item">
                            <div class="datagrid-title">PHP-Version</div>
                            <div class="datagrid-content"><?php echo PHP_VERSION; ?></div>
                        </div>
                        <div class="datagrid-item">
                            <div class="datagrid-title">Server</div>
                            <div class="datagrid-content"><?php echo htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Unbekannt'); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
