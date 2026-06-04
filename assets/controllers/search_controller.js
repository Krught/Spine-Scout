import { Controller } from '@hotwired/stimulus';

const VALID_TYPES = ['title', 'author', 'genre', 'series', 'publisher'];
const ICON_TYPES = ['title', 'author', 'genre'];
const STORAGE_KEY = 'spinescout.searchType';

export default class extends Controller {
    static targets = ['input', 'typeButton'];
    static values = { browseUrl: String, currentRoute: String, type: String };

    connect() {
        const url = new URL(window.location.href);
        const initial = url.searchParams.get('q');
        if (initial && this.hasInputTarget) {
            this.inputTarget.value = initial;
        }

        let initialType = url.searchParams.get('type');
        if (!VALID_TYPES.includes(initialType)) {
            initialType = null;
        }
        // A hidden lens (series/publisher) may only be shown while we're actively
        // viewing that search — i.e. it arrives in the URL alongside a query. A hidden
        // type with no query, or no URL type at all, falls back to the last remembered
        // shown lens; hidden lenses are never restored from persistence.
        if (initialType && !ICON_TYPES.includes(initialType) && !initial) {
            initialType = null;
        }
        this.typeValue = initialType || this.storedShownType();
        this.reflectActiveButton();
    }

    // The last remembered shown lens (title/author/genre), or 'title' by default.
    // Hidden lenses persisted by older code are ignored here.
    storedShownType() {
        try {
            const stored = window.localStorage.getItem(STORAGE_KEY);
            if (ICON_TYPES.includes(stored)) return stored;
        } catch (_) { /* localStorage unavailable */ }
        return 'title';
    }

    toggleMobile(event) {
        if (event) event.preventDefault();
        const expanded = this.element.classList.toggle('is-expanded');
        if (expanded && this.hasInputTarget) {
            window.requestAnimationFrame(() => this.inputTarget.focus());
        }
    }

    collapseMobile() {
        this.element.classList.remove('is-expanded');
    }

    onInputKeydown(event) {
        if (event.key === 'Escape') {
            this.collapseMobile();
            if (this.hasInputTarget) this.inputTarget.blur();
        }
    }

    setType(event) {
        if (event) event.preventDefault();
        const btn = event && event.currentTarget;
        const next = btn && btn.dataset.searchTypeParam;
        if (!VALID_TYPES.includes(next)) return;
        this.setActiveType(next, { submit: this.inputTarget.value.trim() !== '' });
    }

    // Public: other controllers (book modal, author modal, home) call this before navigating
    // so the icon state in the bar reflects the type of search they're triggering.
    setActiveType(type, options = {}) {
        if (!VALID_TYPES.includes(type)) type = 'title';
        this.typeValue = type;
        try { window.localStorage.setItem(STORAGE_KEY, type); } catch (_) { /* ignore */ }
        this.reflectActiveButton();
        if (options.submit) this.submit();
    }

    submit(event) {
        if (event) event.preventDefault();
        const query = this.inputTarget.value.trim();

        // Clearing the box drops any active hidden lens (series/publisher) back to the
        // last remembered shown lens — a hidden lens is only valid with an active query.
        if (query === '' && !ICON_TYPES.includes(this.typeValue)) {
            this.typeValue = this.storedShownType();
            this.reflectActiveButton();
        }
        const type = VALID_TYPES.includes(this.typeValue) ? this.typeValue : 'title';

        if (this.currentRouteValue === 'browse') {
            window.dispatchEvent(new CustomEvent('search:submit', { detail: { query, type } }));
            return;
        }

        if (query === '') return;
        const params = new URLSearchParams({ q: query });
        if (type !== 'title') params.set('type', type);
        window.location.href = `${this.browseUrlValue}?${params.toString()}`;
    }

    reflectActiveButton() {
        if (!this.hasTypeButtonTarget) return;
        const active = this.typeValue;
        this.typeButtonTargets.forEach((btn) => {
            const btnType = btn.dataset.searchTypeParam;
            const isActive = btnType === active;
            btn.classList.toggle('is-active', isActive);
            btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }
}
