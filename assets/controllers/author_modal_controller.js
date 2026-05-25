import { Controller } from '@hotwired/stimulus';

const CARD_GRADIENTS = [
    'linear-gradient(135deg,#3a4a6b,#5c6f99)',
    'linear-gradient(135deg,#7a3a5f,#a85b85)',
    'linear-gradient(135deg,#2f5a4e,#508a76)',
    'linear-gradient(135deg,#6b4a2a,#a3754a)',
    'linear-gradient(135deg,#3d3d6b,#6a6ab0)',
    'linear-gradient(135deg,#5a2a3d,#8a4a60)',
];

export default class extends Controller {
    static targets = ['modal', 'cover', 'name', 'meta', 'status', 'facts', 'bio', 'works'];

    connect() {
        this.requestSeq = 0;
    }

    async open(event) {
        const card = event.currentTarget;
        const params = card.dataset;

        this.currentName = params.authorModalNameParam || '';
        this.nameTarget.textContent = this.currentName;
        this.metaTarget.textContent = '';
        this.factsTarget.innerHTML = '';
        this.bioTarget.textContent = '';
        this.worksTarget.innerHTML = '';
        this.statusTarget.textContent = 'Loading…';
        this.statusTarget.hidden = false;

        const image = params.authorModalImageParam;
        if (image) {
            this.coverTarget.style.backgroundImage = `url(${JSON.stringify(image)})`;
            this.coverTarget.classList.add('has-image');
        } else {
            this.coverTarget.style.backgroundImage = '';
            this.coverTarget.classList.remove('has-image');
        }

        this.modalTarget.hidden = false;
        document.body.classList.add('book-modal-open');

        const slug = params.authorModalSlugParam || '';
        if (!slug) {
            this.statusTarget.textContent = 'No identifier on this card.';
            return;
        }
        const source = params.authorModalSourceParam || 'hardcover';

        const seq = ++this.requestSeq;
        const query = new URLSearchParams({ source, slug });
        if (params.authorModalNameParam)        query.set('name', params.authorModalNameParam);
        if (params.authorModalImageParam)       query.set('imageUrl', params.authorModalImageParam);
        if (params.authorModalExternalUrlParam) query.set('externalUrl', params.authorModalExternalUrlParam);

        try {
            const response = await fetch(`/authors/metadata?${query.toString()}`, {
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
            this.render(data.author || {});
        } catch (e) {
            if (seq !== this.requestSeq) return;
            this.statusTarget.textContent = "Couldn't load metadata.";
        }
    }

    close() {
        this.modalTarget.hidden = true;
        // Shared scroll-lock with book-modal — only release when no .book-modal is open.
        if (document.querySelector('.book-modal:not([hidden])') === null) {
            document.body.classList.remove('book-modal-open');
        }
        this.requestSeq++;
        this.worksTarget.innerHTML = '';
    }

    backdropClick(event) {
        if (event.target === this.modalTarget) {
            this.close();
        }
    }

    // Placeholder: no search endpoint yet — populate topbar search and focus.
    searchBooks() {
        const input = document.querySelector('.topbar .search-input');
        if (!input) return;
        input.value = this.currentName || '';
        this.close();
        input.focus();
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    render(author) {
        if (author.name) {
            this.nameTarget.textContent = author.name;
            this.currentName = author.name;
        }

        if (author.imageUrl) {
            this.coverTarget.style.backgroundImage = `url(${JSON.stringify(author.imageUrl)})`;
            this.coverTarget.classList.add('has-image');
        }

        if (author.fetched === false) {
            this.statusTarget.textContent = "Couldn't reach the metadata provider — try again later.";
            this.statusTarget.hidden = false;
        } else {
            this.statusTarget.hidden = true;
        }

        const metaBits = [];
        if (author.location) metaBits.push(escapeHtml(author.location));
        const lifeSpan = formatLifeSpan(author.bornYear, author.deathYear);
        if (lifeSpan) metaBits.push(escapeHtml(lifeSpan));
        this.metaTarget.innerHTML = metaBits.join(' • ');

        const facts = [];
        if (typeof author.booksCount === 'number' && author.booksCount > 0) {
            facts.push(['Books', formatNumber(author.booksCount)]);
        }
        if (typeof author.usersCount === 'number' && author.usersCount > 0) {
            facts.push(['Followers', formatNumber(author.usersCount)]);
        }
        this.factsTarget.innerHTML = facts.map(([k, v]) =>
            `<div class="book-modal-fact"><dt>${escapeHtml(k)}</dt><dd>${escapeHtml(v)}</dd></div>`
        ).join('');

        if (author.bio) {
            this.bioTarget.textContent = stripBasicHtml(author.bio);
        }

        this.renderWorks(author.name || this.currentName, Array.isArray(author.topBooks) ? author.topBooks : []);
    }

    renderWorks(authorName, books) {
        if (books.length === 0) {
            this.worksTarget.innerHTML = '';
            return;
        }
        const cards = books.map((b, i) => this.renderBookCard(authorName, b, i)).join('');
        // Mirror homepage row markup so Stimulus auto-wires carousel + book-modal on the new nodes.
        this.worksTarget.innerHTML = `
            <section class="row author-modal-row">
                <div class="row-header">
                    <div>
                        <h2 class="row-title">Books by ${escapeHtml(authorName || 'this author')}</h2>
                        <p class="row-subtitle">Most popular on Hardcover</p>
                    </div>
                </div>
                <div class="carousel" data-controller="carousel">
                    <button class="carousel-btn carousel-btn-left" aria-label="Scroll left" data-scroll="-1" data-carousel-target="left" data-action="click->carousel#scroll">‹</button>
                    <div class="carousel-track" data-carousel-target="track">${cards}</div>
                    <button class="carousel-btn carousel-btn-right" aria-label="Scroll right" data-scroll="1" data-carousel-target="right" data-action="click->carousel#scroll">›</button>
                </div>
            </section>
        `;
    }

    renderBookCard(authorName, book, index) {
        const gradient = CARD_GRADIENTS[index % CARD_GRADIENTS.length];
        const title = book.title || '';
        const slug = book.slug || '';
        const cover = book.coverUrl || '';
        const externalUrl = book.externalUrl || '';
        const downloaded = !!book.downloaded;
        const clickable = slug !== '';
        const attrs = clickable
            ? `role="button" tabindex="0"
               data-action="click->book-modal#open keydown.enter->book-modal#open keydown.space->book-modal#open"
               data-book-modal-title-param="${escapeAttr(title)}"
               data-book-modal-author-param="${escapeAttr(authorName || '')}"
               data-book-modal-cover-param="${escapeAttr(cover)}"
               data-book-modal-external-url-param="${escapeAttr(externalUrl)}"
               data-book-modal-downloaded-param="${downloaded ? '1' : '0'}"
               data-book-modal-source-param="hardcover"
               data-book-modal-external-id-param="${escapeAttr(slug)}"`
            : '';
        const coverInner = cover
            ? `<img class="card-cover-img" src="${escapeAttr(cover)}" alt="${escapeAttr(title)}" loading="lazy">`
            : `<span class="card-cover-title">${escapeHtml(title.slice(0, 24))}</span>`;
        const checkmark = downloaded
            ? `<span class="card-check" title="In your library" aria-label="In your library">
                   <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">
                       <path d="M5 12.5l4.2 4.2L19 7" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                   </svg>
               </span>`
            : '';
        return `
            <article class="card${clickable ? ' card-clickable' : ''}" ${attrs}>
                <div class="card-cover" style="background: ${gradient};">
                    ${coverInner}
                    ${checkmark}
                    <div class="card-overlay">
                        <div class="card-title" title="${escapeAttr(title)}">${escapeHtml(title)}</div>
                    </div>
                </div>
            </article>
        `;
    }
}

function formatLifeSpan(born, died) {
    if (typeof born === 'number' && typeof died === 'number') return `${born}–${died}`;
    if (typeof born === 'number') return `b. ${born}`;
    if (typeof died === 'number') return `d. ${died}`;
    return '';
}

function formatNumber(n) {
    return new Intl.NumberFormat().format(n);
}

function stripBasicHtml(str) {
    return String(str).replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
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
