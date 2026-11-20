// SPDX-License-Identifier: AGPL-3.0-or-later
// „Auswertungen“ (AUSWERTUNGEN.md, F128): Summen- und Saldenliste, Journal, Kontoblätter – und eigene Auswertungen,
// die sich der Nutzer aus denselben Bausteinen zusammenstellt: welche Konten, welche Spalten, welche Gliederung,
// welcher Zeitraum. Eine Auswertung ändert nichts und bucht nichts. Gerechnet wird im Kern (Basisabfragen `summen`
// und `buchungen`); diese Seite ordnet, zeigt, druckt und schreibt CSV. Kopf und Leisten stehen fest, gesperrte
// Knöpfe nennen den Grund, nichts verschiebt sich (F106).
import { h, seitenkopf, knopf, feld, auswahl, sperren, bestaetigen, datumFeld, datumText, heute, zeit, KONTOARTEN } from '/kern/ui/bausteine.js';

const SPALTEN = [['vortrag', 'Vortrag'], ['soll', 'Soll'], ['haben', 'Haben'], ['soll_jahr', 'Soll seit Jahresbeginn'], ['haben_jahr', 'Haben seit Jahresbeginn'], ['saldo', 'Saldo']];
const ZEITRAEUME = [['monat', 'Laufender Monat'], ['vormonat', 'Vormonat'], ['quartal', 'Laufendes Quartal'], ['vorquartal', 'Vorquartal'],
  ['jahr', 'Laufendes Geschäftsjahr'], ['vorjahr', 'Voriges Geschäftsjahr'], ['frei', 'Von – bis']];
const ARTEN = [['konten', 'Kontenliste mit Summen'], ['journal', 'Journal (Buchungen der Reihe nach)'], ['kontoblaetter', 'Kontoblätter (Bewegungen je Konto)']];
const GLIEDERUNGEN = [['kontoart', 'nach Kontoart (Aktiv, Passiv, Aufwand, Ertrag)'], ['eigene', 'eigene Zeilen'], ['keine', 'ohne – alle Konten untereinander']];
const VORGABE = { art: 'konten', zeitraum: 'jahr', entwuerfe: false, spalten: SPALTEN.map((s) => s[0]), ohne_null: true, gliederung: 'kontoart', einzeln: true, konten: '', kontoarten: [], zeilen: [] };
/** Mitgeliefert: nicht änderbar, aber Ausgangspunkt für eigene („Anpassen“, dann „Speichern unter“). */
const MITGELIEFERT = [
  { id: 'fest:susa', name: 'Summen- und Saldenliste', definition: { ...VORGABE } },
  { id: 'fest:erfolg', name: 'Aufwand und Ertrag', definition: { ...VORGABE, spalten: ['soll', 'haben', 'saldo'], kontoarten: ['aufwand', 'ertrag'] } },
  { id: 'fest:journal', name: 'Journal', definition: { ...VORGABE, art: 'journal' } },
  { id: 'fest:kontoblaetter', name: 'Kontoblätter', definition: { ...VORGABE, art: 'kontoblaetter' } },
];

const zahl = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const cent = (dez) => Math.round(Number(dez) * 100);
const vz = (b) => (b.seite === 'H' ? -1 : 1) * cent(b.betrag);              // Soll positiv, Haben negativ
const geld = (c) => zahl.format(c / 100);
const saldoText = (c) => (c === 0 ? geld(0) : `${geld(Math.abs(c))} ${c < 0 ? 'H' : 'S'}`);
const csvZahl = (c) => (c / 100).toFixed(2).replace('.', ',');
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

/** „4200-4299, 4900, 48*“ → Prüffunktion für eine Kontonummer; leer heißt: alle. */
export function kontenFilter(text) {
  const teile = String(text || '').split(/[,;\s]+/).map((s) => s.trim()).filter(Boolean);
  if (teile.length === 0) return () => true;
  const num = (s) => (/^\d+$/.test(s) ? Number(s) : null);
  const regeln = teile.map((t) => {
    if (t.endsWith('*')) { const p = t.slice(0, -1); return (nr) => nr.startsWith(p); }
    const m = t.split(/[-–]/);
    if (m.length === 2 && m[0] && m[1]) {
      const [a, b] = [num(m[0]), num(m[1])];
      return (nr) => (a !== null && b !== null && num(nr) !== null ? num(nr) >= a && num(nr) <= b : nr >= m[0] && nr <= m[1]);
    }
    return (nr) => nr === t;
  });
  return (nr) => regeln.some((r) => r(nr));
}

/** Zeitraum einer Vorgabe als [von, bis] – aus dem heutigen Tag und den Geschäftsjahren des Buchs. */
export function zeitraumZu(art, jahre, tag = heute()) {
  const [j, m] = [Number(tag.slice(0, 4)), Number(tag.slice(5, 7))];
  const monat = (jahr, mon, laenge = 1) => [iso(new Date(jahr, mon - 1, 1)), iso(new Date(jahr, mon - 1 + laenge, 0))];
  const q = Math.floor((m - 1) / 3) * 3 + 1;
  const i = jahre.findIndex((g) => g.beginn <= tag && tag <= g.ende);
  switch (art) {
    case 'monat': return monat(j, m);
    case 'vormonat': return monat(j, m - 1);
    case 'quartal': return monat(j, q, 3);
    case 'vorquartal': return monat(j, q - 3, 3);
    case 'vorjahr': { const g = i > 0 ? jahre[i - 1] : null; return g ? [g.beginn, g.ende] : [`${j - 1}-01-01`, `${j - 1}-12-31`]; }
    default: { const g = jahre[i] || null; return g ? [g.beginn, g.ende] : [`${j}-01-01`, `${j}-12-31`]; }
  }
}

export async function zeigen(b, k) {
  const css = new URL('./auswertungen.css', import.meta.url).href;
  if (!document.querySelector(`link[href="${css}"]`)) document.head.append(h('link', { rel: 'stylesheet', href: css }));
  b.classList.add('listenseite', 'auswertung');
  const basis = `${k.apiBasis}/auswertungen/vorlagen`;
  const merk = `kumero.auswertung.${k.mandant}.${k.buch}`;

  let gespeichert = [];                 // vom Server: eigene und geteilte
  let rechte = { speichern: false, teilen: false };
  let jahre = [];
  let gewaehlt = MITGELIEFERT[0];       // die Auswertung in der Auswahl
  let def = { ...gewaehlt.definition }; // was gerade gilt (kann von der gewählten abweichen: „geändert“)
  let geaendert = false;
  let daten = null;                     // letzte Antwort von `summen`
  let modell = null;                    // was gerade gezeigt wird: Kopf, Zeilen – auch die Quelle für CSV
  let laeuft = 0;

  // ── Leisten: alles steht immer da ──
  const wahl = h('select', { id: 'aw-wahl', class: 'aw-wahl', 'aria-label': 'Auswertung', title: 'Mitgelieferte, eigene und für alle im Buch freigegebene Auswertungen' });
  const zeitraum = auswahl(ZEITRAEUME, def.zeitraum, { id: 'aw-zeitraum', 'aria-label': 'Zeitraum', title: 'Zeitraum nach Buchungsdatum' });
  const von = datumFeld({ id: 'aw-von', 'aria-label': 'von', title: 'Erster Tag des Zeitraums' });
  const bis = datumFeld({ id: 'aw-bis', 'aria-label': 'bis', title: 'Letzter Tag des Zeitraums' });
  const entwuerfe = h('input', { type: 'checkbox', id: 'aw-entwuerfe' });
  const kn = {
    anpassen: knopf('Anpassen …', anpassenDialog, 'primaer', { id: 'aw-anpassen', title: 'Konten, Spalten und Gliederung dieser Auswertung ändern – erst einmal nur für die Anzeige' }),
    speichern: knopf('Speichern', () => speichern(false), '', { id: 'aw-speichern', title: 'Speichert die Änderungen an der eigenen Auswertung' }),
    unter: knopf('Speichern unter …', () => speichern(true), '', { id: 'aw-speichern-unter', title: 'Speichert das, was gerade zu sehen ist, als eigene Auswertung unter einem Namen' }),
    loeschen: knopf('Löschen …', loeschen, '', { id: 'aw-loeschen', title: 'Löscht die eigene Auswertung' }),
    drucken: knopf('Drucken', () => window.print(), '', { id: 'aw-drucken', title: 'Druckansicht; als PDF über „Als PDF speichern“ im Druckdialog' }),
    csv: knopf('CSV', csvLaden, '', { id: 'aw-csv', title: 'Dieselben Zeilen als Datei für ein Tabellenprogramm' }),
  };
  const stand = h('div', { class: 'aw-stand', id: 'aw-stand', role: 'status' });
  const ergebnis = h('div', { class: 'liste-rahmen aw-ergebnis', id: 'aw-ergebnis' });
  b.append(seitenkopf(`Auswertungen – ${k.buchName || k.mandantName || k.mandant}`),
    h('div', { class: 'aw-leiste nicht-drucken' },
      h('label', { class: 'aw-feld' }, h('span', {}, 'Auswertung'), wahl),
      h('label', { class: 'aw-feld' }, h('span', {}, 'Zeitraum'), zeitraum),
      h('span', { class: 'aw-feld' }, h('span', {}, 'von'), von.el), h('span', { class: 'aw-feld' }, h('span', {}, 'bis'), bis.el),
      h('label', { class: 'aw-schalter', title: 'Rechnet Entwürfe mit Buchungsdatum mit. Die Auswertung ist dann vorläufig.' }, entwuerfe, ' Entwürfe mitrechnen')),
    h('div', { class: 'aw-leiste aw-knoepfe nicht-drucken' }, ...Object.values(kn)),
    stand, ergebnis);

  // ── Auswahl ──
  function wahlZeichnen() {
    const gruppe = (titel, liste) => (liste.length ? h('optgroup', { label: titel }, liste.map((v) => h('option', { value: v.id }, v.name))) : null);
    wahl.replaceChildren(gruppe('Mitgeliefert', MITGELIEFERT), gruppe('Meine Auswertungen', gespeichert.filter((v) => v.eigene)),
      gruppe('Für alle im Buch', gespeichert.filter((v) => !v.eigene)));
    wahl.value = gewaehlt.id;
  }
  const alle = () => [...MITGELIEFERT, ...gespeichert];

  function knoepfe() {
    const eigene = gewaehlt.eigene === true;
    const recht = rechte.speichern ? null : 'Dafür fehlt das Recht, Auswertungen zu speichern – ansehen und anpassen geht trotzdem';
    sperren(kn.speichern, recht || (eigene ? (geaendert ? null : 'Nichts geändert') : 'Nur eine eigene Auswertung lässt sich überschreiben – sonst „Speichern unter …“'));
    sperren(kn.unter, recht);
    sperren(kn.loeschen, recht || (eigene ? null : 'Nur eine eigene Auswertung lässt sich löschen'));
    const leer = modell && modell.zeilen.length ? null : 'Es ist nichts zu sehen';
    sperren(kn.drucken, leer);
    sperren(kn.csv, leer);
  }

  function zeitraumSetzen() {
    if (def.zeitraum !== 'frei') { const [a, e] = zeitraumZu(def.zeitraum, jahre); von.setzen(a); bis.setzen(e); }
    else { von.setzen(def.von || zeitraumZu('jahr', jahre)[0]); bis.setzen(def.bis || zeitraumZu('jahr', jahre)[1]); }
    zeitraum.value = def.zeitraum;
    entwuerfe.checked = def.entwuerfe === true;
  }

  function waehlen(id) {
    gewaehlt = alle().find((v) => v.id === id) || MITGELIEFERT[0];
    def = { ...VORGABE, ...structuredClone(gewaehlt.definition) };
    geaendert = false;
    try { localStorage.setItem(merk, gewaehlt.id); } catch { /* nur eine Bequemlichkeit */ }
    wahlZeichnen();
    zeitraumSetzen();
    rechnen();
  }

  // ── Rechnen und zeigen ──
  async function rechnen() {
    const lauf = ++laeuft;
    const [a, e] = [von.wert(), bis.wert()];
    knoepfe();
    if (!a || !e) { leer('Bitte Anfang und Ende des Zeitraums als Datum eingeben.'); return; }
    if (a > e) { leer('Das Ende des Zeitraums liegt vor dem Anfang.'); return; }
    ergebnis.classList.add('laedt');
    try {
      daten = await k.api('GET', `${k.apiBasis}/summen?von=${a}&bis=${e}&entwuerfe=${def.entwuerfe ? 'ja' : 'nein'}`);
      let buchungen = null;
      if (def.art !== 'konten') buchungen = await buchungenLaden(a, e);
      if (lauf !== laeuft) return;
      modell = def.art === 'journal' ? journalModell(buchungen) : (def.art === 'kontoblaetter' ? blaetterModell(buchungen) : kontenModell());
      zeichnen();
    } catch (x) {
      if (lauf !== laeuft) return;
      if (x.problem?.code === 'zeitraum.geschaeftsjahr') {
        leer('Für diesen Zeitraum gibt es kein Geschäftsjahr, oder er reicht über eine Jahresgrenze. Ein Zeitraum liegt immer in einem Geschäftsjahr.');
      } else { leer('Die Auswertung konnte nicht gerechnet werden.'); k.fehler(x); }
    } finally { if (lauf === laeuft) ergebnis.classList.remove('laedt'); }
  }

  function leer(text) {
    modell = null;
    stand.replaceChildren(h('span', { class: 'hinweis' }, ' '));
    ergebnis.replaceChildren(h('p', { class: 'hinweis aw-leer', id: 'aw-leer' }, text));
    knoepfe();
  }

  /** Alle Buchungen des Zeitraums, der Reihe nach (für Journal und Kontoblätter). */
  async function buchungenLaden(a, e) {
    const liste = [];
    let weiter = null;
    do {
      const r = await k.api('GET', `${k.apiBasis}/buchungen?von=${a}&bis=${e}&anzahl=500${def.entwuerfe ? '' : '&status=festgeschrieben'}${weiter ? `&cursor=${encodeURIComponent(weiter)}` : ''}`);
      liste.push(...r.eintraege.filter((x) => x.buchungsdatum));
      weiter = r.weiter;
    } while (weiter && liste.length < 20000);
    liste.abgeschnitten = Boolean(weiter);
    return liste.sort((x, y) => (x.journal_nr ?? 1e15) - (y.journal_nr ?? 1e15) || (x.buchungsdatum < y.buchungsdatum ? -1 : 1));
  }

  const passt = () => { const f = kontenFilter(def.konten); const nur = def.kontoarten?.length ? def.kontoarten : null; return (kto) => f(kto.konto) && (!nur || nur.includes(kto.kontoart)); };

  function kontenModell() {
    const sp = SPALTEN.filter(([s]) => def.spalten.includes(s));
    const kopf = [{ t: 'Konto', kl: 'nr' }, { t: 'Bezeichnung' }, ...sp.map(([, t]) => ({ t, kl: 'betrag' }))];
    const filter = passt();
    const konten = daten.konten.filter((x) => filter(x)).map((x) => ({ ...x,
      w: { vortrag: vz(x.vortrag), soll: cent(x.soll), haben: cent(x.haben), soll_jahr: cent(x.soll_jahr), haben_jahr: cent(x.haben_jahr), saldo: vz(x.saldo) } }));
    const sichtbar = (x) => !def.ohne_null || x.bewegt || x.w.saldo !== 0 || x.w.vortrag !== 0;
    const null6 = () => ({ vortrag: 0, soll: 0, haben: 0, soll_jahr: 0, haben_jahr: 0, saldo: 0 });
    const dazu = (s, w) => { for (const f of Object.keys(s)) s[f] += w[f]; return s; };
    const zellen = (w) => sp.map(([s]) => (s === 'vortrag' || s === 'saldo' ? { t: saldoText(w[s]), c: csvZahl(w[s]) } : { t: geld(w[s]), c: csvZahl(w[s]) }));
    const kontoZeile = (x, tief = false) => ({ kl: tief ? 'aw-konto aw-tief' : 'aw-konto', konto: x.konto, zellen: [{ t: x.konto, c: x.konto }, { t: x.bezeichnung, c: x.bezeichnung }, ...zellen(x.w)] });
    const summe = (titel, w, kl) => ({ kl, zellen: [{ t: '', c: '' }, { t: titel, c: titel }, ...zellen(w)] });
    const zeilen = [];
    const gesamt = null6();
    const hinweise = [];
    if (def.gliederung === 'eigene') {
      const benutzt = new Set();
      let zwischen = null6();
      for (const z of def.zeilen) {
        if (z.summe) { zeilen.push(summe(z.titel, zwischen, 'aw-summe')); zwischen = null6(); continue; }
        const f = kontenFilter(z.konten);
        const treffer = konten.filter((x) => f(x.konto));
        const s = treffer.reduce((acc, x) => dazu(acc, x.w), null6());
        treffer.forEach((x) => benutzt.add(x.konto));
        zeilen.push(summe(z.titel, s, def.einzeln ? 'aw-gruppe' : 'aw-gruppe aw-allein'));
        if (def.einzeln) zeilen.push(...treffer.filter(sichtbar).map((x) => kontoZeile(x, true)));
        dazu(zwischen, s); dazu(gesamt, s);
      }
      const rest = konten.filter((x) => !benutzt.has(x.konto) && (x.bewegt || x.w.saldo !== 0));
      if (rest.length) hinweise.push(`${rest.length === 1 ? '1 bewegtes Konto steht' : `${rest.length} bewegte Konten stehen`} in keiner Zeile: ${rest.slice(0, 12).map((x) => x.konto).join(', ')}${rest.length > 12 ? ' …' : ''}`);
      const doppelt = def.zeilen.filter((z) => !z.summe).flatMap((z) => { const f = kontenFilter(z.konten); return konten.filter((x) => f(x.konto)).map((x) => x.konto); });
      const mehrfach = [...new Set(doppelt.filter((nr, i) => doppelt.indexOf(nr) !== i))];
      if (mehrfach.length) hinweise.push(`In mehr als einer Zeile gezählt: ${mehrfach.slice(0, 12).join(', ')}${mehrfach.length > 12 ? ' …' : ''}`);
    } else if (def.gliederung === 'kontoart') {
      for (const [art, titel] of KONTOARTEN) {
        const gruppe = konten.filter((x) => x.kontoart === art);
        const zeigen = gruppe.filter(sichtbar);
        if (zeigen.length === 0) continue;
        const s = gruppe.reduce((acc, x) => dazu(acc, x.w), null6());
        zeilen.push({ kl: 'aw-titel', zellen: [{ t: '', c: '' }, { t: titel, c: titel }, ...sp.map(() => ({ t: '', c: '' }))] });
        zeilen.push(...zeigen.map((x) => kontoZeile(x)));
        zeilen.push(summe(`Summe ${titel}`, s, 'aw-summe'));
        dazu(gesamt, s);
      }
    } else {
      const zeigen = konten.filter(sichtbar);
      zeilen.push(...zeigen.map((x) => kontoZeile(x)));
      konten.forEach((x) => dazu(gesamt, x.w));
    }
    if (zeilen.length) zeilen.push(summe('Gesamt', gesamt, 'aw-gesamt'));
    // Kontrolle der doppelten Buchführung – nur aussagekräftig, wenn alle Konten in der Auswertung stehen
    const ganz = !def.konten && !def.kontoarten?.length && def.gliederung !== 'eigene';
    if (ganz && zeilen.length) hinweise.unshift(gesamt.soll === gesamt.haben && gesamt.saldo === 0 ? 'Soll und Haben stimmen überein.' : `Soll und Haben stimmen nicht überein (Differenz ${geld(Math.abs(gesamt.soll - gesamt.haben))}).`);
    return { kopf, zeilen, hinweise, pruefung: ganz ? (gesamt.soll === gesamt.haben && gesamt.saldo === 0) : null, anzahl: ((n) => (n === 1 ? '1 Konto' : `${n} Konten`))(zeilen.filter((z) => z.konto).length) };
  }

  const buchungPasst = (bu, f) => bu.zeilen.some((z) => f(z.konto));
  const vermerk = (bu) => (bu.status !== 'festgeschrieben' ? 'Entwurf' : (bu.storno_von ? 'Storno' : (bu.storniert_durch ? 'storniert' : '')));

  function journalModell(buchungen) {
    const f = kontenFilter(def.konten);
    const kopf = [{ t: 'Nr.', kl: 'nr' }, { t: 'Buch.-Datum' }, { t: 'Belegdatum' }, { t: 'Beleg-Nr.' }, { t: 'Text' }, { t: 'Konto', kl: 'nr' }, { t: 'Soll', kl: 'betrag' }, { t: 'Haben', kl: 'betrag' }];
    const zeilen = [];
    let [soll, haben, n] = [0, 0, 0];
    for (const bu of buchungen) {
      if (!buchungPasst(bu, f)) continue;
      n++;
      bu.zeilen.forEach((z, i) => {
        const c = cent(z.betrag);
        if (z.seite === 'S') soll += c; else haben += c;
        const text = i === 0 ? [bu.text || '', vermerk(bu)].filter(Boolean).join(' · ') : '';
        zeilen.push({ kl: i === 0 ? 'aw-buchung' : 'aw-folge', buchung: bu.id, zellen: [
          { t: i === 0 ? (bu.nr || '–') : '', c: bu.nr || '' }, { t: i === 0 ? datumText(bu.buchungsdatum) : '', c: datumText(bu.buchungsdatum) },
          { t: i === 0 ? datumText(bu.belegdatum) : '', c: datumText(bu.belegdatum) }, { t: i === 0 ? (bu.belegnummer || '') : '', c: bu.belegnummer || '' },
          { t: text, c: [bu.text || '', vermerk(bu)].filter(Boolean).join(' · ') }, { t: z.konto, c: z.konto },
          { t: z.seite === 'S' ? geld(c) : '', c: z.seite === 'S' ? csvZahl(c) : '' }, { t: z.seite === 'H' ? geld(c) : '', c: z.seite === 'H' ? csvZahl(c) : '' }] });
      });
    }
    if (zeilen.length) zeilen.push({ kl: 'aw-gesamt', zellen: [{ t: '', c: '' }, { t: '', c: '' }, { t: '', c: '' }, { t: '', c: '' }, { t: 'Gesamt', c: 'Gesamt' }, { t: '', c: '' }, { t: geld(soll), c: csvZahl(soll) }, { t: geld(haben), c: csvZahl(haben) }] });
    const hinweise = buchungen.abgeschnitten ? ['Es sind mehr als 20.000 Buchungen – gezeigt ist nur der Anfang. Bitte den Zeitraum verkleinern.'] : [];
    return { kopf, zeilen, hinweise, pruefung: null, anzahl: n === 1 ? '1 Buchung' : `${n} Buchungen` };
  }

  function blaetterModell(buchungen) {
    const filter = passt();
    const kopf = [{ t: 'Buch.-Datum' }, { t: 'Nr.', kl: 'nr' }, { t: 'Beleg-Nr.' }, { t: 'Text' }, { t: 'Gegenkonto', kl: 'nr' }, { t: 'Soll', kl: 'betrag' }, { t: 'Haben', kl: 'betrag' }, { t: 'Saldo', kl: 'betrag' }];
    const leerz = (n) => Array.from({ length: n }, () => ({ t: '', c: '' }));
    const zeilen = [];
    let blaetter = 0;
    for (const kto of daten.konten) {
      if (!filter(kto)) continue;
      let saldo = vz(kto.vortrag);
      const bew = [];
      for (const bu of buchungen) {
        bu.zeilen.forEach((z) => {
          if (z.konto !== kto.konto) return;
          const gegen = [...new Set(bu.zeilen.filter((y) => y.seite !== z.seite).map((y) => y.konto))];
          bew.push({ bu, z, gegen: gegen.length > 2 ? 'diverse' : gegen.join(', ') });
        });
      }
      bew.sort((x, y) => (x.bu.buchungsdatum < y.bu.buchungsdatum ? -1 : (x.bu.buchungsdatum > y.bu.buchungsdatum ? 1 : (x.bu.journal_nr ?? 1e15) - (y.bu.journal_nr ?? 1e15))));
      if (bew.length === 0 && (def.ohne_null || saldo === 0)) continue;
      blaetter++;
      const name = `${kto.konto} ${kto.bezeichnung}`;
      zeilen.push({ kl: 'aw-titel aw-blatt', konto: kto.konto, zellen: [{ t: name, c: name, breit: 4 }, ...leerz(3), { t: `Vortrag ${saldoText(saldo)}`, c: csvZahl(saldo), kl: 'betrag' }] });
      for (const { bu, z, gegen } of bew) {
        const c = cent(z.betrag);
        saldo += z.seite === 'S' ? c : -c;
        zeilen.push({ kl: 'aw-konto', buchung: bu.id, zellen: [{ t: datumText(bu.buchungsdatum), c: datumText(bu.buchungsdatum) }, { t: bu.nr || '–', c: bu.nr || '' }, { t: bu.belegnummer || '', c: bu.belegnummer || '' },
          { t: [bu.text || '', vermerk(bu)].filter(Boolean).join(' · '), c: bu.text || '' }, { t: gegen, c: gegen },
          { t: z.seite === 'S' ? geld(c) : '', c: z.seite === 'S' ? csvZahl(c) : '' }, { t: z.seite === 'H' ? geld(c) : '', c: z.seite === 'H' ? csvZahl(c) : '' }, { t: saldoText(saldo), c: csvZahl(saldo) }] });
      }
      zeilen.push({ kl: 'aw-summe', zellen: [...leerz(3), { t: `Saldo ${kto.konto} am ${datumText(daten.bis)}`, c: `Saldo ${kto.konto}` }, ...leerz(3), { t: saldoText(saldo), c: csvZahl(saldo) }] });
    }
    const hinweise = buchungen.abgeschnitten ? ['Es sind mehr als 20.000 Buchungen – die Blätter sind unvollständig. Bitte den Zeitraum verkleinern.'] : [];
    return { kopf, zeilen, hinweise, pruefung: null, anzahl: blaetter === 1 ? '1 Kontoblatt' : `${blaetter} Kontoblätter` };
  }

  function zeichnen() {
    // Stand der Auswertung (AUSWERTUNGEN.md Regel 5) – steht auch auf dem Ausdruck
    const marken = [];
    if (daten.entwuerfe) marken.push(h('span', { class: 'marke-klein warnung', id: 'aw-vorlaeufig' }, 'vorläufig – mit Entwürfen'));
    else if (daten.entwuerfe_im_jahr > 0) marken.push(h('span', { class: 'marke-klein', title: 'Nicht festgeschriebene Buchungen mit Buchungsdatum in diesem Geschäftsjahr zählen nicht mit' }, `${daten.entwuerfe_im_jahr} ${daten.entwuerfe_im_jahr === 1 ? 'Entwurf zählt' : 'Entwürfe zählen'} nicht mit`));
    if (k.buchInfo?.testbuch) marken.push(h('span', { class: 'marke-klein warnung' }, 'Testbuch'));
    else if (k.buchInfo?.betriebsart === 'test') marken.push(h('span', { class: 'marke-klein warnung' }, 'Testbetrieb'));
    if (geaendert) marken.push(h('span', { class: 'marke-klein korrigiert nicht-drucken', id: 'aw-geaendert' }, 'angepasst – nicht gespeichert'));
    stand.replaceChildren(
      h('strong', { id: 'aw-titel' }, gewaehlt.name), ' · ', h('span', {}, k.buchName || ''), ' · ',
      h('span', { id: 'aw-zeit' }, `${datumText(daten.von)} – ${datumText(daten.bis)}`), ' · ',
      h('span', {}, daten.stand.nr ? `Stand: bis Buchung ${daten.stand.nr}` : 'Stand: noch keine festgeschriebene Buchung'), ' · ',
      h('span', {}, `erstellt ${zeit(daten.stand.erstellt_am)}`), ...marken);
    if (modell.zeilen.length === 0) {
      ergebnis.replaceChildren(h('p', { class: 'hinweis aw-leer', id: 'aw-leer' }, 'In diesem Zeitraum gibt es dazu nichts.'));
      knoepfe();
      return;
    }
    const zeile = (z) => {
      const tr = h('tr', { class: z.kl || '' }, z.zellen.map((c, i) => h('td', { class: [modell.kopf[i]?.kl || '', c.kl || ''].join(' ').trim(), colspan: c.breit ? String(c.breit) : null }, c.t)).filter((td, i) => !verdeckt(z, i)));
      const ziel = z.buchung ? `${k.seitenBasis}/buchen/erfassen?id=${z.buchung}` : (z.konto ? `${k.seitenBasis}/buchen/kontoblatt?konto=${encodeURIComponent(z.konto)}&von=${daten.von}&bis=${daten.bis}` : null);
      if (ziel) { tr.classList.add('aw-klick'); tr.title = z.buchung ? 'Doppelklick: Buchung öffnen' : 'Doppelklick: Kontoblatt öffnen'; tr.addEventListener('dblclick', () => k.navigieren(ziel)); }
      return tr;
    };
    // eine Zelle mit „breit“ überdeckt die folgenden
    const verdeckt = (z, i) => z.zellen.some((c, j) => c.breit && i > j && i < j + c.breit);
    ergebnis.replaceChildren(
      h('div', { class: 'liste-scroll' }, h('table', { class: 'liste aw-tabelle', id: 'aw-tabelle' },
        h('thead', {}, h('tr', {}, modell.kopf.map((s) => h('th', { class: s.kl || '' }, s.t)))), h('tbody', {}, modell.zeilen.map(zeile)))),
      h('div', { class: 'liste-fuss' }, h('span', { id: 'aw-anzahl' }, modell.anzahl),
        ...modell.hinweise.map((t, i) => h('span', { class: ['aw-hinweis', modell.pruefung === false && i === 0 ? 'aw-falsch' : ''], id: i === 0 ? 'aw-pruefung' : null }, t))));
    knoepfe();
  }

  // ── CSV: dieselben Zeilen, wie sie zu sehen sind (AUSWERTUNGEN.md Abschnitt 4) ──
  function csvLaden() {
    if (!modell) return;
    const zelle = (s) => `"${String(s ?? '').replace(/"/g, '""')}"`;
    const kopf = [[gewaehlt.name], [`Buch: ${k.buchName || ''}`], [`Zeitraum: ${datumText(daten.von)} – ${datumText(daten.bis)}`],
      [daten.stand.nr ? `Stand: bis Buchung ${daten.stand.nr}` : 'Stand: noch keine festgeschriebene Buchung'], ...(daten.entwuerfe ? [['vorläufig – mit Entwürfen']] : []),
      ...(k.buchInfo?.testbuch ? [['Testbuch']] : []), ['Beträge: Soll positiv, Haben negativ, wo eine Spalte beides enthalten kann'], []];
    const zeilen = [...kopf, modell.kopf.map((s) => s.t), ...modell.zeilen.map((z) => z.zellen.map((c) => c.c))];
    const text = `﻿${zeilen.map((z) => z.map(zelle).join(';')).join('\r\n')}\r\n`;
    const url = URL.createObjectURL(new Blob([text], { type: 'text/csv;charset=utf-8' }));
    // Dateiname nur aus ASCII: Umlaute umschrieben – manche Browser verwerfen sonst den ganzen Namen
    const um = { ä: 'ae', ö: 'oe', ü: 'ue', Ä: 'Ae', Ö: 'Oe', Ü: 'Ue', ß: 'ss' };
    const name = `${gewaehlt.name} ${daten.von} bis ${daten.bis}`.replace(/[äöüÄÖÜß]/g, (c) => um[c]).normalize('NFKD').replace(/[^A-Za-z0-9 ._-]+/g, '_').trim();
    const a = h('a', { href: url, download: `${name}.csv`, hidden: true, id: 'aw-csv-link' });
    document.body.append(a);
    a.click();
    setTimeout(() => { a.remove(); URL.revokeObjectURL(url); }, 2000);
    k.melden('CSV erzeugt.', 'erfolg');
  }

  // ── Anpassen: der Baukasten ──
  function dialog(o) {
    const ja = h('button', { type: 'submit', class: 'knopf primaer', id: `${o.id}-senden` }, o.ja);
    const fehler = h('p', { class: 'hinweis fehlertext', id: `${o.id}-fehler`, role: 'alert' }, ' ');
    const dlg = h('dialog', { class: ['dialog', o.breit ? 'aw-dialog' : ''], id: o.id },
      h('form', { onsubmit: async (ev) => {
        ev.preventDefault();
        ja.disabled = true;
        try { const r = await o.senden(); if (r !== false) dlg.close(); } catch (x) {
          fehler.textContent = (x.problem?.fehler || []).map((f) => f.meldung).join(' · ') || x.problem?.detail || x.message || 'Das hat nicht geklappt';
        } finally { ja.disabled = false; }
      } },
      h('h2', {}, o.titel), o.text ? h('p', { class: 'hinweis' }, o.text) : null, ...o.felder, fehler,
      h('div', { class: 'dialog-knoepfe' }, knopf('Abbrechen', () => dlg.close(), '', { id: `${o.id}-abbrechen` }), ja)));
    dlg.addEventListener('close', () => dlg.remove());
    document.body.append(dlg);
    dlg.showModal();
    return { dlg, fehler };
  }

  function anpassenDialog() {
    const d = structuredClone(def);
    const art = auswahl(ARTEN, d.art, { id: 'aw-f-art' });
    const konten = h('input', { id: 'aw-f-konten', size: '40', maxlength: '500', value: d.konten || '', spellcheck: false, autocomplete: 'off', placeholder: 'alle Konten' });
    const arten = KONTOARTEN.map(([a, t]) => h('label', { class: 'aw-wahlzeile' }, h('input', { type: 'checkbox', value: a, checked: !d.kontoarten?.length || d.kontoarten.includes(a) ? '' : null }), ` ${t}`));
    const spalten = SPALTEN.map(([s, t]) => h('label', { class: 'aw-wahlzeile' }, h('input', { type: 'checkbox', value: s, checked: d.spalten.includes(s) ? '' : null }), ` ${t}`));
    const glied = auswahl(GLIEDERUNGEN, d.gliederung, { id: 'aw-f-gliederung' });
    const ohneNull = h('input', { type: 'checkbox', id: 'aw-f-ohne-null', checked: d.ohne_null ? '' : null });
    const einzeln = h('input', { type: 'checkbox', id: 'aw-f-einzeln', checked: d.einzeln ? '' : null });
    const zeilenTabelle = h('tbody', { id: 'aw-f-zeilen' });
    let zeilen = d.zeilen.map((z) => ({ ...z }));
    const zeilenZeichnen = () => {
      zeilenTabelle.replaceChildren(...zeilen.map((z, i) => {
        const titel = h('input', { size: '24', maxlength: '100', value: z.titel, 'aria-label': 'Bezeichnung', placeholder: z.summe ? 'z. B. Kosten gesamt' : 'z. B. Raumkosten', oninput: (ev) => { z.titel = ev.target.value; } });
        const kto = z.summe ? h('span', { class: 'hinweis' }, 'Summe der Zeilen darüber, bis zur vorigen Summe')
          : h('input', { size: '28', maxlength: '500', value: z.konten || '', 'aria-label': 'Konten', placeholder: 'z. B. 4200-4299, 4900', spellcheck: false, oninput: (ev) => { z.konten = ev.target.value; } });
        const bewegen = (um) => { const j = i + um; if (j < 0 || j >= zeilen.length) return; [zeilen[i], zeilen[j]] = [zeilen[j], zeilen[i]]; zeilenZeichnen(); };
        const hoch = knopf('↑', () => bewegen(-1), 'klein', { title: 'nach oben', 'aria-label': 'nach oben' });
        const runter = knopf('↓', () => bewegen(1), 'klein', { title: 'nach unten', 'aria-label': 'nach unten' });
        sperren(hoch, i === 0 ? 'Steht schon oben' : null);
        sperren(runter, i === zeilen.length - 1 ? 'Steht schon unten' : null);
        return h('tr', { class: z.summe ? 'aw-f-summe' : '' }, h('td', {}, titel), h('td', {}, kto),
          h('td', { class: 'aw-f-knoepfe' }, hoch, runter, knopf('Entfernen', () => { zeilen.splice(i, 1); zeilenZeichnen(); }, 'klein', { title: 'Zeile entfernen' })));
      }));
      if (zeilen.length === 0) zeilenTabelle.append(h('tr', {}, h('td', { colspan: '3', class: 'hinweis' }, 'Noch keine Zeile. Eine Zeile fasst die Konten zusammen, die du nennst.')));
    };
    zeilenZeichnen();
    const neuZeile = knopf('Zeile hinzufügen', () => { zeilen.push({ titel: '', konten: '' }); zeilenZeichnen(); zeilenTabelle.querySelector('tr:last-child input')?.focus(); }, '', { id: 'aw-f-zeile-neu' });
    const neuSumme = knopf('Summenzeile hinzufügen', () => { zeilen.push({ titel: '', summe: true }); zeilenZeichnen(); zeilenTabelle.querySelector('tr:last-child input')?.focus(); }, '', { id: 'aw-f-summe-neu', title: 'Summe über die Zeilen seit der vorigen Summe' });
    const teilKonten = h('fieldset', { class: 'aw-gruppe-feld', id: 'aw-f-teil-spalten' }, h('legend', {}, 'Spalten'), ...spalten);
    const teilEigene = h('fieldset', { class: 'aw-gruppe-feld', id: 'aw-f-teil-zeilen' }, h('legend', {}, 'Eigene Zeilen'),
      h('table', { class: 'aw-f-tabelle' }, h('thead', {}, h('tr', {}, h('th', {}, 'Bezeichnung'), h('th', {}, 'Konten'), h('th', {}, ''))), zeilenTabelle),
      h('div', { class: 'aw-f-leiste' }, neuZeile, neuSumme),
      h('label', { class: 'aw-wahlzeile' }, einzeln, ' die Konten unter jeder Zeile einzeln zeigen'));
    // alle Teile stehen immer da; was zur gewählten Art nicht passt, ist gesperrt und sagt warum (F106)
    const passend = () => {
      const istKonten = art.value === 'konten';
      const grund = istKonten ? null : 'Gilt nur für die Kontenliste';
      spalten.forEach((l) => sperren(l.querySelector('input'), grund));
      sperren(glied, grund);
      const eigene = istKonten && glied.value === 'eigene';
      const g2 = eigene ? null : (istKonten ? 'Erst als Gliederung „eigene Zeilen“ wählen' : grund);
      zeilenTabelle.querySelectorAll('input, button').forEach((e) => sperren(e, g2));
      [neuZeile, neuSumme, einzeln].forEach((e) => sperren(e, g2));
      sperren(ohneNull, art.value === 'journal' ? 'Gilt nicht für das Journal' : null);
      arten.forEach((l) => sperren(l.querySelector('input'), art.value === 'journal' ? 'Gilt nicht für das Journal – dort wählst du Konten' : null));
      teilEigene.classList.toggle('aw-aus', !eigene);
    };
    art.addEventListener('change', passend);
    glied.addEventListener('change', passend);
    dialog({ id: 'aw-dialog', breit: true, titel: 'Auswertung anpassen', ja: 'Anzeigen',
      text: 'Stelle zusammen, was du sehen willst. Die Änderung gilt sofort für die Anzeige; gespeichert wird erst mit „Speichern“ oder „Speichern unter …“.',
      felder: [
        h('div', { class: 'aw-f-kopf' }, feld('Was soll die Auswertung zeigen?', art),
          feld('Welche Konten?', konten, 'Leer heißt alle. Einzelne Nummern, Bereiche und Anfänge: 4200-4299, 4900, 48*')),
        h('fieldset', { class: 'aw-gruppe-feld aw-f-reihe', id: 'aw-f-teil-arten' }, h('legend', {}, 'Kontoarten'), ...arten),
        h('div', { class: 'aw-f-kopf' }, feld('Gliederung', glied),
          h('label', { class: 'aw-wahlzeile aw-f-unten' }, ohneNull, ' Konten ohne Bewegung und ohne Saldo weglassen')),
        teilKonten, teilEigene],
      senden: async () => {
        const neu = { ...d, art: art.value, konten: konten.value.trim(), gliederung: glied.value, ohne_null: ohneNull.checked, einzeln: einzeln.checked,
          kontoarten: arten.map((l) => l.querySelector('input')).filter((c) => c.checked).map((c) => c.value),
          spalten: spalten.map((l) => l.querySelector('input')).filter((c) => c.checked).map((c) => c.value), zeilen: zeilen.map((z) => (z.summe ? { titel: z.titel.trim(), summe: true } : { titel: z.titel.trim(), konten: (z.konten || '').trim() })) };
        if (!/^[0-9A-Za-z*.,;\s\-–]*$/.test(neu.konten)) throw new Error('Konten: nur Nummern, Bereiche (4200-4299) und Anfänge (48*), mit Komma getrennt.');
        if (neu.art === 'konten' && neu.spalten.length === 0) throw new Error('Bitte mindestens eine Spalte wählen.');
        if (neu.art === 'konten' && neu.gliederung === 'eigene') {
          if (neu.zeilen.length === 0) throw new Error('Eine eigene Gliederung braucht mindestens eine Zeile.');
          if (neu.zeilen.some((z) => !z.titel)) throw new Error('Jede Zeile braucht eine Bezeichnung.');
          if (neu.zeilen.some((z) => !z.summe && !z.konten)) throw new Error('Jede Zeile braucht Konten – oder ist eine Summenzeile.');
        } else if (neu.gliederung === 'eigene') neu.gliederung = 'kontoart';
        if (neu.kontoarten.length === 0) throw new Error('Bitte mindestens eine Kontoart wählen.');
        if (neu.kontoarten.length === KONTOARTEN.length) neu.kontoarten = [];   // alle = keine Einschränkung
        def = neu;
        geaendert = true;
        rechnen();
      } });
    passend();
    art.focus();
  }

  // ── Speichern, löschen ──
  const definitionZumSpeichern = () => {
    const d = { art: def.art, zeitraum: def.zeitraum, entwuerfe: def.entwuerfe === true, spalten: def.spalten, ohne_null: def.ohne_null, gliederung: def.gliederung, einzeln: def.einzeln, konten: def.konten || '', kontoarten: def.kontoarten || [], zeilen: def.zeilen || [] };
    if (def.zeitraum === 'frei') { d.von = von.wert(); d.bis = bis.wert(); }
    return d;
  };

  async function speichern(unter) {
    if (!unter) {
      try {
        const r = await k.api('PUT', `${basis}/${gewaehlt.id}`, { json: { name: gewaehlt.name, geteilt: gewaehlt.geteilt, definition: definitionZumSpeichern() } });
        await vorlagenLaden();
        gewaehlt = gespeichert.find((v) => v.id === r.id) || gewaehlt;
        geaendert = false;
        wahlZeichnen(); zeichnen();
        k.melden('Gespeichert.', 'erfolg');
      } catch (x) { k.fehler(x); }
      return;
    }
    const name = h('input', { id: 'aw-f-name', required: true, maxlength: '80', size: '36', autocomplete: 'off', value: gewaehlt.eigene ? `${gewaehlt.name} (Kopie)` : (gewaehlt.id.startsWith('fest:') && !geaendert ? `Meine ${gewaehlt.name}` : '') });
    const teilen = h('input', { type: 'checkbox', id: 'aw-f-teilen' });
    sperren(teilen, rechte.teilen ? null : 'Dafür fehlt das Recht, Auswertungen für alle freizugeben');
    dialog({ id: 'aw-dialog-name', titel: 'Auswertung speichern', ja: 'Speichern',
      text: 'Gespeichert wird die Zusammenstellung – Konten, Spalten, Gliederung und Zeitraum –, keine Zahl. Gerechnet wird bei jedem Aufruf neu.',
      felder: [feld('Name', name), h('label', { class: 'aw-wahlzeile' }, teilen, ' für alle in diesem Buch sichtbar')],
      senden: async () => {
        const r = await k.api('POST', basis, { json: { name: name.value.trim(), geteilt: teilen.checked, definition: definitionZumSpeichern() } });
        await vorlagenLaden();
        gewaehlt = gespeichert.find((v) => v.id === r.id) || gewaehlt;
        geaendert = false;
        try { localStorage.setItem(merk, gewaehlt.id); } catch { /* nur eine Bequemlichkeit */ }
        wahlZeichnen(); zeichnen();
        k.melden(`„${r.name}“ gespeichert.`, 'erfolg');
      } });
    name.focus(); name.select();
  }

  async function loeschen() {
    if (!gewaehlt.eigene) return;
    if (!await bestaetigen('Auswertung löschen', `„${gewaehlt.name}“ wird gelöscht${gewaehlt.geteilt ? ' – auch für alle anderen im Buch' : ''}. Buchungen sind davon nicht berührt.`, 'Löschen')) return;
    try {
      await k.api('DELETE', `${basis}/${gewaehlt.id}`);
      await vorlagenLaden();
      k.melden('Gelöscht.', 'erfolg');
      waehlen(MITGELIEFERT[0].id);
    } catch (x) { k.fehler(x); }
  }

  async function vorlagenLaden() {
    const r = await k.api('GET', basis);
    gespeichert = r.vorlagen;
    rechte = { speichern: r.darf_speichern, teilen: r.darf_teilen };
  }

  // ── Bedienung ──
  wahl.addEventListener('change', async () => {
    if (geaendert && !await bestaetigen('Anpassung verwerfen?', 'Die Auswertung ist angepasst und nicht gespeichert. Beim Wechsel geht die Anpassung verloren.', 'Wechseln')) { wahl.value = gewaehlt.id; return; }
    waehlen(wahl.value);
  });
  zeitraum.addEventListener('change', () => { def.zeitraum = zeitraum.value; geaendert = true; zeitraumSetzen(); rechnen(); });
  for (const f of [von, bis]) f.ein.addEventListener('change', () => { def.zeitraum = 'frei'; zeitraum.value = 'frei'; def.von = von.wert(); def.bis = bis.wert(); geaendert = true; rechnen(); });
  entwuerfe.addEventListener('change', () => { def.entwuerfe = entwuerfe.checked; geaendert = true; rechnen(); });

  try {
    [jahre] = await Promise.all([k.api('GET', `${k.apiBasis}/geschaeftsjahre`), vorlagenLaden()]);
  } catch (x) { k.fehler(x); }
  let start = MITGELIEFERT[0].id;
  try { start = localStorage.getItem(merk) || start; } catch { /* ohne Merker */ }
  if (jahre.length === 0) {
    wahlZeichnen(); zeitraumSetzen();
    leer('In diesem Buch gibt es noch kein Geschäftsjahr. Auswertungen brauchen eines (Einstellungen → Buchen einrichten).');
    return undefined;
  }
  waehlen(alle().some((v) => v.id === start) ? start : MITGELIEFERT[0].id);
  return undefined;
}
