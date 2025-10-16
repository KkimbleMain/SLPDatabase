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

	// (no id namespacing here) Keep original ids so existing modal-scoped selectors continue to work.

	// Prevent body layout shift by locking scroll and compensating for scrollbar width if present
	const _body = document.body;
	const previousOverflow = _body.style.overflow || '';
	const previousPaddingRight = _body.style.paddingRight || '';
	const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
	if (scrollbarWidth > 0) {
		_body.style.paddingRight = (parseFloat(previousPaddingRight) || 0) + scrollbarWidth + 'px';
	}
	_body.style.overflow = 'hidden';

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
						// Refresh recent activity so dashboard shows the new student immediately
						try { if (typeof window.refreshRecentActivity === 'function') window.refreshRecentActivity(20); } catch (e) { /* ignore */ }
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

	// Attach a modal-local listener only. Page/module-level handlers should manage most form submissions.
	// Keep the modal-local submit handler as a fallback when a page module did not attach its own handler.
	modalEl.addEventListener('submit', handleAddStudentSubmit);

	// focus first input
	const firstInput = modalEl.querySelector('input,select,textarea,button');
	if (firstInput) firstInput.focus();

	// Attach single-close handlers for any .close buttons and cancel buttons
	// Ensure there is always a close button: if template author omitted one, inject a fallback
	if (!modalEl.querySelector('.close')) {
		const header = modalEl.querySelector('.modal-header');
		const fallbackClose = document.createElement('button');
		fallbackClose.type = 'button';
		fallbackClose.className = 'close circle';
		fallbackClose.setAttribute('aria-label', 'Close');
		fallbackClose.innerHTML = '&times;';
		if (header) {
			header.appendChild(fallbackClose);
		} else {
			// insert at top of modal-content so it's visible
			const contentRoot = modalEl.querySelector('.modal-content') || modalEl;
			contentRoot.insertBefore(fallbackClose, contentRoot.firstChild);
		}
	}

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
	// Restore body overflow and padding
	try {
		const _body = document.body;
		_body.style.overflow = '';
		_body.style.paddingRight = '';
	} catch (e) { /* ignore */ }
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
function showRegisterModal() { return showModalFromTemplate('tmpl-register') || null; }

// Promise-based modal confirm helper using the tmpl-confirm-modal template.
// message: string, options: { title?: string, confirmText?: string, cancelText?: string }
function showConfirm(message, options = {}) {
	return new Promise((resolve) => {
		const content = getTemplateContent('tmpl-confirm-modal');
		if (!content) {
			// fallback to native confirm
			return resolve(Boolean(window.confirm(message)));
		}

		// Clone and insert modal
		const clone = content.firstElementChild ? content.firstElementChild.cloneNode(true) : null;
		if (!clone) return resolve(Boolean(window.confirm(message)));

		// Set title/message and button labels
		try {
			const titleEl = clone.querySelector('#confirmModalTitle');
			const msgEl = clone.querySelector('#confirmModalMessage');
			const cancelBtn = clone.querySelector('#confirmModalCancel');
			const confirmBtn = clone.querySelector('#confirmModalConfirm');
			if (titleEl && options.title) titleEl.textContent = options.title;
			if (msgEl) msgEl.textContent = message || options.message || '';
			if (cancelBtn && options.cancelText) cancelBtn.textContent = options.cancelText;
			if (confirmBtn && options.confirmText) confirmBtn.textContent = options.confirmText;
		} catch (e) { /* ignore */ }

		insertModal(clone);

		const modalRoot = document.getElementById('confirmModalTitle')?.closest('.modal') || document.querySelector('.modal');
		if (!modalRoot) { return resolve(Boolean(window.confirm(message))); }

		// Handlers
		const cleanup = () => {
			try { modalRoot.remove(); } catch (e) {}
			// ensure any backdrop also removed
			try { document.querySelectorAll('.modal-backdrop').forEach(n=>n.remove()); } catch (e) {}
		};

		const onCancel = (e) => { e && e.preventDefault(); cleanup(); resolve(false); };
		const onConfirm = (e) => { e && e.preventDefault(); cleanup(); resolve(true); };

		const cancelBtn = modalRoot.querySelector('#confirmModalCancel');
		const confirmBtn = modalRoot.querySelector('#confirmModalConfirm');
		const closeBtns = modalRoot.querySelectorAll('.close');

		if (cancelBtn) cancelBtn.addEventListener('click', onCancel, { once: true });
		if (confirmBtn) confirmBtn.addEventListener('click', onConfirm, { once: true });
		closeBtns.forEach(b => b.addEventListener('click', onCancel, { once: true }));

		// keyboard handling: Esc -> cancel, Enter -> confirm
		const keyHandler = (ev) => {
			if (ev.key === 'Escape') { onCancel(); document.removeEventListener('keydown', keyHandler); }
			if (ev.key === 'Enter') { onConfirm(); document.removeEventListener('keydown', keyHandler); }
		};
		document.addEventListener('keydown', keyHandler);
	});
}

// Promise-based typed confirmation modal.
// expectedText: string that must be typed (case-insensitive by default)
// options: { title?, message?, placeholder?, caseSensitive?: boolean }
function showTypedConfirm(expectedText, options = {}) {
	return new Promise((resolve) => {
		const content = getTemplateContent('tmpl-typed-confirm-modal');
		if (!content) {
			// fallback to prompt
			try {
				const typed = window.prompt(options.message || ('Type ' + expectedText + ' to confirm'), '');
				return resolve(Boolean(typed && typed.toString().trim().toUpperCase() === expectedText.toString().trim().toUpperCase()));
			} catch (e) { return resolve(false); }
		}

		const clone = content.firstElementChild ? content.firstElementChild.cloneNode(true) : null;
		if (!clone) return resolve(false);

		// set texts
		try {
			const titleEl = clone.querySelector('#typedConfirmTitle');
			const msgEl = clone.querySelector('#typedConfirmMessage');
			const inputEl = clone.querySelector('#typedConfirmInput');
			const cancelBtn = clone.querySelector('#typedConfirmCancel');
			const confirmBtn = clone.querySelector('#typedConfirmConfirm');
			if (titleEl && options.title) titleEl.textContent = options.title;
			if (msgEl) msgEl.textContent = options.message || ('Please type "' + expectedText + '" to confirm.');
			if (inputEl && options.placeholder) inputEl.placeholder = options.placeholder;
			// clear input
			if (inputEl) inputEl.value = '';
		} catch (e) { /* ignore */ }

		insertModal(clone);
		const modalRoot = document.getElementById('typedConfirmTitle')?.closest('.modal') || document.querySelector('.modal');
		if (!modalRoot) return resolve(false);

		const inputEl = modalRoot.querySelector('#typedConfirmInput');
		const cancelBtn = modalRoot.querySelector('#typedConfirmCancel');
		const confirmBtn = modalRoot.querySelector('#typedConfirmConfirm');
		const closeBtns = modalRoot.querySelectorAll('.close');

		const cleanup = () => { try { modalRoot.remove(); } catch (e) {} try { document.querySelectorAll('.modal-backdrop').forEach(n=>n.remove()); } catch (e) {} };

		const onCancel = (e) => { e && e.preventDefault(); cleanup(); resolve(false); };
		const onConfirm = (e) => {
			e && e.preventDefault();
			try {
				const val = inputEl ? (inputEl.value || '').toString() : '';
				const match = options.caseSensitive ? (val === expectedText) : (val.trim().toUpperCase() === expectedText.toString().trim().toUpperCase());
				cleanup();
				resolve(Boolean(match));
			} catch (err) { cleanup(); resolve(false); }
		};

		if (cancelBtn) cancelBtn.addEventListener('click', onCancel, { once: true });
		if (confirmBtn) confirmBtn.addEventListener('click', onConfirm, { once: true });
		closeBtns.forEach(b => b.addEventListener('click', onCancel, { once: true }));

		// Enter key triggers confirm
		const keyHandler = (ev) => {
			if (ev.key === 'Escape') { onCancel(); document.removeEventListener('keydown', keyHandler); }
			if (ev.key === 'Enter') { onConfirm(); document.removeEventListener('keydown', keyHandler); }
		};
		document.addEventListener('keydown', keyHandler);

		// focus input
		if (inputEl) setTimeout(() => inputEl.focus(), 50);
	});
}
// Register modal handler (moved from assets/js/register.js) — uses apiFetch to create account
function showRegisterModalFromTemplate() {
	const tmpl = document.getElementById('tmpl-register');
	if (!tmpl) return console.error('register template missing');
	const clone = tmpl.content.firstElementChild.cloneNode(true);
	const modalId = 'registerModal-' + Date.now();
	clone.id = modalId;

	// namespace ids (avoid collisions when multiple modals are open)
	clone.querySelectorAll('[id]').forEach(el => {
		const old = el.id; el.id = `${old}-${modalId}`;
	});
	// update label 'for' attributes to match namespaced ids
	clone.querySelectorAll('label[for]').forEach(l => {
		const f = l.getAttribute('for'); if (f) l.setAttribute('for', f + '-' + modalId);
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
				// if apiFetch returned JSON object, treat as success payload
				showNotification('Account created. You may now log in.', 'success');
				// close modal
				const root = document.getElementById(modalId);
				if (root) root.remove();
			} catch (err) {
				showNotification(err && err.message ? err.message : 'Failed to create account', 'error');
			}
		});
	}

	insertModal(clone);
}

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
// Prefer the implementation that wires the submit handler to call the API
window.showRegisterModal = showRegisterModalFromTemplate;
window.showNotification = window.showNotification || showNotification;
window.closeModal = window.closeModal || closeModal;
window.addStudentToSelects = window.addStudentToSelects || addStudentToSelects;
window.closeNearestModal = window.closeNearestModal || closeNearestModal;
// Also expose the register modal implementation (compatibility with prior register.js)
// Ensure the global points to the handler-backed implementation
window.showRegisterModal = showRegisterModalFromTemplate;
window.insertModal = window.insertModal || insertModal;
window.showConfirm = window.showConfirm || showConfirm;
window.showTypedConfirm = window.showTypedConfirm || showTypedConfirm;

export {
	showAddStudentModal,
	showAddGoalModal,
	showRegisterModal,
	showNotification,
	showConfirm,
	showTypedConfirm,
	closeModal,
	insertModal,
	addStudentToSelects,
	closeNearestModal
};
