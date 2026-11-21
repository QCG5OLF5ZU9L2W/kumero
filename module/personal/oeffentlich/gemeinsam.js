// SPDX-License-Identifier: AGPL-3.0-or-later
// Gemeinsames der Seiten des Personals (PERSONAL.md Stufe 1): Dialog, Stunden, Texte der Zustände.
import { h, knopf, datumText } from '/kern/ui/bausteine.js';

export function stil() {
  const css = new URL('./personal.css', import.meta.url).href;
  if (!document.querySelector(`link[href="${css}"]`)) document.head.append(h('link', { rel: 'stylesheet', href: css }));
}

/** Dialog mit Formular: o.felder (Elemente), o.ja (Text), o.senden(); schließt erst nach Erfolg. Fehler stehen an festem Platz. */
export function dialog(o) {
  const ja = h('button', { type: 'submit', class: 'knopf primaer', id: `${o.id}-senden` }, o.ja || 'Speichern');
  const fehler = h('p', { class: 'hinweis fehlertext', id: `${o.id}-fehler`, role: 'alert' }, ' ');
  const dlg = h('dialog', { class: ['dialog', o.breit ? 'pe-breit' : ''], id: o.id },
    h('form', { onsubmit: async (ev) => {
      ev.preventDefault();
      if (o.ohneJa) return;
      ja.disabled = true;
      fehler.textContent = ' ';
      try { await o.senden(); dlg.close(); } catch (x) {
        fehler.textContent = (x.problem?.fehler || []).map((f) => `${FELDNAMEN[f.feld] || f.feld}: ${f.meldung}`).join(' · ') || x.problem?.detail || x.message || 'Das hat nicht geklappt';
      } finally { ja.disabled = false; }
    } },
    h('h2', {}, o.titel), o.text ? h('p', { class: 'hinweis' }, o.text) : null, ...o.felder, fehler,
    h('div', { class: 'dialog-knoepfe' }, knopf(o.ohneJa ? 'Schließen' : 'Abbrechen', () => dlg.close(), '', { id: `${o.id}-abbrechen` }), o.ohneJa ? null : ja)));
  dlg.addEventListener('close', () => dlg.remove());
  document.body.append(dlg);
  dlg.showModal();
  return { dlg, ja, fehler };
}

const FELDNAMEN = { personalnummer: 'Personalnummer', familienname: 'Familienname', vornamen: 'Vorname(n)', akademischer_titel: 'Titel', lohnbuero_nummer: 'Nr. beim Lohnbüro', vorgesetzter: 'Vorgesetzter', nutzer: 'Nutzer',
  austritt: 'Austritt', 'privat.geburtsdatum': 'Geburtsdatum', 'privat.strasse': 'Straße', 'privat.plz': 'PLZ', 'privat.ort': 'Ort', 'privat.telefon': 'Telefon',
  'privat.email': 'E-Mail', 'privat.notfall_name': 'Notfallkontakt', 'privat.notfall_telefon': 'Telefon des Notfallkontakts', gueltig_ab: 'Gültig ab', art: 'Art',
  funktion: 'Funktion', taetigkeit: 'Aufgaben', beginn: 'Beginn', probezeit_bis: 'Probezeit bis', befristet_bis: 'Befristet bis', kuendigungsfrist: 'Kündigungsfrist', kuendigung_art: 'Kündigungsfrist', kuendigungsregel: 'Tarifvertrag', regel: 'Regel',
  verteilung: 'Stunden je Wochentag', urlaubstage: 'Urlaubstage', lohnbuch: 'Buch', faellig: 'Fällig am', bemerkung: 'Bemerkung', person: 'Person',
  name: 'Name', warn_tage: 'Warnfrist', grund: 'Begründung', loeschen_ab: 'Löschen ab', strasse: 'Straße', plz: 'PLZ', ort: 'Ort', telefon: 'Telefon',
  email: 'E-Mail', notfall_name: 'Notfallkontakt', notfall_telefon: 'Telefon des Notfallkontakts' };
export const PRIVAT_FELDER = [['strasse', 'Straße und Hausnummer'], ['plz', 'PLZ'], ['ort', 'Ort'], ['telefon', 'Telefon'], ['email', 'E-Mail (privat)'],
  ['notfall_name', 'Notfallkontakt'], ['notfall_telefon', 'Telefon des Notfallkontakts']];
export const feldname = (f) => FELDNAMEN[f] || f;

export const VERTRAGSARTEN = { unbefristet: 'unbefristet', befristet: 'befristet', ausbildung: 'Ausbildung', minijob: 'Minijob', werkstudent: 'Werkstudent' };
export const WOCHENTAGE = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

/** Minuten als Stunden: 450 → „7,5“, 465 → „7:45“ */
export function stundenText(min) {
  if (min % 60 === 0) return String(min / 60);
  if (min % 30 === 0) return `${Math.floor(min / 60)},5`;
  return `${Math.floor(min / 60)}:${String(min % 60).padStart(2, '0')}`;
}

/** „8“, „7,5“, „7.5“ oder „7:30“ → Minuten; leer → 0; unlesbar → null */
export function stundenLesen(text) {
  const t = String(text || '').trim();
  if (t === '') return 0;
  let m = /^(\d{1,2}):([0-5]\d)$/.exec(t);
  if (m) return Number(m[1]) * 60 + Number(m[2]);
  m = /^(\d{1,2})(?:[.,](\d{1,2}))?$/.exec(t);
  if (!m) return null;
  const min = Math.round(Number(`${m[1]}.${m[2] || '0'}`) * 60);
  return min > 1440 ? null : min;
}

/** Zustand einer Frist als Zeichen und Text – nie nur Farbe */
export function fristZustand(f) {
  if (f.zustand === 'erledigt') return h('span', { 'data-zustand': 'erledigt' }, '✓ erledigt');
  if (f.zustand === 'ueberfaellig') return h('span', { 'data-zustand': 'ueberfaellig' }, `! überfällig seit ${-f.tage === 1 ? '1 Tag' : `${-f.tage} Tagen`}`);
  if (f.zustand === 'faellig') return h('span', { 'data-zustand': 'faellig' }, f.tage === 0 ? '▲ heute fällig' : `▲ fällig in ${f.tage === 1 ? '1 Tag' : `${f.tage} Tagen`}`);
  return h('span', { 'data-zustand': 'offen' }, `in ${f.tage} Tagen`);
}

export const tag = (iso) => (iso ? datumText(iso) : '');

// --- Abwesenheiten (PERSONAL.md Stufe 2) -------------------------------------------------------------------------

Object.assign(FELDNAMEN, { von: 'Von', bis: 'Bis', halber_tag: 'Halber Tag', 'au.festgestellt_am': 'Festgestellt am', 'au.folge': 'Folgebescheinigung',
  'au.arbeitsunfall': 'Arbeitsunfall', anspruch: 'Anspruch', uebertrag: 'Übertrag', datum: 'Datum', jahr: 'Jahr', verfallen: 'Verfallen', tage: 'Arbeitstage', tage_folgejahr: 'Davon im Folgejahr',
  land: 'Bundesland', feiertage: 'Feiertage', monat: 'Monat', tag: 'Tag', abstand: 'Tage ab Ostersonntag', laender: 'Länder', teile: 'In Teilen von', arbeitsort: 'Arbeitsort',
  anteilig: 'Anteiliger Anspruch', rundung: 'Rundung', uebertrag_hoechstens: 'Höchstens', verfall: 'Verfall', verfall_tag: 'Stichtag' });

export const LAENDER = [['BW', 'Baden-Württemberg'], ['BY', 'Bayern'], ['BE', 'Berlin'], ['BB', 'Brandenburg'], ['HB', 'Bremen'], ['HH', 'Hamburg'], ['HE', 'Hessen'],
  ['MV', 'Mecklenburg-Vorpommern'], ['NI', 'Niedersachsen'], ['NW', 'Nordrhein-Westfalen'], ['RP', 'Rheinland-Pfalz'], ['SL', 'Saarland'], ['SN', 'Sachsen'],
  ['ST', 'Sachsen-Anhalt'], ['SH', 'Schleswig-Holstein'], ['TH', 'Thüringen']];

/** Hinweis zum Verfall des Übertrags am Urlaubskonto – als Zeichen und Text; leer, wenn nichts ansteht. */
export function verfallText(z) {
  if (z.verfall_droht > 0) return `▲ ${tageText(z.verfall_droht)} aus dem Vorjahr ${z.verfall_droht === 1 ? 'verfällt' : 'verfallen'} am ${tag(z.verfall_am)}, wenn sie bis dahin nicht genommen sind`;
  if (z.verfall_offen > 0) return `! ${tageText(z.verfall_offen)} aus dem Vorjahr: Stichtag ${tag(z.verfall_am)} vorbei – das Personal entscheidet über den Verfall`;
  return '';
}

/** Tage als Text: 5 → „5“, 2.5 → „2,5“ */
export const tageText = (n) => (n === null || n === undefined ? '' : String(Math.round(n * 10) / 10).replace('.', ','));

/** Zustand einer Abwesenheit als Zeichen und Text – nie nur Farbe. Ohne Art (Sicht des Vorgesetzten) heißt es nur „abwesend“. */
export function abwZustandText(x, heute) {
  if (x.zustand === 'beantragt') return ['beantragt', '▲ beantragt'];
  if (x.zustand === 'abgelehnt') return ['abgelehnt', '✗ abgelehnt'];
  if (x.zustand === 'zurueckgenommen') return ['zurueckgenommen', '– zurückgenommen'];
  if (x.bis < heute) return ['genommen', x.art ? '✓ genommen' : '✓ war abwesend'];
  if (x.von <= heute) return ['laeuft', '● läuft'];
  return ['genehmigt', x.art ? (x.ist_au ? '✓ gemeldet' : '✓ genehmigt') : '✓ geplant'];
}
export function abwZustand(x, heute) {
  const [z, t] = abwZustandText(x, heute);
  return h('span', { 'data-zustand': `abw-${z}`, title: x.grund ? `Grund: ${x.grund}` : '' }, t + (x.grund && (z === 'abgelehnt' || z === 'zurueckgenommen') ? ` – ${x.grund}` : ''));
}

/** Angaben zur Bescheinigung einer AU als Text – mehr führt kumero nicht (nie eine Diagnose). */
export function auText(au) {
  if (!au) return '';
  return [au.folge ? 'Folgebescheinigung' : 'Erstbescheinigung', au.festgestellt_am ? `festgestellt ${tag(au.festgestellt_am)}` : null,
    au.arbeitsunfall ? 'Arbeitsunfall' : null, au.nachweis ? '✓ Nachweis liegt vor' : '! Nachweis fehlt'].filter(Boolean).join(' · ');
}

/**
 * Felder für eine neue Abwesenheit – dieselben beim Eintragen durch das Personal und im Selfservice. Alle Felder stehen
 * immer da; was zur gewählten Art nicht gehört, ist gesperrt und nennt den Grund (F106).
 * o: {id, arten, personen?: [{id, name}], person?: id, art?: id, datumFeld, auswahl, feld, heute, darfAu}
 * Liefert {felder: [Element], json(): Objekt für die API (wirft bei unlesbarem Datum)}.
 */
export function abwesenheitFelder(o) {
  const person = o.personen ? o.auswahl(o.personen.map((p) => [p.id, p.name]), o.person || null, { id: `${o.id}-person` }) : null;
  const art = o.auswahl(o.arten.map((a) => [a.id, a.name]), o.art || null, { id: `${o.id}-art` });
  const von = o.datumFeld({ id: `${o.id}-von`, value: o.von || '' });
  const bis = o.datumFeld({ id: `${o.id}-bis`, value: o.bis || '' });
  const halb = h('input', { type: 'checkbox', id: `${o.id}-halb` });
  const bemerkung = h('input', { id: `${o.id}-bemerkung`, type: 'text', maxlength: '200', size: '44', autocomplete: 'off' });
  const festgestellt = o.datumFeld({ id: `${o.id}-festgestellt` });
  const folge = h('input', { type: 'checkbox', id: `${o.id}-folge` });
  const unfall = h('input', { type: 'checkbox', id: `${o.id}-unfall` });
  const hinweis = h('p', { class: 'pe-klein', id: `${o.id}-hinweis`, role: 'status' }, ' ');
  const gewaehlt = () => o.arten.find((a) => a.id === art.value) || null;
  const halbPruefen = () => {
    const mehr = von.wert() && bis.wert() && von.wert() !== bis.wert();
    halb.disabled = Boolean(mehr); halb.title = mehr ? 'Ein halber Tag ist genau ein Tag' : '';
    if (mehr) halb.checked = false;
  };
  const anpassen = () => {
    const a = gewaehlt();
    const istAu = a?.kennung === 'au';
    const ohneAu = istAu ? (o.darfAu === false ? 'Angaben zur Bescheinigung sind mit deiner Rolle nicht einsehbar' : null) : 'Nur bei einer Arbeitsunfähigkeit';
    festgestellt.sperren(Boolean(ohneAu)); festgestellt.ein.title = ohneAu || '';
    for (const el of [folge, unfall]) { el.disabled = Boolean(ohneAu); el.title = ohneAu || ''; if (ohneAu) el.checked = false; }
    if (ohneAu) festgestellt.setzen('');
    bemerkung.disabled = istAu; bemerkung.title = istAu ? 'Eine Arbeitsunfähigkeit trägt keine Bemerkung – kumero führt keine Angaben zur Erkrankung' : '';
    if (istAu) bemerkung.value = '';
    hinweis.textContent = !a ? ' ' : [a.genehmigung ? 'braucht eine Genehmigung' : 'gilt ohne Genehmigung', a.zaehlt_urlaub ? 'zählt gegen das Urlaubskonto' : 'zählt nicht gegen das Urlaubskonto',
      a.bezahlt ? 'bezahlt' : 'unbezahlt'].join(' · ');
    halbPruefen();
  };
  art.addEventListener('change', anpassen);
  von.ein.addEventListener('change', () => { if (von.wert() && (!bis.wert() || bis.wert() < von.wert())) bis.setzen(von.wert()); halbPruefen(); });
  bis.ein.addEventListener('change', halbPruefen);
  anpassen();
  const haken = (el, text) => h('label', { class: 'pe-haken' }, el, ' ', text);
  return {
    felder: [
      h('div', { class: 'pe-reihe' }, ...(person ? [o.feld('Person', person)] : []), o.feld('Art', art)), hinweis,
      h('div', { class: 'pe-reihe' }, o.feld('Von', von.el), o.feld('Bis (bei AU: voraussichtlich)', bis.el), haken(halb, 'halber Tag')),
      o.feld('Bemerkung', bemerkung, 'keine Gesundheitsangaben'),
      h('fieldset', { class: 'pe-gruppe', id: `${o.id}-au` }, h('legend', {}, 'Bescheinigung der Arbeitsunfähigkeit – ohne Diagnose'),
        h('div', { class: 'pe-reihe' }, o.feld('Ärztlich festgestellt am', festgestellt.el), haken(folge, 'Folgebescheinigung'), haken(unfall, 'Arbeitsunfall')))],
    json: () => {
      const v = von.wert(); const b = bis.wert(); const f = festgestellt.wert();
      if (v === null || b === null || f === null) throw new Error('Ein Datum ist nicht lesbar');
      if (!v || !b) throw new Error('Von und Bis gehören dazu');
      const a = gewaehlt();
      return { ...(person ? { person: person.value } : {}), art: art.value, von: v, bis: b, halber_tag: halb.checked, bemerkung: bemerkung.value.trim() || null,
        ...(a?.kennung === 'au' && o.darfAu !== false ? { au: { festgestellt_am: f || null, folge: folge.checked, arbeitsunfall: unfall.checked } } : {}) };
    },
  };
}

// --- Zuschläge und Lohnvorbereitung (PERSONAL.md Stufe 3) ---------------------------------------------------------

Object.assign(FELDNAMEN, { monat: 'Monat', betrag_cent: 'Betrag', begruendung: 'Begründung', lohnart: 'Lohnart', vermerk: 'Vermerk', grundlage: 'Grundlage',
  'fassung.gueltig_ab': 'Gültig ab', 'fassung.form': 'Form', satz_bp: 'Satz', schwelle_cent: 'Schwelle', satz_ab_schwelle_bp: 'Satz ab der Schwelle', umfang: 'Umfang', bezug: 'Gruppe oder Leistung',
  zeitart: 'Fenster', fassung: 'Fassung' });

const MONATSNAMEN = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
/** „2026-10“ → „Oktober 2026“ */
export const monatText = (m) => (m ? `${MONATSNAMEN[Number(m.slice(5, 7)) - 1]} ${m.slice(0, 4)}` : '');

/** Cent als Betrag: 15000 → „150,00 €“ */
export const betragText = (cent) => (cent === null || cent === undefined ? '' : `${(cent / 100).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`);
/** „150“, „150,5“, „1.234,56“, „-20,00“ → Cent; leer → 0; unlesbar → null */
export function centLesen(text) {
  const t = String(text || '').replace(/[\s€]/g, '');
  if (t === '') return 0;
  const m = /^(-?)(\d{1,3}(?:\.\d{3})+|\d+)(?:,(\d{1,2}))?$/.exec(t);
  if (!m) return null;
  return (m[1] ? -1 : 1) * (Number(m[2].replace(/\./g, '')) * 100 + Number((m[3] || '0').padEnd(2, '0')));
}
/** Hundertstel Prozent als Text: 2500 → „25 %“, 1250 → „12,5 %“ */
export const satzText = (bp) => (bp === null || bp === undefined ? '' : `${String(bp / 100).replace('.', ',')} %`);
/** „25“, „12,5“ → Hundertstel Prozent; leer → null; unlesbar → undefined */
export function satzLesen(text) {
  const t = String(text || '').replace(/[\s%]/g, '');
  if (t === '') return null;
  const m = /^(\d{1,3})(?:,(\d{1,2}))?$/.exec(t);
  return m ? Number(m[1]) * 100 + Number((m[2] || '0').padEnd(2, '0')) : undefined;
}

export const ZEITARTEN = { nacht: 'Nachtarbeit', sonntag: 'Sonntagsarbeit', feiertag: 'Feiertagsarbeit', ueberstunden: 'Überstunden' };
/** Eine Fassung einer Zuschlagsregel in einem Satz */
export function fassungText(f) {
  if (!f) return 'noch keine Fassung gilt';
  const umfang = f.umfang === 'alle' ? 'aller Leistungen' : (f.umfang === 'gruppe' ? `der Gruppe „${f.bezug}“` : `der Leistung „${f.bezug}“`);
  if (f.form === 'zeitfenster') return `${satzText(f.satz_bp)} des Grundlohns für Stunden mit ${ZEITARTEN[f.zeitart] || f.zeitart}`;
  if (f.form === 'betrag') return `${betragText(f.betrag_cent)} je Leistung – ${umfang}`;
  if (f.form === 'staffel') return `${satzText(f.satz_bp)} vom Betrag ${umfang}, ab ${betragText(f.schwelle_cent)} im Monat ${satzText(f.satz_ab_schwelle_bp)}`;
  return `${satzText(f.satz_bp)} vom Betrag ${umfang}`;
}

export const LOHN_ZUSTAND = { offen: '○ offen', geprueft: '▲ geprüft', uebergeben: '✓ übergeben' };

/**
 * Die Unterlage für das Lohnbüro: eine Person, ein Monat. Dieselbe Darstellung am Bildschirm, im Druck und unter
 * „Meine Daten“. l: {inhalt, zustand?, fassung?, vermerk?, bemerkung?, geprueft_am?, uebergeben_am?, abweichung?}
 */
export function lohnUnterlage(l, o = {}) {
  const i = l.inhalt;
  if (!i) return h('p', { class: 'hinweis' }, 'Für diesen Monat gibt es nichts zu zeigen.');
  const vor = [i.person.akademischer_titel, i.person.vornamen].filter(Boolean).join(' ');
  const name = i.person.familienname + (vor ? `, ${vor}` : '');
  const v = i.vertrag;
  const paare = [['Personalnummer', i.person.personalnummer], ...(i.person.lohnbuero_nummer ? [['Nr. beim Lohnbüro', i.person.lohnbuero_nummer]] : []),
    ['Vertrag', v ? [VERTRAGSARTEN[v.art] || v.art, v.funktion, `${stundenText(v.wochenminuten)} Std. je Woche`, v.befristet_bis ? `befristet bis ${tag(v.befristet_bis)}` : null].filter(Boolean).join(' · ') : 'kein Vertrag'],
    ['Buch, das den Lohn trägt', v?.lohnbuch || '–'],
    ['Eintritt im Monat', i.eintritt ? tag(i.eintritt) : '–'], ['Austritt im Monat', i.austritt ? tag(i.austritt) : '–'],
    ['Sollstunden', `${stundenText(i.soll_minuten)} Std. an ${i.arbeitstage === 1 ? '1 Arbeitstag' : `${i.arbeitstage} Arbeitstagen`}`],
    ['Ist- und Überstunden', i.ist_minuten === null || i.ist_minuten === undefined ? h('span', { 'data-zustand': 'abw-fehlt' }, `! ${i.ist_hinweis}`)
      : `${stundenText(i.ist_minuten)} Std. gezählt · ${stundenText(i.ueber_minuten || 0)} Std. über dem Soll${i.ist_hinweis ? ` · ${i.ist_hinweis}` : ''}`],
    ...(i.zeitarten ? [['Stunden in Zeitfenstern', [['nacht', 'Nacht'], ['sonntag', 'Sonntag'], ['feiertag', 'Feiertag']].map(([k, t]) => `${t} ${stundenText(i.zeitarten[k] || 0)}`).join(' · ')]] : [])];
  const zeile = (zellen, klasse = '') => h('tr', { class: klasse }, zellen.map((z, n) => h('td', { class: typeof z === 'object' && z?.zahl ? 'zahl' : '' }, typeof z === 'object' && z?.zahl ? z.text : z)));
  const zahl = (text) => ({ zahl: true, text });
  const kopf = (titel) => h('thead', {}, h('tr', {}, titel.map((t) => h('th', { class: t.startsWith('#') ? 'zahl' : '' }, t.replace(/^#/, '')))));
  const zus = i.zuschlaege || [];
  return h('article', { class: 'lo-unterlage', 'data-person': o.person || '' },
    h('h2', {}, `Lohnvorbereitung ${monatText(i.monat)} – ${name}`),
    l.zustand ? h('p', { class: 'pe-klein lo-stand' }, [LOHN_ZUSTAND[l.zustand], `Fassung ${l.fassung}`, l.vermerk ? `Vermerk: ${l.vermerk}` : null,
      l.geprueft_am ? `geprüft ${tag(l.geprueft_am.slice(0, 10))}` : null, l.uebergeben_am ? `übergeben ${tag(l.uebergeben_am.slice(0, 10))}` : null].filter(Boolean).join(' · ')) : null,
    l.abweichung ? h('p', { class: 'pa-hinweis', 'data-zustand': 'abw-fehlt' }, '! Seit der Prüfung hat sich etwas geändert, das hier nicht steht. Geprüft: Prüfung zurücknehmen und neu prüfen. Übergeben: Nachtrag im Folgemonat oder eine neue Fassung.') : null,
    h('dl', { class: 'pe-angaben lo-angaben' }, paare.flatMap(([t, w]) => [h('dt', {}, t), h('dd', {}, w)])),
    h('h3', {}, 'Abwesenheiten'),
    (i.abwesenheiten || []).length ? h('table', { class: 'pe-tabelle lo-tabelle' }, kopf(['Art', 'Von', 'Bis', '#Arbeitstage', 'Entgelt']),
      h('tbody', {}, i.abwesenheiten.map((x) => zeile([x.art, tag(x.von), tag(x.bis) + (x.anteilig ? ' (Teil im Monat)' : ''), zahl(tageText(x.tage)), x.bezahlt ? 'bezahlt' : 'unbezahlt'])),
        i.abwesenheit_summen.map((s) => zeile([`Summe ${s.art}`, '', '', zahl(tageText(s.tage)), s.bezahlt ? 'bezahlt' : 'unbezahlt'], 'lo-summe'))))
      : h('p', { class: 'pe-klein' }, 'Keine Abwesenheit im Monat.'),
    h('h3', {}, 'Zuschläge'),
    zus.length ? h('table', { class: 'pe-tabelle lo-tabelle' }, kopf(['Bezeichnung', 'Grundlage', 'Lohnart', '#Betrag']),
      h('tbody', {}, zus.flatMap((z) => [zeile([z.name, z.art === 'hand' ? 'von Hand' : `Regel (${z.grundlage === 'zeit' ? 'Zeit' : 'Leistung'}), Fassung ab ${tag(z.fassung_ab)}`, z.lohnart || '',
        z.betrag_cent === null ? h('span', { 'data-zustand': 'abw-fehlt' }, `${z.menge_minuten !== null && z.menge_minuten !== undefined ? `${stundenText(z.menge_minuten)} Std. · ` : ''}! ${z.hinweis}`) : zahl(betragText(z.betrag_cent))]),
        ...(z.nachweis || []).map((n) => zeile([`· ${tag(n.datum)} ${n.text || ''}`, n.menge ?? '', n.grundlage_cent === undefined ? '' : betragText(n.grundlage_cent), zahl(betragText(n.betrag_cent))], 'lo-nachweis'))]),
        zeile(['Summe der Zuschläge mit Nachweis', '', '', zahl(betragText(i.zuschlag_summe_cent))], 'lo-summe')))
      : h('p', { class: 'pe-klein' }, 'Kein Zuschlag im Monat.'),
    h('h3', {}, 'Änderungen am Vertrag im Monat'),
    (i.vertragsaenderungen || []).length ? h('ul', { class: 'lo-liste' }, i.vertragsaenderungen.map((a) => h('li', {}, `Neue Fassung gültig ab ${tag(a.gueltig_ab)}: ${a.felder.map(feldname).join(', ') || 'ohne Änderung'}`)))
      : h('p', { class: 'pe-klein' }, 'Keine.'),
    o.ohneBemerkung ? null : h('h3', {}, 'Bemerkung'),
    o.ohneBemerkung ? null : h('p', { class: 'lo-bemerkung' }, l.bemerkung || '–'));
}

// --- Beurteilungen und Ziele (PERSONAL.md Stufe 4) ----------------------------------------------------------------

Object.assign(FELDNAMEN, { vorlage: 'Vorlage', zeitraum_von: 'Zeitraum von', zeitraum_bis: 'Zeitraum bis', beurteiler: 'Beurteiler', werte: 'Werte', texte: 'Anmerkungen', gesamt: 'Gesamteinschätzung',
  gespraech_am: 'Gespräch am', ergebnis: 'Ergebnis', stellungnahme: 'Stellungnahme', kriterien: 'Kriterien', skala_von: 'Skala von', skala_bis: 'Skala bis', titel: 'Titel', beschreibung: 'Beschreibung',
  frist: 'Frist', stand: 'Stand', text: 'Anmerkung', bewertung: 'Bewertung', ja: 'Bestätigung' });

/** Zustand einer Beurteilung als Zeichen und Text – nie nur Farbe */
export function beurteilungZustandText(b) {
  if (b.zustand === 'offen') return ['offen', `○ offen · Selbsteinschätzung ${b.selbst_abgegeben ? '✓' : 'fehlt'} · Einschätzung ${b.fremd_abgegeben ? '✓' : 'fehlt'}`];
  if (b.zustand === 'abgeschlossen') return ['wartet', '▲ abgeschlossen – Kenntnisnahme fehlt'];
  if (b.zustand === 'zur_kenntnis') return ['fertig', '✓ zur Kenntnis genommen'];
  return ['aufgehoben', `– aufgehoben${b.grund ? ` – ${b.grund}` : ''}`];
}
export const beurteilungZustand = (b) => { const [z, t] = beurteilungZustandText(b); return h('span', { 'data-zustand': `be-${z}` }, t); };

export const BEWERTUNGEN = { erreicht: '✓ erreicht', teilweise: '◐ teilweise erreicht', nicht: '✗ nicht erreicht' };
export function zielZustandText(z) {
  if (z.zustand === 'vorgeschlagen') return ['wartet', '▲ vorgeschlagen – Bestätigung fehlt'];
  if (z.zustand === 'vereinbart') return [z.tage < 0 ? 'ueberfaellig' : 'offen', `● vereinbart · ${z.stand} %${z.tage < 0 ? ' · ! Frist vorbei' : ''}`];
  if (z.zustand === 'bewertet') return ['fertig', BEWERTUNGEN[z.bewertung] || z.bewertung];
  return ['aufgehoben', `– ${z.vereinbart_am ? 'aufgehoben' : 'nicht vereinbart'}${z.grund ? ` – ${z.grund}` : ''}`];
}
export const zielZustand = (z) => { const [s, t] = zielZustandText(z); return h('span', { 'data-zustand': `be-${s}` }, t); };

const skalaText = (b) => `Skala ${b.skala_von} bis ${b.skala_bis}${b.skala_niedrig || b.skala_hoch ? ` (${b.skala_von} = ${b.skala_niedrig || '…'}, ${b.skala_bis} = ${b.skala_hoch || '…'})` : ''}`;

/** Eine Beurteilung zum Lesen: Kriterien mit beiden Einschätzungen nebeneinander, Gespräch, Ergebnis, Stellungnahme. */
export function beurteilungAnsicht(b) {
  const verdeckt = (abgegeben, wer) => (abgegeben ? `abgegeben – zu lesen, sobald ${wer}` : 'noch nicht abgegeben');
  const spalte = (e, abgegeben, wer) => (k) => (e ? [String(e.werte[k.kennung] ?? '–'), e.texte?.[k.kennung] || ''] : [h('span', { class: 'pe-klein' }, verdeckt(abgegeben, wer)), '']);
  const selbst = spalte(b.selbst, b.selbst_abgegeben, 'die Einschätzung des Beurteilers abgegeben ist');
  const fremd = spalte(b.fremd, b.fremd_abgegeben, 'auch die Selbsteinschätzung abgegeben ist');
  const paare = [['Zustand', beurteilungZustand(b)], ['Zeitraum', `${tag(b.zeitraum_von)} bis ${tag(b.zeitraum_bis)}`], ['Vorlage', `${b.vorlage} · ${skalaText(b)}`],
    ['Beurteiler', b.beurteiler_name || 'das Personal'], ['Gespräch am', b.gespraech_am ? tag(b.gespraech_am) : '–']];
  return h('article', { class: 'be-ansicht', 'data-id': b.id },
    h('h2', {}, `Beurteilung – ${b.name}`),
    h('dl', { class: 'pe-angaben' }, paare.flatMap(([t, w]) => [h('dt', {}, t), h('dd', {}, w)])),
    h('table', { class: 'pe-tabelle be-tabelle' },
      h('thead', {}, h('tr', {}, ['Kriterium', 'Selbst', 'Anmerkung (selbst)', 'Beurteiler', 'Anmerkung (Beurteiler)'].map((t) => h('th', {}, t)))),
      h('tbody', {}, b.kriterien.map((k) => { const s = selbst(k); const f = fremd(k);
        return h('tr', { 'data-kriterium': k.kennung }, h('td', { title: k.beschreibung || '' }, k.name, k.beschreibung ? h('div', { class: 'pe-klein' }, k.beschreibung) : null),
          h('td', { class: 'be-wert' }, s[0]), h('td', {}, s[1]), h('td', { class: 'be-wert' }, f[0]), h('td', {}, f[1])); }))),
    h('h3', {}, 'Gesamteinschätzung'),
    h('dl', { class: 'pe-angaben be-texte' }, [h('dt', {}, 'Selbst'), h('dd', {}, b.selbst?.gesamt || '–'), h('dt', {}, 'Beurteiler'), h('dd', {}, b.fremd?.gesamt || '–')]),
    h('h3', {}, 'Ergebnis des Gesprächs'), h('p', { class: 'lo-bemerkung' }, b.ergebnis || '–'),
    h('h3', {}, 'Stellungnahme der Person'), h('p', { class: 'lo-bemerkung' }, b.stellungnahme || (b.zustand === 'zur_kenntnis' ? 'Zur Kenntnis genommen, ohne Stellungnahme.' : '–')));
}

/** Felder einer Einschätzung: je Kriterium ein Wert der Skala und eine Anmerkung, dazu die Gesamteinschätzung. Liefert {felder, json()}. */
export function einschaetzungFelder(b, vorhanden, id) {
  const stufen = Array.from({ length: b.skala_bis - b.skala_von + 1 }, (_, i) => b.skala_von + i);
  const zeilen = b.kriterien.map((k) => ({ k,
    wert: h('select', { id: `${id}-w-${k.kennung}`, 'aria-label': `${k.name}: Wert`, required: true }, h('option', { value: '' }, '–'), stufen.map((n) => h('option', { value: String(n) }, String(n)))),
    text: h('input', { id: `${id}-t-${k.kennung}`, type: 'text', maxlength: '500', 'aria-label': `${k.name}: Anmerkung`, autocomplete: 'off', value: vorhanden?.texte?.[k.kennung] || '' }) }));
  for (const z of zeilen) if (vorhanden?.werte?.[z.k.kennung] !== undefined) z.wert.value = String(vorhanden.werte[z.k.kennung]);
  const gesamt = h('textarea', { id: `${id}-gesamt`, class: 'lo-text', maxlength: '2000' }, vorhanden?.gesamt || '');
  return {
    felder: [h('p', { class: 'pe-klein' }, skalaText(b)),
      h('div', { class: 'pa-rollbar' }, h('table', { class: 'pe-tabelle be-eingabe' }, h('thead', {}, h('tr', {}, ['Kriterium', 'Wert', 'Anmerkung'].map((t) => h('th', {}, t)))),
        h('tbody', {}, zeilen.map((z) => h('tr', {}, h('td', {}, z.k.name, z.k.beschreibung ? h('div', { class: 'pe-klein' }, z.k.beschreibung) : null), h('td', {}, z.wert), h('td', {}, z.text)))))),
      h('label', { class: 'feld' }, h('span', { class: 'beschriftung' }, 'Gesamteinschätzung'), gesamt)],
    json: () => {
      if (zeilen.some((z) => z.wert.value === '')) throw new Error('Zu jedem Kriterium gehört ein Wert');
      return { werte: Object.fromEntries(zeilen.map((z) => [z.k.kennung, Number(z.wert.value)])),
        texte: Object.fromEntries(zeilen.filter((z) => z.text.value.trim()).map((z) => [z.k.kennung, z.text.value.trim()])), gesamt: gesamt.value.trim() || null };
    },
  };
}

/** Felder für den Stand eines Ziels. Liefert {felder, json()}. */
export function standFelder(z, id) {
  const stand = h('input', { id: `${id}-stand`, class: 'pa-schmal', type: 'number', min: '0', max: '100', step: '5', required: true, value: String(z.stand) });
  const text = h('input', { id: `${id}-text`, type: 'text', maxlength: '500', size: '48', autocomplete: 'off', value: z.stand_text || '' });
  return { felder: [h('div', { class: 'pe-reihe' }, h('label', { class: 'feld' }, h('span', { class: 'beschriftung' }, 'Stand in %'), stand), h('label', { class: 'feld' }, h('span', { class: 'beschriftung' }, 'Anmerkung'), text))],
    json: () => { const n = Number(stand.value); if (!Number.isInteger(n) || n < 0 || n > 100) throw new Error('Stand: ganze Zahl von 0 bis 100'); return { stand: n, text: text.value.trim() || null }; } };
}

// --- Jahreskalender je Person (ZE8, F145) --------------------------------------------------------------------------

/**
 * Jahreskalender: zwölf Zeilen, 31 Spalten. Markiert Wochenenden, Feiertage am Arbeitsort, freie Tage des Betriebs,
 * Brückentage und die eigenen Abwesenheiten – als Zeichen und mit Namen beim Überfahren, nie nur als Farbe.
 * wessen: wie die Legende den Arbeitsort nennt (auf „Abwesenheiten“: „am Arbeitsort der Person“).
 * k: {jahr, tage: [{datum, art, name}]}, abwesenheiten: [{von, bis, zustand, art_name, halber_tag}], heute: ISO
 */
export function jahreskalender(k, abwesenheiten, heute, wessen = 'an deinem Arbeitsort') {
  const tage = new Map(k.tage.map((t) => [t.datum, t]));
  const eigene = (abwesenheiten || []).filter((x) => x.zustand === 'beantragt' || x.zustand === 'genehmigt');
  const ZEICHEN = { feiertag: 'F', frei: 'B', bruecke: '↔' };
  const zeile = (m) => {
    const anzahl = new Date(k.jahr, m, 0).getDate();
    return h('tr', {}, h('th', { class: 'pa-name jk-monat' }, MONATSNAMEN[m - 1]), Array.from({ length: 31 }, (_, i) => {
      if (i >= anzahl) return h('td', { class: 'jk-leer' }, '');
      const iso = `${k.jahr}-${String(m).padStart(2, '0')}-${String(i + 1).padStart(2, '0')}`;
      const wt = new Date(k.jahr, m - 1, i + 1).getDay();
      const t = tage.get(iso);
      const x = eigene.find((e) => e.von <= iso && e.bis >= iso);
      const klasse = [wt === 0 || wt === 6 || (t && t.art !== 'bruecke') ? 'pa-frei' : '', t?.art === 'bruecke' ? 'jk-bruecke' : '', iso === heute ? 'pa-heute' : ''];
      const titel = [t?.name, x ? `${x.art_name}${x.zustand === 'beantragt' ? ' (beantragt)' : ''}` : null].filter(Boolean).join(' · ');
      return h('td', { class: klasse, title: titel, 'data-tag': iso, ...(x ? { 'data-abw': x.zustand } : {}), ...(t ? { 'data-art': t.art } : {}) },
        x ? (x.zustand === 'beantragt' ? '○' : (x.halber_tag ? '◐' : '●')) : (t ? ZEICHEN[t.art] : ''));
    }));
  };
  return h('div', { class: 'jk', 'data-jahr': String(k.jahr) },
    h('table', { class: 'pa-kalender jk-tabelle' },
      h('thead', {}, h('tr', {}, h('th', { class: 'pa-name jk-monat' }, String(k.jahr)), Array.from({ length: 31 }, (_, i) => h('th', {}, String(i + 1))))),
      h('tbody', {}, Array.from({ length: 12 }, (_, i) => zeile(i + 1)))),
    h('p', { class: 'pa-legende' }, `F Feiertag ${wessen} · B freier Tag des Betriebs · ↔ Brückentag · ● abwesend · ◐ halber Tag · ○ beantragt · grau: arbeitsfrei`));
}

// --- Vergütung am Vertrag (F153) ---
export const VERGUETUNGSARTEN = { monat: 'Monatsgehalt', stunde: 'Stundenlohn' };
export const SONDERZAHLUNG_ARTEN = [['keins', '– keines –'], ['betrag', 'fester Betrag in €'], ['prozent', '% eines Monatsgehalts']];
export const MONATE = MONATSNAMEN;
const euro = (w) => `${Number(w).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
/** Betrag aus einer Eingabe wie „2.850,00“, „2850,5“ oder „18.50“ als Dezimal-String; '' bleibt '', Unlesbares wird null */
export function euroLesen(text) {
  let t = String(text || '').replace(/[\s€%]/g, '');
  if (t === '') return '';
  if (t.includes(',')) t = t.replace(/\./g, '').replace(',', '.');
  return /^\d{1,7}(\.\d{1,2})?$/.test(t) && Number(t) > 0 ? t : null;
}
export const euroEingabe = (w) => (w === null || w === undefined || w === '' ? '' : Number(w).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2, useGrouping: false }));
/** „2.850,00 € im Monat (brutto)“ – g ist die Vergütung einer Fassung oder null */
export const verguetungText = (g) => (g ? `${euro(g.betrag)} ${g.art === 'stunde' ? 'je Stunde' : 'im Monat'} (brutto)` : '');
/** Urlaubs- oder Weihnachtsgeld in einem Satz; was: 'urlaubsgeld' | 'weihnachtsgeld' */
export function sonderzahlungText(g, was) {
  if (!g || g[`${was}_art`] === 'keins') return '';
  const wert = g[`${was}_art`] === 'prozent' ? `${String(Number(g[`${was}_wert`])).replace('.', ',')} % eines Monatsgehalts` : euro(g[`${was}_wert`]);
  return wert + (g[`${was}_monat`] ? `, mit dem Lohn für ${MONATE[g[`${was}_monat`] - 1]}` : '');
}
