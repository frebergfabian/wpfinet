# WPFINET (Finet Troubleshoot) 3.0

Ett produktionsinriktat WordPress-plugin för interaktiva, nodbaserade felsökningsguider, driftstörningar och samtyckesstyrd statistik. Guider publiceras med en shortcode, fungerar i Elementor Shortcode-widgeten och kan flyttas mellan WordPress-installationer som dokumenterad JSON.

## Nytt i 3.0

Se [CHANGELOG.md](CHANGELOG.md) och repo-dokumentationen i `docs/`. Nya moduler är avstängda som standard och slås på under **WPFinet → Moduler & integrationer**:

- `[wpfinet_access_check]`: adressökning mot Finet Access API, som ersätter WPGetAPI-lösningen.
- `[wpfinet_instagram_feed]`: Instagramflöde via Metas officiella API, med lokalt lagrade bilder.
- Cookies & samtycke: banner, förhandsblockering, inventarium och `[wpfinet_cookie_policy]` / `[wpfinet_cookie_settings]`. Ersätter WPConsent efter verifiering.

WP-CLI: `wp wpfinet migrate`, `wp wpfinet inventory`, `wp wpfinet jobs run`, `wp wpfinet access sync|status|lookup`, `wp wpfinet instagram sync|status`.

## Krav

- WordPress 6.5 eller senare (testat 7.1.4)
- PHP 8.1 eller senare (mål: 8.5) med sodium, intl, mbstring
- Moderna webbläsare
- Valfritt: [WP Consent API](https://wordpress.org/plugins/wp-consent-api/), som fungerar med WPFINET:s egen samtyckeshanterare eller en annan CMP
- Valfritt: WPForms för formulärnoder

## Användning

1. Aktivera pluginet. Den importerbara demo-guiden **Internet fungerar inte** skapas första gången.
2. Skapa eller redigera en guide under **WPFinet → Felsökningsguider**.
3. Publicera den och lägg in `[finet_troubleshoot id="123"]` eller `[finet_troubleshoot slug="internet-fungerar-inte"]` på en sida.

Attribut: `id`, `slug`, `class`, `show_title` och `show_restart`. Exempel: `[finet_troubleshoot id="123" show_title="false"]`.

Driftstatus kan visas med `[wpfinet_outages]` och den kompakta statuslänken med `[wpfinet_outage_status]`. Filterattribut: `status`, `service`, `provider`, `area`, `limit` och `filters`.

## Arkitektur

- `finet_ts_guide` är en CPT. Flödet sparas som registrerad post meta så att revisionssystemet kan användas.
- `FlowValidator` kontrollerar grafen: startnod, länkar, dubbletter, slutnoder, nåbarhet och cirklar.
- `ImportManager` kör schema- och grafvalidering innan skrivning. `ImporterV1` avgränsar version 1.0 för kommande migratorer.
- `ExportManager` producerar vanlig JSON utan WordPress-ID, nonce, cache eller revisionsdata.
- Den publika klienten är liten vanilla JavaScript utan jQuery eller externa anrop. Varje shortcode-instans har egen navigeringshistorik i minnet.
- Statistik använder en egen första-partsdatabas och lagras först efter samtycke via WP Consent API/WPConsent. Se [PRIVACY.md](PRIVACY.md).
- Driftstörningar är en separat CPT med status, tidslinje, taxonomier och portabla import/export-poster.

## Utveckling

```bash
composer install
npm install
npm run build
composer lint
composer test
npm test
```

Den byggda frontend-koden ligger med i `assets/build` så pluginet är körbart direkt från ett installationspaket. `assets/src` är ingångspunkterna för `@wordpress/scripts`.

## Kataloger

```text
includes/         PHP-domänlogik, REST, import/export och rendering
assets/src/       Källingångar för admin och frontend
assets/build/     Checkade produktionsassets
examples/         Importerbar demo-guide (single source of truth)
docs/             Importformat och JSON Schema
tests/            PHPUnit- och Jest-tester
```

## Importformat

Se [docs/import-format.md](docs/import-format.md), [JSON Schema 2.0](docs/schema/finet-troubleshoot-2.0.schema.json) och exempelfilen. Importören accepterar både schema 1.0 och 2.0; nya exporter använder 2.0.

## Release

1. Kör lint, tester och `npm run build`.
2. Kontrollera demo-import → export → import i en testinstallation.
3. Höj versionen i pluginhuvud, `FINET_TS_VERSION`, `package.json` och `readme.txt`.
4. Bygg ett ZIP-paket utan `node_modules`, utvecklingsfiler eller personliga konfigurationsfiler.

## Hooks

- Actions: `finet_troubleshoot_before_render`, `finet_troubleshoot_after_render`, `finet_troubleshoot_import_guide`
- Filters: `finet_troubleshoot_guide_data`, `finet_troubleshoot_allowed_html`, `finet_troubleshoot_max_import_size`, `finet_troubleshoot_max_import_guides`, `finet_troubleshoot_max_nodes_per_guide`, `finet_troubleshoot_max_options_per_node`

## Privacy

Pluginet skickar inga externa anrop eller laddar externa typsnitt. Läs den fullständiga beskrivningen av anonym, samtyckesstyrd statistik i [PRIVACY.md](PRIVACY.md).
