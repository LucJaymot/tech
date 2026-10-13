/*
 * Door check-in logic, without browser APIs, so that it runs and is tested
 * under Node as well (tests/js/pointage.test.js).
 *
 * An invite: {id, nom, accompagnants, liste, pointe_at, pointage_id}.
 * A pending check-in: {id: uuid, invite: invite id, at: ISO 8601}.
 */

/**
 * Lower case, without accents, ligatures nor punctuation: "Hélène D'Œuf" → "helene d oeuf".
 */
export function normaliser(texte) {
    return String(texte ?? '')
        .replace(/œ/g, 'oe').replace(/Œ/g, 'Oe')
        .replace(/æ/g, 'ae').replace(/Æ/g, 'Ae')
        .replace(/ß/g, 'ss')
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .toLowerCase()
        .replace(/[^\p{L}\p{N}]+/gu, ' ')
        .trim();
}

/**
 * Invites whose name or list owner contains every typed word, in any order,
 * whatever the accents and case.
 */
export function filtrer(invites, recherche) {
    const mots = normaliser(recherche).split(' ').filter(Boolean);
    if (0 === mots.length) {
        return invites;
    }

    return invites.filter((invite) => {
        const cible = ` ${normaliser(`${invite.nom} ${invite.liste}`)}`;

        return mots.every((mot) => cible.includes(` ${mot}`));
    });
}

/**
 * Guests still expected first, then by name.
 */
export function trier(invites) {
    return [...invites].sort((a, b) => (null !== a.pointe_at) - (null !== b.pointe_at) || normaliser(a.nom).localeCompare(normaliser(b.nom)));
}

/**
 * The server's list with the local check-ins not sent yet. The earliest
 * check-in wins, as on the server.
 */
export function fusionner(serveur, enAttente) {
    const parInvite = new Map();
    for (const pointage of enAttente) {
        const deja = parInvite.get(pointage.invite);
        if (!deja || pointage.at < deja.at) {
            parInvite.set(pointage.invite, pointage);
        }
    }

    return serveur.map((invite) => {
        const pointage = parInvite.get(invite.id);
        if (!pointage || (invite.pointe_at && Date.parse(invite.pointe_at) <= Date.parse(pointage.at))) {
            return { ...invite, local: false };
        }

        return { ...invite, pointe_at: pointage.at, pointage_id: pointage.id, local: true };
    });
}

export function creerPointage(invite, maintenant = new Date(), uuid = globalThis.crypto.randomUUID()) {
    return { id: uuid, invite: invite.id, at: maintenant.toISOString() };
}

/**
 * Applies the server's answers to the cached list.
 *
 * @returns {{invites: Array, envoyes: Array<string>, conflits: Array<object>}}
 *          envoyes: ids to drop from the queue (the server has settled them)
 */
export function appliquerResultats(invites, resultats) {
    const parInvite = new Map(resultats.filter((r) => 'invalide' !== r.resultat).map((r) => [r.invite, r]));

    return {
        invites: invites.map((invite) => {
            const resultat = parInvite.get(invite.id);

            return resultat ? { ...invite, pointe_at: resultat.pointe_at, local: false } : invite;
        }),
        envoyes: resultats.map((r) => r.id),
        conflits: resultats.filter((r) => 'conflit' === r.resultat),
    };
}

/**
 * "21:42" in the device's time zone.
 */
export function heure(iso) {
    const date = new Date(iso);

    return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}
