import { Controller } from '@hotwired/stimulus';

/**
 * "Link torrent" per-row action on the Requests page: opens a centered modal
 * listing the torrents currently in the download client's category, and POSTs
 * the picked hash to the link endpoint — which creates a DOWNLOADING job the
 * torrent poller then finalizes into the library exactly like an automatic
 * grab. The response is the same toast + re-rendered row contract the
 * request-actions controller uses, so the row swaps in place.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    disconnect() {
        this.closeModal();
    }

    async open(event) {
        const button = event.currentTarget;
        const { optionsUrl, linkUrl, token } = event.params;

        if (button.disabled) return;
        button.disabled = true;
        button.classList.add('is-busy');
        try {
            const res = await fetch(optionsUrl, { headers: { Accept: 'application/json' } });
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
            this.openModal(button.closest('.request-row'), data.torrents, linkUrl, token);
        } catch (e) {
            this.toast('Network error — could not list torrents.', 'error');
        } finally {
            button.disabled = false;
            button.classList.remove('is-busy');
        }
    }

    openModal(row, torrents, linkUrl, token) {
        this.closeModal();

        const overlay = document.createElement('div');
        overlay.className = 'link-torrent-modal';
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) this.closeModal();
        });

        const dialog = document.createElement('div');
        dialog.className = 'link-torrent-dialog';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-label', 'Link a torrent');

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'link-torrent-close';
        close.setAttribute('aria-label', 'Close');
        close.innerHTML = '&times;';
        close.addEventListener('click', () => this.closeModal());

        const title = document.createElement('h3');
        title.className = 'link-torrent-title';
        title.textContent = 'Link a torrent';

        const hint = document.createElement('p');
        hint.className = 'link-torrent-hint';
        hint.textContent = 'Pick the torrent in the download client that belongs to this request.';

        const list = document.createElement('div');
        list.className = 'link-torrent-list';
        list.setAttribute('role', 'listbox');

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
                item.addEventListener('click', () => this.link(t.id, row, linkUrl, token));
            }
            list.appendChild(item);
        }

        dialog.append(close, title, hint, list);
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);
        document.body.classList.add('link-torrent-modal-open');
        this.modal = overlay;

        this.onDocumentKeydown = (e) => {
            if (e.key === 'Escape') this.closeModal();
        };
        document.addEventListener('keydown', this.onDocumentKeydown);
        close.focus();
    }

    closeModal() {
        this.modal?.remove();
        this.modal = null;
        document.body.classList.remove('link-torrent-modal-open');
        if (this.onDocumentKeydown) {
            document.removeEventListener('keydown', this.onDocumentKeydown);
            this.onDocumentKeydown = null;
        }
    }

    async link(hash, row, linkUrl, token) {
        this.closeModal();

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
