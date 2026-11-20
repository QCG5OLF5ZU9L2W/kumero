// SPDX-License-Identifier: AGPL-3.0-or-later
// „Module“ (Bereich Mandant – VERWALTUNG.md Abschnitt 16, F142): welches Modul wo aktiv ist – am Mandanten und in jedem
// Buch. Der Administrator schaltet ein und aus und zieht nach einem Update nach. Jeder Schritt ist ein Auftrag, den der
// Dienst auf dem Server ausführt; die Seite wartet darauf. Ausschalten nimmt Menü und Schnittstelle weg, nicht die Daten.
// Kopf und Werkzeugleiste stehen fest, gesperrte Knöpfe nennen den Grund (F106); Spalten mit Sortierung und Filter (F91).
import { h, seitenkopf, knopf, sperren, bestaetigen, zeit } from '../ui/bausteine.js';
import { tabellenfilter } from '../ui/tabellenfilter.js';

const FEHLER = { 'modul.abhaengigkeit': 'Ein Modul, auf dem es aufbaut, ist dort nicht aktiv', 'modul.wird_gebraucht': 'Ein aktives Modul baut darauf auf',
  'modul.nicht_aktiv': 'Das Modul ist dort nicht aktiv', 'modul.einschalten': 'Das Einschalten ist gescheitert – Einzelheiten stehen im Log des Dienstes',
  'modul.nachziehen': 'Nicht jedes Modul ließ sich nachziehen – Einzelheiten stehen im Log des Dienstes', 'modul.unbekannt': 'Das Modul gibt es in diesem Release nicht',
  'modul.ebene': 'Modul und Ort passen nicht zusammen', 'auftrag.fehler': 'Fehler bei der Ausführung – Einzelheiten stehen im Log des Dienstes',
  'auftrag.abgebrochen': 'Der Dienst hat den Auftrag nicht beendet' };
const ZUSTAND = { aktiv: '● aktiv', aus: '○ ausgeschaltet', nie: '– nie eingeschaltet' };
const TUN = { einschalten: 'eingeschaltet', ausschalten: 'ausgeschaltet', nachziehen: 'nachgezogen' };

export async function zeigen(b, k) {
  b.classList.add('listenseite');
  const basis = `${k.mandantApi}/module`;
  let d = { module: [], auftraege: [], laeuft: false };
  let zeilen = [];
  let gewaehlt = null;
  let wartet = null;
  let wartetAuf = null;

  const knEin = knopf('Einschalten', () => beauftragen('einschalten'), 'primaer', { id: 'mo-ein', title: 'Schaltet das gewählte Modul dort ein: spielt seine Tabellen ein, meldet Rechte an und gibt den Rollen ihre Bausteine' });
  const knAus = knopf('Ausschalten …', () => beauftragen('ausschalten'), '', { id: 'mo-aus', title: 'Nimmt Menü, Seiten und Schnittstelle des gewählten Moduls weg – Daten, Rechte und Rollen bleiben' });
  const knNach = knopf('Alle nachziehen', () => beauftragen('nachziehen'), '', { id: 'mo-nach', title: 'Bringt alle aktiven Module am Mandanten und in allen Büchern auf den Stand des installierten Releases' });
  const stand = h('span', { class: 'hinweis', id: 'mo-stand', role: 'status' });
  const liste = h('div', { id: 'mo-liste', class: 'liste-rahmen listen-rollen' });

  const zustandText = (z) => ZUSTAND[z.zustand] + (z.zustand !== 'nie' ? ` (${z.stand})` : '') + (z.veraltet ? ` → ${z.version}` : '');
  const spalten = [
    { id: 'modul', titel: 'Modul', klasse: 'eng', wert: (z) => z.modul },
    { id: 'ort', titel: 'Wo', wert: (z) => (z.buch === null ? 'Mandant' : `Buch ${z.ort}`) },
    { id: 'zustand', titel: 'Zustand', wert: (z) => zustandText(z),
      zelle: (z) => h('span', { 'data-zustand': z.veraltet ? 'veraltet' : `modul-${z.zustand}`, title: z.veraltet ? 'Das Release bringt eine neuere Fassung – „Alle nachziehen“' : '' }, zustandText(z) + (z.veraltet ? ' ▲ nachziehen' : '')) },
    { id: 'braucht', titel: 'Baut auf', wert: (z) => z.abhaengigkeiten.join(', ') },
    { id: 'beschreibung', titel: 'Was es tut', wert: (z) => z.beschreibung },
  ];
  const filter = tabellenfilter(spalten, zeichnen);
  const schluessel = (z) => `${z.modul}|${z.buch ?? ''}`;
  const wahl = () => zeilen.find((z) => schluessel(z) === gewaehlt) || null;
  const aktivDort = (z, modul) => zeilen.some((y) => y.modul === modul && y.buch === z.buch && y.zustand === 'aktiv');

  function knoepfe() {
    const z = wahl();
    const laeuft = d.laeuft ? 'Für diesen Mandanten läuft gerade ein Auftrag' : null;
    const keine = z ? null : 'Erst ein Modul in der Liste wählen';
    const fehlt = z ? z.abhaengigkeiten.filter((n) => !aktivDort(z, n)) : [];
    const braucht = z ? zeilen.filter((y) => y.buch === z.buch && y.zustand === 'aktiv' && y.abhaengigkeiten.includes(z.modul)).map((y) => y.modul) : [];
    sperren(knEin, laeuft || keine || (z.zustand === 'aktiv' && !z.veraltet ? 'Das Modul ist dort schon aktiv' : null) || (fehlt.length ? `Erst einschalten: ${fehlt.join(', ')}` : null));
    sperren(knAus, laeuft || keine || (z.zustand === 'aktiv' ? null : 'Das Modul ist dort nicht aktiv') || (braucht.length ? `Erst ausschalten: ${braucht.join(', ')}` : null));
    sperren(knNach, laeuft || (zeilen.some((z2) => z2.veraltet) ? null : 'Alle aktiven Module sind auf dem Stand des Releases'));
  }

  function zeichnen() {
    filter.setzeZeilen(zeilen);
    const sicht = filter.anwenden(zeilen);
    const tbody = h('tbody', {}, sicht.length ? sicht.map((z) => {
      const tr = h('tr', { 'data-modul': z.modul, 'data-buch': z.buch ?? '', tabindex: '0', class: schluessel(z) === gewaehlt ? 'gewaehlt' : '', 'aria-selected': schluessel(z) === gewaehlt ? 'true' : 'false' },
        spalten.map((s) => h('td', { class: s.klasse || '' }, s.zelle ? s.zelle(z) : s.wert(z))));
      const waehlen = () => { gewaehlt = schluessel(z); zeichnen(); };
      tr.addEventListener('click', waehlen);
      tr.addEventListener('keydown', (ev) => { if (ev.target === tr && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); waehlen(); } });
      return tr;
    }) : h('tr', { class: 'leer' }, h('td', { colspan: spalten.length }, zeilen.length ? 'Kein Modul passt zum Filter.' : 'Dieses Release bringt keine Module mit.')));
    const aktiv = zeilen.filter((z) => z.zustand === 'aktiv').length;
    const alt = zeilen.filter((z) => z.veraltet).length;
    liste.replaceChildren(h('div', { class: 'liste-scroll' }, h('table', { class: 'liste', id: 'mo-tabelle' }, h('thead', {}, filter.kopf), tbody)),
      h('div', { class: 'liste-fuss' }, `${aktiv} aktiv${alt ? ` · ${alt} nachzuziehen` : ''}${filter.aktiv() ? ' · gefiltert' : ''}`));
    const letzter = d.auftraege[0];
    const was = (a) => (a.art === 'nachziehen' ? 'Nachziehen' : `${a.ergebnis?.modul || 'Modul'}${a.ergebnis?.buch ? ` im Buch ${a.ergebnis.buch}` : ''}`);
    stand.textContent = d.laeuft ? 'Ein Auftrag läuft – der Dienst auf dem Server arbeitet …'
      : (!letzter ? '' : (letzter.zustand === 'fehlgeschlagen' ? `Letzter Auftrag (${was(letzter)}, ${zeit(letzter.angelegt_am)}) fehlgeschlagen: ${FEHLER[letzter.ergebnis?.code] || letzter.ergebnis?.code || ''}`
        : `Zuletzt: ${was(letzter)} ${letzter.art === 'nachziehen' ? `– ${(letzter.ergebnis?.nachgezogen || []).length} Module nachgezogen` : TUN[letzter.art]}, ${zeit(letzter.angelegt_am)}`));
    knoepfe();
    clearTimeout(wartet);
    if (d.laeuft && b.isConnected) wartet = setTimeout(() => { if (b.isConnected) laden(); }, 3000);
  }

  async function laden() {
    try {
      d = await k.api('GET', basis);
      zeilen = d.module.flatMap((m) => m.staende.map((s) => ({ modul: m.name, version: m.version, beschreibung: m.beschreibung, abhaengigkeiten: m.abhaengigkeiten,
        buch: s.buch, ort: s.ort, zustand: s.zustand, stand: s.version, veraltet: s.veraltet })));
      zeichnen();
      // der Auftrag, auf den die Seite gewartet hat, ist durch: melden – und das Menü zeigt, was jetzt gilt
      if (wartetAuf && !d.laeuft) {
        const a = d.auftraege.find((x) => x.id === wartetAuf);
        wartetAuf = null;
        if (a?.zustand === 'erledigt') { k.melden(a.art === 'nachziehen' ? 'Module nachgezogen.' : `Modul ${TUN[a.art]}.`, 'erfolg'); await k.menueNeuLaden?.(); }
        else if (a) k.melden(`Auftrag fehlgeschlagen: ${FEHLER[a.ergebnis?.code] || a.ergebnis?.code || ''}`, 'fehler');
      }
    } catch (x) { k.fehler(x); }
  }

  async function beauftragen(art) {
    const z = wahl();
    if (art !== 'nachziehen' && !z) return;
    const wo = z ? (z.buch === null ? 'am Mandanten' : `im Buch ${z.ort}`) : '';
    if (art === 'ausschalten' && !await bestaetigen(`Modul ${z.modul} ${wo} ausschalten?`,
      'Menü, Seiten und Schnittstelle des Moduls sind danach weg. Seine Daten, Rechte, Rollen und Zuweisungen bleiben; mit „Einschalten“ ist alles wieder da.', 'Ausschalten')) return;
    try {
      const r = await k.api('POST', `${basis}/auftraege`, { json: art === 'nachziehen' ? { art } : { art, modul: z.modul, buch: z.buch } });
      wartetAuf = r.id;
      k.melden('Beauftragt – der Dienst auf dem Server führt es aus.', 'erfolg');
    } catch (x) { k.fehler(x); }
    await laden();
  }

  b.append(seitenkopf(`Module – ${k.mandantName || k.mandant}`, knEin, knAus, knNach, stand),
    h('p', { class: 'hinweis' }, 'Ein Modul gehört zum Mandanten oder zu einem Buch; in jedem Buch wird es eigens eingeschaltet. Ausschalten nimmt Menü und Schnittstelle weg, nicht die Daten. '
      + 'Nach einem Update zieht der Server die aktiven Module von selbst nach; „Alle nachziehen“ holt es nach, wenn das nicht geschehen ist.'),
    liste);
  await laden();
  return () => clearTimeout(wartet);
}
