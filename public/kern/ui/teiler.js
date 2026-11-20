// SPDX-License-Identifier: AGPL-3.0-or-later
// Zweiteilung einer Seite mit verschiebbarem Teiler (F137): oben die Liste, unten das Gewählte. Ein gemeinsamer
// Baustein, nicht je Seite neu. Der Teiler bewegt sich nur, wenn der Nutzer ihn bewegt (F106 gilt weiter); die
// gewählte Höhe merkt sich der Browser je Seite. Bedienbar mit Maus, Finger und Tastatur.
import { h } from './bausteine.js';

/**
 * oben, unten: Elemente. o: {id (Merkname, Pflicht), hoehe (Vorgabe für unten in px, 240), min (kleinste Höhe je Teil, 96)}.
 * Liefert das Element der Zweiteilung.
 */
export function teiler(oben, unten, o) {
  const vorgabe = o.hoehe || 240;
  const min = o.min || 96;
  const merkname = `kumero.teiler.${o.id}`;
  let hoehe = vorgabe;
  try { const g = Number(localStorage.getItem(merkname)); if (Number.isFinite(g) && g >= min) hoehe = g; } catch { /* ohne Speicher gilt die Vorgabe */ }

  const teilOben = h('div', { class: 'teil-oben' }, oben);
  const teilUnten = h('div', { class: 'teil-unten' }, unten);
  const griff = h('div', { class: 'teiler-griff nicht-drucken', role: 'separator', tabindex: '0', 'aria-orientation': 'horizontal', id: `${o.id}-teiler`,
    'aria-label': 'Teiler zwischen Liste und Einzelheiten', title: 'Ziehen, um die Aufteilung zu ändern · Pfeiltasten · Doppelklick: Vorgabe' });
  const el = h('div', { class: 'teilung', id: o.id }, teilOben, griff, teilUnten);

  const grenze = () => Math.max(min, el.clientHeight - min - griff.offsetHeight);
  function setzen(px, merken = true) {
    hoehe = Math.round(Math.min(Math.max(px, min), el.clientHeight ? grenze() : px));
    teilUnten.style.height = `${hoehe}px`;
    griff.setAttribute('aria-valuenow', String(hoehe));
    griff.setAttribute('aria-valuemin', String(min));
    if (el.clientHeight) griff.setAttribute('aria-valuemax', String(grenze()));
    if (merken) { try { localStorage.setItem(merkname, String(hoehe)); } catch { /* dann eben nur für diese Sitzung */ } }
  }
  let start = null;
  griff.addEventListener('pointerdown', (ev) => {
    if (ev.button !== 0) return;
    start = { y: ev.clientY, hoehe };
    griff.setPointerCapture(ev.pointerId);
    griff.classList.add('zieht');
    ev.preventDefault();
  });
  griff.addEventListener('pointermove', (ev) => { if (start) setzen(start.hoehe + (start.y - ev.clientY), false); });
  const ende = () => { if (!start) return; start = null; griff.classList.remove('zieht'); setzen(hoehe); };
  griff.addEventListener('pointerup', ende);
  griff.addEventListener('pointercancel', ende);
  griff.addEventListener('dblclick', () => setzen(vorgabe));
  griff.addEventListener('keydown', (ev) => {
    const schritt = ev.shiftKey ? 64 : 16;
    if (ev.key === 'ArrowUp') setzen(hoehe + schritt);
    else if (ev.key === 'ArrowDown') setzen(hoehe - schritt);
    else if (ev.key === 'Home') setzen(grenze());
    else if (ev.key === 'End') setzen(min);
    else return;
    ev.preventDefault();
  });
  setzen(hoehe, false);
  // Wird das Fenster kleiner, bleibt oben die Mindesthöhe
  if (typeof ResizeObserver === 'function') new ResizeObserver(() => { if (el.clientHeight && hoehe > grenze()) setzen(grenze(), false); }).observe(el);
  return el;
}
