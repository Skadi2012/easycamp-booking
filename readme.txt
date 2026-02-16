=== EasyCamp Booking ===
Contributors: skadi12
Tags: booking, camping, ical, reservation, calendar
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.01
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Ein leichtgewichtiges Buchungssystem für Campingplätze mit iCal-Export für Google Kalender und integrierter Belegungsprüfung.

== Description ==

EasyCamp Booking ist eine All-in-One Lösung für kleine Campingplätze oder Vereine. Es bietet ein Frontend-Formular inklusive interaktivem Belegungskalender, eine automatische Buchungsnummern-Generierung und eine einfache Betreuer-Verwaltung direkt auf der Webseite.

**Hauptmerkmale:**
* **Interaktiver Kalender:** Gäste können An- und Abreise direkt im Kalender wählen.
* **Verfügbarkeitsprüfung:** Verhindert Überbuchungen basierend auf einstellbaren Slots.
* **iCal-Export:** Synchronisiere deine Buchungen automatisch mit Google Kalender oder Outlook.
* **Betreuer-Modus:** Verwalte Buchungen (Bestätigen, Bezahlt-Markierung, Storno) direkt im Frontend via Passwort-Login.
* **E-Mail Benachrichtigungen:** Automatische Mails an Admins und Gäste.
* **Manuelle Buchungen:** Schnelles Eintragen von telefonischen Reservierungen durch das Personal.

== Installation ==

1. Lade den Ordner `easycamp-booking` in das Verzeichnis `/wp-content/plugins/` hoch.
2. Aktiviere das Plugin im WordPress-Menü 'Plugins'.
3. Gehe zu 'EasyCamp' im Admin-Menü, um die Einstellungen (Slots, E-Mail, Passwort) anzupassen.
4. Füge den Shortcode `[easycamp_form]` auf einer beliebigen Seite ein.

== Screenshots ==

1. Das Buchungsformular mit dem interaktiven Kalender.
2. Die Admin-Einstellungen mit dem iCal-Link.
3. Die Betreuer-Ansicht zur Verwaltung der Anfragen.

== Changelog ==

= 1.01 =
* NEU: Validierung gegen Buchungen in der Vergangenheit.
* NEU: Tag-genaue Prüfung der Slot-Verfügbarkeit.
* NEU: Unterstützung für mehrere Admin-E-Mail-Adressen (Komma-getrennt).
* FIX: iCal-Export Formatierung für ganztägige Ereignisse optimiert.

= 1.0 =
* Initialer Release.

== Frequently Asked Questions ==

= Wie ändere ich die Anzahl der verfügbaren Plätze? =
Gehe im WordPress-Backend auf den Menüpunkt "EasyCamp". Dort kannst du unter "Max. Plaetze pro Tag" die Kapazität anpassen.

= Wo finde ich den iCal Link? =
Der Link wird direkt auf der Einstellungsseite im WordPress-Backend generiert und angezeigt.

= Wie logge ich mich als Betreuer im Frontend ein? =
Unter dem Buchungsformular befindet sich ein kleines Login-Feld. Das Passwort legst du in den Admin-Einstellungen fest.

Buy me a Coffee Link
https://www.paypal.com/paypalme/Tobsta