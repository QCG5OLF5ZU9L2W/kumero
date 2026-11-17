// SPDX-License-Identifier: AGPL-3.0-or-later
// Geschäftsvorfälle pflegen (BUCHUNGSMASKE.md Abschnitt 3, M5): Vorlagen sind Daten im Mandanten, keine Logik.
import { h, seitenkopf, knopf, feld, auswahl, tabelle, bestaetigen } from '/kern/ui/bausteine.js';
import { stilLaden, stammdatenLaden, kontoVorschau } from './gemeinsam.js';

export async function zeigen(b, k) {
  stilLaden();
  const t = k.t;
  const basis = `${k.apiBasis}/buchen/vorlagen`;
  const s = await stammdatenLaden(k);
  let vorlagen = [];
  let akt = null;

  const f = {
    gruppe: h('input', { id: 'v-gruppe', required: true, maxlength: '60', list: 'v-gruppen' }),
    name: h('input', { id: 'v-name', required: true, maxlength: '100', size: '30' }),
    konto: h('input', { id: 'v-konto', class: 'konto-nr', maxlength: '20', autocomplete: 'off', title: t('hilfe.konto') }),
    gegenkonto: h('input', { id: 'v-gegenkonto', class: 'konto-nr', maxlength: '20', autocomplete: 'off', title: t('hilfe.gegenkonto') }),
    buchungsart: auswahl([['', '–'], ...s.buchungsarten.map((a) => [a.kuerzel, a.kuerzel])], '', { id: 'v-art' }),
    text: h('input', { id: 'v-text', maxlength: '500', size: '30' }),
    tags: h('input', { id: 'v-tags', size: '20', placeholder: s.tags.map((x) => x.name).slice(0, 2).join(', ') }),
    reihenfolge: h('input', { id: 'v-reihenfolge', type: 'number', min: '0', max: '100000', value: '100', class: 'kurz' }),
  };
  const kontoEingabe = (ein) => { const e = h('span', { class: 'konto-eingabe' }, ein); kontoVorschau(ein, s.konten); return e; };
  const gruppen = h('datalist', { id: 'v-gruppen' });
  const titel = h('h2', {});
  const loeschen = knopf(t('aktion.loeschen'), async () => {
    if (!akt || !await bestaetigen(t('vorlagen.loeschen_titel'), t('vorlagen.loeschen_frage', { name: akt.name }), t('aktion.loeschen'))) return;
    try { await k.api('DELETE', `${basis}/${akt.id}`); k.melden(t('vorlagen.geloescht'), 'erfolg'); await laden(); neu(); } catch (e) { k.fehler(e); }
  }, 'gefahr', { hidden: true });
  const liste = h('div');
  const formular = h('form', { class: 'karte', id: 'vorlage-formular', onsubmit: async (ev) => {
    ev.preventDefault();
    const daten = {
      gruppe: f.gruppe.value.trim(), name: f.name.value.trim(), konto: f.konto.value.trim() || null, gegenkonto: f.gegenkonto.value.trim() || null,
      buchungsart: f.buchungsart.value || null, text: f.text.value.trim() || null,
      tags: f.tags.value.split(',').map((x) => x.trim()).filter(Boolean), reihenfolge: Number(f.reihenfolge.value || 100),
    };
    const unbekannt = [daten.konto, daten.gegenkonto].filter((nr) => nr && !s.konto(nr));
    try {
      if (akt) await k.api('PUT', `${basis}/${akt.id}`, { json: daten }); else await k.api('POST', basis, { json: daten });
      k.melden(t('vorlagen.gespeichert') + (unbekannt.length ? ` ${t('vorlagen.konto_unbekannt', { nr: unbekannt.join(', ') })}` : ''), unbekannt.length ? 'warnung' : 'erfolg');
      await laden();
      neu();
    } catch (e) { k.fehler(e); }
  } }, titel,
  h('div', { class: 'zeile' }, feld(t('vorlagen.gruppe'), f.gruppe), feld(t('vorlagen.name'), f.name), feld(t('vorlagen.reihenfolge'), f.reihenfolge)),
  h('div', { class: 'zeile' }, feld(t('feld.konto'), kontoEingabe(f.konto), t('vorlagen.soll')), feld(t('feld.gegenkonto'), kontoEingabe(f.gegenkonto), t('vorlagen.haben')),
    feld(t('feld.buchungsart'), f.buchungsart), feld(t('feld.text'), f.text), feld(t('feld.tags'), f.tags, t('vorlagen.tags_hinweis'))),
  gruppen,
  h('div', { class: 'leiste-unten' }, loeschen, knopf(t('aktion.neu'), neu), h('button', { type: 'submit', class: 'knopf primaer', id: 'vorlage-speichern' }, t('aktion.speichern'))));

  function neu() {
    akt = null;
    formular.reset();
    f.reihenfolge.value = '100';
    titel.textContent = t('vorlagen.neu');
    loeschen.hidden = true;
  }
  function bearbeiten(v) {
    akt = v;
    for (const n of ['gruppe', 'name', 'konto', 'gegenkonto', 'buchungsart', 'text']) f[n].value = v[n] || '';
    f.tags.value = v.tags.join(', ');
    f.reihenfolge.value = String(v.reihenfolge);
    titel.textContent = t('vorlagen.bearbeiten', { name: v.name });
    loeschen.hidden = false;
    f.name.focus();
  }

  async function laden() {
    vorlagen = await k.api('GET', basis);
    gruppen.replaceChildren(...[...new Set(vorlagen.map((v) => v.gruppe))].map((g) => h('option', { value: g })));
    const konto = (nr) => (nr ? `${nr} ${s.konto(nr)?.bezeichnung || ''}`.trim() : '');
    liste.replaceChildren(tabelle([
      { titel: t('vorlagen.gruppe'), wert: (v) => v.gruppe },
      { titel: t('vorlagen.name'), wert: (v) => v.name },
      { titel: t('feld.konto'), wert: (v) => konto(v.konto) },
      { titel: t('feld.gegenkonto'), wert: (v) => konto(v.gegenkonto) },
      { titel: t('feld.buchungsart'), klasse: 'eng', wert: (v) => v.buchungsart || '' },
      { titel: t('feld.text'), wert: (v) => v.text || '' },
      { titel: t('feld.tags'), wert: (v) => v.tags.join(', ') },
      { titel: '', klasse: 'eng', wert: (v) => knopf(t('aktion.bearbeiten'), () => bearbeiten(v), 'klein') },
    ], { zeilen: vorlagen, oeffnen: bearbeiten, leer: t('vorlagen.leer'), fuss: t('vorlagen.fuss', { n: vorlagen.length }) }));
  }

  b.append(seitenkopf(t('vorlagen.titel', { mandant: k.mandantName || k.mandant }),
    knopf(t('vorlagen.vorgaben'), async () => {
      try {
        const r = await k.api('POST', `${basis}/vorgaben`);
        k.melden(t('vorlagen.vorgaben_fertig', { n: r.angelegt }), 'erfolg');
        await laden();
      } catch (e) { k.fehler(e); }
    }, '', { id: 'vorgaben' })),
  h('p', { class: 'hinweis' }, t('vorlagen.erklaerung')), formular, liste);
  neu();
  await laden();
}
