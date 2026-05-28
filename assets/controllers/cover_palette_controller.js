import { Controller } from '@hotwired/stimulus';

// Extracts two dominant colors from a cover image inside the host element and
// sets --row-c1 / --row-c2 CSS custom properties on the host so the row's
// ::before gradient can paint a tinted overlay.
//
// Same-origin images only — canvas pixel reads on cross-origin images without
// proper CORS headers will throw a SecurityError. Covers live at /cache/cover/*
// which is same-origin, so this is fine here.
export default class extends Controller {
    static values = {
        target: { type: String, default: 'img' }, // CSS selector for the source image
    };

    connect() {
        const img = this.element.querySelector(this.targetValue);
        if (!img) return;

        if (img.complete && img.naturalWidth > 0) {
            this.apply(img);
        } else {
            img.addEventListener('load', () => this.apply(img), { once: true });
        }
    }

    apply(img) {
        const colors = this.extract(img);
        if (!colors) return;
        this.element.style.setProperty('--row-c1', colors[0]);
        this.element.style.setProperty('--row-c2', colors[1]);
        this.element.classList.add('has-palette');
    }

    extract(img) {
        const TW = 32;
        const TH = Math.max(1, Math.round(img.naturalHeight * TW / img.naturalWidth));
        const canvas = document.createElement('canvas');
        canvas.width = TW;
        canvas.height = TH;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        if (!ctx) return null;
        try {
            ctx.drawImage(img, 0, 0, TW, TH);
        } catch (_) {
            return null;
        }

        let data;
        try {
            data = ctx.getImageData(0, 0, TW, TH).data;
        } catch (_) {
            return null; // tainted canvas
        }

        // Quantize each channel to 4 bits → 4096 buckets, accumulate count + summed RGB.
        const buckets = new Map();
        for (let i = 0; i < data.length; i += 4) {
            const a = data[i + 3];
            if (a < 200) continue;
            const r = data[i];
            const g = data[i + 1];
            const b = data[i + 2];
            // Skip near-black and near-white so we get a vivid pull, not just card backgrounds.
            const maxC = Math.max(r, g, b);
            const minC = Math.min(r, g, b);
            if (maxC < 24 || minC > 235) continue;
            const key = ((r >> 4) << 8) | ((g >> 4) << 4) | (b >> 4);
            const bucket = buckets.get(key);
            if (bucket) {
                bucket.n++;
                bucket.r += r; bucket.g += g; bucket.b += b;
            } else {
                buckets.set(key, { n: 1, r, g, b });
            }
        }

        if (buckets.size === 0) return null;

        const sorted = [...buckets.values()].sort((a, b) => b.n - a.n);
        const avg = (bk) => [Math.round(bk.r / bk.n), Math.round(bk.g / bk.n), Math.round(bk.b / bk.n)];

        const first = avg(sorted[0]);
        let second = null;
        for (let i = 1; i < sorted.length; i++) {
            const c = avg(sorted[i]);
            const dr = c[0] - first[0], dg = c[1] - first[1], db = c[2] - first[2];
            if (dr * dr + dg * dg + db * db > 4000) {
                second = c;
                break;
            }
        }
        if (!second) {
            // Single dominant color: derive a darker companion.
            second = [Math.round(first[0] * 0.45), Math.round(first[1] * 0.45), Math.round(first[2] * 0.45)];
        }

        return [hex(first), hex(second)];
    }
}

function hex([r, g, b]) {
    return '#' + [r, g, b].map((v) => v.toString(16).padStart(2, '0')).join('');
}
