// SPDX-License-Identifier: AGPL-3.0-or-later
// „Nutzer und Rollen“ (Bereich Mandant, für Administratoren – VERWALTUNG.md Abschnitt 6, 8 und 15, BUECHER.md; F125, F126):
// Nutzer anlegen und ändern, Zugang ausstellen (Link **und** Code), Passkey widerrufen, sperren, Administratoren
// einsetzen und abberufen – das gehört zum Mandanten. Rollen zuweisen und beenden gilt für das im Kopf gewählte Buch;
// Rollen am Mandanten (F135) gibt es, sobald dort ein Modul aktiv ist – sie stehen dann in einer eigenen Spalte und in derselben Auswahl. Der Kopf der Liste steht fest, Knöpfe werden gesperrt statt ausgeblendet und nennen den Grund (F106).
// Sperren, Entsperren und „Rolle zuweisen“ gelten für die markierten Einträge, ohne Haken für die gewählte Zeile (F107).
import { h, seitenkopf, tabelle, knopf, feld, auswahl, sperren, zeit, datumText, datumFeld } from '../ui/bausteine.js';

const STUNDEN = [['24', '24 Stunden'], ['12', '12 Stunden'], ['4', '4 Stunden'], ['1', '1 Stunde']];

export async function zeigen(b, k) {
  b.classList.add('listenseite');
  const basis = `${k.mandantApi}/nutzer`;
  const zuweisungenApi = k.buchApi ? `${k.buchApi}/zuweisungen` : null;
  const mandantZuweisungenApi = `${k.mandantApi}/zuweisungen`;
  let d = { nutzer: [], rollen: [], ich: null };
  /** Rollen gibt es im gewählten Buch und, mit einem Modul am Mandanten, am Mandanten selbst */
  const amMandanten = () => d.rollen.some((r) => r.ebene === 'mandant');
  const keineRollen = () => (d.rollen.length ? null : (zuweisungenApi ? 'Es gibt noch keine Rolle' : 'Dafür braucht es ein Buch – Rollen gelten je Buch'));
  const ort = (ebene) => (ebene === 'mandant' ? 'am Mandanten' : `im Buch „${k.buchName}“`);
  const rollenText = (liste) => liste.map((z) => z.rolle + (z.gueltig_bis ? ` (bis ${datumText(endTag(z.gueltig_bis))})` : '')).join(', ');
  let gewaehlt = null;
  const markiert = new Set();
  const darf = k.darf('mandant.administrator') ? null : 'Das darf nur ein Administrator des Mandanten';

  const kn = {
    neu: knopf('Nutzer anlegen …', anlegenDialog, 'primaer', { id: 'nu-anlegen', title: 'Legt eine Identität in diesem Mandanten an' }),
    aendern: knopf('Bearbeiten …', aendernDialog, '', { id: 'nu-aendern', title: 'Name und E-Mail-Adresse ändern' }),
    zugang: knopf('Zugang ausstellen …', zugangDialog, '', { id: 'nu-zugang', title: 'Stellt Link und Code aus, mit denen die Person einmal einen Passkey einrichtet' }),
    rolle: knopf('Rolle zuweisen …', rolleDialog, '', { id: 'nu-rolle', title: 'Weist den markierten Nutzern eine Rolle zu – in diesem Buch oder am Mandanten –, auf Wunsch befristet' }),
    rolleEnde: knopf('Rolle beenden …', rolleEndeDialog, '', { id: 'nu-rolle-ende', title: 'Beendet eine Zuweisung sofort' }),
    admin: knopf('Als Administrator einsetzen', () => adminSetzen(true), '', { id: 'nu-admin', title: 'Administratoren pflegen Nutzer, Bücher und Rollen des Mandanten; Buchungsdaten sehen sie nicht' }),
    adminEnde: knopf('Abberufen', () => adminSetzen(false), '', { id: 'nu-admin-ende', title: 'Nimmt das Amt des Administrators zurück' }),
    passkey: knopf('Passkey widerrufen …', passkeyDialog, '', { id: 'nu-passkey', title: 'Mit einem widerrufenen Passkey meldet sich niemand mehr an' }),
    sperren: knopf('Sperren', () => aktivSetzen(false), '', { id: 'nu-sperren', title: 'Gesperrte Nutzer können sich nicht anmelden; ihre Sitzungen enden sofort' }),
    entsperren: knopf('Entsperren', () => aktivSetzen(true), '', { id: 'nu-entsperren', title: 'Hebt die Sperre auf' }),
  };
  const liste = h('div', { id: 'nu-liste', class: 'listen-rollen' });
  b.append(seitenkopf('Nutzer und Rollen', ...Object.values(kn)),
    h('p', { class: 'hinweis', id: 'nu-hinweis' }, 'Nutzer gehören zu diesem Mandanten. Angemeldet wird nur mit einem Passkey; wer neu ist oder seinen Passkey verloren hat, '
      + 'bekommt einen Zugang aus Link und Code. Was jemand in einem Buch darf, sagen allein seine Rollen dort – '
      + (k.buch ? `die Spalte „Rollen“ und die beiden Knöpfe dazu gelten für das Buch „${k.buchName}“, das oben gewählt ist.` : 'es gibt noch kein Buch.')),
    liste);

  const name = (n) => [n.familienname, n.vornamen].filter(Boolean).join(', ');
  const anzeige = (n) => [n.vornamen, n.familienname].filter(Boolean).join(' ');
  const einer = () => {
    if (markiert.size === 1) return d.nutzer.find((n) => markiert.has(n.id)) || null;
    return markiert.size === 0 ? d.nutzer.find((n) => n.id === gewaehlt) || null : null;
  };
  /** Die markierten Einträge, ohne Haken die gewählte Zeile */
  const auswahlListe = () => {
    const m = d.nutzer.filter((n) => markiert.has(n.id));
    if (m.length) return m;
    const g = d.nutzer.find((n) => n.id === gewaehlt);
    return g ? [g] : [];
  };

  function knoepfe() {
    const n = einer();
    const viele = auswahlListe();
    const keiner = viele.length ? null : 'Erst einen Nutzer in der Liste wählen oder markieren';
    const genauEiner = n ? null : (markiert.size > 1 ? 'Das geht nur für einen Nutzer – nur einen markieren' : 'Erst einen Nutzer in der Liste wählen');
    sperren(kn.neu, darf);
    sperren(kn.aendern, darf || genauEiner);
    sperren(kn.zugang, darf || genauEiner || (n.aktiv ? null : 'Der Nutzer ist gesperrt – erst entsperren'));
    sperren(kn.rolle, darf || keineRollen() || keiner);
    sperren(kn.rolleEnde, darf || keineRollen() || genauEiner || (n.zuweisungen.length ? null : (amMandanten() ? 'Der Nutzer hat keine Rolle' : 'Der Nutzer hat in diesem Buch keine Rolle')));
    sperren(kn.admin, darf || genauEiner || (n.administrator ? 'Ist schon Administrator' : (n.aktiv ? null : 'Der Nutzer ist gesperrt – erst entsperren')));
    sperren(kn.adminEnde, darf || genauEiner || (n.administrator ? null : 'Ist kein Administrator'));
    sperren(kn.passkey, darf || genauEiner || (n.passkeys.length ? null : 'Der Nutzer hat keinen Passkey'));
    sperren(kn.sperren, darf || keiner || (viele.some((x) => x.aktiv) ? null : 'Schon gesperrt'));
    sperren(kn.entsperren, darf || keiner || (viele.some((x) => !x.aktiv) ? null : 'Nicht gesperrt'));
  }

  function zeichnen() {
    const alle = h('input', { type: 'checkbox', id: 'nu-alle', title: 'Alle markieren', 'aria-label': 'Alle markieren',
      checked: d.nutzer.length > 0 && d.nutzer.every((n) => markiert.has(n.id)) ? '' : null });
    alle.addEventListener('change', () => { markiert.clear(); if (alle.checked) d.nutzer.forEach((n) => markiert.add(n.id)); zeichnen(); });
    liste.replaceChildren(tabelle([
      { titel: alle, klasse: 'eng', wert: (n) => {
        const c = h('input', { type: 'checkbox', class: 'nu-haken', 'aria-label': `${anzeige(n)} markieren`, checked: markiert.has(n.id) ? '' : null });
        c.addEventListener('click', (ev) => ev.stopPropagation());
        c.addEventListener('change', () => { if (c.checked) markiert.add(n.id); else markiert.delete(n.id); zeichnen(); });
        return c;
      } },
      { titel: 'Name', wert: (n) => name(n) + (n.id === d.ich ? ' (du)' : '') },
      { titel: 'E-Mail', wert: (n) => n.email || '' },
      { titel: 'Administrator', klasse: 'eng', wert: (n) => (n.administrator ? h('span', { 'data-zustand': 'administrator' }, 'ja') : '') },
      { titel: k.buch ? `Rollen im Buch „${k.buchName}“` : 'Rollen', wert: (n) => {
        const im = n.zuweisungen.filter((z) => z.ebene === 'buch');
        return im.length ? rollenText(im) : h('span', { class: 'hinweis' }, 'keine Rolle');
      } },
      // nur mit einem Modul am Mandanten: Die Spalte steht dann von Anfang an, sie kommt nicht beim Arbeiten dazu (F106)
      ...(amMandanten() ? [{ titel: 'Rollen am Mandanten', wert: (n) => {
        const am = n.zuweisungen.filter((z) => z.ebene === 'mandant');
        return am.length ? h('span', { class: 'nu-rollen-mandant' }, rollenText(am)) : h('span', { class: 'hinweis' }, 'keine Rolle');
      } }] : []),
      { titel: 'Zustand', klasse: 'eng', wert: (n) => h('span', { 'data-zustand': n.aktiv ? 'aktiv' : 'gesperrt' }, n.aktiv ? 'aktiv' : 'gesperrt') },
      { titel: 'Passkeys', klasse: 'nr', wert: (n) => h('span', { title: n.passkeys.map((p) => `${p.bezeichnung || 'ohne Bezeichnung'} · ${zeit(p.registriert_am)}`).join('\n') }, String(n.passkeys.length)) },
      { titel: 'Zugang', klasse: 'eng', wert: (n) => (n.zugang_bis ? h('span', { 'data-zustand': 'zugang' }, `offen bis ${zeit(n.zugang_bis)}`) : '') },
      { titel: 'Letzte Anmeldung', klasse: 'eng', wert: (n) => (n.letzte_anmeldung ? zeit(n.letzte_anmeldung) : 'noch nie') },
    ], { zeilen: d.nutzer, leer: 'Noch kein Nutzer.',
      fuss: `${d.nutzer.length === 1 ? '1 Nutzer' : `${d.nutzer.length} Nutzer`}${markiert.size ? ` · ${markiert.size} markiert` : ''}` }));
    [...liste.querySelectorAll('tbody tr')].forEach((tr, i) => {
      const n = d.nutzer[i];
      if (!n) return;
      tr.dataset.id = n.id;
      tr.tabIndex = 0;
      tr.classList.toggle('gewaehlt', n.id === gewaehlt);
      tr.setAttribute('aria-selected', n.id === gewaehlt ? 'true' : 'false');
      const waehlen = () => { gewaehlt = n.id; zeichnen(); };
      tr.addEventListener('click', (ev) => { if (!ev.target.closest('input')) waehlen(); });
      tr.addEventListener('keydown', (ev) => { if (ev.target === tr && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); waehlen(); } });
    });
    knoepfe();
  }

  /** „bis“ ist der letzte Tag der Rolle; gespeichert ist der Beginn des Tages danach. */
  function endTag(iso) {
    const t = new Date(new Date(iso).getTime() - 1000);
    return `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
  }

  async function laden() {
    try {
      const [nutzer, zb, zm] = await Promise.all([k.api('GET', basis), zuweisungenApi ? k.api('GET', zuweisungenApi) : { zuweisungen: [], rollen: [] },
        k.api('GET', mandantZuweisungenApi)]);
      // Nutzer kommen vom Mandanten, ihre Rollen aus dem gewählten Buch und – mit einem Modul dort – vom Mandanten
      // Schlüssel einer Rolle: im Buch ihr Name, am Mandanten mit Vorsatz – Rollennamen enthalten keinen Doppelpunkt
      const mit = (liste, ebene, api) => liste.map((x) => ({ ...x, ebene, api, schluessel: (ebene === 'mandant' ? 'mandant:' : '') + (x.name ?? x.rolle) }));
      const z = [...mit(zb.zuweisungen, 'buch', zuweisungenApi), ...mit(zm.zuweisungen, 'mandant', mandantZuweisungenApi)];
      d = { ...nutzer, rollen: [...mit(zb.rollen, 'buch', zuweisungenApi), ...mit(zm.rollen, 'mandant', mandantZuweisungenApi)],
        nutzer: nutzer.nutzer.map((n) => ({ ...n, zuweisungen: z.filter((x) => x.nutzer === n.id) })) };
      if (!d.nutzer.some((n) => n.id === gewaehlt)) gewaehlt = null;
      for (const id of [...markiert]) if (!d.nutzer.some((n) => n.id === id)) markiert.delete(id);
      zeichnen();
    } catch (x) { k.fehler(x); }
  }

  /** Dialog mit Formular: o.felder (Elemente), o.ja (Text), o.senden(); schließt erst nach Erfolg. */
  function dialog(o) {
    const ja = h('button', { type: 'submit', class: ['knopf', o.art || 'primaer'], id: `${o.id}-senden` }, o.ja);
    const fehler = h('p', { class: 'hinweis fehlertext', id: `${o.id}-fehler`, role: 'alert' }, ' ');
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
    return dlg;
  }

  function personFelder(n = {}) {
    return {
      vornamen: h('input', { id: 'nu-f-vornamen', required: true, maxlength: '200', size: '28', autocomplete: 'off', value: n.vornamen || '' }),
      familienname: h('input', { id: 'nu-f-familienname', required: true, maxlength: '200', size: '28', autocomplete: 'off', value: n.familienname || '' }),
      email: h('input', { id: 'nu-f-email', type: 'email', required: true, maxlength: '254', size: '36', autocomplete: 'off', value: n.email || '' }),
    };
  }

  function anlegenDialog() {
    const f = personFelder();
    const wahl = (ebene) => d.rollen.filter((r) => r.ebene === ebene).map((r) => h('label', { class: 'nu-wahl', title: r.beschreibung || '' },
      h('input', { type: 'checkbox', name: 'nu-f-rolle', value: r.schluessel }), ` ${r.name}`));
    const rollen = wahl('buch');
    const rollenMandant = wahl('mandant');
    const gleich = h('input', { type: 'checkbox', id: 'nu-f-zugang', checked: '' });
    dialog({ id: 'nu-dialog-anlegen', titel: 'Nutzer anlegen', ja: 'Anlegen',
      text: 'Die Person bekommt eine eigene Identität in diesem Mandanten. Über die E-Mail-Adresse erreichst du sie, wenn sie einen neuen Zugang braucht.',
      felder: [feld('Vorname(n)', f.vornamen), feld('Familienname', f.familienname), feld('E-Mail-Adresse', f.email),
        h('fieldset', { class: 'nu-gruppe' }, h('legend', {}, k.buch ? `Rollen im Buch „${k.buchName}“` : 'Rollen – erst mit einem Buch'), ...rollen),
        ...(rollenMandant.length ? [h('fieldset', { class: 'nu-gruppe', id: 'nu-f-rollen-mandant' }, h('legend', {}, 'Rollen am Mandanten'), ...rollenMandant)] : []),
        h('label', { class: 'nu-wahl' }, gleich, ' gleich einen Zugang ausstellen (Link und Code, 24 Stunden)')],
      senden: async () => {
        const r = await k.api('POST', basis, { json: { vornamen: f.vornamen.value.trim(), familienname: f.familienname.value.trim(), email: f.email.value.trim() } });
        // die Identität gehört zum Mandanten, die Rollen zum Buch: zwei Schritte
        for (const schluessel of [...rollen, ...rollenMandant].map((l) => l.querySelector('input')).filter((c) => c.checked).map((c) => c.value)) {
          const rolle = d.rollen.find((x) => x.schluessel === schluessel);
          await k.api('POST', rolle.api, { json: { nutzer: r.id, rolle: rolle.name } });
        }
        gewaehlt = r.id;
        markiert.clear();
        k.melden('Nutzer angelegt.', 'erfolg');
        const person = `${f.vornamen.value.trim()} ${f.familienname.value.trim()}`;
        let z = null;
        if (gleich.checked) z = await k.api('POST', `${basis}/${r.id}/zugang`, { json: {} });
        await laden();
        if (z) zugangZeigen(person, z);
      } });
    f.vornamen.focus();
  }

  function aendernDialog() {
    const n = einer();
    if (!n) return;
    const f = personFelder(n);
    dialog({ id: 'nu-dialog-aendern', titel: `${anzeige(n)} bearbeiten`, ja: 'Speichern',
      text: 'Name und E-Mail-Adresse sind Stammdaten: Die ID der Person in Protokoll und Buchungen ändert sich dadurch nicht.',
      felder: [feld('Vorname(n)', f.vornamen), feld('Familienname', f.familienname), feld('E-Mail-Adresse', f.email)],
      senden: async () => {
        await k.api('PATCH', `${basis}/${n.id}`, { json: { vornamen: f.vornamen.value.trim(), familienname: f.familienname.value.trim(), email: f.email.value.trim() } });
        k.melden('Angaben gespeichert.', 'erfolg');
        await laden();
      } });
    f.vornamen.focus();
  }

  function zugangDialog() {
    const n = einer();
    if (!n) return;
    const stunden = auswahl(STUNDEN, '24', { id: 'nu-f-stunden' });
    const widerrufen = h('input', { type: 'checkbox', id: 'nu-f-widerrufen' });
    sperren(widerrufen, n.passkeys.length ? null : 'Der Nutzer hat keinen Passkey');
    dialog({ id: 'nu-dialog-zugang', titel: `Zugang für ${anzeige(n)} ausstellen`, ja: 'Ausstellen',
      text: 'Mit Link und Code richtet die Person einmal einen Passkey ein. Ein früher ausgestellter Zugang wird damit ungültig.',
      felder: [feld('Gültig für', stunden),
        h('label', { class: 'nu-wahl' }, widerrufen, ` vorhandene Passkeys widerrufen (${n.passkeys.length}) – bei verlorenem Passkey`)],
      senden: async () => {
        const z = await k.api('POST', `${basis}/${n.id}/zugang`, { json: { stunden: Number(stunden.value), passkeys_widerrufen: widerrufen.checked } });
        await laden();
        zugangZeigen(anzeige(n), z);
      } });
  }

  /** Link und Code: genau einmal sichtbar. Der Dialog schließt nur über seinen Knopf. */
  function zugangZeigen(person, z) {
    const link = h('input', { id: 'nu-zugang-link', readonly: true, size: '44', value: z.link, spellcheck: false });
    const code = h('output', { id: 'nu-zugang-code', class: 'nu-code' }, `${z.code.slice(0, 3)} ${z.code.slice(3)}`);
    const kopieren = (text, was) => async () => {
      try { await navigator.clipboard.writeText(text); k.melden(`${was} in die Zwischenablage kopiert.`, 'erfolg'); } catch { k.melden(`${was} bitte von Hand kopieren.`, 'warnung'); }
    };
    const dlg = h('dialog', { class: 'dialog nu-zugang', id: 'nu-dialog-zugang-ergebnis' },
      h('h2', {}, `Zugang für ${person}`),
      h('p', {}, `Gültig bis ${zeit(z.gueltig_bis)}, einmal verwendbar. `, h('strong', {}, 'kumero zeigt Link und Code nicht wieder.')),
      h('div', { class: 'zeile' }, feld('Link', link), knopf('Link kopieren', kopieren(z.link, 'Link'), '', { id: 'nu-zugang-link-kopieren' })),
      h('div', { class: 'zeile' }, feld('Code', code), knopf('Code kopieren', kopieren(z.code, 'Code'), '', { id: 'nu-zugang-code-kopieren' })),
      h('p', { class: 'hinweis' }, 'Gib Link und Code auf zwei verschiedenen Wegen weiter – zum Beispiel den Link per E-Mail und den Code am Telefon. '
        + 'Wer nur eines von beiden hat, kommt nicht hinein. Nach fünf falschen Codes ist der Zugang ungültig.'),
      z.passkeys_widerrufen ? h('p', { class: 'hinweis' }, `${z.passkeys_widerrufen} Passkey${z.passkeys_widerrufen === 1 ? '' : 's'} widerrufen.`) : null,
      h('div', { class: 'dialog-knoepfe' }, knopf('Weitergegeben – schließen', () => dlg.close(), 'primaer', { id: 'nu-zugang-schliessen' })));
    dlg.addEventListener('cancel', (ev) => ev.preventDefault());
    dlg.addEventListener('close', () => dlg.remove());
    document.body.append(dlg);
    dlg.showModal();
  }

  function rolleDialog() {
    const ziel = auswahlListe();
    if (!ziel.length) return;
    // mit Rollen am Mandanten sagt jede Zeile der Auswahl, wo die Rolle gilt
    const rolle = auswahl(d.rollen.map((r) => [r.schluessel, amMandanten() ? `${r.name} – ${r.ebene === 'mandant' ? 'am Mandanten' : 'im Buch'}` : r.name]), null, { id: 'nu-f-rolle' });
    const gewaehlteRolle = () => d.rollen.find((r) => r.schluessel === rolle.value);
    const beschreibung = h('p', { class: 'hinweis', id: 'nu-f-rolle-text' }, ' ');
    const zeigen_ = () => { beschreibung.textContent = gewaehlteRolle()?.beschreibung || ' '; };
    rolle.addEventListener('change', zeigen_);
    const bis = datumFeld({ id: 'nu-f-bis' });
    dialog({ id: 'nu-dialog-rolle', titel: 'Rolle zuweisen', ja: 'Zuweisen',
      text: (amMandanten() ? '' : `Im Buch „${k.buchName}“. `) + (ziel.length === 1 ? `Für ${anzeige(ziel[0])}.` : `Für ${ziel.length} markierte Nutzer.`),
      felder: [feld('Rolle', rolle), beschreibung, feld('Befristet bis einschließlich (leer = unbefristet)', bis.el)],
      senden: async () => {
        const ende = bis.wert();
        if (ende === null) throw new Error('Das Datum ist nicht lesbar');
        const r = gewaehlteRolle();
        let neu = 0;
        for (const n of ziel) {
          if (n.zuweisungen.some((z) => z.schluessel === r.schluessel)) continue;   // hat die Rolle schon
          await k.api('POST', r.api, { json: { nutzer: n.id, rolle: r.name, ...(ende ? { bis: ende } : {}) } });
          neu++;
        }
        k.melden(neu ? `Rolle ${r.name} zugewiesen${ziel.length > 1 ? ` (${neu} von ${ziel.length}; die übrigen hatten sie schon)` : ''}.`
          : 'Die Rolle war schon zugewiesen.', neu ? 'erfolg' : 'warnung');
        await laden();
      } });
    zeigen_();
  }

  /** Auswahl aus den Einträgen eines Nutzers (Rollen oder Passkeys) mit Bestätigung. */
  function wahlDialog(o) {
    const wahl = o.eintraege.map((e, i) => h('label', { class: 'nu-wahl' },
      h('input', { type: 'radio', name: `${o.id}-wahl`, value: e.id, checked: i === 0 ? '' : null }), ` ${e.text}`));
    dialog({ id: o.id, titel: o.titel, ja: o.ja, art: 'gefahr', text: o.text,
      felder: [h('fieldset', { class: 'nu-gruppe' }, h('legend', {}, o.legende), ...wahl)],
      senden: async () => {
        const id = wahl.map((l) => l.querySelector('input')).find((c) => c.checked)?.value;
        await o.senden(id);
        await laden();
      } });
  }

  function rolleEndeDialog() {
    const n = einer();
    if (!n || !n.zuweisungen.length) return;
    wahlDialog({ id: 'nu-dialog-rolle-ende', titel: `Rolle von ${anzeige(n)} beenden`, ja: 'Beenden', legende: 'Rolle',
      text: `${amMandanten() ? '' : `Im Buch „${k.buchName}“. `}Die Zuweisung endet sofort. Was die Person mit dieser Rolle getan hat, bleibt im Protokoll.`,
      eintraege: n.zuweisungen.map((z) => ({ id: z.id, text: rollenText([z]) + (amMandanten() ? ` – ${ort(z.ebene)}` : '') })),
      senden: async (id) => { await k.api('DELETE', `${n.zuweisungen.find((z) => z.id === id).api}/${id}`); k.melden('Rolle beendet.', 'erfolg'); } });
  }

  function passkeyDialog() {
    const n = einer();
    if (!n || !n.passkeys.length) return;
    wahlDialog({ id: 'nu-dialog-passkey', titel: `Passkey von ${anzeige(n)} widerrufen`, ja: 'Widerrufen', legende: 'Passkey',
      text: 'Mit einem widerrufenen Passkey meldet sich niemand mehr an. Das lässt sich nicht rückgängig machen; einen neuen Passkey gibt es über einen Zugang.',
      eintraege: n.passkeys.map((p) => ({ id: p.id, text: `${p.bezeichnung || 'ohne Bezeichnung'} · registriert ${zeit(p.registriert_am)}` })),
      senden: async (id) => { await k.api('DELETE', `${basis}/${n.id}/passkeys/${encodeURIComponent(id)}`); k.melden('Passkey widerrufen.', 'erfolg'); } });
  }

  async function adminSetzen(einsetzen) {
    const n = einer();
    if (!n) return;
    try {
      if (einsetzen) await k.api('POST', `${k.mandantApi}/administratoren`, { json: { nutzer: n.id } });
      else await k.api('DELETE', `${k.mandantApi}/administratoren/${n.id}`);
      await laden();
      k.melden(einsetzen ? `${anzeige(n)} ist jetzt Administrator.` : `${anzeige(n)} ist kein Administrator mehr.`, 'erfolg');
      if (n.id === d.ich) await k.buecherNeuLaden();   // das eigene Amt ändert, was man sieht
    } catch (x) { k.fehler(x); }
  }

  /** Sperren und Entsperren für die Auswahl: je Nutzer ein Aufruf; was nicht geht, nennt die Meldung mit Grund. */
  async function aktivSetzen(aktiv) {
    const ziel = auswahlListe().filter((n) => n.aktiv !== aktiv);
    let ok = 0;
    const gruende = [];
    for (const n of ziel) {
      try { await k.api('PATCH', `${basis}/${n.id}`, { json: { aktiv } }); ok++; } catch (x) { gruende.push(`${anzeige(n)}: ${x.message}`); }
    }
    await laden();
    if (gruende.length) k.melden(`${ok ? `${ok} ${aktiv ? 'entsperrt' : 'gesperrt'}. ` : ''}${gruende.join(' · ')}`, 'fehler');
    else k.melden(ok === 1 ? `Nutzer ${aktiv ? 'entsperrt' : 'gesperrt'}.` : `${ok} Nutzer ${aktiv ? 'entsperrt' : 'gesperrt'}.`, 'erfolg');
  }

  await laden();
}
