(function () {
    'use strict';

    function parseConfig(element) {
        if (!element) {
            return null;
        }

        try {
            return JSON.parse(element.value || '{}');
        } catch (error) {
            return null;
        }
    }

    function escapeRegExp(value) {
        return String(value || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function setPreviewText(bindName, value) {
        document.querySelectorAll('[data-seo-preview-bind="' + bindName + '"]').forEach(function (element) {
            element.textContent = String(value || '');
        });
    }

    function setPreviewImage(bindName, value) {
        var resolved = String(value || '').trim();

        document.querySelectorAll('[data-seo-preview-bind="' + bindName + '"]').forEach(function (element) {
            if (!('src' in element)) {
                return;
            }

            element.src = resolved;
            element.hidden = !resolved;
            element.style.display = resolved ? 'block' : 'none';
        });
    }

    function resolvePreviewTitle(template, title, siteName, separator, description, slug) {
        var separatorToken = '__CMS_SEO_SEP__';
        var resolvedSeparator = String(separator || '|').trim() || '|';
        var workingTemplate = String(template || '%%title%% %%sep%% %%sitename%%');
        var resolvedTitle = String(title || '').trim();
        var resolvedSiteName = String(siteName || '365CMS').trim() || '365CMS';
        var resolvedDescription = String(description || '').trim();
        var resolvedSlug = String(slug || '').trim();

        workingTemplate = workingTemplate.replace(/%%sep%%/gi, separatorToken);
        workingTemplate = workingTemplate.replace(/%%title%%/gi, resolvedTitle);
        workingTemplate = workingTemplate.replace(/%%sitename%%/gi, resolvedSiteName);
        workingTemplate = workingTemplate.replace(/%%excerpt%%/gi, resolvedDescription);
        workingTemplate = workingTemplate.replace(/%%slug%%/gi, resolvedSlug);
        workingTemplate = workingTemplate.replace(new RegExp(separatorToken, 'g'), resolvedSeparator);
        workingTemplate = workingTemplate.replace(/\s{2,}/g, ' ').trim();

        if (resolvedSeparator !== '') {
            var escapedSeparator = escapeRegExp(resolvedSeparator);
            workingTemplate = workingTemplate.replace(new RegExp('\\s*' + escapedSeparator + '\\s*', 'g'), ' ' + resolvedSeparator + ' ');
            workingTemplate = workingTemplate.replace(new RegExp('^(?:' + escapedSeparator + '\\s*)+|(?:\\s*' + escapedSeparator + ')+$', 'g'), '').trim();
            workingTemplate = workingTemplate.replace(/\s{2,}/g, ' ').trim();
        }

        return workingTemplate || resolvedTitle || resolvedSiteName;
    }

    function setActiveContextButton(buttons, activeKey) {
        buttons.forEach(function (button) {
            var isActive = button.getAttribute('data-seo-meta-context') === activeKey;
            button.classList.toggle('active', isActive);
            button.classList.toggle('btn-primary', isActive);
            button.classList.toggle('btn-outline-primary', !isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    function init() {
        var configElement = document.getElementById('seoMetaPreviewConfig');
        var config = parseConfig(configElement);
        if (!config || !Array.isArray(config.contexts) || config.contexts.length === 0) {
            return;
        }

        var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-seo-meta-context]'));
        var inputs = {
            homepageTitle: document.getElementById('seoHomepageTitle'),
            homepageDescription: document.getElementById('seoHomepageDescription'),
            metaDescription: document.getElementById('seoGlobalMetaDescription'),
            titleFormat: document.getElementById('seoSiteTitleFormat'),
            titleSeparator: document.getElementById('seoTitleSeparator')
        };
        var contextLabel = document.getElementById('seoMetaPreviewContextLabel');
        var contextUrl = document.getElementById('seoMetaPreviewContextUrl');
        var contextNote = document.getElementById('seoMetaPreviewContextNote');
        var activeContextKey = String(config.contexts[0].key || 'homepage');

        function findContext(key) {
            for (var index = 0; index < config.contexts.length; index += 1) {
                if (String(config.contexts[index].key || '') === key) {
                    return config.contexts[index];
                }
            }

            return config.contexts[0];
        }

        function readValue(element, fallback) {
            if (!element) {
                return String(fallback || '');
            }

            return String(element.value || '').trim() || String(fallback || '');
        }

        function render() {
            var context = findContext(activeContextKey);
            var isHomepage = String(context.key || '') === 'homepage';
            var previewTitleSource = isHomepage
                ? readValue(inputs.homepageTitle, context.title || config.siteName || '365CMS')
                : String(context.title || config.siteName || '365CMS');
            var previewDescriptionSource = isHomepage
                ? readValue(inputs.homepageDescription, readValue(inputs.metaDescription, context.description || ''))
                : readValue(inputs.metaDescription, context.description || '');
            var previewSeparator = readValue(inputs.titleSeparator, config.titleSeparator || '|');
            var previewTitleFormat = readValue(inputs.titleFormat, config.titleFormat || '%%title%% %%sep%% %%sitename%%');
            var resolvedTitle = resolvePreviewTitle(
                previewTitleFormat,
                previewTitleSource,
                String(config.siteName || '365CMS'),
                previewSeparator,
                previewDescriptionSource,
                String(context.slug || '')
            );
            var resolvedDescription = previewDescriptionSource || 'Die globale Meta-Beschreibung wird hier als Vorschau angezeigt.';
            var resolvedUrl = String(context.url || '/');
            var resolvedImage = String(context.social_image || config.defaultSocialImage || '').trim();

            setPreviewText('serp-title', resolvedTitle);
            setPreviewText('serp-url', resolvedUrl);
            setPreviewText('serp-description', resolvedDescription);
            setPreviewText('social-title', resolvedTitle);
            setPreviewText('social-description', resolvedDescription);
            setPreviewImage('social-image', resolvedImage);

            if (contextLabel) {
                contextLabel.textContent = String(context.label || 'Vorschau');
            }
            if (contextUrl) {
                contextUrl.textContent = resolvedUrl;
            }
            if (contextNote) {
                contextNote.textContent = isHomepage
                    ? 'Die Vorschau reagiert live auf Homepage-Titel und Homepage-Beschreibung.'
                    : 'Die Vorschau kombiniert Titel-Template, Separator und globale Meta-Beschreibung mit dem gewählten Archiv-/Taxonomie-Kontext.';
            }

            setActiveContextButton(buttons, activeContextKey);
        }

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                var nextKey = String(button.getAttribute('data-seo-meta-context') || '').trim();
                if (!nextKey) {
                    return;
                }

                activeContextKey = nextKey;
                render();
            });
        });

        Object.keys(inputs).forEach(function (key) {
            if (!inputs[key]) {
                return;
            }

            inputs[key].addEventListener('input', render);
            inputs[key].addEventListener('change', render);
        });

        render();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
        return;
    }

    init();
})();
