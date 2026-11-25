// SPDX-License-Identifier: AGPL-3.0-or-later
// „Wareneingang“ (LAGER.md Stufe 1, F131): Die Positionen eines Belegs aus dem Arbeitsvorrat sind ein Vorschlag (WW9).
// Der Mensch ordnet jede Position einem Artikel zu (einmal je Lieferantenartikel, danach gemerkt), erfasst Charge und
// Verfall – von Hand oder mit dem Scanner – und bucht den Zugang. Erst dann ändert sich der Bestand.
// Sortierung und Filter an den Spalten des Belegs (F91; Spalten mit Eingabefeldern sind ausgenommen), alle Spalten
// verschiebbar (F146). Ein Filter verdeckt Zeilen nur: geprüft, gescannt und gebucht wird immer über alle Positionen.
import { h, seitenkopf, knopf, auswahl, sperren, datumFeld, datumText } from '/kern/ui/bausteine.js';
import { tabellenfilter } from '/kern/ui/tabellenfilter.js';
import { mengeText, mengeLesen, codeLesen, einheitText, stil } from './gemeinsam.js';

const KEIN = '-';

export async function zeigen(b, k) {
  stil();
  b.classList.add('listenseite', 'lager');
  const basis = `${k.apiBasis}/lager`;
  let stand = { artikel: [], orte: [], darf: {} };
  let eintraege = [];
  let e = null;          // geladener Eintrag mit Positionen
  let zeilen = [];       // je Position die Eingabefelder

  const wahl = h('select', { id: 'we-beleg', 'aria-label': 'Beleg', title: 'Belege aus dem Arbeitsvorrat, die Positionen tragen' });
  const scan = h('input', { id: 'we-scan', class: 'lg-suche', autocomplete: 'off', placeholder: 'Packung scannen – füllt Charge und Verfall der passenden Position', 'aria-label': 'Code scannen' });
  const buchen = knopf('Zugang buchen', zugangBuchen, 'primaer', { id: 'we-buchen', title: 'Bucht alle vollständigen Positionen als Zugang' });
  const grund = h('div', { class: 'lg-grund', id: 'we-grund', role: 'status' });
  const kopf = h('div', { class: 'lg-we-kopf', id: 'we-kopf' });
  const liste = h('div', { class: 'liste-rahmen lg-liste', id: 'we-liste' });
  const fuss = h('div', { class: 'lg-we-fuss', id: 'we-fuss' });
  b.append(seitenkopf(`Wareneingang – ${k.buchName || k.mandantName || k.mandant}`),
    h('div', { class: 'lg-leiste' }, h('label', { for: 'we-beleg' }, 'Beleg'), wahl, h('span', { class: 'lg-luecke' }),
      // Ohne Beleg geht Ware unter „Bestand“ zu – der Knopf führt dorthin und öffnet dort den Dialog (F197)
      h('a', { class: 'knopf', id: 'we-ohne-beleg', href: `${k.buchSeiten(k.buch)}/lager/bestand`, title: 'Anfangsbestand, Muster oder Ware ohne E-Rechnung: führt zu „Bestand“ und öffnet dort „Zugang ohne Beleg“ für den markierten Artikel',
        onclick: () => { try { sessionStorage.setItem('kumero.lager.zugang', '1'); } catch { /* dann öffnet sich nur die Seite */ } } }, 'Zugang ohne Beleg …'), buchen),
    grund, kopf, h('div', { class: 'lg-leiste' }, h('label', { for: 'we-scan' }, h('strong', {}, 'Code scannen')), scan,
      h('span', { class: 'hinweis' }, 'Der Scanner schreibt wie eine Tastatur. Zerlegt wird im Browser.')), liste, fuss);

  const spalten = [
    { id: 'pos', titel: 'Pos.', klasse: 'eng', wert: (z) => String(z.p.nr), sortwert: (z) => Number(z.p.nr) },
    { id: 'beleg', titel: 'Laut Beleg', wert: (z) => z.p.bezeichnung },
    { id: 'netto', titel: 'Betrag netto', klasse: 'zahl', wert: (z) => (z.p.netto ? `${Number(z.p.netto).toLocaleString('de-DE', { minimumFractionDigits: 2 })} €` : ''), sortwert: (z) => Number(z.p.netto || 0) },
    { id: 'artikel', titel: 'Artikel im Lager', fest: true },
    { id: 'zugang', titel: 'Zugang', fest: true },
    { id: 'charge', titel: 'Charge', fest: true },
    { id: 'verfall', titel: 'Verfall', fest: true },
    { id: 'ort', titel: 'Lagerort und Stand', fest: true },
  ];
  const filter = tabellenfilter(spalten, () => tabelleZeichnen(), { id: 'lager.wareneingang' });

  /** Zeilen und Zellen in der gewählten Ordnung einhängen – die Eingabefelder bleiben dieselben, nichts geht verloren */
  function tabelleZeichnen() {
    if (!e) return;
    filter.setzeZeilen(zeilen);
    const sichtbar = filter.anwenden(zeilen);
    const sp = filter.spalten();
    for (const z of zeilen) z.tr.replaceChildren(...sp.map((x) => z.td[x.id]));
    liste.replaceChildren(h('div', { class: 'liste-scroll' }, h('table', { class: 'liste lg-tabelle lg-we-tabelle', id: 'we-tabelle' },
      h('thead', {}, filter.kopf), h('tbody', {}, sichtbar.length ? sichtbar.map((z) => z.tr)
        : h('tr', { class: 'leer' }, h('td', { colspan: sp.length, id: 'we-kein-treffer' }, 'Keine Position passt zum Filter.'))))),
    h('div', { class: 'liste-fuss', id: 'we-anzahl' }, filter.aktiv() ? `${sichtbar.length} von ${zeilen.length} Positionen · gefiltert – geprüft und gebucht wird über alle Positionen` : (zeilen.length === 1 ? '1 Position' : `${zeilen.length} Positionen`)));
  }

  const artikel = (id) => stand.artikel.find((a) => a.id === id) || null;
  const waehlbar = () => stand.artikel.filter((a) => !a.stillgelegt && !a.btm);
  const orte = () => stand.orte.filter((o) => !o.stillgelegt);

  /** Zustand einer Zeile: [kennung, text, marke] – in Worten, nicht nur als Farbe */
  function zustand(z) {
    if (z.rueckgabe) return ['aus', 'Rückgabe – kein Zugang', ''];
    if (z.wahl.value === KEIN) return ['aus', 'kein Lagerartikel', ''];
    if (z.rest <= 0 && z.p.zugaenge.length) return ['fertig', 'zugegangen', 'korrigiert'];
    const a = artikel(z.wahl.value);
    if (!a) return ['offen', 'Artikel wählen', 'warnung'];
    // Charge und Seriennummer zugleich (F211) braucht zwei Felder – die hat der Wareneingang noch nicht
    if (a.hat_charge && a.hat_serie) return ['fehlt', 'hat Charge und Seriennummer: Zugang über „Bestand“', 'warnung'];
    if (mengeLesen(z.menge.value) === null) return ['fehlt', 'Menge fehlt', 'warnung'];
    if (a.fuehrung !== 'ohne' && z.charge.value.trim() === '') return ['fehlt', a.fuehrung === 'serie' ? 'Seriennummer fehlt' : 'Charge fehlt', 'warnung'];
    if (z.verfall.wert() === null) return ['fehlt', 'Verfall unlesbar', 'warnung'];
    if (a.verfall_pflicht && !z.verfall.wert()) return ['fehlt', 'Verfall fehlt', 'warnung'];
    if (a.fuehrung === 'serie' && mengeLesen(z.menge.value) !== '1') return ['fehlt', 'Seriennummer: je Stück eine Zeile, Menge 1', 'warnung'];
    if (!z.ort.value) return ['fehlt', 'Lagerort fehlt', 'warnung'];
    return ['bereit', z.gescannt ? 'vollständig · gescannt' : 'vollständig', 'erfolg'];
  }

  function pruefen() {
    let bereit = 0;
    const fehlt = [];
    for (const z of zeilen) {
      const [kennung, text, art] = zustand(z);
      z.marke.className = `marke-klein ${art === 'erfolg' ? 'korrigiert' : art}`;
      z.marke.textContent = text;
      z.tr.classList.toggle('lg-fehlt', kennung === 'fehlt' || kennung === 'offen');
      const a = artikel(z.wahl.value);
      const aus = kennung === 'aus' || kennung === 'fertig' || !a;
      for (const el of [z.faktor, z.menge, z.ort]) el.disabled = aus;
      z.charge.disabled = aus || a.fuehrung === 'ohne';
      z.verfall.sperren(aus || a.fuehrung === 'ohne');
      z.einheit.textContent = a ? a.einheit : '';
      if (kennung === 'bereit') bereit += 1;
      if (kennung === 'fehlt' || kennung === 'offen') fehlt.push(`Position ${z.p.nr}: ${text}`);
    }
    const keinRecht = !stand.darf.buchen ? 'Dir fehlt das Recht, im Lager zu buchen' : null;
    sperren(buchen, keinRecht || (!e ? 'Erst einen Beleg wählen' : bereit === 0 ? (fehlt[0] || 'Keine Position ist zu buchen') : null));
    grund.className = `lg-grund${fehlt.length ? ' an' : ''}`;
    grund.textContent = !e ? '' : fehlt.length ? `${bereit} Position(en) vollständig. Noch offen – ${fehlt.slice(0, 3).join(' · ')}${fehlt.length > 3 ? ' …' : ''}` : bereit ? `${bereit} Position(en) vollständig.` : 'Alle Positionen sind erledigt.';
  }

  function zeichnen() {
    if (!e) {
      kopf.replaceChildren(h('span', { class: 'hinweis', id: 'we-leer' }, stand.vorrat === false
        ? 'Das Modul „Arbeitsvorrat“ ist in diesem Buch nicht aktiv – Zugänge aus Belegen gibt es nur mit ihm. Ware ohne solchen Beleg buchst du mit „Zugang ohne Beleg …“ oben rechts.'
        : 'Kein Beleg mit Positionen. Positionen kommen aus E-Rechnungen (XRechnung, ZUGFeRD), die im Arbeitsvorrat eingelesen sind. Ware ohne solchen Beleg buchst du mit „Zugang ohne Beleg …“ oben rechts.'));
      liste.replaceChildren(); fuss.replaceChildren(); zeilen = []; filter.setzeZeilen([]);
      pruefen();
      return;
    }
    const x = e.eintrag;
    kopf.replaceChildren(
      h('span', {}, h('strong', {}, x.betreff)), h('span', {}, x.gegenpartei || 'Lieferant unbekannt'), h('span', {}, x.datum ? datumText(x.datum) : ''),
      h('span', {}, { offen: 'noch nicht gebucht', in_arbeit: 'Buchung in Arbeit', gebucht: 'gebucht' }[x.zustand] || x.zustand),
      e.lieferant === '' ? h('span', { class: 'marke-klein warnung', title: 'Ohne Lieferant am Eintrag lässt sich die Zuordnung der Positionen nicht merken' }, 'ohne Lieferant – nichts wird gemerkt') : '');
    zeilen = e.positionen.map((p) => {
      const erwartetRoh = Number(p.menge || 0);
      const z = { p, rueckgabe: erwartetRoh < 0, gescannt: null };
      const zu = p.zuordnung;
      z.wahl = auswahl([['', '– Artikel wählen –'], ...waehlbar().map((a) => [a.id, a.bezeichnung]), [KEIN, '✕ kein Lagerartikel']], zu ? (zu.kein_lager ? KEIN : zu.artikel) : '', { 'aria-label': `Artikel zu Position ${p.nr}`, id: `we-artikel-${p.nr}` });
      z.faktor = h('input', { class: 'lg-kurz', inputmode: 'decimal', value: zu?.faktor || '1', 'aria-label': `Faktor Position ${p.nr}`, title: '1 Einheit des Belegs = so viele Lagereinheiten (z. B. 1 Packung = 10 Ampullen)', id: `we-faktor-${p.nr}` });
      const gebucht = p.zugaenge.reduce((s, g) => s + Number(g.menge), 0);
      const erwartet = () => Math.round(erwartetRoh * Number(mengeLesen(z.faktor.value) || 1) * 1000) / 1000;
      z.rest = p.zugaenge.length ? erwartet() - gebucht : 1;
      z.menge = h('input', { class: 'lg-kurz', inputmode: 'decimal', 'aria-label': `Zugang Position ${p.nr}`, id: `we-menge-${p.nr}`, value: erwartetRoh > 0 ? String(Math.max(0, erwartet() - gebucht) || '').replace('.', ',') : '' });
      z.einheit = h('span', { class: 'lg-klein' });
      z.charge = h('input', { maxlength: '50', class: 'lg-nr', size: '10', value: p.zugaenge.length ? '' : (p.charge || ''), 'aria-label': `Charge Position ${p.nr}`, id: `we-charge-${p.nr}` });
      z.verfall = datumFeld({ 'aria-label': `Verfall Position ${p.nr}`, id: `we-verfall-${p.nr}`, value: p.zugaenge.length ? '' : (p.verfall || '') });
      z.ort = auswahl(orte().map((o) => [o.id, o.name]), artikel(z.wahl.value)?.standardort || orte()[0]?.id, { 'aria-label': `Lagerort Position ${p.nr}`, id: `we-ort-${p.nr}` });
      z.marke = h('span', { class: 'marke-klein', id: `we-stand-${p.nr}` });
      z.wahl.addEventListener('change', async () => {
        const a = artikel(z.wahl.value);
        if (a?.standardort) z.ort.value = a.standardort;
        pruefen();
        if (z.wahl.value === KEIN && e.lieferant !== '') {
          try { await k.api('POST', `${basis}/zuordnungen`, { json: { eintrag: e.eintrag.id, nummer: p.schluessel, artikel: null } }); k.melden(`Gemerkt: „${p.bezeichnung}“ ist bei diesem Lieferanten kein Lagerartikel.`, 'erfolg'); } catch (x) { k.fehler(x); }
        }
      });
      z.faktor.addEventListener('change', () => { if (erwartetRoh > 0 && mengeLesen(z.faktor.value) !== null) z.menge.value = String(Math.max(0, erwartet() - gebucht)).replace('.', ','); pruefen(); });
      for (const el of [z.menge, z.charge, z.ort, z.verfall.ein]) { el.addEventListener('input', pruefen); el.addEventListener('change', pruefen); }
      z.tr = h('tr', { 'data-nr': p.nr });
      z.td = {
        pos: h('td', { class: 'eng' }, p.nr),
        beleg: h('td', { class: 'lg-we-beleg' }, p.bezeichnung, h('span', { class: 'lg-klein' }, [p.menge ? `${mengeText(p.menge)} ${einheitText(p.einheit)}` : null, p.einzelpreis ? `× ${Number(p.einzelpreis).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 4 })} €` : null,
          p.artikelnummer ? `Art.-Nr. ${p.artikelnummer}` : null, p.gtin ? `GTIN ${p.gtin}` : null].filter(Boolean).join(' · ')),
          e.anlagegrenze && Number(p.einzelpreis || 0) > Number(e.anlagegrenze) ? h('a', { class: 'lg-klein lg-warn', id: `we-anlage-${p.nr}`, href: `${k.buchSeiten(k.buch)}/anlagen/anlagen`,
            title: 'Wirtschaftsgüter über dieser Grenze werden in der Regel über die Nutzungsdauer abgesetzt – das entscheidest du in der Anlagenbuchhaltung' },
            `über ${Number(e.anlagegrenze).toLocaleString('de-DE', { minimumFractionDigits: 2 })} € je Einheit – eher ein Anlagegut?`) : ''),
        netto: h('td', { class: 'zahl' }, p.netto ? `${Number(p.netto).toLocaleString('de-DE', { minimumFractionDigits: 2 })} €` : ''),
        artikel: h('td', {}, z.wahl, h('label', { class: 'lg-klein lg-faktor', title: z.faktor.title }, '1 Einheit des Belegs = ', z.faktor, ' Lagereinheiten'),
          h('span', { class: 'lg-klein' }, zu?.gemerkt ? 'gemerkt' : zu ? 'Vorschlag über die GTIN' : '')),
        zugang: h('td', {}, z.menge, z.einheit), charge: h('td', {}, z.charge), verfall: h('td', {}, z.verfall.el),
        ort: h('td', { class: 'lg-we-ort' }, z.ort, h('span', { class: 'lg-klein' }, z.marke), p.zugaenge.length ? h('span', { class: 'lg-klein' }, p.zugaenge.map((g) => `${mengeText(g.menge)}${g.charge ? ` · ${g.charge}` : ''} → ${g.ort}`).join('; ')) : null) };
      return z;
    });
    tabelleZeichnen();
    fuss.replaceChildren(
      e.summenhinweis ? h('span', { class: 'marke-klein warnung', id: 'we-summe' }, e.summenhinweis) : h('span', { class: 'marke-klein korrigiert', id: 'we-summe' }, 'Summe der Positionen entspricht dem Nettobetrag des Belegs'),
      h('span', { class: 'hinweis' }, `Positionen sind ein Vorschlag. Der Bestand ändert sich erst mit „Zugang buchen“. Einstandspreise ${e.preise === 'brutto' ? 'brutto' : 'netto'}.`));
    pruefen();
  }

  async function eintragLaden(id) {
    e = null;
    if (id) { try { e = await k.api('GET', `${basis}/wareneingang/${id}`); } catch (x) { k.fehler(x); } }
    zeichnen();
  }

  async function laden(behalten = null) {
    try {
      stand = await k.api('GET', basis);
      eintraege = (await k.api('GET', `${basis}/wareneingang`)).eintraege;
    } catch (x) { k.fehler(x); }
    wahl.replaceChildren(...(eintraege.length ? eintraege.map((x) => h('option', { value: x.id },
      `${x.datum ? `${datumText(x.datum)} · ` : ''}${x.betreff} – ${x.zugaenge >= x.positionen ? 'erledigt' : `${x.zugaenge} von ${x.positionen} Positionen zugegangen`}`)) : [h('option', { value: '' }, '– kein Beleg mit Positionen –')]));
    const offen = eintraege.find((x) => x.zugaenge < x.positionen) || eintraege[0];
    wahl.value = eintraege.some((x) => x.id === behalten) ? behalten : (offen?.id || '');
    await eintragLaden(wahl.value);
  }

  async function zugangBuchen() {
    const bereit = zeilen.filter((z) => zustand(z)[0] === 'bereit');
    const json = { eintrag: e.eintrag.id, zeilen: bereit.map((z) => {
      const a = artikel(z.wahl.value);
      const faktor = mengeLesen(z.faktor.value) || '1';
      // Einstandspreis je Lagereinheit: netto, oder brutto, wenn das Buch keine Vorsteuer abzieht (Einstellung des Lagers)
      const mal = e.preise === 'brutto' && z.p.satz ? 1 + Number(z.p.satz) / 100 : 1;
      const preis = z.p.einzelpreis ? (Number(z.p.einzelpreis) * mal / Number(faktor)).toFixed(4) : null;
      return { artikel: a.id, ort: z.ort.value, menge: mengeLesen(z.menge.value), charge: a.fuehrung === 'ohne' ? '' : z.charge.value.trim(), verfall: a.fuehrung === 'ohne' ? null : (z.verfall.wert() || null),
        preis: preis !== null && Number(preis) >= 0 ? preis : null, position: z.p.nr, code: z.gescannt,
        merken: e.lieferant !== '' ? { nummer: z.p.schluessel, faktor } : undefined };
    }) };
    buchen.disabled = true;
    try {
      await k.api('POST', `${basis}/zugaenge`, { json });
      const id = e.eintrag.id;
      await laden(id);
      k.melden(`Zugang gebucht: ${json.zeilen.length} Position(en).`, 'erfolg');
    } catch (x) { k.fehler(x); pruefen(); }
  }

  function gescannt() {
    const c = codeLesen(scan.value);
    if (!c) { k.melden('Das ist kein lesbarer Code einer Packung (erwartet: Produktkennung, Verfall, Charge).', 'warnung'); return; }
    const gleich = (g, w) => g && w && g.replace(/^0+/, '') === w.replace(/^0+/, '');
    const offen = zeilen.filter((z) => ['fehlt', 'offen', 'bereit'].includes(zustand(z)[0]));
    const z = offen.find((x) => c.gtin && (gleich(x.p.gtin, c.gtin) || gleich(artikel(x.wahl.value)?.gtin, c.gtin)) && x.charge.value.trim() === '')
      || offen.find((x) => c.gtin && (gleich(x.p.gtin, c.gtin) || gleich(artikel(x.wahl.value)?.gtin, c.gtin)))
      || (c.gtin ? null : offen.find((x) => artikel(x.wahl.value) && x.charge.value.trim() === ''));
    if (!z) { k.melden(c.gtin ? `Keine offene Position passt zur Produktkennung ${c.gtin}.` : 'Keine offene Position ohne Charge.', 'warnung'); return; }
    const a = artikel(z.wahl.value);
    z.charge.value = (a?.fuehrung === 'serie' && c.serie) || c.charge || c.serie || z.charge.value;
    if (c.verfall) z.verfall.setzen(c.verfall);
    z.gescannt = scan.value.trim().slice(0, 200);
    k.melden(`Code gelesen: Position ${z.p.nr}${c.charge ? `, Charge ${c.charge}` : ''}${c.verfall ? `, Verfall ${datumText(c.verfall)}` : ''}.`, 'erfolg');
    scan.value = '';
    pruefen();
  }

  scan.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); gescannt(); } });
  wahl.addEventListener('change', () => eintragLaden(wahl.value));
  await laden();
}
