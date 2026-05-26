import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['modal', 'cover', 'action', 'search', 'title', 'author', 'status', 'facts', 'genres', 'description'];

    connect() {
        this.requestSeq = 0;
    }

    async open(event) {
        const card = event.currentTarget;
        const params = card.dataset;

        this.titleTarget.textContent = params.bookModalTitleParam || '';
        this.renderAuthor(params.bookModalAuthorParam || '');
        this.factsTarget.innerHTML = '';
        this.genresTarget.innerHTML = '';
        this.descriptionTarget.textContent = '';
        this.statusTarget.textContent = 'Loading…';
        this.statusTarget.hidden = false;
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
        this.setAction(this.seedDownloaded);
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
            this.statusTarget.textContent = 'No identifier on this card.';
            return;
        }

        try {
            const response = await fetch(`/books/metadata?${query.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (seq !== this.requestSeq) return;
            if (!response.ok) {
                this.statusTarget.textContent = `Couldn't load metadata (HTTP ${response.status}).`;
                return;
            }
            const data = await response.json();
            if (seq !== this.requestSeq) return;
            this.render(data.book || {});
        } catch (e) {
            if (seq !== this.requestSeq) return;
            this.statusTarget.textContent = "Couldn't load metadata.";
        }
    }

    setAction(downloaded) {
        const action = this.actionTarget;
        action.hidden = false;
        if (downloaded) {
            action.textContent = 'In Library';
            action.classList.add('is-have');
            action.classList.remove('is-get');
            action.disabled = true;
        } else {
            action.textContent = 'Get';
            action.classList.add('is-get');
            action.classList.remove('is-have');
            action.disabled = false;
        }
        this.searchTarget.hidden = downloaded;
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
            const stored = type;
            if (stored !== 'title') window.localStorage.setItem('spinescout.searchType', stored);
            else window.localStorage.removeItem('spinescout.searchType');
        } catch (_) { /* ignore */ }
        const params = new URLSearchParams({ q: term });
        if (type !== 'title') params.set('type', type);
        window.location.href = `/browse?${params.toString()}`;
    }

    close() {
        this.modalTarget.hidden = true;
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
        if (book.title) this.titleTarget.textContent = book.title;
        if (book.author) this.renderAuthor(book.author);

        this.setAction(this.seedDownloaded || !!book.downloaded);

        if (book.fetched === false) {
            this.statusTarget.textContent = "Couldn't reach the metadata provider — try again later.";
            this.statusTarget.hidden = false;
        } else {
            this.statusTarget.hidden = true;
        }

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
        if (book.publisher) {
            facts.push(['Publisher', `<span class="book-modal-link" role="link" tabindex="0" data-action="click->book-modal#searchFor keydown.enter->book-modal#searchFor keydown.space->book-modal#searchFor" data-search-term="${escapeAttr(book.publisher)}" data-search-type="publisher">${escapeHtml(book.publisher)}</span>`]);
        }
        if (book.publishedDate) facts.push(['Published', escapeHtml(book.publishedDate)]);
        if (book.language) facts.push(['Language', escapeHtml(book.language)]);
        if (book.isbn) facts.push(['ISBN', escapeHtml(book.isbn)]);
        this.factsTarget.innerHTML = facts.map(([k, v]) =>
            `<div class="book-modal-fact"><dt>${escapeHtml(k)}</dt><dd>${v}</dd></div>`
        ).join('');

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
