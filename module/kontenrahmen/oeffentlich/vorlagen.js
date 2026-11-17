// SPDX-License-Identifier: AGPL-3.0-or-later
// Gespeicherte Kontenrahmen-Vorlagen (installationsweit, K2).
import { h, seitenkopf, knopf, tabelle, zeit } from '/kern/ui/bausteine.js';

export async function zeigen(b, k) {
  const t = k.t;
  const vorlagen = await k.api('GET', '/api/v1/kontenrahmen/vorlagen');
  const oeffnen = (v) => k.navigieren(`#/system/kontenrahmen/vorlage?kennung=${v.kennung}`);
  b.append(seitenkopf(t('vorlagen.titel'), knopf(t('import.titel'), () => k.navigieren('#/system/kontenrahmen/import'), 'primaer')),
    tabelle([
      { titel: t('spalte.name'), wert: (v) => h('a', { href: `#/system/kontenrahmen/vorlage?kennung=${v.kennung}` }, v.name) },
      { titel: t('feld.rahmen'), klasse: 'eng', wert: (v) => v.rahmen + (v.fassung ? ` (${v.fassung})` : '') },
      { titel: t('spalte.konten'), klasse: 'zahl eng', wert: (v) => v.bericht.konten },
      { titel: t('spalte.pruefen'), klasse: 'zahl eng', wert: (v) => v.bericht.pruefen },
      { titel: t('spalte.status'), klasse: 'eng', wert: (v) => (v.bericht.bereit ? t('status.bereit') : t('status.offen')) },
      { titel: t('feld.quelle'), klasse: 'eng', wert: (v) => (v.quelle_art === 'datev-pdf' ? 'PDF' : 'CSV') },
      { titel: t('feld.eingelesen'), klasse: 'eng', wert: (v) => zeit(v.eingelesen_am) },
    ], { zeilen: vorlagen, oeffnen, leer: t('leer.vorlagen') }));
}
