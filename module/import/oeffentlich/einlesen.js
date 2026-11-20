// SPDX-License-Identifier: AGPL-3.0-or-later
// Datei einlesen (IMPORT.md, F104): CSV wählen → Aufbau prüfen → Spalten zuordnen → Einnahme/Ausgabe festlegen →
// Geldkonto wählen → Vorschau → in den Arbeitsvorrat einliefern. Die Datei bleibt im Browser und geht mit jeder
// Anfrage an den Server; gespeichert wird sie dort nicht.
import { h, seitenkopf, knopf, feld, auswahl, tabelle, bestaetigen } from '/kern/ui/bausteine.js';
import { rechnungsteil } from './rechnung.js';

const ZIELE = ['datum', 'zahltag', 'betrag', 'einnahme', 'ausgabe', 'kennzeichen', 'gegenpartei', 'gegenpartei_kennung', 'betreff', 'belegnummer', 'art', 'waehrung', 'saldo'];
const RICHTUNGEN = ['vorzeichen', 'vorzeichen_umgekehrt', 'zwei_spalten', 'kennzeichen', 'fest'];

export async function zeigen(b, k) {
  const t = k.t;
  const basis = `${k.apiBasis}/import`;
  const zahl = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const betrag = (dez) => (dez === null || dez === undefined ? '' : zahl.format(Number(dez)));
  let datei = null;        // {name, bytes}
  let camt = false;
  let erkannt = null;      // Antwort von /erkennen
  let profile = [];
  let darfVorgeben = false;
  let konten = [];
  let buchungsarten = [];
  try { [konten, buchungsarten] = await Promise.all([k.api('GET', `${k.apiBasis}/konten`), k.api('GET', `${k.apiBasis}/buchungsarten`)]); } catch { /* dann ohne Vorschläge */ }

  const wahl = h('input', { type: 'file', id: 'import-datei', multiple: true, accept: '.csv,.txt,.xml,.pdf,text/csv,text/plain,application/xml,text/xml,application/pdf' });
  const schritte = h('div', { id: 'import-schritte' });
  const ergebnis = h('div', { id: 'import-ergebnis' });
  const verlauf = h('div', { id: 'import-verlauf' });
  b.append(seitenkopf(t('einlesen.titel', { mandant: k.mandantName || k.mandant }),
    knopf(t('aktion.zum_vorrat'), () => k.navigieren(`${k.seitenBasis}/arbeitsvorrat/liste`))),
  h('div', { class: 'karte' }, h('h2', {}, t('einlesen.datei')), h('p', { class: 'hinweis' }, t('einlesen.datei_text')), wahl),
  schritte, ergebnis, verlauf);

  // Zustand der Eingaben
  const f = {};
  const anfrage = (pfad, frage = {}) => k.api('POST', `${basis}/${pfad}?${new URLSearchParams(frage)}`, { roh: datei.bytes, typ: camt ? 'application/xml' : 'text/csv' });

  // Dieselbe Auswahl wird nur einmal gelesen, auch wenn der Browser sie zweimal meldet – sonst gingen Eingaben verloren
  let gelesen = null;
  // Der XML-Namensraum unterscheidet Rechnung und Banknachricht auch bei gleicher Dateiendung.
  function dateiart(bytes) {
    const anfang = new Uint8Array(bytes);
    let i = anfang[0] === 0xEF && anfang[1] === 0xBB && anfang[2] === 0xBF ? 3 : 0;
    while (i < anfang.length && [0x20, 0x09, 0x0A, 0x0D].includes(anfang[i])) i++;
    if (anfang[i] === 0x25 && anfang[i + 1] === 0x50 && anfang[i + 2] === 0x44 && anfang[i + 3] === 0x46) return 'rechnung';
    if (anfang[i] !== 0x3C) return 'csv';
    const wurzel = new DOMParser().parseFromString(new TextDecoder().decode(bytes), 'application/xml').documentElement;
    return wurzel.localName === 'Document' && wurzel.namespaceURI?.startsWith('urn:iso:std:iso:20022:tech:xsd:camt.')
      ? wurzel.namespaceURI.split(':').pop() : 'rechnung';
  }
  const rechnungen = rechnungsteil(k, basis, () => verlaufLaden());
  wahl.addEventListener('change', async () => {
    const alle = [...wahl.files];
    const d = alle[0];
    if (!d) return;
    if (alle.some((x) => x.size > 350 * 1024 * 1024)) { k.melden(t('einlesen.zu_gross'), 'fehler'); return; }
    if (alle.length > 50) { k.melden(t('rechnung.zu_viele'), 'fehler'); return; }
    const kennung = alle.map((x) => `${x.name}|${x.size}|${x.lastModified}`).join('\n');
    if (gelesen === kennung) return;
    const inhalte = await Promise.all(alle.map(async (x) => ({ name: x.name, bytes: await x.arrayBuffer() })));
    const arten = inhalte.map((x) => dateiart(x.bytes));
    if (arten.some((art) => art.startsWith('camt.'))) {
      if (alle.length !== 1) { k.melden(t('camt.einzeln'), 'fehler'); return; }
      gelesen = kennung;
      camt = true;
      datei = inhalte[0];
      ergebnis.replaceChildren();
      f.geldkonto = auswahl([['', t('camt.waehlen')], ...konten.filter((x) => x.aktiv !== false).map((x) => [x.nr, x.nr + ' – ' + x.bezeichnung])], '', { id: 'imp-geldkonto' });
      f.geldkonto.addEventListener('change', () => ergebnis.replaceChildren());
      schritte.replaceChildren(h('div', { class: 'karte' }, h('h2', {}, arten[0]),
        feld(t('geld.konto'), f.geldkonto), knopf(t('aktion.vorschau'), vorschau, 'primaer', { id: 'imp-vorschau' })));
      return;
    }
    if (arten.includes('rechnung')) {
      // E-Rechnungen: mehrere auf einmal; eine CSV-Datei dazwischen wird nicht geraten
      if (!arten.every((art) => art === 'rechnung')) { k.melden(t('einlesen.gemischt'), 'fehler'); return; }
      gelesen = kennung;
      camt = false;
      datei = null;
      ergebnis.replaceChildren();
      schritte.replaceChildren(rechnungen.el);
      await rechnungen.setzen(inhalte);
      k.melden(inhalte.length === 1 ? t('rechnung.gelesen_1') : t('rechnung.gelesen', { n: inhalte.length }), 'erfolg');
      return;
    }
    if (alle.length > 1) { k.melden(t('einlesen.eine_csv'), 'fehler'); return; }
    gelesen = kennung;
    camt = false;
    datei = inhalte[0];
    ergebnis.replaceChildren();
    await erkennen();
  });

  async function erkennen(merkmale = null) {
    try {
      const frage = merkmale ? { ...merkmale, trenner: merkmale.trenner === '\t' ? 'tab' : merkmale.trenner } : {};
      erkannt = await anfrage('erkennen', frage);
      schritteZeichnen(merkmale ? einstellungenLesen(false) : null);
    } catch (e) { k.fehler(e); }
  }

  /** Baut die Schritte auf; start: Einstellungen (aus einem Profil oder den bisherigen Eingaben), sonst der Vorschlag. */
  function schritteZeichnen(start = null) {
    const m = erkannt.merkmale;
    const e = start || { ...m, ...erkannt.vorschlag, geldkonto: '', buchungsart: null, zahlweise: 'unbar' };
    // 1 Aufbau
    f.zeichensatz = auswahl([['utf-8', 'UTF-8'], ['windows-1252', 'Windows-1252']], m.zeichensatz, { id: 'imp-zeichensatz' });
    f.trenner = auswahl([[';', t('trenner.semikolon')], [',', t('trenner.komma')], ['\t', t('trenner.tab')]], m.trenner, { id: 'imp-trenner' });
    f.kopfzeile = h('input', { type: 'number', id: 'imp-kopfzeile', min: '1', max: '200', value: String(m.kopfzeile), class: 'schmal' });
    f.fusszeilen = h('input', { type: 'number', id: 'imp-fusszeilen', min: '0', max: '50', value: String(m.fusszeilen), class: 'schmal' });
    f.datum = auswahl(['TT.MM.JJJJ', 'TT.MM.JJ', 'JJJJ-MM-TT', 'MM/TT/JJJJ'].map((x) => [x, x]), e.datum, { id: 'imp-datum' });
    f.dezimal = auswahl([[',', t('dezimal.komma')], ['.', t('dezimal.punkt')]], e.dezimal, { id: 'imp-dezimal' });
    const merkmaleLesen = () => ({ zeichensatz: f.zeichensatz.value, trenner: f.trenner.value, kopfzeile: f.kopfzeile.value, fusszeilen: f.fusszeilen.value });
    // 2 Spalten
    const zielWahl = [['', t('ziel.keins')], ...ZIELE.map((z) => [z, t(`ziel.${z}`)])];
    const zielVon = (name) => Object.entries(e.spalten || {}).find(([ziel, namen]) => ziel !== 'anzeigen' && namen.includes(name))?.[0] || '';
    f.spalten = erkannt.kopf.map((name) => ({ name, ziel: auswahl(zielWahl, zielVon(name), { 'data-spalte': name, 'aria-label': t('spalten.ziel_von', { name }) }) }));
    // 3 Richtung
    const rArt = e.richtung?.art || 'vorzeichen';
    f.richtung = RICHTUNGEN.map((r) => h('input', { type: 'radio', name: 'imp-richtung', value: r, id: `imp-richtung-${r}`, checked: r === rArt }));
    f.kzAusgabe = h('input', { id: 'imp-kz-ausgabe', value: (e.richtung?.ausgabe || ['S', 'Soll', 'Lastschrift']).join(', '), size: '30' });
    f.kzEinnahme = h('input', { id: 'imp-kz-einnahme', value: (e.richtung?.einnahme || ['H', 'Haben', 'Gutschrift']).join(', '), size: '30' });
    f.fest = auswahl([['ausgabe', t('richtung.nur_ausgabe')], ['einnahme', t('richtung.nur_einnahme')]], e.richtung?.fest || 'ausgabe', { id: 'imp-fest' });
    // 4 Geldkonto
    f.geldkonto = h('input', { id: 'imp-geldkonto', list: 'imp-konten', maxlength: '20', size: '10', value: e.geldkonto || '', autocomplete: 'off' });
    f.buchungsart = auswahl([['', t('geld.keine_buchungsart')], ...buchungsarten.map((a) => [a.kuerzel, `${a.kuerzel} – ${a.bezeichnung}`])], e.buchungsart || '', { id: 'imp-buchungsart' });
    f.zahlweise = auswahl([['unbar', t('geld.unbar')], ['bar', t('geld.bar')]], e.zahlweise || 'unbar', { id: 'imp-zahlweise' });
    const kontoName = h('span', { class: 'hinweis' });
    const kontoZeigen = () => { kontoName.textContent = konten.find((x) => x.nr === f.geldkonto.value.trim())?.bezeichnung || (f.geldkonto.value.trim() ? t('geld.unbekannt') : ''); };
    f.geldkonto.addEventListener('input', kontoZeigen);
    kontoZeigen();
    // Profile
    const passend = erkannt.profile;
    f.profil = auswahl([['', t('profil.keins')], ...passend.map((p) => [p.id, `${p.name}${p.geltung === 'mandant' ? ` (${t('profil.mandant')})` : ''}`])], start?.profilId || '', { id: 'imp-profil' });
    f.profil.addEventListener('change', () => {
      const p = passend.find((x) => x.id === f.profil.value);
      if (p) { schritteZeichnen({ ...p.einstellungen, profilId: p.id, profilName: p.name }); k.melden(t('profil.angewendet', { name: p.name }), 'erfolg'); }
    });
    f.profilName = h('input', { id: 'imp-profil-name', maxlength: '80', size: '28', value: start?.profilName || '' });
    f.profilMandant = h('input', { type: 'checkbox', id: 'imp-profil-mandant' });

    const beispiel = erkannt.beispiel[0] || {};
    schritte.replaceChildren(...[
      erkannt.schon_eingelesen ? h('div', { class: 'meldung warnung', id: 'imp-schon' }, t('einlesen.schon_eingelesen')) : null,
      passend.length ? h('div', { class: 'karte' }, h('h2', {}, t('profil.titel')), h('p', { class: 'hinweis' }, t('profil.passend')), feld(t('profil.wahl'), f.profil)) : null,
      h('div', { class: 'karte' }, h('h2', {}, t('aufbau.titel')), h('p', { class: 'hinweis' }, t('aufbau.text', { n: erkannt.zeilen_anzahl })),
        h('div', { class: 'zeile' }, feld(t('aufbau.zeichensatz'), f.zeichensatz), feld(t('aufbau.trenner'), f.trenner), feld(t('aufbau.kopfzeile'), f.kopfzeile, t('aufbau.kopfzeile_hilfe')),
          feld(t('aufbau.fusszeilen'), f.fusszeilen, t('aufbau.fusszeilen_hilfe')), feld(t('aufbau.datum'), f.datum), feld(t('aufbau.dezimal'), f.dezimal),
          knopf(t('aufbau.neu_lesen'), () => erkennen(merkmaleLesen()), '', { id: 'imp-neu-lesen', title: t('aufbau.neu_lesen_hilfe') })),
        h('details', {}, h('summary', {}, t('aufbau.vorspann')), h('pre', { class: 'imp-vorspann' }, erkannt.vorspann.map((z, i) => `${String(i + 1).padStart(2, ' ')}  ${z}`).join('\n')))),
      h('div', { class: 'karte' }, h('h2', {}, t('spalten.titel')), h('p', { class: 'hinweis' }, t('spalten.text')),
        tabelle([
          { titel: t('spalten.spalte'), wert: (x) => x.name },
          { titel: t('spalten.beispiel'), wert: (x) => h('span', { class: 'einzeilig hinweis' }, beispiel[x.name] || '') },
          { titel: t('spalten.ziel'), wert: (x) => x.ziel },
        ], { zeilen: f.spalten })),
      h('div', { class: 'karte' }, h('h2', {}, t('richtung.titel')), h('p', { class: 'hinweis' }, t('richtung.text')),
        h('div', { class: 'imp-richtungen' }, RICHTUNGEN.map((r, i) => h('label', { class: 'feld-wahl' }, f.richtung[i], h('span', {}, h('strong', {}, t(`richtung.${r}`)), ' – ', t(`richtung.${r}_text`))))),
        h('div', { class: 'zeile' }, feld(t('richtung.kz_ausgabe'), f.kzAusgabe, t('richtung.kz_hilfe')), feld(t('richtung.kz_einnahme'), f.kzEinnahme), feld(t('richtung.fest'), f.fest))),
      h('div', { class: 'karte' }, h('h2', {}, t('geld.titel')), h('p', { class: 'hinweis' }, t('geld.text')),
        h('div', { class: 'zeile' }, feld(t('geld.konto'), h('span', { class: 'zeile' }, f.geldkonto, kontoName)), feld(t('geld.zahlweise'), f.zahlweise), feld(t('geld.buchungsart'), f.buchungsart)),
        h('datalist', { id: 'imp-konten' }, konten.filter((x) => x.aktiv !== false).map((x) => h('option', { value: x.nr }, x.bezeichnung)))),
      h('div', { class: 'karte' }, h('div', { class: 'zeile' },
        knopf(t('aktion.vorschau'), vorschau, 'primaer', { id: 'imp-vorschau' }),
        h('span', { class: 'luecke' }),
        feld(t('profil.name'), f.profilName), darfVorgeben ? h('label', { class: 'feld-wahl' }, f.profilMandant, t('profil.fuer_mandant')) : null,
        knopf(t('profil.speichern'), profilSpeichern, '', { id: 'imp-profil-speichern', title: t('profil.speichern_hilfe') })))].filter(Boolean));
  }

  /** Einstellungen aus den Eingaben; mitPruefung meldet, was fehlt, und liefert dann null. */
  function einstellungenLesen(mitPruefung = true) {
    const spalten = {};
    for (const s of f.spalten) {
      if (!s.ziel.value) continue;
      (spalten[s.ziel.value] ||= []).push(s.name);
    }
    const art = f.richtung.find((r) => r.checked)?.value || 'vorzeichen';
    const liste = (ein) => ein.value.split(',').map((x) => x.trim()).filter(Boolean);
    const richtung = { art };
    if (art === 'kennzeichen') { richtung.ausgabe = liste(f.kzAusgabe); richtung.einnahme = liste(f.kzEinnahme); }
    if (art === 'fest') richtung.fest = f.fest.value;
    const e = { zeichensatz: f.zeichensatz.value, trenner: f.trenner.value, kopfzeile: Number(f.kopfzeile.value), fusszeilen: Number(f.fusszeilen.value),
      datum: f.datum.value, dezimal: f.dezimal.value, spalten, richtung, geldkonto: f.geldkonto.value.trim(), buchungsart: f.buchungsart.value || null, zahlweise: f.zahlweise.value };
    if (!mitPruefung) return { ...e, profilName: f.profilName.value };
    const einfach = ['datum', 'zahltag', 'betrag', 'einnahme', 'ausgabe', 'kennzeichen', 'gegenpartei_kennung', 'belegnummer', 'art', 'waehrung', 'saldo'];
    const doppelt = einfach.find((z) => (spalten[z] || []).length > 1);
    if (doppelt) { k.melden(t('pruefung.doppelt', { ziel: t(`ziel.${doppelt}`) }), 'fehler'); return null; }
    if (!spalten.datum) { k.melden(t('pruefung.datum'), 'fehler'); return null; }
    if (art === 'zwei_spalten' ? !(spalten.einnahme && spalten.ausgabe) : !spalten.betrag) { k.melden(t(art === 'zwei_spalten' ? 'pruefung.zwei_spalten' : 'pruefung.betrag'), 'fehler'); return null; }
    if (art === 'kennzeichen' && !(spalten.kennzeichen && richtung.ausgabe.length && richtung.einnahme.length)) { k.melden(t('pruefung.kennzeichen'), 'fehler'); return null; }
    if (!konten.some((x) => x.nr === e.geldkonto)) { k.melden(t('pruefung.geldkonto'), 'fehler'); f.geldkonto.focus(); return null; }
    return e;
  }

  const frageFuer = (e) => ({ einstellungen: JSON.stringify(e), name: datei.name, profil: camt ? '' : f.profilName.value.trim() });

  async function vorschau() {
    const e = camt ? { geldkonto: f.geldkonto.value } : einstellungenLesen();
    if (!e) return;
    if (!e.geldkonto) { k.melden(t('pruefung.geldkonto'), 'fehler'); return; }
    const aktuell = datei;
    try {
      const r = await anfrage('vorschau', frageFuer(e));
      if (datei === aktuell && (!camt || f.geldkonto.value === e.geldkonto)) berichtZeigen(r, e);
    } catch (x) { k.fehler(x); }
  }

  function berichtZeigen(r, e, eingeliefert = false) {
    const kachel = (n, text, art = '') => h('div', { class: ['kachel', art] }, h('strong', {}, String(n)), h('span', {}, text));
    ergebnis.replaceChildren(h('div', { class: 'karte', id: 'imp-bericht' }, h('h2', {}, t(eingeliefert ? 'bericht.eingeliefert' : 'bericht.vorschau')),
      r.bankkonto ? h('p', {}, t('camt.zuordnung', { bank: r.bankkonto, konto: e.geldkonto })) : null,
      h('div', { class: 'kacheln' }, kachel(r.neu, t('bericht.neu'), 'erfolg'), kachel(r.vorhanden, t('bericht.vorhanden')), kachel(r.fehlerhaft, t('bericht.fehlerhaft'), r.fehlerhaft ? 'warnung' : '')),
      h('p', {}, t('bericht.summen', { einnahmen: betrag(r.einnahmen), ausgaben: betrag(r.ausgaben), von: r.von || '–', bis: r.bis || '–' })),
      r.salden ? h('p', { class: r.salden.stimmt ? 'hinweis' : 'fehler-text', id: 'imp-salden' }, t(r.salden.stimmt ? 'bericht.salden_ok' : 'bericht.salden_falsch', { anfang: betrag(r.salden.anfang), ende: betrag(r.salden.ende) })) : null,
      r.fehlerhaft ? h('div', {}, h('p', { class: 'hinweis' }, t('bericht.fehler_text')),
        h('ul', { id: 'imp-fehler' }, r.fehler.map((x) => h('li', {}, t('bericht.fehler_zeile', { zeile: x.zeile, grund: x.grund }))))) : null,
      tabelle([
        { titel: t('bericht.zeile'), klasse: 'eng', wert: (x) => String(x.zeile) },
        { titel: t('ziel.datum'), klasse: 'eng', wert: (x) => x.datum || '' },
        { titel: t('ziel.gegenpartei'), wert: (x) => x.gegenpartei || '' },
        { titel: t('ziel.betreff'), wert: (x) => h('span', { class: 'einzeilig' }, x.betreff) },
        { titel: t('bericht.richtung'), klasse: 'eng', wert: (x) => (x.richtung ? t(`bericht.${x.richtung}`) : '') },
        { titel: t('ziel.betrag'), klasse: 'zahl', wert: (x) => betrag(x.betrag) },
        { titel: t('bericht.soll'), klasse: 'nr', wert: (x) => (x.richtung === 'einnahme' ? x.geldkonto : '') },
        { titel: t('bericht.haben'), klasse: 'nr', wert: (x) => (x.richtung === 'ausgabe' ? x.geldkonto : '') },
        { titel: '', wert: (x) => (x.fehler ? h('span', { class: 'marke-klein warnung', title: x.fehler }, t('bericht.markiert')) : '') },
      ], { zeilen: r.beispiel, leer: t('bericht.nichts_neu'), fuss: t('bericht.beispiel_fuss', { n: r.beispiel.length, neu: r.neu }) }),
      eingeliefert
        ? h('div', { class: 'leiste-unten' }, knopf(t('aktion.zum_vorrat'), () => k.navigieren(`${k.seitenBasis}/arbeitsvorrat/liste`), 'primaer', { id: 'imp-zum-vorrat' }))
        : h('div', { class: 'leiste-unten' }, knopf(t('aktion.einliefern', { n: r.neu }), () => einliefern(e), 'primaer', { id: 'imp-einliefern', disabled: r.neu === 0 }))));
    ergebnis.scrollIntoView({ block: 'nearest' });
  }

  async function einliefern(e) {
    try {
      const r = await anfrage('einliefern', frageFuer(e));
      k.melden(t('bericht.meldung', { n: r.neu }), 'erfolg');
      berichtZeigen(r, e, true);
      k.menueNeuLaden?.();
      await verlaufLaden();
    } catch (x) { k.fehler(x); }
  }

  async function profilSpeichern() {
    const e = einstellungenLesen();
    if (!e) return;
    const name = f.profilName.value.trim();
    if (!name) { k.melden(t('profil.name_fehlt'), 'fehler'); f.profilName.focus(); return; }
    try {
      const r = await k.api('PUT', `${basis}/profile`, { json: { name, fuer: f.profilMandant.checked ? 'mandant' : 'mich', kopf: erkannt.kopf, einstellungen: e } });
      profile = r.profile;
      k.melden(t('profil.gespeichert', { name }), 'erfolg');
      verlaufZeichnen();
    } catch (x) { k.fehler(x); }
  }

  let importe = [];
  let ablage = { liegen: 0, gesendet: 0 };
  async function verlaufLaden() {
    try {
      const [p, i] = await Promise.all([k.api('GET', `${basis}/profile`), k.api('GET', `${basis}/importe`)]);
      profile = p.profile;
      darfVorgeben = p.darf_vorgeben;
      importe = i.importe;
      ablage = i.zwischenablage || { liegen: 0, gesendet: 0, abgelehnt: 0 };
      verlaufZeichnen();
    } catch (x) { k.fehler(x); }
  }
  function verlaufZeichnen() {
    const zeit = (z) => (z ? new Date(z).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' }) : '');
    verlauf.replaceChildren(
      h('div', { class: 'karte' }, h('h2', {}, t('verlauf.titel')),
        h('p', { class: 'hinweis', id: 'import-zwischenablage' }, t('ablage.stand', { n: ablage.liegen, m: ablage.gesendet, k: ablage.abgelehnt || 0 })),
        tabelle([
          { titel: t('verlauf.am'), klasse: 'eng', wert: (x) => zeit(x.am) },
          { titel: t('verlauf.datei'), wert: (x) => x.dateiname },
          { titel: t('verlauf.profil'), wert: (x) => x.profil || '' },
          { titel: t('geld.konto'), klasse: 'nr', wert: (x) => x.geldkonto },
          { titel: t('bericht.neu'), klasse: 'zahl', wert: (x) => String(x.neu) },
          { titel: t('bericht.vorhanden'), klasse: 'zahl', wert: (x) => String(x.vorhanden) },
          { titel: t('bericht.fehlerhaft'), klasse: 'zahl', wert: (x) => String(x.fehlerhaft) },
          { titel: '', wert: (x) => (x.zurueckgezogen_am ? h('span', { class: 'hinweis' }, t('verlauf.zurueckgezogen', { am: zeit(x.zurueckgezogen_am) }))
            : knopf(t('verlauf.zurueckziehen'), async () => {
              if (!await bestaetigen(t('verlauf.frage_titel'), t('verlauf.frage', { datei: x.dateiname, n: x.neu }), t('verlauf.zurueckziehen'))) return;
              try { const r = await k.api('POST', `${basis}/importe/${x.id}/zurueckziehen`, { json: {} }); k.melden(t('verlauf.meldung', { n: r.zurueckgezogen }), 'erfolg'); k.menueNeuLaden?.(); await verlaufLaden(); } catch (e) { k.fehler(e); }
            }, 'klein', { 'data-import': x.id, title: t('verlauf.zurueckziehen_hilfe') })) },
        ], { zeilen: importe, leer: t('verlauf.leer') })),
      h('div', { class: 'karte' }, h('h2', {}, t('profil.liste')),
        tabelle([
          { titel: t('profil.name'), wert: (x) => x.name },
          { titel: t('profil.geltung'), klasse: 'eng', wert: (x) => t(x.geltung === 'mandant' ? 'profil.mandant' : 'profil.eigenes') },
          { titel: t('geld.konto'), klasse: 'nr', wert: (x) => x.einstellungen.geldkonto },
          { titel: t('richtung.titel'), wert: (x) => t(`richtung.${x.einstellungen.richtung.art}`) },
          { titel: '', wert: (x) => (x.geltung === 'mandant' && !darfVorgeben ? '' : knopf(t('profil.loeschen'), async () => {
            if (!await bestaetigen(t('profil.loeschen_titel'), t('profil.loeschen_frage', { name: x.name }), t('profil.loeschen'))) return;
            try { await k.api('DELETE', `${basis}/profile/${x.id}`); k.melden(t('profil.geloescht'), 'erfolg'); await verlaufLaden(); } catch (e) { k.fehler(e); }
          }, 'klein', { 'data-profil': x.id })) },
        ], { zeilen: profile, leer: t('profil.leer') })));
  }
  await verlaufLaden();
}
