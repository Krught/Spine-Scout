import { Controller } from '@hotwired/stimulus';

/**
 * Client-side filter for the Requests page over two independent dimensions:
 *   - status: all | available | downloaded | approved | pending | rejected
 *   - format: all | book | audiobook
 * Each row carries `data-status-key` and `data-format`; chips carry the matching
 * `data-status-key` / `data-format-key` (both computed server-side). A row is shown
 * only when it matches BOTH active chips (the two dimensions are ANDed) — kept in a
 * single controller so they share one source of truth over `row.hidden` instead of
 * fighting over it. Filtering is instant; the page already renders every request.
 */
export default class extends Controller {
    static targets = ['row', 'chip', 'formatChip', 'empty'];
    static values = {
        current: { type: String, default: 'all' },
        currentFormat: { type: String, default: 'all' },
    };

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
     * Recount the chips and re-apply the filters after request-actions mutates
     * rows in place (status swap or removal). Chips whose count drops to zero
     * are hidden — mirroring the server omitting empty categories at render —
     * and if the active chip vanishes, fall back to "all" rather than leaving
     * an unde-selectable empty view.
     */
    refresh() {
        const rows = this.rowTargets;
        this.chipTargets.forEach((chip) => {
            const key = chip.dataset.statusKey || 'all';
            const count = key === 'all' ? rows.length : rows.filter((r) => r.dataset.statusKey === key).length;
            this.updateChip(chip, key, count);
        });
        this.formatChipTargets.forEach((chip) => {
            const key = chip.dataset.formatKey || 'all';
            const count = key === 'all' ? rows.length : rows.filter((r) => r.dataset.format === key).length;
            this.updateChip(chip, key, count);
        });
        if (this.currentValue !== 'all'
            && !this.chipTargets.some((c) => !c.hidden && c.dataset.statusKey === this.currentValue)) {
            this.currentValue = 'all';
        }
        if (this.currentFormatValue !== 'all'
            && !this.formatChipTargets.some((c) => !c.hidden && c.dataset.formatKey === this.currentFormatValue)) {
            this.currentFormatValue = 'all';
        }
        this.apply();
    }

    updateChip(chip, key, count) {
        const badge = chip.querySelector('.requests-filter-count');
        if (badge) badge.textContent = count;
        chip.hidden = key !== 'all' && count === 0;
    }

    apply() {
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
