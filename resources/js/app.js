// Loaded once via @vite and preserved across Livewire wire:navigate SPA
// transitions, so the theme store and toast helper stay defined on every screen.

// The chosen scheme is mirrored into a COOKIE, not just localStorage, because
// the server renders `data-theme` onto <html> (App\Support\Theme) and only a
// cookie reaches the server. Without that, `wire:navigate` swaps in a document
// whose <html> carries no attribute, the CSS falls back to its light `:root`
// default, and dark mode is lost on every navigation.
//
// Not `Secure`, deliberately: the flag would drop the cookie over plain HTTP,
// and this same build runs on `php artisan serve` during development. It holds
// "dark" or "light" — no identity, nothing worth protecting in transit — and the
// server whitelists both values before rendering either.
const THEME_KEY = 'bbt-theme';

function persistTheme(v) {
    try { localStorage.setItem(THEME_KEY, v); } catch (e) {}
    try {
        document.cookie = THEME_KEY + '=' + v + ';path=/;max-age=31536000;samesite=Lax';
    } catch (e) {}
}

function applyTheme(v) {
    document.documentElement.setAttribute('data-theme', v);
}

document.addEventListener('alpine:init', () => {
    window.Alpine.store('theme', {
        v: document.documentElement.getAttribute('data-theme') || 'dark',
        toggle() {
            this.v = this.v === 'dark' ? 'light' : 'dark';
            applyTheme(this.v);
            persistTheme(this.v);
        },
    });
});

// Safety net for the swap, not the mechanism.
//
// The server now renders the right attribute into every page Livewire fetches,
// so this should find nothing to do. It still runs because the one case the
// cookie cannot cover is a browser with cookies disabled — there the server
// renders the default, and this restores the visitor's actual choice from
// localStorage the moment the new DOM lands.
//
// Reads the Alpine store first: it is the live value, and it is right even in
// the instant after a toggle when the cookie has not been read back yet.
document.addEventListener('livewire:navigated', () => {
    try {
        const wanted = window.Alpine?.store('theme')?.v
            || localStorage.getItem(THEME_KEY)
            || 'dark';

        if (document.documentElement.getAttribute('data-theme') !== wanted) {
            applyTheme(wanted);
        }
    } catch (e) {}
});

// ---- Input masks -----------------------------------------------------------
//
// Typed into the exact shapes the server stores, so what the officer reads back
// is what lands in the database and the two can never disagree. Both are pure
// string functions of the digits entered: they only ever insert separators, so
// backspacing through one behaves the way people expect.
//
// The canonical forms are defined by App\Support\Contact, keep them in step.

// 3520114058987 -> 35201-1405898-7
window.bbtMaskCnic = function (raw) {
    const d = String(raw).replace(/\D+/g, '').slice(0, 13);
    if (d.length <= 5) return d;
    if (d.length <= 12) return d.slice(0, 5) + '-' + d.slice(5);
    return d.slice(0, 5) + '-' + d.slice(5, 12) + '-' + d.slice(12);
};

// 03004481220 / 923004481220 / 3004481220 -> +92 300 4481220
window.bbtMaskPhone = function (raw) {
    let d = String(raw).replace(/\D+/g, '');
    if (d.startsWith('92')) d = d.slice(2);
    else if (d.startsWith('0')) d = d.slice(1);
    d = d.slice(0, 10);
    if (!d) return '';
    if (d.length <= 3) return '+92 ' + d;
    return '+92 ' + d.slice(0, 3) + ' ' + d.slice(3);
};

// Applies a mask to an input without fighting Livewire: rewrite the value, then
// tell Livewire to re-read it, otherwise wire:model keeps the pre-mask string.
window.bbtApplyMask = function (el, fn) {
    const before = el.value;
    const masked = fn(before);
    if (masked === before) return;

    // Keep the caret at the end when typing forward, which is the only case
    // where a mask visibly moves it.
    const atEnd = el.selectionStart === before.length;
    el.value = masked;
    if (atEnd) el.setSelectionRange(masked.length, masked.length);
    el.dispatchEvent(new Event('input', { bubbles: true }));
};

// ---- Navigation progress ---------------------------------------------------
//
// wire:navigate fetches the next page over the wire, so a slow query reads as a
// dead click. A 2px bar creeping across the top says "received, working" without
// the layout shift a skeleton would cause on every single navigation.
(function () {
    let bar = null;
    let timer = null;

    const start = () => {
        if (bar) return;
        bar = document.createElement('div');
        bar.className = 'nav-progress';
        document.body.appendChild(bar);
        requestAnimationFrame(() => bar && bar.classList.add('is-running'));
    };

    const done = () => {
        clearTimeout(timer);
        if (!bar) return;
        const el = bar;
        bar = null;
        el.classList.add('is-done');
        setTimeout(() => el.remove(), 260);
    };

    // Only show it if the navigation is slow enough to notice. Under ~120ms a
    // flashing bar is more distracting than the wait it describes.
    document.addEventListener('livewire:navigate', () => {
        clearTimeout(timer);
        timer = setTimeout(start, 120);
    });
    document.addEventListener('livewire:navigated', done);
})();

// Toast host component (spec §9.11). Bottom-right, auto-dismiss ~3.4s.
window.bbtToasts = function () {
    const icons = { ok: '✓', info: 'i', warn: '!', err: '✕' };
    return {
        items: [],
        push(d) {
            const id = Date.now() + Math.random();
            // `note` is the quiet third line, used to say a duplicate
            // submission was ignored. Deliberately not an error tone: the
            // officer did nothing wrong, and the thing they asked for did
            // happen — just once rather than twice.
            this.items.push({
                id, tone: d.tone || 'info', icon: icons[d.tone] || 'i',
                title: d.title || '', msg: d.msg || '', note: d.note || '',
            });
            setTimeout(() => { this.items = this.items.filter((t) => t.id !== id); }, 3400);
        },
    };
};

// ---- Connection banner -----------------------------------------------------
//
// The failure this exists for: the counter's internet drops, the officer clicks
// "Record payment", and NOTHING happens. No error, no spinner, no change — a
// Livewire request that never reaches the server fails silently by default. The
// rational response to a dead button is to press it again, which is the last
// thing anyone wants on a payment, and the officer has no way to know whether
// the first press was recorded.
//
// So every state where the server is unreachable gets said out loud, in words
// aimed at somebody with a parent waiting at the desk rather than at a
// developer reading a console.
//
// Three distinct cases, because the right thing to do differs:
//   offline      the browser knows there is no network. Wait.
//   maintenance  503: a deploy is in progress. Seconds, not minutes.
//   unreachable  the request left and nothing came back, or the server errored.
(function () {
    let el = null;
    let current = null;

    const MESSAGES = {
        offline: ['warn', 'No internet connection', 'Your last action was not saved. It will work again as soon as the connection returns — nothing has been lost.'],
        maintenance: ['info', 'The system is briefly unavailable', 'This is usually a short update and clears on its own. Your last action was not saved — try it again in a moment.'],
        unreachable: ['err', 'Could not reach the server', 'Your last action was not saved. Check the connection and try again — do not assume it went through.'],
    };

    function show(kind) {
        if (current === kind) return;
        current = kind;
        const [tone, title, detail] = MESSAGES[kind];
        if (!el) {
            el = document.createElement('div');
            el.className = 'conn-banner';
            el.setAttribute('role', 'status');
            // aria-live so a screen reader announces it; the banner is the only
            // notice that a click did nothing.
            el.setAttribute('aria-live', 'polite');
            document.body.appendChild(el);
        }
        el.dataset.tone = tone;
        el.innerHTML = '<strong></strong><span></span>';
        el.querySelector('strong').textContent = title;
        el.querySelector('span').textContent = detail;
    }

    function hide() {
        current = null;
        if (el) { el.remove(); el = null; }
    }

    /**
     * Which of the three it actually is, established rather than assumed.
     *
     * `/up` is the framework's own health route and answers in a few bytes, so
     * this costs nothing and only ever runs after something has already failed.
     * `cache: 'no-store'` because a cached 200 from before the outage would be
     * the one answer that could not be trusted.
     */
    let lastProbe = 0;
    let lastVerdict = null;

    async function classify() {
        if (navigator.onLine === false) return 'offline';

        // One probe per five seconds. A burst of failed requests is exactly the
        // moment the server is least able to answer more of them, and every
        // browser in the institute would otherwise pile on together.
        const now = Date.now();
        if (lastVerdict && now - lastProbe < 5000) return lastVerdict;
        lastProbe = now;

        try {
            // `/ready`, NOT `/up`.
            //
            // `/up` is a LIVENESS probe and is deliberately excepted from
            // maintenance mode, so it answers 200 throughout a deploy —
            // measured, not assumed. Probing it would have reported a deploy as
            // a network failure, which is the exact misdiagnosis this function
            // exists to prevent. `/ready` is not excepted: 503 while the site
            // is down for a release, 503 if the database or cache store is
            // unusable, 200 only when a request that touches money would work.
            //
            // Both of its 503s get the same wording on purpose. "Briefly
            // unavailable, try again shortly" is true of a deploy AND of a
            // degraded box, and it is the same thing to do either way.
            const res = await fetch('/ready', { method: 'GET', cache: 'no-store' });
            lastVerdict = res.status === 503 ? 'maintenance' : 'unreachable';
        } catch (e) {
            // Nothing came back at all: the network, not the application.
            lastVerdict = navigator.onLine === false ? 'offline' : 'unreachable';
        }

        return lastVerdict;
    }

    window.addEventListener('offline', () => show('offline'));
    window.addEventListener('online', hide);
    if (navigator.onLine === false) show('offline');

    document.addEventListener('livewire:init', () => {
        window.Livewire.hook('request', ({ fail, succeed }) => {
            // Any answer at all means the server is there. Clearing on success
            // rather than on a timer is what stops the banner outliving the
            // outage it describes.
            succeed(() => { lastVerdict = null; if (navigator.onLine !== false) hide(); });

            fail(({ status }) => {
                // 419 and 422 are the server answering properly and Livewire
                // handles them itself; claiming a connection problem there
                // would be a lie in the other direction.
                if (status && status < 500 ) return;

                // Livewire reports status 503 for a request that never
                // completed at all — a dropped wifi, DNS failing, the laptop
                // lid closing mid-click. It is indistinguishable at this point
                // from the real 503 that `artisan down` serves during a deploy,
                // and the two need OPPOSITE advice: "wait a few seconds, it is
                // coming back" versus "your connection is gone, check it".
                //
                // Telling an officer the system is updating when their internet
                // has actually died is the worse mistake of the two: they wait
                // at a desk with a parent in front of them for something that
                // will never resolve on its own. So we ask the server directly
                // instead of guessing from a status Livewire had to invent.
                classify().then(show);
            });
        });
    });
})();
