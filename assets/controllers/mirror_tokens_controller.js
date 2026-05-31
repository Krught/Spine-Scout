import { Controller } from '@hotwired/stimulus';

/**
 * Token/chip input for mirror URLs.
 *
 * Type a URL and press Tab, Enter, or comma to commit it as a chip. Remove a
 * chip via its × button, or by pressing Backspace in the empty field (removes
 * the last chip). Pasting several URLs separated by whitespace/commas commits
 * them all.
 *
 * Chips are drag-reorderable: drag a chip left/right to promote/demote it. Chip
 * order IS priority order — the first (top-left) chip is highest priority. This
 * serializes to a newline-joined hidden field, in order, so the existing
 * server-side blob normalization (MirrorListNormalizer::normalizeBlob) picks it
 * up unchanged (it preserves order).
 */
export default class extends Controller {
    static targets = ['chips', 'input', 'hidden'];
    static values = { initial: { type: Array, default: [] } };

    connect() {
        this.items = (this.initialValue || []).filter((u) => typeof u === 'string' && u.trim() !== '');
        this.render();
        this.sync();
    }

    onKeydown(event) {
        const key = event.key;

        if (key === 'Tab' || key === 'Enter' || key === ',') {
            const raw = this.inputTarget.value.trim();
            if (raw !== '') {
                // Keep focus in the field so several URLs can be added in a row,
                // and stop Enter from submitting / comma from being typed.
                event.preventDefault();
                this.commit(raw);
            }
            // Empty + Tab: let focus move to the next field naturally.
            return;
        }

        if (key === 'Backspace' && this.inputTarget.value === '' && this.items.length > 0) {
            event.preventDefault();
            this.items.pop();
            this.render();
            this.sync();
        }
    }

    onBlur() {
        const raw = this.inputTarget.value.trim();
        if (raw !== '') this.commit(raw);
    }

    focusInput(event) {
        // Ignore clicks on a chip's remove button.
        if (event && event.target.closest('.token-chip__remove')) return;
        this.inputTarget.focus();
    }

    remove(event) {
        const idx = Number(event.currentTarget.dataset.index);
        if (!Number.isInteger(idx)) return;
        this.items.splice(idx, 1);
        this.render();
        this.sync();
    }

    // ---- internals --------------------------------------------------------

    commit(raw) {
        raw.split(/[\s,]+/).forEach((part) => {
            const value = part.trim();
            if (value !== '' && !this.items.includes(value)) this.items.push(value);
        });
        this.inputTarget.value = '';
        this.render();
        this.sync();
    }

    render() {
        const box = this.chipsTarget;
        box.replaceChildren();
        this.items.forEach((url, idx) => box.appendChild(this.renderChip(url, idx)));
    }

    renderChip(url, idx) {
        const chip = document.createElement('span');
        chip.className = 'token-chip';
        chip.draggable = true;
        chip.dataset.index = String(idx);
        chip.addEventListener('dragstart', (e) => this.onDragStart(e));
        chip.addEventListener('dragend', (e) => this.onDragEnd(e));
        chip.addEventListener('dragover', (e) => this.onDragOver(e));
        chip.addEventListener('dragleave', (e) => this.onDragLeave(e));
        chip.addEventListener('drop', (e) => this.onDrop(e));

        const label = document.createElement('span');
        label.className = 'token-chip__label';
        label.textContent = url;
        label.title = url;
        chip.appendChild(label);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'token-chip__remove';
        remove.textContent = '×';
        remove.setAttribute('aria-label', `Remove ${url}`);
        remove.dataset.index = String(idx);
        remove.dataset.action = 'click->mirror-tokens#remove';
        // Don't let the button start a chip drag.
        remove.draggable = false;
        chip.appendChild(remove);

        return chip;
    }

    // ---- drag-to-reorder (left = higher priority) -------------------------

    onDragStart(event) {
        this.dragIndex = Number(event.currentTarget.dataset.index);
        event.dataTransfer.effectAllowed = 'move';
        event.currentTarget.classList.add('token-chip--dragging');
    }

    onDragEnd(event) {
        event.currentTarget.classList.remove('token-chip--dragging');
        this.dragIndex = null;
        this.chipsTarget.querySelectorAll('.token-chip--over').forEach((el) => {
            el.classList.remove('token-chip--over');
        });
    }

    onDragOver(event) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        event.currentTarget.classList.add('token-chip--over');
    }

    onDragLeave(event) {
        event.currentTarget.classList.remove('token-chip--over');
    }

    onDrop(event) {
        event.preventDefault();
        event.currentTarget.classList.remove('token-chip--over');
        const target = Number(event.currentTarget.dataset.index);
        if (!Number.isInteger(this.dragIndex) || !Number.isInteger(target) || this.dragIndex === target) {
            return;
        }
        const [moved] = this.items.splice(this.dragIndex, 1);
        this.items.splice(target, 0, moved);
        this.dragIndex = null;
        this.render();
        this.sync();
    }

    sync() {
        if (this.hasHiddenTarget) this.hiddenTarget.value = this.items.join('\n');
    }
}
