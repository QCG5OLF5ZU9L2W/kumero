// SPDX-License-Identifier: AGPL-3.0-or-later
// „Siegel“ (SIEGEL.md Abschnitt 7; F119, F120): Siegel anfordern, Siegelinhalt herunterladen, außerhalb signieren,
// Signatur hochladen. Die Seite nimmt nie einen privaten Schlüssel und kein Passwort entgegen – signiert wird am
// Rechner des Nutzers. Alle Knöpfe bleiben stehen; gesperrte nennen den Grund (F106).
import { h, seitenkopf, tabelle, knopf, feld, sperren, zeit } from '/kern/ui/bausteine.js';

export async function zeigen(b, k) {
  const css = new URL('./siegel.css', import.meta.url).href;
  if (!document.querySelector(`link[href="${css}"]`)) document.head.append(h('link', { rel: 'stylesheet', href: css }));
  const t = k.t;
  const basis = `${k.apiBasis}/siegel`;
  let d = { kettenkoepfe: { buchungen: { nr: 0 }, protokoll: { nr: 0 } }, siegel: [], schluessel: [], namen: {} };
  let siegelGewaehlt = null;
  let schluesselGewaehlt = null;

  const knAnfordern = knopf(t('aktion.anfordern'), anfordern, 'primaer', { id: 'sg-anfordern', title: t('hilfe.anfordern') });
  const knInhalt = knopf(t('aktion.inhalt'), () => laden_datei(`${basis}/${siegelGewaehlt}/inhalt`), '', { id: 'sg-inhalt', title: t('hilfe.inhalt') });
  const knSignatur = knopf(t('aktion.signatur'), signaturDialog, '', { id: 'sg-signatur', title: t('hilfe.signatur') });
  const knSchluessel = knopf(t('aktion.schluessel'), schluesselDialog, '', { id: 'sg-schluessel-neu', title: t('hilfe.schluessel') });
  const knZurueck = knopf(t('aktion.zurueckziehen'), zurueckDialog, '', { id: 'sg-zurueckziehen', title: t('hilfe.zurueckziehen') });
  const stand = h('dl', { class: 'sg-stand', id: 'sg-stand' });
  const liste = h('div', { id: 'sg-liste' });
  const schluessel = h('div', { id: 'sg-schluessel' });
  const befehl = h('code', { class: 'sg-befehl', id: 'sg-befehl' });

  b.append(seitenkopf(t('titel'), knAnfordern, knInhalt, knSignatur),
    h('p', { class: 'hinweis' }, t('erklaerung')),
    h('div', { class: 'karte' }, h('h2', {}, t('stand.titel')), stand),
    liste,
    h('div', { class: 'karte' }, h('h2', {}, t('ablauf.titel')),
      h('ol', { class: 'sg-schritte' }, h('li', {}, t('ablauf.1')), h('li', {}, t('ablauf.2'), befehl), h('li', {}, t('ablauf.3'))),
      h('p', { class: 'hinweis' }, t('ablauf.hinweis'))),
    h('div', { class: 'karte' }, h('h2', {}, t('schluessel.titel')), h('p', { class: 'hinweis' }, t('schluessel.erklaerung')),
      h('div', { class: 'sg-zeile' }, knSchluessel, knZurueck), schluessel));

  const name = (subjekt) => d.namen[subjekt] || subjekt;
  const dateiname = (s) => `kumero-siegel-${k.mandant}-${String(s.nr).padStart(4, '0')}.json`;
  const fprText = (f) => f.replace(/(.{4})/g, '$1 ').trim();

  /** Datei über einen gewöhnlichen Link laden – der Server liefert sie als Anhang, die Seite bleibt stehen. */
  function laden_datei(url) {
    const a = h('a', { href: url, download: '', hidden: true });
    document.body.append(a);
    a.click();
    a.remove();
  }

  function knoepfe() {
    const s = d.siegel.find((x) => x.id === siegelGewaehlt) || null;
    const sl = d.schluessel.find((x) => x.fpr === schluesselGewaehlt) || null;
    const darfSiegeln = k.darf('siegel.siegel.anfordern') ? null : t('sperre.recht_anfordern');
    const darfSchluessel = k.darf('siegel.schluessel.pflegen') ? null : t('sperre.recht_schluessel');
    sperren(knAnfordern, darfSiegeln);
    sperren(knInhalt, s ? null : t('sperre.kein_siegel'));
    sperren(knSignatur, darfSiegeln || (s ? null : t('sperre.kein_siegel')) || (d.schluessel.some((x) => !x.zurueckgezogen_am) ? null : t('sperre.kein_aktiver')));
    sperren(knSchluessel, darfSchluessel);
    sperren(knZurueck, darfSchluessel || (sl ? null : t('sperre.kein_schluessel')) || (sl.zurueckgezogen_am ? t('sperre.schon_zurueck') : null));
    const gewaehlt = s || d.siegel[0] || null;
    befehl.textContent = `gpg --armor --detach-sign ${gewaehlt ? dateiname(gewaehlt) : `kumero-siegel-${k.mandant}-0001.json`}`;
  }

  /** Die Tabelle des Kerns kennt keine Auswahl nach außen: Zeilen selbst kennzeichnen. */
  function auswahl(rahmen, zeilen, schluesselVon, gewaehlt, setzen) {
    [...rahmen.querySelectorAll('tbody tr')].forEach((tr, i) => {
      const z = zeilen[i];
      if (!z) return;
      const id = schluesselVon(z);
      tr.dataset.id = id;
      tr.tabIndex = 0;
      tr.classList.toggle('gewaehlt', id === gewaehlt);
      tr.setAttribute('aria-selected', id === gewaehlt ? 'true' : 'false');
      const waehlen = () => { setzen(id); zeichnen(); };
      tr.addEventListener('click', (ev) => { if (!ev.target.closest('a')) waehlen(); });
      tr.addEventListener('keydown', (ev) => { if (ev.target === tr && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); waehlen(); } });
    });
  }

  function zeichnen() {
    const letztes = d.siegel.find((x) => x.status !== 'ausstehend') || null;
    const offen = letztes ? letztes.buchungen_danach : null;
    stand.replaceChildren(
      h('div', {}, h('dt', {}, t('stand.buchungen')), h('dd', { id: 'sg-stand-buchungen' }, t('stand.nr', { n: d.kettenkoepfe.buchungen.nr }))),
      h('div', {}, h('dt', {}, t('stand.protokoll')), h('dd', {}, t('stand.nr', { n: d.kettenkoepfe.protokoll.nr }))),
      h('div', {}, h('dt', {}, t('stand.offen')), h('dd', { id: 'sg-stand-offen' },
        offen === null ? t('stand.nie') : (offen === 0 ? t('stand.offen_0') : (offen === 1 ? t('stand.offen_1') : t('stand.offen_n', { n: offen }))))));

    liste.replaceChildren(tabelle([
      { titel: t('spalte.nr'), klasse: 'nr', wert: (s) => String(s.nr) },
      { titel: t('spalte.angefordert'), klasse: 'eng', wert: (s) => zeit(s.angefordert_am) },
      { titel: t('spalte.von'), wert: (s) => name(s.angefordert_von) },
      { titel: t('spalte.buchungen'), klasse: 'nr', wert: (s) => String(s.buchungen_nr) },
      { titel: t('spalte.protokoll'), klasse: 'nr', wert: (s) => String(s.protokoll_nr) },
      { titel: t('spalte.zustand'), wert: (s) => h('span', { 'data-zustand': s.status }, t(`zustand.${s.status}`)) },
      { titel: t('spalte.signaturen'), wert: (s) => h('ul', { class: 'sg-signaturen' }, s.signaturen.map((g) => h('li', {},
        h('a', { href: `${basis}/${s.id}/signaturen/${g.schluessel}`, download: '', title: t('signatur.hilfe', { fpr: fprText(g.schluessel) }) },
          t('signatur.zeile', { kennung: g.kennung, zeit: zeit(g.signiert_am) }))))) },
      { titel: t('spalte.pruefsumme'), wert: (s) => h('span', { class: 'sg-hash', title: s.inhalt_sha256 }, `${s.inhalt_sha256.slice(0, 16)}…`) },
    ], { zeilen: d.siegel, leer: t('liste.leer') }));
    auswahl(liste, d.siegel, (s) => s.id, siegelGewaehlt, (id) => { siegelGewaehlt = id; });

    schluessel.replaceChildren(tabelle([
      { titel: t('spalte.kennung'), wert: (s) => s.kennung },
      { titel: t('spalte.fpr'), wert: (s) => h('a', { class: 'sg-hash', href: `${basis}/schluessel/${s.fpr}`, download: '', title: t('schluessel.hilfe_datei') }, fprText(s.fpr)) },
      { titel: t('spalte.verfahren'), klasse: 'eng', wert: (s) => s.algorithmus },
      { titel: t('spalte.angemeldet'), klasse: 'eng', wert: (s) => `${zeit(s.angemeldet_am)} · ${name(s.angemeldet_von)}` },
      { titel: t('spalte.genutzt'), klasse: 'nr', wert: (s) => String(s.signaturen) },
      { titel: t('spalte.zustand'), wert: (s) => h('span', { 'data-zustand': s.zurueckgezogen_am ? 'zurueck' : 'aktiv' },
        s.zurueckgezogen_am ? t('schluessel.zurueck', { zeit: zeit(s.zurueckgezogen_am), grund: s.zurueckgezogen_grund }) : t('schluessel.aktiv')) },
    ], { zeilen: d.schluessel, leer: t('schluessel.leer') }));
    auswahl(schluessel, d.schluessel, (s) => s.fpr, schluesselGewaehlt, (id) => { schluesselGewaehlt = id; });
    knoepfe();
  }

  async function laden() {
    try {
      d = await k.api('GET', basis);
      if (!d.siegel.some((s) => s.id === siegelGewaehlt)) siegelGewaehlt = null;
      if (!d.schluessel.some((s) => s.fpr === schluesselGewaehlt)) schluesselGewaehlt = null;
      zeichnen();
    } catch (x) { k.fehler(x); }
  }

  async function anfordern() {
    try {
      const r = await k.api('POST', basis, { json: {} });
      siegelGewaehlt = r.id;
      k.melden(t('meldung.angefordert', { nr: r.nr }), 'erfolg');
      await laden();
    } catch (x) { k.fehler(x); }
  }

  /** Dialog mit Dateiauswahl und Textfeld; die Datei wird im Browser gelesen und als Text gesendet. */
  function textDialog(o) {
    const text = h('textarea', { id: `${o.id}-text`, class: 'sg-eingabe', rows: '9', spellcheck: false, autocomplete: 'off', placeholder: o.platzhalter });
    const datei = h('input', { id: `${o.id}-datei`, type: 'file', accept: '.asc,.sig,.txt,.gpg,.pub,text/plain,application/pgp-signature,application/pgp-keys' });
    const ja = knopf(o.ja, async () => {
      dlg.close();
      try { await o.senden(text.value); await laden(); } catch (x) { k.fehler(x); }
    }, 'primaer', { id: `${o.id}-senden` });
    const pruefen = () => sperren(ja, text.value.trim() === '' ? t('sperre.leer') : null);
    datei.addEventListener('change', async () => {
      const f = datei.files[0];
      if (f) text.value = f.size > 200000 ? '' : await f.text();
      pruefen();
    });
    text.addEventListener('input', pruefen);
    const dlg = h('dialog', { class: 'dialog', id: o.id }, h('h2', {}, o.titel), h('p', {}, o.text), o.zusatz || null,
      feld(t('feld.datei'), datei), feld(t('feld.text'), text),
      h('div', { class: 'dialog-knoepfe' }, knopf(t('aktion.abbrechen'), () => dlg.close(), '', { id: `${o.id}-abbrechen` }), ja));
    dlg.addEventListener('close', () => dlg.remove());
    document.body.append(dlg);
    pruefen();
    dlg.showModal();
  }

  function signaturDialog() {
    const s = d.siegel.find((x) => x.id === siegelGewaehlt);
    if (!s) return;
    textDialog({ id: 'sg-dialog-signatur', titel: t('dialog.signatur.titel', { nr: s.nr }), text: t('dialog.signatur.text'), ja: t('aktion.hochladen'),
      platzhalter: '-----BEGIN PGP SIGNATURE-----',
      zusatz: h('code', { class: 'sg-befehl' }, `gpg --armor --detach-sign ${dateiname(s)}`),
      senden: async (text) => {
        await k.api('POST', `${basis}/${s.id}/signaturen`, { json: { signatur: text } });
        k.melden(t('meldung.signiert', { nr: s.nr }), 'erfolg');
      } });
  }

  function schluesselDialog() {
    textDialog({ id: 'sg-dialog-schluessel', titel: t('dialog.schluessel.titel'), text: t('dialog.schluessel.text'), ja: t('aktion.anmelden'),
      platzhalter: '-----BEGIN PGP PUBLIC KEY BLOCK-----',
      zusatz: h('code', { class: 'sg-befehl' }, t('dialog.schluessel.befehl')),
      senden: async (text) => {
        const r = await k.api('POST', `${basis}/schluessel`, { json: { schluessel: text } });
        schluesselGewaehlt = r.fpr;
        k.melden(t('meldung.angemeldet', { kennung: r.kennung }), 'erfolg');
      } });
  }

  function zurueckDialog() {
    const s = d.schluessel.find((x) => x.fpr === schluesselGewaehlt);
    if (!s) return;
    const grund = h('input', { id: 'sg-grund', maxlength: '300', size: '50', autocomplete: 'off' });
    const ja = knopf(t('aktion.zurueckziehen').replace(' …', ''), async () => {
      dlg.close();
      try {
        await k.api('POST', `${basis}/schluessel/${s.fpr}/zurueckziehen`, { json: { grund: grund.value.trim() } });
        k.melden(t('meldung.zurueck'), 'erfolg');
        await laden();
      } catch (x) { k.fehler(x); }
    }, 'gefahr', { id: 'sg-zurueck-bestaetigen' });
    const pruefen = () => sperren(ja, grund.value.trim() === '' ? t('sperre.grund') : null);
    grund.addEventListener('input', pruefen);
    const dlg = h('dialog', { class: 'dialog', id: 'sg-dialog-zurueck' }, h('h2', {}, t('dialog.zurueck.titel')),
      h('p', {}, t('dialog.zurueck.text', { kennung: s.kennung })), feld(t('feld.grund'), grund),
      h('div', { class: 'dialog-knoepfe' }, knopf(t('aktion.abbrechen'), () => dlg.close()), ja));
    dlg.addEventListener('close', () => dlg.remove());
    document.body.append(dlg);
    pruefen();
    dlg.showModal();
    grund.focus();
  }

  await laden();
}
