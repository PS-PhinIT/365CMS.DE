(function () {
    'use strict';

    var MAX_ANALYSIS_JSON_LENGTH = 250000;
    var MAX_ANALYSIS_BLOCKS = 120;
    var MAX_HTML_FRAGMENT_LENGTH = 100000;

    function toLower(value) {
        return String(value || '').toLocaleLowerCase('de-DE');
    }

    function wordCount(text) {
        var match = String(text || '').match(/[\p{L}\p{N}\-]+/gu);
        return match ? match.length : 0;
    }

    function splitSentences(text) {
        return String(text || '').split(/(?<=[.!?])\s+/u).map(function (item) {
            return item.trim();
        }).filter(Boolean);
    }

    function splitParagraphs(text) {
        var items = String(text || '').split(/\n\s*\n|\r\n\s*\r\n/u).map(function (item) {
            return item.trim();
        }).filter(Boolean);
        return items.length ? items : [String(text || '').trim()].filter(Boolean);
    }

    function parseEditorJson(raw) {
        var analysis = analyzeEditorJson(raw);
        return analysis ? analysis.plainText : raw;
    }

    function normalizeVisibleText(text) {
        return String(text || '').replace(/\s+/g, ' ').trim();
    }

    function truncateText(value, maxLength) {
        var normalized = String(value || '').trim();
        var characters = Array.from(normalized);

        if (characters.length <= maxLength) {
            return normalized;
        }

        return characters.slice(0, Math.max(0, maxLength - 1)).join('').trim() + '…';
    }

    function normalizeComparableText(text) {
        return toLower(normalizeVisibleText(text));
    }

    function buildEmptyAnalysis() {
        return {
            plainText: '',
            paragraphs: [],
            links: { internal: 0, external: 0 },
            images: { count: 0, missing: 0 },
            headings: { h1: 0, h2: 0, h3: 0, h4: 0, h5: 0, h6: 0 }
        };
    }

    function incrementHeadingCount(analysis, level) {
        var normalizedLevel = Number(level);
        var key;

        if (!analysis || !analysis.headings) {
            return;
        }

        if (!Number.isFinite(normalizedLevel) || normalizedLevel < 1 || normalizedLevel > 6) {
            return;
        }

        key = 'h' + String(Math.round(normalizedLevel));
        analysis.headings[key] = Number(analysis.headings[key] || 0) + 1;
    }

    function pushParagraph(analysis, value) {
        var text = normalizeVisibleText(value);
        if (text !== '') {
            analysis.paragraphs.push(text);
        }
    }

    function finalizeAnalysis(analysis) {
        analysis.plainText = normalizeVisibleText(analysis.paragraphs.join(' '));
        return analysis;
    }

    function classifyHref(href) {
        var value = String(href || '').trim();

        if (value === '') {
            return '';
        }

        if (value.indexOf('/') === 0) {
            return 'internal';
        }

        try {
            if (window.location) {
                var resolved = new URL(value, window.location.origin);
                return resolved.origin === window.location.origin ? 'internal' : 'external';
            }
        } catch (_error) {
            // ignore and fall back below
        }

        return /^(https?:|mailto:|tel:)/i.test(value) ? 'external' : '';
    }

    function parseHtmlDocument(html) {
        var safeHtml = String(html || '');

        if (safeHtml.length > MAX_HTML_FRAGMENT_LENGTH) {
            safeHtml = safeHtml.slice(0, MAX_HTML_FRAGMENT_LENGTH);
        }

        if (typeof DOMParser === 'function') {
            return new DOMParser().parseFromString(safeHtml, 'text/html');
        }

        var fallbackDocument = document.implementation.createHTMLDocument('');
        fallbackDocument.body.textContent = safeHtml;
        return fallbackDocument;
    }

    function clearElement(element) {
        if (!element) {
            return;
        }

        while (element.firstChild) {
            element.removeChild(element.firstChild);
        }
    }

    function collectPreviewTargets(primaryElement, bindName) {
        var targets = [];
        var seen = [];

        if (primaryElement) {
            targets.push(primaryElement);
            seen.push(primaryElement);
        }

        if (bindName) {
            document.querySelectorAll('[data-seo-preview-bind="' + bindName + '"]').forEach(function (element) {
                if (seen.indexOf(element) !== -1) {
                    return;
                }

                seen.push(element);
                targets.push(element);
            });
        }

        return targets;
    }

    function setPreviewText(primaryElement, bindName, value, fallback) {
        var resolved = String(value || '');
        if (!resolved) {
            resolved = String(fallback || '');
        }

        collectPreviewTargets(primaryElement, bindName).forEach(function (element) {
            element.textContent = resolved;
        });
    }

    function setPreviewImage(primaryElement, bindName, src, fallback) {
        var resolved = String(src || '').trim() || String(fallback || '').trim();

        collectPreviewTargets(primaryElement, bindName).forEach(function (element) {
            if (!('src' in element)) {
                return;
            }

            element.src = resolved;
            element.style.display = resolved ? 'block' : 'none';
        });
    }

    function collectHtmlAnalysis(html, analysis, addParagraph) {
        var htmlDocument = parseHtmlDocument(html);
        var root = htmlDocument.body || htmlDocument;

        root.querySelectorAll('h1, h2, h3, h4, h5, h6').forEach(function (heading) {
            incrementHeadingCount(analysis, Number(String(heading.tagName || '').replace(/[^1-6]/g, '')));
        });

        root.querySelectorAll('a[href]').forEach(function (link) {
            var kind = classifyHref(link.getAttribute('href') || '');
            if (kind === 'internal') {
                analysis.links.internal += 1;
            } else if (kind === 'external') {
                analysis.links.external += 1;
            }
        });

        root.querySelectorAll('img').forEach(function (image) {
            analysis.images.count += 1;
            if (normalizeVisibleText(image.getAttribute('alt') || '') === '') {
                analysis.images.missing += 1;
            }
        });

        if (addParagraph) {
            pushParagraph(analysis, root.textContent || '');
        }
    }

    function collectListItemAnalysis(item, analysis) {
        if (typeof item === 'string') {
            collectHtmlAnalysis(item, analysis, true);
            return;
        }

        if (!item || typeof item !== 'object') {
            return;
        }

        ['content', 'text'].forEach(function (key) {
            if (typeof item[key] === 'string') {
                collectHtmlAnalysis(item[key], analysis, true);
            }
        });

        if (Array.isArray(item.items)) {
            item.items.forEach(function (child) {
                collectListItemAnalysis(child, analysis);
            });
        }
    }

    function collectImageAnalysis(candidate, analysis) {
        var hasImage = false;
        var altText = '';

        if (!candidate || typeof candidate !== 'object') {
            return;
        }

        hasImage = Boolean(
            candidate.url
            || candidate.src
            || (candidate.file && (candidate.file.url || candidate.file.src || candidate.file.path))
        );

        if (!hasImage) {
            return;
        }

        altText = normalizeVisibleText(candidate.alt || candidate.caption || (candidate.file && candidate.file.alt) || '');
        analysis.images.count += 1;
        if (altText === '') {
            analysis.images.missing += 1;
        }

        if (typeof candidate.caption === 'string') {
            collectHtmlAnalysis(candidate.caption, analysis, true);
        }
    }

    function collectEditorBlockAnalysis(block, analysis) {
        var type = String(block && block.type || '');
        var data = block && block.data && typeof block.data === 'object' ? block.data : {};

        switch (type) {
            case 'header':
                incrementHeadingCount(analysis, data.level);
                if (typeof data.text === 'string') {
                    collectHtmlAnalysis(data.text, analysis, true);
                }
                break;
            case 'paragraph':
            case 'quote':
            case 'warning':
            case 'callout':
            case 'mediaText':
            case 'raw':
                ['text', 'caption', 'title', 'message', 'html'].forEach(function (key) {
                    if (typeof data[key] === 'string') {
                        collectHtmlAnalysis(data[key], analysis, true);
                    }
                });
                break;
            case 'checklist':
                (Array.isArray(data.items) ? data.items : []).forEach(function (item) {
                    if (item && typeof item === 'object' && typeof item.text === 'string') {
                        collectHtmlAnalysis(item.text, analysis, true);
                    }
                });
                break;
            case 'list':
                (Array.isArray(data.items) ? data.items : []).forEach(function (item) {
                    collectListItemAnalysis(item, analysis);
                });
                break;
            case 'image':
                collectImageAnalysis(data, analysis);
                break;
            case 'imageGallery':
                var galleryImages = Array.isArray(data.images) ? data.images : [];
                galleryImages.forEach(function (item) {
                    collectImageAnalysis(item, analysis);
                });
                if (galleryImages.length === 0) {
                    (Array.isArray(data.urls) ? data.urls : []).forEach(function (url) {
                        collectImageAnalysis({ url: url }, analysis);
                    });
                }
                break;
            case 'carousel':
                (Array.isArray(data.items) ? data.items : (Array.isArray(data) ? data : [])).forEach(function (item) {
                    collectImageAnalysis(item, analysis);
                });
                break;
            case 'linkTool':
            case 'link':
                ['link', 'url', 'href'].forEach(function (key) {
                    if (typeof data[key] === 'string') {
                        var kind = classifyHref(data[key]);
                        if (kind === 'internal') {
                            analysis.links.internal += 1;
                        } else if (kind === 'external') {
                            analysis.links.external += 1;
                        }
                    }
                });
                ['title', 'description', 'caption', 'text'].forEach(function (key) {
                    if (typeof data[key] === 'string') {
                        collectHtmlAnalysis(data[key], analysis, true);
                    }
                });
                break;
            default:
                Object.keys(data).forEach(function (key) {
                    if (/^(?:url|href|src|path|id|type|level|alignment|style|preset|target|rel)$/i.test(key)) {
                        return;
                    }

                    if (typeof data[key] === 'string') {
                        collectHtmlAnalysis(data[key], analysis, true);
                    }
                });
                break;
        }
    }

    function analyzeEditorJson(raw) {
        var source = String(raw || '');

        if (source.length > MAX_ANALYSIS_JSON_LENGTH) {
            return null;
        }

        try {
            var parsed = JSON.parse(source);

            if (!parsed || !Array.isArray(parsed.blocks) || parsed.blocks.length > MAX_ANALYSIS_BLOCKS) {
                return null;
            }

            var analysis = buildEmptyAnalysis();
            parsed.blocks.forEach(function (block) {
                collectEditorBlockAnalysis(block, analysis);
            });

            return finalizeAnalysis(analysis);
        } catch (_error) {
            return null;
        }
    }

    function analyzeContent(raw, editorContainer) {
        var analysis = buildEmptyAnalysis();
        var source = String(raw || '').trim();

        if (source && (source.charAt(0) === '{' || source.charAt(0) === '[')) {
            var jsonAnalysis = analyzeEditorJson(source);
            if (jsonAnalysis) {
                return jsonAnalysis;
            }
        }

        if (editorContainer && editorContainer.querySelectorAll) {
            editorContainer.querySelectorAll('h1, h2, h3, h4, h5, h6').forEach(function (heading) {
                incrementHeadingCount(analysis, Number(String(heading.tagName || '').replace(/[^1-6]/g, '')));
            });

            editorContainer.querySelectorAll('a[href]').forEach(function (link) {
                var kind = classifyHref(link.getAttribute('href') || '');
                if (kind === 'internal') {
                    analysis.links.internal += 1;
                } else if (kind === 'external') {
                    analysis.links.external += 1;
                }
            });

            editorContainer.querySelectorAll('img').forEach(function (image) {
                analysis.images.count += 1;
                if (normalizeVisibleText(image.getAttribute('alt') || '') === '') {
                    analysis.images.missing += 1;
                }
            });

            Array.prototype.slice.call(editorContainer.querySelectorAll('.ce-block')).forEach(function (block) {
                pushParagraph(analysis, block.textContent || '');
            });
        }

        if (analysis.paragraphs.length > 0 || analysis.links.internal > 0 || analysis.links.external > 0 || analysis.images.count > 0) {
            return finalizeAnalysis(analysis);
        }

        if (!source) {
            return finalizeAnalysis(analysis);
        }

        collectHtmlAnalysis(source, analysis, true);
        return finalizeAnalysis(analysis);
    }

    function extractFirstParagraph(analysis) {
        if (!analysis || !Array.isArray(analysis.paragraphs) || analysis.paragraphs.length === 0) {
            return '';
        }

        return String(analysis.paragraphs[0] || '').slice(0, 155).trim();
    }

    function containsPhrase(text, phrase) {
        return countPhrase(text, phrase) > 0;
    }

    function isPhraseBoundary(text, index, length) {
        var previous = index > 0 ? text.charAt(index - 1) : '';
        var next = index + length < text.length ? text.charAt(index + length) : '';

        return !/[\p{L}\p{N}_]/u.test(previous) && !/[\p{L}\p{N}_]/u.test(next);
    }

    function countPhrase(text, phrase) {
        var haystack;
        var needle;
        var count = 0;
        var offset = 0;
        var index;

        if (!phrase) {
            return 0;
        }

        haystack = toLower(text);
        needle = toLower(phrase).trim();

        if (!needle) {
            return 0;
        }

        while ((index = haystack.indexOf(needle, offset)) !== -1) {
            if (isPhraseBoundary(haystack, index, needle.length)) {
                count += 1;
            }
            offset = index + needle.length;
        }

        return count;
    }

    function countTransitionWords(text) {
        var words = ['außerdem', 'zudem', 'darüber hinaus', 'deshalb', 'daher', 'allerdings', 'jedoch', 'dennoch', 'somit', 'folglich', 'beispielsweise', 'anschließend', 'gleichzeitig', 'insbesondere', 'schließlich', 'weiterhin'];
        return words.reduce(function (sum, word) {
            return sum + countPhrase(text, word);
        }, 0);
    }

    function countPassive(text) {
        var patterns = [/\bwird\b[^.!?]{0,40}\b(?:von|durch|worden)\b/giu, /\bwurden\b/giu, /\bworden\b/giu];
        return patterns.reduce(function (sum, pattern) {
            var matches = String(text || '').match(pattern);
            return sum + (matches ? matches.length : 0);
        }, 0);
    }

    function countLongSentences(sentences, maxWords) {
        return sentences.filter(function (sentence) {
            return wordCount(sentence) > maxWords;
        }).length;
    }

    function countLongParagraphs(paragraphs, maxWords) {
        return paragraphs.filter(function (paragraph) {
            return wordCount(paragraph) > maxWords;
        }).length;
    }

    function renderRuleList(rulesList, rules) {
        if (!rulesList) {
            return;
        }

        clearElement(rulesList);
        rules.forEach(function (rule) {
            var item = document.createElement('div');
            var content = document.createElement('span');
            var title = document.createElement('strong');
            var detail = document.createElement('small');
            var badge = document.createElement('span');

            item.className = 'd-flex justify-content-between gap-3 py-1 border-bottom';
            content.className = 'd-inline-flex flex-column';
            title.textContent = rule.label;
            detail.className = 'text-secondary';
            detail.textContent = rule.detail;
            badge.className = 'badge ' + (rule.passed ? 'bg-success-lt text-success' : 'bg-warning-lt text-warning');
            badge.textContent = rule.passed ? 'OK' : 'Offen';

            content.appendChild(title);
            content.appendChild(detail);
            item.appendChild(content);
            item.appendChild(badge);
            rulesList.appendChild(item);
        });
    }

    function renderHintBadges(container, badges) {
        if (!container) {
            return;
        }

        clearElement(container);

        badges.forEach(function (badgeConfig) {
            var badge = document.createElement('span');
            var tone = badgeConfig.tone || 'secondary';
            var suffix = badgeConfig.passed ? 'ok' : 'empfohlen';

            badge.className = 'badge rounded-pill bg-' + tone + '-lt text-' + tone;
            badge.textContent = badgeConfig.label + ': ' + (badgeConfig.text || suffix);

            if (badgeConfig.detail) {
                badge.setAttribute('title', badgeConfig.detail);
                badge.setAttribute('aria-label', badgeConfig.label + ': ' + badgeConfig.detail);
            }

            container.appendChild(badge);
        });
    }

    function summarizeOverrideValue(value, fallback) {
        var resolved = truncateText(value, 120);

        return resolved !== '' ? resolved : String(fallback || '—');
    }

    function buildDescriptionDefaultSource(excerpt, firstParagraph) {
        if (normalizeVisibleText(excerpt) !== '') {
            return 'Kurzfassung';
        }

        if (normalizeVisibleText(firstParagraph) !== '') {
            return 'ersten Absatz';
        }

        return 'Inhalt';
    }

    function resolveMetaDescription(metaDescription, excerpt, analysis) {
        var explicit = String(metaDescription || '').trim();
        var excerptValue = String(excerpt || '').trim();
        var firstParagraph = extractFirstParagraph(analysis);
        var plainText = analysis && typeof analysis.plainText === 'string' ? analysis.plainText : '';

        if (explicit !== '') {
            return explicit;
        }

        if (excerptValue !== '') {
            return truncateText(excerptValue, 155);
        }

        if (firstParagraph !== '') {
            return truncateText(firstParagraph, 155);
        }

        return truncateText(plainText, 155);
    }

    function renderOverrideNotice(container, summaryElement, listElement, items, buttons) {
        if (!container) {
            return;
        }

        if (!Array.isArray(items) || items.length === 0) {
            container.classList.add('d-none');
            container.classList.remove('alert-warning', 'alert-info');
            if (summaryElement) {
                summaryElement.textContent = '';
            }
            if (listElement) {
                clearElement(listElement);
            }

            if (buttons && buttons.title) {
                buttons.title.classList.add('d-none');
            }
            if (buttons && buttons.description) {
                buttons.description.classList.add('d-none');
            }
            if (buttons && buttons.all) {
                buttons.all.classList.add('d-none');
            }

            return;
        }

        var redundantCount = items.filter(function (item) {
            return !!item.redundant;
        }).length;

        container.classList.remove('d-none', 'alert-warning', 'alert-info');
        container.classList.add(redundantCount > 0 ? 'alert-warning' : 'alert-info');

        if (summaryElement) {
            summaryElement.textContent = '';
        }

        if (listElement) {
            clearElement(listElement);
        }

        if (buttons && buttons.title) {
            buttons.title.classList.toggle('d-none', !items.some(function (item) {
                return item.key === 'meta_title';
            }));
        }

        if (buttons && buttons.description) {
            buttons.description.classList.toggle('d-none', !items.some(function (item) {
                return item.key === 'meta_description';
            }));
        }

        if (buttons && buttons.all) {
            buttons.all.classList.toggle('d-none', items.length < 2);
        }
    }

    function resolveMetaTitle(metaTitle, title, siteName, format, separator) {
        if (metaTitle) {
            return metaTitle;
        }
        return String(format || '%%title%% %%sep%% %%sitename%%')
            .replace(/%%title%%|%title%/g, title || '')
            .replace(/%%sitename%%|%sitename%/g, siteName || '')
            .replace(/%%sep%%|%sep%/g, separator || '|')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function createRule(key, label, passed, detail, weight) {
        return { key: key, label: label, passed: passed, detail: detail, weight: weight };
    }

    function init(config) {
        var form = document.getElementById(config.formId);
        if (!form) {
            return;
        }

        var titleInput = document.getElementById(config.titleId);
        var slugInput = document.getElementById(config.slugId);
        var excerptInput = config.excerptId ? document.getElementById(config.excerptId) : null;
        var metaTitleInput = document.getElementById(config.metaTitleId);
        var metaDescInput = document.getElementById(config.metaDescId);
        var focusInput = document.getElementById(config.focusKeyphraseId);
        var ogTitleInput = document.getElementById(config.ogTitleId);
        var ogDescriptionInput = document.getElementById(config.ogDescriptionId);
        var ogImageInput = document.getElementById(config.ogImageId);
        var twitterTitleInput = document.getElementById(config.twitterTitleId);
        var twitterDescriptionInput = document.getElementById(config.twitterDescriptionId);
        var twitterImageInput = document.getElementById(config.twitterImageId);
        var featuredInput = document.getElementById(config.featuredImageId);
        var statusInput = document.getElementById(config.statusId);
        var contentInput = document.getElementById(config.contentInputId);
        var editorContainer = document.getElementById(config.editorContainerId);
        var previewTitle = document.getElementById(config.serpTitleId);
        var previewUrl = document.getElementById(config.serpUrlId);
        var previewDesc = document.getElementById(config.serpDescriptionId);
        var progressBar = document.getElementById(config.scoreBarId);
        var scoreLabel = document.getElementById(config.scoreLabelId);
        var scoreBadge = document.getElementById(config.scoreBadgeId);
        var rulesList = document.getElementById(config.scoreRulesId);
        var socialTitle = document.getElementById(config.socialTitleId);
        var socialDesc = document.getElementById(config.socialDescriptionId);
        var socialImage = document.getElementById(config.socialImageId);
        var warningBox = document.getElementById(config.publishWarningId);
        var slugState = document.getElementById(config.slugStateId);
        var wordCountLabel = document.getElementById(config.wordCountId);
        var densityLabel = document.getElementById(config.densityId);
        var internalLinksLabel = document.getElementById(config.internalLinksId);
        var externalLinksLabel = document.getElementById(config.externalLinksId);
        var transitionWordsLabel = document.getElementById(config.transitionWordsId);
        var longSentencesLabel = document.getElementById(config.longSentencesId);
        var longParagraphsLabel = document.getElementById(config.longParagraphsId);
        var missingAltLabel = document.getElementById(config.missingAltId);
        var readabilityBadge = document.getElementById(config.readabilityBadgeId);
        var readabilitySummary = document.getElementById(config.readabilitySummaryId);
        var hintBadgeContainer = document.getElementById(config.hintBadgeContainerId);
        var overrideNotice = document.getElementById(config.overrideNoticeId);
        var overrideSummary = document.getElementById(config.overrideSummaryId);
        var overrideList = document.getElementById(config.overrideListId);
        var resetTitleButton = document.getElementById(config.resetMetaTitleId);
        var resetDescriptionButton = document.getElementById(config.resetMetaDescriptionId);
        var resetAllButton = document.getElementById(config.resetAllMetaDefaultsId);
        var hideTitleInput = document.getElementById(config.hideTitleId);

        function clearFieldAndRefresh(field) {
            if (!field) {
                return;
            }

            field.value = '';
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
            field.focus();
        }

        if (resetTitleButton && metaTitleInput) {
            resetTitleButton.addEventListener('click', function () {
                clearFieldAndRefresh(metaTitleInput);
            });
        }

        if (resetDescriptionButton && metaDescInput) {
            resetDescriptionButton.addEventListener('click', function () {
                clearFieldAndRefresh(metaDescInput);
            });
        }

        if (resetAllButton && (metaTitleInput || metaDescInput)) {
            resetAllButton.addEventListener('click', function () {
                if (metaTitleInput) {
                    metaTitleInput.value = '';
                    metaTitleInput.dispatchEvent(new Event('input', { bubbles: true }));
                    metaTitleInput.dispatchEvent(new Event('change', { bubbles: true }));
                }

                if (metaDescInput) {
                    metaDescInput.value = '';
                    metaDescInput.dispatchEvent(new Event('input', { bubbles: true }));
                    metaDescInput.dispatchEvent(new Event('change', { bubbles: true }));
                }

                if (metaTitleInput) {
                    metaTitleInput.focus();
                } else if (metaDescInput) {
                    metaDescInput.focus();
                }
            });
        }

        var updateTimer = null;
        var update = function () {
            if (updateTimer !== null) {
                window.clearTimeout(updateTimer);
                updateTimer = null;
            }

            var title = titleInput ? titleInput.value.trim() : '';
            var slug = slugInput ? slugInput.value.trim().replace(/^\/+/, '') : '';
            var excerpt = excerptInput ? excerptInput.value.trim() : '';
            var metaTitle = metaTitleInput ? metaTitleInput.value.trim() : '';
            var metaDesc = metaDescInput ? metaDescInput.value.trim() : '';
            var focusPhrase = focusInput ? focusInput.value.trim().split(/[,;\n]+/)[0].trim() : '';
            var ogTitle = ogTitleInput ? ogTitleInput.value.trim() : '';
            var ogDescription = ogDescriptionInput ? ogDescriptionInput.value.trim() : '';
            var rawContent = contentInput ? contentInput.value : '';
            var analysis = analyzeContent(rawContent, editorContainer);
            var plainText = analysis.plainText;
            var templateTitle = resolveMetaTitle('', title, config.siteName, config.siteTitleFormat, config.titleSeparator);
            var resolvedTitle = metaTitle || templateTitle;
            var firstParagraph = extractFirstParagraph(analysis);
            var defaultDescription = resolveMetaDescription('', excerpt, analysis);
            var resolvedDesc = resolveMetaDescription(metaDesc, excerpt, analysis);
            var words = wordCount(plainText);
            var density = focusPhrase ? (countPhrase(plainText, focusPhrase) / Math.max(words, 1)) * 100 : 0;
            var intro = plainText.slice(0, Math.max(120, Math.round(plainText.length * 0.1)));
            var sentences = splitSentences(plainText);
            var paragraphs = analysis.paragraphs.length ? analysis.paragraphs : splitParagraphs(plainText);
            var links = analysis.links;
            var images = analysis.images;
            var contentH1Count = Number((analysis.headings && analysis.headings.h1) || 0);
            var visiblePrimaryHeading = (config.titleCreatesH1 !== false) && title !== '' && !(hideTitleInput && hideTitleInput.checked);
            var totalH1Count = contentH1Count + (visiblePrimaryHeading ? 1 : 0);
            var transitionCount = countTransitionWords(plainText);
            var passiveCount = countPassive(plainText);
            var longSentences = countLongSentences(sentences, config.maxSentenceWords || 24);
            var longParagraphs = countLongParagraphs(paragraphs, config.maxParagraphWords || 120);
            var keyphraseHintPassed = !!focusPhrase && (containsPhrase(title, focusPhrase) || containsPhrase(intro, focusPhrase)) && density > 0;
            var previewHref = (function () {
                var template = String(config.previewUrlTemplate || '');
                var placeholderSlug = String(config.previewPlaceholderSlug || 'beitrag');
                if (template !== '' && template.indexOf('{slug}') !== -1) {
                    return template.replace(/\{slug\}/g, slug ? slug : placeholderSlug);
                }

                return (config.previewBaseUrl || '') + (slug ? slug : '…');
            })();
            var ogImage = (ogImageInput && ogImageInput.value.trim()) || (twitterImageInput && twitterImageInput.value.trim()) || (featuredInput && featuredInput.value.trim()) || '';
            var socialResolvedTitle = ogTitle || (twitterTitleInput && twitterTitleInput.value.trim()) || resolvedTitle || config.siteName;
            var socialResolvedDesc = ogDescription || (twitterDescriptionInput && twitterDescriptionInput.value.trim()) || resolvedDesc || 'Social-Vorschau';
            var descriptionSource = buildDescriptionDefaultSource(excerpt, firstParagraph);
            var descriptionSourceLabel = descriptionSource === 'Kurzfassung'
                ? 'der Kurzfassung'
                : (descriptionSource === 'ersten Absatz' ? 'dem ersten Absatz' : 'dem Inhalt');
            var overrideItems = [];

            if (metaTitle !== '') {
                var comparableMetaTitle = normalizeComparableText(metaTitle);
                var comparableTemplateTitle = normalizeComparableText(templateTitle);
                var titleRedundant = comparableTemplateTitle !== '' && comparableMetaTitle === comparableTemplateTitle;

                overrideItems.push({
                    key: 'meta_title',
                    redundant: titleRedundant,
                    message: titleRedundant
                        ? 'Meta-Titel: Der lokale Wert entspricht bereits dem globalen Titel-Template („' + summarizeOverrideValue(templateTitle, config.siteName || '—') + '“).'
                        : 'Meta-Titel: Der lokale Wert überschreibt das globale Titel-Template. Template-Default wäre aktuell „' + summarizeOverrideValue(templateTitle, config.siteName || '—') + '“.'
                });
            }

            if (metaDesc !== '') {
                var comparableMetaDescription = normalizeComparableText(metaDesc);
                var comparableDefaultDescription = normalizeComparableText(defaultDescription);
                var descriptionRedundant = comparableDefaultDescription !== '' && comparableMetaDescription === comparableDefaultDescription;

                overrideItems.push({
                    key: 'meta_description',
                    redundant: descriptionRedundant,
                    message: descriptionRedundant
                        ? 'Meta-Beschreibung: Der lokale Wert entspricht bereits dem automatischen Default aus ' + descriptionSourceLabel + ' („' + summarizeOverrideValue(defaultDescription, 'aktuell leer') + '“).'
                        : (comparableDefaultDescription !== ''
                            ? 'Meta-Beschreibung: Der lokale Wert überschreibt den automatischen Default aus ' + descriptionSourceLabel + '. Default wäre aktuell „' + summarizeOverrideValue(defaultDescription, 'aktuell leer') + '“.'
                            : 'Meta-Beschreibung: Der lokale Wert ist aktiv; ohne ihn gäbe es aktuell keinen automatisch ableitbaren Description-Text.')
                });
            }

            renderOverrideNotice(overrideNotice, overrideSummary, overrideList, overrideItems, {
                title: resetTitleButton,
                description: resetDescriptionButton,
                all: resetAllButton
            });

            var rules = [
                createRule('meta_title', 'Meta-Titel', resolvedTitle.length >= 30 && resolvedTitle.length <= 60, resolvedTitle.length + ' Zeichen', 10),
                createRule('meta_description', 'Meta-Beschreibung', resolvedDesc.length >= 120 && resolvedDesc.length <= 155, resolvedDesc.length + ' Zeichen', 10),
                createRule('h1', 'H1-Eindeutigkeit', totalH1Count === 1, totalH1Count + ' H1 gesamt · ' + contentH1Count + ' im Inhalt', 8),
                createRule('focus', 'Fokus-Keyphrase', !!focusPhrase, focusPhrase || 'fehlt', 10),
                createRule('title', 'Keyphrase im Titel', focusPhrase ? containsPhrase(title, focusPhrase) : false, focusPhrase ? 'Titel geprüft' : 'ohne Keyphrase', 8),
                createRule('slug', 'Keyphrase im Slug', focusPhrase ? containsPhrase(slug.replace(/-/g, ' '), focusPhrase) : false, slug || 'leer', 8),
                createRule('intro', 'Keyphrase in Einleitung', focusPhrase ? containsPhrase(intro, focusPhrase) : false, intro ? 'Einleitung geprüft' : 'kein Text', 6),
                createRule('density', 'Keyphrase-Dichte', focusPhrase ? density >= 0.5 && density <= 3.5 : false, density.toFixed(2) + '%', 8),
                createRule('length', 'Textlänge', words >= (config.minWords || 300), words + ' Wörter', 8),
                createRule('internal', 'Interne Links', links.internal >= 1, links.internal + ' intern', 6),
                createRule('external', 'Externe Links', links.external >= 1, links.external + ' extern', 6),
                createRule('alt', 'Bild-Alt-Texte', images.count === 0 || images.missing === 0, images.count === 0 ? 'keine Bilder' : images.missing + ' ohne Alt', 6),
                createRule('sentences', 'Satzlänge', longSentences <= 3, longSentences + ' lange Sätze', 6),
                createRule('paragraphs', 'Absatzlänge', longParagraphs <= 2, longParagraphs + ' lange Absätze', 6),
                createRule('passive', 'Passive Formulierungen', passiveCount <= 3, passiveCount + ' Marker', 4),
                createRule('transition', 'Signalwörter', transitionCount >= 2, transitionCount + ' Wörter', 4),
                createRule('og', 'OG-/Social-Bild', !!ogImage, ogImage ? 'vorhanden' : 'fehlt', 10)
            ];

            var totalWeight = rules.reduce(function (sum, rule) { return sum + rule.weight; }, 0) || 1;
            var passedWeight = rules.reduce(function (sum, rule) { return sum + (rule.passed ? rule.weight : 0); }, 0);
            var score = Math.round((passedWeight / totalWeight) * 100);
            var status = score >= 80 ? 'green' : (score >= 55 ? 'orange' : 'red');
            var hintBadges = [
                {
                    label: 'Title',
                    passed: resolvedTitle.length >= 30 && resolvedTitle.length <= 60,
                    text: resolvedTitle.length >= 30 && resolvedTitle.length <= 60 ? 'ok' : 'empfohlen',
                    detail: 'Titellinks sollten prägnant, eindeutig und beschreibend sein. Aktuell: ' + resolvedTitle.length + ' Zeichen.',
                    tone: resolvedTitle.length >= 30 && resolvedTitle.length <= 60 ? 'success' : 'warning'
                },
                {
                    label: 'Description',
                    passed: resolvedDesc.length >= 120 && resolvedDesc.length <= 155,
                    text: resolvedDesc.length >= 120 && resolvedDesc.length <= 155 ? 'ok' : 'empfohlen',
                    detail: 'Meta-Beschreibungen sollen seitenkonkret und informativ sein. Aktuell: ' + resolvedDesc.length + ' Zeichen.',
                    tone: resolvedDesc.length >= 120 && resolvedDesc.length <= 155 ? 'success' : 'warning'
                },
                {
                    label: 'H1',
                    passed: totalH1Count === 1,
                    text: totalH1Count === 1 ? 'ok' : 'prüfen',
                    detail: totalH1Count + ' H1 gesamt erkannt; ein klarer Haupttitel ist empfohlen.',
                    tone: totalH1Count === 1 ? 'success' : 'warning'
                },
                {
                    label: 'Keyphrase',
                    passed: keyphraseHintPassed,
                    text: keyphraseHintPassed ? 'ok' : 'empfohlen',
                    detail: focusPhrase ? 'Fokus-Keyphrase „' + focusPhrase + '“ sollte früh im Inhalt oder Titel auftauchen.' : 'Fokus-Keyphrase fehlt.',
                    tone: keyphraseHintPassed ? 'success' : 'warning'
                },
                {
                    label: 'Alt-Texte',
                    passed: images.count === 0 || images.missing === 0,
                    text: images.count === 0 ? 'n/a' : (images.missing === 0 ? 'ok' : 'prüfen'),
                    detail: images.count === 0 ? 'Keine Bilder im Inhalt erkannt.' : images.missing + ' von ' + images.count + ' Bildern ohne Alt-Text.',
                    tone: images.count === 0 ? 'secondary' : (images.missing === 0 ? 'success' : 'warning')
                }
            ];

            setPreviewText(previewTitle, 'serp-title', resolvedTitle || config.siteName, config.siteName || '');
            setPreviewText(previewUrl, 'serp-url', previewHref, config.previewBaseUrl || '/');
            setPreviewText(previewDesc, 'serp-description', resolvedDesc, 'Meta-Beschreibung wird automatisch aus dem ersten Absatz erzeugt.');
            setPreviewText(socialTitle, 'social-title', socialResolvedTitle, config.siteName || '');
            setPreviewText(socialDesc, 'social-description', socialResolvedDesc, 'Social-Vorschau');
            setPreviewImage(socialImage, 'social-image', ogImage, config.fallbackImage || '');
            if (progressBar) {
                progressBar.style.width = score + '%';
                progressBar.className = 'progress-bar bg-' + (status === 'green' ? 'success' : (status === 'orange' ? 'warning' : 'danger'));
            }
            if (scoreLabel) {
                scoreLabel.textContent = String(score);
            }
            if (scoreBadge) {
                scoreBadge.className = 'badge ' + (status === 'green' ? 'bg-success-lt text-success' : (status === 'orange' ? 'bg-warning-lt text-warning' : 'bg-danger-lt text-danger'));
                scoreBadge.textContent = status === 'green' ? 'Grün' : (status === 'orange' ? 'Orange' : 'Rot');
            }
            if (rulesList) {
                renderRuleList(rulesList, rules);
            }
            if (hintBadgeContainer) {
                renderHintBadges(hintBadgeContainer, hintBadges);
            }
            if (slugState) {
                var slugValid = slug === '' || /^[a-z0-9\-]+$/.test(slug);
                slugState.className = 'badge ' + (slugValid ? 'bg-success-lt text-success' : 'bg-danger-lt text-danger');
                slugState.textContent = slugValid ? 'Slug gültig' : 'Slug ungültig';
            }
            if (wordCountLabel) {
                wordCountLabel.textContent = String(words);
            }
            if (densityLabel) {
                densityLabel.textContent = density.toFixed(2) + '%';
            }
            if (internalLinksLabel) {
                internalLinksLabel.textContent = String(links.internal);
            }
            if (externalLinksLabel) {
                externalLinksLabel.textContent = String(links.external);
            }
            if (transitionWordsLabel) {
                transitionWordsLabel.textContent = String(transitionCount);
            }
            if (longSentencesLabel) {
                longSentencesLabel.textContent = String(longSentences);
            }
            if (longParagraphsLabel) {
                longParagraphsLabel.textContent = String(longParagraphs);
            }
            if (missingAltLabel) {
                missingAltLabel.textContent = String(images.missing);
            }
            if (readabilityBadge) {
                var readabilityGood = longSentences <= 3 && longParagraphs <= 2 && passiveCount <= 3 && transitionCount >= 2;
                var readabilityWarn = !readabilityGood && (longSentences <= 5 && longParagraphs <= 4);
                readabilityBadge.className = 'badge ' + (readabilityGood ? 'bg-success-lt text-success' : (readabilityWarn ? 'bg-warning-lt text-warning' : 'bg-danger-lt text-danger'));
                readabilityBadge.textContent = readabilityGood ? 'Gut lesbar' : (readabilityWarn ? 'Optimierbar' : 'Kritisch');
            }
            if (readabilitySummary) {
                readabilitySummary.textContent = words + ' Wörter · ' + longSentences + ' lange Sätze · ' + longParagraphs + ' lange Absätze';
            }
            if (warningBox) {
                var requiredMissing = [];
                if (!resolvedTitle) { requiredMissing.push('Meta-Titel'); }
                if (!resolvedDesc) { requiredMissing.push('Meta-Beschreibung'); }
                if (!ogImage) { requiredMissing.push('OG-Bild'); }
                warningBox.textContent = requiredMissing.length ? 'Vor Veröffentlichung fehlen: ' + requiredMissing.join(', ') : 'SEO-Pflichtfelder für die Veröffentlichung sind gesetzt.';
                warningBox.className = 'alert ' + (requiredMissing.length ? 'alert-warning' : 'alert-success');
                warningBox.dataset.missingRequired = requiredMissing.length ? '1' : '0';
            }

            form.dataset.seoScore = String(score);
        };

        var scheduleUpdate = function () {
            if (updateTimer !== null) {
                window.clearTimeout(updateTimer);
            }

            updateTimer = window.setTimeout(update, 120);
        };

        [titleInput, slugInput, excerptInput, metaTitleInput, metaDescInput, focusInput, ogTitleInput, ogDescriptionInput, ogImageInput, twitterTitleInput, twitterDescriptionInput, twitterImageInput, featuredInput, statusInput, contentInput].forEach(function (el) {
            if (!el) {
                return;
            }
            el.addEventListener('input', scheduleUpdate);
            el.addEventListener('change', scheduleUpdate);
        });

        if (editorContainer && window.MutationObserver) {
            new MutationObserver(scheduleUpdate).observe(editorContainer, { childList: true, subtree: true, characterData: true });
        }

        form.addEventListener('submit', function () {
            update();
        });

        update();
    }

    window.cmsSeoEditor = { init: init };
})();
