# Changelog

## 3.1.0

Uppgradering på plats av 3.0.0 (eller direkt från 2.0.1). Inga data tas bort; samma shortcodes, REST-rutter, hooks, slugs och URL:er.

**Instagram**

- Stöd för båda Meta-modellerna: *Instagram API with Instagram Login* (graph.instagram.com, token börjar med `IGAA`) och *Instagram API with Facebook Login* (graph.facebook.com, token börjar med `EAA`). 3.0 talade bara den första, så Facebook-tokens hämtade aldrig inlägg. Modellen känns igen automatiskt eller väljs manuellt.
- Instagram Account ID hittas automatiskt (via `/me` respektive `/me/accounts?fields=instagram_business_account`) och kan anges manuellt; vid flera konton visas en vallista.
- Anslutningstest steg för steg (token, modell, konto, behörigheter, inläggsanrop, bildnedladdning, uppladdningsmapp, bildbibliotek, modul/schema) med förklaring av Metas felkoder.
- "Hämta nu" körs direkt i adminanropet i stället för via WP-Cron-loopback (som IIS/Cloudflare/DISABLE_WP_CRON kunde stoppa).
- Bildnedladdning följer omdirigeringar bara inom Metas CDN. En synk där ingen bild kan sparas behåller de gamla inläggen.
- `wp wpfinet instagram test`.
- Hemligheter: OpenSSL AES-256-GCM som reserv när libsodium saknas; status "kan inte dekrypteras" om salterna i wp-config ändrats.

**Driftbanner**

- Stängknappen fungerar (`[hidden]` skrevs över av `display:flex`). Ny layout i Finets färger, märkt region, radbryts på mobil, 44 px stängyta, fokus flyttas till huvudinnehållet. Stängning gäller per bannerversion och session.

**Felsökningsguide**

- Nytt kort i Finets formspråk (Montserrat/Poppins från temat, indigo knappar, orange accent). Ytan reserverar höjden för guidens högsta steg, knapparna ligger i botten av kortet och navigeringsraden står still, så innehåll under guiden inte hoppar. Bildram med fast höjd (beskärs aldrig). Lika stora knappar, minst 50 px. Navigering, statistik, bilder, data och API oförändrade. 2.x-standardvärden för utseende skriver inte längre över den nya stilen; egna värden gäller fortfarande.

**Adressökning (Finet Access API)**

- Ny datakälla "Befintlig WPGetAPI-konfiguration": återanvänder WPGetAPI:s API `finet_access` / endpoint `get_access_data` (sökväg och nyckel kopieras inte), men bara i bakgrundssynken. Besökare och sidvisningar anropar aldrig WPGetAPI eller API:t.
- Reservmatchning utan diakriter (API:t skriver "Ostra Langgatan", besökaren "Östra Långgatan"). Används bara när exakt en gata matchar, så Ågatan och Agatan blandas aldrig ihop.
- Standardtexterna följer den gamla adresskontrollen ("{adress}: ansluten till Finet." m.fl.); `{adress}` ersätts med besökarens egen adress.
- Shortcode-attribut: `placeholder`, `hide_label`, `hint`, `theme="dark"` (för mörk hero), flera klasser i `class`.
- Migrering 3.0 → 3.1 lägger till kolumnen `street_fold` i adressindexet och fyller den för befintliga rader.

## 3.0.0

Uppgradering på plats av Finet Troubleshoot 2.0.1. Samma pluginidentitet, data, URL:er, shortcodes, REST-rutter och hooks.

**Rättat från 2.0.1**

- Enskilda driftstörningssidor hängde och gav HTTP 500 (B25).
- Guidebyggaren visade 0 noder och kunde inte spara (B28, B27).
- `GET /guides/{id}` kraschade på PHP 8 (B23).
- Att spara en inställningsflik nollställde de andra (B1).
- En ny statusuppdatering tvingade status "Planerad" (B3).
- Statusräknaren stannade på 2 (B4).
- `javascript:`-länkar var möjliga i support-CTA (B5).
- CSS-inställningar för kort och avstånd fungerade inte (B6).
- Guidevariabler försvann vid import (B9).
- Importerade utkast kunde få dubbla slugs (B10).
- Drift-cachen tömdes inte med objektcache (B24).
- PHP-varningar och null-värden i inställningar (B8, B26, B29, B30).
- Statistiksidan visade alltid 0 (B2).
- Modalen saknade fokusfälla (B20).
- Ogiltig CSS (`font: 600 1em/1.3 inherit`) gjorde att guideknappar, rubriker och driftkort visades i webbläsarens standardtypsnitt (B31). **Synlig ändring:** knapptexterna följer nu temats typsnitt i avsedd storlek.
- Guidebyggarens inspektörspanel flödade ut över sidopanelen. Panelen anpassar sig nu till bredden och nodtyperna visas på svenska.
- Demo-illustrationens text låg över kabeln.

**Nytt**

- WPFINET-adminskal, Diagnos med migreringsrapport (inventering av shortcodes och äldre integrationer), WP-CLI (`wp wpfinet migrate|inventory|jobs|access|instagram`).
- Moduler som är avstängda som standard: Adressökning (`[wpfinet_access_check]`), Instagram (`[wpfinet_instagram_feed]`), Cookies & samtycke (av / testläge / live), samt strikta behörigheter.
- Internt samtyckesgränssnitt som fungerar med WP Consent API utan att kräva WPConsent.
- Krypterad lagring av hemligheter, härdad HTTP-klient med kretsbrytare och rate limiting.
- Block för adressökning och Instagram, progressbar, bildplacering vänster/höger och CTA-redigering i byggaren.
- Ny översiktssida med status för alla moduler.
- Testdata för manuell test (Diagnos eller `wp wpfinet testdata create|remove`). Inte tillgängligt i miljötypen production.

## 2.0.1

- Rättar WP-admin-routning, driftbanner, driftstörningsomskrivning och dokumentationslänk.

## 2.0.0

- WPFinet-vy med grafisk guidebyggare, driftstörningar och samtyckesstyrd statistik.
- JSON-schema 2.0 med portabel import/export av guider och driftstörningar.
- Uppgradering från tidigare guide-data sker idempotent utan att ta bort befintliga guider.

## 1.0.0

- Första versionen av Finet Troubleshoot.
