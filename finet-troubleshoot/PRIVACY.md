# Integritet

WPFinet skickar inga data till externa tjänster och laddar inga externa typsnitt.

Statistik är avstängd tills WP Consent API/WPConsent signalerar samtycke för kategorin `statistics`. Därefter lagras endast anonymt sessions-ID, guide-/nod-ID, valda händelsetyper, datum/tid och grov enhetsklass i webbplatsens egen databas. IP-adress, full user-agent, cookies och formulärfält lagras inte. Lagringstid ställs in under **WPFinet → Inställningar → Statistik**. Att neka eller återkalla samtycke stoppar ny statistiklagring.

Driftbannerns stängning använder bara `sessionStorage` för aktuell webbläsarsession och är inte kopplad till statistik.
