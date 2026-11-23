<?php
/**
 * A car's photos: the gallery editor on the car's screen, what a change to it
 * is called in the history, and why a photo or an upload was refused.
 * `photo_alt_default` is written into the stored description of a photo, always
 * from this English file: that text is public and is kept once for every
 * language of the site.
 */
return [
	'photos_heading'          => 'Photos',
	'photos_help'             => 'The photos are shown on the car\'s page in this order, and the first one is the large one at the top. The main photo is the one on the list of cars and in search results. Change the photos here, not on the Media screen: there you can upload, but not describe or remove.',
	'photos_empty'            => 'This car has no photos yet.',
	'photos_add'              => 'Add photos',
	'photos_main'             => 'Main photo',
	'photos_alt'              => 'What the photo shows (read out to people who cannot see it, and read by search engines)',
	'photos_size'             => '%1$d × %2$d pixels',
	'photos_narrow'           => 'This photo is %1$d pixels wide. A photo added to a gallery must be at least %2$d pixels wide, so it will not be saved.',
	'photos_earlier'          => 'Move earlier',
	'photos_later'            => 'Move later',
	'photos_remove'           => 'Take out of the gallery',
	'photos_pick_title'       => 'Choose photos for this car',
	'photos_pick_use'         => 'Add to the gallery',
	'photos_rule_kinds'       => 'Accepted kinds of photo: %s.',
	'photos_rule_size'        => 'A file may be up to %s MB.',
	'photos_rule_width'       => 'A new photo must be at least %d pixels wide.',
	'photos_rule_stored'      => 'Every photo is kept as a JPEG no wider than %d pixels.',
	'photo_alt_default'       => '%1$s — photo %2$d',

	'change_photos_added'     => 'Photos added: %d',
	'change_photos_removed'   => 'Photos taken out of the gallery: %d (they stay in the library)',
	'change_photos_order'     => 'The order of the photos changed',
	'change_photos_main'      => 'Another photo is the main one',
	'change_photos_main_none' => 'The car has no main photo now',
	'change_photos_alt'       => 'Description: "%1$s" → "%2$s"',

	'err_photo_shape'         => 'The photos could not be read.',
	'err_photo_id'            => '"%1$s" is not a photo.',
	'err_photo_count'         => 'A gallery can hold at most %1$d photos. This one has %2$d.',
	'err_photo_foreign'       => 'Photo #%1$d cannot be used here: it is not on this car and it is not a photo you uploaded.',
	'err_photo_type'          => '%1$s is of the kind %2$s. A gallery takes: %3$s.',
	'err_photo_narrow'        => '%1$s is %2$d pixels wide. A photo added to a gallery must be at least %3$d pixels wide, or it looks soft at the top of the car\'s page.',
	'err_photo_unmeasured'    => 'The width of %1$s could not be read, so it cannot be checked against the smallest allowed width of %2$d pixels.',
	'err_photo_main'          => 'The main photo (#%1$d) is not in the gallery. Choose one of the photos in the gallery.',
	'err_photo_main_none'     => 'Choose which photo is the main one.',
	'err_photo_alt_long'      => 'A description is %1$d characters long and the most is %2$d: "%3$s"',

	'err_upload_closed'       => 'Uploading photos is switched off for partners at the moment. Write to Geolander.',
	'err_upload_too_big'      => 'This file is %1$s. The largest photo you can upload is %2$s.',
	'err_upload_type'         => 'This file is not a photo of a kind the site takes. Upload one of: %s.',
	'err_upload_unreadable'   => 'This photo could not be opened, so it was not stored. Save it again as a JPEG on your phone or computer and upload that file.',

	'metabox_gallery_name'    => 'Photo gallery',
	'metabox_not_written'     => 'Not written: %s. The rest of this page was saved.',
	'metabox_main_photo'      => 'The main photo is one of the gallery\'s photos. It is chosen on the car\'s own screen, under Photos.',
	'metabox_main_photo_link' => 'Choose the main photo',
];
