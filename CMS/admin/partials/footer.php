<?php
declare(strict_types=1);

/**
 * Admin Partial: Footer – Scripts + Closing Tags
 *
 * Erwartet optional:
 *   $pageAssets['js']  – array  Zusätzliche Script-Pfade
 *   $inlineJs          – string Inline-JavaScript (ohne <script>-Tags)
 *
 * @package CMSv2\Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

$siteUrl    = defined('SITE_URL') ? SITE_URL : '';
$pageAssets = $pageAssets ?? [];
$inlineJs   = $inlineJs ?? '';
?>
        </div><!-- /.page-wrapper -->
    </div><!-- /.page -->

    <!-- Tabler Core JS -->
    <script src="<?= htmlspecialchars(cms_asset_url('tabler/js/tabler.min.js'), ENT_QUOTES) ?>" defer></script>
    <script src="<?= htmlspecialchars(cms_asset_url('js/tabler-bootstrap-bridge.js'), ENT_QUOTES) ?>" defer></script>

    <!-- Admin JS -->
    <script src="<?= htmlspecialchars(cms_asset_url('js/admin.js'), ENT_QUOTES) ?>" defer></script>
    <!-- CSP-konforme Ersatzlogik für Inline-Event-Handler (data-cms-*) -->
    <script src="<?= htmlspecialchars(cms_asset_url('js/cms-inline-actions.js'), ENT_QUOTES) ?>" defer></script>

    <?php
    // Zusätzliche Scripts aus $pageAssets['js']
    if (!empty($pageAssets['js'])):
        foreach ($pageAssets['js'] as $js): ?>
            <script src="<?= htmlspecialchars($js) ?>" defer></script>
        <?php endforeach;
    endif;
    ?>

    <?php if ($inlineJs !== ''): ?>
    <script <?= \CMS\Security::instance()->nonceAttr() ?>>
    document.addEventListener('DOMContentLoaded', function() {
        <?= $inlineJs ?>
    });
    </script>
    <?php endif; ?>

    <!-- Confirm Modal (global) -->
    <div class="modal modal-blur fade" id="confirmModal" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="confirmModalTitle" aria-describedby="confirmModalMessage" aria-hidden="true">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                <div class="modal-status bg-danger"></div>
                <div class="modal-body text-center py-4">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-danger icon-lg" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                        <path d="M12 9v4"/>
                        <path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z"/>
                        <path d="M12 16h.01"/>
                    </svg>
                    <h3 id="confirmModalTitle">Sind Sie sicher?</h3>
                    <div class="text-secondary" id="confirmModalMessage"></div>
                </div>
                <div class="modal-footer">
                    <div class="w-100">
                        <div class="row">
                            <div class="col">
                                <button type="button" class="btn w-100" data-bs-dismiss="modal">Abbrechen</button>
                            </div>
                            <div class="col">
                                <button type="button" class="btn w-100" id="confirmModalBtn">Bestätigen</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script <?= \CMS\Security::instance()->nonceAttr() ?>>
    /**
     * Globale Bestätigungsfunktion für destruktive Aktionen
     */
    function cmsConfirm(options) {
        options = options && typeof options === 'object' ? options : {};
        const modal = document.getElementById('confirmModal');
        const fallbackConfirm = function() {
            const message = String(options && options.message ? options.message : '');
            const confirmed = window.confirm(message !== '' ? message : 'Sind Sie sicher?');

            if (confirmed && typeof options.onConfirm === 'function') {
                options.onConfirm();
            }

            return confirmed;
        };

        if (!modal || typeof bootstrap === 'undefined' || !bootstrap || typeof bootstrap.Modal !== 'function') {
            return fallbackConfirm();
        }

        const bsModal = new bootstrap.Modal(modal);
        const titleEl = document.getElementById('confirmModalTitle');
        const msgEl = document.getElementById('confirmModalMessage');
        const btnEl = document.getElementById('confirmModalBtn');
        const statusEl = modal.querySelector('.modal-status');
        const cancelBtn = modal.querySelector('[data-bs-dismiss="modal"]');

        if (!titleEl || !msgEl || !btnEl || !statusEl || !cancelBtn) {
            return fallbackConfirm();
        }

        titleEl.textContent = options.title || 'Sind Sie sicher?';
        msgEl.textContent = options.message || '';
        btnEl.textContent = options.confirmText || 'Bestätigen';
        btnEl.className = 'btn w-100 ' + (options.confirmClass || 'btn-danger');
        cancelBtn.className = 'btn w-100 ' + (options.cancelClass || 'btn-outline-secondary');
        statusEl.className = 'modal-status ' + (options.statusClass || 'bg-danger');

        // Clone button to remove old listeners
        const newBtn = btnEl.cloneNode(true);
        btnEl.parentNode.replaceChild(newBtn, btnEl);
        newBtn.id = 'confirmModalBtn';

        newBtn.addEventListener('click', function() {
            bsModal.hide();
            if (typeof options.onConfirm === 'function') {
                options.onConfirm();
            }
        });

        bsModal.show();

        return true;
    }

    /**
     * Globale Alert-Funktion
     */
    function cmsAlert(type, message, container) {
        const wrapper = container || document.querySelector('.page-body .container-xl');
        if (!wrapper) return;
        const allowedTypes = ['success', 'info', 'warning', 'danger', 'primary', 'secondary'];
        const alert = document.createElement('div');
        alert.className = 'alert alert-' + (allowedTypes.indexOf(type) !== -1 ? type : 'info') + ' alert-dismissible fade show';
        alert.setAttribute('role', 'alert');
        // Meldungen als Text einfügen (keine HTML-Interpretation von Server-/Nutzerdaten).
        const row = document.createElement('div');
        row.className = 'd-flex';
        const text = document.createElement('div');
        text.textContent = String(message === undefined || message === null ? '' : message);
        row.appendChild(text);
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close';
        close.setAttribute('data-bs-dismiss', 'alert');
        close.setAttribute('aria-label', 'Schließen');
        alert.appendChild(row);
        alert.appendChild(close);
        wrapper.prepend(alert);
        setTimeout(function() { alert.remove(); }, 8000);
    }
    </script>

    <?php \CMS\Hooks::doAction('body_end'); ?>
    <?php \CMS\Hooks::doAction('admin_body_end'); ?>
</body>
</html>
