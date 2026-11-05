# WPFINET 3.0

WordPress-plugin för Finet: felsökningsguider, driftstörningar, samtyckesstyrd statistik, adressökning mot Finet Access API, Instagramflöde och samtyckeshantering för cookies. Version 3.0 är en **bakåtkompatibel uppgradering** av `Finet Troubleshoot 2.0.1`. Pluginidentiteten är densamma (`finet-troubleshoot/finet-troubleshoot.php`), och data, URL:er, shortcodes, REST-rutter och hooks behålls.

| Dokument | Innehåll |
|---|---|
| [docs/FASRAPPORT.md](docs/FASRAPPORT.md) | Status per fas, testresultat, acceptanskriterier och vad som behöver beslut |
| [docs/fas-a/INVENTERING.md](docs/fas-a/INVENTERING.md) | Inventering av 2.0.1, kontrakt, avvikelser mot registret, 30 buggar och okända uppgifter |
| [docs/MIGRERING-OCH-ROLLBACK.md](docs/MIGRERING-OCH-ROLLBACK.md) | Steg för staging och produktion, modul för modul, samt rollback |
| [docs/DRIFT-IIS-CLOUDFLARE.md](docs/DRIFT-IIS-CLOUDFLARE.md) | IIS, Task Scheduler, wp-config-konstanter och Cloudflare |
| [docs/SAKERHET-OCH-PRESTANDA.md](docs/SAKERHET-OCH-PRESTANDA.md) | Publik yta, hot och åtgärder, mätvärden och begränsningar |
| [scanner/README.md](scanner/README.md) | Headless-skanner för cookies (Playwright, körs utanför WordPress) |

## Struktur

```text
finet-troubleshoot/   Pluginet (det som levereras i ZIP:en)
  includes/           PHP (PSR-4, namespace FinetTroubleshoot\)
    Core/             Moduler/feature flags, migrering, hemligheter, HTTP-klient, rate limit, loggning
    Access/ Instagram/ Consent/ Migration/ CLI/   Nya 3.0-moduler
    Admin/ Frontend/ Outages/ Analytics/ Import/ Export/ REST/ …   2.0-delar (kontrakten oförändrade)
  assets/src → assets/build   Vanilla JS/CSS (byggs med bin/build-assets.mjs, inga beroenden)
scanner/              Fristående cookie-skanner (Node + Playwright)
tests/                PHPUnit (kontrakt, enhet, integration), JS, E2E (Playwright), uppgradering
bin/                  Testmiljö, tester, build och release
docs/                 Rapporter och planer
```

## Utveckling och test

```bash
composer install
npm install
bin/setup-test-env.sh
bin/check.sh
```

`bin/setup-test-env.sh` installerar WordPress 7.1.4 med SQLite i `.test-env/`. `bin/check.sh` kör lint, build, PHPUnit och JS.

E2E-sviterna:

```bash
tests/e2e/http-smoke.sh
tests/e2e/run-browser.sh
tests/e2e/run-consent.sh
tests/e2e/perf-dead-externals.sh
tests/upgrade/run-upgrade-test.sh /sökväg/finet-troubleshoot-2.0.1.zip
```

## Release

```bash
bin/build-release.sh
```

Skriptet ger `dist/finet-troubleshoot-<version>.zip` plus `.sha256`. Versionen måste stämma i pluginhuvudet, `FINET_TS_VERSION` och `readme.txt`.

Grenen `main` innehåller oförändrade 2.0.1 som referens. Utvecklingen sker på `wpfinet-3`.
