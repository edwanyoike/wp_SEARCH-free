/* assets/js/admin.js — Turbo Search settings-page controller.
 * Config (nonces, i18n, initial state) is injected as `wcsAdmin` via
 * wp_add_inline_script(); `ajaxurl` is the wp-admin global. */
(function () {
	if (typeof wcsAdmin === 'undefined') return;

	// ── Notice dismissal ────────────────────────────────────────────────────
	// WordPress adds .notice-dismiss buttons dynamically; fire-and-forget the
	// persistence call — WP core JS already removes the DOM node on click.
	document.addEventListener('click', function (e) {
		const btn = e.target.closest('.notice-dismiss');
		if (!btn) return;
		const notice = btn.closest('[data-wcs-notice]');
		if (!notice) return;

		const body = new URLSearchParams();
		body.append('action', 'wcs_dismiss_notice');
		body.append('notice_id', notice.getAttribute('data-wcs-notice'));
		body.append('_wpnonce', wcsAdmin.nonces.dismiss);
		fetch(ajaxurl, { method: 'POST', body: body });
	});

	// App Data tab — independent of the Settings tab's statusWrapper guard
	// below, since the two live on different tabs and are never both present.
	function initAppDataTab() {
		const deleteBtn     = document.getElementById('wcs-delete-data-btn');
		const deleteSpinner = document.getElementById('wcs-delete-spinner');
		if (!deleteBtn) return;

		const i18n = wcsAdmin.i18n;

		deleteBtn.addEventListener('click', function (e) {
			e.preventDefault();
			if (!confirm(i18n.confirmDelete)) return;

			deleteBtn.disabled = true;
			if (deleteSpinner) deleteSpinner.classList.add('is-active');

			fetch(ajaxurl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams({
					action: 'wcs_delete_all_data',
					_ajax_nonce: wcsAdmin.nonces.delete
				})
			}).then(res => res.json()).then(data => {
				if (data.success) {
					window.location.reload();
				} else {
					alert(i18n.errDelete);
					deleteBtn.disabled = false;
					if (deleteSpinner) deleteSpinner.classList.remove('is-active');
				}
			}).catch(() => {
				alert(i18n.errDelete);
				deleteBtn.disabled = false;
				if (deleteSpinner) deleteSpinner.classList.remove('is-active');
			});
		});
	}

	function init() {
		initAppDataTab();

		const btn             = document.getElementById('wcs-rebuild-btn');
		const spinner         = document.getElementById('wcs-rebuild-spinner');
		const statusWrapper   = document.getElementById('wcs-status-wrapper');
		const progressWrapper = document.getElementById('wcs-progress-wrapper');
		const errorWrapper    = document.getElementById('wcs-rebuild-error');
		if (!statusWrapper) return; // App Data/Docs tab — nothing else to control.

		const i18n = wcsAdmin.i18n;
		const errorLabels = wcsAdmin.errorLabels || {};
		let pollingInterval = null;

		function showRebuildError(code) {
			if (!errorWrapper) return;
			if (!code) {
				errorWrapper.style.display = 'none';
				errorWrapper.textContent = '';
				return;
			}
			errorWrapper.textContent = errorLabels[code] || code;
			errorWrapper.style.display = '';
		}

		function fmtProgress(processed, total) {
			return i18n.progress.replace('%1$d', processed).replace('%2$d', total);
		}

		// textContent (not innerHTML) — labels may contain arbitrary translations.
		function setStatus(label, color) {
			statusWrapper.textContent = '';
			const span = document.createElement('span');
			span.style.color = color;
			span.style.fontWeight = 'bold';
			span.textContent = label;
			statusWrapper.appendChild(span);
		}

		function phaseLabel(data) {
			if (data.recovering) {
				return i18n.recovering.replace('%d', data.cursor);
			}
			if (data.stall_secs >= 180) {
				return i18n.timedOut.replace('%d', Math.max(0, 300 - data.stall_secs));
			}
			switch (data.phase) {
				case 'swapping':   return i18n.swapping;
				case 'optimizing': return i18n.optimizing;
				default:           return i18n.indexing;
			}
		}

		function startPolling() {
			if (!pollingInterval) {
				pollingInterval = setInterval(checkStatus, 2000);
			}
		}

		function checkStatus() {
			fetch(ajaxurl + '?action=wcs_get_index_status&_ajax_nonce=' + encodeURIComponent(wcsAdmin.nonces.status))
				.then(res => res.json())
				.then(response => {
					if (!response.success) return;
					const data = response.data;
					const processed = parseInt(data.processed, 10);
					const total = parseInt(data.total, 10);
					progressWrapper.textContent = fmtProgress(processed, total);
					if (data.is_indexing) {
						setStatus(phaseLabel(data), '#d63638');
						showRebuildError(''); // only relevant once idle
						if (btn) btn.disabled = true;
						if (spinner) spinner.classList.add('is-active');
					} else {
						setStatus(i18n.idle, '#00a32a');
						showRebuildError(data.last_error);
						if (btn) btn.disabled = false;
						if (spinner) spinner.classList.remove('is-active');
						if (pollingInterval) {
							clearInterval(pollingInterval);
							pollingInterval = null;
						}
					}
				})
				.catch(() => {
					// Transient network error — keep polling; next tick may succeed.
				});
		}

		if (wcsAdmin.isIndexing) {
			startPolling();
		}

		if (btn) {
			btn.addEventListener('click', function (e) {
				e.preventDefault();
				if (!confirm(i18n.confirmRebuild)) return;

				btn.disabled = true;
				spinner.classList.add('is-active');

				fetch(ajaxurl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: new URLSearchParams({
						action: 'wcs_rebuild_index',
						_ajax_nonce: wcsAdmin.nonces.rebuild
					})
				}).then(res => res.json()).then(data => {
					if (data.success) {
						setStatus(i18n.indexing, '#d63638');
						showRebuildError('');
						startPolling();
					} else {
						alert(i18n.errRebuild);
						btn.disabled = false;
						spinner.classList.remove('is-active');
					}
				}).catch(() => {
					alert(i18n.errRebuild);
					btn.disabled = false;
					spinner.classList.remove('is-active');
				});
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
