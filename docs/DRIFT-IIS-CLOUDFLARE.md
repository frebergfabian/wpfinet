# Drift: Windows Server, IIS, PHP 8.5, Cloudflare

## Krav
- PHP 8.1 eller senare (målet är 8.5.12 NTS x64) med tilläggen `sodium` (hemligheter), `intl` (Unicode-normalisering för adresser, med reserv utan), `mbstring`, `curl`, `openssl` och `gd` eller `imagick` (Instagrambilder).
- WordPress 6.5 eller senare (testat med 7.1.4).
- **Inget Node/npm i produktion.** Färdigbyggda JS/CSS-filer finns i ZIP:en. Node behövs bara på den maskin som kör skannern.
- Inga Apache-specifika regler (`.htaccess`) och inga Unix-cron- eller Linux-binärer.

## IIS URL Rewrite (permalänkar)
WordPress standardregel i `web.config` räcker. WPFINET:s driftstörnings-URL:er (`/driftstorningar/ÅÅÅÅ/MM/DD/slug/`) hanteras av WordPress egna rewrite-regler:

```xml
<rule name="WordPress" patternSyntax="Wildcard">
  <match url="*" />
  <conditions>
    <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
    <add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
  </conditions>
  <action type="Rewrite" url="index.php" />
</rule>
```

Utan URL Rewrite fungerar REST via `?rest_route=`, och adminskripten använder sökvägar som fungerar i båda lägena.

## Bakgrundsjobb (WP-Cron → Windows Task Scheduler)
WPFINET kör synk av Access API, Instagram och tokenförnyelse samt statistikstädning **endast i bakgrunden**, aldrig i en sidvisning. Rekommenderat på IIS:

1. Lägg `define( 'DISABLE_WP_CRON', true );` i `wp-config.php`.
2. Skapa en uppgift i Task Scheduler som körs var 5:e minut:
   ```bash
   php.exe C:\wp-cli\wp-cli.phar --path=C:\inetpub\wwwroot\finet cron event run --due-now --quiet
   ```
   Eller bara WPFINET-jobben: `... wpfinet jobs run`.
3. Kontrollera under **WPFinet → Diagnos → System** att "Schemalagda jobb" visar nästa körning.

Om WP-Cron används (inget `DISABLE_WP_CRON`) körs jobben i en separat loopback-förfrågan som WordPress startar asynkront. Besökarens svar väntar inte på den.

## wp-config.php-konstanter

| Konstant | Syfte |
|---|---|
| `WPFINET_ACCESS_API_SECRET` / `WPFINET_ACCESS_API_USERNAME` | Access API-hemlighet (rekommenderas framför databas) |
| `WPFINET_ACCESS_ALLOWED_HOSTS` | Extra tillåtna värdar för Access API, kommaseparerat (standard: `access.finet.se`) |
| `WPFINET_ACCESS_ALLOW_MOCK` | Tillåt testdata i miljötypen `production` (bör inte användas) |
| `WPFINET_INSTAGRAM_TOKEN` / `WPFINET_INSTAGRAM_APP_SECRET` | Instagram-token och app-hemlighet. Token i konstant förnyas inte automatiskt. |
| `WPFINET_PREVENT_DATA_DELETION` | `true`: avinstallation raderar aldrig data, oavsett inställning |

## Cloudflare
- **Cache:** HTML kan cachas. Förhandsblockeringen ger samma HTML för alla besökare och är därför cache-säker. Rensa HTML-cachen efter att samtyckesläget eller blockeringsreglerna ändrats.
- **Bypass:** `/wp-json/wpfinet/v1/access/*` och `/wp-json/wpfinet/v1/consent/*` (POST, `Cache-Control: no-store`) samt `/wp-admin/*`.
- **WAF/rate limiting (rekommenderat):** regel för `/wp-json/wpfinet/v1/access/check`, till exempel 30 anrop per minut och IP, med åtgärden *Managed Challenge*. WPFINET har dessutom en egen gräns per IP.
- **Riktig besökar-IP:** slå på *Lita på CF-Connecting-IP* under Adressökning. Headern används bara när anropet kommer från Cloudflares publicerade IP-intervall (listan finns i `Core/ClientIp.php` och bör kontrolleras mot cloudflare.com/ips ibland).
- **Skannern:** vitlista worker-maskinens IP så att botskyddet inte stoppar skanningen.
- Cloudflare kan sätta egna cookies (`__cf_bm`, `cf_clearance`). De finns som förslag "nödvändig" i inventariet men måste verifieras.

## OPcache
Efter uppgradering via ZIP läser IIS/FastCGI normalt de nya filerna. Om gamla filer verkar ligga kvar: återvinn applikationspoolen eller kör `opcache_reset()`.

## Prestanda-guardrail
`tests/e2e/perf-dead-externals.sh` mäter TTFB med alla moduler på och alla externa tjänster hängande i 30 s. Målet är median under 1 s och 0 utgående anrop vid sidrendering. Det är ett mål, inte en garanti genom Cloudflare och nätverk. Upprepa mätningen med `curl -w '%{time_starttransfer}'` mot staging, både med kall och varm cache.
