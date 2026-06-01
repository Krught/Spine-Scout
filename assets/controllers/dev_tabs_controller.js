import { Controller } from '@hotwired/stimulus';

/**
 * Simple sub-tab switcher for the dev Downloads page: toggles which pane is shown
 * (download jobs vs activity log). The active pane is driven by a class on the
 * controller's root element (`dev-tabs--<pane>`) and matching CSS, so it survives
 * the activity-poll controller swapping the polled content every few seconds —
 * the panes inside are re-rendered but the root class persists.
 */
export default class extends Controller {
    static targets = ['tab'];
    static values = { active: { type: String, default: 'jobs' } };

    connect() {
        this.render();
    }

    show(event) {
        event.preventDefault();
        this.activeValue = event.currentTarget.dataset.pane;
    }

    activeValueChanged() {
        this.render();
    }

    render() {
        this.element.classList.remove('dev-tabs--jobs', 'dev-tabs--log');
        this.element.classList.add('dev-tabs--' + this.activeValue);
        this.tabTargets.forEach((tab) => {
            const on = tab.dataset.pane === this.activeValue;
            tab.classList.toggle('is-active', on);
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
        });
    }
}
