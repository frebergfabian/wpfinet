# Baslinje: testresultat för 2.0.1 (före ändringar)

Miljö: WordPress 7.1.4, SQLite (sqlite-database-integration), PHP 8.3.6 CLI, `php -S` med router som efterliknar IIS URL Rewrite, tema Twenty Twenty-Five. Pluginet är oförändrat från ZIP-filen.

## Kontraktstester (`bin/test.sh --testsuite contract`)

31 tester, 259 påståenden: **28 gröna, 3 fel**. Alla tre fel har samma orsak: originalbuggen B23 (`GET /guides/{id}` → `ArgumentCountError` på PHP 8).

## HTTP-röktest (`tests/e2e/http-smoke.sh`)

| Kontroll | 2.0.1 |
|---|---|
| `/` (med driftbanner) | 200 ✔ |
| `/driftstorningar/` | 200 ✔ |
| `/driftstorningar/2026/03/04/e2e-fiberavbrott/` | **500 efter 30 s ✘ (B25)** |
| `/driftstorningar/e2e-fiberavbrott/` | **500 efter 30 s ✘ (B25)** |
| Sida med `finet_troubleshoot` ×2, `wpfinet_outage_status`, `wpfinet_outages` | 200 ✔ |
| `/wp-json/wpfinet/v1/outages/current` (döljer intern anteckning) | 200 ✔ |
| `/wp-json/finet-troubleshoot/v1/guides` anonymt | 401 ✔ |
| `/wp-json/wpfinet/v1/analytics/summary` anonymt | 401 ✔ |
| `?rest_route=/wpfinet/v1/outages` (plain permalinks/IIS utan rewrite) | 200 ✔ |

TTFB-median (7 anrop, lokal server, varm): startsida 0,077 s, sida med två guider 0,091 s, driftarkiv 0,082 s.

PHP-varningar i `debug.log` vid demoimport (B8/B26).

## Webbläsartest (`tests/e2e/run-browser.sh`, Chromium via Playwright)

| Kontroll | 2.0.1 | 3.0 |
|---|---|---|
| Två guideinstanser, förgrening, fokus till rubrik, oberoende historik, tillbaka | ✔ | ✔ |
| Omstart med bekräftelse | ✔ | ✔ |
| Fokus stannar i dialogen (Tab) | ✘ (ingen fokusfälla) | ✔ |
| Ingen statistik-session eller cookie utan samtycke | ✔ | ✔ |
| **Guidebyggaren visar befintliga noder** | **✘ 0 noder (B28)** | ✔ 10 noder |
| **Guidebyggaren sparar** | **✘ "Spara inlägget först…" (B28/B27)** | ✔ HTTP 200 |
| Statistiksidan | visar 0 för startade/slutförda oavsett data (B2) | ✔ rätt nycklar |
