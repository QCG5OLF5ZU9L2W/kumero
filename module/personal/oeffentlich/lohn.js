// SPDX-License-Identifier: AGPL-3.0-or-later
// „Lohnvorbereitung“ (PERSONAL.md Stufe 3, Abschnitt 10d, F140): je Monat die Personen mit dem Stand ihrer
// Lohnvorbereitung; darunter, mit verschiebbarem Teiler (F137), die Unterlage der gewählten Person – so, wie sie das
// Lohnbüro bekommt. Ablauf offen → geprüft → übergeben; danach ändert sich nichts mehr, berichtigt wird mit einer
// weiteren Fassung. Knöpfe stehen fest und nennen gesperrt den Grund (F106). Kein Betrag ohne Nachweis (PE11).
import { h, seitenkopf, knopf, feld, auswahl, sperren, datumFeld, bestaetigen } from '/kern/ui/bausteine.js';
import { tabellenfilter } from '/kern/ui/tabellenfilter.js';
import { teiler } from '/kern/ui/teiler.js';
import { stil, dialog, tag, tageText, stundenText, monatText, betragText, centLesen, satzLesen, fassungText, lohnUnterlage, LOHN_ZUSTAND, ZEITARTEN } from './gemeinsam.js';

export async function zeigen(b, k) {
  stil();
  b.classList.add('listenseite', 'personal');
  const api = `${k.mandantApi}/personal`;
  let d = { darf: {}, personen: [], regeln: [], zuordnungen: [], auswahl: [], funktionen: [], monat: '', heute: '' };
  let gewaehlt = null;

  const kn = {
    pruefen: knopf('Prüfen', pruefen, 'primaer', { id: 'lo-pruefen', title: 'Friert die Lohnvorbereitung der gewählten Person ein: Was jetzt dasteht, ist geprüft' }),
    oeffnen: knopf('Prüfung zurücknehmen', oeffnen, '', { id: 'lo-oeffnen', title: 'Macht die geprüfte Lohnvorbereitung wieder offen' }),
    uebergeben: knopf('Übergeben', uebergeben, '', { id: 'lo-uebergeben', title: 'Vermerkt die Übergabe an das Lohnbüro – danach ist die Lohnvorbereitung gesperrt' }),
    fassung: knopf('Neue Fassung …', fassungDialog, '', { id: 'lo-fassung', title: 'Berichtigung nach der Übergabe: eine weitere Fassung mit Vermerk; die übergebene bleibt stehen' }),
    bemerkung: knopf('Bemerkung …', bemerkungDialog, '', { id: 'lo-bemerkung', title: 'Bemerkung für das Lohnbüro zur gewählten Person' }),
    zuschlag: knopf('Zuschlag von Hand …', zuschlagDialog, '', { id: 'lo-zuschlag', title: 'Einmalzahlung, Zielerreichung, Berichtigung – mit Begründung' }),
    zurueck: knopf('Zuschlag zurücknehmen …', zuschlagZurueck, '', { id: 'lo-zuschlag-zurueck', title: 'Nimmt einen Zuschlag von Hand zurück; er bleibt als zurückgenommen stehen' }),
    drucken: knopf('Drucken', () => drucken(false), '', { id: 'lo-drucken', title: 'Die Unterlage der gewählten Person; als PDF über „Als PDF speichern“ im Druckdialog' }),
    sammel: knopf('Sammelliste drucken', () => drucken(true), '', { id: 'lo-sammel', title: 'Alle Unterlagen des Monats, je Person eine Seite; als PDF über den Druckdialog' }),
    regeln: knopf('Zuschlagsregeln …', regelnDialog, '', { id: 'lo-regeln', title: 'Regeln mit ihren Fassungen pflegen' }),
    zuordnen: knopf('Regeln zuordnen …', zuordnenDialog, '', { id: 'lo-zuordnen', title: 'Regeln an Personen oder Funktionen hängen' }),
  };
  const monat = h('select', { id: 'lo-monat', class: 'lo-monat', 'aria-label': 'Monat', onchange: () => laden(monat.value) });
  const stand = h('div', { class: 'pe-stand', id: 'lo-stand', role: 'status' });
  const liste = h('div', { class: 'liste-rahmen pe-liste', id: 'lo-liste' });
  const unten = h('div', { class: 'pa-ansicht', id: 'lo-unterlage' });
  const druck = h('div', { class: 'lo-druck', id: 'lo-druck' });

  const hinweise = (p) => [p.abweichung ? '! seit der Prüfung geändert' : null,
    (p.inhalt?.zuschlaege || []).some((z) => z.betrag_cent === null) ? '! Nachweis fehlt' : null, p.inhalt?.eintritt ? 'Eintritt' : null, p.inhalt?.austritt ? 'Austritt' : null,
    (p.inhalt?.vertragsaenderungen || []).length ? 'Vertrag geändert' : null, p.bemerkung ? 'Bemerkung' : null].filter(Boolean);
  const spalten = [
    { id: 'nr', titel: 'Nr.', klasse: 'eng', wert: (p) => p.personalnummer },
    { id: 'person', titel: 'Person', wert: (p) => p.name },
    { id: 'zustand', titel: 'Zustand', wert: (p) => LOHN_ZUSTAND[p.zustand], zelle: (p) => h('span', { 'data-zustand': `lohn-${p.zustand}` }, LOHN_ZUSTAND[p.zustand] + (p.fassung > 1 ? ` (Fassung ${p.fassung})` : '')) },
    { id: 'soll', titel: 'Soll', klasse: 'zahl', wert: (p) => (p.inhalt ? stundenText(p.inhalt.soll_minuten) : ''), sortwert: (p) => p.inhalt?.soll_minuten ?? -1 },
    { id: 'abw', titel: 'Abwesend', klasse: 'zahl', wert: (p) => tageText((p.inhalt?.abwesenheit_summen || []).reduce((s, x) => s + Number(x.tage), 0)) },
    { id: 'zuschlag', titel: 'Zuschläge', klasse: 'zahl', wert: (p) => (p.inhalt ? betragText(p.inhalt.zuschlag_summe_cent) : ''), sortwert: (p) => p.inhalt?.zuschlag_summe_cent ?? 0 },
    { id: 'hinweise', titel: 'Hinweise', wert: (p) => hinweise(p).join(' · '),
      zelle: (p) => h('span', {}, hinweise(p).flatMap((t, i) => [i ? ' · ' : '', t.startsWith('!') ? h('span', { 'data-zustand': 'abw-fehlt' }, t) : t])) },
  ];
  const filter = tabellenfilter(spalten, zeichnen);
  const wahl = () => d.personen.find((p) => p.person === gewaehlt) || null;

  function knoepfe() {
    const g = wahl();
    const keine = g ? null : 'Erst eine Person in der Liste wählen';
    const ohneInhalt = keine || (g.inhalt ? null : 'Die Person ist in diesem Monat nicht beschäftigt');
    const nurPruefen = d.darf.pruefen ? null : 'Das darf nur, wer Lohnvorbereitungen prüft (Rollen „Personal“ und „Lohn“)';
    const nurLohn = d.darf.uebergeben ? null : 'Das darf nur, wer Lohnvorbereitungen übergibt (Rolle „Lohn“)';
    const nurZuschlag = d.darf.zuschlag ? null : 'Das darf nur, wer Zuschläge führt (Rolle „Personal“)';
    const nichtOffen = g && g.zustand !== 'offen' ? (g.zustand === 'geprueft' ? 'Die Lohnvorbereitung ist geprüft – erst die Prüfung zurücknehmen' : 'Die Lohnvorbereitung ist übergeben – berichtigt wird mit „Neue Fassung …“ oder im Folgemonat') : null;
    sperren(kn.pruefen, nurPruefen || ohneInhalt || nichtOffen);
    sperren(kn.oeffnen, nurPruefen || keine || (g.zustand === 'geprueft' ? null : (g.zustand === 'offen' ? 'Die Lohnvorbereitung ist nicht geprüft' : 'Übergeben ist übergeben')));
    sperren(kn.uebergeben, nurLohn || keine || (g.zustand === 'geprueft' ? null : (g.zustand === 'offen' ? 'Übergeben wird nur, was geprüft ist' : 'Schon übergeben')));
    sperren(kn.fassung, nurLohn || keine || (g.zustand === 'uebergeben' ? null : 'Eine neue Fassung gibt es erst nach der Übergabe'));
    sperren(kn.bemerkung, nurPruefen || ohneInhalt || nichtOffen);
    sperren(kn.zuschlag, nurZuschlag || ohneInhalt || nichtOffen);
    sperren(kn.zurueck, nurZuschlag || ohneInhalt || nichtOffen || ((g.inhalt.zuschlaege || []).some((z) => z.art === 'hand') ? null : 'Die Person hat in diesem Monat keinen Zuschlag von Hand'));
    sperren(kn.drucken, ohneInhalt);
    sperren(kn.sammel, d.personen.some((p) => p.inhalt) ? null : 'In diesem Monat ist niemand beschäftigt');
    sperren(kn.regeln, nurZuschlag);
    sperren(kn.zuordnen, nurZuschlag || (d.regeln.some((r) => !r.stillgelegt) ? null : 'Es gibt noch keine Regel'));
  }

  function zeichnen() {
    filter.setzeZeilen(d.personen);
    const zeilen = filter.anwenden(d.personen);
    const tbody = h('tbody', {}, zeilen.length ? zeilen.map((p) => {
      const tr = h('tr', { 'data-id': p.person, tabindex: '0', class: p.person === gewaehlt ? 'gewaehlt' : '', 'aria-selected': p.person === gewaehlt ? 'true' : 'false' },
        spalten.map((s) => h('td', { class: s.klasse || '' }, s.zelle ? s.zelle(p) : s.wert(p))));
      const waehlen = () => { gewaehlt = p.person; zeichnen(); };
      tr.addEventListener('click', waehlen);
      tr.addEventListener('keydown', (ev) => { if (ev.target === tr && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); waehlen(); } });
      return tr;
    }) : h('tr', { class: 'leer' }, h('td', { colspan: spalten.length, id: 'lo-leer' },
      d.personen.length ? 'Keine Person passt zum Filter.' : `Im ${monatText(d.monat)} ist niemand beschäftigt.`)));
    const n = (z) => d.personen.filter((p) => p.zustand === z).length;
    liste.replaceChildren(h('div', { class: 'liste-scroll' }, h('table', { class: 'liste', id: 'lo-tabelle' }, h('thead', {}, filter.kopf), tbody)),
      h('div', { class: 'liste-fuss' }, `${d.personen.length === 1 ? '1 Person' : `${d.personen.length} Personen`} im ${monatText(d.monat)} · ${n('offen')} offen · ${n('geprueft')} geprüft · ${n('uebergeben')} übergeben`
        + (filter.aktiv() ? ' · gefiltert' : '')));
    stand.textContent = 'Zuschläge aus Regeln brauchen ihren Nachweis: Ohne bestätigte Leistungen bzw. ein freigegebenes Monatsblatt steht „Nachweis fehlt“ statt eines Betrags.';
    const g = wahl();
    unten.replaceChildren(g ? h('div', {}, lohnUnterlage(g, { person: g.person }),
      ...(g.fruehere.length ? [h('h3', { class: 'lo-frueher' }, 'Frühere Fassungen'), ...g.fruehere.map((f) => h('details', { class: 'lo-fruehere' },
        h('summary', {}, `Fassung ${f.fassung}, übergeben ${f.uebergeben_am ? tag(f.uebergeben_am.slice(0, 10)) : ''}`), lohnUnterlage({ ...f, zustand: 'uebergeben' })))] : []))
      : h('p', { class: 'hinweis', id: 'lo-keine-wahl' }, 'Wähle oben eine Person: Hier steht dann ihre Unterlage für das Lohnbüro.'));
    knoepfe();
  }

  async function tun(methode, pfad, json, meldung) {
    try { await k.api(methode, `${api}${pfad}`, { json }); k.melden(meldung, 'erfolg'); } catch (x) { k.fehler(x); }
    await laden();
  }
  const pfad = (g) => `/lohn/${d.monat}/${g.person}`;

  async function pruefen() {
    const g = wahl();
    if (!g) return;
    const fehlt = (g.inhalt.zuschlaege || []).filter((z) => z.betrag_cent === null).length;
    if (!await bestaetigen('Lohnvorbereitung prüfen?', `${g.name}, ${monatText(d.monat)}. Was jetzt in der Unterlage steht, wird eingefroren.`
      + (fehlt ? ` Achtung: Bei ${fehlt === 1 ? 'einem Zuschlag' : `${fehlt} Zuschlägen`} fehlt der Nachweis – dort steht kein Betrag.` : ''), 'Prüfen')) return;
    await tun('POST', `${pfad(g)}/pruefung`, {}, 'Geprüft.');
  }
  async function oeffnen() {
    const g = wahl();
    if (g) await tun('POST', `${pfad(g)}/oeffnung`, {}, 'Prüfung zurückgenommen.');
  }
  async function uebergeben() {
    const g = wahl();
    if (!g) return;
    if (!await bestaetigen('Als übergeben vermerken?', `${g.name}, ${monatText(d.monat)}. kumero überträgt nichts – übergeben wird der Ausdruck oder das PDF. Danach ist die Lohnvorbereitung gesperrt.`, 'Übergeben')) return;
    await tun('POST', `${pfad(g)}/uebergabe`, {}, 'Als übergeben vermerkt.');
  }

  function fassungDialog() {
    const g = wahl();
    if (!g) return;
    const vermerk = h('input', { id: 'lo-f-vermerk', type: 'text', required: true, maxlength: '200', size: '52', autocomplete: 'off' });
    dialog({ id: 'lo-dialog-fassung', titel: 'Neue Fassung', ja: 'Anlegen',
      text: `${g.name}, ${monatText(d.monat)}. Die übergebene Fassung ${g.fassung} bleibt stehen; die neue ist offen und wird neu gerechnet.`,
      felder: [feld('Vermerk – warum berichtigt wird', vermerk)],
      senden: async () => { await k.api('POST', `${api}${pfad(g)}/fassungen`, { json: { vermerk: vermerk.value.trim() } }); k.melden('Neue Fassung angelegt.', 'erfolg'); await laden(); } });
  }

  function bemerkungDialog() {
    const g = wahl();
    if (!g) return;
    const text = h('textarea', { id: 'lo-b-text', class: 'lo-text', maxlength: '500' }, g.bemerkung || '');
    dialog({ id: 'lo-dialog-bemerkung', titel: 'Bemerkung für das Lohnbüro', ja: 'Speichern', breit: true, text: `${g.name}, ${monatText(d.monat)}. Keine Gesundheitsangaben.`,
      felder: [feld('Bemerkung', text)],
      senden: async () => { await k.api('PUT', `${api}${pfad(g)}/bemerkung`, { json: { bemerkung: text.value.trim() || null } }); k.melden('Gespeichert.', 'erfolg'); await laden(); } });
  }

  function zuschlagDialog() {
    const g = wahl();
    if (!g) return;
    const betrag = h('input', { id: 'lo-z-betrag', class: 'lo-betrag', type: 'text', inputmode: 'decimal', required: true, autocomplete: 'off', placeholder: '0,00' });
    const grund = h('input', { id: 'lo-z-grund', type: 'text', required: true, maxlength: '200', size: '48', autocomplete: 'off' });
    const lohnart = h('input', { id: 'lo-z-lohnart', class: 'lo-regelfeld', type: 'text', maxlength: '20', autocomplete: 'off' });
    dialog({ id: 'lo-dialog-zuschlag', titel: 'Zuschlag von Hand', ja: 'Anlegen', text: `${g.name}, ${monatText(d.monat)}. Ein negativer Betrag berichtigt.`,
      felder: [h('div', { class: 'pe-reihe' }, feld('Betrag in €', betrag), feld('Lohnart', lohnart, 'falls bekannt')), feld('Begründung', grund)],
      senden: async () => {
        const cent = centLesen(betrag.value);
        if (cent === null || cent === 0) throw new Error('Der Betrag ist nicht lesbar oder null');
        await k.api('POST', `${api}/zuschlaege`, { json: { person: g.person, monat: d.monat, betrag_cent: cent, begruendung: grund.value.trim(), lohnart: lohnart.value.trim() || null } });
        k.melden('Zuschlag angelegt.', 'erfolg');
        await laden();
      } });
  }

  function zuschlagZurueck() {
    const g = wahl();
    const eigene = (g?.inhalt?.zuschlaege || []).filter((z) => z.art === 'hand');
    if (!eigene.length) return;
    const welcher = auswahl(eigene.map((z) => [z.id, `${betragText(z.betrag_cent)} – ${z.name}`]), eigene[0].id, { id: 'lo-r-welcher' });
    dialog({ id: 'lo-dialog-zurueck', titel: 'Zuschlag zurücknehmen', ja: 'Zurücknehmen', text: `${g.name}, ${monatText(d.monat)}. Der Zuschlag bleibt als zurückgenommen stehen und zählt nicht mehr.`,
      felder: [feld('Zuschlag', welcher)],
      senden: async () => { await k.api('POST', `${api}/zuschlaege/${welcher.value}/ruecknahme`, { json: {} }); k.melden('Zuschlag zurückgenommen.', 'erfolg'); await laden(); } });
  }

  function drucken(alle) {
    const wer = alle ? d.personen.filter((p) => p.inhalt) : [wahl()].filter(Boolean);
    if (!wer.length) return;
    druck.replaceChildren(...wer.map((p) => lohnUnterlage(p, { person: p.person })));
    b.classList.add('lo-druckt');
    window.addEventListener('afterprint', () => { b.classList.remove('lo-druckt'); druck.replaceChildren(); }, { once: true });
    window.print();
  }

  // --- Regeln ------------------------------------------------------------------------------------------------

  function regelnDialog() {
    const zeilen = d.regeln.map((r) => ({ r, still: h('input', { type: 'checkbox', checked: r.stillgelegt, 'aria-label': `${r.name} stillgelegt` }) }));
    const welche = auswahl([['', '– neue Regel –'], ...d.regeln.filter((r) => !r.stillgelegt).map((r) => [r.id, `neue Fassung für „${r.name}“`])], '', { id: 'lo-g-welche' });
    const name = h('input', { id: 'lo-g-name', type: 'text', maxlength: '60', size: '28', autocomplete: 'off' });
    const lohnart = h('input', { id: 'lo-g-lohnart', class: 'lo-regelfeld', type: 'text', maxlength: '20', autocomplete: 'off' });
    const grundlage = auswahl([['leistung', 'Leistung'], ['zeit', 'Zeit']], 'leistung', { id: 'lo-g-grundlage' });
    const ab = datumFeld({ id: 'lo-g-ab' });
    const form = auswahl([['anteil', 'Anteil am Betrag'], ['betrag', 'fester Betrag je Leistung'], ['staffel', 'Staffel']], 'anteil', { id: 'lo-g-form' });
    const satz = h('input', { id: 'lo-g-satz', class: 'lo-regelfeld', type: 'text', inputmode: 'decimal', autocomplete: 'off', placeholder: 'z. B. 25' });
    const betrag = h('input', { id: 'lo-g-betrag', class: 'lo-regelfeld', type: 'text', inputmode: 'decimal', autocomplete: 'off', placeholder: '0,00' });
    const schwelle = h('input', { id: 'lo-g-schwelle', class: 'lo-regelfeld', type: 'text', inputmode: 'decimal', autocomplete: 'off', placeholder: '0,00' });
    const satz2 = h('input', { id: 'lo-g-satz2', class: 'lo-regelfeld', type: 'text', inputmode: 'decimal', autocomplete: 'off' });
    const umfang = auswahl([['alle', 'alle Leistungen'], ['gruppe', 'eine Gruppe'], ['leistung', 'eine Leistung']], 'alle', { id: 'lo-g-umfang' });
    const bezug = h('input', { id: 'lo-g-bezug', type: 'text', maxlength: '60', autocomplete: 'off' });
    const zeitart = auswahl(Object.entries(ZEITARTEN), 'nacht', { id: 'lo-g-zeitart' });
    const zu = (el, grund) => { el.disabled = Boolean(grund); el.title = grund || ''; if (grund && el.tagName === 'INPUT') el.value = ''; };
    const anpassen = () => {
      const r = d.regeln.find((x) => x.id === welche.value) || null;
      if (r) { grundlage.value = r.grundlage; name.value = r.name; lohnart.value = r.lohnart || ''; }
      const bestehend = r ? 'Steht an der Regel – hier geht es um ihre neue Fassung' : null;
      zu(grundlage, bestehend); name.disabled = Boolean(r); name.title = bestehend || ''; lohnart.disabled = Boolean(r); lohnart.title = bestehend || '';
      const zeit = grundlage.value === 'zeit';
      const nurLeistung = zeit ? 'Nur bei der Grundlage Leistung' : null;
      zu(form, nurLeistung); zu(umfang, nurLeistung);
      const f = zeit ? 'zeitfenster' : form.value;
      zu(zeitart, zeit ? null : 'Nur bei der Grundlage Zeit');
      zu(satz, f === 'betrag' ? 'Ein fester Betrag hat keinen Satz' : null);
      zu(betrag, f === 'betrag' ? null : 'Nur bei „fester Betrag je Leistung“');
      zu(schwelle, f === 'staffel' ? null : 'Nur bei einer Staffel');
      zu(satz2, f === 'staffel' ? null : 'Nur bei einer Staffel');
      zu(bezug, nurLeistung || (umfang.value === 'alle' ? 'Nur bei einer Gruppe oder einer einzelnen Leistung' : null));
    };
    for (const el of [welche, grundlage, form, umfang]) el.addEventListener('change', anpassen);
    anpassen();
    const zuordnung = (r) => d.zuordnungen.filter((z) => z.regel === r.id && (!z.bis || z.bis >= d.heute)).map((z) => z.person_name || `Funktion „${z.funktion}“`).join(', ') || '–';
    dialog({ id: 'lo-dialog-regeln', titel: 'Zuschlagsregeln', ja: 'Speichern', breit: true,
      text: 'Eine Regel gilt in Fassungen: Eine neue Fassung gilt ab ihrem Datum, vergangene Monate werden nicht neu gerechnet. Gerechnet wird erst, wenn die Quelle da ist – erbrachte Leistungen bzw. das freigegebene Monatsblatt der Zeiterfassung.',
      felder: [h('div', { class: 'pa-rollbar' }, h('table', { class: 'pe-tabelle', id: 'lo-g-tabelle' },
        h('thead', {}, h('tr', {}, ['Regel', 'Grundlage', 'Es gilt', 'Lohnart', 'Zugeordnet', 'stillgelegt'].map((t) => h('th', {}, t)))),
        h('tbody', {}, zeilen.length ? zeilen.map((z) => h('tr', { class: z.r.stillgelegt ? 'pe-alt' : '' }, [z.r.name, z.r.grundlage === 'zeit' ? 'Zeit' : 'Leistung',
          h('span', { class: 'pa-hinweis' }, fassungText(z.r.fassungen.find((f) => f.id === z.r.gilt)) + (z.r.fassungen.length > 1 ? ` (${z.r.fassungen.length} Fassungen)` : '')), z.r.lohnart || '', zuordnung(z.r), z.still].map((x) => h('td', {}, x))))
          : h('tr', {}, h('td', { colspan: 6 }, 'Noch keine Regel.'))))),
        h('fieldset', { class: 'pe-gruppe' }, h('legend', {}, 'Neue Regel oder neue Fassung'),
          h('div', { class: 'pe-reihe' }, feld('Was', welche), feld('Name', name), feld('Lohnart', lohnart), feld('Grundlage', grundlage), feld('Gültig ab', ab.el)),
          h('div', { class: 'pe-reihe' }, feld('Form', form), feld('Satz in %', satz), feld('Betrag in €', betrag), feld('Schwelle in €', schwelle), feld('Satz ab Schwelle in %', satz2)),
          h('div', { class: 'pe-reihe' }, feld('Umfang', umfang), feld('Gruppe oder Leistung', bezug), feld('Fenster', zeitart)))],
      senden: async () => {
        const w = ab.wert();
        if (w === null) throw new Error('Das Datum ist nicht lesbar');
        let fassung = null;
        if (w || name.value.trim() || welche.value) {
          if (!w) throw new Error('Gültig ab fehlt');
          const zeit = grundlage.value === 'zeit';
          const s1 = satzLesen(satz.value); const s2 = satzLesen(satz2.value);
          const c1 = betrag.disabled ? null : centLesen(betrag.value); const c2 = schwelle.disabled ? null : centLesen(schwelle.value);
          if (s1 === undefined || s2 === undefined || (!betrag.disabled && c1 === null) || (!schwelle.disabled && c2 === null)) throw new Error('Ein Satz oder Betrag ist nicht lesbar');
          fassung = Object.fromEntries(Object.entries({ gueltig_ab: w, form: zeit ? 'zeitfenster' : form.value, satz_bp: s1, betrag_cent: c1, schwelle_cent: c2, satz_ab_schwelle_bp: s2,
            umfang: zeit ? null : umfang.value, bezug: bezug.disabled ? null : (bezug.value.trim() || null), zeitart: zeit ? zeitart.value : null }).filter(([, x]) => x !== null));
        }
        for (const z of zeilen) if (z.still.checked !== z.r.stillgelegt) await k.api('PUT', `${api}/zuschlagsregeln/${z.r.id}`, { json: { name: z.r.name, lohnart: z.r.lohnart, stillgelegt: z.still.checked } });
        if (fassung && welche.value) await k.api('POST', `${api}/zuschlagsregeln/${welche.value}/fassungen`, { json: fassung });
        else if (fassung) await k.api('POST', `${api}/zuschlagsregeln`, { json: { name: name.value.trim(), grundlage: grundlage.value, lohnart: lohnart.value.trim() || null, fassung } });
        k.melden('Gespeichert.', 'erfolg');
        await laden();
      } });
  }

  function zuordnenDialog() {
    const regeln = d.regeln.filter((r) => !r.stillgelegt);
    const name = (id) => d.regeln.find((r) => r.id === id)?.name || '';
    const zeilen = d.zuordnungen.filter((z) => !z.bis || z.bis >= d.heute).map((z) => ({ z, bis: datumFeld({ id: `lo-o-bis-${z.id}`, value: z.bis || '', 'aria-label': 'Ende der Zuordnung' }) }));
    const regel = auswahl(regeln.map((r) => [r.id, r.name]), regeln[0]?.id, { id: 'lo-o-regel' });
    const person = auswahl([['', '– an eine Funktion –'], ...d.auswahl.map((p) => [p.id, p.name])], '', { id: 'lo-o-person' });
    const funktion = h('input', { id: 'lo-o-funktion', type: 'text', maxlength: '100', autocomplete: 'off', list: 'lo-o-funktionen' });
    const von = datumFeld({ id: 'lo-o-von' });
    const bis = datumFeld({ id: 'lo-o-bis' });
    const anpassen = () => { funktion.disabled = Boolean(person.value); funktion.title = person.value ? 'Die Regel hängt an der gewählten Person' : ''; if (person.value) funktion.value = ''; };
    person.addEventListener('change', anpassen);
    anpassen();
    dialog({ id: 'lo-dialog-zuordnen', titel: 'Regeln zuordnen', ja: 'Speichern', breit: true,
      text: 'Eine Regel hängt an einer Person oder an einer Funktion – dann gilt sie für alle, deren Vertrag diese Funktion nennt. Eine Zuordnung endet mit ihrem „Bis“; gelöscht wird nichts.',
      felder: [h('datalist', { id: 'lo-o-funktionen' }, d.funktionen.map((f) => h('option', { value: f }))),
        h('div', { class: 'pa-rollbar' }, h('table', { class: 'pe-tabelle', id: 'lo-o-tabelle' }, h('thead', {}, h('tr', {}, ['Regel', 'Für', 'Von', 'Bis'].map((t) => h('th', {}, t)))),
          h('tbody', {}, zeilen.length ? zeilen.map((x) => h('tr', {}, [name(x.z.regel), x.z.person_name || `Funktion „${x.z.funktion}“`, tag(x.z.von), x.bis.el].map((c) => h('td', {}, c))))
            : h('tr', {}, h('td', { colspan: 4 }, 'Noch keine Zuordnung.'))))),
        h('fieldset', { class: 'pe-gruppe' }, h('legend', {}, 'Neue Zuordnung'),
          h('div', { class: 'pe-reihe' }, feld('Regel', regel), feld('Person', person), feld('oder Funktion', funktion), feld('Von', von.el), feld('Bis', bis.el, 'leer: bis auf Weiteres')))],
      senden: async () => {
        const v = von.wert(); const e = bis.wert();
        if (v === null || e === null || zeilen.some((x) => x.bis.wert() === null)) throw new Error('Ein Datum ist nicht lesbar');
        const neu = v || person.value || funktion.value.trim();
        if (neu && !v) throw new Error('Von fehlt');
        if (neu && !person.value && !funktion.value.trim()) throw new Error('Person oder Funktion fehlt');
        for (const x of zeilen) { const w = x.bis.wert(); if (w && w !== (x.z.bis || '')) await k.api('PUT', `${api}/zuschlagszuordnungen/${x.z.id}/ende`, { json: { bis: w } }); }
        if (neu) await k.api('POST', `${api}/zuschlagsregeln/${regel.value}/zuordnungen`, { json: { ...(person.value ? { person: person.value } : { funktion: funktion.value.trim() }), von: v, bis: e || null } });
        k.melden('Gespeichert.', 'erfolg');
        await laden();
      } });
  }

  async function laden(m) {
    try { d = await k.api('GET', `${api}/lohn${m ? `?monat=${m}` : ''}`); } catch (x) { k.fehler(x); }
    // die letzten 24 Monate bis zum laufenden; der gewählte steht immer dabei
    const jetzt = new Date();
    const monate = Array.from({ length: 24 }, (_, i) => { const t = new Date(jetzt.getFullYear(), jetzt.getMonth() - i, 1); return `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}`; });
    if (d.monat && !monate.includes(d.monat)) monate.push(d.monat);
    monat.replaceChildren(...monate.map((x) => h('option', { value: x }, monatText(x))));
    monat.value = d.monat;
    if (gewaehlt && !d.personen.some((p) => p.person === gewaehlt)) gewaehlt = null;
    zeichnen();
  }

  b.append(seitenkopf(`Lohnvorbereitung – ${k.mandantName || k.mandant}`),
    // zwei feste Zeilen (F106): oben die gewählte Person, darunter die Einrichtung
    h('div', { class: 'pe-leiste nicht-drucken', id: 'lo-leiste-person' }, kn.pruefen, kn.oeffnen, kn.uebergeben, kn.fassung, kn.bemerkung, kn.zuschlag, kn.zurueck, kn.drucken, kn.sammel),
    h('div', { class: 'pe-leiste nicht-drucken', id: 'lo-leiste-einrichtung' }, kn.regeln, kn.zuordnen),
    h('div', { class: 'pa-zeile nicht-drucken' }, stand, h('label', { class: 'pe-haken' }, 'Monat ', monat)),
    teiler(liste, unten, { id: 'lo-teilung', hoehe: 340 }), druck);
  await laden();
}
