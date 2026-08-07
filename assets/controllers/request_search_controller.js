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
 *
 * When automatic fulfillment is switched off, approved requests queue up for
 * manual searching. "Get next item" then walks that queue: each POST to the
 * manual-next endpoint returns the next waiting request, which is seeded into the
 * very same overlay, with queue chrome (Skip / Next item) rendered in the overlay
 * shell around the shared panel. Queue mode is a strict superset of the single-row
 * mode — `open` always leaves it.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'overlay',
        'pipelineToggle',
        'queueStart',
        'queueNotice',
        'queueBar',
        'queueTitle',
        'skipButton',
        'nextButton',
        'queueDone',
    ];

    static values = {
        toggleUrl: String,
        manualNextUrl: String,
        token: String,
    };

    connect() {
        this.didDownload = false;
        // Queue state: null = not in queue mode; otherwise the id of the request
        // currently seeded into the overlay. `acted` flips on the first
        // interactive-search:downloaded for the current item.
        this.queueId = null;
        this.acted = false;
        this.busy = false;
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
        this.leaveQueueMode();
        this.showOverlay();
        this.seed({
            bookId: p.bookId,
            title: p.title || '',
            author: p.author || '',
            isbn: p.isbn || '',
            audiobook: p.audiobook,
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
        this.leaveQueueMode();
        this.dispatch('close', { prefix: 'book-modal', bubbles: true });
        if (this.didDownload) {
            window.location.reload();
        }
    }

    onDownloaded() {
        // A file was fetched into the library (or a torrent was queued); the
        // request's status changed server-side. Defer the reload to close() so the
        // success trail stays readable. In queue mode this also marks the current
        // item as acted on, swapping Skip for "Next item".
        this.didDownload = true;
        if (this.queueId !== null) {
            this.acted = true;
            this.renderQueueButtons();
        }
    }

    /* ── automatic-fulfillment toggle ─────────────────────────────────────── */

    async togglePipeline(event) {
        const input = event.target;
        const wanted = input.checked;
        input.disabled = true;
        try {
            const data = await this.post(this.toggleUrlValue, { enabled: wanted });
            const enabled = data.enabled === true;
            input.checked = enabled;
            this.applyPipelineState(enabled);
        } catch (e) {
            input.checked = !wanted; // revert to the server-side truth we still believe
            this.toast(`Couldn't change automatic fulfillment: ${e.message}`, 'error');
        } finally {
            input.disabled = false;
        }
    }

    // The manual queue only makes sense while the pipeline is off.
    applyPipelineState(enabled) {
        if (this.hasQueueStartTarget) {
            this.queueStartTarget.hidden = enabled;
        }
        if (enabled) {
            this.hideNotice();
        }
    }

    /* ── manual queue ─────────────────────────────────────────────────────── */

    startQueue() {
        this.hideNotice();
        this.fetchNext(null);
    }

    advance() {
        this.fetchNext(this.queueId);
    }

    async fetchNext(after) {
        if (this.busy) return;
        this.busy = true;
        this.setQueueBusy(true);
        try {
            const body = {};
            if (after !== null && after !== undefined) {
                body.after = after;
            }
            const data = await this.post(this.manualNextUrlValue, body);
            if (data.done) {
                this.onQueueExhausted();
                return;
            }
            this.enterQueueItem(data.request || {});
        } catch (e) {
            this.toast(`Couldn't fetch the next request: ${e.message}`, 'error');
        } finally {
            this.busy = false;
            this.setQueueBusy(false);
        }
    }

    enterQueueItem(request) {
        this.queueId = request.id ?? null;
        this.acted = false;
        this.showOverlay();
        if (this.hasQueueDoneTarget) this.queueDoneTarget.hidden = true;
        if (this.hasQueueBarTarget) this.queueBarTarget.hidden = false;
        if (this.hasQueueTitleTarget) {
            const who = request.requestedBy ? ` — requested by ${request.requestedBy}` : '';
            const fmt = request.audiobook ? ' (audiobook)' : '';
            this.queueTitleTarget.textContent = `${request.title || 'Untitled'}${fmt}${who}`;
        }
        this.renderQueueButtons();
        this.seed({
            bookId: request.bookId,
            title: request.title || '',
            author: request.author || '',
            isbn: request.isbn || '',
            source: request.bookSource || null,
            externalId: request.externalId || null,
            audiobook: request.audiobook,
        });
    }

    // Queue ran dry. Mid-queue we swap the overlay chrome for the caught-up card
    // (and reset the panel); from the closed state we show the header notice.
    onQueueExhausted() {
        this.queueId = null;
        this.acted = false;
        if (this.overlayTarget.hidden) {
            this.showNotice('No requests waiting for manual search.');
            return;
        }
        if (this.hasQueueBarTarget) this.queueBarTarget.hidden = true;
        if (this.hasQueueDoneTarget) this.queueDoneTarget.hidden = false;
        this.dispatch('close', { prefix: 'book-modal', bubbles: true });
    }

    renderQueueButtons() {
        if (this.hasSkipButtonTarget) this.skipButtonTarget.hidden = this.acted;
        if (this.hasNextButtonTarget) this.nextButtonTarget.hidden = !this.acted;
    }

    setQueueBusy(busy) {
        for (const el of [
            this.hasQueueStartTarget ? this.queueStartTarget : null,
            this.hasSkipButtonTarget ? this.skipButtonTarget : null,
            this.hasNextButtonTarget ? this.nextButtonTarget : null,
        ]) {
            if (!el) continue;
            el.disabled = busy;
            el.classList.toggle('is-busy', busy);
        }
    }

    leaveQueueMode() {
        this.queueId = null;
        this.acted = false;
        if (this.hasQueueBarTarget) this.queueBarTarget.hidden = true;
        if (this.hasQueueDoneTarget) this.queueDoneTarget.hidden = true;
    }

    /* ── helpers ──────────────────────────────────────────────────────────── */

    showOverlay() {
        this.overlayTarget.hidden = false;
        document.body.classList.add('book-modal-open');
    }

    seed(detail) {
        const payload = {
            bookId: detail.bookId,
            title: detail.title || '',
            author: detail.author || '',
            isbn: detail.isbn || '',
            source: detail.source || null,
            externalId: detail.externalId || null,
        };
        // Only forward a real boolean — an absent flag stays absent so the
        // panel (and ultimately the server) can fall back.
        if (typeof detail.audiobook === 'boolean') {
            payload.audiobook = detail.audiobook;
        }
        this.dispatch('opensearch', {
            prefix: 'book-modal',
            bubbles: true,
            detail: payload,
        });
    }

    showNotice(message) {
        if (!this.hasQueueNoticeTarget) return;
        this.queueNoticeTarget.textContent = message;
        this.queueNoticeTarget.hidden = false;
    }

    hideNotice() {
        if (!this.hasQueueNoticeTarget) return;
        this.queueNoticeTarget.hidden = true;
        this.queueNoticeTarget.textContent = '';
    }

    async post(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ _token: this.tokenValue, ...body }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            throw new Error(data.error || `HTTP ${res.status}`);
        }
        return data;
    }

    toast(message, kind) {
        this.application
            .getControllerForElementAndIdentifier(this.element, 'request-actions')
            ?.toast(message, kind);
    }
}
