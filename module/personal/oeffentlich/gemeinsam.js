// SPDX-License-Identifier: AGPL-3.0-or-later
// Gemeinsames der Seiten des Personals (PERSONAL.md Stufe 1): Dialog, Stunden, Texte der Zustände.
import { h, knopf, datumText } from '/kern/ui/bausteine.js';

export function stil() {
  const css = new URL('./personal.css', import.meta.url).href;
  if (!document.querySelector(`link[href="${css}"]`)) document.head.append(h('link', { rel: 'stylesheet', href: css }));
}

/** Dialog mit Formular: o.felder (Elemente), o.ja (Text), o.senden(); schließt erst nach Erfolg. Fehler stehen an festem Platz. */
export function dialog(o) {
  const ja = h('button', { type: 'submit', class: 'knopf primaer', id: `${o.id}-senden` }, o.ja || 'Speichern');
  const fehler = h('p', { class: 'hinweis fehlertext', id: `${o.id}-fehler`, role: 'alert' }, ' ');
  const dlg = h('dialog', { class: ['dialog', o.breit ? 'pe-breit' : ''], id: o.id },
    h('form', { onsubmit: async (ev) => {
      ev.preventDefault();
      if (o.ohneJa) return;
      ja.disabled = true;
      fehler.textContent = ' ';
      try { await o.senden(); dlg.close(); } catch (x) {
        fehler.textContent = (x.problem?.fehler || []).map((f) => `${FELDNAMEN[f.feld] || f.feld}: ${f.meldung}`).join(' · ') || x.problem?.detail || x.message || 'Das hat nicht geklappt';
      } finally { ja.disabled = false; }
    } },
    h('h2', {}, o.titel), o.text ? h('p', { class: 'hinweis' }, o.text) : null, ...o.felder, fehler,
    h('div', { class: 'dialog-knoepfe' }, knopf(o.ohneJa ? 'Schließen' : 'Abbrechen', () => dlg.close(), '', { id: `${o.id}-abbrechen` }), o.ohneJa ? null : ja)));
  dlg.addEventListener('close', () => dlg.remove());
  document.body.append(dlg);
  dlg.showModal();
  return { dlg, ja, fehler };
}

const FELDNAMEN = { personalnummer: 'Personalnummer', familienname: 'Familienname', vornamen: 'Vorname(n)', vorgesetzter: 'Vorgesetzter', nutzer: 'Nutzer',
  austritt: 'Austritt', 'privat.geburtsdatum': 'Geburtsdatum', 'privat.strasse': 'Straße', 'privat.plz': 'PLZ', 'privat.ort': 'Ort', 'privat.telefon': 'Telefon',
  'privat.email': 'E-Mail', 'privat.notfall_name': 'Notfallkontakt', 'privat.notfall_telefon': 'Telefon des Notfallkontakts', gueltig_ab: 'Gültig ab', art: 'Art',
  funktion: 'Funktion', beginn: 'Beginn', probezeit_bis: 'Probezeit bis', befristet_bis: 'Befristet bis', kuendigungsfrist: 'Kündigungsfrist',
  verteilung: 'Stunden je Wochentag', urlaubstage: 'Urlaubstage', lohnbuch: 'Buch', faellig: 'Fällig am', bemerkung: 'Bemerkung', person: 'Person',
  name: 'Name', warn_tage: 'Warnfrist', grund: 'Begründung', loeschen_ab: 'Löschen ab', strasse: 'Straße', plz: 'PLZ', ort: 'Ort', telefon: 'Telefon',
  email: 'E-Mail', notfall_name: 'Notfallkontakt', notfall_telefon: 'Telefon des Notfallkontakts' };
export const PRIVAT_FELDER = [['strasse', 'Straße und Hausnummer'], ['plz', 'PLZ'], ['ort', 'Ort'], ['telefon', 'Telefon'], ['email', 'E-Mail (privat)'],
  ['notfall_name', 'Notfallkontakt'], ['notfall_telefon', 'Telefon des Notfallkontakts']];
export const feldname = (f) => FELDNAMEN[f] || f;

export const VERTRAGSARTEN = { unbefristet: 'unbefristet', befristet: 'befristet', ausbildung: 'Ausbildung', minijob: 'Minijob', werkstudent: 'Werkstudent' };
export const WOCHENTAGE = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

/** Minuten als Stunden: 450 → „7,5“, 465 → „7:45“ */
export function stundenText(min) {
  if (min % 60 === 0) return String(min / 60);
  if (min % 30 === 0) return `${Math.floor(min / 60)},5`;
  return `${Math.floor(min / 60)}:${String(min % 60).padStart(2, '0')}`;
}

/** „8“, „7,5“, „7.5“ oder „7:30“ → Minuten; leer → 0; unlesbar → null */
export function stundenLesen(text) {
  const t = String(text || '').trim();
  if (t === '') return 0;
  let m = /^(\d{1,2}):([0-5]\d)$/.exec(t);
  if (m) return Number(m[1]) * 60 + Number(m[2]);
  m = /^(\d{1,2})(?:[.,](\d{1,2}))?$/.exec(t);
  if (!m) return null;
  const min = Math.round(Number(`${m[1]}.${m[2] || '0'}`) * 60);
  return min > 1440 ? null : min;
}

/** Zustand einer Frist als Zeichen und Text – nie nur Farbe */
export function fristZustand(f) {
  if (f.zustand === 'erledigt') return h('span', { 'data-zustand': 'erledigt' }, '✓ erledigt');
  if (f.zustand === 'ueberfaellig') return h('span', { 'data-zustand': 'ueberfaellig' }, `! überfällig seit ${-f.tage === 1 ? '1 Tag' : `${-f.tage} Tagen`}`);
  if (f.zustand === 'faellig') return h('span', { 'data-zustand': 'faellig' }, f.tage === 0 ? '▲ heute fällig' : `▲ fällig in ${f.tage === 1 ? '1 Tag' : `${f.tage} Tagen`}`);
  return h('span', { 'data-zustand': 'offen' }, `in ${f.tage} Tagen`);
}

export const tag = (iso) => (iso ? datumText(iso) : '');
