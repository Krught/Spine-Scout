import { Controller } from '@hotwired/stimulus';

/**
 * Cloudflare-bypass mode selector on the direct-download settings form.
 *
 * Shows the FlareSolverr address field only when the "external" mode is
 * selected; hides it for "none". Pure progressive enhancement — the field still
 * submits its value if JS is off (the server defaults it).
 */
export default class extends Controller {
    static targets = ['mode', 'flaresolverr'];

    connect() {
        this.toggle();
    }

    toggle() {
        if (!this.hasFlaresolverrTarget || !this.hasModeTarget) {
            return;
        }
        this.flaresolverrTarget.hidden = this.modeTarget.value !== 'external';
    }
}
