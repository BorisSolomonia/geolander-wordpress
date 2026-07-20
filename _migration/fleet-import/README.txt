HOW TO BULK-ADD CARS
====================

1. Inside THIS folder (_migration/fleet-import/), make ONE folder per car.
   Name the folder exactly what the car should be called, e.g.

     Toyota Land Cruiser 2021
     Jeep Wrangler 2019

   (Format: "Model YEAR" — the year at the end is shown separately on the card.)

2. Put that car's PHOTOS inside its folder. Name them so the order is right —
   the FIRST one (alphabetically) becomes the main photo:

     Toyota Land Cruiser 2021/
       01-front.jpg      <- main / featured photo
       02-side.jpg
       03-interior.jpg

   JPG, PNG or WebP all work.

3. (Optional) To also set specs + prices, drop a file named  car.json  in the
   car's folder. Copy _EXAMPLE_car.json below as a starting point. Without it,
   the car is created with photos only — you can add specs/pricing later in the
   WordPress admin.

4. Commit + push the repo, then run in the container:

     wp eval-file /migration/import-fleet.php --allow-root

   Re-running is safe: it updates existing cars and never duplicates photos.

5. After the cars appear, you can delete these folders from the repo to keep it
   small — the photos are already saved in WordPress (they live on the volume).
