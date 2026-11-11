(function () {
    'use strict';

    function submitHiddenForm(inputElement, formElement, value) {
        if (!inputElement || !formElement) {
            return;
        }

        inputElement.value = value;
        if (typeof formElement.requestSubmit === 'function') {
            formElement.requestSubmit();
            return;
        }

        var fallbackSubmitter = document.createElement('button');
        fallbackSubmitter.type = 'submit';
        fallbackSubmitter.hidden = true;
        formElement.appendChild(fallbackSubmitter);
        fallbackSubmitter.click();
        fallbackSubmitter.remove();
    }

    function showConfirm(options, fallbackMessage, onConfirm) {
        if (typeof cmsConfirm === 'function') {
            cmsConfirm(Object.assign({}, options, { onConfirm: onConfirm }));
            return;
        }

        if (window.confirm(fallbackMessage)) {
            onConfirm();
        }
    }

    function showToast(message, type) {
        if (typeof cmsShowTopToast === 'function') {
            cmsShowTopToast(message);
            return;
        }
        if (typeof cmsAlert === 'function') {
            cmsAlert(type || 'info', message);
        }
    }

    function absoluteUrlFromPath(path) {
        var normalizedPath = String(path || '').trim();

        if (normalizedPath === '') {
            return '';
        }

        try {
            return new URL(normalizedPath, window.location.origin + '/').toString();
        } catch (_error) {
            return normalizedPath;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var deleteIdInput = document.getElementById('deleteId');
        var deleteForm = document.getElementById('deleteForm');
        var duplicateIdInput = document.getElementById('duplicateId');
        var duplicateForm = document.getElementById('duplicateForm');
        var duplicateTemplateKeyInput = document.getElementById('duplicateTemplateKey');
        var duplicateTemplateForm = document.getElementById('duplicateTemplateForm');
        var deleteTemplateKeyInput = document.getElementById('deleteTemplateKey');
        var deleteTemplateForm = document.getElementById('deleteTemplateForm');

        document.querySelectorAll('.js-hub-sites-search-input').forEach(function (input) {
            input.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter') {
                    return;
                }

                event.preventDefault();
                var query = input.value.trim();
                var baseUrl = input.getAttribute('data-search-url') || window.location.pathname;
                window.location.href = baseUrl + (query !== '' ? '?q=' + encodeURIComponent(query) : '');
            });
        });

        document.querySelectorAll('.js-copy-hub-url').forEach(function (button) {
            button.addEventListener('click', function () {
                var publicUrl = absoluteUrlFromPath(button.getAttribute('data-hub-public-path') || '');
                if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
                    showToast('Kopieren wird von diesem Browser leider nicht unterstützt.', 'warning');
                    return;
                }

                navigator.clipboard.writeText(publicUrl).then(function () {
                    showToast('Public-URL wurde in die Zwischenablage kopiert.', 'success');
                }).catch(function () {
                    showToast('Public-URL konnte nicht kopiert werden.', 'danger');
                });
            });
        });

        document.querySelectorAll('.js-duplicate-hub-site').forEach(function (button) {
            button.addEventListener('click', function () {
                submitHiddenForm(duplicateIdInput, duplicateForm, button.getAttribute('data-hub-site-id') || '0');
            });
        });

        document.querySelectorAll('.js-delete-hub-site').forEach(function (button) {
            button.addEventListener('click', function () {
                var siteId = button.getAttribute('data-hub-site-id') || '0';
                var siteName = button.getAttribute('data-hub-site-name') || '';
                showConfirm(
                    {
                        title: 'Hub-Site in den Papierkorb',
                        message: 'Hub-Site "' + siteName + '" in den Papierkorb verschieben? Sie ist danach nicht mehr erreichbar und lässt sich wiederherstellen.',
                        confirmText: 'In den Papierkorb',
                        confirmClass: 'btn-danger',
                    },
                    'Hub-Site "' + siteName + '" in den Papierkorb verschieben?',
                    function () {
                        submitHiddenForm(deleteIdInput, deleteForm, siteId);
                    }
                );
            });
        });

        document.querySelectorAll('.js-duplicate-hub-template').forEach(function (button) {
            button.addEventListener('click', function () {
                submitHiddenForm(duplicateTemplateKeyInput, duplicateTemplateForm, button.getAttribute('data-hub-template-key') || '');
            });
        });

        document.querySelectorAll('.js-delete-hub-template').forEach(function (button) {
            button.addEventListener('click', function () {
                var templateKey = button.getAttribute('data-hub-template-key') || '';
                var templateName = button.getAttribute('data-hub-template-name') || '';
                showConfirm(
                    {
                        title: 'Template löschen',
                        message: 'Template <strong>' + templateName + '</strong> wirklich löschen?',
                        confirmText: 'In den Papierkorb',
                        confirmClass: 'btn-danger',
                    },
                    'Template "' + templateName + '" wirklich löschen?',
                    function () {
                        submitHiddenForm(deleteTemplateKeyInput, deleteTemplateForm, templateKey);
                    }
                );
            });
        });
    });
})();