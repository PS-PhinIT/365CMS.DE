/**
 * 365CMS Admin – Toolbar „Cache & Wartung“ in der oberen Leiste.
 *
 * - Cache leeren (alles, Seiten-/Objekt-Cache, optimierte Assets, OPcache) per POST `cache_clear`,
 *   Ergebnis als Toast.
 * - Wartungsmodus umschalten per POST `maintenance` nach Bestätigung im eigenen Dialog (`cmsConfirm`);
 *   Badge in der Leiste und Banner unter der Leiste folgen dem Zustand.
 */

function toast(type, message) {
    if (typeof window.cmsToast === 'function' && message) {
        window.cmsToast(type, message);
    }
}

function confirmDialog(options, onConfirm) {
    if (typeof window.cmsConfirm === 'function') {
        window.cmsConfirm(Object.assign({}, options, { onConfirm }));
    }
}

/**
 * @param {HTMLElement} root    Element mit [data-cms-toolbar]
 * @param {{post: Function}} client JSON-Client für /admin/admin-ui
 */
export function initToolbar(root, client) {
    if (!root) {
        return;
    }

    const labels = root.dataset;
    const badge = root.querySelector('[data-cms-maintenance-badge]');
    const banner = document.querySelector('[data-cms-maintenance-banner]');
    const toggle = root.querySelector('[data-cms-maintenance-toggle]');
    const toggleLabel = root.querySelector('[data-cms-maintenance-label]');
    let busy = false;

    function setBusy(state) {
        busy = state;
        root.classList.toggle('is-busy', state);
        root.setAttribute('aria-busy', state ? 'true' : 'false');
    }

    function applyMaintenance(active) {
        if (badge) {
            badge.classList.toggle('d-none', !active);
        }
        if (banner) {
            banner.classList.toggle('d-none', !active);
        }
        if (toggle) {
            toggle.dataset.active = active ? '1' : '0';
            toggle.classList.toggle('text-danger', active);
        }
        if (toggleLabel) {
            toggleLabel.textContent = active ? labels.labelMaintenanceSwitchOff : labels.labelMaintenanceSwitchOn;
        }
    }

    root.querySelectorAll('[data-cms-cache-clear]').forEach((button) => {
        button.addEventListener('click', async () => {
            if (busy) {
                return;
            }
            setBusy(true);
            toast('info', labels.labelBusy);
            const result = await client.post('cache_clear', { scope: button.dataset.cmsCacheClear || 'all' });
            setBusy(false);
            const details = Array.isArray(result.details) && result.details.length > 0 ? ' ' + result.details.join(' · ') : '';
            toast(result.success ? 'success' : 'danger', (result.success ? result.message : result.error) + details);
        });
    });

    if (toggle) {
        toggle.addEventListener('click', () => {
            if (busy) {
                return;
            }
            const enable = toggle.dataset.active !== '1';
            confirmDialog({
                title: enable ? labels.labelMaintenanceOnTitle : labels.labelMaintenanceOffTitle,
                message: enable ? labels.labelMaintenanceOn : labels.labelMaintenanceOff,
                confirmText: enable ? labels.labelMaintenanceOnButton : labels.labelMaintenanceOffButton,
                confirmClass: enable ? 'btn-danger' : 'btn-primary',
            }, async () => {
                setBusy(true);
                const result = await client.post('maintenance', { active: enable ? '1' : '0' });
                setBusy(false);
                if (result.success) {
                    applyMaintenance(result.active === true);
                }
                toast(result.success ? (result.active ? 'warning' : 'success') : 'danger', result.success ? result.message : result.error);
            });
        });
    }
}
