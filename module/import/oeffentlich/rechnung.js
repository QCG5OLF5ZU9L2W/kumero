// SPDX-License-Identifier: AGPL-3.0-or-later
// E-Rechnungen als Teil der Seite „Datei einlesen“ (IMPORT.md Abschnitt 15, F108): XRechnung (XML) und ZUGFeRD /
// Factur-X (PDF mit XML) prüfen und in den Arbeitsvorrat einliefern. Die Dateien bleiben im Browser und gehen mit
// jeder Anfrage an den Server; gespeichert werden sie dort nicht.
import { h, knopf, feld, auswahl, tabelle, sperren } from '/kern/ui/bausteine.js';

/** Liefert {el, setzen(dateien)}; dateien: [{name, bytes}]. nachher() läuft nach dem Einliefern. */
export function rechnungsteil(k, basis, nachher) {
  const t = k.t;
  const zahl = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const betrag = (dez) => (dez === null || dez === undefined || dez === '' ? '' : zahl.format(Number(dez)));
  const datum = (d) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString() : '');
  let dateien = [];   // {name, bytes, stand: lese|neu|fehlerhaft|vorhanden|eingeliefert|abgelehnt, bericht, grund}

  const seite = auswahl([['eingang', t('rechnung.eingang')], ['ausgang', t('rechnung.ausgang')]], 'eingang', { id: 'rechnung-seite', title: t('rechnung.seite_hilfe') });
  const liefern = knopf(t('rechnung.einliefern'), einliefern, 'primaer', { id: 'rechnung-einliefern' });
  const liste = h('div', { id: 'rechnung-liste' });
  const el = h('div', { class: 'karte breit', id: 'rechnung-teil' }, h('h2', {}, t('rechnung.pruefung')), h('p', { class: 'hinweis' }, t('rechnung.text')),
    h('div', { class: 'zeile' }, feld(t('rechnung.seite'), seite)), liste, h('div', { class: 'zeile' }, liefern), h('p', { class: 'hinweis' }, t('rechnung.aufbewahren')));

  const anfrage = (pfad, d) => k.api('POST', `${basis}/rechnung/${pfad}?${new URLSearchParams({ name: d.name, seite: seite.value })}`, { roh: d.bytes, typ: 'application/octet-stream' });

  function zeichnen() {
    const e = (d) => d.bericht?.eintrag || {};
    liste.replaceChildren(tabelle([
      { titel: t('verlauf.datei'), wert: (d) => d.name },
      { titel: t('rechnung.stand'), wert: (d) => h('span', { class: `rechnung-stand stand-${d.stand}`, 'data-stand': d.stand },
        t(`rechnung.stand_${d.stand}`), d.stand === 'vorhanden' && d.bericht?.zustand ? ` – ${t(`rechnung.zustand_${d.bericht.zustand}`)}` : '', d.grund ? `: ${d.grund}` : '',
        ...(d.bericht?.hinweise || []).map((x) => h('div', { class: 'hinweis' }, x))) },
      { titel: t('rechnung.format'), wert: (d) => d.bericht?.format || '' },
      { titel: t('rechnung.art'), klasse: 'eng', wert: (d) => (e(d).art ? `${t(`rechnung.art_${e(d).art}`)} · ${t(`bericht.${e(d).richtung}`)}` : '') },
      { titel: t('rechnung.gegenpartei'), wert: (d) => e(d).gegenpartei || '' },
      { titel: t('rechnung.nummer'), wert: (d) => e(d).belegnummer || '' },
      { titel: t('rechnung.datum'), klasse: 'eng', wert: (d) => datum(e(d).datum) },
      { titel: t('rechnung.leistung'), klasse: 'eng', wert: (d) => datum(d.bericht?.lieferdatum) },
      { titel: t('rechnung.faellig'), klasse: 'eng', wert: (d) => datum(d.bericht?.faellig) },
      { titel: t('rechnung.positionen'), wert: (d) => (d.bericht?.positionen ? h('span', { title: (d.bericht.positionsnamen || []).join('\n') },
        `${d.bericht.positionen}: ${(d.bericht.positionsnamen || []).slice(0, 2).join(', ')}${d.bericht.positionen > 2 ? ' …' : ''}`) : '') },
      { titel: t('rechnung.netto'), klasse: 'zahl', wert: (d) => betrag(e(d).netto) },
      { titel: t('rechnung.steuer'), klasse: 'zahl', wert: (d) => betrag(e(d).steuer) },
      { titel: t('rechnung.je_satz'), wert: (d) => (d.bericht?.steuern || []).map((x) => h('div', { class: 'rechnung-satz' },
        `${x.satz !== '' ? `${String(Number(x.satz)).replace('.', ',')} %` : x.kategorie}: ${betrag(x.betrag)} ${t('rechnung.auf')} ${betrag(x.basis)}`)) },
      { titel: t('rechnung.brutto'), klasse: 'zahl', wert: (d) => betrag(e(d).betrag) },
    ], { zeilen: dateien, leer: t('rechnung.leer') }));
    const n = dateien.filter((d) => ['neu', 'fehlerhaft', 'nachliefern', 'wieder_oeffnen'].includes(d.stand)).length;
    liefern.textContent = n === 1 ? t('rechnung.einliefern_1') : (n ? t('rechnung.einliefern_n', { n }) : t('rechnung.einliefern'));
    sperren(liefern, !dateien.length ? t('rechnung.grund_keine') : (dateien.some((d) => d.stand === 'lese') ? t('rechnung.grund_liest') : (n ? null : t('rechnung.grund_nichts'))));
  }

  async function pruefen() {
    for (const d of dateien) { d.stand = 'lese'; d.bericht = null; d.grund = null; }
    zeichnen();
    for (const d of dateien) {
      try {
        d.bericht = await anfrage('vorschau', d);
        d.stand = d.bericht.stand;
        d.grund = d.bericht.fehler;
      } catch (x) { d.stand = 'abgelehnt'; d.grund = x.message; }
      zeichnen();
    }
  }

  seite.addEventListener('change', () => pruefen());

  async function einliefern() {
    let n = 0;
    for (const d of dateien) {
      if (!['neu', 'fehlerhaft', 'nachliefern', 'wieder_oeffnen'].includes(d.stand)) continue;
      try {
        const r = await anfrage('einliefern', d);
        d.stand = r.eingeliefert ? 'eingeliefert' : (r.nachgeliefert ? 'nachgeliefert' : (r.wieder_geoeffnet ? 'wieder_geoeffnet' : 'vorhanden'));
        if (r.eingeliefert || r.nachgeliefert || r.wieder_geoeffnet) n += 1;
      } catch (x) { d.stand = 'abgelehnt'; d.grund = x.message; }
      zeichnen();
    }
    k.melden(n === 1 ? t('rechnung.meldung_1') : t('rechnung.meldung', { n }), n ? 'erfolg' : 'warnung');
    k.menueNeuLaden?.();
    await nachher?.();
  }

  return { el, setzen(neu) { dateien = neu.map((d) => ({ name: d.name, bytes: d.bytes, stand: 'lese' })); return pruefen(); } };
}
