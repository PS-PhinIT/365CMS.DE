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

    const [{ default: PhotoSwipeLightbox }, { default: PhotoSwipe }] = await Promise.all([
        import(photoswipeBase + 'photoswipe-lightbox.esm.min.js'),
        import(photoswipeBase + 'photoswipe.esm.min.js')
    ]);

    const containers = document.querySelectorAll('.post-content, .page-content, [data-photoswipe]');
    if (containers.length === 0) return;

    containers.forEach(container => {
        const images = container.querySelectorAll('img');
        if (images.length === 0) return;

        // Wrap each image in <a> if not already wrapped
        images.forEach((img, index) => {
            if (img.closest('a[data-pswp-src]')) return;
            if (img.closest('.no-lightbox')) return;

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
            pswpModule: PhotoSwipe,
            bgOpacity: 0.9,
            padding: { top: 20, bottom: 20, left: 20, right: 20 },
            showHideAnimationType: 'zoom'
        });

        lightbox.init();
    });
})();
