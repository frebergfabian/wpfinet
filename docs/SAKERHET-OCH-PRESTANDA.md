# Säkerhets- och prestandarapport – WPFINET 3.0.0

Testmiljö: WordPress 7.1.4, SQLite, PHP 8.3.6 CLI, `php -S` med 4 arbetare och en router som efterliknar IIS URL Rewrite. Siffrorna gäller samma testmiljö. Mät på staging bakom Cloudflare innan release.

## 1. Publik yta (REST)

| Rutt | Metod | Behörighet | Skydd |
|---|---|---|---|
| `wpfinet/v1/outages`, `/outages/current` | GET | publik (2.0-kontrakt) | Endast publik data. Intern anteckning och icke-publika tidslinjeposter exkluderas (kontraktstest). |
| `wpfinet/v1/events` | POST | publik (2.0-kontrakt) | Sparar inget utan statistiksamtycke. Max 25 händelser per anrop och 120 per minut och session, **nytt** 60 anrop per minut och IP. Ingen IP, user agent eller formulärdata sparas. |
| `wpfinet/v1/access/check` | POST | publik (ny) | **En** adress per anrop och minimalt svar (status, text, CTA, `stale`, tid). Max 120 tecken, honeypot, rate limit per IP (standard 20 per minut), `Cache-Control: no-store`, nekar annan webbplats `Origin`. Det finns ingen rutt som listar adresser. |
| `wpfinet/v1/consent/log` | POST | publik (ny) | Validerat UUID och åtgärd, max 3 kategorier, rate limit 20 per minut och IP. Lagrar inte IP eller user agent. |
| `wpfinet/v1/consent/scan` | POST | `manage_wpfinet_settings` (Application Password) | Storleksbegränsade listor. Fynd blir förslag, aldrig verifierade. |
| `wpfinet/v1/analytics/summary` | GET | `view_wpfinet_statistics` / `manage_options` | oförändrat |
| `finet-troubleshoot/v1/*` | – | `can_edit`/`can_manage` (2.0-kontrakt) | `GET /guides/{id}` gav HTTP 500 i 2.0.1 och fungerar nu. Strikt läge kan begränsa till WPFINET-behörigheter. |

## 2. Hot och åtgärder

| Hot | Åtgärd |
|---|---|
| **XSS** | All output escapas (WPCS EscapeOutput: 0 fynd). CTA-, support- och bild-URL:er saneras till http(s), mailto, tel eller relativa på servern **och** i klienten (B5: `javascript:` i support-CTA var möjligt i 2.0.1). Guideinnehåll går genom `wp_kses` med 2.0:s allowlist. CSS-värden begränsas till färger (B6). Adress- och Instagramdata renderas med `textContent` respektive `esc_*`. |
| **CSRF** | Alla `admin-post`-åtgärder har nonce plus behörighetskontroll. OAuth använder ett engångs-`state` bundet till användaren. REST använder WordPress cookie-nonce. |
| **SSRF** | `HttpClient` kräver https, en **allowlist** av värdar (Access: `access.finet.se`, utökas bara via wp-config; Instagram: `graph.instagram.com`, `api.instagram.com`; media: `*.cdninstagram.com`, `*.fbcdn.net`), `wp_safe_remote_request` (blockerar privata adresser), inga omdirigeringar och storleksgräns. Endpoint-sökvägar med `..` avvisas. |
| **Läckor av adressdata** | Hela listan finns bara i serverns tabell `wpfinet_access_index`. Varken HTML, JS eller REST exponerar den. Admin-förhandsgranskningen maskerar värden. Loggar innehåller inga adresser. |
| **Hemligheter** | Läses från wp-config-konstanter i första hand, annars krypteras de med libsodium (nyckel härledd från `AUTH_KEY`/`SECURE_AUTH_SALT`) i ett option som inte autoladdas. Fälten är skriv-bara i admin och visas aldrig. Loggar maskerar token, e-post och långa nycklar. |
| **Missbruk/bottar** | Rate limiting per IP med HMAC-hash, aldrig rå IP. Cloudflares header godtas bara från Cloudflares intervall. Honeypot. Rekommenderad WAF-regel finns i `DRIFT-IIS-CLOUDFLARE.md`. |
| **Minsta behörighet** | Nya adminsidor kräver `manage_wpfinet_settings`. Strikt läge (opt-in) kopplar CPT:erna till WPFINET-behörigheter (B11). |
| **Dataförlust** | Avinstallation raderar bara när "Radera data" är på. `WPFINET_PREVENT_DATA_DELETION` blockerar helt. Migreringen gör inga borttagningar och är verifierad med uppgraderingstest. |
| **Injektion i SQL** | Alla värden går via `$wpdb->prepare`/`insert`/`update`. WPCS-fynden gäller bara interpolerade tabellnamn med `$wpdb->prefix`, vilket fanns redan i 2.0.1. |
| **CSV-injektion** | Export av inventariet prefixar celler som börjar med `= + - @`. |

WPCS-säkerhetssniffar (EscapeOutput, ValidatedSanitizedInput, NonceVerification, PreparedSQL): 0 fynd för escaping och sanering. 30 nonce-fynd är falsklarm, eftersom nonce verifieras i hjälpmetoden `guard()`. 6 fynd gäller tabellnamn.

## 3. Prestanda

| Mätning | Resultat |
|---|---|
| TTFB, startsida (alla moduler på, **alla externa tjänster hänger i 30 s**) | första 0,11 s, median av 9: 0,10 s |
| TTFB, sida med två guider och driftlistor | median 0,085 s |
| TTFB, `/driftstorningar/` | median 0,074 s |
| TTFB, adressökningssida | median 0,072 s |
| Utgående HTTP-anrop vid sidrendering | **0** (verifierat med loggande mu-plugin) |
| Enskild driftstörning, 2.0.1 → 3.0 | **30,4 s / HTTP 500 → 0,10 s / HTTP 200** (B25) |

Adressökning i läget *uppslag* gör ett anrop per sökning med 3 s timeout (konfigurerbart 1–5 s) och utan omförsök. Efter 3 fel i rad öppnas kretsbrytaren i 5 min och svaren blir omedelbart "tillfälligt otillgänglig". **Indexläget (standard) gör aldrig externa anrop vid sökning.** Simuleringen ovan hänger i `pre_http_request` före WordPress timeout och visar därför ett värsta fall på 30 s för själva sök-anropet, inte för sidan.

Inga JS/CSS-filer laddas på sidor som inte använder respektive funktion, utom samtyckesmodulens fil när den är live. Inga loggar skrivs per sidvisning.

## 4. Kvarstående risker och begränsningar
- PHP 8.5 och IIS är inte körda i den här miljön (PHP 8.3, `php -S`). En kodsökning efter kända utfasningar i 8.4/8.5 gav 0 träffar. Kör `bin/php-lint.sh` och sviten med PHP 8.5 på staging.
- MySQL är inte testat här (SQLite). Alla SQL-frågor är standard-SQL och tabellerna skapas med `dbDelta`. Verifiera på staging.
- Förhandsblockering kan inte fånga skript som ett icke-blockerat skript laddar dynamiskt, cookies som sätts på serversidan av andra tillägg eller Cloudflare, eller cookies i tredjeparts-iframes. Se fliken Blockering.
- Skannern hittar inte allt. Se `scanner/README.md`.
- Metas dokumentation kunde inte läsas från den här miljön. Endpoints och fält är implementerade enligt kända specifikationer och ska verifieras mot Metas aktuella referens: API-version, fältet `alt_text` och om omdirigerings-URI med query-parameter godtas.
