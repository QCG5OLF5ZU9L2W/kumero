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

// --- Abwesenheiten (PERSONAL.md Stufe 2) -------------------------------------------------------------------------

Object.assign(FELDNAMEN, { von: 'Von', bis: 'Bis', halber_tag: 'Halber Tag', 'au.festgestellt_am': 'Festgestellt am', 'au.folge': 'Folgebescheinigung',
  'au.arbeitsunfall': 'Arbeitsunfall', anspruch: 'Anspruch', uebertrag: 'Übertrag', datum: 'Datum', jahr: 'Jahr', verfallen: 'Verfallen', tage: 'Arbeitstage', tage_folgejahr: 'Davon im Folgejahr',
  land: 'Bundesland', feiertage: 'Feiertage', monat: 'Monat', tag: 'Tag', abstand: 'Tage ab Ostersonntag', laender: 'Länder', teile: 'In Teilen von', arbeitsort: 'Arbeitsort',
  anteilig: 'Anteiliger Anspruch', rundung: 'Rundung', uebertrag_hoechstens: 'Höchstens', verfall: 'Verfall', verfall_tag: 'Stichtag' });

export const LAENDER = [['BW', 'Baden-Württemberg'], ['BY', 'Bayern'], ['BE', 'Berlin'], ['BB', 'Brandenburg'], ['HB', 'Bremen'], ['HH', 'Hamburg'], ['HE', 'Hessen'],
  ['MV', 'Mecklenburg-Vorpommern'], ['NI', 'Niedersachsen'], ['NW', 'Nordrhein-Westfalen'], ['RP', 'Rheinland-Pfalz'], ['SL', 'Saarland'], ['SN', 'Sachsen'],
  ['ST', 'Sachsen-Anhalt'], ['SH', 'Schleswig-Holstein'], ['TH', 'Thüringen']];

/** Hinweis zum Verfall des Übertrags am Urlaubskonto – als Zeichen und Text; leer, wenn nichts ansteht. */
export function verfallText(z) {
  if (z.verfall_droht > 0) return `▲ ${tageText(z.verfall_droht)} aus dem Vorjahr ${z.verfall_droht === 1 ? 'verfällt' : 'verfallen'} am ${tag(z.verfall_am)}, wenn sie bis dahin nicht genommen sind`;
  if (z.verfall_offen > 0) return `! ${tageText(z.verfall_offen)} aus dem Vorjahr: Stichtag ${tag(z.verfall_am)} vorbei – das Personal entscheidet über den Verfall`;
  return '';
}

/** Tage als Text: 5 → „5“, 2.5 → „2,5“ */
export const tageText = (n) => (n === null || n === undefined ? '' : String(Math.round(n * 10) / 10).replace('.', ','));

/** Zustand einer Abwesenheit als Zeichen und Text – nie nur Farbe. Ohne Art (Sicht des Vorgesetzten) heißt es nur „abwesend“. */
export function abwZustandText(x, heute) {
  if (x.zustand === 'beantragt') return ['beantragt', '▲ beantragt'];
  if (x.zustand === 'abgelehnt') return ['abgelehnt', '✗ abgelehnt'];
  if (x.zustand === 'zurueckgenommen') return ['zurueckgenommen', '– zurückgenommen'];
  if (x.bis < heute) return ['genommen', x.art ? '✓ genommen' : '✓ war abwesend'];
  if (x.von <= heute) return ['laeuft', '● läuft'];
  return ['genehmigt', x.art ? (x.ist_au ? '✓ gemeldet' : '✓ genehmigt') : '✓ geplant'];
}
export function abwZustand(x, heute) {
  const [z, t] = abwZustandText(x, heute);
  return h('span', { 'data-zustand': `abw-${z}`, title: x.grund ? `Grund: ${x.grund}` : '' }, t + (x.grund && (z === 'abgelehnt' || z === 'zurueckgenommen') ? ` – ${x.grund}` : ''));
}

/** Angaben zur Bescheinigung einer AU als Text – mehr führt kumero nicht (nie eine Diagnose). */
export function auText(au) {
  if (!au) return '';
  return [au.folge ? 'Folgebescheinigung' : 'Erstbescheinigung', au.festgestellt_am ? `festgestellt ${tag(au.festgestellt_am)}` : null,
    au.arbeitsunfall ? 'Arbeitsunfall' : null, au.nachweis ? '✓ Nachweis liegt vor' : '! Nachweis fehlt'].filter(Boolean).join(' · ');
}

/**
 * Felder für eine neue Abwesenheit – dieselben beim Eintragen durch das Personal und im Selfservice. Alle Felder stehen
 * immer da; was zur gewählten Art nicht gehört, ist gesperrt und nennt den Grund (F106).
 * o: {id, arten, personen?: [{id, name}], person?: id, art?: id, datumFeld, auswahl, feld, heute, darfAu}
 * Liefert {felder: [Element], json(): Objekt für die API (wirft bei unlesbarem Datum)}.
 */
export function abwesenheitFelder(o) {
  const person = o.personen ? o.auswahl(o.personen.map((p) => [p.id, p.name]), o.person || null, { id: `${o.id}-person` }) : null;
  const art = o.auswahl(o.arten.map((a) => [a.id, a.name]), o.art || null, { id: `${o.id}-art` });
  const von = o.datumFeld({ id: `${o.id}-von`, value: o.von || '' });
  const bis = o.datumFeld({ id: `${o.id}-bis`, value: o.bis || '' });
  const halb = h('input', { type: 'checkbox', id: `${o.id}-halb` });
  const bemerkung = h('input', { id: `${o.id}-bemerkung`, type: 'text', maxlength: '200', size: '44', autocomplete: 'off' });
  const festgestellt = o.datumFeld({ id: `${o.id}-festgestellt` });
  const folge = h('input', { type: 'checkbox', id: `${o.id}-folge` });
  const unfall = h('input', { type: 'checkbox', id: `${o.id}-unfall` });
  const hinweis = h('p', { class: 'pe-klein', id: `${o.id}-hinweis`, role: 'status' }, ' ');
  const gewaehlt = () => o.arten.find((a) => a.id === art.value) || null;
  const halbPruefen = () => {
    const mehr = von.wert() && bis.wert() && von.wert() !== bis.wert();
    halb.disabled = Boolean(mehr); halb.title = mehr ? 'Ein halber Tag ist genau ein Tag' : '';
    if (mehr) halb.checked = false;
  };
  const anpassen = () => {
    const a = gewaehlt();
    const istAu = a?.kennung === 'au';
    const ohneAu = istAu ? (o.darfAu === false ? 'Angaben zur Bescheinigung sind mit deiner Rolle nicht einsehbar' : null) : 'Nur bei einer Arbeitsunfähigkeit';
    festgestellt.sperren(Boolean(ohneAu)); festgestellt.ein.title = ohneAu || '';
    for (const el of [folge, unfall]) { el.disabled = Boolean(ohneAu); el.title = ohneAu || ''; if (ohneAu) el.checked = false; }
    if (ohneAu) festgestellt.setzen('');
    bemerkung.disabled = istAu; bemerkung.title = istAu ? 'Eine Arbeitsunfähigkeit trägt keine Bemerkung – kumero führt keine Angaben zur Erkrankung' : '';
    if (istAu) bemerkung.value = '';
    hinweis.textContent = !a ? ' ' : [a.genehmigung ? 'braucht eine Genehmigung' : 'gilt ohne Genehmigung', a.zaehlt_urlaub ? 'zählt gegen das Urlaubskonto' : 'zählt nicht gegen das Urlaubskonto',
      a.bezahlt ? 'bezahlt' : 'unbezahlt'].join(' · ');
    halbPruefen();
  };
  art.addEventListener('change', anpassen);
  von.ein.addEventListener('change', () => { if (von.wert() && (!bis.wert() || bis.wert() < von.wert())) bis.setzen(von.wert()); halbPruefen(); });
  bis.ein.addEventListener('change', halbPruefen);
  anpassen();
  const haken = (el, text) => h('label', { class: 'pe-haken' }, el, ' ', text);
  return {
    felder: [
      h('div', { class: 'pe-reihe' }, ...(person ? [o.feld('Person', person)] : []), o.feld('Art', art)), hinweis,
      h('div', { class: 'pe-reihe' }, o.feld('Von', von.el), o.feld('Bis (bei AU: voraussichtlich)', bis.el), haken(halb, 'halber Tag')),
      o.feld('Bemerkung', bemerkung, 'keine Gesundheitsangaben'),
      h('fieldset', { class: 'pe-gruppe', id: `${o.id}-au` }, h('legend', {}, 'Bescheinigung der Arbeitsunfähigkeit – ohne Diagnose'),
        h('div', { class: 'pe-reihe' }, o.feld('Ärztlich festgestellt am', festgestellt.el), haken(folge, 'Folgebescheinigung'), haken(unfall, 'Arbeitsunfall')))],
    json: () => {
      const v = von.wert(); const b = bis.wert(); const f = festgestellt.wert();
      if (v === null || b === null || f === null) throw new Error('Ein Datum ist nicht lesbar');
      if (!v || !b) throw new Error('Von und Bis gehören dazu');
      const a = gewaehlt();
      return { ...(person ? { person: person.value } : {}), art: art.value, von: v, bis: b, halber_tag: halb.checked, bemerkung: bemerkung.value.trim() || null,
        ...(a?.kennung === 'au' && o.darfAu !== false ? { au: { festgestellt_am: f || null, folge: folge.checked, arbeitsunfall: unfall.checked } } : {}) };
    },
  };
}
