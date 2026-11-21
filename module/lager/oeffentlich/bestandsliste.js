// SPDX-License-Identifier: AGPL-3.0-or-later
// „Bestandsliste und Wert“ (LAGER.md Stufe 3, F133): Bestand zu einem Stichtag mit Menge und Wert, nach dem Verfahren
// des Buchs (letzter Einstandspreis oder gleitender Durchschnitt). Führt das Buch Bestand, steht daneben der Saldo der
// Vorratskonten, und die Bestandsveränderung lässt sich als Buchungsentwurf anlegen – festgeschrieben wird wie jede
// Buchung von einem Menschen (F31). Das Lager selbst bucht nie.
// Sortierung und Filter am Spaltenkopf (F91), Spalten verschiebbar (F146). „Gesamt“ ist immer der Wert des ganzen Lagers;
// ist gefiltert, steht darüber die Summe der gezeigten Artikel. Die CSV-Datei enthält immer alle Artikel.
import { h, seitenkopf, knopf, feld, auswahl, sperren, datumFeld, datumText } from '/kern/ui/bausteine.js';
import { tabellenfilter } from '/kern/ui/tabellenfilter.js';
import { ARTEN, mengeText, kontenFilter, dialog, stil } from './gemeinsam.js';

const VERFAHREN = [['letzter', 'letzter Einstandspreis'], ['durchschnitt', 'gleitender Durchschnitt']];
const zahl = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const cent = (dez) => Math.round(Number(dez || 0) * 100);
const geld = (c) => zahl.format(c / 100);

export async function zeigen(b, k) {
  stil();
  b.classList.add('listenseite', 'lager');
  const basis = `${k.apiBasis}/lager`;
  let d = { artikel: [], einstellung: {}, vermerke: [], summe: '0.00', ohne_preis: 0, stichtag: '', heute: '', darf: false };
  let jahre = [];
  let buchungsarten = [];
  let saldo = null;          // Saldo der Vorratskonten zum Stichtag (Cent); null: nicht zu ermitteln
  let saldoGrund = '';
  let status = new Map();    // vermerkte Buchung → entwurf | festgeschrieben

  const tag = datumFeld({ id: 'lb-stichtag', 'aria-label': 'Stichtag' });
  const kn = {
    buchen: knopf('Bestandsveränderung buchen …', buchenDialog, 'primaer', { id: 'lb-buchen', title: 'Legt einen Buchungsentwurf über die Differenz zwischen Lagerwert und Saldo der Vorratskonten an' }),
    einstellung: knopf('Bewertung und Konten …', einstellungDialog, '', { id: 'lb-einstellung', title: 'Verfahren der Bewertung, Preise netto oder brutto, Aufwand oder Bestand, Konten' }),
    drucken: knopf('Drucken', () => window.print(), '', { id: 'lb-drucken', title: 'Bestandsliste drucken; als PDF über den Druckdialog' }),
    csv: knopf('CSV', csvLaden, '', { id: 'lb-csv', title: 'Bestandsliste als Datei für ein Tabellenprogramm' }),
  };
  const stand = h('div', { class: 'lg-grund', id: 'lb-stand', role: 'status' });
  const liste = h('div', { class: 'liste-rahmen lg-liste', id: 'lb-liste' });
  b.append(seitenkopf(`Bestandsliste und Wert – ${k.buchName || k.mandantName || k.mandant}`),
    h('div', { class: 'lg-leiste nicht-drucken' }, h('label', { for: 'lb-stichtag' }, 'Stichtag'), tag.el, h('span', { class: 'lg-luecke' }), ...Object.values(kn)), stand, liste);

  const spalten = [
    { id: 'artikel', titel: 'Artikel', wert: (a) => a.bezeichnung },
    { id: 'nummer', titel: 'Nummer', klasse: 'eng', wert: (a) => a.nummer || '' },
    { id: 'art', titel: 'Art', klasse: 'eng', wert: (a) => ARTEN.find(([w]) => w === a.art)?.[1] || a.art },
    { id: 'menge', titel: 'Menge', klasse: 'zahl', wert: (a) => `${mengeText(a.menge)} ${a.einheit}`, sortwert: (a) => Number(a.menge) },
    { id: 'preis', titel: 'Preis je Einheit', klasse: 'zahl', wert: (a) => (a.preis === null ? 'ohne Preis' : `${Number(a.preis).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 4 })} €`),
      sortwert: (a) => (a.preis === null ? -1 : Number(a.preis)) },
    { id: 'wert', titel: 'Wert', klasse: 'zahl', wert: (a) => (a.wert === null ? '–' : `${geld(cent(a.wert))} €${a.ohne_preis ? ' *' : ''}`), sortwert: (a) => (a.wert === null ? -1 : cent(a.wert)) },
  ];
  const filter = tabellenfilter(spalten, () => zeichnen(), { id: 'lager.bestandsliste' });
  const zellen = (a) => spalten.map((s) => s.wert(a));
  const gj = () => jahre.find((j) => j.beginn <= d.stichtag && d.stichtag <= j.ende) || null;
  const entwurfOffen = () => d.vermerke.some((v) => status.has(v.buchung) && status.get(v.buchung) !== 'festgeschrieben');

  function zeichnen() {
    const e = d.einstellung;
    const wert = cent(d.summe);
    stand.replaceChildren(h('strong', {}, `Bestand zum ${datumText(d.stichtag)}`), ` · ${VERFAHREN.find(([w]) => w === e.bewertung)?.[1] || ''} · Preise ${e.preise === 'brutto' ? 'brutto (kein Vorsteuerabzug)' : 'netto'}`,
      d.ohne_preis ? h('span', { class: 'marke-klein warnung', id: 'lb-ohne', title: 'Zugänge ohne Beleg tragen keinen Einstandspreis. Der Wert dieser Artikel fehlt oder ist unvollständig (mit * markiert).' },
        d.ohne_preis === 1 ? '1 Artikel ohne vollständigen Preis' : `${d.ohne_preis} Artikel ohne vollständigen Preis`) : '',
      k.buchInfo?.testbuch ? h('span', { class: 'marke-klein warnung' }, 'Testbuch') : '');
    const abstimmung = e.buchfuehrung !== 'bestand'
      ? 'Das Buch bucht den Einkauf sofort als Aufwand – der Wert des Lagers ist nachrichtlich und wird nicht gebucht.'
      : saldo === null ? `Abstimmung zum Journal nicht möglich: ${saldoGrund}`
        : saldo === wert ? `Abstimmung: Der Lagerwert entspricht dem Saldo der Vorratskonten (${geld(wert)} €).`
          : `Abstimmung: Lagerwert ${geld(wert)} €, Saldo der Vorratskonten ${geld(saldo)} € – Differenz ${geld(Math.abs(wert - saldo))} €.${entwurfOffen() ? ' Dazu gibt es einen Entwurf, der noch nicht festgeschrieben ist.' : ''}`;
    if (d.artikel.length === 0) {
      liste.replaceChildren(h('p', { class: 'hinweis', id: 'lb-leer' }, `Zum ${datumText(d.stichtag)} gab es keinen Bestand.`), h('div', { class: 'liste-fuss', id: 'lb-abstimmung' }, abstimmung));
    } else {
      filter.setzeZeilen(d.artikel);
      const zeilen = filter.anwenden(d.artikel);
      const sp = filter.spalten();
      // Summenzeilen: Beschriftung in der ersten Spalte, die nicht der Wert ist; der Betrag immer in der Spalte „Wert“
      const erste = sp.find((s) => s.id !== 'wert').id;
      const summe = (titel, c, id) => h('tr', { class: 'lg-gesamt', id }, sp.map((s) => h('td', { class: s.klasse || '' }, s.id === 'wert' ? `${geld(c)} €` : s.id === erste ? titel : '')));
      liste.replaceChildren(h('div', { class: 'liste-scroll' }, h('table', { class: 'liste lg-tabelle', id: 'lb-tabelle' },
        h('thead', {}, filter.kopf),
        h('tbody', {}, zeilen.length ? zeilen.map((a) => h('tr', { 'data-id': a.artikel }, sp.map((s) => h('td', { class: s.klasse || '' }, s.wert(a)))))
          : h('tr', { class: 'leer' }, h('td', { colspan: sp.length, id: 'lb-kein-treffer' }, 'Kein Artikel passt zum Filter.')),
        filter.aktiv() ? summe(`Gezeigt (${zeilen.length} von ${d.artikel.length} Artikeln, gefiltert)`, zeilen.reduce((x, a) => x + cent(a.wert), 0), 'lb-gezeigt') : null,
        summe('Gesamt', wert, 'lb-gesamt')))),
      h('div', { class: 'liste-fuss' }, h('span', { id: 'lb-abstimmung', class: e.buchfuehrung === 'bestand' && saldo !== null && saldo !== wert ? 'lg-warn' : '' }, abstimmung)));
    }
    const diff = saldo === null ? null : wert - saldo;
    sperren(kn.buchen, !d.darf ? 'Dir fehlt das Recht, die Bestandsführung des Lagers zu pflegen' : !d.darf_buchen ? 'Buchungsentwürfe legt die Buchhaltung an – deiner Rolle fehlt das Recht dazu' : e.buchfuehrung !== 'bestand' ? 'Das Buch führt keinen Bestand – einzustellen unter „Bewertung und Konten …“'
      : saldo === null ? saldoGrund : entwurfOffen() ? 'Zum Stichtag gibt es schon einen Entwurf – erst festschreiben oder löschen (Buchungsliste)'
        : diff === 0 ? 'Der Saldo der Vorratskonten entspricht dem Lagerwert – es ist nichts zu buchen' : buchungsarten.length === 0 ? 'Es gibt noch keine Buchungsart' : null);
    sperren(kn.einstellung, d.darf ? null : 'Dir fehlt das Recht, die Einstellungen zu ändern');
    sperren(kn.csv, d.artikel.length ? null : 'Die Liste ist leer');
  }

  async function laden() {
    const w = tag.wert();
    try {
      d = await k.api('GET', `${basis}/bestandsliste${w ? `?stichtag=${w}` : ''}`);
      tag.setzen(d.stichtag);
    } catch (x) { k.fehler(x); zeichnen(); return; }
    // Zustand der vermerkten Buchungen beim Kern erfragen; was es nicht mehr gibt (gelöschter Entwurf), trägt nichts
    status = new Map();
    for (const v of d.vermerke) {
      try { status.set(v.buchung, (await k.api('GET', `${k.apiBasis}/buchungen/${v.buchung}`)).status); } catch (x) {
        if (x.problem?.status === 404 && d.darf) { try { await k.api('POST', `${basis}/abschluss/entfernen`, { json: { stichtag: d.stichtag, buchung: v.buchung } }); } catch { /* beim nächsten Mal */ } }
      }
    }
    saldo = null; saldoGrund = '';
    const e = d.einstellung;
    if (e.buchfuehrung === 'bestand') {
      const j = gj();
      if (!e.vorratskonten) saldoGrund = 'erst unter „Bewertung und Konten …“ die Vorratskonten nennen';
      else if (!j) saldoGrund = `für den ${datumText(d.stichtag)} gibt es kein Geschäftsjahr`;
      else {
        try {
          const f = kontenFilter(e.vorratskonten);
          const su = await k.api('GET', `${k.apiBasis}/summen?von=${j.beginn}&bis=${d.stichtag}`);
          saldo = su.konten.filter((x) => f(x.konto)).reduce((s, x) => s + (x.saldo.seite === 'H' ? -1 : 1) * cent(x.saldo.betrag), 0);
        } catch (x) { saldoGrund = x.problem?.detail || 'der Saldo der Vorratskonten ist nicht zu lesen'; }
      }
    }
    zeichnen();
  }

  function buchenDialog() {
    const e = d.einstellung;
    const diff = cent(d.summe) - saldo;
    const mehr = diff > 0;
    const betrag = (Math.abs(diff) / 100).toFixed(2);
    const artWahl = auswahl(buchungsarten.map((x) => [x.kuerzel, `${x.kuerzel} – ${x.bezeichnung}`]), buchungsarten[0]?.kuerzel, { id: 'lb-f-buchungsart' });
    dialog({ id: 'lb-dialog-buchen', titel: `Bestandsveränderung zum ${datumText(d.stichtag)}`, ja: 'Entwurf anlegen',
      text: `Lagerwert ${geld(cent(d.summe))} €, Saldo der Vorratskonten ${geld(saldo)} €. Es entsteht ein Buchungsentwurf über die Differenz; er steht danach in der Buchungsliste und wird dort festgeschrieben.`
        + (d.ohne_preis ? ' Achtung: Nicht alle Artikel haben einen vollständigen Preis – ihr Wert fehlt in der Summe.' : ''),
      felder: [feld('Buchungsart des Entwurfs', artWahl),
        h('table', { class: 'lg-chargen', id: 'lb-buchung' }, h('thead', {}, h('tr', {}, ['Buchung', 'Soll', 'Haben', 'Betrag'].map((t, i) => h('th', { class: i === 3 ? 'zahl' : '' }, t)))),
          h('tbody', {}, h('tr', {}, h('td', {}, mehr ? 'Bestandserhöhung' : 'Bestandsminderung'), h('td', {}, mehr ? e.bestandskonto : e.aufwandskonto), h('td', {}, mehr ? e.aufwandskonto : e.bestandskonto), h('td', { class: 'zahl' }, `${geld(Math.abs(diff))} €`))))],
      senden: async () => {
        if (!artWahl.value) throw new Error('Bitte eine Buchungsart wählen.');
        try {
          const bu = await k.api('POST', `${k.apiBasis}/buchungen`, { json: { buchungsart: artWahl.value, belegdatum: d.stichtag, buchungsdatum: d.stichtag, text: `Bestandsveränderung Lager zum ${datumText(d.stichtag)}`,
            zeilen: [{ konto: mehr ? e.bestandskonto : e.aufwandskonto, seite: 'S', betrag }, { konto: mehr ? e.aufwandskonto : e.bestandskonto, seite: 'H', betrag }] } });
          await k.api('POST', `${basis}/abschluss`, { json: { stichtag: d.stichtag, buchung: bu.id, betrag, richtung: mehr ? 'erhoehung' : 'minderung' } });
        } finally { await laden(); }
        k.melden('Entwurf angelegt – er steht in der Buchungsliste.', 'erfolg');
      } });
  }

  function einstellungDialog() {
    const e = d.einstellung;
    const f = {
      bewertung: auswahl(VERFAHREN, e.bewertung, { id: 'lb-f-bewertung' }),
      preise: auswahl([['netto', 'netto – das Buch zieht Vorsteuer ab'], ['brutto', 'brutto – kein Vorsteuerabzug (z. B. steuerfreie Heilbehandlungen)']], e.preise, { id: 'lb-f-preise' }),
      fuehrung: auswahl([['aufwand', 'sofort Aufwand – der Wert des Lagers ist nachrichtlich'], ['bestand', 'Bestand führen – Bestandsveränderung zum Stichtag buchen']], e.buchfuehrung, { id: 'lb-f-fuehrung' }),
      vorrat: h('input', { id: 'lb-f-vorrat', maxlength: '500', value: e.vorratskonten || '', placeholder: 'z. B. 3970-3989' }),
      bestand: h('input', { id: 'lb-f-bestand', maxlength: '20', class: 'lg-kurz', value: e.bestandskonto || '' }),
      aufwand: h('input', { id: 'lb-f-aufwand', maxlength: '20', class: 'lg-kurz', value: e.aufwandskonto || '' }),
    };
    const regeln = () => { for (const el of [f.vorrat, f.bestand, f.aufwand]) { el.disabled = f.fuehrung.value !== 'bestand'; el.title = el.disabled ? 'Nur bei Bestandsführung' : ''; } };
    f.fuehrung.addEventListener('change', regeln); regeln();
    dialog({ id: 'lb-dialog-einstellung', breit: true, titel: 'Bewertung und Konten des Lagers', ja: 'Speichern',
      text: 'Die Preise gelten für künftige Zugänge aus Belegen; schon gebuchte Zugänge behalten ihren Preis.',
      felder: [h('div', { class: 'lg-reihe' }, feld('Bewertung', f.bewertung), feld('Einstandspreise', f.preise)), feld('Buchführung', f.fuehrung),
        h('div', { class: 'lg-reihe' }, feld('Vorratskonten (für die Abstimmung)', f.vorrat, 'Nummern, Bereiche oder Anfänge, durch Komma getrennt'), feld('Bestandskonto', f.bestand), feld('Konto der Bestandsveränderung', f.aufwand))],
      senden: async () => {
        await k.api('PUT', `${basis}/einstellung`, { json: { bewertung: f.bewertung.value, preise: f.preise.value, buchfuehrung: f.fuehrung.value,
          vorratskonten: f.vorrat.value.trim(), bestandskonto: f.bestand.value.trim() || null, aufwandskonto: f.aufwand.value.trim() || null } });
        await laden(); k.melden('Einstellungen gespeichert.', 'erfolg');
      } });
  }

  function csvLaden() {
    const zelle = (s) => `"${String(s ?? '').replace(/"/g, '""')}"`;
    const zeilen = [['Bestandsliste'], [`Buch: ${k.buchName || ''}`], [`Stichtag: ${datumText(d.stichtag)}`], [`Bewertung: ${VERFAHREN.find(([w]) => w === d.einstellung.bewertung)?.[1] || ''}`], [],
      spalten.map((s) => s.titel), ...d.artikel.map(zellen), ['Gesamt', '', '', '', '', `${geld(cent(d.summe))} €`]];
    const blob = new Blob(['﻿', zeilen.map((z) => z.map(zelle).join(';')).join('\r\n'), '\r\n'], { type: 'text/csv;charset=utf-8' });
    const a = h('a', { href: URL.createObjectURL(blob), download: `Bestandsliste-${d.stichtag}.csv` });
    document.body.append(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  }

  tag.ein.addEventListener('change', () => { if (tag.wert()) laden(); });
  try { [jahre, buchungsarten] = await Promise.all([k.api('GET', `${k.apiBasis}/geschaeftsjahre`), k.api('GET', `${k.apiBasis}/buchungsarten`)]); } catch { /* ohne Rechte im Kern: keine Abstimmung */ }
  await laden();
}
