import { Controller } from '@hotwired/stimulus';

const IDLE_SUBMIT_MS = 3000;
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
        if (!initialType) {
            try {
                const stored = window.localStorage.getItem(STORAGE_KEY);
                if (VALID_TYPES.includes(stored)) initialType = stored;
            } catch (_) { /* localStorage unavailable */ }
        }
        this.typeValue = initialType || 'title';
        this.reflectActiveButton();
    }

    disconnect() {
        this.clearTimer();
    }

    onInput() {
        this.clearTimer();
        const value = this.inputTarget.value.trim();
        if (value === '') return;
        this.timer = window.setTimeout(() => this.submit(), IDLE_SUBMIT_MS);
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
        this.clearTimer();
        const query = this.inputTarget.value.trim();
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

    clearTimer() {
        if (this.timer) {
            window.clearTimeout(this.timer);
            this.timer = null;
        }
    }
}
