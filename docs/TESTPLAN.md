# Manuell testplan – WPFINET 3.0.0

Gå igenom avsnitten i ordning på **staging**. Varje steg har ett förväntat resultat. Bocka av och notera avvikelser med URL, skärmdump och vad du gjorde.

**Tidsåtgång:** ungefär 2–3 timmar för A–N. Samtyckesdelen (K) tar längst.

## 0. Förberedelser

- [ ] Staging är en kopia av produktion (databas + `wp-content`) och har **egen** backup.
- [ ] I staging-sajtens `wp-config.php`: `define( 'WP_ENVIRONMENT_TYPE', 'staging' );`. Krävs för testdata och mock-adresser.
- [ ] Gärna även `define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', true ); define( 'WP_DEBUG_DISPLAY', false );` så att eventuella PHP-varningar hamnar i `wp-content/debug.log`.
- [ ] Notera före uppgradering, för jämförelse:
  - antal guider och driftstörningar
  - ett par guide-ID och slugs
  - URL:en till en enskild driftstörning och om den laddar (2.0.1 ger troligen HTTP 500 efter lång väntan)
  - siffrorna på statistiksidan
- [ ] **WPFinet → Inställningar → Avancerat:** "Radera data vid avinstallation" ska vara **av**.

## A. Uppgradering och data

| # | Gör | Förväntat |
|---|---|---|
| A1 | Tillägg → Lägg till nytt → Ladda upp `finet-troubleshoot-3.0.0.zip` → **Ersätt nuvarande med uppladdad** | "Tillägget uppdaterades". Pluginet heter nu "WPFINET (Finet Troubleshoot)", version 3.0.0, fortfarande aktivt. Det finns bara **ett** sådant plugin i listan. |
| A2 | Öppna **WPFinet → Översikt** | Sidan visar nyckeltal och fyra modulkort. Ingen gul varning om väntande migrering. |
| A3 | **WPFinet → Diagnos** | Databasversion 3.0.0. Migreringsloggen visar "Före" och "Efter" med samma antal och "slutförd utan borttagningar". |
| A4 | Guider och driftstörningar i listorna | Samma antal, samma titlar, samma ID (håll muspekaren över länken: `post=ID`). |
| A5 | **Diagnos → Kör inventering** | Tabellen visar var `[finet_troubleshoot]` med flera finns, även i Elementor-sidor. Rutan "trasiga guidereferenser" visar shortcodes som pekar på saknade eller opublicerade guider. "Oersatta äldre referenser" räknar WPGetAPI-shortcodes, `checkAddress`-skript, Instagram-shortcodes och WPConsent. **Spara rapporten, den behövs i H och K.** |
| A6 | Kontrollera `wp-content/debug.log` | Inga rader med `finet-troubleshoot` (PHP-varningar). |

## B. Guider på webbplatsen (frontend)

| # | Gör | Förväntat |
|---|---|---|
| B1 | Öppna sidor med guider, både Elementor-sidor och vanliga | Guiderna visas som förut. Knapptexterna är nu i temats typsnitt; i 2.0.1 blev de små på grund av en CSS-bugg. **Bedöm om utseendet är OK.** |
| B2 | Klicka igenom en guide: välj svar, Tillbaka, Börja om | Förgrening fungerar. Tillbaka går ett steg bakåt. "Börja om" frågar först och går sedan till start. |
| B3 | Bara tangentbord: Tab och Enter | Alla knappar nås. Efter varje steg hamnar fokus på rubriken. I dialogen "Börja om" stannar Tab inne i dialogen och Esc stänger den. |
| B4 | Två guider på samma sida (om sådan sida finns) | De går att använda oberoende av varandra. |
| B5 | CTA-knappar: telefon, e-post, länk, support, WPForms | Rätt länkar. WPForms öppnas i en modal eller direkt i noden. Formuläret går att skicka. |
| B6 | Driftkontrollnod (om någon guide har en) | Om det finns en aktuell driftstörning som matchar visas den, eller så hoppar guiden till rätt nod. |
| B7 | Mobil (riktig telefon eller 390 px bred webbläsare) | Ingen horisontell scroll. Knapparna är fullbredd. |
| B8 | (3.1.1) Öppna `/guide/` på 1920, 1440 och 1280 px samt 390 och 375 px | Guiden är 760 px bred på desktop och fyller kolumnen på mobil. Rubriker och knappar bryts mellan ord, aldrig bokstav för bokstav. |
| B9 | (3.1.1) Klicka igenom flera steg, långa och korta | Guidens höjd och texten under den ("Frågor och svar") står still. På mobil får bara steg som är längre än 80 % av skärmen guiden att växa. |

## C. Guidebyggaren (admin)

| # | Gör | Förväntat |
|---|---|---|
| C1 | Öppna en befintlig guide | **Noderna syns** i arbetsytan. I 2.0.1 visades 0 noder. Nodtyperna står på svenska. |
| C2 | Klicka på en nod och ändra rubriken | Inspektören under eller bredvid arbetsytan uppdateras. Status "Osparade ändringar". |
| C3 | Lägg till ett svarsalternativ och en CTA-knapp (Knappar (CTA) → Lägg till knapp) | Fälten syns. Värdet `javascript:alert(1)` ger en tom länk efter sparning. |
| C4 | **Spara** | "Guiden är sparad." Ladda om sidan: ändringen finns kvar. Revision skapad (Revisioner i sidopanelen). |
| C5 | Koppla bort startnodens länk och spara | Felmeddelande med vad som är fel. Inget sparas. |
| C6 | **Förhandsvisa** | Guiden körs i en modal utan statistik. |
| C7 | Drag noder, Ångra/Gör om, Auto-layout | Fungerar. |
| C8 | Ta bort en nod som en driftkontroll pekar på | Länkarna dit töms (ingen trasig referens). |

## D. Import och export

| # | Gör | Förväntat |
|---|---|---|
| D1 | Exportera en guide (radåtgärd "Exportera") | JSON-fil med `schema_version` 2.0. |
| D2 | Import/Export → välj filen → Analysera → strategin **Uppdatera** → Importera | "1 importerade". Guiden har **samma ID** som förut. |
| D3 | Samma fil med **Skapa kopia** | Ny guide som utkast med unikt external_id och unik slug. |
| D4 | Samma fil med **Hoppa över** | "1 överhoppade". |
| D5 | En trasig JSON-fil eller en fil med fel `schema` | Tydligt fel. Inget skrivs. |

## E. Driftstörningar

| # | Gör | Förväntat |
|---|---|---|
| E1 | Öppna en **enskild driftstörning** på webbplatsen via datum-URL `/driftstorningar/ÅÅÅÅ/MM/DD/slug/` och via `/driftstorningar/slug/` | **Laddar snabbt med HTTP 200**, i 2.0.1 gav den HTTP 500 efter ca 30 s. Visar status, tider, leverantör, tjänster, område (tomt visas som "–") och publika uppdateringar, men **inte** interna. |
| E2 | Skapa en ny akut driftstörning med "Visa i global driftbanner" | Bannern syns överst på sajten (även utan `wp_body_open`, längst ned). Stäng: den förblir stängd under sessionen. |
| E3 | Lägg till en statusuppdatering utan att ändra statusrullistan | Statusen **behålls**. I 2.0.1 blev den "Planerad". |
| E4 | Skapa fler än 2 aktuella driftstörningar | `[wpfinet_outage_status]` visar rätt antal. I 2.0.1 stannade den på 2. |
| E5 | `[wpfinet_outages status="current" service="internet"]` | Bara matchande. Ändringar syns direkt efter sparning. |
| E6 | `/wp-json/wpfinet/v1/outages/current` i webbläsaren | JSON utan interna anteckningar. |

## F. Statistik

| # | Gör | Förväntat |
|---|---|---|
| F1 | WPFinet → Statistik, 7/30/90 dagar | Siffror för startade, slutförda, avbrutna, slutförandegrad och snitt. Historiska data från 2.0.1 finns kvar. |
| F2 | Med WPConsent aktivt: acceptera statistik i WPConsent och klicka igenom en guide | Nya starter räknas (ladda om statistiksidan). |
| F3 | Neka statistik och klicka igenom en guide | Inga nya rader. |

## G. Inställningar

| # | Gör | Förväntat |
|---|---|---|
| G1 | Ändra **bara** fliken Support och spara | Banner och statistik är **fortfarande på** under sina flikar. I 2.0.1 stängdes de av. |
| G2 | Utseende: ändra kortbakgrund och avstånd | Guiderna får ny bakgrund och nytt avstånd. I 2.0.1 hade inställningarna ingen effekt. |

## H. Adressökning

**Med testdata** (utan riktigt API):

| # | Gör | Förväntat |
|---|---|---|
| H1 | Diagnos → **Skapa testdata**, och Moduler → Adressökning **på** → Spara | Testsidorna listas som länkar. |
| H2 | Öppna "WPFINET test: adressökning" (privat sida) | Formuläret visas med texten "Testläge: … påhittade testadresser". |
| H3 | Sök `Exempelgatan 1` med Enter | "Ansluten", knapp till **/tjanster**. |
| H4 | `exempelgatan 2`, `Exempelgatan 3`, `Exempelgatan 3 a`, `Åtestvägen 5`, `Atestvägen 5`, `Testallen 12b`, `Storgatan`, `Exempelgatan 99` | Ej ansluten → **/kontakta-oss**. "Lägg till bokstaven". Ansluten. Ansluten. Ej ansluten (å ≠ a). Ansluten (é = e). Ogiltigt format. Hittades inte. |
| H5 | Öppna utvecklarverktygen → Nätverk och sök | Svaret innehåller **bara** status och text, aldrig någon adresslista. Sidans HTML innehåller inget `<script>` med data. |
| H6 | Sök snabbt fler än 20 gånger på en minut | "För många sökningar. Vänta en minut." |

**Med riktigt API** (kräver uppgifterna från WPGetAPI):

| # | Gör | Förväntat |
|---|---|---|
| H7 | Adressökning → Datakälla: Finet Access API. Fyll i endpoint-sökväg, autentisering och hemlighet (gärna `WPFINET_ACCESS_API_SECRET` i wp-config). Spara. | "Vad saknas" blir tom. |
| H8 | **Testa anslutning** | HTTP 200. "Listor i svaret" visar sökvägen till adresslistan. Schemat visas med maskerade värden. |
| H9 | Ställ in "Sökväg till adresslistan" och fältnamnen tills "Fältmappning" visar giltiga adresser och statusvärden. **Synka nu.** | "Index uppdaterat: N adresser". |
| H10 | Testsökning på 5–10 kända adresser (ansluten, ej ansluten, med bokstav, med å/ä/ö) | Samma svar som den gamla lösningen gav. |
| H11 | Ersätt gamla adressökningen (enligt rapporten i A5) på en kopia av sidan med `[wpfinet_access_check]` | Fungerar i Elementors Shortcode-widget. CTA:er identiska med förut. |

## I. Instagram

| # | Gör | Förväntat |
|---|---|---|
| I1 | Moduler → Instagram **på**. Öppna "WPFINET test: Instagram" (testdata). | Rutnät med 6 påhittade inlägg, datum, text och videomärke. Bilderna kommer från `/wp-content/uploads/wpfinet-instagram/`. |
| I2 | Mobil | 2 kolumner, ingen horisontell scroll. |
| I3 | Med riktigt konto: Ta bort testdata → Instagram → klistra in långlivad token → Verifiera och spara → **Hämta nu** | "@konto" visas. Efter en stund syns riktiga inlägg. Nätverksfliken på sidan visar **inga** anrop till instagram.com eller cdninstagram.com. |
| I4 | Koppla från kontot | Flödet visar de senast sparade inläggen eller profillänken. Sidan blir inte långsammare. |

## J. Cookies & samtycke

Testa först i **testläge**. WPConsent ska vara aktivt och vanliga besökare märker inget.

| # | Gör | Förväntat |
|---|---|---|
| J1 | Moduler → Cookies & samtycke: **Testläge** → Spara | Inloggad ser du WPFINET:s banner (och sannolikt WPConsents). I en privat webbläsare (utloggad) syns **bara** WPConsent. |
| J2 | Öppna "WPFINET test: spårare" med utvecklarverktygen (Nätverk + Application → Cookies). Klicka **Ny besökare** i testlägets verktygsrad. | Bannern har tre likadana knappar: Acceptera alla, Neka valfria, Anpassa. **Inga** anrop till googletagmanager.com eller youtube-nocookie.com. Videon är en platshållare. Ingen `_ga`-cookie. |
| J3 | **Neka valfria** | Bannern försvinner. Cookien `wpfinet_consent` har alla tre kategorier `false`. Ladda om: fortfarande inga anrop. "Cookieinställningar" finns i sidfoten. |
| J4 | Cookieinställningar → kryssa **Statistik** → Spara val | Google-taggen laddas (anrop i nätverksfliken). Videon är fortfarande blockerad. |
| J5 | Klicka **Tillåt och visa** på videon | Videon laddas (marknadsföring godkänd). |
| J6 | Cookieinställningar → avkryssa Statistik → Spara | Sidan laddas om. Google-taggen laddas inte och `_ga`-cookies är borta. |
| J7 | Tangentbord: Tab genom bannern och dialogen | Allt nås, synligt fokus, Esc stänger dialogen när ett val redan finns. |
| J8 | Kör skannern (`scanner/README.md`) mot staging, eller lägg till poster manuellt | Fynden hamnar under **Inventarium → Att granska** med förslag. "Sedd före samtycke" markeras rött för icke-nödvändiga. |
| J9 | Granska varje post: kategori, syfte, leverantör, lagringstid, status **Verifierad** | En post kan inte verifieras utan kategori. |
| J10 | Policy: skriv utkastet → **förhandsgranska** → (juridisk granskning) → kryssa granskad → **Godkänn ny version** | Versionen listas med datum och användare. |
| J11 | Lägg `[wpfinet_cookie_policy]` på en kopia av cookiepolicysidan och välj sidan under Banner | Inloggad i testläge ser du policyn med tabeller per kategori. Utloggad ser du inget nytt. |
| J12 | **Inte i produktion utan beslut:** Live-läget kräver kryss för "verifierat" och "WPConsent stängs av" | Utan kryssen nekas bytet med ett tydligt meddelande. |

## L. Behörigheter

| # | Gör | Förväntat |
|---|---|---|
| L1 | Som Redaktör: öppna guider och driftstörningar | Fungerar som i 2.0.1. |
| L2 | (Valfritt) Moduler → **Ge rollen Redaktör WPFINET-behörigheter**, sedan **Strikta behörigheter** på | Redaktören kan fortfarande arbeta. En Författare kan **inte** längre skapa eller publicera driftstörningar. Slå av igen om det inte önskas. |

## M. Prestanda

```bash
curl -s -o /dev/null -w "%{http_code} %{time_starttransfer}\n" https://staging.example.se/
```

| # | Gör | Förväntat |
|---|---|---|
| M1 | Kör kommandot ovan 5 gånger mot startsidan, en guidesida och `/driftstorningar/` (gärna både med och utan Cloudflare-cache) | Samma eller bättre än före uppgraderingen. Målet är under 1 s i samma miljö. |
| M2 | Sätt Adressökning till Finet Access API med en felaktig endpoint, och Instagram med en ogiltig token | Sidorna är lika snabba. Sökningen ger "tillfälligt otillgänglig" (max ca 3 s, efter 3 fel omedelbart). |
| M3 | (3.1.1) Chrome DevTools → Lighthouse → Performance, mobil och desktop, på startsidan, `/guide/` och `/felsokningsguide/` med en aktiv driftstörning i bannern. Kör i ett inkognitofönster (tom cache). | CLS under 0,1, normalt nära 0. Under "Avoid large layout shifts" finns inget WPFINET-element (`wpfinet-banner`, `finet-troubleshoot`). |
| M4 | (3.1.1) Stäng bannern och ladda om sidan | Bannern syns inte alls, inte ens kort, och sidan hoppar inte. |

## N. Avslutning och rollback

| # | Gör | Förväntat |
|---|---|---|
| N1 | Diagnos → **Ta bort testdata** | Testsidor och testinlägg borta. Adressökningen tillbaka på riktigt API med tomt testindex. |
| N2 | (Övning) Rollback enligt `MIGRERING-OCH-ROLLBACK.md` §4 | 2.0.1 fungerar igen med samma data. |

## Kända begränsningar att ha i åtanke vid test

- Gränssnittstexter från WordPress själv (t.ex. "Publish", "seconds") är svenska först när sajtens språk är svenska.
- Testläget visar WPFINET:s banner **ovanpå** WPConsents för inloggade administratörer. Det är väntat.
- Förhandsblockeringen fångar inte skript som laddas in i efterhand av ett skript som inte är blockerat, och inte cookies som sätts av servern eller av Cloudflare.
- Metas API-detaljer (version, OAuth-omdirigering med query-parameter) är inte verifierade mot Metas aktuella dokumentation.

## Rapportera avvikelser

Ange avsnitt och nummer (t.ex. "C4"), URL, webbläsare, inloggad eller utloggad, skärmdump och eventuella rader ur `wp-content/debug.log`. Bifoga gärna **Diagnos → Ladda ner diagnosrapport**. Den innehåller inga personuppgifter.
