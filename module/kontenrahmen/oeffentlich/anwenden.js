// SPDX-License-Identifier: AGPL-3.0-or-later
// Vorlage auf den gewählten Mandanten anwenden: Konten anlegen (F2, F74, KONTENRAHMEN.md 7).
import { h, seitenkopf, knopf, feld, auswahl, kachel, bestaetigen, zeit } from '/kern/ui/bausteine.js';

export async function zeigen(b, k) {
  const t = k.t;
  const basis = `${k.apiBasis}/kontenrahmen`;
  const vorlagen = (await k.api('GET', `${basis}/vorlagen`)).filter((v) => v.bericht.bereit);
  b.append(seitenkopf(t('anwenden.titel', { mandant: k.mandantName || k.mandant })));
  if (!vorlagen.length) {
    b.append(h('div', { class: 'karte' }, h('p', {}, t('anwenden.keine')),
      k.ich.verwalter ? knopf(t('import.titel'), () => k.navigieren('#/system/kontenrahmen/import'), 'primaer') : null));
    return;
  }
  const wahl = auswahl(vorlagen.map((v) => [v.kennung, `${v.name} – ${v.bericht.konten} ${t('spalte.konten')}`]),
    vorlagen.some((v) => v.kennung === k.param.vorlage) ? k.param.vorlage : vorlagen[0].kennung, { id: 'vorlage-wahl' });
  const bez = h('input', { type: 'checkbox', id: 'bezeichnungen' });
  const info = h('p', { class: 'hinweis' });
  const ergebnis = h('div');
  const zeigeInfo = () => {
    const v = vorlagen.find((x) => x.kennung === wahl.value);
    info.textContent = t('anwenden.info', { rahmen: v.rahmen, eingelesen: zeit(v.eingelesen_am), pruefen: v.bericht.pruefen });
  };
  wahl.addEventListener('change', zeigeInfo);
  zeigeInfo();

  b.append(h('div', { class: 'karte' },
    h('p', {}, t('anwenden.erklaerung')),
    feld(t('anwenden.vorlage'), wahl), info,
    h('label', { class: 'feld-wahl' }, bez, t('anwenden.bezeichnungen')),
    h('div', { class: 'leiste-unten' }, knopf(t('aktion.anwenden'), async () => {
      const v = vorlagen.find((x) => x.kennung === wahl.value);
      if (!await bestaetigen(t('anwenden.frage_titel'), t('anwenden.frage', { name: v.name, mandant: k.mandantName || k.mandant }), t('aktion.anwenden'))) return;
      try {
        const e = await k.api('POST', `${basis}/anwenden`, { json: { vorlage: v.kennung, bezeichnungen: bez.checked } });
        ergebnis.replaceChildren(h('div', { class: 'karte' }, h('h2', {}, t('anwenden.fertig')),
          h('div', { class: 'kacheln' }, kachel(e.angelegt, t('ergebnis.angelegt'), 'erfolg'), kachel(e.vorhanden, t('ergebnis.vorhanden')),
            kachel(e.umbenannt, t('ergebnis.umbenannt')), kachel(e.konflikte.length, t('ergebnis.konflikte'), e.konflikte.length ? 'warnung' : '')),
          e.konflikte.length ? h('ul', {}, e.konflikte.map((x) => h('li', {}, x))) : null,
          e.pruefen ? h('p', { class: 'hinweis' }, t('anwenden.pruefen_hinweis', { n: e.pruefen })) : null,
          h('div', { class: 'leiste-unten' }, knopf(t('aktion.zu_konten'), () => k.navigieren(`#/m/${k.mandant}/kern/konten`), 'primaer'))));
        k.melden(t('anwenden.meldung', { n: e.angelegt }), 'erfolg');
      } catch (f) { k.fehler(f); }
    }, 'primaer', { id: 'anwenden' }))),
  ergebnis);
}
