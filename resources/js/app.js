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
