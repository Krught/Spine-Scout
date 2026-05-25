import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['track', 'left', 'right'];

    connect() {
        this.updateButtons = this.updateButtons.bind(this);
        this.onPointerDown = this.onPointerDown.bind(this);
        this.onPointerMove = this.onPointerMove.bind(this);
        this.onPointerUp = this.onPointerUp.bind(this);
        this.onClickCapture = this.onClickCapture.bind(this);
        this.onDragStart = (e) => e.preventDefault();

        this.trackTarget.addEventListener('scroll', this.updateButtons, { passive: true });
        this.trackTarget.addEventListener('pointerdown', this.onPointerDown);
        this.trackTarget.addEventListener('click', this.onClickCapture, true);
        this.trackTarget.addEventListener('dragstart', this.onDragStart);
        window.addEventListener('resize', this.updateButtons);
        this.resizeObserver = new ResizeObserver(this.updateButtons);
        this.resizeObserver.observe(this.trackTarget);
        for (const img of this.trackTarget.querySelectorAll('img')) {
            if (!img.complete) img.addEventListener('load', this.updateButtons, { once: true });
        }
        this.updateButtons();
    }

    disconnect() {
        this.trackTarget.removeEventListener('scroll', this.updateButtons);
        this.trackTarget.removeEventListener('pointerdown', this.onPointerDown);
        this.trackTarget.removeEventListener('click', this.onClickCapture, true);
        this.trackTarget.removeEventListener('dragstart', this.onDragStart);
        window.removeEventListener('resize', this.updateButtons);
        window.removeEventListener('pointermove', this.onPointerMove);
        window.removeEventListener('pointerup', this.onPointerUp);
        window.removeEventListener('pointercancel', this.onPointerUp);
        this.resizeObserver?.disconnect();
    }

    scroll(event) {
        const direction = Number(event.currentTarget.dataset.scroll || 1);
        const track = this.trackTarget;
        const step = Math.max(track.clientWidth * 0.85, 320);
        track.scrollBy({ left: step * direction, behavior: 'smooth' });
    }

    onPointerDown(event) {
        // Native touch scroll handles touch; only hijack mouse/pen drag.
        if (event.pointerType === 'touch') return;
        if (event.button !== 0) return;

        this.dragging = true;
        this.dragMoved = false;
        this.startX = event.clientX;
        this.startScrollLeft = this.trackTarget.scrollLeft;

        this.prevSnap = this.trackTarget.style.scrollSnapType;
        this.prevBehavior = this.trackTarget.style.scrollBehavior;
        this.trackTarget.style.scrollSnapType = 'none';
        this.trackTarget.style.scrollBehavior = 'auto';
        this.trackTarget.style.cursor = 'grabbing';

        window.addEventListener('pointermove', this.onPointerMove);
        window.addEventListener('pointerup', this.onPointerUp);
        window.addEventListener('pointercancel', this.onPointerUp);
    }

    onPointerMove(event) {
        if (!this.dragging) return;
        const dx = event.clientX - this.startX;
        if (Math.abs(dx) > 4) this.dragMoved = true;
        this.trackTarget.scrollLeft = this.startScrollLeft - dx;
    }

    onPointerUp() {
        if (!this.dragging) return;
        this.dragging = false;
        this.trackTarget.style.scrollSnapType = this.prevSnap || '';
        this.trackTarget.style.scrollBehavior = this.prevBehavior || '';
        this.trackTarget.style.cursor = '';
        window.removeEventListener('pointermove', this.onPointerMove);
        window.removeEventListener('pointerup', this.onPointerUp);
        window.removeEventListener('pointercancel', this.onPointerUp);
    }

    onClickCapture(event) {
        if (this.dragMoved) {
            event.preventDefault();
            event.stopPropagation();
            this.dragMoved = false;
        }
    }

    updateButtons() {
        const track = this.trackTarget;
        const tolerance = 8;
        const maxScroll = track.scrollWidth - track.clientWidth;
        const atStart = track.scrollLeft <= tolerance;
        const atEnd = track.scrollLeft >= maxScroll - tolerance;
        if (this.hasLeftTarget) this.leftTarget.hidden = atStart;
        if (this.hasRightTarget) this.rightTarget.hidden = atEnd;
    }
}
