// SPDX-License-Identifier: AGPL-3.0-or-later
// Übersicht (Bereich Start): der Mandant dieser Anmeldung bzw. die Verwaltung der Installation.
import { h, seitenkopf } from '../ui/bausteine.js';

export async function zeigen(b, k) {
  const ich = k.ich;
  b.append(seitenkopf(`Willkommen, ${ich.name}`));
  // Eine Identität gehört zu genau einem Mandanten (F114); System-Verwalter haben keinen
  const m = ich.mandanten[0];
  if (m) {
    b.append(h('h2', {}, 'Mandant'), h('div', { class: 'kartenreihe' },
      h('a', { class: 'karte', href: `#/m/${m.kennung}/kern/konten` },
        h('strong', {}, m.name), h('div', { class: 'hinweis' }, m.kennung),
        h('div', { class: 'hinweis' }, m.betriebsart === 'test' ? 'Testbetrieb' : (m.betriebsart || 'nicht eingerichtet'),
          m.lesemodus ? ' · Lesemodus' : ''))),
    h('p', { class: 'hinweis' }, 'Für einen anderen Mandanten meldest du dich mit dem Passkey dieses Mandanten an.'));
  }
  if (k.darf('verwaltung.verwalter')) {
    b.append(h('h2', {}, 'Verwaltung'), h('div', { class: 'kartenreihe' },
      h('a', { class: 'karte', href: '#/system/kontenrahmen/import' }, h('strong', {}, 'Kontenrahmen importieren'),
        h('div', { class: 'hinweis' }, 'DATEV-PDF oder CSV einlesen, prüfen, als Vorlage speichern')),
      h('a', { class: 'karte', href: '#/system/kontenrahmen/vorlagen' }, h('strong', {}, 'Kontenrahmen-Vorlagen'),
        h('div', { class: 'hinweis' }, 'Gespeicherte Vorlagen ansehen; anwenden kann sie, wer im Mandanten das Recht dazu hat'))),
    h('div', { class: 'karte' }, h('h2', {}, 'Mandanten und Nutzer'),
      h('p', {}, 'Mandanten, ihre Administratoren und Nutzer werden bis zu den Seiten der nächsten Stufe auf dem Server gepflegt:'),
      h('pre', {}, 'bin/kumero mandant:anlegen KENNUNG NAME --administrator VORNAME FAMILIENNAME E-MAIL\n'
        + 'bin/kumero nutzer:anlegen MANDANT VORNAME FAMILIENNAME E-MAIL --rolle ROLLE\n'
        + 'bin/kumero nutzer:liste')));
  }
}
