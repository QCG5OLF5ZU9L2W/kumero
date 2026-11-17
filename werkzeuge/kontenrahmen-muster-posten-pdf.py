#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
# Erzeugt tests/fixtures/kontenrahmen/skr03-muster-posten.pdf: ein PDF im Aufbau der DATEV-Kontenrahmen
# mit Posten-Spalte (SKR 03/04 ab BilRUG): je Seitenhälfte „Bilanz-/GuV-Posten | Programmverbindung |
# Konten“, Posten in Kästen aus gefüllten Linien, Kopf- und Fußzeilen – mit ERFUNDENEN Konten.
# Die Posten-Namen sind die gesetzliche Gliederung (§§ 266, 275 HGB). Kein DATEV-Inhalt.
# Aufruf: python3 werkzeuge/kontenrahmen-muster-posten-pdf.py
from reportlab.lib.pagesizes import letter
from reportlab.pdfgen import canvas
import os

ZIEL = os.path.join(os.path.dirname(__file__), "..", "tests", "fixtures", "kontenrahmen", "skr03-muster-posten.pdf")
H = 792

# Hälften: (x Posten, x Programmverbindung, x Konten, x Ende)
HAELFTEN = [(55, 114, 149, 292), (295, 354, 389, 533)]

# Je Spalte: Liste von Kästen (Posten-Zeilen oder None = ohne Kasten) mit Konten
# Konto: (Kennzeichen, Nummer, [Beschriftungszeilen], Programmverbindung)
SEITEN = [
    ("Bilanz-", "0", "Anlage- und Kapitalkonten", [
        [
            (["Technische An-", "lagen und Ma-", "schinen"], [("", "0200", ["Maschinen Muster"], ""), ("", "0210", ["Werkzeuge Muster mit einer", "langen Beschriftung"], "")]),
            (["Andere Anla-", "gen, Betriebs-", "und Geschäfts-", "ausstattung"], [("", "0400", ["Büroeinrichtung Muster"], ""), ("R", "0405", [], "")]),
            (None, [("", "0490", ["Konto ohne Posten Muster"], "")]),
            (["Gezeichnetes", "Kapital"], [("", "0800", ["Kapital Muster"], "K")]),
        ],
        [
            (["Sonstige Rück-", "stellungen"], [("", "0970", ["Rückstellungen Muster 1)"], ""), ("", "0971", ["Rückstellungen", "Muster zwei"], "")]),
            (["Rechnungsab-", "grenzungspos-", "ten"], [("", "0980", ["Aktive Abgrenzung Muster"], "")]),
            (["Kassenbestand,", "Bundesbank-", "guthaben, Gut-", "haben bei Kre-", "ditinstituten", "und Schecks"], [("", "1000", ["Kasse Muster"], ""), ("", "1200", ["Bank Muster"], ""), ("", "1210", ["Bank Muster zwei"], "")]),
        ],
    ]),
    ("Bilanz-/GuV-", "1", "Finanz- und Privatkonten", [
        [
            (["Sonstige Ver-", "bindlichkeiten", "oder Sonstige", "Vermögensge-", "genstände"], [("S/AV", "1576", ["Vorsteuer Muster"], "U"), ("S", "1776", ["Umsatzsteuer Muster"], "U")]),
            (["Verbindlich-", "keiten aus Lie-", "ferungen und", "Leistungen"], [("", "1600", ["Verbindlichkeiten Muster"], "")]),
            (["Umsatzerlöse"], [("AM", "8400", ["Erlöse Muster 19 % USt"], ""), ("", "8410", ["Erlöse Muster"], "")]),
        ],
        [
            (["Sonstige be-", "triebliche Auf-", "wendungen"], [("AV", "4930", ["Bürobedarf Muster"], ""), ("", "4980", ["Sonstige Aufwendungen", "Muster"], "")]),
            (["Löhne und Ge-", "hälter"], [("", "4120", ["Gehälter Muster"], "")]),
            (None, [("", "9000", ["Saldenvorträge Muster"], "")]),
        ],
    ]),
]

def kasten(c, x0, x1, oben, unten):
    for y in (oben, unten):
        c.rect(x0 + 1, H - y - 0.35, x1 - x0 - 4, 0.7, stroke=0, fill=1)
    for x in (x0 + 1, x1 - 3):
        c.rect(x, H - unten, 0.7, unten - oben, stroke=0, fill=1)

c = canvas.Canvas(ZIEL, pagesize=letter, invariant=1)
for nr, (kopf, klasse, titel, spalten) in enumerate(SEITEN, start=1):
    c.setFont("Helvetica-Bold", 12)
    if nr == 1:
        c.drawString(79, H - 32, "Muster-Kontenrahmen nach dem Bilanzrichtlinie-Umsetzungsgesetz")
        c.drawString(79, H - 46, "Standardkontenrahmen - Prozessgliederungsprinzip (SKR 03) - Muster")
        c.drawString(79, H - 60, "Gültig für 2026")
    # Kopf der Tabelle
    c.rect(53, H - 81, 480, 0.9, stroke=0, fill=1)
    c.rect(53, H - 116, 480, 0.9, stroke=0, fill=1)
    c.rect(292, H - 759, 0.9, 678, stroke=0, fill=1)
    for (xp, xv, xk, xe) in HAELFTEN:
        c.setFont("Helvetica", 8)
        c.drawString(xp + 8, H - 91, kopf)
        c.drawString(xp + 8, H - 100, "Posten2)")
        c.setFont("Helvetica", 6)
        c.drawString(xv + 3, H - 89, "Programm-")
        c.drawString(xv + 2, H - 96, "verbindung4)")
        c.drawString(xv + 4, H - 104, "Abschluss-")
        c.drawString(xv + 8, H - 111, "zweck4)")
        for x in (xv - 1, xk - 1):
            c.rect(x, H - 116, 0.9, 35, stroke=0, fill=1)
        c.setFont("Helvetica", 8)
        c.drawCentredString((xk + xe) / 2, H - 91, klasse)
        c.drawCentredString((xk + xe) / 2, H - 100, titel)
    for (xp, xv, xk, xe), kaesten in zip(HAELFTEN, spalten):
        y = 130.0
        for posten, konten in kaesten:
            oben = y - 6
            zeilen_posten = posten or []
            yk = y
            c.setFont("Helvetica", 7.5)
            for kz, knr, texte, pv in konten:
                if pv:
                    c.drawString(xv + 12, H - yk, pv)
                if kz:
                    c.drawRightString(xk + 24, H - yk, kz)
                c.drawString(xk + 27, H - yk, knr)
                for i, t in enumerate(texte):
                    c.drawString(xk + 50, H - yk, t)
                    if i < len(texte) - 1:
                        yk += 9
                yk += 9
            for i, t in enumerate(zeilen_posten):
                c.drawString(xp + 4, H - (y + 2 + i * 9), t)
            unten = max(yk, y + 2 + len(zeilen_posten) * 9) + 2
            if posten:
                kasten(c, xp, xv, oben, unten)
            y = unten + 12
    c.setFont("Helvetica", 6.5)
    c.drawString(79, H - 771, "Art.-Nr. 99999 2026-01-01")
    c.drawRightString(555, H - 771, f"Seite {nr}")
    c.showPage()
c.save()
print("geschrieben:", os.path.normpath(ZIEL))
