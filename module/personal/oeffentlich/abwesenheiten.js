// SPDX-License-Identifier: AGPL-3.0-or-later
// „Abwesenheiten“ (PERSONAL.md Stufe 2, F138): oben die Liste der Abwesenheiten eines Jahres, die der Angemeldete sehen
// darf; darunter, mit verschiebbarem Teiler (F137), der Kalender „wer fehlt wann“ und die Urlaubskonten. Spalten mit
// Sortierung und Filter (F91). Knöpfe stehen fest und nennen gesperrt den Grund (F106). Was eine Rolle nicht sehen darf,
// kommt vom Server gar nicht erst: Der Vorgesetzte sieht bei einer Arbeitsunfähigkeit nur „abwesend“.
import { h, seitenkopf, knopf, feld, auswahl, sperren, datumFeld, bestaetigen, reiterleiste } from '/kern/ui/bausteine.js';
import { tabellenfilter } from '/kern/ui/tabellenfilter.js';
import { teiler } from '/kern/ui/teiler.js';
import { stil, dialog, tag, tageText, abwZustand, abwZustandText, auText, abwesenheitFelder, verfallText, LAENDER, jahreskalender, schritteText, wartetText, ablaufText, ABLAUF_WAS, ABLAUF_WER, ABLAUF_WANN } from './gemeinsam.js';

const MONATE = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

export async function zeigen(b, k) {
  stil();
  b.classList.add('listenseite', 'personal');
  const api = `${k.mandantApi}/personal`;
  let d = { darf: {}, abwesenheiten: [], personen: [], konten: [], arten: [], freie_tage: [], arbeitsorte: [], feiertage: {}, regelwerk: [], urlaubsregeln: [], jahre: [], jahr: new Date().getFullYear(), heute: '' };
  let gewaehlt = null;
  let ansicht = 'kalender';
  let jahrPerson = null; // Person der Jahresansicht
  let monat = null;      // „JJJJ-MM“ des Kalenders

  const kn = {
    neu: knopf('Eintragen …', eintragen, 'primaer', { id: 'pa-neu', title: 'Trägt für eine Person eine Abwesenheit ein – sie gilt ohne Antrag' }),
    ja: knopf('Genehmigen', () => entscheiden(true), '', { id: 'pa-genehmigen', title: 'Genehmigt den gewählten Antrag' }),
    nein: knopf('Ablehnen …', () => entscheiden(false), '', { id: 'pa-ablehnen', title: 'Lehnt den gewählten Antrag ab – mit Grund, den die Person sieht' }),
    zurueck: knopf('Zurücknehmen …', zuruecknehmen, '', { id: 'pa-zurueck', title: 'Nimmt die gewählte Abwesenheit zurück; der Eintrag bleibt als „zurückgenommen“ stehen' }),
    ende: knopf('Ende ändern …', endeAendern, '', { id: 'pa-ende', title: 'Verkürzt oder verlängert die gewählte Abwesenheit' }),
    tage: knopf('Arbeitstage …', tageDialog, '', { id: 'pa-tage', title: 'Berichtigt die Arbeitstage der gewählten Abwesenheit: neu rechnen oder von Hand setzen' }),
    nachweis: knopf('Nachweis …', nachweis, '', { id: 'pa-nachweis', title: 'Vermerkt, ob der Nachweis der Arbeitsunfähigkeit vorliegt' }),
    regel: knopf('Urlaubsregel …', regelDialog, '', { id: 'pa-regel', title: 'Anteiliger Anspruch, Übertrag und Verfall – als Regel des Mandanten ab einem Jahr' }),
    orte: knopf('Arbeitsorte …', orteDialog, '', { id: 'pa-orte', title: 'Arbeitsorte mit ihrem Bundesland und den Feiertagen, die dort gelten' }),
    feiertage: knopf('Feiertage …', feiertageDialog, '', { id: 'pa-feiertage', title: 'Das Regelwerk der Feiertage anpassen oder die Vorgabe wieder einlesen' }),
    konto: knopf('Urlaubskonto …', kontoDialog, '', { id: 'pa-konto', title: 'Anspruch und Übertrag einer Person für das gewählte Jahr festsetzen' }),
    arten: knopf('Arten …', artenDialog, '', { id: 'pa-arten', title: 'Arten von Abwesenheiten pflegen' }),
    frei: knopf('Betriebsferien, freie Tage …', freieTageDialog, '', { id: 'pa-frei', title: 'Betriebsferien (Zeitraum) und Brückentage: Sie zählen nicht als Arbeitstag' }),
  };
  const jahr = h('select', { id: 'pa-jahr', class: 'pe-jahr', 'aria-label': 'Jahr', onchange: () => { monat = null; laden(Number(jahr.value)); } });
  const stand = h('div', { class: 'pe-stand', id: 'pa-stand', role: 'status' });
  const liste = h('div', { class: 'liste-rahmen pe-liste', id: 'pa-liste' });
  // Doppelklick führt zur Bearbeitung (F150): „Ende ändern“, sonst „Arbeitstage“, bei einer AU der Nachweis. Genehmigen und
  // Ablehnen bleiben den Knöpfen – eine Entscheidung fällt nicht durch einen Doppelklick. Der Lauscher sitzt an der Liste.
  // Gezählt wird am zweiten Klick (detail === 2): Ein dblclick-Ereignis kommt nicht an, weil der erste Klick die Liste neu zeichnet.
  liste.addEventListener('click', (ev) => {
    if (ev.detail !== 2) return;
    // der erste Klick hat die Zeile neu gezeichnet: Ziel des Doppelklicks ist dann die Tabelle – die Zeile unter dem Zeiger zählt
    const id = (ev.target.closest?.('tr[data-id]') || document.elementFromPoint(ev.clientX, ev.clientY)?.closest?.('tr[data-id]'))?.dataset.id;
    if (!id) return;
    if (gewaehlt !== id) { gewaehlt = id; zeichnen(); }
    if (!kn.ende.disabled) endeAendern();
    else if (!kn.tage.disabled) tageDialog();
    else if (!kn.nachweis.disabled) nachweis();
    else k.melden(kn.ende.title || 'Diese Abwesenheit lässt sich nicht bearbeiten.', 'warnung');
  });
  const reiter = h('div', {});
  const unten = h('div', { class: 'pa-ansicht', id: 'pa-ansicht' });

  const spalten = [
    { id: 'von', titel: 'Von', klasse: 'eng', wert: (x) => tag(x.von), sortwert: (x) => x.von },
    { id: 'bis', titel: 'Bis', klasse: 'eng', wert: (x) => tag(x.bis), sortwert: (x) => x.bis },
    { id: 'person', titel: 'Person', wert: (x) => x.name + (x.ich ? ' (du)' : '') },
    { id: 'art', titel: 'Art', wert: (x) => x.art_name },
    { id: 'tage', titel: 'Arbeitstage', klasse: 'zahl', wert: (x) => tageText(x.tage) + (x.tage_von_hand ? ' ✎' : ''), sortwert: (x) => x.tage ?? -1,
      zelle: (x) => (x.tage_von_hand ? h('span', { title: 'von Hand berichtigt' }, `${tageText(x.tage)} ✎`) : tageText(x.tage)) },
    { id: 'zustand', titel: 'Zustand', wert: (x) => abwZustandText(x, d.heute)[1], zelle: (x) => abwZustand(x, d.heute) },
    { id: 'angaben', titel: 'Angaben', wert: (x) => [x.bemerkung, auText(x.au), x.vertretung_name ? `Vertretung: ${x.vertretung_name}` : null, schritteText(x), x.vertretung_abwesend ? '! die Vertretung ist in dieser Zeit selbst abwesend' : null,
      x.ueberschneidet_au ? '! überschneidet sich mit einer Arbeitsunfähigkeit' : null].filter(Boolean).join(' · '),
      zelle: (x) => h('span', {}, [x.bemerkung, x.au ? h('span', { 'data-zustand': x.au.nachweis ? '' : 'abw-fehlt' }, auText(x.au)) : null,
        // Vertretung und die Schritte des Antrags (PERSONAL.md 10n)
        x.vertretung_name ? `Vertretung: ${x.vertretung_name}` : null, schritteText(x) || null,
        x.vertretung_abwesend ? h('span', { 'data-zustand': 'abw-fehlt', title: 'Die Vertretung hat im selben Zeitraum eine eigene Abwesenheit, beantragt oder genehmigt' }, '! die Vertretung ist in dieser Zeit selbst abwesend') : null,
        x.ueberschneidet_au ? h('span', { 'data-zustand': 'abw-fehlt', title: 'Wer im Urlaub krank wird, ist beides. Den Urlaub kürzt du mit „Ende ändern …“ oder nimmst ihn zurück.' }, '! überschneidet sich mit einer Arbeitsunfähigkeit') : null]
        .filter(Boolean).flatMap((el, i) => (i ? [' · ', el] : [el]))) },
  ];
  const filter = tabellenfilter(spalten, zeichnen);
  const wahl = () => d.abwesenheiten.find((x) => x.id === gewaehlt) || null;
  const nurPflege = () => (d.darf.abwesenheit_pflegen ? null : 'Das darf nur, wer Abwesenheiten pflegt (Rolle „Personal“)');
  const aktiv = (x) => x.zustand === 'beantragt' || x.zustand === 'genehmigt';

  function knoepfe() {
    const g = wahl();
    const keine = g ? null : 'Erst eine Abwesenheit in der Liste wählen';
    sperren(kn.neu, nurPflege() || (d.personen.some((p) => !p.ausgeschieden) ? null : 'Es gibt noch keine Person'));
    const entscheidung = (d.darf.genehmigen ? null : 'Das darf nur, wer Anträge genehmigt (Rollen „Vorgesetzter“ und „Personal“)') || keine
      || (g.zustand !== 'beantragt' ? 'Das ist kein offener Antrag' : (g.entscheidbar ? null : (g.wartet && g.wartet.wer !== 'vorgesetzter' ? `Der Antrag ${wartetText(g.wartet)} – diesen Schritt entscheidest nicht du` : 'Diesen Antrag entscheidest nicht du – über Eigenes und über fremde Teams entscheidet das Personal')));
    sperren(kn.ja, entscheidung);
    sperren(kn.nein, entscheidung);
    const laufend = nurPflege() || keine || (aktiv(g) ? null : 'Die Abwesenheit ist abgelehnt oder zurückgenommen');
    sperren(kn.zurueck, laufend);
    sperren(kn.ende, laufend || (g.halber_tag ? 'Ein halber Tag hat kein anderes Ende – zurücknehmen und neu eintragen' : null));
    sperren(kn.tage, laufend);
    sperren(kn.regel, nurPflege());
    sperren(kn.orte, nurPflege());
    sperren(kn.feiertage, nurPflege());
    sperren(kn.nachweis, nurPflege() || (d.darf.au_sehen ? null : 'Angaben zur Bescheinigung sind mit deiner Rolle nicht einsehbar') || keine || (g.au ? null : 'Nur bei einer Arbeitsunfähigkeit'));
    sperren(kn.konto, nurPflege() || (d.konten.length ? null : 'Es gibt noch keine Person'));
    sperren(kn.arten, nurPflege());
    sperren(kn.frei, nurPflege());
  }

  function zeichnen() {
    filter.setzeZeilen(d.abwesenheiten);
    const zeilen = filter.anwenden(d.abwesenheiten);
    const tbody = h('tbody', {}, zeilen.length ? zeilen.map((x) => {
      const tr = h('tr', { 'data-id': x.id, tabindex: '0', class: [x.id === gewaehlt ? 'gewaehlt' : '', aktiv(x) ? '' : 'pe-alt'], 'aria-selected': x.id === gewaehlt ? 'true' : 'false' },
        spalten.map((s) => h('td', { class: s.klasse || '' }, s.zelle ? s.zelle(x) : s.wert(x))));
      const waehlen = () => { gewaehlt = x.id; monat = x.von.slice(0, 7) < `${d.jahr}-01` ? `${d.jahr}-01` : x.von.slice(0, 7); zeichnen(); };
      tr.addEventListener('click', waehlen);
      tr.addEventListener('keydown', (ev) => { if (ev.target === tr && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); waehlen(); } });
      return tr;
    }) : h('tr', { class: 'leer' }, h('td', { colspan: spalten.length, id: 'pa-leer' },
      d.abwesenheiten.length ? 'Keine Abwesenheit passt zum Filter.' : `Für ${d.jahr} ist keine Abwesenheit eingetragen.`)));
    const offen = d.abwesenheiten.filter((x) => x.entscheidbar).length;
    liste.replaceChildren(h('div', { class: 'liste-scroll' }, h('table', { class: 'liste', id: 'pa-tabelle' }, h('thead', {}, filter.kopf), tbody)),
      h('div', { class: 'liste-fuss' }, `${zeilen.length === d.abwesenheiten.length ? '' : `${zeilen.length} von `}${d.abwesenheiten.length === 1 ? '1 Abwesenheit' : `${d.abwesenheiten.length} Abwesenheiten`} in ${d.jahr}`
        + ` · ${offen === 1 ? '1 Antrag wartet' : `${offen} Anträge warten`} auf deine Entscheidung` + (filter.aktiv() ? ' · gefiltert' : '')));
    stand.textContent = d.darf.abwesenheit_sehen ? 'Du siehst die Abwesenheiten aller Personen mit ihrer Art.'
      : (d.darf.team ? 'Du siehst dein Team: Anträge mit ihrer Art, alles andere nur als „abwesend“.' : '');
    knoepfe();
    untenZeichnen();
  }

  // --- unten: Kalender und Urlaubskonten ----------------------------------------------------------------------

  function untenZeichnen() {
    reiter.replaceChildren(reiterleiste([{ id: 'kalender', text: 'Kalender' }, { id: 'jahr', text: `Jahr ${d.jahr}` }, { id: 'konten', text: `Urlaubskonten ${d.jahr}` }], ansicht, (id) => { ansicht = id; untenZeichnen(); }));
    reiter.firstChild.querySelectorAll('button').forEach((el, i) => { el.id = `pa-reiter-${['kalender', 'jahr', 'konten'][i]}`; });
    unten.replaceChildren(ansicht === 'kalender' ? kalender() : (ansicht === 'jahr' ? jahresansicht() : konten()));
  }

  // Jahresansicht einer Person: zwölf Monate auf einen Blick – ihre Abwesenheiten, die Feiertage an ihrem Arbeitsort und die
  // freien Tage des Betriebs (Betriebsferien). Gerechnet aus dem, was die Seite schon geladen hat; Brückentage zeigt nur „Meine Daten“.
  function jahresansicht() {
    const personen = d.personen.filter((p) => !p.ausgeschieden || d.abwesenheiten.some((x) => x.person === p.id));
    if (!personen.length) return h('p', { class: 'hinweis', id: 'pa-jahr-leer' }, 'Niemand zu sehen.');
    if (!personen.some((p) => p.id === jahrPerson)) jahrPerson = (personen.find((p) => p.id === d.abwesenheiten.find((x) => x.id === gewaehlt)?.person) || personen.find((p) => p.id === d.ich) || personen[0]).id;
    const p = personen.find((x) => x.id === jahrPerson);
    const wahl = auswahl(personen.map((x) => [x.id, x.name]), jahrPerson, { id: 'pa-jahr-person', 'aria-label': 'Person der Jahresansicht' });
    wahl.addEventListener('change', () => { jahrPerson = wahl.value; untenZeichnen(); });
    const jahr = String(d.jahr);
    const frei = d.freie_tage.filter((f) => f.datum.startsWith(jahr)).map((f) => ({ datum: f.datum, art: 'frei', name: f.name }));
    const feiertage = (d.feiertage[p.arbeitsort || ''] || []).filter((f) => f.datum.startsWith(jahr)).map((f) => ({ datum: f.datum, art: 'feiertag', name: f.name }));
    return h('div', { id: 'pa-jahr' },
      h('div', { class: 'pa-monat nicht-drucken' }, h('label', { for: 'pa-jahr-person' }, 'Person '), wahl),
      h('div', { class: 'pa-rollbar-x' }, jahreskalender({ jahr: d.jahr, tage: [...frei, ...feiertage] }, d.abwesenheiten.filter((x) => x.person === p.id), d.heute, 'am Arbeitsort der Person')));
  }

  function kalender() {
    if (!monat || monat.slice(0, 4) !== String(d.jahr)) monat = d.heute.slice(0, 4) === String(d.jahr) ? d.heute.slice(0, 7) : `${d.jahr}-01`;
    const [j, m] = monat.split('-').map(Number);
    const tage = new Date(j, m, 0).getDate();
    const iso = (t) => `${monat}-${String(t).padStart(2, '0')}`;
    const frei = new Map(d.freie_tage.map((f) => [f.datum, f.name]));
    // Feiertage hängen am Arbeitsort der Person; der Kopf zeigt die des Arbeitsorts des Mandanten
    const feiertage = (ort) => new Map((d.feiertage[ort || ''] || []).map((f) => [f.datum, f.name]));
    const kopfFeiertage = feiertage(d.arbeitsorte.find((o) => o.standard)?.id);
    const wt = (t) => new Date(j, m - 1, t).getDay();   // 0 = Sonntag
    const springen = (um) => { const n = m + um; if (n < 1 || n > 12) return; monat = `${j}-${String(n).padStart(2, '0')}`; untenZeichnen(); };
    const vor = knopf('‹', () => springen(-1), '', { id: 'pa-monat-vor', class: ['knopf', 'klein'], title: 'Monat davor', 'aria-label': 'Monat davor' });
    const nach = knopf('›', () => springen(1), '', { id: 'pa-monat-nach', class: ['knopf', 'klein'], title: 'Monat danach', 'aria-label': 'Monat danach' });
    sperren(vor, m === 1 ? 'Für das Jahr davor oben das Jahr wechseln' : null);
    sperren(nach, m === 12 ? 'Für das Jahr danach oben das Jahr wechseln' : null);
    const klasseFuer = (ft) => (t) => [frei.has(iso(t)) || ft.has(iso(t)) || wt(t) === 0 || wt(t) === 6 ? 'pa-frei' : '', iso(t) === d.heute ? 'pa-heute' : ''];
    const kopfKlasse = klasseFuer(kopfFeiertage);
    const personen = d.personen.filter((p) => !p.ausgeschieden || d.abwesenheiten.some((x) => x.person === p.id));
    const zeile = (p) => {
      const eigene = d.abwesenheiten.filter((x) => x.person === p.id && aktiv(x));
      const ft = feiertage(p.arbeitsort);
      const zellKlasse = klasseFuer(ft);
      return h('tr', { 'data-person': p.id }, h('td', { class: 'pa-name', title: p.name }, p.name), Array.from({ length: tage }, (_, i) => {
        const t = iso(i + 1);
        // eine Arbeitsunfähigkeit geht in der Anzeige vor, wenn sich zwei überschneiden
        const x = eigene.filter((e) => e.von <= t && e.bis >= t).sort((a, c) => Number(Boolean(c.ist_au)) - Number(Boolean(a.ist_au)))[0];
        const klasse = zellKlasse(i + 1);
        if (!x) return h('td', { class: klasse, title: ft.get(t) || frei.get(t) || '' }, '');
        return h('td', { class: klasse, 'data-abw': x.zustand, title: `${x.art_name}, ${tag(x.von)}–${tag(x.bis)} · ${abwZustandText(x, d.heute)[1]}` },
          x.zustand === 'beantragt' ? '○' : (x.halber_tag ? '◐' : '●'));
      }));
    };
    return h('div', { id: 'pa-kalender' },
      h('div', { class: 'pa-monat nicht-drucken' }, vor, h('strong', { id: 'pa-monat' }, `${MONATE[m - 1]} ${j}`), nach),
      h('table', { class: 'pa-kalender' },
        h('thead', {}, h('tr', {}, h('th', { class: 'pa-name' }, 'Person'), Array.from({ length: tage }, (_, i) => h('th', { class: kopfKlasse(i + 1), title: kopfFeiertage.get(iso(i + 1)) || frei.get(iso(i + 1)) || '' }, String(i + 1))))),
        h('tbody', {}, personen.length ? personen.map(zeile) : h('tr', {}, h('td', { class: 'pa-name' }, 'Niemand zu sehen.'), h('td', { colspan: tage }, '')))),
      h('p', { class: 'pa-legende' }, '● abwesend (genehmigt, gemeldet oder eingetragen) · ◐ halber Tag · ○ beantragt · grau: Wochenende, Feiertag am Arbeitsort der Person oder freier Tag des Betriebs'));
  }

  function konten() {
    const name = new Map(d.personen.map((p) => [p.id, p.name]));
    const kopf = [['Person', ''], ['Anspruch', 'zahl'], ['Übertrag', 'zahl'], ['verfallen', 'zahl'], ['genommen', 'zahl'], ['genehmigt', 'zahl'], ['Rest', 'zahl'], ['beantragt', 'zahl'], ['Rest nach Anträgen', 'zahl'], ['Hinweis', '']];
    const regel = [...d.urlaubsregeln].reverse().find((r) => r.ab_jahr <= d.jahr) || null;
    return h('div', { id: 'pa-konten' },
      h('table', { class: 'pe-tabelle pa-konten' }, h('thead', {}, h('tr', {}, kopf.map(([t, c]) => h('th', { class: c }, t)))),
        h('tbody', {}, d.konten.length ? d.konten.map((z) => h('tr', { 'data-person': z.person },
          h('td', {}, name.get(z.person) || ''),
          h('td', { class: 'zahl', title: z.festgesetzt ? 'für dieses Jahr festgesetzt' : (z.monate !== null ? `${z.monate} Zwölftel von ${tageText(z.voll)} Tagen nach der Urlaubsregel` : 'aus dem Vertrag') },
            tageText(z.anspruch) + (z.festgesetzt ? ' *' : (z.monate !== null ? ` (${z.monate}/12)` : ''))),
          h('td', { class: 'zahl', title: z.uebertrag_festgesetzt ? 'festgesetzt' : 'nach der Urlaubsregel' }, tageText(z.uebertrag) + (z.uebertrag_festgesetzt ? ' *' : '')),
          h('td', { class: 'zahl', title: z.verfallen_festgesetzt ? 'vom Personal bestätigt' : '' }, tageText(z.verfallen) + (z.verfallen_festgesetzt ? ' *' : '')),
          ...[z.genommen, z.genehmigt].map((w) => h('td', { class: 'zahl' }, tageText(w))),
          h('td', { class: 'zahl' }, z.rest < 0 ? h('span', { 'data-zustand': 'abw-fehlt' }, `! ${tageText(z.rest)}`) : tageText(z.rest)),
          h('td', { class: 'zahl' }, tageText(z.beantragt)),
          h('td', { class: 'zahl' }, z.rest_nach_antraegen < 0 ? h('span', { 'data-zustand': 'abw-fehlt' }, `! ${tageText(z.rest_nach_antraegen)}`) : tageText(z.rest_nach_antraegen)),
          h('td', {}, verfallText(z) ? h('span', { 'data-zustand': z.verfall_offen > 0 ? 'abw-fehlt' : 'abw-beantragt', class: 'pa-hinweis' }, verfallText(z)) : '')))
          : h('tr', {}, h('td', { colspan: kopf.length }, 'Niemand zu sehen.')))),
      h('p', { class: 'pa-legende', id: 'pa-regeltext' }, 'Tage in Arbeitstagen. Anspruch aus der Fassung des Vertrags, die am 1. Januar gilt; * = für die Person festgesetzt („Urlaubskonto …“). '
        + (regel ? `Urlaubsregel ab ${regel.ab_jahr}: ${regelText(regel)}.` : `Für ${d.jahr} gilt keine Urlaubsregel: voller Anspruch, kein Übertrag, kein Verfall.`)));
  }

  const ANTEILIG = { aus: 'kein anteiliger Anspruch', gesetz: 'Teilurlaub nach § 5 BUrlG', zwoelftel: 'immer ein Zwölftel je vollem Monat' };
  const RUNDUNG = { gesetz: 'ab einem halben Tag auf volle Tage auf (§ 5 Abs. 2)', halbe: 'auf halbe Tage', ganze: 'immer auf ganze Tage auf', kaufmaennisch: 'kaufmännisch auf ganze Tage', keine: 'nicht runden' };
  const VERFALL = { nie: 'kein Verfall', bestaetigt: 'nach dem Stichtag bestätigt das Personal', selbst: 'nach dem Stichtag von selbst' };
  const regelText = (r) => [ANTEILIG[r.anteilig], r.anteilig === 'aus' ? null : `Rundung ${RUNDUNG[r.rundung]}`,
    r.uebertrag === 'rest' ? `der Rest geht ins Folgejahr${r.uebertrag_hoechstens !== null ? `, höchstens ${tageText(r.uebertrag_hoechstens)} Tage` : ''}` : 'kein Übertrag von selbst',
    r.verfall === 'nie' ? 'kein Verfall' : `Übertrag verfällt am ${String(r.verfall_tag).padStart(2, '0')}.${String(r.verfall_monat).padStart(2, '0')}. (${VERFALL[r.verfall]})`].filter(Boolean).join(' · ');

  // --- Laden und Handlungen -----------------------------------------------------------------------------------

  async function laden(j = d.jahr) {
    try { d = await k.api('GET', `${api}/abwesenheiten?jahr=${j}`); } catch (x) { k.fehler(x); }
    jahr.replaceChildren(...(d.jahre.length ? d.jahre : [d.jahr]).map((n) => h('option', { value: String(n) }, String(n))));
    jahr.value = String(d.jahr);
    if (!d.abwesenheiten.some((x) => x.id === gewaehlt)) gewaehlt = null;
    zeichnen();
    k.menueNeuLaden?.();   // der Zähler am Menüpunkt folgt den offenen Anträgen
  }

  function eintragen() {
    const g = wahl();
    const f = abwesenheitFelder({ id: 'pa-f', arten: d.arten.filter((a) => !a.stillgelegt), personen: d.personen.filter((p) => !p.ausgeschieden), person: g?.person,
      vertretungen: d.personen.filter((p) => !p.ausgeschieden), datumFeld, auswahl, feld, darfAu: d.darf.au_sehen });
    dialog({ id: 'pa-dialog-neu', titel: 'Abwesenheit eintragen', ja: 'Eintragen', breit: true,
      text: 'Was du hier einträgst, gilt ohne Antrag und ohne Mitzeichnung – eine Vertretung kannst du dazu nennen. Die Arbeitstage rechnet kumero aus dem Vertrag der Person und den freien Tagen.',
      felder: f.felder,
      senden: async () => {
        gewaehlt = (await k.api('POST', `${api}/abwesenheiten`, { json: f.json() })).id;
        k.melden('Abwesenheit eingetragen.', 'erfolg');
        await laden();
      } });
  }

  async function entscheiden(ja) {
    const g = wahl();
    if (!g) return;
    if (ja) {
      // der Schritt, der gerade wartet (PERSONAL.md 10n): Das Personal kann auch eine Mitzeichnung übernehmen
      const mit = g.wartet?.was === 'mitzeichnen';
      if (!await bestaetigen(mit ? 'Mitzeichnung übernehmen?' : 'Antrag genehmigen?', `${g.art_name} von ${g.name}, ${tag(g.von)} bis ${tag(g.bis)}${g.tage !== null ? ` (${tageText(g.tage)} Arbeitstage)` : ''}.${
        mit ? ` Der Antrag ${wartetText(g.wartet)} – du zeichnest an ihrer Stelle.` : ''}`, mit ? 'Mitzeichnen' : 'Genehmigen')) return;
      let gut = false;
      try { await k.api('POST', `${api}/abwesenheiten/${g.id}/entscheidung`, { json: { genehmigen: true } }); gut = true; } catch (x) { k.fehler(x); }
      await laden();
      const danach = d.abwesenheiten.find((x) => x.id === g.id);
      if (gut) k.melden(danach?.zustand === 'beantragt' && danach.wartet ? `Erledigt – der Antrag ${wartetText(danach.wartet)}.` : 'Antrag genehmigt.', 'erfolg');
      return;
    }
    const grund = h('input', { id: 'pa-g-grund', type: 'text', required: true, maxlength: '200', size: '48', autocomplete: 'off' });
    dialog({ id: 'pa-dialog-ablehnen', titel: 'Antrag ablehnen', ja: 'Ablehnen', text: `${g.art_name} von ${g.name}, ${tag(g.von)} bis ${tag(g.bis)}. Die Person sieht den Grund unter „Meine Daten“.`,
      felder: [feld('Grund', grund)],
      senden: async () => {
        await k.api('POST', `${api}/abwesenheiten/${g.id}/entscheidung`, { json: { genehmigen: false, grund: grund.value.trim() } });
        k.melden('Antrag abgelehnt.', 'erfolg');
        await laden();
      } });
  }

  function zuruecknehmen() {
    const g = wahl();
    if (!g) return;
    const grund = h('input', { id: 'pa-z-grund', type: 'text', required: true, maxlength: '200', size: '48', autocomplete: 'off' });
    dialog({ id: 'pa-dialog-zurueck', titel: 'Abwesenheit zurücknehmen', ja: 'Zurücknehmen',
      text: `${g.art_name} von ${g.name}, ${tag(g.von)} bis ${tag(g.bis)}. Der Eintrag bleibt als „zurückgenommen“ stehen und zählt nicht mehr. Keine Gesundheitsangaben im Grund.`,
      felder: [feld('Grund', grund)],
      senden: async () => {
        await k.api('POST', `${api}/abwesenheiten/${g.id}/ruecknahme`, { json: { grund: grund.value.trim() } });
        k.melden('Abwesenheit zurückgenommen.', 'erfolg');
        await laden();
      } });
  }

  function endeAendern() {
    const g = wahl();
    if (!g) return;
    const bis = datumFeld({ id: 'pa-e-bis', value: g.bis });
    dialog({ id: 'pa-dialog-ende', titel: 'Ende ändern', ja: 'Speichern', text: `${g.art_name} von ${g.name}, seit ${tag(g.von)}. Die Arbeitstage werden neu gerechnet.`,
      felder: [feld('Bis', bis.el)],
      senden: async () => {
        const w = bis.wert();
        if (!w) throw new Error(w === null ? 'Das Datum ist nicht lesbar' : 'Bis fehlt');
        await k.api('PUT', `${api}/abwesenheiten/${g.id}/ende`, { json: { bis: w } });
        k.melden('Ende geändert.', 'erfolg');
        await laden();
      } });
  }

  async function nachweis() {
    const g = wahl();
    if (!g?.au) return;
    const vor = !g.au.nachweis;
    const wer = `Arbeitsunfähigkeit von ${g.name}, ${tag(g.von)} bis ${tag(g.bis)}.`;
    if (!vor) {
      if (!await bestaetigen('Nachweis liegt doch nicht vor?', `${wer} Der Vermerk wird zurückgenommen.`, 'Liegt nicht vor')) return;
      try { await k.api('PUT', `${api}/abwesenheiten/${g.id}/nachweis`, { json: { liegt_vor: false } }); k.melden('Vermerk zurückgenommen.', 'erfolg'); } catch (x) { k.fehler(x); }
      await laden();
      return;
    }
    // Form des Nachweises (F155): elektronisch (eAU, vom Arbeitgeber bei der Krankenkasse abgerufen) oder Papier
    const form = auswahl([['elektronisch', 'elektronisch (eAU)'], ['papier', 'Papier']], 'elektronisch', { id: 'pa-n-form' });
    const hinweis = h('p', { class: 'hinweis', id: 'pa-n-hinweis' }, '');
    const zeigen = () => { hinweis.textContent = form.value === 'papier'
      ? 'Papier: Die Bescheinigung bleibt vorerst in deiner Ablage. Das Hochladen nach Paperless ist entworfen und noch nicht gebaut – kumero selbst hält kein Dokument.'
      : 'Elektronisch: kumero ruft nichts bei Krankenkassen ab – vermerkt wird nur, dass der Nachweis vorliegt.'; };
    form.addEventListener('change', zeigen);
    zeigen();
    dialog({ id: 'pa-dialog-nachweis', titel: 'Nachweis liegt vor?', ja: 'Liegt vor', text: wer, felder: [feld('Form des Nachweises', form), hinweis],
      senden: async () => { await k.api('PUT', `${api}/abwesenheiten/${g.id}/nachweis`, { json: { liegt_vor: true, form: form.value } }); k.melden('Nachweis vermerkt.', 'erfolg'); await laden(); } });
  }

  function kontoDialog() {
    const g = wahl();
    const person = auswahl(d.konten.map((z) => [z.person, d.personen.find((p) => p.id === z.person)?.name || '']), g && d.konten.some((z) => z.person === g.person) ? g.person : null, { id: 'pa-k-person' });
    const feldTage = (id, platz) => h('input', { id, type: 'text', class: 'pe-kurz', inputmode: 'decimal', autocomplete: 'off', placeholder: platz });
    const anspruch = feldTage('pa-k-anspruch', 'Vertrag / Regel');
    const uebertrag = feldTage('pa-k-uebertrag', 'nach der Regel');
    const verfallen = feldTage('pa-k-verfallen', 'nach der Regel');
    const stand = h('p', { class: 'pe-klein', id: 'pa-k-stand', role: 'status' }, ' ');
    const fuellen = () => {
      const z = d.konten.find((x) => x.person === person.value);
      anspruch.value = z?.festgesetzt ? tageText(z.anspruch) : '';
      uebertrag.value = z?.uebertrag_festgesetzt ? tageText(z.uebertrag) : '';
      verfallen.value = z?.verfallen_festgesetzt ? tageText(z.verfallen) : '';
      stand.textContent = z ? `Gerechnet: Anspruch ${tageText(z.anspruch)} · Übertrag ${tageText(z.uebertrag)} · verfallen ${tageText(z.verfallen)} · Rest ${tageText(z.rest)}`
        + (verfallText(z) ? ` — ${verfallText(z)}` : '') : ' ';
    };
    person.addEventListener('change', fuellen);
    fuellen();
    const zahl = (el) => { const t = el.value.trim().replace(',', '.'); if (t === '') return null; if (!/^-?\d{1,2}(\.[05])?$/.test(t)) throw new Error('Tage als Zahl, halbe Tage mit ,5'); return t.replace(/\.0$/, ''); };
    dialog({ id: 'pa-dialog-konto', titel: `Urlaubskonto ${d.jahr}`, ja: 'Speichern', breit: true,
      text: 'Hier setzt du für eine Person fest, was von Vertrag und Urlaubsregel abweicht. Ein leeres Feld heißt: nach Vertrag bzw. Regel. '
        + '„Verfallen“ ist zugleich die Bestätigung des Verfalls nach dem Stichtag; 0 heißt: Es verfällt nichts.',
      felder: [feld('Person', person), stand,
        h('div', { class: 'pe-reihe' }, feld('Anspruch in diesem Jahr', anspruch), feld('Übertrag aus dem Vorjahr', uebertrag), feld('Davon verfallen', verfallen))],
      senden: async () => {
        await k.api('PUT', `${api}/urlaubskonten/${person.value}/${d.jahr}`, { json: { anspruch: zahl(anspruch), uebertrag: zahl(uebertrag), verfallen: zahl(verfallen) } });
        ansicht = 'konten';
        k.melden('Urlaubskonto gespeichert.', 'erfolg');
        await laden();
      } });
  }

  function tageDialog() {
    const g = wahl();
    if (!g) return;
    const ueber = g.von.slice(0, 4) !== g.bis.slice(0, 4);
    const neu = h('input', { type: 'checkbox', id: 'pa-g-neu', checked: '' });
    const tage = h('input', { id: 'pa-g-tage', type: 'text', class: 'pe-kurz', inputmode: 'decimal', autocomplete: 'off', value: tageText(g.tage) });
    const folge = h('input', { id: 'pa-g-folge', type: 'text', class: 'pe-kurz', inputmode: 'decimal', autocomplete: 'off', value: tageText(g.tage_folgejahr) });
    const anpassen = () => {
      sperren(tage, neu.checked ? 'Wird neu gerechnet – für einen Wert von Hand den Haken entfernen' : null);
      sperren(folge, neu.checked ? 'Wird neu gerechnet' : (ueber ? null : 'Nur bei einer Abwesenheit über den Jahreswechsel'));
    };
    neu.addEventListener('change', anpassen);
    anpassen();
    const zahl = (el) => { const t = el.value.trim().replace(',', '.'); if (!/^\d{1,4}(\.[05])?$/.test(t)) throw new Error('Tage als Zahl, halbe Tage mit ,5'); return t.replace(/\.0$/, ''); };
    dialog({ id: 'pa-dialog-tage', titel: 'Arbeitstage berichtigen', ja: 'Speichern',
      text: `${g.art_name} von ${g.name}, ${tag(g.von)} bis ${tag(g.bis)}: bisher ${tageText(g.tage)} Arbeitstage${g.tage_von_hand ? ' (von Hand)' : ''}. `
        + 'Neu gerechnet wird nach dem Vertrag, den Feiertagen am Arbeitsort und den freien Tagen des Betriebs, wie sie jetzt gelten.',
      felder: [h('label', { class: 'pe-haken' }, neu, ' neu rechnen'), h('div', { class: 'pe-reihe' }, feld('Arbeitstage von Hand', tage), feld('davon im Folgejahr', folge))],
      senden: async () => {
        await k.api('PUT', `${api}/abwesenheiten/${g.id}/tage`, { json: neu.checked ? { tage: null } : { tage: zahl(tage), tage_folgejahr: ueber ? zahl(folge) : null } });
        k.melden('Arbeitstage berichtigt.', 'erfolg');
        await laden();
      } });
  }

  function regelDialog() {
    const gilt = [...d.urlaubsregeln].reverse().find((r) => r.ab_jahr <= d.jahr) || null;
    const r = gilt || { ab_jahr: d.jahr, anteilig: 'gesetz', rundung: 'gesetz', uebertrag: 'rest', uebertrag_hoechstens: null, verfall: 'bestaetigt', verfall_tag: 31, verfall_monat: 3 };
    const ab = h('input', { id: 'pa-r-ab', type: 'number', min: '2000', max: '2100', step: '1', class: 'pe-kurz', value: String(r.ab_jahr) });
    const f = { anteilig: auswahl(Object.entries(ANTEILIG), r.anteilig, { id: 'pa-r-anteilig' }), rundung: auswahl(Object.entries(RUNDUNG), r.rundung, { id: 'pa-r-rundung' }),
      uebertrag: auswahl([['rest', 'der Rest geht ins Folgejahr'], ['nie', 'nie von selbst']], r.uebertrag, { id: 'pa-r-uebertrag' }),
      hoechstens: h('input', { id: 'pa-r-hoechstens', type: 'text', class: 'pe-kurz', inputmode: 'decimal', autocomplete: 'off', placeholder: 'unbegrenzt', value: r.uebertrag_hoechstens === null ? '' : tageText(r.uebertrag_hoechstens) }),
      verfall: auswahl(Object.entries(VERFALL), r.verfall, { id: 'pa-r-verfall' }),
      tag: h('input', { id: 'pa-r-tag', type: 'number', min: '1', max: '31', class: 'pe-kurz', value: String(r.verfall_tag) }),
      monat: h('input', { id: 'pa-r-monat', type: 'number', min: '1', max: '12', class: 'pe-kurz', value: String(r.verfall_monat) }) };
    const weg = h('input', { type: 'checkbox', id: 'pa-r-weg' });
    const anpassen = () => {
      sperren(f.rundung, f.anteilig.value === 'aus' ? 'Ohne anteiligen Anspruch wird nichts gerundet' : null);
      sperren(f.hoechstens, f.uebertrag.value === 'nie' ? 'Ohne Übertrag keine Höchstzahl' : null);
      for (const el of [f.tag, f.monat]) sperren(el, f.verfall.value === 'nie' ? 'Ohne Verfall kein Stichtag' : null);
      const da = d.urlaubsregeln.some((x) => x.ab_jahr === Number(ab.value));
      weg.disabled = !da; weg.title = da ? '' : 'Für dieses Jahr gibt es keine eigene Regel'; if (!da) weg.checked = false;
    };
    for (const el of [f.anteilig, f.uebertrag, f.verfall, ab]) el.addEventListener('change', anpassen);
    anpassen();
    dialog({ id: 'pa-dialog-regel', titel: 'Urlaubsregel des Mandanten', ja: 'Speichern', breit: true,
      text: 'Eine Regel gilt ab ihrem Jahr, bis eine spätere sie ablöst; frühere Jahre behalten ihre. Was am Urlaubskonto einer Person festgesetzt ist, geht der Regel vor. '
        + 'Teilurlaub nach § 5 BUrlG: ein Zwölftel je vollem Monat bei Eintritt ab dem 1. Juli, bei Ausscheiden vor sechs Monaten und bei Ausscheiden bis zum 30. Juni – sonst der volle Anspruch. '
        + 'Ob Urlaub rechtlich verfällt, hängt auch vom rechtzeitigen Hinweis des Arbeitgebers ab: kumero zeigt den drohenden Verfall der Person unter „Meine Daten“ und dem Personal hier. Keine Rechtsberatung.',
      felder: [h('p', { class: 'pe-klein', id: 'pa-r-liste' }, d.urlaubsregeln.length ? d.urlaubsregeln.map((x) => `ab ${x.ab_jahr}: ${regelText(x)}`).join(' — ') : 'Noch keine Regel.'),
        h('div', { class: 'pe-reihe' }, feld('Gilt ab Jahr', ab), feld('Anteiliger Anspruch im Jahr des Ein- oder Austritts', f.anteilig), feld('Rundung', f.rundung)),
        h('div', { class: 'pe-reihe' }, feld('Übertrag', f.uebertrag), feld('höchstens (Tage)', f.hoechstens), feld('Verfall des Übertrags', f.verfall), feld('Stichtag: Tag', f.tag), feld('Monat', f.monat)),
        h('label', { class: 'pe-haken' }, weg, ' die Regel dieses Jahres entfernen')],
      senden: async () => {
        const jahr = Number(ab.value);
        if (!Number.isInteger(jahr) || jahr < 2000 || jahr > 2100) throw new Error('Gilt ab Jahr: 2000 bis 2100');
        if (weg.checked) await k.api('PUT', `${api}/urlaubsregeln/${jahr}`, { json: { entfernen: true } });
        else {
          const t = f.hoechstens.value.trim().replace(',', '.');
          if (t !== '' && !/^\d{1,2}(\.[05])?$/.test(t)) throw new Error('Höchstens: Tage als Zahl, halbe Tage mit ,5');
          await k.api('PUT', `${api}/urlaubsregeln/${jahr}`, { json: { anteilig: f.anteilig.value, rundung: f.rundung.value, uebertrag: f.uebertrag.value,
            uebertrag_hoechstens: f.uebertrag.value === 'nie' || t === '' ? null : t, verfall: f.verfall.value, verfall_tag: Number(f.tag.value), verfall_monat: Number(f.monat.value) } });
        }
        ansicht = 'konten';
        k.melden(weg.checked ? 'Regel entfernt.' : 'Urlaubsregel gespeichert.', 'erfolg');
        await laden();
      } });
  }

  function orteDialog() {
    const ort = auswahl([['', '– neuer Arbeitsort –'], ...d.arbeitsorte.map((o) => [o.id, o.name + (o.standard ? ' (Arbeitsort des Mandanten)' : '') + (o.stillgelegt ? ' (stillgelegt)' : '')])], '', { id: 'pa-o-ort' });
    const name = h('input', { id: 'pa-o-name', type: 'text', maxlength: '60', autocomplete: 'off', required: true });
    const land = auswahl(LAENDER, 'BY', { id: 'pa-o-land' });
    const standard = h('input', { type: 'checkbox', id: 'pa-o-standard' });
    const still = h('input', { type: 'checkbox', id: 'pa-o-still' });
    const haken = d.regelwerk.map((f) => ({ f, el: h('input', { type: 'checkbox', id: `pa-o-f-${f.kennung}` }) }));
    const vorgabe = (f) => f.bundesweit || f.laender.includes(land.value);
    const nachLand = () => haken.forEach(({ f, el }) => { el.checked = vorgabe(f); });
    const fuellen = () => {
      const o = d.arbeitsorte.find((x) => x.id === ort.value) || null;
      name.value = o ? o.name : ''; if (o) land.value = o.land;
      standard.checked = o ? o.standard : d.arbeitsorte.length === 0;
      still.checked = Boolean(o?.stillgelegt);
      sperren(still, o ? null : 'Erst anlegen');
      haken.forEach(({ f, el }) => { el.checked = o ? (vorgabe(f) || o.zusatz.includes(f.kennung)) && !o.ohne.includes(f.kennung) : vorgabe(f); });
    };
    ort.addEventListener('change', fuellen);
    land.addEventListener('change', nachLand);
    fuellen();
    const wo = (f) => (f.bundesweit ? 'bundesweit' : [f.laender.join(', '), f.teile.length ? `in Teilen von ${f.teile.join(', ')}` : ''].filter(Boolean).join(' · ') || 'eigener Feiertag');
    dialog({ id: 'pa-dialog-orte', titel: 'Arbeitsorte', ja: 'Speichern', breit: true,
      text: 'Ein Arbeitsort ist ein Bundesland mit den Feiertagen, die dort gelten – frei zu- und abwählbar (z. B. Augsburg: Bayern mit Friedensfest). '
        + 'Jede Person hat ihren Arbeitsort („Personen“ → Bearbeiten); ohne Angabe gilt der Arbeitsort des Mandanten, ohne jeden Arbeitsort gelten die bundeseinheitlichen Feiertage.',
      felder: [h('div', { class: 'pe-reihe' }, feld('Arbeitsort', ort), feld('Name', name), feld('Bundesland', land)),
        h('div', { class: 'pe-reihe' }, h('label', { class: 'pe-haken' }, standard, ' Arbeitsort des Mandanten'), h('label', { class: 'pe-haken' }, still, ' stillgelegt')),
        h('fieldset', { class: 'pe-gruppe' }, h('legend', {}, 'Feiertage, die an diesem Arbeitsort gelten (mit dem Land vorbelegt)'),
          h('div', { class: 'pa-feiertage', id: 'pa-o-feiertage' }, haken.map(({ f, el }) => h('label', { class: 'pe-haken', title: wo(f) }, el, ` ${f.name}`))))],
      senden: async () => {
        const json = { name: name.value.trim(), land: land.value, standard: standard.checked, feiertage: haken.filter(({ el }) => el.checked).map(({ f }) => f.kennung) };
        if (ort.value) await k.api('PUT', `${api}/arbeitsorte/${ort.value}`, { json: { ...json, stillgelegt: still.checked } });
        else await k.api('POST', `${api}/arbeitsorte`, { json });
        k.melden('Arbeitsort gespeichert.', 'erfolg');
        await laden();
      } });
  }

  function feiertageDialog() {
    const ARTEN = [['fest', 'festes Datum'], ['ostern', 'beweglich (ab Ostersonntag)'], ['busstag', 'Mittwoch vor dem 23.11.']];
    const zahlfeld = (wert, attr) => h('input', { type: 'number', class: 'pa-schmal', value: wert === null || wert === undefined ? '' : String(wert), ...attr });
    const zeile = (f) => {
      const z = { f, name: h('input', { type: 'text', maxlength: '60', value: f?.name || '', 'aria-label': 'Name', placeholder: f ? '' : 'neuer Feiertag', id: f ? `pa-ft-name-${f.kennung}` : 'pa-ft-neu-name' }),
        art: auswahl(ARTEN, f?.art || 'fest', { 'aria-label': 'Art', id: f ? null : 'pa-ft-neu-art' }),
        tag: zahlfeld(f?.tag, { min: '1', max: '31', 'aria-label': 'Tag', id: f ? null : 'pa-ft-neu-tag' }), monat: zahlfeld(f?.monat, { min: '1', max: '12', 'aria-label': 'Monat', id: f ? null : 'pa-ft-neu-monat' }),
        abstand: zahlfeld(f?.abstand, { min: '-100', max: '100', 'aria-label': 'Tage ab Ostersonntag' }),
        bundesweit: h('input', { type: 'checkbox', checked: f?.bundesweit ? '' : null, 'aria-label': 'bundesweit', id: f ? null : 'pa-ft-neu-bundesweit' }),
        laender: h('input', { type: 'text', class: 'pa-laender', value: (f?.laender || []).join(', '), 'aria-label': 'Länder', placeholder: 'BW, BY', id: f ? `pa-ft-laender-${f.kennung}` : null }),
        teile: h('input', { type: 'text', class: 'pa-laender', value: (f?.teile || []).join(', '), 'aria-label': 'in Teilen von', placeholder: 'SN, TH' }),
        weg: h('input', { type: 'checkbox', 'aria-label': 'entfernen', disabled: f ? null : '', title: f ? '' : 'Noch nicht angelegt', id: f ? `pa-ft-weg-${f.kennung}` : null }) };
      const anpassen = () => {
        for (const el of [z.tag, z.monat]) sperren(el, z.art.value === 'fest' ? null : 'Nur bei einem festen Datum');
        sperren(z.abstand, z.art.value === 'ostern' ? null : 'Nur bei einem beweglichen Feiertag');
        sperren(z.laender, z.bundesweit.checked ? 'Gilt bundesweit' : null);
      };
      z.art.addEventListener('change', anpassen); z.bundesweit.addEventListener('change', anpassen);
      anpassen();
      return z;
    };
    const zeilen = d.regelwerk.map(zeile);
    const neu = zeile(null);
    const liste = (el) => el.value.split(/[\s,;]+/).map((x) => x.trim().toUpperCase()).filter(Boolean);
    const json = (z) => ({ name: z.name.value.trim(), art: z.art.value, ...(z.art.value === 'fest' ? { tag: Number(z.tag.value), monat: Number(z.monat.value) } : {}),
      ...(z.art.value === 'ostern' ? { abstand: Number(z.abstand.value) } : {}), bundesweit: z.bundesweit.checked, laender: z.bundesweit.checked ? [] : liste(z.laender), teile: liste(z.teile) });
    const gleich = (z) => { const f = z.f; const j = json(z); return j.name === f.name && j.art === f.art && (j.tag ?? null) === f.tag && (j.monat ?? null) === f.monat && (j.abstand ?? null) === f.abstand
      && j.bundesweit === f.bundesweit && j.laender.join() === f.laender.join() && j.teile.join() === f.teile.join(); };
    const dlg = dialog({ id: 'pa-dialog-feiertage', titel: 'Feiertage – Regelwerk des Mandanten', ja: 'Speichern', breit: true,
      text: 'Die Vorgabe kommt aus der Steuerdatei des Moduls (regelwerk/feiertage-de.json) und ist hier anzupassen: ändern, eigene Feiertage anlegen, entfernen. '
        + 'Länder als Kürzel (BW, BY, BE, BB, HB, HH, HE, MV, NI, NW, RP, SL, SN, ST, SH, TH). „In Teilen von“ gilt nicht von selbst – solche Feiertage wählt man am Arbeitsort zu. '
        + 'Schon eingetragene Abwesenheiten behalten ihre Tage; berichtigt werden sie mit „Arbeitstage …“.',
      felder: [h('div', { class: 'pa-rollbar' }, h('table', { class: 'pe-tabelle', id: 'pa-ft-tabelle' },
        h('thead', {}, h('tr', {}, ['Feiertag', 'Art', 'Tag', 'Monat', 'Tage ab Ostern', 'bundesweit', 'Länder', 'in Teilen von', 'entfernen'].map((t) => h('th', {}, t)))),
        h('tbody', {}, [...zeilen, neu].map((z) => h('tr', { 'data-kennung': z.f?.kennung || '' }, [z.name, z.art, z.tag, z.monat, z.abstand, z.bundesweit, z.laender, z.teile, z.weg].map((el) => h('td', {}, el))))))),
        h('div', { class: 'pe-reihe' }, knopf('Vorgabe wieder einlesen', async () => {
          try {
            const r = await k.api('POST', `${api}/feiertage/vorgabe`, { json: {} });
            dlg.dlg.close();
            await laden();
            k.melden(`Vorgabe eingelesen: ${r.anzahl} Feiertage, Stand ${tag(r.stand)}. Eigene Feiertage bleiben.`, 'erfolg');
          } catch (x) { dlg.fehler.textContent = x.message; }
        }, '', { id: 'pa-ft-vorgabe', title: 'Setzt die Feiertage der Steuerdatei auf ihre Vorgabe zurück; eigene Feiertage bleiben' }))],
      senden: async () => {
        for (const z of zeilen) {
          if (z.weg.checked) await k.api('PUT', `${api}/feiertage/${z.f.kennung}`, { json: { entfernen: true } });
          else if (!gleich(z)) await k.api('PUT', `${api}/feiertage/${z.f.kennung}`, { json: json(z) });
        }
        if (neu.name.value.trim()) await k.api('POST', `${api}/feiertage`, { json: json(neu) });
        k.melden('Regelwerk gespeichert.', 'erfolg');
        await laden();
      } });
  }

  function artenDialog() {
    const haken = (an, attr = {}) => h('input', { type: 'checkbox', checked: an ? '' : null, ...attr });
    const fest = (a, feldname) => (a.kennung === 'au' && (feldname === 'zaehlt_urlaub' || feldname === 'genehmigung')) || (a.kennung === 'urlaub' && feldname === 'zaehlt_urlaub') || (a.kennung && feldname === 'stillgelegt');
    const FELDER = [['bezahlt', 'bezahlt'], ['zaehlt_urlaub', 'zählt gegen Urlaub'], ['genehmigung', 'braucht Genehmigung'], ['selbst', 'selbst beantragen'], ['stillgelegt', 'stillgelegt']];
    const zeilen = d.arten.map((a) => ({ a, name: h('input', { type: 'text', value: a.name, maxlength: '60', 'aria-label': 'Name der Art' }),
      ...Object.fromEntries(FELDER.map(([f, t]) => [f, haken(a[f], { 'aria-label': `${a.name}: ${t}`, disabled: fest(a, f) ? '' : null, title: fest(a, f) ? 'Bei Urlaub und Arbeitsunfähigkeit steht das fest' : '' })])) }));
    const neu = { name: h('input', { id: 'pa-a-neu', type: 'text', maxlength: '60', placeholder: 'neue Art', autocomplete: 'off' }),
      ...Object.fromEntries(FELDER.slice(0, 4).map(([f, t]) => [f, haken(f === 'genehmigung', { id: `pa-a-neu-${f}`, 'aria-label': `neue Art: ${t}` })])) };
    // der Ablauf vor der Genehmigung je Art (PERSONAL.md 10n): als Satz in der Zeile, gepflegt in einem eigenen Dialog
    const ablaufZelle = (a) => {
      const kn = knopf('Ablauf …', () => { document.getElementById('pa-dialog-arten')?.close(); ablaufDialog(a); }, '', { class: ['knopf', 'klein', 'pa-ablauf'], 'data-art': a.id });
      sperren(kn, a.genehmigung ? (a.stillgelegt ? 'Die Art ist stillgelegt' : null) : 'Eine Art ohne Genehmigung hat keinen Ablauf');
      return h('td', { class: 'pe-klein' }, a.genehmigung ? `${ablaufText(a.ablauf || a.ablauf_vorgabe, d.personen)}${a.ablauf ? '' : ' (Vorgabe)'} ` : '– ', kn);
    };
    dialog({ id: 'pa-dialog-arten', titel: 'Arten von Abwesenheiten', ja: 'Speichern', breit: true,
      text: 'Eine Art wird stillgelegt, nicht gelöscht: Bestehende Abwesenheiten behalten sie. „Bezahlt“ ist eine Angabe für die Lohnvorbereitung – bitte prüfen. Der Ablauf sagt, wer vor der Genehmigung mitzeichnet und wer genehmigt.',
      felder: [h('table', { class: 'pe-tabelle', id: 'pa-a-tabelle' }, h('thead', {}, h('tr', {}, ['Art', ...FELDER.map(([, t]) => t), 'Ablauf'].map((t) => h('th', {}, t)))),
        h('tbody', {}, ...zeilen.map((z) => h('tr', {}, h('td', {}, z.name), ...FELDER.map(([f]) => h('td', {}, z[f])), ablaufZelle(z.a))),
          h('tr', {}, h('td', {}, neu.name), ...FELDER.slice(0, 4).map(([f]) => h('td', {}, neu[f])), h('td', {}, ''), h('td', { class: 'pe-klein' }, 'nach dem Anlegen'))))],
      senden: async () => {
        for (const z of zeilen) {
          const json = { name: z.name.value.trim(), ...Object.fromEntries(FELDER.map(([f]) => [f, z[f].checked])) };
          if (json.name !== z.a.name || FELDER.some(([f]) => json[f] !== z.a[f])) await k.api('PUT', `${api}/abwesenheitsarten/${z.a.id}`, { json });
        }
        if (neu.name.value.trim()) await k.api('POST', `${api}/abwesenheitsarten`, { json: { name: neu.name.value.trim(), ...Object.fromEntries(FELDER.slice(0, 4).map(([f]) => [f, neu[f].checked])) } });
        k.melden('Gespeichert.', 'erfolg');
        await laden();
      } });
  }

  // Der Ablauf einer Art (PERSONAL.md 10n): bis zu acht Schritte – was geschieht, wer es tut, wann es gilt. Die Zeilen stehen fest (F106);
  // eine Zeile ohne „Was“ ist kein Schritt. Mit dem Antrag werden seine Schritte festgehalten: Laufende Anträge ändert der Dialog nicht.
  function ablaufDialog(a) {
    const vorhanden = a.ablauf || a.ablauf_vorgabe;
    const leute = d.personen.filter((p) => !p.ausgeschieden);
    const zu = (el, warum) => { el.disabled = Boolean(warum); el.title = warum || ''; };
    const zeilen = Array.from({ length: 8 }, (_, i) => {
      const s = vorhanden[i] || {};
      const z = { was: auswahl([['', '– kein Schritt –'], ...ABLAUF_WAS], s.was || '', { id: `pa-ab-was-${i}`, 'aria-label': `Schritt ${i + 1}: was` }),
        wer: auswahl(ABLAUF_WER, s.wer || 'vorgesetzter', { id: `pa-ab-wer-${i}`, 'aria-label': `Schritt ${i + 1}: wer` }),
        person: auswahl([['', '– Person wählen –'], ...leute.map((p) => [p.id, p.name])], s.person || '', { id: `pa-ab-person-${i}`, 'aria-label': `Schritt ${i + 1}: benannte Person` }),
        wann: auswahl(ABLAUF_WANN, s.wann || 'immer', { id: `pa-ab-wann-${i}`, 'aria-label': `Schritt ${i + 1}: wann` }),
        tage: h('input', { id: `pa-ab-tage-${i}`, class: 'pe-zahl', type: 'text', inputmode: 'decimal', size: '4', value: s.tage ? String(s.tage).replace('.', ',') : '', autocomplete: 'off', 'aria-label': `Schritt ${i + 1}: ab Arbeitstagen` }) };
      const anpassen = () => {
        const ohne = z.was.value ? null : 'Kein Schritt in dieser Zeile';
        zu(z.wer, ohne); zu(z.wann, ohne);
        zu(z.person, ohne || (z.wer.value === 'person' ? null : 'Nur bei „eine benannte Person“'));
        zu(z.tage, ohne || (z.wann.value === 'ab_tage' ? null : 'Nur bei „ab … Arbeitstagen“'));
      };
      for (const el of [z.was, z.wer, z.wann]) el.addEventListener('change', anpassen);
      anpassen();
      return z;
    });
    const lesen = () => zeilen.filter((z) => z.was.value).map((z, i) => {
      const s = { was: z.was.value, wer: z.wer.value, wann: z.wann.value };
      if (s.wer === 'person') { if (!z.person.value) throw new Error(`Schritt ${i + 1}: Die benannte Person fehlt`); s.person = z.person.value; }
      if (s.wann === 'ab_tage') {
        const t = Number(z.tage.value.trim().replace(',', '.'));
        if (!z.tage.value.trim() || !Number.isFinite(t) || t < 0.5 || t > 366 || (t * 2) % 1 !== 0) throw new Error(`Schritt ${i + 1}: „ab … Arbeitstagen“ braucht eine Zahl, auch halbe Tage`);
        s.tage = t;
      }
      return s;
    });
    const zurueck = async () => { await laden(); artenDialog(); };
    const vorgabe = knopf('Zurück zur Vorgabe', async () => {
      try { await k.api('PUT', `${api}/abwesenheitsarten/${a.id}`, { json: { name: a.name, ablauf: null } }); document.getElementById('pa-dialog-ablauf')?.close(); k.melden('Es gilt wieder die Vorgabe.', 'erfolg'); await zurueck(); } catch (x) { k.fehler(x); }
    }, '', { id: 'pa-ab-vorgabe', title: `Die Vorgabe: ${ablaufText(a.ablauf_vorgabe)}` });
    sperren(vorgabe, a.ablauf ? null : 'Es gilt schon die Vorgabe');
    dialog({ id: 'pa-dialog-ablauf', titel: `Ablauf – ${a.name}`, ja: 'Speichern', breit: true,
      text: `Die Schritte laufen nacheinander. Wer ablehnt, nennt den Grund – der Antrag ist dann abgelehnt. „Zur Kenntnis“ hält nicht auf. Mindestens ein Schritt „genehmigen“ gilt immer. Wer einen Schritt entscheiden darf, folgt den Rollen; das Personal kann jeden Schritt entscheiden. Laufende Anträge behalten ihre Schritte.${a.ablauf ? '' : ' Zurzeit gilt die Vorgabe.'}`,
      felder: [h('table', { class: 'pe-tabelle pe-ablauf', id: 'pa-ab-tabelle' }, h('thead', {}, h('tr', {}, ['Nr.', 'Was', 'Wer', 'Benannte Person', 'Wann', 'ab Tagen'].map((t) => h('th', {}, t)))),
        h('tbody', {}, zeilen.map((z, i) => h('tr', {}, h('td', {}, String(i + 1)), h('td', {}, z.was), h('td', {}, z.wer), h('td', {}, z.person), h('td', {}, z.wann), h('td', {}, z.tage))))), vorgabe],
      senden: async () => {
        await k.api('PUT', `${api}/abwesenheitsarten/${a.id}`, { json: { name: a.name, ablauf: lesen() } });
        k.melden('Ablauf gespeichert.', 'erfolg');
        await zurueck();
      } });
  }

  function freieTageDialog() {
    const zeilen = d.freie_tage.filter((f) => f.datum.slice(0, 4) >= String(d.jahr)).map((f) => ({ f, weg: h('input', { type: 'checkbox', 'aria-label': `${tag(f.datum)} entfernen` }) }));
    const datum = datumFeld({ id: 'pa-t-datum' });
    const bis = datumFeld({ id: 'pa-t-bis' });   // leer: ein einzelner Tag; gefüllt: jeder Tag des Zeitraums (Betriebsferien)
    const name = h('input', { id: 'pa-t-name', type: 'text', maxlength: '60', placeholder: 'z. B. Betriebsferien, Brückentag', autocomplete: 'off' });
    dialog({ id: 'pa-dialog-frei', titel: 'Betriebsferien und freie Tage des Betriebs', ja: 'Speichern', breit: true,
      text: 'Betriebsferien, Brückentage und andere Tage, an denen der ganze Betrieb frei hat – ein einzelner Tag oder ein Zeitraum von–bis. Sie zählen nicht als Arbeitstag und kosten deshalb keinen Urlaub. Die gesetzlichen Feiertage kommen aus dem Regelwerk („Feiertage …“) und dem Arbeitsort. Schon eingetragene Abwesenheiten behalten ihre Tage; berichtigt werden sie mit „Arbeitstage …“.',
      felder: [h('table', { class: 'pe-tabelle', id: 'pa-t-tabelle' }, h('thead', {}, h('tr', {}, ['Datum', 'bis (bei einem Zeitraum)', 'Name', 'entfernen'].map((t) => h('th', {}, t)))),
        h('tbody', {}, ...zeilen.map((z) => h('tr', {}, h('td', {}, tag(z.f.datum)), h('td', {}, ''), h('td', {}, z.f.name), h('td', {}, z.weg))),
          h('tr', {}, h('td', {}, datum.el), h('td', {}, bis.el), h('td', {}, name), h('td', {}, ''))))],
      senden: async () => {
        const w = datum.wert();
        const e = bis.wert();
        if (w === null || e === null) throw new Error('Das Datum ist nicht lesbar');
        if (Boolean(w) !== Boolean(name.value.trim())) throw new Error('Ein neuer freier Tag braucht Datum und Namen');
        if (e && !w) throw new Error('Ein Zeitraum braucht seinen ersten Tag');
        if (e && e < w) throw new Error('Der Zeitraum endet vor seinem Beginn');
        // jeder Tag des Zeitraums wird ein freier Tag mit demselben Namen
        const neu = [];
        for (let t = w ? new Date(`${w}T12:00:00Z`) : null; t && t.toISOString().slice(0, 10) <= (e || w); t.setUTCDate(t.getUTCDate() + 1)) neu.push(t.toISOString().slice(0, 10));
        if (neu.length > 62) throw new Error('Ein Zeitraum von höchstens 62 Tagen');
        for (const z of zeilen) if (z.weg.checked) await k.api('PUT', `${api}/freie-tage/${z.f.datum}`, { json: { name: null } });
        for (const t of neu) await k.api('PUT', `${api}/freie-tage/${t}`, { json: { name: name.value.trim() } });
        k.melden('Gespeichert.', 'erfolg');
        await laden();
      } });
  }

  b.append(seitenkopf(`Abwesenheiten – ${k.mandantName || k.mandant}`),
    // zwei feste Zeilen mit fester Bedeutung (F106): oben der gewählte Eintrag, darunter die Einrichtung
    h('div', { class: 'pe-leiste nicht-drucken', id: 'pa-leiste-eintrag' }, kn.neu, kn.ja, kn.nein, kn.zurueck, kn.ende, kn.tage, kn.nachweis),
    h('div', { class: 'pe-leiste nicht-drucken', id: 'pa-leiste-einrichtung' }, kn.konto, kn.regel, kn.arten, kn.orte, kn.feiertage, kn.frei),
    h('div', { class: 'pa-zeile' }, stand, h('label', { class: 'pe-haken nicht-drucken' }, 'Jahr ', jahr)),
    teiler(liste, h('div', { class: 'pa-unten' }, reiter, unten), { id: 'pa-teilung', hoehe: 300 }));
  await laden();
}
