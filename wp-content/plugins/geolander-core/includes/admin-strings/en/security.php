<?php
/** The front door: the log and the state line under Settings → Geolander. */
return [
	'security_heading'      => 'The front door',
	'security_state'        => 'Application passwords: %1$s. Author pages and the public user list: %2$s. A sign-in address waits after %3$d failed attempts, for %4$d minutes.',
	'security_allowed'      => 'ALLOWED',
	'security_blocked'      => 'blocked',
	'security_hidden'       => 'hidden (404)',
	'security_public'       => 'PUBLIC',
	'security_quiet'        => 'Nothing to report. This log fills when an account is made an administrator, when one is created as an administrator, or when an application password is minted. Any of those three on this site means someone is in who should not be.',
	'security_last_watch'   => 'Last daily check: %1$s. Found %2$d administrator(s), %3$d application password(s), %4$d post(s).',
	'security_never_watched'=> 'The daily check has never run. It is driven from the server, not by WordPress, because scheduled events are switched off on this installation.',
	'security_col_when'     => 'When',
	'security_col_what'     => 'What happened',
	'security_locked'       => 'Too many failed sign-ins from your address. Try again in %d minutes.',
	'security_mail_subject' => 'Geolander: someone gained power on %s',
	'security_mail_body'    => "%1\$s\n\nSeen at %2\$s on %3\$s.\n\nIf this was not you, the site is being held by someone else. Change the administrator password, then look at Settings → Geolander for the full log.",
];
