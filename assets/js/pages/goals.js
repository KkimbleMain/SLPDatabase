// Page module: goals
import { apiFetch } from '../api.js';
import { showNotification, closeModal, insertModal } from '../ui.js';

console.log('Page module: goals loaded');

export function showAddGoalModal(studentId) {
    const modalHtml = `
        <div class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Add New Goal</h2>
                    <button type="button" class="close">&times;</button>
                </div>
                <form id="addGoalForm" class="modal-form">
                    <input type="hidden" name="student_id" value="${studentId || ''}">
                    <div class="form-group">
                        <label for="goalArea">Goal Area *</label>
                        <select id="goalArea" name="goal_area" required>
                            <option value="">Select area...</option>
                            <option value="articulation">Articulation</option>
                            <option value="language">Language</option>
                            <option value="fluency">Fluency</option>
                            <option value="voice">Voice</option>
                            <option value="pragmatics">Social/Pragmatics</option>
                            <option value="oral_motor">Oral Motor</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="goalText">Goal Description *</label>
                        <textarea id="goalText" name="goal_text" rows="3" required placeholder="Describe the specific goal and criteria for success..."></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="baselineScore">Baseline Score (%)</label>
                            <input type="number" id="baselineScore" name="baseline_score" min="0" max="100" value="0">
                        </div>
                        <div class="form-group">
                            <label for="targetScore">Target Score (%) *</label>
                            <input type="number" id="targetScore" name="target_score" min="1" max="100" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="targetDate">Target Date</label>
                        <input type="date" id="targetDate" name="target_date">
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-outline cancel">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Goal</button>
                    </div>
                </form>
            </div>
        </div>
    `;

    // build element and insert via insertModal (expects an element)
    const wrapper = document.createElement('div');
    wrapper.innerHTML = modalHtml.trim();
    const modalEl = wrapper.firstElementChild;
    if (!modalEl) return null;

    // insertModal will attach close handlers and backdrop; do not attach manual removers here
    insertModal(modalEl);

    const form = modalEl.querySelector('#addGoalForm');
    if (form) form.addEventListener('submit', submitGoalForm);
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
            setTimeout(() => location.reload(), 700);
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
