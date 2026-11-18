// SPDX-License-Identifier: AGPL-3.0-or-later
// Gemeinsames der Seiten des Moduls buchen: Beträge, Datum, Stammdaten, Kontensuche, Freigabe.
import { h, knopf, datumText as datumZeigen } from '/kern/ui/bausteine.js';
import { passkeyBestaetigen } from '/kern/ui/api.js';

/** Stylesheet des Moduls einmal einbinden (eigene Datei, keine style-Attribute – strenge CSP). */
export function stilLaden() {
  const url = new URL('./buchen.css', import.meta.url).href;
  if (!document.querySelector(`link[href="${url}"]`)) document.head.append(h('link', { rel: 'stylesheet', href: url }));
}

// ── Beträge: in der API Dezimal-Strings ("109.43"), gerechnet wird in Cent (F19, F24) ──
const zahlformat = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
export const cent = (dez) => (dez ? Math.round(Number(dez) * 100) : 0);
export const dezimal = (c) => (c / 100).toFixed(2);
export const betragZeigen = (dez) => (dez === null || dez === undefined || dez === '' ? '' : zahlformat.format(Number(dez)));
export const centZeigen = (c) => zahlformat.format(c / 100);

/** Eine eingegebene Zahl: Komma als Dezimalzeichen, Punkte nur als Tausenderpunkte; "109.43" (Ziffernblock) geht auch. */
function zahl(s) {
  let m;
  if ((m = /^(\d{1,3}(?:\.\d{3})+|\d+)(?:,(\d+))?$/.exec(s))) return Number(m[1].replace(/\./g, '') + '.' + (m[2] || '0'));
  if (/^\d+\.\d{1,2}$/.test(s)) return Number(s);
  return NaN;
}

/**
 * Betrag aus dem Eingabefeld lesen. Rechnen im Feld ist erlaubt (100+9,43; Klammern, + − * /).
 * art (Einstellung des Nutzers, M10): 'dezimal' – 1000 ist 1.000,00; 'kaufmaennisch' – eine reine
 * Ziffernfolge zählt in Cent, 1000 ist 10,00. Mit Komma und in Rechnungen gilt immer, was dasteht.
 * Liefert einen Dezimal-String mit zwei Nachkommastellen, '' für leer oder null, wenn ungültig oder nicht positiv.
 */
export function betragLesen(text, art = 'dezimal') {
  const s = String(text || '').replace(/\s+/g, '').replace(/[−–]/g, '-').replace(/×/g, '*').replace(/÷|:/g, '/');
  if (s === '') return '';
  if (art === 'kaufmaennisch' && /^\d{1,15}$/.test(s)) {
    const c = Number(s);
    return c > 0 ? dezimal(c) : null;
  }
  const teile = s.match(/\d[\d.,]*|[-+*/()]/g);
  if (!teile || teile.join('') !== s) return null;
  let i = 0;
  const faktor = () => {
    const t = teile[i++];
    if (t === '(') { const w = summe(); if (teile[i++] !== ')') return NaN; return w; }
    if (t === '-') return -faktor();
    if (t === '+') return faktor();
    return t === undefined ? NaN : zahl(t);
  };
  const produkt = () => {
    let w = faktor();
    while (teile[i] === '*' || teile[i] === '/') { const op = teile[i++]; const r = faktor(); w = op === '*' ? w * r : w / r; }
    return w;
  };
  function summe() {
    let w = produkt();
    while (teile[i] === '+' || teile[i] === '-') { const op = teile[i++]; const r = produkt(); w = op === '+' ? w + r : w - r; }
    return w;
  }
  const w = summe();
  if (i !== teile.length || !Number.isFinite(w)) return null;
  // Eine einzelne Zahl mit mehr als zwei Nachkommastellen ist ein Tippfehler, kein Rundungsfall
  if (teile.length === 1 && Math.abs(w * 100 - Math.round(w * 100)) > 1e-6) return null;
  const c = Math.round(w * 100 + (w >= 0 ? 1e-7 : -1e-7));
  return c > 0 && c < 1e15 ? dezimal(c) : null;
}

// ── Datum: Schreibweise nach der Sprache des Nutzers, sonst des Browsers (M11) – Bausteine des Kerns ──
export { heute, wochentag, datumText as datumZeigen } from '/kern/ui/bausteine.js';

// ── Stammdaten des Mandanten ───────────────────────────────────────────────────────────────
export async function stammdatenLaden(k) {
  const b = k.apiBasis;
  const [konten, buchungsarten, tags, geschaeftsjahre, nummernkreise] = await Promise.all([
    k.api('GET', `${b}/konten`), k.api('GET', `${b}/buchungsarten`), k.api('GET', `${b}/tags`),
    k.api('GET', `${b}/geschaeftsjahre`), k.api('GET', `${b}/nummernkreise`),
  ]);
  const nach = new Map(konten.map((x) => [x.nr, x]));
  // Steuersätze der Steuerkonten (F101); ohne Recht oder bei einem Fehler wird nichts gerechnet
  let saetze = new Map();
  try { saetze = new Map((await k.api('GET', `${b}/buchen/steuerkonten`)).steuerkonten.map((x) => [x.konto, Number(x.satz)])); } catch { /* dann ohne Steuerrechnung */ }
  return { konten, buchungsarten, tags, geschaeftsjahre, nummernkreise, konto: (nr) => nach.get(nr) || null, steuersatz: (nr) => saetze.get(nr) || null, steuerkonten: saetze };
}

/** Geschäftsjahr, in dem das Datum liegt (sonst das letzte). */
export function geschaeftsjahrZu(jahre, datum) {
  return jahre.find((g) => g.beginn <= datum && datum <= g.ende) || jahre[jahre.length - 1] || null;
}

/** Was zum Buchen noch fehlt: Geschäftsjahr, Buchungsart, Nummernvorlage je offenem Jahr (F25). */
export function fehlendes(s) {
  const kreise = [];
  for (const nk of new Set(s.buchungsarten.map((a) => a.nummernkreis))) {
    const jahre = s.nummernkreise.find((n) => n.kuerzel === nk)?.jahre || [];
    for (const g of s.geschaeftsjahre.filter((x) => x.status === 'offen')) {
      if (!jahre.some((j) => j.geschaeftsjahr === g.kennung)) kreise.push({ kreis: nk, jahr: g.kennung });
    }
  }
  return { jahr: s.geschaeftsjahre.length === 0, art: s.buchungsarten.length === 0, kreise,
    etwas: s.geschaeftsjahre.length === 0 || s.buchungsarten.length === 0 || kreise.length > 0 };
}

export function saldoText(saldo) {
  return saldo ? `${betragZeigen(saldo.betrag)} ${saldo.betrag === '0.00' ? '' : saldo.seite}`.trim() : '';
}

export function betriebsart(k) {
  return k.ich.mandanten.find((m) => m.kennung === k.mandant)?.betriebsart || null;
}

/**
 * Kontenvorschau beim Tippen (M9): Liste der passenden aktiven Konten unter dem Feld – Treffer auf den
 * Anfang der Nummer oder einen Teil der Bezeichnung. Pfeiltasten wählen, Enter oder Klick übernimmt,
 * Esc schließt nur die Liste. Ist die getippte Nummer selbst ein Konto, lässt Enter sie stehen, solange
 * nicht mit den Pfeiltasten gewählt wurde. Beim Verlassen des Felds wird nichts still ersetzt.
 * Das Feld muss in einem Element mit position: relative stehen (.konto-eingabe).
 */
export function kontoVorschau(ein, konten, nachWahl = null) {
  const liste = h('ul', { class: 'kontovorschau', role: 'listbox', hidden: true });
  let treffer = [];
  let pos = 0;
  let bewegt = false;
  ein.setAttribute('role', 'combobox');
  ein.setAttribute('aria-autocomplete', 'list');
  ein.setAttribute('aria-expanded', 'false');
  const zu = () => { liste.hidden = true; ein.setAttribute('aria-expanded', 'false'); };
  const waehlen = (x) => {
    ein.value = x.nr;
    zu();
    ein.dispatchEvent(new Event('input', { bubbles: true }));
    ein.dispatchEvent(new Event('change', { bubbles: true }));
    if (nachWahl) nachWahl(x);
  };
  const zeichnen = () => {
    liste.replaceChildren(...treffer.map((x, i) => {
      const li = h('li', { role: 'option', class: i === pos ? 'gewaehlt' : '', 'aria-selected': i === pos ? 'true' : 'false' },
        h('span', { class: 'nr' }, x.nr), h('span', {}, x.bezeichnung));
      // mousedown statt click: das Feld soll den Fokus nicht verlieren, bevor gewählt ist
      li.addEventListener('mousedown', (ev) => { ev.preventDefault(); waehlen(x); });
      return li;
    }));
    liste.querySelector('.gewaehlt')?.scrollIntoView({ block: 'nearest' });
  };
  const suchen = () => {
    const f = ein.value.trim().toLowerCase();
    const aktiv = konten.filter((x) => x.aktiv !== false);
    treffer = f ? [...aktiv.filter((x) => x.nr.toLowerCase().startsWith(f)),
      ...aktiv.filter((x) => !x.nr.toLowerCase().startsWith(f) && x.bezeichnung.toLowerCase().includes(f))].slice(0, 12) : [];
    pos = 0;
    bewegt = false;
    // Steht genau ein Konto da und sonst passt nichts, braucht es keine Liste
    if (treffer.length === 0 || (treffer.length === 1 && treffer[0].nr === ein.value.trim())) { zu(); return; }
    if (!liste.isConnected) ein.insertAdjacentElement('afterend', liste);
    liste.hidden = false;
    ein.setAttribute('aria-expanded', 'true');
    zeichnen();
  };
  ein.addEventListener('input', (ev) => { if (ev.isTrusted) suchen(); });
  ein.addEventListener('blur', zu);
  ein.addEventListener('keydown', (ev) => {
    if (liste.hidden) return;
    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
      ev.preventDefault();
      pos = Math.max(0, Math.min(treffer.length - 1, pos + (ev.key === 'ArrowDown' ? 1 : -1)));
      bewegt = true;
      zeichnen();
    } else if (ev.key === 'Enter') {
      // kein preventDefault: danach geht es wie gewohnt zum nächsten Feld
      const exakt = konten.some((x) => x.nr === ein.value.trim());
      if (treffer[pos] && (bewegt || !exakt)) waehlen(treffer[pos]); else zu();
    } else if (ev.key === 'Escape') {
      ev.preventDefault();
      ev.stopPropagation();
      zu();
    }
  });
  return { zu };
}

/** Kontensuche (F2): Nummer oder Bezeichnung; Pfeiltasten, Enter übernimmt. Liefert die Nummer oder null. */
export function kontoSuchen(konten, t, anfang = '') {
  return new Promise((fertig) => {
    let treffer = [];
    let pos = 0;
    const eingabe = h('input', { type: 'search', class: 'suche', placeholder: t('suche.platzhalter'), value: anfang });
    const liste = h('ul', { class: 'kontotreffer', role: 'listbox' });
    const zeichnen = () => {
      const f = eingabe.value.trim().toLowerCase();
      treffer = konten.filter((x) => x.aktiv && (!f || x.nr.toLowerCase().startsWith(f) || x.bezeichnung.toLowerCase().includes(f))).slice(0, 60);
      pos = Math.min(pos, Math.max(0, treffer.length - 1));
      liste.replaceChildren(...(treffer.length ? treffer.map((x, i) => h('li', {
        role: 'option', class: i === pos ? 'gewaehlt' : '', 'aria-selected': i === pos ? 'true' : 'false',
        onclick: () => schliessen(x.nr),
      }, h('span', { class: 'nr' }, x.nr), h('span', {}, x.bezeichnung))) : [h('li', { class: 'leer' }, t('suche.nichts'))]));
      liste.querySelector('.gewaehlt')?.scrollIntoView({ block: 'nearest' });
    };
    const d = h('dialog', { class: 'dialog breit' }, h('h2', {}, t('suche.titel')), eingabe, liste,
      h('div', { class: 'dialog-knoepfe' }, knopf(t('aktion.abbrechen'), () => schliessen(null))));
    let ergebnis = null;
    function schliessen(nr) { ergebnis = nr; d.close(); }
    eingabe.addEventListener('input', () => { pos = 0; zeichnen(); });
    eingabe.addEventListener('keydown', (ev) => {
      if (ev.key === 'ArrowDown') { ev.preventDefault(); pos = Math.min(pos + 1, treffer.length - 1); zeichnen(); }
      if (ev.key === 'ArrowUp') { ev.preventDefault(); pos = Math.max(pos - 1, 0); zeichnen(); }
      if (ev.key === 'Enter') { ev.preventDefault(); if (treffer[pos]) schliessen(treffer[pos].nr); }
    });
    d.addEventListener('close', () => { d.remove(); fertig(ergebnis); });
    document.body.append(d);
    zeichnen();
    d.showModal();
    eingabe.focus();
  });
}

/**
 * Zeigt genau den Inhalt, der in den Freigabe-Hash eingeht (F38): die Antwort von …/freigaben/challenge.
 * Liefert true, wenn der Nutzer freigibt.
 */
function inhaltBestaetigen(c, s, t) {
  return new Promise((fertig) => {
    const name = (nr) => s?.konto(nr)?.bezeichnung || '';
    const eine = (x) => {
      const i = x.inhalt;
      return h('section', { class: 'freigabe-buchung' },
        h('div', { class: 'freigabe-kopf' },
          h('strong', {}, i.text || ''), i.storno_von ? h('span', { class: 'marke-klein warnung' }, t('status.storno')) : null,
          h('span', {}, `${t('feld.belegdatum')} ${datumZeigen(i.belegdatum)}`),
          i.buchungsdatum !== i.belegdatum ? h('span', {}, `${t('feld.buchungsdatum')} ${datumZeigen(i.buchungsdatum)}`) : null,
          i.belegnummer ? h('span', {}, `${t('feld.belegnummer')} ${i.belegnummer}`) : null,
          h('span', {}, `${t('feld.buchungsart')} ${i.buchungsart}`)),
        h('table', { class: 'freigabe-zeilen' },
          h('thead', {}, h('tr', {}, h('th', { colspan: '2' }, t('spalte.konto')), h('th', { class: 'zahl' }, t('spalte.soll')), h('th', { class: 'zahl' }, t('spalte.haben')))),
          h('tbody', {}, i.zeilen.map((z) => h('tr', {},
          h('td', { class: 'nr' }, z.konto), h('td', {}, name(z.konto), z.tags.length ? h('span', { class: 'hinweis' }, ` · ${z.tags.join(', ')}`) : null),
          h('td', { class: 'zahl' }, z.seite === 'S' ? betragZeigen(z.betrag) : ''), h('td', { class: 'zahl' }, z.seite === 'H' ? betragZeigen(z.betrag) : ''))))),
        h('div', { class: 'hinweis hash' }, x.freigabe_hash));
    };
    let ja = false;
    const d = h('dialog', { class: 'dialog breit' },
      h('h2', {}, t(c.inhalt.buchungen.length === 1 ? 'freigabe.titel' : 'freigabe.titel_stapel', { n: c.inhalt.buchungen.length })),
      h('p', { class: 'hinweis' }, t('freigabe.erklaerung')),
      h('div', { class: 'freigabe-liste' }, c.inhalt.buchungen.map(eine)),
      h('div', { class: 'hinweis hash' }, `${t('freigabe.hash')} ${c.inhalt_hash}`),
      h('div', { class: 'dialog-knoepfe' }, knopf(t('aktion.abbrechen'), () => d.close()),
        knopf(t('aktion.freigeben'), () => { ja = true; d.close(); }, 'primaer', { id: 'freigabe-ja' })));
    d.addEventListener('close', () => { d.remove(); fertig(ja); });
    document.body.append(d);
    d.showModal();
  });
}

/**
 * Freigeben und festschreiben (F78 O1): Challenge → Inhalt anzeigen → Passkey → ausführen.
 * Im Testbetrieb (F59) darf die Assertion fehlen; mit `direkt` entfällt dort auch die Anzeige
 * (Buchen aus dem Formular, dessen Felder der Nutzer gerade vor sich hat).
 * Liefert das Ergebnis von POST …/freigaben oder null, wenn der Nutzer abbricht.
 */
export async function freigeben(k, ids, s, direkt = false) {
  const produktiv = betriebsart(k) === 'produktiv';
  const koerper = { buchungen: ids };
  if (produktiv || !direkt) {
    const c = await k.api('POST', `${k.apiBasis}/freigaben/challenge`, { json: { art: 'festschreibung', buchungen: ids } });
    if (!await inhaltBestaetigen(c, s, k.t)) return null;
    if (c.optionen.allowCredentials.length) {
      koerper.challenge_id = c.challenge_id;
      koerper.assertion = await passkeyBestaetigen(c.optionen);
    } else if (produktiv) {
      throw new Error(k.t('freigabe.kein_passkey'));
    }
  }
  return k.api('POST', `${k.apiBasis}/freigaben`, { json: koerper });
}

/** Alle Seiten einer Liste laden (Cursor), höchstens `grenze` Einträge. */
export async function alleSeiten(k, pfad, grenze = 5000) {
  const eintraege = [];
  let weiter = null;
  do {
    const r = await k.api('GET', `${pfad}${pfad.includes('?') ? '&' : '?'}anzahl=500${weiter ? `&cursor=${encodeURIComponent(weiter)}` : ''}`);
    eintraege.push(...r.eintraege);
    weiter = r.weiter;
  } while (weiter && eintraege.length < grenze);
  return { eintraege, mehr: Boolean(weiter) };
}

// ── Belegarchiv (F97): Vorschau und Prüfung kommen aus dem Baustein des Kerns, gleich welches DMS angebunden ist ──
export { belegarchive as belegarchiv, archivZuLink, belegSchwebe, belegPruefen, belegAuge, belegBeimUeberfahren, belegZiel } from '/kern/ui/beleg.js';
import { belegVorschau as vorschauFenster } from '/kern/ui/beleg.js';
export const belegVorschau = (k, link) => vorschauFenster(k, link);

/** Steuer aus dem Bruttobetrag in Cent: Netto = Brutto / (1 + Satz), Steuer = Brutto − Netto – geht immer auf den Cent auf. */
export function steuerAusBrutto(bruttoCent, satz) {
  return bruttoCent - Math.round(bruttoCent / (1 + satz / 100));
}

/** Steuer aus dem Nettobetrag in Cent. */
export function steuerAusNetto(nettoCent, satz) {
  return Math.round(nettoCent * satz / 100);
}

/** Steuersatz, den die Bezeichnung eines Kontos nahelegt („Vorsteuer 19 %“) – nur als Vorschlag. */
export function satzAusBezeichnung(text) {
  const m = /steuer/i.test(text || '') ? /(\d{1,2}(?:[.,]\d{1,2})?)\s*%/.exec(text) : null;
  return m ? Number(m[1].replace(',', '.')) : null;
}
