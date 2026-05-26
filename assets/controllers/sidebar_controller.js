import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.onKeydown = this.onKeydown.bind(this);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
    }

    toggle(event) {
        if (event) event.preventDefault();
        this.element.classList.toggle('sidebar-open');
    }

    open() {
        this.element.classList.add('sidebar-open');
    }

    close() {
        this.element.classList.remove('sidebar-open');
    }

    onKeydown(event) {
        if (event.key === 'Escape' && this.element.classList.contains('sidebar-open')) {
            this.close();
        }
    }
}
