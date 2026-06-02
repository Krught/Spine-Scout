import { Controller } from '@hotwired/stimulus';

/**
 * Interactive Search panel. Lives inside the book modal; the book-modal
 * controller reveals it and seeds it with the current book's title/author/ISBN
 * via the `seed` action.
 *
 * Flow: click a source button at the top → the mirror toggle repopulates from
 * that source's configured mirrors and a search fires automatically against the
 * first mirror → results render with a match %. Switching the mirror re-fires the
 * search. The user picks one result and clicks Manual Download, which downloads
 * exactly that file into the library server-side.
 *
 * The edited title/author/ISBN live in the input targets (the DOM), never in
 * server state. runSearch() always reads the current inputs, so swapping source
 * or mirror re-queries with whatever the user typed — edits are never reset.
 */
export default class extends Controller {
    static targets = [
        'panel', 'sources', 'mirrors', 'mirrorRow',
        'title', 'author', 'isbn',
        'status', 'searchUrl', 'results',
    ];

    static values = {
        sourcesUrl: String,
        runUrl: String,
        downloadUrl: String,
        token: String,
        bookId: Number,
    };

    connect() {
        this.sourcesLoaded = false;
        this.activeSource = null;
        this.activeMirror = null;
        this.sourceList = [];
        this.selectedRow = null;
        this.runSeq = 0;
        this.bookSeed = { source: null, externalId: null };
    }

    /**
     * Open the panel and seed the query fields. Called by the book-modal
     * controller with the current book's metadata in event.detail. Seeds only
     * once per open so edits survive re-opens within the same modal session.
     */
    seed(event) {
        const d = (event && event.detail) || {};
        this.bookIdValue = typeof d.bookId === 'number' ? d.bookId : (this.hasBookIdValue ? this.bookIdValue : 0);
        this.bookSeed = { source: d.source || null, externalId: d.externalId || null };

        // Re-seed the query fields only when this is a different book; reopening the
        // same book keeps whatever the user edited.
        const identity = String(d.bookId || `${d.source || ''}:${d.externalId || ''}`);
        if (this.seededFor !== identity) {
            this.titleTarget.value = d.title || '';
            this.authorTarget.value = d.author || '';
            this.isbnTarget.value = d.isbn || '';
            this.seededFor = identity;
            this.selectedRow = null;
            this.activeSource = null;
            this.activeMirror = null;
            this.resultsTarget.innerHTML = '';
            this.searchUrlTarget.textContent = '';
            this.mirrorRowTarget.hidden = true;
            this.sourcesTarget.querySelectorAll('.ix-source.is-active').forEach((b) => b.classList.remove('is-active'));
        }
        this.panelTarget.hidden = false;
        this.loadSources();
    }

    close() {
        this.panelTarget.hidden = true;
    }

    async loadSources() {
        if (!this.sourcesLoaded) {
            this.setStatus('Loading sources…');
            try {
                const data = await this.post(this.sourcesUrlValue, {});
                this.sourceList = data.sources || [];
                this.sourcesLoaded = true;
                this.renderSources();
            } catch (e) {
                this.setStatus(`Couldn't load sources: ${e.message}`, true);
                return;
            }
        }
        this.autoRun();
    }

    /**
     * On open, default to searching the operator's highest-priority source that
     * has mirrors (the /sources response is already in priority order) — unless a
     * search is already active for this book (reopened without changing books).
     */
    autoRun() {
        if (this.activeSource) return;
        const first = this.sourceList.find((s) => (s.mirrors || []).length > 0);
        if (!first) {
            this.setStatus('No mirrors configured. Add mirror URLs in Settings → Direct downloads.');
            return;
        }
        this.activateSource(first.id);
    }

    renderSources() {
        this.sourcesTarget.innerHTML = this.sourceList
            .map((s) => {
                const disabled = (s.mirrors || []).length === 0;
                const title = disabled ? ' title="No mirrors configured in Settings"' : '';
                return (
                    `<button type="button" class="ix-source" data-source="${this.escAttr(s.id)}"` +
                    `${disabled ? ' disabled' : ''}${title}` +
                    ` data-action="interactive-search#selectSource">${this.esc(s.label)}</button>`
                );
            })
            .join('');
    }

    selectSource(event) {
        this.activateSource(event.currentTarget.dataset.source);
    }

    /** Select a source by id, point at its first mirror, and search. */
    activateSource(id) {
        const source = this.sourceList.find((s) => s.id === id);
        if (!source || !(source.mirrors || []).length) return;

        this.activeSource = id;
        this.sourcesTarget.querySelectorAll('.ix-source').forEach((b) => {
            b.classList.toggle('is-active', b.dataset.source === id);
        });

        this.activeMirror = source.mirrors[0];
        this.renderMirrors(source.mirrors);
        this.runSearch();
    }

    renderMirrors(mirrors) {
        this.mirrorRowTarget.hidden = mirrors.length <= 1;
        this.mirrorsTarget.innerHTML = mirrors
            .map((m, i) => {
                const active = m === this.activeMirror ? ' is-active' : '';
                return (
                    `<button type="button" class="ix-mirror${active}" data-mirror="${this.escAttr(m)}"` +
                    ` data-action="interactive-search#selectMirror" title="${this.escAttr(m)}">` +
                    `${this.esc(this.hostOf(m) || `mirror ${i + 1}`)}</button>`
                );
            })
            .join('');
    }

    selectMirror(event) {
        const mirror = event.currentTarget.dataset.mirror;
        if (!mirror || mirror === this.activeMirror) return;
        this.activeMirror = mirror;
        this.mirrorsTarget.querySelectorAll('.ix-mirror').forEach((b) => {
            b.classList.toggle('is-active', b.dataset.mirror === mirror);
        });
        this.runSearch();
    }

    /** Re-run when the user edits a field and presses Enter. */
    onQueryKeydown(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            this.rerun();
        }
    }

    /** Explicit "Search" / retry button: re-query with the edited fields. */
    rerun() {
        if (!this.activeSource) {
            this.setStatus('Pick a source above first.');
            return;
        }
        this.runSearch();
    }

    async runSearch() {
        if (!this.activeSource || !this.activeMirror) return;

        const seq = ++this.runSeq;
        this.selectedRow = null;
        this.searchUrlTarget.textContent = '';
        this.resultsTarget.innerHTML = '';
        this.setStatus('Searching… this can take a moment per result.');

        try {
            const data = await this.post(this.runUrlValue, {
                source: this.activeSource,
                mirror: this.activeMirror,
                title: this.titleTarget.value.trim(),
                author: this.authorTarget.value.trim(),
                isbn: this.isbnTarget.value.trim(),
            });
            if (seq !== this.runSeq) return;
            if (data.searchUrl) {
                this.searchUrlTarget.innerHTML =
                    `Query: <a href="${this.escAttr(data.searchUrl)}" target="_blank" rel="noopener noreferrer">${this.esc(data.searchUrl)}</a>`;
            }
            this.renderResults(data.results || [], data.threshold ?? 0, data.truncated);
        } catch (e) {
            if (seq !== this.runSeq) return;
            this.setStatus(`Search failed: ${e.message}`, true);
        }
    }

    renderResults(results, threshold, truncated) {
        if (!results.length) {
            this.setStatus('No results — try editing the title/author/ISBN, or another mirror.');
            return;
        }
        const downloadableCount = results.filter((r) => (r.links || []).length > 0).length;
        const note = truncated ? ` (showing the top ${results.length})` : '';
        const skipped = results.length - downloadableCount;
        const skipNote = skipped > 0
            ? ` ${skipped} have no resolvable download link (login-gated / rate-limited on this mirror) and can't be selected.`
            : '';
        this.setStatus(`${results.length} result(s)${note}. Match % is relevance against your query.${skipNote}`);

        const noLinkHint = 'No download link found on this result’s page — often login-gated or rate-limited on this mirror.';
        const rows = results
            .map((r, i) => {
                const downloadable = (r.links || []).length > 0;
                const pct = typeof r.matchPct === 'number' ? r.matchPct : 0;
                const pctCls = r.qualifies ? 'ix-pct--ok' : 'ix-pct--low';
                const info = r.infoUrl
                    ? `<a href="${this.escAttr(r.infoUrl)}" target="_blank" rel="noopener noreferrer" data-action="click->interactive-search#stop">↗</a>`
                    : '';
                return (
                    `<tr class="ix-row${downloadable ? '' : ' ix-row--nolinks'}" data-index="${i}"` +
                    `${downloadable ? ' data-action="click->interactive-search#pick"' : ` title="${this.escAttr(noLinkHint)}"`}>` +
                    `<td class="ix-pick">${downloadable
                        ? '<input type="radio" name="ix-pick" tabindex="-1">'
                        : '<span class="ix-nolink" title="' + this.escAttr(noLinkHint) + '">no link</span>'}</td>` +
                    `<td class="ix-pct"><span class="${pctCls}">${pct}%</span></td>` +
                    `<td>${this.esc(r.title || '—')}</td>` +
                    `<td>${this.esc(r.author || '—')}</td>` +
                    `<td>${this.esc(r.format || '—')}</td>` +
                    `<td>${this.esc(r.size || '—')}</td>` +
                    `<td>${this.esc(r.year || '—')}</td>` +
                    `<td class="ix-info">${info}</td>` +
                    '</tr>'
                );
            })
            .join('');

        this.resultsTarget.innerHTML =
            '<table class="ix-table"><thead><tr>' +
            '<th></th><th>Match</th><th>Title</th><th>Author</th><th>Format</th><th>Size</th><th>Year</th><th></th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table>' +
            '<div class="ix-actions">' +
            '<button type="button" class="btn btn-primary" data-interactive-search-target="downloadButton"' +
            ' data-action="interactive-search#download" disabled>Manual Download</button>' +
            '<div class="ix-download-result" data-interactive-search-target="downloadResult"></div>' +
            '</div>';

        this.currentResults = results;
        this.downloadButton = this.resultsTarget.querySelector('[data-interactive-search-target="downloadButton"]');
        this.downloadResult = this.resultsTarget.querySelector('[data-interactive-search-target="downloadResult"]');
    }

    pick(event) {
        const tr = event.currentTarget;
        const index = Number(tr.dataset.index);
        const row = this.currentResults[index];
        if (!row || !(row.links || []).length) return;

        this.selectedRow = row;
        this.resultsTarget.querySelectorAll('.ix-row').forEach((r) => r.classList.remove('is-selected'));
        tr.classList.add('is-selected');
        const radio = tr.querySelector('input[type="radio"]');
        if (radio) radio.checked = true;
        if (this.downloadButton) this.downloadButton.disabled = false;
    }

    /** Don't let an info-link click also select the row. */
    stop(event) {
        event.stopPropagation();
    }

    async download() {
        if (!this.selectedRow) return;
        const btn = this.downloadButton;
        const original = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Downloading…';
        this.downloadResult.innerHTML = '<p class="form-note">Downloading the chosen file… this can take a while.</p>';

        try {
            const data = await this.post(this.downloadUrlValue, {
                bookId: this.bookIdValue || undefined,
                bookSource: this.bookSeed.source || undefined,
                externalId: this.bookSeed.externalId || undefined,
                source: this.selectedRow.source || this.activeSource,
                format: this.selectedRow.format || undefined,
                title: this.titleTarget.value.trim(),
                author: this.authorTarget.value.trim(),
                isbn: this.isbnTarget.value.trim(),
                links: this.selectedRow.links || [],
            });
            this.renderDownload(data);
            if (data.ok) {
                const bookId = data.bookId || this.bookIdValue || null;
                if (bookId) this.bookIdValue = bookId;
                this.dispatch('downloaded', { detail: { bookId }, prefix: 'interactive-search', bubbles: true });
            }
        } catch (e) {
            this.downloadResult.innerHTML = `<p class="flash flash-error">${this.esc(e.message)}</p>`;
        } finally {
            btn.disabled = false;
            btn.textContent = original;
        }
    }

    renderDownload(data) {
        const steps = (data.steps || [])
            .map((s) => `<li${s.level === 'warn' ? ' class="ix-step--warn"' : ''}>${this.esc(s.message)}</li>`)
            .join('');
        const trail = steps ? `<ol class="ix-steps">${steps}</ol>` : '';
        if (data.ok) {
            this.downloadResult.innerHTML =
                `<p class="flash flash-success">✓ Downloaded <strong>${this.esc(data.filename)}</strong> into the library.</p>` + trail;
        } else {
            this.downloadResult.innerHTML =
                `<p class="flash flash-error">✗ ${this.esc(data.error || 'Download failed.')}</p>` + trail;
        }
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

    setStatus(message, isError = false) {
        this.statusTarget.textContent = message;
        this.statusTarget.classList.toggle('ix-status--error', isError);
    }

    hostOf(url) {
        try {
            return new URL(url).host;
        } catch (_) {
            return '';
        }
    }

    esc(s) {
        const div = document.createElement('div');
        div.textContent = String(s ?? '');
        return div.innerHTML;
    }

    escAttr(s) {
        return this.esc(s).replace(/"/g, '&quot;');
    }
}
