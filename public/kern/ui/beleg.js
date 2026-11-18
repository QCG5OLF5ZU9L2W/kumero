// SPDX-License-Identifier: AGPL-3.0-or-later
// Belege aus angebundenen Belegarchiven (F97): Vorschau, Original und Prüfung – immer dann, wenn ein
// Belegverweis aus einem DMS vorliegt, gleich aus welchem. Seiten kennen kein DMS beim Namen; sie fragen
// nur diesen Baustein. Geladen wird über kumero (/belege/…), nie direkt vom Archiv, und nichts wird gespeichert.
import { h, knopf } from './bausteine.js';

const stand = new Map();

/** Archive des Nutzers im Mandanten: [{praefix, titel, bereit}] – leer, wenn kein DMS angebunden ist. */
export async function belegarchive(k, neu = false) {
  if (neu || !stand.has(k.mandant)) {
    let a = [];
    if (k.darf('kern.buchung.sehen')) {
      try { a = await k.api('GET', `${k.apiBasis}/belege/archive`); } catch { /* ohne Archiv bleibt ein Beleg ein gewöhnlicher Link */ }
    }
    stand.set(k.mandant, a);
  }
  return stand.get(k.mandant);
}

/** Das Archiv, das für den Link zuständig ist, oder null. */
export function archivZuLink(archive, link) {
  return (archive || []).find((a) => link && link.startsWith(a.praefix)) || null;
}

const adresse = (k, art, link) => `${k.apiBasis}/belege/${art}?link=${encodeURIComponent(link)}`;

/** Große Vorschau in einem Fenster: Bild sofort, Original und Archiv auf Wunsch in neuem Browserfenster. */
export function belegVorschau(k, link) {
  const bild = h('img', { class: 'beleg-bild', alt: 'Vorschau des Belegs', src: adresse(k, 'vorschau', link) });
  const hinweis = h('p', { class: 'hinweis', hidden: true }, 'Keine Vorschau: Das Belegarchiv ist gesperrt oder nicht erreichbar, oder der Beleg ist für dein Konto nicht sichtbar.');
  bild.addEventListener('error', () => { bild.hidden = true; hinweis.hidden = false; });
  const d = h('dialog', { class: 'dialog beleg-dialog', id: 'beleg-dialog' }, h('h2', {}, 'Vorschau'), bild, hinweis,
    h('div', { class: 'dialog-knoepfe' },
      h('a', { class: 'knopf', href: adresse(k, 'original', link), target: '_blank', rel: 'noopener noreferrer' }, 'Original öffnen'),
      h('a', { class: 'knopf', href: link, target: '_blank', rel: 'noopener noreferrer' }, 'Im Archiv öffnen'),
      knopf('Schließen', () => d.close(), 'primaer', { id: 'beleg-schliessen' })));
  d.addEventListener('close', () => d.remove());
  document.body.append(d);
  d.showModal();
}

/**
 * Kleine Vorschau am Rand des Fensters – beim Überfahren mit der Maus oder per Tastatur. Sie liegt über der
 * Seite und verschiebt nichts. Geladen wird erst, wenn sie gebraucht wird.
 * Liefert {zeigen(link), verbergen(), umschalten(link), entfernen()}.
 */
export function belegSchwebe(k) {
  const bild = h('img', { alt: 'Vorschau des Belegs' });
  const text = h('p', { class: 'hinweis' });
  const el = h('div', { class: 'beleg-schwebe', id: 'beleg-schwebe', hidden: true, role: 'status' }, bild, text);
  let aktuell = null;
  let warte = null;
  bild.addEventListener('error', () => { bild.hidden = true; text.textContent = 'Keine Vorschau verfügbar.'; });
  bild.addEventListener('load', () => { bild.hidden = false; text.textContent = ''; });
  document.body.append(el);
  const verbergen = () => { clearTimeout(warte); aktuell = null; el.hidden = true; };
  const sofort = (link) => {
    if (aktuell !== link) { aktuell = link; bild.hidden = true; text.textContent = 'Lade Vorschau …'; bild.src = adresse(k, 'vorschau', link); }
    el.hidden = false;
  };
  return {
    // kurze Verzögerung: Beim Überstreichen der Liste wird nicht jede Zeile geladen
    zeigen: (link) => { clearTimeout(warte); warte = setTimeout(() => sofort(link), 350); },
    verbergen,
    umschalten: (link) => { if (!el.hidden && aktuell === link) verbergen(); else { clearTimeout(warte); sofort(link); } },
    entfernen: () => { clearTimeout(warte); el.remove(); },
  };
}

/** Prüft den Beleg am Original: {link, md5, sha256} in einheitlicher Schreibweise (F10, F88). */
export function belegPruefen(k, link) {
  return k.api('POST', `${k.apiBasis}/belege/verweis`, { json: { link } });
}
