/**
 * PhotoSwipe V5 – Automatische Lightbox für Bilder
 *
 * Scannt alle Bilder in .post-content, .page-content und [data-photoswipe]
 * und aktiviert die PhotoSwipe-Lightbox für Klick-Events.
 *
 * Einbindung (im Theme-Footer):
 *   <link rel="stylesheet" href="/assets/photoswipe/photoswipe.css">
 *   <script type="module" src="/assets/js/photoswipe-init.js"></script>
 *
 * @package CMSv2\Frontend
 */
(async function () {
    'use strict';

    // Als ES-Modul geladen: document.currentScript ist hier immer null.
    const photoswipeBase = new URL('../photoswipe/', import.meta.url).href;

    // Release-Prüfung 4.1.0 (PERF, cwv:2): Erst Container und Bilder suchen, dann laden. Seiten ohne passende
    // Bilder laden keines der beiden Module (zusammen rund 68 KB). Verlinkte Bilder (z. B. Link-Vorschauen)
    // bleiben Links und werden nicht in ein zweites <a> verschachtelt.
    const containers = Array.from(document.querySelectorAll('.post-content, .page-content, [data-photoswipe]'))
        .filter(container => !container.parentElement || !container.parentElement.closest('.post-content, .page-content, [data-photoswipe]'));
    const isLightboxImage = img => !img.closest('.no-lightbox') && (!img.closest('a') || img.closest('a[data-pswp-src]') !== null);
    const galleries = containers.filter(container => Array.from(container.querySelectorAll('img')).some(isLightboxImage));
    if (galleries.length === 0) return;

    // Release-Prüfung 4.1.0 (PERF, cwv:2): Stylesheet nur bei Bedarf nachladen, falls der Server es nicht schon ausgibt.
    if (!document.querySelector('link[rel="stylesheet"][href*="photoswipe.css"]')) {
        const stylesheet = document.createElement('link');
        stylesheet.rel = 'stylesheet';
        stylesheet.href = photoswipeBase + 'photoswipe.css';
        document.head.appendChild(stylesheet);
    }

    // Release-Prüfung 4.1.0 (PERF, cwv:2): Nur die kleine Lightbox sofort laden, den PhotoSwipe-Kern (54 KB) erst beim ersten Klick.
    const { default: PhotoSwipeLightbox } = await import(photoswipeBase + 'photoswipe-lightbox.esm.min.js');
    const loadPhotoSwipeCore = () => import(photoswipeBase + 'photoswipe.esm.min.js');

    galleries.forEach(container => {
        const images = Array.from(container.querySelectorAll('img')).filter(isLightboxImage);
        if (images.length === 0) return;

        // Wrap each image in <a> if not already wrapped
        images.forEach((img, index) => {
            if (img.closest('a[data-pswp-src]')) return;

            const src = img.dataset.fullSrc || img.src;
            const wrapper = document.createElement('a');
            wrapper.href = src;
            wrapper.setAttribute('data-pswp-src', src);
            wrapper.setAttribute('data-pswp-width', img.naturalWidth || 1200);
            wrapper.setAttribute('data-pswp-height', img.naturalHeight || 800);
            wrapper.style.cursor = 'zoom-in';

            // Load actual dimensions after image is loaded
            if (!img.complete) {
                img.addEventListener('load', () => {
                    wrapper.setAttribute('data-pswp-width', img.naturalWidth || 1200);
                    wrapper.setAttribute('data-pswp-height', img.naturalHeight || 800);
                });
            }

            // <picture> (AVIF/WebP-Quellen, seit 4.1.0) als Ganzes umschließen: Die <source>-Elemente wirken nur,
            // solange das <img> direktes Kind von <picture> bleibt.
            const target = img.parentElement && img.parentElement.tagName === 'PICTURE' ? img.parentElement : img;
            target.parentNode.insertBefore(wrapper, target);
            wrapper.appendChild(target);
        });

        // Initialize PhotoSwipe lightbox for this container
        const lightbox = new PhotoSwipeLightbox({
            gallery: container,
            children: 'a[data-pswp-src]',
            pswpModule: loadPhotoSwipeCore,
            bgOpacity: 0.9,
            padding: { top: 20, bottom: 20, left: 20, right: 20 },
            showHideAnimationType: 'zoom'
        });

        lightbox.init();
    });
})();
