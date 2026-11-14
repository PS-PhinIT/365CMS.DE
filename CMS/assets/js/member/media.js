/**
 * Dialoge „Umbenennen“ und „Verschieben“ der Medienverwaltung (/member/media).
 */

export function init(root = document) {
    const configElement = root.getElementById('member-media-config');
    if (!configElement) {
        return;
    }

    let config = {};
    try {
        config = JSON.parse(configElement.textContent || '{}');
    } catch (error) {
        return;
    }

    const byId = (id, fallback) => root.getElementById(config[id] || fallback);
    const currentPath = config.currentPath || '';
    const renameModal = byId('renameModalId', 'memberMediaRenameModal');
    const renamePath = byId('renamePathFieldId', 'memberMediaRenamePath');
    const renameName = byId('renameNameFieldId', 'memberMediaRenameName');
    const renameLabel = byId('renameLabelId', 'memberMediaRenameLabel');
    const moveModal = byId('moveModalId', 'memberMediaMoveModal');
    const movePath = byId('movePathFieldId', 'memberMediaMovePath');
    const moveTarget = byId('moveTargetFieldId', 'memberMediaMoveTarget');
    const moveLabel = byId('moveLabelId', 'memberMediaMoveLabel');

    // Fallback, falls show.bs.modal kein relatedTarget liefert (Auslöser in einem schließenden Dropdown).
    let pendingRename = null;
    let pendingMove = null;
    root.querySelectorAll('.js-member-media-open-rename').forEach((button) => {
        button.addEventListener('click', () => { pendingRename = button; });
    });
    root.querySelectorAll('.js-member-media-open-move').forEach((button) => {
        button.addEventListener('click', () => { pendingMove = button; });
    });

    if (renameModal) {
        renameModal.addEventListener('show.bs.modal', (event) => {
            const trigger = event.relatedTarget || pendingRename;
            pendingRename = null;
            if (!trigger || !renamePath || !renameName) {
                return;
            }
            const name = trigger.getAttribute('data-media-name') || '';
            renamePath.value = trigger.getAttribute('data-media-path') || '';
            renameName.value = name;
            if (renameLabel) {
                renameLabel.textContent = name;
            }
        });
        renameModal.addEventListener('shown.bs.modal', () => {
            if (renameName) {
                renameName.focus();
                renameName.select();
            }
        });
    }

    if (moveModal) {
        moveModal.addEventListener('show.bs.modal', (event) => {
            const trigger = event.relatedTarget || pendingMove;
            pendingMove = null;
            if (!trigger || !movePath || !moveTarget) {
                return;
            }
            const target = trigger.getAttribute('data-media-target') || currentPath;
            movePath.value = trigger.getAttribute('data-media-path') || '';
            moveTarget.value = target;
            if (moveTarget.value !== target && moveTarget.options.length > 0) {
                moveTarget.selectedIndex = 0;
            }
            if (moveLabel) {
                moveLabel.textContent = trigger.getAttribute('data-media-name') || '';
            }
        });
        moveModal.addEventListener('shown.bs.modal', () => {
            if (moveTarget) {
                moveTarget.focus();
            }
        });
    }
}
