// Page module: progress
import { apiFetch } from '../api.js';
import { showNotification, closeModal, insertModal } from '../ui.js';

console.log('Page module: progress loaded');

export function showAddProgressModal(studentId, goalId) {
    const modalHtml = `
        <div class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Add Progress Update</h2>
                    <button type="button" class="close">&times;</button>
                </div>
                <form id="addProgressForm" class="modal-form">
                    <input type="hidden" name="student_id" value="${studentId || ''}">
                    <input type="hidden" name="goal_id" value="${goalId || ''}">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="sessionDate">Session Date *</label>
                            <input type="date" id="sessionDate" name="date_recorded" value="${new Date().toISOString().split('T')[0]}" required>
                        </div>
                        <div class="form-group">
                            <label for="score">Score (%) *</label>
                            <input type="number" id="score" name="score" min="0" max="100" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="sessionType">Session Type</label>
                        <select id="sessionType" name="session_type">
                            <option value="individual">Individual</option>
                            <option value="group">Group</option>
                            <option value="classroom">Classroom</option>
                            <option value="consultation">Consultation</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="notes">Session Notes</label>
                        <textarea id="notes" name="notes" rows="4" placeholder="Describe progress, behaviors, strategies used..."></textarea>
                    </div>
                    <div class="form-group">
                        <label for="strategiesUsed">Strategies Used</label>
                        <textarea id="strategiesUsed" name="strategies_used" rows="2" placeholder="Therapeutic techniques and methods..."></textarea>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-outline cancel">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Progress</button>
                    </div>
                </form>
            </div>
        </div>
    `;

    const wrapper = document.createElement('div');
    wrapper.innerHTML = modalHtml.trim();
    const modalEl = wrapper.firstElementChild;
    if (!modalEl) return null;

    // Do not attach manual close handlers here; insertModal will attach them and add the circular styling
    insertModal(modalEl);

    const form = modalEl.querySelector('#addProgressForm');
    if (form) form.addEventListener('submit', submitProgressForm);
    return modalEl;
}

async function submitProgressForm(e) {
    e.preventDefault();
    const form = e.target;
    const fd = new FormData(form);
    fd.append('action', 'add_progress');
    try {
        showNotification('Saving progress...', 'info');
        const result = await apiFetch('/includes/submit.php', { method: 'POST', body: fd });
        if (result && result.success) {
            showNotification('Progress saved successfully!', 'success');
            const modal = e.target.closest('.modal');
            if (modal) modal.remove();
            setTimeout(() => location.reload(), 700);
        } else {
            showNotification((result && result.error) ? result.error : 'Failed to save progress', 'error');
        }
    } catch (err) {
        showNotification('Network error: ' + err.message, 'error');
    }
}

function init() {
    document.querySelectorAll('.add-progress-btn, .add-progress-btn a').forEach(b => {
        b.addEventListener('click', (e) => {
            const sid = b.getAttribute('data-student-id') || new URLSearchParams(window.location.search).get('student_id');
            const gid = b.getAttribute('data-goal-id');
            showAddProgressModal(sid, gid);
        });
    });
}

init();

export function showQuickProgressModal(studentId) {
    // prefer the canonical showAddProgressModal if available
    try {
        if (typeof showAddProgressModal === 'function') return showAddProgressModal(studentId);
    } catch (e) { /* continue to fallback */ }

    // fallback: open template-based modal if template exists
    const tpl = document.getElementById('tmpl-add-progress');
    if (!tpl) return null;

    const clone = tpl.content.cloneNode(true);
    const container = document.createElement('div');
    container.appendChild(clone);
    insertModal(container);

    if (studentId) {
        const sel = container.querySelector('select[name="student_id"]');
        if (sel) sel.value = studentId;
        const hid = container.querySelector('input[name="student_id"]');
        if (hid) hid.value = studentId;
    }

    if (window.attachModalHandlers) window.attachModalHandlers(container);
    return container;
}

export default { init, showAddProgressModal, showQuickProgressModal };
