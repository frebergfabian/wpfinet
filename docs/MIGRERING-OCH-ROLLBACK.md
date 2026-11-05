# Migrering och rollback: Finet Troubleshoot 2.0.1 / WPFINET 3.0.0 → WPFINET 3.1.0

3.1 (liksom 3.0) är en **uppgradering på plats** av samma plugin: katalog `finet-troubleshoot/`, fil `finet-troubleshoot.php` och textdomän `finet-troubleshoot`. Det blir inget parallellt plugin, och inga CPT:er, hooks eller REST-rutter registreras två gånger.

Migreringen är **additiv och idempotent**. Den ändrar inga post-ID, slugs, URL:er, meta, termer eller statistikrader, och den tar inte bort något. Det är verifierat med de riktiga ZIP-filerna i `tests/upgrade/run-upgrade-test.sh` (se avsnitt 6).

> **Bygg inte om i produktion.** Kör allt nedan på staging först. Fråga ansvarig innan något görs i produktion som inte går att ångra.

## 0. Från 3.0.0 till 3.1.0 (dev.finet.se kör 3.0.0)

1. Backup av databas och `wp-content/` (som i avsnitt 1). Spara `finet-troubleshoot-3.0.0.zip` för rollback.
2. **Tillägg → Lägg till nytt → Ladda upp** `finet-troubleshoot-3.1.0.zip` → **Ersätt nuvarande med uppladdad**.
3. Öppna wp-admin som administratör (migreringen körs på `admin_init`) eller kör `wp wpfinet migrate`. **WPFinet → Diagnos** ska visa databasversion 3.1.0.
4. Migreringen lägger bara till kolumnen `street_fold` i `{prefix}wpfinet_access_index` och fyller den för befintliga rader. Moduler, inställningar, hemligheter, Instagraminlägg, samtyckesinställningar och adressindex är oförändrade (verifierat, avsnitt 6).
5. Rensa Cloudflare-cachen för HTML (nya CSS/JS har versionsparameter `?ver=3.1.0`).

Rollback till 3.0.0: `wp option update finet_ts_db_version 3.0.0` och ladda upp 3.0.0-ZIP:en med **Ersätt**. Kolumnen `street_fold` ignoreras av 3.0.

## 1. Före uppgradering (staging och sedan produktion)

| # | Åtgärd | Varför |
|---|---|---|
| 1 | Full databasbackup (mysqldump eller hostingens verktyg) **och** kopia av hela `wp-content/` | Återställning oavsett vad som händer |
| 2 | Spara aktuell plugin-ZIP `finet-troubleshoot-2.0.1.zip` | Krävs för rollback |
| 3 | Exportera inställningar från WPConsent, WPGetAPI (API `finet_access`, endpoint `get_access_data`) och Instagram-pluginet: skärmdumpar plus export där sådan finns | De behövs för att konfigurera ersättarna. **Endpoint-sökväg, autentisering och svarsformat för Access API finns bara där.** |
| 4 | Kontrollera **WPFinet → Inställningar → Avancerat → "Radera data vid avinstallation"**. Ska vara **av**. | Om den är på raderar en avinstallation guider, driftstörningar och statistik. Lägg gärna även `define( 'WPFINET_PREVENT_DATA_DELETION', true );` i `wp-config.php` i produktion. |
| 5 | Lista publika URL:er (startsida, `/tjansteguide/`, `/home/kundservice/`, `/fragor-och-svar/`, `/kontakta-oss/`, `/driftstorningar/` plus några enskilda driftstörningar) och spara HTTP-status och TTFB | Jämförelse efter uppgradering |
| 6 | Kontrollera PHP-tillägg: `sodium`, `intl`, `mbstring`, `curl`, `openssl` | sodium krävs för krypterade hemligheter i databasen; utan sodium används wp-config-konstanter |

## 2. Uppgradering på staging

1. Ladda upp `finet-troubleshoot-3.1.0.zip` via **Tillägg → Lägg till nytt → Ladda upp** och välj **Ersätt nuvarande med uppladdad**. Pluginet förblir aktivt.
2. Öppna wp-admin som administratör. Migreringen körs automatiskt på `admin_init` med lås och loggning. Alternativt: `wp wpfinet migrate`.
3. **WPFinet → Diagnos**: kontrollera att databasversionen är 3.1.0 och läs migreringsloggen (antal före/efter samt eventuell varning om "Radera data").
4. **WPFinet → Diagnos → Kör inventering** (bara läsning). Rapporten visar:
   - var `finet_troubleshoot`, `wpfinet_outages` och `wpfinet_outage_status` används, inklusive i Elementor-data
   - trasiga guidereferenser (saknade eller opublicerade guider)
   - kvarvarande `wpgetapi_endpoint`, `checkAddress()`/`#addressInput`/`#finetApiRaw`, Instagram-shortcodes från tredjepart och WPConsent-referenser
   - Samma rapport som JSON: `wp wpfinet inventory --format=json`.
5. Kontrollera:
   - Guider på Elementor-sidor fungerar (navigering, tillbaka, börja om, CTA:er, WPForms).
   - Driftbanner, `/driftstorningar/` och enskilda driftstörningar med datum-URL ger HTTP 200. **I 2.0.1 gav enskilda driftstörningar HTTP 500 efter 30 s (B25).**
   - Guidebyggaren visar noderna och sparar. **I 2.0.1 visade den 0 noder (B28).**
   - Import och export av en befintlig guide med strategin "Uppdatera" behåller post-ID.
   - Statistiksidan visar historiska siffror.

Alla nya moduler (Adressökning, Instagram, Cookies & samtycke) är **avstängda** efter uppgraderingen. Guider, driftstörningar och statistik fungerar som förut.

## 3. Nya moduler, en i taget (staging → produktion)

### Adressökning (ersätter WPGetAPI-lösningen)
1. **Moduler & integrationer → Adressökning på**.
2. **Adressökning**: välj datakälla.
   - **Steg 1 (rekommenderat först): "Befintlig WPGetAPI-konfiguration".** WPGetAPI måste vara aktivt med API `finet_access` och endpoint `get_access_data`. Sökväg och nyckel läses från WPGetAPI, bara i bakgrundssynken.
   - **Steg 2: "Finet Access API (direkt)".** Fyll i endpoint-sökväg, autentisering och fältmappning från WPGetAPI-konfigurationen. Hemligheten läggs helst i `wp-config.php` som `WPFINET_ACCESS_API_SECRET`. Först därefter kan WPGetAPI inaktiveras.
3. **Testa anslutning**. Förhandsgranskningen visar svarets struktur med maskerade värden. Justera "Sökväg till adresslistan" tills fältmappningen hittar adresserna.
4. **Synka nu**. Gör sedan **Testsökning** med några kända adresser (ansluten, ej ansluten, med bokstav, med å/ä/ö).
5. Ersätt den gamla koden på respektive sida, som inventeringen pekat ut, med `[wpfinet_access_check]`, Elementor-widgeten "Shortcode" eller blocket "WPFINET adressökning". Kontrollera att CTA:erna går till `/tjanster` och `/kontakta-oss`.
6. Kör inventeringen igen. Först när den visar **0** WPGetAPI-referenser och 0 gamla adresskript, och sidorna är verifierade, kan WPGetAPI inaktiveras. Den gamla djuplänken `admin.php?page=wpgetapi_finet_access` visar då en hänvisning till WPFINET.

### Instagram
1. Slå på modulen. Anslut med långlivad token eller OAuth (professionellt konto, `instagram_business_basic`).
2. **Hämta nu**. Lägg `[wpfinet_instagram_feed]` på sidan som i dag använder tredjepartspluginet. Inaktivera det pluginet först när flödet är verifierat.

### Cookies & samtycke (ersätter WPConsent, sist)
1. **Låt WPConsent vara aktivt.** Ställ WPFINET-läget på **Testläge**: bara inloggade WPFINET-administratörer ser WPFINET:s banner och blockering. Besökare skyddas fortfarande av WPConsent.
2. Kör skannern (`scanner/`) mot staging. Granska inventariet: varje post ska få kategori och status "verifierad". Komplettera blockeringsregler och manuella poster.
3. Testa i ren webbläsare med testlägets knappar: *Ny besökare*, *Neka alla*, *Endast statistik*, *Alla* samt återkallelse.
4. Skriv policytexten, låt den **granskas juridiskt** och **godkänn** en version. Lägg `[wpfinet_cookie_policy]` på den **befintliga** cookiepolicysidan och välj sidan under Banner.
5. Kontrollerat byte i produktion, **efter godkännande av ansvarig**:
   1. Ställ läget på **Live** (kräver bekräftelser).
   2. Inaktivera WPConsent direkt därefter.
   3. Rensa Cloudflare-cachen för HTML.
   4. Verifiera med skannern att inga icke-nödvändiga cookies eller tjänster syns före samtycke.

## 4. Rollback

Rollback påverkar inte innehållet: 3.0 har inte ändrat befintliga data, och 3.0:s nya data (egna options och tabeller) ignoreras av 2.0.1.

1. Om samtycke var live: aktivera WPConsent igen **innan** WPFINET-läget stängs av, så att besökare inte står utan skydd.
2. Sätt databasversionen till 2.0.0, annars kör 2.0.1 om sin egen 1.x-migrering som lägger till nycklar i nodmeta:
   ```bash
   wp option update finet_ts_db_version 2.0.0
   ```
   Alternativt i *WPFinet → Diagnos* med SQL: `UPDATE wp_options SET option_value='2.0.0' WHERE option_name='finet_ts_db_version'`.
3. Ladda upp `finet-troubleshoot-2.0.1.zip` med **Ersätt nuvarande med uppladdad**.
4. Kontrollera guidesidor och driftlistor. Notera att enskilda driftstörningssidor då åter har 2.0.1-felet B25.
5. Vid katastrof: återställ databasbackup och `wp-content` från steg 1.

Avinstallera **aldrig** pluginet som rollback-metod. Det raderar data om "Radera data" är på.

## 5. Destruktiva eller känsliga åtgärder som kräver godkännande

- Läge **Live** för samtycke och inaktivering av WPConsent i produktion.
- Inaktivering av WPGetAPI och tredjeparts-Instagram.
- Strikta WPFINET-behörigheter: ändrar vilka roller som får redigera guider och driftstörningar.
- Ändring av permalänkar eller slugs. Ingenting i 3.0 gör detta.

## 6. Verifierat uppgraderingstest

`tests/upgrade/run-upgrade-test.sh <2.0.1.zip>` installerar den riktiga 2.0.1-ZIP:en, skapar guider, driftstörning med taxonomier och tidslinje, sida med shortcodes, inställningar och statistikrader. Därefter installeras den nya ZIP:en på plats och följande jämförs. Samma skript körs med 3.0.0-ZIP:en som startpunkt; då skapas även 3.x-data (adressindex, krypterad hemlighet, egna texter, Instagram- och samtyckesinställningar) och kontrolleras efter uppgraderingen:

| Kontroll | Resultat |
|---|---|
| Pluginet aktivt, exakt en instans | ✔ |
| Post-ID, slugs, datum, innehåll, all postmeta (guider, driftstörningar, sidor) | ✔ oförändrat (7 poster) |
| Termer och term-ID, relationer | ✔ oförändrat |
| `finet_ts_settings` | ✔ oförändrat |
| Statistiktabeller (radinnehåll) | ✔ oförändrat |
| Capabilities | ✔ behållna |
| Databasversion 2.0.0 → 3.1.0 resp. 3.0.0 → 3.1.0, migreringslogg | ✔ |
| 3.0.0 → 3.1.0: moduler, Access-/Instagram-/samtyckesinställningar, hemligheter (dekrypteras), adressindex oförändrade; `street_fold` ifylld; egna texter kvar | ✔ |
| HTTP 200: guidesida, arkiv, datum-URL, slug-URL | ✔ (0,07–0,16 s) |
| Rollback till 2.0.1 resp. 3.0.0: alla data oförändrade, sidor renderas | ✔ |
