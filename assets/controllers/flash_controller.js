import { Controller } from '@hotwired/stimulus';

/*
 * Closes a flash message on click, or automatically after `delay` ms when set.
 */
export default class extends Controller {
    static values = { delay: Number };

    connect() {
        if (this.delayValue > 0) {
            this.timeout = setTimeout(() => this.close(), this.delayValue);
        }
    }

    disconnect() {
        clearTimeout(this.timeout);
    }

    close() {
        clearTimeout(this.timeout);
        this.element.classList.add('opacity-0');
        setTimeout(() => this.element.remove(), 300);
    }
}
