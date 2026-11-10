/**
 * 365CMS Admin-Shell – Einstiegspunkt (ES-Modul, geladen in partials/footer.php).
 *
 * Module werden über die Importmap in partials/header.php als `cms-admin/<datei>.js`
 * aufgelöst (versionierte URLs). Jede Komponente wird isoliert initialisiert, damit
 * ein Fehler in einer Komponente die übrigen nicht blockiert.
 */

import { createJsonClient } from 'cms-admin/api.js';
import { initDrawer } from 'cms-admin/drawer.js';
import { initSidebar } from 'cms-admin/sidebar.js';
import { initSiteSwitcher } from 'cms-admin/site-switcher.js';
import { initUserMenu } from 'cms-admin/user-menu.js';
import { recordRecentLink } from 'cms-admin/recent-links.js';

function run(name, callback) {
    try {
        callback();
    } catch (error) {
        console.error('[365CMS Admin] ' + name + ' konnte nicht initialisiert werden.', error);
    }
}

const sidebar = document.querySelector('[data-cms-sidebar]');

if (sidebar) {
    const client = createJsonClient({
        endpoint: sidebar.dataset.cmsUiEndpoint || '',
        token: sidebar.dataset.cmsUiToken || '',
    });

    run('Drawer', () => initDrawer(sidebar));
    run('Seitenleiste', () => initSidebar(sidebar, client));
    run('Site-Wechsler', () => initSiteSwitcher(sidebar, client));
    run('Benutzermenü', () => initUserMenu(sidebar, client));
}

run('Zuletzt genutzt', recordRecentLink);
