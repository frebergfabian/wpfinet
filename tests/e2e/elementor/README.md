# Layout- och CLS-test i den riktiga Elementor-strukturen

`fixtures/dev-finet.har.gz` är en inspelning av dev.finet.se: `/`, `/guide/` och `/felsokningsguide/` med Elementor 4.3.6, Astra 4.14.2, sajtens CSS och typsnitt. `server.mjs` spelar upp den offline över HTTPS. Webbläsaren pekas dit med `--host-resolver-rules`, så sidorna har sina riktiga URL:er.

Pluginets aktuella kod används hela vägen:

- CSS och JS hämtas från `finet-troubleshoot/assets/build/`.
- `transform.php` kör pluginets egen PHP (`GuideMarkup`, `OutageModule::banner_html`, CSS i `<head>`) på den inspelade HTML:en.

Lägen (query-parameter):

| Läge | Vad som visas |
|---|---|
| `?wpfinet=baseline` | Exakt som inspelat (3.1.0), för före-mätningar |
| `?wpfinet=fallback` | Bannern som i teman utan `wp_body_open` |
| `?wpfinet=demo` | Guiden utbytt mot `tests/js/fixtures/demo-guide.json` |

```bash
tests/e2e/run-elementor-layout.sh --shots .test-env/elementor-shots    # nuvarande kod
tests/e2e/run-elementor-layout.sh --baseline                           # 3.1.0 som jämförelse (förväntade fel)
LIGHTHOUSE_DIR=/tmp/lh node tests/e2e/elementor/lighthouse.mjs         # Lighthouse före/efter (se filens huvud)
node tests/e2e/elementor/record.mjs https://dev.finet.se               # spela in igen när sajten har ändrats
```

`layout.mjs` testar vid 1920, 1440, 1280, 390 och 375 px. Varje sidladdning sker med tom cache.

- **Guidens bredd:** min(760, kolumnen).
- **Radbrytning:** texten bryts mellan ord.
- **Synligt innehåll:** inget klipps och inget flödar över.
- **Knappar:** storleken, och att temats knappstilar inte läcker in.
- **Rubriker:** temats rubriktypsnitt.
- **Alla nåbara steg:** höjden och innehållet under guiden är oförändrade.
- **CLS:** med banner, efter stängning och i reservläget.
- **Stängknappen** fungerar.
