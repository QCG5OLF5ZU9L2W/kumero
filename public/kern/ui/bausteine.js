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

/** Meldungsleiste: setzen(text, art) mit art = '' | 'fehler' | 'erfolg' | 'warnung' */
export function meldungsleiste() {
  const e = h('div', { class: 'meldung', role: 'status', hidden: true });
  e.setzen = (text, art = '') => {
    e.hidden = !text;
    e.className = ['meldung', art].filter(Boolean).join(' ');
    e.textContent = text || '';
  };
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
    h('div', { class: 'liste-fuss' }, o.fuss ?? `${zeilen.length} Einträge`));
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

/** Datum/Zeit kurz deutsch */
export function zeit(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? String(iso) : d.toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' });
}

export const KONTOARTEN = [['aktiv', 'Aktiv'], ['passiv', 'Passiv'], ['aufwand', 'Aufwand'], ['ertrag', 'Ertrag']];
