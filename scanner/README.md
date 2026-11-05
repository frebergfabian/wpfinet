# WPFINET-skanner

Fristående skanner som kör en riktig Chromium (Playwright) **utanför WordPress**. Den besöker utvalda sidor i rena webbläsarprofiler och registrerar cookies, localStorage, sessionStorage och anrop till tredje part i tre scenarier: före samtycke, efter *neka* och efter *acceptera*. Resultatet skickas till `POST /wp-json/wpfinet/v1/consent/scan`. Där blir fynden **granskningsförslag** i *WPFINET → Cookies & samtycke → Inventarium*.

## Installation (worker-maskin, t.ex. Windows)

```bash
cd scanner
npm install
npx playwright install chromium
copy config.example.json config.json
```

Fyll i `config.json`:

- `site` och `pages`: representativa sidor, till exempel startsida, formulär, tjänsteguide, kontakt och inloggning där det är relevant.
- `wordpress.user`: en användare med behörigheten `manage_wpfinet_settings`.
- Lösenordet: skapa ett **Application Password** under *Användare → Profil*. Lägg det helst i miljövariabeln `WPFINET_SCANNER_APP_PASSWORD` i stället för i filen.
- `selectors`: knapparna för acceptera och neka. Så länge WPConsent används behöver du ange WPConsents selektorer, annars hoppas scenarierna över med en varning.

Kör manuellt:

```bash
node scan.mjs --config config.json
```

Använd `--dry-run` för att bara spara JSON-filen i `results/` utan att skicka den.

## Schemaläggning (Windows Task Scheduler)

1. Skapa en *Basic Task*, till exempel varje natt klockan 02:30.
2. Program: `C:\Program Files\nodejs\node.exe`
3. Argument: `scan.mjs --config config.json`
4. Starta i: sökvägen till mappen `scanner`.
5. Sätt `WPFINET_SCANNER_APP_PASSWORD` som miljövariabel för kontot som kör uppgiften.

Skannern körs aldrig i besökarnas HTTP-anrop och aldrig inne i WordPress.

## Begränsningar

Skannern kan **inte** garantera att alla cookies hittas:

- Innehåll bakom inloggning, formulärsteg och andra interaktioner.
- Cloudflare-utmaningar. Dessa loggas som varning, men vitlista gärna workerns IP i Cloudflare.
- Geostyrda eller slumpade skript.
- Cookies inne i iframes från tredje part.

Komplettera med manuella poster. Ingenting markeras automatiskt som nödvändigt, och inget publiceras i cookiepolicyn förrän en människa har verifierat posten och godkänt en ny policyversion.
