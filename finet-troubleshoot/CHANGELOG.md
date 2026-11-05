# Changelog

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

**Nytt**

- WPFINET-adminskal, Diagnos med migreringsrapport (inventering av shortcodes och äldre integrationer), WP-CLI (`wp wpfinet migrate|inventory|jobs|access|instagram`).
- Moduler som är avstängda som standard: Adressökning (`[wpfinet_access_check]`), Instagram (`[wpfinet_instagram_feed]`), Cookies & samtycke (av / testläge / live), samt strikta behörigheter.
- Internt samtyckesgränssnitt som fungerar med WP Consent API utan att kräva WPConsent.
- Krypterad lagring av hemligheter, härdad HTTP-klient med kretsbrytare och rate limiting.
- Block för adressökning och Instagram, progressbar, bildplacering vänster/höger och CTA-redigering i byggaren.

## 2.0.1

- Rättar WP-admin-routning, driftbanner, driftstörningsomskrivning och dokumentationslänk.

## 2.0.0

- WPFinet-vy med grafisk guidebyggare, driftstörningar och samtyckesstyrd statistik.
- JSON-schema 2.0 med portabel import/export av guider och driftstörningar.
- Uppgradering från tidigare guide-data sker idempotent utan att ta bort befintliga guider.

## 1.0.0

- Första versionen av Finet Troubleshoot.
