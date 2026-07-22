// Loaded once via @vite and preserved across Livewire wire:navigate SPA
// transitions, so the theme store and toast helper stay defined on every screen.

document.addEventListener('alpine:init', () => {
    window.Alpine.store('theme', {
        v: document.documentElement.getAttribute('data-theme') || 'dark',
        toggle() {
            this.v = this.v === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', this.v);
            try { localStorage.setItem('bbt-theme', this.v); } catch (e) {}
        },
    });
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
            this.items.push({ id, tone: d.tone || 'info', icon: icons[d.tone] || 'i', title: d.title || '', msg: d.msg || '' });
            setTimeout(() => { this.items = this.items.filter((t) => t.id !== id); }, 3400);
        },
    };
};
