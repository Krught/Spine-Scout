import { Controller } from '@hotwired/stimulus';

const COVER_GRADIENTS = [
    'linear-gradient(135deg,#3a4a6b,#5c6f99)',
    'linear-gradient(135deg,#7a3a5f,#a85b85)',
    'linear-gradient(135deg,#2f5a4e,#508a76)',
    'linear-gradient(135deg,#6b4a2a,#a3754a)',
    'linear-gradient(135deg,#3d3d6b,#6a6ab0)',
    'linear-gradient(135deg,#5a2a3d,#8a4a60)',
];

export default class extends Controller {
    static targets = ['grid', 'sentinel', 'status', 'sort', 'dir', 'dirText', 'rowTitle'];
    static values = { itemsUrl: String, searchUrl: String };

    connect() {
        this.resetState();

        const initialUrl = new URL(window.location.href);
        const initialQuery = initialUrl.searchParams.get('q');
        this.searchQuery = initialQuery && initialQuery.trim() !== '' ? initialQuery.trim() : null;
        const initialType = initialUrl.searchParams.get('type');
        this.searchType = ['title', 'author', 'genre', 'series', 'publisher'].includes(initialType) ? initialType : 'title';
        if (this.searchQuery) this.applySearchMode();

        this.onSearchSubmit = (e) => this.handleSearchSubmit(e);
        window.addEventListener('search:submit', this.onSearchSubmit);

        // Primary trigger: IntersectionObserver fires when the sentinel enters the prefetch
        // zone below the viewport bottom. Zone size depends on search type — see
        // `prefetchRowCount()` (genre uses a larger lookahead because the upstream taggings-join
        // query is slow, so we fire earlier to mask latency).
        this.rebuildObserver();

        // Fallback: some layouts / browsers don't reliably re-fire the observer
        // (sticky topbar, layout thrash). A scroll listener is a cheap safety
        // net, throttled via rAF + the `loading` guard inside loadMore().
        this.onScroll = () => {
            if (this.rafPending) return;
            this.rafPending = true;
            requestAnimationFrame(() => {
                this.rafPending = false;
                this.maybeLoadMore('scroll');
            });
        };
        window.addEventListener('scroll', this.onScroll, { passive: true });
        window.addEventListener('resize', this.onScroll, { passive: true });

        this.loadMore();
    }

    resetState() {
        this.offset = 0;
        this.hasMore = true;
        this.loading = false;
        this.totalRendered = 0;
        this.cancelled = false;
    }

    currentSort() {
        return this.hasSortTarget ? this.sortTarget.value : 'trending';
    }

    currentDir() {
        return this.hasDirTarget ? (this.dirTarget.dataset.direction || 'asc') : 'asc';
    }

    refresh() {
        this.resetState();
        this.gridTarget.innerHTML = '';
        this.statusTarget.textContent = '';
        this.loadMore();
    }

    toggleDir() {
        const next = this.currentDir() === 'desc' ? 'asc' : 'desc';
        this.dirTarget.dataset.direction = next;
        this.dirTarget.title = next === 'desc' ? 'Descending' : 'Ascending';
        if (this.hasDirTextTarget) this.dirTextTarget.textContent = next === 'desc' ? 'Desc' : 'Asc';
        this.dirTarget.classList.toggle('browse-dir-asc', next === 'asc');
        this.refresh();
    }

    openFilters() {
        // Filters UI not implemented yet.
    }

    disconnect() {
        // Turbo's snapshot-preview phase connects a controller on the cached DOM, then
        // disconnects it when the fresh page swaps in. Without this flag, in-flight
        // loadMore() promises continue, append to the now-detached grid (invisible),
        // and recurse forever — sentinelNeedsFill() returns true on detached nodes
        // because getBoundingClientRect().top === 0.
        this.cancelled = true;
        this.observer?.disconnect();
        if (this.onScroll) {
            window.removeEventListener('scroll', this.onScroll);
            window.removeEventListener('resize', this.onScroll);
        }
        if (this.onSearchSubmit) {
            window.removeEventListener('search:submit', this.onSearchSubmit);
        }
    }

    handleSearchSubmit(event) {
        const query = (event.detail && typeof event.detail.query === 'string') ? event.detail.query.trim() : '';
        const rawType = event.detail && typeof event.detail.type === 'string' ? event.detail.type : 'title';
        const type = ['title', 'author', 'genre', 'series', 'publisher'].includes(rawType) ? rawType : 'title';
        this.searchType = type;
        if (query === '') {
            this.searchQuery = null;
            this.applyTrendingMode();
            const url = new URL(window.location.href);
            url.searchParams.delete('q');
            url.searchParams.delete('type');
            window.history.replaceState(null, '', url.toString());
        } else {
            this.searchQuery = query;
            this.applySearchMode();
            const url = new URL(window.location.href);
            url.searchParams.set('q', query);
            if (type === 'title') {
                url.searchParams.delete('type');
            } else {
                url.searchParams.set('type', type);
            }
            window.history.replaceState(null, '', url.toString());
        }
        this.resetState();
        this.gridTarget.innerHTML = '';
        if (this.searchQuery) {
            this.statusTarget.innerHTML = '<span class="browse-spinner" aria-hidden="true"></span> Searching…';
        } else {
            this.statusTarget.textContent = '';
        }
        // Prefetch margin depends on search type (genre uses a larger lookahead).
        this.rebuildObserver();
        this.loadMore();
    }

    applySearchMode() {
        if (!this.hasRowTitleTarget) return;
        this.rowTitleTarget.textContent = '';
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'row-title-icon');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('width', '18');
        svg.setAttribute('height', '18');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        circle.setAttribute('cx', '11');
        circle.setAttribute('cy', '11');
        circle.setAttribute('r', '7');
        const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        line.setAttribute('x1', '21'); line.setAttribute('y1', '21');
        line.setAttribute('x2', '16.65'); line.setAttribute('y2', '16.65');
        svg.appendChild(circle);
        svg.appendChild(line);
        const text = document.createTextNode(` - "${this.searchQuery}"`);
        this.rowTitleTarget.appendChild(svg);
        this.rowTitleTarget.appendChild(text);
    }

    applyTrendingMode() {
        if (this.hasRowTitleTarget) {
            this.rowTitleTarget.textContent = 'Trending';
        }
    }

    rowHeightPx() {
        const rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
        // 8.5rem cover width × 2:3 aspect ratio = row height.
        return rem * 8.5 * 1.5;
    }

    // Genre search hits Hardcover's taggings-join, which is much slower than title/author
    // searches (~5s per page vs <1s). Fire prefetch when the user is 2 rows from the bottom
    // instead of 1, so the next batch is in flight before they see an empty edge.
    prefetchRowCount() {
        return this.searchType === 'genre' && this.searchQuery ? 2 : 1;
    }

    rebuildObserver() {
        if (this.observer) this.observer.disconnect();
        const margin = this.rowHeightPx() * this.prefetchRowCount();
        this.observer = new IntersectionObserver((entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) this.maybeLoadMore('observer');
            }
        }, { rootMargin: `${margin}px 0px` });
        this.observer.observe(this.sentinelTarget);
    }

    maybeLoadMore(/* source */) {
        if (this.cancelled || !this.hasMore || this.loading) return;
        if (this.sentinelNeedsFill()) this.loadMore();
    }

    pageSize() {
        const grid = this.gridTarget;
        const styles = window.getComputedStyle(grid);
        const colCount = styles.gridTemplateColumns.split(' ').filter(Boolean).length || 6;
        const rowHeight = (grid.clientWidth / colCount) * 1.5;
        const viewportRows = Math.max(3, Math.ceil(window.innerHeight / rowHeight));
        // Floor at 100 so we don't spam many small requests; cap at 240 server-side.
        return Math.max(100, Math.min(240, colCount * viewportRows * 2));
    }

    async loadMore() {
        if (this.cancelled || this.loading || !this.hasMore) return;
        this.loading = true;
        this.statusTarget.textContent = 'Loading…';

        const limit = this.pageSize();
        const params = new URLSearchParams({
            offset: String(this.offset),
            limit: String(limit),
            sort: this.currentSort(),
            dir: this.currentDir(),
        });
        const isSearch = !!this.searchQuery;
        if (isSearch) {
            params.set('q', this.searchQuery);
            if (this.searchType && this.searchType !== 'title') params.set('type', this.searchType);
        }
        const baseUrl = isSearch ? this.searchUrlValue : this.itemsUrlValue;
        const url = `${baseUrl}?${params.toString()}`;
        const requestSort = this.currentSort();
        const requestDir = this.currentDir();
        const requestQuery = this.searchQuery;

        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (this.cancelled) return;
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();
            if (this.cancelled) return;
            if (requestSort !== this.currentSort() || requestDir !== this.currentDir() || requestQuery !== this.searchQuery) {
                return;
            }
            this.appendItems(data.items || []);
            this.offset = data.next_offset ?? (this.offset + (data.items?.length || 0));
            this.hasMore = !!data.has_more;
            if (this.hasMore) {
                this.statusTarget.textContent = '';
            } else if (this.totalRendered === 0) {
                this.statusTarget.textContent = isSearch
                    ? `No results for "${requestQuery}".`
                    : 'No trending books available yet.';
            } else {
                this.statusTarget.textContent = 'End of list.';
            }
        } catch (err) {
            this.statusTarget.textContent = 'Failed to load more books. Scroll to retry.';
            this.hasMore = true;
            console.error(err);
        } finally {
            this.loading = false;
        }

        // IntersectionObserver only re-fires when the sentinel transitions in/out
        // of view; if the first batch didn't fill the viewport + a buffer row, the
        // sentinel stays visible and we'd never refetch. Top up explicitly until
        // the page is full or upstream is exhausted.
        if (!this.cancelled && this.hasMore && this.sentinelNeedsFill()) {
            this.loadMore();
        }
    }

    sentinelNeedsFill() {
        // Fire when fewer than N rows of covers are left between the viewport bottom and the
        // sentinel — N is 1 normally, 2 for genre search (see prefetchRowCount).
        const rect = this.sentinelTarget.getBoundingClientRect();
        return rect.top < window.innerHeight + this.rowHeightPx() * this.prefetchRowCount();
    }

    appendItems(items) {
        const frag = document.createDocumentFragment();
        for (const item of items) {
            frag.appendChild(this.buildCard(item, this.totalRendered++));
        }
        this.gridTarget.appendChild(frag);
    }

    buildCard(item, index) {
        const article = document.createElement('article');
        const clickable = !!item.meta_id || (item.meta_source && item.meta_external_id);
        article.className = `card${clickable ? ' card-clickable' : ''}`;
        if (clickable) {
            article.setAttribute('role', 'button');
            article.setAttribute('tabindex', '0');
            article.dataset.action = 'click->book-modal#open keydown.enter->book-modal#open keydown.space->book-modal#open';
            if (item.meta_id) {
                article.dataset.bookModalIdParam = String(item.meta_id);
            } else {
                article.dataset.bookModalSourceParam = item.meta_source;
                article.dataset.bookModalExternalIdParam = item.meta_external_id;
                if (item.external_url) article.dataset.bookModalExternalUrlParam = item.external_url;
            }
        }
        article.dataset.bookModalTitleParam = item.title ?? '';
        article.dataset.bookModalAuthorParam = item.author ?? '';
        article.dataset.bookModalCoverParam = item.cover_url ?? '';
        article.dataset.bookModalDownloadedParam = item.downloaded ? '1' : '0';
        article.dataset.bookModalRequestStatusParam = item.request_status || '';

        const cover = document.createElement('div');
        cover.className = 'card-cover';
        cover.style.background = COVER_GRADIENTS[index % COVER_GRADIENTS.length];

        if (item.cover_url) {
            const img = document.createElement('img');
            img.className = 'card-cover-img';
            img.loading = 'lazy';
            img.src = item.cover_url;
            img.alt = item.title ?? '';
            cover.appendChild(img);
        } else {
            const span = document.createElement('span');
            span.className = 'card-cover-title';
            span.textContent = (item.title ?? '').slice(0, 24);
            cover.appendChild(span);
        }

        if (item.downloaded) {
            const check = document.createElement('span');
            check.className = 'card-check';
            check.title = 'In your library';
            check.setAttribute('aria-label', 'In your library');
            check.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false"><path d="M5 12.5l4.2 4.2L19 7" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            cover.appendChild(check);
        } else if (item.request_status) {
            const labels = { pending: 'Pending', approved: 'Approved', rejected: 'Rejected' };
            const icons = {
                pending: '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2.5"/><path d="M12 7v5l3 2" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                approved: '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false"><path d="M7 10v9h-3v-9h3zm3 9c-.55 0-1-.45-1-1v-8.4l3.6-6.6c.32-.58 1.04-.79 1.62-.46.42.23.66.69.61 1.16l-.49 4.3h5.16c1.1 0 2 .9 2 2 0 .27-.06.53-.16.78l-2.55 6.78c-.29.78-1.04 1.3-1.88 1.3h-6.91z" fill="currentColor"/></svg>',
                rejected: '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>',
            };
            if (icons[item.request_status]) {
                const badge = document.createElement('span');
                badge.className = `card-status card-status-${item.request_status}`;
                const label = labels[item.request_status];
                badge.title = label;
                badge.setAttribute('aria-label', label);
                badge.innerHTML = icons[item.request_status];
                cover.appendChild(badge);
            }
        }

        const overlay = document.createElement('div');
        overlay.className = 'card-overlay';
        const title = document.createElement('div');
        title.className = 'card-title';
        title.title = item.title ?? '';
        title.textContent = item.title ?? '';
        overlay.appendChild(title);
        if (item.author) {
            const meta = document.createElement('div');
            meta.className = 'card-meta';
            meta.textContent = item.author;
            overlay.appendChild(meta);
        }
        cover.appendChild(overlay);
        article.appendChild(cover);
        return article;
    }
}
