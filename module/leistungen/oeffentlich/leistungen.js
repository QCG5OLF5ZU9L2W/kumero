// SPDX-License-Identifier: AGPL-3.0-or-later
// „Leistungen“ (LEISTUNGEN.md Abschnitt 10, F154): für Vorgesetzte, Personal und Lohn. Oben die Leistungen des Monats mit
// Sortierung, Filter und verschiebbaren Spalten; bestätigen, löschen, für jemanden eintragen; das Personal pflegt den Katalog.
import { h, seitenkopf, knopf, feld, auswahl, sperren, bestaetigen } from '/kern/ui/bausteine.js';
import { tabellenfilter } from '/kern/ui/tabellenfilter.js';
import { stil, dialog, monatAuswahl, monatText, tag, geld, centLesen, mengeText, zeileFelder, zustandText } from './gemeinsam.js';

export async function zeigen(b, k) {
  stil();
  b.classList.add('leistungen');
  const api = `${k.mandantApi}/leistungen`;
  let d = { zeilen: [], personen: [], katalog: [], darf: {}, monat: null };
  let monat = null;
  let gewaehlt = null;
  const kn = {
    ja: knopf('Bestätigen', bestaetige, 'primaer', { id: 'le-bestaetigen', title: 'Bestätigt die gewählte Leistung – erst dann zählt sie für den Zuschlag' }),
    neu: knopf('Eintragen …', eintragen, '', { id: 'le-neu', title: 'Trägt für eine Person eine Leistung ein – sie gilt sofort als bestätigt' }),
    weg: knopf('Löschen …', loeschen, '', { id: 'le-loeschen', title: 'Löscht die gewählte Leistung' }),
    katalog: knopf('Katalog …', katalogDialog, '', { id: 'le-katalog', title: 'Leistungen und ihre Preise pflegen' }),
    druck: knopf('Drucken', () => window.print(), '', { id: 'le-druck', title: 'Druckt die Auswertung – das PDF entsteht im Druckdialog des Browsers' }),
    csv: knopf('CSV', csvLaden, '', { id: 'le-csv', title: 'Die Auswertung als Datei für ein Tabellenprogramm' }),
  };
  // Auswertung (F155): Umsatz des Monats nach Person, Gruppe oder Leistung – aus den Zeilen, die die Rolle sehen darf
  const GLIEDERUNG = [['person', 'je Person'], ['person_gruppe', 'je Person und Gruppe'], ['person_leistung', 'je Person und Leistung'], ['gruppe', 'je Gruppe'], ['leistung', 'je Leistung']];
  const gliederung = auswahl(GLIEDERUNG, 'person_gruppe', { id: 'le-gliederung', 'aria-label': 'Gliederung der Auswertung' });
  gliederung.addEventListener('change', () => zeichnen());
  const auswertung = h('div', { class: 'le-karte', id: 'le-auswertung' });
  const awSpalten = [
    { id: 'person', titel: 'Person', wert: (r) => r.person },
    { id: 'gruppe', titel: 'Gruppe', wert: (r) => r.gruppe },
    { id: 'leistung', titel: 'Leistung', wert: (r) => r.leistung },
    { id: 'anzahl', titel: 'Zeilen', klasse: 'zahl', wert: (r) => String(r.anzahl), sortwert: (r) => r.anzahl },
    { id: 'menge', titel: 'Menge', klasse: 'zahl', wert: (r) => mengeText(r.menge.toFixed(2)), sortwert: (r) => r.menge },
    { id: 'umsatz', titel: 'Umsatz €', klasse: 'zahl', wert: (r) => geld(r.umsatz), sortwert: (r) => r.umsatz },
    { id: 'bestaetigt', titel: 'davon bestätigt €', klasse: 'zahl', wert: (r) => geld(r.bestaetigt), sortwert: (r) => r.bestaetigt },
    { id: 'offen', titel: 'wartet €', klasse: 'zahl', wert: (r) => geld(r.umsatz - r.bestaetigt), sortwert: (r) => r.umsatz - r.bestaetigt },
  ];
  const awFilter = tabellenfilter(awSpalten, () => zeichnen(), { id: 'leistungen.auswertung' });
  const summen = () => {
    const g = gliederung.value;
    const m = new Map();
    for (const z of d.zeilen) {
      const person = g.startsWith('person') ? z.name : ''; const gruppe = g.endsWith('gruppe') ? (z.gruppe || '– ohne Gruppe –') : ''; const leistung = g.endsWith('leistung') ? z.bezeichnung : '';
      const key = `${person}|${gruppe}|${leistung}`;
      const r = m.get(key) || { person, gruppe, leistung, anzahl: 0, menge: 0, umsatz: 0, bestaetigt: 0 };
      r.anzahl += 1; r.menge += Number(z.menge); r.umsatz += z.betrag_cent; if (z.bestaetigt) r.bestaetigt += z.betrag_cent;
      m.set(key, r);
    }
    return [...m.values()].sort((a, c) => `${a.person}|${a.gruppe}|${a.leistung}`.localeCompare(`${c.person}|${c.gruppe}|${c.leistung}`, 'de'));
  };
  let awZeilen = [];
  function csvLaden() {
    const zelle = (s) => `"${String(s ?? '').replace(/"/g, '""')}"`;
    const sp = awFilter.spalten();
    const zeilen = [[`Leistungen ${monatText(d.monat)}`], [GLIEDERUNG.find(([w]) => w === gliederung.value)[1]], [], sp.map((s) => s.titel), ...awZeilen.map((r) => sp.map((s) => s.wert(r)))];
    const url = URL.createObjectURL(new Blob([`\ufeff${zeilen.map((z) => z.map(zelle).join(';')).join('\r\n')}\r\n`], { type: 'text/csv;charset=utf-8' }));
    const a = h('a', { href: url, download: `Leistungen ${d.monat}.csv`, hidden: true, id: 'le-csv-link' });
    document.body.append(a);
    a.click();
    setTimeout(() => { a.remove(); URL.revokeObjectURL(url); }, 2000);
  }
  const stand = h('div', { class: 'le-klein', id: 'le-stand', role: 'status' });
  const liste = h('div', { class: 'liste-rahmen', id: 'le-liste' });
  const spalten = [
    { id: 'tag', titel: 'Tag', wert: (z) => tag(z.tag), sortwert: (z) => z.tag },
    { id: 'person', titel: 'Person', wert: (z) => z.name + (z.ich ? ' (du)' : '') },
    { id: 'leistung', titel: 'Leistung', wert: (z) => z.bezeichnung },
    { id: 'gruppe', titel: 'Gruppe', wert: (z) => z.gruppe || '' },
    { id: 'menge', titel: 'Menge', klasse: 'zahl', wert: (z) => `${mengeText(z.menge)} ${z.einheit}`, sortwert: (z) => Number(z.menge) },
    { id: 'einzel', titel: 'Einzelpreis €', klasse: 'zahl', wert: (z) => geld(z.einzel_cent), sortwert: (z) => z.einzel_cent },
    { id: 'betrag', titel: 'Betrag €', klasse: 'zahl', wert: (z) => geld(z.betrag_cent), sortwert: (z) => z.betrag_cent },
    { id: 'vorgang', titel: 'Vorgang', wert: (z) => z.vorgang || '' },
    { id: 'zustand', titel: 'Zustand', wert: (z) => zustandText(z) + (z.fest ? ' · in der Lohnvorbereitung' : ''),
      zelle: (z) => h('span', { 'data-zustand': z.bestaetigt ? (z.fest ? 'le-fest' : '') : 'le-wartet' }, zustandText(z) + (z.fest ? ' · in der Lohnvorbereitung' : '')) },
  ];
  const filter = tabellenfilter(spalten, zeichnen, { id: 'leistungen.leistungen' });
  const wahl = () => d.zeilen.find((z) => z.id === gewaehlt) || null;

  function knoepfe() {
    const g = wahl();
    const keine = g ? null : 'Erst eine Leistung in der Liste wählen';
    const frei = d.darf.bestaetigen ? null : 'Das darf nur, wer Leistungen bestätigt (Rollen „Vorgesetzter“ und „Personal“)';
    const nichtMeine = g && !g.zustaendig ? (g.ich ? 'Über eigene Leistungen entscheidet dein Vorgesetzter oder das Personal' : 'Diese Person gehört nicht zu deinem Team') : null;
    const fest = g?.fest ? 'Die Lohnvorbereitung des Monats ist geprüft oder übergeben – an seinen Leistungen ändert sich nichts mehr' : null;
    sperren(kn.ja, frei || keine || nichtMeine || fest || (g.bestaetigt ? 'Schon bestätigt' : null));
    sperren(kn.weg, frei || keine || nichtMeine || fest);
    sperren(kn.neu, frei || (d.personen.length ? null : 'Es gibt niemanden, für den du eintragen kannst') || (d.katalog.some((l) => !l.stillgelegt) ? null : 'Der Katalog ist leer – erst unter „Katalog …“ eine Leistung anlegen'));
    sperren(kn.katalog, d.darf.pflegen ? null : 'Den Katalog pflegt das Personal');
  }

  function zeichnen() {
    filter.setzeZeilen(d.zeilen);
    const zeilen = filter.anwenden(d.zeilen);
    const sp = filter.spalten();
    const summe = (liste2) => liste2.reduce((x, z) => x + z.betrag_cent, 0);
    liste.replaceChildren(h('div', { class: 'liste-scroll' }, h('table', { class: 'liste', id: 'le-tabelle' }, h('thead', {}, filter.kopf),
      h('tbody', {}, zeilen.length ? zeilen.map((z) => {
        const tr = h('tr', { 'data-id': z.id, tabindex: '0', class: z.id === gewaehlt ? 'gewaehlt' : '', 'aria-selected': z.id === gewaehlt ? 'true' : 'false' },
          sp.map((s) => h('td', { class: s.klasse || '' }, s.zelle ? s.zelle(z) : s.wert(z))));
        const waehlen = () => { gewaehlt = z.id; zeichnen(); };
        tr.addEventListener('click', waehlen);
        tr.addEventListener('keydown', (ev) => { if (ev.target === tr && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); waehlen(); } });
        return tr;
      }) : h('tr', { class: 'leer' }, h('td', { colspan: sp.length, id: 'le-leer' }, d.zeilen.length ? 'Keine Leistung passt zum Filter.'
        : (d.darf.alle ? `Im ${monatText(d.monat)} ist nichts eingetragen.` : (d.darf.verknuepft ? `Im ${monatText(d.monat)} hat dein Team nichts eingetragen.` : 'Dein Zugang ist mit keiner Person verknüpft – das richtet das Personal unter „Personen“ ein.'))))))),
      h('div', { class: 'liste-fuss', id: 'le-summe' }, `${zeilen.length === d.zeilen.length ? '' : `${zeilen.length} von `}${d.zeilen.length === 1 ? '1 Leistung' : `${d.zeilen.length} Leistungen`} · gezeigt ${geld(summe(zeilen))} €`
        + ` · davon bestätigt ${geld(summe(zeilen.filter((z) => z.bestaetigt)))} €${filter.aktiv() ? ' · gefiltert' : ''}`));
    const offen = d.zeilen.filter((z) => !z.bestaetigt).length;
    stand.textContent = (d.darf.alle ? 'Du siehst die Leistungen aller Personen.' : 'Du siehst die Leistungen deines Teams.')
      + (offen ? ` ${offen === 1 ? 'Eine wartet' : `${offen} warten`} auf die Bestätigung.` : '')
      + (d.darf.eigene ? ' Eigene Leistungen trägst du unter „Meine Leistungen“ ein.' : '');
    // Auswertung: Summen der Zeilen des Monats in der gewählten Gliederung; leere Spalten der Gliederung bleiben stehen (F106)
    const alle = summen();
    awFilter.setzeZeilen(alle);
    awZeilen = awFilter.anwenden(alle);
    const asp = awFilter.spalten();
    const ges = awZeilen.reduce((x, r) => ({ anzahl: x.anzahl + r.anzahl, umsatz: x.umsatz + r.umsatz, bestaetigt: x.bestaetigt + r.bestaetigt }), { anzahl: 0, umsatz: 0, bestaetigt: 0 });
    const fuss = { person: awFilter.aktiv() ? 'Summe (gefiltert)' : 'Summe', anzahl: String(ges.anzahl), umsatz: geld(ges.umsatz), bestaetigt: geld(ges.bestaetigt), offen: geld(ges.umsatz - ges.bestaetigt) };
    auswertung.replaceChildren(h('h2', { id: 'le-aw-titel' }, `Auswertung ${monatText(d.monat)}`),
      h('div', { class: 'liste-scroll le-aw-rahmen' }, h('table', { class: 'liste', id: 'le-aw-tabelle' }, h('thead', {}, awFilter.kopf),
        h('tbody', {}, awZeilen.length ? awZeilen.map((r) => h('tr', {}, asp.map((s) => h('td', { class: s.klasse || '' }, s.wert(r)))))
          : h('tr', { class: 'leer' }, h('td', { colspan: asp.length }, alle.length ? 'Nichts passt zum Filter.' : 'Nichts auszuwerten.'))),
        h('tfoot', {}, h('tr', { class: 'le-summe' }, asp.map((s, i) => h('td', { class: s.klasse || '' }, fuss[s.id] ?? (i === 0 ? fuss.person : ''))))))),
      h('p', { class: 'le-klein' }, 'Umsatz der eingetragenen Leistungen; für den Zuschlag zählt nur das Bestätigte. Den Zuschlag selbst rechnet die Lohnvorbereitung.'));
    sperren(kn.druck, d.zeilen.length ? null : 'Im Monat ist nichts eingetragen');
    sperren(kn.csv, d.zeilen.length ? null : 'Im Monat ist nichts eingetragen');
    knoepfe();
  }

  async function bestaetige() {
    const g = wahl();
    if (!g) return;
    try { await k.api('POST', `${api}/zeilen/${g.id}/bestaetigung`, { json: {} }); k.melden('Leistung bestätigt.', 'erfolg'); } catch (x) { k.fehler(x); }
    await laden();
  }

  async function loeschen() {
    const g = wahl();
    if (!g || !await bestaetigen('Leistung löschen?', `${g.bezeichnung} von ${g.name}, ${tag(g.tag)}, ${geld(g.betrag_cent)} €. Die Zeile wird gelöscht, nicht storniert.`, 'Löschen')) return;
    try { await k.api('DELETE', `${api}/zeilen/${g.id}`); gewaehlt = null; k.melden('Leistung gelöscht.', 'erfolg'); } catch (x) { k.fehler(x); }
    await laden();
  }

  function eintragen() {
    const f = zeileFelder({ id: 'le-f', katalog: d.katalog.filter((l) => !l.stillgelegt), heute: d.heute, feld, auswahl, personen: d.personen });
    dialog({ id: 'le-dialog-neu', titel: 'Leistung eintragen', ja: 'Eintragen', breit: true,
      text: 'Was du für jemanden einträgst, gilt sofort als bestätigt. Wer die Leistung empfangen hat, steht hier nicht.',
      felder: f.felder,
      senden: async () => { gewaehlt = (await k.api('POST', `${api}/zeilen`, { json: f.json() })).id; k.melden('Leistung eingetragen und bestätigt.', 'erfolg'); await laden(); } });
  }

  // Katalog: eine Leistung wählen oder neu anlegen; der Preis ist eine Fassung mit „gültig ab“
  function katalogDialog() {
    const text = (id, attr = {}) => h('input', { id, type: 'text', autocomplete: 'off', ...attr });
    const f = {
      wahl: auswahl([...d.katalog.map((l) => [l.id, (l.gruppe ? `${l.gruppe}: ` : '') + l.bezeichnung + (l.stillgelegt ? ' (stillgelegt)' : '')]), ['', '– neue Leistung –']], d.katalog[0]?.id || '', { id: 'le-k-wahl' }),
      bezeichnung: text('le-k-bezeichnung', { maxlength: '60', size: '30', required: true }), gruppe: text('le-k-gruppe', { maxlength: '60', size: '20' }),
      einheit: text('le-k-einheit', { maxlength: '20', class: 'le-kurz' }),
      frei: h('input', { type: 'checkbox', id: 'le-k-frei' }), still: h('input', { type: 'checkbox', id: 'le-k-still' }),
      preis: text('le-k-preis', { inputmode: 'decimal', class: 'le-kurz' }), ab: h('input', { id: 'le-k-ab', type: 'date', value: d.heute }),
    };
    const preise = h('p', { class: 'le-klein', id: 'le-k-preise' }, '');
    const fuellen = () => {
      const l = d.katalog.find((x) => x.id === f.wahl.value) || null;
      f.bezeichnung.value = l?.bezeichnung || ''; f.gruppe.value = l?.gruppe || ''; f.einheit.value = l?.einheit || 'Stück';
      f.frei.checked = Boolean(l?.preis_frei); f.still.checked = Boolean(l?.stillgelegt);
      f.still.disabled = !l; f.still.title = l ? '' : 'Erst nach dem Anlegen';
      f.preis.value = l && l.preis_cent !== null ? geld(l.preis_cent) : '';
      preise.textContent = l?.preise?.length ? `Preise: ${l.preise.map((p) => `ab ${tag(p.gueltig_ab)} ${geld(p.preis_cent)} €`).join(' · ')}` : 'Noch kein Preis.';
    };
    f.wahl.addEventListener('change', fuellen);
    dialog({ id: 'le-dialog-katalog', titel: 'Leistungskatalog', ja: 'Speichern', breit: true,
      text: 'Aus dem Katalog wird beim Eintragen gewählt. Ein Preis gilt ab seinem Tag; schon eingetragene Leistungen behalten Bezeichnung und Preis ihres Tages.',
      felder: [h('div', { class: 'le-reihe' }, feld('Leistung', f.wahl), feld('Bezeichnung', f.bezeichnung), feld('Gruppe', f.gruppe, 'für Regeln und Filter'), feld('Einheit', f.einheit)),
        h('div', { class: 'le-reihe' }, feld('Preis €', f.preis, 'leer: kein neuer Preis'), feld('Preis gültig ab', f.ab),
          h('label', {}, f.frei, ' Preis darf je Zeile abweichen'), h('label', {}, f.still, ' stillgelegt')), preise],
      senden: async () => {
        const preis = f.preis.value.trim() === '' ? null : centLesen(f.preis.value);
        if (f.preis.value.trim() !== '' && preis === null) throw new Error('Preis nicht lesbar (12,50)');
        const json = { bezeichnung: f.bezeichnung.value.trim(), gruppe: f.gruppe.value.trim() || null, einheit: f.einheit.value.trim() || 'Stück', preis_frei: f.frei.checked, stillgelegt: f.still.checked };
        let id = f.wahl.value;
        if (id) await k.api('PUT', `${api}/katalog/${id}`, { json }); else id = (await k.api('POST', `${api}/katalog`, { json })).id;
        const alt = d.katalog.find((x) => x.id === id);
        if (preis !== null && !(alt && alt.preis_cent === preis && (alt.preise || []).some((p) => p.gueltig_ab === f.ab.value))) {
          await k.api('PUT', `${api}/katalog/${id}/preis`, { json: { gueltig_ab: f.ab.value, preis_cent: preis } });
        }
        k.melden('Katalog gespeichert.', 'erfolg');
        await laden();
      } });
    fuellen();
  }

  async function laden() {
    try { d = await k.api('GET', `${api}${monat ? `?monat=${monat}` : ''}`); } catch (x) { k.fehler(x); }
    d.zeilen ||= []; d.personen ||= []; d.katalog ||= []; d.darf ||= {};
    wo.replaceChildren('Monat ', monatAuswahl('le-monatwahl', d.monat, (m) => { monat = m; gewaehlt = null; laden(); }));
    zeichnen();
  }

  // Doppelklick führt zur Entscheidung (F150): eine unbestätigte Leistung bestätigen – nach Rückfrage, denn ein Doppelklick entscheidet nicht selbst
  liste.addEventListener('click', async (ev) => {
    if (ev.detail !== 2) return;
    const id = (ev.target.closest?.('tr[data-id]') || document.elementFromPoint(ev.clientX, ev.clientY)?.closest?.('tr[data-id]'))?.dataset.id;
    if (!id) return;
    if (gewaehlt !== id) { gewaehlt = id; zeichnen(); }
    const g = wahl();
    if (kn.ja.disabled) { k.melden(kn.ja.title, 'warnung'); return; }
    if (await bestaetigen('Leistung bestätigen?', `${g.bezeichnung} von ${g.name}, ${tag(g.tag)}, ${geld(g.betrag_cent)} €.`, 'Bestätigen')) bestaetige();
  });

  const wo = h('label', { id: 'le-monat' });
  b.append(seitenkopf('Leistungen'), h('div', { class: 'le-leiste nicht-drucken' }, kn.ja, kn.neu, kn.weg, kn.katalog, wo), stand, liste,
    h('div', { class: 'le-leiste nicht-drucken' }, h('label', {}, 'Auswertung ', gliederung), kn.druck, kn.csv), auswertung);
  await laden();
}
