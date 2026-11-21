// SPDX-License-Identifier: AGPL-3.0-or-later
// „Nachbestellen“ (LAGER.md Stufe 2, F132): Artikel, deren verfügbarer Bestand unter dem Mindestbestand liegt – mit
// Vorschlag bis zum Sollbestand, in Bestelleinheiten des letzten Lieferanten, mit dessen Artikelnummer und Preis.
// kumero bestellt nicht selbst (WW20): Die Liste wird gedruckt oder als CSV gespeichert.
// Sortierung und Filter am Spaltenkopf (F91), Spalten verschiebbar (F146). Gedruckt wird, was zu sehen ist; die CSV-Datei
// enthält immer alle Artikel in der Reihenfolge der Vorgabe.
import { h, seitenkopf, knopf, sperren, datumText } from '/kern/ui/bausteine.js';
import { tabellenfilter } from '/kern/ui/tabellenfilter.js';
import { mengeText, stil } from './gemeinsam.js';

export async function zeigen(b, k) {
  stil();
  b.classList.add('listenseite', 'lager');
  let d = { artikel: [], heute: '' };
  const kn = {
    drucken: knopf('Drucken', () => window.print(), '', { id: 'nb-drucken', title: 'Bestellliste drucken; als PDF über den Druckdialog' }),
    csv: knopf('CSV', csvLaden, '', { id: 'nb-csv', title: 'Bestellliste als Datei für ein Tabellenprogramm' }),
  };
  const stand = h('div', { class: 'lg-grund', id: 'nb-stand', role: 'status' });
  const liste = h('div', { class: 'liste-rahmen lg-liste', id: 'nb-liste' });
  b.append(seitenkopf(`Nachbestellen – ${k.buchName || k.mandantName || k.mandant}`), h('div', { class: 'lg-leiste nicht-drucken' }, kn.drucken, kn.csv), stand, liste);
  const preis = (p) => (p === null ? '' : `${Number(p).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 4 })} €`);
  const zahlOderLeer = (w) => (w === null ? -1 : Number(w));
  const spalten = [
    { id: 'artikel', titel: 'Artikel', wert: (a) => a.bezeichnung + (Number(a.nicht_verfuegbar) > 0 ? ` (${mengeText(a.nicht_verfuegbar)} ${a.einheit} gesperrt oder abgelaufen)` : '') },
    { id: 'nummer', titel: 'Nummer', klasse: 'eng', wert: (a) => a.nummer || '' },
    { id: 'verfuegbar', titel: 'Verfügbar', klasse: 'zahl', wert: (a) => `${mengeText(a.verfuegbar)} ${a.einheit}`, sortwert: (a) => Number(a.verfuegbar) },
    { id: 'mindest', titel: 'Mindest', klasse: 'zahl', wert: (a) => mengeText(a.mindest), sortwert: (a) => Number(a.mindest) },
    { id: 'soll', titel: 'Soll', klasse: 'zahl', wert: (a) => (a.soll === null ? '' : mengeText(a.soll)), sortwert: (a) => zahlOderLeer(a.soll) },
    { id: 'vorschlag', titel: 'Vorschlag', klasse: 'zahl', wert: (a) => `${mengeText(a.vorschlag)} ${a.einheit}`, sortwert: (a) => Number(a.vorschlag) },
    { id: 'bestellen', titel: 'Bestellen', klasse: 'zahl', hinweis: 'Bestelleinheiten des Lieferanten × Lagereinheiten je Bestelleinheit',
      wert: (a) => (a.faktor !== '1' && a.bestelleinheiten !== null ? `${a.bestelleinheiten} × ${mengeText(a.faktor)}` : mengeText(a.vorschlag)),
      sortwert: (a) => (a.faktor !== '1' && a.bestelleinheiten !== null ? Number(a.bestelleinheiten) : Number(a.vorschlag)) },
    { id: 'lieferant', titel: 'Lieferant', wert: (a) => a.lieferant || '' },
    { id: 'lieferant_nummer', titel: 'Art.-Nr. dort', klasse: 'eng', wert: (a) => a.lieferant_nummer || '' },
    { id: 'preis', titel: 'Letzter Preis', klasse: 'zahl', wert: (a) => (a.letzter_preis === null ? '' : `${preis(a.letzter_preis)} je ${a.einheit}${a.letzter_zugang ? ` (${datumText(a.letzter_zugang)})` : ''}`),
      sortwert: (a) => zahlOderLeer(a.letzter_preis) },
  ];
  const filter = tabellenfilter(spalten, () => zeichnen(), { id: 'lager.nachbestellen' });

  function zeichnen() {
    stand.textContent = d.artikel.length ? `${d.artikel.length} Artikel unter Mindestbestand · Stand ${datumText(d.heute)} · verfügbar ist, was weder abgelaufen noch gesperrt ist` : '';
    if (d.artikel.length === 0) {
      liste.replaceChildren(h('p', { class: 'hinweis', id: 'nb-leer' }, 'Kein Artikel liegt unter seinem Mindestbestand. Den Mindest- und den Sollbestand trägst du am Artikel ein (Bestand → „Artikel bearbeiten …“).'));
    } else {
      filter.setzeZeilen(d.artikel);
      const zeilen = filter.anwenden(d.artikel);
      const sp = filter.spalten();
      liste.replaceChildren(h('div', { class: 'liste-scroll' }, h('table', { class: 'liste lg-tabelle', id: 'nb-tabelle' },
        h('thead', {}, filter.kopf),
        h('tbody', {}, zeilen.length ? zeilen.map((a) => h('tr', { 'data-id': a.artikel }, sp.map((s) => h('td', { class: s.klasse || '' }, s.wert(a)))))
          : h('tr', { class: 'leer' }, h('td', { colspan: sp.length, id: 'nb-kein-treffer' }, 'Kein Artikel passt zum Filter.'))))),
      h('div', { class: 'liste-fuss' }, h('span', { id: 'nb-anzahl' }, filter.aktiv() ? `${zeilen.length} von ${d.artikel.length} Artikeln · gefiltert · ` : ''), 'Vorschlag = Sollbestand (sonst Mindestbestand) − verfügbar. kumero bestellt nicht selbst.'));
    }
    sperren(kn.csv, d.artikel.length ? null : 'Die Liste ist leer');
    sperren(kn.drucken, d.artikel.length ? null : 'Die Liste ist leer');
  }

  function csvLaden() {
    const zelle = (s) => `"${String(s ?? '').replace(/"/g, '""')}"`;
    const zeilen = [['Bestellliste'], [`Buch: ${k.buchName || ''}`], [`Stand: ${datumText(d.heute)}`], [], spalten.map((s) => s.titel), ...d.artikel.map((a) => spalten.map((s) => s.wert(a)))];
    const blob = new Blob(['﻿', zeilen.map((z) => z.map(zelle).join(';')).join('\r\n'), '\r\n'], { type: 'text/csv;charset=utf-8' });
    const a = h('a', { href: URL.createObjectURL(blob), download: `Bestellliste-${d.heute}.csv` });
    document.body.append(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  }

  try { d = await k.api('GET', `${k.apiBasis}/lager/nachbestellung`); } catch (x) { k.fehler(x); }
  zeichnen();
}
