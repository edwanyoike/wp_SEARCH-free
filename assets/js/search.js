/* assets/js/search.js */
(function() {
	if (typeof wcs_config === 'undefined') return;

	let cache = new Map();
	let controller = null;
	// Monotonic token: each performSearch() call claims the next value, and a
	// response only renders if it still holds the latest token. Without this a
	// slow network response for an older keystroke can arrive after a newer
	// (e.g. cache-served) query already rendered, overwriting fresh results.
	let searchSeq = 0;
	const portal = document.getElementById('wcs-dropdown-portal');

	if (!portal) return;

	// Create dropdown element
	const dropdown = document.createElement('div');
	dropdown.className = 'wcs-dropdown';
	dropdown.id = 'wcs-listbox';
	dropdown.setAttribute('role', 'listbox');
	portal.appendChild(dropdown);

	// Match any input[name="s"] — standard WooCommerce uses type="search",
	// Woodmart uses type="text" with class="s", other themes vary. The form-level
	// post_type=product check below is the real gate.
	const searchInputs = document.querySelectorAll('input[name="s"]');

	let activeInput = null;
	let activeIndex = -1;

	searchInputs.forEach(input => {
		// Only attach to WooCommerce product search forms.
		const form = input.closest('form');
		if (!form) return;

		const postType = form.querySelector('input[name="post_type"]');
		if (!postType || postType.value !== 'product') return;

		// Woodmart (and similar themes) have their own AJAX search attached to the
		// "woodmart-ajax-search" class. Remove it so both dropdowns don't fire at
		// the same time — ours replaces theirs. The hidden results wrapper is also
		// hidden via CSS since Woodmart may still inject empty markup into it.
		form.classList.remove('woodmart-ajax-search');
		const wdResultsWrapper = form.querySelector('.wd-search-results-wrapper');
		if (wdResultsWrapper) wdResultsWrapper.style.display = 'none';

		input.setAttribute('autocomplete', 'off');
		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-expanded', 'false');
		input.setAttribute('aria-autocomplete', 'list');
		input.setAttribute('aria-controls', 'wcs-listbox');

		input.addEventListener('input', debounce((e) => {
			activeInput = input;
			const query = e.target.value.trim();
			const minChars = parseInt(wcs_config.min_chars, 10) || 2;
			if (query.length < minChars) {
				hideDropdown();
				return;
			}
			performSearch(query, input);
		}, 250));

		input.addEventListener('focus', () => {
			activeInput = input;
			const minChars = parseInt(wcs_config.min_chars, 10) || 2;
			if (input.value.trim().length >= minChars && dropdown.children.length > 0) {
				positionDropdown(input);
				showDropdown();
			}
		});

		input.addEventListener('keydown', (e) => {
			if (!dropdown.classList.contains('is-active')) return;

			const items = dropdown.querySelectorAll('.wcs-result-item');
			if (items.length === 0) return;

			if (e.key === 'ArrowDown') {
				e.preventDefault();
				activeIndex = (activeIndex + 1) % items.length;
				highlightItem(items);
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				activeIndex = (activeIndex - 1 + items.length) % items.length;
				highlightItem(items);
			} else if (e.key === 'Enter') {
				if (activeIndex >= 0 && activeIndex < items.length) {
					e.preventDefault();
					items[activeIndex].click();
				}
			} else if (e.key === 'Escape') {
				e.preventDefault();
				hideDropdown();
			}
		});
	});

	// Single document-level click listener shared across all inputs. Attaching
	// one per input caused earlier inputs to close the dropdown when a later
	// input was clicked (each closure captured its own `input` variable).
	document.addEventListener('click', (e) => {
		if (activeInput && !activeInput.contains(e.target) && !dropdown.contains(e.target)) {
			hideDropdown();
		}
	});

	// Reposition the dropdown on resize so it stays aligned with its input.
	// Throttled to one call per animation frame to avoid layout thrash.
	let repositionPending = false;
	function scheduleReposition() {
		if (repositionPending || !dropdown.classList.contains('is-active') || !activeInput) return;
		repositionPending = true;
		requestAnimationFrame(() => {
			repositionPending = false;
			if (activeInput && dropdown.classList.contains('is-active')) {
				positionDropdown(activeInput);
			}
		});
	}
	window.addEventListener('resize', scheduleReposition, { passive: true });

	// True when some ancestor (e.g. a sticky/fixed theme header) takes the
	// input out of normal document flow. Cheap: reading the `position`
	// property from computed style — unlike getBoundingClientRect() — does
	// not force a synchronous layout, so this is safe to call on every
	// scroll tick. It is NOT cached: a sticky header's position toggles
	// live between "static" and "fixed" as the page crosses its stick
	// threshold, so a stale answer would silently mis-position the dropdown.
	function isViewportAnchored(el) {
		let node = el;
		while (node && node !== document.body) {
			const position = getComputedStyle(node).position;
			if (position === 'fixed' || position === 'sticky') return true;
			node = node.parentElement;
		}
		return false;
	}

	// A plain in-flow input needs no scroll handling at all: the dropdown
	// portal is position:absolute with no positioned ancestor (see
	// inject_dropdown_container()), so it's already anchored to document
	// coordinates and tracks an ordinary page scroll for free.
	//
	// An input pinned by a fixed/sticky header is different but, once
	// correctly positioned, *also* needs no per-frame tracking: a
	// position:fixed input does not move relative to the viewport while the
	// page scrolls — that's what position:fixed means — so switching the
	// dropdown itself to position:fixed (see positionDropdown()) makes it
	// track for free too, via CSS, with zero JS. The only moment either
	// needs a recompute is the instant the header's own stuck/unstuck state
	// flips. That's a single event, not a per-pixel one, so this listener
	// is debounced to fire once shortly after scrolling settles rather than
	// on every animation frame — avoiding the forced-synchronous-layout
	// storm (one getBoundingClientRect() per scroll frame) that otherwise
	// visibly janks scrolling on image-heavy catalog pages, and which a
	// plain rAF throttle does not fix since it still fires every frame.
	let scrollSettleTimer = null;
	window.addEventListener('scroll', () => {
		if (!activeInput || !dropdown.classList.contains('is-active') || !isViewportAnchored(activeInput)) return;
		clearTimeout(scrollSettleTimer);
		scrollSettleTimer = setTimeout(() => {
			if (activeInput && dropdown.classList.contains('is-active')) {
				positionDropdown(activeInput);
			}
		}, 150);
	}, { passive: true, capture: true });

	function showDropdown() {
		dropdown.classList.add('is-active');
		if (activeInput) {
			activeInput.setAttribute('aria-expanded', 'true');
		}
	}

	function hideDropdown() {
		dropdown.classList.remove('is-active');
		activeIndex = -1;
		if (activeInput) {
			activeInput.setAttribute('aria-expanded', 'false');
			activeInput.removeAttribute('aria-activedescendant');
		}
	}

	function highlightItem(items) {
		items.forEach((item, index) => {
			if (index === activeIndex) {
				item.classList.add('wcs-highlighted');
				item.setAttribute('aria-selected', 'true');
				item.scrollIntoView({ block: 'nearest' });
				if (activeInput) {
					activeInput.setAttribute('aria-activedescendant', item.id);
				}
			} else {
				item.classList.remove('wcs-highlighted');
				item.setAttribute('aria-selected', 'false');
			}
		});
	}

	function debounce(func, wait) {
		let timeout;
		return function executedFunction(...args) {
			const later = () => {
				clearTimeout(timeout);
				func(...args);
			};
			clearTimeout(timeout);
			timeout = setTimeout(later, wait);
		};
	}

	function positionDropdown(input) {
		const rect = input.getBoundingClientRect();
		if (isViewportAnchored(input)) {
			// Viewport coordinates only, no scroll offset — matches
			// position:fixed, which is what keeps this glued to the input
			// with no further JS as the page scrolls.
			dropdown.style.position = 'fixed';
			dropdown.style.top  = (rect.bottom + 5) + 'px';
			dropdown.style.left = rect.left + 'px';
		} else {
			dropdown.style.position = 'absolute';
			dropdown.style.top  = (rect.bottom + window.scrollY + 5) + 'px';
			dropdown.style.left = (rect.left + window.scrollX) + 'px';
		}
		dropdown.style.width = rect.width + 'px';
	}

	/**
	 * Read a single cookie value by name, URL-decoding it.
	 * Returns null when the cookie is absent.
	 */
	function readCookie(name) {
		const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
		const match   = document.cookie.match(new RegExp('(?:^|;\\s*)' + escaped + '=([^;]*)'));
		return match ? decodeURIComponent(match[1]) : null;
	}

	/**
	 * Return the ISO-4217 currency code that is active right now.
	 *
	 * Checks the cookies written by the four most common WooCommerce
	 * multi-currency switcher plugins in priority order. Falls back to the
	 * code baked into wcs_config at page load (the store default).
	 *
	 * This must be called at search time — not once at init — so that a
	 * mid-session currency switch is picked up without a page reload.
	 */
	function getActiveCurrency() {
		const cookieNames = [
			'wmc_current_currency',          // Villatheme CURCY / WooMultiCurrency
			'woocs_current_currency',        // WOOCS — WooCommerce Currency Switcher
			'woocommerce_current_currency',  // Official WooCommerce Multi-Currency
			'_wpml_active_currency',         // WPML / WooCommerce Multilingual
		];

		for (const name of cookieNames) {
			const val = readCookie(name);
			if (val) {
				// Sanitize: uppercase letters only, exactly 3 characters (ISO-4217).
				const code = val.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 3);
				if (code.length === 3) return code;
			}
		}

		return wcs_config.currency.code;
	}

	/**
	 * Format a numeric price for display.
	 *
	 * For the store-default currency: uses WooCommerce's admin-configured
	 * symbol, separators, decimal count, and position (from wcs_config).
	 *
	 * For any other currency (e.g. after a mid-session switcher change):
	 * delegates to the browser's Intl.NumberFormat, which knows the correct
	 * symbol, decimal count, and grouping for every ISO-4217 currency.
	 * This avoids showing a KES symbol on USD amounts when the visitor
	 * switches currency without a full page reload.
	 *
	 * @param {number|string} value        Raw numeric price.
	 * @param {string}        currencyCode Active ISO-4217 currency code.
	 * @returns {string}
	 */
	function formatPrice(value, currencyCode) {
		const parsedVal = parseFloat(value);
		if (isNaN(parsedVal)) return '';

		// Default currency — use WooCommerce's exact admin formatting.
		if (!currencyCode || currencyCode === wcs_config.currency.code) {
			const decimals    = wcs_config.currency.decimals != null ? wcs_config.currency.decimals : 2;
			const decimalSep  = wcs_config.currency.decimal_sep  || '.';
			const thousandSep = wcs_config.currency.thousand_sep || ',';
			const symbol      = wcs_config.currency.symbol       || '$';
			const position    = wcs_config.currency.position     || 'left';

			let parts = parsedVal.toFixed(decimals).split('.');
			parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandSep);
			const formattedNum = parts.join(decimalSep);

			switch (position) {
				case 'right':       return formattedNum + symbol;
				case 'left_space':  return symbol + ' ' + formattedNum;
				case 'right_space': return formattedNum + ' ' + symbol;
				default:            return symbol + formattedNum;
			}
		}

		// Non-default currency (mid-session switcher) — use browser Intl API.
		// Intl.NumberFormat knows the correct symbol and decimal places for
		// every ISO-4217 code without any server-side data.
		try {
			return new Intl.NumberFormat(undefined, {
				style:    'currency',
				currency: currencyCode,
			}).format(parsedVal);
		} catch (_) {
			// Unrecognised currency code — show plain number + ISO code.
			return parsedVal.toFixed(2) + ' ' + currencyCode;
		}
	}

	/**
	 * Fetch search results for the given query.
	 *
	 * The active currency is read from switcher cookies at call time so that
	 * a mid-session currency change is picked up immediately without a reload.
	 * The in-memory cache is keyed by "query\0currency" so KES and USD results
	 * for the same term are stored and served independently.
	 */
	function performSearch(query, input) {
		const currency = getActiveCurrency();
		const cacheKey = query + '\x00' + currency;
		const seq = ++searchSeq;

		if (cache.has(cacheKey)) {
			// Abort any in-flight older request so its late response cannot
			// overwrite these newer, cache-served results.
			if (controller) {
				controller.abort();
				controller = null;
			}
			renderResults(cache.get(cacheKey), input, currency, query);
			return;
		}

		if (controller) {
			controller.abort();
		}
		controller = new AbortController();

		doSearch(query, currency, false)
			.then(data => {
				// Never cache "index still building" responses — results should
				// appear the moment the build finishes.
				if (!data.__indexing) {
					cache.set(cacheKey, data);
					if (cache.size > 100) {
						cache.delete(cache.keys().next().value);
					}
				}
				if (seq !== searchSeq) return; // A newer search already rendered.
				renderResults(data, input, currency, query);
			})
			.catch(err => {
				if (err.name !== 'AbortError') {
					console.error(`WCS Search [v${wcs_config.version}] Error:`, err);
				}
			});
	}

	/**
	 * Issue the search API request and return parsed JSON.
	 *
	 * On a 403 (expired nonce — common after leaving a tab open longer than
	 * 12 hours) the function fetches a fresh nonce and retries exactly once
	 * so the visitor never has to reload the page to restore search.
	 *
	 * @param {string}  query    Sanitized search term.
	 * @param {string}  currency Active ISO-4217 currency code.
	 * @param {boolean} isRetry  True on the single automatic retry (prevents loops).
	 * @returns {Promise<Array>}
	 */
	function doSearch(query, currency, isRetry) {
		const url = new URL(wcs_config.api_url);
		url.searchParams.append('q', query);
		url.searchParams.append('_wpnonce', wcs_config.nonce);
		url.searchParams.append('currency', currency);

		return fetch(url, { signal: controller.signal })
			.then(res => {
				if (403 === res.status && !isRetry) {
					return refreshNonce().then(() => doSearch(query, currency, true));
				}
				if (!res.ok) throw new Error('Network response was not ok');
				// First-run signal: the index is still being built, so an empty
				// array means "not ready yet", not "no matching products".
				const indexing = res.headers.get('X-WCS-Indexing') === '1';
				// Set when server-side typo correction silently substituted a
				// different query — the corrected words, not what the shopper
				// typed, are what actually appear in the result text, so
				// highlighting must key off this instead of the raw input.
				const corrected = res.headers.get('X-WCS-Corrected-Query');
				return res.json().then(data => {
					if (indexing && Array.isArray(data)) {
						data.__indexing = true;
					}
					if (corrected && Array.isArray(data)) {
						data.__corrected = corrected;
					}
					return data;
				});
			});
	}

	/**
	 * Fetch a fresh wp_rest nonce and update wcs_config.nonce in place.
	 * Subsequent requests — including the in-flight retry — will use the new value.
	 *
	 * @returns {Promise<void>}
	 */
	function refreshNonce() {
		return fetch(wcs_config.nonce_refresh_url)
			.then(res => res.json())
			.then(data => {
				if (data.success && data.data && data.data.nonce) {
					wcs_config.nonce = data.data.nonce;
				}
			});
	}

	/**
	 * Append `text` to `el` as text nodes, wrapping each occurrence of any
	 * word in `queryWords` in a <mark>. Built entirely from textContent /
	 * createElement / createTextNode — never innerHTML — so arbitrary text
	 * (including a compromised wcs_indexed_product_data filter's output)
	 * can never inject markup.
	 *
	 * @param {HTMLElement} el         Container to fill.
	 * @param {string}      text       Plain text to render.
	 * @param {string[]}    queryWords Words to highlight (already non-empty).
	 */
	function appendHighlighted(el, text, queryWords) {
		if (!queryWords.length || !text) {
			el.appendChild(document.createTextNode(text));
			return;
		}

		const escaped = queryWords
			.map(w => w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
			.sort((a, b) => b.length - a.length); // longest first so "necklace" isn't swallowed by a shorter overlapping match
		const re = new RegExp('(' + escaped.join('|') + ')', 'ig');

		let lastIndex = 0;
		let match;
		while ((match = re.exec(text)) !== null) {
			if (match.index > lastIndex) {
				el.appendChild(document.createTextNode(text.slice(lastIndex, match.index)));
			}
			const mark = document.createElement('mark');
			mark.className = 'wcs-highlight';
			mark.textContent = match[0];
			el.appendChild(mark);
			lastIndex = match.index + match[0].length;
			if (0 === match[0].length) {
				re.lastIndex++; // guard against zero-length matches looping forever
			}
		}
		if (lastIndex < text.length) {
			el.appendChild(document.createTextNode(text.slice(lastIndex)));
		}
	}

	function renderResults(results, input, currency, query) {
		// Highlight the words the server actually matched. When typo
		// correction silently substituted a different query (e.g. "necklase"
		// -> "necklace"), the corrected form is what appears in the result
		// text — highlighting against the raw typo would never match anything.
		const highlightSource = (results && results.__corrected) || query;
		const queryWords = (highlightSource || '').trim().split(/\s+/).filter(Boolean);

		requestAnimationFrame(() => {
			dropdown.innerHTML = '';
			activeIndex = -1;

			if (results.length === 0) {
				const noResultsDiv = document.createElement('div');
				noResultsDiv.className = 'wcs-no-results';
				noResultsDiv.textContent = results.__indexing
					? (wcs_config.i18n.index_building || wcs_config.i18n.no_results)
					: wcs_config.i18n.no_results;
				dropdown.appendChild(noResultsDiv);
			} else {
				// Tell the shopper when we silently corrected their query —
				// otherwise a misspelling like "kayo" showing "kato" results
				// looks like broken search, not a helpful correction.
				if (results.__corrected && results.__corrected !== query) {
					const notice = document.createElement('div');
					notice.className = 'wcs-corrected-notice';
					const template = wcs_config.i18n.showingResultsFor || 'Showing results for "%s"';
					const parts = template.split('%s');
					notice.appendChild(document.createTextNode(parts[0] || ''));
					const strong = document.createElement('strong');
					strong.textContent = results.__corrected;
					notice.appendChild(strong);
					if (parts[1]) notice.appendChild(document.createTextNode(parts[1]));
					dropdown.appendChild(notice);
				}

				results.forEach((item, index) => {
					const a = document.createElement('a');
					a.className = 'wcs-result-item';
					a.id = 'wcs-option-' + index;
					a.setAttribute('role', 'option');
					a.setAttribute('aria-selected', 'false');

					// Validate URL schemes to mitigate XSS
					let safeUrl = '#';
					try {
						const parsedUrl = new URL(item.permalink, window.location.origin);
						if (['http:', 'https:'].includes(parsedUrl.protocol)) {
							safeUrl = parsedUrl.href;
						}
					} catch(e) {}
					a.href = safeUrl;

					// Category / brand suggestion rows — no image or price.
					if (item.type === 'taxonomy') {
						a.className += ' wcs-result-tax';
						const label = document.createElement('span');
						label.className = 'wcs-result-title';
						label.textContent = item.title;
						const badge = document.createElement('span');
						badge.className = 'wcs-badge-tax';
						const kind = item.taxonomy === 'product_brand'
							? (wcs_config.i18n.brand || 'Brand')
							: (wcs_config.i18n.category || 'Category');
						badge.textContent = item.count > 1
							? kind + ' · ' + (wcs_config.i18n.products_count || '%d products').replace('%d', item.count)
							: kind;
						a.appendChild(label);
						a.appendChild(badge);
						dropdown.appendChild(a);
						return;
					}

					const img = document.createElement('img');
					img.className = 'wcs-result-img';
					img.alt = '';
					img.loading = 'lazy';

					let safeImgUrl = '';
					try {
						const parsedImgUrl = new URL(item.image_url, window.location.origin);
						if (['http:', 'https:'].includes(parsedImgUrl.protocol)) {
							safeImgUrl = parsedImgUrl.href;
						}
					} catch(e) {}
					// Neutral inline placeholder when the product has no image —
					// independent of the active theme, so it never goes stale.
					img.src = safeImgUrl || 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(
						'<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48"><rect width="48" height="48" fill="#f0f0f1"/><path d="M14 32l7-9 5 6 4-4 4 7z" fill="#c3c4c7"/><circle cx="19" cy="17" r="3" fill="#c3c4c7"/></svg>'
					);

					const info = document.createElement('div');
					info.className = 'wcs-result-info';

					const title = document.createElement('span');
					title.className = 'wcs-result-title';
					appendHighlighted(title, item.title, queryWords);

					const meta = document.createElement('div');
					meta.className = 'wcs-result-meta';

					const price = document.createElement('span');
					price.className = 'wcs-result-price';

					let priceStr = '';
					const pMin = parseFloat(item.price_min);
					const pMax = parseFloat(item.price_max);
					if (pMin > 0) {
						if (pMin !== pMax) {
							priceStr = formatPrice(pMin, currency) + ' - ' + formatPrice(pMax, currency);
						} else {
							priceStr = formatPrice(pMin, currency);
						}
					}
					price.textContent = priceStr;
					meta.appendChild(price);

					if (item.stock_status !== 'instock') {
						const oosBadge = document.createElement('span');
						oosBadge.className = 'wcs-badge-oos';
						oosBadge.textContent = wcs_config.i18n.out_of_stock;
						meta.appendChild(oosBadge);
					}

					info.appendChild(title);
					info.appendChild(meta);

					if (item.excerpt) {
						const excerpt = document.createElement('span');
						excerpt.className = 'wcs-result-excerpt';
						appendHighlighted(excerpt, item.excerpt, queryWords);
						info.appendChild(excerpt);
					}

					a.appendChild(img);
					a.appendChild(info);
					dropdown.appendChild(a);
				});

				// "View all results" footer — always points at the standard
				// WooCommerce search results page, which works regardless of
				// permalink structure since ?s= is a query var, not a rewrite rule.
				// This branch only runs when results.length > 0 (see the empty
				// check above), so it always has a query worth linking to. Uses
				// the corrected query when typo correction fired — the native
				// WooCommerce search page has no typo tolerance of its own, so
				// linking the shopper's raw misspelling would likely land on an
				// empty results page despite the dropdown just showing matches.
				const viewAllQuery = highlightSource || query;
				const viewAll = document.createElement('a');
				viewAll.className = 'wcs-result-item wcs-view-all';
				viewAll.id = 'wcs-option-' + results.length;
				viewAll.setAttribute('role', 'option');
				viewAll.setAttribute('aria-selected', 'false');
				const url = new URL(window.location.origin + '/');
				url.searchParams.set('s', viewAllQuery);
				url.searchParams.set('post_type', 'product');
				viewAll.href = url.href;
				viewAll.textContent = (wcs_config.i18n.view_all || 'View all results for "%s"').replace('%s', viewAllQuery);
				dropdown.appendChild(viewAll);
			}

			positionDropdown(input);
			showDropdown();
		});
	}
})();
