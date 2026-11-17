// SPDX-License-Identifier: AGPL-3.0-or-later
// Buchungserfassung (BUCHUNGSMASKE.md Abschnitt 2 und 3, M1–M6): Formular mit Konto/Gegenkonto,
// Zeilentabelle für Splitbuchungen, Geschäftsvorfälle links. Speichern = Entwurf im Stapel,
// Buchen = vorbereiten und festschreiben. Alles über die REST-API, alles mit der Tastatur.
import { h, seitenkopf, knopf, auswahl, bestaetigen } from '/kern/ui/bausteine.js';
import {
  stilLaden, betragLesen, betragZeigen, cent, centZeigen, heute, datumZeigen, wochentag, stammdatenLaden, fehlendes,
  saldoText, kontoSuchen, freigeben, betriebsart,
} from './gemeinsam.js';

export async function zeigen(b, k) {
  stilLaden();
  const t = k.t;
  const basis = k.apiBasis;
  const s = await stammdatenLaden(k);
  const darfBuchen = k.darf('kern.buchung.festschreiben') && k.darf('kern.entwurf.aendern');
  const listenPfad = `#/m/${k.mandant}/buchen/liste`;

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
  let nurLesen = false;
  let folgt = true;      // Buchungsdatum folgt dem Belegdatum, bis es getrennt geändert wird (M3)

  // ── Felder ───────────────────────────────────────────────────────────────────────────
  function tagWahl(vor = []) {
    const kisten = s.tags.map((x) => h('input', { type: 'checkbox', value: x.name, checked: vor.includes(x.name) }));
    const kopf = h('summary');
    const wert = () => kisten.filter((c) => c.checked).map((c) => c.value);
    const zeigenKopf = () => { kopf.textContent = wert().join(', ') || '–'; };
    kisten.forEach((c) => c.addEventListener('change', zeigenKopf));
    zeigenKopf();
    const el = h('details', { class: 'tagwahl' }, kopf, h('div', { class: 'tagliste' },
      s.tags.length ? kisten.map((c) => h('label', {}, c, c.value)) : h('span', { class: 'hinweis' }, t('hinweis.keine_tags'))));
    return { el, wert, setzen: (liste) => { kisten.forEach((c) => { c.checked = (liste || []).includes(c.value); }); zeigenKopf(); },
      sperren: (an) => kisten.forEach((c) => { c.disabled = an; }) };
  }

  function kontoFeld(id) {
    const ein = h('input', { id, class: 'konto-nr', maxlength: '20', autocomplete: 'off', spellcheck: false });
    const name = h('span', { class: 'konto-name' });
    const saldo = h('span', { class: 'konto-saldo' });
    const hinweis = h('span', { class: 'feldhinweis' });
    let lauf = 0;
    const nameZeigen = () => {
      const nr = ein.value.trim();
      const x = nr ? s.konto(nr) : null;
      name.textContent = x ? x.bezeichnung : '';
      hinweis.textContent = nr && !x ? t('hinweis.konto_unbekannt') : (x && !x.aktiv ? t('hinweis.konto_inaktiv') : '');
      return x;
    };
    const pruefen = async () => {
      const x = nameZeigen();
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
    const such = knopf('…', suchen, 'klein', { title: t('suche.kurz'), tabindex: '-1' });
    return { ein, such, name, saldo, hinweis, wert: () => ein.value.trim(), setzen: (nr) => { ein.value = nr || ''; return pruefen(); } };
  }

  function betragFeld(attr = {}) {
    const ein = h('input', { class: 'betrag', inputmode: 'decimal', autocomplete: 'off', ...attr });
    const hinweis = h('span', { class: 'feldhinweis' });
    ein.addEventListener('change', () => {
      const w = betragLesen(ein.value);
      hinweis.textContent = w === null ? t('hinweis.betrag') : '';
      if (w) ein.value = betragZeigen(w);
    });
    return { ein, hinweis, wert: () => betragLesen(ein.value), setzen: (dez) => { ein.value = betragZeigen(dez); hinweis.textContent = ''; } };
  }

  const konto = kontoFeld('konto');
  const gegen = kontoFeld('gegenkonto');
  const betrag = betragFeld({ id: 'betrag' });
  const belegdatum = h('input', { id: 'belegdatum', type: 'date', value: heute() });
  const tag = h('span', { class: 'konto-name' }, wochentag(belegdatum.value));
  const buchungsdatum = h('input', { id: 'buchungsdatum', type: 'date', value: heute() });
  const belegnummer = h('input', { id: 'belegnummer', maxlength: '36', autocomplete: 'off' });
  const text = h('input', { id: 'text', maxlength: '500', list: 'buchungstexte', autocomplete: 'off' });
  const textliste = h('datalist', { id: 'buchungstexte' }, [...texte].map((x) => h('option', { value: x })));
  const art = auswahl(s.buchungsarten.map((a) => [a.kuerzel, `${a.kuerzel} – ${a.bezeichnung}`]), s.buchungsarten[0]?.kuerzel, { id: 'buchungsart' });
  const tags = tagWahl();

  belegdatum.addEventListener('change', () => {
    tag.textContent = wochentag(belegdatum.value);
    if (folgt) buchungsdatum.value = belegdatum.value;
  });
  buchungsdatum.addEventListener('change', () => {
    if (!buchungsdatum.value) buchungsdatum.value = belegdatum.value;
    folgt = buchungsdatum.value === belegdatum.value;
  });
  const zaehlen = (schritt) => {
    const m = /^(.*?)(\d+)(\D*)$/.exec(belegnummer.value);
    if (!m) return;
    const n = Math.max(0, Number(m[2]) + schritt);
    belegnummer.value = (m[1] + String(n).padStart(m[2].length, '0') + m[3]).slice(0, 36);
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
    const kf = kontoFeld(`split-konto-${splitZeilen.length + 1}`);
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
      h('td', { class: 'konto-zelle' }, kf.ein, kf.such),
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
    h('div', { class: 'split-fuss' }, knopf(t('split.zeile'), () => { splitZeile().kf.ein.focus(); }, 'klein', { id: 'split-zeile' }), rest));

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
      if (!nurLesen) splitZeile();
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

  // ── Aufbau ─────────────────────────────────────────────────────────────────────────────
  const zeile = (id, beschriftung, ...inhalt) => h('div', { class: 'formzeile' }, h('label', { for: id }, beschriftung), ...inhalt);
  const kontoZeile = (id, beschriftung, f) => zeile(id, beschriftung, h('span', { class: 'konto-eingabe' }, f.ein, f.such), f.name, f.saldo, f.hinweis);
  const einfach = [
    kontoZeile('konto', t('feld.konto'), konto),
    zeile('betrag', t('feld.betrag'), betrag.ein, betrag.hinweis),
    kontoZeile('gegenkonto', t('feld.gegenkonto'), gegen),
  ];
  const tagsZeile = zeile('', t('feld.tags'), tags.el);
  einfach.push(tagsZeile);
  const status = h('div', { class: 'buchungsstatus', id: 'buchungsstatus', hidden: true });
  const knoepfe = {
    aufteilen: knopf(t('aktion.aufteilen'), () => {
      if (!split) { splitSetzen(true, einfacheZeilen()); return; }
      let zeilen;
      try { zeilen = splitDaten(); } catch (e) { k.melden(e.message, 'fehler'); return; }
      if (!istEinfach(zeilen)) { k.melden(t('split.nicht_einfach'), 'warnung'); return; }
      splitSetzen(false, []);
      einfachSetzen(zeilen);
    }, '', { id: 'aufteilen' }),
    umkehren: knopf(t('aktion.umkehren'), umkehren, '', { id: 'umkehren', title: 'F4' }),
    speichern: knopf(t('aktion.speichern'), () => speichern(false), darfBuchen ? '' : 'primaer', { id: 'speichern', title: 'Strg+Enter' }),
    buchen: darfBuchen ? knopf(t('aktion.buchen'), () => speichern(true), 'primaer', { id: 'buchen', title: 'Strg+Umschalt+Enter' }) : null,
    abbrechen: knopf(t('aktion.abbrechen'), () => k.navigieren(listenPfad), '', { id: 'abbrechen', title: 'Esc' }),
    kopieren: knopf(t('aktion.kopieren'), () => k.navigieren(`#/m/${k.mandant}/buchen/erfassen?kopie=${akt.id}`), '', { hidden: true }),
    stornieren: knopf(t('aktion.stornieren'), stornieren, 'gefahr', { hidden: true }),
  };
  const formular = h('form', { class: 'karte erfassung', id: 'erfassung', onsubmit: (ev) => ev.preventDefault() },
    status, ...einfach.slice(0, 3),
    zeile('belegdatum', t('feld.belegdatum'), belegdatum, tag, h('label', { for: 'buchungsdatum', class: 'neben' }, t('feld.buchungsdatum')), buchungsdatum),
    zeile('belegnummer', t('feld.belegnummer'), belegnummer,
      knopf('▲', () => zaehlen(1), 'klein', { title: t('aktion.hoch'), tabindex: '-1' }), knopf('▼', () => zaehlen(-1), 'klein', { title: t('aktion.runter'), tabindex: '-1' })),
    zeile('text', t('feld.text'), text, textliste),
    zeile('buchungsart', t('feld.buchungsart'), art),
    tagsZeile, splitTabelle,
    h('div', { class: 'leiste-unten' }, knoepfe.aufteilen, knoepfe.umkehren, h('span', { class: 'luecke' }),
      knoepfe.kopieren, knoepfe.stornieren, knoepfe.speichern, knoepfe.buchen, knoepfe.abbrechen));

  // Geschäftsvorfälle links (Abschnitt 3): belegen das Formular vor
  const gruppen = new Map();
  for (const v of vorlagen) gruppen.set(v.gruppe, [...(gruppen.get(v.gruppe) || []), v]);
  const baum = h('nav', { class: 'karte vorfaelle', 'aria-label': t('vorfaelle.titel') }, h('h2', {}, t('vorfaelle.titel')),
    gruppen.size ? [...gruppen].map(([g, liste]) => h('details', { open: true }, h('summary', {}, g),
      h('ul', {}, liste.map((v) => h('li', {}, h('button', { type: 'button', class: 'vorfall', onclick: () => vorlageAnwenden(v) }, v.name))))))
      : h('p', { class: 'hinweis' }, t(k.darf('buchen.vorlage.sehen') ? 'vorfaelle.keine' : 'vorfaelle.kein_recht')),
    k.darf('buchen.vorlage.pflegen') ? h('a', { class: 'hinweis', href: `#/m/${k.mandant}/buchen/vorlagen` }, t('vorfaelle.pflegen')) : null);

  const test = betriebsart(k) === 'test';
  b.append(seitenkopf(t('erfassen.titel', { mandant: k.mandantName || k.mandant }),
    test ? h('span', { class: 'marke-klein warnung' }, t('hinweis.testbetrieb')) : null,
    knopf(t('aktion.zur_liste'), () => k.navigieren(listenPfad))));
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
  const rahmen = h('div', { class: 'erfassen' }, baum, formular);
  b.append(rahmen);

  // ── Abläufe ────────────────────────────────────────────────────────────────────────────
  function umkehren() {
    if (split || nurLesen) return;
    const a = konto.wert();
    konto.setzen(gegen.wert());
    gegen.setzen(a);
  }

  function vorlageAnwenden(v) {
    if (nurLesen) return;
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
    return {
      buchungsart: art.value || null,
      belegdatum: belegdatum.value || null,
      buchungsdatum: buchungsdatum.value || belegdatum.value || null,
      belegnummer: belegnummer.value.trim() || null,
      text: text.value.trim() || null,
      zeilen: split ? splitDaten() : einfacheZeilen(),
    };
  }

  function statusZeigen(x = null) {
    status.hidden = !akt;
    if (!akt) return;
    status.replaceChildren(h('span', { class: ['marke-klein', akt.status === 'festgeschrieben' ? 'korrigiert' : 'warnung'] }, t(`status.${akt.status}`)),
      akt.status === 'festgeschrieben' && x
        ? h('span', {}, t('status.zeile_fest', { nr: x.nr, journal: x.journal_nr, am: new Date(x.festgeschrieben_am).toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' }) }),
          x.testbetrieb ? h('span', { class: 'marke-klein warnung' }, t('status.testbuchung')) : null,
          x.storniert_durch ? h('span', { class: 'marke-klein fehler' }, t('status.storniert')) : null,
          x.storno_von ? h('span', { class: 'marke-klein' }, t('status.storno')) : null)
        : h('span', {}, t('status.zeile_stapel')));
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
    history.replaceState(null, '', `#/m/${k.mandant}/buchen/erfassen`);
    konto.ein.focus();
  }

  async function speichern(mitBuchen) {
    if (nurLesen || (mitBuchen && !darfBuchen)) return;
    // Ein Betrag, der gerade noch im Feld steht, wird erst beim Verlassen ausgewertet
    if (document.activeElement instanceof HTMLElement && formular.contains(document.activeElement)) document.activeElement.dispatchEvent(new Event('change'));
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

  async function stornieren() {
    if (!akt || !await bestaetigen(t('storno.titel'), t('storno.frage'), t('aktion.stornieren'))) return;
    try {
      await k.api('POST', `${basis}/buchungen/${akt.id}/storno`, { json: {} });
      k.navigieren(`${listenPfad}?ansicht=stapel`);
    } catch (e) { k.fehler(e); }
  }

  async function laden(id, alsKopie) {
    const r = await k.api('GET', `${basis}/buchungen/${id}`, { mitKopf: true });
    const x = r.daten;
    if (x.buchungsart && s.buchungsarten.some((a) => a.kuerzel === x.buchungsart)) art.value = x.buchungsart;
    belegdatum.value = x.belegdatum || heute();
    buchungsdatum.value = x.buchungsdatum || belegdatum.value;
    folgt = buchungsdatum.value === belegdatum.value;
    tag.textContent = wochentag(belegdatum.value);
    belegnummer.value = x.belegnummer || '';
    text.value = x.text || '';
    if (!alsKopie) {
      akt = { id: x.id, etag: r.kopf.get('ETag'), status: x.status };
      nurLesen = x.status === 'festgeschrieben';
    }
    if (istEinfach(x.zeilen)) einfachSetzen(x.zeilen); else splitSetzen(true, x.zeilen);
    statusZeigen(x);
    if (nurLesen) {
      formular.querySelectorAll('input, select').forEach((e) => { e.disabled = true; });
      formular.querySelectorAll('.konto-eingabe .knopf, .konto-zelle .knopf, .formzeile > .knopf, .split .knopf').forEach((e) => { e.hidden = true; });
      for (const x2 of [knoepfe.speichern, knoepfe.buchen, knoepfe.aufteilen, knoepfe.umkehren]) if (x2) x2.hidden = true;
      knoepfe.kopieren.hidden = !k.darf('kern.entwurf.anlegen');
      knoepfe.stornieren.hidden = !k.darf('kern.storno.vorschlagen') || Boolean(x.storniert_durch);
      knoepfe.abbrechen.textContent = t('aktion.zur_liste');
      baum.hidden = true;
      rahmen.classList.add('ohne-baum');
    }
  }

  // ── Tastatur (M6): Enter zum nächsten Feld, Strg+Enter speichern, Strg+Umschalt+Enter buchen, F4 umkehren, Esc zurück ──
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
      const felder = [...formular.querySelectorAll('input:not([type=checkbox]), select')].filter((e) => !e.disabled && e.offsetParent !== null);
      const naechstes = felder[felder.indexOf(ziel) + 1];
      (naechstes || knoepfe.buchen || knoepfe.speichern).focus();
    }
  });

  if (k.param.id || k.param.kopie) {
    try { await laden(k.param.id || k.param.kopie, !k.param.id); } catch (e) { k.fehler(e); }
  }
  if (!nurLesen) konto.ein.focus();
}
