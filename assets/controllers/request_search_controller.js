import { Controller } from '@hotwired/stimulus';

/**
 * Hosts the shared Interactive Search panel on the Requests page, where there is
 * no book modal to live inside. A per-row "Interactive Search" button (admins,
 * unfulfilled rows only) calls `open` with the row's book metadata; this reveals
 * a modal overlay and hands the panel the query via the same `book-modal:opensearch`
 * window event the book modal uses, so the panel itself is reused verbatim.
 *
 * Closing dispatches `book-modal:close` (which resets the panel) and, if a manual
 * download happened while open, reloads so the row's status flips to "Downloaded".
 * The reload waits until close so the user can read the panel's success trail.
 */
export default class extends Controller {
    static targets = ['overlay'];

    connect() {
        this.didDownload = false;
        this.onDownloaded = this.onDownloaded.bind(this);
        this.onKeydown = this.onKeydown.bind(this);
        window.addEventListener('interactive-search:downloaded', this.onDownloaded);
        window.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        window.removeEventListener('interactive-search:downloaded', this.onDownloaded);
        window.removeEventListener('keydown', this.onKeydown);
    }

    open(event) {
        const p = event.params || {};
        this.overlayTarget.hidden = false;
        document.body.classList.add('book-modal-open');
        this.dispatch('opensearch', {
            prefix: 'book-modal',
            bubbles: true,
            detail: {
                bookId: p.bookId,
                title: p.title || '',
                author: p.author || '',
                isbn: p.isbn || '',
                source: null,
                externalId: null,
            },
        });
    }

    // Backdrop click or the panel's own × (which bubbles up to the overlay) closes.
    backdropClick(event) {
        if (event.target === this.overlayTarget || event.target.closest('.ix-panel-close')) {
            this.close();
        }
    }

    onKeydown(event) {
        if (event.key === 'Escape' && !this.overlayTarget.hidden) {
            this.close();
        }
    }

    close() {
        if (this.overlayTarget.hidden) return;
        this.overlayTarget.hidden = true;
        document.body.classList.remove('book-modal-open');
        this.dispatch('close', { prefix: 'book-modal', bubbles: true });
        if (this.didDownload) {
            window.location.reload();
        }
    }

    onDownloaded() {
        // A file was fetched into the library; the request's status changed
        // server-side. Defer the reload to close() so the success trail stays
        // readable.
        this.didDownload = true;
    }
}
