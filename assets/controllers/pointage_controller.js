import { Controller } from '@hotwired/stimulus';
import { appliquerResultats, creerPointage, filtrer, fusionner, heure, trier } from '../pointage/logique.js';

/*
 * Door check-in that keeps working offline.
 *
 * The guest list is kept in IndexedDB; check-ins are saved there first, in a
 * queue, then sent to the server whenever the network is back. Each check-in
 * has its own id, so sending it twice is harmless.
 */
const BASE = 'pointage';
const SYNCHRO_PERIODE_MS = 15000;

export default class extends Controller {
    static targets = ['recherche', 'liste', 'etat', 'compteur'];
    static values = { soiree: Number, listeUrl: String, synchroUrl: String, csrf: String };

    async connect() {
        this.invites = [];
        this.enLigne = navigator.onLine;
        this.db = await ouvrirBase();

        const cache = await lire(this.db, 'listes', this.soireeValue);
        await this.afficher(cache?.invites ?? []);

        this.surRetourReseau = () => this.synchroniser();
        window.addEventListener('online', this.surRetourReseau);
        this.minuterie = setInterval(() => this.synchroniser(), SYNCHRO_PERIODE_MS);
        await this.synchroniser();
    }

    disconnect() {
        window.removeEventListener('online', this.surRetourReseau);
        clearInterval(this.minuterie);
        this.db?.close();
    }

    rechercher() {
        this.rendre();
    }

    async pointer(event) {
        const invite = this.invites.find((i) => i.id === event.params.invite);
        if (!invite || invite.pointe_at) {
            return;
        }

        await ecrire(this.db, 'attente', { ...creerPointage(invite), soiree: this.soireeValue });
        await this.afficher(this.serveur);
        this.rechercheTarget.select();
        this.synchroniser();
    }

    /**
     * Sends the queue, then reloads the list. Any network failure leaves the
     * queue untouched for the next attempt.
     */
    async synchroniser() {
        if (this.enCours) {
            return;
        }
        this.enCours = true;
        try {
            const attente = await this.attente();
            if (attente.length > 0) {
                const reponse = await fetch(this.synchroUrlValue, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': this.csrfValue },
                    body: JSON.stringify({ pointages: attente.map(({ id, invite, at }) => ({ id, invite, at })) }),
                });
                const { resultats } = await lireJson(reponse);
                const { invites, envoyes, conflits } = appliquerResultats(this.serveur, resultats);
                for (const id of envoyes) {
                    await supprimer(this.db, 'attente', id);
                }
                this.serveur = invites;
                this.conflits = conflits.length;
            }

            const { invites } = await lireJson(await fetch(this.listeUrlValue, { credentials: 'same-origin', headers: { Accept: 'application/json' } }));
            await ecrire(this.db, 'listes', { soiree: this.soireeValue, invites, recuLe: new Date().toISOString() });
            this.enLigne = true;
            await this.afficher(invites);
        } catch {
            this.enLigne = false;
            await this.afficher(this.serveur);
        } finally {
            this.enCours = false;
        }
    }

    async attente() {
        return (await toutLire(this.db, 'attente')).filter((p) => p.soiree === this.soireeValue);
    }

    async afficher(serveur) {
        this.serveur = serveur;
        this.attenteCourante = await this.attente();
        this.invites = fusionner(serveur, this.attenteCourante);
        this.rendre();
    }

    rendre() {
        const visibles = trier(filtrer(this.invites, this.rechercheTarget.value));
        this.listeTarget.replaceChildren(...visibles.map((invite) => this.ligne(invite)));

        const arrives = this.invites.filter((i) => i.pointe_at);
        const personnes = (liste) => liste.reduce((total, i) => total + 1 + i.accompagnants, 0);
        this.compteurTarget.textContent = `${personnes(arrives)} / ${personnes(this.invites)} personnes arrivées`;

        const enAttente = this.attenteCourante.length;
        const reseau = this.enLigne ? 'En ligne' : 'Hors ligne';
        const pluriel = enAttente > 1 ? 's' : '';
        const file = enAttente ? ` · ${enAttente} pointage${pluriel} ${this.enLigne ? 'à envoyer' : `en attente, envoyé${pluriel} au retour du réseau`}` : ' · tout est envoyé';
        const conflits = this.conflits ? ` · ${this.conflits} déjà pointé${this.conflits > 1 ? 's' : ''} sur un autre appareil` : '';
        this.etatTarget.textContent = reseau + file + conflits;
        this.etatTarget.dataset.etat = this.enLigne ? 'en-ligne' : 'hors-ligne';
    }

    ligne(invite) {
        const li = document.createElement('li');
        const bouton = document.createElement('button');
        bouton.type = 'button';
        bouton.dataset.action = 'pointage#pointer';
        bouton.dataset.pointageInviteParam = invite.id;
        bouton.dataset.invite = invite.id;
        bouton.disabled = Boolean(invite.pointe_at);
        bouton.className = 'flex w-full items-center justify-between gap-4 rounded-xl border-2 px-5 py-5 text-left focus-visible:outline-4 focus-visible:outline-offset-2 focus-visible:outline-yellow-300 '
            + (invite.pointe_at ? 'border-neutral-700 bg-neutral-900 text-neutral-400' : 'border-white bg-black text-white active:bg-white active:text-black');

        const nom = document.createElement('span');
        nom.className = 'min-w-0';
        const titre = document.createElement('span');
        titre.className = 'block break-words text-3xl font-bold leading-tight';
        titre.textContent = invite.nom + (invite.accompagnants ? ` +${invite.accompagnants}` : '');
        const sous = document.createElement('span');
        sous.className = 'mt-1 block text-xl';
        sous.textContent = `Liste ${invite.liste}`;
        nom.append(titre, sous);

        const statut = document.createElement('span');
        statut.className = 'shrink-0 text-right text-2xl font-bold';
        statut.textContent = invite.pointe_at ? `✓ ${heure(invite.pointe_at)}${invite.local ? ' ⏳' : ''}` : 'Pointer';
        if (invite.local) {
            statut.title = 'Pas encore envoyé';
        }

        bouton.append(nom, statut);
        li.append(bouton);

        return li;
    }
}

async function lireJson(reponse) {
    if (!reponse.ok || !reponse.headers.get('Content-Type')?.includes('application/json')) {
        throw new Error(`HTTP ${reponse.status}`);
    }

    return reponse.json();
}

function ouvrirBase() {
    return new Promise((resolve, reject) => {
        const requete = indexedDB.open(BASE, 1);
        requete.onupgradeneeded = () => {
            requete.result.createObjectStore('listes', { keyPath: 'soiree' });
            requete.result.createObjectStore('attente', { keyPath: 'id' });
        };
        requete.onsuccess = () => resolve(requete.result);
        requete.onerror = () => reject(requete.error);
    });
}

function transaction(db, magasin, mode, action) {
    return new Promise((resolve, reject) => {
        const tx = db.transaction(magasin, mode);
        const requete = action(tx.objectStore(magasin));
        tx.oncomplete = () => resolve(requete?.result);
        tx.onerror = () => reject(tx.error);
    });
}

const lire = (db, magasin, cle) => transaction(db, magasin, 'readonly', (store) => store.get(cle));
const toutLire = (db, magasin) => transaction(db, magasin, 'readonly', (store) => store.getAll());
const ecrire = (db, magasin, valeur) => transaction(db, magasin, 'readwrite', (store) => store.put(valeur));
const supprimer = (db, magasin, cle) => transaction(db, magasin, 'readwrite', (store) => store.delete(cle));
