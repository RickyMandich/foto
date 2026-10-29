import { clampInterval } from '../lib/nav.js';

export class Autoplay {
    constructor(root, { next, isActive }) {
        this.root = root;
        this.next = next;
        this.isActive = isActive;
        
        const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.enabled = !prefersReduced;
        this.paused = false;
        this.timer = null;
        this.touchTimeout = null;

        this.btnToggle = root.querySelector('#autoplay-toggle');
        this.inputInterval = root.querySelector('#autoplay-interval');

        this.init();
    }

    init() {
        if (this.btnToggle) {
            this.updateButton();
            this.btnToggle.addEventListener('click', () => {
                this.enabled = !this.enabled;
                this.updateButton();
                this.restart();
            });
        }

        if (this.inputInterval) {
            this.inputInterval.addEventListener('change', () => this.restart());
        }

        // Hover mouse
        const carousel = this.root.querySelector('#carousel-view');
        if (carousel) {
            carousel.addEventListener('pointerenter', (e) => {
                if (e.pointerType === 'mouse') {
                    this.paused = true;
                    this.clear();
                }
            });
            carousel.addEventListener('pointerleave', (e) => {
                if (e.pointerType === 'mouse') {
                    this.paused = false;
                    this.restart();
                }
            });

            // Tocco
            carousel.addEventListener('touchstart', () => {
                this.paused = true;
                this.clear();
                clearTimeout(this.touchTimeout);
            }, { passive: true });

            carousel.addEventListener('touchend', () => {
                clearTimeout(this.touchTimeout);
                this.touchTimeout = setTimeout(() => {
                    this.paused = false;
                    this.restart();
                }, 1500);
            }, { passive: true });
        }

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.clear();
            } else {
                this.restart();
            }
        });

        this.restart();
    }

    getInterval() {
        return clampInterval(this.inputInterval?.value ?? 4);
    }

    clear() {
        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
        }
    }

    restart() {
        this.clear();
        if (this.enabled && !this.paused && this.isActive() && !document.hidden) {
            this.timer = setTimeout(() => {
                this.next();
            }, this.getInterval() * 1000);
        }
    }

    updateButton() {
        if (!this.btnToggle) return;
        this.btnToggle.setAttribute('aria-pressed', this.enabled ? 'true' : 'false');
        this.btnToggle.classList.toggle('text-sky-400', this.enabled);
    }
}

