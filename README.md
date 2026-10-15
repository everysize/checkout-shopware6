# everysize Checkout — Plugin für Shopware 6

Bindet einen Shopware-6-Shop an den everysize-Direktkauf an: Bestellungen, die
Kundinnen auf everysize.com aufgeben, landen als Bestellung im Shop;
Bestellnummer, Versand, Sendungsnummer, Storno und Retoure gehen zurück an
everysize. Erstattungen laufen über everysize.

Composer-Paket `everysize/checkout-shopware6`, technischer Name
`EverysizeCheckout`.

## Voraussetzungen

* Shopware 6.6 (ab 6.6.8) oder 6.7, PHP wie die jeweilige Shopware-Version
* laufende geplante Aufgaben (Scheduled Tasks) — der Abgleich hängt daran
* ein everysize-Shop mit Checkout und Zugang zu everysize Business
  (business.everysize.com)

Kein PayPal-Plugin nötig: bezahlt wird bei everysize, erstattet über
everysize.

## Installation

Es gibt zwei Wege. **Wähle einen davon** — beide installieren dasselbe
Plugin, du brauchst nicht beide.

### Weg A: ZIP im Admin hochladen

Für Shops ohne Kommandozeile oder ohne Composer-Deployment.

1. In everysize Business unter **Shop-Integration → Shopware** das Plugin als
   ZIP herunterladen.
2. Im Shopware-Admin unter **Erweiterungen → Meine Erweiterungen →
   „Erweiterung hochladen“** das ZIP hochladen (nicht entpacken).
3. „everysize Checkout“ installieren und aktivieren.

**Update:** das neue ZIP genauso hochladen, dann bei „everysize Checkout“
auf „Aktualisieren“.

### Weg B: Composer

Für Shops, die per Composer und Deployment gepflegt werden. Im Verzeichnis
des Shops:

```bash
composer require everysize/checkout-shopware6
bin/console plugin:refresh
bin/console plugin:install --activate EverysizeCheckout
bin/console cache:clear
```

Das Paket landet unter `vendor/everysize/checkout-shopware6`; ein ZIP wird
dafür nicht gebraucht. Ein per Composer installiertes Plugin lässt sich im
Admin nicht aktualisieren oder löschen, das geht dann nur per Composer.

**Update:**

```bash
composer update everysize/checkout-shopware6
bin/console plugin:update EverysizeCheckout
bin/console cache:clear
```

**Von ZIP zu Composer wechseln:** Plugin installiert lassen (nicht
deinstallieren, sonst gehen Einstellungen und Bestellzuordnungen verloren),
den Ordner `custom/plugins/EverysizeCheckout` löschen, dann
`composer require` wie oben und `bin/console plugin:refresh`.

## Einrichten

1. In everysize Business unter **Shop-Integration → Shopware** einen Token
   erzeugen. Das Client Secret wird nur einmal angezeigt.
2. Im Shopware-Admin **Erweiterungen → Meine Erweiterungen → everysize
   Checkout → Konfigurieren**: Client-ID und Client Secret eintragen (Felder
   „Everysize Checkout API ClientId“ und „… ClientSecret“), mit „API Test“
   die Verbindung prüfen.
3. Unter „Bestellungen - Import Zuweisungen“ festlegen, mit welchem Status,
   welcher Zahlungsart und welcher Versandart everysize-Bestellungen angelegt
   werden.
4. Unter „Bestellungen - Export Zuweisungen“ festlegen, welcher Bestellstatus
   als versandt (abgeschlossen) und welcher als storniert gilt, welcher
   Versandstatus als retourniert, und den Versanddienstleister wählen.
   Speichern. **Ohne diese Angaben meldet das Plugin Versand, Storno und
   Retoure nicht an everysize.**

Die Einstellungen gelten je Verkaufskanal und erben von „Alle
Verkaufskanäle“. Teilen sich mehrere Kanäle dieselben Zugangsdaten, legt
„Verkaufskanal für importierte Bestellungen“ fest, welcher Kanal die
Bestellungen importiert.

Bestellungen werden über die Artikelnummer zugeordnet: Die SKU im
everysize-Produktfeed muss der Produktnummer der Variante in Shopware
entsprechen.

## Ablauf

| Richtung | Was | Wann |
|---|---|---|
| everysize → Shop | neue Bestellungen | alle 5 Minuten (Scheduled Task) |
| Shop → everysize | Shopware-Bestellnummer | direkt nach dem Import |
| Shop → everysize | Versand mit Sendungsnummer | Bestellung im Status „abgeschlossen“ laut Export-Zuweisung |
| Shop → everysize | Sendungsnummer nachgetragen oder korrigiert | bis 14 Tage nach dem Versand |
| Shop → everysize | Storno, Retoure | Bestell- bzw. Versandstatus laut Export-Zuweisung |

**Erstattung:** in der Bestellung im Reiter „everysize“, ganz oder
teilweise. Das Geld geht über everysize an das PayPal-Konto zurück, mit dem
die Kundin bezahlt hat. Der Reiter zeigt auch die everysize-Bestellnummer,
die bisherigen Erstattungen und alle Rückmeldungen an everysize.

**Mails:** Die E-Mail-Adresse der Kundin ist eine Weiterleitungsadresse von
everysize; Mails des Shops kommen darüber bei ihr an. Die Bestellbestätigung
schickt der Shop nur, wenn „Bestellbestätigungs Mail senden“ an ist.

## Umstieg vom bisherigen Plugin

Wer noch das bisherige Plugin „everysize checkout“ (technischer Name
`ITSEverysizeCheckout`, Version 2.x) hat: das neue installieren, das
bisherige **deaktivieren (nicht deinstallieren)** und dann das neue
aktivieren. Es übernimmt Zugangsdaten, Einstellungen und
Bestellzuordnungen; danach nur mit „API Test“ die Verbindung prüfen. Beide gleichzeitig aktiv geht bewusst nicht, sonst kämen
Bestellungen doppelt an. Das bisherige Plugin kann später deinstalliert
werden.

## Support

everysize Business → Shop-Integration:
<https://business.everysize.com/shop-integration>

## Lizenz

MIT, siehe [`LICENSE`](LICENSE).
