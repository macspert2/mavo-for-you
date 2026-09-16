/**
 * Mavo For You — the /pour-vous/ page controller.
 *
 * The page arrives from the cache as one sentence saying the suggestions are
 * loading. Everything else is built here: the local session profile goes to
 * /wp-json/mavo/v1/for-you-page, and what comes back is a list of rows, each
 * a heading and a strip of tiles.
 *
 * It owns no data of its own. The profile, its expiry, the tile markup and the
 * already-read marking all belong to mavo-for-you.js, which this script
 * depends on and reaches through window.mavoForYou. If that script is missing
 * — the block disabled for this language, say — the page keeps the line the
 * server rendered and stops, which is the correct outcome rather than a
 * failure to handle.
 */
(function () {
	'use strict';

	var cfg = window.mavoForYouPageConfig;
	var api = window.mavoForYou;

	if (!cfg || !cfg.endpoint || !api || !api.session) {
		return;
	}

	var labels = cfg.labels || {};

	/** How much of the strip a single arrow press moves: one viewport, less a peek. */
	var SCROLL_OVERLAP = 0.85;

	/** Scroll positions within this many pixels of an end count as being at it. */
	var EDGE_TOLERANCE = 4;

	var rowSeq = 0;

	function debugLog() {
		if (api.config && api.config.debug && window.console) {
			console.log.apply(console, ['[mavo-for-you-page]'].concat([].slice.call(arguments)));
		}
	}

	// -------------------------------------------------------------------------
	// Request
	// -------------------------------------------------------------------------

	function request(host) {
		var body = api.session();

		body.page_id = cfg.pageId;

		var headers = { 'Content-Type': 'application/json' };
		var nonce = api.config && api.config.nonce;

		if (nonce) {
			headers['X-WP-Nonce'] = nonce;
		}

		window.fetch(cfg.endpoint, {
			method: 'POST',
			headers: headers,
			credentials: nonce ? 'same-origin' : 'omit',
			cache: 'no-store',
			body: JSON.stringify(body)
		}).then(function (response) {
			return response.ok ? response.json() : null;
		}).then(function (data) {
			debugLog('response', data);

			if (!data || !data.rows || !data.rows.length) {
				empty(host);
				return;
			}

			render(host, data);
		}).catch(function (error) {
			// The page cannot fall back to anything server-rendered — there is
			// nothing else on it — so it says plainly that there is nothing to
			// show rather than leaving a spinner running forever.
			debugLog('request failed', error);
			empty(host);
		});
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	function el(tag, className, text) {
		return api.el(tag, className, text);
	}

	function empty(host) {
		host.innerHTML = '';
		host.appendChild(el('p', 'mfy-page__empty', labels.empty || ''));
	}

	function render(host, data) {
		var section = el('section', 'mfy-page');

		// A personalized page explains itself with the same sentence the block
		// uses; a cold one says what it is actually showing instead, because
		// calling the catalogue's most-read articles "based on your visit"
		// would simply be untrue.
		var intro = data.personalized ? labels.intro : labels.coldIntro;

		if (intro) {
			section.appendChild(el('p', 'mfy-page__intro', intro));
		}

		data.rows.forEach(function (row) {
			var built = buildRow(row);

			if (built) {
				section.appendChild(built);
			}
		});

		// Only on a page built from a history there is something to clear. The
		// cold fallback has none, and offering to erase nothing would be a
		// control that does nothing.
		if (data.personalized) {
			section.appendChild(resetControl());
		}

		host.innerHTML = '';
		host.appendChild(section);

		// The rows did not exist during the first pass, so the links in them
		// have never been looked at. On this page that matters more than
		// anywhere else: half the tiles are things the visitor has read.
		api.markRead();

		window.requestAnimationFrame(function () {
			section.classList.add('is-visible');
		});
	}

	function buildRow(row) {
		if (!row.items || !row.items.length) {
			return null;
		}

		var id = 'mfy-row-' + (++rowSeq);
		var section = el('section', 'mfy-page__row mfy-page__row--' + (row.kind || 'other'));

		section.setAttribute('aria-labelledby', id);

		var head = el('div', 'mfy-page__row-head');
		var title = el('h2', 'mfy-page__row-title', row.title || '');

		title.id = id;
		head.appendChild(title);

		var viewport = el('div', 'mfy-page__viewport');
		var track = el('ul', 'mfy-page__track');

		// A scrollable region needs to be reachable without a mouse; the
		// arrows are a convenience on top of that, not the only way through.
		track.tabIndex = 0;
		track.setAttribute('role', 'list');
		track.setAttribute('aria-label', row.title || '');

		row.items.forEach(function (item) {
			var slide = el('li', 'mfy-page__slide');

			slide.appendChild(api.card(item));
			track.appendChild(slide);
		});

		viewport.appendChild(track);

		var nav = arrows(track);
		head.appendChild(nav.element);

		section.appendChild(head);
		section.appendChild(viewport);

		// Measured after layout, or every strip looks like it fits.
		window.requestAnimationFrame(function () {
			nav.sync();
		});

		return section;
	}

	/**
	 * Clearing the history, and leaving.
	 *
	 * The same quiet text button the block carries, in the same markup, so it
	 * is recognisably the same control — but it cannot behave the same way.
	 * The block can put itself back to what an anonymous visitor would see;
	 * this page *is* the history, and with the history gone it would silently
	 * become the cold fallback under the reader, which reads as a bug rather
	 * than as confirmation. So it leaves for the home page instead, and the
	 * hint says so before the click rather than after it.
	 */
	function resetControl() {
		var footer = el('div', 'mfy__footer mfy-page__footer');
		var button = el('button', 'mfy__reset', labels.reset || 'Reset');

		button.type = 'button';

		if (labels.resetHint) {
			button.title = labels.resetHint;
			footer.appendChild(el('p', 'mfy-page__reset-hint', labels.resetHint));
		}

		button.addEventListener('click', function () {
			api.reset();

			// replace(), not href: the page behind this one no longer exists
			// in any meaningful sense, so Back should not return to it.
			window.location.replace(cfg.homeUrl || '/');
		});

		footer.insertBefore(button, footer.firstChild);

		return footer;
	}

	// -------------------------------------------------------------------------
	// The strip
	// -------------------------------------------------------------------------

	/**
	 * Two arrows that rotate the strip rather than stopping at its ends.
	 *
	 * Wrap-around, not cloned tiles: pressing next at the end returns to the
	 * start and pressing previous at the start jumps to the end, so the strip
	 * is endless in the sense that matters — it never dead-ends — without a
	 * duplicated DOM whose copies would have to be kept out of the tab order,
	 * out of the already-read pass, and out of the reader's way when the
	 * browser restores a scroll position.
	 */
	function arrows(track) {
		var nav = el('div', 'mfy-page__nav');
		var prev = arrowButton('prev', labels.prev || 'Previous');
		var next = arrowButton('next', labels.next || 'Next');

		function step() {
			return Math.max(1, Math.round(track.clientWidth * SCROLL_OVERLAP));
		}

		function maxScroll() {
			return Math.max(0, track.scrollWidth - track.clientWidth);
		}

		function scrollTo(left) {
			track.scrollTo({ left: left, behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
		}

		prev.addEventListener('click', function () {
			var at = track.scrollLeft;

			scrollTo(at <= EDGE_TOLERANCE ? maxScroll() : Math.max(0, at - step()));
		});

		next.addEventListener('click', function () {
			var at = track.scrollLeft;

			scrollTo(at >= maxScroll() - EDGE_TOLERANCE ? 0 : Math.min(maxScroll(), at + step()));
		});

		nav.appendChild(prev);
		nav.appendChild(next);

		function sync() {
			// A strip that fits needs no arrows, and two dead buttons above it
			// would promise a rotation that cannot happen.
			var scrollable = maxScroll() > EDGE_TOLERANCE;

			nav.hidden = !scrollable;
		}

		window.addEventListener('resize', debounce(sync, 150));

		return { element: nav, sync: sync };
	}

	function arrowButton(direction, label) {
		var button = el('button', 'mfy-page__arrow mfy-page__arrow--' + direction);

		button.type = 'button';
		button.setAttribute('aria-label', label);
		button.title = label;
		button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" width="20" height="20">'
			+ '<path fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" d="'
			+ (direction === 'prev' ? 'M15 5 8 12l7 7' : 'M9 5l7 7-7 7')
			+ '"/></svg>';

		return button;
	}

	function prefersReducedMotion() {
		return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
	}

	function debounce(fn, wait) {
		var handle = null;

		return function () {
			window.clearTimeout(handle);
			handle = window.setTimeout(fn, wait);
		};
	}

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	function boot() {
		var host = document.getElementById('mavo-for-you-page');

		if (!host) {
			return;
		}

		request(host);
	}

	/** A broken suggestions page is still a page; it must not throw over it. */
	function safeBoot() {
		try {
			boot();
		} catch (error) {
			debugLog('boot failed', error);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', safeBoot);
	} else {
		safeBoot();
	}
})();
