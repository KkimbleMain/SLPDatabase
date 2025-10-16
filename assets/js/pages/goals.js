// Page module: goals
import { apiFetch } from '../api.js';
import { showNotification, closeModal, insertModal } from '../ui.js';

console.log('Page module: goals loaded');

export function showAddGoalModal(studentId) {
    // Prefer server-provided template `tmpl-add-goal` to avoid duplicating modal HTML in JS.
    const tpl = document.getElementById('tmpl-add-goal');
    if (!tpl) {
        console.warn('tmpl-add-goal template not found; cannot show goal modal.');
        return null;
    }

    // Clone the template content and prefill the student select if provided
    const fragment = tpl.content.cloneNode(true);
    const select = fragment.querySelector('#goalStudent') || fragment.querySelector('select[name="student_id"]');
    if (select && studentId) {
        // select option if present
        const opt = Array.from(select.options).find(o => String(o.value) === String(studentId));
        if (opt) opt.selected = true;
    } else if (studentId) {
        // no select in template (unexpected) — append hidden input to the form
        const form = fragment.querySelector('form');
        if (form) {
            const hidden = document.createElement('input');
            hidden.type = 'hidden'; hidden.name = 'student_id'; hidden.value = String(studentId);
            form.appendChild(hidden);
        }
    }

    // Insert the fragment using the shared helper which ensures modal/backdrop behavior
    const wrapper = document.createElement('div');
    wrapper.appendChild(fragment);
    const modalEl = insertModal(wrapper);

    // attach the submit handler to the modal-local form
    if (modalEl) {
        const form = modalEl.querySelector('#addGoalForm');
        if (form) form.addEventListener('submit', submitGoalForm);
    }
    return modalEl;
}

async function submitGoalForm(e) {
    e.preventDefault();
    const form = e.target;
    const fd = new FormData(form);
    // server expects 'description' field for goal text
    if (!fd.get('description') && fd.get('goal_text')) fd.set('description', fd.get('goal_text'));
    fd.append('action', 'add_goal');

    try {
        showNotification('Adding goal...', 'info');
        const result = await apiFetch('/includes/submit.php', { method: 'POST', body: fd });
        if (result && result.success) {
            showNotification('Goal added successfully!', 'success');
            const modal = e.target.closest('.modal');
            if (modal) modal.remove();
            // Try to update dashboard counts via global cache without full reload
            try {
                if (window.SLPCache && typeof window.SLPCache.incGoals === 'function') {
                    window.SLPCache.incGoals(1);
                } else {
                    // fallback to reload to ensure other UI updates
                    setTimeout(() => location.reload(), 700);
                }
            } catch (e) {
                setTimeout(() => location.reload(), 700);
            }
        } else {
            showNotification((result && result.error) ? result.error : 'Failed to add goal', 'error');
            // allow retry by leaving modal open
        }
    } catch (err) {
        showNotification('Network error: ' + err.message, 'error');
    }

}

function init() {
    document.querySelectorAll('.add-goal-btn').forEach(b => {
        b.addEventListener('click', (e) => {
            const sid = b.getAttribute('data-student-id');
            if (!sid) {
                // open generic goal modal
                showAddGoalModal();
                return;
            }
            showAddGoalModal(sid);
        });
    });
}

init();

export default { init, showAddGoalModal };
