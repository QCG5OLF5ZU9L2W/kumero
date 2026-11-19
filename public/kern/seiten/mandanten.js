// SPDX-License-Identifier: AGPL-3.0-or-later
// Mandanten (Bereich Verwaltung, nur System-Verwalter – VERWALTUNG.md Abschnitt 5 und 14, F118): anlegen
// (= ersten Administrator einsetzen), stilllegen, exportieren, löschen. Die Seite schreibt Aufträge; ausgeführt werden
// sie vom Dienst auf dem Server. Der System-Verwalter sieht keinen Mandanten von innen.
import { h, seitenkopf, tabelle, knopf, feld, sperren, zeit } from '../ui/bausteine.js';

const ART = { mandant_anlegen: 'anlegen', mandant_exportieren: 'exportieren', mandant_loeschen: 'löschen' };
const ZUSTAND = { offen: 'wartet auf den Dienst', laeuft: 'läuft', erledigt: 'erledigt', fehlgeschlagen: 'fehlgeschlagen' };
const FEHLER = {
  'export.verzeichnis': 'Das Verzeichnis für Sicherungen lässt sich nicht anlegen',
  'export.pg_dump_fehlt': 'Auf dem Server fehlt das Programm pg_dump',
  'export.pg_dump': 'pg_dump ist gescheitert',
  'loeschen.nicht_stillgelegt': 'Der Mandant ist nicht stillgelegt',
  'auftrag.abgebrochen': 'Der Dienst hat den Auftrag nicht beendet',
  'auftrag.fehler': 'Fehler bei der Ausführung – Einzelheiten stehen im Log des Dienstes',
};
const groesse = (n) => (n >= 1048576 ? `${(n / 1048576).toFixed(1).replace('.', ',')} MB` : `${Math.max(1, Math.round(n / 1024))} KB`);

export async function zeigen(b, k) {
  const basis = '/api/v1/verwaltung/mandanten';
  let daten = { mandanten: [], auftraege: [] };
  let gewaehlt = null;
  let wartet = null;

  // ── Werkzeugleiste: Aktionen gelten für die gewählte Zeile; gesperrte Knöpfe nennen den Grund (F106) ──
  const knStill = knopf('Stilllegen', () => tun('stilllegen', 'Mandant stillgelegt. Niemand kann sich dort mehr anmelden.'), '', { id: 'mandant-stilllegen' });
  const knReakt = knopf('Reaktivieren', () => tun('reaktivieren', 'Mandant wieder in Betrieb.'), '', { id: 'mandant-reaktivieren' });
  const knExport = knopf('Exportieren', () => tun('export', 'Export beauftragt. Der Dienst legt die Sicherung auf dem Server ab.'), '', { id: 'mandant-exportieren' });
  const knLoeschen = knopf('Löschen …', loeschen, 'gefahr', { id: 'mandant-loeschen' });
  const stand = h('span', { class: 'hinweis', id: 'mandanten-stand' });
  const liste = h('div', { id: 'mandanten-liste' });
  const auftraege = h('div', { id: 'auftrags-liste' });

  // ── Anlegen: Mandant und erster Administrator in einem Vorgang (U5) ──
  const f = {
    kennung: h('input', { id: 'neu-kennung', required: true, maxlength: '32', size: '16', pattern: '[a-z][a-z0-9\\-]{1,31}', spellcheck: false, autocomplete: 'off',
      title: 'Steht in der Adresse: Kleinbuchstaben, Ziffern, Bindestrich; beginnt mit einem Buchstaben' }),
    name: h('input', { id: 'neu-name', required: true, maxlength: '100', size: '32', autocomplete: 'off' }),
    vornamen: h('input', { id: 'neu-vornamen', required: true, maxlength: '100', size: '20', autocomplete: 'off' }),
    familienname: h('input', { id: 'neu-familienname', required: true, maxlength: '100', size: '20', autocomplete: 'off' }),
    email: h('input', { id: 'neu-email', type: 'email', required: true, maxlength: '200', size: '32', autocomplete: 'off' }),
  };
  const zugangLink = h('input', { id: 'zugang-link', readonly: true, size: '70', value: '', placeholder: 'Der Link erscheint hier nach dem Anlegen – einmal.', spellcheck: false });
  const zugangText = h('p', { class: 'hinweis', id: 'zugang-hinweis' }, 'Noch kein Zugang ausgestellt.');
  const knKopieren = knopf('Kopieren', async () => { await navigator.clipboard.writeText(zugangLink.value); k.melden('Link in die Zwischenablage kopiert.', 'erfolg'); }, '', { id: 'zugang-kopieren' });
  sperren(knKopieren, 'Es ist kein Link ausgestellt');
  const formular = h('form', { id: 'mandant-anlegen', onsubmit: async (ev) => {
    ev.preventDefault();
    try {
      const r = await k.api('POST', basis, { json: { kennung: f.kennung.value.trim(), name: f.name.value.trim(),
        administrator: { vornamen: f.vornamen.value.trim(), familienname: f.familienname.value.trim(), email: f.email.value.trim() } } });
      zugangLink.value = r.link;
      zugangText.textContent = `Zugang für den Administrator von „${r.kennung}“. Er gilt ${r.gueltig_stunden} Stunden, sobald der Mandant angelegt ist, `
        + 'und lässt sich genau einmal verwenden. kumero zeigt ihn nicht wieder – gib ihn jetzt auf sicherem Weg weiter.';
      sperren(knKopieren, null);
      for (const x of Object.values(f)) x.value = '';
      k.melden('Mandant wird angelegt.', 'erfolg');
      await laden();
    } catch (x) { k.fehler(x); }
  } },
  h('div', { class: 'zeile' }, feld('Kennung', f.kennung), feld('Name des Mandanten', f.name)),
  h('div', { class: 'zeile' }, feld('Administrator: Vorname(n)', f.vornamen), feld('Familienname', f.familienname), feld('E-Mail', f.email)),
  h('div', { class: 'zeile' }, h('button', { type: 'submit', class: 'knopf primaer', id: 'mandant-anlegen-knopf' }, 'Mandant anlegen')));

  b.append(seitenkopf('Mandanten', knStill, knReakt, knExport, knLoeschen, stand),
    liste,
    h('div', { class: 'karte', id: 'karte-anlegen' }, h('h2', {}, 'Mandant anlegen'),
      h('p', { class: 'hinweis' }, 'Mit dem Mandanten wird sein erster Administrator eingesetzt. Der Administrator pflegt danach Nutzer und Rollen; '
        + 'Buchungsdaten sieht er nicht. Du selbst siehst als System-Verwalter keinen Mandanten von innen.'),
      formular),
    h('div', { class: 'karte', id: 'karte-zugang' }, h('h2', {}, 'Zugang des Administrators'), zugangText,
      h('div', { class: 'zeile' }, zugangLink, knKopieren)),
    h('div', { class: 'karte', id: 'karte-auftraege' }, h('h2', {}, 'Aufträge'),
      h('p', { class: 'hinweis' }, 'Anlegen, Exportieren und Löschen führt ein Dienst auf dem Server aus. Die Sicherung eines Exports liegt dort '
        + 'in einem geschützten Verzeichnis; sie enthält alle Daten des Mandanten und wird nicht im Browser angeboten.'),
      auftraege));

  const zustandText = (m) => {
    if (m.auftrag) return `wird ${m.auftrag === 'mandant_anlegen' ? 'angelegt' : (m.auftrag === 'mandant_loeschen' ? 'gelöscht' : 'exportiert')} …`;
    return m.stillgelegt_am ? `stillgelegt seit ${zeit(m.stillgelegt_am)}` : 'in Betrieb';
  };

  function knoepfe() {
    const m = daten.mandanten.find((x) => x.kennung === gewaehlt) || null;
    const kein = m ? null : 'Erst einen Mandanten in der Liste wählen';
    const beschaeftigt = m?.auftrag ? 'Für diesen Mandanten läuft gerade ein Auftrag' : null;
    sperren(knStill, kein || beschaeftigt || (m.stillgelegt_am ? 'Der Mandant ist schon stillgelegt' : null));
    sperren(knReakt, kein || beschaeftigt || (m.stillgelegt_am ? null : 'Der Mandant ist in Betrieb'));
    sperren(knExport, kein || beschaeftigt);
    sperren(knLoeschen, kein || beschaeftigt || (!m.stillgelegt_am ? 'Erst stilllegen – nur ein stillgelegter Mandant lässt sich löschen'
      : (m.loeschbar ? null : 'Erst exportieren – die Sicherung muss nach dem Stilllegen entstanden sein')));
  }

  function zeichnen() {
    liste.replaceChildren(tabelle([
      { titel: 'Kennung', klasse: 'nr', wert: (m) => m.kennung },
      { titel: 'Name', wert: (m) => m.name || '' },
      { titel: 'Zustand', wert: (m) => h('span', { 'data-zustand': m.auftrag || (m.stillgelegt_am ? 'stillgelegt' : 'betrieb') }, zustandText(m)) },
      { titel: 'Angelegt', klasse: 'eng', wert: (m) => (m.angelegt_am ? zeit(m.angelegt_am) : '') },
      { titel: 'Letzter Export', wert: (m) => (m.export ? h('span', { title: `SHA-256 ${m.export.sha256}` },
        `${zeit(m.export.am)} · ${groesse(m.export.groesse)} · ${m.export.datei}`) : '') },
    ], { zeilen: daten.mandanten, leer: 'Noch kein Mandant.' }));
    // die Tabelle des Kerns kennt keine Auswahl: Zeilen selbst kennzeichnen
    [...liste.querySelectorAll('tbody tr')].forEach((tr, i) => {
      const m = daten.mandanten[i];
      if (!m) return;
      tr.dataset.kennung = m.kennung;
      tr.tabIndex = 0;
      tr.classList.toggle('gewaehlt', m.kennung === gewaehlt);
      tr.setAttribute('aria-selected', m.kennung === gewaehlt ? 'true' : 'false');
      const waehlen = () => { gewaehlt = m.kennung; zeichnen(); };
      tr.addEventListener('click', waehlen);
      tr.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); waehlen(); } });
    });
    auftraege.replaceChildren(tabelle([
      { titel: 'Angelegt', klasse: 'eng', wert: (x) => zeit(x.angelegt_am) },
      { titel: 'Auftrag', klasse: 'eng', wert: (x) => ART[x.art] || x.art },
      { titel: 'Mandant', klasse: 'nr', wert: (x) => x.mandant },
      { titel: 'Zustand', wert: (x) => h('span', { 'data-zustand': x.zustand }, ZUSTAND[x.zustand] || x.zustand,
        x.zustand === 'fehlgeschlagen' ? `: ${FEHLER[x.ergebnis?.code] || x.ergebnis?.code || ''}` : '') },
      { titel: 'Ergebnis', wert: (x) => (x.ergebnis?.datei ? h('span', { title: `SHA-256 ${x.ergebnis.sha256}` }, `${x.ergebnis.datei} · ${groesse(x.ergebnis.groesse)}`) : '') },
    ], { zeilen: daten.auftraege, leer: 'Noch keine Aufträge.' }));
    const offen = daten.auftraege.filter((x) => x.zustand === 'offen' || x.zustand === 'laeuft').length;
    stand.textContent = offen ? `${offen} Auftrag${offen === 1 ? '' : 'e'} in Arbeit …` : '';
    knoepfe();
    // solange der Dienst arbeitet, alle drei Sekunden nachsehen – nur während die Seite gezeigt wird
    clearTimeout(wartet);
    if (offen && b.isConnected) wartet = setTimeout(() => { if (b.isConnected) laden(); }, 3000);
  }

  async function laden() {
    try {
      daten = await k.api('GET', basis);
      if (gewaehlt && !daten.mandanten.some((m) => m.kennung === gewaehlt)) gewaehlt = null;
      zeichnen();
    } catch (x) { k.fehler(x); }
  }

  async function tun(was, meldung) {
    if (!gewaehlt) return;
    try {
      await k.api('POST', `${basis}/${gewaehlt}/${was}`, { json: {} });
      k.melden(meldung, 'erfolg');
      await laden();
    } catch (x) { k.fehler(x); }
  }

  /** Löschen ist nicht umkehrbar: Die Kennung muss eingetippt werden. */
  function loeschen() {
    const m = daten.mandanten.find((x) => x.kennung === gewaehlt);
    if (!m) return;
    const eingabe = h('input', { id: 'loeschen-kennung', autocomplete: 'off', spellcheck: false, size: '20' });
    const ja = knopf('Endgültig löschen', async () => {
      d.close();
      try {
        await k.api('DELETE', `${basis}/${m.kennung}`, { json: { bestaetigung: eingabe.value.trim() } });
        k.melden('Löschen beauftragt.', 'erfolg');
        await laden();
      } catch (x) { k.fehler(x); }
    }, 'gefahr', { id: 'loeschen-bestaetigen' });
    sperren(ja, 'Zur Bestätigung die Kennung eintippen');
    eingabe.addEventListener('input', () => sperren(ja, eingabe.value.trim() === m.kennung ? null : 'Zur Bestätigung die Kennung eintippen'));
    const d = h('dialog', { class: 'dialog', id: 'loeschen-dialog' },
      h('h2', {}, `Mandant „${m.name}“ löschen`),
      h('p', {}, 'Die Datenbank des Mandanten wird entfernt – mit Buch, Belegverweisen, Nutzern, Passkeys und Rollen. Das lässt sich nicht rückgängig machen.'),
      h('p', {}, `Letzter Export: ${m.export ? `${zeit(m.export.am)}, ${m.export.datei}` : 'keiner'}. `
        + 'Sicherungen des Servers und die Siegelkopien außer Haus löscht kumero nicht.'),
      h('p', {}, h('label', { for: 'loeschen-kennung' }, `Zur Bestätigung die Kennung eintippen: ${m.kennung}`)), eingabe,
      h('div', { class: 'dialog-knoepfe' }, knopf('Abbrechen', () => d.close(), '', { id: 'loeschen-abbrechen' }), ja));
    d.addEventListener('close', () => d.remove());
    document.body.append(d);
    d.showModal();
    eingabe.focus();
  }

  await laden();
}
