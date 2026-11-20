// SPDX-License-Identifier: AGPL-3.0-or-later
// „Abwesenheiten“ (PERSONAL.md Stufe 2, F138): oben die Liste der Abwesenheiten eines Jahres, die der Angemeldete sehen
// darf; darunter, mit verschiebbarem Teiler (F137), der Kalender „wer fehlt wann“ und die Urlaubskonten. Spalten mit
// Sortierung und Filter (F91). Knöpfe stehen fest und nennen gesperrt den Grund (F106). Was eine Rolle nicht sehen darf,
// kommt vom Server gar nicht erst: Der Vorgesetzte sieht bei einer Arbeitsunfähigkeit nur „abwesend“.
import { h, seitenkopf, knopf, feld, auswahl, sperren, datumFeld, bestaetigen, reiterleiste } from '/kern/ui/bausteine.js';
import { tabellenfilter } from '/kern/ui/tabellenfilter.js';
import { teiler } from '/kern/ui/teiler.js';
import { stil, dialog, tag, tageText, abwZustand, abwZustandText, auText, abwesenheitFelder } from './gemeinsam.js';

const MONATE = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

export async function zeigen(b, k) {
  stil();
  b.classList.add('listenseite', 'personal');
  const api = `${k.mandantApi}/personal`;
  let d = { darf: {}, abwesenheiten: [], personen: [], konten: [], arten: [], freie_tage: [], jahre: [], jahr: new Date().getFullYear(), heute: '' };
  let gewaehlt = null;
  let ansicht = 'kalender';
  let monat = null;      // „JJJJ-MM“ des Kalenders

  const kn = {
    neu: knopf('Eintragen …', eintragen, 'primaer', { id: 'pa-neu', title: 'Trägt für eine Person eine Abwesenheit ein – sie gilt ohne Antrag' }),
    ja: knopf('Genehmigen', () => entscheiden(true), '', { id: 'pa-genehmigen', title: 'Genehmigt den gewählten Antrag' }),
    nein: knopf('Ablehnen …', () => entscheiden(false), '', { id: 'pa-ablehnen', title: 'Lehnt den gewählten Antrag ab – mit Grund, den die Person sieht' }),
    zurueck: knopf('Zurücknehmen …', zuruecknehmen, '', { id: 'pa-zurueck', title: 'Nimmt die gewählte Abwesenheit zurück; der Eintrag bleibt als „zurückgenommen“ stehen' }),
    ende: knopf('Ende ändern …', endeAendern, '', { id: 'pa-ende', title: 'Verkürzt oder verlängert die gewählte Abwesenheit' }),
    nachweis: knopf('Nachweis …', nachweis, '', { id: 'pa-nachweis', title: 'Vermerkt, ob der Nachweis der Arbeitsunfähigkeit vorliegt' }),
    konto: knopf('Urlaubskonto …', kontoDialog, '', { id: 'pa-konto', title: 'Anspruch und Übertrag einer Person für das gewählte Jahr festsetzen' }),
    arten: knopf('Arten …', artenDialog, '', { id: 'pa-arten', title: 'Arten von Abwesenheiten pflegen' }),
    frei: knopf('Freie Tage …', freieTageDialog, '', { id: 'pa-frei', title: 'Feiertage und Betriebsferien: Sie zählen nicht als Arbeitstag' }),
  };
  const jahr = h('select', { id: 'pa-jahr', class: 'pe-jahr', 'aria-label': 'Jahr', onchange: () => { monat = null; laden(Number(jahr.value)); } });
  const stand = h('div', { class: 'pe-stand', id: 'pa-stand', role: 'status' });
  const liste = h('div', { class: 'liste-rahmen pe-liste', id: 'pa-liste' });
  const reiter = h('div', {});
  const unten = h('div', { class: 'pa-ansicht', id: 'pa-ansicht' });

  const spalten = [
    { id: 'von', titel: 'Von', klasse: 'eng', wert: (x) => tag(x.von), sortwert: (x) => x.von },
    { id: 'bis', titel: 'Bis', klasse: 'eng', wert: (x) => tag(x.bis), sortwert: (x) => x.bis },
    { id: 'person', titel: 'Person', wert: (x) => x.name + (x.ich ? ' (du)' : '') },
    { id: 'art', titel: 'Art', wert: (x) => x.art_name },
    { id: 'tage', titel: 'Arbeitstage', klasse: 'zahl', wert: (x) => tageText(x.tage), sortwert: (x) => x.tage ?? -1 },
    { id: 'zustand', titel: 'Zustand', wert: (x) => abwZustandText(x, d.heute)[1], zelle: (x) => abwZustand(x, d.heute) },
    { id: 'angaben', titel: 'Angaben', wert: (x) => [x.bemerkung, auText(x.au), x.ueberschneidet_au ? '! überschneidet sich mit einer Arbeitsunfähigkeit' : null].filter(Boolean).join(' · '),
      zelle: (x) => h('span', {}, [x.bemerkung, x.au ? h('span', { 'data-zustand': x.au.nachweis ? '' : 'abw-fehlt' }, auText(x.au)) : null,
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
      || (g.zustand !== 'beantragt' ? 'Das ist kein offener Antrag' : (g.entscheidbar ? null : 'Diesen Antrag entscheidest nicht du – über Eigenes und über fremde Teams entscheidet das Personal'));
    sperren(kn.ja, entscheidung);
    sperren(kn.nein, entscheidung);
    const laufend = nurPflege() || keine || (aktiv(g) ? null : 'Die Abwesenheit ist abgelehnt oder zurückgenommen');
    sperren(kn.zurueck, laufend);
    sperren(kn.ende, laufend || (g.halber_tag ? 'Ein halber Tag hat kein anderes Ende – zurücknehmen und neu eintragen' : null));
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
    reiter.replaceChildren(reiterleiste([{ id: 'kalender', text: 'Kalender' }, { id: 'konten', text: `Urlaubskonten ${d.jahr}` }], ansicht, (id) => { ansicht = id; untenZeichnen(); }));
    reiter.firstChild.querySelectorAll('button').forEach((el, i) => { el.id = `pa-reiter-${['kalender', 'konten'][i]}`; });
    unten.replaceChildren(ansicht === 'kalender' ? kalender() : konten());
  }

  function kalender() {
    if (!monat || monat.slice(0, 4) !== String(d.jahr)) monat = d.heute.slice(0, 4) === String(d.jahr) ? d.heute.slice(0, 7) : `${d.jahr}-01`;
    const [j, m] = monat.split('-').map(Number);
    const tage = new Date(j, m, 0).getDate();
    const iso = (t) => `${monat}-${String(t).padStart(2, '0')}`;
    const frei = new Map(d.freie_tage.map((f) => [f.datum, f.name]));
    const wt = (t) => new Date(j, m - 1, t).getDay();   // 0 = Sonntag
    const springen = (um) => { const n = m + um; if (n < 1 || n > 12) return; monat = `${j}-${String(n).padStart(2, '0')}`; untenZeichnen(); };
    const vor = knopf('‹', () => springen(-1), '', { id: 'pa-monat-vor', class: ['knopf', 'klein'], title: 'Monat davor', 'aria-label': 'Monat davor' });
    const nach = knopf('›', () => springen(1), '', { id: 'pa-monat-nach', class: ['knopf', 'klein'], title: 'Monat danach', 'aria-label': 'Monat danach' });
    sperren(vor, m === 1 ? 'Für das Jahr davor oben das Jahr wechseln' : null);
    sperren(nach, m === 12 ? 'Für das Jahr danach oben das Jahr wechseln' : null);
    const kopfKlasse = (t) => [frei.has(iso(t)) || wt(t) === 0 || wt(t) === 6 ? 'pa-frei' : '', iso(t) === d.heute ? 'pa-heute' : ''];
    const personen = d.personen.filter((p) => !p.ausgeschieden || d.abwesenheiten.some((x) => x.person === p.id));
    const zeile = (p) => {
      const eigene = d.abwesenheiten.filter((x) => x.person === p.id && aktiv(x));
      return h('tr', { 'data-person': p.id }, h('td', { class: 'pa-name', title: p.name }, p.name), Array.from({ length: tage }, (_, i) => {
        const t = iso(i + 1);
        // eine Arbeitsunfähigkeit geht in der Anzeige vor, wenn sich zwei überschneiden
        const x = eigene.filter((e) => e.von <= t && e.bis >= t).sort((a, c) => Number(Boolean(c.ist_au)) - Number(Boolean(a.ist_au)))[0];
        const klasse = kopfKlasse(i + 1);
        if (!x) return h('td', { class: klasse, title: frei.get(t) || '' }, '');
        return h('td', { class: klasse, 'data-abw': x.zustand, title: `${x.art_name}, ${tag(x.von)}–${tag(x.bis)} · ${abwZustandText(x, d.heute)[1]}` },
          x.zustand === 'beantragt' ? '○' : (x.halber_tag ? '◐' : '●'));
      }));
    };
    return h('div', { id: 'pa-kalender' },
      h('div', { class: 'pa-monat nicht-drucken' }, vor, h('strong', { id: 'pa-monat' }, `${MONATE[m - 1]} ${j}`), nach),
      h('table', { class: 'pa-kalender' },
        h('thead', {}, h('tr', {}, h('th', { class: 'pa-name' }, 'Person'), Array.from({ length: tage }, (_, i) => h('th', { class: kopfKlasse(i + 1), title: frei.get(iso(i + 1)) || '' }, String(i + 1))))),
        h('tbody', {}, personen.length ? personen.map(zeile) : h('tr', {}, h('td', { class: 'pa-name' }, 'Niemand zu sehen.'), h('td', { colspan: tage }, '')))),
      h('p', { class: 'pa-legende' }, '● abwesend (genehmigt, gemeldet oder eingetragen) · ◐ halber Tag · ○ beantragt · grau: Wochenende oder freier Tag des Betriebs'));
  }

  function konten() {
    const name = new Map(d.personen.map((p) => [p.id, p.name]));
    const kopf = [['Person', ''], ['Anspruch', 'zahl'], ['Übertrag', 'zahl'], ['genommen', 'zahl'], ['genehmigt', 'zahl'], ['Rest', 'zahl'], ['beantragt', 'zahl'], ['Rest nach Anträgen', 'zahl']];
    return h('div', { id: 'pa-konten' },
      h('table', { class: 'pe-tabelle pa-konten' }, h('thead', {}, h('tr', {}, kopf.map(([t, c]) => h('th', { class: c }, t)))),
        h('tbody', {}, d.konten.length ? d.konten.map((z) => h('tr', { 'data-person': z.person },
          h('td', {}, name.get(z.person) || ''),
          h('td', { class: 'zahl', title: z.festgesetzt ? 'für dieses Jahr festgesetzt' : 'aus dem Vertrag' }, tageText(z.anspruch) + (z.festgesetzt ? ' *' : '')),
          ...[z.uebertrag, z.genommen, z.genehmigt].map((w) => h('td', { class: 'zahl' }, tageText(w))),
          h('td', { class: 'zahl' }, z.rest < 0 ? h('span', { 'data-zustand': 'abw-fehlt' }, `! ${tageText(z.rest)}`) : tageText(z.rest)),
          h('td', { class: 'zahl' }, tageText(z.beantragt)),
          h('td', { class: 'zahl' }, z.rest_nach_antraegen < 0 ? h('span', { 'data-zustand': 'abw-fehlt' }, `! ${tageText(z.rest_nach_antraegen)}`) : tageText(z.rest_nach_antraegen))))
          : h('tr', {}, h('td', { colspan: kopf.length }, 'Niemand zu sehen.')))),
      h('p', { class: 'pa-legende' }, 'Tage in Arbeitstagen. Anspruch aus der Fassung des Vertrags, die am 1. Januar gilt; * = für das Jahr festgesetzt. '
        + 'kumero zwölftelt nicht, überträgt nichts von selbst und lässt nichts verfallen – das setzt das Personal mit „Urlaubskonto …“.'));
  }

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
      datumFeld, auswahl, feld, darfAu: d.darf.au_sehen });
    dialog({ id: 'pa-dialog-neu', titel: 'Abwesenheit eintragen', ja: 'Eintragen', breit: true,
      text: 'Was du hier einträgst, gilt ohne Antrag. Die Arbeitstage rechnet kumero aus dem Vertrag der Person und den freien Tagen.',
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
      if (!await bestaetigen('Antrag genehmigen?', `${g.art_name} von ${g.name}, ${tag(g.von)} bis ${tag(g.bis)}${g.tage !== null ? ` (${tageText(g.tage)} Arbeitstage)` : ''}.`, 'Genehmigen')) return;
      try { await k.api('POST', `${api}/abwesenheiten/${g.id}/entscheidung`, { json: { genehmigen: true } }); k.melden('Antrag genehmigt.', 'erfolg'); } catch (x) { k.fehler(x); }
      await laden();
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
    if (!await bestaetigen(vor ? 'Nachweis liegt vor?' : 'Nachweis liegt doch nicht vor?',
      `Arbeitsunfähigkeit von ${g.name}, ${tag(g.von)} bis ${tag(g.bis)}. kumero ruft nichts bei Krankenkassen ab – vermerkt wird nur, dass der Nachweis vorliegt.`, vor ? 'Liegt vor' : 'Liegt nicht vor')) return;
    try { await k.api('PUT', `${api}/abwesenheiten/${g.id}/nachweis`, { json: { liegt_vor: vor } }); k.melden(vor ? 'Nachweis vermerkt.' : 'Vermerk zurückgenommen.', 'erfolg'); } catch (x) { k.fehler(x); }
    await laden();
  }

  function kontoDialog() {
    const g = wahl();
    const person = auswahl(d.konten.map((z) => [z.person, d.personen.find((p) => p.id === z.person)?.name || '']), g && d.konten.some((z) => z.person === g.person) ? g.person : null, { id: 'pa-k-person' });
    const anspruch = h('input', { id: 'pa-k-anspruch', type: 'text', class: 'pe-kurz', inputmode: 'decimal', autocomplete: 'off', placeholder: 'aus dem Vertrag' });
    const uebertrag = h('input', { id: 'pa-k-uebertrag', type: 'text', class: 'pe-kurz', inputmode: 'decimal', autocomplete: 'off' });
    const fuellen = () => { const z = d.konten.find((x) => x.person === person.value); anspruch.value = z?.festgesetzt ? tageText(z.anspruch) : ''; uebertrag.value = z ? tageText(z.uebertrag) : '0'; };
    person.addEventListener('change', fuellen);
    fuellen();
    const zahl = (el, leer) => { const t = el.value.trim().replace(',', '.'); if (t === '') return leer; if (!/^-?\d{1,2}(\.[05])?$/.test(t)) throw new Error('Tage als Zahl, halbe Tage mit ,5'); return t.replace(/\.0$/, ''); };
    dialog({ id: 'pa-dialog-konto', titel: `Urlaubskonto ${d.jahr}`, ja: 'Speichern',
      text: 'Der Anspruch kommt aus dem Vertrag. Setze ihn hier nur fest, wenn er in diesem Jahr abweicht (Eintritt im Jahr, Teilzeit ab Mitte des Jahres). Der Übertrag aus dem Vorjahr wird nie von selbst gesetzt.',
      felder: [feld('Person', person), h('div', { class: 'pe-reihe' }, feld('Anspruch in diesem Jahr (leer = aus dem Vertrag)', anspruch), feld('Übertrag aus dem Vorjahr', uebertrag))],
      senden: async () => {
        await k.api('PUT', `${api}/urlaubskonten/${person.value}/${d.jahr}`, { json: { anspruch: zahl(anspruch, null), uebertrag: zahl(uebertrag, '0') } });
        ansicht = 'konten';
        k.melden('Urlaubskonto gespeichert.', 'erfolg');
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
    dialog({ id: 'pa-dialog-arten', titel: 'Arten von Abwesenheiten', ja: 'Speichern', breit: true,
      text: 'Eine Art wird stillgelegt, nicht gelöscht: Bestehende Abwesenheiten behalten sie. „Bezahlt“ ist eine Angabe für die Lohnvorbereitung – bitte prüfen.',
      felder: [h('table', { class: 'pe-tabelle', id: 'pa-a-tabelle' }, h('thead', {}, h('tr', {}, ['Art', ...FELDER.map(([, t]) => t)].map((t) => h('th', {}, t)))),
        h('tbody', {}, ...zeilen.map((z) => h('tr', {}, h('td', {}, z.name), ...FELDER.map(([f]) => h('td', {}, z[f])))),
          h('tr', {}, h('td', {}, neu.name), ...FELDER.slice(0, 4).map(([f]) => h('td', {}, neu[f])), h('td', {}, ''))))],
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

  function freieTageDialog() {
    const zeilen = d.freie_tage.filter((f) => f.datum.slice(0, 4) >= String(d.jahr)).map((f) => ({ f, weg: h('input', { type: 'checkbox', 'aria-label': `${tag(f.datum)} entfernen` }) }));
    const datum = datumFeld({ id: 'pa-t-datum' });
    const name = h('input', { id: 'pa-t-name', type: 'text', maxlength: '60', placeholder: 'z. B. Neujahr, Betriebsferien', autocomplete: 'off' });
    dialog({ id: 'pa-dialog-frei', titel: 'Freie Tage des Betriebs', ja: 'Speichern', breit: true,
      text: 'Feiertage und Betriebsferien zählen nicht als Arbeitstag. kumero gibt keine vor – sie hängen vom Ort ab. Schon eingetragene Abwesenheiten behalten ihre Tage; neu gerechnet wird beim Genehmigen und bei „Ende ändern …“.',
      felder: [h('table', { class: 'pe-tabelle', id: 'pa-t-tabelle' }, h('thead', {}, h('tr', {}, ['Datum', 'Name', 'entfernen'].map((t) => h('th', {}, t)))),
        h('tbody', {}, ...zeilen.map((z) => h('tr', {}, h('td', {}, tag(z.f.datum)), h('td', {}, z.f.name), h('td', {}, z.weg))),
          h('tr', {}, h('td', {}, datum.el), h('td', {}, name), h('td', {}, ''))))],
      senden: async () => {
        const w = datum.wert();
        if (w === null) throw new Error('Das Datum ist nicht lesbar');
        if (Boolean(w) !== Boolean(name.value.trim())) throw new Error('Ein neuer freier Tag braucht Datum und Namen');
        for (const z of zeilen) if (z.weg.checked) await k.api('PUT', `${api}/freie-tage/${z.f.datum}`, { json: { name: null } });
        if (w) await k.api('PUT', `${api}/freie-tage/${w}`, { json: { name: name.value.trim() } });
        k.melden('Gespeichert.', 'erfolg');
        await laden();
      } });
  }

  b.append(seitenkopf(`Abwesenheiten – ${k.mandantName || k.mandant}`),
    h('div', { class: 'pe-leiste nicht-drucken' }, kn.neu, kn.ja, kn.nein, kn.zurueck, kn.ende, kn.nachweis, kn.konto, kn.arten, kn.frei),
    h('div', { class: 'pa-zeile' }, stand, h('label', { class: 'pe-haken nicht-drucken' }, 'Jahr ', jahr)),
    teiler(liste, h('div', { class: 'pa-unten' }, reiter, unten), { id: 'pa-teilung', hoehe: 300 }));
  await laden();
}
