// SPDX-License-Identifier: AGPL-3.0-or-later
// „Meine Leistungen“ (LEISTUNGEN.md Abschnitt 10, F154): die eigenen erbrachten Leistungen eintragen und sehen. Bestätigt
// werden sie vom Vorgesetzten; eine unbestätigte lässt sich löschen. Knöpfe stehen fest und nennen gesperrt den Grund (F106).
import { h, seitenkopf, knopf, feld, auswahl, sperren, bestaetigen } from '/kern/ui/bausteine.js';
import { tabellenfilter } from '/kern/ui/tabellenfilter.js';
import { stil, dialog, monatAuswahl, monatText, tag, geld, mengeText, zeileFelder, zustandText } from './gemeinsam.js';

export async function zeigen(b, k) {
  stil();
  b.classList.add('leistungen');
  const api = `${k.mandantApi}/leistungen/meine`;
  let d = { person: null, zeilen: [], katalog: [] };
  let monat = null;
  let gewaehlt = null;
  const kn = {
    neu: knopf('Leistung eintragen …', eintragen, 'primaer', { id: 'lm-neu', title: 'Trägt eine erbrachte Leistung aus dem Katalog ein' }),
    weg: knopf('Löschen …', loeschen, '', { id: 'lm-loeschen', title: 'Löscht die gewählte Leistung – solange sie nicht bestätigt ist' }),
  };
  const inhalt = h('div', { id: 'lm-inhalt' });
  const liste = h('div', { class: 'liste-rahmen', id: 'lm-liste' });
  const spalten = [
    { id: 'tag', titel: 'Tag', wert: (z) => tag(z.tag), sortwert: (z) => z.tag },
    { id: 'leistung', titel: 'Leistung', wert: (z) => z.bezeichnung },
    { id: 'gruppe', titel: 'Gruppe', wert: (z) => z.gruppe || '' },
    { id: 'menge', titel: 'Menge', klasse: 'zahl', wert: (z) => `${mengeText(z.menge)} ${z.einheit}`, sortwert: (z) => Number(z.menge) },
    { id: 'einzel', titel: 'Einzelpreis €', klasse: 'zahl', wert: (z) => geld(z.einzel_cent), sortwert: (z) => z.einzel_cent },
    { id: 'betrag', titel: 'Betrag €', klasse: 'zahl', wert: (z) => geld(z.betrag_cent), sortwert: (z) => z.betrag_cent },
    { id: 'vorgang', titel: 'Vorgang', wert: (z) => z.vorgang || '' },
    { id: 'zustand', titel: 'Zustand', wert: (z) => zustandText(z) + (z.fest ? ' · in der Lohnvorbereitung' : ''),
      zelle: (z) => h('span', { 'data-zustand': z.bestaetigt ? (z.fest ? 'le-fest' : '') : 'le-wartet' }, zustandText(z) + (z.fest ? ' · in der Lohnvorbereitung' : '')) },
  ];
  const filter = tabellenfilter(spalten, zeichnen, { id: 'leistungen.meine' });
  const wahl = () => d.zeilen.find((z) => z.id === gewaehlt) || null;

  function zeichnen() {
    const ohne = d.person ? null : 'Dein Zugang ist noch mit keiner Person verknüpft';
    const g = wahl();
    sperren(kn.neu, ohne || (d.katalog.length ? null : 'Der Katalog ist leer – das Personal legt Leistungen unter „Leistungen“ → „Katalog …“ an'));
    sperren(kn.weg, ohne || (g ? null : 'Erst eine Leistung in der Liste wählen') || (g.bestaetigt ? 'Bestätigt – löschen kann sie dein Vorgesetzter oder das Personal' : null)
      || (g.fest ? 'Die Lohnvorbereitung des Monats ist geprüft oder übergeben' : null));
    if (!d.person) { inhalt.replaceChildren(h('p', { class: 'hinweis', id: 'lm-leer' }, d.hinweis || 'Keine Daten.')); return; }
    filter.setzeZeilen(d.zeilen);
    const zeilen = filter.anwenden(d.zeilen);
    const sp = filter.spalten();
    liste.replaceChildren(h('div', { class: 'liste-scroll' }, h('table', { class: 'liste', id: 'lm-tabelle' }, h('thead', {}, filter.kopf),
      h('tbody', {}, zeilen.length ? zeilen.map((z) => {
        const tr = h('tr', { 'data-id': z.id, tabindex: '0', class: z.id === gewaehlt ? 'gewaehlt' : '', 'aria-selected': z.id === gewaehlt ? 'true' : 'false' },
          sp.map((s) => h('td', { class: s.klasse || '' }, s.zelle ? s.zelle(z) : s.wert(z))));
        const waehlen = () => { gewaehlt = z.id; zeichnen(); };
        tr.addEventListener('click', waehlen);
        tr.addEventListener('keydown', (ev) => { if (ev.target === tr && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); waehlen(); } });
        return tr;
      }) : h('tr', { class: 'leer' }, h('td', { colspan: sp.length, id: 'lm-keine' }, d.zeilen.length ? 'Keine Leistung passt zum Filter.' : `Im ${monatText(d.monat)} ist noch nichts eingetragen.`))))),
      h('div', { class: 'liste-fuss', id: 'lm-summe' }, `${d.zeilen.length === 1 ? '1 Leistung' : `${d.zeilen.length} Leistungen`} · ${geld(d.summe_cent)} € · davon bestätigt ${geld(d.bestaetigt_cent)} €`));
    inhalt.replaceChildren(
      h('div', { class: 'le-leiste' }, h('label', {}, 'Monat ', monatAuswahl('lm-monatwahl', d.monat, (m) => { monat = m; gewaehlt = null; laden(); })),
        h('span', { class: 'le-klein' }, 'Bestätigt werden deine Leistungen vom Vorgesetzten; aus den bestätigten rechnet die Lohnvorbereitung den Zuschlag.')),
      liste);
  }

  function eintragen() {
    const f = zeileFelder({ id: 'lm-f', katalog: d.katalog, heute: d.heute, feld, auswahl });
    dialog({ id: 'lm-dialog-neu', titel: 'Leistung eintragen', ja: 'Eintragen', breit: true,
      text: 'Die Leistung zählt für den Zuschlag, sobald dein Vorgesetzter sie bestätigt hat. Wer die Leistung empfangen hat, steht hier nicht.',
      felder: f.felder,
      senden: async () => { gewaehlt = (await k.api('POST', `${api}/zeilen`, { json: f.json() })).id; k.melden('Leistung eingetragen – wartet auf die Bestätigung.', 'erfolg'); await laden(); } });
  }

  async function loeschen() {
    const g = wahl();
    if (!g || !await bestaetigen('Leistung löschen?', `${g.bezeichnung} vom ${tag(g.tag)}, ${geld(g.betrag_cent)} €. Die Zeile wird gelöscht, nicht storniert.`, 'Löschen')) return;
    try { await k.api('DELETE', `${api}/zeilen/${g.id}`); gewaehlt = null; k.melden('Leistung gelöscht.', 'erfolg'); } catch (x) { k.fehler(x); }
    await laden();
  }

  async function laden() {
    try { d = await k.api('GET', `${api}${monat ? `?monat=${monat}` : ''}`); } catch (x) { k.fehler(x); }
    d.zeilen ||= []; d.katalog ||= [];
    zeichnen();
  }

  // Doppelklick (F150): öffnet das, was an der Zeile zu tun ist – bei einer unbestätigten das Löschen
  liste.addEventListener('click', (ev) => {
    if (ev.detail !== 2) return;
    const id = (ev.target.closest?.('tr[data-id]') || document.elementFromPoint(ev.clientX, ev.clientY)?.closest?.('tr[data-id]'))?.dataset.id;
    if (!id) return;
    if (gewaehlt !== id) { gewaehlt = id; zeichnen(); }
    if (!kn.weg.disabled) loeschen(); else k.melden(kn.weg.title, 'warnung');
  });

  b.append(seitenkopf('Meine Leistungen', kn.neu, kn.weg), inhalt);
  await laden();
}
