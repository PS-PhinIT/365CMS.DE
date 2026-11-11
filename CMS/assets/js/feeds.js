/**
 * Feed-Aggregator – öffentliche Seiten: Bildfehler, Suche (Esc), Neuladen nach Einwilligungsänderung.
 */
(function() {
    'use strict';

    // Lazy-Load für Bilder (Intersection Observer Fallback)
    function initLazyImages() {
        const images = document.querySelectorAll('.fd-card__image[loading="lazy"]');
        if (!images.length || 'loading' in HTMLImageElement.prototype) return;

        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    if (img.dataset.src) {
                        img.src = img.dataset.src;
                    }
                    observer.unobserve(img);
                }
            });
        }, { rootMargin: '100px' });

        images.forEach(function(img) { observer.observe(img); });
    }

    // Fehlendes Bild ausblenden (Platzhalter verhindern)
    function initImageErrorHandler() {
        document.querySelectorAll('.fd-card__image').forEach(function(img) {
            if (img.complete && img.naturalWidth === 0) {
                const brokenLink = img.closest('.fd-card__image-link');
                if (brokenLink) brokenLink.hidden = true;
            }
            img.addEventListener('error', function() {
                const link = this.closest('.fd-card__image-link');
                if (link) link.hidden = true;
            });
        });
    }

    // Suche: ESC zum Leeren
    function initSearchEsc() {
        const input = document.querySelector('.fd-search__input');
        if (!input) return;
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                this.value = '';
                this.focus();
            }
        });
    }

    function hasFeedConsent(detail) {
        if (!detail || typeof detail !== 'object') {
            return true;
        }

        const acceptedCategories = Array.isArray(detail.acceptedCategories) ? detail.acceptedCategories : [];
        const acceptedServicesMap = detail.acceptedServices && typeof detail.acceptedServices === 'object'
            ? detail.acceptedServices
            : {};
        const acceptedServices = Object.values(acceptedServicesMap).flatMap(function(services) {
            return Array.isArray(services) ? services : [];
        });

        return acceptedCategories.includes('external_media') || acceptedServices.includes('cms_feed');
    }

    function initConsentGuard() {
        const consentRequiredPage = document.querySelector('[data-feed-consent-required="1"]');

        window.addEventListener('cms-cookie-consent-change', function(event) {
            const consentGranted = hasFeedConsent(event.detail);
            if ((consentRequiredPage && consentGranted) || (!consentRequiredPage && !consentGranted)) {
                window.location.reload();
            }
        });
    }

    // Init
    document.addEventListener('DOMContentLoaded', function() {
        initLazyImages();
        initImageErrorHandler();
        initSearchEsc();
        initConsentGuard();
    });

})();
