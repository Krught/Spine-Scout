import { Controller } from '@hotwired/stimulus';

/**
 * Drives the shared create/edit user modal on the /users page. Edit and create
 * reuse one form; this controller swaps the form action, CSRF token, field
 * values, and the master-lock state based on the clicked button's params.
 */
export default class extends Controller {
    static targets = [
        'modal', 'form', 'token', 'title', 'submit',
        'username', 'password', 'passwordConfirm', 'passwordHelp',
        'manageSettings', 'manageUsers', 'autoApprove', 'masterNote',
    ];

    openCreate(event) {
        const { createUrl, createToken } = event.params;
        this.formTarget.action = createUrl;
        this.tokenTarget.value = createToken;
        this.titleTarget.textContent = 'Add user';
        this.submitTarget.textContent = 'Create user';

        this.usernameTarget.value = '';
        this.clearPasswords();
        this.passwordTarget.required = true;
        this.passwordConfirmTarget.required = true;
        this.passwordHelpTarget.textContent = 'At least 8 characters.';

        this.setCapabilities(false, false, false);
        this.setMasterLocked(false);
        this.open();
    }

    openEdit(event) {
        const p = event.params;
        this.formTarget.action = p.updateUrl;
        this.tokenTarget.value = p.updateToken;
        this.titleTarget.textContent = 'Edit user';
        this.submitTarget.textContent = 'Save changes';

        this.usernameTarget.value = p.username || '';
        this.clearPasswords();
        this.passwordTarget.required = false;
        this.passwordConfirmTarget.required = false;
        this.passwordHelpTarget.textContent = 'Leave blank to keep the current password.';

        this.setCapabilities(p.manageSettings === '1', p.manageUsers === '1', p.autoApprove === '1');
        this.setMasterLocked(p.master === '1');
        this.open();
    }

    clearPasswords() {
        this.passwordTarget.value = '';
        this.passwordConfirmTarget.value = '';
    }

    setCapabilities(settings, users, autoApprove) {
        this.manageSettingsTarget.checked = settings;
        this.manageUsersTarget.checked = users;
        this.autoApproveTarget.checked = autoApprove;
    }

    // The master always has full access — show it checked and locked, and note why.
    setMasterLocked(locked) {
        if (locked) {
            this.manageSettingsTarget.checked = true;
            this.manageUsersTarget.checked = true;
        }
        this.manageSettingsTarget.disabled = locked;
        this.manageUsersTarget.disabled = locked;
        this.masterNoteTarget.hidden = !locked;
    }

    open() {
        this.modalTarget.hidden = false;
        document.body.classList.add('book-modal-open');
        this.usernameTarget.focus();
    }

    close() {
        this.modalTarget.hidden = true;
        if (document.querySelector('.book-modal:not([hidden])') === null) {
            document.body.classList.remove('book-modal-open');
        }
    }

    backdropClick(event) {
        if (event.target === this.modalTarget) {
            this.close();
        }
    }
}
