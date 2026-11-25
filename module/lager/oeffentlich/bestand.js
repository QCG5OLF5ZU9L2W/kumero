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
import { ARTEN, FUEHRUNG, ZUSTAND, mengeText, mengeLesen, codeLesen, dialog, stil, rfeld, rwahl } from './gemeinsam.js';

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

  const suche = h('input', { type: 'search', id: 'lg-suche', class: 'lg-suche', placeholder: 'Artikel, Nummer oder Charge – oder Packung scannen', 'aria-label': 'Suchen', autocomplete: 'off' });
  const kn = {
    entnahme: knopf('Entnahme …', entnahmeDialog, 'primaer', { id: 'lg-entnahme', title: 'Aus der gewählten Charge entnehmen' }),
    zugang: knopf('Zugang ohne Beleg …', zugangDialog, '', { id: 'lg-zugang', title: 'Anfangsbestand, Muster oder Berichtigung – Zugänge aus Rechnungen stehen unter „Wareneingang“' }),
    umlagern: knopf('Umlagern …', umlagernDialog, '', { id: 'lg-umlagern', title: 'Die gewählte Charge an einen anderen Lagerort bringen' }),
    ausbuchen: knopf('Ausbuchen …', ausbuchenDialog, '', { id: 'lg-ausbuchen', title: 'Verfallen, beschädigt, zurückgegeben oder vernichtet – auch für abgelaufene und gesperrte Chargen' }),
    sperre: knopf('Sperren …', sperreDialog, '', { id: 'lg-sperre', title: 'Die gewählte Charge sperren oder wieder freigeben' }),
    verlauf: knopf('Rückverfolgung …', verlaufDialog, '', { id: 'lg-verlauf', title: 'Alle Bewegungen der gewählten Charge' }),
    neu: knopf('Artikel neu …', () => artikelDialog(null, markierteGruppe()), '', { id: 'lg-artikel-neu', title: 'Artikel anlegen' }),
    gruppe: knopf('Artikelgruppe neu …', () => gruppeDialog(null), '', { id: 'lg-gruppe-neu', title: 'Artikel gleicher Aufgabe zusammenfassen, die einander ersetzen – mit gemeinsamem Mindestbestand' }),
    aendern: knopf('Bearbeiten …', () => (zeile()?.istMeta ? gruppeDialog(zeile()) : artikelDialog(zeile())), '', { id: 'lg-artikel-aendern', title: 'Angaben des gewählten Artikels oder der gewählten Artikelgruppe ändern' }),
    orte: knopf('Lagerorte …', orteDialog, '', { id: 'lg-orte', title: 'Lagerorte mit ihrem Bestand ansehen, anlegen, umbenennen, stilllegen' }),
    einstellung: knopf('Einstellungen …', einstellungDialog, '', { id: 'lg-einstellung', title: 'Warnfrist vor dem Verfall und die Auswahlliste „Zweck“ der Entnahme' }),
    drucken: knopf('Drucken', () => window.print(), '', { id: 'lg-drucken', title: 'Bestandsliste drucken; als PDF über den Druckdialog' }),
  };
  const kacheln = h('div', { class: 'lg-kacheln nicht-drucken', id: 'lg-kacheln' });
  const liste = h('div', { class: 'liste-rahmen lg-liste', id: 'lg-liste' });
  const unten = h('section', { class: 'lg-chargen-rahmen', id: 'lg-chargen', 'aria-label': 'Chargen des gewählten Artikels' });
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
  const chargeName = (a, c) => (a?.fuehrung === 'ohne' ? 'ohne Charge' : c.bezeichnung);
  const marke = (zustand, text) => h('span', { class: ['marke-klein', ZUSTAND[zustand][1]] }, text || ZUSTAND[zustand][0]);
  const chargeZustand = (c) => (c.zustand === 'abgelaufen' ? 'abgelaufen – nur ausbuchen' : c.zustand === 'gesperrt' ? `gesperrt – ${c.sperrgrund}` : c.zustand === 'laeuft_ab' ? `läuft ab in ${c.tage} Tagen` : 'frei');
  const chargeOrte = (c) => c.orte.map((o) => `${ort(o.ort)}${c.orte.length > 1 ? ` (${mengeText(o.menge)})` : ''}`).join(', ');

  // Spalten mit Sortierung und Filter am Kopf (F91): wert = Text für Filter und Sortierung, zelle = Anzeige
  const spalten = [
    { id: 'auf', titel: '', fest: true, klasse: 'lg-auf', zelle: dreieck },
    { id: 'artikel', titel: 'Artikel', wert: (a) => a.bezeichnung, zelle: (a) => [!a.istMeta && a.meta_artikel ? h('span', { class: 'lg-ein', 'aria-hidden': 'true' }, '↳ ') : null, a.bezeichnung,
      a.istMeta ? h('span', { class: 'marke-klein korrigiert', title: a.aufgabe ? `Aufgabe: ${a.aufgabe}` : 'Artikel gleicher Aufgabe, die einander ersetzen' }, a.mitglieder.length === 1 ? 'Gruppe · 1 Artikel' : `Gruppe · ${a.mitglieder.length} Artikel`) : null,
      a.istMeta && !a.aufgabe ? h('span', { class: 'marke-klein warnung', title: 'Trage unter „Bearbeiten …“ nach, wofür die Artikel dieser Gruppe dienen' }, 'Aufgabe fehlt') : null,
      a.btm ? h('span', { class: 'marke-klein warnung', title: 'Betäubungsmittel werden nicht im Lager geführt' }, 'BtM – nicht geführt') : null,
      a.stillgelegt ? h('span', { class: 'marke-klein' }, 'stillgelegt') : null] },
    { id: 'nummer', titel: 'Nummer', klasse: 'eng', wert: (a) => a.nummer || '' },
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
      c.vorschlag ? h('span', { class: 'marke-klein korrigiert', title: 'Frühester Verfall unter den freien Chargen' }, 'wird vorgeschlagen') : null] },
  ];
  const zellen = (sp, z) => sp.map((s) => h('td', { class: s.zellklasse || s.klasse || '' }, s.zelle ? s.zelle(z) : s.wert(z)));
  const spaltenfilter = tabellenfilter(spalten, () => zeichnen(), { id: 'lager.bestand' });
  // Die Filter der Chargen gelten für den gewählten Artikel: Mit einem anderen Artikel beginnen sie neu, damit kein
  // Filter von vorhin dessen Bestand verdeckt.
  let chargenfilter = tabellenfilter(chargenSpalten, () => zeichnen(), { id: 'lager.chargen' });
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
      chargenfilter = tabellenfilter(chargenSpalten, () => zeichnen(), { id: 'lager.chargen' });
    }
    chargenfilter.setzeZeilen(a0 ? a0.chargen : []);
    // Gewählt ist immer eine Charge, die zu sehen ist – die Knöpfe wirken nie auf eine weggefilterte
    const chargen = a0 ? chargenfilter.anwenden(a0.chargen) : [];
    if (a0 && !chargen.some((c) => c.id === chargeId)) {
      const code = codeLesen(suche.value);
      chargeId = (chargen.find((c) => code?.charge && c.bezeichnung === (code.serie || code.charge)) || chargen.find((c) => c.vorschlag) || chargen[0])?.id || null;
    }
    if (d.artikel.length === 0) {
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
      unten.replaceChildren(
        h('div', { class: 'lg-chargen-kopf' }, h('h2', { id: 'lg-chargen-titel' }, a.istMeta ? `Chargen aller Artikel: ${a.bezeichnung}` : `Chargen: ${a.bezeichnung}`),
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
            }) : h('tr', { class: 'leer' }, h('td', { colspan: chargenSpalten.length, id: 'lg-keine-charge' }, 'Keine Charge passt zum Filter.')))));
    }
    knoepfe();
  }

  function knoepfe() {
    const a = artikel();
    const c = charge();
    const darf = d.darf;
    const keine = !c ? 'Erst eine Charge mit Bestand wählen' : null;
    sperren(kn.entnahme, !darf.buchen ? 'Dir fehlt das Recht, im Lager zu buchen' : keine
      || (c.zustand === 'abgelaufen' ? `Die Charge ist seit dem ${datumText(c.verfall)} abgelaufen – sie lässt sich nur ausbuchen` : c.zustand === 'gesperrt' ? `Die Charge ist gesperrt: ${c.sperrgrund}` : null));
    sperren(kn.zugang, !darf.buchen ? 'Dir fehlt das Recht, im Lager zu buchen' : zeile()?.istMeta ? 'Eine Artikelgruppe hat keinen eigenen Bestand – klappe sie auf und wähle den Artikel, der zugeht' : !a ? 'Erst einen Artikel wählen' : a.btm ? 'Betäubungsmittel werden nicht im Lager geführt'
      : a.stillgelegt ? 'Der Artikel ist stillgelegt' : orteFrei().length === 0 ? 'Erst einen Lagerort anlegen' : null);
    sperren(kn.umlagern, !darf.buchen ? 'Dir fehlt das Recht, im Lager zu buchen' : keine || (orteFrei().length < 2 ? 'Dafür braucht es einen zweiten Lagerort' : null));
    sperren(kn.ausbuchen, !darf.buchen ? 'Dir fehlt das Recht, im Lager zu buchen' : keine);
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
    dialog({ id: 'lg-dialog-entnahme', titel: `Entnahme: ${a.bezeichnung}`, ja: 'Entnehmen',
      text: `Charge ${chargeName(a, c)}${c.verfall ? `, Verfall ${datumText(c.verfall)}` : ''}.${gruppeVon(a) ? ` Artikelgruppe „${gruppeVon(a).bezeichnung}“${gruppeVon(a).aufgabe ? ` – ${gruppeVon(a).aufgabe}` : ''}.` : ''} Ohne Patientenbezug – an wem angewendet wurde, steht in der Patientenakte, nicht im Lager.`,
      felder: [h('div', { class: 'lg-reihe' }, feld('Lagerort', wo), feld(`Menge (${a.einheit})`, m), feld('Zweck', zweck, 'Auswahlliste des Buchs')), rest],
      senden: async () => {
        const r = await k.api('POST', `${basis}/entnahmen`, { json: { charge: c.id, ort: wo.value, menge: menge(m), zweck: zweck.value || null } });
        await laden();
        k.melden(`Entnommen: ${mengeText(menge(m))} ${a.einheit} ${a.bezeichnung}, Charge ${chargeName(a, c)}${r.abweichung ? ' (abweichend vom Vorschlag)' : ''}.`, 'erfolg');
      } });
    rechnen(); m.select();
  }

  function zugangDialog() {
    const a = artikel();
    const code = h('input', { id: 'lg-z-code', autocomplete: 'off', placeholder: 'Packung scannen – füllt Charge und Verfall' });
    const ch = h('input', { id: 'lg-z-charge', maxlength: '50', autocomplete: 'off' });
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
      if (c.verfall) vf.setzen(c.verfall);
      m.focus();
    });
    code.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); code.dispatchEvent(new Event('change')); } });
    const mitCharge = a.fuehrung !== 'ohne';
    if (!mitCharge) { ch.disabled = true; ch.title = 'Der Artikel wird ohne Charge geführt'; vf.sperren(true); code.disabled = true; }
    if (a.fuehrung === 'serie') { m.readOnly = true; m.title = 'Mit Seriennummer ist die Menge je Stück 1'; }
    dialog({ id: 'lg-dialog-zugang', titel: `Zugang ohne Beleg: ${a.bezeichnung}`, ja: 'Zugang buchen',
      text: 'Für Anfangsbestand, Muster und Berichtigungen. Zugänge aus Rechnungen kommen über „Wareneingang“ – dort hängt der Beleg daran.',
      felder: [feld('Code scannen', code), h('div', { class: 'lg-reihe' }, feld(a.fuehrung === 'serie' ? 'Seriennummer' : 'Charge', ch), feld(`Verfall${a.verfall_pflicht ? ' (Pflicht)' : ''}`, vf.el)),
        h('div', { class: 'lg-reihe' }, feld('Lagerort', wo), feld(`Menge (${a.einheit})`, m), feld('Grund', grund))],
      senden: async () => {
        if (vf.wert() === null) throw new Error('Verfall: kein gültiges Datum');
        if (mitCharge && ch.value.trim() === '') throw new Error(a.fuehrung === 'serie' ? 'Seriennummer fehlt' : 'Charge fehlt');
        if (a.verfall_pflicht && !vf.wert()) throw new Error('Verfallsdatum fehlt');
        await k.api('POST', `${basis}/zugaenge`, { json: { zeilen: [{ artikel: a.id, ort: wo.value, menge: menge(m), charge: ch.value.trim(), verfall: vf.wert() || null, grund: grund.value, code: gelesen }] } });
        await laden();
        k.melden(`Zugang gebucht: ${mengeText(menge(m))} ${a.einheit} ${a.bezeichnung}.`, 'erfolg');
      } });
    (mitCharge ? code : m).focus();
  }

  function umlagernDialog() {
    const a = artikel(); const c = charge();
    const von = auswahl(orteDerCharge(c), c.orte[0].ort, { id: 'lg-u-von' });
    const nach = auswahl(orteFrei().map((o) => [o.id, o.name]), orteFrei().find((o) => o.id !== c.orte[0].ort)?.id, { id: 'lg-u-nach' });
    const m = mengeFeld('lg-u-menge', c.orte[0].menge);
    dialog({ id: 'lg-dialog-umlagern', titel: `Umlagern: ${a.bezeichnung}`, ja: 'Umlagern', text: `Charge ${chargeName(a, c)}.`,
      felder: [h('div', { class: 'lg-reihe' }, feld('Von', von), feld('Nach', nach), feld(`Menge (${a.einheit})`, m))],
      senden: async () => {
        if (von.value === nach.value) throw new Error('Von und Nach sind derselbe Lagerort');
        await k.api('POST', `${basis}/umlagerungen`, { json: { charge: c.id, von: von.value, nach: nach.value, menge: menge(m) } });
        await laden(); k.melden('Umgelagert.', 'erfolg');
      } });
  }

  function ausbuchenDialog() {
    const a = artikel(); const c = charge();
    const wo = auswahl(orteDerCharge(c), c.orte[0].ort, { id: 'lg-a-ort' });
    const m = mengeFeld('lg-a-menge', c.orte[0].menge);
    wo.addEventListener('change', () => { m.value = c.orte.find((o) => o.ort === wo.value)?.menge || ''; });
    const grund = auswahl(GRUENDE_AUS, c.zustand === 'abgelaufen' ? 'verfallen' : 'beschaedigt', { id: 'lg-a-grund' });
    dialog({ id: 'lg-dialog-ausbuchen', titel: `Ausbuchen: ${a.bezeichnung}`, ja: 'Ausbuchen',
      text: `Charge ${chargeName(a, c)}. Die Bewegung bleibt in der Rückverfolgung stehen; berichtigt wird durch eine Gegenbewegung.`,
      felder: [h('div', { class: 'lg-reihe' }, feld('Lagerort', wo), feld(`Menge (${a.einheit})`, m), feld('Grund', grund))],
      senden: async () => {
        await k.api('POST', `${basis}/ausbuchungen`, { json: { charge: c.id, ort: wo.value, menge: menge(m), grund: grund.value } });
        await laden(); k.melden('Ausgebucht.', 'erfolg');
      } });
  }

  function sperreDialog() {
    const a = artikel(); const c = charge();
    if (c.sperrgrund) {
      dialog({ id: 'lg-dialog-sperre', titel: `Charge ${chargeName(a, c)} freigeben`, ja: 'Freigeben', text: `Gesperrt mit dem Grund: ${c.sperrgrund}. Nach der Freigabe ist die Charge wieder entnehmbar.`, felder: [],
        senden: async () => { await k.api('POST', `${basis}/chargen/${c.id}/sperre`, { json: { grund: null } }); await laden(); k.melden('Charge freigegeben.', 'erfolg'); } });
      return;
    }
    const grund = h('input', { id: 'lg-s-grund', maxlength: '200', required: true, placeholder: 'z. B. Rückruf des Herstellers' });
    dialog({ id: 'lg-dialog-sperre', titel: `Charge ${chargeName(a, c)} sperren`, ja: 'Sperren', text: 'Eine gesperrte Charge ist nicht entnehmbar; ausbuchen und umlagern bleiben möglich.',
      felder: [feld('Grund', grund, 'Kein Patientenbezug – nur, warum die Charge gesperrt ist.')],
      senden: async () => { await k.api('POST', `${basis}/chargen/${c.id}/sperre`, { json: { grund: grund.value.trim() } }); await laden(); k.melden('Charge gesperrt.', 'erfolg'); } });
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
    dialog({ id: 'lg-dialog-verlauf', breit: true, ohneJa: true, titel: `Rückverfolgung: ${a.bezeichnung}, Charge ${chargeName(a, c)}`,
      text: `Zugegangen ${mengeText(v.summen.zugang)}, entnommen ${mengeText(v.summen.entnahme)}, ausgebucht ${mengeText(v.summen.ausbuchung)}, im Bestand ${mengeText(v.summen.bestand)} ${a.einheit}`
        + `${v.verfall ? ` · Verfall ${datumText(v.verfall)}` : ''}${v.artikel.gtin ? ` · GTIN ${v.artikel.gtin}` : ''}${v.code ? ` · gelesener Code ${v.code}` : ''}`,
      felder: [h('div', { class: 'lg-rv' }, h('table', { class: 'lg-chargen', id: 'lg-verlauf-tabelle' },
        h('thead', {}, h('tr', {}, ['Zeit', 'Bewegung', 'Menge', 'Von / nach', 'Zweck, Grund oder Beleg', 'Wer', 'Bestand danach'].map((t, i) => h('th', { class: i === 2 || i === 6 ? 'zahl' : '' }, t)))),
        h('tbody', {}, zeilen.map((z) => h('tr', {}, z.zellen.map((t, i) => h('td', { class: i === 2 || i === 6 ? 'zahl' : '' }, t))))))),
      h('p', { class: 'hinweis' }, 'Das Lager kennt keine Patienten. Bei wem diese Charge angewendet wurde, zeigt das Praxissystem – dort nach der Chargenbezeichnung suchen.')],
      zusatz: [knopf('Drucken', () => window.print(), '', { id: 'lg-verlauf-drucken' })] });
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
      art: auswahl(ARTEN, a?.art || 'medizinprodukt', { id: 'lg-f-art' }),
      einheit: h('input', { id: 'lg-f-einheit', maxlength: '20', required: true, value: a?.einheit || vorGruppe?.einheit || 'Stück' }),
      fuehrung: auswahl(FUEHRUNG, a?.fuehrung || 'charge', { id: 'lg-f-fuehrung' }),
      verfall: h('input', { type: 'checkbox', id: 'lg-f-verfall', checked: a ? a.verfall_pflicht : true }),
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
    const aufgabe = h('span', { class: 'lg-zwei lg-aufgabe', id: 'lg-f-gruppe-aufgabe' });
    let ja = null;
    // Vorbelegung (WW5): Arzneimittel und Medizinprodukte mit Charge und Verfall; ohne Charge kein Verfall
    const regeln = (vonArt) => {
      if (f.fuehrung.value === 'ohne') { f.verfall.checked = false; f.verfall.disabled = true; f.verfall.title = 'Ein Verfallsdatum gehört zu einer Charge'; } else {
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
      sperren(ja, passtNicht ? `${passtNicht} – ändere das oder wähle bei „Artikelgruppe“ „– keine –“` : null);
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
    if (bewegt) { f.fuehrung.disabled = true; f.fuehrung.title = 'Die Führungsart steht fest, sobald der Artikel Bestand hat'; }
    sperren(f.still, neu ? 'Erst nach dem Anlegen' : null);
    const r = dialog({ id: 'lg-dialog-artikel', breit: true, titel: neu ? 'Artikel anlegen' : 'Artikel bearbeiten', ja: neu ? 'Anlegen' : 'Speichern',
      felder: [h('div', { class: 'lg-raster' },
        rfeld('Bezeichnung', f.bezeichnung, '', 3), rfeld('Artikelnummer', f.nummer, 'eigene Nummer', 1), rfeld('Art', f.art, '', 2),
        rfeld('Lagereinheit', f.einheit, 'z. B. Stück, Rolle', 1), rfeld('Führung', f.fuehrung, '', 2),
        rwahl(3, h('label', { class: 'feld-wahl' }, f.verfall, 'Verfallsdatum ist Pflicht'), h('label', { class: 'feld-wahl', title: 'Betäubungsmittel werden nicht im Lager geführt – der Artikel lehnt dann Bewegungen ab' }, f.btm, 'Betäubungsmittel (nicht geführt)')),
        rfeld('GTIN', f.gtin, 'Kennung auf der Packung, 8–14 Ziffern', 2), rfeld('Mindestbestand', f.mindest, 'darunter wird nachbestellt', 2), rfeld('Sollbestand', f.soll, 'bis dahin wird nachbestellt', 2),
        rfeld('Üblicher Lagerort', f.ort, '', 2), rfeld('Lagerbedingung', f.bedingung, '', 4),
        rfeld('Artikelgruppe', f.gruppe, aufgabe, 6)),
      h('label', { class: 'feld-wahl', title: 'Stillgelegte Artikel nehmen keine Zugänge mehr an; gelöscht wird nichts' }, f.still, 'stillgelegt')],
      senden: async () => {
        const mengeOderLeer = (el) => (el.value.trim() === '' ? null : (mengeLesen(el.value) ?? (/^0([.,]0+)?$/.test(el.value.trim()) ? '0' : undefined)));
        const inGruppe = f.gruppe.value || null;
        const mindest = inGruppe ? null : mengeOderLeer(f.mindest);
        const soll = inGruppe ? null : mengeOderLeer(f.soll);
        if (mindest === undefined) throw new Error('Mindestbestand: eine Zahl mit höchstens drei Nachkommastellen');
        if (soll === undefined) throw new Error('Sollbestand: eine Zahl mit höchstens drei Nachkommastellen');
        if (soll !== null && mindest !== null && Number(soll) < Number(mindest)) throw new Error('Der Sollbestand liegt unter dem Mindestbestand');
        const x = { bezeichnung: f.bezeichnung.value, nummer: f.nummer.value || null, art: f.art.value, einheit: f.einheit.value, fuehrung: f.fuehrung.value, verfall_pflicht: f.verfall.checked,
          btm: f.btm.checked, gtin: f.gtin.value.trim() || null, mindest, soll, standardort: f.ort.value || null, lagerbedingung: f.bedingung.value || null, stillgelegt: f.still.checked };
        // erst lösen, dann speichern – sonst wiese der Server den eigenen Mindestbestand eines Gruppenartikels ab
        if (!neu && a.meta_artikel && !inGruppe) await k.api('POST', `${basis}/artikel/${a.id}/meta`, { json: { meta: null } });
        const r2 = neu ? await k.api('POST', `${basis}/artikel`, { json: x }) : await k.api('PUT', `${basis}/artikel/${a.id}`, { json: x });
        if (inGruppe && inGruppe !== (a?.meta_artikel || null)) await k.api('POST', `${basis}/artikel/${r2.id}/meta`, { json: { meta: inGruppe } });
        if (inGruppe) offen.add(inGruppe);
        // aus der markierten Gruppe angelegt: Die Gruppe bleibt markiert und ist aufgeklappt (AG8)
        artikelId = vorGruppe && inGruppe === vorGruppe.id ? inGruppe : r2.id; suche.value = ''; filter = 'alle';
        await laden(); k.melden(neu ? (inGruppe ? `Artikel angelegt – in der Artikelgruppe „${gruppen.find((g) => g.id === inGruppe)?.bezeichnung || ''}“.` : 'Artikel angelegt.') : 'Artikel gespeichert.', 'erfolg');
      } });
    ja = r.ja;
    regeln(false);
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

    dialog({ id: 'lg-dialog-gruppe', breit: true, titel: neu ? 'Artikelgruppe anlegen' : 'Artikelgruppe bearbeiten', ja: neu ? 'Anlegen' : 'Speichern',
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
      { id: 'charge', titel: 'Charge', zellklasse: 'lg-nr', wert: (x) => chargeName(x.artikel, x.charge) },
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
}
