(function () {
    'use strict';

    function setFormPendingState(form, pending) {
        if (!form) {
            return;
        }

        form.dataset.submitting = pending ? '1' : '0';

        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (button) {
            button.disabled = pending;
            button.setAttribute('aria-disabled', pending ? 'true' : 'false');
        });
    }

    function parseConfig(id) {
        var element = document.getElementById(id);
        if (!element) {
            return {};
        }

        try {
            return JSON.parse(element.textContent || '{}');
        } catch (error) {
            console.error('Legal-Sites-Konfiguration konnte nicht gelesen werden.', error);
            return {};
        }
    }

    function initLegalProfileRequirements(config) {
        var entityTypeSelect = document.getElementById('legalProfileEntityType');
        var entityHint = document.getElementById('legalProfileEntityHint');
        var companyHint = config.companyHint || 'Firmenprofil aktiv.';
        var privateHint = config.privateHint || 'Privatprofil aktiv.';

        if (!entityTypeSelect) {
            return;
        }

        function updateLegalProfileRequirements() {
            var entityType = entityTypeSelect.value || 'company';
            var isCompany = entityType === 'company';

            document.querySelectorAll('[data-required-for]').forEach(function (wrapper) {
                var requiredFor = wrapper.getAttribute('data-required-for');
                var input = wrapper.querySelector('input, select, textarea');
                var indicator = wrapper.querySelector('.js-required-indicator');
                var isRequired = requiredFor === entityType;

                wrapper.classList.toggle('opacity-75', !isRequired);

                if (input) {
                    input.required = isRequired;
                }

                if (indicator) {
                    indicator.classList.toggle('d-none', !isRequired);
                }
            });

            document.querySelectorAll('[data-recommended-for="company"]').forEach(function (wrapper) {
                wrapper.classList.toggle('opacity-75', !isCompany);
            });

            if (entityHint) {
                entityHint.textContent = isCompany ? companyHint : privateHint;
            }
        }

        entityTypeSelect.addEventListener('change', updateLegalProfileRequirements);
        updateLegalProfileRequirements();
    }

    function initPrivacyFeatureDetails() {
        var featureEmptyState = document.getElementById('legalPrivacyFeatureEmptyState');

        function updatePrivacyFeatureDetails() {
            var visibleCount = 0;

            document.querySelectorAll('[data-privacy-feature]').forEach(function (block) {
                var fieldName = block.getAttribute('data-privacy-feature');
                var toggle = document.querySelector('input[name="' + fieldName + '"]');
                var isEnabled = !!(toggle && toggle.checked);

                block.classList.toggle('d-none', !isEnabled);

                if (isEnabled) {
                    visibleCount += 1;
                }
            });

            if (featureEmptyState) {
                featureEmptyState.classList.toggle('d-none', visibleCount > 0);
            }
        }

        document.querySelectorAll('input[name^="legal_profile_has_"]').forEach(function (toggle) {
            toggle.addEventListener('change', updatePrivacyFeatureDetails);
        });

        updatePrivacyFeatureDetails();
    }

    function initTemplateInsertButtons(config) {
        var title = config.insertTemplateTitle || 'Vorlage einfügen?';
        var message = config.insertTemplateMessage || 'Vorhandener Inhalt wird überschrieben.';
        var confirmText = config.insertTemplateConfirmText || 'Einfügen';

        document.querySelectorAll('.js-insert-template').forEach(function (button) {
            button.addEventListener('click', function () {
                var targetId = button.getAttribute('data-target') || '';
                var template = button.getAttribute('data-template') || '';
                var field = document.getElementById(targetId);

                if (!field) {
                    return;
                }

                if (field.value.trim() === '') {
                    field.value = template;
                    return;
                }

                if (typeof window.cmsConfirm === 'function') {
                    window.cmsConfirm({
                        title: title,
                        message: message,
                        confirmText: confirmText,
                        onConfirm: function () {
                            field.value = template;
                        }
                    });
                    return;
                }

                if (window.confirm(message)) {
                    field.value = template;
                }
            });
        });
    }

    function initProfileTabs() {
        var tabButtons = Array.prototype.slice.call(document.querySelectorAll('[data-legal-profile-tab]'));
        var tabSections = Array.prototype.slice.call(document.querySelectorAll('[data-legal-profile-section]'));
        var tabSaves = Array.prototype.slice.call(document.querySelectorAll('[data-legal-profile-save]'));

        if (!tabButtons.length || !tabSections.length) {
            return;
        }

        function activateTab(tabKey) {
            tabButtons.forEach(function (button) {
                var isActive = button.getAttribute('data-legal-profile-tab') === tabKey;
                button.classList.toggle('active', isActive);
                button.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });

            tabSections.forEach(function (section) {
                var isVisible = section.getAttribute('data-legal-profile-section') === tabKey;
                section.classList.toggle('d-none', !isVisible);
            });

            tabSaves.forEach(function (saveBar) {
                var isVisible = saveBar.getAttribute('data-legal-profile-save') === tabKey;
                saveBar.classList.toggle('d-none', !isVisible);
            });
        }

        tabButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                activateTab(button.getAttribute('data-legal-profile-tab') || 'profile');
            });
        });

        activateTab('profile');
    }

    function sanitizePreviewHtml(rawHtml) {
        var parser = new DOMParser();
        var parsedDocument = parser.parseFromString(rawHtml || '', 'text/html');

        parsedDocument.querySelectorAll('script, iframe, object, embed, link[rel="import"], base').forEach(function (node) {
            node.remove();
        });

        parsedDocument.querySelectorAll('*').forEach(function (node) {
            Array.prototype.slice.call(node.attributes).forEach(function (attribute) {
                if (/^on/i.test(attribute.name)) {
                    node.removeAttribute(attribute.name);
                }
            });
        });

        return parsedDocument.body.innerHTML;
    }

    function initHtmlPreviewToggles() {
        document.querySelectorAll('.legal-sites-editor-toggle').forEach(function (toggle) {
            var buttons = toggle.querySelectorAll('[data-legal-view-mode]');
            if (!buttons.length) {
                return;
            }

            var targetId = buttons[0].getAttribute('data-legal-view-target');
            if (!targetId) {
                return;
            }

            var textarea = document.getElementById(targetId);
            if (!textarea) {
                return;
            }

            var preview = textarea.parentElement ? textarea.parentElement.querySelector('[data-legal-html-preview]') : null;
            if (!preview) {
                return;
            }

            function updatePreviewContent() {
                preview.innerHTML = sanitizePreviewHtml(textarea.value || '');
            }

            function setMode(mode) {
                var isPreview = mode === 'preview';
                textarea.classList.toggle('d-none', isPreview);
                preview.classList.toggle('d-none', !isPreview);

                buttons.forEach(function (button) {
                    var isActive = button.getAttribute('data-legal-view-mode') === mode;
                    button.classList.toggle('active', isActive);
                });

                if (isPreview) {
                    updatePreviewContent();
                }
            }

            buttons.forEach(function (button) {
                button.addEventListener('click', function () {
                    setMode(button.getAttribute('data-legal-view-mode') || 'edit');
                });
            });

            textarea.addEventListener('input', function () {
                if (!preview.classList.contains('d-none')) {
                    updatePreviewContent();
                }
            });
        });
    }

    function serializeForm(form) {
        return new URLSearchParams(new FormData(form));
    }

    function initGlobalSaveAction() {
        var saveAllButton = document.getElementById('js-legal-sites-save-all');
        if (!saveAllButton) {
            return;
        }

        saveAllButton.addEventListener('click', function () {
            var profileForm = document.querySelector('form[data-legal-bulk-save-part="profile"]');
            var accessibilityForm = document.querySelector('form[data-legal-bulk-save-part="accessibility"][data-legal-dirty="1"]');
            var documentForms = Array.prototype.slice.call(document.querySelectorAll('form[data-legal-bulk-save-part="document"]'));
            var createAllForm = document.querySelector('form input[name="action"][value="create_all_pages"]')
                ? document.querySelector('form input[name="action"][value="create_all_pages"]').form
                : null;
            var forms = [];

            if (profileForm) {
                forms.push(profileForm);
            }
            if (accessibilityForm) {
                forms.push(accessibilityForm);
            }
            Array.prototype.push.apply(forms, documentForms);
            if (createAllForm) {
                forms.push(createAllForm);
            }

            if (!forms.length) {
                return;
            }

            saveAllButton.disabled = true;
            saveAllButton.textContent = 'Speichere ...';

            var chain = Promise.resolve();
            forms.forEach(function (form) {
                chain = chain.then(function () {
                    return fetch(window.location.href, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                        },
                        body: serializeForm(form)
                    }).then(function (response) {
                        if (!response.ok) {
                            throw new Error('Speichern fehlgeschlagen');
                        }
                    });
                });
            });

            chain.then(function () {
                window.location.reload();
            }).catch(function () {
                saveAllButton.disabled = false;
                saveAllButton.textContent = 'Alle Standardwerte speichern & Dokumente aktualisieren';
                window.alert('Mindestens ein Speicherschritt ist fehlgeschlagen. Bitte erneut versuchen.');
            });
        });
    }

    // Die Barrierefreiheitserklärung ist optional: „Alle speichern“ nimmt ihr Formular nur bei Änderungen mit.
    function initAccessibilityDirtyTracking() {
        var form = document.querySelector('form[data-legal-bulk-save-part="accessibility"]');
        if (!form) {
            return;
        }

        var markDirty = function () {
            form.setAttribute('data-legal-dirty', '1');
        };
        form.addEventListener('input', markDirty);
        form.addEventListener('change', markDirty);
    }

    function initSubmitGuards() {
        document.querySelectorAll('form[method="post"]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (form.dataset.submitting === '1') {
                    event.preventDefault();
                    return;
                }

                setFormPendingState(form, true);
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var config = parseConfig('legal-sites-config');

        initLegalProfileRequirements(config);
        initProfileTabs();
        initPrivacyFeatureDetails();
        initTemplateInsertButtons(config);
        initHtmlPreviewToggles();
        initGlobalSaveAction();
        initAccessibilityDirtyTracking();
        initSubmitGuards();
    });
})();