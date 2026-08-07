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
        if (event.params.torrent) {
            // Delete with an active torrent: the keep/remove/cancel dialog replaces
            // the plain confirm (it carries its own Cancel).
            this.openTorrentDialog(form, event.params.torrentState || 'downloading');
            return;
        }
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
            if (data.needsTorrentDecision) {
                // The server saw an active torrent but no decision (e.g. the row's
                // param was stale) — nothing was deleted; ask now and re-submit.
                this.openTorrentDialog(form, data.torrentState || 'downloading');
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

    /**
     * Modal choice for deleting a request whose torrent is still in the download
     * client: keep it seeding (the server re-tags it), remove it (and its files)
     * from the client, or cancel. The chosen action rides along as a hidden
     * `torrent_action` input on the same form, re-submitted through send().
     * Built in JS (no template markup): overlay + dialog, Esc/backdrop = cancel.
     */
    openTorrentDialog(form, state) {
        if (form.dataset.torrentDialogOpen) return;
        form.dataset.torrentDialogOpen = '1';

        const seeding = state === 'seeding';
        const backdrop = document.createElement('div');
        backdrop.className = 'torrent-decision-modal';
        backdrop.innerHTML = `
            <div class="torrent-decision-dialog" role="dialog" aria-modal="true" aria-labelledby="torrent-decision-title">
                <h3 class="torrent-decision-title" id="torrent-decision-title">This request has an active torrent</h3>
                <p class="torrent-decision-body">
                    ${seeding
                        ? 'Its download is complete and the torrent is still seeding in the download client.'
                        : 'Its torrent is still downloading in the download client.'}
                    Keep it seeding (it will be tagged so it’s easy to spot), or remove it — and its files — from the client?
                </p>
                <div class="torrent-decision-actions">
                    <button type="button" class="request-btn torrent-decision-keep">Keep seeding</button>
                    <button type="button" class="request-btn request-btn-delete torrent-decision-remove">Remove from client</button>
                    <button type="button" class="request-btn torrent-decision-cancel">Cancel</button>
                </div>
            </div>`;

        let done = false;
        const close = () => {
            if (done) return;
            done = true;
            delete form.dataset.torrentDialogOpen;
            document.removeEventListener('keydown', onKeydown);
            backdrop.remove();
        };
        const choose = async (action) => {
            if (done) return;
            // Disable everything before the async send — double-submit protection.
            backdrop.querySelectorAll('button').forEach((b) => (b.disabled = true));
            let input = form.querySelector('input[name="torrent_action"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'torrent_action';
                form.appendChild(input);
            }
            input.value = action;
            close();
            await this.send(form);
        };
        const onKeydown = (e) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                close();
            }
        };

        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) close();
        });
        backdrop.querySelector('.torrent-decision-keep').addEventListener('click', () => choose('keep'));
        backdrop.querySelector('.torrent-decision-remove').addEventListener('click', () => choose('remove'));
        backdrop.querySelector('.torrent-decision-cancel').addEventListener('click', close);
        document.addEventListener('keydown', onKeydown);

        document.body.appendChild(backdrop);
        backdrop.querySelector('.torrent-decision-cancel').focus();
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
