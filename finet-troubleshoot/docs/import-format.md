# WPFinet importformat 2.0

Finet Troubleshoot använder portabla JSON-filer för att skapa, uppdatera och versionshantera felsökningsguider. Formatet kräver aldrig databasåtkomst: en AI eller extern utvecklare kan skapa en importfil från en befintlig PDF, Word-fil eller textguide och sedan låta en administratör analysera och importera den i WordPress.

## Root-objekt

```json
{
  "schema": "finet-troubleshoot",
  "schema_version": "2.0",
  "generated_at": "2026-08-25T18:00:00+02:00",
  "generator": "Valfritt verktygsnamn",
  "guides": []
}
```

`schema`, `schema_version` och `guides` är obligatoriska. Versionerna `1.0` och `2.0` kan importeras; alla nya exporter använder `2.0`. `generated_at` och `generator` rekommenderas men är frivilliga. En schema 2.0-fil kan dessutom innehålla `outages` för driftstörningar.

## Guide

Varje objekt i `guides` måste ha `external_id`, `title`, `slug`, `start_node` och `nodes`.

```json
{
  "external_id": "internet-fungerar-inte",
  "title": "Internet fungerar inte",
  "slug": "internet-fungerar-inte",
  "description": "Steg-för-steg-felsökning.",
  "status": "publish",
  "start_node": "start",
  "settings": {},
  "variables": {},
  "nodes": []
}
```

`external_id` är det stabila import-ID:t, inte ett WordPress post-ID. Det får innehålla små bokstäver, siffror och bindestreck och ska inte ändras när guiden byter titel. Vid strategin **Uppdatera befintlig guide** matchas det mot detta fält. `status` kan vara `publish`, `draft` eller `private`.

## Noder och länkar

Varje nod behöver `id`, `type` och `title`. Nod-ID:n är unika inom guiden, har samma teckenuppsättning som `external_id` och alla länkar stannar inom samma guide. Det finns inga cross-guide-länkar i schema 1.0.

```json
{
  "id": "check-router-power",
  "type": "question",
  "title": "Kontrollera router",
  "heading": "Är routern igång?",
  "intro": "Kontrollera först att den har ström.",
  "content": "<p>Kontrollera strömkabel och statuslampor.</p>",
  "image": { "url": "https://example.se/router.jpg", "alt": "Router med statuslampor" },
  "options": [
    { "id": "yes", "label": "Ja", "next": "check-router-lights", "style": "primary", "icon": "" },
    { "id": "no", "label": "Nej", "next": "power-router", "style": "secondary", "icon": "" }
  ],
  "meta": {}
}
```

Tillåtna typer är `information`, `action`, `question`, `check`, `result`, `support`, `outage` och `wpforms`. `question` och `check` måste ha minst ett svarsalternativ. Ett `information`- eller `action`-steg kan använda `button_text` och `next` för en fortsättningsknapp. `result` och `support` är slutnoder.

Ett `option`-objekt måste ha `id`, `label` och `next`. `next` måste vara ID:t på en nod i samma guide. Tillåtna stilar är `primary`, `secondary`, `success`, `warning` och `danger`.

## Slutnoder

Resultatnoder använder en av `success`, `info`, `warning` eller `failure` i `result_type`.

```json
{
  "id": "success",
  "type": "result",
  "result_type": "success",
  "title": "Klart",
  "heading": "Problemet är löst",
  "content": "<p>Du behöver inte göra något mer.</p>",
  "options": []
}
```

Supportnoder kan ärva pluginets globala supportuppgifter eller bära egna uppgifter.

```json
{
  "id": "contact-support",
  "type": "support",
  "title": "Kontakta support",
  "heading": "Vi hjälper dig vidare",
  "content": "<p>Berätta vilka steg du har testat.</p>",
  "support": { "inherit_global": true },
  "options": []
}
```

När `inherit_global` är `false` kan `support` innehålla `phone`, `email`, `url` och `opening_hours`.

## HTML och media

`content` saneras både vid import och vid manuell redigering. Tillåten HTML är `p`, `strong`, `em`, `ul`, `ol`, `li`, `a`, `br`, `h2`, `h3`, `h4`, `blockquote` och `code`, med säkra standardattribut. Script-taggar, inline-JavaScript, händelseattribut och iframe-element tas bort.

Bilder är en URL och alt-text. Importen laddar inte ned externa bilder automatiskt; URL:en måste använda `http` eller `https`. Bundlade demoillustrationer kan använda `demo:cpe`.

## V2: visuella positioner, CTAs, formulär och driftkontroll

`editor_position` sparar `{ "x": 120, "y": 80 }` för den visuella editorn. Noder kan ha `ctas` (telefon, e-post, URL, support eller WPForms), `wpforms` med `form_id` och `outage` med filter för `service`, `provider`, `area`, samt valfria `match_node` och `no_match_node`.

Rotfältet `outages` innehåller portabla driftstörningar. Varje post använder stabilt `external_id`, `title`, `slug`, `status`, `published_at`, sammanfattning/innehåll, incidenttyp, driftstatus, tidpunkter, prioritet, bannerflagga, tjänsteleverantörer, tjänster, områden och en tidslinje. Import uppdaterar samma `external_id` och skapar revision före ändring.

## Validering, storleksgränser och konflikter

Importen kör alltid en dry-run före databasskrivning. Den kontrollerar rootformat, version, obligatoriska fält, dubbletter, nodtyper, alla länkar, startnod, slutnoder och flödets nåbarhet. Cirklar är tillåtna men visas som varning.

Standardgränser är 5 MB per fil, 100 guider per fil, 500 noder per guide och 50 alternativ per nod. WordPress-utvecklare kan justera dem med filtren `finet_troubleshoot_max_import_size`, `finet_troubleshoot_max_import_guides`, `finet_troubleshoot_max_nodes_per_guide` och `finet_troubleshoot_max_options_per_node`.

När samma `external_id` redan finns kan administratören välja:

- **Skapa kopia**: skapar ett nytt utkast med unikt `external_id` och slug.
- **Uppdatera befintlig guide**: ersätter flödet på befintligt WordPress-ID och skapar en revision före ändringen.
- **Hoppa över**: lämnar den befintliga guiden orörd.

Se [exempelfilen](../examples/finet-troubleshoot-example.json) för en komplett, importerbar guide.
