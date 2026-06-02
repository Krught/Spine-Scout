import { Controller } from '@hotwired/stimulus';

/**
 * Client-side status filter for the Requests page. The chips and each row carry a
 * `data-status-key` (the display status: all | available | downloaded | approved |
 * pending | rejected — computed server-side in RequestsController). Selecting a
 * chip shows only matching rows; "all" shows everything. Filtering is instant and
 * needs no reload since the page already renders every request.
 */
export default class extends Controller {
    static targets = ['row', 'chip', 'empty'];
    static values = { current: { type: String, default: 'all' } };

    select(event) {
        this.currentValue = event.params.key || 'all';
    }

    currentValueChanged() {
        const key = this.currentValue;
        let visible = 0;
        this.rowTargets.forEach((row) => {
            const match = key === 'all' || row.dataset.statusKey === key;
            row.hidden = !match;
            if (match) visible += 1;
        });
        this.chipTargets.forEach((chip) => {
            chip.classList.toggle('is-active', (chip.dataset.statusKey || 'all') === key);
        });
        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = visible > 0;
        }
    }
}
