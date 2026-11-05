# Integritet (WPFINET 3.0)

**Guider och driftstörningar** gör inga externa anrop och laddar inga externa typsnitt. Driftbannerns stängning använder `sessionStorage` (`wpfinet_banner_dismissed_*`) under webbläsarsessionen.

**Statistik** sparas bara efter samtycke för kategorin `statistics`. Samtycket kommer antingen från WPFINET:s egen samtyckeshanterare (läge live, eller testläge för administratörer) eller från WP Consent API när en CMP har satt samtyckestyp. WP Consent API utan CMP räknas **inte** som samtycke.

Det som lagras är ett anonymt sessions-ID (`sessionStorage.wpfinet_analytics_session`), guide- och nod-ID, händelsetyp, tid, sökväg och grov enhetsklass. IP-adress, full user agent och formulärfält lagras inte. Återkallat samtycke stoppar insamlingen och tar bort sessions-ID:t.

**Adressökning (valfri modul)**

- Webbläsaren skickar den adress besökaren skriver till webbplatsens egen server.
- Servern slår upp den i sitt lokala index eller, i uppslagsläget, mot Finet Access API.
- Svaret innehåller bara status för den adressen.
- Adresser loggas inte.
- Rate limiting använder en saltad hash av IP-adressen i högst en minut.

**Instagram (valfri modul)**

- Inlägg och bilder hämtas i bakgrunden och lagras lokalt.
- Besökare laddar inget från Meta. Länkar till instagram.com är vanliga länkar.
- Inlägg som tas bort på Instagram tas bort lokalt vid nästa synk.

**Cookies & samtycke (valfri modul)**

- Valet sparas i den nödvändiga förstapartscookien `wpfinet_consent` (slumpat ID, tid, policyversion och kategorier), standard 180 dagar.
- Om loggning är på sparas samma uppgifter utan IP och user agent som bevis på valet, med konfigurerbar lagringstid.

WPFINET gör ingen telemetri. Det här dokumentet är inte en juridisk bedömning. Cookiepolicyn ska bygga på en verifierad inventering och granskas av ansvarig.
