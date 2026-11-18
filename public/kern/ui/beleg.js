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

/** Wohin „Beleg öffnen“ führt: ins Archiv – bei einem vorläufigen Beleg (F110) zum Original, das noch in kumero liegt. */
export const belegZiel = (k, link) => (archivZuLink(stand.get(k.mandant), link)?.vorlaeufig ? adresse(k, 'original', link) : link);

/** Adresse des Vorschaubilds eines Belegs, für Seiten, die das Bild selbst einbetten (z. B. einen Vergleich). */
export const belegBildAdresse = (k, link) => adresse(k, 'vorschau', link);

/**
 * Warum ein Beleg keine Vorschau hat – der konkrete Grund statt einer allgemeinen Auskunft. Das Bild selbst sagt
 * dem Browser nur „Fehler“; deshalb fragt der Baustein den Vorschau-Endpunkt noch einmal und liest dessen Meldung
 * (Archiv gesperrt, nicht erreichbar, Beleg fehlt oder ist für das Konto nicht sichtbar …).
 */
export async function belegGrund(k, link) {
  try {
    await k.api('GET', adresse(k, 'vorschau', link));
    return 'Keine Vorschau: Das Archiv liefert zu diesem Beleg kein Bild, das der Browser darstellen kann.';
  } catch (e) {
    return `Keine Vorschau: ${e.message}${e.status ? ` (Fehler ${e.status})` : ''}`;
  }
}

/** Große Vorschau in einem Fenster: Bild sofort, Original und Archiv auf Wunsch in neuem Browserfenster. */
export function belegVorschau(k, link) {
  const bild = h('img', { class: 'beleg-bild', alt: 'Vorschau des Belegs', src: adresse(k, 'vorschau', link) });
  const hinweis = h('p', { class: 'hinweis', id: 'beleg-grund', hidden: true }, 'Keine Vorschau – der Grund wird ermittelt …');
  bild.addEventListener('error', async () => { bild.hidden = true; hinweis.hidden = false; hinweis.textContent = await belegGrund(k, link); });
  const d = h('dialog', { class: 'dialog beleg-dialog', id: 'beleg-dialog' }, h('h2', {}, 'Vorschau'), bild, hinweis,
    h('div', { class: 'dialog-knoepfe' },
      h('a', { class: 'knopf', href: adresse(k, 'original', link), target: '_blank', rel: 'noopener noreferrer' }, 'Original öffnen'),
      // Ein vorläufiger Beleg (F110) liegt in keinem Archiv: Der Knopf steht da, ist aber gesperrt und nennt den Grund
      archivZuLink(stand.get(k.mandant), link)?.vorlaeufig
        ? h('button', { type: 'button', class: 'knopf', disabled: true, title: 'Dieser Beleg liegt noch in der Zwischenablage von kumero. Ins Belegarchiv kommt er mit der Buchung.' }, 'Im Archiv öffnen')
        : h('a', { class: 'knopf', href: link, target: '_blank', rel: 'noopener noreferrer' }, 'Im Archiv öffnen'),
      knopf('Schließen', () => d.close(), 'primaer', { id: 'beleg-schliessen' })));
  d.addEventListener('close', () => d.remove());
  document.body.append(d);
  d.showModal();
}

/**
 * Kleine Vorschau, die rechts neben dem Mauszeiger hängt (bzw. neben dem Symbol, wenn sie per Tastatur
 * geöffnet wird). Sie liegt über der Seite und verschiebt nichts; geladen wird erst, wenn sie gebraucht wird.
 * Liefert {zeigen(link, ort), bewegen(ort), verbergen(), umschalten(link, ort), entfernen()};
 * ort: {x, y} in Fensterkoordinaten.
 */
export function belegSchwebe(k) {
  const bild = h('img', { alt: 'Vorschau des Belegs' });
  const text = h('p', { class: 'hinweis' });
  const el = h('div', { class: 'beleg-schwebe', id: 'beleg-schwebe', hidden: true, role: 'status' }, bild, text);
  let aktuell = null;
  let warte = null;
  let ort = { x: 0, y: 0 };
  // Rechts neben dem Zeiger; reicht der Platz nicht, links davon – immer ganz im Fenster
  const setzen = () => {
    const b = el.offsetWidth || 352;
    const hoch = el.offsetHeight || 300;
    let x = ort.x + 18;
    if (x + b > window.innerWidth - 8) x = Math.max(8, ort.x - b - 18);
    const y = Math.min(Math.max(8, ort.y - hoch / 3), Math.max(8, window.innerHeight - hoch - 8));
    el.style.left = `${Math.round(x)}px`;
    el.style.top = `${Math.round(y)}px`;
  };
  bild.addEventListener('error', async () => {
    const link = aktuell;
    bild.hidden = true; text.textContent = 'Keine Vorschau – der Grund wird ermittelt …'; setzen();
    const grund = await belegGrund(k, link);
    if (aktuell === link) { text.textContent = grund; setzen(); }
  });
  bild.addEventListener('load', () => { bild.hidden = false; text.textContent = ''; setzen(); });
  document.getElementById('beleg-schwebe')?.remove();
  document.body.append(el);
  const verbergen = () => { clearTimeout(warte); aktuell = null; el.hidden = true; };
  const sofort = (link) => {
    if (aktuell !== link) { aktuell = link; bild.hidden = true; text.textContent = 'Lade Vorschau …'; bild.src = adresse(k, 'vorschau', link); }
    el.hidden = false;
    setzen();
  };
  return {
    // kurze Verzögerung: Beim Überstreichen wird nicht jeder Beleg geladen
    zeigen: (link, wo) => { if (wo) ort = wo; clearTimeout(warte); warte = setTimeout(() => sofort(link), 250); },
    bewegen: (wo) => { ort = wo; if (!el.hidden) setzen(); },
    verbergen,
    umschalten: (link, wo) => { if (!el.hidden && aktuell === link) { verbergen(); return; } if (wo) ort = wo; clearTimeout(warte); sofort(link); },
    entfernen: () => { clearTimeout(warte); el.remove(); },
  };
}

/**
 * Das Auge: Symbol an einem Beleg. Überfahren mit der Maus zeigt die Vorschau rechts neben dem Zeiger,
 * Fokus per Tastatur neben dem Symbol; ein Klick (oder Enter) öffnet die große Vorschau.
 */
export function belegAuge(k, link, schwebe) {
  const knopfAuge = h('button', { type: 'button', class: 'beleg-auge', title: 'Vorschau des Belegs', 'aria-label': 'Vorschau des Belegs' },
    h('img', { src: new URL('./symbole/auge.svg', import.meta.url).href, alt: '' }));
  const neben = () => { const r = knopfAuge.getBoundingClientRect(); return { x: r.right, y: r.top + r.height / 2 }; };
  knopfAuge.addEventListener('mouseenter', (ev) => schwebe.zeigen(link, { x: ev.clientX, y: ev.clientY }));
  knopfAuge.addEventListener('mousemove', (ev) => schwebe.bewegen({ x: ev.clientX, y: ev.clientY }));
  knopfAuge.addEventListener('mouseleave', () => schwebe.verbergen());
  knopfAuge.addEventListener('focus', () => { if (knopfAuge.matches(':focus-visible')) schwebe.zeigen(link, neben()); });
  knopfAuge.addEventListener('blur', () => schwebe.verbergen());
  knopfAuge.addEventListener('click', (ev) => { ev.stopPropagation(); schwebe.verbergen(); belegVorschau(k, link); });
  knopfAuge.neben = neben;
  return knopfAuge;
}

/**
 * Hängt die schwebende Vorschau an ein beliebiges Element (z. B. den Knopf „Vorschau“): Überfahren zeigt sie
 * rechts neben dem Zeiger. link() liefert den Link oder null, wenn es gerade nichts zu zeigen gibt.
 */
export function belegBeimUeberfahren(el, schwebe, link) {
  el.addEventListener('mouseenter', (ev) => { const l = link(); if (l) schwebe.zeigen(l, { x: ev.clientX, y: ev.clientY }); });
  el.addEventListener('mousemove', (ev) => schwebe.bewegen({ x: ev.clientX, y: ev.clientY }));
  for (const art of ['mouseleave', 'click', 'blur']) el.addEventListener(art, () => schwebe.verbergen());
  return el;
}

/** Prüft den Beleg am Original: {link, md5, sha256} in einheitlicher Schreibweise (F10, F88). */
export function belegPruefen(k, link) {
  return k.api('POST', `${k.apiBasis}/belege/verweis`, { json: { link } });
}
