// SPDX-License-Identifier: AGPL-3.0-or-later
// „Zeiten“ (ZEIT.md Abschnitt 9, F145): je Monat die Personen, die der Angemeldete sehen darf, mit Summen, Zeitkonto und
// dem, was auf eine Entscheidung wartet; darunter (verschiebbarer Teiler, F137) das Monatsblatt der gewählten Person.
// Vorgesetzte geben Anträge, einzelne Tage und Monate frei; das Personal trägt nach und pflegt Zeitprofile und Regeln.
// Spalten mit Sortierung und Filter (F91); Knöpfe stehen fest und nennen gesperrt den Grund (F106).
import { h, seitenkopf, knopf, feld, auswahl, sperren, bestaetigen } from '/kern/ui/bausteine.js';
import { tabellenfilter } from '/kern/ui/tabellenfilter.js';
import { teiler } from '/kern/ui/teiler.js';
import { stil, dialog, dauer, saldo, saldoText, minutenLesen, monatAuswahl, monatText, blattTabelle, antragFelder, antragText, MONATSSTAND, tag, wochentag } from './gemeinsam.js';

const TAGE = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
const BRAUCHT = { ausserhalb: 'Arbeit außerhalb der Regelarbeitstage', ueber_hoechst: 'Arbeit über der Höchstzeit' };

export async function zeigen(b, k) {
  stil();
  b.classList.add('listenseite', 'zeit');
  const api = `${k.mandantApi}/zeit`;
  let d = { darf: {}, personen: [], einstellung: { monatsabschluss: 'aus', pausen: [] }, monat: '', heute: '' };
  let blatt = null;
  let gewaehlt = null;

  const kn = {
    antrag: knopf('Antrag entscheiden …', antragDialog, 'primaer', { id: 'ze-antrag', title: 'Nimmt einen Nachtrag oder eine Korrektur der gewählten Person an oder lehnt ab' }),
    tag: knopf('Tag freigeben …', () => tagDialog(), '', { id: 'ze-tag', title: 'Arbeit außerhalb der Regelarbeitstage genehmigen; Sonderfreigabe über der Höchstzeit' }),
    frei: knopf('Monat freigeben', () => monatSetzen('freigabe'), '', { id: 'ze-monat-frei', title: 'Gibt den von der Person bestätigten Monat frei' }),
    oeffnen: knopf('Monat öffnen', () => monatSetzen('oeffnung'), '', { id: 'ze-monat-auf', title: 'Macht den abgeschlossenen Monat wieder bearbeitbar' }),
    nach: knopf('Nachtragen …', () => nachtragDialog(), '', { id: 'ze-nachtrag', title: 'Trägt für die gewählte Person nach oder berichtigt – gilt sofort, mit Begründung' }),
    profil: knopf('Zeitprofil …', profilDialog, '', { id: 'ze-profil', title: 'Regelarbeitstage, Gleitzeit, Rahmen und Kernzeit, Korrekturen, Anfangssaldo der gewählten Person' }),
    regeln: knopf('Regeln …', regelnDialog, '', { id: 'ze-regeln', title: 'Pausen, Höchstzeit, Nachtfenster und Monatsabschluss des Mandanten' }),
  };
  const stand = h('div', { class: 'ze-klein', id: 'ze-stand', role: 'status' });
  const wo = h('div', { class: 'ze-klein', id: 'ze-wo' });
  const liste = h('div', { class: 'liste-rahmen ze-liste', id: 'ze-liste' });
  const unten = h('div', { class: 'ze-unten', id: 'ze-blatt' });
  const kopfMonat = h('label', { class: 'ze-monat', id: 'ze-monatfeld' });

  const wartet = (p) => [p.antraege ? `${p.antraege} ${p.antraege === 1 ? 'Antrag' : 'Anträge'}` : null, p.tage_offen ? `${p.tage_offen} ${p.tage_offen === 1 ? 'Tag' : 'Tage'}` : null,
    d.einstellung.monatsabschluss === 'freigabe' && p.monatsstand === 'bestaetigt' ? 'Monat' : null].filter(Boolean).join(' · ');
  const spalten = [
    { id: 'nr', titel: 'Nr.', klasse: 'eng', wert: (p) => p.personalnummer },
    { id: 'person', titel: 'Person', wert: (p) => p.name + (p.ich ? ' (du)' : '') },
    { id: 'modell', titel: 'Modell', wert: (p) => (p.modell === 'gleitzeit' ? 'Gleitzeit' : 'fest') + (p.ohne_vertrag ? ' · ohne Vertrag' : ''),
      zelle: (p) => h('span', { title: p.ohne_vertrag ? 'Ohne Vertrag gibt es kein Soll und keine Regelarbeitstage – anlegen unter „Personen“ → „Vertrag …“' : '' }, (p.modell === 'gleitzeit' ? 'Gleitzeit' : 'fest') + (p.ohne_vertrag ? ' · ▲ ohne Vertrag' : '')) },
    { id: 'ist', titel: 'zählt', klasse: 'zahl', wert: (p) => dauer(p.summe.gezaehlt + p.summe.gutschrift), sortwert: (p) => p.summe.gezaehlt + p.summe.gutschrift },
    { id: 'soll', titel: 'Soll', klasse: 'zahl', wert: (p) => dauer(p.summe.soll), sortwert: (p) => p.summe.soll },
    { id: 'saldo', titel: 'Saldo', klasse: 'zahl', wert: (p) => saldoText(p.summe.saldo), sortwert: (p) => p.summe.saldo, zelle: (p) => saldo(p.summe.saldo) },
    { id: 'konto', titel: 'Zeitkonto', klasse: 'zahl', wert: (p) => saldoText(p.konto), sortwert: (p) => p.konto, zelle: (p) => saldo(p.konto) },
    { id: 'monat', titel: 'Monat', wert: (p) => (d.einstellung.monatsabschluss === 'aus' ? '– ohne Abschluss' : MONATSSTAND[p.monatsstand]) },
    { id: 'wartet', titel: 'Wartet auf Entscheidung', wert: (p) => wartet(p), zelle: (p) => (wartet(p) ? h('span', { 'data-zustand': 'ze-wartet' }, `▲ ${wartet(p)}`) : '') },
  ];
  const filter = tabellenfilter(spalten, zeichnen, { id: 'zeit.zeiten' });
  // Doppelklick auf einen Tag führt zu dem, was dort zu entscheiden ist (F150): zur Genehmigung des Tages, sonst zum offenen Antrag
  const blattT = blattTabelle('zeit.zeiten.blatt', () => zeichnen(), { beiDoppelklick: (t) => {
    if (t.braucht.length && !kn.tag.disabled) tagDialog(t.tag);
    else if ((blatt?.antraege || []).some((x) => x.zustand === 'offen' && x.tag === t.tag) && !kn.antrag.disabled) antragDialog();
    else k.melden(t.braucht.length ? (kn.tag.title || 'Diesen Tag kannst du nicht freigeben.') : 'An diesem Tag wartet nichts auf eine Entscheidung.', 'warnung');
  },
  // „Gehen fehlt“ führt zum Nachtrag nur des Gehens (ZEIT.md Abschnitt 12) – für den, der für andere nachtragen darf
  beiGehenFehlt: (a) => { if (kn.nach.disabled) k.melden(kn.nach.title || 'Nachtragen kannst du für diese Person nicht.', 'warnung'); else nachtragDialog({ art: 'gehen', abschnitt: a.id }); } });
  const wahl = () => d.personen.find((p) => p.person === gewaehlt) || null;
  // eine leere Liste sagt, warum sie leer ist
  const leerGrund = () => (d.personen_gesamt === 0 ? 'Es gibt noch keine Person – das Personal legt sie unter „Personen“ an.'
    : (d.darf.alle ? `Im ${monatText(d.monat)} ist niemand beschäftigt.`
      : (d.darf.verknuepft ? 'Dir ist niemand unterstellt – hier stehen die Zeiten deines Teams.' : 'Dein Zugang ist mit keiner Person verknüpft – das richtet das Personal unter „Personen“ ein.')));
  // wo gestempelt wird: Diese Seite ist die Übersicht, Kommen und Gehen stehen unter „Meine Zeiten“
  const stempelHinweis = () => (d.darf.eigene
    ? (d.darf.verknuepft ? 'Kommen und Gehen: unter „Meine Zeiten“ (Personal → Meine Daten).' : 'Kommen und Gehen stehen unter „Meine Zeiten“ – dein Zugang ist aber mit keiner Person verknüpft (Personal → „Personen“).')
    : 'Kommen und Gehen stehen unter „Meine Zeiten“ – dafür braucht es die Rolle „Zeiterfassung“, die der Administrator unter „Nutzer und Rollen“ zuweist.');

  function knoepfe() {
    const g = wahl();
    const keine = g ? null : 'Erst eine Person in der Liste wählen';
    const nichtMeine = g && !g.zustaendig ? (g.ich ? 'Über die eigenen Zeiten entscheidest nicht du' : 'Dafür bist du nicht zuständig') : null;
    const frei = d.darf.freigeben ? null : 'Das darf nur, wer Zeiten freigibt (Rollen „Vorgesetzter“ und „Personal“)';
    const pflege = d.darf.pflegen ? null : 'Das darf nur, wer Zeiten führt (Rolle „Personal“)';
    const zu = g && g.monatsstand !== 'offen' && d.einstellung.monatsabschluss !== 'aus' ? 'Der Monat ist abgeschlossen – erst öffnen' : null;
    const modus = d.einstellung.monatsabschluss;
    sperren(kn.antrag, frei || keine || nichtMeine || zu || (g.antraege ? null : 'Für diese Person wartet in diesem Monat kein Antrag'));
    sperren(kn.tag, frei || keine || nichtMeine || zu || (g.tage_offen ? null : 'Kein Tag dieser Person wartet auf eine Freigabe'));
    sperren(kn.frei, frei || keine || nichtMeine || (modus === 'freigabe' ? null : 'Der Mandant nutzt keine Freigabe des Monats – einstellbar unter „Regeln …“')
      || (g.monatsstand === 'bestaetigt' ? null : (g.monatsstand === 'offen' ? 'Erst bestätigt die Person ihren Monat' : 'Schon freigegeben')));
    sperren(kn.oeffnen, frei || keine || nichtMeine || (modus === 'aus' ? 'Der Mandant nutzt keinen Monatsabschluss' : null) || (g.monatsstand === 'offen' ? 'Der Monat ist nicht abgeschlossen' : null));
    sperren(kn.nach, pflege || keine || zu);
    sperren(kn.profil, pflege || keine);
    sperren(kn.regeln, pflege);
  }

  function zeichnen() {
    filter.setzeZeilen(d.personen);
    const zeilen = filter.anwenden(d.personen);
    const tbody = h('tbody', {}, zeilen.length ? zeilen.map((p) => {
      const tr = h('tr', { 'data-id': p.person, tabindex: '0', class: p.person === gewaehlt ? 'gewaehlt' : '', 'aria-selected': p.person === gewaehlt ? 'true' : 'false' },
        filter.spalten().map((s) => h('td', { class: s.klasse || '' }, s.zelle ? s.zelle(p) : s.wert(p))));
      const waehlen = async () => { gewaehlt = p.person; await blattLaden(); zeichnen(); };
      tr.addEventListener('click', waehlen);
      tr.addEventListener('keydown', (ev) => { if (ev.target === tr && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); waehlen(); } });
      return tr;
    }) : h('tr', { class: 'leer' }, h('td', { colspan: spalten.length, id: 'ze-leer' }, d.personen.length ? 'Keine Person passt zum Filter.' : leerGrund())));
    liste.replaceChildren(h('div', { class: 'liste-scroll' }, h('table', { class: 'liste', id: 'ze-tabelle' }, h('thead', {}, filter.kopf), tbody)),
      h('div', { class: 'liste-fuss' }, `${d.personen.length === 1 ? '1 Person' : `${d.personen.length} Personen`} im ${monatText(d.monat)}${filter.aktiv() ? ' · gefiltert' : ''}`));
    const e = d.einstellung;
    stand.textContent = `Pausen: ${e.pausen.map((x) => `über ${dauer(x.ab)} Std. ${x.pause} Min.`).join(', ') || 'keine Regel'} · Höchstzeit ${dauer(e.hoechst_minuten || 0)} Std. · Monatsabschluss: `
      + { aus: 'aus', person: 'die Person bestätigt', freigabe: 'bestätigen und freigeben' }[e.monatsabschluss]
      + (e.aufbewahrung_monate ? ` · Aufbewahrung mindestens ${e.aufbewahrung_monate} Monate` : '');
    wo.textContent = stempelHinweis();
    const g = wahl();
    unten.replaceChildren(g && blatt ? h('div', {}, h('h2', { id: 'ze-blatt-titel' }, `${g.name} – ${monatText(d.monat)}`),
      blatt.antraege.filter((x) => x.zustand === 'offen').length ? h('p', { 'data-zustand': 'ze-wartet', id: 'ze-offene' }, `▲ wartet: ${blatt.antraege.filter((x) => x.zustand === 'offen').map(antragText).join(' · ')}`) : null,
      blattT(blatt, d.heute))
      : h('p', { class: 'hinweis', id: 'ze-keine-wahl' }, 'Wähle oben eine Person: Hier steht dann ihr Monatsblatt.'));
    knoepfe();
  }

  async function blattLaden() {
    blatt = null;
    if (!gewaehlt) return;
    try { blatt = await k.api('GET', `${api}/personen/${gewaehlt}?monat=${d.monat}`); } catch (x) { k.fehler(x); }
  }

  async function laden(m) {
    try { d = await k.api('GET', `${api}${m || d.monat ? `?monat=${m || d.monat}` : ''}`); } catch (x) { k.fehler(x); }
    kopfMonat.replaceChildren('Monat ', monatAuswahl('ze-monatwahl', d.monat, (neu) => laden(neu)));
    if (gewaehlt && !d.personen.some((p) => p.person === gewaehlt)) gewaehlt = null;
    await blattLaden();
    zeichnen();
  }

  // --- Entscheidungen -----------------------------------------------------------------------------------------

  function antragDialog() {
    const offene = (blatt?.antraege || []).filter((x) => x.zustand === 'offen');
    if (!offene.length) return;
    const welcher = auswahl(offene.map((x) => [x.id, antragText(x)]), offene[0].id, { id: 'ze-a-welcher' });
    const urteil = auswahl([['ja', 'annehmen'], ['nein', 'ablehnen']], 'ja', { id: 'ze-a-urteil' });
    const grund = h('input', { id: 'ze-a-grund', type: 'text', maxlength: '200', size: '48', autocomplete: 'off' });
    const anpassen = () => { grund.disabled = urteil.value === 'ja'; grund.title = grund.disabled ? 'Nur bei einer Ablehnung' : ''; if (grund.disabled) grund.value = ''; };
    urteil.addEventListener('change', anpassen);
    anpassen();
    dialog({ id: 'ze-dialog-antrag', titel: `Antrag entscheiden – ${wahl().name}`, ja: 'Entscheiden', breit: true,
      text: 'Angenommen wird der Wert übernommen; der alte wird nicht aufbewahrt, die Begründung bleibt. Eine Ablehnung braucht einen Grund, den die Person sieht.',
      felder: [feld('Antrag', welcher), h('div', { class: 'ze-reihe' }, feld('Entscheidung', urteil), feld('Grund', grund))],
      senden: async () => {
        await k.api('POST', `${api}/antraege/${welcher.value}/entscheidung`, { json: { annehmen: urteil.value === 'ja', ...(urteil.value === 'ja' ? {} : { grund: grund.value.trim() }) } });
        k.melden(urteil.value === 'ja' ? 'Antrag angenommen.' : 'Antrag abgelehnt.', 'erfolg');
        await laden();
      } });
  }

  function tagDialog(vorwahl) {
    const offene = (blatt?.tage || []).flatMap((t) => t.braucht.map((art) => [`${t.tag}|${art}`, `${wochentag(t.tag)} ${tag(t.tag)}: ${BRAUCHT[art]} (${dauer(t.brutto)} Std. gearbeitet)`]));
    if (!offene.length) return;
    const welcher = auswahl(offene, (typeof vorwahl === 'string' && offene.find(([w]) => w.startsWith(`${vorwahl}|`)) || offene[0])[0], { id: 'ze-t-welcher' });
    const urteil = auswahl([['ja', 'genehmigen'], ['nein', 'ablehnen']], 'ja', { id: 'ze-t-urteil' });
    const grund = h('input', { id: 'ze-t-grund', type: 'text', maxlength: '200', size: '48', autocomplete: 'off' });
    dialog({ id: 'ze-dialog-tag', titel: `Tag freigeben – ${wahl().name}`, ja: 'Entscheiden', breit: true,
      text: 'Ohne Genehmigung zählt Arbeit außerhalb der Regelarbeitstage nicht, und über der Höchstzeit zählt nichts. Eine Ablehnung braucht einen Grund.',
      felder: [feld('Tag', welcher), h('div', { class: 'ze-reihe' }, feld('Entscheidung', urteil), feld('Grund', grund, 'bei Ablehnung Pflicht'))],
      senden: async () => {
        const [t, art] = welcher.value.split('|');
        await k.api('PUT', `${api}/personen/${gewaehlt}/tage/${t}/freigabe`, { json: { art, genehmigt: urteil.value === 'ja', ...(grund.value.trim() ? { grund: grund.value.trim() } : {}) } });
        k.melden(urteil.value === 'ja' ? 'Tag genehmigt.' : 'Tag abgelehnt.', 'erfolg');
        await laden();
      } });
  }

  async function monatSetzen(was) {
    const g = wahl();
    if (!g) return;
    if (was === 'oeffnung' && !await bestaetigen('Monat wieder öffnen?', `${g.name}, ${monatText(d.monat)}. Bestätigung und Freigabe entfallen; die Person bestätigt danach neu.`, 'Öffnen')) return;
    try { await k.api('POST', `${api}/personen/${g.person}/monate/${d.monat}/${was}`, { json: {} }); k.melden(was === 'freigabe' ? 'Monat freigegeben.' : 'Monat geöffnet.', 'erfolg'); } catch (x) { k.fehler(x); }
    await laden();
  }

  function nachtragDialog(vor) {
    const g = wahl();
    if (!g || !blatt) return;
    const f = antragFelder({ id: 'ze-n', tage: blatt.tage, offene: blatt.offene, heute: d.heute, feld, auswahl, vor });
    dialog({ id: 'ze-dialog-nachtrag', titel: `Nachtragen oder berichtigen – ${g.name}`, ja: 'Übernehmen', breit: true,
      text: 'Was du hier einträgst, gilt sofort. Die Begründung bleibt sichtbar, der alte Wert nicht.', felder: f.felder,
      senden: async () => { await k.api('POST', `${api}/personen/${g.person}/antraege`, { json: f.json() }); k.melden('Übernommen.', 'erfolg'); await laden(); } });
    f.fokus();
  }

  function profilDialog() {
    const g = wahl();
    if (!g || !blatt) return;
    const p = blatt.profil;
    const modell = auswahl([['fest', 'feste Arbeitszeit'], ['gleitzeit', 'Gleitzeit']], p.modell, { id: 'ze-p-modell' });
    const eigene = h('input', { type: 'checkbox', id: 'ze-p-eigene', checked: Boolean(p.regeltage) });
    const tage = TAGE.map((t, i) => h('input', { type: 'checkbox', id: `ze-p-tag-${i}`, checked: p.regeltage ? p.regeltage[i] : i < 5 }));
    const uhr = (id, wert) => h('input', { id: `ze-p-${id}`, class: 'ze-uhr', type: 'time', value: wert || '' });
    const rv = uhr('rahmen-von', p.rahmen_von); const rb = uhr('rahmen-bis', p.rahmen_bis); const kv = uhr('kern-von', p.kern_von); const kb = uhr('kern-bis', p.kern_bis);
    const korrektur = auswahl([['freigabe', 'brauchen die Freigabe des Vorgesetzten'], ['sofort', 'gelten sofort']], p.korrektur, { id: 'ze-p-korrektur' });
    const anfang = h('input', { id: 'ze-p-anfang', class: 'ze-uhr', type: 'text', value: saldoText(p.anfangssaldo).replace('−', '-'), autocomplete: 'off' });
    const beginn = h('input', { id: 'ze-p-beginn', type: 'date', value: p.beginn || '' });
    const anpassen = () => {
      for (const el of tage) { el.disabled = !eigene.checked; el.title = eigene.checked ? '' : 'Es gelten die Tage, an denen der Vertrag Stunden nennt'; }
      for (const el of [rv, rb, kv, kb]) { el.disabled = modell.value !== 'gleitzeit'; el.title = el.disabled ? 'Nur bei Gleitzeit' : ''; if (el.disabled) el.value = ''; }
    };
    modell.addEventListener('change', anpassen); eigene.addEventListener('change', anpassen);
    anpassen();
    dialog({ id: 'ze-dialog-profil', titel: `Zeitprofil – ${g.name}`, ja: 'Speichern', breit: true,
      text: 'Arbeit außerhalb der Regelarbeitstage zählt erst mit Genehmigung. Bei Gleitzeit zählt Arbeit außerhalb des Rahmens nicht; die Kernzeit gibt nur einen Hinweis.',
      felder: [h('div', { class: 'ze-reihe' }, feld('Modell', modell), feld('Korrekturen der Person', korrektur)),
        h('fieldset', { class: 'pe-gruppe' }, h('legend', {}, 'Regelarbeitstage'), h('div', { class: 'ze-reihe' },
          h('label', { class: 'ze-haken' }, eigene, ' eigene Tage statt der aus dem Vertrag'), ...TAGE.map((t, i) => h('label', { class: 'ze-haken' }, tage[i], ` ${t}`)))),
        h('div', { class: 'ze-reihe' }, feld('Rahmen von', rv), feld('bis', rb), feld('Kernzeit von', kv), feld('bis', kb)),
        h('div', { class: 'ze-reihe' }, feld('Zeitkonto zählt ab', beginn, 'leer: ab dem ersten gebuchten Tag'), feld('Anfangssaldo', anfang, 'Stunden:Minuten, z. B. 12:30 oder -4:00'))],
      senden: async () => {
        const saldoMin = minutenLesen(anfang.value.replace('+', ''));
        if (saldoMin === null) throw new Error('Der Anfangssaldo ist nicht lesbar');
        const json = { modell: modell.value, korrektur: korrektur.value, anfangssaldo: saldoMin, regeltage: eigene.checked ? tage.map((el) => el.checked) : null,
          ...(beginn.value ? { beginn: beginn.value } : {}),
          ...Object.fromEntries([['rahmen_von', rv], ['rahmen_bis', rb], ['kern_von', kv], ['kern_bis', kb]].filter(([, el]) => el.value).map(([n, el]) => [n, el.value])) };
        await k.api('PUT', `${api}/personen/${g.person}/profil`, { json });
        k.melden('Zeitprofil gespeichert.', 'erfolg');
        await laden();
      } });
  }

  function regelnDialog() {
    const e = d.einstellung;
    const stufen = [0, 1, 2].map((i) => ({ ab: h('input', { id: `ze-r-ab-${i}`, class: 'ze-uhr', type: 'text', value: e.pausen[i] ? dauer(e.pausen[i].ab) : '', autocomplete: 'off', 'aria-label': `Stufe ${i + 1}: über` }),
      pause: h('input', { id: `ze-r-pause-${i}`, class: 'ze-uhr', type: 'number', min: '1', max: '240', value: e.pausen[i] ? String(e.pausen[i].pause) : '', 'aria-label': `Stufe ${i + 1}: Pause in Minuten` }) }));
    const hoechst = h('input', { id: 'ze-r-hoechst', class: 'ze-uhr', type: 'text', value: dauer(e.hoechst_minuten), autocomplete: 'off' });
    const nv = h('input', { id: 'ze-r-nacht-von', class: 'ze-uhr', type: 'time', value: e.nacht_von });
    const nb = h('input', { id: 'ze-r-nacht-bis', class: 'ze-uhr', type: 'time', value: e.nacht_bis });
    const abschluss = auswahl([['aus', 'aus – es gilt der Stand von jetzt'], ['person', 'die Person bestätigt ihren Monat'], ['freigabe', 'die Person bestätigt, der Vorgesetzte gibt frei']], e.monatsabschluss, { id: 'ze-r-abschluss' });
    const vorgabe = knopf('Vorgabe wieder einlesen', async () => {
      try { const r = await k.api('POST', `${api}/einstellung/vorgabe`, { json: {} }); document.getElementById('ze-dialog-regeln')?.close(); await laden(); k.melden(`Vorgabe vom ${tag(r.stand)} eingelesen.`, 'erfolg'); } catch (x) { k.fehler(x); }
    }, '', { id: 'ze-r-vorgabe', title: 'Pausen, Höchstzeit und Nachtfenster nach der Vorgabe des Releases; der Monatsabschluss bleibt' });
    dialog({ id: 'ze-dialog-regeln', titel: 'Regeln der Zeiterfassung', ja: 'Speichern', breit: true,
      text: 'Abgezogen wird nur, was an Pause fehlt, und nie unter die Schwelle der Stufe. Über der Höchstzeit zählt nichts ohne Sonderfreigabe. Die Vorgabe folgt dem Arbeitszeitgesetz, wie es öffentlich beschrieben ist – bitte prüfen.',
      felder: [h('table', { class: 'ze-blatt', id: 'ze-r-pausen' }, h('thead', {}, h('tr', {}, ['Arbeit über (Std.:Min.)', 'Pause in Minuten'].map((t) => h('th', {}, t)))),
        h('tbody', {}, stufen.map((s) => h('tr', {}, h('td', {}, s.ab), h('td', {}, s.pause))))),
        h('div', { class: 'ze-reihe' }, feld('Höchstzeit (Std.:Min.)', hoechst), feld('Nacht von', nv), feld('bis', nb), feld('Monatsabschluss', abschluss)), vorgabe],
      senden: async () => {
        const pausen = [];
        for (const s of stufen) {
          if (!s.ab.value.trim() && !s.pause.value) continue;
          const ab = minutenLesen(s.ab.value);
          if (ab === null || ab <= 0 || !Number.isInteger(Number(s.pause.value)) || Number(s.pause.value) <= 0) throw new Error('Eine Pausenstufe ist unvollständig oder nicht lesbar');
          pausen.push({ ab, pause: Number(s.pause.value) });
        }
        const h2 = minutenLesen(hoechst.value);
        if (h2 === null || h2 < 60) throw new Error('Die Höchstzeit ist nicht lesbar');
        await k.api('PUT', `${api}/einstellung`, { json: { pausen, hoechst_minuten: h2, nacht_von: nv.value, nacht_bis: nb.value, monatsabschluss: abschluss.value } });
        k.melden('Regeln gespeichert.', 'erfolg');
        await laden();
      } });
  }

  b.append(seitenkopf(`Zeiten – ${k.mandantName || k.mandant}`),
    // zwei feste Zeilen (F106): oben die gewählte Person, darunter die Einrichtung
    h('div', { class: 'ze-leiste nicht-drucken', id: 'ze-leiste-person' }, kn.antrag, kn.tag, kn.frei, kn.oeffnen, kn.nach),
    h('div', { class: 'ze-leiste nicht-drucken', id: 'ze-leiste-einrichtung' }, kn.profil, kn.regeln, kopfMonat),
    stand, wo, teiler(liste, unten, { id: 'ze-teilung', hoehe: 360 }));
  await laden();
}
