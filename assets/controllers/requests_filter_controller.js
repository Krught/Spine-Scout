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
