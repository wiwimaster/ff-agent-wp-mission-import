# ff-agent-wp-mission-import

Importiert Einsatzdaten von FF Agent nach WordPress.

- **Widget-Import (Basis):** Holt das öffentliche FF-Agent-Website-Widget-JSON per Action Scheduler und legt je
  Einsatz einen Beitrag vom Typ `mission` an (Titel, Berichtstext, Bilder, Dauer, Ort, Personalanzahl, Fahrzeuge).
  Ohne Widget-Beitrag wird nichts veröffentlicht.
- **MP-Feuer-Export (Ergänzung):** Nimmt den MP-Feuer-Export aus FF-Agent an und reichert die Widget-Einsätze um
  strukturierte Felder an. Wo beide Quellen etwas liefern, gewinnt der präzisere oder umfangreichere Wert.

Voraussetzungen: PHP ≥ 8.1, Plugin „Action Scheduler“ (oder WooCommerce).

## MP-Feuer-Export

FF-Agent schickt einen Einsatz per Hand („Export an MP-Feuer“) als
`POST /mpfeuer/rest/<sechsstellige Kennung aus Buchstaben und Ziffern>/Einsatz` mit JSON-Body und HTTP Basic Auth. Das Plugin

1. prüft Zugangsdaten und `Content-Type: application/json` und antwortet `200 {}`
   (`401` mit `WWW-Authenticate: Basic realm="mpfeuer"` ohne bzw. mit falschen Zugangsdaten, `400` bei ungültigem
   JSON, `415` bei falschem Content-Type, `429` nach zehn Fehlversuchen in zehn Minuten),
2. wendet sofort die Datenregeln an und verwirft alles andere (siehe unten),
3. legt den bereinigten Datensatz in einer internen Warteschlange ab (Post-Typ `ffami_mpfeuer`, nicht öffentlich,
   ohne REST, ohne Suche), dedupliziert über die Export-`_id`,
4. ordnet ihn über die Alarmzeit dem Widget-Einsatz zu (Standard-Toleranz ±10 Minuten, einstellbar). Kommt der
   Export vor dem Widget-Einsatz an, bleibt er `offen` und wird nach dem nächsten Widget-Import zugeordnet
   (Action `ffami_mission_saved`). Mehrere Einsätze im Zeitfenster werden über die Fahrzeugnamen plausibilisiert;
   bleibt es mehrdeutig, entscheidest du im Backend.

Die Zuordnung schreibt `ffami_mpf_*`-Metas an den `mission`-Beitrag. Der Widget-Import überschreibt sie nicht;
`ffami_mission::from_post` nimmt sie mit Vorrang (Beginn/Ende und Dauer, Stichwort, Einsatzart, Kategorie,
Fahrzeuge und Personalanzahl, wenn umfangreicher, weitere Kräfte).

### Einrichtung

1. Zugangsdaten festlegen – bevorzugt in `wp-config.php` (bzw. über die `.env` des Website-Stacks), nie im Repo:

   ```php
   define('FFAMI_MPFEUER_USER', '…');
   define('FFAMI_MPFEUER_PASS', '…');
   ```

   Alternativ im Backend (*FF Agent Mission Import → MP-Feuer-Export*); dort wird nur ein Passwort-Hash gespeichert.
   Ohne Zugangsdaten weist der Endpunkt alle Exporte ab.
2. Optional die sechsstellige Kennung aus dem Pfad eintragen (dann werden andere Kennungen mit `404` abgewiesen),
   die Toleranz anpassen und die **Fahrzeug-Zuordnung** pflegen (`FMS_ID = eigener Name`, je Zeile, z. B.
   `Steinebach 11/1 = MZF`). Unbekannte Fahrzeuge und Einheiten erscheinen in der Prüfliste.
3. In FF-Agent unter *Admin → Externe Dienste → MP-Feuer API* eintragen:

   | Feld | Wert |
   |---|---|
   | URL (ohne Pfad) | `https://mpfeuer.ffw-steinebach-auing.de` (FF-Agent hängt `/mpfeuer/rest/…` an) |
   | Benutzername / Passwort | wie in Schritt 1 |
   | Abteilung | `001` |

Erreichbar ist der Endpunkt unter `/wp-json/ffami/v1/mpfeuer/rest/<Kennung>/Einsatz` und – über eine
Rewrite-Regel – direkt unter `/mpfeuer/rest/<Kennung>/Einsatz`. Die Subdomain `mpfeuer.` muss dafür per Reverse
Proxy (Caddy) auf den Website-Container zeigen. Läuft PHP hinter einem Proxy oder FastCGI, muss der
`Authorization`-Header durchgereicht werden.

Im Backend gibt es außerdem die Übersicht der Warteschlange (offen/zugeordnet, manuell zuordnen, verwerfen,
„Zuordnung nachholen“), die letzten Eingänge (nur Zeit, Export-ID, Ergebnis) und die Prüfliste.

### Datenregeln

| Feld im Export | Gespeichert | Öffentlich |
|---|---|---|
| `Zeit_Von`, `Zeit_Bis` (Ortszeit Europe/Berlin → Unix-Timestamp) | ja | Beginn, Ende, Dauer |
| `Alarmstichwort`, `Einsatz_Art`, `Kategorie` | ja | ja |
| `Einsatzort` (Straße, PLZ, Ort, Ortsteil, Objekt) | ja, intern | nur Ortsteil (vorbereitet) |
| `Fahrzeuge` (`FMS_ID`, `Bezeichnung`) | ja, auf eigene Namen abgebildet | ja |
| `Fahrzeuge[].Besatzung` | ja, intern | nein |
| `Sonstige_EK` | ja | Feuerwehren (`Florian …`) mit Name, alle anderen nur Typ und Organisation, z. B. „RTW (BRK)“ |
| `Personal` | nur Anzahl | Anzahl |
| `Alarmgeber`, `Leitstellen_Nr`, Fahrzeug-Zeiten (`Zeit_AUS/EIN/ADE/VDE/STOPP`), `Tagebuch`, `Einsatzleiter`, Personennamen, Rollen, MML-Felder, Freitexte, `Statistik`, Brandfelder | **nein** | nein |

Fremde Einheiten werden über den bayerischen Funkrufnamen eingeordnet: Organisation aus dem Präfix (`Florian` FW,
`Rotkreuz` BRK, `Akkon` JUH, `Johannes` MHD, `Sama` ASB, `Heros` THW, `Christoph` RTH, `Polizei`), Typ aus der
Kennzahl (`71` RTW, `72` KTW, `76` NEF, `10` ELW, `40` LF, `59` Rettungshunde-Fahrzeug; bei `Einheit/Typ/Nr` zählt
die mittlere Zahl). Beide Tabellen sind im Backend pflegbar. Was sich nicht einordnen lässt, erscheint neutral als
„Weitere Einheit“ bzw. „Fahrzeug (Org)“ und in der Prüfliste.

### Datenschutz

- Was nicht gespeichert werden darf, wird **beim Empfang** verworfen, nicht erst bei der Anzeige. Der Rohexport wird
  weder gespeichert noch geloggt; protokolliert werden nur Zeit, Export-`_id` und Ergebnis.
- Adresse und Besatzungsstärke liegen nur als interne Metas (`ffami_mpf_address`, `ffami_mpf_crew`, als geschützte
  Metas ausgeblendet) am Beitrag und werden von keiner öffentlichen Ausgabe geliefert. Der Post-Typ `mission` hat
  keine REST-Ausgabe.
- Unzugeordnete Exporte werden nach 90 Tagen automatisch verworfen.

## Entwicklung

```bash
composer install
composer test
```

Die Tests laufen ohne WordPress gegen schlanke Stubs (`tests/bootstrap.php`). Testdaten stammen ausschließlich aus
einem pseudonymisierten Beispielexport (`tests/fixtures/mpfeuer-einsatz.json`).
