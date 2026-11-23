<?php
/**
 * A car's details: the fields a partner may change, the block that shows what
 * Geolander sets, what a change is called in the history, and why a value was
 * refused. A field's name is `spec_<field>`, its one-line explanation
 * `spec_<field>_help`, and a gearbox or a fuel is `spec_choice_<word>`.
 */
return [
	'specs_heading'                => 'Details of the car',
	'specs_help'                   => 'These are shown on the car\'s page, on the list of cars and in search results. Check them against the car itself before saving.',
	'spec_seats'                   => 'Seats',
	'spec_transmission'            => 'Gearbox',
	'spec_fuel_type'               => 'Fuel',
	'spec_drivetrain'              => 'Drive system',
	'spec_drivetrain_help'         => 'Capital letters and digits, for example AWD, 4WD or 4x4. Leave it empty if you are not sure.',
	'spec_color'                   => 'Colour',
	'spec_color_help'              => 'In English, for example White or Dark grey.',
	'spec_license_category'        => 'Driving licence category',
	'spec_available'               => 'Available for booking',
	'spec_available_help'          => 'Unticked: the car stays on the site, but the list of cars shows it as booked and search engines are told it is not available.',
	'spec_evidence_checked_on'     => 'Car checked on',
	'spec_evidence_checked_on_help' => 'The day you read the odometer and looked at the service record. Without this date the two facts below are not shown on the car\'s page. It cannot be a day still to come.',
	'spec_last_service_on'         => 'Last documented service',
	'spec_last_service_on_help'    => 'From the service record, not from memory. It cannot be after the day the car was checked.',
	'spec_odometer_km'             => 'Odometer on the check date (km)',
	'spec_yes'                     => 'yes',
	'spec_no'                      => 'no',
	'spec_choice_automatic'        => 'Automatic',
	'spec_choice_manual'           => 'Manual',
	'spec_choice_gasoline'         => 'Gasoline',
	'spec_choice_diesel'           => 'Diesel',
	'spec_choice_hybrid'           => 'Hybrid',
	'spec_choice_electric'         => 'Electric',

	'specs_fixed_heading'          => 'Set by Geolander',
	'specs_fixed_help'             => 'What the car is. Only Geolander changes these. Write to us if one of them is wrong.',
	'specs_title'                  => 'Name',
	'specs_brand'                  => 'Brand',
	'specs_body_type'              => 'Body type',
	'specs_description'            => 'Description',
	'specs_fuel_note'              => 'Fuel-economy note',
	'specs_tyres_note'             => 'Tyres',
	'specs_luggage_note'           => 'Luggage space',
	'specs_cut'                    => '…',

	'change_spec'                  => '%1$s: %2$s → %3$s',

	'err_spec_shape'               => 'The details could not be read.',
	'err_spec_field'               => '"%1$s" is not a detail that can be changed here.',
	'err_spec_seats'               => '%1$s: "%2$s" is not a whole number from %3$d to %4$d.',
	'err_spec_choice'              => '%1$s: "%2$s" is not one of: %3$s.',
	'err_spec_token'               => '%1$s: "%2$s" has something other than capital letters, digits and a small x. Write for example AWD, 4WD or 4x4.',
	'err_spec_code'                => '%1$s: "%2$s" has something other than letters and digits.',
	'err_spec_long'                => '%1$s: %2$d characters, and the most is %3$d.',
	'err_spec_text'                => '%1$s: plain text only, without tags.',
	'err_spec_switch'              => '%1$s: the answer is yes or no.',
	'err_spec_odometer'            => '%1$s: "%2$s" is not a whole number of kilometres above zero.',
	'err_spec_date'                => '%1$s: "%2$s" is not a day that has already come. Write it as year-month-day, for example 2026-09-30.',
	'err_spec_service_after_check' => '%1$s: %2$s is after the day the car was checked (%3$s). The check date has to be on or after the service.',
];
