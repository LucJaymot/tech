import { test } from 'node:test';
import assert from 'node:assert/strict';
import { appliquerResultats, creerPointage, filtrer, fusionner, normaliser, trier } from '../../assets/pointage/logique.js';

const invites = [
    { id: 1, nom: 'Hélène Pétillon', accompagnants: 1, liste: 'DJ Kumo', pointe_at: null, pointage_id: null },
    { id: 2, nom: 'Joël Œuvray', accompagnants: 0, liste: 'Camille Martin', pointe_at: null, pointage_id: null },
    { id: 3, nom: 'Amélie Dupré', accompagnants: 0, liste: 'Lila Moon', pointe_at: '2026-11-02T20:05:00+01:00', pointage_id: 'a' },
];

test('normalises accents, case, ligatures and punctuation', () => {
    assert.equal(normaliser("  HÉLÈNE  d'Œuf-Façade "), 'helene d oeuf facade');
    assert.equal(normaliser('Straße'), 'strasse');
    assert.equal(normaliser(null), '');
});

test('search ignores accents and case and matches every word', () => {
    const noms = (liste) => liste.map((i) => i.nom);
    assert.deepEqual(noms(filtrer(invites, 'helene')), ['Hélène Pétillon']);
    assert.deepEqual(noms(filtrer(invites, 'PETI')), ['Hélène Pétillon']);
    assert.deepEqual(noms(filtrer(invites, 'oeuvray')), ['Joël Œuvray']);
    assert.deepEqual(noms(filtrer(invites, 'joel camille')), ['Joël Œuvray'], 'Owner names are searched too.');
    assert.deepEqual(noms(filtrer(invites, 'kumo helene')), ['Hélène Pétillon'], 'Words in any order.');
    assert.deepEqual(noms(filtrer(invites, 'lene')), [], 'Words match from their start.');
    assert.equal(filtrer(invites, '  ').length, 3);
});

test('expected guests come first, then by name', () => {
    assert.deepEqual(trier(invites).map((i) => i.id), [1, 2, 3]);
});

test('local check-ins waiting to be sent show over the server list', () => {
    const attente = [{ id: 'u1', invite: 1, at: '2026-11-02T19:10:00.000Z' }];
    const fusion = fusionner(invites, attente);

    assert.equal(fusion[0].pointe_at, '2026-11-02T19:10:00.000Z');
    assert.equal(fusion[0].local, true);
    assert.equal(fusion[1].local, false);
});

test('the earliest check-in wins locally too', () => {
    // Already checked in on the server at 20:05 (19:05 UTC); a later local one loses.
    const plusTard = fusionner(invites, [{ id: 'u2', invite: 3, at: '2026-11-02T19:30:00.000Z' }]);
    assert.equal(plusTard[2].pointe_at, '2026-11-02T20:05:00+01:00');
    assert.equal(plusTard[2].local, false);

    const plusTot = fusionner(invites, [{ id: 'u3', invite: 3, at: '2026-11-02T18:00:00.000Z' }]);
    assert.equal(plusTot[2].local, true);

    const deuxLocaux = fusionner(invites, [
        { id: 'tard', invite: 2, at: '2026-11-02T19:00:00.000Z' },
        { id: 'tot', invite: 2, at: '2026-11-02T18:59:00.000Z' },
    ]);
    assert.equal(deuxLocaux[1].pointage_id, 'tot');
});

test('check-ins get a client-side id and time', () => {
    const pointage = creerPointage(invites[0], new Date('2026-11-02T19:10:00Z'), '6f1c1c43-0d0e-4b8e-9a3e-0c2a8e3d9b51');

    assert.deepEqual(pointage, { id: '6f1c1c43-0d0e-4b8e-9a3e-0c2a8e3d9b51', invite: 1, at: '2026-11-02T19:10:00.000Z' });
    assert.match(creerPointage(invites[0]).id, /^[0-9a-f-]{36}$/);
});

test('server answers settle the queue and keep the winning time', () => {
    const { invites: apres, envoyes, conflits } = appliquerResultats(invites, [
        { id: 'u1', invite: 1, resultat: 'accepte', pointe_at: '2026-11-02T20:10:00+01:00' },
        { id: 'u2', invite: 3, resultat: 'conflit', pointe_at: '2026-11-02T20:05:00+01:00' },
        { id: 'u9', invite: 99, resultat: 'invalide', pointe_at: null },
    ]);

    assert.deepEqual(envoyes, ['u1', 'u2', 'u9'], 'Invalid ones are dropped too: resending them would not help.');
    assert.equal(apres[0].pointe_at, '2026-11-02T20:10:00+01:00');
    assert.equal(apres[2].pointe_at, '2026-11-02T20:05:00+01:00');
    assert.equal(conflits.length, 1);
});
