import { Controller } from '@hotwired/stimulus';

/**
 * Settings "Refresh now" button. POSTs a CSRF token to the endpoint, which runs one
 * freeleech sweep synchronously against the CURRENTLY SAVED config and returns
 * { ok, message, summary }. Unlike the connection test this can take the better part of
 * a minute — paged tracker fetches plus paced Hardcover lookups — so the button stays
 * disabled and the note stays live for the whole run.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['button', 'result'];
    static values = { url: String, token: String };

    async run() {
        const original = this.buttonTarget.textContent;
        this.buttonTarget.disabled = true;
        this.buttonTarget.textContent = 'Refreshing…';
        this.resultTarget.hidden = false;
        this.resultTarget.className = 'form-note';
        this.resultTarget.textContent = 'Pulling the freeleech catalog — this can take a minute…';

        try {
            const res = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
                body: new URLSearchParams({ _token: this.tokenValue }),
            });
            const data = await res.json();
            const ok = res.ok && data.ok;
            this.resultTarget.className = ok ? 'flash flash-success' : 'flash flash-error';
            this.resultTarget.textContent = data.message || (ok ? 'Refreshed.' : `HTTP ${res.status}`);
        } catch (e) {
            this.resultTarget.className = 'flash flash-error';
            this.resultTarget.textContent = e.message;
        } finally {
            this.buttonTarget.disabled = false;
            this.buttonTarget.textContent = original;
        }
    }
}
