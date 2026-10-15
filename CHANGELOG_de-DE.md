# 1.0.0
- Initialer plugin release

# 1.1.0
- Nutze Produktname von Shopware anstelle der everysize-api.
- Speichere zusätzliche PayPal-Transaktions IDs für Detail-Ansicht im Administrations-Backend.
- Neuer Protokoll-Screen im Administrations-Backend um die letzten importe und exporte zu everysize anzuschauen.

# 1.1.1
- Bessere Unterstützung für Varianten Produkte

# 1.1.2
- Bestell ID in den Logs eingefügt
- Kleinere Bugfixes
- 
# 1.2.0
- Produkt-Bilder zur Bestellbestätigungs-eMail hinzugefügt.

# 1.2.1
- Kleinere Fehlerbehebungen und reduziertes logging

# 1.3.0
- Kleinere Fehlerbehebungen
- Neues LogFeed für Entwickler

# 1.4.0
- Neue Rückerstattungsfunktionalität für Paypal-Zahlungen über everysize
- Fix für die Anzeige von Details der Produkt-Varianten in Bestell-E-Mails

# 1.5.0
- Übermittlung des Retour-Status an everysize

# 1.5.1
- Filter optionen für das Protokoll-feed

# 1.6.0
- Automatisches Cleanup des LogFeeds

# 1.6.1
- Ausblenden des Bearbeiten-Buttons auf der Everysize Zahlungs-Detail Seite
- Support für ältere PayPal v5.x Plugins

# 1.6.2
- Kompatibilitätsproblem mit Shopware-Markets Plugin gefixt

# 2.0.0
- Shopware 6.5 kompatibilität

# 2.1.0
- Shopware 6.6 kompatibilität

# 2.2.0
- Shopware 6.7 Kompatibilität (nur im Shopware Store veröffentlicht)

# 2.5.0
- Ein Paket für Shopware 6.6 (ab 6.6.8) und 6.7 mit dem PayPal-Plugin 9 bzw. 10
- Neue Einstellung „Verkaufskanal für importierte Bestellungen“: Teilen sich mehrere Verkaufskanäle dieselben Zugangsdaten (z. B. nur unter „Alle Verkaufskanäle“ eingetragen), landen die Bestellungen im gewählten Kanal statt im zuerst abgearbeiteten. Leer bleibt alles wie bisher
- Import: Gastkunde, Bestellung und everysize-Zuordnung entstehen in einer Datenbank-Transaktion; scheitert die Bestellung, bleibt kein Gastkunde ohne Bestellung zurück
- Import: Die Gastkundin bekommt keine Mail „Deine Registrierung bei …“ mehr; Shopware wertete das Anlegen des Gastkunden als Registrierung und schickte sie über die everysize-Weiterleitungsadresse, auch wenn der Import danach scheiterte
- Import: Bestellungen ab 1.000 € werden importiert (everysize liefert Beträge mit Tausender-Komma, „1,234.56“)
- Import: Der Betrag der Zahlung enthält die Versandkosten; Steuern und Nettobeträge werden wie im Shopware-Warenkorb kaufmännisch auf Cent gerundet
- Versand, Storno und Retoure werden je Bestellung genau einmal gemeldet, auch nach dem Bearbeiten einer Bestellung im Admin; Storno- und Retouren-Export prüfen ihren eigenen konfigurierten Status
- Bestellungen, die bei everysize bereits storniert sind (z. B. per PayPal-Erstattung), werden nicht mehr als versandt gemeldet; stattdessen erscheint ein Fehler im Protokoll
- Netzwerk- oder TLS-Fehler zur everysize-API werden als verständlicher Fehler gemeldet; Zugriffstokens werden je API-Umgebung zwischengespeichert
- Für Testshops lässt sich die API-Umgebung über die Umgebungsvariable `EVERYSIZE_CHECKOUT_API_URL` auf eine everysize-Testumgebung umstellen; Standard bleibt Produktion, erlaubt sind nur bekannte everysize-Adressen
- Entfernt: die eingehenden Endpunkte `GET /api/everysize/protocol` und `GET /api/everysize/productstocks` (nur mit der Client-ID geschützt, von everysize nicht aufgerufen); das Protokoll im Admin bleibt
- Das Protokoll enthält nur noch Läufe, die etwas übertragen haben oder fehlgeschlagen sind

# 2.5.1
- Erstattungsdialog unter Shopware 6.7: Der PayPal-Einzug wird angezeigt; die Auswahlliste dafür war unter 6.7 leer

# 3.0.0
- Neuer Store-Eintrag unter everysize GmbH: technischer Name `EverysizeCheckout`, bisher `ITSEverysizeCheckout`. Für Shopware ist es ein neues Plugin, das das bisherige ersetzt
- Übernimmt beim Installieren und Aktivieren Einstellungen, Bestellzuordnungen, bereits gemeldete Status und das Protokoll des bisherigen Plugins; dessen Daten bleiben unverändert
- Lässt sich nur aktivieren, wenn das bisherige Plugin deaktiviert ist, und verhindert umgekehrt dessen Aktivierung – beide zusammen würden Bestellungen doppelt importieren
- Wurde das bisherige Plugin schon ohne „Daten behalten“ deinstalliert, stellt der erste Import die Bestellzuordnungen aus everysize wieder her (Bestellnummer und Kunden-E-Mail müssen übereinstimmen); auch manuell mit `bin/console everysize:order:recover`
- „API Test“ in den Einstellungen funktioniert unter Shopware 6.7
- Testshops: `EVERYSIZE_CHECKOUT_API_URL` nimmt die Adresse jeder everysize-Testumgebung an (https auf einem Host unter everysize.com); jeder andere Wert bleibt ein Fehler
- Enthält die Verbesserungen von 2.5.0 und 2.5.1, die nie im Store waren: ein Paket für Shopware 6.6 (ab 6.6.8) und 6.7; Versand, Storno und Retoure je Bestellung genau einmal gemeldet; Bestellungen ab 1.000 €; Zahlbetrag mit Versandkosten; keine Registrierungsmail an die Kundin; Einstellung „Verkaufskanal für importierte Bestellungen“; eingehende Endpunkte entfernt
- Sendungsnummern, die nach der Versandmeldung eingetragen oder korrigiert werden, meldet das Plugin einmal nach (bis 14 Tage nach dem Versand, nur solange die Bestellung bei everysize abgeschlossen ist). Versände aus der Zeit davor werden einmal mit everysize abgeglichen und nur gemeldet, wo die Nummer dort fehlt oder abweicht
- Zeigt im Admin die Original-E-Mail der Kundin, sobald everysize sie mitliefert – nur zur Info; Bestellung und Shop-Mails behalten die everysize-Weiterleitungsadresse

# 3.1.0
- Reiter „everysize“ in der Bestellung neu gegliedert: everysize-Bestellung (Bestellnummer, Import, Meldung der Bestellnummer, PayPal-Transaktion, Original-E-Mail), Erstattung (Bezahlt, erstattet, noch offen; Erstattungen als Tabelle; Betrag vorbelegt mit dem offenen Betrag) und Rückmeldungen an everysize (Versand, Sendungsnummern, Storno, Retoure mit Ergebnis). Der Reiter erscheint bei jeder everysize-Bestellung, Beträge mit Währung, Zeiten in der Zeitzone des Benutzers
- Versand wird auch gemeldet, wenn die Bestellung zwischen zwei Abgleichen versandt und schon storniert oder retourniert wurde: erst Versand mit Sendungsnummer, im selben Lauf dann Storno bzw. Retoure. Bisher ging die Versandmeldung samt Sendungsnummer verloren
- Erstattung über everysize wie in allen anderen everysize-Plugins: Der Reiter erstattet nicht mehr über das PayPal-Plugin des Shops, sondern über everysize, das jede Erstattung damit sofort kennt. Die Liste zeigt auch Erstattungen, die direkt in PayPal gemacht wurden; ein doppelter Klick erstattet nur einmal; eine zweite Teilerstattung scheitert nicht mehr am Zahlungsstatus
- Das PayPal-Plugin von Shopware ist keine Voraussetzung mehr
- Bezug als Download im everysize-Partner-Portal (Shop-Integration) statt im Shopware Store

# 3.1.1
- Auch per Composer installierbar: `composer require everysize/checkout-shopware6` (Packagist). Das ZIP aus everysize Business bleibt der Weg für Shops ohne Composer; einer der beiden Wege genügt
- README mit Installation, Update, Einrichtung und Umstieg vom bisherigen Plugin
- Lizenz: MIT
