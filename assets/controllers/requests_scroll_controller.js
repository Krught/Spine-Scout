import { Controller } from '@hotwired/stimulus';

/**
 * Endless scroll for the Requests page. The server still pages the query
 * (PER_PAGE rows at a time); this controller watches a sentinel below the list
 * and, when it nears the viewport, fetches the next page as JSON (the index
 * route answers row HTML when asked for application/json) and appends the rows.
 * Appended rows carry the same data-* wiring as the server-rendered ones, so
 * the filter chips, modals and row actions work on them unchanged; the filter
 * controller is refreshed after each append so an active chip hides
 * non-matching new rows and the counts stay honest.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['list', 'sentinel'];
    static values = {
        url: String,
        page: Number,
        pages: Number,
    };

    connect() {
        this.loading = false;
        if (!this.hasSentinelTarget || this.pageValue >= this.pagesValue) {
            this.finish();
            return;
        }
        this.observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((e) => e.isIntersecting)) this.loadMore();
            },
            // Start fetching a screen early so scrolling rarely hits the sentinel.
            { rootMargin: '600px 0px' },
        );
        this.observer.observe(this.sentinelTarget);
    }

    disconnect() {
        this.observer?.disconnect();
        this.observer = null;
    }

    async loadMore() {
        if (this.loading || this.pageValue >= this.pagesValue) return;
        this.loading = true;
        this.sentinelTarget.classList.add('is-loading');
        try {
            const url = new URL(this.urlValue, window.location.origin);
            url.searchParams.set('page', String(this.pageValue + 1));
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) return; // Leave the sentinel; the next intersection retries.
            const data = await res.json();
            if (!data?.ok) return;

            const tpl = document.createElement('template');
            tpl.innerHTML = data.rows;
            this.listTarget.append(...tpl.content.children);

            this.pageValue = data.page;
            this.pagesValue = data.pages;
            this.refreshFilters();

            if (this.pageValue >= this.pagesValue) this.finish();
        } catch (e) {
            // Network hiccup — keep the sentinel so scrolling retries.
        } finally {
            this.loading = false;
            if (this.hasSentinelTarget) this.sentinelTarget.classList.remove('is-loading');
        }
    }

    finish() {
        this.observer?.disconnect();
        this.observer = null;
        if (this.hasSentinelTarget) this.sentinelTarget.remove();
    }

    refreshFilters() {
        this.application
            .getControllerForElementAndIdentifier(this.element, 'requests-filter')
            ?.refresh();
    }
}
