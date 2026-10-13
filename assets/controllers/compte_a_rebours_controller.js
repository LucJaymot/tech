import { Controller } from '@hotwired/stimulus';
import { decalage, texte } from '../feuille_de_route/compte_a_rebours.js';

/*
 * Countdown to a soirée's opening, then closing, on the server's clock.
 * Values are Unix timestamps in seconds, `maintenant` the server's time;
 * public/sw.js marks the copies it serves offline with body[data-hors-ligne].
 */
export default class extends Controller {
    static values = { maintenant: Number, ouverture: Number, fermeture: Number };

    connect() {
        this.decalage = decalage(this.maintenantValue * 1000, Date.now(), !document.body.hasAttribute('data-hors-ligne'), window.localStorage);
        this.afficher();
        this.minuteur = setInterval(() => this.afficher(), 1000);
    }

    disconnect() {
        clearInterval(this.minuteur);
    }

    afficher() {
        this.element.textContent = texte(Date.now() + this.decalage, this.ouvertureValue * 1000, this.fermetureValue * 1000);
    }
}
