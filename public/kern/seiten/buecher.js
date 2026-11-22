// SPDX-License-Identifier: AGPL-3.0-or-later
// „Bücher“ (Bereich Mandant – BUECHER.md Abschnitt 8 und 11; F123, F126): die Bücher des Mandanten. Jedes Buch ist eine
// eigenständige Buchhaltung mit eigener Datenbank. Anlegen und umbenennen darf der Administrator; angelegt wird über
// einen Auftrag, den der Dienst auf dem Server ausführt. Kopf und Werkzeugleiste stehen fest, gesperrte Knöpfe nennen
// den Grund (F106).
import { h, seitenkopf, tabelle, knopf, feld, auswahl, sperren, zeit } from '../ui/bausteine.js';

const ART = { euer: 'Einnahmenüberschussrechnung', bilanz: 'Bilanz' };
const FEHLER = { 'buch.vorhanden': 'Das Buch oder seine Datenbank gibt es schon', 'auftrag.fehler': 'Fehler bei der Ausführung – Einzelheiten stehen im Log des Dienstes',
  'auftrag.abgebrochen': 'Der Dienst hat den Auftrag nicht beendet' };

export async function zeigen(b, k) {
  b.classList.add('listenseite');
  const basis = `${k.mandantApi}/buecher`;
  let d = { buecher: [], administrator: false, auftraege: [] };
  let gewaehlt = k.buch;
  let wartet = null;

  const knNeu = knopf('Buch anlegen …', anlegenDialog, 'primaer', { id: 'bu-anlegen', title: 'Legt eine weitere, eigenständige Buchhaltung in diesem Mandanten an' });
  const knName = knopf('Umbenennen …', nameDialog, '', { id: 'bu-umbenennen', title: 'Ändert den Namen; die Kennung bleibt' });
  const knOeffnen = knopf('In diesem Buch arbeiten', () => { if (gewaehlt) k.navigieren(`${k.buchSeiten(gewaehlt)}/kern/konten`); }, '', { id: 'bu-oeffnen', title: 'Wechselt in das gewählte Buch' });
  const stand = h('span', { class: 'hinweis', id: 'bu-stand' });
  const liste = h('div', { id: 'bu-liste', class: 'listen-rollen' });
  b.append(seitenkopf('Bücher', knNeu, knName, knOeffnen, stand),
    h('p', { class: 'hinweis' }, 'Jedes Buch ist eine eigene Buchhaltung mit eigenem Journal, eigenen Konten, Rollen, Modulen und Siegeln. '
      + 'Hinein kommt, wer dort eine Rolle hat. Ein Testbuch ist dauerhaft als solches gekennzeichnet und wird nie produktiv.'),
    liste);

  function knoepfe() {
    const admin = d.administrator ? null : 'Das darf nur ein Administrator des Mandanten';
    const x = d.buecher.find((y) => y.kennung === gewaehlt) || null;
    const kein = x ? null : 'Erst ein Buch in der Liste wählen';
    const laeuft = d.auftraege.some((a) => a.zustand === 'offen' || a.zustand === 'laeuft') ? 'Es wird gerade ein Buch angelegt' : null;
    sperren(knNeu, admin || laeuft);
    sperren(knName, admin || kein);
    sperren(knOeffnen, kein || (x.zugang || d.administrator ? null : 'In diesem Buch hast du keine Rolle'));
  }

  function zeichnen() {
    liste.replaceChildren(tabelle([
      { titel: 'Kennung', klasse: 'nr', wert: (x) => x.kennung },
      { titel: 'Name', wert: (x) => x.name },
      { titel: 'Art', wert: (x) => ART[x.art] || x.art },
      { titel: 'Zustand', wert: (x) => h('span', { 'data-zustand': x.testbuch ? 'testbuch' : x.betriebsart },
        (x.testbuch ? 'Testbuch' : (x.betriebsart === 'test' ? 'Testbetrieb' : 'Produktivbetrieb')) + (x.lesemodus ? ' · Lesemodus' : '')) },
      { titel: 'Übernommen aus', wert: (x) => (x.kopie_von ? `${x.kopie_von}, Stand ${zeit(x.kopie_stand)}` : '') },
      { titel: 'Deine Rolle', klasse: 'eng', wert: (x) => (x.zugang ? 'ja' : 'keine') },
    ], { zeilen: d.buecher, leer: 'Noch kein Buch. Der Administrator legt das erste an.',
      fuss: d.buecher.length === 1 ? '1 Buch' : `${d.buecher.length} Bücher` }));
    [...liste.querySelectorAll('tbody tr')].forEach((tr, i) => {
      const x = d.buecher[i];
      if (!x) return;
      tr.dataset.kennung = x.kennung;
      tr.tabIndex = 0;
      tr.classList.toggle('gewaehlt', x.kennung === gewaehlt);
      tr.setAttribute('aria-selected', x.kennung === gewaehlt ? 'true' : 'false');
      const waehlen = () => { gewaehlt = x.kennung; zeichnen(); };
      tr.addEventListener('click', waehlen);
      tr.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); waehlen(); } });
    });
    const offen = d.auftraege.find((a) => a.zustand === 'offen' || a.zustand === 'laeuft');
    const letzter = d.auftraege[0];
    stand.textContent = offen ? 'Ein Buch wird angelegt – der Dienst auf dem Server arbeitet …'
      : (letzter?.zustand === 'fehlgeschlagen' ? `Letzter Auftrag fehlgeschlagen: ${FEHLER[letzter.ergebnis?.code] || letzter.ergebnis?.code || ''}` : '');
    knoepfe();
    clearTimeout(wartet);
    if (offen && b.isConnected) wartet = setTimeout(() => { if (b.isConnected) laden(true); }, 3000);
  }

  async function laden(nachAuftrag = false) {
    try {
      const vorher = d.buecher.length;
      d = await k.api('GET', basis);
      if (!d.buecher.some((x) => x.kennung === gewaehlt)) gewaehlt = null;
      zeichnen();
      // ein neues Buch gehört in die Auswahl im Kopf
      if (nachAuftrag && d.buecher.length !== vorher) await k.buecherNeuLaden();
    } catch (x) { k.fehler(x); }
  }

  function dialog(o) {
    const ja = h('button', { type: 'submit', class: 'knopf primaer', id: `${o.id}-senden` }, o.ja);
    const fehler = h('p', { class: 'hinweis fehlertext', id: `${o.id}-fehler`, role: 'alert' }, '\u00a0');
    const dlg = h('dialog', { class: 'dialog', id: o.id },
      h('form', { onsubmit: async (ev) => {
        ev.preventDefault();
        ja.disabled = true;
        try { await o.senden(); dlg.close(); } catch (x) {
          fehler.textContent = (x.problem?.fehler || []).map((f) => f.meldung).join(' · ') || x.message || 'Das hat nicht geklappt';
        } finally { ja.disabled = false; }
      } },
      h('h2', {}, o.titel), o.text ? h('p', {}, o.text) : null, ...o.felder, fehler,
      h('div', { class: 'dialog-knoepfe' }, knopf('Abbrechen', () => dlg.close(), '', { id: `${o.id}-abbrechen` }), ja)));
    dlg.addEventListener('close', () => dlg.remove());
    document.body.append(dlg);
    dlg.showModal();
  }

  function anlegenDialog() {
    const kennung = h('input', { id: 'bu-f-kennung', required: true, maxlength: '20', size: '20', pattern: '[a-z][a-z0-9\\-]{1,19}', spellcheck: false, autocomplete: 'off',
      title: 'Steht in der Adresse: Kleinbuchstaben, Ziffern, Bindestrich; 2 bis 20 Zeichen; beginnt mit einem Buchstaben. Lässt sich später nicht ändern.' });
    const name = h('input', { id: 'bu-f-name', required: true, maxlength: '100', size: '36', autocomplete: 'off' });
    const art = auswahl(Object.entries(ART), 'euer', { id: 'bu-f-art' });
    const test = h('input', { type: 'checkbox', id: 'bu-f-testbuch' });
    dialog({ id: 'bu-dialog-anlegen', titel: 'Buch anlegen', ja: 'Anlegen',
      text: 'Das Buch bekommt eine eigene Datenbank; angelegt wird es von einem Dienst auf dem Server, das dauert einige Sekunden.',
      felder: [feld('Kennung', kennung), feld('Name', name), feld('Art der Gewinnermittlung', art, 'Gebucht wird in jedem Buch doppelt; die Art sagt, was am Ende daraus wird.'),
        h('label', { class: 'nu-wahl' }, test, ' Testbuch – zum Testen und Spielen; dauerhaft gekennzeichnet, wird nie produktiv')],
      senden: async () => {
        await k.api('POST', basis, { json: { kennung: kennung.value.trim(), name: name.value.trim(), art: art.value, testbuch: test.checked } });
        k.melden('Buch beauftragt.', 'erfolg');
        await laden();
      } });
    kennung.focus();
  }

  function nameDialog() {
    const x = d.buecher.find((y) => y.kennung === gewaehlt);
    if (!x) return;
    const name = h('input', { id: 'bu-f-neuer-name', required: true, maxlength: '100', size: '36', autocomplete: 'off', value: x.name });
    dialog({ id: 'bu-dialog-name', titel: `Buch „${x.kennung}“ umbenennen`, ja: 'Speichern',
      felder: [feld('Name', name)],
      senden: async () => {
        await k.api('PATCH', `${basis}/${x.kennung}`, { json: { name: name.value.trim() } });
        k.melden('Name geändert.', 'erfolg');
        await laden();
        await k.buecherNeuLaden();
      } });
    name.focus();
  }

  await laden();
  return () => clearTimeout(wartet);
}
