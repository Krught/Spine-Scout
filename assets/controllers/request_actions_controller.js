import { Controller } from '@hotwired/stimulus';

/**
 * Submits the per-row action forms on the Requests page (approve / recheck /
 * rewrite sidecar / delete) in the background instead of a full POST+redirect,
 * so several rows can be acted on in quick succession. Each endpoint answers
 * JSON (selected via the Accept header) with a toast message plus either the
 * row's re-rendered HTML — swapped in place, bringing fresh status badge,
 * buttons and CSRF tokens — or `removed: true`, which drops the row. Filter
 * chip counts are then recomputed by the sibling requests-filter controller.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    async submit(event) {
        event.preventDefault();
        const form = event.target;
        if (event.params.confirm && !window.confirm(event.params.confirm)) {
            return;
        }

        await this.send(form);
    }

    async send(form) {
        const button = form.querySelector('button[type="submit"]');
        if (button?.disabled) return;
        if (button) {
            button.disabled = true;
            button.classList.add('is-busy');
        }

        const row = form.closest('.request-row');
        try {
            const res = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json' },
            });
            let data = null;
            try {
                data = await res.json();
            } catch (e) {
                // Non-JSON body (e.g. an HTML error page) — fall through to the status check.
            }
            if (!res.ok || !data?.ok) {
                if (data?.unavailable) {
                    await this.offerReget(data, row);
                    return;
                }
                this.toast(data?.message || `Action failed (HTTP ${res.status}).`, 'error');
                return;
            }
            if (data.removed) {
                row?.remove();
            } else if (data.row && row) {
                row.outerHTML = data.row;
            }
            this.toast(data.message || 'Done.', 'success');
            this.refreshFilters();
        } catch (e) {
            this.toast('Network error — action not applied.', 'error');
        } finally {
            // The button is usually gone (row swapped or removed); only restore a survivor.
            if (button?.isConnected) {
                button.disabled = false;
                button.classList.remove('is-busy');
            }
        }
    }

    /**
     * A reimport answered that the torrent's raw files are gone from the download
     * client. Offer the fallbacks the server said are viable: an automatic
     * re-download (the row's hidden re-get form, sent through the same fetch
     * path), else the row's interactive-search overlay, else just the message.
     */
    async offerReget(data, row) {
        if (data.canAuto && window.confirm('Original files are no longer available. Start an automatic re-download?')) {
            const regetForm = row?.querySelector('form[data-request-actions-reget]');
            if (regetForm) {
                await this.send(regetForm);
                return;
            }
        } else if (data.canSearch) {
            const searchButton = row?.querySelector('.request-btn-search');
            if (searchButton) {
                this.toast('Original files are gone — opening interactive search.', 'error');
                searchButton.click();
                return;
            }
        }
        this.toast(data.message || 'Original files are no longer available.', 'error');
    }

    refreshFilters() {
        this.application
            .getControllerForElementAndIdentifier(this.element, 'requests-filter')
            ?.refresh();
    }

    toast(message, kind) {
        let host = document.querySelector('.toast-host');
        if (!host) {
            host = document.createElement('div');
            host.className = 'toast-host';
            document.body.appendChild(host);
        }
        const el = document.createElement('div');
        el.className = `toast toast-${kind}`;
        el.setAttribute('role', 'status');
        el.textContent = message;
        host.appendChild(el);
        requestAnimationFrame(() => el.classList.add('is-visible'));
        setTimeout(() => {
            el.classList.remove('is-visible');
            el.addEventListener('transitionend', () => el.remove(), { once: true });
            setTimeout(() => el.remove(), 600); // in case transitionend never fires
        }, 3500);
    }
}
