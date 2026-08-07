import { Controller } from '@hotwired/stimulus';

/**
 * "Link torrent" per-row action on the Requests page: lists the torrents
 * currently in the download client's category in a small popover under the
 * button, and POSTs the picked hash to the link endpoint — which creates a
 * DOWNLOADING job the torrent poller then finalizes into the library exactly
 * like an automatic grab. The response is the same toast + re-rendered row
 * contract the request-actions controller uses, so the row swaps in place.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    disconnect() {
        this.closePanel();
    }

    async open(event) {
        const button = event.currentTarget;
        const { optionsUrl, linkUrl, token } = event.params;

        // Second click on the same button closes the open panel instead of re-fetching.
        if (this.panel) {
            const reopen = this.panelButton !== button;
            this.closePanel();
            if (!reopen) return;
        }

        if (button.disabled) return;
        button.disabled = true;
        button.classList.add('is-busy');
        try {
            const res = await fetch(optionsUrlParam, { headers: { Accept: 'application/json' } });
            let data = null;
            try {
                data = await res.json();
            } catch (e) {
                // Non-JSON body (e.g. an HTML error page) — fall through to the status check.
            }
            if (!res.ok || !data?.ok) {
                this.toast(data?.message || `Could not list torrents (HTTP ${res.status}).`, 'error');
                return;
            }
            if (!data.torrents?.length) {
                this.toast('No torrents found in the download client.', 'error');
                return;
            }
            this.openPanel(button, data.torrents, linkUrl, token);
        } catch (e) {
            this.toast('Network error — could not list torrents.', 'error');
        } finally {
            button.disabled = false;
            button.classList.remove('is-busy');
        }
    }

    openPanel(button, torrents, linkUrl, token) {
        const panel = document.createElement('div');
        panel.className = 'link-torrent-panel';
        panel.setAttribute('role', 'listbox');

        for (const t of torrents) {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'link-torrent-item';

            const name = document.createElement('span');
            name.className = 'link-torrent-name';
            name.textContent = t.name;
            name.title = t.name;

            const meta = document.createElement('span');
            meta.className = 'link-torrent-meta';
            const parts = [t.completed ? 'complete' : `${t.state} — ${Math.round(t.progress)}%`];
            if (t.sizeBytes) parts.push(this.humanSize(t.sizeBytes));
            if (t.linked) parts.push('linked');
            meta.textContent = parts.join(' · ');

            item.append(name, meta);
            if (t.linked) {
                item.disabled = true;
                item.classList.add('is-linked');
            } else {
                item.addEventListener('click', () => this.link(t.id, linkUrl, token));
            }
            panel.appendChild(item);
        }

        // Anchor under the button: the actions column is the positioning context.
        button.parentElement.appendChild(panel);
        this.panel = panel;
        this.panelButton = button;

        this.onDocumentClick = (e) => {
            if (!panel.contains(e.target) && e.target !== button) this.closePanel();
        };
        this.onDocumentKeydown = (e) => {
            if (e.key === 'Escape') this.closePanel();
        };
        document.addEventListener('click', this.onDocumentClick);
        document.addEventListener('keydown', this.onDocumentKeydown);
    }

    closePanel() {
        this.panel?.remove();
        this.panel = null;
        this.panelButton = null;
        if (this.onDocumentClick) {
            document.removeEventListener('click', this.onDocumentClick);
            this.onDocumentClick = null;
        }
        if (this.onDocumentKeydown) {
            document.removeEventListener('keydown', this.onDocumentKeydown);
            this.onDocumentKeydown = null;
        }
    }

    async link(hash, linkUrl, token) {
        const row = this.panel?.closest('.request-row');
        this.closePanel();

        const body = new FormData();
        body.append('hash', hash);
        body.append('_csrf_token', token);
        try {
            const res = await fetch(linkUrl, {
                method: 'POST',
                body,
                headers: { Accept: 'application/json' },
            });
            let data = null;
            try {
                data = await res.json();
            } catch (e) {
                // Non-JSON body (e.g. an HTML error page) — fall through to the status check.
            }
            if (!res.ok || !data?.ok) {
                this.toast(data?.message || `Action failed (HTTP ${res.status}).`, 'error');
                return;
            }
            if (data.row && row) {
                row.outerHTML = data.row;
            }
            this.toast(data.message || 'Done.', 'success');
            this.refreshFilters();
        } catch (e) {
            this.toast('Network error — action not applied.', 'error');
        }
    }

    humanSize(bytes) {
        if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
        return `${Math.max(1, Math.round(bytes / 1024 ** 2))} MB`;
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
