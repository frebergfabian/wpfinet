=== WPFINET (Finet Troubleshoot) ===
Contributors: fabianfreberg
Tags: troubleshoot, support, guide, flow, elementor
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 3.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Skapa interaktiva, nodbaserade felsökningsguider med shortcode, import/export och en visuell guidebyggare.

== Description ==

WPFinet gör det möjligt att skapa avancerade supportflöden, hantera driftstörningar och följa anonym, samtyckesstyrd statistik. Varje svar leder till valfritt nästa steg, så guider kan förgrenas, återanvända steg och ha flera slutresultat.

Den publicerade guiden körs lokalt i besökarens webbläsare utan AJAX-anrop mellan steg. Den fungerar i vanliga teman och i Elementors Shortcode-widget.

== Installation ==

1. Ladda upp pluginmappen till `/wp-content/plugins/` eller installera ZIP-filen i WordPress.
2. Aktivera Finet Troubleshoot.
3. Öppna **Felsökningsguider**. En demo-guide skapas vid första aktivering.
4. Publicera en guide och lägg in dess shortcode på önskad sida.

== Usage ==

Använd `[finet_troubleshoot id="123"]` eller `[finet_troubleshoot slug="internet-fungerar-inte"]`.

Tillgängliga attribut är `id`, `slug`, `class`, `show_title` och `show_restart`.

== Import ==

Öppna **Felsökningsguider → Import / Export**, välj en JSON-fil och analysera den. Importen är en dry-run tills administratören bekräftar. Det finns en nedladdningsbar exempelfil och full formatdokumentation i pluginmappen.

== FAQ ==

= Fungerar pluginet med Elementor? =

Ja. Använd Elementors vanliga Shortcode-widget; en särskild Elementor-widget behövs inte.

= Sparar pluginet besökardata? =

Endast efter statistik-samtycke via WP Consent API/WPConsent lagras anonym händelsestatistik i webbplatsens egen databas. Ingen IP-adress, full user-agent eller formulärdata lagras.

= Kan jag flytta en guide till en annan webbplats? =

Ja. Exportera guiden som JSON och importera den på den andra webbplatsen.

== Screenshots ==

1. Guidebyggaren med steglänkning.
2. Importanalys innan databasskrivning.
3. En publicerad guide på mobil.

== Changelog ==

= 3.1.0 =
* Instagram: stöd för både Instagram Login och Facebook Login, automatisk och manuell Instagram Account ID, anslutningstest med Metas felmeddelanden.
* Driftbannern: stängknappen fungerar, ny layout och placering.
* Felsökningsguiden: Finets formspråk, stabil yta som inte hoppar mellan steg.
* Adressökning: datakälla via befintlig WPGetAPI-konfiguration, reservmatchning utan å/ä/ö (bara entydig), gamla texterna som standard.

= 3.0.0 =
* WPFINET 3.0: rättar bl.a. driftstörningssidor (HTTP 500), guidebyggaren och inställningsflikar; nya moduler för adressökning, Instagram och samtycke (avstängda som standard).


= 2.0.1 =
* Rättar adminflikar, driftbanner, driftstörningspermalänkar och dokumentationslänk.

= 2.0.0 =
* WPFinet-vy, grafisk guidebyggare, driftstörningar och samtyckesstyrd statistik.

= 1.0.0 =
* Första versionen med nodbaserad flödesmotor, adminbyggare, frontend, import/export och demo-guide.

== Privacy ==

Finet Troubleshoot gör inga externa nätverksanrop, laddar inga externa typsnitt, använder ingen telemetri och skapar inga cookies som standard.
