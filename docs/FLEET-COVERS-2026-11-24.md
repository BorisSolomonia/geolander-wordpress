# Home and fleet card covers — 24 November 2026

The home page and car archive use one illustrative studio front cover per current listing, with responsive JPG/WebP files at 360, 480 and 720 pixels. The manifest in `wp-content/plugins/geolander-core/assets/fleet-covers/` binds each cover to its existing car slug and recorded year. Unknown slugs and changed years fall back to the actual featured image. Other routes, car galleries, featured attachments, social previews and schema continue using the real WordPress media. No image import or database migration is needed.

[OBSERVED] All 19 published records and their current first three photo URLs were checked read-only on production on 24 November. The six stored colour disagreements remain documented rather than silently changing business records: car 13 white/recorded gray; 15 green/white; 24 dark gray/white; 25 black/silver; 37 white/blue; 43 red/black. Covers follow the visible photos. Car 4 (Renegade) has no listing photos, so recorded black is visually unverified and its detail page retains the existing photos-coming-soon state.

[OBSERVED] Five Forester front covers were revised against actual photos: 2014 green, 2016 blue, 2018 green, 2018 black and 2023 black. This corrects trim/headlamp/grille/bumper differences that the earlier shared-generation illustrations did not represent. Removable roof boxes and ski racks are omitted from representative covers. The covers remain illustrations rather than evidence of exact equipment or condition; the page copy and alt text make that explicit in all seven locales.

[FIRST-PARTY] Subaru documents revised front styling for the [2017 Forester](https://subarumedia.iconicweb.com/mediasite/specs/2017_Subaru_Forester_Pricing-Final11.1.2016.pdf) and the [2022 Forester](https://www.subaru.eu/media/introducing-the-2022-subaru-forester-e-boxer). Its [new XV body premiered in March 2017](https://www.subaru.co.jp/press/news-en/2017_03_07_3762/). Therefore the white XV/Crosstrek uses the body visible in its actual photos, not the earlier-body comparison candidate. Recorded years are retained; production year, registration year and market model year cannot be established from these photos without VIN/registration evidence.

Card-only names omit stale colour words and repeated years in old titles. The database titles, URLs, colours, years, prices and SEO fields are unchanged.

Validation: PHP lint; WordPress cover-scope/year-fallback/gallery preservation integration test; fleet integrity audit; local and production schema guard; before/after public head/schema/gallery comparisons; responsive browser review. Production rollout must preserve the database and uploads, take a fresh code/database backup, and refresh the home/fleet cache variants.

Source corrections and their actual built-in ImageGen prompts are retained locally in `output/fleet-production-covers-2026-11-24/`. Only the selected first covers and their web sizes ship with the application.
