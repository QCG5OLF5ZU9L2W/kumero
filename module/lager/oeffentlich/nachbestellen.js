// SPDX-License-Identifier: AGPL-3.0-or-later
// „Nachbestellen“ (LAGER.md Stufe 2, F132): Artikel, deren verfügbarer Bestand unter dem Mindestbestand liegt – mit
// Vorschlag bis zum Sollbestand, in Bestelleinheiten des letzten Lieferanten, mit dessen Artikelnummer und Preis.
// kumero bestellt nicht selbst (WW20): Die Liste wird gedruckt oder als CSV gespeichert.
import { h, seitenkopf, knopf, sperren, datumText } from '/kern/ui/bausteine.js';
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
  const spalten = [['Artikel', ''], ['Nummer', 'eng'], ['Verfügbar', 'zahl'], ['Mindest', 'zahl'], ['Soll', 'zahl'], ['Vorschlag', 'zahl'], ['Bestellen', 'zahl'], ['Lieferant', ''], ['Art.-Nr. dort', 'eng'], ['Letzter Preis', 'zahl']];
  const zellen = (a) => [a.bezeichnung + (Number(a.nicht_verfuegbar) > 0 ? ` (${mengeText(a.nicht_verfuegbar)} ${a.einheit} gesperrt oder abgelaufen)` : ''), a.nummer || '',
    `${mengeText(a.verfuegbar)} ${a.einheit}`, mengeText(a.mindest), a.soll === null ? '' : mengeText(a.soll), `${mengeText(a.vorschlag)} ${a.einheit}`,
    a.faktor !== '1' && a.bestelleinheiten !== null ? `${a.bestelleinheiten} × ${mengeText(a.faktor)}` : mengeText(a.vorschlag),
    a.lieferant || '', a.lieferant_nummer || '', a.letzter_preis === null ? '' : `${preis(a.letzter_preis)} je ${a.einheit}${a.letzter_zugang ? ` (${datumText(a.letzter_zugang)})` : ''}`];

  function zeichnen() {
    stand.textContent = d.artikel.length ? `${d.artikel.length} Artikel unter Mindestbestand · Stand ${datumText(d.heute)} · verfügbar ist, was weder abgelaufen noch gesperrt ist` : '';
    if (d.artikel.length === 0) {
      liste.replaceChildren(h('p', { class: 'hinweis', id: 'nb-leer' }, 'Kein Artikel liegt unter seinem Mindestbestand. Den Mindest- und den Sollbestand trägst du am Artikel ein (Bestand → „Artikel bearbeiten …“).'));
    } else {
      liste.replaceChildren(h('div', { class: 'liste-scroll' }, h('table', { class: 'liste lg-tabelle', id: 'nb-tabelle' },
        h('thead', {}, h('tr', {}, spalten.map(([t, kl]) => h('th', { class: kl, title: t === 'Bestellen' ? 'Bestelleinheiten des Lieferanten × Lagereinheiten je Bestelleinheit' : '' }, t)))),
        h('tbody', {}, d.artikel.map((a) => h('tr', { 'data-id': a.artikel }, zellen(a).map((t, i) => h('td', { class: spalten[i][1] }, t))))))),
      h('div', { class: 'liste-fuss' }, 'Vorschlag = Sollbestand (sonst Mindestbestand) − verfügbar. kumero bestellt nicht selbst.'));
    }
    sperren(kn.csv, d.artikel.length ? null : 'Die Liste ist leer');
    sperren(kn.drucken, d.artikel.length ? null : 'Die Liste ist leer');
  }

  function csvLaden() {
    const zelle = (s) => `"${String(s ?? '').replace(/"/g, '""')}"`;
    const zeilen = [['Bestellliste'], [`Buch: ${k.buchName || ''}`], [`Stand: ${datumText(d.heute)}`], [], spalten.map(([t]) => t), ...d.artikel.map(zellen)];
    const blob = new Blob(['﻿', zeilen.map((z) => z.map(zelle).join(';')).join('\r\n'), '\r\n'], { type: 'text/csv;charset=utf-8' });
    const a = h('a', { href: URL.createObjectURL(blob), download: `Bestellliste-${d.heute}.csv` });
    document.body.append(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  }

  try { d = await k.api('GET', `${k.apiBasis}/lager/nachbestellung`); } catch (x) { k.fehler(x); }
  zeichnen();
}
