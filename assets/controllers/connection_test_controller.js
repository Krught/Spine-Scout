import { Controller } from '@hotwired/stimulus';

/**
 * Settings connection tester. POSTs a CSRF token to the given endpoint, which runs
 * the integration's testConnection() against the CURRENTLY SAVED config and returns
 * { ok, message }. Renders the outcome inline. Because it tests saved settings, save
 * the form first — the button hint says so. The button is disabled while in flight.
 */
export default class extends Controller {
    static targets = ['button', 'result'];
    static values = { url: String, token: String };

    async run() {
        const original = this.buttonTarget.textContent;
        this.buttonTarget.disabled = true;
        this.buttonTarget.textContent = 'Testing…';
        this.resultTarget.hidden = false;
        this.resultTarget.className = 'form-note';
        this.resultTarget.textContent = 'Connecting…';

        try {
            const res = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
                body: new URLSearchParams({ _token: this.tokenValue }),
            });
            const data = await res.json();
            const ok = res.ok && data.ok;
            this.resultTarget.className = ok ? 'flash flash-success' : 'flash flash-error';
            this.resultTarget.textContent = data.message || (ok ? 'Connected.' : `HTTP ${res.status}`);
        } catch (e) {
            this.resultTarget.className = 'flash flash-error';
            this.resultTarget.textContent = e.message;
        } finally {
            this.buttonTarget.disabled = false;
            this.buttonTarget.textContent = original;
        }
    }
}
