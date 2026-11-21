// SPDX-License-Identifier: AGPL-3.0-or-later
// Sortierbare Spalten und Filter je Spalte für Tabellen (F91) – ein gemeinsamer Baustein, nicht je Seite neu.
// Filter wie in Paperless: einen Wert finden (＋) oder ausschließen (−); Aufbau wie in Excel: am Spaltenkopf,
// mit der Liste der vorkommenden Werte. Zustände tragen Zeichen und Text, nie nur Farbe.
// Spalten sind verschiebbar (F146): die Breite am rechten Rand des Spaltenkopfs, die Reihenfolge durch Ziehen des
// Titels – beides auch mit der Tastatur, gemerkt je Browser und Tabelle. Nichts verschiebt sich von selbst (F106).
import { h } from './bausteine.js';

const LEER = '(leer)';

/**
 * spalten: [{id, titel, wert?: (zeile) => text, sortwert?: (zeile) => zahl|text, klasse?, fest?: true, hinweis?: text}]
 *   wert fehlt oder fest: Spalte ohne Sortieren und Filter (z. B. Markierung, Eingabefelder).
 * o: {id} – Merkname der Tabelle. Mit ihm sind die Spalten verschiebbar (Breite und Reihenfolge, F146); dann braucht
 *   jede Spalte eine id, und die Seite zeichnet ihre Zellen in der Reihenfolge von spalten().
 * Liefert {kopf, spalten(), anwenden(zeilen), setzeZeilen(zeilen), aktiv(), aufheben(), entfernen()}.
 */
export function tabellenfilter(spalten, beiAenderung, o = {}) {
  const merkname = o.id ? `kumero.tabelle.${o.id}` : null;
  const SCHMAL = 48; const BREIT = 960;
  const vorgabe = spalten.map((s) => s.id);
  let reihenfolge = [...vorgabe];
  let breiten = null;                // null: Breiten nach Inhalt (Vorgabe); sonst Spalte → px, für alle Spalten
  if (merkname) {
    try {
      const g = JSON.parse(localStorage.getItem(merkname) || '{}');
      if (Array.isArray(g.reihenfolge)) reihenfolge = [...g.reihenfolge.filter((id) => vorgabe.includes(id)), ...vorgabe.filter((id) => !g.reihenfolge.includes(id))];
      if (g.breiten && typeof g.breiten === 'object') breiten = new Map(vorgabe.map((id) => [id, Number.isFinite(g.breiten[id]) ? Math.min(Math.max(g.breiten[id], SCHMAL), BREIT) : 120]));
    } catch { /* ohne Speicher gilt die Vorgabe */ }
  }
  const merken = () => { if (merkname) { try { localStorage.setItem(merkname, JSON.stringify({ reihenfolge, breiten: breiten ? Object.fromEntries(breiten) : null })); } catch { /* dann eben nur für diese Sitzung */ } } };
  const veraendert = () => breiten !== null || reihenfolge.some((id, i) => id !== vorgabe[i]);
  const thVon = new Map();
  let kopf = null;
  let geklickt = true;               // false unmittelbar nach dem Ziehen: Der Klick sortiert dann nicht

  function breitenAnwenden() {
    for (const [id, th] of thVon) th.style.width = breiten ? `${breiten.get(id)}px` : '';
    kopf.classList.toggle('sp-fest', breiten !== null);
  }
  /** Hält die Breiten fest, wie sie gerade sind – erst danach lässt sich eine einzelne ändern, ohne dass sich andere bewegen */
  function einfrieren() {
    if (breiten) return true;
    const gemessen = vorgabe.map((id) => [id, Math.round(thVon.get(id).getBoundingClientRect().width)]);
    if (gemessen.some(([, b]) => b === 0)) return false;
    breiten = new Map(gemessen);
    breitenAnwenden();
    return true;
  }
  function breiteSetzen(id, px) {
    if (!einfrieren()) return;
    breiten.set(id, Math.round(Math.min(Math.max(px, SCHMAL), BREIT)));
    breitenAnwenden();
  }
  function ordnen(neu, fokus) {
    reihenfolge = neu;
    kopf.replaceChildren(...reihenfolge.map((id) => thVon.get(id)));
    merken();
    beiAenderung();
    fokus?.focus();
  }
  function verschieben(id, vorId) {
    if (id === vorId) return;
    const neu = reihenfolge.filter((x) => x !== id);
    neu.splice(vorId === null ? neu.length : neu.indexOf(vorId), 0, id);
    if (neu.some((x, i) => x !== reihenfolge[i])) ordnen(neu);
  }
  function zuruecksetzen() {
    breiten = null;
    breitenAnwenden();
    ordnen([...vorgabe]);
  }
  /** Macht den Kopf einer Spalte verschiebbar: griff = Titel zum Ziehen und für die Tasten */
  function beweglich(s, th, griff) {
    thVon.set(s.id, th);
    if (!merkname) return;
    th.classList.add('sp-beweglich');
    const rand = h('span', { class: 'sp-griff nicht-drucken', 'aria-hidden': 'true', title: 'Ziehen: Breite der Spalte · Doppelklick: Breiten nach Vorgabe' });
    th.append(rand);
    let start = null;
    rand.addEventListener('pointerdown', (ev) => {
      if (ev.button !== 0 || !einfrieren()) return;
      start = { x: ev.clientX, b: breiten.get(s.id) };
      rand.setPointerCapture(ev.pointerId);
      rand.classList.add('zieht');
      ev.preventDefault(); ev.stopPropagation();
    });
    rand.addEventListener('pointermove', (ev) => { if (start) breiteSetzen(s.id, start.b + (ev.clientX - start.x)); });
    const ende = () => { if (!start) return; start = null; rand.classList.remove('zieht'); merken(); };
    rand.addEventListener('pointerup', ende);
    rand.addEventListener('pointercancel', ende);
    rand.addEventListener('click', (ev) => ev.stopPropagation());
    rand.addEventListener('dblclick', (ev) => { ev.stopPropagation(); breiten = null; breitenAnwenden(); merken(); });
    if (!griff) return;
    griff.classList.add('sp-zug');
    griff.title = `${griff.title ? `${griff.title} · ` : ''}Ziehen: Spalte verschieben · Strg+←/→: verschieben · Umschalt+←/→: Breite`;
    let zug = null;
    const ziel = (x) => {
      const vor = reihenfolge.find((id) => { const r = thVon.get(id).getBoundingClientRect(); return x < r.left + r.width / 2; });
      return vor === undefined ? null : vor;
    };
    const marken = (vor) => {
      for (const t of thVon.values()) t.classList.remove('sp-ziel-vor', 'sp-ziel-nach');
      if (vor === undefined) return;
      if (vor === null) thVon.get(reihenfolge[reihenfolge.length - 1]).classList.add('sp-ziel-nach'); else thVon.get(vor).classList.add('sp-ziel-vor');
    };
    const zugEnde = () => { zug = null; marken(undefined); th.classList.remove('sp-gezogen'); };
    griff.addEventListener('pointerdown', (ev) => { if (ev.button === 0) { zug = { x: ev.clientX, aktiv: false }; griff.setPointerCapture(ev.pointerId); } });
    griff.addEventListener('pointermove', (ev) => {
      if (!zug) return;
      if (!zug.aktiv) { if (Math.abs(ev.clientX - zug.x) < 8) return; zug.aktiv = true; th.classList.add('sp-gezogen'); }
      marken(ziel(ev.clientX));
    });
    griff.addEventListener('pointerup', (ev) => {
      if (!zug) return;
      const aktiv = zug.aktiv;
      zugEnde();
      if (!aktiv) return;
      geklickt = false; setTimeout(() => { geklickt = true; }, 0);
      verschieben(s.id, ziel(ev.clientX));
    });
    griff.addEventListener('pointercancel', zugEnde);
    griff.addEventListener('keydown', (ev) => {
      const links = ev.key === 'ArrowLeft'; const rechts = ev.key === 'ArrowRight';
      const strg = ev.ctrlKey || ev.metaKey;
      if (ev.shiftKey && (links || rechts)) {
        if (einfrieren()) { breiteSetzen(s.id, breiten.get(s.id) + (rechts ? 1 : -1) * (strg ? 64 : 16)); merken(); }
      } else if (ev.shiftKey && (ev.key === 'Home' || ev.key === 'End')) {
        breiteSetzen(s.id, ev.key === 'Home' ? SCHMAL : BREIT); merken();
      } else if (strg && (links || rechts)) {
        const i = reihenfolge.indexOf(s.id); const j = i + (rechts ? 1 : -1);
        if (j >= 0 && j < reihenfolge.length) { const neu = [...reihenfolge]; [neu[i], neu[j]] = [neu[j], neu[i]]; ordnen(neu, griff); }
      } else return;
      ev.preventDefault();
    });
  }
  const filter = new Map();          // Spalte → {ein: Set, aus: Set}
  let sortierung = null;             // {id, ab}
  let alle = [];
  const text = (s, z) => { const w = s.wert(z); return w === null || w === undefined || w === '' ? LEER : String(w); };
  const stand = (id) => filter.get(id) || { ein: new Set(), aus: new Set() };
  const gefiltert = (id) => { const f = filter.get(id); return !!f && (f.ein.size > 0 || f.aus.size > 0); };

  const koepfe = spalten.map((s) => {
    if (!s.wert || s.fest) {
      const fester = merkname && typeof s.titel === 'string' ? h('span', { class: 'sp-titel-fest', tabindex: '0' }, s.titel) : null;
      const th = h('th', { class: s.klasse || '', title: s.hinweis || null }, fester || s.titel);
      beweglich(s, th, fester);
      return th;
    }
    const zeichen = h('span', { class: 'sp-sort', 'aria-hidden': 'true' });
    const titel = h('button', { type: 'button', class: 'sp-titel', title: 'Sortieren', onclick: () => {
      if (!geklickt) return;
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
        h('button', { type: 'button', class: 'knopf klein sp-aufheben', disabled: !gefiltert(s.id), onclick: () => { filter.delete(s.id); zeichnen(); menueZeichnen(); beiAenderung(); } }, 'Filter aufheben'),
        // Steht immer da (F106); gesperrt, solange Breiten und Reihenfolge der Vorgabe entsprechen
        merkname ? h('button', { type: 'button', class: 'knopf klein sp-vorgabe', disabled: !veraendert(), title: veraendert() ? 'Breiten und Reihenfolge aller Spalten dieser Tabelle wie ausgeliefert' : 'Breiten und Reihenfolge entsprechen der Vorgabe',
          onclick: () => { zuruecksetzen(); menueZeichnen(); } }, 'Spalten nach Vorgabe') : null].filter(Boolean));
    }
    const th = h('th', { class: ['sp-kopf', s.klasse || ''], title: s.hinweis || null }, h('span', { class: 'sp-zeile' }, titel, klappe));
    beweglich(s, th, titel);
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

  kopf = h('tr', {}, merkname ? reihenfolge.map((id) => thVon.get(id)) : koepfe);
  if (merkname) breitenAnwenden();
  const nachId = new Map(spalten.map((s) => [s.id, s]));
  return {
    kopf,
    spalten: () => (merkname ? reihenfolge.map((id) => nachId.get(id)) : spalten),
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
