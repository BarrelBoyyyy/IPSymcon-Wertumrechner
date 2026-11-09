# IP-Symcon Wertumrechner

Eine Instanz liest mehrere numerische Symcon-Variablen und stellt die ausgewählten Ergebnisse in W, kW und A als Float-Variablen bereit. Die Berechnung läuft beim Speichern und bei Aktualisierungen einer Quellvariable.

## Installation

1. In der logischen Baumansicht unter **Kern-Instanzen → Modules** das Repository hinzufügen.
2. Eine Instanz **Wertumrechner** anlegen.
3. In der Liste je Quellvariable eine Zeile hinzufügen und Quelleinheit, gewünschte Ausgaben sowie bei Bedarf Phasen, Spannung und Leistungsfaktor einstellen. Anschließend die Konfiguration übernehmen.

Neue Zeilen starten mit aktivierter Ausgabe in kW. Die Ergebnisvariablen tragen den Namen der Quelle mit dem Zusatz W, kW oder Ampere. Bestehende Einzelkonfigurationen erscheinen als erste Zeile. Ihre Ergebnisvariablen behalten beim Update ihre IDs. Beim Abwählen einer Ausgabe entfernt Symcon die zugehörige Ergebnisvariable. Beim späteren Aktivieren wird eine neue Variable angelegt.

## Berechnung

| Eingabe | Umrechnung |
| --- | --- |
| W oder kW | 1 kW = 1.000 W |
| Einphasig | P = U × I × Leistungsfaktor. U ist die Spannung zwischen Außenleiter und Neutralleiter. |
| Dreiphasig | P = √3 × U × I × Leistungsfaktor. U ist die Außenleiterspannung und die Rechnung setzt eine symmetrische Last voraus. |

Voreinstellungen sind 230 V einphasig, 400 V dreiphasig und Leistungsfaktor 1. Die Stromausgabe für Drehstrom gilt je Phase. Das Vorzeichen des Eingangswerts bleibt bei den Umrechnungen erhalten. Negative Stromausgaben bilden damit die Richtung gemäß der Vorzeichenkonvention der Quelle ab.

[Offizielle Symcon-Entwicklerdokumentation](https://www.symcon.de/de/llms-full.txt)
