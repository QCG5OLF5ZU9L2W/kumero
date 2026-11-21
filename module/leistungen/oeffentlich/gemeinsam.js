// SPDX-License-Identifier: AGPL-3.0-or-later
// Gemeinsames der Seiten des Moduls leistungen (LEISTUNGEN.md Abschnitt 10, F154): Dialog, Monatswahl, Beträge,
// die Felder zum Eintragen einer Leistung und die Tabelle der Leistungen.
import { h, knopf, datumText } from '/kern/ui/bausteine.js';

const FELDER = { tag: 'Tag', leistung: 'Leistung', menge: 'Menge', einzel_cent: 'Einzelpreis', vorgang: 'Vorgang', person: 'Person', bezeichnung: 'Bezeichnung', gruppe: 'Gruppe',
  einheit: 'Einheit', gueltig_ab: 'Gültig ab', preis_cent: 'Preis', monat: 'Monat' };

export function stil() {
  const css = new URL('./leistungen.css', import.meta.url).href;
  if (!document.querySelector(`link[href="${css}"]`)) document.head.append(h('link', { rel: 'stylesheet', href: css }));
}


export function dialog(o) {
  const ja = h('button', { type: 'submit', class: 'knopf primaer', id: `${o.id}-senden` }, o.ja || 'Speichern');
  const fehler = h('p', { class: 'hinweis fehlertext', id: `${o.id}-fehler`, role: 'alert' }, ' ');
  const dlg = h('dialog', { class: ['dialog', o.breit ? 'le-breit' : ''], id: o.id },
    h('form', { onsubmit: async (ev) => {
      ev.preventDefault();
      ja.disabled = true;
      fehler.textContent = ' ';
      try { await o.senden(); dlg.close(); } catch (x) {
        fehler.textContent = (x.problem?.fehler || []).map((f) => `${FELDER[f.feld] || f.feld}: ${f.meldung}`).join(' · ') || x.problem?.detail || x.message || 'Das hat nicht geklappt';
      } finally { ja.disabled = false; }
    } },
    h('h2', {}, o.titel), o.text ? h('p', { class: 'hinweis' }, o.text) : null, ...o.felder, fehler,
    h('div', { class: 'dialog-knoepfe' }, knopf('Abbrechen', () => dlg.close(), '', { id: `${o.id}-abbrechen` }), ja)));
  dlg.addEventListener('close', () => dlg.remove());
  document.body.append(dlg);
  dlg.showModal();
}


const MONATE = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

export const monatText = (m) => (m ? `${MONATE[Number(m.slice(5, 7)) - 1]} ${m.slice(0, 4)}` : '');

export const tag = (iso) => (iso ? datumText(iso) : '');

export function monatAuswahl(id, wert, wechsel) {
  const jetzt = new Date();
  const monate = Array.from({ length: 24 }, (_, i) => { const t = new Date(jetzt.getFullYear(), jetzt.getMonth() - i, 1); return `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}`; });
  if (wert && !monate.includes(wert)) monate.push(wert);
  const s = h('select', { id, 'aria-label': 'Monat', onchange: () => wechsel(s.value) }, monate.map((m) => h('option', { value: m }, monatText(m))));
  if (wert) s.value = wert;
  return s;
}


// Beträge in ganzen Cent: „12,50“ zeigen, „12,5“ / „12.50“ / „12“ lesen (null: nicht lesbar)
export const geld = (cent) => `${cent < 0 ? '−' : ''}${Math.floor(Math.abs(cent) / 100).toLocaleString('de-DE')},${String(Math.abs(cent) % 100).padStart(2, '0')}`;
export function centLesen(text) {
  const t = String(text).trim().replace(/\s|€/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.');
  return /^\d{1,7}(\.\d{1,2})?$/.test(t) ? Math.round(Number(t) * 100) : null;
}
export const mengeText = (m) => String(m).replace(/\.?0+$/, '').replace('.', ',');
export function mengeLesen(text) {
  const t = String(text).trim().replace(',', '.');
  return /^\d{1,6}(\.\d{1,2})?$/.test(t) && Number(t) > 0 ? String(Number(t)) : null;
}

/**
 * Felder zum Eintragen einer Leistung. o: {id, katalog, heute, feld, auswahl, personen?}; liefert {felder, json()}.
 * Der Betrag rechnet mit: Menge × Preis des Katalogs; bei einer Leistung mit freiem Preis lässt sich der Einzelpreis ändern.
 */
export function zeileFelder(o) {
  const person = o.personen ? o.auswahl(o.personen.map((p) => [p.id, p.name]), o.personen[0]?.id || '', { id: `${o.id}-person` }) : null;
  const leistung = o.auswahl(o.katalog.length ? o.katalog.map((l) => [l.id, (l.gruppe ? `${l.gruppe}: ` : '') + l.bezeichnung]) : [['', '– der Katalog ist leer –']], o.katalog[0]?.id || '', { id: `${o.id}-leistung` });
  const tagFeld = h('input', { id: `${o.id}-tag`, type: 'date', max: o.heute, value: o.heute });
  const menge = h('input', { id: `${o.id}-menge`, type: 'text', inputmode: 'decimal', class: 'le-kurz', value: '1', autocomplete: 'off' });
  const einzel = h('input', { id: `${o.id}-einzel`, type: 'text', inputmode: 'decimal', class: 'le-kurz', autocomplete: 'off' });
  const vorgang = h('input', { id: `${o.id}-vorgang`, type: 'text', maxlength: '40', size: '18', autocomplete: 'off' });
  const betrag = h('output', { id: `${o.id}-betrag`, class: 'le-betrag' }, '');
  const gewaehlt = () => o.katalog.find((l) => l.id === leistung.value) || null;
  const rechnen = () => {
    const l = gewaehlt(); const m = mengeLesen(menge.value); const e = centLesen(einzel.value);
    betrag.textContent = l && m !== null && e !== null ? `= ${geld(Math.round(Number(m) * e))} €` : '';
  };
  const anpassen = () => {
    const l = gewaehlt();
    einzel.value = l && l.preis_cent !== null ? geld(l.preis_cent) : '';
    einzel.disabled = !l?.preis_frei;
    einzel.title = !l ? '' : (l.preis_frei ? 'Der Preis dieser Leistung darf je Zeile abweichen' : (l.preis_cent === null ? 'Für diese Leistung gilt heute kein Preis – im Katalog setzen' : 'Der Preis kommt aus dem Katalog'));
    rechnen();
  };
  leistung.addEventListener('change', anpassen);
  for (const el of [menge, einzel]) el.addEventListener('input', rechnen);
  anpassen();
  return {
    felder: [...(person ? [o.feld('Für wen', person)] : []),
      h('div', { class: 'le-reihe' }, o.feld('Tag', tagFeld), o.feld('Leistung', leistung)),
      h('div', { class: 'le-reihe' }, o.feld('Menge', menge), o.feld('Einzelpreis €', einzel), o.feld('Betrag', betrag), o.feld('Vorgang', vorgang, 'neutrale Kennung – kein Name, kein Kunde'))],
    json: () => {
      const l = gewaehlt();
      if (!l) throw new Error('Der Katalog ist leer – das Personal legt Leistungen unter „Katalog …“ an');
      const m = mengeLesen(menge.value);
      if (m === null) throw new Error('Menge: eine Zahl größer null, höchstens zwei Nachkommastellen');
      const e = centLesen(einzel.value);
      if (l.preis_frei && e === null) throw new Error('Einzelpreis nicht lesbar (12,50)');
      return { ...(person ? { person: person.value } : {}), tag: tagFeld.value, leistung: l.id, menge: m, ...(l.preis_frei ? { einzel_cent: e } : {}), ...(vorgang.value.trim() ? { vorgang: vorgang.value.trim() } : {}) };
    },
  };
}

export const zustandText = (z) => (z.bestaetigt ? '✓ bestätigt' : '▲ wartet auf die Bestätigung');
