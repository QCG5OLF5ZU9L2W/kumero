#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
# Erzeugt tests/fixtures/kontenrahmen/skr81-muster.pdf: ein PDF im Aufbau eines
# gedruckten DATEV-Kontenrahmens (zwei Spalten, Kennzeichen, Umbrüche, Bereiche,
# Fußnoten, Kopf- und Fußzeilen) – mit ERFUNDENEN Konten. Kein DATEV-Inhalt.
# Aufruf: python3 werkzeuge/kontenrahmen-muster-pdf.py
from reportlab.lib.pagesizes import A4
from reportlab.pdfgen import canvas
import os

ZIEL = os.path.join(os.path.dirname(__file__), "..", "tests", "fixtures", "kontenrahmen", "skr81-muster.pdf")

# (Kennzeichen, Nummer, Beschriftung in Zeilen) | ("K", Klasse, Titel) | ("U", Überschrift) | ("B", Text)
links = [
    ("K", "0", "Anlagekonten"),
    ("U", "Anlagevermögen"),
    ("", "0010", ["Grundstück Muster"]),
    ("", "0200", ["Praxisgeräte Muster"]),
    ("", "0420", ["Büroeinrichtung Muster mit einer", "langen Beschriftung"]),
    ("U", "Kapital"),
    ("", "0800", ["Festkapital Muster"]),
    ("", "0810", ["Kapital Teilhafter Muster"], "-69"),
    ("", "0950", ["Rückstellungen Muster 1)"]),
    ("K", "1", "Finanz- und Privatkonten"),
    ("B", "KU 1000-1371"),
    ("F", "1000", ["Kasse Muster"]),
    ("F", "1200", ["Bank Muster"]),
    ("R", "1401", ["Forderungen Bereich Muster"], "-06"),
    ("AV", "1576", ["Abziehbare Vorsteuer Muster"]),
    ("", "1600", ["Verbindlichkeiten Muster"]),
    ("S", "1776", ["Umsatzsteuer Muster 18)"]),
    ("", "1900", ["Privatentnahmen Muster"]),
]
rechts = [
    ("K", "4", "Betriebliche Aufwendungen"),
    ("", "4120", ["Gehälter Muster"]),
    ("", "4210", ["Miete Muster"]),
    ("AV", "4930", ["Bürobedarf Muster"]),
    ("", "4980", ["Sonstige betriebliche", "Aufwendungen Muster"]),
    ("K", "8", "Erlöskonten"),
    ("AM", "8100", ["Honorare Muster"]),
    ("", "8590", ["Statistisches Konto Muster 3)"]),
    ("K", "9", "Vortrags- und Abschlusskonten"),
    ("", "9000", ["Saldenvorträge Muster"]),
    ("U", "Personenkonten"),
    ("", "10000-69999", ["Debitoren"]),
    ("K", "5", "Muster ohne Gliederung"),
    ("", "5100", ["Konto ohne Gliederung Muster"]),
]

def spalte(c, eintraege, x, y0):
    y = y0
    for e in eintraege:
        if e[0] == "K":
            y -= 6; c.setFont("Helvetica-Bold", 9); c.drawString(x, y, f"{e[1]} {e[2]}"); y -= 12
        elif e[0] == "U":
            c.setFont("Helvetica-Bold", 7.5); c.drawString(x + 30, y, e[1]); y -= 10
        elif e[0] == "B":
            c.setFont("Helvetica", 7.5); c.drawString(x, y, e[1]); y -= 10
        else:
            kz, nr, texte = e[0], e[1], e[2]
            c.setFont("Helvetica", 7.5)
            if kz:
                c.drawString(x, y, kz)
            c.drawString(x + 14, y, nr)
            for i, t in enumerate(texte):
                c.drawString(x + 44, y, t)
                if i < len(texte) - 1 or len(e) > 3:
                    y -= 9
                    if i == 0 and len(e) > 3:
                        c.drawString(x + 14, y, e[3])
            y -= 10
    return y

c = canvas.Canvas(ZIEL, pagesize=A4, invariant=1)
for seite, (l, r) in enumerate([(links, rechts)], start=1):
    c.setFont("Helvetica-Bold", 11)
    c.drawString(40, 805, "Muster-Kontenrahmen (Aufbau wie SKR 81)")
    c.setFont("Helvetica", 8)
    c.drawString(40, 793, "Standardkontenrahmen (SKR) 81 – Muster")
    c.drawRightString(555, 805, "Gültig für 2015")
    spalte(c, l, 40, 770)
    spalte(c, r, 300, 770)
    c.setFont("Helvetica", 6.5)
    c.drawString(40, 30, f"Art.-Nr. 99999 2015-01-01")
    c.drawRightString(555, 30, f"Seite {seite}")
    c.showPage()
c.save()
print("geschrieben:", os.path.normpath(ZIEL))
