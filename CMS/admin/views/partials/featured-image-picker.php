<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$pickerModalId = (string)($pickerModalId ?? 'featuredImagePickerModal');
$pickerOpenButtonId = (string)($pickerOpenButtonId ?? 'featuredImageBtn');
$pickerInputId = (string)($pickerInputId ?? 'featuredImageInput');
$pickerPreviewContainerId = (string)($pickerPreviewContainerId ?? 'featuredImagePreview');
$pickerRemoveButtonId = (string)($pickerRemoveButtonId ?? 'featuredImageRemove');
$pickerEmptyStateId = (string)($pickerEmptyStateId ?? 'featuredImageEmpty');
$pickerTitleInputId = (string)($pickerTitleInputId ?? 'title');
$pickerSlugInputId = (string)($pickerSlugInputId ?? 'slug');
$pickerDialogTitle = (string)($pickerDialogTitle ?? 'Bild auswählen');
$pickerToken = (string)($editorMediaToken ?? '');
$pickerIsNew = isset($pickerIsNew) ? (bool)$pickerIsNew : true;
$pickerContentType = (string)($pickerContentType ?? 'post'); // 'post' | 'page'
$pickerFilenamePrefix = (string)($pickerFilenamePrefix ?? 'ArtikelRahmen');
?>

<div class="modal modal-blur fade" id="<?= htmlspecialchars($pickerModalId) ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= htmlspecialchars($pickerDialogTitle) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <div class="featured-picker__toolbar mb-3">
                    <div class="featured-picker__upload">
                        <button type="button" class="btn btn-outline-primary btn-sm" data-role="featured-picker-upload-button">Bild hochladen</button>
                        <input type="file" class="d-none" aria-label="Bild hochladen" tabindex="-1" accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.ico,image/jpeg,image/png,image/gif,image/webp,image/bmp,image/x-bmp,image/x-icon,image/vnd.microsoft.icon" data-role="featured-picker-upload">
                    </div>
                    <div class="featured-picker__search">
                        <input type="search" class="form-control form-control-sm" aria-label="Bilder filtern" placeholder="Bilder filtern …" data-role="featured-picker-search">
                    </div>
                </div>

                <div class="featured-picker__status text-secondary small mb-3" data-role="featured-picker-status">
                    Lade Bilder …
                </div>

                <div class="featured-picker__grid" data-role="featured-picker-grid"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Schließen</button>
            </div>
        </div>
    </div>
</div>

<script <?= \CMS\Security::instance()->nonceAttr() ?>>
(function() {
    var modalEl = document.getElementById(<?= json_encode($pickerModalId) ?>);
    var openBtn = document.getElementById(<?= json_encode($pickerOpenButtonId) ?>);
    var inputEl = document.getElementById(<?= json_encode($pickerInputId) ?>);
    var previewEl = document.getElementById(<?= json_encode($pickerPreviewContainerId) ?>);
    var removeBtn = document.getElementById(<?= json_encode($pickerRemoveButtonId) ?>);
    var emptyEl = document.getElementById(<?= json_encode($pickerEmptyStateId) ?>);
    var titleInput = document.getElementById(<?= json_encode($pickerTitleInputId) ?>);
    var slugInput = document.getElementById(<?= json_encode($pickerSlugInputId) ?>);
    var token = <?= json_encode($pickerToken) ?>;
    var apiUrl = <?= json_encode('/api/media') ?>;
    var pickerIsNew = <?= $pickerIsNew ? 'true' : 'false' ?>;
    var pickerContentType = <?= json_encode($pickerContentType) ?>;
    var pickerFilenamePrefix = <?= json_encode($pickerFilenamePrefix) ?>;
    var pickerTempPathInputId = <?= json_encode($pickerInputId . '_temp_path') ?>;
    var tempPathEl = document.getElementById(pickerTempPathInputId);

    if (!modalEl || !openBtn || !inputEl || !previewEl || !apiUrl) {
        return;
    }

    var gridEl = modalEl.querySelector('[data-role="featured-picker-grid"]');
    var statusEl = modalEl.querySelector('[data-role="featured-picker-status"]');
    var searchEl = modalEl.querySelector('[data-role="featured-picker-search"]');
    var uploadButton = modalEl.querySelector('[data-role="featured-picker-upload-button"]');
    var uploadEl = modalEl.querySelector('[data-role="featured-picker-upload"]');
    var modalInstance = typeof bootstrap !== 'undefined' && bootstrap.Modal
        ? bootstrap.Modal.getOrCreateInstance(modalEl)
        : null;
    var allItems = [];

    function cleanupModalArtifacts() {
        modalEl.classList.remove('show');
        modalEl.style.display = 'none';
        modalEl.setAttribute('aria-hidden', 'true');
        modalEl.removeAttribute('aria-modal');

        document.querySelectorAll('.modal-backdrop[data-featured-picker-backdrop="1"]').forEach(function(backdrop) {
            backdrop.remove();
        });

        if (!document.querySelector('.modal.show')) {
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
        }
    }

    function showModal() {
        if (modalInstance) {
            modalInstance.show();
            return;
        }

        modalEl.classList.add('show');
        modalEl.style.display = 'block';
        modalEl.removeAttribute('aria-hidden');
        modalEl.setAttribute('aria-modal', 'true');
        document.body.classList.add('modal-open');

        var backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        backdrop.setAttribute('data-featured-picker-backdrop', '1');
        document.body.appendChild(backdrop);
    }

    function hideModal() {
        if (modalInstance) {
            modalInstance.hide();
            window.setTimeout(cleanupModalArtifacts, 250);
            return;
        }

        cleanupModalArtifacts();
    }

    function clearChildren(node) {
        if (!node) {
            return;
        }

        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    function createPreviewImage(url) {
        var image = document.createElement('img');
        image.src = String(url || '');
        image.alt = '';
        image.className = 'rounded mb-2';
        image.style.maxWidth = '100%';
        image.style.maxHeight = '120px';
        image.style.objectFit = 'cover';
        image.style.display = 'block';

        return image;
    }

    function createGridItem(item) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'featured-picker__item';
        button.dataset.url = String(item && item.url ? item.url : '');

        var thumb = document.createElement('span');
        thumb.className = 'featured-picker__thumb';

        var image = document.createElement('img');
        image.src = String(item && item.url ? item.url : '');
        image.alt = String(item && item.name ? item.name : 'Bild');
        image.loading = 'lazy';
        thumb.appendChild(image);

        var meta = document.createElement('span');
        meta.className = 'featured-picker__meta';

        var name = document.createElement('span');
        name.className = 'featured-picker__name';
        name.textContent = String(item && item.name ? item.name : 'Bild');

        var path = document.createElement('span');
        path.className = 'featured-picker__path';
        path.textContent = String(item && item.path ? item.path : '');

        meta.appendChild(name);
        meta.appendChild(path);
        button.appendChild(thumb);
        button.appendChild(meta);

        return button;
    }

    function slugify(value) {
        return String(value || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 80);
    }

    function getPreferredSlug() {
        var directSlug = slugInput && slugInput.value ? slugify(slugInput.value) : '';
        if (directSlug) {
            return directSlug;
        }

        var titleSlug = titleInput && titleInput.value ? slugify(titleInput.value) : '';
        return titleSlug || 'artikelbild';
    }

    function updatePreview(url, tempPath) {
        inputEl.value = String(url || '');
        if (tempPathEl) {
            tempPathEl.value = String(tempPath || '');
        }
        clearChildren(previewEl);
        previewEl.appendChild(createPreviewImage(url));
        previewEl.classList.remove('d-none');
        if (emptyEl) {
            emptyEl.classList.add('d-none');
        }
        if (removeBtn) {
            removeBtn.classList.remove('d-none');
        }
    }

    function renderItems(items) {
        if (!gridEl || !statusEl) {
            return;
        }

        if (!Array.isArray(items) || items.length === 0) {
            clearChildren(gridEl);
            statusEl.textContent = pickerFilenamePrefix
                ? 'Keine passenden Coverbilder gefunden.'
                : 'Keine Bilder gefunden.';
            return;
        }

        statusEl.textContent = items.length + (items.length === 1 ? ' Bild gefunden' : ' Bilder gefunden');
        clearChildren(gridEl);
        items.forEach(function(item) {
            gridEl.appendChild(createGridItem(item || {}));
        });
    }

    function filterItems() {
        var query = searchEl ? String(searchEl.value || '').trim().toLowerCase() : '';
        if (!query) {
            renderItems(allItems);
            return;
        }

        renderItems(allItems.filter(function(item) {
            return String(item.name || '').toLowerCase().includes(query)
                || String(item.path || '').toLowerCase().includes(query);
        }));
    }

    function loadItems() {
        if (!statusEl) {
            return;
        }

        statusEl.textContent = 'Lade Bilder …';
        var listUrl = apiUrl + '?action=list_images';
        if (pickerFilenamePrefix) {
            listUrl += '&filename_prefix=' + encodeURIComponent(pickerFilenamePrefix);
        }

        fetch(listUrl, {
            method: 'GET',
            headers: {
                'X-CSRF-Token': token
            },
            credentials: 'same-origin'
        }).then(function(response) {
            return response.json();
        }).then(function(payload) {
            allItems = Array.isArray(payload.items) ? payload.items : [];
            filterItems();
        }).catch(function(error) {
            console.error('Featured image list error:', error);
            statusEl.textContent = 'Bilder konnten nicht geladen werden.';
        });
    }

    function uploadImage(file) {
        if (!file || !statusEl) {
            return;
        }

        statusEl.textContent = 'Lade Bild hoch …';
        var formData = new FormData();
        formData.append('action', 'upload_featured');
        formData.append('image', file);
        formData.append('slug', getPreferredSlug());
        formData.append('is_new', pickerIsNew ? '1' : '0');
        formData.append('content_type', pickerContentType);

        fetch(apiUrl, {
            method: 'POST',
            headers: {
                'X-CSRF-Token': token
            },
            body: formData,
            credentials: 'same-origin'
        }).then(function(response) {
            return response.json();
        }).then(function(payload) {
            if (!payload || Number(payload.success) !== 1 || !payload.file || !payload.file.url) {
                throw new Error(payload && payload.message ? payload.message : 'Upload fehlgeschlagen.');
            }

            updatePreview(payload.file.url, payload.temp_path || '');
            hideModal();
            loadItems();
        }).catch(function(error) {
            console.error('Featured image upload error:', error);
            statusEl.textContent = error && error.message ? error.message : 'Upload fehlgeschlagen.';
        }).finally(function() {
            if (uploadEl) {
                uploadEl.value = '';
            }
        });
    }

    openBtn.addEventListener('click', function(event) {
        event.preventDefault();
        event.stopPropagation();

        window.setTimeout(function() {
            showModal();
            loadItems();
        }, 40);
    });

    modalEl.addEventListener('click', function(event) {
        if (event.target === modalEl) {
            hideModal();
        }
    });

    modalEl.querySelectorAll('[data-bs-dismiss="modal"]').forEach(function(button) {
        button.addEventListener('click', function(event) {
            event.preventDefault();
            hideModal();
        });
    });

    modalEl.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            hideModal();
        }
    });

    modalEl.addEventListener('hidden.bs.modal', cleanupModalArtifacts);

    gridEl && gridEl.addEventListener('click', function(event) {
        var button = event.target.closest('.featured-picker__item');
        if (!button) {
            return;
        }

        var url = button.getAttribute('data-url') || '';
        if (!url) {
            return;
        }

        updatePreview(url, '');
        hideModal();
    });

    searchEl && searchEl.addEventListener('input', filterItems);

    uploadButton && uploadButton.addEventListener('click', function(event) {
        event.preventDefault();
        event.stopPropagation();

        if (uploadEl) {
            uploadEl.click();
        }
    });

    uploadEl && uploadEl.addEventListener('change', function() {
        var file = this.files && this.files[0] ? this.files[0] : null;
        if (file) {
            uploadImage(file);
        }
    });
})();
</script>
