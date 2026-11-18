// SPDX-License-Identifier: AGPL-3.0-or-later
// Sortierbare Spalten und Filter je Spalte für Tabellen (F91) – ein gemeinsamer Baustein, nicht je Seite neu.
// Filter wie in Paperless: einen Wert finden (＋) oder ausschließen (−); Aufbau wie in Excel: am Spaltenkopf,
// mit der Liste der vorkommenden Werte. Zustände tragen Zeichen und Text, nie nur Farbe.
import { h } from './bausteine.js';

const LEER = '(leer)';

/**
 * spalten: [{id, titel, wert?: (zeile) => text, sortwert?: (zeile) => zahl|text, klasse?, fest?: true}]
 *   wert fehlt oder fest: Spalte ohne Sortieren und Filter (z. B. Markierung, Eingabefelder).
 * Liefert {kopf, anwenden(zeilen), setzeZeilen(zeilen), aktiv(), aufheben()}.
 */
export function tabellenfilter(spalten, beiAenderung) {
  const filter = new Map();          // Spalte → {ein: Set, aus: Set}
  let sortierung = null;             // {id, ab}
  let alle = [];
  const text = (s, z) => { const w = s.wert(z); return w === null || w === undefined || w === '' ? LEER : String(w); };
  const stand = (id) => filter.get(id) || { ein: new Set(), aus: new Set() };
  const gefiltert = (id) => { const f = filter.get(id); return !!f && (f.ein.size > 0 || f.aus.size > 0); };

  const koepfe = spalten.map((s) => {
    if (!s.wert || s.fest) return h('th', { class: s.klasse || '' }, s.titel);
    const zeichen = h('span', { class: 'sp-sort', 'aria-hidden': 'true' });
    const titel = h('button', { type: 'button', class: 'sp-titel', title: 'Sortieren', onclick: () => {
      sortierung = sortierung?.id !== s.id ? { id: s.id, ab: false } : (sortierung.ab ? null : { id: s.id, ab: true });
      zeichnen(); beiAenderung();
    } }, s.titel, zeichen);
    const marke = h('span', { class: 'sp-marke' });
    const menue = h('div', { class: 'sp-menue' });
    const klappe = h('details', { class: 'sp-filter', 'data-spalte': s.id }, h('summary', { title: 'Filtern: Wert finden oder ausschließen', 'aria-label': `Filter ${s.titel}` }, '⏷', marke), menue);
    klappe.addEventListener('toggle', () => { if (klappe.open) menueZeichnen(); });
    function menueZeichnen() {
      const f = stand(s.id);
      const werte = [...new Set(alle.map((z) => text(s, z)))].sort((a, b) => a.localeCompare(b, 'de', { numeric: true }));
      const suche = h('input', { type: 'search', placeholder: 'Werte suchen', 'aria-label': 'Werte suchen' });
      const zeilen = werte.map((w) => {
        const zustand = f.ein.has(w) ? 'ein' : (f.aus.has(w) ? 'aus' : '');
        const setzen = (neu) => {
          const g = stand(s.id);
          g.ein.delete(w); g.aus.delete(w);
          if (neu && neu !== zustand) g[neu].add(w);
          filter.set(s.id, g);
          zeichnen(); menueZeichnen(); beiAenderung();
        };
        return h('li', { 'data-wert': w.toLowerCase(), class: zustand ? `sp-${zustand}` : '' },
          h('button', { type: 'button', class: 'sp-ein', title: 'Diesen Wert finden', 'aria-pressed': zustand === 'ein' ? 'true' : 'false', onclick: () => setzen('ein') }, '＋'),
          h('button', { type: 'button', class: 'sp-aus', title: 'Diesen Wert ausschließen', 'aria-pressed': zustand === 'aus' ? 'true' : 'false', onclick: () => setzen('aus') }, '−'),
          h('span', { class: 'sp-wert' }, w), zustand ? h('span', { class: 'sp-zustand' }, zustand === 'ein' ? 'finden' : 'ausgeschlossen') : null);
      });
      suche.addEventListener('input', () => { const q = suche.value.trim().toLowerCase(); zeilen.forEach((li) => { li.hidden = q !== '' && !li.dataset.wert.includes(q); }); });
      menue.replaceChildren(...[werte.length > 8 ? suche : null, h('ul', {}, zeilen),
        h('button', { type: 'button', class: 'knopf klein sp-aufheben', disabled: !gefiltert(s.id), onclick: () => { filter.delete(s.id); zeichnen(); menueZeichnen(); beiAenderung(); } }, 'Filter aufheben')].filter(Boolean));
    }
    const th = h('th', { class: ['sp-kopf', s.klasse || ''] }, h('span', { class: 'sp-zeile' }, titel, klappe));
    th.zeichnen = () => {
      zeichen.textContent = sortierung?.id === s.id ? (sortierung.ab ? ' ▼' : ' ▲') : '';
      th.setAttribute('aria-sort', sortierung?.id === s.id ? (sortierung.ab ? 'descending' : 'ascending') : 'none');
      marke.textContent = gefiltert(s.id) ? ' gefiltert' : '';
      klappe.classList.toggle('sp-aktiv', gefiltert(s.id));
    };
    return th;
  });
  const zeichnen = () => koepfe.forEach((th) => th.zeichnen?.());
  zeichnen();
  // Klick außerhalb schließt offene Filter
  const schliessen = (ev) => koepfe.forEach((th) => { const d = th.querySelector('details[open]'); if (d && !ev.composedPath().includes(d)) d.open = false; });
  document.addEventListener('click', schliessen);

  return {
    kopf: h('tr', {}, koepfe),
    setzeZeilen: (zeilen) => { alle = zeilen; },
    anwenden: (zeilen) => {
      let erg = zeilen.filter((z) => spalten.every((s) => {
        const f = filter.get(s.id);
        if (!f || !s.wert) return true;
        const w = text(s, z);
        return (f.ein.size === 0 || f.ein.has(w)) && !f.aus.has(w);
      }));
      if (sortierung) {
        const s = spalten.find((x) => x.id === sortierung.id);
        const wert = s.sortwert || ((z) => text(s, z));
        erg = [...erg].sort((a, b) => {
          const x = wert(a); const y = wert(b);
          const v = typeof x === 'number' && typeof y === 'number' ? x - y : String(x ?? '').localeCompare(String(y ?? ''), 'de', { numeric: true });
          return sortierung.ab ? -v : v;
        });
      }
      return erg;
    },
    aktiv: () => spalten.some((s) => gefiltert(s.id)),
    aufheben: () => { filter.clear(); zeichnen(); beiAenderung(); },
    entfernen: () => document.removeEventListener('click', schliessen),
  };
}
