import { Controller } from '@hotwired/stimulus';

/*
 * Submits the form as soon as a field changes. The fallback submit button
 * (data-auto-submit-target="button") is only needed without JavaScript.
 */
export default class extends Controller {
    static targets = ['button'];

    buttonTargetConnected(button) {
        button.hidden = true;
    }

    submit() {
        this.element.requestSubmit();
    }
}
