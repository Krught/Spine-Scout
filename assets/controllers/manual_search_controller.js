import { Controller } from '@hotwired/stimulus';

/**
 * Manual Search page. The interactive-search panel's flow, lifted out of the
 * book modal and given no book: the user types a query, picks a source, and
 * grabs whatever release looks right — even when the metadata provider has
 * nothing matching it at all.
 *
 * Differences from the in-modal panel, all of them consequences of "no book":
 *  - An Ebook / Audiobook toggle stands in for the seed's format. It is sent
 *    with every search and every grab (it decides the destination path) and is
 *    mirrored into the URL (?format=audiobook) so a reload keeps it.
 *  - There is nothing to match against, so the match % is presented neutrally
 *    (it scores the result against what the user typed) and no row is hidden
 *    for scoring below the threshold.
 *  - Before grabbing, the user edits a "Save as" name pre-filled from the
 *    server's `suggestedName` for the picked row.
 *
 * The query fields are the only state that matters and they live in the DOM, so
 * switching source/mirror/method re-queries with exactly what is on screen.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'format',
        'sources', 'mirrors', 'mirrorRow',
        'methods', 'methodRow',
        'title', 'author', 'isbn',
        'status', 'searchUrl', 'results',
        'spinner', 'searchButton',
        'action', 'actionButton', 'actionResult',
        'saveAs',
        'wedgeRow', 'wedgeToggle', 'wedgeNote',
    ];

    static values = {
        sourcesUrl: String,
        runUrl: String,
        downloadUrl: String,
        grabUrl: String,
        token: String,
        audiobook: Boolean,
    };

    connect() {
        this.sourcesLoaded = false;
        this.activeSource = null;
        this.activeMirror = null;
        // Torrent/MAM search method (categories / raw / filtered). Null until such
        // a source is activated, then seeded from the operator's saved default.
        this.searchMethod = null;
        this.sourceList = [];
        this.currentResults = [];
        this.selectedRow = null;
        this.acceptedFormats = [];
        this.runSeq = 0;
        this.searchBusy = false;
        this.hasRun = false;
        // Set once the user types in "Save as" themselves. From then on picking a
        // different row leaves their text alone (and says so) rather than silently
        // discarding it — an accidental click must never cost typed-out work. The
        // Reset link in the note puts the suggestion back on demand.
        this.saveAsDirty = false;
        this.loadSources();
    }

    disconnect() {
        // Never leave controls stuck disabled if the page tears down mid-search.
        this.setSearchBusy(false);
    }

    // --- format toggle ------------------------------------------------------

    /**
     * Ebook / audiobook. The previous results answered a query for the other
     * medium, so they are cleared rather than left sitting there looking current.
     */
    selectFormat(event) {
        const audiobook = event.currentTarget.dataset.format === 'audiobook';
        if (audiobook === this.audiobookValue) return;
        this.audiobookValue = audiobook;

        this.formatTarget.querySelectorAll('.ms-format__option').forEach((b) => {
            const on = (b.dataset.format === 'audiobook') === audiobook;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });

        // Reload-safe: the medium is part of the page's address, not hidden state.
        const url = new URL(window.location.href);
        if (audiobook) url.searchParams.set('format', 'audiobook');
        else url.searchParams.delete('format');
        window.history.replaceState({}, '', url);

        this.clearResults();
        this.setStatus(`Switched to ${audiobook ? 'audiobooks' : 'ebooks'} — press Search to query again.`);
    }

    /** Drop everything tied to the previous query, selection included. */
    clearResults() {
        this.runSeq += 1;
        this.currentResults = [];
        this.selectedRow = null;
        this.resultsTarget.innerHTML = '';
        this.searchUrlTarget.textContent = '';
        this.hideAction();
    }

    // --- sources / mirrors / method ----------------------------------------

    async loadSources() {
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
        // Preselect only — the first search waits for the user.
        if (this.selectDefaultSource()) this.setIdleStatus();
    }

    /**
     * Preselect the operator's highest-priority usable source (the /sources
     * response is already in priority order). Selection only: no search fires.
     */
    selectDefaultSource() {
        if (this.activeSource) return true;
        const first = this.sourceList.find((s) => this.sourceUsable(s));
        if (!first) {
            this.setStatus('No usable sources. Configure mirrors, indexers or MyAnonamouse in Settings.');
            return false;
        }
        this.activateSource(first.id, { run: false });
        return true;
    }

    setIdleStatus() {
        this.setStatus('Ready — fill in a title, author or ISBN and press Search.');
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
        if (source.enabled === false) return false;
        if (this.isTorrentSource(source) || this.isMamSource(source)) return true;
        return (source.mirrors || []).length > 0;
    }

    /** Why a source can't be picked — shown as its tooltip. */
    disabledHint(source) {
        if (this.isTorrentSource(source)) return 'No torrent indexers/client configured in Settings';
        if (this.isMamSource(source)) return 'MyAnonamouse integration or torrent client not configured in Settings';
        return 'Not fully configured — add mirror URLs in Settings → Direct downloads';
    }

    renderSources() {
        this.sourcesTarget.innerHTML = this.sourceList
            .map((s) => {
                const disabled = !this.sourceUsable(s);
                const title = disabled ? ` title="${this.escAttr(this.disabledHint(s))}"` : '';
                return (
                    `<button type="button" class="ix-source" data-source="${this.escAttr(s.id)}"` +
                    `${disabled ? ' disabled' : ''}${title}` +
                    ` data-action="manual-search#selectSource">${this.esc(s.label)}</button>`
                );
            })
            .join('');
    }

    selectSource(event) {
        this.activateSource(event.currentTarget.dataset.source);
    }

    /**
     * Select a source by id and point at its first mirror. Re-searches straight
     * away once the user has run at least one search; before that it is pure
     * selection.
     */
    activateSource(id, { run = true } = {}) {
        const source = this.sourceList.find((s) => s.id === id);
        if (!this.sourceUsable(source)) return;

        this.activeSource = id;
        this.sourcesTarget.querySelectorAll('.ix-source').forEach((b) => {
            b.classList.toggle('is-active', b.dataset.source === id);
        });

        if (this.isTorrentSource(source) || this.isMamSource(source)) {
            // Indexer-driven: there is no mirror to choose, but the query-scoping
            // method is. Starts on the operator's saved default.
            this.activeMirror = null;
            this.mirrorsTarget.innerHTML = '';
            this.mirrorRowTarget.hidden = true;
            if (!this.searchMethod) this.searchMethod = source.searchMethod || 'categories';
            this.renderMethods();
        } else {
            this.activeMirror = (source.mirrors || [])[0];
            this.renderMirrors(source.mirrors || []);
            this.methodRowTarget.hidden = true;
        }
        if (run && this.hasRun) this.runSearch();
    }

    renderMirrors(mirrors) {
        this.mirrorRowTarget.hidden = mirrors.length <= 1;
        this.mirrorsTarget.innerHTML = mirrors
            .map((m, i) => {
                const active = m === this.activeMirror ? ' is-active' : '';
                return (
                    `<button type="button" class="ix-mirror${active}" data-mirror="${this.escAttr(m)}"` +
                    ` data-action="manual-search#selectMirror" title="${this.escAttr(m)}">` +
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
                ? 'Scope the query to the matching MAM main category (Audiobooks or E-books)'
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
                    ` data-action="manual-search#selectMethod" title="${this.escAttr(o.hint)}">` +
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

    // --- searching ----------------------------------------------------------

    /** Re-run when the user edits a field and presses Enter. */
    onQueryKeydown(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            this.rerun();
        }
    }

    rerun() {
        if (!this.activeSource) {
            this.setStatus('Pick a source above first.', true);
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

        // A pasted MyAnonamouse torrent link isn't a query at all — it names one
        // exact release — so it takes a different path through the same endpoint.
        // Checked before the "type something" guard so a link alone is enough.
        const torrentRef = this.detectMamTorrent();
        if (torrentRef) {
            if (!mamMode) {
                // Deliberately no auto-switch: changing the operator's chosen source
                // behind their back is worse than telling them to change it.
                this.setStatus('That looks like a MyAnonamouse torrent link — pick MyAnonamouse as the source to resolve it.', true);
                return;
            }
            return this.resolveMamTorrent(torrentRef);
        }
        if (!mirrorless && !this.activeMirror) return;

        const title = this.titleTarget.value.trim();
        const author = this.authorTarget.value.trim();
        const isbn = this.isbnTarget.value.trim();
        // No book seeds this page, so an empty form would search for nothing at
        // all — every source would either error or return its front page.
        if (!title && !author && !isbn) {
            this.setStatus('Enter a title, author or ISBN to search for.', true);
            this.titleTarget.focus();
            return;
        }

        const seq = ++this.runSeq;
        this.hasRun = true;
        this.selectedRow = null;
        this.hideAction();
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
                // Key must be `searchMethod` — InteractiveSearchRunner reads that name.
                // Posting it as `method` silently fell back to the operator's default.
                searchMethod: mirrorless ? this.searchMethod || undefined : undefined,
                title,
                author,
                isbn,
                audiobook: this.audiobookValue,
            });
            if (seq !== this.runSeq) return;
            this.applyRunResponse(data);
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

    /**
     * A MyAnonamouse torrent reference sitting in one of the query fields:
     * a full `https://www.myanonamouse.net/t/182137`, any host's `/t/182137`
     * (the server re-checks the host against MAM and the operator's configured
     * base URL — this side only has to notice the shape), or a bare id.
     *
     * A bare id is only ever read out of the Title field and only while MAM is
     * the selected source: the ISBN field is full of 10- and 13-digit numbers
     * that are emphatically not torrent ids.
     */
    detectMamTorrent() {
        const linkFields = [];
        // Stimulus throws on a missing target, so each one is asked for by name.
        if (this.hasTitleTarget) linkFields.push(this.titleTarget);
        if (this.hasIsbnTarget) linkFields.push(this.isbnTarget);
        if (this.hasAuthorTarget) linkFields.push(this.authorTarget);
        for (const el of linkFields) {
            const raw = (el.value || '').trim();
            if (!raw) continue;
            const m = raw.match(/^(?:[a-z][a-z0-9+.-]*:)?(?:\/\/[^\s/]+)?\/?t\/(\d+)(?:[/?#]\S*)?$/i);
            if (m) return { raw, id: m[1] };
        }
        if (this.activeSource === 'mam' && this.hasTitleTarget) {
            const raw = (this.titleTarget.value || '').trim();
            // Up to 9 digits: MAM ids are ~6-7, and the cap keeps 10/13-digit
            // ISBNs typed into the Title field from being read as ids.
            if (/^\d{1,9}$/.test(raw)) return { raw, id: raw };
        }
        return null;
    }

    /**
     * Resolve one pasted torrent link into the ordinary single-row result table.
     * Same endpoint, same response shape, same pick/grab flow as a search — only
     * the request body (`torrentUrl` instead of a query) and the wording differ.
     */
    async resolveMamTorrent(ref) {
        const seq = ++this.runSeq;
        this.hasRun = true;
        this.selectedRow = null;
        this.hideAction();
        this.searchUrlTarget.textContent = '';
        this.resultsTarget.innerHTML = this.skeletonMarkup();
        this.setSearchBusy(true);
        this.setStatus(`Resolving MyAnonamouse torrent ${ref.id}…`);

        try {
            const data = await this.post(this.runUrlValue, {
                source: 'mam',
                torrentUrl: ref.raw,
                audiobook: this.audiobookValue,
            });
            if (seq !== this.runSeq) return;
            this.applyRunResponse(data);
            const count = (data.results || []).length;
            if (count) {
                // renderResults() has just written the generic "N result(s), match %
                // is relevance…" line, which is meaningless here: nothing was
                // matched against anything, the operator named this release.
                this.setStatus(`Resolved 1 release — pick it below to grab it.`);
            } else {
                this.setStatus(`MyAnonamouse torrent ${ref.id} could not be resolved.`, true);
            }
        } catch (e) {
            if (seq !== this.runSeq) return;
            this.resultsTarget.innerHTML = '';
            this.setStatus(`Could not resolve that MyAnonamouse link: ${e.message}`, true);
        } finally {
            if (seq === this.runSeq) this.setSearchBusy(false);
        }
    }

    /** The half of a run response that is identical for a search and a resolve. */
    applyRunResponse(data) {
        this.resultsTarget.innerHTML = '';
        if (data.searchUrl) {
            this.searchUrlTarget.innerHTML =
                `Query: <a href="${this.escAttr(data.searchUrl)}" target="_blank" rel="noopener noreferrer">${this.esc(data.searchUrl)}</a>`;
        }
        this.acceptedFormats = Array.isArray(data.acceptedFormats)
            ? data.acceptedFormats.map((f) => String(f).toLowerCase())
            : [];
        this.renderResults(data.results || [], data.truncated);
    }

    /** Placeholder rows standing in for the results table while a search runs. */
    skeletonMarkup() {
        const rows = new Array(4).fill('<div class="ix-skeleton-row"></div>').join('');
        return `<div class="ix-skeleton" aria-hidden="true">${rows}</div>`;
    }

    /**
     * Lock/unlock the whole query surface for the duration of a search so a
     * second submit can't race the first. Only controls this method disabled are
     * re-enabled (`data-msBusy`), so sources the operator hasn't configured stay
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
        if (this.hasFormatTarget) controls.push(...this.formatTarget.querySelectorAll('button'));
        if (this.hasTitleTarget) controls.push(this.titleTarget);
        if (this.hasAuthorTarget) controls.push(this.authorTarget);
        if (this.hasIsbnTarget) controls.push(this.isbnTarget);
        if (this.hasSearchButtonTarget) controls.push(this.searchButtonTarget);
        if (this.hasActionButtonTarget) controls.push(this.actionButtonTarget);

        controls.forEach((el) => {
            if (!el) return;
            if (this.searchBusy) {
                if (el.disabled) return;
                el.disabled = true;
                el.dataset.msBusy = '1';
            } else if (el.dataset.msBusy === '1') {
                el.disabled = false;
                delete el.dataset.msBusy;
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

    // --- results ------------------------------------------------------------

    renderResults(results, truncated) {
        this.currentResults = results;
        if (!results.length) {
            this.setStatus('No results — try a shorter title, another source or the Raw method.');
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
        // No book, so nothing to be "a match" for: say plainly what the % measures.
        this.setStatus(`${results.length} result(s)${note}. Match % is relevance against what you typed — nothing is filtered out.${skipNote}`);

        const head = torrentMode
            ? '<th></th><th>Match</th><th>Title</th><th>Indexer</th><th>S/L</th><th>Size</th>' +
              '<th>Flags</th><th>Format</th><th>Year</th><th></th>'
            : '<th></th><th>Match</th><th>Title</th><th>Author</th><th>Format</th><th>Size</th><th>Year</th><th></th>';

        const rows = results
            .map((r, i) => (torrentMode ? this.torrentRow(r, i) : this.directRow(r, i)))
            .join('');

        this.resultsTarget.innerHTML =
            '<table class="ix-table"><thead><tr>' + head +
            '</tr></thead><tbody>' + rows + '</tbody></table>';
    }

    /**
     * Shared leading cells: the pick radio (or a "no link" stub) and the match %.
     * Rows are focusable and Enter/Space-selectable so the table is usable
     * without a mouse.
     */
    rowLead(r, i, noLinkHint, noLinkLabel) {
        const pickable = (r.links || []).length > 0;
        const pct = typeof r.matchPct === 'number' ? r.matchPct : 0;
        const pctHint = 'Relevance against the title/author/ISBN you typed — not a library match.';
        return (
            `<tr class="ix-row${pickable ? '' : ' ix-row--nolinks'}" data-index="${i}"` +
            `${pickable
                ? ' tabindex="0" data-action="click->manual-search#pick keydown.enter->manual-search#pick keydown.space->manual-search#pick"'
                : ` title="${this.escAttr(noLinkHint)}"`}>` +
            `<td class="ix-pick">${pickable
                ? '<input type="radio" name="ms-pick" tabindex="-1">'
                : `<span class="ix-nolink" title="${this.escAttr(noLinkHint)}">${this.esc(noLinkLabel)}</span>`}</td>` +
            `<td class="ix-pct"><span class="ms-pct" title="${this.escAttr(pctHint)}">${pct}%</span></td>`
        );
    }

    /**
     * Format cell body for a known format. When the operator's Best Match policy
     * limits accepted formats, a format outside that list draws in warning red —
     * a heads-up that this may not be the file type the user wants.
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
            ? `<a href="${this.escAttr(r.infoUrl)}" target="_blank" rel="noopener noreferrer" data-action="click->manual-search#stop">↗</a>`
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

    /** Audiobook vs ebook, as a chip that leads the Title cell. */
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

    // --- picking a row ------------------------------------------------------

    pick(event) {
        // Space would otherwise scroll the page out from under the selection.
        if (event.type === 'keydown') event.preventDefault();
        const tr = event.currentTarget;
        const index = Number(tr.dataset.index);
        const row = this.currentResults[index];
        if (!row || !(row.links || []).length) return;

        this.selectedRow = row;
        this.resultsTarget.querySelectorAll('.ix-row').forEach((r) => r.classList.remove('is-selected'));
        tr.classList.add('is-selected');
        const radio = tr.querySelector('input[type="radio"]');
        if (radio) radio.checked = true;

        this.actionTarget.hidden = false;
        this.actionResultTarget.innerHTML = '';
        this.fillSaveAs(row);
        this.updateWedgeRow(row);
        if (!this.searchBusy) {
            this.actionButtonTarget.disabled = false;
            // MAM rows are torrent rows too — same client, same label.
            this.actionButtonTarget.textContent = row.torrent ? 'Send to Torrent Client' : 'Download';
        }
    }

    /**
     * Pre-fill "Save as" from the picked row's server-suggested name. Once the
     * user has typed their own name it is kept across further picks (no
     * confirm() dialog — the note offers an explicit Reset instead, which is
     * less interruptive than a modal on every row click).
     */
    fillSaveAs(row) {
        const suggestion = typeof row.suggestedName === 'string' ? row.suggestedName : '';
        this.lastSuggestion = suggestion;
        if (this.saveAsDirty) {
            this.renderSaveAsHint(true);
            return;
        }
        this.saveAsTarget.value = suggestion;
        this.renderSaveAsHint(false);
    }

    onSaveAsInput() {
        this.saveAsDirty = this.saveAsTarget.value !== (this.lastSuggestion || '');
        this.renderSaveAsHint(this.saveAsDirty);
    }

    /** Offer the suggestion back once the field carries the user's own text. */
    renderSaveAsHint(showReset) {
        const note = this.element.querySelector('.ms-saveas-note');
        if (!note) return;
        note.classList.toggle('ms-saveas-note--kept', !!showReset);
        const existing = note.querySelector('.ms-saveas-reset');
        if (showReset && !existing) {
            note.insertAdjacentHTML(
                'beforeend',
                ' <button type="button" class="ms-saveas-reset" data-action="manual-search#resetSaveAs">' +
                'Use the suggested name</button>'
            );
        } else if (!showReset && existing) {
            existing.remove();
        }
    }

    resetSaveAs() {
        this.saveAsTarget.value = this.lastSuggestion || '';
        this.saveAsDirty = false;
        this.renderSaveAsHint(false);
        this.saveAsTarget.focus();
    }

    hideAction() {
        if (!this.hasActionTarget) return;
        this.actionTarget.hidden = true;
        this.actionButtonTarget.disabled = true;
        this.actionResultTarget.innerHTML = '';
        this.hideWedgeRow();
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

    // --- grabbing / downloading --------------------------------------------

    async download() {
        if (!this.selectedRow) return;
        if (this.selectedRow.torrent) {
            await this.grab();
            return;
        }
        const restore = this.setActionBusy(this.actionButtonTarget, 'Downloading…');
        this.actionResultTarget.innerHTML =
            '<p class="form-note ix-working"><span class="ix-spinner ix-spinner--inline" aria-hidden="true"></span>' +
            'Downloading the chosen file… this can take a while.</p>';

        try {
            const data = await this.post(this.downloadUrlValue, {
                source: this.selectedRow.source || this.activeSource,
                links: this.selectedRow.links || [],
                title: this.titleTarget.value.trim(),
                format: this.selectedRow.format || undefined,
                audiobook: this.audiobookValue,
                saveAs: this.saveAsTarget.value.trim(),
            });
            this.renderDownload(data);
        } catch (e) {
            this.actionResultTarget.innerHTML = `<p class="flash flash-error">${this.esc(e.message)}</p>`;
        } finally {
            restore();
        }
    }

    /**
     * Torrent/MAM path: hand the release to the torrent client and return. Unlike
     * the HTTP download there's no file at the end of this request — the client
     * keeps downloading in the background and the import happens later.
     */
    async grab() {
        const row = this.selectedRow;
        const link = (row.links || [])[0];
        if (!link) {
            this.actionResultTarget.innerHTML = '<p class="flash flash-error">✗ This release has no magnet or .torrent link.</p>';
            return;
        }
        const t = row.torrent || {};
        const isMam = !!row.mam;

        const restore = this.setActionBusy(this.actionButtonTarget, 'Sending…');
        this.actionResultTarget.innerHTML =
            '<p class="form-note ix-working"><span class="ix-spinner ix-spinner--inline" aria-hidden="true"></span>' +
            'Sending the release to the torrent client…</p>';

        try {
            const data = await this.post(this.grabUrlValue, {
                source: this.activeSource,
                id: row.id,
                link,
                title: row.title,
                format: row.format || undefined,
                sizeBytes: typeof row.sizeBytes === 'number' ? row.sizeBytes : undefined,
                seeders: typeof t.seeders === 'number' ? t.seeders : undefined,
                indexer: t.indexer || undefined,
                audiobook: this.audiobookValue,
                saveAs: this.saveAsTarget.value.trim(),
                mam: isMam ? row.mam : undefined,
                useWedge: isMam
                    ? this.hasWedgeToggleTarget && this.wedgeToggleTarget.checked
                    : undefined,
            });
            this.renderGrab(data);
        } catch (e) {
            this.actionResultTarget.innerHTML = `<p class="flash flash-error">${this.esc(e.message)}</p>`;
        } finally {
            restore();
        }
    }

    renderGrab(data) {
        if (data.ok && data.queued) {
            this.actionResultTarget.innerHTML =
                '<p class="flash flash-success ix-queued">✓ Queued in torrent client — the download continues in the ' +
                'background and the book will be imported automatically.</p>';
        } else {
            this.actionResultTarget.innerHTML =
                `<p class="flash flash-error">✗ ${this.esc(data.error || 'Could not send this release to the torrent client.')}</p>`;
        }
    }

    renderDownload(data) {
        const steps = (data.steps || [])
            .map((s) => `<li${s.level === 'warn' ? ' class="ix-step--warn"' : ''}>${this.esc(s.message)}</li>`)
            .join('');
        const trail = steps ? `<ol class="ix-steps">${steps}</ol>` : '';
        if (data.ok) {
            this.actionResultTarget.innerHTML =
                `<p class="flash flash-success">✓ Downloaded <strong>${this.esc(data.filename)}</strong> into the library.</p>` + trail;
        } else {
            this.actionResultTarget.innerHTML =
                `<p class="flash flash-error">✗ ${this.esc(data.error || 'Download failed.')}</p>` + trail;
        }
    }

    // --- plumbing -----------------------------------------------------------

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
