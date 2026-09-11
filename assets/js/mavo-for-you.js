/**
 * Mavo For You — frontend controller.
 *
 * Three jobs, in this order:
 *   1. keep a short-lived, local record of what this visitor has been reading;
 *   2. once that record is substantial enough, ask the server what to suggest;
 *   3. write the answer into the placeholder the cached page left behind.
 *
 * Everything it knows lives in this browser's localStorage and expires with
 * the session. Nothing leaves the page except the one POST to Maman Voyage.
 */
(function () {
	'use strict';

	var cfg = window.mavoForYouConfig;

	if (!cfg || !cfg.endpoint || !cfg.storageKey) {
		return;
	}

	var SEARCH_ENGINES = {
		google: 'google',
		bing: 'bing',
		duckduckgo: 'duckduckgo',
		ecosia: 'ecosia',
		qwant: 'qwant',
		yahoo: 'yahoo',
		yandex: 'yandex',
		baidu: 'baidu',
		brave: 'brave'
	};

	/** Params search engines are known to expose a query in, when they expose one at all. */
	var QUERY_PARAMS = ['q', 'query', 'p', 'text', 'wd'];

	function now() {
		return Math.floor(Date.now() / 1000);
	}

	function clamp(value, min, max) {
		value = parseInt(value, 10);
		if (isNaN(value)) {
			value = min;
		}
		return Math.max(min, Math.min(max, value));
	}

	function debugLog() {
		if (cfg.debug && window.console) {
			console.log.apply(console, ['[mavo-for-you]'].concat([].slice.call(arguments)));
		}
	}

	// -------------------------------------------------------------------------
	// Profile storage
	// -------------------------------------------------------------------------

	function emptyProfile() {
		return {
			version: cfg.schemaVersion,
			views: [],
			searches: [],
			referral: null,
			updated: now()
		};
	}

	/**
	 * The stored profile, or a fresh one.
	 *
	 * Expiry is manual because localStorage has none: anything untouched for
	 * longer than historyTtl is discarded wholesale rather than partially aged,
	 * which keeps "session-only" honest and the code trivial.
	 */
	function loadProfile() {
		var raw;

		try {
			raw = window.localStorage.getItem(cfg.storageKey);
		} catch (e) {
			return emptyProfile();
		}

		if (!raw) {
			return emptyProfile();
		}

		var profile;
		try {
			profile = JSON.parse(raw);
		} catch (e) {
			return emptyProfile();
		}

		if (!profile || profile.version !== cfg.schemaVersion || !Array.isArray(profile.views)) {
			return emptyProfile();
		}

		if (!profile.updated || now() - profile.updated > cfg.historyTtl) {
			debugLog('profile expired, starting a new one');
			return emptyProfile();
		}

		if (!Array.isArray(profile.searches)) {
			profile.searches = [];
		}

		return profile;
	}

	function saveProfile(profile) {
		profile.updated = now();

		profile.views.sort(function (a, b) {
			return b.last_seen - a.last_seen;
		});
		profile.views = profile.views.slice(0, cfg.maxViews);
		profile.searches = profile.searches.slice(-cfg.maxSearches);

		try {
			window.localStorage.setItem(cfg.storageKey, JSON.stringify(profile));
		} catch (e) {
			// Private mode, quota, disabled storage: personalization simply
			// does not happen. The page is unaffected.
			debugLog('could not persist profile', e);
		}
	}

	/** Set once boot() starts tracking, so a reset can silence them. */
	var activeTracker = null;
	var pollHandle = null;

	/**
	 * Forget this visit.
	 *
	 * Clearing the key is not enough on its own: the tracker holds the whole
	 * profile in memory and would write it back within seconds, quietly undoing
	 * the reset. So it is stopped first, and tracking does not resume for the
	 * rest of this page view — a reset the visitor has to ask for twice is not
	 * a reset. The next page load starts a fresh, empty profile.
	 */
	function resetProfile() {
		if (activeTracker) {
			activeTracker.stop();
			activeTracker = null;
		}

		if (pollHandle) {
			window.clearInterval(pollHandle);
			pollHandle = null;
		}

		try {
			window.localStorage.removeItem(cfg.storageKey);
		} catch (e) {}
	}

	window.mavoForYouReset = function () {
		resetProfile();

		var host = document.getElementById('mavo-for-you');
		if (host) {
			host.innerHTML = '';
		}
	};

	// -------------------------------------------------------------------------
	// Signals: search + referral
	// -------------------------------------------------------------------------

	function recordSearch(profile) {
		var params = new URLSearchParams(window.location.search);
		var query = (params.get('s') || '').replace(/\s+/g, ' ').trim();

		if (!query || query.length > cfg.maxSearchLength) {
			return false;
		}

		var last = profile.searches[profile.searches.length - 1];
		if (last && last.query === query) {
			last.timestamp = now();
			return true;
		}

		profile.searches.push({ query: query, source: 'site', timestamp: now() });
		return true;
	}

	/**
	 * Where this visit came from, recorded once per profile.
	 *
	 * Most organic referrals expose the engine and nothing else — that is
	 * recorded as-is, with a null query. No keyword is ever inferred from a
	 * bare "google.com".
	 */
	function recordReferral(profile) {
		if (profile.referral || !document.referrer) {
			return false;
		}

		var ref;
		try {
			ref = new URL(document.referrer);
		} catch (e) {
			return false;
		}

		if (ref.hostname === window.location.hostname) {
			return false;
		}

		var host = ref.hostname.replace(/^www\./, '');
		var source = host;

		Object.keys(SEARCH_ENGINES).forEach(function (engine) {
			if (host.indexOf(engine + '.') === 0 || host.indexOf('.' + engine + '.') !== -1) {
				source = SEARCH_ENGINES[engine];
			}
		});

		var query = null;
		for (var i = 0; i < QUERY_PARAMS.length; i++) {
			var value = ref.searchParams.get(QUERY_PARAMS[i]);
			if (value) {
				value = value.replace(/\s+/g, ' ').trim();
				if (value && value.length <= cfg.maxSearchLength) {
					query = value;
				}
				break;
			}
		}

		profile.referral = {
			source: source.slice(0, 100),
			query: query,
			landing_post_id: cfg.postId || 0,
			timestamp: now()
		};

		return true;
	}

	// -------------------------------------------------------------------------
	// View tracking
	// -------------------------------------------------------------------------

	function ViewTracker(profile, postId) {
		this.profile = profile;
		this.postId = postId;
		this.entry = null;
		this.baseDuration = 0;
		this.baseScroll = 0;
		this.visibleMs = 0;
		this.maxScroll = 0;
		this.lastTick = document.visibilityState === 'visible' ? Date.now() : null;
		this.scrollQueued = false;
		this.stopped = false;
	}

	ViewTracker.prototype.start = function () {
		var self = this;

		for (var i = 0; i < this.profile.views.length; i++) {
			if (this.profile.views[i].post_id === this.postId) {
				this.entry = this.profile.views[i];
				break;
			}
		}

		if (!this.entry) {
			this.entry = {
				post_id: this.postId,
				lang: cfg.lang,
				first_seen: now(),
				last_seen: now(),
				duration_seconds: 0,
				max_scroll_pct: 0
			};
			this.profile.views.push(this.entry);
		}

		// A revisit updates the existing record rather than appending a new
		// one, so a tab left open and returned to a dozen times cannot inflate
		// its own weight.
		this.baseDuration = clamp(this.entry.duration_seconds, 0, cfg.maxDuration);
		this.baseScroll = clamp(this.entry.max_scroll_pct, 0, 100);

		this.measureScroll();

		document.addEventListener('visibilitychange', function () {
			if (document.visibilityState === 'visible') {
				self.lastTick = Date.now();
			} else {
				// Time in a hidden tab is not reading time.
				self.accumulate();
				self.lastTick = null;
				self.persist();
			}
		});

		window.addEventListener('scroll', function () {
			if (self.scrollQueued) {
				return;
			}
			self.scrollQueued = true;
			window.requestAnimationFrame(function () {
				self.scrollQueued = false;
				self.measureScroll();
			});
		}, { passive: true });

		window.addEventListener('pagehide', function () {
			self.persist();
		});

		this.interval = window.setInterval(function () {
			self.persist();
		}, Math.max(5, cfg.saveInterval) * 1000);

		this.persist();
	};

	ViewTracker.prototype.accumulate = function () {
		if (this.lastTick !== null) {
			this.visibleMs += Date.now() - this.lastTick;
			this.lastTick = Date.now();
		}
	};

	/** Held in memory; only persist() ever writes it out. */
	ViewTracker.prototype.measureScroll = function () {
		var doc = document.documentElement;
		var body = document.body;
		var height = Math.max(
			doc.scrollHeight,
			body ? body.scrollHeight : 0,
			doc.offsetHeight,
			body ? body.offsetHeight : 0
		);
		var viewport = window.innerHeight || doc.clientHeight;

		if (height <= viewport) {
			// A page that does not scroll has been seen in full.
			this.maxScroll = 100;
			return;
		}

		var scrolled = (window.pageYOffset || doc.scrollTop || 0) + viewport;
		var pct = Math.round((scrolled / height) * 100);

		this.maxScroll = Math.max(this.maxScroll, clamp(pct, 0, 100));
	};

	ViewTracker.prototype.sessionSeconds = function () {
		this.accumulate();
		return Math.min(cfg.maxDuration, Math.round(this.visibleMs / 1000));
	};

	/** After a reset there is nothing left to record, and nothing to record into. */
	ViewTracker.prototype.stop = function () {
		this.stopped = true;
		window.clearInterval(this.interval);
	};

	ViewTracker.prototype.persist = function () {
		if (this.stopped) {
			return;
		}

		this.entry.last_seen = now();
		this.entry.duration_seconds = Math.max(this.baseDuration, this.sessionSeconds());
		this.entry.max_scroll_pct = Math.max(this.baseScroll, this.maxScroll);

		saveProfile(this.profile);
	};

	// -------------------------------------------------------------------------
	// Recommendation request
	// -------------------------------------------------------------------------

	function isMeaningful(view) {
		return view.duration_seconds >= cfg.minDuration || view.max_scroll_pct >= cfg.minScroll;
	}

	function meaningfulCount(profile) {
		var count = 0;
		for (var i = 0; i < profile.views.length; i++) {
			if (isMeaningful(profile.views[i])) {
				count++;
			}
		}
		return count;
	}

	/**
	 * What the placeholder itself asks for.
	 *
	 * On a page carrying [geo_related] the server has already rendered an
	 * impersonal block of a given size and geographic level into the cached
	 * HTML; those are read back off the element so the personalized block that
	 * replaces it keeps the same shape.
	 */
	function placeholderOptions(host) {
		var options = {};

		if (!host) {
			return options;
		}

		var limit = parseInt(host.getAttribute('data-limit'), 10);
		if (!isNaN(limit) && limit > 0) {
			options.limit = Math.min(limit, cfg.maxLimit || 12);
		}

		var level = host.getAttribute('data-level');
		if (level) {
			options.geo_level = level;
		}

		return options;
	}

	function payload(profile) {
		var views = profile.views.slice(0, cfg.maxViews).map(function (view) {
			return {
				post_id: view.post_id,
				duration_seconds: clamp(view.duration_seconds, 0, cfg.maxDuration),
				max_scroll_pct: clamp(view.max_scroll_pct, 0, 100),
				last_seen: view.last_seen
			};
		});

		return {
			current_post_id: cfg.postId,
			lang: cfg.lang,
			views: views,
			searches: profile.searches.slice(-cfg.maxSearches),
			referral: profile.referral || null,
			debug: !!cfg.debug
		};
	}

	function request(profile, host) {
		var body = payload(profile);
		var options = placeholderOptions(host);

		if (options.limit) {
			body.limit = options.limit;
		}
		if (options.geo_level) {
			body.geo_level = options.geo_level;
		}

		var headers = { 'Content-Type': 'application/json' };
		if (cfg.nonce) {
			headers['X-WP-Nonce'] = cfg.nonce;
		}

		window.fetch(cfg.endpoint, {
			method: 'POST',
			headers: headers,
			credentials: cfg.nonce ? 'same-origin' : 'omit',
			cache: 'no-store',
			body: JSON.stringify(body)
		}).then(function (response) {
			return response.ok ? response.json() : null;
		}).then(function (data) {
			if (!data) {
				return;
			}

			debugLog('response', data);

			// On anything less than a usable answer the placeholder is left
			// exactly as the server rendered it — which on a [geo_related]
			// page means the impersonal block simply stays.
			if (data.show && data.recommendations && data.recommendations.length) {
				render(host, data);
			}

			// After render(), which clears the placeholder first.
			if (data.debug) {
				renderDebug(data.debug);
			}
		}).catch(function (error) {
			// A failed request leaves the placeholder empty and the page intact.
			debugLog('request failed', error);
		});
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text) {
			node.textContent = text;
		}
		return node;
	}

	/** Recommendation card, reusing the site's existing tile styling. */
	function card(item) {
		var tile = el('div', 'mv-tile mv-tile--media mfy__card' + (item.image ? '' : ' mv-tile--no-media'));

		if (item.image) {
			var media = el('span', 'mv-tile__media');
			var img = document.createElement('img');
			img.className = 'mv-tile__img';
			img.src = item.image;
			img.alt = '';
			img.loading = 'lazy';
			img.decoding = 'async';
			media.appendChild(img);
			tile.appendChild(media);
		}

		var body = el('span', 'mv-tile__body');

		// The site's own eyebrow, not a new badge: a hub is editorial context
		// ("Guide"), not a status chip, and .mv-tile__eyebrow already says
		// exactly that in the theme's own voice.
		if (item.hub && item.hub.label) {
			body.appendChild(el('span', 'mv-tile__eyebrow mfy__hub-label', item.hub.label));
			tile.className += ' mfy__card--hub';
		}

		var title = el('span', 'mv-tile__title');
		var link = el('a', 'mv-tile__link', item.title);

		link.href = item.url;
		link.setAttribute('data-mavo-post-id', item.post_id);
		title.appendChild(link);
		body.appendChild(title);

		if (item.excerpt) {
			body.appendChild(el('span', 'mv-tile__description', trim(item.excerpt, 130)));
		}

		tile.appendChild(body);

		return tile;
	}

	function trim(text, length) {
		if (text.length <= length) {
			return text;
		}
		var cut = text.slice(0, length);
		var space = cut.lastIndexOf(' ');
		return (space > 40 ? cut.slice(0, space) : cut) + '…';
	}

	function render(host, data) {
		var section = el('section', 'mfy');
		section.setAttribute('aria-labelledby', 'mfy-title');

		var header = el('header', 'mfy__header');
		var heading = el('h2', 'mfy__title', cfg.labels.heading);
		heading.id = 'mfy-title';
		header.appendChild(heading);
		header.appendChild(el('p', 'mfy__subtitle', cfg.labels.subtitle));
		section.appendChild(header);

		var grid = el('div', 'mfy__grid');
		data.recommendations.forEach(function (item) {
			grid.appendChild(card(item));
		});
		section.appendChild(grid);

		if (data.recently_viewed && data.recently_viewed.length) {
			var recent = el('div', 'mfy__recent');
			recent.appendChild(el('h3', 'mfy__recent-title', cfg.labels.recent));

			var list = el('ul', 'mfy__recent-list');
			data.recently_viewed.forEach(function (item) {
				var li = el('li', 'mfy__recent-item');
				var link = el('a', 'mfy__recent-link', item.title);
				link.href = item.url;
				link.setAttribute('data-mavo-post-id', item.post_id);
				li.appendChild(link);

				// Marks the way back to the overview page the visitor came
				// through, without promoting it above the articles.
				if (item.hub && item.hub.label) {
					li.className += ' mfy__recent-item--hub';
					li.appendChild(el('span', 'mfy__recent-hub', item.hub.label));
				}

				list.appendChild(li);
			});

			recent.appendChild(list);
			section.appendChild(recent);
		}

		section.appendChild(resetControl(host));

		host.innerHTML = '';
		host.appendChild(section);

		window.requestAnimationFrame(function () {
			section.classList.add('is-visible');
		});
	}

	/**
	 * The way out: a quiet text button, last thing in the block.
	 *
	 * Deliberately understated — this is a courtesy, not a warning. There is no
	 * confirmation dialog because there is nothing to lose (a few post IDs in
	 * this browser) and because a modal would block the extension-free page
	 * anyway; the block simply becomes a one-line acknowledgement.
	 */
	function resetControl(host) {
		var footer = el('div', 'mfy__footer');
		var button = el('button', 'mfy__reset', cfg.labels.reset || 'Reset');

		button.type = 'button';

		if (cfg.labels.resetHint) {
			button.title = cfg.labels.resetHint;
		}

		button.addEventListener('click', function () {
			resetProfile();

			var note = el('p', 'mfy__note', cfg.labels.resetDone || '');
			note.setAttribute('role', 'status');

			host.innerHTML = '';
			host.appendChild(note);
		});

		footer.appendChild(button);

		return footer;
	}

	/** Administrator-only scoring explanation, printed under the block. */
	function renderDebug(debug) {
		if (window.console) {
			console.groupCollapsed('[mavo-for-you] scoring');
			console.log('session filters', debug.profile_filters);
			console.log('search filters', debug.search_filters);
			console.table(debug.profile_views);
			console.table(debug.candidates);
			console.log('excluded', debug.excluded);
			console.groupEnd();
		}

		var host = document.getElementById('mavo-for-you');
		if (!host) {
			return;
		}

		var details = el('details', 'mfy-debug');
		details.appendChild(el('summary', null, 'Mavo For You — scoring (' + debug.pool_size + ' candidates in pool)'));

		var pre = el('pre', 'mfy-debug__dump', JSON.stringify(debug, null, 2));
		details.appendChild(pre);
		host.appendChild(details);
	}

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	function boot() {
		var profile = loadProfile();
		var dirty = false;

		if (recordSearch(profile)) {
			dirty = true;
		}
		if (cfg.mode === 'content' && recordReferral(profile)) {
			dirty = true;
		}

		if (cfg.mode !== 'content' || !cfg.postId) {
			if (dirty) {
				saveProfile(profile);
			}
			return;
		}

		var tracker = new ViewTracker(profile, cfg.postId);
		tracker.start(); // Also persists, so `dirty` is covered.
		activeTracker = tracker;

		var host = document.getElementById('mavo-for-you');
		if (!host || !cfg.showBlock) {
			return;
		}

		var requested = false;
		var ticks = 0;

		function maybeRequest() {
			if (requested || meaningfulCount(profile) < cfg.minMeaningfulViews) {
				return;
			}
			requested = true;
			window.clearInterval(check);
			pollHandle = null;
			request(profile, host);
		}

		// Either the visitor already arrives with enough history, or the
		// current page earns the threshold while they read it. Either way the
		// endpoint is called at most once per page view, and the poll gives up
		// after a couple of minutes rather than ticking for the life of the tab.
		var check = window.setInterval(function () {
			tracker.persist();
			maybeRequest();

			if (!requested && ++ticks >= 15) {
				window.clearInterval(check);
				pollHandle = null;
			}
		}, Math.max(2, Math.min(cfg.minDuration, 8)) * 1000);

		pollHandle = check;

		window.addEventListener('pagehide', function () {
			window.clearInterval(check);
		});

		maybeRequest();
	}

	/** Personalization is never worth breaking a page over. */
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
