// SPDX-License-Identifier: AGPL-3.0-or-later
// „Bestand“ (LAGER.md Stufe 1, F131): Artikel des Lagers mit Menge, nächstem Verfall und Zustand in Worten; darunter
// an festem Platz die Chargen des gewählten Artikels. Entnahme mit Vorschlag (frühester Verfall zuerst), abgelaufene
// und gesperrte Chargen sind nicht entnehmbar. Nichts verschiebt sich, gesperrte Knöpfe nennen den Grund (F106).
// Beide Tabellen tragen Sortierung und Filter am Spaltenkopf (F91), ihre Spalten sind verschiebbar (F146); zwischen
// Liste und Chargen liegt der Teiler (F137).
// Artikelgruppen (LAGER.md Abschnitt 17, F168; intern „Meta-Artikel“): eine Zeile für ersetzbare Artikel gleicher Funktion –
// Summe, Mindestbestand und Vorschlag gelten über alle; hinter dem Dreieck stehen die Artikel der Gruppe. Bewegt wird
// immer der konkrete Artikel mit seiner Charge.
import { h, seitenkopf, knopf, feld, auswahl, sperren, datumFeld, datumText, zeit } from '/kern/ui/bausteine.js';
import { tabellenfilter } from '/kern/ui/tabellenfilter.js';
import { teiler } from '/kern/ui/teiler.js';
import { ARTEN, FUEHRUNG, ZUSTAND, mengeText, mengeLesen, codeLesen, dialog, stil } from './gemeinsam.js';

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
    neu: knopf('Artikel neu …', () => artikelDialog(null), '', { id: 'lg-artikel-neu', title: 'Artikel anlegen' }),
    gruppe: knopf('Artikelgruppe neu …', () => gruppeDialog(null), '', { id: 'lg-gruppe-neu', title: 'Artikel gleicher Funktion zusammenfassen, die einander ersetzen – mit gemeinsamem Mindestbestand' }),
    aendern: knopf('Bearbeiten …', () => (zeile()?.istMeta ? gruppeDialog(zeile()) : artikelDialog(zeile())), '', { id: 'lg-artikel-aendern', title: 'Angaben des gewählten Artikels oder der gewählten Artikelgruppe ändern' }),
    orte: knopf('Lagerorte …', orteDialog, '', { id: 'lg-orte', title: 'Lagerorte anlegen, umbenennen, stilllegen' }),
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
      a.istMeta ? h('span', { class: 'marke-klein korrigiert', title: 'Artikel gleicher Funktion, die einander ersetzen' }, a.mitglieder.length === 1 ? 'Gruppe · 1 Artikel' : `Gruppe · ${a.mitglieder.length} Artikel`) : null,
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
      title: 'Liste auf diese Artikel einschränken – noch einmal drücken zeigt wieder alle', onclick: () => { filter = filter === id ? 'alle' : id; zeichnen(); } },
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
          h('span', { class: 'hinweis' }, a.istMeta ? `Artikelgruppe · ${a.mitglieder.length === 1 ? '1 Artikel' : `${a.mitglieder.length} Artikel`} · Lagereinheit ${a.einheit} · vorgeschlagen wird der früheste Verfall über alle`
            : `${FUEHRUNG.find(([w]) => w === a.fuehrung)[1]}${a.verfall_pflicht ? ', Verfall Pflicht' : ''} · Lagereinheit ${a.einheit}${a.lagerbedingung ? ` · ${a.lagerbedingung}` : ''}${gruppeVon(a) ? ` · gehört zur Artikelgruppe „${gruppeVon(a).bezeichnung}“` : ''}`)),
        a.chargen.length === 0 ? h('p', { class: 'hinweis' }, a.btm ? 'Betäubungsmittel werden nicht im Lager geführt – der Nachweis gehört ins Betäubungsmittelbuch.'
          : a.istMeta && a.mitglieder.length === 0 ? 'Noch kein Artikel in dieser Gruppe. Öffne einen Artikel mit „Bearbeiten …“ und wähle dort die Artikelgruppe.' : 'Kein Bestand.')
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
      text: `Charge ${chargeName(a, c)}${c.verfall ? `, Verfall ${datumText(c.verfall)}` : ''}. Ohne Patientenbezug – an wem angewendet wurde, steht in der Patientenakte, nicht im Lager.`,
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

  function artikelDialog(a) {
    const neu = !a;
    const f = {
      bezeichnung: h('input', { id: 'lg-f-bezeichnung', maxlength: '200', required: true, value: a?.bezeichnung || '' }),
      nummer: h('input', { id: 'lg-f-nummer', maxlength: '40', class: 'lg-kurz', value: a?.nummer || '' }),
      art: auswahl(ARTEN, a?.art || 'medizinprodukt', { id: 'lg-f-art' }),
      einheit: h('input', { id: 'lg-f-einheit', maxlength: '20', class: 'lg-kurz', required: true, value: a?.einheit || 'Stück' }),
      fuehrung: auswahl(FUEHRUNG, a?.fuehrung || 'charge', { id: 'lg-f-fuehrung' }),
      verfall: h('input', { type: 'checkbox', id: 'lg-f-verfall', checked: a ? a.verfall_pflicht : true }),
      btm: h('input', { type: 'checkbox', id: 'lg-f-btm', checked: a?.btm || false }),
      gtin: h('input', { id: 'lg-f-gtin', maxlength: '14', inputmode: 'numeric', value: a?.gtin || '' }),
      mindest: h('input', { id: 'lg-f-mindest', class: 'lg-kurz', inputmode: 'decimal', value: a?.mindest ?? '' }),
      soll: h('input', { id: 'lg-f-soll', class: 'lg-kurz', inputmode: 'decimal', value: a?.soll ?? '' }),
      ort: auswahl([['', '– keiner –'], ...orteFrei().map((o) => [o.id, o.name])], a?.standardort || '', { id: 'lg-f-ort' }),
      bedingung: h('input', { id: 'lg-f-bedingung', maxlength: '100', value: a?.lagerbedingung || '', placeholder: 'z. B. gekühlt 2–8 °C' }),
      still: h('input', { type: 'checkbox', id: 'lg-f-still', checked: a?.stillgelegt || false }),
      gruppe: auswahl([['', '– keine –'], ...gruppen.filter((g) => !g.stillgelegt || g.id === a?.meta_artikel).map((g) => [g.id, `${g.bezeichnung} (${g.einheit})`])], a?.meta_artikel || '', { id: 'lg-f-gruppe' }),
    };
    // Vorbelegung (WW5): Arzneimittel und Medizinprodukte mit Charge und Verfall; ohne Charge kein Verfall
    const regeln = (vonArt) => {
      if (f.fuehrung.value === 'ohne') { f.verfall.checked = false; f.verfall.disabled = true; f.verfall.title = 'Ein Verfallsdatum gehört zu einer Charge'; } else {
        f.verfall.disabled = false; f.verfall.title = '';
        if (vonArt && neu) f.verfall.checked = f.art.value === 'arzneimittel' || f.art.value === 'medizinprodukt';
      }
      // Artikelgruppe (LAGER.md MA2, MA7): nicht für Arzneimittel, Betäubungsmittel und Seriennummern; in der Gruppe stehen
      // Mindest- und Sollbestand an der Gruppe – die Felder bleiben stehen und sind gesperrt (F106)
      const keine = f.art.value === 'arzneimittel' ? 'Arzneimittel gehören zu keiner Artikelgruppe: Ob eines ein anderes ersetzt, ist eine Entscheidung im Einzelfall'
        : f.btm.checked ? 'Betäubungsmittel gehören zu keiner Artikelgruppe' : f.fuehrung.value === 'serie' ? 'Artikel mit Seriennummer gehören zu keiner Artikelgruppe'
        : gruppen.length === 0 ? 'Es gibt noch keine Artikelgruppe („Artikelgruppe neu …“)' : null;
      if (keine) f.gruppe.value = '';
      sperren(f.gruppe, keine);
      const anGruppe = f.gruppe.value ? 'Der Artikel gehört zu einer Artikelgruppe: Mindest- und Sollbestand stehen dort' : null;
      sperren(f.mindest, anGruppe); sperren(f.soll, anGruppe);
    };
    f.btm.addEventListener('change', () => regeln(false)); f.gruppe.addEventListener('change', () => regeln(false));
    f.art.addEventListener('change', () => regeln(true)); f.fuehrung.addEventListener('change', () => regeln(false));
    const bewegt = a && (a.chargen || []).length > 0;
    if (bewegt) { f.fuehrung.disabled = true; f.fuehrung.title = 'Die Führungsart steht fest, sobald der Artikel Bestand hat'; }
    regeln(false);
    dialog({ id: 'lg-dialog-artikel', breit: true, titel: neu ? 'Artikel anlegen' : 'Artikel bearbeiten', ja: neu ? 'Anlegen' : 'Speichern',
      felder: [h('div', { class: 'lg-reihe' }, feld('Bezeichnung', f.bezeichnung), feld('Artikelnummer', f.nummer), feld('Art', f.art)),
        h('div', { class: 'lg-reihe' }, feld('Lagereinheit', f.einheit, 'z. B. Stück, Ampulle, Packung'), feld('Führung', f.fuehrung),
          h('label', { class: 'feld-wahl' }, f.verfall, 'Verfallsdatum ist Pflicht'), h('label', { class: 'feld-wahl', title: 'Betäubungsmittel werden nicht im Lager geführt – der Artikel lehnt dann Bewegungen ab' }, f.btm, 'Betäubungsmittel (nicht geführt)')),
        h('div', { class: 'lg-reihe' }, feld('GTIN', f.gtin, 'Produktkennung auf der Packung, 8–14 Ziffern'), feld('Mindestbestand', f.mindest, 'darunter wird nachbestellt'), feld('Sollbestand', f.soll, 'bis dahin wird nachbestellt'), feld('Üblicher Lagerort', f.ort), feld('Lagerbedingung', f.bedingung)),
        h('div', { class: 'lg-reihe' }, feld('Artikelgruppe', f.gruppe, 'Artikel gleicher Funktion, die einander ersetzen – mit gemeinsamem Mindestbestand')),
        neu ? null : h('label', { class: 'feld-wahl', title: 'Stillgelegte Artikel nehmen keine Zugänge mehr an; gelöscht wird nichts' }, f.still, 'stillgelegt')],
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
        const r = neu ? await k.api('POST', `${basis}/artikel`, { json: x }) : await k.api('PUT', `${basis}/artikel/${a.id}`, { json: x });
        if (inGruppe && inGruppe !== (a?.meta_artikel || null)) await k.api('POST', `${basis}/artikel/${r.id}/meta`, { json: { meta: inGruppe } });
        if (inGruppe) offen.add(inGruppe);
        artikelId = r.id; suche.value = ''; filter = 'alle';
        await laden(); k.melden(neu ? 'Artikel angelegt.' : 'Artikel gespeichert.', 'erfolg');
      } });
    f.bezeichnung.focus();
  }

  /** Artikelgruppe anlegen oder ändern: eine Funktion, kein Produkt – ohne GTIN, Charge und eigenen Bestand (LAGER.md Abschnitt 17). */
  function gruppeDialog(g) {
    const neu = !g;
    // Vorschlag aus dem gewählten Artikel (MA7) – nur, wenn er selbst in eine Gruppe dürfte
    const z0 = neu ? zeile() : null;
    const vor = z0 && !z0.istMeta && !z0.meta_artikel && z0.art !== 'arzneimittel' && !z0.btm && z0.fuehrung !== 'serie' ? z0 : null;
    const f = {
      bezeichnung: h('input', { id: 'lg-m-bezeichnung', maxlength: '200', required: true, value: g?.bezeichnung || '', placeholder: 'z. B. Einmalhandschuh Nitril, Größe M' }),
      einheit: h('input', { id: 'lg-m-einheit', maxlength: '20', class: 'lg-kurz', required: true, value: g?.einheit || vor?.einheit || 'Stück' }),
      mindest: h('input', { id: 'lg-m-mindest', class: 'lg-kurz', inputmode: 'decimal', value: g ? (g.mindest ?? '') : (vor?.mindest ?? '') }),
      soll: h('input', { id: 'lg-m-soll', class: 'lg-kurz', inputmode: 'decimal', value: g ? (g.soll ?? '') : (vor?.soll ?? '') }),
      still: h('input', { type: 'checkbox', id: 'lg-m-still', checked: g?.stillgelegt || false }),
    };
    const hat = g && g.mitglieder.length > 0;
    sperren(f.einheit, hat ? 'Die Lagereinheit steht fest, solange die Gruppe Artikel hat' : null);
    sperren(f.still, neu ? 'Erst nach dem Anlegen' : hat ? 'Die Gruppe hat noch Artikel – erst dort die Zuordnung lösen' : null);
    dialog({ id: 'lg-dialog-gruppe', breit: true, titel: neu ? 'Artikelgruppe anlegen' : 'Artikelgruppe bearbeiten', ja: neu ? 'Anlegen' : 'Speichern',
      text: 'Eine Artikelgruppe fasst Artikel gleicher Funktion zusammen, die einander ersetzen. Sie hat keinen eigenen Bestand: Gezählt wird die Summe ihrer Artikel, entnommen wird immer ein konkreter Artikel mit seiner Charge.',
      felder: [h('div', { class: 'lg-reihe' }, feld('Bezeichnung', f.bezeichnung), feld('Lagereinheit', f.einheit, 'gilt für alle Artikel der Gruppe')),
        h('div', { class: 'lg-reihe' }, feld('Mindestbestand', f.mindest, 'über alle Artikel – darunter wird nachbestellt'), feld('Sollbestand', f.soll, 'bis dahin wird nachbestellt')),
        h('p', { class: 'hinweis', id: 'lg-m-mitglieder' }, neu ? 'Artikel ordnest du danach zu: Artikel öffnen und dort die Artikelgruppe wählen.'
          : hat ? `Artikel der Gruppe: ${g.mitglieder.map((x) => x.bezeichnung).join(' · ')}` : 'Noch kein Artikel zugeordnet.'),
        h('label', { class: 'feld-wahl', title: 'Eine stillgelegte Artikelgruppe nimmt keine Artikel mehr auf; gelöscht wird nichts' }, f.still, 'stillgelegt')],
      senden: async () => {
        const mengeOderLeer = (el) => (el.value.trim() === '' ? null : (mengeLesen(el.value) ?? (/^0([.,]0+)?$/.test(el.value.trim()) ? '0' : undefined)));
        const mindest = mengeOderLeer(f.mindest);
        const soll = mengeOderLeer(f.soll);
        if (mindest === undefined) throw new Error('Mindestbestand: eine Zahl mit höchstens drei Nachkommastellen');
        if (soll === undefined) throw new Error('Sollbestand: eine Zahl mit höchstens drei Nachkommastellen');
        if (soll !== null && mindest !== null && Number(soll) < Number(mindest)) throw new Error('Der Sollbestand liegt unter dem Mindestbestand');
        const x = { bezeichnung: f.bezeichnung.value, einheit: f.einheit.value, mindest, soll, stillgelegt: f.still.checked };
        const r = neu ? await k.api('POST', `${basis}/meta`, { json: x }) : await k.api('PUT', `${basis}/meta/${g.id}`, { json: x });
        artikelId = r.id; suche.value = ''; filter = 'alle';
        await laden(); k.melden(neu ? 'Artikelgruppe angelegt.' : 'Artikelgruppe gespeichert.', 'erfolg');
      } });
    f.bezeichnung.focus();
  }

  function orteDialog() {
    const name = h('input', { id: 'lg-o-name', maxlength: '80', placeholder: 'z. B. Behandlung 1, Schrank 2' });
    const kalt = h('input', { type: 'checkbox', id: 'lg-o-kalt' });
    const liste2 = h('div', { class: 'lg-orte', id: 'lg-o-liste' });
    let fehlerEl = null;
    const zeigenOrte = () => liste2.replaceChildren(...(d.orte.length ? d.orte.map((o) => h('div', {}, h('span', {}, o.name, o.gekuehlt ? h('span', { class: 'marke-klein korrigiert' }, 'gekühlt') : null, o.stillgelegt ? h('span', { class: 'marke-klein' }, 'stillgelegt') : null),
      knopf(o.stillgelegt ? 'Wieder nutzen' : 'Stilllegen', async () => {
        try { await k.api('PUT', `${basis}/orte/${o.id}`, { json: { name: o.name, gekuehlt: o.gekuehlt, stillgelegt: !o.stillgelegt } }); await laden(); zeigenOrte(); fehlerEl.textContent = ' '; } catch (x) { fehlerEl.textContent = x.problem?.detail || x.message; }
      }, 'klein', { title: 'Ein Lagerort mit Bestand lässt sich nicht stilllegen; gelöscht wird nichts' }))) : [h('p', { class: 'hinweis' }, 'Noch kein Lagerort.')]));
    const r = dialog({ id: 'lg-dialog-orte', titel: 'Lagerorte', ohneJa: true,
      felder: [liste2, h('div', { class: 'lg-reihe' }, feld('Neuer Lagerort', name), h('label', { class: 'feld-wahl' }, kalt, 'gekühlt'),
        knopf('Anlegen', async () => {
          try { await k.api('POST', `${basis}/orte`, { json: { name: name.value, gekuehlt: kalt.checked } }); name.value = ''; kalt.checked = false; await laden(); zeigenOrte(); fehlerEl.textContent = ' '; name.focus(); } catch (x) {
            fehlerEl.textContent = (x.problem?.fehler || []).map((f) => f.meldung).join(' · ') || x.problem?.detail || x.message;
          }
        }, 'primaer', { id: 'lg-o-anlegen' }))] });
    fehlerEl = r.fehler;
    name.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); document.getElementById('lg-o-anlegen').click(); } });
    zeigenOrte(); name.focus();
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
