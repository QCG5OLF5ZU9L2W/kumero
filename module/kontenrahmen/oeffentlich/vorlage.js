// SPDX-License-Identifier: AGPL-3.0-or-later
// Eine Kontenrahmen-Vorlage ansehen; von hier aus in einem Mandanten anwenden.
import { h, seitenkopf, knopf, tabelle, reiterleiste, kachel, zeit, KONTOARTEN } from '/kern/ui/bausteine.js';
import { herkunft } from './import.js';

const ART = Object.fromEntries(KONTOARTEN);

export async function zeigen(b, k) {
  const t = k.t;
  const v = await k.api('GET', `/api/v1/kontenrahmen/vorlagen/${encodeURIComponent(k.param.kennung || '')}`);
  let reiter = 'alle';
  let suche = '';
  const liste = h('div');
  const sucheFeld = h('input', { type: 'search', class: 'suche', placeholder: t('import.suche'),
    oninput: () => { suche = sucheFeld.value.trim().toLowerCase(); zeichnen(); } });
  b.append(seitenkopf(v.name,
    k.mandant && v.bericht.bereit
      ? knopf(t('aktion.anwenden_in', { mandant: k.mandantName || k.mandant }), () => k.navigieren(`#/m/${k.mandant}/kontenrahmen/anwenden?vorlage=${v.kennung}`), 'primaer')
      : null),
  h('div', { class: 'karte' }, h('dl', { class: 'eigenschaften' },
    h('dt', {}, t('feld.kennung')), h('dd', {}, h('code', {}, v.kennung)),
    h('dt', {}, t('feld.rahmen')), h('dd', {}, v.rahmen + (v.fassung ? `, ${t('feld.fassung')} ${v.fassung}` : '')),
    h('dt', {}, t('feld.quelle')), h('dd', {}, v.quelle.art === 'datev-pdf' ? t('quelle.pdf') : t('quelle.csv'),
      v.quelle.ergaenzung_sha256 ? ` · ${t('feld.mit_korrekturen')}` : ''),
    h('dt', {}, t('feld.regelwerk')), h('dd', {}, `${v.regelwerk.name}, ${t('feld.fassung')} ${v.regelwerk.version}`),
    h('dt', {}, t('feld.eingelesen')), h('dd', {}, zeit(v.eingelesen_am)),
    h('dt', {}, 'SHA-256'), h('dd', {}, h('code', {}, v.sha256)))),
  h('div', { class: 'kacheln' }, kachel(v.bericht.konten, t('bericht.konten')), kachel(v.bericht.pruefen, t('bericht.pruefen_vorlage')),
    kachel(v.bericht.ausgelassen, t('bericht.ausgelassen'))),
  k.mandant ? null : h('p', { class: 'hinweis' }, t('gespeichert.mandant_waehlen')),
  liste);

  function zeichnen() {
    const passt = (x) => !suche || x.nr.toLowerCase().includes(suche) || x.bezeichnung.toLowerCase().includes(suche);
    const listen = { alle: v.konten, pruefen: v.konten.filter((x) => x.pruefen), ausgelassen: v.ausgelassen };
    const zeilen = listen[reiter].filter(passt);
    liste.replaceChildren(
      reiterleiste(['alle', 'pruefen', 'ausgelassen'].map((id) => ({ id, text: t(`reiter.${id}`), anzahl: listen[id].length })), reiter,
        (id) => { reiter = id; zeichnen(); }),
      h('div', { class: 'werkzeugzeile' }, sucheFeld),
      tabelle(reiter === 'ausgelassen' ? [
        { titel: t('spalte.nr'), klasse: 'nr', wert: (x) => x.nr },
        { titel: t('spalte.bezeichnung'), wert: (x) => x.bezeichnung },
        { titel: t('spalte.grund'), wert: (x) => x.grund },
      ] : [
        { titel: t('spalte.nr'), klasse: 'nr', wert: (x) => x.nr },
        { titel: t('spalte.bezeichnung'), wert: (x) => x.bezeichnung },
        { titel: t('spalte.kz'), klasse: 'eng', wert: (x) => x.kennzeichen || '' },
        { titel: t('spalte.kontoart'), klasse: 'eng', wert: (x) => ART[x.kontoart] || x.kontoart },
        { titel: t('spalte.herkunft'), wert: (x) => herkunft(t, x) + (x.pruefen ? ` – ${x.pruefen}` : '') },
      ], { zeilen, fuss: t('fuss.konten', { n: zeilen.length, alle: listen[reiter].length }) }));
  }
  zeichnen();
}
