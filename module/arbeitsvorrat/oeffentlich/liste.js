// SPDX-License-Identifier: AGPL-3.0-or-later
// Arbeitsvorrat (ARBEITSVORRAT.md Abschnitt 5): der Posteingang der Buchhaltung. Die Liste zeigt, was noch
// zu buchen ist – unabhängig von der Quelle. Enter öffnet die vorbefüllte Buchungserfassung des Moduls buchen.
import { h, seitenkopf, knopf, auswahl, datumFeld, datumText, zeit } from '/kern/ui/bausteine.js';
import { tabellenfilter } from '/kern/ui/tabellenfilter.js';
import { belegarchive, archivZuLink, belegSchwebe, belegVorschau, belegAuge } from '/kern/ui/beleg.js';

const zahl = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const betragZeigen = (dez) => (dez ? zahl.format(Number(dez)) : '');
const LINK = /^https?:\/\/[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:[0-9]{1,5})?(\/[^\s?#]*)?$/;

/** Betrag aus der Eingabe: Komma oder Punkt als Dezimalzeichen, Tausenderpunkte erlaubt. */
function betragLesen(text) {
  const s = text.trim().replace(/\s/g, '');
  if (s === '') return '';
  const m = /^(\d{1,3}(?:\.\d{3})+|\d+)(?:,(\d{1,2}))?$/.exec(s) || /^(\d+)(?:\.(\d{1,2}))?$/.exec(s);
  if (!m) return null;
  const w = `${m[1].replace(/\./g, '')}.${(m[2] || '').padEnd(2, '0')}`;
  return Number(w) > 0 ? w : null;
}

// Abgleich mit den Quellen höchstens alle fünf Minuten je Mandant (PAPERLESS.md L6); der Knopf „Jetzt abgleichen“ geht immer
const LETZTER_ABGLEICH = new Map();
const ABGLEICH_FRIST = 5 * 60 * 1000;

export async function zeigen(b, k) {
  const css = new URL('./vorrat.css', import.meta.url).href;
  if (!document.querySelector(`link[href="${css}"]`)) document.head.append(h('link', { rel: 'stylesheet', href: css }));
  const t = k.t;
  const basis = `${k.apiBasis}/arbeitsvorrat`;
  const darfAnlegen = k.darf('arbeitsvorrat.eintrag.anlegen');
  const darfBearbeiten = k.darf('arbeitsvorrat.eintrag.bearbeiten');
  const darfBuchen = darfBearbeiten && k.darf('kern.entwurf.anlegen');
  const erfassen = (param) => k.navigieren(`#/m/${k.mandant}/buchen/erfassen?${param}`);

  // Stammdaten für die Vorauswahl (X7); ohne Leserecht bleiben die Spalten leer
  let konten = [];
  let buchungsarten = [];
  try { [konten, buchungsarten] = await Promise.all([k.api('GET', `${k.apiBasis}/konten`), k.api('GET', `${k.apiBasis}/buchungsarten`)]); } catch { /* dann ohne Vorschläge */ }
  let steuersaetze = new Map();
  try { steuersaetze = new Map((await k.api('GET', `${k.apiBasis}/buchen/steuerkonten`)).steuerkonten.map((y) => [y.konto, Number(y.satz)])); } catch { /* dann ohne Steuerrechnung */ }
  const kontoName = (nr) => konten.find((x) => x.nr === nr)?.bezeichnung || '';
  const kontenliste = h('datalist', { id: 'vorrat-konten' }, konten.filter((x) => x.aktiv).map((x) => h('option', { value: x.nr }, `${x.nr} ${x.bezeichnung}`)));
  const archive = await belegarchive(k);
  const schwebe = belegSchwebe(k);
  const markiert = new Set();
  let eintraege = [];
  let quellen = [];
  let gewaehlt = null;
  const titelQuelle = (name) => quellen.find((q) => q.name === name)?.titel || name;

  const ansicht = auswahl(['offen', 'in_arbeit', 'gebucht', 'verworfen'].map((z) => [z, t(`zustand.${z}`)]),
    ['offen', 'in_arbeit', 'gebucht', 'verworfen'].includes(k.param.ansicht) ? k.param.ansicht : 'offen',
    { id: 'vorrat-ansicht', 'aria-label': t('filter.ansicht'), title: t('hilfe.ansicht') });
  const quelle = auswahl([['', t('filter.alle_quellen')]], '', { id: 'vorrat-quelle', 'aria-label': t('filter.quelle'), title: t('hilfe.quelle') });
  const suche = h('input', { id: 'vorrat-suche', type: 'search', placeholder: t('filter.suche'), title: t('hilfe.suche') });
  const tbody = h('tbody');
  // Sortieren und Filtern je Spalte (F91): Wert finden oder ausschließen
  const filter = tabellenfilter([
    { id: 'markierung', titel: '', fest: true },
    { id: 'beleg', titel: t('spalte.beleg'), fest: true },
    { id: 'eingang', titel: t('spalte.eingang'), wert: (x) => zeit(x.eingegangen_am), sortwert: (x) => x.eingegangen_am },
    { id: 'quelle', titel: t('spalte.quelle'), wert: (x) => x.quellen.map(titelQuelle).join(', ') },
    { id: 'art', titel: t('spalte.art'), wert: (x) => x.art },
    { id: 'betreff', titel: t('spalte.betreff'), wert: (x) => x.betreff },
    { id: 'gegenpartei', titel: t('spalte.gegenpartei'), wert: (x) => x.gegenpartei },
    { id: 'belegnummer', titel: t('spalte.belegnummer'), wert: (x) => x.belegnummer },
    { id: 'datum', titel: t('spalte.datum'), wert: (x) => datumText(x.datum), sortwert: (x) => x.datum || '' },
    { id: 'betrag', titel: t('spalte.betrag'), klasse: 'zahl', wert: (x) => betragZeigen(x.betrag), sortwert: (x) => Number(x.betrag || 0) },
    { id: 'konto', titel: t('spalte.konto'), wert: (x) => x.vor_konto },
    { id: 'gegenkonto', titel: t('spalte.gegenkonto'), wert: (x) => x.vor_gegenkonto },
    { id: 'hinweise', titel: t('spalte.hinweise'), fest: true },
  ], () => zeichnen());
  const fuss = h('div', { class: 'vorrat-fuss', id: 'vorrat-fuss' });
  const hinweisQuellen = h('p', { class: 'hinweis', id: 'vorrat-ausgefallen', hidden: true });

  const stand = h('span', { class: 'hinweis vorrat-stand', id: 'vorrat-stand', role: 'status' });
  const knAbgleich = knopf(t('aktion.abgleichen'), () => quellenAbgleichen(true), 'klein', { id: 'vorrat-abgleichen', title: t('hilfe.abgleichen') });
  const kn = {
    buchen: darfBuchen ? knopf(t('aktion.buchen'), buchen, 'primaer', { id: 'vorrat-buchen', title: t('hilfe.buchen') }) : null,
    neu: darfAnlegen ? knopf(t('aktion.neu'), neu, '', { id: 'vorrat-neu', title: t('hilfe.neu') }) : null,
    verwerfen: darfBearbeiten ? knopf(t('aktion.verwerfen'), verwerfen, '', { id: 'vorrat-verwerfen', title: t('hilfe.verwerfen') }) : null,
    oeffnen: darfBearbeiten ? knopf(t('aktion.oeffnen'), wiederOeffnen, '', { id: 'vorrat-oeffnen', title: t('hilfe.oeffnen') }) : null,
    beleg: knopf(t('aktion.beleg'), () => { const x = aktuell(); if (!x?.belege[0]) return; if (archivZuLink(archive, x.belege[0].link)) belegVorschau(k, x.belege[0].link); else window.open(x.belege[0].link, '_blank', 'noopener,noreferrer'); },
      '', { id: 'vorrat-beleg', title: t('hilfe.beleg') }),
    alle: darfBearbeiten ? knopf(t('aktion.alle_markieren'), alleMarkieren, '', { id: 'vorrat-alle', title: t('hilfe.alle_markieren') }) : null,
    vorbelegen: darfBearbeiten ? knopf(t('aktion.vorbelegen'), vorbelegen, '', { id: 'vorrat-vorbelegen', title: t('hilfe.vorbelegen') }) : null,
    leeren: darfBearbeiten ? knopf(t('aktion.leeren'), vorauswahlLeeren, '', { id: 'vorrat-leeren', title: t('hilfe.leeren') }) : null,
    stapel: darfBuchen ? knopf(t('aktion.stapel'), inDenStapel, '', { id: 'vorrat-stapel', title: t('hilfe.stapel') }) : null,
  };

  const aktuell = () => eintraege.find((x) => x.id === gewaehlt) || null;
  const sichtbar = () => filter.anwenden(gesucht());
  const gesucht = () => {
    const s = suche.value.trim().toLowerCase();
    if (!s) return eintraege;
    return eintraege.filter((x) => [x.betreff, x.gegenpartei, x.belegnummer, x.art, betragZeigen(x.betrag), datumText(x.datum)]
      .some((w) => (w || '').toLowerCase().includes(s)));
  };

  function knoepfe() {
    const x = aktuell();
    if (kn.buchen) {
      kn.buchen.disabled = !x || !['offen', 'in_arbeit', 'gebucht'].includes(x.zustand);
      kn.buchen.textContent = t(x && x.zustand !== 'offen' ? 'aktion.ansehen' : 'aktion.buchen');
    }
    if (kn.verwerfen) kn.verwerfen.disabled = !x || x.zustand !== 'offen';
    if (kn.oeffnen) kn.oeffnen.disabled = !x || x.zustand !== 'verworfen';
    kn.beleg.disabled = !x || !x.belege.length;
    // Aktionen gelten für die markierten Einträge – ohne Haken für die gewählte Zeile
    const n = [...markiert].filter((id) => eintraege.some((e) => e.id === id && e.zustand === 'offen')).length;
    const eine = n === 0 && x?.zustand === 'offen';
    if (kn.vorbelegen) { kn.vorbelegen.disabled = n === 0 && !eine; kn.vorbelegen.textContent = n ? t('aktion.vorbelegen_n', { n }) : t('aktion.vorbelegen'); }
    if (kn.leeren) { kn.leeren.disabled = n === 0 && !eine; kn.leeren.textContent = n ? t('aktion.leeren_n', { n }) : t('aktion.leeren'); }
    if (kn.stapel) { kn.stapel.disabled = n === 0 && !eine; kn.stapel.textContent = n ? t('aktion.stapel_n', { n }) : t('aktion.stapel'); }
    if (kn.alle) kn.alle.disabled = ansicht.value !== 'offen' || !eintraege.length;
  }

  function waehlen(id, fokus = true) {
    gewaehlt = id;
    for (const tr of tbody.querySelectorAll('tr[data-id]')) {
      const an = tr.dataset.id === id;
      tr.classList.toggle('gewaehlt', an);
      if (an && fokus) tr.focus();
    }
    knoepfe();
  }

  /** Vorauswahl eines Eintrags aus der Zeile speichern (X7): Vorbelegung, keine Buchung. */
  async function vorauswahl(x, feld, ein) {
    const w = ein.value.trim();
    if (w === (x[`vor_${feld}`] || '')) return;
    if (w && !konten.some((y) => y.nr === w)) { ein.classList.add('ungueltig'); ein.title = t('hinweis.konto_unbekannt'); return; }
    ein.classList.remove('ungueltig');
    try {
      await k.api('PUT', `${basis}/eintraege/vorauswahl`, { json: { eintraege: [x.id], [feld]: w || null } });
      x[`vor_${feld}`] = w || null;
      ein.title = kontoName(w);
    } catch (e) { k.fehler(e); ein.value = x[`vor_${feld}`] || ''; }
  }

  function zeichnen() {
    filter.setzeZeilen(gesucht());
    const zeilen = sichtbar();
    const offen = ansicht.value === 'offen';
    const kontoFeld = (x, feld) => {
      if (!offen || !darfBearbeiten) return x[`vor_${feld}`] || '';
      const ein = h('input', { class: 'vorrat-konto', list: 'vorrat-konten', maxlength: '20', value: x[`vor_${feld}`] || '', autocomplete: 'off', 'data-feld': feld,
        'aria-label': t(`spalte.${feld}`), title: kontoName(x[`vor_${feld}`]) || t(`hilfe.${feld}`) });
      ein.addEventListener('change', () => vorauswahl(x, feld, ein));
      ein.addEventListener('click', (ev) => ev.stopPropagation());
      ein.addEventListener('keydown', (ev) => { if (ev.key !== 'Escape') ev.stopPropagation(); });
      return ein;
    };
    tbody.replaceChildren(...zeilen.map((x) => {
      const link = x.belege[0]?.link;
      const mitVorschau = !!archivZuLink(archive, link);
      const tr = h('tr', { tabindex: '-1', 'data-id': x.id, class: x.id === gewaehlt ? 'gewaehlt' : '' },
        h('td', { class: 'eng' }, offen && darfBearbeiten ? h('input', { type: 'checkbox', class: 'vorrat-marke', checked: markiert.has(x.id), 'aria-label': t('liste.markieren'), tabindex: '-1',
          onclick: (ev) => ev.stopPropagation(), onchange: (ev) => { if (ev.target.checked) markiert.add(x.id); else markiert.delete(x.id); knoepfe(); fussZeigen(); } }) : null),
        h('td', { class: 'eng vorrat-belegzelle' }, mitVorschau ? belegAuge(k, link, schwebe) : null,
          link ? h('a', { href: link, target: '_blank', rel: 'noopener noreferrer', class: 'vorrat-beleg', title: t('hilfe.beleg'), 'aria-label': t('aktion.beleg'), onclick: (ev) => ev.stopPropagation() }, '↗') : null),
        h('td', { class: 'eng' }, zeit(x.eingegangen_am)),
        h('td', { class: 'eng' }, x.quellen.map(titelQuelle).join(', ')),
        h('td', { class: 'eng' }, x.art || ''),
        h('td', {}, h('span', { class: 'einzeilig', title: x.verworfen_grund ? t('liste.grund', { grund: x.verworfen_grund }) : x.betreff }, x.betreff)),
        h('td', {}, h('span', { class: 'einzeilig' }, x.gegenpartei || '')),
        h('td', { class: 'eng' }, x.belegnummer || ''),
        h('td', { class: 'eng' }, datumText(x.datum)),
        h('td', { class: 'zahl' }, betragZeigen(x.betrag), x.richtung === 'einnahme' ? h('span', { class: 'marke-klein korrigiert', title: t('hilfe.einnahme') }, t('liste.einnahme')) : null),
        h('td', { class: 'eng' }, kontoFeld(x, 'konto')),
        h('td', { class: 'eng' }, kontoFeld(x, 'gegenkonto')),
        h('td', {},         (x.hinweise.length + x.vermerke.length) ? h('span', { class: 'marke-klein warnung', title: [...x.hinweise.map(hinweisText), ...x.vermerke.map((v) => v.text)].join('\n') },
          t('liste.hinweise', { n: x.hinweise.length + x.vermerke.length })) : null));
      tr.addEventListener('click', (ev) => { waehlen(x.id); if (ev.detail === 2) buchen(); });
      return tr;
    }));
    if (!zeilen.length) {
      tbody.append(h('tr', { class: 'leer' }, h('td', { colspan: '13' }, t(eintraege.length ? 'liste.nichts_gefunden' : `liste.leer_${ansicht.value}`))));
    }
    if (!zeilen.some((x) => x.id === gewaehlt)) gewaehlt = zeilen[0]?.id || null;
    waehlen(gewaehlt, false);
    fussZeigen();
  }

  function fussZeigen() {
    const zeilen = sichtbar();
    const summe = zeilen.reduce((n, x) => n + (x.betrag ? Math.round(Number(x.betrag) * 100) : 0), 0);
    fuss.textContent = t('liste.fuss', { n: zeilen.length, summe: zahl.format(summe / 100) })
      + (zeilen.length !== eintraege.length ? ` · ${t('liste.von', { n: eintraege.length })}` : '') + (markiert.size ? ` · ${t('liste.markiert', { n: markiert.size })}` : '');
  }

  /** Massenauswahl: alle gerade sichtbaren (gefilterten) Einträge markieren bzw. die Markierung aufheben. */
  function alleMarkieren() {
    const ids = sichtbar().filter((x) => x.zustand === 'offen').map((x) => x.id);
    const schonAlle = ids.length > 0 && ids.every((id) => markiert.has(id));
    ids.forEach((id) => { if (schonAlle) markiert.delete(id); else markiert.add(id); });
    zeichnen();
  }

  /** Einträge der Aktion: die markierten offenen – ohne Haken die gewählte Zeile. */
  const gewaehlteOffene = () => {
    const m = eintraege.filter((x) => markiert.has(x.id) && x.zustand === 'offen');
    if (m.length) return m;
    const x = aktuell();
    return x && x.zustand === 'offen' ? [x] : [];
  };

  /** Vorauswahl (Konto, Gegenkonto, Steuerkonto, Buchungsart) der Auswahl wieder leeren. */
  async function vorauswahlLeeren() {
    const liste = gewaehlteOffene().filter((x) => x.vor_konto || x.vor_gegenkonto || x.vor_steuerkonto || x.vor_buchungsart);
    if (!liste.length) { k.melden(t('meldung.nichts_zu_leeren'), 'warnung'); return; }
    try {
      const r = await k.api('PUT', `${basis}/eintraege/vorauswahl`, { json: { eintraege: liste.map((x) => x.id), konto: null, gegenkonto: null, steuerkonto: null, buchungsart: null } });
      k.melden(t('meldung.geleert', { n: r.geaendert }), 'erfolg');
      await laden();
    } catch (e) { k.fehler(e); }
  }

  /** Abgleich mit den Quellen im Hintergrund: Die Liste steht sofort da und wird danach still aufgefrischt. */
  let abgleichLaeuft = false;
  async function quellenAbgleichen(erzwingen = false) {
    const zuletzt = LETZTER_ABGLEICH.get(k.mandant) || 0;
    if (abgleichLaeuft || (!erzwingen && Date.now() - zuletzt < ABGLEICH_FRIST)) { standZeigen(); return; }
    abgleichLaeuft = true;
    standZeigen();
    try {
      const r = await k.api('POST', `${basis}/abgleich`, { json: {} });
      LETZTER_ABGLEICH.set(k.mandant, Date.now());
      hinweisQuellen.hidden = !r.quellen_ausgefallen.length;
      hinweisQuellen.textContent = r.quellen_ausgefallen.length ? t('liste.ausgefallen', { quellen: r.quellen_ausgefallen.map(titelQuelle).join(', ') }) : '';
      stand.title = Object.entries(r.dauer_ms).map(([n, ms]) => `${titelQuelle(n)}: ${(ms / 1000).toFixed(1)} s`).join(' · ');
      abgleichLaeuft = false;
      if (b.isConnected) await laden();
    } catch (e) { abgleichLaeuft = false; k.fehler(e); }
    standZeigen();
  }
  function standZeigen() {
    const zuletzt = LETZTER_ABGLEICH.get(k.mandant);
    stand.textContent = abgleichLaeuft ? t('abgleich.laeuft') : (zuletzt ? t('abgleich.stand', { zeit: new Date(zuletzt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }) : '');
    stand.classList.toggle('laeuft', abgleichLaeuft);
    knAbgleich.disabled = abgleichLaeuft;
  }

  /** Stapelvorbelegung (X1): Konto, Gegenkonto und Buchungsart für alle markierten Einträge auf einmal. */
  function vorbelegen() {
    const liste = gewaehlteOffene();
    if (!liste.length) return;
    const f = {
      konto: h('input', { id: 'stapel-konto', class: 'schmal', list: 'vorrat-konten', maxlength: '20', autocomplete: 'off', title: t('hilfe.konto') }),
      gegenkonto: h('input', { id: 'stapel-gegenkonto', class: 'schmal', list: 'vorrat-konten', maxlength: '20', autocomplete: 'off', title: t('hilfe.gegenkonto') }),
      steuerkonto: h('input', { id: 'stapel-steuerkonto', class: 'schmal', list: 'vorrat-konten', maxlength: '20', autocomplete: 'off', title: t('hilfe.steuerkonto') }),
      buchungsart: auswahl([['', t('stapel.unveraendert')], ...buchungsarten.map((a) => [a.kuerzel, `${a.kuerzel} – ${a.bezeichnung}`])], '', { id: 'stapel-buchungsart', class: 'schmal' }),
    };
    const fehler = h('p', { class: 'fehler-text', role: 'alert' });
    const zeile = (id, text, el) => [h('label', { for: id }, text), h('div', {}, el)];
    const d = h('dialog', { class: 'dialog vorrat-neu', id: 'stapel-dialog' }, h('h2', {}, t('stapel.titel', { n: liste.length })), h('p', { class: 'hinweis' }, t('stapel.erklaerung')),
      h('form', { onsubmit: async (ev) => {
        ev.preventDefault();
        const daten = { eintraege: liste.map((x) => x.id) };
        for (const feld of ['konto', 'gegenkonto', 'steuerkonto']) {
          const w = f[feld].value.trim();
          if (w && !konten.some((y) => y.nr === w)) { fehler.textContent = t('hinweis.konto_unbekannt'); f[feld].focus(); return; }
          if (w) daten[feld] = w;
        }
        if (f.buchungsart.value) daten.buchungsart = f.buchungsart.value;
        if (Object.keys(daten).length === 1) { fehler.textContent = t('stapel.nichts'); return; }
        try {
          const r = await k.api('PUT', `${basis}/eintraege/vorauswahl`, { json: daten });
          d.close();
          k.melden(t('meldung.vorbelegt', { n: r.geaendert }), 'erfolg');
          await laden();
        } catch (e) { fehler.textContent = e.message; }
      } }, h('div', { class: 'raster' }, zeile('stapel-konto', t('spalte.konto'), f.konto), zeile('stapel-gegenkonto', t('spalte.gegenkonto'), f.gegenkonto), zeile('stapel-steuerkonto', t('feld.steuerkonto'), f.steuerkonto),
        zeile('stapel-buchungsart', t('feld.buchungsart'), f.buchungsart)), fehler,
      h('div', { class: 'dialog-knoepfe' }, knopf(t('aktion.abbrechen'), () => d.close()), h('button', { type: 'submit', class: 'knopf primaer', id: 'stapel-ja' }, t('aktion.vorbelegen')))));
    d.addEventListener('close', () => d.remove());
    document.body.append(d);
    d.showModal();
    f.konto.focus();
  }

  /**
   * Übergabe in die Stapelbuchung (X1a, X7): Aus jedem markierten, vollständig vorbelegten Eintrag wird ein
   * freigabebereiter Entwurf im Erfassungsstapel. Festgeschrieben wird dort, mit der Stapelfreigabe (F38).
   * Was unvollständig ist, bleibt im Vorrat und wird genannt.
   */
  /**
   * Zeilen des Entwurfs (X2, F101): Mit Steuerkonto eine Splitbuchung – Netto und Steuer vom Beleg, wenn sie den
   * Bruttobetrag ergeben, sonst aus dem Steuersatz des Kontos gerechnet (Steuer = Brutto − Brutto/(1+Satz)).
   * Die Steuer steht auf der Seite des Erfolgskontos: Ertrag im Haben → Umsatzsteuer im Haben, sonst Vorsteuer im Soll.
   */
  function zeilenFuer(x) {
    const brutto = Math.round(Number(x.betrag) * 100);
    const dez = (c) => (c / 100).toFixed(2);
    let steuer = null;
    if (x.vor_steuerkonto) {
      const vomBeleg = x.netto && x.steuer && Number(x.steuer) > 0 && Math.round((Number(x.netto) + Number(x.steuer)) * 100) === brutto;
      const satz = steuersaetze.get(x.vor_steuerkonto);
      if (vomBeleg) steuer = Math.round(Number(x.steuer) * 100);
      else if (satz) steuer = brutto - Math.round(brutto / (1 + satz / 100));
    }
    if (!steuer || steuer >= brutto) return [{ konto: x.vor_konto, seite: 'S', betrag: x.betrag }, { konto: x.vor_gegenkonto, seite: 'H', betrag: x.betrag }];
    const imHaben = konten.find((y) => y.nr === x.vor_gegenkonto)?.kontoart === 'ertrag' && konten.find((y) => y.nr === x.vor_konto)?.kontoart !== 'aufwand';
    return imHaben
      ? [{ konto: x.vor_konto, seite: 'S', betrag: dez(brutto) }, { konto: x.vor_gegenkonto, seite: 'H', betrag: dez(brutto - steuer) }, { konto: x.vor_steuerkonto, seite: 'H', betrag: dez(steuer) }]
      : [{ konto: x.vor_konto, seite: 'S', betrag: dez(brutto - steuer) }, { konto: x.vor_steuerkonto, seite: 'S', betrag: dez(steuer) }, { konto: x.vor_gegenkonto, seite: 'H', betrag: dez(brutto) }];
  }

  async function inDenStapel() {
    const liste = gewaehlteOffene();
    if (!liste.length) return;
    const art = (x) => x.vor_buchungsart || buchungsarten[0]?.kuerzel || null;
    const buchungsdatum = (x) => (x.zahlweise === 'unbar' ? x.zahltag : x.datum);
    const fehlt = (x) => [!x.vor_konto && t('spalte.konto'), !x.vor_gegenkonto && t('spalte.gegenkonto'), !x.betrag && t('spalte.betrag'), !x.datum && t('spalte.datum'),
      x.datum && !buchungsdatum(x) && t('stapel.geldfluss'), !art(x) && t('feld.buchungsart')].filter(Boolean);
    let uebergeben = 0;
    const liegen = [];
    for (const kn_ of Object.values(kn)) if (kn_) kn_.disabled = true;
    for (const x of liste) {
      const f = fehlt(x);
      if (f.length) { liegen.push(`${x.betreff} (${f.join(', ')})`); continue; }
      try {
        const b = await k.api('POST', `${k.apiBasis}/buchungen`, { json: { buchungsart: art(x), belegdatum: x.datum, buchungsdatum: buchungsdatum(x),
          belegnummer: x.belegnummer || null, text: [x.gegenpartei, x.gegenpartei ? x.belegnummer : null].filter(Boolean).join(' ') || x.betreff,
          belege: x.belege.map((y) => (y.md5 ? { link: y.link, md5: y.md5, sha256: y.sha256 } : { link: y.link })),
          zeilen: zeilenFuer(x), vorbereiten: true } });
        await k.api('POST', `${basis}/eintraege/${x.id}/buchung`, { json: { buchung: b.id } });
        markiert.delete(x.id);
        uebergeben++;
      } catch (e) { liegen.push(`${x.betreff} (${e.message})`); }
    }
    await laden();
    k.melden(t('meldung.stapel', { n: uebergeben }) + (liegen.length ? ` ${t('meldung.stapel_liegen', { n: liegen.length, liste: liegen.slice(0, 5).join('; ') })}` : ''), liegen.length ? 'warnung' : 'erfolg');
  }

  function hinweisText(x) {
    if (x.abweichend !== undefined) return t('liste.abweichend', { quelle: titelQuelle(x.quelle), feld: t(`feld.${x.feld}`), wert: x.abweichend });
    return x.text;
  }

  async function laden() {
    try {
      const q = new URLSearchParams({ zustand: ansicht.value, abgleich: 'nein' });
      if (quelle.value) q.set('quelle', quelle.value);
      const r = await k.api('GET', `${basis}/eintraege?${q}`);
      eintraege = r.eintraege;
      for (const id of [...markiert]) if (!eintraege.some((x) => x.id === id)) markiert.delete(id);
      quellen = r.quellen;
      const wahl = quelle.value;
      quelle.replaceChildren(h('option', { value: '' }, t('filter.alle_quellen')), ...quellen.map((x) => h('option', { value: x.name }, x.titel)));
      quelle.value = wahl;
      zeichnen();
      if (r.mehr) k.melden(t('liste.mehr'), 'warnung');
      k.menueNeuLaden?.();
    } catch (e) { k.fehler(e); }
  }

  function buchen() {
    const x = aktuell();
    if (!x || !darfBuchen) return;
    if (x.zustand === 'offen') erfassen(`vorrat=${x.id}`);
    else if (x.buchungen.length) erfassen(`id=${x.buchungen[x.buchungen.length - 1].id}`);
  }

  /** Eintrag von Hand (W9): Betreff ist Pflicht, alles andere freiwillig. */
  function neu() {
    const f = {
      betreff: h('input', { id: 'neu-betreff', maxlength: '200', title: t('hilfe.betreff') }),
      art: h('input', { id: 'neu-art', class: 'schmal', maxlength: '40', list: 'neu-arten', placeholder: 'rechnung', title: t('hilfe.art') }),
      richtung: auswahl([['ausgabe', t('richtung.ausgabe')], ['einnahme', t('richtung.einnahme')]], 'ausgabe', { id: 'neu-richtung', class: 'schmal', title: t('hilfe.richtung') }),
      gegenpartei: h('input', { id: 'neu-gegenpartei', maxlength: '200', title: t('hilfe.gegenpartei') }),
      datum: datumFeld({ id: 'neu-datum', title: t('hilfe.datum') }, { kalenderTitel: t('hilfe.kalender'), ungueltig: t('hinweis.datum') }),
      betrag: h('input', { id: 'neu-betrag', class: 'schmal', inputmode: 'decimal', autocomplete: 'off', title: t('hilfe.betrag') }),
      zahlweise: auswahl([['', t('zahlweise.offen')], ['bar', t('zahlweise.bar')], ['unbar', t('zahlweise.unbar')]], '', { id: 'neu-zahlweise', class: 'schmal', title: t('hilfe.zahlweise') }),
      belegnummer: h('input', { id: 'neu-belegnummer', class: 'schmal', maxlength: '36', title: t('hilfe.belegnummer') }),
      link: h('input', { id: 'neu-link', type: 'url', maxlength: '500', placeholder: 'https://…/documents/…/', spellcheck: false, title: t('hilfe.link') }),
      notiz: h('textarea', { id: 'neu-notiz', rows: '2', maxlength: '1000', title: t('hilfe.notiz') }),
    };
    const fehler = h('p', { class: 'fehler-text', role: 'alert' });
    const zeile = (id, text, el) => [h('label', { for: id }, text), h('div', {}, el)];
    const arten = h('datalist', { id: 'neu-arten' }, [...new Set(['rechnung', 'gutschrift', 'kassenbon', ...eintraege.map((x) => x.art).filter(Boolean)])].map((x) => h('option', { value: x })));
    const speichern = async () => {
      fehler.textContent = '';
      const betrag = betragLesen(f.betrag.value);
      const datum = f.datum.wert();
      const link = f.link.value.trim().replace(/^([a-z]+:\/\/[^/]*)/i, (x) => x.toLowerCase());
      const art = f.art.value.trim().toLowerCase();
      if (!f.betreff.value.trim()) { fehler.textContent = t('hinweis.betreff'); f.betreff.focus(); return; }
      if (art && !/^[a-z][a-z0-9_-]{0,39}$/.test(art)) { fehler.textContent = t('hinweis.art'); f.art.focus(); return; }
      if (betrag === null) { fehler.textContent = t('hinweis.betrag'); f.betrag.focus(); return; }
      if (datum === null) { fehler.textContent = t('hinweis.datum'); f.datum.ein.focus(); return; }
      if (link && !LINK.test(link)) { fehler.textContent = t('hinweis.link'); f.link.focus(); return; }
      const daten = { betreff: f.betreff.value.trim(), richtung: f.richtung.value };
      if (art) daten.art = art;
      if (f.gegenpartei.value.trim()) daten.gegenpartei = f.gegenpartei.value.trim();
      if (datum) daten.datum = datum;
      if (betrag) daten.betrag = betrag;
      if (f.zahlweise.value) daten.zahlweise = f.zahlweise.value;
      if (f.belegnummer.value.trim()) daten.belegnummer = f.belegnummer.value.trim();
      if (link) daten.belege = [{ link }];
      if (f.notiz.value.trim()) daten.notiz = f.notiz.value.trim();
      try {
        const r = await k.api('POST', `${basis}/eintraege`, { json: daten });
        d.close();
        k.melden(t('meldung.angelegt'), 'erfolg');
        ansicht.value = 'offen';
        gewaehlt = r.id;
        await laden();
        waehlen(r.id);
      } catch (e) { fehler.textContent = e.message; }
    };
    const d = h('dialog', { class: 'dialog vorrat-neu', id: 'vorrat-dialog' }, h('h2', {}, t('neu.titel')),
      h('form', { onsubmit: (ev) => { ev.preventDefault(); speichern(); } },
        h('div', { class: 'raster' }, zeile('neu-betreff', t('feld.betreff'), f.betreff), zeile('neu-art', t('feld.art'), [f.art, arten]),
          zeile('neu-richtung', t('feld.richtung'), f.richtung), zeile('neu-gegenpartei', t('feld.gegenpartei'), f.gegenpartei),
          zeile('neu-datum', t('feld.datum'), f.datum.el), zeile('neu-betrag', t('feld.betrag'), f.betrag),
          zeile('neu-zahlweise', t('feld.zahlweise'), f.zahlweise), zeile('neu-belegnummer', t('feld.belegnummer'), f.belegnummer),
          zeile('neu-link', t('feld.link'), f.link), zeile('neu-notiz', t('feld.notiz'), f.notiz)),
        fehler,
        h('div', { class: 'dialog-knoepfe' }, knopf(t('aktion.abbrechen'), () => d.close()), h('button', { type: 'submit', class: 'knopf primaer', id: 'neu-speichern' }, t('aktion.anlegen')))));
    d.addEventListener('close', () => d.remove());
    document.body.append(d);
    d.showModal();
    f.betreff.focus();
  }

  /** Verwerfen (kein Buchungsfall) verlangt einen Grund; der Eintrag bleibt unter „verworfen“ sichtbar. */
  function verwerfen() {
    const x = aktuell();
    if (!x || x.zustand !== 'offen') return;
    const grund = h('input', { id: 'verwerfen-grund', maxlength: '300', placeholder: t('verwerfen.beispiel') });
    const fehler = h('p', { class: 'fehler-text', role: 'alert' });
    const d = h('dialog', { class: 'dialog vorrat-neu' }, h('h2', {}, t('verwerfen.titel')), h('p', {}, t('verwerfen.frage', { betreff: x.betreff })),
      h('form', { onsubmit: async (ev) => {
        ev.preventDefault();
        if (!grund.value.trim()) { fehler.textContent = t('verwerfen.grund_fehlt'); return; }
        try {
          await k.api('POST', `${basis}/eintraege/${x.id}/verwerfen`, { json: { grund: grund.value.trim() } });
          d.close();
          k.melden(t('meldung.verworfen'), 'erfolg');
          await laden();
        } catch (e) { fehler.textContent = e.message; }
      } }, h('div', { class: 'raster' }, h('label', { for: 'verwerfen-grund' }, t('verwerfen.grund')), h('div', {}, grund)), fehler,
      h('div', { class: 'dialog-knoepfe' }, knopf(t('aktion.abbrechen'), () => d.close()), h('button', { type: 'submit', class: 'knopf primaer', id: 'verwerfen-ja' }, t('aktion.verwerfen')))));
    d.addEventListener('close', () => d.remove());
    document.body.append(d);
    d.showModal();
    grund.focus();
  }

  async function wiederOeffnen() {
    const x = aktuell();
    if (!x || x.zustand !== 'verworfen') return;
    try {
      await k.api('POST', `${basis}/eintraege/${x.id}/oeffnen`, { json: {} });
      k.melden(t('meldung.geoeffnet'), 'erfolg');
      await laden();
    } catch (e) { k.fehler(e); }
  }

  ansicht.addEventListener('change', () => { markiert.clear(); history.replaceState(null, '', `#/m/${k.mandant}/arbeitsvorrat/liste?ansicht=${ansicht.value}`); laden(); });
  quelle.addEventListener('change', laden);
  suche.addEventListener('input', zeichnen);

  const tabelle = h('table', { class: 'liste', id: 'vorratsliste' }, h('thead', {}, filter.kopf), tbody);
  // Tastatur wie in der Buchungsliste: Pfeile bewegen, Enter bucht, Einfg legt neu an
  tabelle.addEventListener('keydown', (ev) => {
    const zeilen = sichtbar();
    const i = zeilen.findIndex((x) => x.id === gewaehlt);
    if (ev.key === 'ArrowDown' && i < zeilen.length - 1) { ev.preventDefault(); waehlen(zeilen[i + 1].id); }
    else if (ev.key === 'ArrowUp' && i > 0) { ev.preventDefault(); waehlen(zeilen[i - 1].id); }
    else if (ev.key === 'Enter') { ev.preventDefault(); buchen(); }
    else if (ev.key === 'Insert' && darfAnlegen) { ev.preventDefault(); neu(); }
    else if (ev.key === ' ' && ev.target instanceof HTMLTableRowElement) { ev.preventDefault(); const x = aktuell(); if (x?.zustand === 'offen' && darfBearbeiten) { if (markiert.has(x.id)) markiert.delete(x.id); else markiert.add(x.id); zeichnen(); waehlen(x.id); } }
    // Vorschau des Belegs per Tastatur (X6): V auf der gewählten Zeile
    else if ((ev.key === 'v' || ev.key === 'V') && ev.target instanceof HTMLTableRowElement) { const l = aktuell()?.belege[0]?.link; if (archivZuLink(archive, l)) { ev.preventDefault(); const auge = ev.target.querySelector('.beleg-auge'); schwebe.umschalten(l, auge?.neben?.()); } }
  });

  b.append(seitenkopf(t('liste.titel', { mandant: k.mandantName || k.mandant })),
    h('p', { class: 'hinweis' }, t('liste.erklaerung')),
    h('div', { class: 'vorrat-leiste' }, kn.buchen, kn.neu, kn.verwerfen, kn.oeffnen, kn.beleg, h('span', { class: 'luecke' }), kn.alle, kn.vorbelegen, kn.leeren, kn.stapel), kontenliste,
    h('div', { class: 'vorrat-filter' }, ansicht, quelle, suche, h('span', { class: 'luecke' }), stand, knAbgleich), hinweisQuellen,
    h('div', { class: 'vorratsliste' }, tabelle), fuss);
  await laden();
  quellenAbgleichen();   // im Hintergrund, die Seite wartet nicht darauf
  tbody.querySelector('tr.gewaehlt')?.focus();
  return () => { schwebe.entfernen(); filter.entfernen(); };
}
