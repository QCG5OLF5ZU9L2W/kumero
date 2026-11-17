// SPDX-License-Identifier: AGPL-3.0-or-later
// Arbeitsvorrat (ARBEITSVORRAT.md Abschnitt 5): der Posteingang der Buchhaltung. Die Liste zeigt, was noch
// zu buchen ist – unabhängig von der Quelle. Enter öffnet die vorbefüllte Buchungserfassung des Moduls buchen.
import { h, seitenkopf, knopf, auswahl, datumFeld, datumText, zeit } from '/kern/ui/bausteine.js';

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

export async function zeigen(b, k) {
  const css = new URL('./vorrat.css', import.meta.url).href;
  if (!document.querySelector(`link[href="${css}"]`)) document.head.append(h('link', { rel: 'stylesheet', href: css }));
  const t = k.t;
  const basis = `${k.apiBasis}/arbeitsvorrat`;
  const darfAnlegen = k.darf('arbeitsvorrat.eintrag.anlegen');
  const darfBearbeiten = k.darf('arbeitsvorrat.eintrag.bearbeiten');
  const darfBuchen = darfBearbeiten && k.darf('kern.entwurf.anlegen');
  const erfassen = (param) => k.navigieren(`#/m/${k.mandant}/buchen/erfassen?${param}`);

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
  const fuss = h('div', { class: 'vorrat-fuss', id: 'vorrat-fuss' });
  const hinweisQuellen = h('p', { class: 'hinweis', id: 'vorrat-ausgefallen', hidden: true });

  const kn = {
    buchen: darfBuchen ? knopf(t('aktion.buchen'), buchen, 'primaer', { id: 'vorrat-buchen', title: t('hilfe.buchen') }) : null,
    neu: darfAnlegen ? knopf(t('aktion.neu'), neu, '', { id: 'vorrat-neu', title: t('hilfe.neu') }) : null,
    verwerfen: darfBearbeiten ? knopf(t('aktion.verwerfen'), verwerfen, '', { id: 'vorrat-verwerfen', title: t('hilfe.verwerfen') }) : null,
    oeffnen: darfBearbeiten ? knopf(t('aktion.oeffnen'), wiederOeffnen, '', { id: 'vorrat-oeffnen', title: t('hilfe.oeffnen') }) : null,
    beleg: knopf(t('aktion.beleg'), () => { const x = aktuell(); if (x?.belege[0]) window.open(x.belege[0].link, '_blank', 'noopener,noreferrer'); },
      '', { id: 'vorrat-beleg', title: t('hilfe.beleg') }),
  };

  const aktuell = () => eintraege.find((x) => x.id === gewaehlt) || null;
  const sichtbar = () => {
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

  function zeichnen() {
    const zeilen = sichtbar();
    tbody.replaceChildren(...zeilen.map((x) => {
      const tr = h('tr', { tabindex: '-1', 'data-id': x.id, class: x.id === gewaehlt ? 'gewaehlt' : '' },
        h('td', { class: 'eng' }, zeit(x.eingegangen_am)),
        h('td', { class: 'eng' }, x.quellen.map(titelQuelle).join(', ')),
        h('td', { class: 'eng' }, x.art || ''),
        h('td', {}, h('span', { class: 'einzeilig', title: x.verworfen_grund ? t('liste.grund', { grund: x.verworfen_grund }) : x.betreff }, x.betreff)),
        h('td', {}, h('span', { class: 'einzeilig' }, x.gegenpartei || '')),
        h('td', { class: 'eng' }, x.belegnummer || ''),
        h('td', { class: 'eng' }, datumText(x.datum)),
        h('td', { class: 'zahl' }, betragZeigen(x.betrag), x.richtung === 'einnahme' ? h('span', { class: 'marke-klein korrigiert', title: t('hilfe.einnahme') }, t('liste.einnahme')) : null),
        h('td', {}, x.belege.length ? h('a', { href: x.belege[0].link, target: '_blank', rel: 'noopener noreferrer', title: t('hilfe.beleg'), 'aria-label': t('aktion.beleg'),
          onclick: (ev) => ev.stopPropagation() }, '↗') : null,
        (x.hinweise.length + x.vermerke.length) ? h('span', { class: 'marke-klein warnung', title: [...x.hinweise.map(hinweisText), ...x.vermerke.map((v) => v.text)].join('\n') },
          t('liste.hinweise', { n: x.hinweise.length + x.vermerke.length })) : null));
      tr.addEventListener('click', (ev) => { waehlen(x.id); if (ev.detail === 2) buchen(); });
      return tr;
    }));
    if (!zeilen.length) {
      tbody.append(h('tr', { class: 'leer' }, h('td', { colspan: '9' }, t(eintraege.length ? 'liste.nichts_gefunden' : `liste.leer_${ansicht.value}`))));
    }
    if (!zeilen.some((x) => x.id === gewaehlt)) gewaehlt = zeilen[0]?.id || null;
    waehlen(gewaehlt, false);
    const summe = zeilen.reduce((n, x) => n + (x.betrag ? Math.round(Number(x.betrag) * 100) : 0), 0);
    fuss.textContent = t('liste.fuss', { n: zeilen.length, summe: zahl.format(summe / 100) });
  }

  function hinweisText(x) {
    if (x.abweichend !== undefined) return t('liste.abweichend', { quelle: titelQuelle(x.quelle), feld: t(`feld.${x.feld}`), wert: x.abweichend });
    return x.text;
  }

  async function laden() {
    try {
      const q = new URLSearchParams({ zustand: ansicht.value });
      if (quelle.value) q.set('quelle', quelle.value);
      const r = await k.api('GET', `${basis}/eintraege?${q}`);
      eintraege = r.eintraege;
      quellen = r.quellen;
      const wahl = quelle.value;
      quelle.replaceChildren(h('option', { value: '' }, t('filter.alle_quellen')), ...quellen.map((x) => h('option', { value: x.name }, x.titel)));
      quelle.value = wahl;
      hinweisQuellen.hidden = !r.quellen_ausgefallen.length;
      hinweisQuellen.textContent = r.quellen_ausgefallen.length ? t('liste.ausgefallen', { quellen: r.quellen_ausgefallen.map(titelQuelle).join(', ') }) : '';
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

  ansicht.addEventListener('change', () => { history.replaceState(null, '', `#/m/${k.mandant}/arbeitsvorrat/liste?ansicht=${ansicht.value}`); laden(); });
  quelle.addEventListener('change', laden);
  suche.addEventListener('input', zeichnen);

  const tabelle = h('table', { class: 'liste', id: 'vorratsliste' },
    h('thead', {}, h('tr', {}, ['eingang', 'quelle', 'art', 'betreff', 'gegenpartei', 'belegnummer', 'datum', 'betrag', 'hinweise'].map((s) =>
      h('th', { class: s === 'betrag' ? 'zahl' : '' }, t(`spalte.${s}`))))), tbody);
  // Tastatur wie in der Buchungsliste: Pfeile bewegen, Enter bucht, Einfg legt neu an
  tabelle.addEventListener('keydown', (ev) => {
    const zeilen = sichtbar();
    const i = zeilen.findIndex((x) => x.id === gewaehlt);
    if (ev.key === 'ArrowDown' && i < zeilen.length - 1) { ev.preventDefault(); waehlen(zeilen[i + 1].id); }
    else if (ev.key === 'ArrowUp' && i > 0) { ev.preventDefault(); waehlen(zeilen[i - 1].id); }
    else if (ev.key === 'Enter') { ev.preventDefault(); buchen(); }
    else if (ev.key === 'Insert' && darfAnlegen) { ev.preventDefault(); neu(); }
  });

  b.append(seitenkopf(t('liste.titel', { mandant: k.mandantName || k.mandant })),
    h('p', { class: 'hinweis' }, t('liste.erklaerung')),
    h('div', { class: 'vorrat-leiste' }, kn.buchen, kn.neu, kn.verwerfen, kn.oeffnen, kn.beleg),
    h('div', { class: 'vorrat-filter' }, ansicht, quelle, suche), hinweisQuellen,
    h('div', { class: 'vorratsliste' }, tabelle), fuss);
  await laden();
  tbody.querySelector('tr.gewaehlt')?.focus();
}
