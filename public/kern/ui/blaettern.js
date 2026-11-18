// SPDX-License-Identifier: AGPL-3.0-or-later
// Blättern in langen Listen – ein gemeinsamer Baustein (F107): Erste, Vorher, Seite n von m (zum Eintippen), Weiter,
// Letzte, Zeilen je Seite. Fester Platz in der Fußzeile; Knöpfe werden gesperrt, nie ausgeblendet (F106).
// Geblättert wird über die Zeilen, die die Seite schon geladen und gefiltert hat – Filter und Sortieren gelten
// damit immer für die ganze Liste, nicht nur für die angezeigte Seite.
import { h, knopf, auswahl, sperren } from './bausteine.js';

/**
 * beiWechsel: wird gerufen, wenn sich Seite oder Seitengröße ändert (die Liste zeichnet sich dann neu).
 * Liefert {el, schnitt(zeilen), erste(), zuIndex(i), weiter(), vorher()}.
 */
export function blaettern(beiWechsel, { groesse = 100, groessen = [50, 100, 200, 500], id = 'blaettern' } = {}) {
  let seite = 1;
  let anzahl = 0;
  let je = groesse;
  const seiten = () => Math.max(1, Math.ceil(anzahl / je));
  const gehe = (n) => { const neu = Math.min(seiten(), Math.max(1, n)); if (neu !== seite) { seite = neu; beiWechsel(); } else zeigen(); };
  const kn = {
    erste: knopf('«', () => gehe(1), 'klein', { id: `${id}-erste`, title: 'Erste Seite', 'aria-label': 'Erste Seite' }),
    vorher: knopf('‹ Vorher', () => gehe(seite - 1), 'klein', { id: `${id}-vorher`, title: 'Vorherige Seite (Bild ↑)' }),
    weiter: knopf('Weiter ›', () => gehe(seite + 1), 'klein', { id: `${id}-weiter`, title: 'Nächste Seite (Bild ↓)' }),
    letzte: knopf('»', () => gehe(seiten()), 'klein', { id: `${id}-letzte`, title: 'Letzte Seite', 'aria-label': 'Letzte Seite' }),
  };
  const eingabe = h('input', { id: `${id}-seite`, class: 'blaettern-seite', inputmode: 'numeric', size: '4', title: 'Seite eintippen und Enter drücken', 'aria-label': 'Seite' });
  eingabe.addEventListener('change', () => { const n = Number.parseInt(eingabe.value, 10); gehe(Number.isFinite(n) ? n : seite); });
  eingabe.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); eingabe.blur(); } });
  const von = h('span', { class: 'blaettern-von' });
  const bereich = h('span', { class: 'blaettern-bereich', id: `${id}-bereich`, role: 'status' });
  const groesseWahl = auswahl(groessen.map((g) => [String(g), `${g} je Seite`]), String(je), { id: `${id}-groesse`, title: 'Zeilen je Seite', 'aria-label': 'Zeilen je Seite' });
  groesseWahl.addEventListener('change', () => { const erster = (seite - 1) * je; je = Number(groesseWahl.value); seite = Math.floor(erster / je) + 1; beiWechsel(); });
  const el = h('div', { class: 'blaettern', id }, bereich, kn.erste, kn.vorher, h('label', { class: 'blaettern-eingabe' }, 'Seite', eingabe, von), kn.weiter, kn.letzte, groesseWahl);

  function zeigen() {
    const m = seiten();
    eingabe.value = String(seite);
    von.textContent = `von ${m}`;
    bereich.textContent = anzahl ? `${(seite - 1) * je + 1}–${Math.min(anzahl, seite * je)} von ${anzahl}` : '0 von 0';
    const anfang = seite <= 1 ? 'Das ist die erste Seite.' : null;
    const ende = seite >= m ? 'Das ist die letzte Seite.' : null;
    sperren(kn.erste, anfang);
    sperren(kn.vorher, anfang);
    sperren(kn.weiter, ende);
    sperren(kn.letzte, ende);
  }
  zeigen();
  return {
    el,
    /** Die Zeilen der aktuellen Seite; merkt sich dabei die Gesamtzahl und hält die Seite im gültigen Bereich. */
    schnitt(zeilen) {
      anzahl = zeilen.length;
      seite = Math.min(seiten(), Math.max(1, seite));
      zeigen();
      return zeilen.slice((seite - 1) * je, seite * je);
    },
    /** zurück auf die erste Seite, ohne neu zu zeichnen (z. B. wenn sich Filter oder Suche ändern) */
    erste() { seite = 1; },
    /** auf die Seite mit der Zeile an dieser Stelle der ganzen Liste, ohne neu zu zeichnen */
    zuIndex(i) { if (i >= 0) seite = Math.floor(i / je) + 1; },
    weiter() { gehe(seite + 1); },
    vorher() { gehe(seite - 1); },
  };
}
