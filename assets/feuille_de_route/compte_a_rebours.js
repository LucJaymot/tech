/*
 * Countdown of a soirée, without the DOM, so that node --test can run it.
 * Times are in milliseconds.
 */

/** "Ouverture dans 2 j 03 h 04 min 05 s", "Fermeture dans …", then "Soirée terminée". */
export function texte(maintenant, ouverture, fermeture) {
    if (maintenant < ouverture) {
        return `Ouverture dans ${duree(ouverture - maintenant)}`;
    }
    if (maintenant < fermeture) {
        return `Fermeture dans ${duree(fermeture - maintenant)}`;
    }

    return 'Soirée terminée';
}

export function duree(ms) {
    const secondes = Math.floor(ms / 1000);
    const [j, h, min, s] = [Math.floor(secondes / 86400), Math.floor(secondes / 3600) % 24, Math.floor(secondes / 60) % 60, secondes % 60];
    const deux = (n) => String(n).padStart(2, '0');

    return `${j > 0 ? `${j} j ` : ''}${j > 0 || h > 0 ? `${deux(h)} h ` : ''}${deux(min)} min ${deux(s)} s`;
}

/**
 * Server clock minus this device's clock. A copy served offline carries an
 * old server time: keep the gap measured on the last fresh page.
 */
export function decalage(serveur, appareil, fraiche, stockage) {
    if (fraiche) {
        const mesure = serveur - appareil;
        stockage?.setItem('decalage-horloge', String(mesure));

        return mesure;
    }

    return Number(stockage?.getItem('decalage-horloge') ?? 0);
}
