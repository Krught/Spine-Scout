import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['modal', 'cover', 'action', 'search', 'recommend', 'title', 'author', 'status', 'facts', 'genres', 'description', 'format', 'refresh'];

    connect() {
        this.requestSeq = 0;
        this.currentBookId = null;
        this.currentSeed = null;
        this.currentIsbn = null;
        // Per-format ownership/request status; null until /books/metadata resolves.
        this.modes = null;
        this.currentMode = 'book';
        this.seedMode = 'book';
        this.audiobookAvailable = false;
        this.bookData = null;
    }

    async open(event) {
        const card = event.currentTarget;
        const params = card.dataset;
        // Remember the card we opened from so a manual download can stamp its
        // "Downloaded" badge in place without a page reload.
        this.currentCard = card;

        // Opening a (new) book always starts on the details view, never a stale
        // Interactive Search panel left open from a previous book.
        this.dispatch('close', { prefix: 'book-modal', bubbles: true });

        this.titleTarget.textContent = params.bookModalTitleParam || '';
        this.renderAuthor(params.bookModalAuthorParam || '');
        // Fill the metadata area with blurred placeholder text instead of a "Loading…"
        // line, so the modal looks populated while /books/metadata resolves. render()
        // (or an error) swaps it for the real content in place.
        this.statusTarget.textContent = '';
        this.statusTarget.hidden = true;
        this.showSkeleton();
        const cover = params.bookModalCoverParam;
        if (cover) {
            this.coverTarget.style.backgroundImage = `url(${JSON.stringify(cover)})`;
            this.coverTarget.classList.add('has-image');
        } else {
            this.coverTarget.style.backgroundImage = '';
            this.coverTarget.classList.remove('has-image');
        }

        // Homepage seed is authoritative-positive: server response can upgrade
        // false→true but never the reverse.
        this.seedDownloaded = params.bookModalDownloadedParam === '1';
        this.seedRequestStatus = params.bookModalRequestStatusParam || null;
        // Reset format state; the card may already know an audiobook edition exists so the
        // toggle can appear before the fetch resolves. Open in the mode the card was rendered in
        // (clicking from the Audiobook listing opens straight to the audiobook view).
        this.modes = null;
        this.bookData = null;
        this.audiobookAvailable = params.bookModalAudiobookParam === '1';
        const openMode = (this.audiobookAvailable && params.bookModalModeParam === 'audiobook') ? 'audiobook' : 'book';
        this.currentMode = openMode;
        this.seedMode = openMode;
        this.currentIsbn = null;
        this.currentBookId = params.bookModalIdParam ? Number(params.bookModalIdParam) : null;
        this.currentSeed = {
            source: params.bookModalSourceParam || null,
            externalId: params.bookModalExternalIdParam || null,
            externalUrl: params.bookModalExternalUrlParam || null,
            title: params.bookModalTitleParam || '',
            author: params.bookModalAuthorParam || '',
            coverUrl: params.bookModalCoverParam || '',
        };
        // "More like this" stays hidden until metadata resolves and tells us the book is
        // recommendable (has a resolvable Hardcover record). Reset it for the new book so a
        // previous book's button doesn't linger.
        this.recommendSeed = null;
        if (this.hasRecommendTarget) this.recommendTarget.hidden = true;
        this.renderFormatControl();
        this.applyMode(this.currentMode);
        this.modalTarget.hidden = false;
        document.body.classList.add('book-modal-open');

        const seq = ++this.requestSeq;
        const query = new URLSearchParams();
        if (params.bookModalIdParam) {
            query.set('id', params.bookModalIdParam);
        } else if (params.bookModalSourceParam && params.bookModalExternalIdParam) {
            query.set('source', params.bookModalSourceParam);
            query.set('externalId', params.bookModalExternalIdParam);
            if (params.bookModalTitleParam) query.set('title', params.bookModalTitleParam);
            if (params.bookModalAuthorParam) query.set('author', params.bookModalAuthorParam);
            if (params.bookModalExternalUrlParam) query.set('externalUrl', params.bookModalExternalUrlParam);
        } else {
            this.clearSkeleton();
            this.showStatus('No identifier on this card.');
            return;
        }

        try {
            const response = await fetch(`/books/metadata?${query.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (seq !== this.requestSeq) return;
            if (!response.ok) {
                this.clearSkeleton();
                this.showStatus(`Couldn't load metadata (HTTP ${response.status}).`);
                return;
            }
            const data = await response.json();
            if (seq !== this.requestSeq) return;
            this.render(data.book || {});
        } catch (e) {
            if (seq !== this.requestSeq) return;
            this.clearSkeleton();
            this.showStatus("Couldn't load metadata.");
        }
    }

    showStatus(message) {
        this.statusTarget.textContent = message;
        this.statusTarget.hidden = false;
    }

    // Blurred, fake metadata shown while the real data is fetched. The placeholder
    // containers carry `book-modal-skel` (blur + pulse); clearSkeleton() strips both
    // the class and the fake text so render() can drop real content into empty nodes.
    showSkeleton() {
        this.factsTarget.innerHTML = SKELETON_FACTS;
        this.genresTarget.innerHTML = SKELETON_GENRES;
        this.descriptionTarget.innerHTML = SKELETON_DESCRIPTION;
        for (const t of [this.factsTarget, this.genresTarget, this.descriptionTarget]) {
            t.classList.add('book-modal-skel');
            t.setAttribute('aria-hidden', 'true');
        }
    }

    clearSkeleton() {
        for (const t of [this.factsTarget, this.genresTarget, this.descriptionTarget]) {
            t.classList.remove('book-modal-skel');
            t.removeAttribute('aria-hidden');
        }
        this.factsTarget.innerHTML = '';
        this.genresTarget.innerHTML = '';
        this.descriptionTarget.textContent = '';
    }

    setAction(downloaded, requestStatus) {
        const action = this.actionTarget;
        action.hidden = false;
        action.classList.remove('is-get', 'is-have', 'is-requested', 'is-pending', 'is-approved', 'is-rejected', 'is-downloaded');
        const statusMap = {
            pending:    { text: 'Pending',    cls: 'is-pending' },
            approved:   { text: 'Approved',   cls: 'is-approved' },
            rejected:   { text: 'Rejected',   cls: 'is-rejected' },
            downloaded: { text: 'Downloaded', cls: 'is-downloaded' },
        };
        if (downloaded) {
            action.textContent = 'In Library';
            action.classList.add('is-have');
            action.disabled = true;
        } else if (requestStatus && statusMap[requestStatus]) {
            action.textContent = statusMap[requestStatus].text;
            action.classList.add(statusMap[requestStatus].cls);
            action.disabled = true;
        } else {
            action.textContent = 'Get';
            action.classList.add('is-get');
            action.disabled = false;
        }
        // Hide Interactive Search once the book is in the library or its file has
        // already been downloaded ('downloaded' pseudo-status = approved + delivered).
        this.searchTarget.hidden = downloaded || requestStatus === 'downloaded';
    }

    // Render the Book/Audiobook control into the format slot: a segmented toggle when an
    // audiobook edition exists, otherwise a static "Book" indicator.
    renderFormatControl() {
        if (!this.hasFormatTarget) return;
        const el = this.formatTarget;
        if (!this.audiobookAvailable) {
            this.currentMode = 'book';
            el.innerHTML = '<span class="book-modal-format-badge">Book</span>';
            el.hidden = false;
            return;
        }
        const opt = (mode, label) =>
            `<button type="button" class="book-modal-format-option${this.currentMode === mode ? ' is-active' : ''}" ` +
            `data-mode="${mode}" data-action="click->book-modal#selectMode" ` +
            `aria-pressed="${this.currentMode === mode ? 'true' : 'false'}">${label}</button>`;
        el.innerHTML =
            `<div class="book-modal-format-toggle" role="group" aria-label="Book or audiobook" data-mode="${this.currentMode}">` +
            '<span class="book-modal-format-thumb" aria-hidden="true"></span>' +
            opt('book', 'Book') + opt('audiobook', 'Audiobook') +
            '</div>';
        el.hidden = false;
    }

    selectMode(event) {
        const mode = event.currentTarget.dataset.mode === 'audiobook' ? 'audiobook' : 'book';
        if (mode === this.currentMode) return;
        this.currentMode = mode;
        const wrap = this.formatTarget.querySelector('.book-modal-format-toggle');
        if (wrap) {
            wrap.dataset.mode = mode;
            wrap.querySelectorAll('.book-modal-format-option').forEach((o) => {
                const active = o.dataset.mode === mode;
                o.classList.toggle('is-active', active);
                o.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        }
        this.applyMode(mode);
        // Facts differ by mode (audiobook shows Narrator + Audio length instead of Publisher/Published).
        if (this.bookData) this.renderFacts();
    }

    // Drive the action button from the selected format's ownership/status. The card seed
    // reflects the mode the originating card was rendered in (seedMode) and is
    // authoritative-positive (can upgrade false→true, never the reverse).
    applyMode(mode) {
        const m = (this.modes && this.modes[mode]) ? this.modes[mode] : { downloaded: false, requestStatus: null };
        let downloaded = !!m.downloaded;
        let status = m.requestStatus || null;
        if (mode === this.seedMode) {
            downloaded = downloaded || this.seedDownloaded;
            status = status || this.seedRequestStatus || null;
        }
        this.setAction(downloaded, status);
    }

    // Build the facts list for the current mode. Audiobook mode hides Publisher/Published and
    // surfaces Narrator + Audio length; book mode keeps the print/ebook facts.
    renderFacts() {
        const book = this.bookData || {};
        const audio = this.currentMode === 'audiobook';
        const facts = [];
        if (book.series) {
            let badge = '';
            if (book.seriesIndex) {
                badge = `#${book.seriesIndex}`;
                if (book.seriesTotal) badge += ` of ${book.seriesTotal}`;
            } else if (book.seriesTotal) {
                badge = `${book.seriesTotal} books`;
            }
            const badgeHtml = badge !== ''
                ? ` <span class="book-modal-series-badge">${escapeHtml(badge)}</span>`
                : '';
            facts.push(['Series', `<span class="book-modal-link" role="link" tabindex="0" data-action="click->book-modal#searchFor keydown.enter->book-modal#searchFor keydown.space->book-modal#searchFor" data-search-term="${escapeAttr(book.series)}" data-search-type="series">${escapeHtml(book.series)}</span>${badgeHtml}`]);
        }
        if (audio) {
            if (book.narrator) facts.push(['Narrator', escapeHtml(book.narrator)]);
            const len = formatDuration(book.audioSeconds);
            if (len) facts.push(['Audio length', escapeHtml(len)]);
        } else {
            if (book.publisher) {
                facts.push(['Publisher', `<span class="book-modal-link" role="link" tabindex="0" data-action="click->book-modal#searchFor keydown.enter->book-modal#searchFor keydown.space->book-modal#searchFor" data-search-term="${escapeAttr(book.publisher)}" data-search-type="publisher">${escapeHtml(book.publisher)}</span>`]);
            }
            if (book.publishedDate) facts.push(['Published', escapeHtml(book.publishedDate)]);
        }
        if (book.language) facts.push(['Language', escapeHtml(book.language)]);
        if (book.isbn) {
            this.currentIsbn = book.isbn;
            facts.push(['ISBN', escapeHtml(book.isbn)]);
        }
        this.factsTarget.innerHTML = facts.map(([k, v]) =>
            `<div class="book-modal-fact"><dt>${escapeHtml(k)}</dt><dd>${v}</dd></div>`
        ).join('');
    }

    async requestBook(event) {
        if (event && event.preventDefault) event.preventDefault();
        const action = this.actionTarget;
        if (action.disabled) return;
        if (this.currentBookId === null && (!this.currentSeed || !this.currentSeed.source || !this.currentSeed.externalId)) {
            return;
        }

        const tokenEl = document.querySelector('meta[name="csrf-token"]');
        const token = tokenEl ? tokenEl.getAttribute('content') : '';

        const payload = { _csrf_token: token };
        if (this.currentBookId !== null) {
            payload.bookId = this.currentBookId;
        } else {
            payload.source = this.currentSeed.source;
            payload.externalId = this.currentSeed.externalId;
            if (this.currentSeed.title) payload.title = this.currentSeed.title;
            if (this.currentSeed.author) payload.author = this.currentSeed.author;
            if (this.currentSeed.externalUrl) payload.externalUrl = this.currentSeed.externalUrl;
        }
        if (this.currentSeed && this.currentSeed.coverUrl) {
            payload.coverUrl = this.currentSeed.coverUrl;
        }
        if (this.currentMode === 'audiobook') {
            payload.audiobook = 1;
        }

        const previousText = action.textContent;
        action.disabled = true;
        action.textContent = 'Requesting…';

        try {
            const response = await fetch('/requests/create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });
            if (!response.ok) {
                action.disabled = false;
                action.textContent = previousText;
                this.statusTarget.textContent = `Couldn't create request (HTTP ${response.status}).`;
                this.statusTarget.hidden = false;
                return;
            }
            const data = await response.json();
            if (data && typeof data.bookId === 'number') {
                this.currentBookId = data.bookId;
            }
            // The server decides the initial status (auto-approval may approve it
            // instantly); fall back to 'pending' when it isn't reported.
            const status = data && typeof data.status === 'string' ? data.status : 'pending';
            if (!this.modes) {
                this.modes = { book: { downloaded: false, requestStatus: null }, audiobook: { downloaded: false, requestStatus: null } };
            }
            if (!this.modes[this.currentMode]) this.modes[this.currentMode] = { downloaded: false, requestStatus: null };
            this.modes[this.currentMode].requestStatus = status;
            if (this.currentMode === 'book') this.seedRequestStatus = status;
            this.applyMode(this.currentMode);
            // Reflect the new status on the originating card(s) behind the modal — but only when
            // the request matches the mode the card represents (seedMode), so e.g. requesting the
            // audiobook from a Book-listing card doesn't mis-stamp it.
            if (this.currentMode === this.seedMode) this.markStatus(this.currentBookId, status);
        } catch (e) {
            action.disabled = false;
            action.textContent = previousText;
            this.statusTarget.textContent = "Couldn't create request.";
            this.statusTarget.hidden = false;
        }
    }

    // Manual "refresh metadata": force a fresh upstream fetch and re-render in place. Keeps the
    // current Book/Audiobook mode (render() reads this.currentMode).
    async refreshMetadata(event) {
        if (event && event.preventDefault) event.preventDefault();
        if (this.currentBookId === null && (!this.currentSeed || !this.currentSeed.source || !this.currentSeed.externalId)) {
            return;
        }
        const btn = this.hasRefreshTarget ? this.refreshTarget : (event && event.currentTarget) || null;
        if (btn && btn.classList.contains('is-spinning')) return;

        const tokenEl = document.querySelector('meta[name="csrf-token"]');
        const payload = { _csrf_token: tokenEl ? tokenEl.getAttribute('content') : '' };
        if (this.currentBookId !== null) {
            payload.id = this.currentBookId;
        } else {
            payload.source = this.currentSeed.source;
            payload.externalId = this.currentSeed.externalId;
            if (this.currentSeed.title) payload.title = this.currentSeed.title;
            if (this.currentSeed.author) payload.author = this.currentSeed.author;
            if (this.currentSeed.externalUrl) payload.externalUrl = this.currentSeed.externalUrl;
        }

        if (btn) { btn.classList.add('is-spinning'); btn.disabled = true; }
        const seq = ++this.requestSeq;
        try {
            const res = await fetch('/books/metadata/refresh', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });
            if (seq !== this.requestSeq) return;
            if (!res.ok) { this.showStatus(`Couldn't refresh metadata (HTTP ${res.status}).`); return; }
            const data = await res.json();
            if (seq !== this.requestSeq) return;
            this.render(data.book || {});
        } catch (e) {
            if (seq !== this.requestSeq) return;
            this.showStatus("Couldn't refresh metadata.");
        } finally {
            if (btn) { btn.classList.remove('is-spinning'); btn.disabled = false; }
        }
    }

    // Reveal the Interactive Search panel, handing it the current (possibly
    // upgraded) title/author/ISBN so its query fields seed from what the user is
    // looking at. The panel (interactive-search controller) listens on @window.
    openSearch(event) {
        if (event && event.preventDefault) event.preventDefault();
        this.dispatch('opensearch', {
            prefix: 'book-modal',
            bubbles: true,
            detail: {
                bookId: this.currentBookId,
                title: (this.titleTarget.textContent || '').trim(),
                author: (this.authorTarget.textContent || '').trim(),
                isbn: this.currentIsbn || '',
                source: this.currentSeed ? this.currentSeed.source : null,
                externalId: this.currentSeed ? this.currentSeed.externalId : null,
            },
        });
    }

    // "More like this": hand the seed book id to /browse, which renders co-occurrence
    // recommendations through the same grid/infinite-scroll UI as search. Full navigation so
    // it works whether the modal was opened from the home page or /browse.
    moreLikeThis(event) {
        if (event && event.preventDefault) event.preventDefault();
        if (!this.recommendSeed) return;
        const params = new URLSearchParams({ like: String(this.recommendSeed) });
        const title = (this.titleTarget.textContent || '').trim();
        if (title) params.set('likeTitle', title);
        window.location.href = `/browse?${params.toString()}`;
    }

    // The panel reports a successful manual download — the file is fetched but not
    // yet imported, so the book is "Downloaded" (not "In Library"). Reflect it on
    // the modal action and stamp the originating card(s) so the badge appears
    // without a page reload.
    onDownloaded(event) {
        const bookId = (event && event.detail && event.detail.bookId) || this.currentBookId || null;
        if (bookId !== null) this.currentBookId = bookId;
        // Interactive Search downloads ebooks, so this is a book-mode delivery.
        this.seedRequestStatus = 'downloaded';
        if (this.modes && this.modes.book) this.modes.book.requestStatus = 'downloaded';
        if (this.currentMode === 'book') this.applyMode('book');
        this.markStatus(bookId, 'downloaded');
    }

    // Stamp the given request status onto the card we opened from and any other card
    // on the page for the same book (it can appear in several rows/carousels), so the
    // grid reflects a "Get"/download without a page reload.
    markStatus(bookId, status) {
        const cards = new Set();
        if (this.currentCard) cards.add(this.currentCard);
        if (bookId !== null && bookId !== undefined) {
            document
                .querySelectorAll(`.card[data-book-modal-id-param="${bookId}"]`)
                .forEach((c) => cards.add(c));
        }
        cards.forEach((card) => this.stampStatusBadge(card, status));
    }

    stampStatusBadge(card, status) {
        if (!STATUS_BADGE_SVG[status]) return;
        card.dataset.bookModalRequestStatusParam = status;
        const cover = card.querySelector('.card-cover');
        if (!cover) return;
        // Leave an existing "In Library" check untouched. 'downloaded' is terminal —
        // don't let a later pending/approved stamp downgrade it.
        if (cover.querySelector('.card-check')) return;
        if (status !== 'downloaded' && cover.querySelector('.card-status-downloaded')) return;
        // Replace any other request-status badge.
        cover.querySelectorAll('.card-status').forEach((b) => b.remove());
        const label = STATUS_BADGE_LABEL[status];
        const badge = document.createElement('span');
        badge.className = `card-status card-status-${status}`;
        badge.title = label;
        badge.setAttribute('aria-label', label);
        badge.innerHTML = STATUS_BADGE_SVG[status];
        cover.appendChild(badge);
    }

    renderAuthor(raw) {
        const names = String(raw).split(',').map(s => s.trim()).filter(Boolean);
        if (names.length === 0) {
            this.authorTarget.textContent = '';
            return;
        }
        this.authorTarget.innerHTML = names
            .map(n => `<span class="book-modal-link" role="link" tabindex="0" data-action="click->book-modal#searchFor keydown.enter->book-modal#searchFor keydown.space->book-modal#searchFor" data-search-term="${escapeAttr(n)}" data-search-type="author">${escapeHtml(n)}</span>`)
            .join(', ');
    }

    searchFor(event) {
        const el = event.currentTarget;
        const term = (el && el.dataset && el.dataset.searchTerm) ? el.dataset.searchTerm.trim() : '';
        if (!term) return;
        if (event && event.preventDefault) event.preventDefault();
        const validTypes = ['title', 'author', 'genre', 'series', 'publisher'];
        const rawType = el.dataset.searchType || 'title';
        const type = validTypes.includes(rawType) ? rawType : 'title';
        try {
            // Persist only the shown lenses (author/genre) so they're remembered across
            // navigation. Hidden lenses (series/publisher) are URL-only — they still show
            // on the resulting /browse page via the ?type param, but must not be stored or
            // clobber the remembered shown lens. A title search clears the remembered lens.
            if (type === 'author' || type === 'genre') {
                window.localStorage.setItem('spinescout.searchType', type);
            } else if (type === 'title') {
                window.localStorage.removeItem('spinescout.searchType');
            }
        } catch (_) { /* ignore */ }
        const params = new URLSearchParams({ q: term });
        if (type !== 'title') params.set('type', type);
        window.location.href = `/browse?${params.toString()}`;
    }

    close() {
        this.modalTarget.hidden = true;
        // Collapse the Interactive Search panel too, so it isn't still revealed
        // (hiding the book body via CSS) the next time a book opens.
        this.dispatch('close', { prefix: 'book-modal', bubbles: true });
        // Author modal can open a book modal on top — only unlock scroll when no .book-modal remains.
        if (document.querySelector('.book-modal:not([hidden])') === null) {
            document.body.classList.remove('book-modal-open');
        }
        this.requestSeq++;
    }

    backdropClick(event) {
        if (event.target === this.modalTarget) {
            this.close();
        }
    }

    render(book) {
        // Drop the blurred placeholders before populating; render only sets genres /
        // description when present, so without this they'd linger blurred.
        this.clearSkeleton();
        this.bookData = book;
        if (book.title) this.titleTarget.textContent = book.title;
        if (book.author) this.renderAuthor(book.author);

        if (typeof book.id === 'number') {
            this.currentBookId = book.id;
        }

        // Per-format ownership/status from the server (falling back to top-level = book mode).
        this.audiobookAvailable = !!book.audiobookAvailable || this.audiobookAvailable;
        this.modes = (book.modes && book.modes.book)
            ? book.modes
            : {
                book: { downloaded: !!book.downloaded, requestStatus: book.requestStatus || null },
                audiobook: { downloaded: false, requestStatus: null },
            };
        this.renderFormatControl();
        this.applyMode(this.currentMode);

        // Reveal "More like this" only when the server resolved a recommendable seed id.
        if (this.hasRecommendTarget) {
            if (typeof book.recommendSeed === 'number' && book.recommendSeed > 0) {
                this.recommendSeed = book.recommendSeed;
                this.recommendTarget.hidden = false;
            } else {
                this.recommendSeed = null;
                this.recommendTarget.hidden = true;
            }
        }

        if (book.fetched === false) {
            this.statusTarget.textContent = "Couldn't reach the metadata provider — try again later.";
            this.statusTarget.hidden = false;
        } else {
            this.statusTarget.hidden = true;
        }

        this.renderFacts();

        if (Array.isArray(book.genres) && book.genres.length > 0) {
            this.genresTarget.innerHTML = book.genres
                .map(g => `<span class="book-modal-genre" role="link" tabindex="0" data-action="click->book-modal#searchFor keydown.enter->book-modal#searchFor keydown.space->book-modal#searchFor" data-search-term="${escapeAttr(g)}" data-search-type="genre">${escapeHtml(g)}</span>`)
                .join('');
        }

        if (book.description) {
            this.descriptionTarget.textContent = book.description;
        }
    }
}

// Same "Downloaded" (download-into-tray) glyph the cards render in browse_controller.
const DOWNLOADED_BADGE_SVG =
    '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">' +
    '<path d="M12 4v9m0 0l-3.5-3.5M12 13l3.5-3.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>' +
    '<path d="M5 18h14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>';

// Request-status badge glyphs/labels, kept identical to the server-rendered cards in
// browse_controller.js::buildCard() and templates/home/index.html.twig so a badge
// stamped in place after a "Get" matches one rendered on the next page load.
const STATUS_BADGE_SVG = {
    pending:
        '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">' +
        '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2.5"/>' +
        '<path d="M12 7v5l3 2" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    approved:
        '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">' +
        '<path d="M7 10v9h-3v-9h3zm3 9c-.55 0-1-.45-1-1v-8.4l3.6-6.6c.32-.58 1.04-.79 1.62-.46.42.23.66.69.61 1.16l-.49 4.3h5.16c1.1 0 2 .9 2 2 0 .27-.06.53-.16.78l-2.55 6.78c-.29.78-1.04 1.3-1.88 1.3h-6.91z" fill="currentColor"/></svg>',
    rejected:
        '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">' +
        '<path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>',
    downloaded: DOWNLOADED_BADGE_SVG,
};
const STATUS_BADGE_LABEL = {
    pending: 'Pending',
    approved: 'Approved',
    rejected: 'Rejected',
    downloaded: 'Downloaded',
};

// Fake, blurred metadata placeholders. The text itself is never read (blurred +
// aria-hidden via the container); it only needs realistic length/shape so the modal
// looks like a populated record while /books/metadata loads.
const SKELETON_FACTS =
    '<div class="book-modal-fact"><dt>Series</dt><dd>The Silent Horizon #2 of 5</dd></div>' +
    '<div class="book-modal-fact"><dt>Publisher</dt><dd>Evergreen House</dd></div>' +
    '<div class="book-modal-fact"><dt>Published</dt><dd>March 2021</dd></div>' +
    '<div class="book-modal-fact"><dt>Language</dt><dd>English</dd></div>' +
    '<div class="book-modal-fact"><dt>ISBN</dt><dd>9781234567890</dd></div>';

const SKELETON_GENRES = ['Fantasy', 'Adventure', 'Mythology', 'Coming of Age']
    .map((g) => `<span class="book-modal-genre">${g}</span>`)
    .join('');

const SKELETON_DESCRIPTION =
    'A sweeping tale that follows a reluctant hero across distant lands, weaving together ' +
    'old secrets and fragile new alliances. As the journey deepens, loyalties are tested ' +
    'and the true cost of the quest comes sharply into focus, building toward a finale ' +
    'that readers will not soon forget.';

// Seconds → "Xh Ym" (or "Ym"). Returns null for missing/non-positive values.
function formatDuration(seconds) {
    if (typeof seconds !== 'number' || !isFinite(seconds) || seconds <= 0) return null;
    const totalMin = Math.round(seconds / 60);
    const h = Math.floor(totalMin / 60);
    const m = totalMin % 60;
    if (h > 0) return m > 0 ? `${h}h ${m}m` : `${h}h`;
    return `${m}m`;
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function escapeAttr(str) {
    return escapeHtml(str);
}
