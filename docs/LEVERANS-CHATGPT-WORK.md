# WPFINET 3.1.0 – överlämning till ChatGPT Work

Instruktion för att installera och konfigurera WPFINET 3.1.0 på **dev.finet.se**. Följ stegen i ordning. Allt som kräver ett beslut eller uppgifter som bara Finet har är markerat **MANUELLT**.

> **Inte ditt uppdrag här:** sidorna *Sponsring* och *Jobba hos oss* byggs separat och påverkas inte av pluginet.
>
> **Grundregler:** ingen avinstallation av pluginet (bara *Ersätt*), inga ändringar av slugs/permalänkar, inga hemligheter i sidinnehåll eller chatt, och inga ändringar i samtyckesinställningarna (de fungerar redan).

---

## 1. Installationspaket

| | |
|---|---|
| Fil | `finet-troubleshoot-3.1.0.zip` (240 kB, 136 filer) |
| SHA-256 | `4ceee021ca4a4c56281e6903bb79228243028e7ff13c623742ffbd81d57a245c` |
| Pluginidentitet | `finet-troubleshoot/finet-troubleshoot.php` (samma som 2.0.1 och 3.0.0) |
| Krav | WordPress 6.5+ (testat 7.1.4), PHP 8.1+ (testat 8.3; produktion kör 8.5), PHP-tilläggen `mbstring`, `openssl` eller `sodium`, `intl` rekommenderas |
| Byggs inte på servern | Färdigbyggda CSS/JS ingår. Node behövs inte. |

## 2. Uppgradering från tidigare version

dev.finet.se kör i dag **3.0.0**. Uppgraderingen tar bort inga data och ändrar inga ID, slugs eller URL:er.

1. **Backup:** databas och `wp-content/`. Spara den nuvarande `finet-troubleshoot-3.0.0.zip` för rollback.
2. Kontrollera **WPFinet → Inställningar → Avancerat → "Radera data vid avinstallation" = av**.
3. **Tillägg → Lägg till nytt → Ladda upp tillägg** → välj `finet-troubleshoot-3.1.0.zip` → **Ersätt nuvarande med uppladdad**. Pluginet förblir aktivt.
4. Öppna valfri sida i wp-admin som administratör. Migreringen körs då automatiskt (eller `wp wpfinet migrate`).
5. **WPFinet → Diagnos**: databasversionen ska vara **3.1.0** och migreringsloggen ska sluta med "slutförd utan borttagningar".
6. Rensa Cloudflare-cachen för HTML.
7. Kontrollera att följande ger HTTP 200 och ser rätt ut: startsidan, `/felsokningsguide/`, `/guide/`, `/drift/`, `/driftstorningar/` och en enskild driftstörning.

Vad migreringen gör: lägger till kolumnen `street_fold` i tabellen `{prefix}wpfinet_access_index` och fyller den för befintliga rader. Inget annat ändras.

Från **2.0.1** är stegen desamma (se `docs/MIGRERING-OCH-ROLLBACK.md`). Moduler som var avstängda förblir avstängda.

**Rollback till 3.0.0:**

```bash
wp option update finet_ts_db_version 3.0.0
```

Ladda sedan upp 3.0.0-ZIP:en med **Ersätt nuvarande med uppladdad**. För 2.0.1 sätter du `2.0.0` i stället. Avinstallera aldrig pluginet som rollback.

## 3. Konfiguration per modul

Alla inställningar finns under menyn **WPFinet** i wp-admin. Moduler slås på och av under **WPFinet → Moduler & integrationer**.

### 3.1 Felsökningsguide

Inget behöver konfigureras. Befintliga guider, shortcodes och statistik fungerar som förut och får automatiskt det nya utseendet.

- Utseendet följer Finets tema: Montserrat/Poppins från Elementor, indigo `#24216c` och orange `#fd9800`.
- **WPFinet → Inställningar → Utseende**: värden som någon själv har ändrat gäller fortfarande. 2.x-standardvärden (blå `#005baa` m.fl.) skriver inte över den nya stilen. Ändra inga värden här om ni vill behålla Finet-stilen.
- Ytan reserverar höjden för guidens högsta steg, så att innehåll under guiden inte hoppar mellan stegen.

### 3.2 Driftbanner och driftstörningar

1. **WPFinet → Inställningar → Driftstörningar**:
   - **Visa driftbanner**: på.
   - **Statussida URL**: `https://dev.finet.se/drift/`. Det är sidan som bannerns "+N till" och `[wpfinet_outage_status]` länkar till. Lämnas fältet tomt används `/driftstorningar/` som förut.
2. Bannern visas automatiskt överst på alla sidor när det finns en aktuell driftstörning som är akut eller har "Visa i global driftbanner" ikryssat.
3. Stängknappen döljer bannern för resten av besökarens session. Kommer det en ny eller ändrad driftstörning visas bannern igen.

### 3.3 Instagram

Se **avsnitt 5** för token och Account ID. Därefter:

1. **Moduler & integrationer → Instagram: på** → Spara.
2. **WPFinet → Instagram → Inställningar**: API-modell **Automatiskt**, Antal inlägg **6–12**, Hämtningsintervall **Varje timme**, Profillänk `https://www.instagram.com/<konto>/`.
3. Klicka **Testa anslutning**. Alla steg ska vara OK.
4. Klicka **Hämta nu**. Status ska visa antal sparade inlägg.
5. Lägg in `[wpfinet_instagram_feed limit="6" columns="3"]` där flödet ska synas (Elementor-widgeten **Shortcode**).

### 3.4 Adressökning (Finet Access API)

Se **avsnitt 6**.

### 3.5 Cookies & samtycke

**Ändra ingenting.** Modulen är live och fungerar. Ändra inte läget, kategorierna, blockeringsreglerna eller policytexten. Om en ny tjänst (t.ex. ett inbäddat Instagram-skript) ska läggas till senare görs det under **WPFinet → Cookies & samtycke**, efter beslut.

## 4. Shortcodes som finns

| Shortcode | Attribut (standardvärde) | Användning |
|---|---|---|
| `[finet_troubleshoot]` | `id` eller `slug` (ett av dem krävs), `class`, `show_title` (`true`/`false`), `show_restart` (`true`/`false`) | Felsökningsguide. Oförändrad sedan 2.0.1. |
| `[wpfinet_outages]` | `status` (`all` \| `current` \| `upcoming` \| `past`, standard `all`), `service`, `provider`, `area` (term-slugs), `limit` (`10`), `filters` (`false`) | Lista med driftstörningar. Oförändrad. |
| `[wpfinet_outage_status]` | – | Kort status ("Inga kända driftstörningar" / "N aktuella driftstörningar") med länk till statussidan. |
| `[wpfinet_access_check]` | `title` (tom), `label` (`Din adress`), `button` (`Sök`), `placeholder` (`Skriv din adress (t.ex. Storgatan 12)`), `hide_label` (`0`; `1` = etiketten bara för skärmläsare), `hint` (`1`; `0` = dölj hjälptexten), `theme` (`light` \| `dark`), `class` | Adressökning, en adress per sökning. |
| `[wpfinet_instagram_feed]` | `limit` (inställningens antal), `columns` (`3`, 1–6), `layout` (`grid` \| `list`), `show_caption` (`true`), `show_date` (`true`), `title`, `class` | Instagramflöde från lokalt sparade bilder. |
| `[wpfinet_cookie_policy]` | – | Cookiepolicy med aktuellt inventarium (finns redan på policysidan). |
| `[wpfinet_cookie_settings]` | `label` | Knapp som öppnar cookieinställningarna. |

Block i blockredigeraren: **WPFINET adressökning** (`wpfinet/access-check`) och **WPFINET Instagramflöde** (`wpfinet/instagram-feed`). På Elementor-sidor används widgeten **Shortcode**.

Om en modul är avstängd visar dess shortcode ingenting för besökare. Inloggade redaktörer ser en kort notis i stället för rå `[shortcode]`-text.

## 5. Instagram: autentisering och Account ID

Kräver ett **professionellt Instagramkonto** (Business eller Creator). WPFINET stöder båda Metas modeller och känner igen token-typen själv.

| | A. Instagram-inloggning (rekommenderas) | B. Facebook-inloggning |
|---|---|---|
| Token börjar med | `IGAA` | `EAA` |
| API | `graph.instagram.com` | `graph.facebook.com` |
| Behörighet | `instagram_business_basic` | `instagram_basic`, `pages_show_list` (ev. `business_management`) |
| Krav | Meta-app med produkten *Instagram* → *API setup with Instagram login* | Instagramkontot kopplat till en Facebook-sida som token har åtkomst till |
| Förnyelse | Automatiskt före 60 dagar | Sid-token från långlivad användartoken går inte ut; förnyas inte |

**MANUELLT – skapa token (kräver Finets Meta-inloggning):**

- **A:** developers.facebook.com → Appen → *Instagram* → *API setup with Instagram login* → *Generate access tokens* → lägg till Finets Instagramkonto → kopiera token (långlivad, 60 dagar).
- **B:** Graph API Explorer → välj appen → behörigheterna ovan → *Generate Access Token* → byt till långlivad token → hämta sid-token via `/me/accounts`.

**Ansluta i WordPress:**

1. **WPFinet → Instagram → Anslut konto → Alternativ A: klistra in långlivad token** → **Verifiera och spara**. Token kontrolleras mot Meta innan den sparas krypterat. Den visas aldrig igen.
   - Alternativt i `wp-config.php`: `define( 'WPFINET_INSTAGRAM_TOKEN', '…' );`
2. **Account ID** hittas automatiskt:
   - A: via `/me` (fältet `user_id`).
   - B: via `/me/accounts?fields=instagram_business_account{id,username}`.
   - Har token åtkomst till flera konton visar anslutningstestet en vallista. Välj Finets konto.
   - Manuellt: **Inställningar → Instagram Account ID** (siffror, t.ex. `17841400000000000`). Lämna tomt för automatik.
3. **Testa anslutning** visar varje steg och Metas felkod med förklaring. Vanliga fel:
   - `190`/`463`: token ogiltig eller utgången → skapa ny.
   - `10`/`200`: behörighet saknas.
   - `100`/`33`: fel Account ID eller fel modell.
   - `4`/`17`/`32`/`613`: Metas anropsgräns, vänta.
4. Alternativ B i adminsidan (OAuth "Logga in med Instagram") kräver app-ID, app-hemlighet och att omdirigerings-URI:n `https://dev.finet.se/wp-admin/admin-post.php?action=wpfinet_instagram_oauth` registreras i Meta-appen. Använd det bara om token-metoden inte fungerar.

Sidvisningar anropar aldrig Meta. Bilderna laddas ner i bakgrunden till `wp-content/uploads/wpfinet-instagram/`. Om Meta inte svarar visas de senast sparade inläggen, eller profillänken om inga finns.

CLI: `wp wpfinet instagram test`, `wp wpfinet instagram sync`, `wp wpfinet instagram status`.

## 6. Finet Access API (adressökning)

Fakta från den gamla lösningen:

- Base URL `https://access.finet.se/api/onapi/2.4/`.
- WPGetAPI-API `finet_access`, endpoint `get_access_data`.
- Svaret är en lista med `streetName`, `streetNumber`, `streetLittera` och `accessState`, där `CONNECTED` betyder ansluten.
- Endpoint-sökväg och nyckel finns **bara** i WPGetAPI-konfigurationen.

Säkerhet:

- Besökare frågar bara `POST /wp-json/wpfinet/v1/access/check` med en adress och får bara status för den adressen. Listan lämnar aldrig servern.
- Sidvisningar gör inga API-anrop. Hela listan hämtas i bakgrunden till ett lokalt index.
- Om API:t är nere svarar sökningen från det senaste indexet (med varning efter 24 h, "tillfälligt otillgänglig" efter 7 dygn).
- Sökningarna är begränsade till 20 per minut och IP.
- Anrop från andra webbplatser nekas.

### Steg A – via befintlig WPGetAPI (gör detta först)

1. Kontrollera att pluginet **WPGetAPI** är aktivt och att API `finet_access` / endpoint `get_access_data` finns. Under WPGetAPI-endpointens *Results Format* ska det stå **JSON string** eller **PHP array**.
2. **Moduler & integrationer → Adressökning: på** → Spara.
3. **WPFinet → Adressökning → Anslutning och fältmappning**:
   - Datakälla: **Befintlig WPGetAPI-konfiguration (endast bakgrundssynk)**.
   - WPGetAPI: API-ID `finet_access`, endpoint-ID `get_access_data`.
   - Sökväg till adresslistan: tomt (svaret är listan).
   - Fält: `streetName`, `streetNumber`, `streetLittera`, `accessState`.
   - Ansluten-värden: `CONNECTED`.
   - Synkintervall: **Varje timme**.
   - **Lita på CF-Connecting-IP**: **på** (sajten ligger bakom Cloudflare).
   - Spara.
4. **Testa anslutning**. Resultatet ska vara OK. Under "Listor i svaret" ska `(rot)` stå med ett antal, och under "Fältmappning" ska det finnas giltiga adresser och statusvärden som `CONNECTED`. Adresserna visas maskerade.
5. **Synka nu (vänta på svar)**. Status ska visa "Index: N adresser, aktuellt".
6. **Testsökning** med kända adresser: en ansluten, en ej ansluten, en med bokstav (`12B`), en med å/ä/ö och en som inte finns.

### Steg B – ersätta det gamla skriptet på sidorna

Startsidan `/` och `/home/anslut-dig/` har ett Elementor HTML-element med det gamla skriptet (`checkAddress()`, `#addressInput`, `#finetApiRaw`) och `[wpgetapi_endpoint …]`. På dev visas JSON:en som rå text, så den gamla kontrollen fungerar inte där i dag.

1. Kör **WPFinet → Diagnos → Kör inventering**. Rapporten listar exakt vilka sidor och element som har gammal kod.
2. På varje sådan sida i Elementor:
   - Lägg en **Shortcode**-widget på samma plats.
   - Mörk bakgrund (hero): `[wpfinet_access_check theme="dark" hide_label="1" hint="0" button="Sök"]`.
   - Ljus bakgrund: `[wpfinet_access_check title="Kan du få fiber?"]`.
   - Ta sedan bort det gamla HTML-elementet.
3. Kör inventeringen igen. Den ska visa **0** `wpgetapi_endpoint` och **0** gamla adresskript.
4. CTA:erna går till `/tjanster` ("Visa leverantörens tjänster") vid ansluten och `/kontakta-oss` ("Kontakta oss") annars. Ändras under **Texter och knappar**.

Texterna följer den gamla lösningen: "`{adress}`: ansluten till Finet." respektive "`{adress}`: inte ansluten ännu.", där `{adress}` blir besökarens egen adress.

Matchningen är tolerant:

- Skiftläge, mellanslag, "12B" och "12 b" behandlas lika.
- Text efter ett kommatecken (postnummer/ort) ignoreras.
- é/ü behandlas som e/u.
- å/ä/ö är egna bokstäver. Om API:t stavar utan å/ä/ö matchas det ändå, men bara när exakt en gata passar, så Ågatan och Agatan blandas aldrig ihop.
- Om adressen finns med bokstav och besökaren skrev utan, ber sökningen om bokstaven.

### Steg C – direkt mot API:t (senare, ersätter WPGetAPI helt)

**MANUELLT:** kräver uppgifterna från WPGetAPI → API `finet_access` → endpoint `get_access_data`: endpoint-sökväg, metod, autentisering (header-namn eller query-parameter) och nyckel. Skärmdumpa sidan; kopiera aldrig nyckeln till chatt eller dokument.

1. Lägg nyckeln i `wp-config.php` (rekommenderat): `define( 'WPFINET_ACCESS_API_SECRET', '…' );` (och vid Basic-autentisering `WPFINET_ACCESS_API_USERNAME`). Alternativt **API-hemlighet → Spara hemlighet**, som sparar den krypterat.
2. Datakälla **Finet Access API (direkt)**, fyll i Endpoint-sökväg (relativ till base URL), HTTP-metod, Autentisering och Headernamn/Query-parameter enligt WPGetAPI. Läge: **Index**.
3. Testa anslutning → Synka nu → Testsökning (samma kontroller som steg A).
4. Fungerar allt: WPGetAPI kan inaktiveras (**kräver beslut**). Länken `admin.php?page=wpgetapi_finet_access` visar då en hänvisning till WPFINET.

Om `access.finet.se` bara tillåter vissa IP-adresser måste webbserverns utgående IP vara tillåten. Från vår testmiljö svarade värden inte, se avsnitt 9.

CLI: `wp wpfinet access status`, `wp wpfinet access test`, `wp wpfinet access sync`, `wp wpfinet access lookup "Storgatan 12B"`.

## 7. Säker ersättning av gamla funktioner

Ordningen är viktig: slå på det nya, verifiera, ta bort referenserna, och inaktivera det gamla pluginet först därefter.

| Gammalt | Ersätts av | Säkert byte |
|---|---|---|
| **WPGetAPI** + skriptet `checkAddress()` på `/` och `/home/anslut-dig/` | `[wpfinet_access_check]` | 1) Avsnitt 6 steg A. 2) Byt widgetarna (steg B). 3) Inventering visar 0. 4) Låt WPGetAPI vara **aktivt** så länge datakällan är WPGetAPI. 5) Efter steg C kan WPGetAPI inaktiveras (beslut). |
| **Instagram-plugin från tredjepart** (inventeringen letar efter `[instagram-feed]`, `[sbi…]`, `[insta-gallery]`, `[jr_instagram]`, `[wpzoom-instagram…]`, `[spotlight-instagram]`) | `[wpfinet_instagram_feed]` | 1) Avsnitt 5 och 3.3. 2) Lägg den nya shortcoden bredvid den gamla på staging och jämför. 3) Ta bort den gamla shortcoden. 4) Inventering visar 0 → inaktivera det gamla pluginet (beslut). |
| **WPConsent** | WPFINET Cookies & samtycke (redan live på dev) | Redan utfört enligt uppgift. Kontrollera bara att WPConsent är **inaktivt** så att det inte finns två banners, och att inventeringen visar 0 `wpconsent`-referenser. Aktivera aldrig WPConsent och WPFINET live samtidigt. |

## 8. Manuella åtgärder (lista)

| # | Åtgärd | Vem |
|---|---|---|
| M1 | Backup av databas och `wp-content/` före uppgradering | ChatGPT Work / drift |
| M2 | Skapa Instagram-token (Meta-inloggning krävs) och vid behov välja konto | Finet |
| M3 | Bekräfta att WPGetAPI `finet_access`/`get_access_data` fungerar och har Results Format JSON/PHP array | ChatGPT Work |
| M4 | Byta det gamla adresskriptet mot shortcoden på `/` och `/home/anslut-dig/` | ChatGPT Work |
| M5 | Testsökningar med kända adresser, svaren bekräftade av Finet | Finet |
| M6 | Ta fram endpoint-sökväg/autentisering/nyckel för direktanslutning (steg C) | Finet |
| M7 | Beslut: inaktivera WPGetAPI respektive gammalt Instagram-plugin | Finet |
| M8 | Om dev kör med `DISABLE_WP_CRON`: Windows Schemaläggaren var 15:e minut: `php.exe C:\wp-cli\wp-cli.phar --path=C:\inetpub\wwwroot wpfinet jobs run` (se `docs/DRIFT-IIS-CLOUDFLARE.md`) | Drift |
| M9 | Cloudflare: undanta `/wp-json/wpfinet/v1/access/check` från cache. Gärna även en rate-limit-regel (30/min per IP). Rensa HTML-cachen efter uppgradering. | Drift |
| M10 | Verifiera på riktig IIS/PHP 8.5/MySQL: guidesida, driftbanner (stäng), adressökning, Instagramflöde | ChatGPT Work |

## 9. Testresultat och kända begränsningar

Körda i testmiljön: WordPress 7.1.4, PHP 8.3, SQLite, Chromium/Playwright, 2026-11-05.

| Test | Resultat |
|---|---|
| PHP-syntax, alla filer | OK |
| PHPUnit (kontrakt mot 2.0.1, enhet, integration) | **102 tester, 721 kontroller, 0 fel** |
| JS-enhetstester (guide, banner) | 7/7 |
| Webbläsare: guide, banner (stäng/kom ihåg), driftsidor, adressökning, Instagram, admin | 29/29 |
| Samtycke: blockering före val, neka/acceptera/återkalla, statistik bara med samtycke | 21/21 |
| Guidens stabilitet (demo och den riktiga 31-nodsguiden från dev.finet.se), desktop + mobil | 22/22 + 22/22 |
| UI-granskning, 69 vyer (admin + publikt, 1280 och 390 px) | 0 vyer med fel |
| Uppgradering 2.0.1 → 3.1.0 med riktiga ZIP:ar, inkl. rollback | 26/26 |
| Uppgradering 3.0.0 → 3.1.0 inkl. adressindex, hemligheter, egna texter, rollback | 40/40 |
| Prestanda: 0 utgående anrop vid sidrendering, även när externa API:er hänger; TTFB 25–90 ms | OK |
| WPCS-säkerhetsregler (escaping, nonce, prepared SQL) | Bara kända falsklarm: nonce kontrolleras i gemensam `guard()`, tabellnamn från `$wpdb->prefix` |

Kända begränsningar:

1. **Meta-API:t är inte verifierat mot ett riktigt konto.** Metas dokumentation och API:er var inte nåbara från testmiljön. Anropen följer Metas dokumenterade modeller och är testade med simulerade svar. Verifiera med **Testa anslutning** (M2). API-version kan sättas under Instagram → Inställningar om Meta kräver det.
2. **access.finet.se svarade inte** från testmiljön (anslutningen återställdes, troligen IP-begränsning). Endpoint-sökväg, autentisering och verkligt svarsformat är därför inte verifierade. Fältmappningen följer den gamla lösningen. WPGetAPI-vägen (steg A) undviker problemet eftersom den använder den fungerande konfigurationen.
3. **WPGetAPI:s funktion** `wpgetapi_endpoint()` är testad med en ersättare som har samma signatur. Om WPGetAPI har cache påslagen för endpointen kan indexet bli lika gammalt som den cachen.
4. **IIS, PHP 8.5 och MySQL** är inte testade här (SQLite och PHP 8.3 i testmiljön). Koden använder inga SQLite- eller Linux-specifika funktioner. M10 behöver göras.
5. Om adressen finns **med bokstav** och besökaren skriver utan, ber sökningen om bokstaven. Den gamla lösningen godtog vilken bokstav som helst. Det är medvetet, så att besökaren aldrig får fel svar.
6. Läget "Uppslag per sökning" (inte rekommenderat) gör ett API-anrop per sökning med 3 s timeout. Indexläget, som WPGetAPI-vägen alltid använder, påverkas inte av ett långsamt API.
7. Testläge med påhittade adresser är avstängt i produktionsmiljö (`wp_get_environment_type() = production`) om inte `WPFINET_ACCESS_ALLOW_MOCK` är satt.

Mer dokumentation i repot: `docs/MIGRERING-OCH-ROLLBACK.md`, `docs/DRIFT-IIS-CLOUDFLARE.md`, `docs/SAKERHET-OCH-PRESTANDA.md`, `docs/TESTPLAN.md`, `finet-troubleshoot/CHANGELOG.md`.
