import { Controller } from '@hotwired/stimulus';

/*
 * Turns push notifications on or off for this device: subscribes with the
 * VAPID public key, then stores the subscription on the server.
 */
export default class extends Controller {
    static targets = ['bouton', 'etat'];
    static values = { cle: String, abonner: String, desabonner: String, jeton: String };

    async connect() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window) || '' === this.cleValue) {
            this.afficher('Non disponible sur cet appareil.', null);

            return;
        }
        this.enregistrement = await navigator.serviceWorker.ready;
        this.rafraichir(await this.enregistrement.pushManager.getSubscription());
    }

    async basculer() {
        this.boutonTarget.disabled = true;
        try {
            const abonnement = await this.enregistrement.pushManager.getSubscription();
            if (abonnement) {
                await this.envoyer(this.desabonnerValue, { endpoint: abonnement.endpoint });
                await abonnement.unsubscribe();
                this.rafraichir(null);
            } else if ('granted' === await Notification.requestPermission()) {
                const nouveau = await this.enregistrement.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: versOctets(this.cleValue) });
                await this.envoyer(this.abonnerValue, nouveau.toJSON());
                this.rafraichir(nouveau);
            } else {
                this.afficher('Notifications refusées dans les réglages du navigateur.', null);
            }
        } catch {
            this.afficher('L\'activation a échoué, réessayez.', 'Réessayer');
        } finally {
            this.boutonTarget.disabled = false;
        }
    }

    rafraichir(abonnement) {
        this.afficher(abonnement ? 'Activées sur cet appareil.' : 'Désactivées sur cet appareil.', abonnement ? 'Désactiver' : 'Activer');
    }

    afficher(etat, bouton) {
        this.etatTarget.textContent = etat;
        this.boutonTarget.hidden = null === bouton;
        if (bouton) {
            this.boutonTarget.textContent = bouton;
        }
    }

    async envoyer(url, corps) {
        const reponse = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.jetonValue }, body: JSON.stringify(corps) });
        if (!reponse.ok) {
            throw new Error(`HTTP ${reponse.status}`);
        }
    }
}

// base64url → bytes, as PushManager expects the key.
function versOctets(base64url) {
    const base64 = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');

    return Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
}
