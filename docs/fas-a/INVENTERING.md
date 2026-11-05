# Fas A – Funktionsinventering och beroendekarta (Finet Troubleshoot 2.0.1)

**Källa:** `finet-troubleshoot-2.0.1.zip` (65 filer), inlagd oförändrad som första commit i detta repo (`main`, commit "Baseline").
**Metod:** All PHP, JS, CSS, JSON-schema, dokumentation och test lästes i sin helhet. Kontrakten nedan är verifierade mot koden **och** mot en körande WordPress 7.1.4 (SQLite) där 2.0.1 aktiverades. Kontraktstesterna i `tests/php/Contract/` frystes mot 2.0.1 innan någon kod ändrades.

> Denna inventering beskriver **koden**, inte den levande webbplatsens data. Allt som rör verkliga guider, driftstörningar, sidor, Elementor-data, WPConsent-, WPGetAPI- och Instagram-konfiguration är markerat som *okänt* i avsnitt 7 och måste hämtas från staging/produktion.

Maskinläsbar version: [`contracts.json`](contracts.json).

---

## 1. Översikt

| Del | Filer | Ansvar |
|---|---|---|
| Bootstrap | `finet-troubleshoot.php`, `includes/Plugin.php` | Konstanter, PSR-4-liknande autoloader (`FinetTroubleshoot\` → `includes/`), aktiverings-/deaktiveringshooks, `plugins_loaded` → `Plugin::boot()` |
| Livscykel | `includes/Activator.php`, `includes/Core/*` | Standardinställningar, capabilities, statistiktabeller, cron, demo-guide, migrering 1.x → 2.0 |
| Guider | `PostTypes/GuidePostType.php`, `Frontend/*`, `Validation/FlowValidator.php`, `Admin/GuideEditor.php`, `assets/src/frontend`, `assets/src/admin` | CPT, shortcode, rendering, grafvalidering, visuell byggare |
| Import/export | `Import/*`, `Export/ExportManager.php`, `docs/`, `examples/` | Schema 1.0/2.0, dry-run, strategier, roundtrip |
| Driftstörningar | `Outages/*`, `assets/src/outages` | CPT, taxonomier, metaboxar, permalänkar, shortcodes, banner, publikt REST, import/export |
| Statistik | `Analytics/*` | Samtyckesstyrd händelseinsamling, egna tabeller, rapport-REST, städning |
| Integrationer | `Integrations/WPForms.php` | WPForms via dess publika shortcode |
| Admin | `Admin/AdminMenu.php`, `Admin/Settings.php` | WPFinet-meny, dashboard, statistik-, import/export-, format- och inställningssidor |
| REST (admin) | `REST/Controller.php` | `finet-troubleshoot/v1` |
| Avinstallation | `uninstall.php` | Raderar endast om `advanced.delete_data` är sant |

## 2. Beroendekarta (klass → WordPress-hook)

```
plugins_loaded ─► Plugin::boot()
  ├─ GuidePostType ........ init: register_post_type(finet_ts_guide) + register_post_meta ×5
  ├─ OutageModule ......... init: CPT wpfinet_outage + 3 taxonomier + ev. flush_rewrite_rules
  │                         add_meta_boxes_wpfinet_outage, save_post_wpfinet_outage (save, flush)
  │                         post_type_link, rewrite_rules_array, wp_enqueue_scripts
  │                         shortcodes wpfinet_outages / wpfinet_outage_status
  │                         the_content (single), wp_body_open (banner), wp_footer (banner fallback)
  │                         deleted_post (flush), rest_api_init (wpfinet/v1/outages*)
  ├─ MigrationManager ..... admin_init: 1.x→2.0 om finet_ts_db_version !== '2.0.0'
  ├─ Cron ................. wpfinet_daily_maintenance → Database::cleanup()
  ├─ Renderer ............. wp_enqueue_scripts: registrerar finet-troubleshoot (css/js)
  ├─ Shortcode ............ finet_troubleshoot
  ├─ AdminMenu ............ admin_menu, manage_finet_ts_guide_posts_columns/_custom_column,
  │                         post_row_actions, admin_post_finet_ts_duplicate, admin_post_finet_ts_export,
  │                         admin_notices, admin_init (reinstall demo), admin_enqueue_scripts
  ├─ Settings ............. admin_init: register_setting(finet_ts_settings)
  ├─ GuideEditor .......... add_meta_boxes_finet_ts_guide, admin_enqueue_scripts
  ├─ REST\Controller ...... rest_api_init: finet-troubleshoot/v1/*
  ├─ EventController ...... rest_api_init: wpfinet/v1/events, wpfinet/v1/analytics/summary
  └─ ConsentManager ....... filter wp_consent_api_registered_{plugin_basename}
```

Externa beroenden (valfria): WP Consent API (`wp_has_consent`, `wp_has_service_consent`, JS-händelser `wp_listen_for_consent_change`, `wp_consent_api_status_change_service`), WPConsent (konstanter `WPCONSENT_VERSION`/`WPCONSENT_PLUGIN_FILE` eller klass `WPConsent`), WPForms (CPT `wpforms` + shortcode `[wpforms]`), `wp-api-fetch`, `wp-i18n`, `wp.media`.

Inga externa nätverksanrop görs av pluginet (verifierat: ingen `wp_remote_*`, `fetch` endast mot egna REST-rutter).

## 3. Kontrakt som måste bevaras

### 3.1 Shortcodes
| Shortcode | Attribut (standard) | Beteende |
|---|---|---|
| `finet_troubleshoot` | `id` (''), `slug` (''), `class` (''), `show_title` (''), `show_restart` ('') | `id` vinner över `slug`. Endast `publish`. `slug` söker `post_name`. `show_title`/`show_restart` tolkas med `FILTER_VALIDATE_BOOLEAN` och skriver över globala/guide-inställningar. Felmeddelande med ID visas bara för `edit_posts`. Output: `<section id="finet-ts-instance-{postID}-{n}" class="finet-troubleshoot finet-troubleshoot--{animation} {class}" style="--wpfinet-*" data-finet-troubleshoot>` + `<script type="application/json" class="finet-troubleshoot__data">` + `.finet-troubleshoot__mount`. |
| `wpfinet_outages` | `status` ('all'), `service`, `provider`, `area` (''), `limit` (10), `filters` ('false') | **`status` är en "bucket"**: `all` → current+upcoming+past, annars `current`/`upcoming`/`past` (ogiltigt → `current`). Filter = term-**slug**. `filters` läses men används inte. |
| `wpfinet_outage_status` | – | Länk till `home_url('/driftstorningar/')`, klass `is-affected`/`is-clear`. |

### 3.2 REST
Alla rutter testas i `tests/php/Contract/RestContractTest.php`.

**`finet-troubleshoot/v1`** (cookie-auth + `X-WP-Nonce`)
| Rutt | Metod | Behörighet | Svar |
|---|---|---|---|
| `/guides` | GET | `manage_wpfinet_guides` **eller** `edit_posts` | Array av `{id,title,slug,description,status,external_id,start_node,nodes,settings}` (publish/draft/private, sorterat på modified DESC) |
| `/guides/{id}` | GET | samma + `edit_post` | Objekt enligt ovan; 404 `finet_ts_not_found`; 403 `finet_ts_forbidden` |
| `/guides/{id}` | POST/PUT/PATCH | samma | `{guide, validation}`; 400 `finet_ts_invalid_payload`/`finet_ts_invalid_flow` (med `data.validation`). Skapar revision. Uppdaterar **inte** titel/slug/status, bara excerpt + meta. |
| `/guides/{id}/duplicate` | POST | samma | Guideobjekt för nytt utkast, titel "… – Kopia", nytt external_id `{slug}-{8 tecken}` |
| `/validate` | POST | samma | `{valid, errors[], warnings[], summary{nodes,terminals}}` |
| `/import/preview` | POST (multipart `file`) | `manage_wpfinet_settings` **eller** `manage_options` | `{valid, errors, warnings, guides[...+existing_post_id+flow], summary{guides,nodes,outages}, outages[], token}`; 400 `finet_ts_missing_file`/`finet_ts_file_too_large`/`finet_ts_invalid_json`. Token sparas 15 min i transient `finet_ts_import_{uuid}` bundet till användar-ID. |
| `/import` | POST `{token, strategy}` | samma | `{items[], errors[], warnings[], imported, skipped, failed}`; 400 `finet_ts_import_expired` |
| `/export/{id}` | GET | samma | Exportdokument 2.0 med **en guide och alla driftstörningar** |

**`wpfinet/v1`** (publika)
| Rutt | Metod | Behörighet | Svar |
|---|---|---|---|
| `/outages/current` | GET | publik | Array av `public_data` (se 3.6), max 50, filter `service`/`provider`/`area` (slug) |
| `/outages` | GET | publik | Som ovan + `status=current|upcoming|past` |
| `/events` | POST | publik | `{accepted:false, reason:'consent_required'}` utan samtycke; annars `{accepted:true, stored:n}`; 400 `wpfinet_invalid_event`. Max 25 händelser/anrop, 120 händelser/min/session. |
| `/analytics/summary` | GET `days` (1–365, std 30) | `view_wpfinet_statistics` eller `manage_options` | `{days, starts, completions, abandoned, completion_rate, average_steps}` |

**WordPress core-rutter som också är publika kontrakt** (ej nämnda i registret – `show_in_rest => true`): `/wp/v2/finet_ts_guide` (+revisions), `/wp/v2/wpfinet_outage` (+revisions, autosaves), `/wp/v2/wpfinet_provider`, `/wp/v2/wpfinet_service`, `/wp/v2/wpfinet_area`.

### 3.3 Hooks
- Actions: `finet_troubleshoot_before_render( $post_id, $data )`, `finet_troubleshoot_after_render( $post_id, $data )`, `finet_troubleshoot_import_guide( $post_id, $guide, $strategy )`.
- Filters: `finet_troubleshoot_guide_data( $data, $post_id )`, `finet_troubleshoot_allowed_html( $allowed )`, `finet_troubleshoot_max_import_size` (5 MB), `finet_troubleshoot_max_import_guides` (100), `finet_troubleshoot_max_nodes_per_guide` (500), `finet_troubleshoot_max_options_per_node` (50), `wpfinet_consent_provider_available( $available )`.
- Pluginet registrerar sig hos WP Consent API via `wp_consent_api_registered_finet-troubleshoot/finet-troubleshoot.php`.

### 3.4 Data
- **CPT `finet_ts_guide`**: `public=false`, `show_ui`, `show_in_rest`, `capability_type=post`, `supports=title,excerpt,revisions`, `rewrite=false`. Meta (registrerad, `revisions_enabled`): `_finet_ts_external_id`, `_finet_ts_start_node`, `_finet_ts_nodes` (array), `_finet_ts_settings` (array), `_finet_ts_schema_version`.
- **Nodstruktur** (efter `SchemaValidator::sanitize_node`): `id, type, title, heading, intro, content, image{url,alt,caption,placement,zoom}|null, icon, button_text, next, options[{id,label,next,style,icon}], css_class, admin_comment, result_type, support{inherit_global,phone,email,url,opening_hours}, editor_position{x,y}, ctas[{id,type,label,value,form_id,form_title}], wpforms{form_id,form_title,embedded}, outage{service,provider,area,match_node,no_match_node,mode}, meta{}`. 1.x-migreringen kan även ha lagt till `image_position`.
- **CTA-typer**: `phone, email, wpforms, url, support, restart, back, internal`.
- **CPT `wpfinet_outage`**: `public=true`, `has_archive='driftstorningar'`, `rewrite.slug='driftstorningar'`, `supports=title,editor,excerpt,revisions,author`, `capability_type=post`.
- **Taxonomier** `wpfinet_provider|service|area`: hierarkiska, `rewrite=false`, `show_in_rest`.
- **Outage-meta**: `_wpfinet_incident_type` (acute|planned), `_wpfinet_outage_status`, `_wpfinet_incident_start`/`_estimated_end`/`_actual_end` (`Y-m-d H:i:s`, sajtens tidszon), `_wpfinet_priority`, `_wpfinet_banner` ('1'|'0'), `_wpfinet_timeline` (array av `{time,text,status,author,public}`), `_wpfinet_area_note`, `_wpfinet_internal_note`, `_wpfinet_external_id`.
- **Options**: `finet_ts_settings` (sektioner general, appearance, support, outages, analytics, advanced), `finet_ts_demo_installed`, `finet_ts_schema_version`, `finet_ts_db_version`, `finet_ts_upgrade_notice`, `finet_ts_migration_log` (max 20), `wpfinet_outage_rewrite_version` ('2.0.1').
- **Transients**: `finet_ts_activation_notice`, `finet_ts_import_{uuid}`, `wpfinet_outages_{md5}` (5 min), `wpfinet_event_rate_{md5}` (1 min).
- **Tabeller**: `{prefix}wpfinet_sessions` (`anonymous_session_id` UNIQUE, guide_id, started_at, last_event_at, completed_at, result_node, device_class, page_path, event_count, status), `{prefix}wpfinet_events` (anonymous_session_id, event_type, guide_id, node_id, option_id, cta_type, form_id, outage_id, created_at, metadata).
- **Klientlagring**: `sessionStorage.wpfinet_analytics_session` (bara efter samtycke), `sessionStorage.wpfinet_banner_dismissed_{version}`. **Inga cookies sätts av pluginet.**

### 3.5 URL:er
- Arkiv `/driftstorningar/`. Enskild: `get_permalink()` = `/driftstorningar/{Y}/{m}/{d}/{post_name}/` där datumet är **publiceringsdatum (`post_date`)**, inte incidentstart. Datumdelen valideras inte – regeln matchar bara slug.
- WordPress standardregel för CPT:n gör att även `/driftstorningar/{post_name}/` når samma post. Båda ska fortsätta fungera.

### 3.6 Publik driftdata (`OutageRepository::public_data`)
`{id,title,url,type,status,classification,priority,start,estimated_end,actual_end,providers[],services[],areas[],area_note,summary,content,timeline[public only]}`. Klassning: `past` om status resolved/cancelled eller faktiskt slut satt; annars `upcoming` om start > nu, annars `current`. Sortering: prioritet DESC, start ASC.

### 3.7 Admin
Menyslugs exakt som registret. Därutöver: `admin-post.php?action=finet_ts_duplicate|finet_ts_export|finet_ts_reinstall_demo`, inställningsflikar `general, appearance, support, outages, analytics, system, advanced`, kolumner i guidelistan (Status, Shortcode, Antal steg, Startsteg).

### 3.8 Capabilities
`manage_wpfinet, manage_wpfinet_guides, publish_wpfinet_guides, manage_wpfinet_outages, publish_wpfinet_outages, view_wpfinet_statistics, manage_wpfinet_settings` – ges till administratör vid aktivering/migrering. **De används endast för menyer och vissa REST-rutter.** Själva CPT:erna använder `post`-capabilities, och `publish_*` kontrolleras aldrig.

### 3.9 Statistikhändelser
Servern accepterar 16 typer: `guide_viewed, guide_started, node_viewed, answer_selected, guide_completed, guide_abandoned, result_reached, cta_viewed, cta_clicked, outage_displayed, outage_clicked, wpform_displayed, wpform_started, wpform_submitted, restart_clicked, back_clicked`. Klienten skickar aldrig `guide_abandoned` eller `cta_viewed` (övergivna sessioner sätts av cron). `answer_selected.option_id` innehåller **nästa nods ID**, inte svarsalternativets ID – historisk statistik bygger på detta.

## 4. Avvikelser mot `WPFINET-3-KOMPATIBILITET.md`

| # | Registret säger | Koden visar | Konsekvens |
|---|---|---|---|
| D1 | 13 händelsetyper | 16 (även `cta_viewed`, `restart_clicked`, `back_clicked`) | Alla 16 bevaras |
| D2 | REST-listan nämner bara egna namespaces | Även `/wp/v2/finet_ts_guide`, `/wp/v2/wpfinet_outage` och tre taxonomirutter är publika via `show_in_rest` | Läggs till i kontraktslistan |
| D3 | `status` i `[wpfinet_outages]` | Är bucket `all/current/upcoming/past`, inte driftstatus | Dokumenteras; inga nya betydelser för gamla värden |
| D4 | Permalänk med datum | Datum = publiceringsdatum, valideras inte; även `/driftstorningar/{slug}/` fungerar | Båda bevaras |
| D5 | Guidefält `variables` | Accepteras vid import men **sparas aldrig**; export skriver alltid `[]` | Fixas i 3.0 (ny meta `_finet_ts_variables`), annars ingen roundtrip |
| D6 | – | Transients, admin-post-actions, klientlagring och JSON-dataformat i shortcode-HTML saknas i registret | Tillagda i `contracts.json` |
| D7 | Capabilities "behålls" | Är nästan overksamma (se 3.8) | Behålls oförändrade som standard; strikt läge som opt-in (se B11) |
| D8 | `uninstall.php` raderar "egna options" | Raderar inte `wpfinet_outage_rewrite_version`, taxonomitermer eller `wpfinet_event_rate_*` | Oförändrat beteende, utökas bara med 3.0:s nya data |

## 5. Buggar och risker i 2.0.1 (verifierade i kod)

| # | Allvar | Beskrivning | Åtgärd i 3.0 |
|---|---|---|---|
| B1 | Hög | **Inställningar nollställs mellan flikar.** Sanitize bygger hela arrayen från POST och flikformuläret skickar bara sin flik. Sparar man t.ex. "Support" stängs driftbanner och statistik av och färger återställs. | Sammanfoga med lagrade värden per flik |
| B2 | Medel | Statistiksidan läser `started`/`completed` men REST svarar `starts`/`completions`, så kort visar 0 | JS rättas; REST-svaret är oförändrat |
| B3 | Hög | Statusuppdatering på driftstörning: rullistan har alltid "Planerad" förvald. Skriver man ett meddelande utan att byta, sätts den publika statusen till `scheduled` | Förvalt värde = aktuell status |
| B4 | Låg | `[wpfinet_outage_status]` hämtar max 2, så antalet blir aldrig större än 2 | Räkna med högre gräns |
| B5 | Hög | CTA-typ `support` saneras som text och skrivs som `href`, vilket möjliggör `javascript:`-länkar (lagrad XSS av alla med `edit_posts`). JS-klienten kontrollerar inte URL-schema. | Server + klient tillåter bara http(s), relativa, `tel:` och `mailto:` |
| B6 | Låg | Inställningarna `card_background` och `spacing` har ingen effekt (CSS-variabelnamn matchar inte). `background` tar godtycklig CSS-text. | Mappa variabler; validera färgvärden |
| B7 | Medel | Aktivering skriver alltid `finet_ts_db_version = 2.0.0`, vilket döljer framtida migreringar vid återaktivering | Aktivering kör migrering i stället |
| B8 | Låg | PHP-varningar vid import när `settings.general/appearance` saknas (syns redan vid demoinstallation) | Robust sanering |
| B9 | Medel | `variables` försvinner vid import (D5) | Ny meta |
| B10 | Medel | `wp_unique_post_slug(..., 'draft')` gör inget, så importerade kopior kan få dubbla slugs. `slug=`-shortcode blir då tvetydig. | Kontrollera slug över alla statusar |
| B11 | Medel (säkerhet) | CPT:erna använder `post`-capabilities. Författare kan publicera driftstörningar (syns i banner) och bidragsgivare kan lista alla guider via REST. | Opt-in "strikta behörigheter" med migreringsknapp. Standard oförändrad för att inte låsa ute redaktörer. |
| B12 | Låg | Tidslinje från import lagras osanerad (utdata escapas) | Sanera vid import |
| B13 | Medel | Editor: radering av nod lämnar `outage.match_node` dinglande. Bildplacering visar inte sparat värde. `area` saknas för driftnod. Ingen CTA-redigering i UI. | Rättas/kompletteras |
| B14 | Låg | `tests/js/frontend.test.js` fallerar: `restart()` öppnar en modal i stället för att nollställa direkt | Testet skrivs om |
| B15 | Låg | Import/export-sidan och "exportera alla" kräver `manage_options`, menyn kräver `manage_wpfinet_settings` | Acceptera båda |
| B16 | Info | `GuideEditor::assets` kontrollerar fel hook-namn för importsidan (död kod, täcks av AdminMenu) | Städas |
| B17 | Låg | Rate limit för `/events` bygger på klientstyrt session-ID och skriver en transient per händelse | Kompletteras med IP-hashbaserad gräns i minnescache |
| B18 | Info | WP Consent API utan CMP returnerar `true` från `wp_has_consent()` (ingen consent type satt). 2.0.1 skyddar sig genom att kräva WPConsent. | 3.0 kräver att en CMP faktiskt satt consent type, eller intern CMP |
| B19 | Låg | Statistiksidan bygger REST-sökväg med regex på `/wp-json`, vilket inte fungerar med "plain permalinks" (`?rest_route=`) | Använd apiFetch-path |
| B20 | A11y | Modal saknar fokusfälla och återställning av fokus. Progressläge `bar` är inte implementerat. | Rättas |
| B21 | Låg | Export av **en** guide (UI och REST) innehåller **alla** driftstörningar inklusive interna tidslinjeposter och författar-ID | Behålls (kontrakt). Valfri parameter `include_outages=0` läggs till. |
| B22 | Medel (integritet) | `_wpfinet_area_note` beskrivs i UI som "intern eller publik", men ingår alltid i publik REST och export | Etiketten förtydligas. Ingen ändring av svarsformat. |
| B27 | **Hög** | Admin-JS skickar `call('/guides/'+id)` till `wp.apiFetch` utan namespace, vilket ger `/wp-json/guides/ID` (404). Samma sak för `/import/preview` och `/import`. Upptäckt vid kodläsning, döljs i praktiken av B28. | Fullständiga sökvägar med namespace |
| B28 | **Hög** | Metaboxen och byggarens inre element har samma `id="finet-ts-builder"`, så JS läser den yttre rutan utan data. **Den visuella byggaren visar 0 noder och Spara ger "Spara inlägget först".** Verifierat i Chromium mot 2.0.1. | Eget ID + data-attribut |
| B29 | Låg | `EventController` läser `device_class` även när nyckeln saknas (PHP-varning) | Standardvärde |
| B30 | Låg | `Settings::sanitize` läser odefinierade nycklar när en sektion saknas (PHP-varningar) | Standardvärden |
| B23 | **Hög** | `GET /finet-troubleshoot/v1/guides/{id}` kraschar på PHP 8. `'validate_callback' => 'is_numeric'` får tre argument av WordPress och PHP 8 kastar `ArgumentCountError`, vilket ger HTTP 500. Verifierat i testmiljön. | Closure som validerar |
| B24 | Medel | `OutageRepository::flush()` raderar transients med rå SQL. Med beständig objektcache (WinCache/Redis/Memcached) töms cachen aldrig, så ändringar syns först efter upp till 5 min. | Versionsnyckel i stället för SQL-radering |
| B25 | **Kritisk** | **Enskilda driftstörningssidor hänger tills PHP:s tidsgräns slår till och ger HTTP 500.** `single_content()` (filter på `the_content`) anropar `public_data()`, som kör `apply_filters('the_content')`, vilket ger oändlig rekursion. Verifierat med både blocktema (Twenty Twenty-Five) och klassiskt tema (Twenty Twenty-One): 30,4 s → HTTP 500. **Måste verifieras på staging/produktion**, eftersom det kan förklara långa svarstider. | Rendera innehåll utan att filtret anropar sig självt |
| B26 | Låg | Saknade nycklar i guide-inställningar sparas som `null` i stället för standardvärden (`progress_mode`, `animation`, `option.style`, `outage.mode`). Ger tom CSS-klass `finet-troubleshoot--`. | Standardvärden vid sanering. Ingen visuell skillnad, eftersom CSS saknar animationsklasser. |

## 6. Status: bevaras / migreras / ersätts

| Funktion | Status |
|---|---|
| CPT:er, meta, taxonomier, post-ID, term-ID, slugs, permalänkar | **Bevaras** (ingen datamigrering behövs) |
| Shortcodes, REST-rutter, hooks, filters, admin-slugs | **Bevaras** (samma klasser/registrering, buggfixar) |
| Importformat 1.0/2.0 och export 2.0 | **Bevaras**. Export 3.0 = 2.0 + `variables`, valfritt. |
| Statistiktabeller och händelser | **Bevaras** |
| `ConsentManager` (WPConsent-krav) | **Ersätts** av internt `ConsentService` med adapter för WP Consent API. Klassnamn, metoder och filter finns kvar. |
| Inställningsoptionen `finet_ts_settings` | **Bevaras**. Nya moduler får egna options. |
| `uninstall.php` | **Bevaras** (opt-in), utökad till nya tabeller/options |
| WPGetAPI-shortcode/`checkAddress()` | **Ersätts** av `[wpfinet_access_check]` efter manuell verifiering. Ingen alias-shortcode. |
| WPConsent | **Ersätts senare**: ny CMP är avstängd som standard, testläge för admin, live först efter manuellt beslut |
| Instagram Feed (tredjepart) | **Ersätts senare** av `[wpfinet_instagram_feed]` efter manuell kartläggning |

## 7. Okända uppgifter som måste hämtas (hittas inte på)

**Från staging/produktionsdatabasen** (verktyget *WPFinet → Diagnos → Migreringsrapport* och `wp wpfinet inventory` i 3.0 tar fram det mesta automatiskt, utan att skriva):
1. Antal guider, driftstörningar, termer, och vilka post-ID/slugs som är publicerade.
2. Var shortcodes används: `post_content`, `_elementor_data`, widgets, mallar och options för `finet_troubleshoot`, `wpfinet_outages`, `wpfinet_outage_status`, `wpgetapi_endpoint`, `checkAddress`, Instagram- och WPConsent-shortcodes.
3. Värdet av `finet_ts_settings.advanced.delete_data`. Om det är `true` raderar en avinstallation allt.
4. Cookiepolicysidans ID/slug, WPConsent-kategorier, tjänster, script-regler och bannertexter.
5. Vilket Instagram-plugin som används, dess shortcodes och kontotyp.
6. Var gamla `checkAddress()`-skriptet ligger: Elementor HTML-widget, tema eller kodsnutt.
7. Vad `/tjansteguide/#/serviceguide/` är. Det finns inte i ZIP-filen och är troligen en separat JS-app.

**Från WPGetAPI-konfigurationen eller Access API-dokumentationen:**
8. Endpointens relativa sökväg under `https://access.finet.se/api/onapi/2.4/`, HTTP-metod, autentisering, headers, query-parametrar.
9. Komplett exempelsvar: om API:t listar alla adresser eller kan söka på en adress, samt fältnamn, nästling, möjliga `accessState`-värden och paginering.
10. Rate limits, IP-allowlist och avtalsvillkor för cachning.

**Från Meta:** app-ID/hemlighet, professionellt Instagramkonto, redirect-URI och appgranskningsstatus.

**Från drift:** PHP 8.5-tillägg (sodium, intl, mbstring, curl), om WP-CLI och Windows Task Scheduler finns, IIS `web.config`-regler, Cloudflares cacheregler för `/wp-json/` och HTML samt WAF.

**Juridiskt:** granskning av cookiepolicy och bannertexter.

## 8. Implementationsordning och testfall

1. **Fas A (denna):** baseline-commit, testmiljö (WordPress 7.1.4 + SQLite), kontraktstester gröna mot 2.0.1.
2. **Fas B:** modulregister och feature flags, buggfixar B1–B22, `ConsentService`, migrering 3.0 (idempotent, inga borttagningar), migreringsrapport/legacy-skanner, nytt adminskal. Samma kontraktstester ska vara gröna.
3. **Fas C:** Access API med mock-adapter och konfigurerbar HTTP-adapter (blockerad tills punkt 8–9 är kända), index, circuit breaker, REST för en adress, shortcode.
4. **Fas D:** Instagram via officiellt API, bakgrundssynk, lokal mediacache.
5. **Fas E:** Consent-svit i läget *av* som standard, testläge för admin, blockering, inventarium, policyutkast, Playwright-skanner.
6. **Fas F:** full regression, TTFB med döda externa tjänster, säkerhetsgenomgång, release-ZIP, rollback.

Kontraktstester (körs mot både 2.0.1 och 3.0):
- Shortcodes: rendering med id/slug, attributöverstyrning, opublicerad guide, flera instanser på samma sida.
- REST: alla rutter, metoder, behörigheter (anonym/prenumerant/redaktör/admin), statuskoder, svarsnycklar.
- Import 1.0 och 2.0, strategierna copy/update/skip (samma post-ID vid update), roundtrip export → import.
- Driftstörningar: permalänk med datum + slug-URL + arkiv (HTTP 200), klassning, banner, filter.
- Statistik: inget sparas utan samtycke, sparas med samtycke, summary-format.
- Hooks: alla actions/filters anropas med samma argument.
