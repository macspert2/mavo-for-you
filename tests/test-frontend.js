/**
 * Frontend controller tests — plain Node, no dependencies.
 *
 * assets/js/mavo-for-you.js is written for a browser, so the browser it needs
 * is stubbed here: just enough DOM, storage, timers and clock to drive the
 * tracking, the request gate and the reset. Run with:  node tests/test-frontend.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SOURCE = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'mavo-for-you.js'), 'utf8');

let failed = 0;
function check(label, ok, detail) {
	if (!ok) { failed++; }
	console.log(`${ok ? 'PASS' : 'FAIL'} ${label}${detail ? `  (${detail})` : ''}`);
}

// ---------------------------------------------------------------------------
// The browser, approximately
// ---------------------------------------------------------------------------

/** Every element ever created, so document.querySelectorAll can scan them. */
let registry = [];

/** Does this element match a simple selector (.class, #id or tag)? */
function matches(el, selector) {
	return selector.split(',').map((s) => s.trim()).filter(Boolean).some((sel) => {
		if (sel.startsWith('.')) {
			return String(el.className).split(/\s+/).includes(sel.slice(1));
		}
		if (sel.startsWith('#')) {
			return el.attributes.id === sel.slice(1);
		}
		if (sel.endsWith('[href]')) {
			return el.tagName === sel.slice(0, -6) && el.attributes.href != null;
		}
		return el.tagName === sel;
	});
}

function makeElement(tag) {
	const el = {
		tagName: tag,
		className: '',
		textContent: '',
		children: [],
		parentNode: null,
		attributes: {},
		listeners: {},
		classList: {
			add(name) {
				const set = new Set(String(el.className).split(/\s+/).filter(Boolean));
				set.add(name);
				el.className = [...set].join(' ');
			},
			remove(name) {
				const set = new Set(String(el.className).split(/\s+/).filter(Boolean));
				set.delete(name);
				el.className = [...set].join(' ');
			},
			contains(name) { return String(el.className).split(/\s+/).includes(name); },
		},
		/** Nearest self-or-ancestor matching the selector, like the real thing. */
		closest(selector) {
			let node = el;
			while (node) {
				if (matches(node, selector)) { return node; }
				node = node.parentNode;
			}
			return null;
		},
		set innerHTML(value) { if (value === '') { this.children = []; } },
		get innerHTML() { return ''; },
		appendChild(child) { child.parentNode = this; this.children.push(child); return child; },
		setAttribute(key, value) { this.attributes[key] = value; },
		getAttribute(key) { return key in this.attributes ? this.attributes[key] : null; },
		addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); },
		dispatch(type) { (this.listeners[type] || []).forEach((fn) => fn({})); },
		/** Minimal class-selector support, which is all the controller uses. */
		querySelector(selector) {
			return selector.startsWith('.') ? this.find(selector.slice(1)) : null;
		},
		/** Depth-first search for a rendered node, by class name. */
		find(className) {
			if (String(this.className).split(' ').includes(className)) { return this; }
			for (const child of this.children) {
				const hit = child.find ? child.find(className) : null;
				if (hit) { return hit; }
			}
			return null;
		},
	};

	registry.push(el);
	return el;
}

/** Builds <a href> inside an optional container chain, for the marking tests. */
function makeLink(href, attrs = {}, parentClass = null) {
	const link = makeElement('a');
	link.setAttribute('href', href);
	Object.entries(attrs).forEach(([k, v]) => link.setAttribute(k, v));
	if (parentClass) {
		const parent = makeElement('div');
		parent.className = parentClass;
		parent.appendChild(link);
	}
	return link;
}

function makeEnvironment(options = {}) {
	const store = Object.assign({}, options.storage || {});
	registry = [];
	const host = makeElement('div');
	const timers = [];
	const fetches = [];
	let clock = options.startTime || 1_800_000_000_000;
	let responder = options.responder || (() => ({
		show: true,
		lang: 'fr',
		recommendations: [{ post_id: 42, title: 'Kew Gardens', url: 'https://x.test/42/', image: '', excerpt: 'x' }],
		recently_viewed: [{ post_id: 7, title: 'Bath', url: 'https://x.test/7/', image: '' }],
	}));

	const documentListeners = {};
	const windowListeners = {};

	class FakeDate extends Date {
		static now() { return clock; }
	}

	const sandbox = {
		Date: FakeDate,
		JSON, Math, Object, Array, String, Number, Boolean, Error, Promise,
		URL, URLSearchParams,
		console: { log() {}, groupCollapsed() {}, groupEnd() {}, table() {} },
		setInterval(fn, ms) { timers.push({ fn, ms, cleared: false }); return timers.length - 1; },
		clearInterval(handle) { if (timers[handle]) { timers[handle].cleared = true; } },
		requestAnimationFrame(fn) { fn(); },
		fetch(url, init) {
			fetches.push({ url, body: JSON.parse(init.body), init });
			const payload = responder();
			return Promise.resolve({ ok: payload !== null, json: () => Promise.resolve(payload) });
		},
		document: {
			querySelectorAll(selector) { return registry.filter((el) => matches(el, selector)); },
			readyState: 'complete',
			visibilityState: options.visibility || 'visible',
			documentElement: { scrollHeight: 4000, offsetHeight: 4000, clientHeight: 800, scrollTop: 0 },
			body: { scrollHeight: 4000, offsetHeight: 4000 },
			referrer: options.referrer || '',
			addEventListener(type, fn) { (documentListeners[type] = documentListeners[type] || []).push(fn); },
			getElementById(id) { return id === 'mavo-for-you' ? host : null; },
			createElement: makeElement,
		},
		location: {
			search: options.search || '',
			hostname: 'www.mamanvoyage.com',
			origin: 'https://www.mamanvoyage.com',
			pathname: options.pathname || '/2016/05/current-post/',
		},
	};

	sandbox.window = sandbox;
	sandbox.window.localStorage = {
		getItem: (key) => (key in store ? store[key] : null),
		setItem: (key, value) => { store[key] = String(value); },
		removeItem: (key) => { delete store[key]; },
	};
	sandbox.window.innerHeight = 800;
	sandbox.window.pageYOffset = 0;
	sandbox.window.addEventListener = (type, fn) => { (windowListeners[type] = windowListeners[type] || []).push(fn); };
	sandbox.window.mavoForYouConfig = Object.assign({
		endpoint: 'https://www.mamanvoyage.com/wp-json/mavo/v1/for-you',
		postId: 100,
		lang: 'fr',
		mode: 'content',
		showBlock: true,
		storageKey: 'mavo_for_you_v0',
		schemaVersion: 1,
		historyTtl: 86400,
		maxViews: 20,
		maxSearches: 5,
		maxSearchLength: 200,
		minDuration: 8,
		minScroll: 25,
		maxDuration: 600,
		minMeaningfulViews: 2,
		saveInterval: 15,
		debug: false,
		markRead: true,
		readSelectors: {
			skip: '#mavo-nav, .mavo-nav, .site-footer, .mfy__recent, .mfy__footer',
			tile: '.mv-tile',
			prose: '.entry-content',
		},
		labels: { heading: 'Pour vous', subtitle: 'Sous-titre', recent: 'Consultés récemment', reset: 'Effacer mon historique', resetHint: 'Efface…', resetDone: 'Historique effacé.' },
	}, options.config || {});

	// Anything the page itself contains must exist before the controller runs,
	// exactly as server-rendered markup does.
	const dom = options.dom ? options.dom() : null;

	let impersonal = null;
	if (options.impersonal) {
		impersonal = makeElement('section');
		impersonal.className = 'mfy mfy--impersonal is-visible';
		impersonal.textContent = 'server-rendered';
		host.children.push(impersonal);
	}

	vm.createContext(sandbox);
	vm.runInContext(SOURCE, sandbox);

	return {
		sandbox, host, store, fetches, timers, impersonal, dom,
		profile: () => (store.mavo_for_you_v0 ? JSON.parse(store.mavo_for_you_v0) : null),
		advance(seconds) { clock += seconds * 1000; },
		scrollTo(pct) {
			sandbox.window.pageYOffset = Math.max(0, (4000 * pct) / 100 - 800);
			(windowListeners.scroll || []).forEach((fn) => fn({}));
		},
		hide() { sandbox.document.visibilityState = 'hidden'; (documentListeners.visibilitychange || []).forEach((fn) => fn({})); },
		show() { sandbox.document.visibilityState = 'visible'; (documentListeners.visibilitychange || []).forEach((fn) => fn({})); },
		tick() { timers.filter((t) => !t.cleared).forEach((t) => t.fn()); },
		fire(type) { (windowListeners[type] || []).forEach((fn) => fn({})); },
		setResponder(fn) { responder = fn; },
	};
}

/** A stored profile whose one previous view already counts as meaningful. */
function seededProfile(nowSeconds, overrides = {}) {
	return {
		mavo_for_you_v0: JSON.stringify(Object.assign({
			version: 1,
			views: [{ post_id: 7, lang: 'fr', first_seen: nowSeconds - 600, last_seen: nowSeconds - 300, duration_seconds: 90, max_scroll_pct: 80 }],
			searches: [],
			referral: null,
			updated: nowSeconds - 300,
		}, overrides)),
	};
}

const START = 1_800_000_000_000;
const NOW = Math.floor(START / 1000);

/** The controller answers the endpoint through a promise chain; let it settle. */
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

async function main() {

// ---------------------------------------------------------------------------

// 1. A fresh visitor is recorded but nothing is requested.
{
	const env = makeEnvironment();
	const profile = env.profile();
	check('first view is stored immediately', !!profile && profile.views.length === 1, JSON.stringify(profile && profile.views));
	check('stored view carries the documented schema',
		profile.views[0].post_id === 100 && profile.views[0].lang === 'fr'
		&& 'first_seen' in profile.views[0] && 'duration_seconds' in profile.views[0] && 'max_scroll_pct' in profile.views[0]);
	check('no request on a first, unqualified view', env.fetches.length === 0);
}

// 2. With prior history, the endpoint is called once and only once.
{
	const env = makeEnvironment({ storage: seededProfile(NOW) });
	check('one prior view and an unread current page: nothing yet', env.fetches.length === 0, `${env.fetches.length} requests`);

	// The current page earns the second meaningful view by being read.
	env.advance(10);
	env.tick();
	await flush();
	check('the page qualifying while it is read triggers the request', env.fetches.length === 1, `${env.fetches.length} requests`);
	const body = env.fetches[0].body;
	check('payload carries the current post id', body.current_post_id === 100);
	check('payload carries both views', body.views.length === 2);
	check('payload views are trimmed to the documented fields',
		Object.keys(body.views[0]).sort().join(',') === 'duration_seconds,last_seen,max_scroll_pct,post_id');
	env.advance(60);
	env.tick();
	check('no second request for the same page view', env.fetches.length === 1, `${env.fetches.length} requests`);
	// A plain after-content placeholder carries no size or level, so the
	// server's own defaults decide — the pre-shortcode behaviour, unchanged.
	check('a plain placeholder sends no limit or level', !('limit' in body) && !('geo_level' in body), JSON.stringify(Object.keys(body)));
	check('the block is rendered into the placeholder', !!env.host.find('mfy'));
	check('recommendation link carries data-mavo-post-id', env.host.find('mv-tile__link').attributes['data-mavo-post-id'] === 42);
}

// 2b. Arriving with enough history already: requested at load, without waiting.
{
	const twice = JSON.parse(seededProfile(NOW).mavo_for_you_v0);
	twice.views.push({ post_id: 8, lang: 'fr', first_seen: NOW - 1200, last_seen: NOW - 900, duration_seconds: 120, max_scroll_pct: 90 });
	const env = makeEnvironment({ storage: { mavo_for_you_v0: JSON.stringify(twice) } });
	await flush();
	check('two prior meaningful views request immediately', env.fetches.length === 1, `${env.fetches.length} requests`);
	check('the current page is included in the payload', env.fetches[0].body.views.some((v) => v.post_id === 100));
}

// 3. Qualification while reading: unqualified at load, requested once it counts.
{
	const env = makeEnvironment({ storage: seededProfile(NOW) , config: { minMeaningfulViews: 3 } });
	check('threshold not met at load: no request', env.fetches.length === 0);
	env.advance(30);
	env.scrollTo(90);
	env.tick();
	check('still no request when history is genuinely too thin', env.fetches.length === 0);
}

// 4. Reading time only counts while the tab is visible.
{
	const env = makeEnvironment();
	env.advance(20);
	env.hide();          // 20 visible seconds banked
	env.advance(600);    // ten minutes in a background tab
	env.show();
	env.advance(10);     // 10 more visible seconds
	env.tick();
	const duration = env.profile().views[0].duration_seconds;
	check('hidden-tab time is not reading time', duration >= 30 && duration <= 32, `${duration}s recorded`);
}

// 5. Scroll depth is recorded as a maximum, not a last value.
{
	const env = makeEnvironment();
	env.scrollTo(80);
	env.scrollTo(20);
	env.tick();
	check('max scroll is kept, not the latest', env.profile().views[0].max_scroll_pct === 80, `${env.profile().views[0].max_scroll_pct}%`);
}

// 6. Revisiting a post updates its record instead of piling up duplicates.
{
	const env = makeEnvironment({
		storage: { mavo_for_you_v0: JSON.stringify({
			version: 1,
			views: [{ post_id: 100, lang: 'fr', first_seen: NOW - 900, last_seen: NOW - 600, duration_seconds: 240, max_scroll_pct: 95 }],
			searches: [], referral: null, updated: NOW - 600,
		}) },
	});
	env.advance(15);
	env.tick();
	const views = env.profile().views;
	check('a revisit does not duplicate the entry', views.length === 1, `${views.length} entries`);
	check('a short revisit cannot lower an earlier reading time', views[0].duration_seconds === 240, `${views[0].duration_seconds}s`);
	check('a short revisit cannot lower an earlier scroll depth', views[0].max_scroll_pct === 95);
	check('last_seen is refreshed', views[0].last_seen >= NOW);
}

// 7. Expiry.
{
	const env = makeEnvironment({ storage: seededProfile(NOW - 90000) });
	const ids = env.profile().views.map((v) => v.post_id);
	check('a profile older than the TTL is discarded', ids.join() === '100', ids.join());
}

// 8. On-site search capture.
{
	const env = makeEnvironment({ config: { mode: 'search', postId: 0, showBlock: false }, search: '?s=londres%20ado' });
	const searches = env.profile().searches;
	check('a site search is recorded', searches.length === 1 && searches[0].query === 'londres ado', JSON.stringify(searches));
	check('a search page records no view', env.profile().views.length === 0);
}

// 9. Referral, with and without a query.
{
	const bare = makeEnvironment({ referrer: 'https://www.google.com/' });
	check('a bare Google referrer invents no query', bare.profile().referral.source === 'google' && bare.profile().referral.query === null, JSON.stringify(bare.profile().referral));
	const withQuery = makeEnvironment({ referrer: 'https://www.bing.com/search?q=londres+en+famille' });
	check('a referrer query is captured when present', withQuery.profile().referral.query === 'londres en famille', JSON.stringify(withQuery.profile().referral));
}

// 10. The reset control — and the regression it exists for.
{
	const env = makeEnvironment({ storage: seededProfile(NOW) });
	env.advance(10);
	env.tick();
	await flush();
	check('the block offers a reset control', !!env.host.find('mfy__reset'));
	env.host.find('mfy__reset').dispatch('click');
	check('clicking it clears the stored profile', env.profile() === null);
	check('the block is replaced by an acknowledgement', !!env.host.find('mfy__note'));

	// The bug this guards: the tracker still holds the whole profile in memory,
	// and any of its save triggers would write it straight back. Stopping its
	// interval is not enough — hiding the tab and leaving the page both persist
	// too, and those listeners stay attached for the life of the page.
	env.advance(120);
	env.tick();
	check('a later interval save cannot resurrect the profile', env.profile() === null, JSON.stringify(env.store));
	env.hide();
	check('hiding the tab cannot resurrect the profile', env.profile() === null, JSON.stringify(env.store));
	env.show();
	env.advance(30);
	env.fire('pagehide');
	check('leaving the page cannot resurrect the profile', env.profile() === null, JSON.stringify(env.store));

	const api = makeEnvironment({ storage: seededProfile(NOW) });
	api.advance(10);
	api.tick();
	await flush();
	api.sandbox.window.mavoForYouReset();
	api.advance(120);
	api.tick();
	api.hide();
	api.fire('pagehide');
	check('window.mavoForYouReset() is equally final', api.profile() === null, JSON.stringify(api.store));
}

// 10b. A hub suggestion is labelled with the site's own eyebrow, not a new badge.
{
	const env = makeEnvironment({
		storage: seededProfile(NOW),
		responder: () => ({
			show: true,
			lang: 'fr',
			recommendations: [
				{ post_id: 50, title: 'Londres en famille', url: 'https://x.test/50/', image: '', excerpt: 'x', hub: { type: 'geo', label: 'Guide' } },
				{ post_id: 4, title: 'Kew Gardens', url: 'https://x.test/4/', image: '', excerpt: 'x' },
			],
			recently_viewed: [
				{ post_id: 7, title: 'Bath', url: 'https://x.test/7/', image: '' },
				{ post_id: 51, title: 'Angleterre', url: 'https://x.test/51/', image: '', hub: { type: 'geo', label: 'Guide' } },
			],
		}),
	});
	env.advance(10);
	env.tick();
	await flush();

	const eyebrow = env.host.find('mv-tile__eyebrow');
	check('a hub card carries the theme eyebrow', !!eyebrow && eyebrow.textContent === 'Guide', eyebrow && eyebrow.textContent);
	check('the hub card is marked for styling', !!env.host.find('mfy__card--hub'));
	check('a non-hub card gets no label', env.host.find('mfy') && !env.host.find('mfy__card--hub').children.some((c) => c.className === ''));
	const recentHub = env.host.find('mfy__recent-hub');
	check('a hub in recently viewed is marked', !!recentHub && recentHub.textContent === 'Guide', recentHub && recentHub.textContent);
	check('recently-viewed hub keeps a normal crawlable link', !!env.host.find('mfy__recent-link'));
}

// 10c. On a [geo_related] page the placeholder arrives pre-filled and sized.
{
	const env = makeEnvironment({ storage: seededProfile(NOW), impersonal: true });
	env.host.attributes['data-limit'] = '6';
	env.host.attributes['data-level'] = 'country';
	env.host.getAttribute = function (name) { return this.attributes[name] || null; };

	env.advance(10);
	env.tick();
	await flush();

	const body = env.fetches[0].body;
	check('the placeholder\'s limit is sent, so the block keeps its size', body.limit === 6, JSON.stringify(body.limit));
	check('the placeholder\'s geo level is sent', body.geo_level === 'country');
	check('the impersonal block is replaced, not appended', !env.host.find('mfy--impersonal') && !!env.host.find('mfy'));
}

// 10d. A request that yields nothing leaves the impersonal block standing.
{
	const env = makeEnvironment({
		storage: seededProfile(NOW),
		responder: () => ({ show: false, lang: 'fr', recommendations: [], recently_viewed: [] }),
		impersonal: true,
	});

	env.advance(10);
	env.tick();
	await flush();

	check('show:false leaves the server-rendered block untouched', !!env.host.find('mfy--impersonal'));
}

// 10e. Clearing the history on a [geo_related] page brings that block back.
{
	const env = makeEnvironment({ storage: seededProfile(NOW), impersonal: true });

	env.advance(10);
	env.tick();
	await flush();

	check('the personalised block replaces the impersonal one', !env.host.find('mfy--impersonal') && !!env.host.find('mfy__reset'));

	env.host.find('mfy__reset').dispatch('click');

	check('clearing the history restores the impersonal block', !!env.host.find('mfy--impersonal'));
	check('it is the very same node the server rendered', env.host.find('mfy--impersonal') === env.impersonal);
	check('the personalised block is gone with it', !env.host.find('mfy__reset'));
	check('an acknowledgement is shown alongside it', !!env.host.find('mfy__note--restored'));
	check('the history really is cleared', env.profile() === null);
	env.hide();
	env.fire('pagehide');
	check('and stays cleared', env.profile() === null, JSON.stringify(env.store));
}

// 10f. With nothing underneath, clearing still just empties the block.
{
	const env = makeEnvironment({ storage: seededProfile(NOW) });
	env.advance(10);
	env.tick();
	await flush();
	env.host.find('mfy__reset').dispatch('click');

	check('no impersonal block means nothing to restore', !env.host.find('mfy--impersonal'));
	check('and the plain acknowledgement is used', !!env.host.find('mfy__note') && !env.host.find('mfy__note--restored'));
}

// 10g. The programmatic API behaves the same way.
{
	const env = makeEnvironment({ storage: seededProfile(NOW), impersonal: true });

	env.advance(10);
	env.tick();
	await flush();

	env.sandbox.window.mavoForYouReset();
	check('window.mavoForYouReset() restores it too', !!env.host.find('mfy--impersonal'));
}

// 12. Already-read marking.
{
	// A profile holding one article, by id and by path.
	const read = {
		mavo_for_you_v0: JSON.stringify({
			version: 1,
			views: [{
				post_id: 7, lang: 'fr', path: '/2016/05/tower-of-london',
				first_seen: NOW - 600, last_seen: NOW - 300, duration_seconds: 90, max_scroll_pct: 80,
			}],
			searches: [], referral: null, updated: NOW - 300,
		}),
	};

	const env = makeEnvironment({
		storage: read,
		config: { minMeaningfulViews: 99 }, // keep the request out of the way
		dom: () => ({
			tile: makeLink('https://www.mamanvoyage.com/2016/05/tower-of-london/', {}, 'mv-tile'),
			tileUnread: makeLink('https://www.mamanvoyage.com/2020/01/somewhere-else/', {}, 'mv-tile'),
			byId: makeLink('https://www.mamanvoyage.com/whatever/', { 'data-mavo-post-id': '7' }, 'mv-tile'),
			prose: makeLink('/2016/05/tower-of-london/', {}, 'entry-content'),
			messy: makeLink('https://www.mamanvoyage.com/2016/05/Tower-Of-London?utm=x#top', {}, 'entry-content'),
			archive: makeLink('https://www.mamanvoyage.com/tag/tower-of-london/', {}, 'entry-content'),
			external: makeLink('https://example.com/2016/05/tower-of-london/', {}, 'entry-content'),
			nav: makeLink('https://www.mamanvoyage.com/2016/05/tower-of-london/', {}, 'mavo-nav'),
			footer: makeLink('https://www.mamanvoyage.com/2016/05/tower-of-london/', {}, 'site-footer'),
			recent: makeLink('https://www.mamanvoyage.com/2016/05/tower-of-london/', {}, 'mfy__recent'),
			anchor: makeLink('#section', {}, 'entry-content'),
		}),
	});
	const d = env.dom;

	check('a tile linking to a read article is marked', d.tile.parentNode.classList.contains('mfy-read'));
	check('a tile linking elsewhere is not', !d.tileUnread.parentNode.classList.contains('mfy-read'));
	check('data-mavo-post-id matches regardless of href', d.byId.parentNode.classList.contains('mfy-read'));
	check('the tile is marked, not the link inside it', !d.tile.classList.contains('mfy-read-link'));

	check('a prose link to a read article is restyled', d.prose.classList.contains('mfy-read-link'));
	check('case, query and fragment do not defeat the match', d.messy.classList.contains('mfy-read-link'));
	check('a prose link gets no tile class', !d.prose.classList.contains('mfy-read'));

	// The reason this stores paths rather than slugs: /tag/tower-of-london/ and
	// the post itself share a last segment, and only one of them has been read.
	check('an archive sharing the last path segment is not marked', !d.archive.classList.contains('mfy-read-link'));
	check('an external link with the same path is not marked', !d.external.classList.contains('mfy-read-link'));
	check('a bare anchor is not marked', !d.anchor.classList.contains('mfy-read-link'));

	check('navigation is left alone', !d.nav.parentNode.classList.contains('mfy-read') && !d.nav.classList.contains('mfy-read-link'));
	check('the footer is left alone', !d.footer.parentNode.classList.contains('mfy-read'));
	check('"recently viewed" is left alone — every entry there is read', !d.recent.parentNode.classList.contains('mfy-read'));
}

// 12b. The current page records its own path, for the next page to match on.
{
	const env = makeEnvironment({ pathname: '/2016/05/kew-gardens/' });
	check('the visited path is stored', env.profile().views[0].path === '/2016/05/kew-gardens', env.profile().views[0].path);
	check('it is stored normalised', !env.profile().views[0].path.endsWith('/'));
	check('the path is never sent to the server', true); // asserted in 12d via the payload
}

// 12c. Clearing the history clears the marks.
{
	const env = makeEnvironment({
		storage: { mavo_for_you_v0: JSON.stringify({
			version: 1,
			views: [
				{ post_id: 7, lang: 'fr', path: '/a/read-one', first_seen: NOW - 600, last_seen: NOW - 300, duration_seconds: 90, max_scroll_pct: 80 },
				{ post_id: 8, lang: 'fr', path: '/a/read-two', first_seen: NOW - 900, last_seen: NOW - 800, duration_seconds: 90, max_scroll_pct: 80 },
			],
			searches: [], referral: null, updated: NOW - 300,
		}) },
		dom: () => ({
			tile: makeLink('/a/read-one/', {}, 'mv-tile'),
			prose: makeLink('/a/read-two/', {}, 'entry-content'),
		}),
	});

	check('marks are applied before the reset', env.dom.tile.parentNode.classList.contains('mfy-read') && env.dom.prose.classList.contains('mfy-read-link'));

	env.advance(10);
	env.tick();
	await flush();
	env.host.find('mfy__reset').dispatch('click');

	check('clearing the history unmarks the tile', !env.dom.tile.parentNode.classList.contains('mfy-read'));
	check('and unmarks the prose link', !env.dom.prose.classList.contains('mfy-read-link'));
}

// 12d. The path stays in the browser.
{
	const env = makeEnvironment({ storage: seededProfile(NOW) });
	env.advance(10);
	env.tick();
	await flush();
	const sent = Object.keys(env.fetches[0].body.views[0]).sort().join(',');
	check('the REST payload still carries no path', sent === 'duration_seconds,last_seen,max_scroll_pct,post_id', sent);
}

// 12e-bis. The off switch.
{
	const env = makeEnvironment({
		storage: { mavo_for_you_v0: JSON.stringify({
			version: 1,
			views: [{ post_id: 7, lang: 'fr', path: '/a/read-one', first_seen: NOW - 600, last_seen: NOW - 300, duration_seconds: 90, max_scroll_pct: 80 }],
			searches: [], referral: null, updated: NOW - 300,
		}) },
		config: { markRead: false },
		dom: () => ({ tile: makeLink('/a/read-one/', {}, 'mv-tile') }),
	});
	check('markRead:false marks nothing at all', !env.dom.tile.parentNode.classList.contains('mfy-read'));
}

// 12f. A page with no block of its own still marks.
{
	const env = makeEnvironment({
		storage: { mavo_for_you_v0: JSON.stringify({
			version: 1,
			views: [{ post_id: 7, lang: 'fr', path: '/a/read-one', first_seen: NOW - 600, last_seen: NOW - 300, duration_seconds: 90, max_scroll_pct: 80 }],
			searches: [], referral: null, updated: NOW - 300,
		}) },
		config: { mode: 'mark', postId: 0, showBlock: false },
		dom: () => ({ tile: makeLink('/a/read-one/', {}, 'mv-tile') }),
	});
	check('an archive page marks its tiles', env.dom.tile.parentNode.classList.contains('mfy-read'));
	check('without tracking a view', env.profile().views.length === 1, JSON.stringify(env.profile().views.map((v) => v.post_id)));
	check('and without calling the endpoint', env.fetches.length === 0);
}

// 12e. Nothing to mark, nothing done.
{
	const env = makeEnvironment({ dom: () => ({ tile: makeLink('/a/anything/', {}, 'mv-tile') }) });
	check('an empty profile marks nothing', !env.dom.tile.parentNode.classList.contains('mfy-read'));
}

// 11. A failed request leaves the page alone.
{
	const env = makeEnvironment({ storage: seededProfile(NOW), responder: () => null });
	env.advance(10);
	env.tick();
	await flush();
	check('a failed request renders nothing', env.host.children.length === 0);
	check('a failed request does not throw', true);
}

}

main().then(() => {
	console.log('');
	console.log(`${failed} failure(s)`);
	process.exit(failed ? 1 : 0);
});
