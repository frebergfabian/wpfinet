# Fasrapport – WPFINET 3.0.0

Gren: `wpfinet-3` (bas: `main` = oförändrad 2.0.1). Varje fas är en egen commit.

## Status per fas

| Fas | Färdigt | Testat | Kvarstår |
|---|---|---|---|
| **A** Inventering och tester | Funktionsinventering, beroendekarta, avvikelser mot registret, 30 verifierade buggar, `contracts.json`, testmiljö med WordPress 7.1.4 | 31 kontraktstester frysta mot 2.0.1 (28 gröna, 3 röda p.g.a. originalbugg B23). HTTP-röktest och webbläsartest mot 2.0.1. | Data från levande databas (se INVENTERING §7) |
| **B** Arkitektur och design | Modulregister och feature flags, `ConsentService`, Secrets, HttpClient med kretsbrytare, rate limiter, migrering 3.0, migreringsrapport/inventering, adminskal med designtokens, buggfixar B1–B30 | 31/31 kontrakt, integrationstester per buggfix, varningsvakt, webbläsare | Visuell genomgång på staging med riktiga guider |
| **C** Access API | Konfigurerbar adapter, index och uppslag, normalisering, REST för en adress, shortcode och block, admin med "vad saknas", mock-data | 12 integrations- och 5 enhetstester, 8 webbläsarkontroller | **Endpoint-sökväg, autentisering och svarsschema är okända.** Fyll i från WPGetAPI och testa mot riktiga API:t. Byt ut gamla sidor. |
| **D** Instagram | Officiellt API (Instagram Login), token/OAuth, bakgrundssynk, lokal bildlagring, tokenförnyelse, shortcode och block | 5 integrationstester (fejkat API), blockregistrering i editorn | Meta-app, konto och verifiering mot Metas aktuella referens |
| **E** Samtycke | Banner, förhandsblockering, WP Consent API-interop, inventarium, versionerad policy, anonym logg, Playwright-skanner, testläge | 9 integrationstester, 21 webbläsarkontroller i live-läge, skanner körd mot testsajten | Riktig skanning av staging, granskning och verifiering av poster, juridisk granskning, kontrollerat byte från WPConsent |
| **F** Regression och release | Release-ZIP med kontrollsumma, uppgraderings- och rollbacktest med riktiga ZIP:ar, prestandatest med döda tjänster, säkerhetsgenomgång, dokumentation | Allt nedan | Körning på IIS, PHP 8.5, MySQL, bakom Cloudflare |

## Testresultat (senaste körning)

| Svit | Kommando | Resultat |
|---|---|---|
| PHP-lint | `bin/php-lint.sh` | OK |
| PHPUnit (kontrakt, enhet, integration) | `bin/test.sh` | 86 tester, 633 påståenden, gröna |
| JS-enhetstester | `npm run test:js` | 4/4 |
| HTTP-röktest och TTFB | `tests/e2e/http-smoke.sh` | 10/10 |
| Webbläsare (guider, adressökning, admin, block) | `tests/e2e/run-browser.sh` | 23/23 |
| Samtycke live och skanner | `tests/e2e/run-consent.sh` | 21/21, skanner rapporterar |
| Uppgradering 2.0.1 → 3.0 → rollback | `tests/upgrade/run-upgrade-test.sh <zip>` | 26/26 |
| UI-granskning (alla admin- och frontendvyer, 1280 och 390 px: JS-fel, PHP-varningar, horisontell scroll) | `tests/e2e/run-ui-review.sh` | 69/69 |
| Döda externa tjänster | `tests/e2e/perf-dead-externals.sh` | 6/6 (TTFB < 0,12 s, 0 anrop) |

## Acceptanskriterier (masterprompt §6)

| Kriterium | Status |
|---|---|
| Befintliga guider: samma post-ID, slug och innehåll. Alla `finet_troubleshoot`-shortcodes fungerar. | ✔ Verifierat i uppgraderingstestet (id + slug) |
| Driftstörningars datum-URL:er och arkiv ger HTTP 200 med samma innehåll. Banner, filter, tidslinje och WPForms fungerar. | ✔ HTTP 200, vilket 2.0.1 inte gav för enskilda sidor. Banner, filter och tidslinje testade. WPForms ej testat (pluginet saknas i testmiljön). |
| Import/export 1.0/2.0 roundtrip utan förlust, statistik behålls | ✔ Inklusive `variables`, som gick förlorade i 2.0.1 |
| Befintliga REST-rutter och hooks fungerar, nya rutter skyddade | ✔ |
| Startsidan snabb även när Instagram och Access API är nere eller tar 30 s | ✔ 0,10 s |
| Adressökning returnerar bara minimal status, CTA-länkar identiska | ✔ `/tjanster`, `/kontakta-oss` |
| Samtycke: före samtycke bara nödvändigt, neka, statistik, återkallelse | ✔ i testmiljön. Policy kräver manuell verifiering. |
| Ingen dubbel banner vid slutligt byte | ✔ Spärr i admin (live kräver bekräftelse att WPConsent stängs av) |
| ZIP med kompilerade assets, lint och tester gröna | ✔ PHP 8.3. WPCS-säkerhetssniffar gröna (falsklarm dokumenterade). IIS/PHP 8.5 återstår. |
| Migreringsrapport med antal, shortcodes, oersatta referenser och fel. Inget destruktivt vid fel. | ✔ Diagnos → Migreringsrapport och `wp wpfinet inventory` |

## Manuell test

Se [TESTPLAN.md](TESTPLAN.md). Testdata för moduler utan API-uppgifter: *WPFinet → Diagnos → Skapa testdata* (inte i miljötypen production).

## Behöver ditt beslut eller underlag
1. **Access API:** endpoint-sökväg, autentisering, headers, query-parametrar och ett exempelsvar (från WPGetAPI-konfigurationen i staging).
2. **Instagram:** Meta-app (app-ID och hemlighet) eller långlivad token för ett professionellt konto.
3. **Samtycke:** juridisk granskning av texter och policy. Beslut om tidpunkt för bytet från WPConsent.
4. **Strikta behörigheter:** om författare och bidragsgivare ska tappa möjligheten att skapa driftstörningar (B11).
5. **Staging:** körning på IIS + PHP 8.5.12 + MySQL + Cloudflare, plus inventeringsrapporten från den riktiga databasen.
