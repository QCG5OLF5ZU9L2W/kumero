// SPDX-License-Identifier: AGPL-3.0-or-later
// Gemeinsame UI-Bausteine des Kerns (OBERFLAECHE.md 5, F80 N5): einheitliches Bild für alle Module.
// Keine style-Attribute (strenge CSP) – Aussehen nur über Klassen in rahmen.css.

/**
 * Element bauen: h('button', {class: 'primaer', onclick: f}, 'Text', kind, …)
 * Attribute mit on… werden Ereignisse; false/null/undefined werden weggelassen.
 */
export function h(tag, attr = {}, ...kinder) {
  const e = document.createElement(tag);
  for (const [k, w] of Object.entries(attr || {})) {
    if (w === false || w === null || w === undefined) continue;
    if (k.startsWith('on') && typeof w === 'function') e.addEventListener(k.slice(2), w);
    else if (k === 'class') e.className = Array.isArray(w) ? w.filter(Boolean).join(' ') : w;
    else if (k === 'style') throw new Error('style-Attribute sind wegen der CSP nicht erlaubt');
    else if (k in e && typeof w !== 'string') e[k] = w;
    else e.setAttribute(k, w === true ? '' : String(w));
  }
  einfuegen(e, kinder);
  return e;
}

function einfuegen(e, kinder) {
  for (const k of kinder.flat(Infinity)) {
    if (k === null || k === undefined || k === false) continue;
    e.append(k instanceof Node ? k : document.createTextNode(String(k)));
  }
}

/** Seitenkopf mit Titel und optionaler Werkzeugleiste (Sage-Stil) */
export function seitenkopf(titel, ...werkzeuge) {
  return h('header', { class: 'seitenkopf' }, h('h1', {}, titel), werkzeuge.length ? h('div', { class: 'werkzeuge' }, werkzeuge) : null);
}

export function knopf(text, onclick, art = '', attr = {}) {
  return h('button', { type: 'button', class: ['knopf', art], onclick, ...attr }, text);
}

/**
 * Sperrt oder entsperrt einen Knopf, ohne ihn auszublenden (F106: keine dynamische Oberfläche). Ein gesperrter Knopf
 * nennt beim Überfahren den Grund; frei zeigt er wieder seine gewöhnliche Hilfe. grund: Text oder null/'' für frei.
 */
export function sperren(el, grund) {
  if (!el) return;
  if (el.dataset.hilfe === undefined) el.dataset.hilfe = el.title || '';
  el.disabled = Boolean(grund);
  el.title = grund ? `${grund}` : el.dataset.hilfe;
  el.hidden = false;
}

/** Meldungsleiste: setzen(text, art) mit art = '' | 'fehler' | 'erfolg' | 'warnung' */
export function meldungsleiste() {
  const e = h('div', { class: 'meldung', role: 'status', hidden: true });
  e.setzen = (text, art = '') => {
    e.hidden = !text;
    e.className = ['meldung', art].filter(Boolean).join(' ');
    e.textContent = text || '';
    e.title = text || '';   // in der Statusleiste steht eine Zeile; der ganze Text beim Überfahren und nach einem Klick
    e.classList.remove('offen');
  };
  e.addEventListener('click', () => e.classList.toggle('offen'));
  e.fehler = (f) => {
    if (f?.name === 'NotAllowedError') return e.setzen('Vom Browser oder Passkey abgebrochen.', 'fehler');
    const felder = f?.felder;
    e.setzen((f?.message || String(f)) + (felder ? ` (${felder})` : ''), 'fehler');
  };
  return e;
}

/** Kennzahl-Kachel für Prüfberichte */
export function kachel(wert, text, art = '') {
  return h('div', { class: ['kachel', art] }, h('strong', {}, String(wert)), h('span', {}, text));
}

/** Reiter (Filter), z. B. Alle / Zu bestätigen / Offen. reiter: [{id, text, anzahl}] */
export function reiterleiste(reiter, aktiv, wechsel) {
  return h('div', { class: 'reiter', role: 'tablist' }, reiter.map((r) => h('button', {
    type: 'button', role: 'tab', 'aria-selected': r.id === aktiv ? 'true' : 'false', class: r.id === aktiv ? 'aktiv' : '',
    onclick: () => wechsel(r.id),
  }, r.text, r.anzahl !== undefined ? h('span', { class: 'anzahl' }, String(r.anzahl)) : null)));
}

/**
 * Tabelle im Stil der Sage-Listen: fester Kopf, Zeilenauswahl, Pfeiltasten, Enter öffnet.
 * spalten: [{titel, wert: (zeile) => Node|string, klasse?}]
 * o: {zeilen, oeffnen?(zeile), leer?: string, zeilenklasse?(zeile), fuss?: string}
 */
export function tabelle(spalten, o) {
  const tbody = h('tbody');
  const zeilen = o.zeilen || [];
  if (zeilen.length === 0) {
    tbody.append(h('tr', { class: 'leer' }, h('td', { colspan: spalten.length }, o.leer || 'Keine Einträge')));
  }
  for (const z of zeilen) {
    const tr = h('tr', { tabindex: '-1', class: o.zeilenklasse ? o.zeilenklasse(z) : '' },
      spalten.map((s) => h('td', { class: s.klasse || '' }, s.wert(z))));
    tr.addEventListener('click', (ev) => { auswaehlen(tr); if (o.oeffnen && ev.detail === 2) o.oeffnen(z); });
    tr.addEventListener('keydown', (ev) => {
      if (ev.target !== tr) return;
      if (ev.key === 'ArrowDown' && tr.nextElementSibling) { ev.preventDefault(); auswaehlen(tr.nextElementSibling, true); }
      if (ev.key === 'ArrowUp' && tr.previousElementSibling) { ev.preventDefault(); auswaehlen(tr.previousElementSibling, true); }
      if (ev.key === 'Enter' && o.oeffnen) { ev.preventDefault(); o.oeffnen(z); }
    });
    tbody.append(tr);
  }
  function auswaehlen(tr, fokus = false) {
    tbody.querySelectorAll('tr.gewaehlt').forEach((x) => x.classList.remove('gewaehlt'));
    tr.classList.add('gewaehlt');
    if (fokus) tr.focus();
  }
  const tabelle = h('table', { class: 'liste' },
    h('thead', {}, h('tr', {}, spalten.map((s) => h('th', { class: s.klasse || '' }, s.titel)))), tbody);
  return h('div', { class: 'liste-rahmen' }, h('div', { class: 'liste-scroll' }, tabelle),
    h('div', { class: 'liste-fuss' }, o.fuss ?? (zeilen.length === 1 ? "1 Eintrag" : `${zeilen.length} Einträge`)));
}

/** Feld mit Beschriftung */
export function feld(beschriftung, eingabe, hinweis = '') {
  return h('label', { class: 'feld' }, h('span', { class: 'beschriftung' }, beschriftung), eingabe,
    hinweis ? h('span', { class: 'hinweis' }, hinweis) : null);
}

export function auswahl(optionen, wert, attr = {}) {
  const s = h('select', attr, optionen.map(([w, t]) => h('option', { value: w }, t)));
  if (wert !== undefined && wert !== null) s.value = wert;
  return s;
}

/** Modaler Dialog; liefert Promise<boolean> (true = bestätigt) */
export function bestaetigen(titel, text, ja = 'OK', nein = 'Abbrechen') {
  return new Promise((fertig) => {
    const d = h('dialog', { class: 'dialog' },
      h('h2', {}, titel), h('p', {}, text),
      h('div', { class: 'dialog-knoepfe' },
        knopf(nein, () => { d.close(); fertig(false); }),
        knopf(ja, () => { d.close(); fertig(true); }, 'primaer')));
    d.addEventListener('close', () => d.remove());
    document.body.append(d);
    d.showModal();
  });
}

/**
 * Umgebung der Anzeige, vom Rahmen gesetzt. lokal: Sprache für Datum und Zeit – die Sprache des
 * Nutzers aus seinem Profil, sonst die des Browsers (PROFIL.md U5, BUCHUNGSMASKE.md M11).
 */
export const umgebung = { lokal: navigator.language || 'de-DE', texte: 'de-DE' };
// texte: Sprache der Texte – solange es nur deutsche Texte gibt, sind auch Wochentage deutsch

/** Datum und Zeit kurz */
export function zeit(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? String(iso) : d.toLocaleString(umgebung.lokal, { dateStyle: 'medium', timeStyle: 'short' });
}

const datumTeile = (iso) => /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
const istoDatum = (j, m, t) => `${String(j).padStart(4, '0')}-${String(m).padStart(2, '0')}-${String(t).padStart(2, '0')}`;
function datumformat() {
  return new Intl.DateTimeFormat(umgebung.lokal, { day: '2-digit', month: '2-digit', year: 'numeric' });
}

/** Datum (YYYY-MM-DD aus der API) in der Schreibweise der Umgebung, z. B. 17.11.2026 oder 11/17/2026 */
export function datumText(iso) {
  const m = datumTeile(iso);
  return m ? datumformat().format(new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))) : '';
}

export function wochentag(iso) {
  const m = datumTeile(iso);
  return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])).toLocaleDateString(umgebung.texte, { weekday: 'long' }) : '';
}

export function heute() {
  const d = new Date();
  return istoDatum(d.getFullYear(), d.getMonth() + 1, d.getDate());
}

/**
 * Eingabe eines Datums in der Schreibweise der Umgebung lesen. Verkürzt geht auch:
 * nur der Tag (17), Tag und Monat (17.11. oder 1711), zweistelliges Jahr (17.11.26, 171126).
 * Die Reihenfolge von Tag, Monat, Jahr folgt der Umgebung. Liefert YYYY-MM-DD, '' für leer, null wenn ungültig.
 */
export function datumLesen(text) {
  const s = String(text || '').trim();
  if (s === '') return '';
  const folge = datumformat().formatToParts(new Date(2001, 1, 3)).filter((x) => ['day', 'month', 'year'].includes(x.type)).map((x) => x.type);
  const ohneJahr = folge.filter((x) => x !== 'year');
  let teile = s.split(/\D+/).filter(Boolean);
  if (teile.length === 1 && /^\d+$/.test(s)) {
    const z = teile[0];
    if (z.length <= 2) teile = [z];
    else if (z.length === 4) teile = [z.slice(0, 2), z.slice(2)];
    else if (z.length === 6) teile = [z.slice(0, 2), z.slice(2, 4), z.slice(4)];
    else if (z.length === 8) teile = folge[0] === 'year' ? [z.slice(0, 4), z.slice(4, 6), z.slice(6)] : [z.slice(0, 2), z.slice(2, 4), z.slice(4)];
    else return null;
  }
  const jetzt = new Date();
  const w = { day: jetzt.getDate(), month: jetzt.getMonth() + 1, year: jetzt.getFullYear() };
  if (teile.length === 1) w.day = Number(teile[0]);
  else if (teile.length === 2) ohneJahr.forEach((art, i) => { w[art] = Number(teile[i]); });
  else if (teile.length === 3) folge.forEach((art, i) => { w[art] = Number(teile[i]); });
  else return null;
  if (w.year < 100) w.year += 2000;
  const d = new Date(w.year, w.month - 1, w.day);
  if (w.year < 1900 || w.year > 2999 || d.getFullYear() !== w.year || d.getMonth() !== w.month - 1 || d.getDate() !== w.day) return null;
  return istoDatum(w.year, w.month, w.day);
}

/**
 * Datumsfeld (BUCHUNGSMASKE.md M15): ein Halt für die Eingabe, der nächste Halt ist der Kalender.
 * Eingabe in der Schreibweise der Umgebung, auch verkürzt; Pfeil hoch/runter ändert den Tag.
 * Liefert {el, ein, wert(): ISO | '' | null, setzen(iso), sperren(an)}; feuert „change“ auf `ein`.
 */
export function datumFeld(attr = {}, o = {}) {
  const muster = datumText('2026-12-31').replace('31', 'TT').replace('12', 'MM').replace('2026', 'JJJJ');
  const { value: anfang, ...rest } = attr;
  const ein = h('input', { class: 'datum', inputmode: 'numeric', autocomplete: 'off', spellcheck: false, placeholder: muster, ...rest });
  // Der Kalender des Browsers hinter einem Knopf; das Feld selbst liegt nicht in der Tab-Folge
  const kalender = h('input', { type: 'date', class: 'datum-kalender', tabindex: '-1', 'aria-hidden': 'true' });
  const knopfKalender = h('button', { type: 'button', class: 'datum-knopf', title: o.kalenderTitel || 'Kalender', 'aria-label': o.kalenderTitel || 'Kalender',
    onclick: () => {
      kalender.value = datumLesen(ein.value) || heute();
      if (typeof kalender.showPicker === 'function') { try { kalender.showPicker(); return; } catch { /* dann eben Fokus */ } }
      kalender.focus();
    } }, '▦');
  const hinweis = h('span', { class: 'feldhinweis' });
  const setzen = (iso) => { ein.value = datumText(iso); ein.dataset.wert = iso || ''; hinweis.textContent = ''; };
  const melden = () => ein.dispatchEvent(new Event('change', { bubbles: true }));
  let intern = false;
  ein.addEventListener('change', () => {
    if (intern) return;
    const w = datumLesen(ein.value);
    hinweis.textContent = w === null ? (o.ungueltig || `${muster}?`) : '';
    if (w !== null) { ein.value = datumText(w); ein.dataset.wert = w; }
  });
  ein.addEventListener('keydown', (ev) => {
    if (ev.key !== 'ArrowUp' && ev.key !== 'ArrowDown') return;
    const w = datumLesen(ein.value) || heute();
    const m = datumTeile(w);
    const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]) + (ev.key === 'ArrowUp' ? 1 : -1));
    ev.preventDefault();
    setzen(istoDatum(d.getFullYear(), d.getMonth() + 1, d.getDate()));
    intern = true; melden(); intern = false;
  });
  kalender.addEventListener('change', () => {
    if (!kalender.value) return;
    setzen(kalender.value);
    intern = true; melden(); intern = false;
    ein.focus();
  });
  if (anfang) setzen(anfang);
  return {
    el: h('span', { class: 'datumfeld' }, ein, knopfKalender, kalender, hinweis), ein, knopf: knopfKalender,
    wert: () => datumLesen(ein.value), setzen,
    sperren: (an) => { ein.disabled = an; knopfKalender.disabled = an; },
  };
}

export const KONTOARTEN = [['aktiv', 'Aktiv'], ['passiv', 'Passiv'], ['aufwand', 'Aufwand'], ['ertrag', 'Ertrag']];
