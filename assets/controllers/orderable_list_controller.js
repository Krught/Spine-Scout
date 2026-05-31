import { Controller } from '@hotwired/stimulus';

/**
 * A drag-to-reorder list with add / remove / move buttons.
 *
 * Handles two item shapes:
 *   - plain strings (formatPriority, sourcePriority, tieBreakers, languagePriority)
 *   - { id: string, enabled: bool } rows (indexerPriority — mode-value="rows")
 *
 * Serializes the current order into the hidden target as JSON on every change so
 * a vanilla form POST picks it up.
 */
export default class extends Controller {
    static targets = ['list', 'addInput', 'hidden'];
    static values = {
        initial: { type: Array, default: [] },
        suggestions: { type: Array, default: [] },
        labels: { type: Object, default: {} }, // id -> display label (rows mode)
        inputName: String,
        fixedOptions: { type: Boolean, default: false }, // hide remove (set is fixed)
        mode: { type: String, default: 'string' }, // 'string' | 'rows'
    };

    connect() {
        this.items = this.normalizeItems(this.initialValue);
        this.render();
        this.sync();
    }

    add(event) {
        if (event) event.preventDefault();
        if (!this.hasAddInputTarget) return;
        const raw = (this.addInputTarget.value || '').trim();
        if (raw === '') return;

        if (this.modeValue === 'rows') {
            if (this.items.some((it) => it.id === raw)) return; // dedupe
            this.items.push({ id: raw, enabled: true });
        } else {
            const lower = raw.toLowerCase();
            if (this.items.some((it) => it.toLowerCase() === lower)) return;
            this.items.push(raw);
        }
        this.addInputTarget.value = '';
        this.render();
        this.sync();
    }

    remove(event) {
        const idx = Number(event.currentTarget.dataset.index);
        if (!Number.isInteger(idx)) return;
        this.items.splice(idx, 1);
        this.render();
        this.sync();
    }

    moveUp(event) {
        const idx = Number(event.currentTarget.dataset.index);
        if (!Number.isInteger(idx) || idx <= 0) return;
        [this.items[idx - 1], this.items[idx]] = [this.items[idx], this.items[idx - 1]];
        this.render();
        this.sync();
    }

    moveDown(event) {
        const idx = Number(event.currentTarget.dataset.index);
        if (!Number.isInteger(idx) || idx >= this.items.length - 1) return;
        [this.items[idx + 1], this.items[idx]] = [this.items[idx], this.items[idx + 1]];
        this.render();
        this.sync();
    }

    toggleEnabled(event) {
        const idx = Number(event.currentTarget.dataset.index);
        if (!Number.isInteger(idx) || this.modeValue !== 'rows') return;
        this.items[idx] = { ...this.items[idx], enabled: event.currentTarget.checked };
        this.sync();
    }

    // ---- drag handlers ----------------------------------------------------

    onDragStart(event) {
        this.dragIndex = Number(event.currentTarget.dataset.index);
        event.dataTransfer.effectAllowed = 'move';
        event.currentTarget.classList.add('orderable-list__item--dragging');
    }

    onDragEnd(event) {
        event.currentTarget.classList.remove('orderable-list__item--dragging');
        this.dragIndex = null;
        this.listTarget.querySelectorAll('.orderable-list__item--over').forEach((el) => {
            el.classList.remove('orderable-list__item--over');
        });
    }

    onDragOver(event) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        event.currentTarget.classList.add('orderable-list__item--over');
    }

    onDragLeave(event) {
        event.currentTarget.classList.remove('orderable-list__item--over');
    }

    onDrop(event) {
        event.preventDefault();
        event.currentTarget.classList.remove('orderable-list__item--over');
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

    // ---- internals --------------------------------------------------------

    normalizeItems(raw) {
        if (!Array.isArray(raw)) return [];
        if (this.modeValue === 'rows') {
            return raw
                .filter((it) => it && typeof it === 'object' && typeof it.id === 'string' && it.id !== '')
                .map((it) => ({ id: it.id, enabled: it.enabled !== false }));
        }
        return raw.filter((it) => typeof it === 'string' && it !== '');
    }

    render() {
        const list = this.listTarget;
        list.replaceChildren();
        this.items.forEach((item, idx) => list.appendChild(this.renderItem(item, idx)));
    }

    renderItem(item, idx) {
        const li = document.createElement('li');
        li.className = 'orderable-list__item';
        li.dataset.index = String(idx);
        li.draggable = true;
        li.addEventListener('dragstart', (e) => this.onDragStart(e));
        li.addEventListener('dragend', (e) => this.onDragEnd(e));
        li.addEventListener('dragover', (e) => this.onDragOver(e));
        li.addEventListener('dragleave', (e) => this.onDragLeave(e));
        li.addEventListener('drop', (e) => this.onDrop(e));

        const handle = document.createElement('span');
        handle.className = 'orderable-list__handle';
        handle.setAttribute('aria-hidden', 'true');
        handle.textContent = '⋮⋮';
        li.appendChild(handle);

        const label = document.createElement('span');
        label.className = 'orderable-list__label';
        const key = this.modeValue === 'rows' ? item.id : item;
        label.textContent = this.labelsValue[key] || key;
        li.appendChild(label);

        if (this.modeValue === 'rows') {
            const toggleLabel = document.createElement('label');
            toggleLabel.className = 'orderable-list__enabled';
            const toggle = document.createElement('input');
            toggle.type = 'checkbox';
            toggle.checked = !!item.enabled;
            toggle.dataset.index = String(idx);
            toggle.dataset.action = 'change->orderable-list#toggleEnabled';
            toggleLabel.appendChild(toggle);
            toggleLabel.append(' enabled');
            li.appendChild(toggleLabel);
        }

        const actions = document.createElement('span');
        actions.className = 'orderable-list__actions';

        const upBtn = this.makeButton('▲', 'click->orderable-list#moveUp', idx, 'Move up');
        const downBtn = this.makeButton('▼', 'click->orderable-list#moveDown', idx, 'Move down');
        actions.append(upBtn, downBtn);

        // A fixed set (e.g. the known mirror sources) can be reordered and
        // disabled, but not removed — removal is meaningless there.
        if (!this.fixedOptionsValue) {
            actions.append(this.makeButton('✕', 'click->orderable-list#remove', idx, 'Remove'));
        }

        li.appendChild(actions);

        return li;
    }

    makeButton(text, action, idx, aria) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'orderable-list__btn';
        btn.textContent = text;
        btn.dataset.action = action;
        btn.dataset.index = String(idx);
        btn.setAttribute('aria-label', aria);
        return btn;
    }

    sync() {
        if (!this.hasHiddenTarget) return;
        this.hiddenTarget.value = JSON.stringify(this.items);
    }
}
