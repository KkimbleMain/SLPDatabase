import { apiFetch } from './api.js';
import { insertModal, showNotification } from './ui.js';

export function showRegisterModal() {
    const tmpl = document.getElementById('tmpl-register');
    if (!tmpl) return console.error('register template missing');
    const clone = tmpl.content.firstElementChild.cloneNode(true);
    const modalId = 'registerModal-' + Date.now();
    clone.id = modalId;

    // namespace ids
    clone.querySelectorAll('[id]').forEach(el => {
        const old = el.id; el.id = `${old}-${modalId}`;
    });

    // attach submit
    const form = clone.querySelector('form');
    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(form);
            const obj = Object.fromEntries(fd.entries());
            try {
                showNotification('Creating account...', 'info');
                const res = await apiFetch('api/users.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(obj)
                });
                const data = await res.json();
                if (res.ok) {
                    showNotification('Account created. You may now log in.', 'success');
                    // close modal
                    const root = document.getElementById(modalId);
                    if (root) root.remove();
                } else {
                    showNotification(data.error || 'Failed to create account', 'error');
                }
            } catch (err) {
                showNotification('Network error: ' + err.message, 'error');
            }
        });
    }

    insertModal(clone);
}

// expose globally
window.showRegisterModal = showRegisterModal;
export default showRegisterModal;
