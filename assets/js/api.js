
// Minimal API helper to centralize AJAX calls used by other frontend modules.
async function apiFetch(path, options = {}) {
	const headers = options.headers || {};
	if (!headers['Content-Type'] && !(options.body instanceof FormData)) {
		headers['Content-Type'] = 'application/json';
	}
	try {
		const res = await fetch(path, Object.assign({ credentials: 'same-origin', headers }, options));
		if (!res.ok) {
			const text = await res.text();
			throw new Error(text || res.statusText);
		}
		const contentType = res.headers.get('content-type') || '';
		if (contentType.includes('application/json')) return res.json();
		return res.text();
	} catch (err) {
		console.error('apiFetch error', err);
		throw err;
	}
}

export { apiFetch };
