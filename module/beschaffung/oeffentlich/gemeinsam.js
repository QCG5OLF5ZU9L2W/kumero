// SPDX-License-Identifier: AGPL-3.0-or-later
// Gemeinsames der Seite „Bedarf und Bestellung“: Mengen und Preise, Dialog und Raster (wie im Lager, STUECKLISTE.md AG6 –
// eigene Fassung, weil das Modul auch ohne das Lager läuft).
import { h, knopf } from '/kern/ui/bausteine.js';

const zahl = new Intl.NumberFormat('de-DE', { maximumFractionDigits: 3 });
export const mengeText = (m) => (m === null || m === undefined || m === '' ? '' : zahl.format(Number(m)));
/** „12“, „0,5“ oder „1.250,5“ → „12“, „0.5“, „1250.5“; null, wenn es keine Menge größer null ist. */
export function mengeLesen(text) {
  let s = String(text ?? '').replace(/\s/g, '');
  if (s === '') return null;
  if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
  if (!/^\d{1,12}(\.\d{1,3})?$/.test(s) || Number(s) <= 0) return null;
  return s.includes('.') ? s.replace(/0+$/, '').replace(/\.$/, '') : s;
}
export const preisText = (p) => (p === null || p === undefined || p === '' ? '' : `${Number(p).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 4 })} €`);
/** „4,50“ oder „1.204,5“ → „4.50“, „1204.5“; '' für leer; null, wenn es kein Preis mit höchstens vier Nachkommastellen ist. */
export function preisLesen(text) {
  let s = String(text ?? '').replace(/[\s€]/g, '');
  if (s === '') return '';
  if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
  return /^\d{1,11}(\.\d{1,4})?$/.test(s) ? s : null;
}

// Wie die Felder in den Dialogen heißen – damit eine Meldung des Servers sagt, welches Feld gemeint ist (F213)
const FELDNAMEN = { wofuer: 'Wofür', termin: 'Gewünscht bis', bezeichnung: 'Bezeichnung', einheit: 'Einheit', menge: 'Menge', vermerk: 'Vermerk', grund: 'Grund', lieferant: 'Lieferant',
  lieferant_nummer: 'Artikelnummer dort', bestellnummer: 'Bestellnummer', bestellt_am: 'Bestellt am', liefertermin: 'Liefertermin', preis: 'Preis', gtin: 'GTIN', rest: 'Rest', artikel: 'Artikel',
  person: 'An', rolle: 'An', wozu: 'Wozu', am: 'Datum', nummer: 'Artikelnummer', art: 'Art', hat_serie: 'Seriennummer', hat_charge: 'Charge', in_stuecken: 'In Stücken gezählt', verfall_pflicht: 'Verfallsdatum' };
/** Meldung des Servers mit dem Namen des Felds davor; bei Positionen mit ihrer Nummer. */
export function fehlerText(x) {
  const mit = (f) => {
    const feld = String(f.feld || '');
    const pos = /^positionen\[(\d+)\]/.exec(feld);
    const n = FELDNAMEN[feld.split('.').pop().replace(/\[\d+\]$/, '')];
    return `${pos ? `Position ${Number(pos[1]) + 1}, ` : ''}${n && !String(f.meldung).startsWith(n) ? `${n}: ` : ''}${f.meldung}`;
  };
  return (x.problem?.fehler || []).map(mit).join(' · ') || x.problem?.detail || x.message || 'Das hat nicht geklappt';
}

/** Dialog mit Formular: o = {id, titel, text?, felder, ja, senden(), breit?, zusatz?, pruefen?()}. Schließt nach senden(); pruefen() sperrt danach wieder, was gesperrt war. */
export function dialog(o) {
  const ja = h('button', { type: 'submit', class: 'knopf primaer', id: `${o.id}-senden` }, o.ja || 'Speichern');
  const fehler = h('p', { class: 'hinweis fehlertext', id: `${o.id}-fehler`, role: 'alert' }, ' ');
  const laufen = async (f) => {
    if (ja.disabled) return;
    ja.disabled = true;
    let text = null;
    try { await f(); dlg.close(); } catch (x) { text = fehlerText(x); } finally { ja.disabled = false; if (o.pruefen) o.pruefen(); if (text) fehler.textContent = text; }
  };
  const dlg = h('dialog', { class: ['dialog', o.breit ? 'bs-breit' : ''], id: o.id },
    h('form', { onsubmit: (ev) => { ev.preventDefault(); laufen(o.senden); } },
      h('h2', {}, o.titel), o.text ? h('p', { class: 'hinweis' }, o.text) : null, ...o.felder, fehler,
      h('div', { class: 'dialog-knoepfe' }, ...(o.zusatz || []), knopf('Abbrechen', () => dlg.close(), '', { id: `${o.id}-abbrechen` }), ja)));
  dlg.addEventListener('close', () => dlg.remove());
  document.body.append(dlg);
  dlg.showModal();
  return { dlg, ja, fehler, laufen };
}

/** Feld im Raster: Beschriftung über dem Feld, darunter immer eine Zeile für den Hinweis (F106: nichts verschiebt sich). */
export function rfeld(beschriftung, eingabe, hinweis = '', breite = 1) {
  const hin = hinweis instanceof Node ? hinweis : h('span', {}, hinweis || ' ');
  hin.classList.add('hinweis');
  return h('label', { class: ['feld', `bs-b${breite}`] }, h('span', { class: 'beschriftung' }, beschriftung), eingabe, hin);
}
/** Zelle des Rasters für Ankreuzfelder oder Knöpfe: steht auf der Höhe der Eingabefelder ihrer Zeile. */
export function rwahl(breite, ...wahlen) {
  return h('div', { class: ['feld', `bs-b${breite}`] }, h('span', { class: 'beschriftung', 'aria-hidden': 'true' }, ' '), h('div', { class: 'bs-wahlen' }, wahlen), h('span', { class: 'hinweis' }, ' '));
}

export function stil() {
  const css = new URL('./beschaffung.css', import.meta.url).href;
  if (!document.querySelector(`link[href="${css}"]`)) document.head.append(h('link', { rel: 'stylesheet', href: css }));
}
