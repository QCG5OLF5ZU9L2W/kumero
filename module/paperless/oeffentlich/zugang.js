// SPDX-License-Identifier: AGPL-3.0-or-later
// „Paperless – mein Zugang“ (PAPERLESS.md Abschnitt 2 und 3, PROFIL.md U11): Der Nutzer verbindet kumero mit
// seinem eigenen Paperless-Konto. Das Token wird nur verschlüsselt gespeichert und je Sitzung mit der
// Passphrase entsperrt. Hier stehen auch das Tag für offene Belege und die Auswahl der Tags.
import { h, seitenkopf, knopf, bestaetigen } from '/kern/ui/bausteine.js';

export async function zeigen(b, k) {
  const css = new URL('./paperless.css', import.meta.url).href;
  if (!document.querySelector(`link[href="${css}"]`)) document.head.append(h('link', { rel: 'stylesheet', href: css }));
  const t = k.t;
  const basis = `${k.apiBasis}/paperless`;
  const inhalt = h('div');
  b.append(seitenkopf(t('zugang.titel')), h('p', { class: 'hinweis' }, t('zugang.erklaerung')), inhalt);
  const tun = async (f, text) => { try { const r = await f(); if (text) k.melden(text, 'erfolg'); await laden(); return r; } catch (e) { k.fehler(e); return null; } };

  async function laden() {
    let d;
    try { d = await k.api('GET', `${basis}/verbindungen`); } catch (e) { k.fehler(e); return; }
    const gesperrt = d.verbindungen.some((v) => !v.entsperrt);
    const karten = [];

    // Entsperren: einmal je Sitzung mit der Passphrase
    if (d.verbindungen.length) {
      const pass = h('input', { id: 'pl-passphrase', type: 'password', autocomplete: 'current-password', title: t('hilfe.passphrase') });
      karten.push(h('div', { class: 'karte', id: 'pl-sperre' }, h('h2', {}, t(gesperrt ? 'sperre.gesperrt' : 'sperre.offen')),
        h('p', { class: 'hinweis' }, t(gesperrt ? 'sperre.gesperrt_text' : 'sperre.offen_text')),
        gesperrt ? h('form', { class: 'pl-zeile', onsubmit: (ev) => { ev.preventDefault(); tun(() => k.api('POST', `${basis}/entsperren`, { json: { passphrase: pass.value } }), t('meldung.entsperrt')); } },
          h('label', { for: 'pl-passphrase' }, t('feld.passphrase')), pass, h('button', { type: 'submit', class: 'knopf primaer', id: 'pl-entsperren' }, t('aktion.entsperren')))
          : knopf(t('aktion.sperren'), () => tun(() => k.api('POST', `${basis}/sperren`, { json: {} }), t('meldung.gesperrt')), '', { id: 'pl-sperren', title: t('hilfe.sperren') })));
    }

    // Eigene Verbindungen
    const liste = d.verbindungen.map((v) => {
      const status = h('p', { class: 'pl-status hinweis', hidden: true });
      const tags = h('div', { class: 'pl-tags' });
      const tagFeld = h('input', { value: v.tag_offen || '', maxlength: '100', 'aria-label': t('feld.tag_offen'), title: t('hilfe.tag_offen') });
      const pruefen = async () => {
        status.hidden = false;
        status.textContent = t('status.laeuft');
        try {
          const s = await k.api('GET', `${basis}/verbindungen/${v.id}/status`);
          status.textContent = s.erreichbar
            ? t('status.gut', { version: s.version || '?', api: s.api_verwendet, konto: s.konto || '?', tags: s.tags })
              + (s.neuer_als_geprueft ? ` ${t('status.neuer', { api: s.api_gemeldet })}` : '') + (s.tag_offen_vorhanden === false ? ` ${t('status.tag_fehlt')}` : '')
            : t(`status.grund_${s.grund}`, { status: s.status, version: s.version || '?' });
        } catch (e) { status.textContent = e.message; }
      };
      const tagsZeigen = async () => {
        let alle;
        try { alle = await k.api('GET', `${basis}/verbindungen/${v.id}/tags`); } catch (e) { k.fehler(e); return; }
        const kisten = alle.map((x) => h('input', { type: 'checkbox', value: String(x.id), checked: x.uebernommen, disabled: x.uebernommen || x.arbeitstag }));
        const suche = h('input', { type: 'search', placeholder: t('tags.suche'), 'aria-label': t('tags.suche') });
        const zeilen = alle.map((x, i) => h('label', { 'data-name': x.name.toLowerCase() }, kisten[i], x.name,
          x.arbeitstag ? h('span', { class: 'marke-klein', title: t('tags.arbeitstag_hilfe') }, t('tags.arbeitstag')) : null,
          x.uebernommen ? h('span', { class: 'marke-klein korrigiert' }, t('tags.uebernommen')) : null));
        suche.addEventListener('input', () => { const s = suche.value.trim().toLowerCase(); zeilen.forEach((z) => { z.hidden = s !== '' && !z.dataset.name.includes(s); }); });
        tags.replaceChildren(h('details', { open: true, class: 'pl-tagwahl' }, h('summary', {}, t('tags.titel', { n: alle.length })),
          h('p', { class: 'hinweis' }, t('tags.hinweis')), suche, h('div', { class: 'pl-tagliste' }, zeilen),
          k.darf('kern.stammdaten.aendern') ? knopf(t('tags.uebernehmen'), async () => {
            const ids = kisten.filter((c) => c.checked && !c.disabled).map((c) => Number(c.value));
            if (!ids.length) { k.melden(t('tags.nichts_gewaehlt'), 'warnung'); return; }
            try {
              const r = await k.api('PUT', `${basis}/verbindungen/${v.id}/tags`, { json: { tags: ids } });
              k.melden(t('tags.ergebnis', { n: r.angelegt.length }) + (r.uebergangen.length ? ` ${t('tags.uebergangen', { namen: r.uebergangen.join(', ') })}` : ''), 'erfolg');
              await tagsZeigen();
            } catch (e) { k.fehler(e); }
          }, 'primaer', { 'data-pl': 'tags-uebernehmen' }) : h('p', { class: 'hinweis' }, t('tags.kein_recht'))));
      };
      return h('div', { class: 'pl-verbindung', 'data-id': v.id },
        h('h3', {}, v.bezeichnung, h('span', { class: ['marke-klein', v.entsperrt ? 'korrigiert' : 'warnung'] }, t(v.entsperrt ? 'verbindung.entsperrt' : 'verbindung.gesperrt'))),
        h('div', {}, h('a', { href: v.adresse, target: '_blank', rel: 'noopener noreferrer' }, v.adresse),
          v.paperless_version ? h('span', { class: 'hinweis pl-version' }, ` · ${t('verbindung.version', { version: v.paperless_version, api: v.api_verwendet })}`) : null),
        h('div', { class: 'pl-zeile' }, h('span', { class: 'hinweis' }, t('feld.tag_offen')), tagFeld,
          knopf(t('aktion.tag_speichern'), () => tun(() => k.api('PUT', `${basis}/verbindungen/${v.id}`, { json: { bezeichnung: v.bezeichnung, tag_offen: tagFeld.value.trim() || null } }), t('meldung.gespeichert')), 'klein', { disabled: !v.entsperrt })),
        h('div', { class: 'pl-zeile' },
          knopf(t('aktion.testen'), pruefen, '', { 'data-pl': 'testen', disabled: !v.entsperrt, title: t('hilfe.testen') }),
          knopf(t('aktion.tags'), tagsZeigen, '', { 'data-pl': 'tags', disabled: !v.entsperrt, title: t('hilfe.tags') }),
          knopf(t('aktion.loeschen'), async () => {
            if (await bestaetigen(t('loeschen.titel'), t('loeschen.frage', { name: v.bezeichnung }), t('aktion.loeschen'))) tun(() => k.api('DELETE', `${basis}/verbindungen/${v.id}`), t('meldung.geloescht'));
          }, 'gefahr', { 'data-pl': 'loeschen' })),
        status, tags);
    });
    karten.push(h('div', { class: 'karte', id: 'pl-verbindungen' }, h('h2', {}, t('verbindung.titel')),
      liste.length ? liste : h('p', { class: 'hinweis' }, t('verbindung.keine'))));

    // Neue Verbindung
    const f = {
      adresse: h('input', { id: 'pl-adresse', type: 'url', value: d.vorgabe && !d.verbindungen.some((v) => v.adresse === d.vorgabe.adresse) ? d.vorgabe.adresse : '', placeholder: 'https://paperless.example.org', spellcheck: false, title: t('hilfe.adresse') }),
      bezeichnung: h('input', { id: 'pl-bezeichnung', maxlength: '60', value: d.vorgabe && !d.verbindungen.length ? d.vorgabe.bezeichnung : '', title: t('hilfe.bezeichnung') }),
      token: h('input', { id: 'pl-token', type: 'password', autocomplete: 'off', spellcheck: false, title: t('hilfe.token') }),
      tag: h('input', { id: 'pl-tag', maxlength: '100', title: t('hilfe.tag_offen') }),
      pass: h('input', { id: 'pl-neu-passphrase', type: 'password', autocomplete: 'new-password', title: t('hilfe.passphrase_neu', { n: d.passphrase_min }) }),
    };
    karten.push(h('div', { class: 'karte', id: 'pl-neu' }, h('h2', {}, t('neu.titel')), h('p', { class: 'hinweis' }, t('neu.hinweis', { n: d.passphrase_min })),
      h('form', { onsubmit: async (ev) => {
        ev.preventDefault();
        const daten = { adresse: f.adresse.value.trim(), token: f.token.value.trim(), passphrase: f.pass.value };
        if (f.bezeichnung.value.trim()) daten.bezeichnung = f.bezeichnung.value.trim();
        if (f.tag.value.trim()) daten.tag_offen = f.tag.value.trim();
        await tun(() => k.api('POST', `${basis}/verbindungen`, { json: daten }), t('meldung.angelegt'));
      } }, h('div', { class: 'pl-raster' },
        h('label', { for: 'pl-adresse' }, t('feld.adresse')), f.adresse, h('label', { for: 'pl-bezeichnung' }, t('feld.bezeichnung')), f.bezeichnung,
        h('label', { for: 'pl-token' }, t('feld.token')), f.token, h('label', { for: 'pl-tag' }, t('feld.tag_offen')), f.tag,
        h('label', { for: 'pl-neu-passphrase' }, t('feld.passphrase')), f.pass),
      h('div', { class: 'pl-zeile' }, h('button', { type: 'submit', class: 'knopf primaer', id: 'pl-anlegen' }, t('aktion.verbinden'))))));

    // Vorgabe des Mandanten
    if (k.darf('paperless.vorgabe.pflegen')) {
      const va = h('input', { id: 'pl-vorgabe-adresse', type: 'url', value: d.vorgabe?.adresse || '', placeholder: 'https://paperless.example.org', spellcheck: false });
      const vb = h('input', { id: 'pl-vorgabe-bezeichnung', maxlength: '60', value: d.vorgabe?.bezeichnung || '' });
      karten.push(h('div', { class: 'karte', id: 'pl-vorgabe' }, h('h2', {}, t('vorgabe.titel')), h('p', { class: 'hinweis' }, t('vorgabe.hinweis')),
        h('form', { onsubmit: (ev) => { ev.preventDefault(); tun(() => k.api('PUT', `${basis}/vorgabe`, { json: va.value.trim() ? { adresse: va.value.trim(), bezeichnung: vb.value.trim() || undefined } : { adresse: null } }), t('meldung.gespeichert')); } },
          h('div', { class: 'pl-raster' }, h('label', { for: 'pl-vorgabe-adresse' }, t('feld.adresse')), va, h('label', { for: 'pl-vorgabe-bezeichnung' }, t('feld.bezeichnung')), vb),
          h('div', { class: 'pl-zeile' }, h('button', { type: 'submit', class: 'knopf', id: 'pl-vorgabe-speichern' }, t('aktion.speichern'))))));
    }
    inhalt.replaceChildren(...karten);
  }
  await laden();
}
