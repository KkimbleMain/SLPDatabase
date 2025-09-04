// Minimal UI helpers: modal rendering, notifications, and small utilities.
import { apiFetch } from './api.js';

let __slp_lastEsc = null;

function getTemplateContent(id) {
	const tpl = document.getElementById(id);
	if (!tpl) return null;
	return tpl.content ? tpl.content.cloneNode(true) : null;
}

function openModal(el) {
	// Delegate to insertModal to ensure deduplication and consistent behavior
	return insertModal(el);
}

// compatibility helper expected by some modules: insert a modal element into the DOM
function insertModal(node) {
	// Remove any existing modal/backdrop to avoid stacked overlays
	document.querySelectorAll('.modal, .modal-backdrop').forEach(el => el.remove());

	// Resolve modal element from fragment or element
	let modalEl = null;
	if (node instanceof DocumentFragment) {
		const first = node.firstElementChild;
		if (first && first.classList && first.classList.contains('modal')) {
			modalEl = first;
		} else {
			modalEl = document.createElement('div');
			modalEl.className = 'modal';
			const inner = document.createElement('div');
			inner.className = 'modal-content';
			while (node.firstChild) inner.appendChild(node.firstChild);
			modalEl.appendChild(inner);
		}
	} else if (node instanceof Element) {
		modalEl = node;
		if (!modalEl.classList.contains('modal')) {
			const wrapper = document.createElement('div');
			wrapper.className = 'modal';
			wrapper.appendChild(modalEl);
			modalEl = wrapper;
		}
	} else {
		console.error('insertModal: expected Element or DocumentFragment');
		return null;
	}

	// Create single backdrop
	const backdrop = document.createElement('div');
	backdrop.className = 'modal-backdrop';
	backdrop.addEventListener('click', () => closeModal());

	// Append to body
	document.body.appendChild(backdrop);
	document.body.appendChild(modalEl);

	// Delegated submit handler: intercept Add Student form submissions when page module
	// hasn't attached its handler (fallback for portability).
	async function handleAddStudentSubmit(e) {
		try {
			const form = e.target;
			if (!(form instanceof HTMLFormElement)) return;
			// Heuristic: if form contains first_name & last_name fields, treat as Add Student
			// BUT skip if this looks like an edit form (has a student id or 'edit' in id)
			if (form.querySelector('[name="first_name"]') && form.querySelector('[name="last_name"]')) {
				if (form.querySelector('[name="student_id"]') || (form.id && form.id.toLowerCase().includes('edit'))) {
					return; // let the page/module handle update_student
				}
				// If another handler already started processing this form, skip to avoid duplicate submissions
				if (form.dataset.slpHandled) return;
				e.preventDefault();
				// mark as handled immediately to prevent other handlers from also submitting
				form.dataset.slpHandled = '1';
				showNotification('Adding student...', 'info');
				const fd = new FormData(form);
				fd.append('action', 'add_student');
				// include CSRF token if present
				const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
				if (csrf) fd.append('csrf_token', csrf);

				try {
					const result = await apiFetch('/includes/submit.php', { method: 'POST', body: fd });
					if (result && result.success) {
						showNotification('Student added successfully!', 'success');
						const student = Object.fromEntries(fd.entries());
						student.id = result.id;
						// Update selects
						try { if (window.SLPDatabase && typeof window.SLPDatabase.addStudentToSelects === 'function') window.SLPDatabase.addStudentToSelects(student); else if (typeof addStudentToSelects === 'function') addStudentToSelects(student); } catch (ignore) {}
						setTimeout(() => { window.location.href = '?view=students'; }, 700);
					} else {
						showNotification((result && result.error) || 'Failed to add student', 'error');
						// allow retry if server returned an error
						try { delete form.dataset.slpHandled; } catch (ignore) {}
					}
				} catch (err) {
					console.error('Network error when adding student (fallback):', err);
					showNotification('Network error: ' + err.message, 'error');
					// allow retry on network error
					try { delete form.dataset.slpHandled; } catch (ignore) {}
				}
			}
		} catch (err) { console.error('modal submit handler error', err); }
	}

	// Attach a modal-local listener and a non-capturing document listener.
	// The page-level handler (students.js) will run first; both will check form.dataset.slpHandled to avoid duplicates.
+ 
	modalEl.addEventListener('submit', handleAddStudentSubmit);
+    // non-capturing listener to avoid intercepting before in-document handlers; rely on dataset flag to prevent dupes
	document.addEventListener('submit', function(e){ handleAddStudentSubmit(e); }, false);

	// focus first input
	const firstInput = modalEl.querySelector('input,select,textarea,button');
	if (firstInput) firstInput.focus();

	// Attach single-close handlers for any .close buttons and cancel buttons
	modalEl.querySelectorAll('.close, .modal-actions button[type="button"]').forEach(btn => {
		// add circle class to close buttons for styling
		if (btn.classList && btn.classList.contains('close')) btn.classList.add('circle');
		// ensure we don't attach multiple identical handlers
		if (!btn.dataset.slpCloseBound) {
			btn.addEventListener('click', () => closeModal());
			btn.dataset.slpCloseBound = '1';
		}
	});

	// Escape key to close (ensure single binder)
	if (__slp_lastEsc) document.removeEventListener('keydown', __slp_lastEsc);
	__slp_lastEsc = (e) => { if (e.key === 'Escape') closeModal(); };
	document.addEventListener('keydown', __slp_lastEsc);

	return modalEl;
}

function closeModal(modalEl) {
	// Remove modal and backdrop
	document.querySelectorAll('.modal, .modal-backdrop').forEach(el => el.remove());
	// Remove escape handler
	if (__slp_lastEsc) {
		document.removeEventListener('keydown', __slp_lastEsc);
		__slp_lastEsc = null;
	}
}

function closeNearestModal(btn) {
	let el = btn;
	while (el && !el.classList?.contains('modal')) {
		el = el.parentNode;
	}
	if (el) closeModal(el);
}

function showModalFromTemplate(tmplId) {
	const content = getTemplateContent(tmplId);
	if (!content) return null;
	const wrapper = document.createElement('div');
	// if template root is a .modal, append it directly
	const nodes = content.children.length ? Array.from(content.children) : Array.from(content.childNodes);
	if (nodes.length === 1 && nodes[0].classList && nodes[0].classList.contains('modal')) {
		const modal = nodes[0];
		openModal(modal);
		return modal;
	}

	// otherwise append a modal wrapper
	wrapper.className = 'modal';
	const inner = document.createElement('div');
	inner.className = 'modal-content';
	inner.appendChild(content);
	wrapper.appendChild(inner);
	openModal(wrapper);
	return wrapper;
}

function showAddStudentModal() { return showModalFromTemplate('tmpl-add-student'); }
function showAddGoalModal() { return showModalFromTemplate('tmpl-add-goal') || null; }
function showAddProgressModal() { return showModalFromTemplate('tmpl-add-progress') || null; }
function showQuickProgressModal() { return showModalFromTemplate('tmpl-quick-progress') || null; }
function showRegisterModal() { return showModalFromTemplate('tmpl-register') || null; }

function showNotification(message, type = 'info', timeout = 3500) {
	const n = document.createElement('div');
	n.className = 'notification show notification-' + (type || 'info');
	n.innerHTML = `<div class="notification-content"><div class="notification-message">${message}</div><button class="notification-close">×</button></div>`;
	document.body.appendChild(n);
	n.querySelector('.notification-close')?.addEventListener('click', () => n.remove());
	if (timeout > 0) setTimeout(() => n.remove(), timeout);
	return n;
}

function addStudentToSelects(student) {
	// find selects that have data-student-select attribute and append option
	const selects = document.querySelectorAll('select[data-student-select]');
	selects.forEach(s => {
		const opt = document.createElement('option');
		opt.value = student.id;
		opt.text = (student.first_name || '') + ' ' + (student.last_name || '');
		s.appendChild(opt);
	});
}

// Backwards-compatible global namespace for other modules
if (typeof window !== 'undefined') {
	window.SLPDatabase = window.SLPDatabase || {};
	if (!window.SLPDatabase.addStudentToSelects) {
		window.SLPDatabase.addStudentToSelects = addStudentToSelects;
	}
}

// expose helpers globally for other scripts that assume globals
window.showAddStudentModal = window.showAddStudentModal || showAddStudentModal;
window.showAddGoalModal = window.showAddGoalModal || showAddGoalModal;
window.showAddProgressModal = window.showAddProgressModal || showAddProgressModal;
window.showQuickProgressModal = window.showQuickProgressModal || showQuickProgressModal;
window.showRegisterModal = window.showRegisterModal || showRegisterModal;
window.showNotification = window.showNotification || showNotification;
window.closeModal = window.closeModal || closeModal;
window.addStudentToSelects = window.addStudentToSelects || addStudentToSelects;
window.closeNearestModal = window.closeNearestModal || closeNearestModal;
window.insertModal = window.insertModal || insertModal;

export {
	showAddStudentModal,
	showAddGoalModal,
	showAddProgressModal,
	showQuickProgressModal,
	showRegisterModal,
	showNotification,
	closeModal,
	insertModal,
	addStudentToSelects,
	closeNearestModal
};
