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
  // Erweiterungen aktiver Module (F102): zusätzliche Spalten und Werkzeuge. Der Kern kennt nur Konten –
  // was ein Modul an einem Konto führt (z. B. den Steuersatz), bringt es selbst mit.
  const zusatzSpalten = [];
  const werkzeuge = h('span', { class: 'zeile', id: 'konten-werkzeuge' });
  const erweiterungenLaden = async () => {
    for (const pfad of k.erweiterungen?.('konten') || []) {
      try {
        const e = await (await import(pfad)).kontenErweiterung(k, { konten: () => konten, neuZeichnen: () => zeichnen() });
        zusatzSpalten.push(...(e.spalten || []));
        werkzeuge.append(...(e.werkzeuge || []));
      } catch (f) { console.warn('Erweiterung der Kontenliste nicht geladen', pfad, f); }
    }
  };

  b.append(seitenkopf(`Konten – ${k.mandantName || k.mandant}`, suche,
    darfAendern ? knopf('Neu', () => { formular.hidden = !formular.hidden; }, 'primaer') : null,
    k.darf('kontenrahmen.vorlage.anwenden') ? knopf('Kontenrahmen anwenden …', () => k.navigieren(`#/m/${k.mandant}/kontenrahmen/anwenden`)) : null),
  werkzeuge, formular, liste);
  // fester Kopf (F106): nur die Zeilen rollen
  b.classList.add('listenseite');
  liste.classList.add('listen-rollen');

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

  /**
   * Konto ändern: Bezeichnung, Kontoart, aktiv. Die Nummer bleibt immer; bei einem bebuchten Konto auch die
   * Kontoart (F74) – eine falsche Zuordnung wird dann über ein neues Konto und Umbuchen berichtigt.
   */
  function bearbeiten(x) {
    if (!darfAendern) return;
    const bez = h('input', { id: 'konto-bezeichnung', required: true, maxlength: '200', size: '44', value: x.bezeichnung });
    const art = auswahl(KONTOARTEN, x.kontoart, { id: 'konto-art', disabled: x.bebucht });
    const aktiv = h('input', { type: 'checkbox', id: 'konto-aktiv', checked: x.aktiv !== false });
    const fehler = h('p', { class: 'fehler-text', role: 'alert' });
    const d = h('dialog', { class: 'dialog', id: 'konto-dialog' }, h('h2', {}, `Konto ${x.nr} ändern`),
      h('form', { onsubmit: async (ev) => {
        ev.preventDefault();
        const json = {};
        if (bez.value.trim() !== x.bezeichnung) json.bezeichnung = bez.value.trim();
        if (!x.bebucht && art.value !== x.kontoart) json.kontoart = art.value;
        if (aktiv.checked !== (x.aktiv !== false)) json.aktiv = aktiv.checked;
        if (!Object.keys(json).length) { d.close(); return; }
        try {
          await k.api('PATCH', `${basis}/konten/${encodeURIComponent(x.nr)}`, { json });
          d.close();
          k.melden(`Konto ${x.nr} geändert.`, 'erfolg');
          konten = await k.api('GET', `${basis}/konten`);
          zeichnen();
        } catch (e) { fehler.textContent = e.message; }
      } },
      feld('Nummer', h('input', { value: x.nr, disabled: true, size: '8' }), 'Die Nummer eines Kontos lässt sich nicht ändern.'),
      feld('Bezeichnung', bez),
      feld('Kontoart', art, x.bebucht ? 'Das Konto ist bebucht: Die Kontoart steht fest.' : ''),
      h('label', { class: 'feld-wahl' }, aktiv, 'aktiv – steht beim Buchen zur Auswahl'),
      fehler,
      h('div', { class: 'dialog-knoepfe' }, knopf('Abbrechen', () => d.close()), h('button', { type: 'submit', class: 'knopf primaer', id: 'konto-speichern' }, 'Speichern'))));
    d.addEventListener('close', () => d.remove());
    document.body.append(d);
    d.showModal();
    bez.focus();
  }

  function zeichnen() {
    const zeilen = konten.filter((x) => !filter || x.nr.toLowerCase().includes(filter) || x.bezeichnung.toLowerCase().includes(filter));
    liste.replaceChildren(tabelle([
      { titel: 'Nummer', klasse: 'nr', wert: (x) => x.nr },
      { titel: 'Bezeichnung', wert: (x) => x.bezeichnung },
      { titel: 'Kontoart', klasse: 'eng', wert: (x) => ART[x.kontoart] || x.kontoart },
      { titel: 'bebucht', klasse: 'eng', wert: (x) => (x.bebucht ? 'ja' : '') },
      { titel: 'aktiv', klasse: 'eng', wert: (x) => (x.aktiv === false ? 'nein' : '') },
      ...zusatzSpalten,
      ...(darfAendern ? [{ titel: '', klasse: 'eng', wert: (x) => knopf('Ändern', (ev) => { ev?.stopPropagation?.(); bearbeiten(x); }, 'klein', { 'data-konto-aendern': x.nr, title: 'Bezeichnung, Kontoart und „aktiv“ ändern – auch per Doppelklick oder Enter auf der Zeile' }) }] : []),
    ], { zeilen, leer: konten.length ? 'Kein Konto passt zur Suche' : 'Noch keine Konten – Kontenrahmen anwenden oder Konto anlegen',
      fuss: `${zeilen.length} von ${konten.length} Konten`, oeffnen: darfAendern ? bearbeiten : null }));
  }
  await erweiterungenLaden();
  zeichnen();
}
