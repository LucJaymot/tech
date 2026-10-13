import { test } from 'node:test';
import assert from 'node:assert/strict';
import { decalage, duree, texte } from '../../assets/feuille_de_route/compte_a_rebours.js';

const ouverture = Date.parse('2026-11-03T19:00:00+01:00');
const fermeture = Date.parse('2026-11-04T01:00:00+01:00');

test('counts down to the opening, then to the closing', () => {
    assert.equal(texte(ouverture - ((2 * 24 + 3) * 3600 + 4 * 60 + 5) * 1000, ouverture, fermeture), 'Ouverture dans 2 j 03 h 04 min 05 s');
    assert.equal(texte(ouverture - 65 * 1000, ouverture, fermeture), 'Ouverture dans 01 min 05 s');
    assert.equal(texte(ouverture, ouverture, fermeture), 'Fermeture dans 06 h 00 min 00 s');
    assert.equal(texte(fermeture, ouverture, fermeture), 'Soirée terminée');
});

test('rounds down to the second', () => {
    assert.equal(duree(59_999), '00 min 59 s');
    assert.equal(duree(3_600_000), '01 h 00 min 00 s');
});

test('follows the server clock on fresh pages and keeps the last gap on offline copies', () => {
    const memoire = new Map();
    const stockage = { setItem: (k, v) => memoire.set(k, v), getItem: (k) => memoire.get(k) ?? null };

    assert.equal(decalage(10_000, 4_000, true, stockage), 6_000);
    assert.equal(decalage(1_000, 900_000, false, stockage), 6_000, 'the stale server time of a cached page is ignored');
    assert.equal(decalage(1_000, 900_000, false, null), 0);
});
