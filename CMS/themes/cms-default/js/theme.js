/**
 * Meridian CMS Default – Theme JavaScript
 *
 * Vanilla ES2020+, kein jQuery, kein externes Framework.
 * Enthält: Mobile Navigation, Sticky Header, Suche, Dropdown,
 *          Passwort-Toggle, Copy-Link, Scroll-To-Top, Cookie-Banner.
 */

'use strict';

(function () {

    // ── Hilfsfunktionen ────────────────────────────────────────────────────

    /**
     * Einfacher Query-Selektor
     * @param {string} sel
     * @param {Element|Document} ctx
     * @returns {Element|null}
     */
    const $ = (sel, ctx = document) => ctx.querySelector(sel);
    const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

    // ── Suche: Typ und Sortierung sofort anwenden ──────────────────────────

    $$('[data-search-autosubmit]').forEach((select) => {
        select.addEventListener('change', () => {
            const form = select.form;
            if (!form) return;
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        });
    });

    // ── Mobile Navigation ──────────────────────────────────────────────────

    const navToggle       = $('#navToggle');
    const mobileNavPanel  = $('#mobileNavPanel');
    const mobileNavOverlay = $('#mobileNavOverlay');
    const mobileNavClose  = $('#mobileNavClose');

    function openMobileNav() {
        if (!mobileNavPanel) return;
        mobileNavPanel.removeAttribute('aria-hidden');
        mobileNavPanel.removeAttribute('inert');
        mobileNavPanel.classList.add('is-open');
        if (mobileNavOverlay) {
            mobileNavOverlay.removeAttribute('aria-hidden');
            mobileNavOverlay.classList.add('is-visible');
        }
        document.body.style.overflow = 'hidden';
        if (navToggle) navToggle.setAttribute('aria-expanded', 'true');
    }

    function closeMobileNav() {
        if (!mobileNavPanel) return;
        mobileNavPanel.setAttribute('aria-hidden', 'true');
        mobileNavPanel.setAttribute('inert', '');
        mobileNavPanel.classList.remove('is-open');
        if (mobileNavOverlay) {
            mobileNavOverlay.setAttribute('aria-hidden', 'true');
            mobileNavOverlay.classList.remove('is-visible');
        }
        document.body.style.overflow = '';
        if (navToggle) navToggle.setAttribute('aria-expanded', 'false');
    }

    if (navToggle)       navToggle.addEventListener('click', openMobileNav);
    if (mobileNavClose)  mobileNavClose.addEventListener('click', closeMobileNav);
    if (mobileNavOverlay) mobileNavOverlay.addEventListener('click', closeMobileNav);

    // ESC schließt mobile Nav
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeMobileNav();
            closeSearch();
        }
    });

    // ── Desktop Dropdown-Navigation ────────────────────────────────────────

    $$('.nav-item.has-dropdown').forEach((item) => {
        const link    = $('[aria-haspopup]', item);
        const dropdown = $('.nav-dropdown', item);
        if (!link || !dropdown) return;

        let closeTimer;

        function openDropdown() {
            clearTimeout(closeTimer);
            link.setAttribute('aria-expanded', 'true');
            dropdown.classList.add('is-open');
        }

        function scheduleClose() {
            closeTimer = setTimeout(() => {
                link.setAttribute('aria-expanded', 'false');
                dropdown.classList.remove('is-open');
            }, 150);
        }

        item.addEventListener('mouseenter', openDropdown);
        item.addEventListener('mouseleave', scheduleClose);
        dropdown.addEventListener('mouseenter', () => clearTimeout(closeTimer));

        // Keyboard-Zugänglichkeit
        link.addEventListener('click', (e) => {
            const isOpen = link.getAttribute('aria-expanded') === 'true';
            if (isOpen) {
                link.setAttribute('aria-expanded', 'false');
                dropdown.classList.remove('is-open');
            } else {
                openDropdown();
            }
            // Nur unterdrücken wenn es kein echter Link-Klick ist
            if (link.tagName === 'BUTTON') {
                e.preventDefault();
            }
        });

        // Focus-trap: Schließen wenn Fokus raus
        item.addEventListener('focusout', (e) => {
            if (!item.contains(e.relatedTarget)) {
                scheduleClose();
            }
        });
    });

    // Alle Dropdowns schließen bei Klick außerhalb
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.nav-item.has-dropdown')) {
            $$('.nav-dropdown.is-open').forEach((d) => d.classList.remove('is-open'));
            $$('[aria-haspopup][aria-expanded="true"]').forEach((l) => l.setAttribute('aria-expanded', 'false'));
        }
    });

    // ── Sticky Header + Scroll-Shadow ──────────────────────────────────────

    const siteHeader = $('#site-header');

    if (siteHeader) {
        function onScroll() {
            if (window.scrollY > 8) {
                siteHeader.classList.add('site-header--scrolled');
            } else {
                siteHeader.classList.remove('site-header--scrolled');
            }
        }

        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    // ── Suche (Header Search Bar) ──────────────────────────────────────────

    const searchToggle = $('#searchToggle');
    const headerSearch = $('#headerSearch');
    const searchClose  = $('#searchClose');

    function openSearch() {
        if (!headerSearch) return;
        headerSearch.removeAttribute('aria-hidden');
        headerSearch.classList.add('is-open');
        if (searchToggle) searchToggle.setAttribute('aria-expanded', 'true');
        const input = $('input[type="search"]', headerSearch);
        if (input) setTimeout(() => input.focus(), 50);
    }

    function closeSearch(restoreFocus = true) {
        if (!headerSearch) return;
        headerSearch.setAttribute('aria-hidden', 'true');
        headerSearch.classList.remove('is-open');
        if (searchToggle) {
            searchToggle.setAttribute('aria-expanded', 'false');
            if (restoreFocus) searchToggle.focus();
        }
    }

    if (searchToggle) {
        searchToggle.addEventListener('click', () => {
            if (headerSearch && headerSearch.classList.contains('is-open')) {
                closeSearch();
            } else {
                openSearch();
            }
        });
    }
    if (searchClose)  searchClose.addEventListener('click', () => closeSearch());
    if (headerSearch) {
        headerSearch.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') closeSearch();
        });
    }

    // ── Passwort-Toggle ────────────────────────────────────────────────────

    $$('.form-password-toggle').forEach((btn) => {
        btn.addEventListener('click', () => {
            const wrap  = btn.closest('.form-control-wrap--password');
            const input = wrap ? $('input', wrap) : null;
            if (!input) return;
            const isText = input.type === 'text';
            input.type = isText ? 'password' : 'text';
            btn.setAttribute('aria-label', isText ? 'Passwort anzeigen' : 'Passwort ausblenden');
        });
    });

    // ── Copy-Link Buttons (Share) ──────────────────────────────────────────

    $$('.share-btn--copy').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const url = btn.dataset.copy || window.location.href;
            try {
                await navigator.clipboard.writeText(url);
                const original = btn.innerHTML;
                btn.innerHTML = '✓';
                btn.style.color = 'var(--accent, #c0862a)';
                setTimeout(() => {
                    btn.innerHTML = original;
                    btn.style.color = '';
                }, 2000);
            } catch {
                // Fallback: nichts tun
            }
        });
    });

    // ── Scroll-To-Top ──────────────────────────────────────────────────────

    const scrollTopBtn = $('#scrollTop');

    if (scrollTopBtn) {
        window.addEventListener('scroll', () => {
            if (window.scrollY > 400) {
                scrollTopBtn.classList.add('is-visible');
            } else {
                scrollTopBtn.classList.remove('is-visible');
            }
        }, { passive: true });

        scrollTopBtn.addEventListener('click', () => {
            const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
        });
    }

    // ── Cookie-Banner ──────────────────────────────────────────────────────

    const cookieBanner  = $('#cookieBanner');
    const cookieAccept  = $('#cookieAccept');
    const cookieDecline = $('#cookieDecline');

    function setCookie(name, value, days) {
        const d = new Date();
        d.setTime(d.getTime() + days * 24 * 60 * 60 * 1000);
        const secure = window.location.protocol === 'https:' ? ';Secure' : '';
        document.cookie = `${encodeURIComponent(name)}=${encodeURIComponent(value)};expires=${d.toUTCString()};path=/;SameSite=Lax${secure}`;
    }

    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? match[2] : null;
    }

    if (cookieBanner) {
        if (!getCookie('meridian_cookie_consent')) {
            setTimeout(() => cookieBanner.classList.add('is-visible'), 1500);
        }

        if (cookieAccept) {
            cookieAccept.addEventListener('click', () => {
                setCookie('meridian_cookie_consent', 'accepted', 365);
                cookieBanner.classList.remove('is-visible');
            });
        }

        if (cookieDecline) {
            cookieDecline.addEventListener('click', () => {
                setCookie('meridian_cookie_consent', 'declined', 30);
                cookieBanner.classList.remove('is-visible');
            });
        }
    }

    // ── Lazy-Load Bilder (IntersectionObserver) ────────────────────────────

    if ('IntersectionObserver' in window) {
        const lazyImgs = $$('img[loading="lazy"]');
        const imgObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    if (img.dataset.src) {
                        img.src = img.dataset.src;
                        delete img.dataset.src;
                    }
                    img.classList.add('loaded');
                    observer.unobserve(img);
                }
            });
        }, { rootMargin: '200px 0px' });

        lazyImgs.forEach((img) => imgObserver.observe(img));
    }

    // ── Smooth Anchor-Scroll ───────────────────────────────────────────────

    $$('a[href^="#"]').forEach((anchor) => {
        anchor.addEventListener('click', (e) => {
            const targetId = anchor.getAttribute('href').slice(1);
            if (!targetId) return;
            const target = document.getElementById(targetId);
            if (!target) return;
            e.preventDefault();
            const offset = siteHeader ? siteHeader.offsetHeight + 16 : 80;
            const top = target.getBoundingClientRect().top + window.scrollY - offset;
            window.scrollTo({ top, behavior: 'smooth' });
        });
    });

    // ── Reading Progress (optional) ────────────────────────────────────────

    const progressBar = $('#readingProgress');

    if (progressBar) {
        const postBody = $('.post-body');
        window.addEventListener('scroll', () => {
            if (!postBody) return;
            const { top, height } = postBody.getBoundingClientRect();
            const start    = top + window.scrollY;
            const progress = Math.min(1, Math.max(0, (window.scrollY - start + window.innerHeight) / height));
            progressBar.style.width = `${(progress * 100).toFixed(1)}%`;
        }, { passive: true });
    }

})();
