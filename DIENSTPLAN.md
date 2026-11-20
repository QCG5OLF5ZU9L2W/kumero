# DIENSTPLAN.md – Dienstplan: Schichten, Besetzung, Tausch (Entwurf, Fassung 1)

Stand: 2026-11-20. Bezug: PERSONAL.md (Personen, Abwesenheiten, Rollen, Ebene Mandant), ZEIT.md (Soll und Ist).
Vorlagen der Funktionen: TimeTrex, MASTERPLAN – kein fremder Code.
**Status: Entwurf zur Freigabe (P37). Nichts davon ist gebaut. Das Modul kommt nach `personal`, `zeit` und
`leistungen`; dieser Entwurf ist bewusst kurz und wird vor dem Bauen vertieft.**

## 1. Anlass

Der Verantwortliche hat den Dienstplan am 2026-11-20 als relevant genannt. Er sagt, wer wann arbeitet, liefert der
Zeiterfassung das geplante Soll und zeigt, wo Besetzung fehlt.

## 2. Was das Modul tut

1. **Schichtvorlagen:** Name, Beginn, Ende, Pause, Farbe und Kürzel; je Bereich (z. B. Empfang, Behandlung).
2. **Plan** je Woche oder Monat: Personen in Zeilen, Tage in Spalten; Schichten setzen, kopieren, eine Woche
   wiederholen. Genehmigte Abwesenheiten aus `personal` stehen im Plan als „abwesend“ – ohne Grund (PERSONAL.md PE8).
3. **Bedarf:** je Bereich, Tag und Schicht die gewünschte Besetzung; der Plan zeigt, wo sie unter- oder
   überschritten ist.
4. **Hinweise:** Ruhezeit zu kurz, mehr Stunden als im Vertrag, geplant trotz Abwesenheit – als Hinweis, nie als
   Sperre.
5. **Veröffentlichen:** Ein Plan ist Entwurf, bis er veröffentlicht ist; danach sehen ihn alle Betroffenen. Jede
   Änderung nach dem Veröffentlichen ist vermerkt.
6. **Selfservice:** eigenen Plan sehen; Wunsch und Nichtverfügbarkeit melden; **Tausch** mit einer Kollegin oder
   einem Kollegen beantragen – gültig erst mit der Zustimmung beider und der Freigabe des Vorgesetzten.
7. **Soll an die Zeiterfassung:** Die geplante Schicht ist das Soll des Tages (ZEIT.md ZE4).

## 3. Rollen (am Mandanten)

| Rolle | darf |
|---|---|
| **Mitarbeiter** | eigenen Plan und den seines Bereichs sehen, Wünsche melden, Tausch beantragen |
| **Vorgesetzter** | planen, veröffentlichen, Tausch freigeben – für sein Team |
| **Personal** | alles, dazu Vorlagen, Bereiche und Bedarf pflegen |

## 4. Entscheidungen (zur Freigabe)

| # | Frage | Vorschlag |
|---|---|---|
| DP1 | Ebene | Mandant, wie `personal`. |
| DP2 | Planung | von Hand mit Vorlagen und Kopieren; **kein automatischer Planer** in der ersten Fassung. |
| DP3 | Abwesenheiten | kommen aus `personal`, im Plan ohne Grund. |
| DP4 | Regeln | Hinweise, keine Sperren. |
| DP5 | Veröffentlichen | Entwurf und veröffentlichte Fassung; Änderungen danach sind sichtbar. |
| DP6 | Tausch | Antrag, Zustimmung des Gegenübers, Freigabe des Vorgesetzten. |
| DP7 | Verhältnis zur Zeit | Der Plan ist das Soll; das Ist kommt aus `zeit`. Ohne `zeit` ist der Plan nur ein Plan. |
| DP8 | Zuschläge | entstehen aus den **geleisteten** Stunden (`zeit`), nie aus dem Plan. |

## 5. Offen

- Wiederkehrende Muster über mehrere Wochen, Rufbereitschaft, geteilte Dienste und Planung über mehrere Standorte
  sind nicht beschrieben.
- Lizenz und Stand von MASTERPLAN sind nicht geprüft.
