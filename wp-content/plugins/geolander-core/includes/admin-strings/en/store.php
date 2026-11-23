<?php
/**
 * Why a price table or a fee was not saved. Each message names the season, the
 * rental length and the dates, so the person can find the cell without help.
 */
return [
	'season_ref'         => 'season %1$d (%2$s)',
	'list_join'          => ' and ',
	'err_season_row'     => 'Season %1$d could not be read.',
	'err_season_date'    => 'Season %1$d: "%2$s" is not a day of the year. Pick a day and a month for the start and for the end.',
	'err_season_count'   => 'A price table needs at least one season and at most %1$d. This one has %2$d.',
	'err_season_none'    => 'A price table needs at least one season. This one has none.',
	'err_rate_missing'   => 'Season %1$d (%2$s): the price for %3$s days is missing.',
	'err_rate_number'    => 'Season %1$d (%2$s): the price for %3$s days is "%4$s", which is not a number.',
	'err_rate_zero'      => 'Season %1$d (%2$s): the price for %3$s days must be above zero.',
	'err_rate_decimals'  => 'Season %1$d (%2$s): the price for %3$s days has more than two decimals.',
	'err_rate_low'       => 'Season %1$d (%2$s): the price for %3$s days is %4$s, below the lowest allowed price of %5$s.',
	'err_rate_high'      => 'Season %1$d (%2$s): the price for %3$s days is %4$s, above the highest allowed price of %5$s.',
	'err_gap'            => 'No season covers %1$s to %2$s (%3$d days). Every day of the year needs one season.',
	'err_gap_one'        => 'No season covers %1$s. Every day of the year needs one season.',
	'err_overlap'        => '%1$s to %2$s is in more than one season: %3$s. A day can be in one season only.',
	'err_overlap_one'    => '%1$s is in more than one season: %2$s. A day can be in one season only.',
	'err_fee_shape'      => 'The fees could not be read.',
	'err_fee_place'      => '"%1$s" is not a place with a delivery fee.',
	'err_fee_number'     => '%1$s: "%2$s" is not an amount. Write a number, 0 for free, or leave it empty for the site standard.',
	'err_fee_decimals'   => '%1$s: the fee has more than two decimals.',
	'err_fee_high'       => '%1$s: %2$s is above the highest allowed fee of %3$s.',
	'err_bounds_setting' => 'A limit in Settings → Geolander is not a number, so nothing can be checked against it. Ask Geolander to correct it.',
	'err_stale'          => 'This car was changed after this page was opened (%1$s, %2$s). Reload the page and make your change again.',
	'err_stale_unknown'  => 'This car was changed after this page was opened. Reload the page and make your change again.',
	'err_forbidden'      => 'This car is not assigned to you.',
	'err_store_only'     => 'A car\'s details, its gallery and its main photo are changed on the car\'s own screen or on its edit screen, where every change is checked and kept in the history. They cannot be sent here, so nothing of this request was saved.',
	'err_not_car'        => 'Car #%1$d does not exist.',
	'err_no_rev'         => 'The change did not say which version of the car it started from, so it was not saved.',
	'err_unknown_kind'   => 'Changes of the kind "%1$s" cannot be saved yet.',
	'err_source'         => '"%1$s" is not a known source of changes.',
	'err_database'       => 'The database refused the change. Nothing was saved.',
	'err_refused'        => 'Nothing was saved.',
];
