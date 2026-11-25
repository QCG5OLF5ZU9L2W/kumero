// SPDX-License-Identifier: AGPL-3.0-or-later
// „Bedarf und Bestellung“ (BESTELLANFORDERUNG.md Stufe 1, F222): die Bestellanforderung. Drei Ansichten an festem Platz:
// „Bei mir“ (was auf mich wartet), „Alle Anforderungen“, „Vorschlag“ (was im Lager unter Mindestbestand liegt, abzüglich
// dessen, was schon angefordert oder bestellt ist). Oben die Liste, unten die gewählte Anforderung mit Positionen und
// Verlauf (F137). Der Einkauf hakt die Positionen in der Anforderung ab (BA21); eine Liste je Lieferant gibt es nicht.
// Knöpfe an festem Platz, gesperrt mit Grund (F106). Sortierung und Filter am Spaltenkopf (F91), Spalten verschiebbar (F146).
// Doppelklick und Enter öffnen, was zu entscheiden ist (F150): den Entwurf zum Bearbeiten, die offene Position zum
// Abhaken, die bestellte zum Melden des Eingangs.
import { h, seitenkopf, knopf, sperren, datumText, datumFeld, reiterleiste, zeit } from '/kern/ui/bausteine.js';
import { tabellenfilter } from '/kern/ui/tabellenfilter.js';
import { teiler } from '/kern/ui/teiler.js';
import { mengeText, mengeLesen, preisText, preisLesen, dialog, rfeld, rwahl, stil } from './gemeinsam.js';

const STAND = { entwurf: 'Entwurf', angefordert: 'angefordert', freigegeben: 'freigegeben', abgelehnt: 'abgelehnt', zurueckgezogen: 'zurückgezogen', teilweise_bestellt: 'teilweise bestellt',
  bestellt: 'bestellt', teilweise_erhalten: 'teilweise erhalten', erledigt: 'erledigt' };
const POSITION = { offen: 'offen', bestellt: 'bestellt', erhalten: 'erhalten', gestrichen: 'gestrichen' };
const WOZU = { freigabe: 'zur Freigabe', rueckfrage: 'zur Rückfrage', bestellen: 'zum Bestellen' };
const VERLAUF = { angefordert: 'hat angefordert', weitergeleitet: 'hat weitergeleitet', uebernommen: 'hat übernommen', freigegeben: 'hat freigegeben', abgelehnt: 'hat abgelehnt',
  zurueckgezogen: 'hat zurückgezogen', bestellt: 'hat als bestellt abgehakt', zurueckgenommen: 'hat den Haken zurückgenommen', gestrichen: 'hat gestrichen', geteilt: 'hat geteilt', erhalten: 'hat den Eingang gemeldet' };
const ARTEN = [['verbrauch', 'Verbrauch'], ['medizinprodukt', 'Medizinprodukt'], ['arzneimittel', 'Arzneimittel'], ['sonstiges', 'sonstiges']];
// Was beim Abhaken stehen bleibt, bis der Einkäufer es ändert (BA23): wer fünf Positionen im selben Warenkorb hat, tippt es einmal
const merk = { lieferant: '', bestellnummer: '', bestellt_am: '', liefertermin: '' };

export async function zeigen(b, k) {
  stil();
  b.classList.add('listenseite', 'beschaffung');
  let d = { anforderungen: [], darf: {}, vorschlag: [], artikel: [], gruppen: [], personen: [], rollen: [], lieferanten: [], bezug: {}, lager: false, heute: '' };
  let ansicht = 'beimir';
  let anfId = null;
  let posId = null;
  const gehakt = new Set();   // im Vorschlag markierte Zeilen
  const basis = `${k.apiBasis}/beschaffung`;

  const kn = {
    neu: knopf('Anforderung neu …', () => anforderungDialog(null, []), 'primaer', { id: 'bs-neu', title: 'Bedarf melden: Positionen fürs Lager und alles, was nie ins Lager geht' }),
    aendern: knopf('Bearbeiten …', () => anforderungDialog(anf(), null), '', { id: 'bs-aendern', title: 'Kopf und Positionen der gewählten Anforderung ändern' }),
    anfordern: knopf('Anfordern', () => tun('anfordern', null, (r) => `Angefordert als ${r.nummer}`), '', { id: 'bs-anfordern', title: 'Den Entwurf abgeben – er bekommt seine Nummer und wartet auf die Freigabe' }),
    zurueck: knopf('Zurückziehen', zurueckziehen, '', { id: 'bs-zurueck', title: 'Einen Entwurf löschen oder eine angeforderte Anforderung zurückziehen' }),
    weiter: knopf('Weiterleiten …', weiterleitenDialog, '', { id: 'bs-weiter', title: 'An eine Person oder eine Rolle geben – zur Freigabe, zur Rückfrage oder zum Bestellen' }),
    nehmen: knopf('Übernehmen', () => tun('uebernehmen', null, () => 'Übernommen – die Anforderung liegt jetzt bei dir'), '', { id: 'bs-nehmen', title: 'Eine an deine Rolle weitergeleitete Anforderung zu dir nehmen' }),
    frei: knopf('Freigeben', () => tun('freigeben', null, () => 'Freigegeben'), '', { id: 'bs-freigeben', title: 'Die Anforderung freigeben – danach kann bestellt werden' }),
    ab: knopf('Ablehnen …', ablehnenDialog, '', { id: 'bs-ablehnen', title: 'Die Anforderung mit Grund ablehnen' }),
    uebernahme: knopf('In Anforderung übernehmen …', vorschlagUebernehmen, '', { id: 'bs-uebernahme', title: 'Die markierten Zeilen des Vorschlags als Positionen einer neuen Anforderung' }),
    // an der Position
    haken: knopf('Als bestellt abhaken …', () => bestellenDialog(pos()), 'primaer', { id: 'bs-haken', title: 'Erfassen, was bestellt wurde: Menge, Lieferant, Bestellnummer, Termin, Preis' }),
    hakenWeg: knopf('Haken zurücknehmen', hakenZurueck, '', { id: 'bs-haken-weg', title: 'Einen falsch gesetzten Haken zurücknehmen – die Position ist wieder offen' }),
    streichen: knopf('Streichen …', streichenDialog, '', { id: 'bs-streichen', title: 'Die Position wird nicht bestellt – mit Grund' }),
    erhalten: knopf('Eingang melden …', () => erhaltenDialog(pos()), '', { id: 'bs-erhalten', title: 'Melden, was angekommen ist – ganz oder in Teilmengen' }),
  };
  const reiter = h('div', { class: 'bs-ansicht nicht-drucken', id: 'bs-ansicht' });
  const stand = h('div', { class: 'bs-grund', id: 'bs-stand', role: 'status' });
  const liste = h('div', { class: 'liste-rahmen bs-liste', id: 'bs-liste' });
  const unten = h('div', { class: 'bs-unten', id: 'bs-unten' });
  b.append(seitenkopf(`Bedarf und Bestellung – ${k.buchName || k.mandantName || k.mandant}`),
    // zwei feste Zeilen mit fester Bedeutung (F106): oben die eigene Anforderung, darunter der Weg durch die Hände
    h('div', { class: 'bs-leiste nicht-drucken' }, kn.neu, kn.aendern, kn.anfordern, kn.zurueck, h('span', { class: 'bs-luecke' }), kn.uebernahme),
    h('div', { class: 'bs-leiste nicht-drucken' }, kn.weiter, kn.nehmen, kn.frei, kn.ab),
    reiter, stand, teiler(liste, unten, { id: 'bs-teilung', hoehe: 320 }));

  const anf = () => d.anforderungen.find((x) => x.id === anfId) || null;
  const pos = () => anf()?.positionen.find((p) => p.id === posId) || null;
  const artikelVon = (id) => d.artikel.find((a) => a.id === id) || null;
  const offenePos = (x) => x.positionen.filter((p) => p.zustand === 'offen').length;

  const spalten = [
    { id: 'nummer', titel: 'Nummer', klasse: 'eng', wert: (x) => x.nummer || 'Entwurf' },
    { id: 'wofuer', titel: 'Wofür', wert: (x) => x.wofuer },
    { id: 'stand', titel: 'Stand', wert: (x) => STAND[x.stand] + (x.ueberfaellig ? ' · überfällig' : '') },
    { id: 'bei', titel: 'Liegt bei', wert: (x) => (x.bei ? `${x.bei}${x.bei_rolle ? ' (Rolle)' : ''} – ${WOZU[x.bei_wozu]}` : '') },
    { id: 'von', titel: 'Von', wert: (x) => x.von },
    { id: 'am', titel: 'Angefordert am', klasse: 'eng', wert: (x) => (x.angefordert_am ? datumText(x.angefordert_am) : ''), sortwert: (x) => x.angefordert_am || '' },
    { id: 'termin', titel: 'Gewünscht bis', klasse: 'eng', wert: (x) => (x.termin ? datumText(x.termin) : ''), sortwert: (x) => x.termin || '' },
    { id: 'positionen', titel: 'Positionen', klasse: 'zahl', wert: (x) => `${x.positionen.length}${offenePos(x) && x.zustand === 'freigegeben' ? ` (${offenePos(x)} offen)` : ''}`, sortwert: (x) => x.positionen.length,
      suchtext: (x) => x.positionen.map((p) => `${p.bezeichnung} ${p.lieferant || ''} ${p.bestellnummer || ''}`).join(' ') },
  ];
  const filter = tabellenfilter(spalten, () => zeichnen(), { id: 'beschaffung.anforderungen' });
  const vSpalten = [
    { id: 'haken', titel: '', klasse: 'bs-haken', wert: (v) => '', sortwert: (v) => (gehakt.has(vId(v)) ? 0 : 1) },
    { id: 'artikel', titel: 'Artikel', wert: (v) => v.bezeichnung + (v.gruppe ? ' (Artikelgruppe)' : '') },
    { id: 'nummer', titel: 'Nummer', klasse: 'eng', wert: (v) => v.nummer || '' },
    { id: 'verfuegbar', titel: 'Verfügbar', klasse: 'zahl', wert: (v) => `${mengeText(v.verfuegbar)} ${v.einheit}`, sortwert: (v) => Number(v.verfuegbar) },
    { id: 'mindest', titel: 'Mindest', klasse: 'zahl', wert: (v) => mengeText(v.mindest), sortwert: (v) => Number(v.mindest) },
    { id: 'soll', titel: 'Soll', klasse: 'zahl', wert: (v) => (v.soll === null ? '' : mengeText(v.soll)), sortwert: (v) => (v.soll === null ? -1 : Number(v.soll)) },
    { id: 'fehlt', titel: 'Fehlt', klasse: 'zahl', wert: (v) => mengeText(v.fehlt), sortwert: (v) => Number(v.fehlt) },
    { id: 'unterwegs', titel: 'Angefordert oder bestellt', klasse: 'zahl', hinweis: 'Was in Anforderungen steht oder bestellt und noch nicht als erhalten gemeldet ist', wert: (v) => (Number(v.unterwegs) > 0 ? mengeText(v.unterwegs) : ''), sortwert: (v) => Number(v.unterwegs) },
    { id: 'vorschlag', titel: 'Vorschlag', klasse: 'zahl', wert: (v) => (Number(v.vorschlag) > 0 ? `${mengeText(v.vorschlag)} ${v.einheit}` : '–'), sortwert: (v) => Number(v.vorschlag) },
  ];
  const vId = (v) => (v.gruppe ? `m:${v.meta}` : `a:${v.artikel}`);
  const vFilter = tabellenfilter(vSpalten, () => zeichnen(), { id: 'beschaffung.vorschlag' });

  /** Warum ein Knopf an der Anforderung nicht geht – null, wenn er geht. */
  function gruende(x) {
    const g = {};
    const keine = ansicht === 'vorschlag' ? 'Wechsle zu den Anforderungen' : 'Wähle zuerst eine Anforderung';
    const darf = d.darf;
    g.neu = darf.anfordern ? null : 'Dir fehlt das Recht, Bedarf anzufordern';
    if (!x || ansicht === 'vorschlag') return { ...g, aendern: keine, anfordern: keine, zurueck: keine, weiter: keine, nehmen: keine, frei: keine, ab: keine };
    const vorFreigabe = ['entwurf', 'angefordert'].includes(x.zustand);
    const meins = x.eigene && darf.anfordern;
    const fertig = `Die Anforderung ist ${STAND[x.zustand]}`;
    g.aendern = !vorFreigabe ? `${fertig} – ändern lässt sie sich nicht mehr` : (meins || darf.freigeben ? null : 'Ändern kann, wer die Anforderung angelegt hat oder wer freigeben darf');
    g.anfordern = x.zustand !== 'entwurf' ? 'Die Anforderung ist schon angefordert' : (!meins ? 'Anfordern kann den Entwurf nur, wer ihn angelegt hat' : (x.positionen.length ? null : 'Der Entwurf hat noch keine Position'));
    g.zurueck = !vorFreigabe ? `${fertig} – zurückziehen lässt sie sich nicht mehr` : (meins || darf.freigeben ? null : 'Zurückziehen kann, wer die Anforderung angelegt hat oder wer freigeben darf');
    g.weiter = !(darf.anfordern || darf.freigeben || darf.bestellen) ? 'Dir fehlt das Recht dazu' : (x.zustand === 'entwurf' ? 'Ein Entwurf lässt sich nicht weiterleiten – erst anfordern'
      : (!['angefordert', 'freigegeben'].includes(x.zustand) || x.stand === 'erledigt' ? `Die Anforderung ist ${STAND[x.stand]}` : null));
    g.nehmen = !x.bei_rolle ? 'Die Anforderung liegt bei keiner Rolle' : (x.uebernehmbar ? null : `Übernehmen kann, wer die Rolle „${x.bei}“ hat`);
    const entscheiden = !darf.freigeben ? 'Dir fehlt das Recht freizugeben' : (x.zustand === 'entwurf' ? 'Noch ein Entwurf – erst anfordern' : (x.zustand !== 'angefordert' ? 'Über die Anforderung ist schon entschieden' : null));
    g.frei = entscheiden;
    g.ab = entscheiden;
    return g;
  }
  /** Warum ein Knopf an der Position nicht geht. */
  function posGruende(x, p) {
    const darf = d.darf;
    const keine = 'Wähle zuerst eine Position';
    if (!x || !p) return { haken: keine, hakenWeg: keine, streichen: keine, erhalten: keine };
    const einkauf = darf.bestellen ? null : 'Dir fehlt das Recht zu bestellen';
    const nichtFrei = x.zustand !== 'freigegeben' ? (x.zustand === 'entwurf' || x.zustand === 'angefordert' ? 'Bestellen lässt sich erst, was freigegeben ist' : `Die Anforderung ist ${STAND[x.zustand]}`) : null;
    const offen = p.zustand === 'offen' ? null : `Die Position ist ${POSITION[p.zustand]}`;
    return {
      haken: einkauf || nichtFrei || offen || (p.art === 'lager' && !d.lager ? 'Die Position ist fürs Lager, das Modul Lager ist in diesem Buch aber nicht aktiv' : null),
      streichen: einkauf || nichtFrei || offen,
      hakenWeg: einkauf || (p.zustand !== 'bestellt' ? (p.zustand === 'offen' ? 'Die Position ist nicht bestellt' : `Die Position ist ${POSITION[p.zustand]}`) : (Number(p.erhalten_menge) > 0 ? 'Zu der Position ist schon etwas als erhalten gemeldet' : null)),
      erhalten: (darf.bestellen || (darf.anfordern && x.eigene) ? null : 'Den Eingang meldet, wer bestellen darf – oder wer die Anforderung angelegt hat')
        || (p.zustand !== 'bestellt' ? (p.zustand === 'erhalten' ? 'Die Position ist schon ganz erhalten' : 'Erhalten lässt sich nur melden, was bestellt ist') : null),
    };
  }

  function zeichnen() {
    const beiMir = d.anforderungen.filter((x) => x.bei_mir);
    reiter.replaceChildren(reiterleiste([{ id: 'beimir', text: 'Bei mir', anzahl: beiMir.length }, { id: 'alle', text: d.darf.alle ? 'Alle Anforderungen' : 'Meine Anforderungen', anzahl: d.anforderungen.length },
      { id: 'vorschlag', text: 'Vorschlag aus dem Bestand', anzahl: d.vorschlag.filter((v) => Number(v.vorschlag) > 0).length }], ansicht, (id) => { ansicht = id; zeichnen(); }));
    reiter.querySelectorAll('button').forEach((el, n) => { el.id = `bs-reiter-${['beimir', 'alle', 'vorschlag'][n]}`; });
    if (ansicht === 'vorschlag') zeichneVorschlag(); else zeichneAnforderungen(ansicht === 'beimir' ? beiMir : d.anforderungen);
    const g = gruende(anf());
    sperren(kn.neu, g.neu); sperren(kn.aendern, g.aendern); sperren(kn.anfordern, g.anfordern); sperren(kn.zurueck, g.zurueck);
    sperren(kn.weiter, g.weiter); sperren(kn.nehmen, g.nehmen); sperren(kn.frei, g.frei); sperren(kn.ab, g.ab);
    sperren(kn.uebernahme, ansicht !== 'vorschlag' ? 'Wechsle zum Vorschlag aus dem Bestand' : (!d.darf.anfordern ? 'Dir fehlt das Recht, Bedarf anzufordern' : (gehakt.size ? null : 'Markiere zuerst Zeilen des Vorschlags')));
  }

  function zeichneAnforderungen(alle) {
    filter.setzeZeilen(alle);
    const zeilen = filter.anwenden(alle);
    if (!zeilen.some((x) => x.id === anfId)) { anfId = zeilen[0]?.id || null; posId = null; }
    stand.textContent = ansicht === 'beimir'
      ? (alle.length ? `${alle.length} ${alle.length === 1 ? 'Anforderung wartet' : 'Anforderungen warten'} auf dich` : 'Nichts wartet auf dich.')
      : `${alle.length} ${alle.length === 1 ? 'Anforderung' : 'Anforderungen'}`;
    if (alle.length === 0) {
      liste.replaceChildren(h('p', { class: 'hinweis', id: 'bs-leer' }, ansicht === 'beimir'
        ? 'Hier steht, was auf dich wartet: deine Entwürfe, was an dich oder deine Rolle weitergeleitet ist, was du freigeben oder bestellen kannst.'
        : 'Noch keine Anforderung. Mit „Anforderung neu …“ meldest du Bedarf – oder du übernimmst Zeilen aus dem Vorschlag.'));
    } else {
      const sp = filter.spalten();
      liste.replaceChildren(h('div', { class: 'liste-scroll' }, h('table', { class: 'liste bs-tabelle', id: 'bs-tabelle' }, h('thead', {}, filter.kopf),
        h('tbody', {}, zeilen.length ? zeilen.map((x) => {
          const tr = h('tr', { tabindex: '0', class: [x.id === anfId ? 'gewaehlt' : '', ['abgelehnt', 'zurueckgezogen', 'erledigt'].includes(x.stand) ? 'bs-leise' : ''], 'aria-selected': x.id === anfId ? 'true' : 'false', 'data-id': x.id },
            sp.map((s) => h('td', { class: s.klasse || '' }, s.wert(x))));
          const waehlen = () => { if (anfId !== x.id) { anfId = x.id; posId = null; zeichnen(); liste.querySelector(`tr[data-id="${x.id}"]`)?.focus(); } };
          const oeffnen = () => { waehlen(); if (!gruende(x).aendern) anforderungDialog(x, null); };
          tr.addEventListener('click', waehlen);
          tr.addEventListener('dblclick', oeffnen);
          tr.addEventListener('keydown', (ev) => { if (ev.target !== tr) return; if (ev.key === 'Enter') { ev.preventDefault(); oeffnen(); } else if (ev.key === ' ') { ev.preventDefault(); waehlen(); } });
          return tr;
        }) : h('tr', { class: 'leer' }, h('td', { colspan: sp.length, id: 'bs-kein-treffer' }, 'Keine Anforderung passt zum Filter.'))))),
      h('div', { class: 'liste-fuss' }, h('span', { id: 'bs-anzahl' }, filter.aktiv() ? `${zeilen.length} von ${alle.length} · gefiltert` : '')));
    }
    zeichneUnten();
  }

  function zeichneUnten() {
    const x = anf();
    if (!x) { unten.replaceChildren(h('p', { class: 'hinweis' }, 'Wähle oben eine Anforderung: Hier stehen ihre Positionen und ihr Verlauf.')); return; }
    if (!x.positionen.some((p) => p.id === posId)) posId = (x.positionen.find((p) => p.zustand === 'offen') || x.positionen[0])?.id || null;
    const p0 = pos();
    const g = posGruende(x, p0);
    sperren(kn.haken, g.haken); sperren(kn.hakenWeg, g.hakenWeg); sperren(kn.streichen, g.streichen); sperren(kn.erhalten, g.erhalten);
    const marke = (text, art = '') => h('span', { class: ['bs-marke', art] }, text);
    const kopf = ['Nr.', 'Position', 'Angefordert', 'Bestellt', 'Erhalten', 'Stand', 'Lieferant', 'Bestellnummer', 'Bestellt am', 'Liefertermin', ...(d.darf.preise ? ['Preis je Einheit'] : [])];
    const zahlSp = new Set([0, 2, 3, 4, 10]);
    const zeile = (p) => {
      const zellen = [String(p.nr),
        [p.bezeichnung, p.art === 'frei' ? marke('nicht fürs Lager') : (p.meta ? marke('Artikelgruppe: einer davon') : (p.artikel ? null : marke('noch kein Artikel', 'warnung'))),
          p.vermerk ? marke(p.vermerk) : null, p.bestell_vermerk ? marke(p.bestell_vermerk) : null],
        `${mengeText(p.menge)} ${p.einheit}`, p.bestellt_menge === null ? '' : mengeText(p.bestellt_menge), Number(p.erhalten_menge) > 0 ? mengeText(p.erhalten_menge) : '',
        [POSITION[p.zustand], p.ueberfaellig ? marke('überfällig', 'warnung') : null, p.grund ? marke(p.grund) : null],
        p.lieferant || '', p.bestellnummer || '', p.bestellt_am ? datumText(p.bestellt_am) : '', p.liefertermin ? datumText(p.liefertermin) : '', ...(d.darf.preise ? [preisText(p.preis)] : [])];
      const tr = h('tr', { tabindex: '0', class: [p.id === posId ? 'gewaehlt' : '', p.zustand === 'gestrichen' ? 'bs-leise' : ''], 'aria-selected': p.id === posId ? 'true' : 'false', 'data-id': p.id },
        zellen.map((z, n) => h('td', { class: zahlSp.has(n) ? 'zahl' : '' }, z)));
      const waehlen = () => { if (posId !== p.id) { posId = p.id; zeichneUnten(); unten.querySelector(`tr[data-id="${p.id}"]`)?.focus(); } };
      const oeffnen = () => { waehlen(); const gg = posGruende(x, p); if (!gg.haken) bestellenDialog(p); else if (!gg.erhalten) erhaltenDialog(p); };
      tr.addEventListener('click', waehlen);
      tr.addEventListener('dblclick', oeffnen);
      tr.addEventListener('keydown', (ev) => { if (ev.target !== tr) return; if (ev.key === 'Enter') { ev.preventDefault(); oeffnen(); } else if (ev.key === ' ') { ev.preventDefault(); waehlen(); } });
      return tr;
    };
    const verlaufText = (v) => [v.wer, ' ', VERLAUF[v.art] || v.art, v.position ? ` (Position ${v.position})` : '', v.an ? ` an ${v.an}${v.an_rolle && v.art === 'weitergeleitet' ? ' (Rolle)' : ''}` : '',
      v.wozu ? ` ${WOZU[v.wozu]}` : '', v.text ? ` – ${v.art === 'weitergeleitet' ? `„${v.text}“` : v.text}` : ''].join('');
    unten.replaceChildren(
      h('div', { class: 'bs-kopf', id: 'bs-kopf' }, h('strong', {}, x.nummer || 'Entwurf'), h('span', {}, x.wofuer), h('span', { id: 'bs-kopf-stand' }, `Stand: ${STAND[x.stand]}`),
        x.termin ? h('span', {}, `gewünscht bis ${datumText(x.termin)}`) : null, h('span', {}, `von ${x.von}`),
        x.bei ? h('span', { id: 'bs-kopf-bei' }, `liegt bei ${x.bei}${x.bei_rolle ? ' (Rolle)' : ''} ${WOZU[x.bei_wozu]}`) : null,
        x.grund ? h('span', { id: 'bs-kopf-grund' }, `Grund: ${x.grund}`) : null),
      h('div', { class: 'bs-leiste nicht-drucken' }, kn.haken, kn.hakenWeg, kn.streichen, kn.erhalten),
      x.positionen.length ? h('table', { class: 'liste bs-tabelle', id: 'bs-positionen' }, h('thead', {}, h('tr', {}, kopf.map((t, n) => h('th', { class: zahlSp.has(n) ? 'zahl' : '' }, t)))), h('tbody', {}, x.positionen.map(zeile)))
        : h('p', { class: 'hinweis', id: 'bs-ohne-position' }, 'Noch keine Position. Mit „Bearbeiten …“ trägst du ein, was gebraucht wird.'),
      h('h3', {}, 'Verlauf'),
      x.verlauf.length ? h('ul', { class: 'bs-verlauf', id: 'bs-verlauf' }, x.verlauf.map((v) => h('li', {}, h('time', {}, zeit(v.zeit)), verlaufText(v))))
        : h('p', { class: 'hinweis' }, `Angelegt am ${datumText(x.angelegt_am)} von ${x.von}; noch nicht angefordert.`));
  }

  function zeichneVorschlag() {
    const alle = d.vorschlag;
    stand.textContent = !d.lager ? '' : (alle.length ? `${alle.length} Artikel unter Mindestbestand · Stand ${datumText(d.heute)} · Vorschlag = Sollbestand (sonst Mindestbestand) − verfügbar − schon angefordert oder bestellt` : '');
    if (!d.lager || alle.length === 0) {
      liste.replaceChildren(h('p', { class: 'hinweis', id: 'bs-vorschlag-leer' }, !d.lager ? 'Das Modul Lager ist in diesem Buch nicht aktiv – einen Vorschlag aus dem Bestand gibt es deshalb nicht. Anforderungen legst du von Hand an.'
        : (d.darf.anfordern ? 'Kein Artikel liegt unter seinem Mindestbestand.' : 'Den Vorschlag sieht, wer Bedarf anfordern darf.')));
    } else {
      vFilter.setzeZeilen(alle);
      const zeilen = vFilter.anwenden(alle);
      const sp = vFilter.spalten();
      liste.replaceChildren(h('div', { class: 'liste-scroll' }, h('table', { class: 'liste bs-tabelle', id: 'bs-vorschlag' }, h('thead', {}, vFilter.kopf),
        h('tbody', {}, zeilen.length ? zeilen.map((v) => {
          const id = vId(v);
          const nichts = Number(v.vorschlag) <= 0;
          const box = h('input', { type: 'checkbox', checked: gehakt.has(id), disabled: nichts, title: nichts ? 'Was fehlt, ist schon angefordert oder bestellt' : 'Für die Anforderung markieren', 'aria-label': `markieren: ${v.bezeichnung}`,
            onchange: () => { if (box.checked) gehakt.add(id); else gehakt.delete(id); zeichnen(); } });
          return h('tr', { 'data-id': id, class: nichts ? 'bs-leise' : '' }, sp.map((s) => h('td', { class: s.klasse || '' }, s.id === 'haken' ? box : s.wert(v))));
        }) : h('tr', { class: 'leer' }, h('td', { colspan: sp.length }, 'Kein Artikel passt zum Filter.'))))),
      h('div', { class: 'liste-fuss' }, h('span', { id: 'bs-v-anzahl' }, `${gehakt.size} markiert`)));
    }
    unten.replaceChildren(h('p', { class: 'hinweis' }, 'Markiere, was angefordert werden soll, und wähle „In Anforderung übernehmen …“. Die Mengen lassen sich in der Anforderung ändern. Was schon angefordert oder bestellt ist, zieht der Vorschlag ab – so wird nichts doppelt bestellt.'));
  }

  async function laden(waehle) {
    try { d = await k.api('GET', basis); } catch (x) { k.fehler(x); }
    for (const id of [...gehakt]) if (!d.vorschlag.some((v) => vId(v) === id && Number(v.vorschlag) > 0)) gehakt.delete(id);
    if (waehle) { anfId = waehle; if (ansicht === 'vorschlag' || (ansicht === 'beimir' && !d.anforderungen.some((x) => x.id === waehle && x.bei_mir))) ansicht = 'alle'; }
    zeichnen();
  }
  /** Eine Handlung an der gewählten Anforderung ohne Dialog. */
  async function tun(was, daten, meldung) {
    const x = anf();
    try { const r = await k.api('POST', `${basis}/anforderungen/${x.id}/${was}`, { json: daten || {} }); await laden(x.id); k.melden(meldung(r || {})); } catch (e) { k.fehler(e); }
  }
  async function zurueckziehen() {
    const x = anf();
    try {
      const r = await k.api('POST', `${basis}/anforderungen/${x.id}/zurueckziehen`, { json: {} });
      await laden(r.ergebnis === 'geloescht' ? null : x.id);
      k.melden(r.ergebnis === 'geloescht' ? 'Entwurf gelöscht' : `${x.nummer} zurückgezogen`);
    } catch (e) { k.fehler(e); }
  }
  async function hakenZurueck() {
    const p = pos();
    try { await k.api('POST', `${basis}/positionen/${p.id}/zuruecknehmen`, { json: {} }); await laden(anfId); k.melden(`Haken zurückgenommen – „${p.bezeichnung}“ ist wieder offen`); } catch (e) { k.fehler(e); }
  }

  /**
   * Anforderung anlegen oder ändern. x: die Anforderung oder null; vorgabe: Positionen aus dem Vorschlag (nur bei neu).
   * Eine Position ist ein Artikel, eine Artikelgruppe („einer davon“), etwas Neues fürs Lager (der Artikel entsteht
   * spätestens beim Bestellen, BA12) oder etwas, das nie ins Lager geht (BA16).
   */
  function anforderungDialog(x, vorgabe) {
    let zeilen = x ? x.positionen.map((p) => ({ art: p.art, artikel: p.artikel, meta: p.meta, bezeichnung: p.bezeichnung, einheit: p.einheit, menge: p.menge, vermerk: p.vermerk || '' })) : (vorgabe || []);
    const wofuer = h('input', { id: 'bs-f-wofuer', maxlength: '200', value: x?.wofuer || '', autocomplete: 'off' });
    const termin = datumFeld({ id: 'bs-f-termin', value: x?.termin || '' });
    const was = h('select', { id: 'bs-p-was' },
      h('option', { value: '' }, '– wählen –'),
      d.lager ? h('option', { value: 'neu' }, 'Neu fürs Lager (noch kein Artikel)') : null,
      h('option', { value: 'frei' }, 'Nicht fürs Lager (Dienstleistung, Gerät, Verbrauch ohne Bestand)'),
      d.gruppen.length ? h('optgroup', { label: 'Artikelgruppen – einer davon' }, d.gruppen.map((g) => h('option', { value: `m:${g.id}` }, g.bezeichnung))) : null,
      d.artikel.length ? h('optgroup', { label: 'Artikel' }, d.artikel.map((a) => h('option', { value: `a:${a.id}` }, a.bezeichnung + (a.nummer ? ` (${a.nummer})` : '')))) : null);
    const bez = h('input', { id: 'bs-p-bezeichnung', maxlength: '200', autocomplete: 'off' });
    const einheit = h('input', { id: 'bs-p-einheit', maxlength: '20', autocomplete: 'off', value: 'Stück' });
    const menge = h('input', { id: 'bs-p-menge', inputmode: 'decimal', autocomplete: 'off', value: '1' });
    const vermerk = h('input', { id: 'bs-p-vermerk', maxlength: '200', autocomplete: 'off' });
    const dazu = knopf('Dazu', hinzu, '', { id: 'bs-p-dazu', title: 'Die Position in die Anforderung aufnehmen' });
    const wasHinweis = h('span', { id: 'bs-p-hinweis' }, ' ');
    const tabelle = h('div', { class: 'bs-pos-rahmen', id: 'bs-f-positionen' });
    const mitAnfordern = !x || (x.zustand === 'entwurf' && x.eigene);

    const frei = () => was.value === 'neu' || was.value === 'frei';
    function neueZeile() {
      const m = mengeLesen(menge.value);
      if (was.value === '') return { grund: 'Wähle, was gebraucht wird' };
      if (m === null) return { grund: 'Menge größer als null erwartet' };
      if (was.value.startsWith('a:')) {
        const a = artikelVon(was.value.slice(2));
        if (a.in_stuecken && !Number.isInteger(Number(m))) return { grund: 'Der Artikel wird in Stücken gezählt: ganze Zahl erwartet' };
        return { zeile: { art: 'lager', artikel: a.id, meta: null, bezeichnung: a.bezeichnung, einheit: a.einheit, menge: m, vermerk: vermerk.value.trim() } };
      }
      if (was.value.startsWith('m:')) {
        const g = d.gruppen.find((y) => y.id === was.value.slice(2));
        return { zeile: { art: 'lager', artikel: null, meta: g.id, bezeichnung: g.bezeichnung, einheit: g.einheit, menge: m, vermerk: vermerk.value.trim() } };
      }
      if (bez.value.trim() === '') return { grund: 'Bezeichnung fehlt' };
      if (einheit.value.trim() === '') return { grund: 'Einheit fehlt' };
      return { zeile: { art: was.value === 'frei' ? 'frei' : 'lager', artikel: null, meta: null, bezeichnung: bez.value.trim(), einheit: einheit.value.trim(), menge: m, vermerk: vermerk.value.trim() } };
    }
    function pruefen() {
      // Bezeichnung und Einheit eines Artikels kommen vom Lager: die Felder zeigen sie und sind gesperrt
      const gew = was.value.startsWith('a:') ? artikelVon(was.value.slice(2)) : (was.value.startsWith('m:') ? d.gruppen.find((y) => y.id === was.value.slice(2)) : null);
      bez.disabled = !frei(); einheit.disabled = !frei();
      if (gew) { bez.value = gew.bezeichnung; einheit.value = gew.einheit; }
      wasHinweis.textContent = was.value === 'neu' ? 'Der Artikel wird spätestens beim Bestellen angelegt.' : (was.value === 'frei' ? 'Wird nach der Bestellung von Hand als erhalten gemeldet.' : (was.value.startsWith('m:') ? 'Welcher Artikel der Gruppe bestellt wird, entscheidet der Einkauf.' : ' '));
      sperren(dazu, neueZeile().grund || null);
      const leer = wofuer.value.trim() === '' ? 'Wofür fehlt' : (termin.wert() === null ? 'Gewünscht bis: kein gültiges Datum' : null);
      sperren(dlgTeile.ja, leer);
      if (anfordernKn) sperren(anfordernKn, leer || (zeilen.length ? null : 'Die Anforderung hat noch keine Position'));
    }
    function hinzu() {
      const n = neueZeile();
      if (!n.zeile) return;
      zeilen.push(n.zeile);
      was.value = ''; bez.value = ''; menge.value = '1'; vermerk.value = ''; einheit.value = 'Stück';
      zeichneZeilen(); pruefen(); was.focus();
    }
    function zeichneZeilen() {
      tabelle.replaceChildren(zeilen.length ? h('table', { class: 'liste bs-tabelle', id: 'bs-f-tabelle' },
        h('thead', {}, h('tr', {}, ['Nr.', 'Position', 'Menge', 'Einheit', 'Vermerk', ''].map((t) => h('th', {}, t)))),
        h('tbody', {}, zeilen.map((z, n) => {
          const m = h('input', { class: 'bs-kurz bs-f-menge', inputmode: 'decimal', value: mengeText(z.menge), 'aria-label': `Menge: ${z.bezeichnung}`,
            onchange: () => { const w = mengeLesen(m.value); if (w !== null) z.menge = w; m.value = mengeText(z.menge); } });
          return h('tr', {}, h('td', {}, String(n + 1)),
            h('td', {}, z.bezeichnung, z.art === 'frei' ? h('span', { class: 'bs-marke' }, 'nicht fürs Lager') : (z.meta ? h('span', { class: 'bs-marke' }, 'Artikelgruppe: einer davon') : (z.artikel ? null : h('span', { class: 'bs-marke warnung' }, 'noch kein Artikel')))),
            h('td', {}, m), h('td', {}, z.einheit), h('td', {}, z.vermerk),
            h('td', {}, knopf('Entfernen', () => { zeilen = zeilen.filter((y) => y !== z); zeichneZeilen(); pruefen(); }, '', { class: 'knopf bs-f-weg', title: 'Die Position aus der Anforderung nehmen' })));
        }))) : h('p', { class: 'hinweis', id: 'bs-f-leer' }, 'Noch keine Position. Wähle unten, was gebraucht wird, und nimm es mit „Dazu“ auf.'));
    }
    const daten = () => ({ wofuer: wofuer.value.trim(), termin: termin.wert() || null,
      positionen: zeilen.map((z) => ({ art: z.art, menge: z.menge, ...(z.vermerk ? { vermerk: z.vermerk } : {}), ...(z.artikel ? { artikel: z.artikel } : (z.meta ? { meta: z.meta } : { bezeichnung: z.bezeichnung, einheit: z.einheit })) })) });
    async function speichern(anfordern) {
      const r = x ? await k.api('PUT', `${basis}/anforderungen/${x.id}`, { json: daten() }) : await k.api('POST', `${basis}/anforderungen`, { json: daten() });
      let text = x ? 'Anforderung gespeichert' : 'Entwurf gespeichert';
      if (anfordern) {
        try { text = `Angefordert als ${(await k.api('POST', `${basis}/anforderungen/${r.id}/anfordern`, { json: {} })).nummer}`; } catch (e) { await laden(r.id); throw e; }
      }
      if (vorgabe) gehakt.clear();
      await laden(r.id);
      k.melden(text);
    }
    const anfordernKn = mitAnfordern ? knopf('Speichern und anfordern', () => dlgTeile.laufen(() => speichern(true)), '', { id: 'bs-f-anfordern', title: 'Speichern und gleich abgeben – die Anforderung bekommt ihre Nummer' }) : null;
    const dlgTeile = dialog({ id: 'bs-dialog-anforderung', breit: true, titel: x ? `Anforderung bearbeiten: ${x.nummer || 'Entwurf'}` : 'Anforderung neu',
      text: 'Wofür nennt den Zweck – nie eine Person. Menge und Lieferant legt am Ende der Einkauf fest.',
      ja: mitAnfordern ? 'Als Entwurf speichern' : 'Speichern', zusatz: anfordernKn ? [anfordernKn] : [], pruefen,
      felder: [h('div', { class: 'bs-raster' }, rfeld('Wofür', wofuer, 'z. B. „Auffüllen Behandlungsraum 2“', 4), rfeld('Gewünscht bis', termin.el, 'freiwillig', 2)),
        tabelle,
        h('fieldset', { class: 'bs-block' }, h('legend', {}, 'Position dazu'),
          h('div', { class: 'bs-raster' }, rfeld('Was', was, wasHinweis, 6),
            rfeld('Bezeichnung', bez, '', 2), rfeld('Menge', menge, '', 1), rfeld('Einheit', einheit, '', 1), rfeld('Vermerk', vermerk, 'freiwillig', 1), rwahl(1, dazu)))],
      senden: () => speichern(false) });
    for (const el of [was, bez, einheit, menge, vermerk, wofuer, termin.ein]) { el.addEventListener('input', pruefen); el.addEventListener('change', pruefen); }
    zeichneZeilen(); pruefen();
    wofuer.focus();
  }

  function vorschlagUebernehmen() {
    const zeilen = d.vorschlag.filter((v) => gehakt.has(vId(v)) && Number(v.vorschlag) > 0).map((v) => ({ art: 'lager', artikel: v.gruppe ? null : v.artikel, meta: v.gruppe ? v.meta : null,
      bezeichnung: v.bezeichnung, einheit: v.einheit, menge: String(v.gruppe || !artikelVon(v.artikel)?.in_stuecken ? v.vorschlag : Math.ceil(Number(v.vorschlag))), vermerk: '' }));
    anforderungDialog(null, zeilen);
  }

  function weiterleitenDialog() {
    const x = anf();
    const moeglich = { freigabe: x.zustand === 'angefordert' ? null : 'schon freigegeben', bestellen: x.zustand === 'freigegeben' ? null : 'erst nach der Freigabe', rueckfrage: null };
    const wozu = h('select', { id: 'bs-w-wozu' }, Object.entries(WOZU).map(([id, t]) => h('option', { value: id, disabled: Boolean(moeglich[id]) }, t + (moeglich[id] ? ` – ${moeglich[id]}` : ''))));
    wozu.value = x.zustand === 'angefordert' ? 'freigabe' : 'bestellen';
    const an = h('select', { id: 'bs-w-an' }, h('option', { value: '' }, '– wählen –'),
      h('optgroup', { label: 'Rollen – wer die Anforderung übernimmt, hat sie' }, d.rollen.map((r) => h('option', { value: `r:${r.name}` }, r.name))),
      h('optgroup', { label: 'Personen' }, d.personen.map((p) => h('option', { value: `p:${p.id}` }, p.name))));
    const text = h('input', { id: 'bs-w-text', maxlength: '300', autocomplete: 'off' });
    const anHinweis = h('span', { id: 'bs-w-hinweis' }, ' ');
    const kann = { freigabe: 'darf nicht freigeben', bestellen: 'darf nicht bestellen', rueckfrage: 'arbeitet nicht mit Anforderungen' };
    // Die Auswahl bleibt, wie sie ist (SA6): Passt sie nicht zum Wozu, sperrt das den Knopf und nennt den Grund
    function pruefen() {
      const ziel = an.value.startsWith('r:') ? d.rollen.find((r) => r.name === an.value.slice(2)) : d.personen.find((p) => p.id === an.value.slice(2));
      const grund = an.value === '' ? 'Wähle, an wen' : (!ziel.wozu.includes(wozu.value) ? `${ziel.name} ${kann[wozu.value]}` : null);
      anHinweis.textContent = an.value !== '' && grund ? grund : ' ';
      sperren(t.ja, grund);
    }
    const t = dialog({ id: 'bs-dialog-weiter', titel: `Weiterleiten: ${x.nummer}`, text: 'Die Anforderung steht dann bei der Person oder bei allen mit der Rolle unter „Bei mir“. Der Verlauf merkt sich, wer an wen und wozu.', ja: 'Weiterleiten', pruefen,
      felder: [h('div', { class: 'bs-raster' }, rfeld('Wozu', wozu, '', 2), rfeld('An', an, anHinweis, 4), rfeld('Ein Satz dazu', text, 'freiwillig', 6))],
      senden: async () => {
        await k.api('POST', `${basis}/anforderungen/${x.id}/weiterleiten`, { json: { wozu: wozu.value, ...(an.value.startsWith('r:') ? { rolle: an.value.slice(2) } : { person: an.value.slice(2) }), ...(text.value.trim() ? { text: text.value.trim() } : {}) } });
        await laden(x.id);
        k.melden(`Weitergeleitet an ${an.selectedOptions[0].textContent} ${WOZU[wozu.value]}`);
      } });
    wozu.addEventListener('change', pruefen); an.addEventListener('change', pruefen);
    pruefen();
  }

  /** Dialog mit einem Grund: Ablehnen, Streichen. */
  function grundDialog(o) {
    const grund = h('input', { id: `${o.id}-grund`, maxlength: String(o.max), autocomplete: 'off' });
    const pruefen = () => sperren(t.ja, grund.value.trim() === '' ? 'Grund fehlt' : null);
    const t = dialog({ id: o.id, titel: o.titel, text: o.text, ja: o.ja, pruefen, felder: [h('div', { class: 'bs-raster' }, rfeld('Grund', grund, '', 6))], senden: () => o.senden(grund.value.trim()) });
    grund.addEventListener('input', pruefen);
    pruefen(); grund.focus();
  }
  function ablehnenDialog() {
    const x = anf();
    grundDialog({ id: 'bs-dialog-ablehnen', max: 300, titel: `Ablehnen: ${x.nummer}`, text: 'Die Anforderung bleibt mit dem Grund stehen; wer sie angelegt hat, sieht ihn.', ja: 'Ablehnen',
      senden: async (grund) => { await k.api('POST', `${basis}/anforderungen/${x.id}/ablehnen`, { json: { grund } }); await laden(x.id); k.melden(`${x.nummer} abgelehnt`); } });
  }
  function streichenDialog() {
    const p = pos();
    grundDialog({ id: 'bs-dialog-streichen', max: 200, titel: `Streichen: ${p.bezeichnung}`, text: 'Die Position wird nicht bestellt. Sie bleibt mit dem Grund in der Anforderung stehen.', ja: 'Streichen',
      senden: async (grund) => { await k.api('POST', `${basis}/positionen/${p.id}/streichen`, { json: { grund } }); await laden(anfId); k.melden(`„${p.bezeichnung}“ gestrichen`); } });
  }

  /**
   * Eine Position als bestellt abhaken (BA21, BA23, BA27): erfassen, was dann feststeht. Über die Menge entscheidet der
   * Einkäufer; der Lieferant ist frei gewählt, der letzte Bezug nur ein Hinweis (BA22, BA29). Für eine Position fürs Lager
   * steht spätestens jetzt der Artikel fest (BA12): wählen oder hier anlegen.
   */
  function bestellenDialog(p) {
    const lagerPos = p.art === 'lager';
    const heute = d.heute;
    const menge = h('input', { id: 'bs-b-menge', inputmode: 'decimal', autocomplete: 'off', value: mengeText(p.menge) });
    const rest = h('input', { id: 'bs-b-rest', inputmode: 'decimal', autocomplete: 'off' });
    const vermerk = h('input', { id: 'bs-b-vermerk', maxlength: '200', autocomplete: 'off', placeholder: 'z. B. 2 Packungen zu 50' });
    const lieferant = h('input', { id: 'bs-b-lieferant', maxlength: '200', autocomplete: 'off', list: 'bs-b-lieferanten', value: merk.lieferant });
    const lieferanten = h('datalist', { id: 'bs-b-lieferanten' }, d.lieferanten.map((l) => h('option', { value: l })));
    const nummerDort = h('input', { id: 'bs-b-nummer-dort', maxlength: '200', autocomplete: 'off' });
    const bestellnummer = h('input', { id: 'bs-b-bestellnummer', maxlength: '100', autocomplete: 'off', value: merk.bestellnummer });
    const am = datumFeld({ id: 'bs-b-am', value: merk.bestellt_am || heute });
    const termin = datumFeld({ id: 'bs-b-termin', value: merk.liefertermin || '' });
    const preis = h('input', { id: 'bs-b-preis', inputmode: 'decimal', autocomplete: 'off' });
    const mengeHinweis = h('span', { id: 'bs-b-menge-hinweis' }, ' ');
    const bezugHinweis = h('span', { id: 'bs-b-bezug' }, ' ');
    const gtinHinweis = h('span', { id: 'bs-b-gtin-hinweis' }, ' ');
    const nummerHinweis = h('span', { id: 'bs-b-nummer-hinweis' }, 'des Shops; freiwillig');
    // Artikel: bei einer Gruppe einer ihrer Artikel, sonst jeder – oder neu anlegen
    const kandidaten = p.meta ? d.artikel.filter((a) => a.meta_artikel === p.meta) : d.artikel;
    const artikel = lagerPos ? h('select', { id: 'bs-b-artikel' }, h('option', { value: '' }, '– wählen –'), p.meta ? null : h('option', { value: 'neu' }, 'Neuen Artikel anlegen (Angaben unten)'),
      kandidaten.map((a) => h('option', { value: a.id }, a.bezeichnung + (a.nummer ? ` (${a.nummer})` : '')))) : null;
    if (artikel) artikel.value = p.artikel && kandidaten.some((a) => a.id === p.artikel) ? p.artikel : (p.artikel || p.meta ? '' : 'neu');
    const gtin = h('input', { id: 'bs-b-gtin', inputmode: 'numeric', maxlength: '14', autocomplete: 'off' });
    const n = { bez: h('input', { id: 'bs-n-bezeichnung', maxlength: '200', autocomplete: 'off', value: p.artikel || p.meta ? '' : p.bezeichnung }), nummer: h('input', { id: 'bs-n-nummer', maxlength: '40', autocomplete: 'off' }),
      art: h('select', { id: 'bs-n-art' }, ARTEN.map(([w, t]) => h('option', { value: w }, t))), einheit: h('input', { id: 'bs-n-einheit', maxlength: '20', autocomplete: 'off', value: p.artikel || p.meta ? 'Stück' : p.einheit }),
      charge: h('input', { type: 'checkbox', id: 'bs-n-charge' }), serie: h('input', { type: 'checkbox', id: 'bs-n-serie' }), stuecke: h('input', { type: 'checkbox', id: 'bs-n-stuecke', checked: true }),
      verfall: h('input', { type: 'checkbox', id: 'bs-n-verfall' }) };
    const neu = () => artikel?.value === 'neu';
    const gewaehlt = () => (artikel && artikel.value && artikel.value !== 'neu' ? artikelVon(artikel.value) : null);

    function pruefen() {
      const a = gewaehlt();
      const einheit = neu() ? (n.einheit.value.trim() || p.einheit) : (a ? a.einheit : p.einheit);
      const stueck = neu() ? n.stuecke.checked : Boolean(a?.in_stuecken);
      for (const el of [n.bez, n.nummer, n.art, n.einheit, n.charge, n.serie, n.stuecke, n.verfall]) el.disabled = !neu();
      if (neu() && n.serie.checked) { n.stuecke.checked = true; n.stuecke.disabled = true; }
      // GTIN: am neuen Artikel frei; am vorhandenen nur, wo keine steht (BA12)
      gtin.disabled = !lagerPos || (!neu() && (!a || Boolean(a.gtin)));
      if (a?.gtin) gtin.value = a.gtin; else if (gtin.disabled) gtin.value = '';
      gtinHinweis.textContent = !lagerPos ? ' ' : (a?.gtin ? 'steht am Artikel' : (a || neu() ? 'unter dem Strichcode der Packung; freiwillig' : ' '));
      const bz = a && d.bezug[a.id];
      bezugHinweis.textContent = bz ? `Zuletzt bestellt am ${datumText(bz.am)} bei ${bz.lieferant}${bz.lieferant_nummer ? `, Art.-Nr. dort ${bz.lieferant_nummer}` : ''}${bz.preis ? ` – ${preisText(bz.preis)} je ${a.einheit}` : ''}. Nur ein Hinweis.` : ' ';
      // Die stehen gebliebene Bestellnummer wird nie still geleert (SA6) – der Hinweis sagt, woher sie stammt
      nummerHinweis.textContent = merk.bestellnummer && bestellnummer.value === merk.bestellnummer && lieferant.value.trim() !== merk.lieferant ? `steht noch von der Bestellung bei ${merk.lieferant}` : 'des Shops; freiwillig';
      const m = mengeLesen(menge.value);
      const r = rest.value.trim() === '' ? '' : mengeLesen(rest.value);
      mengeHinweis.textContent = m !== null && Number(m) !== Number(p.menge) ? `${mengeText(m)} statt ${mengeText(p.menge)} ${einheit} angefordert` : `angefordert: ${mengeText(p.menge)} ${einheit}`;
      const g = gtin.value.trim();
      const grund = (lagerPos && artikel.value === '' ? (p.meta ? 'Wähle den Artikel der Gruppe, der bestellt wird' : 'Wähle den Artikel oder lege ihn an') : null)
        || (neu() && n.bez.value.trim() === '' ? 'Neuer Artikel: Bezeichnung fehlt' : null) || (neu() && n.einheit.value.trim() === '' ? 'Neuer Artikel: Einheit fehlt' : null)
        || (neu() && n.verfall.checked && !n.charge.checked && !n.serie.checked ? 'Ein Verfallsdatum gehört zu einer Charge oder einem Stück' : null)
        || (!gtin.disabled && g !== '' && !/^\d{8,14}$/.test(g) ? `GTIN: 8 bis 14 Ziffern erwartet – ${/^\d*$/.test(g) ? `es sind ${g.length}` : 'nur Ziffern'}` : null)
        || (m === null ? 'Menge größer als null erwartet' : null) || (stueck && !Number.isInteger(Number(m)) ? 'Der Artikel wird in Stücken gezählt: ganze Zahl erwartet' : null)
        || (r === null ? 'Rest: Menge größer als null erwartet – oder leer lassen' : null) || (stueck && r && !Number.isInteger(Number(r)) ? 'Rest: ganze Zahl erwartet' : null)
        || (lieferant.value.trim() === '' ? 'Lieferant fehlt' : null)
        || (!am.wert() ? 'Bestellt am: Datum fehlt oder ist ungültig' : (am.wert() > heute ? 'Das Bestelldatum liegt nicht in der Zukunft' : null))
        || (termin.wert() === null ? 'Liefertermin: kein gültiges Datum' : null) || (preisLesen(preis.value) === null ? 'Preis: Betrag mit höchstens vier Nachkommastellen erwartet' : null);
      // was falsch ist, steht im Dialog – was nur noch fehlt, nennt der gesperrte Knopf (F213)
      t.fehler.textContent = grund && !/fehlt$|^Wähle/.test(grund) ? grund : ' ';
      sperren(t.ja, grund);
    }
    const t = dialog({ id: 'bs-dialog-bestellen', breit: true, titel: `Als bestellt abhaken: ${p.bezeichnung}`, ja: 'Bestellt', pruefen,
      text: lagerPos ? 'Erfasse, was im Shop bestellt wurde. Lieferant, Bestellnummer und Termine bleiben für die nächste Position stehen.' : 'Nicht fürs Lager: Nach der Lieferung wird die Position von Hand als erhalten gemeldet.',
      felder: [lieferanten,
        lagerPos ? h('div', { class: 'bs-raster' }, rfeld('Artikel', artikel, bezugHinweis, 4), rfeld('GTIN', gtin, gtinHinweis, 2)) : null,
        lagerPos ? h('fieldset', { class: 'bs-block', id: 'bs-n-block' }, h('legend', {}, 'Neuer Artikel'),
          h('div', { class: 'bs-raster' }, rfeld('Bezeichnung', n.bez, '', 3), rfeld('Artikelnummer', n.nummer, 'freiwillig', 1), rfeld('Art', n.art, '', 1), rfeld('Lagereinheit', n.einheit, '', 1),
            rwahl(6, h('label', { class: 'feld-wahl' }, n.charge, 'hat Charge'), h('label', { class: 'feld-wahl' }, n.serie, 'hat Seriennummer'), h('label', { class: 'feld-wahl' }, n.stuecke, 'wird in Stücken gezählt'),
              h('label', { class: 'feld-wahl' }, n.verfall, 'Verfallsdatum ist Pflicht')))) : null,
        h('div', { class: 'bs-raster' }, rfeld('Menge bestellt', menge, mengeHinweis, 2), rfeld('Rest bleibt offen', rest, 'leer: Die Position ist mit dem Haken erledigt', 2), rfeld('Vermerk', vermerk, 'freiwillig', 2),
          rfeld('Lieferant', lieferant, 'Shop oder Händler', 2), rfeld('Artikelnummer dort', nummerDort, 'freiwillig', 2), rfeld('Bestellnummer', bestellnummer, nummerHinweis, 2),
          rfeld('Bestellt am', am.el, '', 2), rfeld('Liefertermin', termin.el, 'zugesagt; freiwillig', 2), rfeld('Preis je Einheit', preis, 'in €; freiwillig', 2))],
      senden: async () => {
        const a = gewaehlt();
        const g = gtin.value.trim();
        const daten = { menge: mengeLesen(menge.value), lieferant: lieferant.value.trim(), bestellt_am: am.wert(),
          ...(nummerDort.value.trim() ? { lieferant_nummer: nummerDort.value.trim() } : {}), ...(bestellnummer.value.trim() ? { bestellnummer: bestellnummer.value.trim() } : {}),
          ...(vermerk.value.trim() ? { vermerk: vermerk.value.trim() } : {}), ...(termin.wert() ? { liefertermin: termin.wert() } : {}), ...(preisLesen(preis.value) ? { preis: preisLesen(preis.value) } : {}),
          ...(rest.value.trim() ? { rest: mengeLesen(rest.value) } : {}),
          ...(neu() ? { neuer_artikel: { bezeichnung: n.bez.value.trim(), nummer: n.nummer.value.trim() || null, art: n.art.value, einheit: n.einheit.value.trim(), hat_charge: n.charge.checked, hat_serie: n.serie.checked,
            in_stuecken: n.stuecke.checked, verfall_pflicht: n.verfall.checked, gtin: g || null } } : {}),
          ...(a && a.id !== p.artikel ? { artikel: a.id } : {}), ...(a && !a.gtin && g ? { gtin: g } : {}) };
        await k.api('POST', `${basis}/positionen/${p.id}/bestellen`, { json: daten });
        Object.assign(merk, { lieferant: daten.lieferant, bestellnummer: daten.bestellnummer || '', bestellt_am: daten.bestellt_am === heute ? '' : daten.bestellt_am, liefertermin: daten.liefertermin || '' });
        posId = null;
        await laden(anfId);
        k.melden(`„${neu() ? daten.neuer_artikel.bezeichnung : (a ? a.bezeichnung : p.bezeichnung)}“ ist bestellt${neu() ? ' – der Artikel ist im Lager angelegt' : ''}`);
      } });
    for (const el of [artikel, gtin, menge, rest, lieferant, preis, am.ein, termin.ein, ...Object.values(n)].filter(Boolean)) { el.addEventListener('input', pruefen); el.addEventListener('change', pruefen); }
    pruefen();
    (lagerPos && artikel.value === '' ? artikel : menge).focus();
  }

  /** Den Eingang von Hand melden (BA16): ganz oder in Teilmengen; „es kommt nichts mehr“ schließt mit weniger ab. */
  function erhaltenDialog(p) {
    const rest = Math.max(0, Number(p.bestellt_menge) - Number(p.erhalten_menge));
    const menge = h('input', { id: 'bs-e-menge', inputmode: 'decimal', autocomplete: 'off', value: mengeText(rest) });
    const am = datumFeld({ id: 'bs-e-am', value: d.heute });
    const fertig = h('input', { type: 'checkbox', id: 'bs-e-fertig' });
    const hinweis = h('span', { id: 'bs-e-hinweis' }, ' ');
    function pruefen() {
      const leer = menge.value.trim() === '';
      const m = leer ? null : mengeLesen(menge.value);
      const grund = (leer && !fertig.checked ? 'Menge fehlt' : null) || (!leer && m === null ? 'Menge größer als null erwartet' : null)
        || (!am.wert() ? 'Datum fehlt oder ist ungültig' : (am.wert() > d.heute ? 'Das Datum des Eingangs liegt nicht in der Zukunft' : null));
      const summe = Number(p.erhalten_menge) + (m === null ? 0 : Number(m));
      hinweis.textContent = m !== null && summe > Number(p.bestellt_menge) ? `mehr als bestellt (${mengeText(p.bestellt_menge)})` : (summe < Number(p.bestellt_menge) ? (fertig.checked ? `abgeschlossen mit ${mengeText(summe)} von ${mengeText(p.bestellt_menge)}` : `danach noch offen: ${mengeText(Number(p.bestellt_menge) - summe)}`) : 'damit ganz erhalten');
      sperren(t.ja, grund);
    }
    const t = dialog({ id: 'bs-dialog-erhalten', titel: `Eingang melden: ${p.bezeichnung}`, ja: 'Erhalten', pruefen,
      text: `Bestellt: ${mengeText(p.bestellt_menge)} ${p.einheit} bei ${p.lieferant}${Number(p.erhalten_menge) > 0 ? `; schon erhalten: ${mengeText(p.erhalten_menge)}` : ''}.`
        + (p.art === 'lager' ? ' Das bucht nichts ins Lager – den Zugang buchst du im Wareneingang oder im Bestand.' : ''),
      felder: [h('div', { class: 'bs-raster' }, rfeld('Menge erhalten', menge, hinweis, 3), rfeld('Am', am.el, '', 3),
        rwahl(6, h('label', { class: 'feld-wahl', title: 'Die Position ist erledigt, auch wenn weniger ankam als bestellt' }, fertig, 'Es kommt nichts mehr – Position abschließen')))],
      senden: async () => {
        const r = await k.api('POST', `${basis}/positionen/${p.id}/erhalten`, { json: { ...(menge.value.trim() ? { menge: mengeLesen(menge.value) } : {}), am: am.wert(), fertig: fertig.checked } });
        await laden(anfId);
        k.melden(r.zustand === 'erhalten' ? `„${p.bezeichnung}“ ist erhalten` : `Teilmenge von „${p.bezeichnung}“ gemeldet`);
      } });
    for (const el of [menge, am.ein, fertig]) { el.addEventListener('input', pruefen); el.addEventListener('change', pruefen); }
    pruefen(); menge.focus(); menge.select();
  }

  await laden(null);
  if (ansicht === 'beimir' && !d.anforderungen.some((x) => x.bei_mir) && d.anforderungen.length) { ansicht = 'alle'; zeichnen(); }
}
