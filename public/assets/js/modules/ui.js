/**
 * Generic progressive-enhancement helpers, opt-in via data attributes so
 * any page markup can use them without importing anything itself:
 *
 *   <a href="/admin/users/9" data-confirm="Delete this user?">Delete</a>
 *   <button type="button" data-toggle="#details">Show details</button>
 */

function initConfirm(root = document) {
    root.addEventListener('click', (event) => {
        const el = event.target.closest('[data-confirm]');
        if (!el) {
            return;
        }
        if (!window.confirm(el.dataset.confirm)) {
            event.preventDefault();
            event.stopPropagation();
        }
    });
}

function initToggle(root = document) {
    root.addEventListener('click', (event) => {
        const el = event.target.closest('[data-toggle]');
        if (!el) {
            return;
        }
        const target = document.querySelector(el.dataset.toggle);
        if (target) {
            target.hidden = !target.hidden;
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initConfirm();
    initToggle();
});
