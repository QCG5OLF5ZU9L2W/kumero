// SPDX-License-Identifier: AGPL-3.0-or-later
// Buchungserfassung (BUCHUNGSMASKE.md Abschnitt 2, 3 und 8; M1–M6, M9–M15): Formular mit Konto/Gegenkonto in
// festen Spalten, Kontenvorschau beim Tippen, Zeilentabelle für Splitbuchungen, Geschäftsvorfälle links.
// Speichern = Entwurf im Stapel, Buchen = vorbereiten und festschreiben. Eine festgeschriebene Buchung
// wird nicht als Formular gezeigt, sondern als feste Ansicht. Alles über die REST-API, alles mit der Tastatur.
import { h, seitenkopf, knopf, auswahl, bestaetigen, datumFeld, zeit } from '/kern/ui/bausteine.js';
import {
  stilLaden, betragLesen, betragZeigen, cent, centZeigen, heute, datumZeigen, wochentag, stammdatenLaden, fehlendes,
  saldoText, kontoSuchen, kontoVorschau, freigeben, betriebsart,
} from './gemeinsam.js';

const BELEGNUMMER_MAX = 36;
const LINK = /^https?:\/\/[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:[0-9]{1,5})?(\/[^\s?#]*)?$/;

export async function zeigen(b, k) {
  stilLaden();
  const t = k.t;
  const basis = k.apiBasis;
  const s = await stammdatenLaden(k);
  const darfBuchen = k.darf('kern.buchung.festschreiben') && k.darf('kern.entwurf.aendern');
  const listenPfad = `#/m/${k.mandant}/buchen/liste`;
  const betragsart = k.einstellungen['kern.betragseingabe'] || 'dezimal';
  const test = betriebsart(k) === 'test';
  const kopf = () => seitenkopf(t('erfassen.titel', { mandant: k.mandantName || k.mandant }),
    test ? h('span', { class: 'marke-klein warnung', title: t('hilfe.testbetrieb') }, t('hinweis.testbetrieb')) : null,
    knopf(t('aktion.zur_liste'), () => k.navigieren(listenPfad), '', { title: t('hilfe.zur_liste') }));

  // Eine festgeschriebene Buchung ist kein Formular mehr (M14)
  if (k.param.id) {
    let geladen;
    try {
      geladen = await k.api('GET', `${basis}/buchungen/${k.param.id}`, { mitKopf: true });
    } catch (e) { b.append(kopf()); k.fehler(e); return; }
    if (geladen.daten.status === 'festgeschrieben') {
      b.append(kopf(), ansicht(geladen.daten));
      return;
    }
  }

  let vorlagen = [];
  if (k.darf('buchen.vorlage.sehen')) {
    try { vorlagen = await k.api('GET', `${basis}/buchen/vorlagen`); } catch { /* ohne Geschäftsvorfälle weiter */ }
  }
  // Vorschläge für den Buchungstext: zuletzt benutzte Texte aus dem Journal und die Texte der Vorlagen
  const texte = new Set(vorlagen.map((v) => v.text).filter(Boolean));
  try {
    const r = await k.api('GET', `${basis}/buchungen?anzahl=200&reihenfolge=ab`);
    for (const x of r.eintraege) if (x.text && texte.size < 80) texte.add(x.text);
  } catch { /* ohne Vorschläge weiter */ }

  let akt = null;        // geladener oder gerade gespeicherter Entwurf: {id, etag, status}
  let split = false;
  let folgt = true;      // Buchungsdatum folgt dem Belegdatum, bis es getrennt geändert wird (M3)

  // ── Felder ───────────────────────────────────────────────────────────────────────────
  function tagWahl(vor = []) {
    const kisten = s.tags.map((x) => h('input', { type: 'checkbox', value: x.name, checked: vor.includes(x.name) }));
    const zusammen = h('summary', { title: t('hilfe.tags') });
    const wert = () => kisten.filter((c) => c.checked).map((c) => c.value);
    const zeigenKopf = () => { zusammen.textContent = wert().join(', ') || '–'; };
    kisten.forEach((c) => c.addEventListener('change', zeigenKopf));
    zeigenKopf();
    const el = h('details', { class: 'tagwahl' }, zusammen, h('div', { class: 'tagliste' },
      s.tags.length ? kisten.map((c) => h('label', {}, c, c.value)) : h('span', { class: 'hinweis' }, t('hinweis.keine_tags'))));
    return { el, wert, setzen: (liste) => { kisten.forEach((c) => { c.checked = (liste || []).includes(c.value); }); zeigenKopf(); } };
  }

  function kontoFeld(id, hilfe) {
    const ein = h('input', { id, class: 'konto-nr', maxlength: '20', autocomplete: 'off', spellcheck: false, title: hilfe });
    const name = h('span', { class: 'konto-name' });
    const saldo = h('span', { class: 'konto-saldo', title: t('hilfe.saldo') });
    const hinweis = h('span', { class: 'feldhinweis' });
    let lauf = 0;
    const nameZeigen = () => {
      const nr = ein.value.trim();
      const x = nr ? s.konto(nr) : null;
      name.textContent = x ? x.bezeichnung : '';
      hinweis.textContent = x && !x.aktiv ? t('hinweis.konto_inaktiv') : '';
      // Der Saldo gehört zum Konto: ohne gültiges Konto bleibt nichts vom vorigen stehen
      if (!x) { lauf++; saldo.textContent = ''; }
      return x;
    };
    const pruefen = async () => {
      const x = nameZeigen();
      const nr = ein.value.trim();
      if (nr && !x) hinweis.textContent = t('hinweis.konto_unbekannt');
      const n = ++lauf;
      saldo.textContent = '';
      if (!x || !k.darf('kern.buchung.sehen')) return;
      try {
        const r = await k.api('GET', `${basis}/salden?stichtag=9999-12-31&konto=${encodeURIComponent(x.nr)}`);
        if (n === lauf && r[0]) saldo.textContent = `${t('feld.saldo')} ${saldoText(r[0].saldo)}`;
      } catch { /* Saldo ist nur eine Hilfe */ }
    };
    const suchen = async () => {
      const nr = await kontoSuchen(s.konten, t, s.konto(ein.value.trim()) ? '' : ein.value.trim());
      if (nr) { ein.value = nr; await pruefen(); }
      ein.focus();
    };
    ein.addEventListener('input', nameZeigen);
    ein.addEventListener('change', pruefen);
    ein.addEventListener('keydown', (ev) => { if (ev.key === 'F2') { ev.preventDefault(); suchen(); } });
    kontoVorschau(ein, s.konten);
    const such = knopf('…', suchen, 'klein', { title: t('suche.kurz'), tabindex: '-1' });
    return { ein, such, name, saldo, hinweis, eingabe: h('span', { class: 'konto-eingabe' }, ein, such),
      wert: () => ein.value.trim(), setzen: (nr) => { ein.value = nr || ''; return pruefen(); } };
  }

  function betragFeld(attr = {}) {
    const ein = h('input', { class: 'betrag', inputmode: 'decimal', autocomplete: 'off', title: t(`hilfe.betrag_${betragsart}`), ...attr });
    const hinweis = h('span', { class: 'feldhinweis' });
    ein.addEventListener('change', () => {
      const w = betragLesen(ein.value, betragsart);
      hinweis.textContent = w === null ? t('hinweis.betrag') : '';
      if (w) ein.value = betragZeigen(w);
    });
    return { ein, hinweis, wert: () => betragLesen(ein.value, betragsart), setzen: (dez) => { ein.value = betragZeigen(dez); hinweis.textContent = ''; } };
  }

  const konto = kontoFeld('konto', t('hilfe.konto'));
  const gegen = kontoFeld('gegenkonto', t('hilfe.gegenkonto'));
  const betrag = betragFeld({ id: 'betrag' });
  const datumOptionen = { kalenderTitel: t('hilfe.kalender'), ungueltig: t('hinweis.datum') };
  const belegdatum = datumFeld({ id: 'belegdatum', value: heute(), title: t('hilfe.belegdatum') }, datumOptionen);
  const tag = h('span', { class: 'konto-name' }, wochentag(heute()));
  const buchungsdatum = datumFeld({ id: 'buchungsdatum', value: heute(), title: t('hilfe.buchungsdatum') }, datumOptionen);
  const buchungstag = h('span', { class: 'hinweis' }, t('hinweis.wie_belegdatum'));
  const belegnummer = h('input', { id: 'belegnummer', autocomplete: 'off', title: t('hilfe.belegnummer') });
  const belegnummerHinweis = h('span', { class: 'feldhinweis' });
  // Beleg als ganzer Link (F88, M16): sagt, wo der Beleg liegt; die Prüfsummen ergänzt später das Modul paperless
  const beleg = h('input', { id: 'beleg', type: 'url', maxlength: '500', autocomplete: 'off', spellcheck: false, placeholder: 'https://…/documents/…/', title: t('hilfe.beleg') });
  const belegHinweis = h('span', { class: 'feldhinweis' });
  const belegOeffnen = h('a', { class: 'beleg-oeffnen', target: '_blank', rel: 'noopener noreferrer', hidden: true, title: t('hilfe.beleg_oeffnen') }, t('aktion.beleg_oeffnen'));
  let belegeAlt = [];    // Verweise des geladenen Entwurfs (mit Prüfsummen, falls vorhanden)
  /** Schema und Host klein – eine Schreibweise je Beleg; alles andere bleibt, wie es eingegeben wurde. */
  const linkLesen = () => beleg.value.trim().replace(/^([a-z]+:\/\/[^/]*)/i, (x) => x.toLowerCase());
  const belegPruefen = () => {
    const w = linkLesen();
    const ok = w === '' || (w.length <= 500 && LINK.test(w));
    belegHinweis.textContent = ok ? '' : t('hinweis.beleg_link');
    belegOeffnen.hidden = !ok || w === '';
    if (ok && w) belegOeffnen.href = w; else belegOeffnen.removeAttribute('href');
    return ok;
  };
  beleg.addEventListener('input', belegPruefen);
  beleg.addEventListener('change', () => { if (belegPruefen()) beleg.value = linkLesen(); });
  const text = h('input', { id: 'text', maxlength: '500', list: 'buchungstexte', autocomplete: 'off', title: t('hilfe.text') });
  const textliste = h('datalist', { id: 'buchungstexte' }, [...texte].map((x) => h('option', { value: x })));
  const art = auswahl(s.buchungsarten.map((a) => [a.kuerzel, `${a.kuerzel} – ${a.bezeichnung}`]), s.buchungsarten[0]?.kuerzel, { id: 'buchungsart', title: t('hilfe.buchungsart') });
  const tags = tagWahl();

  const buchungstagZeigen = () => {
    const w = buchungsdatum.wert();
    buchungstag.textContent = folgt ? t('hinweis.wie_belegdatum') : (w ? wochentag(w) : '');
  };
  belegdatum.ein.addEventListener('change', () => {
    const w = belegdatum.wert();
    tag.textContent = w ? wochentag(w) : '';
    if (folgt && w) buchungsdatum.setzen(w);
    buchungstagZeigen();
  });
  buchungsdatum.ein.addEventListener('change', () => {
    if (buchungsdatum.wert() === '') buchungsdatum.setzen(belegdatum.wert() || '');
    folgt = buchungsdatum.wert() === belegdatum.wert();
    buchungstagZeigen();
  });
  const belegnummerPruefen = () => {
    const w = belegnummer.value.trim();
    const zuLang = w.length > BELEGNUMMER_MAX;
    const istLink = /^https?:\/\//i.test(w);
    belegnummerHinweis.textContent = istLink ? t('hinweis.belegnummer_link') : (zuLang ? t('hinweis.belegnummer_lang', { n: BELEGNUMMER_MAX }) : '');
    return !zuLang && !istLink;
  };
  belegnummer.addEventListener('input', belegnummerPruefen);
  const zaehlen = (schritt) => {
    const m = /^(.*?)(\d+)(\D*)$/.exec(belegnummer.value);
    if (!m) return;
    const n = Math.max(0, Number(m[2]) + schritt);
    belegnummer.value = m[1] + String(n).padStart(m[2].length, '0') + m[3];
    belegnummerPruefen();
  };

  // ── Zeilentabelle für Splitbuchungen (F4) ──────────────────────────────────────────────
  const splitZeilen = [];
  const splitKoerper = h('tbody');
  const rest = h('div', { class: 'split-rest', id: 'split-rest' });
  function restZeigen() {
    let soll = 0;
    let haben = 0;
    for (const z of splitZeilen) { soll += cent(z.soll.wert() || ''); haben += cent(z.haben.wert() || ''); }
    const d = soll - haben;
    rest.className = ['split-rest', d === 0 && soll > 0 ? 'ausgeglichen' : ''].join(' ').trim();
    rest.textContent = t('split.summen', { soll: centZeigen(soll), haben: centZeigen(haben) }) + ' · '
      + (d === 0 ? t('split.ausgeglichen') : t(d > 0 ? 'split.rest_haben' : 'split.rest_soll', { betrag: centZeigen(Math.abs(d)) }));
  }
  function splitZeile(d = {}) {
    const kf = kontoFeld(`split-konto-${splitZeilen.length + 1}`, t('hilfe.konto_zeile'));
    const soll = betragFeld({ 'aria-label': t('spalte.soll') });
    const haben = betragFeld({ 'aria-label': t('spalte.haben') });
    const tg = tagWahl(d.tags || []);
    kf.ein.value = d.konto || '';
    kf.ein.dispatchEvent(new Event('input'));
    if (d.betrag) (d.seite === 'H' ? haben : soll).setzen(d.betrag);
    soll.ein.addEventListener('change', () => { if (soll.ein.value) haben.ein.value = ''; restZeigen(); });
    haben.ein.addEventListener('change', () => { if (haben.ein.value) soll.ein.value = ''; restZeigen(); });
    const z = { kf, soll, haben, tg };
    z.tr = h('tr', {},
      h('td', { class: 'konto-zelle' }, kf.eingabe),
      h('td', {}, kf.name, kf.hinweis),
      h('td', { class: 'zahl' }, soll.ein, soll.hinweis), h('td', { class: 'zahl' }, haben.ein, haben.hinweis),
      h('td', {}, tg.el),
      h('td', { class: 'eng' }, knopf('✕', () => {
        splitZeilen.splice(splitZeilen.indexOf(z), 1);
        z.tr.remove();
        restZeigen();
      }, 'klein', { title: t('split.entfernen'), tabindex: '-1' })));
    splitZeilen.push(z);
    splitKoerper.append(z.tr);
    return z;
  }
  const splitTabelle = h('div', { class: 'split', hidden: true },
    h('table', { class: 'liste split-tabelle' },
      h('thead', {}, h('tr', {}, [t('spalte.konto'), t('spalte.bezeichnung'), t('spalte.soll'), t('spalte.haben'), t('feld.tags'), ''].map((x) => h('th', {}, x)))),
      splitKoerper),
    h('div', { class: 'split-fuss' }, knopf(t('split.zeile'), () => { splitZeile().kf.ein.focus(); }, 'klein', { id: 'split-zeile', title: t('hilfe.split_zeile') }), rest));

  /** Zeilen der einfachen Felder als Liste (für den Wechsel in die Tabelle und zum Speichern). */
  function einfacheZeilen() {
    const w = betrag.wert();
    const tg = tags.wert();
    const zeilen = [];
    if (konto.wert() || w) zeilen.push({ konto: konto.wert() || null, seite: 'S', betrag: w || null, tags: tg });
    if (gegen.wert() || w) zeilen.push({ konto: gegen.wert() || null, seite: 'H', betrag: w || null, tags: tg });
    return zeilen;
  }
  function splitDaten() {
    const zeilen = [];
    for (const z of splitZeilen) {
      const so = z.soll.wert();
      const ha = z.haben.wert();
      if (so === null || ha === null) throw new Error(t('hinweis.betrag'));
      if (!z.kf.wert() && !so && !ha) continue;
      zeilen.push({ konto: z.kf.wert() || null, seite: ha ? 'H' : 'S', betrag: so || ha || null, tags: z.tg.wert() });
    }
    return zeilen;
  }
  const istEinfach = (zeilen) => zeilen.length <= 2 && zeilen.filter((z) => z.seite === 'S').length <= 1 && zeilen.filter((z) => z.seite === 'H').length <= 1
    && (zeilen.length < 2 || (zeilen[0].betrag === zeilen[1].betrag && JSON.stringify(zeilen[0].tags || []) === JSON.stringify(zeilen[1].tags || [])));

  function splitSetzen(an, zeilen) {
    split = an;
    splitZeilen.splice(0);
    splitKoerper.replaceChildren();
    if (an) {
      zeilen.forEach((z) => splitZeile(z));
      splitZeile();
      restZeigen();
    }
    splitTabelle.hidden = !an;
    einfach.forEach((e) => { e.hidden = an; });
    knoepfe.aufteilen.textContent = t(an ? 'aktion.zusammen' : 'aktion.aufteilen');
    knoepfe.umkehren.hidden = an;
  }
  function einfachSetzen(zeilen) {
    const so = zeilen.find((z) => z.seite === 'S');
    const ha = zeilen.find((z) => z.seite === 'H');
    konto.setzen(so?.konto);
    gegen.setzen(ha?.konto);
    betrag.setzen((so || ha)?.betrag || '');
    tags.setzen((so || ha)?.tags || []);
  }

  // ── Aufbau in festen Spalten (M12): Beschriftung | Eingabe | Bezeichnung bzw. Hinweis | Saldo ──
  const zeile = (id, beschriftung, eingabe, info = null, rechts = null) => h('div', { class: 'formzeile' },
    h('label', { for: id }, beschriftung), h('div', { class: 'f-eingabe' }, eingabe), h('div', { class: 'f-info' }, info), h('div', { class: 'f-rechts' }, rechts));
  const kontoZeile = (id, beschriftung, f) => zeile(id, beschriftung, f.eingabe, [f.name, f.hinweis], f.saldo);
  const tagsZeile = zeile('', t('feld.tags'), tags.el);
  const einfach = [
    kontoZeile('konto', t('feld.konto'), konto),
    zeile('betrag', t('feld.betrag'), betrag.ein, betrag.hinweis),
    kontoZeile('gegenkonto', t('feld.gegenkonto'), gegen),
    tagsZeile,
  ];
  const textZeile = h('div', { class: 'formzeile breit' }, h('label', { for: 'text' }, t('feld.text')), h('div', { class: 'f-eingabe' }, text, textliste));
  const status = h('div', { class: 'buchungsstatus', id: 'buchungsstatus', hidden: true });
  const knoepfe = {
    aufteilen: knopf(t('aktion.aufteilen'), () => {
      if (!split) { splitSetzen(true, einfacheZeilen()); return; }
      let zeilen;
      try { zeilen = splitDaten(); } catch (e) { k.melden(e.message, 'fehler'); return; }
      if (!istEinfach(zeilen)) { k.melden(t('split.nicht_einfach'), 'warnung'); return; }
      splitSetzen(false, []);
      einfachSetzen(zeilen);
    }, '', { id: 'aufteilen', title: t('hilfe.aufteilen') }),
    umkehren: knopf(t('aktion.umkehren'), umkehren, '', { id: 'umkehren', title: t('hilfe.umkehren') }),
    speichern: knopf(t('aktion.speichern'), () => speichern(false), darfBuchen ? '' : 'primaer', { id: 'speichern', title: t('hilfe.speichern') }),
    buchen: darfBuchen ? knopf(t('aktion.buchen'), () => speichern(true), 'primaer', { id: 'buchen', title: t('hilfe.buchen') }) : null,
    abbrechen: knopf(t('aktion.abbrechen'), () => k.navigieren(listenPfad), '', { id: 'abbrechen', title: t('hilfe.abbrechen') }),
  };
  const formular = h('form', { class: 'karte erfassung', id: 'erfassung', onsubmit: (ev) => ev.preventDefault() },
    status, ...einfach.slice(0, 3),
    // Buchungsdatum steht neben dem Belegdatum, nicht in einer eigenen Zeile
    h('div', { class: 'formzeile datumzeile' }, h('label', { for: 'belegdatum' }, t('feld.belegdatum')), h('div', { class: 'f-eingabe' }, belegdatum.el),
      h('div', { class: 'f-info' }, tag, h('span', { class: 'neben-feld' }, h('label', { for: 'buchungsdatum', class: 'neben' }, t('feld.buchungsdatum')), buchungsdatum.el))),
    zeile('belegnummer', t('feld.belegnummer'), h('span', { class: 'konto-eingabe' }, belegnummer,
      knopf('▲', () => zaehlen(1), 'klein', { title: t('aktion.hoch'), tabindex: '-1' }), knopf('▼', () => zaehlen(-1), 'klein', { title: t('aktion.runter'), tabindex: '-1' })),
    belegnummerHinweis),
    h('div', { class: 'formzeile breit' }, h('label', { for: 'beleg' }, t('feld.beleg')), h('div', { class: 'f-eingabe' }, beleg, belegOeffnen, belegHinweis)),
    textZeile,
    zeile('buchungsart', t('feld.buchungsart'), art),
    tagsZeile, splitTabelle,
    h('div', { class: 'leiste-unten' }, knoepfe.aufteilen, knoepfe.umkehren, h('span', { class: 'luecke' }),
      knoepfe.speichern, knoepfe.buchen, knoepfe.abbrechen));

  // Geschäftsvorfälle links (Abschnitt 3): belegen das Formular vor
  const gruppen = new Map();
  for (const v of vorlagen) gruppen.set(v.gruppe, [...(gruppen.get(v.gruppe) || []), v]);
  const baum = h('nav', { class: 'karte vorfaelle', 'aria-label': t('vorfaelle.titel') }, h('h2', {}, t('vorfaelle.titel')),
    gruppen.size ? [...gruppen].map(([g, liste]) => h('details', { open: true }, h('summary', {}, g),
      h('ul', {}, liste.map((v) => h('li', {}, h('button', { type: 'button', class: 'vorfall', title: t('hilfe.vorfall'), onclick: () => vorlageAnwenden(v) }, v.name))))))
      : h('p', { class: 'hinweis' }, t(k.darf('buchen.vorlage.sehen') ? 'vorfaelle.keine' : 'vorfaelle.kein_recht')),
    k.darf('buchen.vorlage.pflegen') ? h('a', { class: 'hinweis', href: `#/m/${k.mandant}/buchen/vorlagen` }, t('vorfaelle.pflegen')) : null);

  b.append(kopf());
  const f = fehlendes(s);
  if (f.etwas || s.konten.length === 0) {
    b.append(h('div', { class: 'karte', id: 'einrichtung-fehlt' }, h('h2', {}, t('einrichtung.fehlt')),
      h('ul', {},
        s.konten.length === 0 ? h('li', {}, t('einrichtung.fehlt_konten')) : null,
        f.jahr ? h('li', {}, t('einrichtung.fehlt_jahr')) : null,
        f.art ? h('li', {}, t('einrichtung.fehlt_art')) : null,
        f.kreise.map((x) => h('li', {}, t('einrichtung.fehlt_kreis', x)))),
      k.darf('kern.geschaeftsjahr.anlegen') ? knopf(t('einrichtung.oeffnen'), () => k.navigieren(`#/m/${k.mandant}/buchen/einrichtung`), 'primaer')
        : h('p', { class: 'hinweis' }, t('einrichtung.kein_recht'))));
  }
  b.append(h('div', { class: 'erfassen' }, baum, formular));

  // ── Abläufe ────────────────────────────────────────────────────────────────────────────
  function umkehren() {
    if (split) return;
    const a = konto.wert();
    konto.setzen(gegen.wert());
    gegen.setzen(a);
  }

  function vorlageAnwenden(v) {
    if (split) splitSetzen(false, []);
    if (v.konto) konto.setzen(v.konto);
    if (v.gegenkonto) gegen.setzen(v.gegenkonto);
    if (v.buchungsart && s.buchungsarten.some((a) => a.kuerzel === v.buchungsart)) art.value = v.buchungsart;
    if (v.text) text.value = v.text;
    if (v.tags.length) tags.setzen(v.tags);
    (konto.wert() ? betrag.ein : konto.ein).focus();
  }

  function inhalt() {
    if (!split && betrag.wert() === null) throw new Error(t('hinweis.betrag'));
    const bd = belegdatum.wert();
    const bud = buchungsdatum.wert();
    if (bd === null || bud === null) throw new Error(t('hinweis.datum'));
    if (!belegnummerPruefen()) throw new Error(belegnummerHinweis.textContent);
    if (!belegPruefen()) throw new Error(t('hinweis.beleg_link'));
    // Das Feld trägt den ersten Verweis; weitere (über die API angelegt) bleiben erhalten
    const link = linkLesen();
    const rest = belegeAlt.slice(1).filter((x) => x.link && x.link !== link);
    const erster = link ? [belegeAlt.find((x) => x.link === link) || { link }] : [];
    return {
      belege: [...erster, ...rest].map((x) => (x.md5 ? { link: x.link, md5: x.md5, sha256: x.sha256 } : { link: x.link })),
      buchungsart: art.value || null,
      belegdatum: bd || null,
      buchungsdatum: bud || bd || null,
      belegnummer: belegnummer.value.trim() || null,
      text: text.value.trim() || null,
      zeilen: split ? splitDaten() : einfacheZeilen(),
    };
  }

  function statusZeigen() {
    status.hidden = !akt;
    if (!akt) return;
    status.replaceChildren(h('span', { class: ['marke-klein', akt.status === 'freigabebereit' ? 'korrigiert' : 'warnung'] }, t(`status.${akt.status}`)),
      h('span', {}, t('status.zeile_stapel')));
  }

  function sperren(an) {
    for (const x of [knoepfe.speichern, knoepfe.buchen, knoepfe.aufteilen, knoepfe.umkehren]) if (x) x.disabled = an;
  }

  /** Nach dem Speichern bleibt das Formular offen und nimmt Datum und Belegnummer mit. */
  function naechste() {
    if (text.value.trim() && !texte.has(text.value.trim())) {
      texte.add(text.value.trim());
      textliste.append(h('option', { value: text.value.trim() }));
    }
    akt = null;
    statusZeigen();
    if (split) splitSetzen(false, []);
    einfachSetzen([]);
    text.value = '';
    beleg.value = '';
    belegeAlt = [];
    belegPruefen();
    history.replaceState(null, '', `#/m/${k.mandant}/buchen/erfassen`);
    konto.ein.focus();
  }

  async function speichern(mitBuchen) {
    if (mitBuchen && !darfBuchen) return;
    // Was gerade noch im Feld steht, wird erst beim Verlassen ausgewertet
    if (document.activeElement instanceof HTMLElement && formular.contains(document.activeElement)) {
      document.activeElement.dispatchEvent(new Event('change', { bubbles: true }));
    }
    let daten;
    try { daten = inhalt(); } catch (e) { k.melden(e.message, 'fehler'); return; }
    sperren(true);
    try {
      let r;
      if (akt) {
        r = await k.api('PUT', `${basis}/buchungen/${akt.id}`, { json: daten, kopf: { 'If-Match': akt.etag || '*' }, mitKopf: true });
        akt = { id: r.daten.id, etag: r.kopf.get('ETag'), status: r.daten.status };
        if (mitBuchen) {
          r = await k.api('POST', `${basis}/buchungen/${akt.id}/vorbereiten`, { kopf: { 'If-Match': akt.etag }, mitKopf: true });
          akt = { id: r.daten.id, etag: r.kopf.get('ETag'), status: r.daten.status };
        }
      } else {
        r = await k.api('POST', `${basis}/buchungen`, { json: mitBuchen ? { ...daten, vorbereiten: true } : daten, mitKopf: true });
        akt = { id: r.daten.id, etag: r.kopf.get('ETag'), status: r.daten.status };
      }
      if (!mitBuchen) {
        k.melden(t('meldung.gespeichert'), 'erfolg');
        naechste();
        return;
      }
      let erg;
      try {
        erg = await freigeben(k, [akt.id], s, true);
      } catch (e) {
        // Der Entwurf steht im Stapel; nur das Festschreiben ging nicht (z. B. Nummernkreis fehlt)
        statusZeigen();
        if (e?.name === 'NotAllowedError') k.melden(t('meldung.freigabe_abgebrochen'), 'warnung');
        else k.melden(t('meldung.nicht_festgeschrieben', { grund: e.message }), 'warnung');
        return;
      }
      if (!erg) { statusZeigen(); k.melden(t('meldung.freigabe_abgebrochen'), 'warnung'); return; }
      k.melden(t('meldung.gebucht', { nr: erg.buchungen[0].nr }), 'erfolg');
      naechste();
    } catch (e) {
      k.fehler(e);
    } finally {
      sperren(false);
    }
  }

  /** Entwurf in das Formular laden (zum Bearbeiten) oder eine Buchung als Vorlage für eine neue (Kopie). */
  async function laden(id, alsKopie) {
    const r = await k.api('GET', `${basis}/buchungen/${id}`, { mitKopf: true });
    const x = r.daten;
    if (x.buchungsart && s.buchungsarten.some((a) => a.kuerzel === x.buchungsart)) art.value = x.buchungsart;
    belegdatum.setzen(x.belegdatum || heute());
    buchungsdatum.setzen(x.buchungsdatum || belegdatum.wert());
    folgt = buchungsdatum.wert() === belegdatum.wert();
    tag.textContent = wochentag(belegdatum.wert());
    buchungstagZeigen();
    belegnummer.value = x.belegnummer || '';
    text.value = x.text || '';
    // Ein Beleg gehört zu seiner Buchung: Eine Kopie beginnt ohne
    belegeAlt = alsKopie ? [] : x.belege.filter((y) => y.link);
    beleg.value = belegeAlt[0]?.link || '';
    belegPruefen();
    if (!alsKopie) akt = { id: x.id, etag: r.kopf.get('ETag'), status: x.status };
    if (istEinfach(x.zeilen)) einfachSetzen(x.zeilen); else splitSetzen(true, x.zeilen);
    statusZeigen();
  }

  /** Belege einer festgeschriebenen Buchung: die mit festgeschriebenen und die nachgereichten (F27, F88). */
  function belegListe(x) {
    const alle = [...x.belege.map((y) => ({ ...y, nach: false })), ...(x.belege_nachgereicht || []).map((y) => ({ ...y, nach: true }))];
    if (!alle.length) return null;
    return h('div', { class: 'fest-belege', id: 'fest-belege' }, h('h3', {}, t('ansicht.belege')),
      h('ul', {}, alle.map((y) => h('li', {},
        y.link ? h('a', { href: y.link, target: '_blank', rel: 'noopener noreferrer', title: t('hilfe.beleg_oeffnen') }, y.link)
          : h('span', {}, t('ansicht.beleg_alt', { id: y.paperless_id })),
        y.md5 ? h('span', { class: 'marke-klein korrigiert', title: `SHA-256 ${y.sha256}` }, t('ansicht.beleg_geprueft'))
          : h('span', { class: 'marke-klein warnung', title: t('hilfe.beleg_ungeprueft') }, t('ansicht.beleg_ungeprueft')),
        y.nach ? h('span', { class: 'marke-klein' }, t('ansicht.beleg_nachgereicht')) : null))));
  }

  /** Festgeschriebene Buchung (M14): feste Ansicht statt Formular – Text statt Eingabefeldern, Schloss, Hash. */
  function ansicht(x) {
    const konten = (nr) => s.konto(nr)?.bezeichnung || '';
    const wer = (subjekt) => (subjekt === k.ich.subjekt ? t('ansicht.du') : subjekt);
    const summe = (seite) => centZeigen(x.zeilen.filter((z) => z.seite === seite).reduce((n, z) => n + cent(z.betrag), 0));
    const verweis = (id, schluessel) => h('a', { href: `#/m/${k.mandant}/buchen/erfassen?id=${id}` }, t(schluessel));
    const darfStorno = k.darf('kern.storno.vorschlagen') && !x.storniert_durch;
    const eintrag = (titel, wert) => [h('dt', {}, titel), h('dd', {}, wert || '–')];
    const karte = h('div', { class: 'karte festgeschrieben', id: 'festgeschrieben' },
      h('div', { class: 'fest-kopf' },
        h('img', { class: 'schloss', src: new URL('./symbole/schloss.svg', import.meta.url).href, alt: '' }),
        h('strong', {}, t('ansicht.fest')),
        x.testbetrieb ? h('span', { class: 'marke-klein warnung', title: t('status.testbuchung_lang') }, t('status.testbuchung')) : null,
        x.storniert_durch ? h('span', { class: 'marke-klein fehler' }, t('status.storniert')) : null,
        x.storno_von ? h('span', { class: 'marke-klein' }, t('status.storno')) : null),
      x.storniert_durch ? h('p', { class: 'fest-hinweis' }, t('ansicht.storniert_durch'), ' ', verweis(x.storniert_durch, 'ansicht.zum_storno')) : null,
      x.storno_von ? h('p', { class: 'fest-hinweis' }, t('ansicht.storno_von'), ' ', verweis(x.storno_von, 'ansicht.zum_original')) : null,
      h('dl', { class: 'eigenschaften fest-daten' },
        eintrag(t('spalte.nr'), h('strong', {}, x.nr)), eintrag(t('ansicht.journal'), String(x.journal_nr)),
        eintrag(t('feld.belegdatum'), `${datumZeigen(x.belegdatum)} (${wochentag(x.belegdatum)})`),
        eintrag(t('feld.buchungsdatum'), datumZeigen(x.buchungsdatum)),
        eintrag(t('feld.belegnummer'), x.belegnummer), eintrag(t('feld.text'), x.text), eintrag(t('feld.buchungsart'), x.buchungsart),
        eintrag(t('ansicht.erfasst'), `${zeit(x.erfasst_am)} · ${wer(x.erfasst_von)}`),
        eintrag(t('ansicht.festgeschrieben'), `${zeit(x.festgeschrieben_am)} · ${wer(x.festgeschrieben_von)}`)),
      h('table', { class: 'liste fest-zeilen' },
        h('thead', {}, h('tr', {}, [t('spalte.konto'), t('spalte.bezeichnung'), t('spalte.soll'), t('spalte.haben'), t('feld.tags')].map((y, i) => h('th', { class: i === 2 || i === 3 ? 'zahl' : '' }, y)))),
        h('tbody', {}, x.zeilen.map((z) => h('tr', {}, h('td', { class: 'nr' }, z.konto), h('td', {}, konten(z.konto)),
          h('td', { class: 'zahl' }, z.seite === 'S' ? betragZeigen(z.betrag) : ''), h('td', { class: 'zahl' }, z.seite === 'H' ? betragZeigen(z.betrag) : ''),
          h('td', {}, z.tags.join(', ')))),
        h('tr', { class: 'saldozeile' }, h('td', { colspan: '2' }, t('ansicht.summe')), h('td', { class: 'zahl' }, summe('S')), h('td', { class: 'zahl' }, summe('H')), h('td', {})))),
      belegListe(x),
      h('p', { class: 'hinweis hash', title: t('hilfe.hash') }, `${t('ansicht.hash')} ${x.hash}`),
      h('div', { class: 'leiste-unten' },
        k.darf('kern.entwurf.anlegen') ? knopf(t('aktion.kopieren'), () => k.navigieren(`#/m/${k.mandant}/buchen/erfassen?kopie=${x.id}`), '', { id: 'kopieren', title: t('hilfe.kopieren') }) : null,
        darfStorno ? knopf(t('aktion.stornieren'), async () => {
          if (!await bestaetigen(t('storno.titel'), t('storno.frage'), t('aktion.stornieren'))) return;
          try {
            await k.api('POST', `${basis}/buchungen/${x.id}/storno`, { json: {} });
            k.navigieren(`${listenPfad}?ansicht=stapel`);
          } catch (e) { k.fehler(e); }
        }, 'gefahr', { id: 'stornieren', title: t('hilfe.stornieren') }) : null,
        knopf(t('aktion.zur_liste'), () => k.navigieren(listenPfad), 'primaer', { id: 'zur-liste', title: t('hilfe.zur_liste') })));
    karte.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') k.navigieren(listenPfad); });
    queueMicrotask(() => karte.querySelector('#zur-liste')?.focus());
    return karte;
  }

  // ── Tastatur (M6, M15): Tab und Enter von Feld zu Feld, Strg+Enter speichern, Strg+Umschalt+Enter buchen, F4 umkehren, Esc zurück ──
  formular.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) {
      ev.preventDefault();
      speichern(ev.shiftKey && darfBuchen);
      return;
    }
    if (ev.key === 'F4') { ev.preventDefault(); umkehren(); return; }
    if (ev.key === 'Escape') { ev.preventDefault(); k.navigieren(listenPfad); return; }
    const ziel = ev.target;
    if (ev.key === 'Enter' && (ziel instanceof HTMLInputElement || ziel instanceof HTMLSelectElement) && ziel.type !== 'checkbox') {
      ev.preventDefault();
      const felder = [...formular.querySelectorAll('input:not([type=checkbox]):not(.datum-kalender), select')].filter((e) => !e.disabled && e.offsetParent !== null);
      const naechstes = felder[felder.indexOf(ziel) + 1];
      (naechstes || knoepfe.buchen || knoepfe.speichern).focus();
    }
  });

  if (k.param.id || k.param.kopie) {
    try { await laden(k.param.id || k.param.kopie, !k.param.id); } catch (e) { k.fehler(e); }
  }
  konto.ein.focus();
}
