// SPDX-License-Identifier: AGPL-3.0-or-later
// Übersicht (Bereich Start): Mandanten und nächste Schritte.
import { h, seitenkopf } from '../ui/bausteine.js';

export async function zeigen(b, k) {
  const ich = k.ich;
  b.append(seitenkopf(`Willkommen, ${ich.name}`));
  if (ich.mandanten.length === 0) {
    b.append(h('div', { class: 'karte' }, h('h2', {}, 'Noch kein Mandant'),
      h('p', {}, 'Du hast noch keinen Zugang zu einem Mandanten. Auf dem Server:'),
      h('pre', {}, 'bin/kumero mandant:liste\nbin/kumero zugang:gewaehren NUTZER-ID MANDANT')));
  } else {
    b.append(h('h2', {}, 'Mandanten'), h('div', { class: 'kartenreihe' }, ich.mandanten.map((m) =>
      h('a', { class: 'karte', href: `#/m/${m.kennung}/kern/konten` },
        h('strong', {}, m.name), h('div', { class: 'hinweis' }, m.kennung),
        h('div', { class: 'hinweis' }, m.betriebsart === 'test' ? 'Testbetrieb' : (m.betriebsart || 'nicht eingerichtet'),
          m.lesemodus ? ' · Lesemodus' : '')))));
  }
  if (k.darf('verwaltung.verwalter')) {
    b.append(h('h2', {}, 'Verwaltung'), h('div', { class: 'kartenreihe' },
      h('a', { class: 'karte', href: '#/system/kontenrahmen/import' }, h('strong', {}, 'Kontenrahmen importieren'),
        h('div', { class: 'hinweis' }, 'DATEV-PDF oder CSV einlesen, prüfen, als Vorlage speichern')),
      h('a', { class: 'karte', href: '#/system/kontenrahmen/vorlagen' }, h('strong', {}, 'Kontenrahmen-Vorlagen'),
        h('div', { class: 'hinweis' }, 'Gespeicherte Vorlagen ansehen und anwenden'))));
  }
}
