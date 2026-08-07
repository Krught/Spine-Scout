import { Controller } from '@hotwired/stimulus';

/**
 * Client-side filter for the Requests page over two independent dimensions:
 *   - status: all | available | downloaded | approved | pending | rejected
 *   - format: all | book | audiobook
 * Each row carries `data-status-key` and `data-format`; chips carry the matching
 * `data-status-key` / `data-format-key` (both computed server-side). A row is shown
 * only when it matches BOTH active chips (the two dimensions are ANDed) — kept in a
 * single controller so they share one source of truth over `row.hidden` instead of
 * fighting over it. Filtering is instant over the rows already in the DOM.
 *
 * Chip counts are cross-filtered: each status chip counts only the rows in the
 * selected format, and each format chip only the rows in the selected status, so a
 * chip always answers "how many rows would I show if you clicked me from here?".
 * The server renders the unfiltered numbers; we recount from the DOM on connect and
 * on every selection change. The list is paginated, so these are page counts (the
 * pager carries the true total) — recounting from the DOM keeps that consistent.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['row', 'chip', 'formatChip', 'empty'];
    static values = {
        current: { type: String, default: 'all' },
        currentFormat: { type: String, default: 'all' },
    };

    connect() {
        this.apply();
    }

    select(event) {
        this.currentValue = event.params.key || 'all';
    }

    selectFormat(event) {
        this.currentFormatValue = event.params.key || 'all';
    }

    currentValueChanged() {
        this.apply();
    }

    currentFormatValueChanged() {
        this.apply();
    }

    /**
     * Re-apply after request-actions mutates rows in place (status swap or
     * removal). If the active chip no longer matches any row at all, fall back to
     * "all" rather than leaving an unde-selectable empty view. (A chip that is
     * only empty *because of the other dimension* keeps its selection — it shows
     * a 0 and the empty-state message, which is honest and reversible.)
     */
    refresh() {
        const rows = this.rowTargets;
        if (this.currentValue !== 'all' && !rows.some((r) => r.dataset.statusKey === this.currentValue)) {
            this.currentValue = 'all';
        }
        if (this.currentFormatValue !== 'all' && !rows.some((r) => r.dataset.format === this.currentFormatValue)) {
            this.currentFormatValue = 'all';
        }
        this.apply();
    }

    /**
     * Recount every chip against the *other* dimension's current selection.
     * A chip is hidden only when it is empty across the whole page (mirroring the
     * server omitting empty categories at render); one that is merely empty under
     * the current cross-filter stays clickable and shows 0.
     */
    recount() {
        const rows = this.rowTargets;
        const key = this.currentValue;
        const fmt = this.currentFormatValue;
        const inFormat = fmt === 'all' ? rows : rows.filter((r) => r.dataset.format === fmt);
        const inStatus = key === 'all' ? rows : rows.filter((r) => r.dataset.statusKey === key);

        this.chipTargets.forEach((chip) => {
            const k = chip.dataset.statusKey || 'all';
            const match = (r) => k === 'all' || r.dataset.statusKey === k;
            this.updateChip(chip, k, inFormat.filter(match).length, rows.filter(match).length);
        });
        this.formatChipTargets.forEach((chip) => {
            const k = chip.dataset.formatKey || 'all';
            const match = (r) => k === 'all' || r.dataset.format === k;
            this.updateChip(chip, k, inStatus.filter(match).length, rows.filter(match).length);
        });
    }

    updateChip(chip, key, count, pageCount) {
        const badge = chip.querySelector('.requests-filter-count');
        if (badge) badge.textContent = count;
        chip.hidden = key !== 'all' && pageCount === 0;
        chip.classList.toggle('is-empty', count === 0);
    }

    apply() {
        this.recount();
        const key = this.currentValue;
        const fmt = this.currentFormatValue;
        let visible = 0;
        this.rowTargets.forEach((row) => {
            const statusMatch = key === 'all' || row.dataset.statusKey === key;
            const formatMatch = fmt === 'all' || row.dataset.format === fmt;
            const match = statusMatch && formatMatch;
            row.hidden = !match;
            if (match) visible += 1;
        });
        this.chipTargets.forEach((chip) => {
            chip.classList.toggle('is-active', (chip.dataset.statusKey || 'all') === key);
        });
        this.formatChipTargets.forEach((chip) => {
            chip.classList.toggle('is-active', (chip.dataset.formatKey || 'all') === fmt);
        });
        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = visible > 0;
        }
    }
}
