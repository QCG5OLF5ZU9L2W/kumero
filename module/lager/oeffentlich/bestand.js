// SPDX-License-Identifier: AGPL-3.0-or-later
// „Bestand“ (LAGER.md Stufe 1, F131): Artikel des Lagers mit Menge, nächstem Verfall und Zustand in Worten; darunter
// an festem Platz die Chargen des gewählten Artikels. Entnahme mit Vorschlag (frühester Verfall zuerst), abgelaufene
// und gesperrte Chargen sind nicht entnehmbar. Nichts verschiebt sich, gesperrte Knöpfe nennen den Grund (F106).
// Beide Tabellen tragen Sortierung und Filter am Spaltenkopf (F91), ihre Spalten sind verschiebbar (F146); zwischen
// Liste und Chargen liegt der Teiler (F137).
// Artikelgruppen (LAGER.md Abschnitt 17, F168; intern „Meta-Artikel“): eine Zeile für ersetzbare Artikel gleicher Funktion –
// Summe, Mindestbestand und Vorschlag gelten über alle; hinter dem Dreieck stehen die Artikel der Gruppe. Bewegt wird
// immer der konkrete Artikel mit seiner Charge. Die Gruppe trägt ihre Aufgabe (STUECKLISTE.md AG1): wofür ihre Artikel dienen.
// Im Dialog der Gruppe wird auch zugeordnet und gelöst (AG7); „Artikel neu …“ führt in die markierte Gruppe (AG8).
import { h, seitenkopf, knopf, feld, auswahl, sperren, datumFeld, datumText, zeit } from '/kern/ui/bausteine.js';
import { tabellenfilter } from '/kern/ui/tabellenfilter.js';
import { teiler } from '/kern/ui/teiler.js';
import { ARTEN, FUEHRUNG, ZUSTAND, mengeText, mengeLesen, codeLesen, dialog, stil, rfeld, rwahl, tastaturWache } from './gemeinsam.js';

const GRUENDE_AUS = [['verfallen', 'verfallen'], ['beschaedigt', 'beschädigt'], ['zurueckgegeben', 'an den Lieferanten zurückgegeben'], ['vernichtet', 'vernichtet'], ['berichtigung', 'Berichtigung einer falschen Eingabe']];
const GRUENDE_ZU = [['anfangsbestand', 'Anfangsbestand'], ['muster', 'Muster oder Schenkung'], ['berichtigung', 'Berichtigung einer falschen Eingabe']];
const BEWEGUNG = { zugang: 'Zugang', entnahme: 'Entnahme', umlagerung: 'Umlagerung', ausbuchung: 'Ausbuchung' };
const GRUND_TEXT = { ...Object.fromEntries([...GRUENDE_AUS, ...GRUENDE_ZU]), inventur: 'Inventurdifferenz' };

export async function zeigen(b, k) {
  stil();
  b.classList.add('listenseite', 'lager');
  const basis = `${k.apiBasis}/lager`;
  let d = { artikel: [], meta: [], orte: [], einstellung: { warn_tage: 90, zwecke: [] }, zahlen: {}, darf: {}, heute: '' };
  let artikelId = null;
  let chargeId = null;
  let filter = 'alle';
  let gruppen = [];            // Artikelgruppen als Zeilen der Liste, mit den Chargen aller ihrer Artikel
  const offen = new Set();     // aufgeklappte Artikelgruppen

  const suche = h('input', { type: 'search', id: 'lg-suche', class: 'lg-suche', placeholder: 'Artikel, Nummer, Charge oder Seriennummer – oder Packung scannen', 'aria-label': 'Suchen', autocomplete: 'off' });
  const kn = {
    entnahme: knopf('Entnahme …', entnahmeDialog, 'primaer', { id: 'lg-entnahme', title: 'Aus der unten gewählten Zeile entnehmen – einer Charge oder einem Stück mit Seriennummer' }),
    zugang: knopf('Zugang ohne Beleg …', () => zugangDialog(), '', { id: 'lg-zugang', title: 'Anfangsbestand, Muster oder Berichtigung – Zugänge aus Rechnungen stehen unter „Wareneingang“' }),
    umlagern: knopf('Umlagern …', umlagernDialog, '', { id: 'lg-umlagern', title: 'Die unten gewählte Zeile an einen anderen Lagerort bringen' }),
    ausbuchen: knopf('Ausbuchen …', ausbuchenDialog, '', { id: 'lg-ausbuchen', title: 'Verfallen, beschädigt, zurückgegeben oder vernichtet – auch für abgelaufene und gesperrte Chargen' }),
    sperre: knopf('Sperren …', sperreDialog, '', { id: 'lg-sperre', title: 'Die unten gewählte Charge oder das gewählte Stück sperren oder wieder freigeben' }),
    verlauf: knopf('Rückverfolgung …', verlaufDialog, '', { id: 'lg-verlauf', title: 'Alle Bewegungen der unten gewählten Charge bzw. des gewählten Stücks' }),
    neu: knopf('Artikel neu …', () => artikelDialog(null, markierteGruppe()), '', { id: 'lg-artikel-neu', title: 'Artikel anlegen' }),
    gruppe: knopf('Artikelgruppe neu …', () => gruppeDialog(null), '', { id: 'lg-gruppe-neu', title: 'Artikel gleicher Aufgabe zusammenfassen, die einander ersetzen – mit gemeinsamem Mindestbestand' }),
    aendern: knopf('Bearbeiten …', () => (zeile()?.istMeta ? gruppeDialog(zeile()) : artikelDialog(zeile())), '', { id: 'lg-artikel-aendern', title: 'Angaben des gewählten Artikels oder der gewählten Artikelgruppe ändern' }),
    orte: knopf('Lagerorte …', orteDialog, '', { id: 'lg-orte', title: 'Lagerorte mit ihrem Bestand ansehen, anlegen, umbenennen, stilllegen' }),
    einstellung: knopf('Einstellungen …', einstellungDialog, '', { id: 'lg-einstellung', title: 'Warnfrist vor dem Verfall und die Auswahlliste „Zweck“ der Entnahme' }),
    drucken: knopf('Drucken', () => window.print(), '', { id: 'lg-drucken', title: 'Bestandsliste drucken; als PDF über den Druckdialog' }),
  };
  const kacheln = h('div', { class: 'lg-kacheln nicht-drucken', id: 'lg-kacheln' });
  const liste = h('div', { class: 'liste-rahmen lg-liste', id: 'lg-liste' });
  const unten = h('section', { class: 'lg-chargen-rahmen', id: 'lg-chargen', 'aria-label': 'Chargen und Stücke des gewählten Artikels' });
  // Zweiteilung mit Teiler (F137): oben die Artikel, unten die Chargen des gewählten Artikels
  b.append(seitenkopf(`Bestand – ${k.buchName || k.mandantName || k.mandant}`),
    // Zwei feste Zeilen mit fester Bedeutung (F106 Nr. 2): oben die Bewegungen, über der Liste Suche und Stammdaten
    h('div', { class: 'lg-leiste lg-fest nicht-drucken' }, kn.entnahme, kn.zugang, kn.umlagern, kn.ausbuchen, kn.sperre, kn.verlauf, h('span', { class: 'lg-luecke' }), kn.drucken),
    kacheln, h('div', { class: 'lg-leiste lg-fest nicht-drucken' }, suche, h('span', { class: 'lg-luecke' }), kn.neu, kn.gruppe, kn.aendern, kn.orte, kn.einstellung), teiler(liste, unten, { id: 'lg-teilung', hoehe: 200 }));

  const artikelVon = (id) => d.artikel.find((a) => a.id === id) || null;
  /** Die gewählte Zeile der Liste: ein Artikel oder eine Artikelgruppe. */
  const zeile = () => gruppen.find((g) => g.id === artikelId) || artikelVon(artikelId);
  /** Der konkrete Artikel: bei einer Gruppe der, dem die gewählte Charge gehört – bewegt wird nie die Gruppe. */
  const artikel = () => { const z = zeile(); if (!z || !z.istMeta) return z; const c = z.chargen.find((x) => x.id === chargeId); return c ? artikelVon(c.artikel) : null; };
  const charge = () => zeile()?.chargen.find((c) => c.id === chargeId) || null;
  const gruppeVon = (a) => (a?.meta_artikel ? gruppen.find((g) => g.id === a.meta_artikel) || null : null);
  /** Die Gruppe, in die „Artikel neu …“ führt (AG8): die markierte Gruppe oder die des markierten Artikels – nie eine stillgelegte. */
  const markierteGruppe = () => { const z = zeile(); const g = z?.istMeta ? z : gruppeVon(z); return g && !g.stillgelegt ? g : null; };
  /** Was einen Artikel von jeder Artikelgruppe ausschließt (LAGER.md MA2) – oder null. Seriennummern schließen seit 0.7.85 nicht mehr aus (F192). */
  const keineGruppe = (art, btm) => (art === 'arzneimittel' ? 'Arzneimittel gehören zu keiner Artikelgruppe: Ob eines ein anderes ersetzt, ist eine Entscheidung im Einzelfall'
    : btm ? 'Betäubungsmittel gehören zu keiner Artikelgruppe' : null);
  // Stückliste (STUECKLISTE.md Abschnitt 11, F197): eine Zeile nennt einen Artikel, eine Artikelgruppe oder ein Teil ohne Artikel
  const gruppeNr = (id) => gruppen.find((g) => g.id === id) || null;
  const teilName = (z) => (z.artikel ? artikelVon(z.artikel)?.bezeichnung || 'unbekannter Artikel' : z.meta ? gruppeNr(z.meta)?.bezeichnung || 'unbekannte Artikelgruppe' : z.bezeichnung);
  const teilArt = (z) => (z.artikel ? 'Artikel' : z.meta ? 'Artikelgruppe – einer davon' : 'ohne eigenen Artikel');
  const teilEinheit = (z) => (z.artikel ? artikelVon(z.artikel)?.einheit : z.meta ? gruppeNr(z.meta)?.einheit : '') || '';
  const teilArtikel = (z) => (z.artikel ? [z.artikel] : z.meta ? (gruppeNr(z.meta)?.mitglieder || []).map((m) => m.id) : []);
  /** Enthält der Artikel `von` – über seine Stückliste, auch über Umwege – den Artikel `ziel`? */
  const enthaelt = (von, ziel, tiefe = 0) => tiefe > 12 || (artikelVon(von)?.stueckliste || []).some((z) => teilArtikel(z).some((id) => id === ziel || enthaelt(id, ziel, tiefe + 1)));
  /** Der Baum der Stückliste eines Artikels: Teile, die selbst eine Stückliste haben, lassen sich auf- und zuklappen. */
  const baum = (id, weg = []) => h('ul', { class: 'lg-baum' }, (artikelVon(id)?.stueckliste || []).map((z) => {
    const t = z.artikel ? artikelVon(z.artikel) : null;
    const g = z.meta ? gruppeNr(z.meta) : null;
    const kopf = [h('span', { class: 'lg-baum-menge' }, `${mengeText(z.menge)} ×`), ' ', h('span', { class: 'lg-baum-name' }, teilName(z)),
      z.erforderlich ? null : h('span', { class: 'marke-klein', title: 'Der Artikel ist auch ohne dieses Teil vollständig' }, 'optional'),
      g ? h('span', { class: 'marke-klein korrigiert', title: g.mitglieder.length ? `Einer aus der Artikelgruppe: ${g.mitglieder.map((m) => m.bezeichnung).join(', ')}` : 'Dieser Artikelgruppe ist noch kein Artikel zugeordnet' }, 'Artikelgruppe – einer davon') : null,
      !t && !g ? h('span', { class: 'marke-klein', title: 'Dieses Teil wird nicht als eigener Artikel geführt' }, 'ohne eigenen Artikel') : null,
      t || g ? h('span', { class: 'hinweis' }, ` im Lager: ${mengeText((t || g).bestand)} ${(t || g).einheit}`) : null];
    const tiefer = t && t.stueckliste.length > 0 && weg.length < 12 && !weg.includes(t.id);
    return h('li', {}, tiefer ? h('details', { open: true }, h('summary', {}, kopf), baum(t.id, [...weg, id])) : h('div', { class: 'lg-baum-blatt' }, kopf));
  }));
  const gleicheEinheit = (x, y) => String(x || '').trim().toLowerCase() === String(y || '').trim().toLowerCase();
  const nachVerfall = (x, y) => (x.verfall || '9999').localeCompare(y.verfall || '9999');
  function gruppenBilden() {
    gruppen = (d.meta || []).map((m) => {
      const mitglieder = m.mitglieder.map(artikelVon).filter(Boolean);
      return { ...m, istMeta: true, nummer: null, gtin: null, btm: false, mitglieder, chargen: mitglieder.flatMap((a) => a.chargen).sort(nachVerfall) };
    });
    for (const id of [...offen]) if (!gruppen.some((g) => g.id === id)) offen.delete(id);
  }
  const dreieck = (a) => {
    const auf = offen.has(a.id);
    const kn1 = h('button', { type: 'button', class: 'lg-dreieck', 'aria-expanded': a.istMeta ? String(auf) : null, 'aria-label': a.istMeta ? (auf ? 'Artikel der Gruppe verbergen' : 'Artikel der Gruppe zeigen') : 'nichts aufzuklappen' }, a.istMeta && auf ? '▾' : '▸');
    sperren(kn1, a.istMeta ? (a.mitglieder.length ? null : 'Dieser Artikelgruppe ist noch kein Artikel zugeordnet') : (a.meta_artikel ? 'Artikel einer Artikelgruppe' : 'Keine Artikelgruppe – hier gibt es nichts aufzuklappen'));
    kn1.addEventListener('click', (ev) => { ev.stopPropagation(); if (auf) offen.delete(a.id); else offen.add(a.id); zeichnen(); });
    return kn1;
  };
  const ort = (id) => d.orte.find((o) => o.id === id)?.name || '';
  const orteFrei = () => d.orte.filter((o) => !o.stillgelegt);
  // Bestand von vor einer dazugenommenen Eigenschaft trägt sie nicht – er steht als „ohne Angabe“ da (EG5)
  const chargeName = (a, c) => (a?.fuehrung === 'ohne' ? 'ohne Charge' : c.ohne_serie ? '– ohne Seriennummer –' : c.bezeichnung === '' ? '– ohne Angabe –' : c.bezeichnung);
  const beides = (a) => Boolean(a && !a.istMeta && a.hat_charge && a.hat_serie);
  // Die Worte folgen der Führung (F204): Ein Gerät hat eine Seriennummer und ist ein Stück – keine Charge.
  // fuehrungVon: die Führung eines Artikels; bei einer Artikelgruppe die ihrer Artikel, 'gemischt', wenn sie sich unterscheiden.
  const fuehrungVon = (a) => { if (!a) return 'charge'; if (!a.istMeta) return a.fuehrung; const f = [...new Set(a.mitglieder.map((m) => m.fuehrung))]; return f.length === 1 ? f[0] : f.length === 0 ? 'charge' : 'gemischt'; };
  const WORTE = { serie: { spalte: 'Seriennummer', titel: 'Stücke', eine: 'ein Stück', die: 'Das Stück', der: 'des Stücks' }, charge: { spalte: 'Charge', titel: 'Chargen', eine: 'eine Charge', die: 'Die Charge', der: 'der Charge' },
    ohne: { spalte: 'Charge', titel: 'Bestand', eine: 'eine Zeile', die: 'Der Bestand', der: 'des Bestands' }, gemischt: { spalte: 'Charge / Seriennummer', titel: 'Chargen und Stücke', eine: 'eine Zeile', die: 'Die Zeile', der: 'der Zeile' } };
  const worte = (a) => WORTE[fuehrungVon(a)];
  /** „Seriennummer X“, „Charge X“ oder „ohne Charge“ – wie der Artikel geführt wird. */
  const nenn = (a, c) => (a?.fuehrung === 'ohne' ? 'ohne Charge' : c.ohne_serie ? `Stück ohne Seriennummer${c.los ? `, Charge ${c.los}` : ''}` : c.bezeichnung === '' ? 'Bestand ohne Angabe'
    : a?.fuehrung === 'serie' ? `Seriennummer ${c.bezeichnung}${c.los ? `, Charge ${c.los}` : ''}` : `Charge ${c.bezeichnung}`);
  const marke = (zustand, text) => h('span', { class: ['marke-klein', ZUSTAND[zustand][1]] }, text || ZUSTAND[zustand][0]);
  const chargeZustand = (c) => (c.zustand === 'abgelaufen' ? 'abgelaufen – nur ausbuchen' : c.zustand === 'gesperrt' ? `gesperrt – ${c.sperrgrund}` : c.zustand === 'laeuft_ab' ? `läuft ab in ${c.tage} Tagen` : 'frei');
  const chargeOrte = (c) => c.orte.map((o) => `${ort(o.ort)}${c.orte.length > 1 ? ` (${mengeText(o.menge)})` : ''}`).join(', ');

  // Spalten mit Sortierung und Filter am Kopf (F91): wert = Text für Filter und Sortierung, zelle = Anzeige
  const spalten = [
    { id: 'auf', titel: '', fest: true, klasse: 'lg-auf', zelle: dreieck },
    // Der Textfilter am Spaltenkopf findet eine Artikelgruppe auch über ihre Artikel (suchtext)
    { id: 'artikel', titel: 'Artikel', wert: (a) => a.bezeichnung, suchtext: (a) => (a.istMeta ? [a.bezeichnung, ...a.mitglieder.map((m) => m.bezeichnung)].join(' · ') : a.bezeichnung), zelle: (a) => [!a.istMeta && a.meta_artikel ? h('span', { class: 'lg-ein', 'aria-hidden': 'true' }, '↳ ') : null, a.bezeichnung,
      a.istMeta ? h('span', { class: 'marke-klein korrigiert', title: a.aufgabe ? `Aufgabe: ${a.aufgabe}` : 'Artikel gleicher Aufgabe, die einander ersetzen' }, a.mitglieder.length === 1 ? 'Gruppe · 1 Artikel' : `Gruppe · ${a.mitglieder.length} Artikel`) : null,
      a.istMeta && !a.aufgabe ? h('span', { class: 'marke-klein warnung', title: 'Trage unter „Bearbeiten …“ nach, wofür die Artikel dieser Gruppe dienen' }, 'Aufgabe fehlt') : null,
      !a.istMeta && a.stueckliste?.length ? h('span', { class: 'marke-klein korrigiert lg-marke-sl', title: 'Dieser Artikel besteht aus mehreren Teilen – die Stückliste steht unten' }, a.stueckliste.length === 1 ? 'Stückliste · 1 Teil' : `Stückliste · ${a.stueckliste.length} Teile`) : null,
      a.btm ? h('span', { class: 'marke-klein warnung', title: 'Betäubungsmittel werden nicht im Lager geführt' }, 'BtM – nicht geführt') : null,
      a.stillgelegt ? h('span', { class: 'marke-klein' }, 'stillgelegt') : null] },
    { id: 'nummer', titel: 'Nummer', klasse: 'eng', wert: (a) => a.nummer || '', suchtext: (a) => (a.istMeta ? a.mitglieder.map((m) => m.nummer || '').join(' · ') : a.nummer || '') },
    { id: 'art', titel: 'Art', klasse: 'eng', wert: (a) => (a.istMeta ? 'Artikelgruppe' : ARTEN.find(([w]) => w === a.art)?.[1] || a.art) },
    { id: 'bestand', titel: 'Bestand', klasse: 'zahl', wert: (a) => `${mengeText(a.bestand)} ${a.einheit}`, sortwert: (a) => Number(a.bestand) },
    { id: 'mindest', titel: 'Mindest', klasse: 'zahl', wert: (a) => (a.mindest === null ? '' : mengeText(a.mindest)), sortwert: (a) => (a.mindest === null ? -1 : Number(a.mindest)) },
    { id: 'verfall', titel: 'Nächster Verfall', klasse: 'eng', wert: (a) => (a.naechster_verfall ? datumText(a.naechster_verfall) : ''), sortwert: (a) => a.naechster_verfall || '' },
    { id: 'zustand', titel: 'Zustand', klasse: 'eng', wert: (a) => ZUSTAND[a.zustand][0] + (a.unter_mindest && a.zustand !== 'unter_mindest' ? `, ${ZUSTAND.unter_mindest[0]}` : ''),
      zelle: (a) => [marke(a.zustand), a.unter_mindest && a.zustand !== 'unter_mindest' ? marke('unter_mindest') : null] },
  ];
  const chargenSpalten = [
    { id: 'charge', titel: 'Charge', zellklasse: 'lg-nr', wert: (c) => chargeName(artikelVon(c.artikel), c) },
    { id: 'artikel', titel: 'Artikel', wert: (c) => artikelVon(c.artikel)?.bezeichnung || '' },
    { id: 'verfall', titel: 'Verfall', wert: (c) => (c.verfall ? datumText(c.verfall) : '–'), sortwert: (c) => c.verfall || '' },
    { id: 'ort', titel: 'Lagerort', wert: chargeOrte },
    { id: 'menge', titel: 'Menge', klasse: 'zahl', wert: (c) => mengeText(c.menge), sortwert: (c) => Number(c.menge) },
    { id: 'zustand', titel: 'Zustand', wert: chargeZustand, zelle: (c) => [marke(c.zustand, chargeZustand(c)),
      c.vorschlag ? h('span', { class: 'marke-klein korrigiert', title: 'Frühester Verfall unter den freien Chargen' }, 'wird vorgeschlagen') : null,
      c.teile?.length || c.freiteile?.length ? h('span', { class: 'marke-klein korrigiert lg-marke-teile', title: 'Zu diesem Stück gehören Teile – sie stehen unten' }, (c.teile?.length || 0) + (c.freiteile?.length || 0) === 1 ? '1 Teil' : `${(c.teile?.length || 0) + (c.freiteile?.length || 0)} Teile`) : null,
      c.verbaut_in?.length ? h('span', { class: 'marke-klein warnung lg-marke-verbaut', title: c.verbaut_in.map((v) => `verbaut in „${artikelVon(v.artikel)?.bezeichnung || ''}“, Seriennummer ${v.bezeichnung}`).join(' · ') }, 'verbaut') : null] },
  ];
  const zellen = (sp, z) => sp.map((s) => h('td', { class: s.zellklasse || s.klasse || '' }, s.zelle ? s.zelle(z) : s.wert(z)));
  const spaltenfilter = tabellenfilter(spalten, () => zeichnen(), { id: 'lager.bestand' });
  // Die Filter der Chargen gelten für den gewählten Artikel: Mit einem anderen Artikel beginnen sie neu, damit kein
  // Filter von vorhin dessen Bestand verdeckt.
  // Hat der Artikel Charge und Seriennummer, steht die Charge des Stücks als eigene Spalte daneben (EG2, EG4)
  const chargenSpaltenFuer = (a) => chargenSpalten.flatMap((sp) => (sp.id !== 'charge' ? [sp] : [{ ...sp, titel: worte(a).spalte },
    ...(beides(a) ? [{ id: 'los', titel: 'Charge', zellklasse: 'lg-nr', wert: (c) => c.los || '– ohne Angabe –' }] : [])]));
  let chargenfilter = tabellenfilter(chargenSpaltenFuer(null), () => zeichnen(), { id: 'lager.chargen' });
  let chargenVon = null;

  function sichtbar() {
    const s = suche.value.trim().toLowerCase();
    const code = codeLesen(suche.value);
    // Oben stehen Artikelgruppen und Artikel ohne Gruppe; die Artikel einer Gruppe stehen hinter ihrem Dreieck
    const oben = [...gruppen, ...d.artikel.filter((a) => !gruppeVon(a))].sort((x, y) => Number(x.stillgelegt) - Number(y.stillgelegt) || x.bezeichnung.localeCompare(y.bezeichnung, 'de'));
    const teile = (a) => (a.istMeta ? [a, ...a.mitglieder] : [a]);
    return oben.filter((a) => {
      if (filter === 'unter_mindest' ? !a.unter_mindest : filter !== 'alle' && !a.chargen.some((c) => c.zustand === filter)) return false;
      if (code?.gtin) return teile(a).some((x) => x.gtin && (x.gtin === code.gtin || x.gtin === code.gtin.replace(/^0+/, '')));
      if (s === '') return !a.stillgelegt || Number(a.bestand) > 0;
      return teile(a).some((x) => [x.bezeichnung, x.nummer, x.gtin].some((t) => String(t || '').toLowerCase().includes(s))) || a.chargen.some((c) => String(c.bezeichnung || '').toLowerCase().includes(s));
    });
  }

  function zeichnen() {
    const z = d.zahlen;
    const k1 = (id, wert, text, art) => h('button', { type: 'button', class: ['kachel', wert > 0 ? art : ''], id: `lg-k-${id}`, 'aria-pressed': filter === id ? 'true' : 'false',
      // Die Hilfe beim Überfahren sagt, was der nächste Klick tut: einschränken – oder, wenn der Filter gilt, ihn löschen
      title: filter === id ? 'Dieser Filter gilt gerade – ein weiterer Klick löscht ihn und zeigt wieder alle Artikel' : 'Klick: die Liste auf diese Artikel einschränken – ein weiterer Klick löscht den Filter wieder', onclick: () => { filter = filter === id ? 'alle' : id; zeichnen(); } },
      h('strong', {}, String(wert)), h('span', {}, text));
    kacheln.replaceChildren(
      k1('abgelaufen', z.abgelaufen || 0, 'Chargen abgelaufen, noch im Bestand', 'fehler'),
      k1('laeuft_ab', z.laeuft_ab || 0, `Chargen laufen in ${d.einstellung.warn_tage} Tagen ab`, 'warnung'),
      k1('gesperrt', z.gesperrt || 0, 'Chargen gesperrt', 'fehler'),
      k1('unter_mindest', z.unter_mindest || 0, 'Artikel unter Mindestbestand', 'warnung'));
    const gesucht = sichtbar();
    spaltenfilter.setzeZeilen(gesucht);
    const oben = spaltenfilter.anwenden(gesucht);
    // aufgeklappt folgen einer Gruppe ihre Artikel – sie bleiben beim Sortieren und Filtern bei ihrer Zeile
    const zeilen = oben.flatMap((a) => (a.istMeta && offen.has(a.id) ? [a, ...a.mitglieder] : [a]));
    if (!zeilen.some((a) => a.id === artikelId)) { artikelId = zeilen[0]?.id || null; chargeId = null; }
    const a0 = zeile();
    if ((a0?.id || null) !== chargenVon) {
      chargenVon = a0?.id || null;
      chargenfilter.entfernen();
      chargenfilter = tabellenfilter(chargenSpaltenFuer(a0), () => zeichnen(), { id: 'lager.chargen' });
    }
    chargenfilter.setzeZeilen(a0 ? a0.chargen : []);
    // Gewählt ist immer eine Charge, die zu sehen ist – die Knöpfe wirken nie auf eine weggefilterte
    const chargen = a0 ? chargenfilter.anwenden(a0.chargen) : [];
    if (a0 && !chargen.some((c) => c.id === chargeId)) {
      const code = codeLesen(suche.value);
      chargeId = (chargen.find((c) => code?.charge && c.bezeichnung === (code.serie || code.charge)) || chargen.find((c) => c.vorschlag) || chargen[0])?.id || null;
    }
    // leer ist die Liste erst ohne Artikel und ohne Artikelgruppe – eine Gruppe ohne Artikel steht da und nimmt den ersten auf
    if (d.artikel.length === 0 && gruppen.length === 0) {
      liste.replaceChildren(h('p', { class: 'hinweis', id: 'lg-leer' }, d.orte.length === 0
        ? 'Noch kein Lager. Lege zuerst unter „Lagerorte …“ einen Lagerort an, dann mit „Artikel neu …“ den ersten Artikel.'
        : 'Noch kein Artikel. Mit „Artikel neu …“ legst du den ersten an.'));
    } else {
      const tbody = h('tbody', {}, zeilen.length ? zeilen.map((a) => {
        const tr = h('tr', { tabindex: '0', class: [a.id === artikelId ? 'gewaehlt' : '', a.stillgelegt ? 'lg-still' : '', a.istMeta ? 'lg-gruppe' : '', a.meta_artikel ? 'lg-mitglied' : ''], 'aria-selected': a.id === artikelId ? 'true' : 'false', 'data-id': a.id }, zellen(spaltenfilter.spalten(), a));
        const waehlen = () => { artikelId = a.id; chargeId = null; zeichnen(); };
        tr.addEventListener('click', waehlen);
        tr.addEventListener('keydown', (ev) => {
          if (ev.target !== tr) return;
          if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); waehlen(); }
          // Tastatur am Dreieck (LAGER.md AD16): → öffnet, ← schließt
          if (a.istMeta && a.mitglieder.length && ((ev.key === 'ArrowRight' && !offen.has(a.id)) || (ev.key === 'ArrowLeft' && offen.has(a.id)))) {
            ev.preventDefault(); if (offen.has(a.id)) offen.delete(a.id); else offen.add(a.id); artikelId = a.id; zeichnen();
            liste.querySelector(`tr[data-id="${a.id}"]`)?.focus();
          }
        });
        return tr;
      }) : h('tr', { class: 'leer' }, h('td', { colspan: spalten.length, id: 'lg-kein-treffer' }, 'Kein Artikel passt zu Suche, Kachel und Filter.')));
      liste.replaceChildren(h('div', { class: 'liste-scroll' }, h('table', { class: 'liste lg-tabelle', id: 'lg-tabelle' }, h('thead', {}, spaltenfilter.kopf), tbody)),
        h('div', { class: 'liste-fuss' }, h('span', { id: 'lg-anzahl' }, oben.length === 1 ? '1 Artikel' : `${oben.length} Artikel`, gruppen.length ? ' (Artikelgruppen zählen als einer)' : ''), spaltenfilter.aktiv() ? ' · gefiltert' : '', ` · Stand ${datumText(d.heute)}`,
          k.buchInfo?.testbuch ? h('span', { class: 'marke-klein warnung' }, 'Testbuch') : null));
    }
    const a = zeile();
    if (!a) {
      unten.replaceChildren(h('p', { class: 'hinweis' }, 'Kein Artikel gewählt.'));
    } else {
      unten.replaceChildren(...[
        h('div', { class: 'lg-chargen-kopf' }, h('h2', { id: 'lg-chargen-titel' }, a.istMeta ? `${worte(a).titel} aller Artikel: ${a.bezeichnung}` : `${worte(a).titel}: ${a.bezeichnung}`),
          h('span', { class: 'hinweis lg-aufgabe', id: 'lg-chargen-hinweis' }, a.istMeta ? `Artikelgruppe · ${a.mitglieder.length === 1 ? '1 Artikel' : `${a.mitglieder.length} Artikel`} · Lagereinheit ${a.einheit} · vorgeschlagen wird der früheste Verfall über alle · ${a.aufgabe ? `Aufgabe: ${a.aufgabe}` : 'Aufgabe fehlt'}`
            : `${FUEHRUNG.find(([w]) => w === a.fuehrung)[1]}${a.verfall_pflicht ? ', Verfall Pflicht' : ''} · Lagereinheit ${a.einheit}${a.lagerbedingung ? ` · ${a.lagerbedingung}` : ''}${gruppeVon(a) ? ` · gehört zur Artikelgruppe „${gruppeVon(a).bezeichnung}“` : ''}`)),
        a.chargen.length === 0 ? h('p', { class: 'hinweis' }, a.btm ? 'Betäubungsmittel werden nicht im Lager geführt – der Nachweis gehört ins Betäubungsmittelbuch.'
          : a.istMeta && a.mitglieder.length === 0 ? 'Noch kein Artikel in dieser Gruppe. „Artikel neu …“ legt einen darin an; vorhandene Artikel ordnest du unter „Bearbeiten …“ zu.' : 'Kein Bestand.')
          : h('table', { class: 'lg-chargen', id: 'lg-chargen-tabelle' },
            h('thead', {}, chargenfilter.kopf),
            h('tbody', {}, chargen.length ? chargen.map((c) => {
              const tr = h('tr', { tabindex: '0', class: c.id === chargeId ? 'gewaehlt' : '', 'aria-selected': c.id === chargeId ? 'true' : 'false', 'data-id': c.id }, zellen(chargenfilter.spalten(), c));
              const waehlen = () => { chargeId = c.id; zeichnen(); };
              tr.addEventListener('click', waehlen);
              tr.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); waehlen(); } });
              return tr;
            }) : h('tr', { class: 'leer' }, h('td', { colspan: chargenSpalten.length, id: 'lg-keine-charge' }, 'Keine Zeile passt zum Filter.')))),
        teileUnten(chargen.find((x) => x.id === chargeId) || null),
        !a.istMeta && a.stueckliste?.length ? h('div', { class: 'lg-abschnitt', id: 'lg-stueckliste' },
          h('div', { class: 'lg-abschnitt-kopf' }, h('h3', {}, `Stückliste: ${a.bezeichnung}`), h('span', { class: 'hinweis' }, 'Woraus ein Stück besteht – gepflegt unter „Bearbeiten …“. Der Bestand der Teile ist ihr eigener.')),
          baum(a.id)) : null].filter(Boolean));
    }
    knoepfe();
  }

  /** Unten, zur gewählten Charge: was in diesem Stück steckt und worin es steckt – mit „Lösen“ und „Teil einbauen …“ (F201). */
  function teileUnten(c) {
    const ca = c ? artikelVon(c.artikel) : null;
    if (!c || !ca || (ca.fuehrung !== 'serie' && !c.verbaut_in?.length)) return null;
    const frei = c.freiteile || [];
    if (!c.teile.length && !frei.length && !c.verbaut_in.length && !ca.stueckliste?.length) return null;
    const loesen = (t, text) => {
      const kn1 = h('button', { type: 'button', class: 'lg-t-loesen', onclick: async () => { try { await k.api('POST', `${basis}/teile/${t.id}/loesen`); await laden(); k.melden(text, 'erfolg'); } catch (x) { k.fehler(x); } } }, 'Lösen');
      sperren(kn1, d.darf.buchen ? null : 'Dir fehlt das Recht, im Lager zu buchen');
      kn1.dataset.hilfe = 'Das Teil bleibt im Bestand, wo es liegt, und gehört danach zu keinem Stück';
      if (!kn1.disabled) kn1.title = kn1.dataset.hilfe;
      return kn1;
    };
    // unvollständig (ZS6): eine erforderliche Zeile der Stückliste, zu der in diesem Stück kein Teil steckt – Hinweis, keine Sperre
    const gleich = (x, y) => String(x || '').trim().toLowerCase() === String(y || '').trim().toLowerCase();
    const fehlt = ca.fuehrung === 'serie' ? (ca.stueckliste || []).filter((z) => z.erforderlich
      && (z.artikel || z.meta ? !c.teile.some((t) => teilArtikel(z).includes(t.artikel)) : !frei.some((t) => gleich(t.bezeichnung, z.bezeichnung)))).map(teilName) : [];
    const loesenFrei = (t) => {
      const kn1 = h('button', { type: 'button', class: 'lg-t-loesen', onclick: async () => { try { await k.api('POST', `${basis}/freiteile/${t.id}/loesen`); await laden(); k.melden(`Gelöst: ${t.bezeichnung}.`, 'erfolg'); } catch (x) { k.fehler(x); } } }, 'Lösen');
      sperren(kn1, d.darf.buchen ? null : 'Dir fehlt das Recht, im Lager zu buchen');
      kn1.dataset.hilfe = 'Das Teil steht danach nicht mehr an diesem Stück; es hat keinen eigenen Bestand';
      if (!kn1.disabled) kn1.title = kn1.dataset.hilfe;
      return kn1;
    };
    const eintragen = h('button', { type: 'button', class: 'knopf klein', id: 'lg-t-frei', onclick: () => freiteilDialog(c) }, 'Teil ohne Artikel eintragen …');
    sperren(eintragen, !d.darf.buchen ? 'Dir fehlt das Recht, im Lager zu buchen' : ca.fuehrung !== 'serie' || c.ohne_serie ? 'Teile gehören zu einem Stück mit Seriennummer' : null);
    const einbauen = h('button', { type: 'button', class: 'knopf klein', id: 'lg-t-einbauen', onclick: () => einbauenDialog(c) }, 'Teil einbauen …');
    sperren(einbauen, !d.darf.buchen ? 'Dir fehlt das Recht, im Lager zu buchen' : ca.fuehrung !== 'serie' ? 'Teile gehören zu einem Stück mit Seriennummer' : einbaubar(c).length === 0 && zubuchbar(c).length === 0 ? 'Die Stückliste nennt keinen Artikel, der zugehen kann, und am Lagerort dieses Stücks liegt kein freies Teil' : null);
    return h('div', { class: 'lg-abschnitt', id: 'lg-teile' },
      h('div', { class: 'lg-abschnitt-kopf' }, h('h3', {}, `Teile dieses Stücks: ${chargeName(ca, c)}`),
        fehlt.length ? h('span', { class: 'marke-klein warnung', id: 'lg-teile-fehlt', title: 'Laut Stückliste erforderlich – ein Hinweis, keine Sperre' }, `unvollständig: ${fehlt.join(', ')} fehlt`) : null,
        ca.fuehrung === 'serie' ? h('span', { class: 'lg-teile-knoepfe' }, eintragen, einbauen) : null),
      c.verbaut_in.length ? h('p', { id: 'lg-teile-verbaut' }, c.verbaut_in.map((v) => [`Verbaut in „${artikelVon(v.artikel)?.bezeichnung || ''}“, Seriennummer ${v.bezeichnung} (${mengeText(v.menge)} ${ca.einheit}) `, loesen(v, 'Gelöst.'), ' '])) : null,
      c.teile.length || frei.length ? h('table', { class: 'lg-chargen', id: 'lg-teile-tabelle' },
        h('thead', {}, h('tr', {}, h('th', {}, 'Teil'), h('th', {}, 'Seriennummer / Charge'), h('th', { class: 'zahl' }, 'Menge'), h('th', {}, ''))),
        h('tbody', {}, c.teile.map((t) => { const ta = artikelVon(t.artikel); return h('tr', { class: 'lg-nur-lesen' }, h('td', {}, ta?.bezeichnung || ''), h('td', { class: 'lg-nr' }, t.bezeichnung || '–'),
          h('td', { class: 'zahl' }, `${mengeText(t.menge)} ${ta?.einheit || ''}`), h('td', {}, loesen(t, `Gelöst: ${ta?.bezeichnung || 'Teil'}.`))); }),
        frei.map((t) => h('tr', { class: 'lg-nur-lesen lg-freiteil' }, h('td', {}, t.bezeichnung, h('span', { class: 'marke-klein', title: 'Wird nicht als eigener Artikel geführt: kein eigener Bestand' }, 'ohne eigenen Artikel')),
          h('td', { class: 'lg-nr' }, t.seriennummer || '–'), h('td', { class: 'zahl' }, mengeText(t.menge)), h('td', {}, loesenFrei(t))))))
        : ca.fuehrung === 'serie' ? h('p', { class: 'hinweis', id: 'lg-teile-leer' }, 'Zu diesem Stück ist noch kein Teil erfasst.') : null);
  }
  /** Ein Teil ohne eigenen Artikel an einem Stück eintragen – vorgeschlagen wird, was laut Stückliste noch fehlt. */
  function freiteilDialog(c) {
    const ca = artikelVon(c.artikel);
    const offen1 = (ca.stueckliste || []).filter((z) => !z.artikel && !z.meta && !(c.freiteile || []).some((t) => t.bezeichnung.trim().toLowerCase() === z.bezeichnung.trim().toLowerCase()));
    const bez = h('input', { id: 'lg-ft-bezeichnung', maxlength: '200', value: offen1[0]?.bezeichnung || '', placeholder: 'z. B. Ladeschale' });
    const sn = h('input', { id: 'lg-ft-serie', maxlength: '50', autocomplete: 'off' });
    const m = mengeFeld('lg-ft-menge', '1');
    const regeln = () => sperren(m, sn.value.trim() !== '' ? 'Ein Teil mit Seriennummer ist ein einzelnes Teil – Menge 1' : null);
    sn.addEventListener('input', () => { if (sn.value.trim() !== '') m.value = '1'; regeln(); });
    dialog({ id: 'lg-dialog-freiteil', titel: `Teil ohne Artikel: ${ca.bezeichnung}, ${chargeName(ca, c)}`, ja: 'Eintragen',
      text: 'Für Teile, die zum Stück gehören und nie einzeln geführt werden. Sie haben keinen eigenen Bestand; ihre Nummer steht am Stück.',
      felder: [h('div', { class: 'lg-reihe' }, feld('Bezeichnung', bez), feld('Seriennummer', sn, 'falls vorhanden'), feld('Menge', m)), tastaturWache([sn], 'lg-ft-tastatur')],
      senden: async () => {
        if (bez.value.trim() === '') throw new Error('Die Bezeichnung des Teils fehlt');
        await k.api('POST', `${basis}/chargen/${c.id}/freiteile`, { json: { bezeichnung: bez.value.trim(), seriennummer: sn.value.trim() || null, menge: menge(m) } });
        await laden(); k.melden('Eingetragen.', 'erfolg');
      } });
    regeln();
    (bez.value ? sn : bez).focus();
  }

  /** Was sich in ein Stück einbauen lässt: freie Chargen anderer Artikel am selben Lagerort. */
  function einbaubar(c) {
    const o = c.orte[0]?.ort;
    return d.artikel.flatMap((x) => x.chargen.map((t) => [x, t])).filter(([x, t]) => t.id !== c.id && !x.btm && t.orte.some((p) => p.ort === o)
      && !c.teile.some((y) => y.charge === t.id) && !(x.fuehrung === 'serie' && t.verbaut_in.length) && !t.teile.some((y) => y.charge === c.id));
  }
  /** Artikel, die laut Stückliste in dieses Stück gehören und sich neu zubuchen lassen – was fehlt, zuerst. */
  function zubuchbar(c) {
    const ca = artikelVon(c.artikel);
    const erg = [];
    for (const z of ca.stueckliste || []) {
      const fehlt1 = !c.teile.some((t) => teilArtikel(z).includes(t.artikel));
      for (const x of teilArtikel(z).map(artikelVon)) if (x && !x.stillgelegt && !x.btm && !beides(x) && x.id !== ca.id && !erg.some(([y]) => y.id === x.id)) erg.push([x, fehlt1]);
    }
    return erg.sort((p, q) => Number(q[1]) - Number(p[1]));
  }
  /**
   * Ein Teil in ein Stück einbauen: eines, das schon frei am selben Lagerort liegt – oder eines, das jetzt erst zugeht
   * (F215). Dann bucht der Dialog den Zugang des Teils am Lagerort des Stücks und baut es ein, in einem Vorgang.
   */
  function einbauenDialog(c) {
    const ca = artikelVon(c.artikel);
    const liste = einbaubar(c);
    const neu = zubuchbar(c);
    const was = h('select', { id: 'lg-t-teil' },
      neu.length ? h('optgroup', { label: 'Neu zubuchen und einbauen' }, neu.map(([x, f1]) => h('option', { value: `neu:${x.id}` }, `${x.bezeichnung}${f1 ? ' – fehlt laut Stückliste' : ''}`))) : null,
      liste.length ? h('optgroup', { label: `Liegt schon frei im Lager (${ort(c.orte[0].ort)})` }, liste.map(([x, t]) => h('option', { value: t.id }, `${x.bezeichnung} · ${chargeName(x, t)} (${mengeText(t.orte.find((p) => p.ort === c.orte[0].ort).menge)} ${x.einheit})`))) : null);
    was.value = liste.length && !neu.some(([, f1]) => f1) ? liste[0][1].id : neu.length ? `neu:${neu[0][0].id}` : liste[0][1].id;
    const ch = h('input', { id: 'lg-t-charge', maxlength: '50', autocomplete: 'off' });
    const vf = datumFeld({ id: 'lg-t-verfall' });
    const grund = auswahl(GRUENDE_ZU, 'anfangsbestand', { id: 'lg-t-grund' });
    const m = mengeFeld('lg-t-menge', '1');
    const nummer = h('span', {}, 'Seriennummer');
    const gewaehlt = () => (was.value.startsWith('neu:') ? artikelVon(was.value.slice(4)) : liste.find(([, t]) => t.id === was.value)[0]);
    const regeln = () => {
      const x = gewaehlt(); const neu1 = was.value.startsWith('neu:');
      const vorhanden = 'Das gewählte Teil liegt schon im Lager – seine Angaben stehen fest';
      nummer.textContent = x.fuehrung === 'serie' ? 'Seriennummer' : x.fuehrung === 'charge' ? 'Charge' : 'Seriennummer / Charge';
      sperren(ch, !neu1 ? vorhanden : x.fuehrung === 'ohne' ? 'Das Teil wird ohne Charge geführt' : null);
      vf.sperren(!neu1 || x.fuehrung === 'ohne');
      sperren(grund, neu1 ? null : vorhanden);
      m.readOnly = x.fuehrung === 'serie'; if (m.readOnly) m.value = '1'; m.title = m.readOnly ? 'Mit Seriennummer ist die Menge je Stück 1' : '';
    };
    was.addEventListener('change', regeln);
    dialog({ id: 'lg-dialog-einbauen', breit: true, titel: `Teil einbauen: ${ca.bezeichnung}, ${chargeName(ca, c)}`, ja: 'Einbauen',
      text: `Ein Teil, das jetzt erst zugeht, wird am Lagerort des Stücks (${ort(c.orte[0].ort)}) zugebucht und gleich eingebaut. Ein Teil, das schon frei dort liegt, wird nur eingebaut. In beiden Fällen behält es seinen eigenen Bestand und ist danach „verbaut“.`,
      felder: [h('div', { class: 'lg-reihe' }, feld('Teil', was), feld(nummer, ch), feld('Verfall', vf.el)), h('div', { class: 'lg-reihe' }, feld('Menge', m), feld('Grund des Zugangs', grund)), tastaturWache([ch], 'lg-t-tastatur')],
      senden: async () => {
        if (!was.value.startsWith('neu:')) {
          await k.api('POST', `${basis}/chargen/${c.id}/teile`, { json: { teil: was.value, menge: menge(m) } });
          await laden(); k.melden('Eingebaut.', 'erfolg');
          return;
        }
        const x = gewaehlt();
        if (x.fuehrung !== 'ohne' && ch.value.trim() === '') throw new Error(`${x.fuehrung === 'serie' ? 'Seriennummer' : 'Charge'} des Teils fehlt`);
        if (vf.wert() === null) throw new Error('Verfall: kein gültiges Datum');
        if (x.verfall_pflicht && !vf.wert()) throw new Error('Verfallsdatum des Teils fehlt');
        await k.api('POST', `${basis}/chargen/${c.id}/teile`, { json: { artikel: x.id, charge: x.fuehrung === 'ohne' ? '' : ch.value.trim(), verfall: x.fuehrung === 'ohne' ? null : vf.wert() || null, menge: menge(m), grund: grund.value } });
        await laden(); k.melden(`Zugebucht und eingebaut: ${x.bezeichnung}.`, 'erfolg');
      } });
    regeln();
    (ch.disabled ? was : ch).focus();
  }

  function knoepfe() {
    const a = artikel();
    const c = charge();
    const darf = d.darf;
    // Ein verbautes Teil mit Seriennummer geht nicht allein (F201): erst lösen oder das Stück als Ganzes bewegen
    const steckt = c && a?.fuehrung === 'serie' && c.verbaut_in?.length ? c.verbaut_in[0] : null;
    const w = worte(zeile());
    const keine = !c ? `Erst unten ${w.eine} mit Bestand wählen` : null;
    const fest = keine || (steckt ? `Dieses Teil ist verbaut in „${artikelVon(steckt.artikel)?.bezeichnung || ''}“, Seriennummer ${steckt.bezeichnung} – erst unten „Lösen“ oder das Stück als Ganzes bewegen` : null);
    sperren(kn.entnahme, !darf.buchen ? 'Dir fehlt das Recht, im Lager zu buchen' : fest
      || (c.zustand === 'abgelaufen' ? `${worte(a).die} ist seit dem ${datumText(c.verfall)} abgelaufen – ${a.fuehrung === 'serie' ? 'es' : a.fuehrung === 'ohne' ? 'er' : 'sie'} lässt sich nur ausbuchen` : c.zustand === 'gesperrt' ? `${worte(a).die} ist gesperrt: ${c.sperrgrund}` : null));
    // An einer markierten Gruppe fragt der Dialog, welcher ihrer Artikel zugeht (F197) – die Gruppe selbst hat keinen Bestand
    const gz = zeile()?.istMeta ? zeile() : null;
    sperren(kn.zugang, !darf.buchen ? 'Dir fehlt das Recht, im Lager zu buchen'
      : gz ? (zugangArtikel(gz).length === 0 ? 'Dieser Artikelgruppe ist noch kein Artikel zugeordnet, der zugehen kann – „Artikel neu …“ legt einen darin an' : orteFrei().length === 0 ? 'Erst einen Lagerort anlegen' : null)
        : !a ? 'Erst einen Artikel wählen' : a.btm ? 'Betäubungsmittel werden nicht im Lager geführt' : a.stillgelegt ? 'Der Artikel ist stillgelegt' : orteFrei().length === 0 ? 'Erst einen Lagerort anlegen' : null);
    sperren(kn.umlagern, !darf.buchen ? 'Dir fehlt das Recht, im Lager zu buchen' : fest || (orteFrei().length < 2 ? 'Dafür braucht es einen zweiten Lagerort' : null));
    sperren(kn.ausbuchen, !darf.buchen ? 'Dir fehlt das Recht, im Lager zu buchen' : fest);
    kn.sperre.textContent = c?.zustand === 'gesperrt' || (c && c.sperrgrund) ? 'Freigeben …' : 'Sperren …';
    sperren(kn.sperre, !darf.sperren ? 'Dir fehlt das Recht, Chargen zu sperren' : keine || (a.fuehrung === 'ohne' ? 'Ohne Chargenführung gibt es nichts zu sperren' : null));
    sperren(kn.verlauf, keine);
    // „Artikel neu …“ führt in die markierte Gruppe (AG8) – die Beschriftung bleibt, die Hilfe nennt das Ziel
    const ziel = markierteGruppe();
    kn.neu.dataset.hilfe = ziel ? `Artikel anlegen – in der markierten Artikelgruppe „${ziel.bezeichnung}“` : 'Artikel anlegen';
    sperren(kn.neu, darf.pflegen ? null : 'Dir fehlt das Recht, Artikel zu pflegen');
    sperren(kn.aendern, !darf.pflegen ? 'Dir fehlt das Recht, Artikel zu pflegen' : !zeile() ? 'Erst einen Artikel oder eine Artikelgruppe wählen' : null);
    sperren(kn.gruppe, darf.pflegen ? null : 'Dir fehlt das Recht, Artikel zu pflegen');
    sperren(kn.orte, darf.pflegen ? null : 'Dir fehlt das Recht, Lagerorte zu pflegen');
    sperren(kn.einstellung, darf.einstellung ? null : 'Dir fehlt das Recht, die Einstellungen zu ändern');
  }

  async function laden() {
    try { d = await k.api('GET', basis); } catch (x) { k.fehler(x); }
    gruppenBilden();
    zeichnen();
  }

  const mengeFeld = (id, wert) => h('input', { id, class: 'lg-kurz', inputmode: 'decimal', autocomplete: 'off', value: wert ?? '' });
  function menge(el) {
    const m = mengeLesen(el.value);
    if (m === null) throw new Error('Menge: eine Zahl größer als null mit höchstens drei Nachkommastellen');
    return m;
  }
  const orteDerCharge = (c) => c.orte.map((o) => [o.ort, `${ort(o.ort)} (${mengeText(o.menge)})`]);

  function entnahmeDialog() {
    const a = artikel(); const c = charge();
    // vorgeschlagen wird über alle Artikel der Gruppe hinweg (MA8)
    const vor = (gruppeVon(a)?.chargen || a.chargen).find((x) => x.vorschlag);
    const wo = auswahl(orteDerCharge(c), c.orte[0].ort, { id: 'lg-e-ort' });
    const m = mengeFeld('lg-e-menge', '1');
    const zweck = auswahl([['', '– ohne –'], ...d.einstellung.zwecke.map((z) => [z, z])], d.einstellung.zwecke[0] || '', { id: 'lg-e-zweck' });
    const rest = h('p', { class: 'lg-hinweis-fest', id: 'lg-e-rest' });
    const rechnen = () => {
      const da = Number(c.orte.find((o) => o.ort === wo.value)?.menge || 0);
      const x = mengeLesen(m.value);
      rest.replaceChildren(x === null ? 'Menge eingeben.' : Number(x) > da ? h('span', { class: 'lg-warn' }, `An diesem Lagerort sind nur ${mengeText(da)} ${a.einheit} verfügbar.`) : `Danach an diesem Lagerort: ${mengeText(da - Number(x))} ${a.einheit}.`,
        vor && vor.id !== c.id && vor.verfall !== c.verfall ? h('span', { class: 'lg-warn', id: 'lg-e-abweichung' }, ` Du weichst vom Vorschlag ab: ${chargeName(artikelVon(vor.artikel), vor)}${vor.artikel !== a.id ? ` von „${artikelVon(vor.artikel).bezeichnung}“` : ''} verfällt früher (${datumText(vor.verfall)}). Das ist erlaubt und wird an der Bewegung vermerkt.`) : '');
    };
    m.addEventListener('input', rechnen); wo.addEventListener('change', rechnen);
    const tw = teileWahl(c, 'lg-e-teile');
    dialog({ id: 'lg-dialog-entnahme', titel: `Entnahme: ${a.bezeichnung}`, ja: 'Entnehmen',
      text: `${nenn(a, c)}${c.verfall ? `, Verfall ${datumText(c.verfall)}` : ''}.${gruppeVon(a) ? ` Artikelgruppe „${gruppeVon(a).bezeichnung}“${gruppeVon(a).aufgabe ? ` – ${gruppeVon(a).aufgabe}` : ''}.` : ''} Ohne Patientenbezug – an wem angewendet wurde, steht in der Patientenakte, nicht im Lager.`,
      felder: [h('div', { class: 'lg-reihe' }, feld('Lagerort', wo), feld(`Menge (${a.einheit})`, m), feld('Zweck', zweck, 'Auswahlliste des Buchs')), rest, tw.zeile],
      senden: async () => {
        const r = await k.api('POST', `${basis}/entnahmen`, { json: { charge: c.id, ort: wo.value, menge: menge(m), zweck: zweck.value || null, ...tw.wert() } });
        await laden();
        k.melden(`Entnommen: ${mengeText(menge(m))} ${a.einheit} ${a.bezeichnung}, ${nenn(a, c)}${r.abweichung ? ' (abweichend vom Vorschlag)' : ''}.`, 'erfolg');
      } });
    rechnen(); m.select();
  }

  /** Hat die Charge Teile, fragt jede Bewegung, ob sie mitgehen (F201) – nichts geschieht still. */
  function teileWahl(c, id) {
    if (!c.teile?.length) return { zeile: null, wert: () => ({}) };
    const el = h('input', { type: 'checkbox', id, checked: true });
    const namen = c.teile.map((t) => `${artikelVon(t.artikel)?.bezeichnung || 'Teil'}${t.bezeichnung ? ` (${t.bezeichnung})` : ''}`).join(', ');
    return { zeile: h('label', { class: 'feld-wahl lg-teile-wahl', title: 'Ohne Haken bleiben die Teile liegen, wo sie sind, und sind danach aus dem Stück gelöst' }, el,
      `${c.teile.length === 1 ? 'Das Teil geht' : `Die ${c.teile.length} Teile gehen`} mit: ${namen}`), wert: () => ({ mit_teilen: el.checked }) };
  }

  /** Rückfrage vor dem Löschen eines Artikels oder einer Artikelgruppe – gelöscht wird nur, was nie benutzt wurde. */
  function loeschenDialog(was, x) {
    const gruppe = was === 'meta';
    dialog({ id: 'lg-dialog-loeschen', titel: `${gruppe ? 'Artikelgruppe' : 'Artikel'} „${x.bezeichnung}“ löschen?`, ja: 'Löschen',
      text: gruppe ? 'Die Artikelgruppe hat keine Artikel und wird entfernt. Der Stand vor dem Löschen steht im Protokoll.'
        : `Der Artikel hatte nie Bestand und wird entfernt${x.stueckliste?.length ? ' – samt seiner Stückliste' : ''}. Der Stand vor dem Löschen steht im Protokoll. Für Artikel, die schon bewegt wurden, gibt es nur „stillgelegt“.`,
      felder: [],
      senden: async () => {
        await k.api('DELETE', `${basis}/${gruppe ? 'meta' : 'artikel'}/${x.id}`);
        if (artikelId === x.id) artikelId = null;
        await laden(); k.melden(`${gruppe ? 'Artikelgruppe' : 'Artikel'} gelöscht.`, 'erfolg');
      } });
  }

  /** Die Artikel einer Gruppe, die zugehen können. */
  function zugangArtikel(g) { return g.mitglieder.filter((x) => !x.stillgelegt && !x.btm); }

  /**
   * Zugang ohne Beleg. Ist eine Artikelgruppe markiert, steht oben die Auswahl, welcher ihrer Artikel zugeht (F197);
   * vorgewählt ist der Artikel der gewählten Charge, sonst der erste. Ein anderer Artikel öffnet den Dialog neu –
   * Charge, Seriennummer und Verfall hängen an seiner Führung.
   */
  function zugangDialog(gewaehlt = null) {
    const gz = zeile()?.istMeta ? zeile() : null;
    const kandidaten = gz ? zugangArtikel(gz) : [];
    const a = gewaehlt || (gz ? kandidaten.find((x) => x.id === artikel()?.id) || kandidaten[0] : artikel());
    const welcher = gz ? auswahl(kandidaten.map((x) => [x.id, x.nummer ? `${x.bezeichnung} (${x.nummer})` : x.bezeichnung]), a.id, { id: 'lg-z-artikel' }) : null;
    const code = h('input', { id: 'lg-z-code', autocomplete: 'off', placeholder: 'Packung scannen – füllt Charge und Verfall' });
    const ch = h('input', { id: 'lg-z-charge', maxlength: '50', autocomplete: 'off' });
    const los = beides(a) ? h('input', { id: 'lg-z-los', maxlength: '50', autocomplete: 'off' }) : null;
    const vf = datumFeld({ id: 'lg-z-verfall' });
    const wo = auswahl(orteFrei().map((o) => [o.id, o.name]), a.standardort || orteFrei()[0].id, { id: 'lg-z-ort' });
    const m = mengeFeld('lg-z-menge', a.fuehrung === 'serie' ? '1' : '');
    const grund = auswahl(GRUENDE_ZU, 'anfangsbestand', { id: 'lg-z-grund' });
    let gelesen = null;
    code.addEventListener('change', () => {
      const c = codeLesen(code.value);
      if (!c) return;
      gelesen = code.value.trim();
      if (c.serie && a.fuehrung === 'serie') ch.value = c.serie; else if (c.charge) ch.value = c.charge;
      if (los && c.charge) los.value = c.charge;
      if (c.verfall) vf.setzen(c.verfall);
      m.focus();
    });
    code.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); code.dispatchEvent(new Event('change')); } });
    // Teile des Stücks (STUECKLISTE.md Abschnitt 12, F201): je Zeile der Stückliste, die einen Artikel oder eine Gruppe nennt,
    // eine Zeile – bei Teilen mit Seriennummer eine je Stück. Gebucht wird alles zusammen oder nichts.
    const teilZeilen = [];
    // Teile ohne eigenen Artikel (F212) bekommen ebenfalls eine Zeile: Sie stehen mit ihrer Seriennummer am Stück, ohne Bestand
    if (a.fuehrung === 'serie') {
      for (const z of a.stueckliste || []) {
        if (!z.artikel && !z.meta) {
          const n = Number.isInteger(Number(z.menge)) && Number(z.menge) <= 20 ? Number(z.menge) : 1;
          for (let i = 0; i < n; i++) {
            const nr = teilZeilen.length + 1;
            const t = { z, frei: true, menge: n > 1 ? '1' : String(z.menge), wahl: null, ch: h('input', { id: `lg-z-t-charge-${nr}`, maxlength: '50', autocomplete: 'off', placeholder: 'Seriennummer, falls vorhanden' }),
              vf: datumFeld({ id: `lg-z-t-verfall-${nr}` }), dabei: h('input', { type: 'checkbox', id: `lg-z-t-dabei-${nr}`, checked: z.erforderlich, 'aria-label': `dabei: ${teilName(z)}` }) };
            t.artikel = () => null;
            t.regeln = () => { sperren(t.ch, t.dabei.checked ? (Number(t.menge) !== 1 ? 'Eine Seriennummer gehört zu einem einzelnen Teil – die Menge ist hier nicht 1' : null) : 'Das Teil ist nicht dabei – setze den Haken, um es einzutragen'); };
            t.vf.sperren(true);
            t.dabei.addEventListener('change', t.regeln);
            t.regeln();
            teilZeilen.push(t);
          }
          continue;
        }
        // Teile, die selbst Charge und Seriennummer haben, gehen hier noch nicht mit – sie brauchen zwei Felder; einzeln zubuchen und einbauen
        const kand = teilArtikel(z).map(artikelVon).filter((x) => x && !x.stillgelegt && !x.btm && !beides(x));
        if (kand.length === 0) continue;
        const einzeln = kand.some((x) => x.fuehrung === 'serie') && Number.isInteger(Number(z.menge)) && Number(z.menge) <= 20 ? Number(z.menge) : 1;
        for (let i = 0; i < einzeln; i++) {
          const nr = teilZeilen.length + 1;
          const t = { z, menge: einzeln > 1 ? '1' : String(z.menge), wahl: z.meta ? auswahl(kand.map((x) => [x.id, x.bezeichnung]), kand[0].id, { id: `lg-z-t-artikel-${nr}`, 'aria-label': `Artikel für ${teilName(z)}` }) : null,
            ch: h('input', { id: `lg-z-t-charge-${nr}`, maxlength: '50', autocomplete: 'off' }), vf: datumFeld({ id: `lg-z-t-verfall-${nr}` }),
            dabei: h('input', { type: 'checkbox', id: `lg-z-t-dabei-${nr}`, checked: z.erforderlich, 'aria-label': `dabei: ${teilName(z)}` }) };
          t.artikel = () => (t.wahl ? artikelVon(t.wahl.value) : kand[0]);
          t.regeln = () => {
            const x = t.artikel();
            const aus = !t.dabei.checked ? 'Das Teil ist nicht dabei – setze den Haken, um es mitzubuchen' : null;
            sperren(t.ch, aus || (x.fuehrung === 'ohne' ? 'Das Teil wird ohne Charge geführt' : null));
            t.ch.placeholder = x.fuehrung === 'serie' ? 'Seriennummer' : x.fuehrung === 'charge' ? 'Charge' : '–';
            t.vf.sperren(Boolean(aus) || x.fuehrung === 'ohne');
            if (t.wahl) sperren(t.wahl, aus);
          };
          t.dabei.addEventListener('change', t.regeln); t.wahl?.addEventListener('change', t.regeln);
          t.regeln();
          teilZeilen.push(t);
        }
      }
    }
    const teileAbschnitt = !(a.stueckliste || []).length ? null : h('div', { class: 'lg-abschnitt', id: 'lg-z-teile' },
      h('div', { class: 'lg-abschnitt-kopf' }, h('h3', {}, 'Teile dieses Stücks'), h('span', { class: 'hinweis' }, teilZeilen.length ? 'Artikel gehen mit eigenem Bestand zu; Teile ohne eigenen Artikel stehen mit ihrer Nummer am Stück' : '')),
      a.fuehrung !== 'serie' ? h('p', { class: 'hinweis', id: 'lg-z-teile-grund' }, `Dieser Artikel hat eine Stückliste, wird aber ${a.fuehrung === 'ohne' ? 'ohne Charge' : 'mit Charge'} geführt. Teile lassen sich nur einem einzelnen Stück zuordnen – dafür braucht der Artikel die Führung „mit Seriennummer“. Die Teile buchst du hier einzeln ein.`)
        : teilZeilen.length === 0 ? h('p', { class: 'hinweis', id: 'lg-z-teile-grund' }, 'Die Stückliste nennt keinen Artikel, der zugehen kann.')
          : h('div', { class: 'lg-gitter lg-z-teile-liste' }, h('table', { class: 'lg-chargen', id: 'lg-z-teile-tabelle' },
            h('thead', {}, h('tr', {}, h('th', {}, 'Dabei'), h('th', {}, 'Teil'), h('th', {}, 'Seriennummer / Charge'), h('th', {}, 'Verfall'), h('th', { class: 'zahl' }, 'Menge'))),
            h('tbody', {}, teilZeilen.map((t) => h('tr', { class: 'lg-nur-lesen' }, h('td', {}, t.dabei), h('td', {}, t.wahl || teilName(t.z), t.frei ? h('span', { class: 'marke-klein', title: 'Wird nicht als eigener Artikel geführt: kein Bestand, die Nummer steht am Stück' }, 'ohne eigenen Artikel') : null, t.z.erforderlich ? null : h('span', { class: 'marke-klein' }, 'optional')),
              h('td', {}, t.ch), h('td', {}, t.vf.el), h('td', { class: 'zahl' }, `${mengeText(t.menge)} ${teilEinheit(t.z)}`)))))));
    const mitCharge = a.fuehrung !== 'ohne';
    if (!mitCharge) { ch.disabled = true; ch.title = 'Der Artikel wird ohne Charge geführt'; vf.sperren(true); code.disabled = true; }
    if (a.fuehrung === 'serie') { m.readOnly = true; m.title = 'Mit Seriennummer ist die Menge je Stück 1'; }
    const r = dialog({ id: 'lg-dialog-zugang', breit: Boolean(teileAbschnitt), titel: `Zugang ohne Beleg: ${a.bezeichnung}`, ja: 'Zugang buchen',
      text: 'Für Anfangsbestand, Muster und Berichtigungen. Zugänge aus Rechnungen kommen über „Wareneingang“ – dort hängt der Beleg daran.',
      felder: [welcher ? feld(`Artikel der Gruppe „${gz.bezeichnung}“`, welcher, 'Die Gruppe hat keinen eigenen Bestand – zugehen kann nur einer ihrer Artikel.') : null, feld('Code scannen', code), h('div', { class: 'lg-reihe' }, feld(a.fuehrung === 'serie' ? 'Seriennummer' : 'Charge', ch), los ? feld('Charge', los, 'gilt für alle Stücke dieser Charge – auch der Verfall') : null, feld(`Verfall${a.verfall_pflicht ? ' (Pflicht)' : ''}`, vf.el)),
        tastaturWache([ch, ...(los ? [los] : []), ...teilZeilen.map((t) => t.ch)], 'lg-z-tastatur'),
        h('div', { class: 'lg-reihe' }, feld('Lagerort', wo), feld(`Menge (${a.einheit})`, m), feld('Grund', grund)), teileAbschnitt],
      senden: async () => {
        if (vf.wert() === null) throw new Error('Verfall: kein gültiges Datum');
        const teile = [];
        for (const t of teilZeilen.filter((x) => x.dabei.checked)) {
          if (t.frei) { teile.push({ bezeichnung: t.z.bezeichnung, seriennummer: t.ch.disabled ? null : t.ch.value.trim() || null, menge: t.menge }); continue; }
          const x = t.artikel();
          const raus = 'oder nimm den Haken „dabei“ heraus';
          if (x.fuehrung !== 'ohne' && t.ch.value.trim() === '') throw new Error(`Teil „${x.bezeichnung}“: ${x.fuehrung === 'serie' ? 'Seriennummer' : 'Charge'} fehlt – ${raus}`);
          if (t.vf.wert() === null) throw new Error(`Teil „${x.bezeichnung}“: Verfall ist kein gültiges Datum`);
          if (x.verfall_pflicht && !t.vf.wert()) throw new Error(`Teil „${x.bezeichnung}“: Verfallsdatum fehlt – ${raus}`);
          const gleich = teile.find((y) => y.artikel === x.id && y.charge === t.ch.value.trim());
          if (gleich && x.fuehrung === 'serie') throw new Error(`Teil „${x.bezeichnung}“: Die Seriennummer ${gleich.charge} steht zweimal da`);
          if (gleich) gleich.menge = String(Number(gleich.menge) + Number(t.menge));
          else teile.push({ artikel: x.id, charge: x.fuehrung === 'ohne' ? '' : t.ch.value.trim(), verfall: x.fuehrung === 'ohne' ? null : t.vf.wert() || null, menge: t.menge });
        }
        if (mitCharge && ch.value.trim() === '') throw new Error(a.fuehrung === 'serie' ? 'Seriennummer fehlt' : 'Charge fehlt');
        if (los && los.value.trim() === '') throw new Error('Charge fehlt');
        if (a.verfall_pflicht && !vf.wert()) throw new Error('Verfallsdatum fehlt');
        await k.api('POST', `${basis}/zugaenge`, { json: { zeilen: [{ artikel: a.id, ort: wo.value, menge: menge(m), charge: ch.value.trim(), ...(los ? { los: los.value.trim() } : {}), verfall: vf.wert() || null, grund: grund.value, code: gelesen, ...(teile.length ? { teile } : {}) }] } });
        await laden();
        k.melden(`Zugang gebucht: ${mengeText(menge(m))} ${a.einheit} ${a.bezeichnung}${teile.length ? ` mit ${teile.length === 1 ? 'einem Teil' : `${teile.length} Teilen`}` : ''}.`, 'erfolg');
      } });
    welcher?.addEventListener('change', () => { const x = artikelVon(welcher.value); r.dlg.close(); zugangDialog(x); });
    (mitCharge ? (a.fuehrung === 'serie' ? ch : code) : m).focus();
  }

  function umlagernDialog() {
    const a = artikel(); const c = charge();
    const von = auswahl(orteDerCharge(c), c.orte[0].ort, { id: 'lg-u-von' });
    const nach = auswahl(orteFrei().map((o) => [o.id, o.name]), orteFrei().find((o) => o.id !== c.orte[0].ort)?.id, { id: 'lg-u-nach' });
    const m = mengeFeld('lg-u-menge', c.orte[0].menge);
    const tw = teileWahl(c, 'lg-u-teile');
    dialog({ id: 'lg-dialog-umlagern', titel: `Umlagern: ${a.bezeichnung}`, ja: 'Umlagern', text: `${nenn(a, c)}.`,
      felder: [h('div', { class: 'lg-reihe' }, feld('Von', von), feld('Nach', nach), feld(`Menge (${a.einheit})`, m)), tw.zeile],
      senden: async () => {
        if (von.value === nach.value) throw new Error('Von und Nach sind derselbe Lagerort');
        await k.api('POST', `${basis}/umlagerungen`, { json: { charge: c.id, von: von.value, nach: nach.value, menge: menge(m), ...tw.wert() } });
        await laden(); k.melden('Umgelagert.', 'erfolg');
      } });
  }

  function ausbuchenDialog() {
    const a = artikel(); const c = charge();
    const wo = auswahl(orteDerCharge(c), c.orte[0].ort, { id: 'lg-a-ort' });
    const m = mengeFeld('lg-a-menge', c.orte[0].menge);
    wo.addEventListener('change', () => { m.value = c.orte.find((o) => o.ort === wo.value)?.menge || ''; });
    const grund = auswahl(GRUENDE_AUS, c.zustand === 'abgelaufen' ? 'verfallen' : 'beschaedigt', { id: 'lg-a-grund' });
    const tw = teileWahl(c, 'lg-a-teile');
    dialog({ id: 'lg-dialog-ausbuchen', titel: `Ausbuchen: ${a.bezeichnung}`, ja: 'Ausbuchen',
      text: `${nenn(a, c)}. Die Bewegung bleibt in der Rückverfolgung stehen; berichtigt wird durch eine Gegenbewegung.`,
      felder: [h('div', { class: 'lg-reihe' }, feld('Lagerort', wo), feld(`Menge (${a.einheit})`, m), feld('Grund', grund)), tw.zeile],
      senden: async () => {
        await k.api('POST', `${basis}/ausbuchungen`, { json: { charge: c.id, ort: wo.value, menge: menge(m), grund: grund.value, ...tw.wert() } });
        await laden(); k.melden('Ausgebucht.', 'erfolg');
      } });
  }

  function sperreDialog() {
    const a = artikel(); const c = charge();
    if (c.sperrgrund) {
      dialog({ id: 'lg-dialog-sperre', titel: `${nenn(a, c)} freigeben`, ja: 'Freigeben', text: `Gesperrt mit dem Grund: ${c.sperrgrund}. Nach der Freigabe ist ${worte(a).die.toLowerCase()} wieder entnehmbar.`, felder: [],
        senden: async () => { await k.api('POST', `${basis}/chargen/${c.id}/sperre`, { json: { grund: null } }); await laden(); k.melden(a.fuehrung === 'serie' ? 'Stück freigegeben.' : 'Charge freigegeben.', 'erfolg'); } });
      return;
    }
    const grund = h('input', { id: 'lg-s-grund', maxlength: '200', required: true, placeholder: 'z. B. Rückruf des Herstellers' });
    dialog({ id: 'lg-dialog-sperre', titel: `${nenn(a, c)} sperren`, ja: 'Sperren', text: a.fuehrung === 'serie' ? 'Ein gesperrtes Stück ist nicht entnehmbar; ausbuchen und umlagern bleiben möglich.' : 'Eine gesperrte Charge ist nicht entnehmbar; ausbuchen und umlagern bleiben möglich.',
      felder: [feld('Grund', grund, `Kein Patientenbezug – nur, warum ${worte(a).die.toLowerCase()} gesperrt ist.`)],
      senden: async () => { await k.api('POST', `${basis}/chargen/${c.id}/sperre`, { json: { grund: grund.value.trim() } }); await laden(); k.melden(a.fuehrung === 'serie' ? 'Stück gesperrt.' : 'Charge gesperrt.', 'erfolg'); } });
    grund.focus();
  }

  async function verlaufDialog() {
    const a = artikel(); const c = charge();
    let v;
    try { v = await k.api('GET', `${basis}/chargen/${c.id}`); } catch (x) { k.fehler(x); return; }
    const wer = (s) => (s === d.ich ? 'du' : s);
    const zeilen = [...v.bewegungen.map((b) => ({ zeit: b.zeit, zellen: [zeit(b.zeit), BEWEGUNG[b.art], `${b.art === 'zugang' ? '+' : b.art === 'umlagerung' ? '' : '−'}${mengeText(b.menge)}`,
      b.art === 'zugang' ? `nach ${b.nach}` : b.art === 'umlagerung' ? `${b.von} → ${b.nach}` : `aus ${b.von}`,
      [b.zweck, GRUND_TEXT[b.grund], b.eintrag ? `Beleg im Arbeitsvorrat${b.position ? `, Position ${b.position}` : ''}` : null, b.abweichung ? 'abweichend vom Vorschlag' : null].filter(Boolean).join(' · '), wer(b.subjekt), mengeText(b.danach)] })),
    ...v.sperren.map((s) => ({ zeit: s.zeit, zellen: [zeit(s.zeit), s.grund === null ? 'Freigabe' : 'Sperre', '', '', s.grund || '', wer(s.subjekt), ''] }))].sort((x, y) => (x.zeit < y.zeit ? -1 : x.zeit > y.zeit ? 1 : 0));
    dialog({ id: 'lg-dialog-verlauf', breit: true, ohneJa: true, titel: `Rückverfolgung: ${a.bezeichnung}, ${nenn(a, c)}`,
      text: `Zugegangen ${mengeText(v.summen.zugang)}, entnommen ${mengeText(v.summen.entnahme)}, ausgebucht ${mengeText(v.summen.ausbuchung)}, im Bestand ${mengeText(v.summen.bestand)} ${a.einheit}`
        + `${v.verfall ? ` · Verfall ${datumText(v.verfall)}` : ''}${v.artikel.gtin ? ` · GTIN ${v.artikel.gtin}` : ''}${v.code ? ` · gelesener Code ${v.code}` : ''}`,
      felder: [h('div', { class: 'lg-rv' }, h('table', { class: 'lg-chargen', id: 'lg-verlauf-tabelle' },
        h('thead', {}, h('tr', {}, ['Zeit', 'Bewegung', 'Menge', 'Von / nach', 'Zweck, Grund oder Beleg', 'Wer', 'Bestand danach'].map((t, i) => h('th', { class: i === 2 || i === 6 ? 'zahl' : '' }, t)))),
        h('tbody', {}, zeilen.map((z) => h('tr', {}, z.zellen.map((t, i) => h('td', { class: i === 2 || i === 6 ? 'zahl' : '' }, t))))))),
      h('p', { class: 'hinweis' }, a.fuehrung === 'serie' ? 'Das Lager kennt keine Patienten. Bei wem dieses Stück angewendet wurde, zeigt das Praxissystem – dort nach der Seriennummer suchen.' : 'Das Lager kennt keine Patienten. Bei wem diese Charge angewendet wurde, zeigt das Praxissystem – dort nach der Chargenbezeichnung suchen.')],
      zusatz: [knopf('Drucken', () => window.print(), '', { id: 'lg-verlauf-drucken' })] });
  }

  /** Vorgabe der Art für einen neuen Artikel: die Art der Artikel der Gruppe, sonst die zuletzt gewählte (je Browser), sonst „Verbrauch“. */
  function artVorgabe(vorGruppe) {
    const ausGruppe = vorGruppe?.mitglieder?.[0]?.art;
    let zuletzt = null;
    try { zuletzt = localStorage.getItem('kumero.lager.art'); } catch { /* ohne Speicher gilt die Vorgabe */ }
    const art = ausGruppe || zuletzt;
    return ARTEN.some(([w]) => w === art) && !(vorGruppe && art === 'arzneimittel') ? art : 'verbrauch';
  }

  /**
   * Artikel anlegen oder ändern. vorGruppe: die markierte Artikelgruppe, in die ein neuer Artikel gleich führt (AG8).
   * Felder im Raster (AG6); was gerade nicht geht, ist gesperrt und nennt den Grund (F106).
   */
  function artikelDialog(a, vorGruppe = null) {
    const neu = !a;
    const f = {
      bezeichnung: h('input', { id: 'lg-f-bezeichnung', maxlength: '200', required: true, value: a?.bezeichnung || '' }),
      nummer: h('input', { id: 'lg-f-nummer', maxlength: '40', value: a?.nummer || '' }),
      // Vorgabe der Art eines neuen Artikels (F197): die der Gruppe, in die er führt, sonst die zuletzt gewählte, sonst „Verbrauch“
      art: auswahl(ARTEN, a?.art || artVorgabe(vorGruppe), { id: 'lg-f-art' }),
      einheit: h('input', { id: 'lg-f-einheit', maxlength: '20', required: true, value: a?.einheit || vorGruppe?.einheit || 'Stück' }),
      // Eigenschaften einzeln statt einer „Führung“ (LAGER.md Abschnitt 19, F211): Sie lassen sich verbinden
      hatCharge: h('input', { type: 'checkbox', id: 'lg-f-charge', checked: a ? a.hat_charge : true }),
      hatSerie: h('input', { type: 'checkbox', id: 'lg-f-serie', checked: a ? a.hat_serie : false }),
      inStuecken: h('input', { type: 'checkbox', id: 'lg-f-stuecke', checked: a ? a.in_stuecken : false }),
      verfall: h('input', { type: 'checkbox', id: 'lg-f-verfall', checked: a ? a.verfall_pflicht : ['arzneimittel', 'medizinprodukt'].includes(artVorgabe(vorGruppe)) }),
      btm: h('input', { type: 'checkbox', id: 'lg-f-btm', checked: a?.btm || false }),
      gtin: h('input', { id: 'lg-f-gtin', maxlength: '14', inputmode: 'numeric', value: a?.gtin || '' }),
      mindest: h('input', { id: 'lg-f-mindest', inputmode: 'decimal', value: a?.mindest ?? '' }),
      soll: h('input', { id: 'lg-f-soll', inputmode: 'decimal', value: a?.soll ?? '' }),
      ort: auswahl([['', '– keiner –'], ...orteFrei().map((o) => [o.id, o.name])], a?.standardort || '', { id: 'lg-f-ort' }),
      bedingung: h('input', { id: 'lg-f-bedingung', maxlength: '100', value: a?.lagerbedingung || '', placeholder: 'z. B. trocken, gekühlt 2–8 °C' }),
      still: h('input', { type: 'checkbox', id: 'lg-f-still', checked: a?.stillgelegt || false }),
      gruppe: auswahl([['', '– keine –'], ...gruppen.filter((g) => !g.stillgelegt || g.id === a?.meta_artikel).map((g) => [g.id, `${g.bezeichnung} (${g.einheit})`])], a?.meta_artikel || vorGruppe?.id || '', { id: 'lg-f-gruppe' }),
    };
    // Die Aufgabe der gewählten Gruppe steht neben der Auswahl (AG3): Hier fällt die Entscheidung „gehört dazu“
    // Die abgeleitete Führung, wie die Regeln unten sie lesen: serie, wenn es Seriennummern gibt, sonst charge, sonst ohne
    f.fuehrung = { get value() { return f.hatSerie.checked ? 'serie' : f.hatCharge.checked ? 'charge' : 'ohne'; },
      addEventListener: (was, tun) => [f.hatCharge, f.hatSerie, f.inStuecken].forEach((el) => el.addEventListener('change', tun)) };
    const aufgabe = h('span', { class: 'lg-zwei lg-aufgabe', id: 'lg-f-gruppe-aufgabe' });
    let ja = null;
    // Stückliste: woraus ein Stück dieses Artikels besteht. Sie wird mit dem Artikel gespeichert.
    const slSenden = (liste) => liste.map((z) => ({ ...(z.artikel ? { artikel: z.artikel } : z.meta ? { meta: z.meta } : { bezeichnung: z.bezeichnung }), menge: mengeLesen(z.menge), erforderlich: z.erforderlich }));
    const sl = (a?.stueckliste || []).map((z) => ({ ...z }));
    const slVorher = JSON.stringify(slSenden(sl));
    const slListe = h('div', { class: 'lg-mitglieder', id: 'lg-s-liste' });
    const slZahl = h('span', { class: 'hinweis', id: 'lg-s-zahl' });
    // Wählbar ist, was keinen Kreis ergibt: nicht der Artikel selbst und nichts, das ihn schon enthält
    const kreis = (ids) => Boolean(a) && ids.some((id) => id === a.id || enthaelt(id, a.id));
    const sTeil = h('select', { id: 'lg-s-teil', 'aria-label': 'Teil für die Stückliste' }, h('option', { value: '' }, '– Teil wählen –'), h('option', { value: 'frei' }, 'Teil ohne eigenen Artikel – Bezeichnung rechts eintragen'),
      h('optgroup', { label: 'Artikel' }, d.artikel.filter((x) => !x.stillgelegt && !kreis([x.id])).map((x) => h('option', { value: `a:${x.id}` }, `${x.bezeichnung} (${x.einheit})`))),
      h('optgroup', { label: 'Artikelgruppen – einer davon' }, gruppen.filter((g) => !g.stillgelegt && !kreis(g.mitglieder.map((m) => m.id))).map((g) => h('option', { value: `g:${g.id}` }, `${g.bezeichnung} (${g.einheit})`))));
    const sText = h('input', { id: 'lg-s-text', maxlength: '200', placeholder: 'z. B. Netzteil', 'aria-label': 'Bezeichnung des Teils' });
    const sMenge = h('input', { id: 'lg-s-menge', inputmode: 'decimal', value: '1', 'aria-label': 'Menge des Teils' });
    const sErf = h('input', { type: 'checkbox', id: 'lg-s-erf', checked: true });
    const sDazu = h('button', { type: 'button', id: 'lg-s-dazu' }, 'Hinzufügen');
    let regeln = () => {};
    const gtinHinweis = h('span', { id: 'lg-f-gtin-hinweis' });
    let fehlerZeile = null; let gtinGemeldet = false;
    const sPruefen = () => {
      const w = sTeil.value;
      sperren(sText, w === 'frei' ? null : 'Nur für ein Teil ohne eigenen Artikel – wähle das links');
      const doppelt = w === 'frei' ? sl.some((z) => z.bezeichnung && z.bezeichnung.trim().toLowerCase() === sText.value.trim().toLowerCase())
        : sl.some((z) => (w.startsWith('a:') && z.artikel === w.slice(2)) || (w.startsWith('g:') && z.meta === w.slice(2)));
      sperren(sDazu, w === '' ? 'Wähle zuerst ein Teil' : w === 'frei' && sText.value.trim() === '' ? 'Die Bezeichnung des Teils fehlt' : mengeLesen(sMenge.value) === null ? 'Menge: eine Zahl größer als null mit höchstens drei Nachkommastellen'
        : doppelt ? 'Dieses Teil steht schon in der Stückliste – ändere dort die Menge' : sl.length >= 200 ? 'Eine Stückliste hat höchstens 200 Zeilen' : null);
    };
    const slZeichnen = () => {
      slZahl.textContent = sl.length === 0 ? 'keine – der Artikel ist ein Teil' : sl.length === 1 ? '1 Teil' : `${sl.length} Teile`;
      slListe.replaceChildren(sl.length === 0 ? h('p', { class: 'hinweis' }, 'Besteht ein Stück aus mehreren Teilen – etwa Gerät, Ladeschale und Kabel –, trage sie hier ein: einen Artikel, eine Artikelgruppe („einer davon“) oder ein Teil ohne eigenen Artikel.')
        : h('table', { class: 'lg-chargen', id: 'lg-s-tabelle' },
          h('thead', {}, h('tr', {}, h('th', {}, 'Teil'), h('th', {}, 'Was'), h('th', { class: 'zahl' }, 'Menge'), h('th', {}, 'Einheit'), h('th', {}, 'Erforderlich'), h('th', {}, ''))),
          h('tbody', {}, sl.map((z, i) => {
            const menge = h('input', { class: 'lg-s-zmenge', inputmode: 'decimal', value: mengeText(z.menge), 'aria-label': `Menge: ${teilName(z)}`, oninput: () => { z.menge = menge.value; regeln(false); } });
            const erf = h('input', { type: 'checkbox', checked: z.erforderlich, 'aria-label': `Erforderlich: ${teilName(z)}`, title: 'Ohne Haken ist das Teil optional', onchange: () => { z.erforderlich = erf.checked; } });
            return h('tr', { class: 'lg-nur-lesen' }, h('td', {}, teilName(z)), h('td', {}, teilArt(z)), h('td', { class: 'zahl' }, menge), h('td', {}, teilEinheit(z)), h('td', {}, erf),
              h('td', {}, h('button', { type: 'button', class: 'lg-s-weg', 'aria-label': `Entfernen: ${teilName(z)}`, onclick: () => { sl.splice(i, 1); slZeichnen(); } }, 'Entfernen')));
          }))));
      sPruefen(); regeln(false);
    };
    sTeil.addEventListener('change', sPruefen); sText.addEventListener('input', sPruefen); sMenge.addEventListener('input', sPruefen);
    sDazu.addEventListener('click', () => {
      const w = sTeil.value;
      sl.push({ artikel: w.startsWith('a:') ? w.slice(2) : null, meta: w.startsWith('g:') ? w.slice(2) : null, bezeichnung: w === 'frei' ? sText.value.trim() : null, menge: mengeLesen(sMenge.value), erforderlich: sErf.checked });
      sTeil.value = ''; sText.value = ''; sMenge.value = '1'; sErf.checked = true;
      slZeichnen(); sTeil.focus();
    });
    // Vorbelegung (WW5): Arzneimittel und Medizinprodukte mit Charge und Verfall; ohne Charge kein Verfall
    regeln = (vonArt) => {
      // mit Seriennummer wird immer in Stücken gezählt – der Haken steht dann fest und nennt den Grund
      if (f.hatSerie.checked) f.inStuecken.checked = true;
      sperren(f.inStuecken, f.hatSerie.checked ? 'Mit Seriennummer wird immer in Stücken gezählt' : null);
      if (f.fuehrung.value === 'ohne') { f.verfall.checked = false; f.verfall.disabled = true; f.verfall.title = 'Ein Verfallsdatum gehört zu einer Charge oder einem Stück mit Seriennummer'; } else {
        f.verfall.disabled = false; f.verfall.title = '';
        if (vonArt && neu) f.verfall.checked = f.art.value === 'arzneimittel' || f.art.value === 'medizinprodukt';
      }
      // Artikelgruppe (LAGER.md MA2, MA7): nicht für Arzneimittel und Betäubungsmittel; in der Gruppe stehen
      // Mindest- und Sollbestand an der Gruppe – die Felder bleiben stehen und sind gesperrt (F106).
      // Eine gewählte Gruppe wird nie still entfernt (AG8): Passt der Artikel nicht hinein, ist der Knopf gesperrt und
      // nennt den Grund; die Auswahl bleibt bedienbar, damit „– keine –“ gewählt werden kann.
      const g = gruppen.find((x) => x.id === f.gruppe.value) || null;
      const keine = keineGruppe(f.art.value, f.btm.checked, f.fuehrung.value);
      sperren(f.gruppe, g ? null : keine || (gruppen.length === 0 ? 'Es gibt noch keine Artikelgruppe („Artikelgruppe neu …“)' : null));
      const passtNicht = !g ? null : keine || (gleicheEinheit(f.einheit.value, g.einheit) ? null : `Die Lagereinheit des Artikels (${f.einheit.value.trim() || 'leer'}) ist nicht die der Artikelgruppe (${g.einheit})`);
      // Die Stückliste sperrt den Knopf nur mit Grund (SA6): eine unlesbare Menge, oder die eigene Gruppe als Teil (ein Kreis)
      const slGrund = sl.some((z) => mengeLesen(z.menge) === null) ? 'Stückliste: Jede Menge ist eine Zahl größer als null mit höchstens drei Nachkommastellen'
        : g && sl.some((z) => z.meta === g.id) ? `Die Stückliste nennt die Artikelgruppe „${g.bezeichnung}“, zu der der Artikel selbst gehört – entferne die Zeile oder wähle eine andere Gruppe` : null;
      // GTIN (F213): Die Meldung sagt, welches Feld, was dort steht und wohin es stattdessen gehört – beim Tippen, nicht erst vom Server
      const g0 = f.gtin.value.trim();
      const gtinGrund = g0 === '' || /^\d{8,14}$/.test(g0) ? null
        : /[A-Za-zÄÖÜäöüß]/.test(g0) ? 'Im Feld „GTIN“ stehen Buchstaben. Die GTIN ist die Nummer unter dem Strichcode der Packung – 8 bis 14 Ziffern, für alle Stücke des Artikels dieselbe. Eine Seriennummer gehört nicht an den Artikel: Sie wird beim Zugang am einzelnen Stück erfasst'
          : /[^0-9]/.test(g0) ? 'Im Feld „GTIN“ stehen andere Zeichen als Ziffern – bitte ohne Leerzeichen, Punkte und Striche: 8 bis 14 Ziffern'
            : `Im Feld „GTIN“ stehen ${g0.length} Ziffern – eine GTIN hat 8 bis 14`;
      gtinHinweis.textContent = gtinGrund ? 'keine GTIN – siehe unten' : 'Kennung auf der Packung, 8–14 Ziffern';
      gtinHinweis.classList.toggle('lg-warn', Boolean(gtinGrund));
      if (fehlerZeile && (gtinGrund || gtinGemeldet)) { fehlerZeile.textContent = gtinGrund ? `${gtinGrund}.` : ' '; gtinGemeldet = Boolean(gtinGrund); }
      sperren(ja, passtNicht ? `${passtNicht} – ändere das oder wähle bei „Artikelgruppe“ „– keine –“` : slGrund || gtinGrund);
      aufgabe.textContent = passtNicht ? `Passt nicht in „${g.bezeichnung}“: ${passtNicht}.`
        : g ? (g.aufgabe ? `Aufgabe der Gruppe: ${g.aufgabe}` : 'Die Aufgabe dieser Gruppe ist noch nicht beschrieben.')
          : 'Artikel gleicher Aufgabe, die einander ersetzen – mit gemeinsamem Mindestbestand';
      aufgabe.classList.toggle('lg-warn', Boolean(passtNicht));
      const anGruppe = g ? 'Der Artikel gehört zu einer Artikelgruppe: Mindest- und Sollbestand stehen dort' : null;
      sperren(f.mindest, anGruppe); sperren(f.soll, anGruppe);
    };
    f.btm.addEventListener('change', () => regeln(false)); f.einheit.addEventListener('input', () => regeln(false));
    f.gruppe.addEventListener('change', () => {
      // ein neuer Artikel übernimmt die Lagereinheit der gewählten Gruppe
      const g = gruppen.find((x) => x.id === f.gruppe.value);
      if (neu && g) f.einheit.value = g.einheit;
      regeln(false);
    });
    f.art.addEventListener('change', () => regeln(true)); f.fuehrung.addEventListener('change', () => regeln(false));
    const bewegt = a && (a.chargen || []).length > 0;
    // mit Bestand (EG5): dazunehmen geht jederzeit, wegnehmen nur, solange kein Bestand die Eigenschaft trägt – das prüft der Server und nennt den Grund
    if (bewegt) for (const el of [f.hatCharge, f.hatSerie]) if (el.checked) el.title = 'Wegnehmen geht nur, solange kein Bestand diese Eigenschaft trägt';
    sperren(f.still, neu ? 'Erst nach dem Anlegen' : null);
    // Löschen (LAGER.md 17.12, F214): nur, was nie Bestand hatte und in keiner Stückliste steht – sonst bleibt es beim Stilllegen
    const knLoeschen = neu ? null : knopf('Löschen …', () => { r.dlg.close(); loeschenDialog('artikel', a); }, '', { id: 'lg-artikel-loeschen', title: 'Einen falsch angelegten Artikel entfernen' });
    if (knLoeschen) sperren(knLoeschen, a.bewegt ? 'Der Artikel hatte schon Bestand – er bleibt für die Rückverfolgung stehen und lässt sich nur stilllegen'
      : a.in_stueckliste ? 'Der Artikel steht in einer Stückliste – erst dort entfernen' : null);
    const r = dialog({ id: 'lg-dialog-artikel', breit: true, zusatz: knLoeschen ? [knLoeschen] : [], titel: neu ? 'Artikel anlegen' : 'Artikel bearbeiten', ja: neu ? 'Anlegen' : 'Speichern',
      felder: [h('div', { class: 'lg-raster' },
        rfeld('Bezeichnung', f.bezeichnung, '', 3), rfeld('Artikelnummer', f.nummer, 'eigene Nummer', 1), rfeld('Art', f.art, '', 2),
        rfeld('Lagereinheit', f.einheit, 'z. B. Stück, Rolle', 1),
        rwahl(5, h('label', { class: 'feld-wahl', title: 'Die Ware kommt in Losen des Herstellers; Zugang und Rückverfolgung nennen die Charge' }, f.hatCharge, 'hat Charge'),
          h('label', { class: 'feld-wahl', title: 'Jedes Stück ist einzeln benannt – zusammen mit „hat Charge“ gehört jedes Stück zu einer Charge' }, f.hatSerie, 'hat Seriennummer'),
          h('label', { class: 'feld-wahl', title: 'Nur ganze Zahlen' }, f.inStuecken, 'wird in Stücken gezählt')),
        rwahl(6, h('label', { class: 'feld-wahl' }, f.verfall, 'Verfallsdatum ist Pflicht'), h('label', { class: 'feld-wahl', title: 'Betäubungsmittel werden nicht im Lager geführt – der Artikel lehnt dann Bewegungen ab' }, f.btm, 'Betäubungsmittel (nicht geführt)')),
        rfeld('GTIN', f.gtin, gtinHinweis, 2), rfeld('Mindestbestand', f.mindest, 'darunter wird nachbestellt', 2), rfeld('Sollbestand', f.soll, 'bis dahin wird nachbestellt', 2),
        rfeld('Üblicher Lagerort', f.ort, '', 2), rfeld('Lagerbedingung', f.bedingung, '', 4),
        rfeld('Artikelgruppe', f.gruppe, aufgabe, 6)),
      h('div', { class: 'lg-abschnitt' },
        h('div', { class: 'lg-abschnitt-kopf' }, h('h3', {}, 'Stückliste'), slZahl),
        slListe,
        h('div', { class: 'lg-s-neu' }, sTeil, sText, sMenge, h('label', { class: 'feld-wahl', title: 'Ohne Haken ist das Teil optional' }, sErf, 'erforderlich'), sDazu)),
      h('label', { class: 'feld-wahl', title: 'Stillgelegte Artikel nehmen keine Zugänge mehr an; gelöscht wird nichts' }, f.still, 'stillgelegt')],
      senden: async () => {
        const mengeOderLeer = (el) => (el.value.trim() === '' ? null : (mengeLesen(el.value) ?? (/^0([.,]0+)?$/.test(el.value.trim()) ? '0' : undefined)));
        const inGruppe = f.gruppe.value || null;
        const mindest = inGruppe ? null : mengeOderLeer(f.mindest);
        const soll = inGruppe ? null : mengeOderLeer(f.soll);
        if (mindest === undefined) throw new Error('Mindestbestand: eine Zahl mit höchstens drei Nachkommastellen');
        if (soll === undefined) throw new Error('Sollbestand: eine Zahl mit höchstens drei Nachkommastellen');
        if (soll !== null && mindest !== null && Number(soll) < Number(mindest)) throw new Error('Der Sollbestand liegt unter dem Mindestbestand');
        const x = { bezeichnung: f.bezeichnung.value, nummer: f.nummer.value || null, art: f.art.value, einheit: f.einheit.value, hat_charge: f.hatCharge.checked, hat_serie: f.hatSerie.checked, in_stuecken: f.inStuecken.checked, verfall_pflicht: f.verfall.checked,
          btm: f.btm.checked, gtin: f.gtin.value.trim() || null, mindest, soll, standardort: f.ort.value || null, lagerbedingung: f.bedingung.value || null, stillgelegt: f.still.checked };
        // erst lösen, dann speichern – sonst wiese der Server den eigenen Mindestbestand eines Gruppenartikels ab
        if (!neu && a.meta_artikel && !inGruppe) await k.api('POST', `${basis}/artikel/${a.id}/meta`, { json: { meta: null } });
        const r2 = neu ? await k.api('POST', `${basis}/artikel`, { json: x }) : await k.api('PUT', `${basis}/artikel/${a.id}`, { json: x });
        if (neu) { try { localStorage.setItem('kumero.lager.art', x.art); } catch { /* dann gilt beim nächsten Mal wieder die Vorgabe */ } }
        if (inGruppe && inGruppe !== (a?.meta_artikel || null)) await k.api('POST', `${basis}/artikel/${r2.id}/meta`, { json: { meta: inGruppe } });
        if (JSON.stringify(slSenden(sl)) !== slVorher) await k.api('PUT', `${basis}/artikel/${r2.id}/stueckliste`, { json: { zeilen: slSenden(sl) } });
        if (inGruppe) offen.add(inGruppe);
        // aus der markierten Gruppe angelegt: Die Gruppe bleibt markiert und ist aufgeklappt (AG8)
        artikelId = vorGruppe && inGruppe === vorGruppe.id ? inGruppe : r2.id; suche.value = ''; filter = 'alle';
        await laden(); k.melden(neu ? (inGruppe ? `Artikel angelegt – in der Artikelgruppe „${gruppen.find((g) => g.id === inGruppe)?.bezeichnung || ''}“.` : 'Artikel angelegt.') : 'Artikel gespeichert.', 'erfolg');
      } });
    ja = r.ja; fehlerZeile = r.fehler;
    f.gtin.addEventListener('input', () => regeln(false));
    slZeichnen();
    f.bezeichnung.focus();
  }

  /**
   * Artikelgruppe anlegen oder ändern: eine Aufgabe, kein Produkt – ohne GTIN, Charge und eigenen Bestand (LAGER.md
   * Abschnitt 17). Von oben nach unten (STUECKLISTE.md AG6): was es ist, wofür es dient, wie es gezählt wird, was
   * dazugehört, Zustand. Zuordnen und Lösen wirken sofort (AG7), die Felder darüber erst mit „Speichern“.
   */
  function gruppeDialog(g0) {
    const neu = !g0;
    let g = g0;
    // Vorschlag aus dem gewählten Artikel (MA7) – nur, wenn er selbst in eine Gruppe dürfte
    const z0 = neu ? zeile() : null;
    const vor = z0 && !z0.istMeta && !z0.meta_artikel && !keineGruppe(z0.art, z0.btm, z0.fuehrung) ? z0 : null;
    const f = {
      bezeichnung: h('input', { id: 'lg-m-bezeichnung', maxlength: '200', required: true, value: g?.bezeichnung || '', placeholder: 'z. B. Kopierpapier A4' }),
      // Pflicht beim Anlegen; eine Gruppe von früher bleibt ohne Aufgabe änderbar, bis sie nachgetragen ist (AG2)
      aufgabe: h('textarea', { id: 'lg-m-aufgabe', rows: '2', maxlength: '500', required: neu || Boolean(g?.aufgabe), placeholder: 'z. B. weiß, 80 g/m², für alle Drucker und Kopierer im Haus' }, g?.aufgabe || ''),
      einheit: h('input', { id: 'lg-m-einheit', maxlength: '20', required: true, value: g?.einheit || vor?.einheit || 'Stück' }),
      mindest: h('input', { id: 'lg-m-mindest', inputmode: 'decimal', value: g ? (g.mindest ?? '') : (vor?.mindest ?? '') }),
      soll: h('input', { id: 'lg-m-soll', inputmode: 'decimal', value: g ? (g.soll ?? '') : (vor?.soll ?? '') }),
      still: h('input', { type: 'checkbox', id: 'lg-m-still', checked: g?.stillgelegt || false }),
    };
    const zaehler = h('span', { id: 'lg-m-aufgabe-zahl' });
    const zaehlen = () => { zaehler.textContent = `${f.aufgabe.value.length} / 500`; };
    f.aufgabe.addEventListener('input', zaehlen);
    // Enter im mehrzeiligen Feld schickt den Dialog nicht ab und bricht nicht um: Die Aufgabe ist ein Absatz
    f.aufgabe.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') ev.preventDefault(); });

    // Artikel der Gruppe (AG7): fester Platz, feste Höhe; gewählt wird eine Zeile, „Lösen“ gilt für sie
    let gewaehlt = null;
    const liste2 = h('div', { class: 'lg-mitglieder', id: 'lg-m-liste' });
    const wahl = h('select', { id: 'lg-m-zu', 'aria-label': 'Artikel, der zugeordnet wird' });
    const hin = h('p', { class: 'lg-hinweis-fest', id: 'lg-m-hinweis', role: 'status' });
    const passende = () => d.artikel.filter((x) => !x.meta_artikel && !keineGruppe(x.art, x.btm, x.fuehrung) && gleicheEinheit(x.einheit, g.einheit)).sort((x, y) => x.bezeichnung.localeCompare(y.bezeichnung, 'de'));
    const hinweisen = (fehler) => {
      const x = g ? artikelVon(wahl.value) : null;
      hin.replaceChildren(fehler ? h('span', { class: 'lg-warn' }, fehler)
        : neu ? 'Artikel ordnest du nach dem Anlegen zu – hier oder mit „Artikel neu …“ bei markierter Gruppe.'
          : x && (x.mindest !== null || x.soll !== null) ? `Beim Zuordnen entfällt der eigene Mindest- und Sollbestand von „${x.bezeichnung}“ (${x.mindest === null ? '–' : mengeText(x.mindest)} / ${x.soll === null ? '–' : mengeText(x.soll)}): Beides steht dann an der Gruppe.`
            : 'Zuordnen und Lösen wirken sofort. Zur Auswahl steht, was in die Gruppe darf: gleiche Lagereinheit, kein Arzneimittel, noch in keiner Gruppe.');
    };
    const knZu = knopf('Zuordnen', () => wirken(wahl.value, g.id, 'zugeordnet'), '', { id: 'lg-m-zuordnen', title: 'Den gewählten Artikel dieser Gruppe zuordnen' });
    const knLos = knopf('Lösen', () => wirken(gewaehlt, null, 'gelöst'), '', { id: 'lg-m-loesen', title: 'Den markierten Artikel aus der Gruppe nehmen – er führt danach wieder einen eigenen Mindestbestand' });
    async function wirken(artikelIdZu, meta, wort) {
      const name = artikelVon(artikelIdZu)?.bezeichnung || '';
      try {
        await k.api('POST', `${basis}/artikel/${artikelIdZu}/meta`, { json: { meta } });
      } catch (x) { hinweisen(x.problem?.detail || x.message || 'Das hat nicht geklappt'); return; }
      offen.add(g.id);
      await laden();
      g = gruppen.find((x) => x.id === g.id) || g;
      gewaehlt = meta ? artikelIdZu : null;
      mitglieder();
      k.melden(`„${name}“ ${wort}.`, 'erfolg');
    }
    function mitglieder() {
      const hat = Boolean(g) && g.mitglieder.length > 0;
      if (!hat) gewaehlt = null; else if (!g.mitglieder.some((x) => x.id === gewaehlt)) gewaehlt = g.mitglieder[0].id;
      liste2.replaceChildren(neu ? h('p', { class: 'hinweis' }, 'Erst nach dem Anlegen.') : !hat ? h('p', { class: 'hinweis', id: 'lg-m-leer' }, 'Noch kein Artikel zugeordnet.')
        : h('table', { class: 'lg-chargen', id: 'lg-m-tabelle' },
          h('thead', {}, h('tr', {}, h('th', {}, 'Artikel'), h('th', {}, 'Nummer'), h('th', { class: 'zahl' }, 'verfügbar'), h('th', {}, 'Nächster Verfall'))),
          h('tbody', {}, g.mitglieder.map((x) => {
            const tr = h('tr', { tabindex: '0', class: x.id === gewaehlt ? 'gewaehlt' : '', 'aria-selected': x.id === gewaehlt ? 'true' : 'false', 'data-id': x.id },
              h('td', {}, x.bezeichnung, x.stillgelegt ? h('span', { class: 'marke-klein' }, 'stillgelegt') : null), h('td', {}, x.nummer || ''),
              h('td', { class: 'zahl' }, `${mengeText(x.verfuegbar)} ${x.einheit}`), h('td', {}, x.naechster_verfall ? datumText(x.naechster_verfall) : '–'));
            const waehlen = () => { gewaehlt = x.id; mitglieder(); liste2.querySelector(`tr[data-id="${x.id}"]`)?.focus(); };
            tr.addEventListener('click', waehlen);
            tr.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); waehlen(); } });
            return tr;
          }))));
      const frei = g ? passende() : [];
      const vorher = wahl.value;
      wahl.replaceChildren(...(frei.length ? frei.map((x) => h('option', { value: x.id }, x.nummer ? `${x.bezeichnung} (${x.nummer})` : x.bezeichnung)) : [h('option', { value: '' }, '– kein passender Artikel –')]));
      if (frei.some((x) => x.id === vorher)) wahl.value = vorher;
      const zu = neu ? 'Erst nach dem Anlegen' : g.stillgelegt ? 'Die Artikelgruppe ist stillgelegt – sie nimmt keine Artikel auf' : frei.length === 0 ? 'Kein Artikel passt: gleiche Lagereinheit, kein Arzneimittel, noch in keiner Gruppe' : null;
      sperren(wahl, zu); sperren(knZu, zu);
      sperren(knLos, neu ? 'Erst nach dem Anlegen' : !hat ? 'Der Gruppe ist kein Artikel zugeordnet' : null);
      if (hat) f.einheit.value = g.einheit;
      sperren(f.einheit, hat ? 'Die Lagereinheit steht fest, solange die Gruppe Artikel hat' : null);
      if (hat) f.still.checked = false;
      sperren(f.still, neu ? 'Erst nach dem Anlegen' : hat ? 'Die Gruppe hat noch Artikel – erst die Zuordnung lösen' : null);
      hinweisen(null);
    }
    wahl.addEventListener('change', () => hinweisen(null));

    dialog({ id: 'lg-dialog-gruppe', breit: true, zusatz: neu ? [] : [(() => {
      const kn1 = knopf('Löschen …', () => { document.getElementById('lg-dialog-gruppe')?.close(); loeschenDialog('meta', g0); }, '', { id: 'lg-gruppe-loeschen', title: 'Eine falsch angelegte Artikelgruppe entfernen' });
      sperren(kn1, g0.mitglieder.length ? 'Der Artikelgruppe sind noch Artikel zugeordnet – erst unten lösen' : null);
      return kn1;
    })()], titel: neu ? 'Artikelgruppe anlegen' : 'Artikelgruppe bearbeiten', ja: neu ? 'Anlegen' : 'Speichern',
      text: 'Eine Artikelgruppe fasst Artikel zusammen, die dieselbe Aufgabe erfüllen und einander ersetzen. Gezählt wird die Summe; entnommen wird immer ein Artikel mit seiner Charge.',
      felder: [h('div', { class: 'lg-raster' },
        rfeld('Bezeichnung', f.bezeichnung, '', 6),
        rfeld('Aufgabe', f.aufgabe, h('span', { class: 'lg-mit-zaehler' }, h('span', {}, !neu && !g.aufgabe ? 'Fehlt noch: wofür die Artikel dienen und was einer können muss, um dazuzugehören – Sachen, keine Personen'
          : 'wofür die Artikel dienen und was einer können muss, um dazuzugehören – Sachen, keine Personen'), zaehler), 6),
        rfeld('Lagereinheit', f.einheit, 'gilt für alle Artikel der Gruppe', 2), rfeld('Mindestbestand', f.mindest, 'über alle Artikel – darunter wird nachbestellt', 2), rfeld('Sollbestand', f.soll, 'bis dahin wird nachbestellt', 2)),
      h('section', { class: 'lg-abschnitt', 'aria-label': 'Artikel der Gruppe' },
        h('div', { class: 'lg-abschnitt-kopf' }, h('h3', {}, 'Artikel der Gruppe'), wahl, knZu, knLos), liste2, hin),
      h('label', { class: 'feld-wahl', title: 'Eine stillgelegte Artikelgruppe nimmt keine Artikel mehr auf; gelöscht wird nichts' }, f.still, 'stillgelegt')],
      senden: async () => {
        const mengeOderLeer = (el) => (el.value.trim() === '' ? null : (mengeLesen(el.value) ?? (/^0([.,]0+)?$/.test(el.value.trim()) ? '0' : undefined)));
        const mindest = mengeOderLeer(f.mindest);
        const soll = mengeOderLeer(f.soll);
        if (mindest === undefined) throw new Error('Mindestbestand: eine Zahl mit höchstens drei Nachkommastellen');
        if (soll === undefined) throw new Error('Sollbestand: eine Zahl mit höchstens drei Nachkommastellen');
        if (soll !== null && mindest !== null && Number(soll) < Number(mindest)) throw new Error('Der Sollbestand liegt unter dem Mindestbestand');
        const aufgabe = f.aufgabe.value.replace(/\s+/g, ' ').trim();
        if (aufgabe === '' && f.aufgabe.required) throw new Error('Aufgabe fehlt: wofür die Artikel der Gruppe dienen');
        const x = { bezeichnung: f.bezeichnung.value, aufgabe: aufgabe || null, einheit: f.einheit.value, mindest, soll, stillgelegt: f.still.checked };
        const r = neu ? await k.api('POST', `${basis}/meta`, { json: x }) : await k.api('PUT', `${basis}/meta/${g.id}`, { json: x });
        artikelId = r.id; suche.value = ''; filter = 'alle';
        await laden(); k.melden(neu ? 'Artikelgruppe angelegt.' : 'Artikelgruppe gespeichert.', 'erfolg');
      } });
    zaehlen(); mitglieder();
    f.bezeichnung.focus();
  }

  /**
   * Lagerorte (LAGER.md Abschnitt 18, F191): oben die Orte als Tabelle mit dem, was dort liegt, darunter an festem Platz
   * der Bestand des gewählten Orts; dann der gewählte Ort zum Ändern und ein neuer zum Anlegen. Beide Tabellen tragen
   * Sortierung und Filter am Spaltenkopf (F91). Alles wirkt sofort; gelöscht wird nichts (WW23).
   */
  function orteDialog() {
    let ortId = (d.orte.find((o) => !o.stillgelegt) || d.orte[0])?.id || null;
    let fehlerEl = null;
    // Was an einem Ort liegt: je Charge eine Zeile – der Bestand ist die Summe der Bewegungen, hier nur gezeigt
    const inhalt = (id) => d.artikel.flatMap((a) => a.chargen.flatMap((c) => c.orte.filter((x) => x.ort === id && Number(x.menge) > 0).map((x) => ({ id: c.id, artikel: a, charge: c, menge: x.menge }))));
    const orteMitInhalt = () => d.orte.map((o) => { const i = inhalt(o.id); return { ...o, inhalt: i, artikelZahl: new Set(i.map((x) => x.artikel.id)).size }; });
    const ortSpalten = [
      { id: 'name', titel: 'Lagerort', wert: (o) => o.name },
      { id: 'kalt', titel: 'Gekühlt', klasse: 'eng', wert: (o) => (o.gekuehlt ? 'gekühlt' : '–') },
      { id: 'artikel', titel: 'Artikel', klasse: 'zahl', wert: (o) => String(o.artikelZahl), sortwert: (o) => o.artikelZahl },
      { id: 'chargen', titel: 'Chargen', klasse: 'zahl', wert: (o) => String(o.inhalt.length), sortwert: (o) => o.inhalt.length },
      { id: 'zustand', titel: 'Zustand', klasse: 'eng', wert: (o) => (o.stillgelegt ? 'stillgelegt' : o.inhalt.length ? 'mit Bestand' : 'leer'),
        zelle: (o) => h('span', { class: ['marke-klein', o.stillgelegt ? '' : o.inhalt.length ? 'korrigiert' : ''] }, o.stillgelegt ? 'stillgelegt' : o.inhalt.length ? 'mit Bestand' : 'leer') },
    ];
    const inhaltSpalten = [
      { id: 'artikel', titel: 'Artikel', wert: (x) => x.artikel.bezeichnung },
      { id: 'nummer', titel: 'Nummer', klasse: 'eng', wert: (x) => x.artikel.nummer || '' },
      { id: 'charge', titel: 'Charge / Seriennummer', zellklasse: 'lg-nr', wert: (x) => chargeName(x.artikel, x.charge) },
      { id: 'verfall', titel: 'Verfall', klasse: 'eng', wert: (x) => (x.charge.verfall ? datumText(x.charge.verfall) : '–'), sortwert: (x) => x.charge.verfall || '' },
      { id: 'menge', titel: 'Menge', klasse: 'zahl', wert: (x) => `${mengeText(x.menge)} ${x.artikel.einheit}`, sortwert: (x) => Number(x.menge) },
      { id: 'zustand', titel: 'Zustand', wert: (x) => chargeZustand(x.charge), zelle: (x) => marke(x.charge.zustand, chargeZustand(x.charge)) },
    ];
    const ortFilter = tabellenfilter(ortSpalten, () => zeigenOrte(), { id: 'lager.orte' });
    // Die Filter des Bestands gelten für den gewählten Ort und beginnen mit jedem anderen neu (OBERFLAECHE.md O1)
    let inhaltFilter = tabellenfilter(inhaltSpalten, () => zeigenOrte(), { id: 'lager.ortbestand' });
    let inhaltVon = null;
    const liste2 = h('div', { class: 'lg-gitter', id: 'lg-o-liste' });
    const unten2 = h('div', { class: 'lg-gitter', id: 'lg-o-bestand' });
    const titel2 = h('h3', { id: 'lg-o-bestand-titel' });
    const summe2 = h('span', { class: 'hinweis', id: 'lg-o-bestand-summe' });
    const f = {
      name: h('input', { id: 'lg-o-aendern-name', maxlength: '80' }),
      kalt: h('input', { type: 'checkbox', id: 'lg-o-aendern-kalt' }),
      neuName: h('input', { id: 'lg-o-name', maxlength: '80', placeholder: 'z. B. Lager Keller, Regal 2' }),
      neuKalt: h('input', { type: 'checkbox', id: 'lg-o-kalt' }),
    };
    const meldenFehler = (x) => { fehlerEl.textContent = (x.problem?.fehler || []).map((e) => e.meldung).join(' · ') || x.problem?.detail || x.message || 'Das hat nicht geklappt'; };
    const tun = async (was, danach) => {
      try { await was(); fehlerEl.textContent = ' '; await laden(); danach?.(); zeigenOrte(true); } catch (x) { meldenFehler(x); }
    };
    const gewaehlterOrt = () => d.orte.find((o) => o.id === ortId) || null;
    const knSpeichern = knopf('Speichern', () => { const o = gewaehlterOrt(); tun(() => k.api('PUT', `${basis}/orte/${o.id}`, { json: { name: f.name.value, gekuehlt: f.kalt.checked, stillgelegt: o.stillgelegt } }), () => k.melden('Lagerort gespeichert.', 'erfolg')); },
      '', { id: 'lg-o-speichern', title: 'Name und „gekühlt“ des gewählten Lagerorts speichern' });
    const knStill = knopf('Stilllegen', () => { const o = gewaehlterOrt(); tun(() => k.api('PUT', `${basis}/orte/${o.id}`, { json: { name: o.name, gekuehlt: o.gekuehlt, stillgelegt: !o.stillgelegt } }), () => k.melden(o.stillgelegt ? 'Lagerort wird wieder genutzt.' : 'Lagerort stillgelegt.', 'erfolg')); },
      '', { id: 'lg-o-still', class: 'knopf lg-o-still', title: 'Ein stillgelegter Lagerort nimmt keine Ware mehr auf; gelöscht wird nichts' });
    const knAnlegen = knopf('Anlegen', () => tun(async () => { const r = await k.api('POST', `${basis}/orte`, { json: { name: f.neuName.value, gekuehlt: f.neuKalt.checked } }); ortId = r.id; },
      () => { f.neuName.value = ''; f.neuKalt.checked = false; k.melden('Lagerort angelegt.', 'erfolg'); f.neuName.focus(); }), 'primaer', { id: 'lg-o-anlegen', title: 'Den neuen Lagerort anlegen' });
    const geaendert = () => { const o = gewaehlterOrt(); return Boolean(o) && (f.name.value.trim() !== o.name || f.kalt.checked !== o.gekuehlt); };
    function knoepfeOrte() {
      const o = gewaehlterOrt();
      const hat = o ? inhalt(o.id).length > 0 : false;
      sperren(f.name, o ? null : 'Erst einen Lagerort wählen'); sperren(f.kalt, o ? null : 'Erst einen Lagerort wählen');
      sperren(knSpeichern, !o ? 'Erst einen Lagerort wählen' : f.name.value.trim() === '' ? 'Der Name fehlt' : !geaendert() ? 'Nichts geändert' : null);
      knStill.textContent = o?.stillgelegt ? 'Wieder nutzen' : 'Stilllegen';
      sperren(knStill, !o ? 'Erst einen Lagerort wählen' : !o.stillgelegt && hat ? 'Der Lagerort hat noch Bestand – erst umlagern oder ausbuchen' : geaendert() ? 'Erst die Änderung speichern' : null);
      sperren(knAnlegen, f.neuName.value.trim() === '' ? 'Erst einen Namen eingeben' : null);
    }
    /** neuLaden: Die Felder des gewählten Orts werden aus seinem Stand gefüllt – sonst bleibt, was der Nutzer gerade tippt. */
    function zeigenOrte(neuLaden = false) {
      const alle = orteMitInhalt();
      ortFilter.setzeZeilen(alle);
      const zeilen = ortFilter.anwenden(alle);
      if (!zeilen.some((o) => o.id === ortId)) { ortId = zeilen[0]?.id || null; neuLaden = true; }
      const o = zeilen.find((x) => x.id === ortId) || null;
      liste2.replaceChildren(d.orte.length === 0 ? h('p', { class: 'hinweis', id: 'lg-o-leer' }, 'Noch kein Lagerort. Lege unten den ersten an.')
        : h('table', { class: 'lg-chargen', id: 'lg-o-tabelle' }, h('thead', {}, ortFilter.kopf),
          h('tbody', {}, zeilen.length ? zeilen.map((x) => {
            const tr = h('tr', { tabindex: '0', class: [x.id === ortId ? 'gewaehlt' : '', x.stillgelegt ? 'lg-still' : ''], 'aria-selected': x.id === ortId ? 'true' : 'false', 'data-id': x.id }, zellen(ortFilter.spalten(), x));
            const waehlen = () => { ortId = x.id; zeigenOrte(true); liste2.querySelector(`tr[data-id="${x.id}"]`)?.focus(); };
            tr.addEventListener('click', waehlen);
            tr.addEventListener('keydown', (ev) => { if (ev.target === tr && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); waehlen(); } });
            return tr;
          }) : h('tr', { class: 'leer' }, h('td', { colspan: ortSpalten.length }, 'Kein Lagerort passt zum Filter.')))));
      if ((o?.id || null) !== inhaltVon) {
        inhaltVon = o?.id || null;
        inhaltFilter.entfernen();
        inhaltFilter = tabellenfilter(inhaltSpalten, () => zeigenOrte(), { id: 'lager.ortbestand' });
      }
      const drin = o ? o.inhalt : [];
      inhaltFilter.setzeZeilen(drin);
      const gezeigt = inhaltFilter.anwenden(drin);
      titel2.textContent = o ? `Bestand an diesem Lagerort: ${o.name}` : 'Bestand an diesem Lagerort';
      summe2.textContent = !o ? '' : drin.length === 0 ? 'nichts' : `${o.artikelZahl === 1 ? '1 Artikel' : `${o.artikelZahl} Artikel`} in ${drin.length === 1 ? '1 Charge' : `${drin.length} Chargen`}${inhaltFilter.aktiv() ? ` · gezeigt ${gezeigt.length}` : ''}`;
      unten2.replaceChildren(!o ? h('p', { class: 'hinweis' }, 'Kein Lagerort gewählt.') : drin.length === 0 ? h('p', { class: 'hinweis', id: 'lg-o-bestand-leer' }, o.stillgelegt ? 'Stillgelegt – hier liegt nichts.' : 'Hier liegt nichts.')
        : h('table', { class: 'lg-chargen', id: 'lg-o-bestand-tabelle' }, h('thead', {}, inhaltFilter.kopf),
          h('tbody', {}, gezeigt.length ? gezeigt.map((x) => h('tr', { class: 'lg-nur-lesen', 'data-id': x.id }, zellen(inhaltFilter.spalten(), x)))
            : h('tr', { class: 'leer' }, h('td', { colspan: inhaltSpalten.length }, 'Keine Charge passt zum Filter.')))));
      if (neuLaden) { f.name.value = o?.name || ''; f.kalt.checked = o?.gekuehlt || false; }
      knoepfeOrte();
    }
    for (const el of [f.name, f.neuName]) el.addEventListener('input', knoepfeOrte);
    f.kalt.addEventListener('change', knoepfeOrte);
    f.name.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); if (!knSpeichern.disabled) knSpeichern.click(); } });
    f.neuName.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); if (!knAnlegen.disabled) knAnlegen.click(); } });
    const r = dialog({ id: 'lg-dialog-orte', breit: true, titel: 'Lagerorte', ohneJa: true,
      text: 'Wo die Ware liegt. Ein Lagerort mit Bestand lässt sich nicht stilllegen; gelöscht wird nichts. Bewegt wird die Ware auf der Seite „Bestand“ mit „Umlagern …“.',
      felder: [liste2,
        h('section', { class: 'lg-abschnitt', 'aria-label': 'Bestand am gewählten Lagerort' }, h('div', { class: 'lg-abschnitt-kopf' }, titel2, summe2), unten2),
        h('div', { class: 'lg-raster' }, rfeld('Gewählter Lagerort', f.name, 'umbenennen: Die Ware bleibt, wo sie ist', 3),
          rwahl(1, h('label', { class: 'feld-wahl' }, f.kalt, 'gekühlt')), rwahl(2, knSpeichern, knStill)),
        h('div', { class: 'lg-raster' }, rfeld('Neuer Lagerort', f.neuName, '', 3),
          rwahl(1, h('label', { class: 'feld-wahl' }, f.neuKalt, 'gekühlt')), rwahl(2, knAnlegen))] });
    fehlerEl = r.fehler;
    r.dlg.addEventListener('close', () => { ortFilter.entfernen(); inhaltFilter.entfernen(); });
    zeigenOrte(true);
    (d.orte.length ? liste2.querySelector('tr.gewaehlt') : f.neuName)?.focus();
  }

  function einstellungDialog() {
    const tage = h('input', { id: 'lg-g-tage', class: 'lg-kurz', inputmode: 'numeric', value: String(d.einstellung.warn_tage) });
    const zwecke = h('textarea', { id: 'lg-g-zwecke', rows: '6', cols: '36' }, d.einstellung.zwecke.join('\n'));
    dialog({ id: 'lg-dialog-einstellung', titel: 'Einstellungen des Lagers', ja: 'Speichern',
      felder: [feld('„Läuft ab“ ab so vielen Tagen vor dem Verfall', tage), feld('Zwecke der Entnahme – einer je Zeile', zwecke, 'Die Entnahme kennt nur diese Auswahl und keinen freien Text: So gerät kein Patientenname ins Lager.')],
      senden: async () => {
        if (!/^\d{1,3}$/.test(tage.value.trim())) throw new Error('Tage: eine ganze Zahl von 1 bis 730');
        await k.api('PUT', `${basis}/einstellung`, { json: { warn_tage: Number(tage.value.trim()), zwecke: zwecke.value.split('\n').map((z) => z.trim()).filter(Boolean) } });
        await laden(); k.melden('Einstellungen gespeichert.', 'erfolg');
      } });
  }

  suche.addEventListener('input', () => { chargeId = null; zeichnen(); });
  await laden();
  // Von „Wareneingang“ über „Zugang ohne Beleg …“ gekommen: Der Dialog öffnet sich gleich – oder die Seite sagt, was fehlt
  let vonWe = false;
  try { vonWe = sessionStorage.getItem('kumero.lager.zugang') === '1'; sessionStorage.removeItem('kumero.lager.zugang'); } catch { /* ohne Speicher bleibt es bei der Seite */ }
  if (vonWe) { if (kn.zugang.disabled) k.melden(`Zugang ohne Beleg: ${kn.zugang.title}.`, 'warnung'); else zugangDialog(); }
}
