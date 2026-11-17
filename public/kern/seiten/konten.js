// SPDX-License-Identifier: AGPL-3.0-or-later
// Kontenliste des Mandanten (Stammdaten → Konten).
import { h, seitenkopf, tabelle, knopf, feld, auswahl, KONTOARTEN } from '../ui/bausteine.js';

const ART = Object.fromEntries(KONTOARTEN);

export async function zeigen(b, k) {
  const basis = k.apiBasis;
  let konten = await k.api('GET', `${basis}/konten`);
  let filter = '';
  const suche = h('input', { type: 'search', class: 'suche', placeholder: 'Nummer oder Bezeichnung …', oninput: () => { filter = suche.value.trim().toLowerCase(); zeichnen(); } });
  const liste = h('div');
  const formular = h('div', { class: 'karte', hidden: true });
  const darfAendern = k.darf('kern.stammdaten.aendern');

  b.append(seitenkopf(`Konten – ${k.mandantName || k.mandant}`, suche,
    darfAendern ? knopf('Neu', () => { formular.hidden = !formular.hidden; }, 'primaer') : null,
    k.darf('kontenrahmen.vorlage.anwenden') ? knopf('Kontenrahmen anwenden …', () => k.navigieren(`#/m/${k.mandant}/kontenrahmen/anwenden`)) : null),
  formular, liste);

  if (darfAendern) {
    const nr = h('input', { required: true, maxlength: '20', size: '8' });
    const bez = h('input', { required: true, maxlength: '200', size: '40' });
    const art = auswahl(KONTOARTEN, 'aufwand');
    formular.append(h('h2', {}, 'Konto anlegen'), h('form', { class: 'zeile', onsubmit: async (ev) => {
      ev.preventDefault();
      try {
        const neu = await k.api('POST', `${basis}/konten`, { json: { nr: nr.value, bezeichnung: bez.value, kontoart: art.value } });
        k.melden(`Konto ${neu.nr} angelegt.`, 'erfolg');
        ev.target.reset();
        konten = await k.api('GET', `${basis}/konten`);
        zeichnen();
      } catch (e) { k.fehler(e); }
    } }, feld('Nummer', nr), feld('Bezeichnung', bez), feld('Kontoart', art), h('button', { type: 'submit', class: 'knopf primaer' }, 'Anlegen')));
  }

  function zeichnen() {
    const zeilen = konten.filter((x) => !filter || x.nr.toLowerCase().includes(filter) || x.bezeichnung.toLowerCase().includes(filter));
    liste.replaceChildren(tabelle([
      { titel: 'Nummer', klasse: 'nr', wert: (x) => x.nr },
      { titel: 'Bezeichnung', wert: (x) => x.bezeichnung },
      { titel: 'Kontoart', klasse: 'eng', wert: (x) => ART[x.kontoart] || x.kontoart },
      { titel: 'bebucht', klasse: 'eng', wert: (x) => (x.bebucht ? 'ja' : '') },
      { titel: 'aktiv', klasse: 'eng', wert: (x) => (x.aktiv === false ? 'nein' : '') },
    ], { zeilen, leer: konten.length ? 'Kein Konto passt zur Suche' : 'Noch keine Konten – Kontenrahmen anwenden oder Konto anlegen',
      fuss: `${zeilen.length} von ${konten.length} Konten` }));
  }
  zeichnen();
}
