// SPDX-License-Identifier: AGPL-3.0-or-later
// Buchungsliste (BUCHUNGSMASKE.md Abschnitt 1, M1): Ansichten Stapel / Journal / Alle, Werkzeugleiste,
// Kontrollsumme, Stapelfreigabe (F67), Storno als Entwurf (F30, F78). Die Liste zeigt und steuert,
// erfasst wird im Formular.
import { h, seitenkopf, knopf, auswahl, bestaetigen } from '/kern/ui/bausteine.js';
import {
  stilLaden, betragZeigen, cent, centZeigen, datumZeigen, heute, stammdatenLaden, geschaeftsjahrZu, freigeben, alleSeiten, betriebsart, kontoVorschau,
} from './gemeinsam.js';

function merken(name, wert) {
  try { sessionStorage.setItem(`kumero.buchen.${name}`, wert); } catch { /* ohne Speicher weiter */ }
}
function gemerkt(name) {
  try { return sessionStorage.getItem(`kumero.buchen.${name}`); } catch { return null; }
}

export async function zeigen(b, k) {
  stilLaden();
  const t = k.t;
  const basis = k.apiBasis;
  const s = await stammdatenLaden(k);
  const seite = (ziel, frage = '') => `#/m/${k.mandant}/buchen/${ziel}${frage}`;
  const darf = {
    anlegen: k.darf('kern.entwurf.anlegen'), aendern: k.darf('kern.entwurf.aendern'), loeschen: k.darf('kern.entwurf.loeschen'),
    freigeben: k.darf('kern.buchung.festschreiben') || k.darf('kern.buchung.stornieren'), storno: k.darf('kern.storno.vorschlagen'),
  };

  const schloss = new URL('./symbole/schloss.svg', import.meta.url).href;
  let buchungen = [];
  let mehr = false;
  let gewaehlt = null;              // ID der gewählten Zeile
  const markiert = new Set();       // IDs für die Stapelfreigabe
  const offen = new Set();          // aufgeklappte Splitbuchungen

  const ansichten = [['stapel', t('ansicht.stapel')], ['journal', t('ansicht.journal')], ['alle', t('ansicht.alle')]];
  const start = ansichten.some(([a]) => a === k.param.ansicht) ? k.param.ansicht : (gemerkt('ansicht') || 'journal');
  const ansicht = auswahl(ansichten, start, { id: 'ansicht', 'aria-label': t('ansicht.titel'), title: t('hilfe.ansicht') });
  const jahr = auswahl(s.geschaeftsjahre.map((g) => [g.kennung, g.kennung + (g.status === 'gesperrt' ? ` (${t('jahr.gesperrt')})` : '')]),
    geschaeftsjahrZu(s.geschaeftsjahre, heute())?.kennung, { id: 'jahr', 'aria-label': t('feld.geschaeftsjahr'), title: t('hilfe.jahr') });
  const art = auswahl([['', t('filter.alle_arten')], ...s.buchungsarten.map((a) => [a.kuerzel, a.kuerzel])], '', { id: 'filter-art', 'aria-label': t('feld.buchungsart'), title: t('hilfe.filter_art') });
  const konto = h('input', { id: 'filter-konto', class: 'konto-nr', placeholder: t('feld.konto'), maxlength: '20', 'aria-label': t('filter.konto'), title: t('hilfe.filter_konto'), autocomplete: 'off' });
  const kontoFilter = h('span', { class: 'konto-eingabe' }, konto);
  kontoVorschau(konto, s.konten);
  const meine = h('input', { type: 'checkbox', id: 'filter-meine', title: t('hilfe.filter_meine') });
  const suche = h('input', { type: 'search', id: 'suche', class: 'suche', placeholder: t('liste.suche'), title: t('hilfe.suche') });
  const tabelle = h('div', { class: 'liste-rahmen buchungsliste', id: 'buchungsliste' });

  const wk = {
    neu: darf.anlegen ? knopf(t('aktion.neu'), () => k.navigieren(seite('erfassen')), 'primaer', { id: 'neu', title: t('hilfe.neu') }) : null,
    kopieren: darf.anlegen ? knopf(t('aktion.kopieren'), () => eine((x) => k.navigieren(seite('erfassen', `?kopie=${x.id}`))), '', { id: 'kopieren', title: t('hilfe.kopieren') }) : null,
    bearbeiten: knopf(t('aktion.bearbeiten'), oeffnen, '', { id: 'bearbeiten', title: t('hilfe.bearbeiten') }),
    loeschen: darf.loeschen ? knopf(t('aktion.loeschen'), loeschen, '', { id: 'loeschen', title: t('hilfe.loeschen') }) : null,
    freigeben: darf.freigeben && darf.aendern ? knopf(t('aktion.freigeben'), stapelFreigeben, '', { id: 'freigeben', title: t('hilfe.freigeben') }) : null,
    stornieren: darf.storno ? knopf(t('aktion.stornieren'), stornieren, '', { id: 'stornieren', title: t('hilfe.stornieren') }) : null,
    kontoblatt: knopf(t('aktion.kontoblatt'), () => eine((x) => {
      const nr = (x.zeilen.find((z) => z.seite === 'S') || x.zeilen[0])?.konto;
      const g = s.geschaeftsjahre.find((y) => y.kennung === jahr.value);
      if (nr) k.navigieren(seite('kontoblatt', `?konto=${encodeURIComponent(nr)}${g ? `&von=${g.beginn}&bis=${g.ende}` : ''}`));
    }), '', { id: 'kontoblatt', title: t('hilfe.kontoblatt') }),
  };

  b.append(
    seitenkopf(t('liste.titel', { mandant: k.mandantName || k.mandant }),
      betriebsart(k) === 'test' ? h('span', { class: 'marke-klein warnung' }, t('hinweis.testbetrieb')) : null,
      ansicht, jahr),
    h('div', { class: 'werkzeugleiste' }, Object.values(wk), h('span', { class: 'luecke' }),
      kontoFilter, art, h('label', { class: 'feld-wahl' }, meine, t('filter.meine')), suche),
    tabelle);

  // ── Daten ──────────────────────────────────────────────────────────────────────────────
  async function laden() {
    merken('ansicht', ansicht.value);
    const filter = (konto.value.trim() ? `&konto=${encodeURIComponent(konto.value.trim())}` : '')
      + (art.value ? `&buchungsart=${encodeURIComponent(art.value)}` : '')
      + (meine.checked ? `&erfasst_von=${encodeURIComponent(k.ich.subjekt)}` : '');
    const g = s.geschaeftsjahre.find((x) => x.kennung === jahr.value);
    const zeitraum = g ? `&von=${g.beginn}&bis=${g.ende}` : '';
    try {
      const teile = [];
      // Der Stapel kennt keinen Zeitraum: Entwürfe dürfen noch ohne Datum sein (F62)
      if (ansicht.value !== 'journal') teile.push(await alleSeiten(k, `${basis}/buchungen?status=entwurf,freigabebereit${filter}`));
      if (ansicht.value !== 'stapel') teile.push(await alleSeiten(k, `${basis}/buchungen?status=festgeschrieben${zeitraum}${filter}`));
      buchungen = teile.flatMap((x) => x.eintraege);
      mehr = teile.some((x) => x.mehr);
    } catch (e) {
      buchungen = [];
      k.fehler(e);
    }
    for (const id of [...markiert]) if (!buchungen.some((x) => x.id === id)) markiert.delete(id);
    if (!buchungen.some((x) => x.id === gewaehlt)) gewaehlt = buchungen[0]?.id || null;
    jahr.disabled = ansicht.value === 'stapel';
    zeichnen();
  }

  const sichtbar = () => {
    const f = suche.value.trim().toLowerCase();
    if (!f) return buchungen;
    return buchungen.filter((x) => [x.nr, x.text, x.belegnummer, datumZeigen(x.belegdatum), betragZeigen(x.summe), ...x.zeilen.map((z) => z.konto)]
      .some((w) => w && String(w).toLowerCase().includes(f)));
  };
  const name = (nr) => s.konto(nr)?.bezeichnung || '';

  // ── Tabelle ────────────────────────────────────────────────────────────────────────────
  function zeichnen() {
    const zeilen = sichtbar();
    const tbody = h('tbody');
    let kontrolle = 0;
    for (const x of zeilen) {
      const soll = x.zeilen.filter((z) => z.seite === 'S');
      const haben = x.zeilen.filter((z) => z.seite === 'H');
      const sollSumme = soll.reduce((n, z) => n + cent(z.betrag), 0);
      const habenSumme = haben.reduce((n, z) => n + cent(z.betrag), 0);
      kontrolle += sollSumme - habenSumme;
      const splitt = x.zeilen.length > 2;
      const kontoText = (liste) => (liste.length === 1 ? liste[0].konto || '' : (liste.length ? t('liste.splitt') : ''));
      const fest = x.status === 'festgeschrieben';
      const tr = h('tr', { tabindex: '-1', 'data-id': x.id, class: [x.id === gewaehlt ? 'gewaehlt' : '', `status-${x.status}`] },
        h('td', { class: 'eng' }, fest ? null : h('input', { type: 'checkbox', checked: markiert.has(x.id), 'aria-label': t('liste.markieren'), tabindex: '-1',
          onchange: (ev) => { if (ev.target.checked) markiert.add(x.id); else markiert.delete(x.id); fuss(); knoepfe(); } })),
        h('td', { class: 'eng' }, marken(x)),
        h('td', { class: 'nr breit' }, x.nr || ''),
        h('td', { class: 'eng' }, datumZeigen(x.belegdatum)),
        h('td', {}, h('span', { class: 'einzeilig' }, x.text || '')),
        h('td', {}, x.belegnummer || ''),
        h('td', { class: 'nr', title: soll.length === 1 ? name(soll[0].konto) : '' },
          splitt ? h('button', { type: 'button', class: 'klappe', 'aria-expanded': offen.has(x.id) ? 'true' : 'false', title: t('liste.aufklappen'), tabindex: '-1',
            onclick: (ev) => { ev.stopPropagation(); if (offen.has(x.id)) offen.delete(x.id); else offen.add(x.id); gewaehlt = x.id; zeichnen(); waehlen(x.id); } }, offen.has(x.id) ? '▾' : '▸') : null,
          kontoText(soll)),
        h('td', { class: 'zahl' }, soll.length ? centZeigen(sollSumme) : ''),
        h('td', { class: 'nr', title: haben.length === 1 ? name(haben[0].konto) : '' }, kontoText(haben)),
        h('td', { class: 'zahl' }, haben.length ? centZeigen(habenSumme) : ''));
      tr.addEventListener('click', (ev) => { waehlen(x.id, !(ev.target instanceof HTMLInputElement)); if (ev.detail === 2) oeffnen(); });
      tbody.append(tr);
      if (splitt && offen.has(x.id)) {
        for (const z of x.zeilen) {
          tbody.append(h('tr', { class: 'unterzeile' }, h('td', { colspan: '6' }, h('span', { class: 'hinweis' }, name(z.konto), z.tags.length ? ` · ${z.tags.join(', ')}` : '')),
            h('td', { class: 'nr' }, z.seite === 'S' ? z.konto : ''), h('td', { class: 'zahl' }, z.seite === 'S' ? betragZeigen(z.betrag) : ''),
            h('td', { class: 'nr' }, z.seite === 'H' ? z.konto : ''), h('td', { class: 'zahl' }, z.seite === 'H' ? betragZeigen(z.betrag) : '')));
        }
      }
    }
    if (zeilen.length === 0) {
      tbody.append(h('tr', { class: 'leer' }, h('td', { colspan: '10' },
        buchungen.length ? t('liste.nichts_gefunden') : t(ansicht.value === 'stapel' ? 'liste.stapel_leer' : 'liste.leer'))));
    }
    const kopf = ['', '', t('spalte.nr'), t('spalte.belegdatum'), t('spalte.text'), t('spalte.belegnr'), t('spalte.konto'), t('spalte.soll'), t('spalte.gegenkonto'), t('spalte.haben')];
    const klassen = ['eng', 'eng', 'nr breit', 'eng', '', '', 'nr', 'zahl', 'nr', 'zahl'];
    const fusszeile = h('div', { class: 'liste-fuss', id: 'liste-fuss' });
    tabelle.replaceChildren(h('div', { class: 'liste-scroll' }, h('table', { class: 'liste' },
      h('thead', {}, h('tr', {}, kopf.map((x, i) => h('th', { class: klassen[i] }, x)))), tbody)), fusszeile);
    tabelle.dataset.kontrolle = String(kontrolle);
    tabelle.dataset.anzahl = String(zeilen.length);
    fuss();
    knoepfe();
  }

  function marken(x) {
    const m = [];
    // Zustand nie nur als Farbe: Zeichen und Text (M14, PROFIL.md 4a)
    if (x.status === 'festgeschrieben') m.push(h('img', { class: 'schloss', src: schloss, alt: t('status.festgeschrieben'), title: t('ansicht.fest') }));
    if (x.status !== 'festgeschrieben') m.push(h('span', { class: ['marke-klein', x.status === 'freigabebereit' ? 'korrigiert' : 'warnung'] }, t(`status.${x.status}`)));
    if (x.storno_von) m.push(h('span', { class: 'marke-klein' }, t('status.storno')));
    if (x.storniert_durch) m.push(h('span', { class: 'marke-klein fehler' }, t('status.storniert')));
    if (x.testbetrieb) m.push(h('span', { class: 'marke-klein', title: t('status.testbuchung_lang') }, t('status.testbuchung')));
    return m;
  }

  function fuss() {
    const e = tabelle.querySelector('#liste-fuss');
    if (!e) return;
    const bereit = buchungen.filter((x) => x.status === 'freigabebereit').length;
    const kontrolle = Number(tabelle.dataset.kontrolle || 0);
    e.replaceChildren(...[
      h('span', {}, t('fuss.anzahl', { n: tabelle.dataset.anzahl })),
      h('span', { class: kontrolle === 0 ? '' : 'fuss-warnung', id: 'kontrollsumme' }, t('fuss.kontrolle', { betrag: centZeigen(kontrolle) })),
      ansicht.value !== 'journal' ? h('span', {}, t('fuss.stapel', { n: bereit })) : null,
      markiert.size ? h('span', {}, t('fuss.markiert', { n: markiert.size })) : null,
      mehr ? h('span', { class: 'fuss-warnung' }, t('fuss.mehr')) : null].filter(Boolean));
  }

  const zeile = (id) => buchungen.find((x) => x.id === id) || null;
  /** Buchungen der Aktion: die markierten, sonst die gewählte. */
  const ziele = () => (markiert.size ? buchungen.filter((x) => markiert.has(x.id)) : [zeile(gewaehlt)].filter(Boolean));
  function eine(f) { const x = zeile(gewaehlt); if (x) f(x); }

  function knoepfe() {
    const x = zeile(gewaehlt);
    const z = ziele();
    const entwuerfe = z.filter((y) => y.status !== 'festgeschrieben');
    if (wk.kopieren) wk.kopieren.disabled = !x;
    wk.bearbeiten.disabled = !x;
    wk.bearbeiten.textContent = t(x && (x.status === 'festgeschrieben' || !darf.aendern) ? 'aktion.ansehen' : 'aktion.bearbeiten');
    if (wk.loeschen) wk.loeschen.disabled = entwuerfe.length === 0;
    if (wk.freigeben) wk.freigeben.disabled = entwuerfe.length === 0;
    if (wk.stornieren) wk.stornieren.disabled = !x || x.status !== 'festgeschrieben' || Boolean(x.storniert_durch);
    wk.kontoblatt.disabled = !x || x.zeilen.length === 0;
  }

  function waehlen(id, fokus = true) {
    gewaehlt = id;
    tabelle.querySelectorAll('tr.gewaehlt').forEach((e) => e.classList.remove('gewaehlt'));
    const tr = tabelle.querySelector(`tr[data-id="${id}"]`);
    if (tr) {
      tr.classList.add('gewaehlt');
      if (fokus) tr.focus();
    }
    knoepfe();
  }

  // ── Aktionen ───────────────────────────────────────────────────────────────────────────
  function oeffnen() {
    eine((x) => k.navigieren(seite('erfassen', `?id=${x.id}`)));
  }

  async function loeschen() {
    const z = ziele().filter((x) => x.status !== 'festgeschrieben');
    if (!z.length || !await bestaetigen(t('loeschen.titel'), t('loeschen.frage', { n: z.length }), t('aktion.loeschen'))) return;
    try {
      for (const x of z) await k.api('DELETE', `${basis}/buchungen/${x.id}`, { kopf: { 'If-Match': '*' } });
      k.melden(t('loeschen.fertig', { n: z.length }), 'erfolg');
    } catch (e) { k.fehler(e); }
    markiert.clear();
    await laden();
  }

  /** Eine Freigabe für den ganzen Stapel (F67); Stornos und andere Buchungen getrennt (F78). */
  async function stapelFreigeben() {
    const z = ziele().filter((x) => x.status !== 'festgeschrieben');
    if (!z.length) return;
    wk.freigeben.disabled = true;
    let fertig = 0;
    try {
      for (const x of z.filter((y) => y.status === 'entwurf')) {
        try {
          await k.api('POST', `${basis}/buchungen/${x.id}/vorbereiten`, { kopf: { 'If-Match': '*' } });
        } catch (e) {
          waehlen(x.id);
          throw new Error(t('freigabe.nicht_bereit', { text: x.text || x.belegnummer || '–', grund: e.message }));
        }
      }
      for (const gruppe of [z.filter((x) => !x.storno_von), z.filter((x) => x.storno_von)]) {
        if (!gruppe.length) continue;
        const erg = await freigeben(k, gruppe.map((x) => x.id), s);
        if (!erg) break;
        fertig += erg.buchungen.length;
      }
      k.melden(fertig ? t(fertig === 1 ? 'freigabe.fertig_eine' : 'freigabe.fertig', { n: fertig }) : t('meldung.freigabe_abgebrochen'), fertig ? 'erfolg' : 'warnung');
    } catch (e) {
      if (e?.name === 'NotAllowedError') k.melden(t('meldung.freigabe_abgebrochen'), 'warnung'); else k.fehler(e);
    }
    markiert.clear();
    await laden();
  }

  async function stornieren() {
    const x = zeile(gewaehlt);
    if (!x || x.status !== 'festgeschrieben') return;
    if (!await bestaetigen(t('storno.titel'), t('storno.frage'), t('aktion.stornieren'))) return;
    try {
      const st = await k.api('POST', `${basis}/buchungen/${x.id}/storno`, { json: {} });
      ansicht.value = 'stapel';
      gewaehlt = st.id;
      await laden();
      k.melden(t('storno.fertig'), 'erfolg');
    } catch (e) { k.fehler(e); }
  }

  // ── Tastatur (M6): Pfeile bewegen, Enter öffnet, Einfg neu, Leertaste markiert, Strg+F sucht ──
  // Strg+F und Einfg gelten auf der ganzen Seite, auch wenn gerade keine Zeile den Fokus hat
  const seitenTasten = (ev) => {
    if (document.querySelector('dialog[open]')) return;
    if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'f') { ev.preventDefault(); suche.focus(); suche.select(); }
    if (ev.key === 'Insert' && wk.neu && !(ev.target instanceof HTMLInputElement)) { ev.preventDefault(); k.navigieren(seite('erfassen')); }
  };
  document.addEventListener('keydown', seitenTasten);
  b.addEventListener('keydown', (ev) => {
    const tr = ev.target instanceof HTMLElement ? ev.target.closest('tr[data-id]') : null;
    if (!tr || ev.target !== tr) return;
    const nachbar = (richtung) => {
      let e = tr;
      do { e = richtung > 0 ? e.nextElementSibling : e.previousElementSibling; } while (e && !e.dataset.id);
      return e;
    };
    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
      ev.preventDefault();
      const e = nachbar(ev.key === 'ArrowDown' ? 1 : -1);
      if (e) waehlen(e.dataset.id);
    } else if (ev.key === 'Enter') {
      ev.preventDefault();
      oeffnen();
    } else if (ev.key === ' ') {
      ev.preventDefault();
      const kiste = tr.querySelector('input[type=checkbox]');
      if (kiste) { kiste.checked = !kiste.checked; kiste.dispatchEvent(new Event('change')); }
    }
  });

  for (const e of [ansicht, jahr, art, meine]) e.addEventListener('change', laden);
  konto.addEventListener('change', laden);
  suche.addEventListener('input', () => { zeichnen(); });

  await laden();
  if (gewaehlt) waehlen(gewaehlt);
  return () => document.removeEventListener('keydown', seitenTasten);
}
