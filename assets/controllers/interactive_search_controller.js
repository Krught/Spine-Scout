import { Controller } from '@hotwired/stimulus';

/**
 * Interactive Search panel. Lives inside the book modal; the book-modal
 * controller reveals it and seeds it with the current book's title/author/ISBN
 * via the `seed` action.
 *
 * Flow: opening the panel loads the source list and preselects the operator's
 * highest-priority usable source (with its first mirror) but runs nothing — the
 * first search only fires when the user presses Search or hits Enter in a query
 * field. After that, clicking another source button or switching the mirror
 * re-fires the search. The user picks one result and clicks Manual Download,
 * which downloads exactly that file into the library server-side.
 *
 * The `torrent` source is the exception: it has no mirrors (the mirror row stays
 * hidden), its results carry a `torrent` block that swaps the table columns, and
 * picking one posts to the grab endpoint — which only queues the release in the
 * torrent client, so there's no file waiting at the end of the request.
 *
 * The edited title/author/ISBN live in the input targets (the DOM), never in
 * server state. runSearch() always reads the current inputs, so swapping source
 * or mirror re-queries with whatever the user typed — edits are never reset.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'panel', 'sources', 'mirrors', 'mirrorRow',
        'methods', 'methodRow',
        'wedgeRow', 'wedgeToggle', 'wedgeNote',
        'title', 'author', 'isbn',
        'status', 'searchUrl', 'results',
        'spinner', 'searchButton', 'modeChip',
    ];

    static values = {
        sourcesUrl: String,
        runUrl: String,
        downloadUrl: String,
        grabUrl: String,
        token: String,
        bookId: Number,
    };

    connect() {
        this.sourcesLoaded = false;
        this.activeSource = null;
        this.activeMirror = null;
        // Torrent search method (categories / raw / filtered). Null until the
        // torrent source is activated, then seeded from the operator's saved
        // default; the toggle only overrides it for this panel.
        this.searchMethod = null;
        this.sourceList = [];
        this.selectedRow = null;
        this.runSeq = 0;
        this.searchBusy = false;
        this.hasRun = false;
        this.bookSeed = { source: null, externalId: null };
        // Tri-state: true/false when the seed said which format the user wants,
        // null when it didn't (older launch paths) — then the key is omitted from
        // payloads so the server can fall back.
        this.seedAudiobook = null;
    }

    disconnect() {
        // Never leave controls stuck disabled if the modal tears down mid-search.
        this.setSearchBusy(false);
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
        this.seedAudiobook = typeof d.audiobook === 'boolean' ? d.audiobook : null;
        this.renderModeChip();

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
            this.searchMethod = null;
            this.hasRun = false;
            this.resultsTarget.innerHTML = '';
            this.searchUrlTarget.textContent = '';
            this.mirrorRowTarget.hidden = true;
            this.methodRowTarget.hidden = true;
            this.hideWedgeRow();
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
        // Preselect only — the first search waits for the user.
        if (this.selectDefaultSource() && !this.hasRun) this.setIdleStatus();
    }

    /**
     * Preselect the operator's highest-priority usable source (the /sources
     * response is already in priority order, and operator-disabled sources are
     * omitted). Selection only: no search is fired. Returns false when the
     * operator has configured nothing usable, in which case the status line
     * already explains that.
     */
    selectDefaultSource() {
        if (this.activeSource) return true;
        const first = this.sourceList.find((s) => this.sourceUsable(s));
        if (!first) {
            this.setStatus('No mirrors configured. Add mirror URLs in Settings → Direct downloads.');
            return false;
        }
        this.activateSource(first.id, { run: false });
        return true;
    }

    /** Nothing has been queried yet for this book — say so instead of sitting blank. */
    setIdleStatus() {
        this.setStatus('Ready — press Search to query the selected source.');
    }

    /** Torrent needs no mirrors — only the operator's enabled flag. */
    isTorrentSource(source) {
        return !!source && source.id === 'torrent';
    }

    /** MAM is torrent-like: no mirrors, method toggle, grab-based downloads. */
    isMamSource(source) {
        return !!source && source.id === 'mam';
    }

    sourceUsable(source) {
        if (!source) return false;
        if (this.isTorrentSource(source) || this.isMamSource(source)) return source.enabled !== false;
        return (source.mirrors || []).length > 0;
    }

    renderSources() {
        this.sourcesTarget.innerHTML = this.sourceList
            .map((s) => {
                const disabled = !this.sourceUsable(s);
                let title = '';
                if (disabled) {
                    if (this.isTorrentSource(s)) {
                        title = ' title="No torrent indexers/client configured in Settings"';
                    } else if (this.isMamSource(s)) {
                        title = ' title="MyAnonamouse integration or torrent client not configured in Settings"';
                    } else {
                        title = ' title="No mirrors configured in Settings"';
                    }
                }
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

    /**
     * Select a source by id and point at its first mirror. Searches immediately
     * unless `run` is false — the open-panel preselect selects without querying.
     */
    activateSource(id, { run = true } = {}) {
        const source = this.sourceList.find((s) => s.id === id);
        if (!this.sourceUsable(source)) return;

        this.activeSource = id;
        this.sourcesTarget.querySelectorAll('.ix-source').forEach((b) => {
            b.classList.toggle('is-active', b.dataset.source === id);
        });

        if (this.isTorrentSource(source) || this.isMamSource(source)) {
            // Torrent/MAM search is indexer-driven; there is no mirror to choose,
            // but the query-scoping method is. Starts on the saved default.
            this.activeMirror = null;
            this.mirrorsTarget.innerHTML = '';
            this.mirrorRowTarget.hidden = true;
            if (!this.searchMethod) this.searchMethod = source.searchMethod || 'categories';
            this.renderMethods();
        } else {
            this.activeMirror = source.mirrors[0];
            this.renderMirrors(source.mirrors);
            this.methodRowTarget.hidden = true;
        }
        // Only re-fire once the user has started searching; before the first run
        // switching sources is pure selection.
        if (run && this.hasRun) this.runSearch();
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

    /**
     * The torrent/MAM method toggle, styled like the mirror buttons. Three fixed
     * options; the tooltip carries what each one actually does — the categories
     * hint switches wording for MAM, whose scope is its two main categories.
     */
    renderMethods() {
        const mam = this.activeSource === 'mam';
        const options = [
            { id: 'categories', label: 'Categories', hint: mam
                ? "Scope the query to the matching MAM main category (Audiobooks or E-books)"
                : 'Send the configured Torznab categories with the query (classic scope)' },
            { id: 'raw', label: 'Raw', hint: mam
                ? 'No main-category filter — every MyAnonamouse result shows'
                : 'No category filter — every indexer result shows' },
            { id: 'filtered', label: 'Filtered', hint: 'No category filter on the query; results recognizably the wrong type (ebook vs audiobook) are dropped afterwards' },
        ];
        this.methodsTarget.innerHTML = options
            .map((o) => {
                const active = o.id === this.searchMethod ? ' is-active' : '';
                return (
                    `<button type="button" class="ix-mirror${active}" data-method="${o.id}"` +
                    ` data-action="interactive-search#selectMethod" title="${this.escAttr(o.hint)}">` +
                    `${this.esc(o.label)}</button>`
                );
            })
            .join('');
        this.methodRowTarget.hidden = false;
    }

    selectMethod(event) {
        const method = event.currentTarget.dataset.method;
        if (!method || method === this.searchMethod) return;
        this.searchMethod = method;
        this.methodsTarget.querySelectorAll('.ix-mirror').forEach((b) => {
            b.classList.toggle('is-active', b.dataset.method === method);
        });
        if (this.hasRun) this.runSearch();
    }

    selectMirror(event) {
        const mirror = event.currentTarget.dataset.mirror;
        if (!mirror || mirror === this.activeMirror) return;
        this.activeMirror = mirror;
        this.mirrorsTarget.querySelectorAll('.ix-mirror').forEach((b) => {
            b.classList.toggle('is-active', b.dataset.mirror === mirror);
        });
        if (this.hasRun) this.runSearch();
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
        const torrentMode = this.activeSource === 'torrent';
        const mamMode = this.activeSource === 'mam';
        // Torrent and MAM are mirror-less; every other source needs one picked.
        const mirrorless = torrentMode || mamMode;
        if (!this.activeSource) return;
        if (!mirrorless && !this.activeMirror) return;

        const seq = ++this.runSeq;
        this.hasRun = true;
        this.selectedRow = null;
        this.hideWedgeRow();
        this.searchUrlTarget.textContent = '';
        // Stale results are replaced by a placeholder so nothing on screen looks
        // like it answers the query that's currently in flight.
        this.resultsTarget.innerHTML = this.skeletonMarkup();
        this.setSearchBusy(true);
        this.setStatus(mamMode
            ? 'Searching MyAnonamouse…'
            : torrentMode
                ? 'Searching torrent indexers…'
                : 'Searching… this can take a moment per result.');

        try {
            const data = await this.post(this.runUrlValue, {
                source: this.activeSource,
                mirror: mirrorless ? undefined : this.activeMirror,
                searchMethod: mirrorless ? this.searchMethod || undefined : undefined,
                bookId: this.bookIdValue > 0 ? this.bookIdValue : undefined,
                title: this.titleTarget.value.trim(),
                author: this.authorTarget.value.trim(),
                isbn: this.isbnTarget.value.trim(),
                audiobook: this.seedAudiobook ?? undefined,
            });
            if (seq !== this.runSeq) return;
            this.resultsTarget.innerHTML = '';
            if (data.searchUrl) {
                this.searchUrlTarget.innerHTML =
                    `Query: <a href="${this.escAttr(data.searchUrl)}" target="_blank" rel="noopener noreferrer">${this.esc(data.searchUrl)}</a>`;
            }
            this.acceptedFormats = Array.isArray(data.acceptedFormats)
                ? data.acceptedFormats.map((f) => String(f).toLowerCase())
                : [];
            this.renderResults(data.results || [], data.threshold ?? 0, data.truncated);
        } catch (e) {
            if (seq !== this.runSeq) return;
            this.resultsTarget.innerHTML = '';
            this.setStatus(`Search failed: ${e.message}`, true);
        } finally {
            // Only the newest run may lift the busy state; a superseded run
            // settling must leave the controls locked for the one still going.
            if (seq === this.runSeq) this.setSearchBusy(false);
        }
    }

    /** Placeholder rows standing in for the results table while a search runs. */
    skeletonMarkup() {
        const rows = new Array(4).fill('<div class="ix-skeleton-row"></div>').join('');
        return `<div class="ix-skeleton" aria-hidden="true">${rows}</div>`;
    }

    /**
     * Lock/unlock the whole query surface for the duration of a search so a
     * second submit can't race the first. Only controls this method disabled are
     * re-enabled (`data-ix-busy`), so sources the operator hasn't configured stay
     * disabled for their own reasons.
     */
    setSearchBusy(on) {
        this.searchBusy = !!on;
        this.element.classList.toggle('ix-is-searching', this.searchBusy);
        this.element.setAttribute('aria-busy', this.searchBusy ? 'true' : 'false');
        if (this.hasSpinnerTarget) this.spinnerTarget.hidden = !this.searchBusy;
        if (this.hasResultsTarget) this.resultsTarget.classList.toggle('ix-results--busy', this.searchBusy);

        const controls = [];
        if (this.hasSourcesTarget) controls.push(...this.sourcesTarget.querySelectorAll('button'));
        if (this.hasMirrorsTarget) controls.push(...this.mirrorsTarget.querySelectorAll('button'));
        if (this.hasMethodsTarget) controls.push(...this.methodsTarget.querySelectorAll('button'));
        if (this.hasTitleTarget) controls.push(this.titleTarget);
        if (this.hasAuthorTarget) controls.push(this.authorTarget);
        if (this.hasIsbnTarget) controls.push(this.isbnTarget);
        if (this.hasSearchButtonTarget) controls.push(this.searchButtonTarget);
        if (this.downloadButton) controls.push(this.downloadButton);

        controls.forEach((el) => {
            if (!el) return;
            if (this.searchBusy) {
                if (el.disabled) return;
                el.disabled = true;
                el.dataset.ixBusy = '1';
            } else if (el.dataset.ixBusy === '1') {
                el.disabled = false;
                delete el.dataset.ixBusy;
            }
        });
    }

    /**
     * Put an action button into its running state and hand back the restore
     * callback — callers invoke it from a `finally` so failures unlock too.
     */
    setActionBusy(btn, label) {
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.classList.add('is-busy');
        btn.innerHTML = `<span class="ix-spinner ix-spinner--inline" aria-hidden="true"></span>${this.esc(label)}`;
        return () => {
            btn.disabled = false;
            btn.classList.remove('is-busy');
            btn.innerHTML = original;
        };
    }

    renderResults(results, threshold, truncated) {
        if (!results.length) {
            this.setStatus('No results — try editing the title/author/ISBN, or another mirror.');
            return;
        }
        // A torrent-shaped result carries a `torrent` block; the whole table
        // switches columns when the active source produced them.
        const torrentMode = results.some((r) => r && r.torrent);

        const downloadableCount = results.filter((r) => (r.links || []).length > 0).length;
        const note = truncated ? ` (showing the top ${results.length})` : '';
        const skipped = results.length - downloadableCount;
        const skipNote = skipped > 0
            ? (torrentMode
                ? ` ${skipped} have no usable magnet/torrent link and can't be selected.`
                : ` ${skipped} have no resolvable download link (login-gated / rate-limited on this mirror) and can't be selected.`)
            : '';
        this.setStatus(`${results.length} result(s)${note}. Match % is relevance against your query.${skipNote}`);

        // Stash the exact payload the grab endpoint needs, per torrent row. MAM
        // rows carry their `mam` block along so the grab can post it back.
        if (torrentMode) {
            results.forEach((r) => {
                if (!r || !r.torrent) return;
                r._grab = {
                    id: r.id,
                    title: r.title,
                    link: (r.links || [])[0],
                    format: r.format || undefined,
                    sizeBytes: typeof r.sizeBytes === 'number' ? r.sizeBytes : undefined,
                    indexer: r.torrent.indexer || undefined,
                    seeders: typeof r.torrent.seeders === 'number' ? r.torrent.seeders : undefined,
                    mam: r.mam || undefined,
                };
            });
        }

        const head = torrentMode
            ? '<th></th><th>Match</th><th>Title</th><th>Indexer</th><th>S/L</th><th>Size</th>' +
              '<th>Flags</th><th>Format</th><th>Year</th><th></th>'
            : '<th></th><th>Match</th><th>Title</th><th>Author</th><th>Format</th><th>Size</th><th>Year</th><th></th>';

        const rows = results
            .map((r, i) => (torrentMode ? this.torrentRow(r, i) : this.directRow(r, i)))
            .join('');

        const actionLabel = torrentMode ? 'Send to Torrent Client' : 'Manual Download';
        this.resultsTarget.innerHTML =
            '<table class="ix-table"><thead><tr>' + head +
            '</tr></thead><tbody>' + rows + '</tbody></table>' +
            '<div class="ix-actions">' +
            '<button type="button" class="btn btn-primary" data-interactive-search-target="downloadButton"' +
            ` data-action="interactive-search#download" disabled>${actionLabel}</button>` +
            '<div class="ix-download-result" data-interactive-search-target="downloadResult"></div>' +
            '</div>';

        this.currentResults = results;
        this.downloadButton = this.resultsTarget.querySelector('[data-interactive-search-target="downloadButton"]');
        this.downloadResult = this.resultsTarget.querySelector('[data-interactive-search-target="downloadResult"]');
    }

    /** Shared leading cells: the pick radio (or a "no link" stub) and the match %. */
    rowLead(r, i, noLinkHint, noLinkLabel) {
        const pickable = (r.links || []).length > 0;
        const pct = typeof r.matchPct === 'number' ? r.matchPct : 0;
        const pctCls = r.qualifies ? 'ix-pct--ok' : 'ix-pct--low';
        return (
            `<tr class="ix-row${pickable ? '' : ' ix-row--nolinks'}" data-index="${i}"` +
            `${pickable ? ' data-action="click->interactive-search#pick"' : ` title="${this.escAttr(noLinkHint)}"`}>` +
            `<td class="ix-pick">${pickable
                ? '<input type="radio" name="ix-pick" tabindex="-1">'
                : `<span class="ix-nolink" title="${this.escAttr(noLinkHint)}">${this.esc(noLinkLabel)}</span>`}</td>` +
            `<td class="ix-pct"><span class="${pctCls}">${pct}%</span></td>`
        );
    }

    /**
     * Format cell body for a known format. When the operator's Best Match
     * policy limits accepted formats, a format outside that list draws in
     * warning red — a heads-up that this may not be the file type the user
     * wants (the automatic pipeline would skip it entirely).
     */
    formatCell(format) {
        const accepted = this.acceptedFormats || [];
        const normalized = String(format).toLowerCase().replace(/^\./, '');
        if (!accepted.length || accepted.includes(normalized)) {
            return this.esc(format);
        }
        const hint = `Not in your accepted formats (${accepted.join(', ')}) — automatic search would skip this release.`;
        return `<span class="ix-format--warn" title="${this.escAttr(hint)}">${this.esc(format)}</span>`;
    }

    infoCell(r) {
        const info = r.infoUrl
            ? `<a href="${this.escAttr(r.infoUrl)}" target="_blank" rel="noopener noreferrer" data-action="click->interactive-search#stop">↗</a>`
            : '';
        return `<td class="ix-info">${info}</td>`;
    }

    directRow(r, i) {
        const noLinkHint = 'No download link found on this result’s page — often login-gated or rate-limited on this mirror.';
        return (
            this.rowLead(r, i, noLinkHint, 'no link') +
            `<td>${this.esc(r.title || '—')}</td>` +
            `<td>${this.esc(r.author || '—')}</td>` +
            `<td>${r.format ? this.formatCell(r.format) : '—'}</td>` +
            `<td>${this.esc(r.size || '—')}</td>` +
            `<td>${this.esc(r.year || '—')}</td>` +
            this.infoCell(r) +
            '</tr>'
        );
    }

    /**
     * Audiobook vs ebook, as a chip that leads the Title cell. The distinction
     * drives what the user actually gets, so it rides next to the title rather
     * than in an eleventh column — the torrent table is already wide.
     */
    typeChip(type) {
        const t = typeof type === 'string' ? type.toLowerCase() : '';
        if (t === 'audiobook') {
            return '<span class="ix-type ix-type--audiobook" title="Audiobook release">🎧 Audiobook</span>';
        }
        if (t === 'ebook') {
            return '<span class="ix-type ix-type--ebook" title="Ebook release">📖 Ebook</span>';
        }
        return '<span class="ix-type ix-type--unknown" title="Release type unknown">?</span>';
    }

    /**
     * Mode chip in the panel head: which format this search is running for.
     * Reuses typeChip() so it looks exactly like the per-row chips. Hidden when
     * the seed didn't say (then the server decides).
     */
    renderModeChip() {
        if (!this.hasModeChipTarget) return;
        if (this.seedAudiobook === null) {
            this.modeChipTarget.hidden = true;
            this.modeChipTarget.innerHTML = '';
            return;
        }
        this.modeChipTarget.innerHTML = this.typeChip(this.seedAudiobook ? 'audiobook' : 'ebook');
        this.modeChipTarget.hidden = false;
    }

    /**
     * Indexer category labels as a muted second line under the title. Only the
     * first few are drawn; when any are cut the cell carries the full list in its
     * tooltip so nothing is silently lost.
     */
    categoryLine(categories) {
        const all = (categories || []).map((c) => String(c)).filter((c) => c !== '');
        if (!all.length) return '';
        const max = 3;
        const shown = all.slice(0, max);
        const hidden = all.length - shown.length;
        const chips = shown.map((c) => `<span class="ix-cat">${this.esc(c)}</span>`).join('');
        const more = hidden > 0 ? `<span class="ix-cat ix-cat--more">+${hidden}</span>` : '';
        const titleAttr = hidden > 0 ? ` title="${this.escAttr(all.join(' · '))}"` : '';
        return `<div class="ix-cats"${titleAttr}>${chips}${more}</div>`;
    }

    /** 'YYYY-MM-DD' → 'YYYY'; anything else → ''. */
    yearOf(published) {
        const m = /^(\d{4})/.exec(String(published ?? ''));
        return m ? m[1] : '';
    }

    torrentRow(r, i) {
        const noLinkHint = 'This release exposes no magnet or .torrent link, so it can’t be sent to the torrent client.';
        const t = r.torrent || {};
        const seeders = typeof t.seeders === 'number' ? String(t.seeders) : '?';
        const leechers = typeof t.leechers === 'number' ? String(t.leechers) : '?';
        // The three freeleech variants get their own colored modifiers; the MAM
        // underscore names render as friendlier labels.
        const flagLabels = { vip_freeleech: 'vip freeleech', personal_freeleech: 'personal FL' };
        const flagMods = ['freeleech', 'vip_freeleech', 'personal_freeleech'];
        const flags = (t.flags || [])
            .map((f) => {
                const name = String(f);
                const mod = flagMods.includes(name) ? ` ix-flag--${name}` : '';
                return `<span class="ix-flag${mod}">${this.esc(flagLabels[name] || name)}</span>`;
            })
            .join('');
        // The indexer's publish date backfills the Year column when the release
        // itself carries no year; the full date stays available as a tooltip.
        const year = r.year || this.yearOf(t.published);
        const yearTitle = t.published ? ` title="Published ${this.escAttr(t.published)}"` : '';
        // A blank Format cell reads as "broken"; an explicit muted ? reads as
        // "unknown", with the type chip still saying what kind of release it is.
        const format = r.format
            ? this.formatCell(r.format)
            : '<span class="ix-unknown" title="Main file type not reported by the indexer">?</span>';
        return (
            this.rowLead(r, i, noLinkHint, 'no link') +
            `<td class="ix-title-cell"><div class="ix-title-line">${this.typeChip(t.type)}` +
            `<span class="ix-title-text">${this.esc(r.title || '—')}</span></div>` +
            `${this.categoryLine(t.categories)}</td>` +
            `<td>${this.esc(t.indexer || '—')}</td>` +
            `<td class="ix-sl"><span class="ix-seeders">${this.esc(seeders)}</span>` +
            ` / <span class="ix-leechers">${this.esc(leechers)}</span></td>` +
            `<td>${this.esc(r.size || '—')}</td>` +
            `<td class="ix-flags">${flags}</td>` +
            `<td>${format}</td>` +
            `<td${yearTitle}>${this.esc(year || '—')}</td>` +
            this.infoCell(r) +
            '</tr>'
        );
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
        if (this.downloadButton && !this.searchBusy) {
            this.downloadButton.disabled = false;
            // MAM rows are torrent rows too — same client, same label.
            this.downloadButton.textContent = row.torrent ? 'Send to Torrent Client' : 'Manual Download';
        }
        this.updateWedgeRow(row);
    }

    /** The saved wedge context the /sources response shipped for MAM (or {}). */
    mamWedgeConfig() {
        const s = (this.sourceList || []).find((x) => x && x.id === 'mam');
        return (s && s.wedge) || {};
    }

    /**
     * Show the wedge toggle for a picked MAM row, defaulted to the server's
     * wedgeDecision(). Forced states are disabled with a short note: already-free
     * releases must never cost a wedge, and the operator's "always use wedge"
     * setting wins over the checkbox (the server re-validates both anyway).
     */
    updateWedgeRow(row) {
        if (!this.hasWedgeRowTarget) return;
        const mam = row && row.mam;
        if (!mam) {
            this.hideWedgeRow();
            return;
        }
        const toggle = this.wedgeToggleTarget;
        let note = '';
        if (mam.alreadyFree) {
            toggle.checked = false;
            toggle.disabled = true;
            note = 'already freeleech for you';
        } else if (this.mamWedgeConfig().alwaysUse) {
            toggle.checked = true;
            toggle.disabled = true;
            note = 'always use wedge is enabled in settings';
        } else {
            toggle.disabled = false;
            toggle.checked = !!mam.wedgeDefault;
        }
        if (this.hasWedgeNoteTarget) {
            this.wedgeNoteTarget.textContent = note;
            this.wedgeNoteTarget.hidden = note === '';
        }
        this.wedgeRowTarget.hidden = false;
    }

    hideWedgeRow() {
        if (this.hasWedgeRowTarget) this.wedgeRowTarget.hidden = true;
    }

    /** Don't let an info-link click also select the row. */
    stop(event) {
        event.stopPropagation();
    }

    async download() {
        if (!this.selectedRow) return;
        if (this.selectedRow.torrent) {
            await this.grab();
            return;
        }
        const restore = this.setActionBusy(this.downloadButton, 'Downloading…');
        this.downloadResult.innerHTML =
            '<p class="form-note ix-working"><span class="ix-spinner ix-spinner--inline" aria-hidden="true"></span>' +
            'Downloading the chosen file… this can take a while.</p>';

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
                audiobook: this.seedAudiobook ?? undefined,
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
            restore();
        }
    }

    /**
     * Torrent path: hand the release to the torrent client and return. Unlike the
     * HTTP download there's no file at the end of this request — the client keeps
     * seeding/downloading in the background and the import happens later.
     */
    async grab() {
        const row = this.selectedRow;
        const meta = row._grab || {};
        if (!meta.link) {
            this.downloadResult.innerHTML = '<p class="flash flash-error">✗ This release has no magnet or .torrent link.</p>';
            return;
        }

        const restore = this.setActionBusy(this.downloadButton, 'Sending…');
        this.downloadResult.innerHTML =
            '<p class="form-note ix-working"><span class="ix-spinner ix-spinner--inline" aria-hidden="true"></span>' +
            'Sending the release to the torrent client…</p>';

        // MAM rows post their identity block plus the wedge choice; the plain
        // torrent payload stays exactly as before (no `source` key).
        const isMam = !!meta.mam;
        try {
            const data = await this.post(this.grabUrlValue, {
                bookId: this.bookIdValue || undefined,
                bookSource: this.bookSeed.source || undefined,
                externalId: this.bookSeed.externalId || undefined,
                source: isMam ? 'mam' : undefined,
                id: meta.id,
                title: meta.title,
                link: meta.link,
                format: meta.format,
                sizeBytes: meta.sizeBytes,
                indexer: meta.indexer,
                seeders: meta.seeders,
                mam: isMam ? meta.mam : undefined,
                useWedge: isMam
                    ? this.hasWedgeToggleTarget && this.wedgeToggleTarget.checked
                    : undefined,
                audiobook: this.seedAudiobook ?? undefined,
            });
            this.renderGrab(data);
            if (data.ok && data.queued) {
                const bookId = this.bookIdValue || null;
                this.dispatch('downloaded', { detail: { bookId }, prefix: 'interactive-search', bubbles: true });
            }
        } catch (e) {
            this.downloadResult.innerHTML = `<p class="flash flash-error">${this.esc(e.message)}</p>`;
        } finally {
            restore();
        }
    }

    renderGrab(data) {
        if (data.ok && data.queued) {
            this.downloadResult.innerHTML =
                '<p class="flash flash-success ix-queued">✓ Queued in torrent client — the download continues in the ' +
                'background and the book will be imported automatically.</p>';
        } else {
            this.downloadResult.innerHTML =
                `<p class="flash flash-error">✗ ${this.esc(data.error || 'Could not send this release to the torrent client.')}</p>`;
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
