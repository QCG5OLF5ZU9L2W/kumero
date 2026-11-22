// SPDX-License-Identifier: AGPL-3.0-or-later
// Übersicht (Bereich Start): die Bücher des Mandanten dieser Anmeldung bzw. die Verwaltung der Installation.
import { h, seitenkopf } from '../ui/bausteine.js';

export async function zeigen(b, k) {
  const ich = k.ich;
  b.append(seitenkopf(`Willkommen, ${ich.name}`));
  // Eine Identität gehört zu genau einem Mandanten (F114); System-Verwalter haben keinen
  const m = ich.mandanten[0];
  if (m) {
    const art = { euer: 'Einnahmenüberschussrechnung', bilanz: 'Bilanz' };
    b.append(h('h2', {}, `Bücher im Mandanten „${m.name}“`),
      k.buecher.length
        ? h('div', { class: 'kartenreihe', id: 'start-buecher' }, k.buecher.map((x) => h('a', { class: 'karte', 'data-buch': x.kennung, href: `${k.buchSeiten(x.kennung)}/kern/konten` },
          h('strong', {}, x.name), h('div', { class: 'hinweis' }, `${x.kennung} · ${art[x.art] || x.art}`),
          h('div', { class: 'hinweis' }, x.testbuch ? 'Testbuch' : (x.betriebsart === 'test' ? 'Testbetrieb' : 'Produktivbetrieb'), x.lesemodus ? ' · Lesemodus' : '',
            x.zugang ? '' : ' · ohne Rolle für dich'))))
        : h('p', { class: 'hinweis', id: 'start-kein-buch' }, 'Für dich gibt es in diesem Mandanten noch kein Buch. Bücher legt der Administrator an; hinein kommt, wer dort eine Rolle hat.'),
      h('p', { class: 'hinweis' }, 'Jedes Buch ist eine eigene Buchhaltung. Für einen anderen Mandanten meldest du dich mit dem Passkey dieses Mandanten an.'));
  }
  if (k.darf('verwaltung.verwalter')) {
    b.append(h('h2', {}, 'Verwaltung'), h('div', { class: 'kartenreihe' },
      h('a', { class: 'karte', href: '#/system/kontenrahmen/import' }, h('strong', {}, 'Kontenrahmen importieren'),
        h('div', { class: 'hinweis' }, 'DATEV-PDF oder CSV einlesen, prüfen, als Vorlage speichern')),
      h('a', { class: 'karte', href: '#/system/kontenrahmen/vorlagen' }, h('strong', {}, 'Kontenrahmen-Vorlagen'),
        h('div', { class: 'hinweis' }, 'Gespeicherte Vorlagen ansehen; anwenden kann sie, wer im Mandanten das Recht dazu hat')),
      h('a', { class: 'karte', href: '#/system/kern/mandanten' }, h('strong', {}, 'Mandanten'),
        h('div', { class: 'hinweis' }, 'Anlegen mit erstem Administrator, stilllegen, exportieren, löschen'))),
    h('div', { class: 'karte' }, h('h2', {}, 'Nutzer und Bücher eines Mandanten'),
      h('p', {}, 'Nutzer, Rollen und Bücher pflegt der Administrator des Mandanten in dessen Oberfläche. Du siehst als System-Verwalter keinen Mandanten von innen.')));
  }
}
