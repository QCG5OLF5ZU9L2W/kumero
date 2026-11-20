// SPDX-License-Identifier: AGPL-3.0-or-later
// „Fristen und Nachweise“ (PERSONAL.md Stufe 1, F136): was je Person wann fällig ist – Einträge des Personals und, für
// den, der Verträge sehen darf, die Termine aus dem geltenden Vertrag (Ende der Probezeit, Ende der Befristung).
// Spalten mit Sortierung und Filter (F91, F137); Zustände tragen Zeichen und Text, nie nur Farbe.
import { h, seitenkopf, knopf, feld, auswahl, sperren, datumFeld, bestaetigen } from '/kern/ui/bausteine.js';
import { tabellenfilter } from '/kern/ui/tabellenfilter.js';
import { stil, dialog, fristZustand, tag } from './gemeinsam.js';

const ZUSTAND = { ueberfaellig: 'überfällig', faellig: 'fällig', offen: 'offen', erledigt: 'erledigt' };

export async function zeigen(b, k) {
  stil();
  b.classList.add('listenseite', 'personal');
  const api = `${k.mandantApi}/personal`;
  let d = { darf: {}, fristen: [], fristarten: [], personen: [], warn_tage: 60 };
  let gewaehlt = null;

  const kn = {
    neu: knopf('Frist anlegen …', neuDialog, 'primaer', { id: 'pf-neu', title: 'Legt für eine Person eine Frist oder einen Nachweis mit Fälligkeit an' }),
    erledigt: knopf('Erledigt', erledigen, '', { id: 'pf-erledigt', title: 'Vermerkt die gewählte Frist als erledigt' }),
    arten: knopf('Arten und Warnfrist …', artenDialog, '', { id: 'pf-arten', title: 'Arten von Fristen pflegen und festlegen, wie viele Tage vorher gewarnt wird' }),
  };
  const stand = h('div', { class: 'pe-stand', id: 'pf-stand', role: 'status' });
  const liste = h('div', { class: 'liste-rahmen pe-liste listen-rollen', id: 'pf-liste' });
  const schluessel = (f) => f.id || `${f.person}:${f.art_name}`;
  const spalten = [
    { id: 'faellig', titel: 'Fällig', klasse: 'eng', wert: (f) => tag(f.faellig), sortwert: (f) => f.faellig },
    { id: 'person', titel: 'Person', wert: (f) => f.name },
    { id: 'art', titel: 'Art', wert: (f) => f.art_name },
    { id: 'bemerkung', titel: 'Bemerkung', wert: (f) => f.bemerkung || '' },
    { id: 'zustand', titel: 'Zustand', wert: (f) => ZUSTAND[f.zustand], sortwert: (f) => f.tage, zelle: (f) => fristZustand(f) },
  ];
  const filter = tabellenfilter(spalten, zeichnen);
  const nurPflege = () => (d.darf.frist_pflegen ? null : 'Das darf nur, wer Fristen pflegt (Rolle „Personal“)');

  function zeichnen() {
    filter.setzeZeilen(d.fristen);
    const zeilen = filter.anwenden(d.fristen);
    const g = d.fristen.find((f) => schluessel(f) === gewaehlt) || null;
    const tbody = h('tbody', {}, zeilen.length ? zeilen.map((f) => {
      const tr = h('tr', { 'data-id': schluessel(f), tabindex: '0', class: schluessel(f) === gewaehlt ? 'gewaehlt' : '', 'aria-selected': schluessel(f) === gewaehlt ? 'true' : 'false' },
        spalten.map((s) => h('td', { class: s.klasse || '' }, s.zelle ? s.zelle(f) : s.wert(f))));
      const waehlen = () => { gewaehlt = schluessel(f); zeichnen(); };
      tr.addEventListener('click', waehlen);
      tr.addEventListener('keydown', (ev) => { if (ev.target === tr && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); waehlen(); } });
      return tr;
    }) : h('tr', { class: 'leer' }, h('td', { colspan: spalten.length, id: 'pf-leer' }, d.fristen.length ? 'Keine Frist passt zum Filter.' : 'Keine offene Frist.')));
    const faellig = d.fristen.filter((f) => f.zustand === 'ueberfaellig' || f.zustand === 'faellig').length;
    liste.replaceChildren(h('div', { class: 'liste-scroll' }, h('table', { class: 'liste', id: 'pf-tabelle' }, h('thead', {}, filter.kopf), tbody)),
      h('div', { class: 'liste-fuss' }, `${zeilen.length === d.fristen.length ? '' : `${zeilen.length} von `}${d.fristen.length === 1 ? '1 offene Frist' : `${d.fristen.length} offene Fristen`}`
        + ` · ${faellig} fällig oder überfällig` + (filter.aktiv() ? ' · gefiltert' : '')));
    stand.textContent = `Termine aus dem Vertrag werden ${d.warn_tage} Tage vorher fällig; jede Art von Frist hat ihre eigene Warnfrist.`;
    sperren(kn.neu, nurPflege() || (d.personen.length ? null : 'Es gibt noch keine Person'));
    sperren(kn.erledigt, nurPflege() || (g ? (g.quelle === 'vertrag' ? 'Der Termin kommt aus dem Vertrag – er endet mit einer neuen Fassung' : null) : 'Erst eine Frist in der Liste wählen'));
    sperren(kn.arten, nurPflege());
  }

  async function laden() {
    try { d = await k.api('GET', `${api}/fristen`); } catch (x) { k.fehler(x); }
    if (!d.fristen.some((f) => schluessel(f) === gewaehlt)) gewaehlt = null;
    zeichnen();
  }

  function neuDialog() {
    const arten = d.fristarten.filter((a) => !a.stillgelegt);
    const person = auswahl(d.personen.map((p) => [p.id, p.name]), null, { id: 'pf-f-person' });
    const art = auswahl(arten.map((a) => [a.id, a.name]), null, { id: 'pf-f-art' });
    const faellig = datumFeld({ id: 'pf-f-faellig' });
    const bemerkung = h('input', { id: 'pf-f-bemerkung', type: 'text', maxlength: '200', size: '44', autocomplete: 'off' });
    dialog({ id: 'pf-dialog-neu', titel: 'Frist anlegen', ja: 'Anlegen',
      felder: [feld('Person', person), feld('Art', art), feld('Fällig am', faellig.el), feld('Bemerkung', bemerkung, 'keine Gesundheitsangaben')],
      senden: async () => {
        const w = faellig.wert();
        if (!w) throw new Error(w === null ? 'Das Datum ist nicht lesbar' : 'Fällig am fehlt');
        gewaehlt = (await k.api('POST', `${api}/fristen`, { json: { person: person.value, art: art.value, faellig: w, bemerkung: bemerkung.value.trim() || null } })).id;
        k.melden('Frist angelegt.', 'erfolg');
        await laden();
      } });
  }

  async function erledigen() {
    const g = d.fristen.find((f) => schluessel(f) === gewaehlt);
    if (!g || !g.id) return;
    if (!await bestaetigen('Frist erledigt?', `${g.art_name} von ${g.name}, fällig ${tag(g.faellig)}. Der Vermerk bleibt in der Akte.`, 'Erledigt')) return;
    try { await k.api('POST', `${api}/fristen/${g.id}/erledigt`, { json: {} }); k.melden('Als erledigt vermerkt.', 'erfolg'); } catch (x) { k.fehler(x); }
    await laden();
  }

  function artenDialog() {
    const zahl = (id, wert) => h('input', { id, type: 'number', min: '0', max: '365', step: '1', value: String(wert), class: 'pe-kurz' });
    const warn = zahl('pf-a-warn', d.warn_tage);
    const zeilen = d.fristarten.map((a) => ({ a, name: h('input', { type: 'text', value: a.name, maxlength: '60', 'aria-label': 'Name der Art' }), tage: zahl(`pf-a-tage-${a.id}`, a.warn_tage),
      still: h('input', { type: 'checkbox', checked: a.stillgelegt ? '' : null, 'aria-label': `${a.name} stillgelegt` }) }));
    const neuName = h('input', { id: 'pf-a-neu', type: 'text', maxlength: '60', placeholder: 'neue Art', autocomplete: 'off' });
    const neuTage = zahl('pf-a-neu-tage', 60);
    dialog({ id: 'pf-dialog-arten', titel: 'Arten und Warnfrist', ja: 'Speichern', breit: true,
      text: 'Eine Art wird stillgelegt, nicht gelöscht: Bestehende Fristen behalten sie.',
      felder: [feld('Termine aus dem Vertrag: fällig so viele Tage vorher', warn),
        h('table', { class: 'pe-tabelle', id: 'pf-a-tabelle' }, h('thead', {}, h('tr', {}, ['Art', 'Warnfrist in Tagen', 'stillgelegt'].map((t) => h('th', {}, t)))),
          h('tbody', {}, ...zeilen.map((z) => h('tr', {}, h('td', {}, z.name), h('td', {}, z.tage), h('td', {}, z.still))), h('tr', {}, h('td', {}, neuName), h('td', {}, neuTage), h('td', {}, ''))))],
      senden: async () => {
        const ganz = (el) => { const n = Number(el.value); if (!Number.isInteger(n) || n < 0 || n > 365) throw new Error('Warnfrist: 0 bis 365 Tage'); return n; };
        if (ganz(warn) !== d.warn_tage) await k.api('PUT', `${api}/einstellung`, { json: { warn_tage: ganz(warn) } });
        for (const z of zeilen) {
          if (z.name.value.trim() !== z.a.name || ganz(z.tage) !== z.a.warn_tage || z.still.checked !== z.a.stillgelegt) {
            await k.api('PUT', `${api}/fristarten/${z.a.id}`, { json: { name: z.name.value.trim(), warn_tage: ganz(z.tage), stillgelegt: z.still.checked } });
          }
        }
        if (neuName.value.trim()) await k.api('POST', `${api}/fristarten`, { json: { name: neuName.value.trim(), warn_tage: ganz(neuTage) } });
        k.melden('Gespeichert.', 'erfolg');
        await laden();
      } });
  }

  b.append(seitenkopf(`Fristen und Nachweise – ${k.mandantName || k.mandant}`), h('div', { class: 'pe-leiste nicht-drucken' }, kn.neu, kn.erledigt, kn.arten), stand, liste);
  await laden();
}
