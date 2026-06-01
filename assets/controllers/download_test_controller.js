import { Controller } from '@hotwired/stimulus';

/**
 * Dev direct-download link tester. POSTs the record's download links to the
 * probe endpoint, which attempts to download each to a temp file, measures the
 * byte size, and deletes it. Renders one row per link — size on success, the
 * failure reason otherwise — so an operator can gauge a mirror's download
 * ability. Downloads run server-side and can take a while per link, so the
 * button is disabled while the request is in flight.
 */
export default class extends Controller {
    static targets = ['button', 'results'];
    static values = { url: String, token: String, links: Array };

    async run() {
        const links = this.linksValue;
        if (!links.length) {
            return;
        }

        const original = this.buttonTarget.textContent;
        this.buttonTarget.disabled = true;
        this.buttonTarget.textContent = `Downloading ${links.length} link(s)…`;
        this.resultsTarget.hidden = false;
        this.resultsTarget.innerHTML =
            '<p class="form-note">Attempting downloads… this can take a while per link.</p>';

        try {
            const res = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ _token: this.tokenValue, links }),
            });
            const data = await res.json();
            if (!res.ok) {
                throw new Error(data.error || `HTTP ${res.status}`);
            }
            this.render(data.results || []);
        } catch (e) {
            this.resultsTarget.innerHTML = `<p class="flash flash-error">${this.escape(e.message)}</p>`;
        } finally {
            this.buttonTarget.disabled = false;
            this.buttonTarget.textContent = original;
        }
    }

    render(results) {
        if (!results.length) {
            this.resultsTarget.innerHTML = '<p class="form-note">No results.</p>';
            return;
        }

        const blocks = results.map((r) => this.renderOne(r)).join('');
        const ok = results.filter((r) => r.ok).length;
        this.resultsTarget.innerHTML =
            `<p class="form-note"><strong>${ok}</strong> of <strong>${results.length}</strong> link(s) downloaded successfully.</p>` +
            blocks;
    }

    /** One link: outcome line + the actual workflow trail (bypass → resolve → download). */
    renderOne(r) {
        const outcome = r.ok
            ? `<span class="dev-dl-test__ok">✓ ${this.escape(this.humanBytes(r.bytes))}</span>`
            : `<span class="dev-dl-test__fail">✗ ${this.escape(r.error || 'failed')}</span>`;

        const steps = (r.steps || [])
            .map((s) => {
                const cls = s.level === 'warn' ? ' class="dev-dl-test__warn"' : '';
                return `<li${cls}>${this.escape(s.message)}</li>`;
            })
            .join('');
        const trail = steps
            ? `<ol class="dev-dl-test__steps">${steps}</ol>`
            : '<p class="form-note">No workflow steps recorded.</p>';

        // Head of what actually got downloaded: a real file reads as binary
        // ("PK…" for EPUB/ZIP, "%PDF…"); an HTML wait/landing page grabbed in its
        // place is obvious here even when the byte size looks plausible.
        const preview = r.preview
            ? `<p class="form-note">First ${r.preview.length} chars of the response:</p>` +
              `<pre class="dev-dl-test__preview">${this.escape(r.preview)}</pre>`
            : '';

        return (
            '<div class="dev-dl-test__item">' +
            `<p><code>${this.escape(r.url)}</code> — ${outcome}</p>` +
            preview +
            trail +
            '</div>'
        );
    }

    humanBytes(bytes) {
        if (bytes === null || bytes === undefined) {
            return '—';
        }
        if (bytes < 1024) {
            return `${bytes} B`;
        }
        const units = ['KB', 'MB', 'GB', 'TB'];
        let value = bytes / 1024;
        let i = 0;
        while (value >= 1024 && i < units.length - 1) {
            value /= 1024;
            i += 1;
        }
        return `${value.toFixed(1)} ${units[i]}`;
    }

    escape(s) {
        const div = document.createElement('div');
        div.textContent = String(s ?? '');
        return div.innerHTML;
    }
}
