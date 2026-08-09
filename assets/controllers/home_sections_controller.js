import { Controller } from '@hotwired/stimulus';

/**
 * The Discover "Customize" panel: opens the overlay, then persists whatever the
 * nested orderable-list controller has serialized into the hidden payload input
 * (a `[{ id, enabled }]` list in display order). Order is every row's id, hidden
 * is the ids whose checkbox is cleared. Saving reloads the page so the server
 * re-renders the rows — hidden sections are never built server-side, so there is
 * nothing to hide client-side.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['panel', 'payload', 'error'];
    static values = { url: String, token: String };

    open() {
        this.hideError();
        this.panelTarget.hidden = false;
        document.body.classList.add('book-modal-open');
    }

    close() {
        this.panelTarget.hidden = true;
        document.body.classList.remove('book-modal-open');
    }

    backdropClick(event) {
        if (event.target === this.panelTarget) this.close();
    }

    save() {
        const rows = this.rows();
        this.post({
            order: rows.map((r) => r.id),
            hidden: rows.filter((r) => r.enabled === false).map((r) => r.id),
        });
    }

    reset() {
        this.post({ reset: true });
    }

    rows() {
        if (!this.hasPayloadTarget) return [];
        try {
            const parsed = JSON.parse(this.payloadTarget.value || '[]');
            return Array.isArray(parsed) ? parsed.filter((r) => r && typeof r.id === 'string') : [];
        } catch (e) {
            return [];
        }
    }

    async post(body) {
        this.hideError();
        try {
            const res = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ ...body, _token: this.tokenValue }),
            });
            let data = null;
            try {
                data = await res.json();
            } catch (e) {
                // Non-JSON body (e.g. an HTML error page) — the status check below reports it.
            }
            if (!res.ok || !data?.ok) {
                this.showError(data?.error || `Could not save your layout (HTTP ${res.status}).`);
                return;
            }
            window.location.reload();
        } catch (e) {
            this.showError('Could not save your layout.');
        }
    }

    showError(message) {
        if (!this.hasErrorTarget) return;
        this.errorTarget.textContent = message;
        this.errorTarget.hidden = false;
    }

    hideError() {
        if (!this.hasErrorTarget) return;
        this.errorTarget.hidden = true;
        this.errorTarget.textContent = '';
    }
}
