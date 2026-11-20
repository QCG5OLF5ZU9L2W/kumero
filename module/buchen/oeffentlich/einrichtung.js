// SPDX-License-Identifier: AGPL-3.0-or-later
// Was zum Buchen da sein muss: Geschäftsjahre (F65), Buchungsarten mit Nummernkreis und die
// Nummernvorlage je Geschäftsjahr (F25), dazu Tags (F8). Nur vorhandene Endpunkte des Kerns.
import { h, seitenkopf, knopf, feld, auswahl, tabelle, bestaetigen, datumFeld } from '/kern/ui/bausteine.js';
import { stilLaden, stammdatenLaden, fehlendes, datumZeigen, heute, satzAusBezeichnung } from './gemeinsam.js';

export async function zeigen(b, k) {
  stilLaden();
  const t = k.t;
  const basis = k.apiBasis;
  const darf = { jahr: k.darf('kern.geschaeftsjahr.anlegen'), stamm: k.darf('kern.stammdaten.aendern'), kreis: k.darf('kern.nummernkreis.festlegen') };
  const inhalt = h('div');
  b.append(seitenkopf(t('einrichtung.titel', { mandant: k.mandantName || k.mandant }),
    knopf(t('menue.erfassen'), () => k.navigieren(`${k.seitenBasis}/buchen/erfassen`))), inhalt);

  const tun = async (f, meldung) => {
    try { await f(); k.melden(meldung, 'erfolg'); } catch (e) { k.fehler(e); }
    await zeichnen();
  };
  const vorlageFuer = (kreis) => `${kreis}-{JJJJ}-{NR:5}`;

  async function zeichnen() {
    const s = await stammdatenLaden(k);
    const f = fehlendes(s);
    const teile = [];

    // Schnelleinrichtung: nur solange noch nichts da ist
    if (f.jahr && f.art && darf.jahr && darf.stamm && darf.kreis) {
      const j = heute().slice(0, 4);
      teile.push(h('div', { class: 'karte', id: 'schnell' }, h('h2', {}, t('einrichtung.schnell')),
        h('p', {}, t('einrichtung.schnell_text', { jahr: j, vorlage: vorlageFuer('ALLG') })),
        h('p', { class: 'hinweis' }, t('einrichtung.vorlage_hinweis')),
        knopf(t('einrichtung.schnell_knopf'), () => tun(async () => {
          await k.api('POST', `${basis}/geschaeftsjahre`, { json: { kennung: j, beginn: `${j}-01-01`, ende: `${j}-12-31` } });
          await k.api('POST', `${basis}/nummernkreise`, { json: { kuerzel: 'ALLG', bezeichnung: t('einrichtung.allgemein') } });
          await k.api('POST', `${basis}/buchungsarten`, { json: { kuerzel: 'ALLG', bezeichnung: t('einrichtung.allgemein'), nummernkreis: 'ALLG' } });
          await k.api('PUT', `${basis}/nummernkreise/ALLG/jahre/${j}`, { json: { vorlage: vorlageFuer('ALLG'), zuruecksetzen: true } });
        }, t('einrichtung.fertig')), 'primaer', { id: 'schnell-knopf' })));
    }

    // Geschäftsjahre
    const letztes = s.geschaeftsjahre[s.geschaeftsjahre.length - 1];
    const naechstes = letztes ? String(Number(letztes.ende.slice(0, 4)) + 1) : heute().slice(0, 4);
    const tagNach = letztes ? (() => { const d = new Date(`${letztes.ende}T12:00:00Z`); d.setUTCDate(d.getUTCDate() + 1); return d.toISOString().slice(0, 10); })() : `${naechstes}-01-01`;
    const gj = { kennung: h('input', { id: 'gj-kennung', required: true, maxlength: '32', size: '8', value: naechstes }),
      beginn: datumFeld({ id: 'gj-beginn', required: true, value: tagNach }, { kalenderTitel: t('hilfe.kalender'), ungueltig: t('hinweis.datum') }),
      ende: datumFeld({ id: 'gj-ende', required: true, value: `${tagNach.slice(0, 4)}-12-31` }, { kalenderTitel: t('hilfe.kalender'), ungueltig: t('hinweis.datum') }) };
    teile.push(h('div', { class: 'karte' }, h('h2', {}, t('einrichtung.jahre')),
      tabelle([
        { titel: t('einrichtung.kennung'), wert: (g) => g.kennung },
        { titel: t('feld.von'), wert: (g) => datumZeigen(g.beginn) }, { titel: t('feld.bis'), wert: (g) => datumZeigen(g.ende) },
        { titel: t('einrichtung.status'), wert: (g) => t(`jahr.${g.status}`) },
      ], { zeilen: s.geschaeftsjahre, leer: t('einrichtung.keine_jahre') }),
      darf.jahr ? h('form', { class: 'zeile', onsubmit: (ev) => {
        ev.preventDefault();
        if (!gj.beginn.wert() || !gj.ende.wert()) { k.melden(t('hinweis.datum'), 'fehler'); return; }
        tun(() => k.api('POST', `${basis}/geschaeftsjahre`, { json: { kennung: gj.kennung.value.trim(), beginn: gj.beginn.wert(), ende: gj.ende.wert() } }), t('einrichtung.jahr_angelegt'));
      } }, feld(t('einrichtung.kennung'), gj.kennung), feld(t('feld.von'), gj.beginn.el), feld(t('feld.bis'), gj.ende.el, t('einrichtung.jahr_hinweis')),
      h('button', { type: 'submit', class: 'knopf', id: 'gj-anlegen' }, t('einrichtung.jahr_anlegen'))) : null));

    // Buchungsarten mit Nummernkreis
    const ba = { kuerzel: h('input', { id: 'ba-kuerzel', required: true, maxlength: '16', size: '8', pattern: '[A-Za-z0-9_\\-]{1,16}' }),
      bezeichnung: h('input', { id: 'ba-bezeichnung', required: true, maxlength: '200', size: '24' }),
      kreis: auswahl([['', t('einrichtung.kreis_neu')], ...s.nummernkreise.map((n) => [n.kuerzel, `${n.kuerzel} – ${n.bezeichnung}`])], '', { id: 'ba-kreis' }) };
    teile.push(h('div', { class: 'karte' }, h('h2', {}, t('einrichtung.arten')), h('p', { class: 'hinweis' }, t('einrichtung.arten_text')),
      tabelle([
        { titel: t('einrichtung.kuerzel'), klasse: 'nr', wert: (a) => a.kuerzel }, { titel: t('spalte.bezeichnung'), wert: (a) => a.bezeichnung },
        { titel: t('einrichtung.kreis'), wert: (a) => a.nummernkreis },
      ], { zeilen: s.buchungsarten, leer: t('einrichtung.keine_arten') }),
      darf.stamm ? h('form', { class: 'zeile', onsubmit: (ev) => {
        ev.preventDefault();
        const kz = ba.kuerzel.value.trim();
        tun(async () => {
          if (!ba.kreis.value) await k.api('POST', `${basis}/nummernkreise`, { json: { kuerzel: kz, bezeichnung: ba.bezeichnung.value.trim() } });
          await k.api('POST', `${basis}/buchungsarten`, { json: { kuerzel: kz, bezeichnung: ba.bezeichnung.value.trim(), nummernkreis: ba.kreis.value || kz } });
        }, t('einrichtung.art_angelegt'));
      } }, feld(t('einrichtung.kuerzel'), ba.kuerzel), feld(t('spalte.bezeichnung'), ba.bezeichnung), feld(t('einrichtung.kreis'), ba.kreis),
      h('button', { type: 'submit', class: 'knopf', id: 'ba-anlegen' }, t('einrichtung.art_anlegen'))) : null));

    // Nummernvorlage je Nummernkreis und Geschäftsjahr
    const paare = [];
    for (const n of s.nummernkreise) {
      for (const g of s.geschaeftsjahre) paare.push({ n, g, j: n.jahre.find((x) => x.geschaeftsjahr === g.kennung) || null });
    }
    teile.push(h('div', { class: 'karte' }, h('h2', {}, t('einrichtung.vorlagen')), h('p', { class: 'hinweis' }, t('einrichtung.vorlage_hinweis')),
      tabelle([
        { titel: t('einrichtung.kreis'), klasse: 'nr', wert: (p) => p.n.kuerzel }, { titel: t('feld.geschaeftsjahr'), klasse: 'eng', wert: (p) => p.g.kennung },
        { titel: t('einrichtung.vorlage'), wert: (p) => {
          if (p.j) return h('code', {}, p.j.vorlage);
          if (!darf.kreis || p.g.status !== 'offen') return h('span', { class: 'hinweis' }, t('einrichtung.nicht_festgelegt'));
          const ein = h('input', { value: vorlageFuer(p.n.kuerzel), maxlength: '64', size: '26', 'aria-label': t('einrichtung.vorlage') });
          return h('span', { class: 'zeile' }, ein, knopf(t('einrichtung.festlegen'), async () => {
            if (!await bestaetigen(t('einrichtung.festlegen_titel'), t('einrichtung.festlegen_frage', { vorlage: ein.value, kreis: p.n.kuerzel, jahr: p.g.kennung }), t('einrichtung.festlegen'))) return;
            tun(() => k.api('PUT', `${basis}/nummernkreise/${encodeURIComponent(p.n.kuerzel)}/jahre/${encodeURIComponent(p.g.kennung)}`,
              { json: { vorlage: ein.value.trim(), zuruecksetzen: true } }), t('einrichtung.festgelegt'));
          }, 'klein'));
        } },
        { titel: t('einrichtung.zaehler'), klasse: 'zahl', wert: (p) => (p.j ? String(p.j.zaehler) : '') },
      ], { zeilen: paare, leer: t('einrichtung.keine_kreise') })));

    // Steuerkonten (F101): Der Satz am Konto lässt die Buchungsmaske aus dem Brutto rechnen
    const darfSteuer = k.darf('buchen.vorlage.pflegen');
    const kandidaten = s.konten.filter((x) => s.steuerkonten.has(x.nr) || satzAusBezeichnung(x.bezeichnung) !== null);
    const satzFelder = new Map();
    const satzText = (n) => (n === null || n === undefined ? '' : String(n).replace('.', ','));
    const steuerNeu = h('input', { id: 'steuer-neu-konto', maxlength: '20', size: '8', list: 'steuer-konten', 'aria-label': t('feld.konto') });
    const steuerNeuSatz = h('input', { id: 'steuer-neu-satz', size: '5', inputmode: 'decimal', 'aria-label': t('einrichtung.steuersatz') });
    const steuerSpeichern = () => {
      const saetze = {};
      const lesen = (text) => { const w = text.trim().replace(',', '.'); if (w === '') return null; return /^\d{1,2}(\.\d{1,2})?$/.test(w) && Number(w) > 0 ? Number(w).toFixed(2) : undefined; };
      for (const [nr, ein] of satzFelder) {
        const w = lesen(ein.value);
        if (w === undefined) { k.melden(t('einrichtung.steuersatz_falsch', { konto: nr }), 'fehler'); ein.focus(); return; }
        if (w !== null || s.steuerkonten.has(nr)) saetze[nr] = w;
      }
      if (steuerNeu.value.trim()) {
        const w = lesen(steuerNeuSatz.value);
        if (!s.konto(steuerNeu.value.trim()) || !w) { k.melden(t('einrichtung.steuersatz_falsch', { konto: steuerNeu.value.trim() }), 'fehler'); return; }
        saetze[steuerNeu.value.trim()] = w;
      }
      if (!Object.keys(saetze).length) { k.melden(t('einrichtung.steuer_nichts'), 'warnung'); return; }
      tun(() => k.api('PUT', `${basis}/buchen/steuerkonten`, { json: { saetze } }), t('einrichtung.steuer_gespeichert'));
    };
    teile.push(h('div', { class: 'karte', id: 'steuerkonten' }, h('h2', {}, t('einrichtung.steuerkonten')), h('p', { class: 'hinweis' }, t('einrichtung.steuerkonten_text')),
      tabelle([
        { titel: t('feld.konto'), klasse: 'nr', wert: (x) => x.nr }, { titel: t('spalte.bezeichnung'), wert: (x) => x.bezeichnung },
        { titel: t('einrichtung.steuersatz'), wert: (x) => {
          const gesetzt = s.steuerkonten.get(x.nr);
          const vorschlag = satzAusBezeichnung(x.bezeichnung);
          if (!darfSteuer) return gesetzt ? `${satzText(gesetzt)} %` : '';
          const ein = h('input', { size: '5', inputmode: 'decimal', 'data-steuerkonto': x.nr, value: satzText(gesetzt ?? vorschlag), 'aria-label': `${t('einrichtung.steuersatz')} ${x.nr}` });
          satzFelder.set(x.nr, ein);
          return h('span', { class: 'zeile' }, ein, ' %', gesetzt ? null : h('span', { class: 'marke-klein warnung' }, t('einrichtung.steuer_vorschlag')));
        } },
      ], { zeilen: kandidaten, leer: t('einrichtung.keine_steuerkonten') }),
      darfSteuer ? h('div', { class: 'zeile' }, feld(t('einrichtung.steuer_weiteres'), steuerNeu), feld(t('einrichtung.steuersatz'), steuerNeuSatz),
        h('datalist', { id: 'steuer-konten' }, s.konten.map((x) => h('option', { value: x.nr }, x.bezeichnung))),
        knopf(t('einrichtung.steuer_speichern'), steuerSpeichern, 'primaer', { id: 'steuer-speichern' })) : null));

    // Tags
    const tagName = h('input', { id: 'tag-name', required: true, maxlength: '100', size: '24' });
    teile.push(h('div', { class: 'karte' }, h('h2', {}, t('feld.tags')), h('p', { class: 'hinweis' }, t('einrichtung.tags_text')),
      h('p', {}, s.tags.length ? s.tags.map((x) => h('span', { class: 'marke-klein' }, x.name)) : h('span', { class: 'hinweis' }, t('hinweis.keine_tags'))),
      darf.stamm ? h('form', { class: 'zeile', onsubmit: (ev) => {
        ev.preventDefault();
        tun(() => k.api('POST', `${basis}/tags`, { json: { name: tagName.value.trim() } }), t('einrichtung.tag_angelegt'));
      } }, feld(t('einrichtung.tag'), tagName), h('button', { type: 'submit', class: 'knopf', id: 'tag-anlegen' }, t('einrichtung.tag_anlegen'))) : null));

    inhalt.replaceChildren(
      f.etwas ? h('div', { class: 'meldung warnung', id: 'einrichtung-offen' }, t('einrichtung.offen')) : h('div', { class: 'meldung erfolg', id: 'einrichtung-bereit' }, t('einrichtung.bereit')),
      ...teile);
  }
  await zeichnen();
}
