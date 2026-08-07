import { Controller } from '@hotwired/stimulus';

/**
 * Audiobook destination fieldset on the audiobook settings form.
 *
 * Disables the "Audiobook output folder" input while "deliver into the ebook
 * library folder" is ticked, so it's obvious the path is not used in that mode.
 * A disabled input doesn't submit; the server keeps the stored path when the
 * field is absent, so the value survives ticking and later unticking. The
 * initial disabled state is server-rendered; this controller only keeps it in
 * sync as the checkbox changes.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['toggle', 'folder'];

    connect() {
        this.sync();
    }

    sync() {
        if (!this.hasToggleTarget || !this.hasFolderTarget) {
            return;
        }
        const off = this.toggleTarget.checked;
        this.folderTarget.disabled = off;
        this.folderTarget.closest('.field')?.classList.toggle('is-disabled', off);
    }
}
