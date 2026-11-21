// SPDX-License-Identifier: AGPL-3.0-or-later
// „Personen“ (PERSONAL.md Stufe 1, F136): Liste der Personen, die der Angemeldete sehen darf, darunter an festem Platz
// die Übersicht der Akte der gewählten Person. Der Teiler dazwischen ist verschiebbar, die Spalten tragen Sortierung
// und Filter (F91, F137). Knöpfe werden gesperrt statt ausgeblendet und nennen den Grund (F106). Was eine Rolle nicht
// sehen darf, kommt vom Server gar nicht erst: Die Akte zeigt dann „nicht einsehbar“.
import { h, seitenkopf, knopf, feld, auswahl, sperren, datumFeld, zeit } from '/kern/ui/bausteine.js';
import { tabellenfilter } from '/kern/ui/tabellenfilter.js';
import { teiler } from '/kern/ui/teiler.js';
import { stil, dialog, feldname, PRIVAT_FELDER, VERTRAGSARTEN, WOCHENTAGE, stundenText, stundenLesen, fristZustand, tag, tageText, abwZustand, auText } from './gemeinsam.js';

export async function zeigen(b, k) {
  stil();
  b.classList.add('listenseite', 'personal');
  const api = `${k.mandantApi}/personal`;
  let d = { darf: {}, personen: [], fristarten: [], ich: null };
  let akte = null;          // Akte der gewählten Person
  let gewaehlt = null;

  const kn = {
    neu: knopf('Person anlegen …', () => personDialog(null), 'primaer', { id: 'pe-neu', title: 'Legt eine Person im Personal dieses Mandanten an' }),
    aendern: knopf('Bearbeiten …', () => personDialog(akte), '', { id: 'pe-aendern', title: 'Stammdaten und private Angaben ändern' }),
    vertrag: knopf('Vertrag: neue Fassung …', vertragDialog, '', { id: 'pe-vertrag', title: 'Legt eine Fassung des Vertrags mit „gültig ab“ an; frühere Fassungen bleiben' }),
    regeln: knopf('Kündigungsregeln …', regelnDialog, '', { id: 'pe-kuendigungsregeln', title: 'Die gesetzliche Kündigungsfrist und die Tarifverträge des Mandanten' }),
    loesch: knopf('Löschfrist …', loeschDialog, '', { id: 'pe-loesch', title: 'Legt fest, ab wann ein Objekt dieser Person gelöscht werden soll' }),
  };
  const suche = h('input', { type: 'search', class: 'pe-suche', id: 'pe-suche', placeholder: 'Name, Personalnummer oder Funktion', 'aria-label': 'Personen suchen' });
  const stand = h('div', { class: 'pe-stand', id: 'pe-stand', role: 'status' });
  const liste = h('div', { class: 'liste-rahmen pe-liste', id: 'pe-liste' });
  const unten = h('div', { class: 'pe-akte', id: 'pe-akte' });

  const spalten = [
    { id: 'nr', titel: 'Nr.', klasse: 'eng', wert: (p) => p.personalnummer },
    { id: 'name', titel: 'Name', wert: (p) => p.name + (p.ich ? ' (du)' : '') },
    { id: 'funktion', titel: 'Funktion', wert: (p) => p.funktion || '' },
    { id: 'vorgesetzter', titel: 'Vorgesetzter', wert: (p) => p.vorgesetzter_name || '' },
    { id: 'zustand', titel: 'Zustand', klasse: 'eng', wert: (p) => p.zustand, zelle: (p) => h('span', { 'data-zustand': p.zustand }, p.zustand) },
    { id: 'fristen', titel: 'Fristen fällig', klasse: 'zahl', wert: (p) => (p.fristen ? String(p.fristen) : ''), sortwert: (p) => p.fristen,
      zelle: (p) => (p.fristen ? h('span', { 'data-zustand': 'faellig' }, `▲ ${p.fristen}`) : '') },
  ];
  // Spalten nur für den, der Personen pflegt – sie stehen von Anfang an fest
  const pflegeSpalten = [
    { id: 'zugang', titel: 'Zugang', klasse: 'eng', wert: (p) => (p.mit_nutzer ? 'verknüpft' : 'ohne') },
    { id: 'vorschlaege', titel: 'Vorschläge', klasse: 'zahl', wert: (p) => (p.vorschlaege ? String(p.vorschlaege) : ''), sortwert: (p) => p.vorschlaege || 0,
      zelle: (p) => (p.vorschlaege ? h('span', { 'data-zustand': 'offen-vorschlag' }, `▲ ${p.vorschlaege} offen`) : '') },
    { id: 'loeschfrist', titel: 'Löschfrist', klasse: 'eng', wert: (p) => (p.frist_fehlt ? 'Frist fehlt' : 'gesetzt'),
      zelle: (p) => (p.frist_fehlt ? h('span', { 'data-zustand': 'fehlt', title: 'Mindestens ein Objekt dieser Person hat keine Löschfrist' }, '! Frist fehlt') : 'gesetzt') },
  ];
  let filter = null;
  let alleSpalten = spalten;

  const gesucht = () => {
    const q = suche.value.trim().toLowerCase();
    return q === '' ? d.personen : d.personen.filter((p) => `${p.name} ${p.personalnummer} ${p.funktion || ''}`.toLowerCase().includes(q));
  };

  function knoepfe() {
    const keine = akte ? null : 'Erst eine Person in der Liste wählen';
    sperren(kn.neu, d.darf.pflegen ? null : 'Das darf nur, wer Personen pflegt (Rolle „Personal“)');
    sperren(kn.aendern, (d.darf.pflegen ? null : 'Das darf nur, wer Personen pflegt (Rolle „Personal“)') || keine);
    sperren(kn.vertrag, (d.darf.vertrag_pflegen ? null : 'Das darf nur, wer Verträge pflegt (Rolle „Personal“)') || keine);
    sperren(kn.regeln, d.darf.vertrag_pflegen && d.darf.pflegen ? null : 'Das darf nur, wer Verträge pflegt (Rolle „Personal“)');
    sperren(kn.loesch, (d.darf.pflegen ? null : 'Das darf nur, wer Personen pflegt (Rolle „Personal“)') || keine);
  }

  function zeichnen() {
    const zeilen = filter.anwenden(gesucht());
    filter.setzeZeilen(gesucht());
    const tbody = h('tbody', {}, zeilen.length ? zeilen.map((p) => {
      const tr = h('tr', { 'data-id': p.id, tabindex: '0', class: [p.id === gewaehlt ? 'gewaehlt' : '', p.zustand === 'ausgeschieden' ? 'pe-aus' : ''],
        'aria-selected': p.id === gewaehlt ? 'true' : 'false' }, alleSpalten.map((s) => h('td', { class: s.klasse || '' }, s.zelle ? s.zelle(p) : s.wert(p))));
      tr.addEventListener('click', () => waehlen(p.id));
      tr.addEventListener('keydown', (ev) => { if (ev.target === tr && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); waehlen(p.id); } });
      return tr;
    }) : h('tr', { class: 'leer' }, h('td', { colspan: alleSpalten.length, id: 'pe-leer' },
      d.personen.length ? 'Keine Person passt zu Suche und Filter.' : (d.darf.alle || d.darf.team ? 'Noch keine Person. Mit „Person anlegen …“ beginnt das Personal.' : 'Für dich ist hier niemand zu sehen.'))));
    liste.replaceChildren(h('div', { class: 'liste-scroll' }, h('table', { class: 'liste', id: 'pe-tabelle' }, h('thead', {}, filter.kopf), tbody)),
      h('div', { class: 'liste-fuss' }, `${zeilen.length === d.personen.length ? '' : `${zeilen.length} von `}${d.personen.length === 1 ? '1 Person' : `${d.personen.length} Personen`}`
        + (filter.aktiv() ? ' · gefiltert' : '')));
    stand.textContent = d.darf.alle ? 'Du siehst alle Personen dieses Mandanten.' : (d.darf.team ? 'Du siehst die Personen, deren Vorgesetzter du bist' + (d.ich ? ', und dich selbst.' : '.') : '');
    knoepfe();
  }

  const angaben = (paare) => h('dl', { class: 'pe-angaben' }, paare.flatMap(([t, w]) => [h('dt', {}, t), h('dd', {}, w === null || w === undefined || w === '' ? '–' : w)]));
  const gesperrt = (was) => h('p', { class: 'hinweis' }, `${was} sind mit deiner Rolle nicht einsehbar.`);
  const loeschText = (o) => (d.darf.pflegen ? (o.loeschen_ab ? `löschen ab ${tag(o.loeschen_ab)}` : h('span', { 'data-zustand': 'fehlt' }, '! Frist fehlt')) : null);

  function akteZeichnen() {
    if (!akte) {
      unten.replaceChildren(h('p', { class: 'hinweis', id: 'pe-akte-leer' }, 'Wähle eine Person in der Liste – hier steht dann die Übersicht ihrer Akte.'));
      knoepfe();
      return;
    }
    const p = akte.person;
    const stamm = [['Personalnummer', p.personalnummer], ...('lohnbuero_nummer' in p ? [['Nr. beim Lohnbüro', p.lohnbuero_nummer]] : []), ['Name', p.name], ['Vorgesetzter', p.vorgesetzter_name], ['Arbeitsort', p.arbeitsort_name ? p.arbeitsort_name + (p.arbeitsort ? '' : ' (wie der Mandant)') : 'keiner – es gelten die bundeseinheitlichen Feiertage'], ['Zustand', p.zustand + (p.austritt ? ` · Austritt ${tag(p.austritt)}` : '')],
      ['Zugang zu kumero', p.mit_nutzer ? 'verknüpft (Selfservice möglich)' : 'ohne']];
    if (d.darf.pflegen) stamm.push(['Löschfrist', loeschText(p)]);
    const privat = akte.privat ? angaben([['Geburtsdatum', tag(akte.privat.geburtsdatum)], ['Anschrift', [akte.privat.strasse, [akte.privat.plz, akte.privat.ort].filter(Boolean).join(' ')].filter(Boolean).join(', ')],
      ['Telefon', akte.privat.telefon], ['E-Mail (privat)', akte.privat.email], ['Notfallkontakt', [akte.privat.notfall_name, akte.privat.notfall_telefon].filter(Boolean).join(' · ')],
      ...(d.darf.pflegen ? [['Löschfrist', loeschText(akte.privat)]] : [])]) : gesperrt('Private Angaben');
    const vertraege = akte.vertraege === null ? gesperrt('Verträge') : (akte.vertraege.length === 0 ? h('p', { class: 'hinweis', id: 'pe-kein-vertrag' }, 'Noch kein Vertrag.')
      : h('table', { class: 'pe-tabelle', id: 'pe-vertraege' }, h('thead', {}, h('tr', {}, ['Gültig ab', 'Art', 'Funktion', 'Beginn', 'Probezeit bis', 'Befristet bis', 'Woche', 'Urlaub', 'Kündigungsfrist', 'Buch', 'Stand', ...(d.darf.pflegen ? ['Löschfrist'] : [])].map((t) => h('th', {}, t)))),
        h('tbody', {}, akte.vertraege.map((v) => h('tr', { class: v.gilt ? '' : 'pe-alt', 'data-id': v.id }, [tag(v.gueltig_ab), VERTRAGSARTEN[v.art] || v.art, v.funktion || '', tag(v.beginn), tag(v.probezeit_bis), tag(v.befristet_bis),
          h('span', { title: v.verteilung.map((m, i) => `${WOCHENTAGE[i]} ${stundenText(m)}`).join(' · ') }, `${stundenText(v.wochenminuten)} Std.`), v.urlaubstage ? `${String(v.urlaubstage).replace('.', ',').replace(',0', '')} Tage` : '',
          h('span', { class: 'pe-kuendigung' }, v.kuendigung_anzeige || ''), v.lohnbuch_name || '', v.gilt ? h('span', { 'data-zustand': 'gilt' }, '● gilt') : (v.gueltig_ab > akte.heute ? 'künftig' : 'frühere Fassung'), ...(d.darf.pflegen ? [loeschText(v)] : [])].map((z) => h('td', {}, z)))))));
    const fristen = akte.fristen.length === 0 ? h('p', { class: 'hinweis' }, 'Keine Fristen.')
      : h('table', { class: 'pe-tabelle', id: 'pe-fristen' }, h('thead', {}, h('tr', {}, ['Fällig', 'Art', 'Bemerkung', 'Zustand'].map((t) => h('th', {}, t)))),
        h('tbody', {}, akte.fristen.map((f) => h('tr', { class: f.zustand === 'erledigt' ? 'pe-alt' : '' }, [tag(f.faellig), f.art_name, f.bemerkung || '', fristZustand(f)].map((z) => h('td', {}, z))))));
    const vorschlaege = akte.vorschlaege === null ? null : (akte.vorschlaege.length === 0 ? h('p', { class: 'hinweis' }, 'Keine Änderungsvorschläge.')
      : h('table', { class: 'pe-tabelle', id: 'pe-vorschlaege' }, h('thead', {}, h('tr', {}, ['Vom', 'Vorgeschlagen', 'Zustand', ''].map((t) => h('th', {}, t)))),
        h('tbody', {}, akte.vorschlaege.map((v) => h('tr', { class: v.zustand === 'offen' ? '' : 'pe-alt', 'data-id': v.id }, [zeit(v.angelegt_am),
          Object.entries(v.felder).map(([f, w]) => `${feldname(f)}: ${w === null ? '(leeren)' : w}`).join(' · '),
          v.zustand === 'offen' ? h('span', { 'data-zustand': 'offen-vorschlag' }, '▲ offen') : (v.zustand === 'uebernommen' ? '✓ übernommen' : `✗ abgelehnt${v.grund ? ` – ${v.grund}` : ''}`),
          v.zustand === 'offen' && d.darf.pflegen ? h('span', {}, knopf('Übernehmen', () => entscheiden(v, true), '', { class: ['knopf', 'klein', 'pe-uebernehmen'], title: d.darf.privat ? 'Schreibt die vorgeschlagenen Angaben in die Akte' : '' }), ' ',
            knopf('Ablehnen …', () => entscheiden(v, false), '', { class: ['knopf', 'klein', 'pe-ablehnen'] })) : ''].map((z) => h('td', {}, z)))))));
    // Abwesenheiten und Urlaubskonto (Stufe 2): nur für den, der Abwesenheiten mit ihrer Art sehen darf, und für die eigene Akte
    const konto = (akte.urlaubskonto || [])[0];
    const abwesenheiten = akte.abwesenheiten === null || akte.abwesenheiten === undefined ? gesperrt('Abwesenheiten') : h('div', { id: 'pe-abwesenheiten' },
      konto ? h('p', { class: 'pe-klein', id: 'pe-urlaub' }, `Urlaub ${konto.jahr}: Anspruch ${tageText(konto.anspruch)}${konto.uebertrag ? ` + Übertrag ${tageText(konto.uebertrag)}` : ''} · genommen ${tageText(konto.genommen)} · genehmigt ${tageText(konto.genehmigt)} · beantragt ${tageText(konto.beantragt)} · Rest ${tageText(konto.rest)}`) : null,
      akte.abwesenheiten.length === 0 ? h('p', { class: 'hinweis' }, 'Keine Abwesenheiten.')
        : h('table', { class: 'pe-tabelle' }, h('thead', {}, h('tr', {}, ['Von', 'Bis', 'Art', 'Arbeitstage', 'Zustand', 'Angaben', ...(d.darf.pflegen ? ['Löschfrist'] : [])].map((t) => h('th', {}, t)))),
          h('tbody', {}, akte.abwesenheiten.map((x) => h('tr', { class: x.zustand === 'beantragt' || x.zustand === 'genehmigt' ? '' : 'pe-alt', 'data-id': x.id },
            [tag(x.von), tag(x.bis), x.art_name, tageText(x.tage), abwZustand(x, akte.heute), [x.bemerkung, auText(x.au)].filter(Boolean).join(' · '), ...(d.darf.pflegen ? [loeschText(x)] : [])].map((z) => h('td', {}, z)))))));
    unten.replaceChildren(h('h2', { id: 'pe-akte-titel' }, `Akte: ${p.name}`),
      h('div', { class: 'pe-spalten' }, h('div', {}, h('h3', {}, 'Stamm'), angaben(stamm)), h('div', { id: 'pe-privat' }, h('h3', {}, 'Private Angaben'), privat)),
      h('h3', {}, 'Vertrag'), vertraege, h('h3', {}, 'Abwesenheiten'), abwesenheiten, h('h3', {}, 'Fristen und Nachweise'), fristen,
      ...(vorschlaege ? [h('h3', {}, 'Änderungsvorschläge aus dem Selfservice'), vorschlaege] : []));
    knoepfe();
  }

  async function waehlen(id) {
    gewaehlt = id;
    try { akte = id ? await k.api('GET', `${api}/personen/${id}`) : null; } catch (x) { akte = null; k.fehler(x); }
    zeichnen();
    akteZeichnen();
  }

  async function laden() {
    try {
      d = await k.api('GET', api);
      if (!filter) {
        alleSpalten = d.darf.pflegen ? [...spalten, ...pflegeSpalten] : spalten;
        filter = tabellenfilter(alleSpalten, zeichnen);
      }
      if (gewaehlt && !d.personen.some((p) => p.id === gewaehlt)) gewaehlt = null;
      await waehlen(gewaehlt);
    } catch (x) { k.fehler(x); }
  }

  // --- Dialoge ----------------------------------------------------------------------------------------------

  async function personDialog(a) {
    let wahl = { nutzer: [], buecher: [], arbeitsorte: [] };
    try { wahl = await k.api('GET', `${api}/auswahl`); } catch (x) { k.fehler(x); return; }
    const p = a?.person || {};
    const pr = a?.privat || {};
    const text = (id, wert, attr = {}) => h('input', { id, type: 'text', autocomplete: 'off', value: wert || '', ...attr });
    const f = {
      // die Personalnummer vergibt kumero zufällig (F147): niemand tippt sie, sie verrät nichts über die Dauer im Betrieb
      personalnummer: text('pe-f-nr', a ? p.personalnummer : '', { disabled: true, class: 'pe-kurz', placeholder: 'automatisch',
        title: a ? 'Die Personalnummer vergibt kumero – eine neue gibt es mit dem Haken daneben' : 'Die Personalnummer vergibt kumero beim Anlegen: zufällig, ohne Reihenfolge' }),
      nummerNeu: h('input', { type: 'checkbox', id: 'pe-f-nr-neu', disabled: !a, title: a ? '' : 'Erst nach dem Anlegen – die erste Nummer vergibt kumero' }),
      lohnbuero_nummer: text('pe-f-lohnnr', p.lohnbuero_nummer, { maxlength: '20', class: 'pe-kurz', disabled: !d.darf.vertrag_sehen,
        title: d.darf.vertrag_sehen ? 'Die Nummer, unter der das Lohnbüro die Person führt' : 'Die Nummer beim Lohnbüro sieht nur, wer Verträge sieht' }),
      akademischer_titel: text('pe-f-titel', p.akademischer_titel, { maxlength: '40', class: 'pe-kurz', placeholder: 'z. B. Dr.' }),
      familienname: text('pe-f-familienname', p.familienname, { required: true, maxlength: '100' }),
      vornamen: text('pe-f-vornamen', p.vornamen, { maxlength: '100' }),
      vorgesetzter: auswahl([['', '– keiner –'], ...d.personen.filter((x) => x.id !== p.id && x.zustand === 'aktiv').map((x) => [x.id, x.name])], p.vorgesetzter || '', { id: 'pe-f-vorgesetzter' }),
      nutzer: auswahl([['', '– ohne Zugang –'], ...wahl.nutzer.filter((n) => !n.vergeben || n.id === p.nutzer).map((n) => [n.id, n.name + (n.aktiv ? '' : ' (gesperrt)')])], p.nutzer || '', { id: 'pe-f-nutzer' }),
      austritt: datumFeld({ id: 'pe-f-austritt', value: p.austritt || '' }),
      arbeitsort: auswahl([['', '– wie der Mandant –'], ...(wahl.arbeitsorte || []).filter((o) => !o.stillgelegt || o.id === p.arbeitsort).map((o) => [o.id, o.name])], p.arbeitsort || '', { id: 'pe-f-arbeitsort' }),
      geburtsdatum: datumFeld({ id: 'pe-f-geburtsdatum', value: pr.geburtsdatum || '' }),
    };
    const privat = Object.fromEntries(PRIVAT_FELDER.map(([n]) => [n, text(`pe-f-${n}`, pr[n], { maxlength: n === 'plz' ? '10' : (n === 'email' ? '254' : '100'), class: n === 'plz' ? 'pe-kurz' : '' })]));
    // private Angaben nur mit dem Recht dazu; das Feld bleibt stehen und nennt den Grund (F106)
    const ohnePrivat = d.darf.privat ? null : 'Private Angaben sind mit deiner Rolle nicht einsehbar';
    if (ohnePrivat) { f.geburtsdatum.sperren(true); Object.values(privat).forEach((el) => { el.disabled = true; el.title = ohnePrivat; }); }
    // Zugang anlegen (F149): legt den Nutzer im Mandanten an (Name und E-Mail der Person), wählt ihn hier aus und stellt
    // Link und Code aus – dieselbe Funktion wie unter „Nutzer und Rollen“, deshalb nur für den Administrator.
    const zugangNeu = knopf('Zugang anlegen …', async () => {
      const email = (privat.email.value || '').trim();
      try {
        if (!f.familienname.value.trim() || !f.vornamen.value.trim()) throw new Error('Für den Zugang braucht es Vorname und Familienname');
        if (!/^[^@\s]+@[^@\s]+$/.test(email)) throw new Error('Für den Zugang braucht es eine E-Mail-Adresse – unter „Private Angaben“ eintragen');
        sperren(zugangNeu, 'Wird angelegt …');
        const r = await k.api('POST', `${k.mandantApi}/nutzer`, { json: { vornamen: f.vornamen.value.trim(), familienname: f.familienname.value.trim(), email } });
        f.nutzer.append(h('option', { value: r.id }, `${f.vornamen.value.trim()} ${f.familienname.value.trim()}`));
        f.nutzer.value = r.id;
        const z = await k.api('POST', `${k.mandantApi}/nutzer/${r.id}/zugang`, { json: {} });
        zugangZeigen(`${f.vornamen.value.trim()} ${f.familienname.value.trim()}`, z);
        sperren(zugangNeu, 'Der Zugang ist angelegt und gewählt – jetzt speichern');
        pd.fehler.textContent = 'Zugang angelegt und gewählt. Jetzt speichern – die Rollen weist der Administrator unter „Nutzer und Rollen“ zu.';
      } catch (x) { pd.fehler.textContent = x.problem?.detail || x.message || 'Das hat nicht geklappt'; zugangSperre(); }
    }, '', { id: 'pe-f-zugang-neu', class: ['knopf', 'klein'] });
    const zugangSperre = () => sperren(zugangNeu, !d.darf.zugang_anlegen ? 'Zugänge legt der Administrator an (Mandant → Nutzer und Rollen)'
      : (f.nutzer.value ? 'Die Person hat schon einen Zugang' : null));
    zugangSperre();
    f.nutzer.addEventListener('change', zugangSperre);
    const pd = dialog({ id: 'pe-dialog-person', titel: a ? `${p.name} bearbeiten` : 'Person anlegen', ja: a ? 'Speichern' : 'Anlegen', breit: true,
      felder: [
        h('div', { class: 'pe-reihe' }, feld('Personalnummer', f.personalnummer), feld('Titel', f.akademischer_titel), feld('Familienname', f.familienname), feld('Vorname(n)', f.vornamen),
          h('label', { class: 'pe-haken' }, f.nummerNeu, ' neue Personalnummer vergeben'), feld('Nr. beim Lohnbüro', f.lohnbuero_nummer)),
        h('div', { class: 'pe-reihe' }, feld('Vorgesetzter', f.vorgesetzter), feld('Zugang zu kumero', f.nutzer, 'für „Meine Daten“; Rollen weist der Administrator zu'), zugangNeu,
          feld('Austritt (letzter Tag)', f.austritt.el), feld('Arbeitsort', f.arbeitsort, 'bestimmt die Feiertage; angelegt unter „Abwesenheiten“')),
        h('fieldset', { class: 'pe-gruppe', id: 'pe-f-privat' }, h('legend', {}, ohnePrivat || 'Private Angaben'),
          h('div', { class: 'pe-reihe' }, feld('Geburtsdatum', f.geburtsdatum.el), feld('Straße und Hausnummer', privat.strasse), feld('PLZ', privat.plz), feld('Ort', privat.ort)),
          h('div', { class: 'pe-reihe' }, feld('Telefon', privat.telefon), feld('E-Mail (privat)', privat.email), feld('Notfallkontakt', privat.notfall_name), feld('Telefon des Notfallkontakts', privat.notfall_telefon)))],
      senden: async () => {
        const austritt = f.austritt.wert(); const geb = f.geburtsdatum.wert();
        if (austritt === null || geb === null) throw new Error('Ein Datum ist nicht lesbar');
        const json = { familienname: f.familienname.value.trim(), vornamen: f.vornamen.value.trim() || null, akademischer_titel: f.akademischer_titel.value.trim() || null,
          ...(d.darf.vertrag_sehen ? { lohnbuero_nummer: f.lohnbuero_nummer.value.trim() || null } : {}),
          vorgesetzter: f.vorgesetzter.value || null, nutzer: f.nutzer.value || null, austritt: austritt || null, arbeitsort: f.arbeitsort.value || null };
        if (d.darf.privat) json.privat = { geburtsdatum: geb || null, ...Object.fromEntries(Object.entries(privat).map(([n, el]) => [n, el.value.trim() || null])) };
        if (a) { if (f.nummerNeu.checked) json.personalnummer_neu = true; await k.api('PUT', `${api}/personen/${p.id}`, { json }); k.melden(f.nummerNeu.checked ? 'Gespeichert – neue Personalnummer vergeben.' : 'Gespeichert.', 'erfolg'); }
        else { gewaehlt = (await k.api('POST', `${api}/personen`, { json })).id; k.melden('Person angelegt.', 'erfolg'); }
        await laden();
      } });
  }

  // Link und Code eines neuen Zugangs – kumero zeigt beides nur einmal (wie unter „Nutzer und Rollen“)
  function zugangZeigen(person, z) {
    const kopieren = (text, was) => async () => {
      try { await navigator.clipboard.writeText(text); k.melden(`${was} in die Zwischenablage kopiert.`, 'erfolg'); } catch { k.melden(`${was} bitte von Hand kopieren.`, 'warnung'); }
    };
    const dlg = h('dialog', { class: 'dialog', id: 'pe-dialog-zugang' },
      h('h2', {}, `Zugang für ${person}`),
      h('p', {}, `Gültig bis ${zeit(z.gueltig_bis)}, einmal verwendbar. `, h('strong', {}, 'kumero zeigt Link und Code nicht wieder.')),
      h('div', { class: 'pe-reihe' }, feld('Link', h('input', { id: 'pe-zugang-link', readonly: true, size: '44', value: z.link, spellcheck: false })), knopf('Link kopieren', kopieren(z.link, 'Link'), '', { id: 'pe-zugang-link-kopieren' })),
      h('div', { class: 'pe-reihe' }, feld('Code', h('output', { id: 'pe-zugang-code', class: 'pe-code' }, `${z.code.slice(0, 3)} ${z.code.slice(3)}`)), knopf('Code kopieren', kopieren(z.code, 'Code'), '', { id: 'pe-zugang-code-kopieren' })),
      h('p', { class: 'hinweis' }, 'Gib Link und Code auf zwei verschiedenen Wegen weiter – zum Beispiel den Link per E-Mail und den Code am Telefon. Wer nur eines von beiden hat, kommt nicht hinein.'),
      h('div', { class: 'dialog-knoepfe' }, knopf('Weitergegeben – schließen', () => dlg.close(), 'primaer', { id: 'pe-zugang-schliessen' })));
    dlg.addEventListener('cancel', (ev) => ev.preventDefault());
    dlg.addEventListener('close', () => dlg.remove());
    document.body.append(dlg);
    dlg.showModal();
  }

  // Kündigungsregeln (F148): die gesetzliche (Vorgabe aus der Steuerdatei, anzupassen) und die Tarifverträge des Mandanten
  async function regelnDialog() {
    let regeln = [];
    try { regeln = (await k.api('GET', `${api}/kuendigungsregeln`)).kuendigungsregeln; } catch (x) { k.fehler(x); return; }
    const STUFEN = 8;
    const zahl = (id, attr = {}) => h('input', { id, type: 'text', inputmode: 'numeric', autocomplete: 'off', class: 'pe-kurz', maxlength: '2', ...attr });
    const f = {
      wahl: auswahl([...regeln.map((r) => [r.id, (r.art === 'gesetz' ? '' : 'Tarifvertrag: ') + r.name + (r.stillgelegt ? ' (stillgelegt)' : '')]), ['', '– neuer Tarifvertrag –']], regeln[0]?.id || '', { id: 'pe-k-wahl' }),
      name: h('input', { id: 'pe-k-name', type: 'text', autocomplete: 'off', maxlength: '80', size: '36', required: true }),
      probezeit: zahl('pe-k-probezeit'), grund: zahl('pe-k-grund'),
      termin: auswahl([['15_oder_monatsende', 'zum 15. oder zum Monatsende'], ['monatsende', 'zum Monatsende'], ['jeder_tag', 'zu jedem Tag']], '15_oder_monatsende', { id: 'pe-k-termin' }),
      person: auswahl([['grund', 'die Grundfrist'], ['wie_arbeitgeber', 'dieselbe Staffel wie für den Arbeitgeber']], 'grund', { id: 'pe-k-person' }),
      still: h('input', { type: 'checkbox', id: 'pe-k-still' }),
      vorgabe: knopf('Vorgabe wieder einlesen', async () => {
        try { await k.api('POST', `${api}/kuendigungsregeln/vorgabe`, { json: {} }); k.melden('Gesetzliche Regel aus der Vorgabe eingelesen.', 'erfolg'); dlg.dlg.close(); await laden(); } catch (x) { dlg.fehler.textContent = x.problem?.detail || x.message; }
      }, '', { id: 'pe-k-vorgabe', class: ['knopf', 'klein'] }),
    };
    const stufen = Array.from({ length: STUFEN }, (_, i) => ({ ab: zahl(`pe-k-ab-${i}`, { 'aria-label': `Stufe ${i + 1}: ab Jahren im Betrieb` }), monate: zahl(`pe-k-monate-${i}`, { 'aria-label': `Stufe ${i + 1}: Monate zum Monatsende` }) }));
    const fuellen = () => {
      const r = regeln.find((x) => x.id === f.wahl.value) || null;
      const g = r?.regel || { probezeit_wochen: 2, grund_wochen: 4, grund_termin: '15_oder_monatsende', staffel: [], arbeitnehmer: 'grund' };
      f.name.value = r?.name || ''; f.probezeit.value = String(g.probezeit_wochen); f.grund.value = String(g.grund_wochen); f.termin.value = g.grund_termin; f.person.value = g.arbeitnehmer;
      stufen.forEach((s, i) => { s.ab.value = g.staffel[i] ? String(g.staffel[i].ab_jahren) : ''; s.monate.value = g.staffel[i] ? String(g.staffel[i].monate) : ''; });
      f.still.checked = Boolean(r?.stillgelegt);
      const gesetz = r?.art === 'gesetz';
      f.still.disabled = gesetz || !r; f.still.title = gesetz ? 'Die gesetzliche Regel lässt sich nicht stilllegen' : (r ? '' : 'Erst nach dem Anlegen');
      sperren(f.vorgabe, gesetz ? null : 'Die Vorgabe gibt es nur für die gesetzliche Regel');
    };
    f.wahl.addEventListener('change', fuellen);
    const dlg = dialog({ id: 'pe-dialog-kuendigung', titel: 'Kündigungsregeln', ja: 'Speichern', breit: true,
      text: 'Die gesetzliche Regel ist nach § 622 BGB vorbelegt und lässt sich anpassen; Tarifverträge legst du hier an. Am Vertrag wählst du: gesetzlich, einen Tarifvertrag oder individuell. Keine Rechtsberatung – bitte prüfen.',
      felder: [
        h('div', { class: 'pe-reihe' }, feld('Regel', f.wahl), feld('Name', f.name), h('label', { class: 'pe-haken' }, f.still, ' stillgelegt'), f.vorgabe),
        h('div', { class: 'pe-reihe' }, feld('Probezeit: Wochen', f.probezeit, 'zu jedem Tag; 0 = ohne Frist'), feld('Grundfrist: Wochen', f.grund), feld('Termin der Grundfrist', f.termin), feld('Kündigt die Person, gilt', f.person)),
        h('fieldset', { class: 'pe-gruppe' }, h('legend', {}, 'Kündigt der Arbeitgeber: ab vollen Jahren im Betrieb → Monate zum Monatsende (leere Stufen zählen nicht)'),
          h('div', { class: 'pe-tage', id: 'pe-k-staffel' }, ...stufen.map((s, i) => h('div', { class: 'pe-stufe' }, feld(`ab Jahren (${i + 1})`, s.ab), feld('Monate', s.monate)))))],
      senden: async () => {
        const n = (el) => (/^\d{1,2}$/.test(el.value.trim()) ? Number(el.value.trim()) : null);
        const staffel = [];
        for (const s of stufen) {
          if (s.ab.value.trim() === '' && s.monate.value.trim() === '') continue;
          if (n(s.ab) === null || n(s.monate) === null) throw new Error('Staffel: Jahre und Monate als ganze Zahlen');
          staffel.push({ ab_jahren: n(s.ab), monate: n(s.monate) });
        }
        if (n(f.probezeit) === null || n(f.grund) === null) throw new Error('Fristen in ganzen Wochen');
        const json = { name: f.name.value.trim(), regel: { probezeit_wochen: n(f.probezeit), grund_wochen: n(f.grund), grund_termin: f.termin.value, staffel, arbeitnehmer: f.person.value } };
        if (f.wahl.value) { json.stillgelegt = f.still.checked; await k.api('PUT', `${api}/kuendigungsregeln/${f.wahl.value}`, { json }); k.melden('Kündigungsregel gespeichert.', 'erfolg'); }
        else { await k.api('POST', `${api}/kuendigungsregeln`, { json }); k.melden('Tarifvertrag angelegt.', 'erfolg'); }
        await laden();
      } });
    fuellen();
  }

  async function vertragDialog() {
    if (!akte) return;
    let wahl = { buecher: [], kuendigungsregeln: [] };
    if (d.darf.pflegen) { try { wahl = await k.api('GET', `${api}/auswahl`); } catch (x) { k.fehler(x); return; } }
    const v = (akte.vertraege || []).find((x) => x.gilt) || (akte.vertraege || [])[0] || null;   // Vorbelegung aus der geltenden Fassung
    const text = (id, wert, attr = {}) => h('input', { id, type: 'text', autocomplete: 'off', value: wert || '', ...attr });
    const f = {
      gueltig_ab: datumFeld({ id: 'pe-v-gueltig' }), art: auswahl(Object.entries(VERTRAGSARTEN), v?.art || 'unbefristet', { id: 'pe-v-art' }),
      funktion: text('pe-v-funktion', v?.funktion, { maxlength: '100' }), beginn: datumFeld({ id: 'pe-v-beginn', value: v?.beginn || '' }),
      probezeit_bis: datumFeld({ id: 'pe-v-probezeit', value: v?.probezeit_bis || '' }), befristet_bis: datumFeld({ id: 'pe-v-befristet', value: v?.befristet_bis || '' }),
      // Kündigungsfrist (F148): gesetzlich, nach einem Tarifvertrag des Mandanten oder individuell – eine neue Fassung ist gesetzlich vorbelegt
      kuendigung: auswahl([['gesetzlich', 'gesetzlich (§ 622 BGB)'],
        ...(wahl.kuendigungsregeln || []).filter((r) => r.art === 'tarif' && (!r.stillgelegt || r.id === v?.kuendigungsregel)).map((r) => [`tarif:${r.id}`, `Tarifvertrag: ${r.name}`]),
        ['individuell', 'individuell – Wortlaut daneben']],
        v ? (v.kuendigung_art === 'tarif' ? `tarif:${v.kuendigungsregel}` : (v.kuendigung_art === 'individuell' && !v.kuendigungsfrist ? 'gesetzlich' : v.kuendigung_art)) : 'gesetzlich', { id: 'pe-v-kuendigung-art' }),
      kuendigungsfrist: text('pe-v-kuendigung', v?.kuendigungsfrist, { maxlength: '200', size: '40' }),
      urlaubstage: text('pe-v-urlaub', v?.urlaubstage ? String(v.urlaubstage).replace('.', ',').replace(',0', '') : '', { class: 'pe-kurz', inputmode: 'decimal' }),
      lohnbuch: auswahl([['', '– keines –'], ...wahl.buecher.map((x) => [x.kennung, x.name]), ...(v?.lohnbuch && !wahl.buecher.some((x) => x.kennung === v.lohnbuch) ? [[v.lohnbuch, v.lohnbuch_name]] : [])], v?.lohnbuch || '', { id: 'pe-v-buch' }),
    };
    const tage = WOCHENTAGE.map((t, i) => text(`pe-v-tag-${i}`, v ? (v.verteilung[i] ? stundenText(v.verteilung[i]) : '') : (i < 5 ? '8' : ''), { inputmode: 'decimal', 'aria-label': `Stunden am ${t}` }));
    const summe = h('div', { class: 'pe-summe', id: 'pe-v-summe', role: 'status' });
    const rechnen = () => {
      const m = tage.map((el) => stundenLesen(el.value));
      summe.textContent = m.includes(null) ? 'Stunden nicht lesbar' : `= ${stundenText(m.reduce((x, y) => x + y, 0))} Std. je Woche`;
      return m;
    };
    tage.forEach((el) => el.addEventListener('input', rechnen));
    // „Befristet bis“ steht immer da; gebraucht wird es nur bei einem befristeten Vertrag
    const befristung = () => { const an = f.art.value !== 'befristet' && f.art.value !== 'ausbildung'; f.befristet_bis.sperren(an); f.befristet_bis.ein.title = an ? 'Nur bei einem befristeten Vertrag oder einer Ausbildung' : ''; if (an) f.befristet_bis.setzen(''); };
    f.art.addEventListener('change', befristung);
    // der Wortlaut steht immer da; gebraucht wird er nur bei einer individuellen Frist
    const wortlaut = () => { const an = f.kuendigung.value !== 'individuell'; f.kuendigungsfrist.disabled = an;
      f.kuendigungsfrist.title = an ? 'Die Frist kommt aus der Regel – ein Wortlaut gehört nur zu einer individuellen Kündigungsfrist' : ''; };
    f.kuendigung.addEventListener('change', wortlaut);
    dialog({ id: 'pe-dialog-vertrag', titel: `Vertrag von ${akte.person.name}: neue Fassung`, ja: 'Fassung anlegen', breit: true,
      text: 'Eine Fassung gilt ab ihrem Tag und ersetzt keine frühere: Die bisherigen bleiben in der Akte. Vergütung führt kumero in dieser Stufe nicht.',
      felder: [
        h('div', { class: 'pe-reihe' }, feld('Fassung gültig ab', f.gueltig_ab.el), feld('Art', f.art), feld('Funktion', f.funktion)),
        h('div', { class: 'pe-reihe' }, feld('Beginn des Arbeitsverhältnisses', f.beginn.el), feld('Probezeit bis', f.probezeit_bis.el), feld('Befristet bis', f.befristet_bis.el)),
        h('fieldset', { class: 'pe-gruppe' }, h('legend', {}, 'Arbeitszeit: Stunden je Wochentag (8 · 7,5 · 7:30)'),
          h('div', { class: 'pe-tage' }, ...WOCHENTAGE.map((t, i) => feld(t, tage[i])), summe)),
        h('div', { class: 'pe-reihe' }, feld('Urlaubstage im Jahr', f.urlaubstage), feld('Buch, das den Lohn trägt', f.lohnbuch)),
        h('div', { class: 'pe-reihe' }, feld('Kündigungsfrist', f.kuendigung, 'Tarifverträge legt das Personal unter „Kündigungsregeln …“ an'), feld('Wortlaut (individuell)', f.kuendigungsfrist))],
      senden: async () => {
        const daten = Object.fromEntries(['gueltig_ab', 'beginn', 'probezeit_bis', 'befristet_bis'].map((n) => [n, f[n].wert()]));
        if (Object.values(daten).includes(null)) throw new Error('Ein Datum ist nicht lesbar');
        const m = rechnen();
        if (m.includes(null)) throw new Error('Stunden je Wochentag: 8, 7,5 oder 7:30');
        const u = f.urlaubstage.value.trim().replace(',', '.');
        await k.api('POST', `${api}/personen/${akte.person.id}/vertraege`, { json: { ...Object.fromEntries(Object.entries(daten).map(([n, w]) => [n, w || null])),
          art: f.art.value, funktion: f.funktion.value.trim() || null, verteilung: m,
          kuendigung_art: f.kuendigung.value.split(':')[0], kuendigungsregel: f.kuendigung.value.startsWith('tarif:') ? f.kuendigung.value.slice(6) : null,
          kuendigungsfrist: f.kuendigung.value === 'individuell' ? (f.kuendigungsfrist.value.trim() || null) : null,
          urlaubstage: u === '' ? null : u, lohnbuch: f.lohnbuch.value || null } });
        k.melden('Fassung angelegt.', 'erfolg');
        await laden();
      } });
    rechnen();
    befristung();
    wortlaut();
  }

  function loeschDialog() {
    if (!akte) return;
    const objekte = [['person', akte.person.id, 'Stamm der Person', akte.person.loeschen_ab],
      ...(akte.privat ? [['privat', akte.person.id, 'Private Angaben', akte.privat.loeschen_ab]] : []),
      ...(akte.vertraege || []).map((v) => ['vertrag', v.id, `Vertrag, Fassung ab ${tag(v.gueltig_ab)}`, v.loeschen_ab]),
      ...akte.fristen.filter((x) => x.id).map((x) => ['frist', x.id, `Frist ${x.art_name}, fällig ${tag(x.faellig)}`, x.loeschen_ab]),
      ...(akte.vorschlaege || []).map((v) => ['vorschlag', v.id, `Änderungsvorschlag vom ${zeit(v.angelegt_am)}`, v.loeschen_ab]),
      ...(akte.abwesenheiten || []).map((x) => ['abwesenheit', x.id, `Abwesenheit ${x.art_name}, ${tag(x.von)}–${tag(x.bis)}`, x.loeschen_ab])];
    const objekt = auswahl(objekte.map((o, i) => [String(i), `${o[2]} – ${o[3] ? `löschen ab ${tag(o[3])}` : 'Frist fehlt'}`]), '0', { id: 'pe-l-objekt' });
    const ab = datumFeld({ id: 'pe-l-ab', value: objekte[0][3] || '' });
    objekt.addEventListener('change', () => ab.setzen(objekte[Number(objekt.value)][3] || ''));
    const grund = h('input', { id: 'pe-l-grund', type: 'text', required: true, maxlength: '200', size: '48', autocomplete: 'off' });
    dialog({ id: 'pe-dialog-loesch', titel: `Löschfrist – ${akte.person.name}`, ja: 'Festlegen', breit: true,
      text: 'Jedes Objekt trägt seine eigene Frist. kumero gibt keine vor und löscht nichts von selbst: Die Frist sagt, ab wann das Objekt zum Löschen ansteht.',
      felder: [feld('Objekt', objekt), feld('Löschen ab (leer = Frist zurücknehmen)', ab.el), feld('Begründung', grund)],
      senden: async () => {
        const w = ab.wert();
        if (w === null) throw new Error('Das Datum ist nicht lesbar');
        const o = objekte[Number(objekt.value)];
        await k.api('PUT', `${api}/loeschfrist`, { json: { objektart: o[0], id: o[1], loeschen_ab: w || null, grund: grund.value.trim() } });
        k.melden('Löschfrist festgelegt.', 'erfolg');
        await laden();
      } });
  }

  function entscheiden(v, uebernehmen) {
    if (uebernehmen) {
      if (!d.darf.privat) { k.melden('Übernehmen darf nur, wer private Angaben sehen darf.', 'warnung'); return; }
      k.api('POST', `${api}/vorschlaege/${v.id}/entscheidung`, { json: { uebernehmen: true } })
        .then(() => { k.melden('Vorschlag übernommen.', 'erfolg'); return laden(); }).catch((x) => k.fehler(x));
      return;
    }
    const grund = h('input', { id: 'pe-a-grund', type: 'text', required: true, maxlength: '200', size: '48', autocomplete: 'off' });
    dialog({ id: 'pe-dialog-ablehnen', titel: 'Vorschlag ablehnen', ja: 'Ablehnen', text: 'Die Person sieht den Grund unter „Meine Daten“.', felder: [feld('Grund', grund)],
      senden: async () => {
        await k.api('POST', `${api}/vorschlaege/${v.id}/entscheidung`, { json: { uebernehmen: false, grund: grund.value.trim() } });
        k.melden('Vorschlag abgelehnt.', 'erfolg');
        await laden();
      } });
  }

  suche.addEventListener('input', () => { if (filter) zeichnen(); });
  b.append(seitenkopf(`Personen – ${k.mandantName || k.mandant}`),
    h('div', { class: 'pe-leiste nicht-drucken' }, kn.neu, kn.aendern, kn.vertrag, kn.regeln, kn.loesch, h('span', { class: 'pe-luecke' }), suche), stand,
    teiler(liste, unten, { id: 'pe-teilung', hoehe: 300 }));
  akteZeichnen();
  await laden();
}
