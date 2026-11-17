// SPDX-License-Identifier: AGPL-3.0-or-later
// Vermerk der angewendeten Kontenrahmen im Mandanten (F75 Nr. 6).
import { h, seitenkopf, tabelle, zeit } from '/kern/ui/bausteine.js';

export async function zeigen(b, k) {
  const t = k.t;
  const liste = await k.api('GET', `${k.apiBasis}/kontenrahmen/angewendet`);
  b.append(seitenkopf(t('angewendet.titel', { mandant: k.mandantName || k.mandant })), tabelle([
    { titel: t('spalte.am'), klasse: 'eng', wert: (x) => zeit(x.angewendet_am) },
    { titel: t('spalte.name'), wert: (x) => x.name },
    { titel: t('feld.rahmen'), klasse: 'eng', wert: (x) => x.rahmen + (x.fassung ? ` (${x.fassung})` : '') },
    { titel: t('feld.quelle'), klasse: 'eng', wert: (x) => (x.quelle?.art === 'datev-pdf' ? 'PDF' : 'CSV') },
    { titel: t('ergebnis.angelegt'), klasse: 'zahl eng', wert: (x) => x.ergebnis.angelegt },
    { titel: t('ergebnis.konflikte'), klasse: 'zahl eng', wert: (x) => x.ergebnis.konflikte },
    { titel: t('spalte.von'), klasse: 'eng', wert: (x) => (x.angewendet_von === k.ich.subjekt ? k.ich.name : x.angewendet_von) },
    { titel: 'SHA-256', klasse: 'eng', wert: (x) => h('code', {}, x.vorlage_sha256.slice(0, 12)) },
  ], { zeilen: liste, leer: t('leer.angewendet') }));
}
