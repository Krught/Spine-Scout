import { Controller } from '@hotwired/stimulus';

/**
 * Periodically re-fetches an HTML fragment and swaps it into the content target,
 * giving a live activity view without a full page reload. Pauses while the tab is
 * hidden to avoid pointless requests.
 */
export default class extends Controller {
    static values = { url: String, interval: Number };
    static targets = ['content'];

    connect() {
        this.tick = this.tick.bind(this);
        this.onVisibility = this.onVisibility.bind(this);
        document.addEventListener('visibilitychange', this.onVisibility);
        this.start();
    }

    disconnect() {
        this.stop();
        document.removeEventListener('visibilitychange', this.onVisibility);
    }

    start() {
        this.stop();
        this.timer = setInterval(this.tick, this.intervalValue || 3000);
    }

    stop() {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
    }

    onVisibility() {
        document.hidden ? this.stop() : this.start();
    }

    async tick() {
        if (!this.hasContentTarget) return;
        try {
            const res = await fetch(this.urlValue, { headers: { 'X-Requested-With': 'fetch' } });
            if (!res.ok) return;
            this.contentTarget.innerHTML = await res.text();
        } catch (e) {
            /* transient — try again next tick */
        }
    }
}
