// SPDX-License-Identifier: AGPL-3.0-or-later
// „Anlagen“ (ANLAGEN.md, F61, F129): Anlagegüter des Buchs als Anlagenverzeichnis je Geschäftsjahr – anlegen, ändern,
// Abgang, AfA-Lauf. kumero schlägt vor (Art nach dem Betrag, Nutzungsdauer aus der AfA-Tabelle), der Mensch
// entscheidet. Gebucht wird nie von selbst: Der Lauf legt Entwürfe an, festgeschrieben wird wie jede Buchung.
// Kopf und Leiste stehen fest, gesperrte Knöpfe nennen den Grund (F106).
import { h, seitenkopf, knopf, feld, auswahl, sperren, datumFeld, datumText, heute } from '/kern/ui/bausteine.js';

const ARTEN = [['linear', 'Verteilung über die Nutzungsdauer (linear)'], ['gwg', 'Sofortabzug (geringwertiges Wirtschaftsgut)'], ['sammelposten', 'Sammelposten (fünf Jahre)'], ['keine', 'keine Absetzung (nicht abnutzbar)']];
const ART_KURZ = { linear: 'linear', gwg: 'GWG', sammelposten: 'Sammelposten', keine: 'keine AfA' };
const ABGANG = [['verkauf', 'Verkauf'], ['verschrottung', 'Verschrottung'], ['entnahme', 'Entnahme'], ['sonstiges', 'Sonstiges']];
const zahl = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const cent = (dez) => Math.round(Number(dez || 0) * 100);
const geld = (c) => zahl.format(c / 100);
const dez = (c) => (c / 100).toFixed(2);
/** „1.299,00“ oder „1299.00“ → „1299.00“; null, wenn es kein Betrag ist. */
export function betragLesen(text) {
  let s = String(text || '').replace(/\s|€/g, '');
  if (s === '') return null;
  if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
  return /^\d{1,13}(\.\d{1,2})?$/.test(s) ? Number(s).toFixed(2) : null;
}
function kontenFilter(text) {
  const teile = String(text || '').split(/[,;\s]+/).filter(Boolean);
  if (!teile.length) return () => false;
  const num = (s) => (/^\d+$/.test(s) ? Number(s) : null);
  const regeln = teile.map((t) => {
    if (t.endsWith('*')) return (nr) => nr.startsWith(t.slice(0, -1));
    const m = t.split(/[-–]/);
    if (m.length === 2 && m[0] && m[1]) return (nr) => (num(m[0]) !== null && num(m[1]) !== null && num(nr) !== null ? num(nr) >= num(m[0]) && num(nr) <= num(m[1]) : nr >= m[0] && nr <= m[1]);
    return (nr) => nr === t;
  });
  return (nr) => regeln.some((r) => r(nr));
}

export async function zeigen(b, k) {
  const css = new URL('./anlagen.css', import.meta.url).href;
  if (!document.querySelector(`link[href="${css}"]`)) document.head.append(h('link', { rel: 'stylesheet', href: css }));
  b.classList.add('listenseite', 'anlagen');
  const basis = `${k.apiBasis}/anlagen`;
  let jahre = [];
  let gj = null;
  let d = { einstellung: {}, regelwerk: { grenzen: [], nutzungsdauern: [] }, gueter: [], darf_pflegen: false, darf_lauf: false };
  let konten = [];
  let buchungsarten = [];
  let gewaehlt = null;
  let ohneGut = [];            // festgeschriebene Buchungen auf Anlagekonten, zu denen es kein Anlagegut gibt
  let saldoKonten = null;      // Saldo der Anlagekonten am Jahresende (Cent) – für die Abstimmung
  let status = new Map();      // Buchung → entwurf | festgeschrieben

  const jahrWahl = h('select', { id: 'an-jahr', 'aria-label': 'Geschäftsjahr', title: 'Das Anlagenverzeichnis gilt für ein Geschäftsjahr' });
  const kn = {
    neu: knopf('Neu …', () => gutDialog(null), 'primaer', { id: 'an-neu', title: 'Anlagegut anlegen – aus einer Buchung oder von Hand' }),
    aendern: knopf('Bearbeiten …', () => gutDialog(d.gueter.find((g) => g.id === gewaehlt)), '', { id: 'an-aendern', title: 'Angaben des gewählten Anlageguts ändern' }),
    abgang: knopf('Abgang …', abgangDialog, '', { id: 'an-abgang', title: 'Verkauf, Verschrottung oder Entnahme eintragen – oder einen Abgang zurücknehmen' }),
    lauf: knopf('AfA-Lauf …', laufDialog, '', { id: 'an-lauf', title: 'Legt für das gewählte Geschäftsjahr Buchungsentwürfe für die Absetzung an' }),
    einstellung: knopf('Einstellungen …', einstellungDialog, '', { id: 'an-einstellung', title: 'Vorsteuerabzug, Ende der Absetzung und Anlagekonten dieses Buchs' }),
    drucken: knopf('Drucken', () => window.print(), '', { id: 'an-drucken', title: 'Anlagenverzeichnis drucken; als PDF über den Druckdialog' }),
    csv: knopf('CSV', csvLaden, '', { id: 'an-csv', title: 'Anlagenverzeichnis als Datei für ein Tabellenprogramm' }),
  };
  const stand = h('div', { class: 'an-stand', id: 'an-stand', role: 'status' });
  const liste = h('div', { class: 'liste-rahmen an-liste', id: 'an-liste' });
  b.append(seitenkopf(`Anlagen – ${k.buchName || k.mandantName || k.mandant}`),
    h('div', { class: 'an-leiste nicht-drucken' }, h('label', { class: 'an-feld' }, h('span', {}, 'Geschäftsjahr'), jahrWahl), ...Object.values(kn)),
    stand, liste);

  const gut = () => d.gueter.find((g) => g.id === gewaehlt) || null;
  /** Was für ein Gut im Jahr zu buchen ist und was davon schon gebucht ist. */
  function offen(g) {
    const j = g.jahr;
    const lebend = j.vermerke.filter((v) => status.has(v.buchung));
    const gebucht = (zweck) => lebend.filter((v) => v.zweck === zweck).reduce((s, v) => s + cent(v.betrag), 0);
    return { afa: cent(j.afa) - gebucht('afa'), abgang: cent(j.abgang_rest) - gebucht('abgang'), entwurf: lebend.some((v) => status.get(v.buchung) !== 'festgeschrieben'), gebucht: lebend.length > 0 };
  }

  function knoepfe() {
    const pflege = d.darf_pflegen ? null : 'Dafür fehlt das Recht, Anlagegüter zu pflegen';
    const kein = gut() ? null : 'Erst ein Anlagegut in der Liste wählen';
    const ohneJahr = gj ? null : 'In diesem Buch gibt es noch kein Geschäftsjahr';
    sperren(kn.neu, pflege || ohneJahr || (d.einstellung.vorsteuerabzug === null ? 'Erst in den Einstellungen festlegen, ob dieses Buch Vorsteuer abzieht' : null));
    sperren(kn.aendern, pflege || kein);
    sperren(kn.abgang, pflege || kein);
    sperren(kn.einstellung, pflege);
    const zuBuchen = d.gueter.some((g) => g.jahr && (offen(g).afa > 0 || offen(g).abgang > 0));
    sperren(kn.lauf, (d.darf_lauf ? null : 'Dafür fehlt das Recht, den AfA-Lauf auszuführen') || ohneJahr
      || (gj.status === 'gesperrt' ? 'Das Geschäftsjahr ist gesperrt' : null) || (zuBuchen ? null : 'Für dieses Geschäftsjahr ist nichts mehr zu buchen'));
    const leer = d.gueter.length ? null : 'Es gibt noch kein Anlagegut';
    sperren(kn.drucken, leer);
    sperren(kn.csv, leer);
  }

  const spalten = [['Inv.-Nr.', 'nr'], ['Bezeichnung', ''], ['Zugang', ''], ['Kosten', 'betrag'], ['Absetzung', ''], ['Buchwert Anfang', 'betrag'],
    ['AfA', 'betrag'], ['Abgang', 'betrag'], ['Buchwert Ende', 'betrag'], ['Gebucht', '']];
  function zeilenModell() {
    return d.gueter.map((g) => {
      const j = g.jahr;
      const o = offen(g);
      const soll = cent(j.afa) + cent(j.abgang_rest);
      const gebucht = soll === 0 ? '–' : (o.afa <= 0 && o.abgang <= 0 ? (o.entwurf ? 'Entwurf' : 'festgeschrieben') : (o.gebucht ? `offen ${geld(Math.max(0, o.afa) + Math.max(0, o.abgang))}` : 'offen'));
      const art = g.art === 'linear' ? `linear, ${g.nutzungsdauer} ${g.nutzungsdauer === 1 ? 'Jahr' : 'Jahre'}` : ART_KURZ[g.art];
      return { g, zellen: [g.inventarnr || '', g.bezeichnung + (g.abgang ? ` (Abgang ${datumText(g.abgang)})` : ''), datumText(g.zugang), geld(cent(g.kosten)), art,
        geld(cent(j.buchwert_anfang)), geld(cent(j.afa)), cent(j.abgang_rest) ? geld(cent(j.abgang_rest)) : '', geld(cent(j.buchwert_ende)), gebucht],
        zustand: gebucht.startsWith('offen') ? 'offen' : gebucht };
    });
  }

  function zeichnen() {
    const m = zeilenModell();
    const summe = (f) => d.gueter.reduce((s, g) => s + cent(g.jahr[f]), 0);
    const kosten = d.gueter.filter((g) => g.jahr.zugang_im_jahr || cent(g.jahr.buchwert_anfang) > 0 || cent(g.jahr.buchwert_ende) > 0 || cent(g.jahr.afa) > 0).reduce((s, g) => s + cent(g.kosten), 0);
    const marken = [];
    if (d.einstellung.vorsteuerabzug === null) marken.push(h('span', { class: 'marke-klein warnung', id: 'an-ohne-einstellung' }, 'Vorsteuerabzug noch nicht festgelegt'));
    else marken.push(h('span', { class: 'marke-klein' }, d.einstellung.vorsteuerabzug ? 'Anschaffungskosten netto (Vorsteuerabzug)' : 'Anschaffungskosten brutto (kein Vorsteuerabzug)'));
    if (ohneGut.length) marken.push(h('span', { class: 'marke-klein warnung nicht-drucken', id: 'an-ohne-gut', title: 'Festgeschriebene Buchungen im Soll eines Anlagekontos, zu denen kein Anlagegut angelegt ist. „Neu …“ bietet sie an.' },
      ohneGut.length === 1 ? '1 Buchung auf einem Anlagekonto ohne Anlagegut' : `${ohneGut.length} Buchungen auf Anlagekonten ohne Anlagegut`));
    if (k.buchInfo?.testbuch) marken.push(h('span', { class: 'marke-klein warnung' }, 'Testbuch'));
    stand.replaceChildren(h('strong', {}, 'Anlagenverzeichnis'), ' · ', k.buchName || '', ' · ', gj ? `Geschäftsjahr ${gj.kennung} (${datumText(gj.beginn)} – ${datumText(gj.ende)})` : 'kein Geschäftsjahr', ...marken);
    if (m.length === 0) {
      liste.replaceChildren(h('p', { class: 'hinweis an-leer', id: 'an-leer' }, d.einstellung.vorsteuerabzug === null
        ? 'Noch kein Anlagegut. Lege zuerst in den Einstellungen fest, ob dieses Buch Vorsteuer abzieht – davon hängt ab, ob die Anschaffungskosten netto oder brutto sind.'
        : 'Noch kein Anlagegut. Mit „Neu …“ legst du das erste an.'));
      knoepfe();
      return;
    }
    const tbody = h('tbody', {}, m.map((z) => {
      const tr = h('tr', { tabindex: '0', class: z.g.id === gewaehlt ? 'gewaehlt' : '', 'data-zustand': z.zustand, 'aria-selected': z.g.id === gewaehlt ? 'true' : 'false' },
        z.zellen.map((t, i) => h('td', { class: spalten[i][1] }, t)));
      const waehlen = () => { gewaehlt = z.g.id; zeichnen(); };
      tr.addEventListener('click', waehlen);
      tr.addEventListener('dblclick', () => { gewaehlt = z.g.id; if (d.darf_pflegen) gutDialog(z.g); });
      tr.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); waehlen(); } });
      return tr;
    }), h('tr', { class: 'an-gesamt' }, [null, 'Gesamt', null, geld(kosten), null, geld(summe('buchwert_anfang')), geld(summe('afa')), geld(summe('abgang_rest')), geld(summe('buchwert_ende')), null]
      .map((t, i) => h('td', { class: spalten[i][1] }, t ?? ''))));
    // Abstimmung zum Journal (G17): Summe der Buchwerte gegen den Saldo der Anlagekonten
    const ende = summe('buchwert_ende');
    const abstimmung = saldoKonten === null
      ? h('span', { class: 'an-hinweis', id: 'an-abstimmung' }, 'Abstimmung zum Journal: erst in den Einstellungen die Anlagekonten nennen')
      : h('span', { class: ['an-hinweis', saldoKonten === ende ? '' : 'an-falsch'], id: 'an-abstimmung' }, saldoKonten === ende
        ? `Abstimmung: Die Buchwerte entsprechen dem Saldo der Anlagekonten (${geld(ende)}).`
        : `Abstimmung: Buchwerte ${geld(ende)}, Saldo der Anlagekonten ${geld(saldoKonten)} – Differenz ${geld(Math.abs(ende - saldoKonten))}. Ist die Absetzung des Jahres schon festgeschrieben?`);
    liste.replaceChildren(h('div', { class: 'liste-scroll' }, h('table', { class: 'liste an-tabelle', id: 'an-tabelle' },
      h('thead', {}, h('tr', {}, spalten.map(([t, kl]) => h('th', { class: kl }, t)))), tbody)),
      h('div', { class: 'liste-fuss' }, h('span', { id: 'an-anzahl' }, d.gueter.length === 1 ? '1 Anlagegut' : `${d.gueter.length} Anlagegüter`), abstimmung));
    knoepfe();
  }

  async function laden() {
    if (!gj) { zeichnen(); return; }
    try {
      d = await k.api('GET', `${basis}?kennung=${encodeURIComponent(gj.kennung)}&beginn=${gj.beginn}&ende=${gj.ende}`);
      if (!d.gueter.some((g) => g.id === gewaehlt)) gewaehlt = null;
      // Zustand der vermerkten Buchungen beim Kern erfragen; was es nicht mehr gibt (gelöschter Entwurf), trägt nichts
      status = new Map();
      await Promise.all(d.gueter.flatMap((g) => g.jahr.vermerke.map(async (v) => {
        try { status.set(v.buchung, (await k.api('GET', `${k.apiBasis}/buchungen/${v.buchung}`)).status); } catch (x) {
          if (x.problem?.status === 404 && d.darf_lauf) { try { await k.api('POST', `${basis}/vermerke/entfernen`, { json: { gut: g.id, jahr: gj.kennung, buchung: v.buchung } }); } catch { /* beim nächsten Mal */ } }
        }
      })));
      await journalAbgleichen();
      zeichnen();
    } catch (x) { k.fehler(x); }
  }

  /** Saldo der Anlagekonten und Buchungen ohne Anlagegut – nur, wenn die Anlagekonten genannt sind. */
  async function journalAbgleichen() {
    ohneGut = []; saldoKonten = null;
    const f = kontenFilter(d.einstellung.anlagekonten);
    if (!d.einstellung.anlagekonten) return;
    try {
      const su = await k.api('GET', `${k.apiBasis}/summen?von=${gj.beginn}&bis=${gj.ende}`);
      saldoKonten = su.konten.filter((x) => f(x.konto)).reduce((s, x) => s + (x.saldo.seite === 'H' ? -1 : 1) * cent(x.saldo.betrag), 0);
      const bekannt = new Set(d.gueter.map((g) => g.buchung).filter(Boolean));
      let weiter = null;
      const alle = [];
      do {
        const r = await k.api('GET', `${k.apiBasis}/buchungen?status=festgeschrieben&von=${gj.beginn}&bis=${gj.ende}&anzahl=500${weiter ? `&cursor=${encodeURIComponent(weiter)}` : ''}`);
        alle.push(...r.eintraege);
        weiter = r.weiter;
      } while (weiter && alle.length < 20000);
      ohneGut = alle.filter((bu) => !bekannt.has(bu.id) && !bu.storno_von && !bu.storniert_durch && bu.zeilen.some((z) => z.seite === 'S' && f(z.konto)))
        .map((bu) => { const z = bu.zeilen.find((y) => y.seite === 'S' && f(y.konto)); return { id: bu.id, nr: bu.nr, datum: bu.buchungsdatum, text: bu.text || '', konto: z.konto, betrag: z.betrag }; });
    } catch { /* ohne Abgleich – die Liste steht trotzdem */ }
  }

  // ── Dialoge ──
  function dialog(o) {
    const ja = h('button', { type: 'submit', class: 'knopf primaer', id: `${o.id}-senden` }, o.ja);
    const fehler = h('p', { class: 'hinweis fehlertext', id: `${o.id}-fehler`, role: 'alert' }, ' ');
    const dlg = h('dialog', { class: ['dialog', o.breit ? 'an-dialog' : ''], id: o.id },
      h('form', { onsubmit: async (ev) => {
        ev.preventDefault();
        ja.disabled = true;
        try { await o.senden(); dlg.close(); } catch (x) {
          fehler.textContent = (x.problem?.fehler || []).map((f) => f.meldung).join(' · ') || x.problem?.detail || x.message || 'Das hat nicht geklappt';
        } finally { ja.disabled = false; }
      } },
      h('h2', {}, o.titel), o.text ? h('p', { class: 'hinweis' }, o.text) : null, ...o.felder, fehler,
      h('div', { class: 'dialog-knoepfe' }, ...(o.zusatz || []), knopf('Abbrechen', () => dlg.close(), '', { id: `${o.id}-abbrechen` }), ja)));
    dlg.addEventListener('close', () => dlg.remove());
    document.body.append(dlg);
    dlg.showModal();
    return { dlg, ja };
  }
  const kontoFeld = (id, wert) => h('input', { id, list: 'an-konten', size: '10', maxlength: '20', value: wert || '', autocomplete: 'off', spellcheck: false });

  function gutDialog(g) {
    const neu = !g;
    const e = d.einstellung;
    const aus = auswahl([['', ohneGut.length ? '– von Hand –' : '– keine offene Buchung –'], ...ohneGut.map((x) => [x.id, `${x.nr} · ${datumText(x.datum)} · ${x.konto} · ${geld(cent(x.betrag))} · ${x.text.slice(0, 40)}`])], '', { id: 'an-f-aus' });
    const bez = h('input', { id: 'an-f-bezeichnung', required: true, maxlength: '200', size: '40', value: g?.bezeichnung || '', autocomplete: 'off' });
    const inv = h('input', { id: 'an-f-inventarnr', maxlength: '40', size: '12', value: g?.inventarnr || '', autocomplete: 'off' });
    const zugang = datumFeld({ id: 'an-f-zugang', value: g?.zugang || heute() });
    const kosten = h('input', { id: 'an-f-kosten', required: true, size: '12', inputmode: 'decimal', value: g ? geld(cent(g.kosten)) : '', autocomplete: 'off', class: 'an-betrag' });
    const netto = h('input', { id: 'an-f-netto', size: '12', inputmode: 'decimal', value: g?.netto ? geld(cent(g.netto)) : '', autocomplete: 'off', class: 'an-betrag' });
    const art = auswahl(ARTEN, g?.art || 'linear', { id: 'an-f-art' });
    const tabelle = auswahl([['', '– kein Eintrag der AfA-Tabelle –'], ...d.regelwerk.nutzungsdauern.map((n, i) => [String(i), `${n.bezeichnung} – ${n.jahre} ${n.jahre === 1 ? 'Jahr' : 'Jahre'} (${n.fundstelle})`])],
      g?.nd_quelle ? String(d.regelwerk.nutzungsdauern.findIndex((n) => `${n.bezeichnung} (${n.fundstelle})` === g.nd_quelle)).replace('-1', '') : '', { id: 'an-f-tabelle' });
    const nd = h('input', { id: 'an-f-nutzungsdauer', type: 'number', min: '1', max: '100', step: '1', size: '4', value: g?.nutzungsdauer ?? '', class: 'an-jahre' });
    const begr = h('input', { id: 'an-f-begruendung', maxlength: '500', size: '40', value: g?.nd_begruendung || '', autocomplete: 'off', placeholder: 'freiwillig, z. B. gebraucht gekauft' });
    const rest = auswahl([['null', 'bis auf 0,00 €'], ['erinnerung', 'bis auf 1,00 € Erinnerungswert, solange das Gut da ist']], g?.restwert || e.restwert, { id: 'an-f-restwert' });
    const bestand = kontoFeld('an-f-bestandskonto', g?.bestandskonto);
    const aufwand = kontoFeld('an-f-aufwandskonto', g?.aufwandskonto);
    const standort = h('input', { id: 'an-f-standort', maxlength: '100', size: '20', value: g?.standort || '', autocomplete: 'off' });
    const bem = h('input', { id: 'an-f-bemerkung', maxlength: '1000', size: '40', value: g?.bemerkung || '', autocomplete: 'off' });
    const ueb = datumFeld({ id: 'an-f-uebernahme', value: g?.uebernahme || '' });
    const bisher = h('input', { id: 'an-f-bisher', size: '12', inputmode: 'decimal', value: g && cent(g.bisherige_afa) ? geld(cent(g.bisherige_afa)) : '', autocomplete: 'off', class: 'an-betrag' });
    const vorschlag = h('p', { class: 'an-vorschlag', id: 'an-f-vorschlag' }, ' ');
    const ndHinweis = h('span', { class: 'hinweis', id: 'an-f-nd-hinweis' }, ' ');
    const plan = h('div', { class: 'an-plan', id: 'an-f-plan' });
    let buchung = g?.buchung || null;
    let empfohlen = null;

    const eintrag = () => (tabelle.value === '' ? null : d.regelwerk.nutzungsdauern[Number(tabelle.value)]);
    const daten = () => {
      const t = eintrag();
      const jahreNd = nd.value === '' ? null : Number(nd.value);
      return { inventarnr: inv.value.trim() || null, bezeichnung: bez.value.trim(), zugang: zugang.wert(), kosten: betragLesen(kosten.value), netto: e.vorsteuerabzug === false ? betragLesen(netto.value) : null,
        art: art.value, nutzungsdauer: art.value === 'linear' && Number.isInteger(jahreNd) ? jahreNd : null, nd_vorschlag: t ? t.jahre : null, nd_quelle: t ? `${t.bezeichnung} (${t.fundstelle})` : null,
        nd_begruendung: t && jahreNd !== t.jahre ? (begr.value.trim() || null) : null, restwert: rest.value, bestandskonto: bestand.value.trim() || null, aufwandskonto: aufwand.value.trim() || null,
        buchung, uebernahme: ueb.wert() || null, bisherige_afa: betragLesen(bisher.value) || '0.00', standort: standort.value.trim() || null, bemerkung: bem.value.trim() || null };
    };
    // was zur gewählten Art nicht gehört, bleibt stehen und ist gesperrt – mit Grund (F106)
    const passend = () => {
      const lin = art.value === 'linear';
      const grund = lin ? null : 'Nur bei der Verteilung über die Nutzungsdauer';
      [tabelle, nd, rest, ueb.ein, bisher].forEach((x) => sperren(x, grund));
      ueb.knopf.disabled = !lin;
      const t = eintrag();
      const weicht = lin && t && nd.value !== '' && Number(nd.value) !== t.jahre;
      sperren(begr, weicht ? null : (lin ? 'Nur, wenn die Nutzungsdauer vom Vorschlag der Tabelle abweicht' : grund));
      ndHinweis.textContent = !lin ? ' ' : (t ? (weicht ? `Weicht vom Vorschlag der Tabelle ab (${t.jahre} ${t.jahre === 1 ? 'Jahr' : 'Jahre'}). Eine Begründung ist freiwillig.` : `Vorschlag der Tabelle: ${t.jahre} ${t.jahre === 1 ? 'Jahr' : 'Jahre'}${t.hinweis ? ` – ${t.hinweis}` : ''}`) : 'Ohne Eintrag der Tabelle: Nutzungsdauer von Hand.');
      sperren(netto, e.vorsteuerabzug === false ? null : 'Dieses Buch zieht Vorsteuer ab – die Anschaffungskosten sind schon netto');
      sperren(aufwand, art.value === 'keine' ? 'Ohne Absetzung braucht es kein Aufwandskonto' : null);
    };
    let lauf = 0;
    const vorschau = async () => {
      passend();
      const x = daten();
      const n = ++lauf;
      if (!x.zugang || !x.kosten || (x.art === 'linear' && !x.nutzungsdauer)) { plan.replaceChildren(h('p', { class: 'hinweis' }, 'Der Plan der Absetzung erscheint, sobald Zugang, Kosten und – bei der Verteilung – die Nutzungsdauer stehen.')); return; }
      try {
        const r = await k.api('POST', `${basis}/plan`, { json: { ...x, bezeichnung: x.bezeichnung || null, jahresbeginn: gj.beginn } });
        if (n !== lauf) return;
        // die Grenzen gelten netto: Sind die Kosten brutto und fehlt der Nettobetrag, wird nichts vorgeschlagen
        const ohneNetto = e.vorsteuerabzug === false && !x.netto;
        empfohlen = ohneNetto ? null : r.vorschlag;
        vorschlag.textContent = ohneNetto ? 'Die Grenzen für Sofortabzug und Sammelposten gelten netto. Trage den Nettobetrag ein, dann schlägt kumero die Art vor.' : r.vorschlag ? `Vorschlag nach dem Betrag: ${ARTEN.find((a) => a[0] === r.vorschlag.vorschlag)[1]}. ${r.vorschlag.begruendung}.${r.vorschlag.arten.includes(x.art) ? '' : ' Die gewählte Art passt nicht zu den Grenzen – bitte prüfen.'}`
          : 'Für das Datum des Zugangs sind keine Grenzen hinterlegt.';
        vorschlag.classList.toggle('an-falsch', !ohneNetto && Boolean(r.vorschlag) && !r.vorschlag.arten.includes(x.art));
        plan.replaceChildren(h('table', { class: 'an-plan-tabelle' }, h('thead', {}, h('tr', {}, ['Geschäftsjahr', 'Buchwert Anfang', 'AfA', 'Buchwert Ende'].map((t, i) => h('th', { class: i ? 'betrag' : '' }, t)))),
          h('tbody', {}, r.plan.map((p) => h('tr', {}, h('td', {}, `${datumText(p.beginn)} – ${datumText(p.ende)}`), h('td', { class: 'betrag' }, geld(cent(p.buchwert_anfang))),
            h('td', { class: 'betrag' }, geld(cent(p.afa))), h('td', { class: 'betrag' }, geld(cent(p.buchwert_ende))))))));
      } catch (x2) { if (n === lauf) plan.replaceChildren(h('p', { class: 'hinweis' }, (x2.problem?.fehler || []).map((f) => f.meldung).join(' · ') || 'Der Plan lässt sich mit diesen Angaben nicht rechnen.')); }
    };
    aus.addEventListener('change', () => {
      const x = ohneGut.find((y) => y.id === aus.value);
      buchung = x ? x.id : (g?.buchung || null);
      if (x) { zugang.setzen(x.datum); kosten.value = geld(cent(x.betrag)); bestand.value = x.konto; if (!bez.value) bez.value = x.text; }
      vorschau();
    });
    tabelle.addEventListener('change', () => { const t = eintrag(); if (t) nd.value = String(t.jahre); vorschau(); });
    for (const x of [zugang.ein, kosten, netto, art, nd, rest, ueb.ein, bisher]) x.addEventListener('change', vorschau);
    sperren(aus, neu ? (ohneGut.length ? null : (d.einstellung.anlagekonten ? 'Es gibt keine Buchung auf einem Anlagekonto ohne Anlagegut' : 'Erst in den Einstellungen die Anlagekonten nennen')) : 'Nur beim Anlegen');
    const uebernehmen = knopf('Vorschlag übernehmen', () => { if (empfohlen) { art.value = empfohlen.vorschlag; vorschau(); } }, '', { id: 'an-f-vorschlag-nehmen', title: 'Setzt die Art auf den Vorschlag nach dem Betrag' });
    dialog({ id: 'an-dialog', breit: true, titel: neu ? 'Anlagegut anlegen' : `Anlagegut bearbeiten`, ja: neu ? 'Anlegen' : 'Speichern',
      text: 'kumero schlägt vor, du entscheidest: Die Art der Absetzung und die Nutzungsdauer sind Wahlrechte und Schätzungen, die nur der treffen kann, der das Gut kennt.',
      felder: [
        h('div', { class: 'an-f-reihe' }, feld('Aus einer Buchung übernehmen', aus)),
        h('div', { class: 'an-f-reihe' }, feld('Bezeichnung', bez), feld('Inventar-Nr.', inv), feld('Standort', standort)),
        h('div', { class: 'an-f-reihe' }, h('label', { class: 'feld' }, h('span', { class: 'beschriftung' }, 'Zugang'), zugang.el),
          feld(e.vorsteuerabzug === false ? 'Anschaffungskosten (brutto)' : 'Anschaffungskosten (netto)', kosten), feld('Nettobetrag (für die Grenzen)', netto)),
        h('div', { class: 'an-f-reihe' }, feld('Art der Absetzung', art), uebernehmen),
        vorschlag,
        h('fieldset', { class: 'an-gruppe-feld' }, h('legend', {}, 'Nutzungsdauer'),
          h('div', { class: 'an-f-reihe' }, feld('Vorschlag aus der AfA-Tabelle', tabelle), feld('Jahre', nd), feld('Begründung bei Abweichung', begr)), ndHinweis,
          h('div', { class: 'an-f-reihe' }, feld('Ende der Absetzung', rest))),
        h('div', { class: 'an-f-reihe' }, feld('Bestandskonto (Anlage)', bestand), feld('Aufwandskonto (Absetzung)', aufwand), feld('Bemerkung', bem)),
        h('fieldset', { class: 'an-gruppe-feld' }, h('legend', {}, 'Bestand von vor kumero (nur ausfüllen, wenn schon abgesetzt wurde)'),
          h('div', { class: 'an-f-reihe' }, h('label', { class: 'feld' }, h('span', { class: 'beschriftung' }, 'Stichtag der Übernahme'), ueb.el), feld('bis dahin abgesetzt', bisher))),
        h('datalist', { id: 'an-konten' }, konten.map((x) => h('option', { value: x.nr }, `${x.nr} ${x.bezeichnung}`))),
        h('h3', { class: 'an-plan-titel' }, 'Plan der Absetzung'), plan],
      senden: async () => {
        const x = daten();
        if (!x.zugang) throw new Error('Bitte das Datum des Zugangs eingeben.');
        if (!x.kosten) throw new Error('Bitte die Anschaffungskosten als Betrag eingeben, z. B. 1.299,00.');
        if (netto.value.trim() && !x.netto && e.vorsteuerabzug === false) throw new Error('Der Nettobetrag ist kein Betrag.');
        if (x.art === 'linear' && !x.nutzungsdauer) throw new Error('Bitte die Nutzungsdauer in ganzen Jahren eingeben – oder einen Eintrag der AfA-Tabelle wählen.');
        if (bisher.value.trim() && betragLesen(bisher.value) === null) throw new Error('„bis dahin abgesetzt“ ist kein Betrag.');
        const r = neu ? await k.api('POST', `${basis}/gueter`, { json: x }) : await k.api('PUT', `${basis}/gueter/${g.id}`, { json: x });
        gewaehlt = r.id;
        await laden();
        k.melden(neu ? 'Anlagegut angelegt.' : 'Gespeichert.', 'erfolg');
      } });
    vorschau();
    bez.focus();
  }

  function abgangDialog() {
    const g = gut();
    if (!g) return;
    const datum = datumFeld({ id: 'an-f-abgang', value: g.abgang || heute() });
    const art = auswahl(ABGANG, g.abgang_art || 'verkauf', { id: 'an-f-abgang-art' });
    const zurueck = knopf('Abgang zurücknehmen', async () => {
      try { await k.api('POST', `${basis}/gueter/${g.id}/abgang`, { json: { datum: null } }); document.getElementById('an-dialog-abgang')?.close(); await laden(); k.melden('Abgang zurückgenommen.', 'erfolg'); } catch (x) { k.fehler(x); }
    }, '', { id: 'an-f-abgang-zurueck' });
    sperren(zurueck, g.abgang ? null : 'Es ist kein Abgang eingetragen');
    dialog({ id: 'an-dialog-abgang', titel: `Abgang: ${g.bezeichnung}`, ja: 'Abgang eintragen', zusatz: [zurueck],
      text: g.art === 'sammelposten' ? 'Ein Gut im Sammelposten bleibt im Topf: Der Abgang wird vermerkt, die Auflösung über fünf Jahre läuft weiter.'
        : 'Die Absetzung läuft bis zum Monat des Abgangs. Der Rest des Buchwerts wird mit dem nächsten AfA-Lauf als Entwurf ausgebucht; einen Erlös buchst du wie gewohnt.',
      felder: [h('div', { class: 'an-f-reihe' }, h('label', { class: 'feld' }, h('span', { class: 'beschriftung' }, 'Tag des Abgangs'), datum.el), feld('Art', art))],
      senden: async () => {
        if (!datum.wert()) throw new Error('Bitte den Tag des Abgangs eingeben.');
        await k.api('POST', `${basis}/gueter/${g.id}/abgang`, { json: { datum: datum.wert(), art: art.value } });
        await laden();
        k.melden('Abgang eingetragen.', 'erfolg');
      } });
  }

  function laufDialog() {
    const posten = d.gueter.flatMap((g) => {
      const o = offen(g);
      const tag = g.abgang && g.jahr.abgang_im_jahr ? g.abgang : gj.ende;
      return [o.afa > 0 ? { g, zweck: 'afa', c: o.afa, datum: gj.ende, text: `AfA ${gj.kennung} ${g.bezeichnung}` } : null,
        o.abgang > 0 ? { g, zweck: 'abgang', c: o.abgang, datum: tag, text: `Abgang Restbuchwert ${g.bezeichnung}` } : null].filter(Boolean);
    });
    const artWahl = auswahl(buchungsarten.map((x) => [x.kuerzel, `${x.kuerzel} – ${x.bezeichnung}`]), buchungsarten[0]?.kuerzel, { id: 'an-f-buchungsart' });
    const { ja } = dialog({ id: 'an-dialog-lauf', breit: true, titel: `AfA-Lauf ${gj.kennung}`, ja: posten.length === 1 ? '1 Entwurf anlegen' : `${posten.length} Entwürfe anlegen`,
      text: 'Für jede Zeile entsteht ein Buchungsentwurf: Aufwandskonto im Soll, Bestandskonto im Haben. Die Entwürfe stehen danach in der Buchungsliste; festgeschrieben werden sie dort. Der Lauf lässt sich wiederholen – er legt nur an, was fehlt.',
      felder: [h('div', { class: 'an-f-reihe' }, feld('Buchungsart der Entwürfe', artWahl)),
        h('table', { class: 'an-plan-tabelle', id: 'an-lauf-tabelle' }, h('thead', {}, h('tr', {}, ['Anlagegut', 'Buchung', 'Datum', 'Soll', 'Haben', 'Betrag'].map((t, i) => h('th', { class: i === 5 ? 'betrag' : '' }, t)))),
          h('tbody', {}, posten.map((p) => h('tr', {}, h('td', {}, p.g.bezeichnung), h('td', {}, p.zweck === 'afa' ? 'AfA des Jahres' : 'Rest beim Abgang'), h('td', {}, datumText(p.datum)),
            h('td', {}, p.g.aufwandskonto), h('td', {}, p.g.bestandskonto), h('td', { class: 'betrag' }, geld(p.c))))))],
      senden: async () => {
        if (!artWahl.value) throw new Error('Bitte eine Buchungsart wählen.');
        let n = 0;
        try {
          for (const p of posten) {
            const bu = await k.api('POST', `${k.apiBasis}/buchungen`, { json: { buchungsart: artWahl.value, belegdatum: p.datum, buchungsdatum: p.datum, text: p.text.slice(0, 500),
              zeilen: [{ konto: p.g.aufwandskonto, seite: 'S', betrag: dez(p.c) }, { konto: p.g.bestandskonto, seite: 'H', betrag: dez(p.c) }] } });
            await k.api('POST', `${basis}/vermerke`, { json: { gut: p.g.id, jahr: gj.kennung, buchung: bu.id, zweck: p.zweck, betrag: dez(p.c) } });
            n++;
          }
        } finally { await laden(); }
        k.melden(n === 1 ? '1 Entwurf angelegt – er steht in der Buchungsliste.' : `${n} Entwürfe angelegt – sie stehen in der Buchungsliste.`, 'erfolg');
      } });
    sperren(ja, buchungsarten.length ? null : 'Es gibt noch keine Buchungsart (Einstellungen → Buchen einrichten)');
  }

  function einstellungDialog() {
    const e = d.einstellung;
    const vst = auswahl([['', '– bitte wählen –'], ['ja', 'ja – das Buch erbringt umsatzsteuerpflichtige Leistungen: Anschaffungskosten sind netto'], ['nein', 'nein – z. B. steuerfreie Heilbehandlungen: Anschaffungskosten sind brutto']],
      e.vorsteuerabzug === null ? '' : (e.vorsteuerabzug ? 'ja' : 'nein'), { id: 'an-f-vorsteuer' });
    const rest = auswahl([['null', 'bis auf 0,00 €'], ['erinnerung', 'bis auf 1,00 € Erinnerungswert, solange das Gut da ist']], e.restwert, { id: 'an-f-rest-vorgabe' });
    const kto = h('input', { id: 'an-f-anlagekonten', size: '40', maxlength: '500', value: e.anlagekonten || '', spellcheck: false, autocomplete: 'off', placeholder: 'z. B. 0100-0899' });
    dialog({ id: 'an-dialog-einstellung', titel: 'Einstellungen der Anlagenbuchhaltung', ja: 'Speichern',
      text: 'Diese Angaben gelten für dieses Buch.',
      felder: [feld('Zieht dieses Buch Vorsteuer ab?', vst, 'Davon hängt ab, ob die Anschaffungskosten eines Guts netto oder brutto sind. Die Grenzen für Sofortabzug und Sammelposten gelten immer netto.'),
        feld('Ende der Absetzung – Vorgabe für neue Anlagegüter', rest, 'Je Anlagegut änderbar. Beim Abgang wird der Rest des Buchwerts in jedem Fall ausgebucht.'),
        feld('Anlagekonten', kto, 'Für die Abstimmung zum Journal und für „Buchungen ohne Anlagegut“. Nummern, Bereiche und Anfänge: 0100-0899, 04*')],
      senden: async () => {
        if (vst.value === '') throw new Error('Bitte festlegen, ob dieses Buch Vorsteuer abzieht.');
        await k.api('PUT', `${basis}/einstellung`, { json: { vorsteuerabzug: vst.value === 'ja', restwert: rest.value, anlagekonten: kto.value.trim() } });
        await laden();
        k.melden('Einstellungen gespeichert.', 'erfolg');
      } });
  }

  function csvLaden() {
    const zelle = (s) => `"${String(s ?? '').replace(/"/g, '""')}"`;
    const m = zeilenModell();
    const zeilen = [['Anlagenverzeichnis'], [`Buch: ${k.buchName || ''}`], [`Geschäftsjahr: ${gj.kennung} (${datumText(gj.beginn)} – ${datumText(gj.ende)})`], [],
      spalten.map((s) => s[0]), ...m.map((z) => z.zellen.map((t, i) => (spalten[i][1] === 'betrag' ? String(t).replace(/\./g, '') : t)))];
    const url = URL.createObjectURL(new Blob([`﻿${zeilen.map((z) => z.map(zelle).join(';')).join('\r\n')}\r\n`], { type: 'text/csv;charset=utf-8' }));
    const a = h('a', { href: url, download: `Anlagenverzeichnis ${gj.kennung}.csv`.replace(/[^A-Za-z0-9 ._-]+/g, '_'), hidden: true });
    document.body.append(a);
    a.click();
    setTimeout(() => { a.remove(); URL.revokeObjectURL(url); }, 2000);
    k.melden('CSV erzeugt.', 'erfolg');
  }

  jahrWahl.addEventListener('change', () => { gj = jahre.find((x) => x.kennung === jahrWahl.value) || null; laden(); });
  try {
    [jahre, konten, buchungsarten] = await Promise.all([k.api('GET', `${k.apiBasis}/geschaeftsjahre`), k.api('GET', `${k.apiBasis}/konten`), k.api('GET', `${k.apiBasis}/buchungsarten`)]);
  } catch (x) { k.fehler(x); }
  const tag = heute();
  gj = jahre.find((x) => x.beginn <= tag && tag <= x.ende) || jahre[jahre.length - 1] || null;
  jahrWahl.replaceChildren(...(jahre.length ? jahre.map((x) => h('option', { value: x.kennung }, x.kennung)) : [h('option', { value: '' }, 'kein Geschäftsjahr')]));
  if (gj) jahrWahl.value = gj.kennung;
  sperren(jahrWahl, jahre.length > 1 ? null : (jahre.length ? 'Es gibt nur dieses Geschäftsjahr' : 'In diesem Buch gibt es noch kein Geschäftsjahr'));
  if (!gj) {
    try { d = await k.api('GET', basis); d.gueter = []; } catch (x) { k.fehler(x); }
    stand.replaceChildren(h('span', { class: 'hinweis' }, ' '));
    liste.replaceChildren(h('p', { class: 'hinweis an-leer', id: 'an-leer' }, 'In diesem Buch gibt es noch kein Geschäftsjahr. Das Anlagenverzeichnis braucht eines (Einstellungen → Buchen einrichten).'));
    knoepfe();
    return undefined;
  }
  await laden();
  return undefined;
}
