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

function makeElement(tag) {
	return {
		tagName: tag,
		className: '',
		textContent: '',
		children: [],
		attributes: {},
		listeners: {},
		classList: { add() {}, remove() {} },
		set innerHTML(value) { if (value === '') { this.children = []; } },
		get innerHTML() { return ''; },
		appendChild(child) { this.children.push(child); return child; },
		setAttribute(key, value) { this.attributes[key] = value; },
		addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); },
		dispatch(type) { (this.listeners[type] || []).forEach((fn) => fn({})); },
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
}

function makeEnvironment(options = {}) {
	const store = Object.assign({}, options.storage || {});
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
			readyState: 'complete',
			visibilityState: options.visibility || 'visible',
			documentElement: { scrollHeight: 4000, offsetHeight: 4000, clientHeight: 800, scrollTop: 0 },
			body: { scrollHeight: 4000, offsetHeight: 4000 },
			referrer: options.referrer || '',
			addEventListener(type, fn) { (documentListeners[type] = documentListeners[type] || []).push(fn); },
			getElementById(id) { return id === 'mavo-for-you' ? host : null; },
			createElement: makeElement,
		},
		location: { search: options.search || '', hostname: 'www.mamanvoyage.com' },
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
		labels: { heading: 'Pour vous', subtitle: 'Sous-titre', recent: 'Consultés récemment', reset: 'Effacer mon historique', resetHint: 'Efface…', resetDone: 'Historique effacé.' },
	}, options.config || {});

	vm.createContext(sandbox);
	vm.runInContext(SOURCE, sandbox);

	return {
		sandbox, host, store, fetches, timers,
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
