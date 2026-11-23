<?php
/**
 * The change log. `log_title` and `log_note_restore` are written into the stored
 * record, always from this English file, so the log reads the same for everyone.
 */
return [
	'log_menu'            => 'Change log',
	'log_singular'        => 'Change',
	'log_title'           => '#%1$d %2$s by %3$s %4$s',
	'log_actor_cli'       => 'wp-cli',
	'log_actor_unknown'   => 'user #%1$d',
	// What a partner reads in place of a name: the owner's and an earlier partner's names are not theirs to learn.
	'log_who_you'         => 'you',
	'log_who_owner'       => 'Geolander',
	'log_who_partner'     => 'a previous partner',
	'log_note_restore'    => 'restore of #%1$d',
	'log_note_account_deleted' => 'partner account #%1$d was deleted',
	'err_log_entry'       => 'The change could not be written to the log.',
	'err_restore_missing' => 'Change #%1$d was not found.',
	'err_restore_kind'    => 'A change of the kind "%1$s" cannot be restored from the log yet.',
	'err_restore_empty'   => 'Change #%1$d has no readable earlier state.',
];
