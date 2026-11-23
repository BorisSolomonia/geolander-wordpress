<?php
/**
 * How fast the site is, measured over and over, so a change can be seen.
 *
 * WHY A TREND AND NOT A NUMBER. One Lighthouse run tells you today's score and
 * nothing about whether the site is getting slower. A row of readings taken the
 * same way, on the same page, on the same cadence does: that is the whole point
 * of this file. The readings sit under Settings → Geolander, newest first, with
 * the ones below the chosen floor marked.
 *
 * WHO MEASURES. Google's PageSpeed Insights, which runs Lighthouse on Google's
 * machines against the public URL. It needs an API key of its own. Until one is
 * pasted into Settings → Geolander NOTHING IS MEASURED and the screen says so —
 * it does not estimate, and it does not quietly show an old number as if it
 * were new. There is no key on this installation at the time of writing.
 *
 * THE AUTOMATION, PLAINLY:
 *  - trigger: WordPress cron, every `speed_check_hours` hours (a cron fires on
 *    a visit, so a site with no visitors measures itself late — which is fine,
 *    because a site with no visitors has nobody waiting for it).
 *  - owner: whoever holds `manage_options`, i.e. the site owner.
 *  - on failure: the reading is not recorded. The failure is, with its reason
 *    and its time, and the screen shows it instead of a score. Three failures
 *    in a row is the signal that the key or the URL is wrong.
 *  - stop condition: an empty key. No key, no scheduled event, no outbound
 *    request at all.
 *  - manual fallback: the "Measure now" button on the same screen, and
 *    `wp cron event run glc_speed_check` over the CLI.
 *
 * WHAT IT DOES NOT DO. It does not email. It does not change anything on the
 * site in response to a slow reading. A monitor that fixes things on its own is
 * a monitor you cannot trust the readings of.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Speed {

	public const EVENT    = 'glc_speed_check';
	public const SCHEDULE = 'glc_speed_interval';
	public const ACTION   = 'glc_speed_measure';

	/** The readings, newest last, and the last thing that went wrong. */
	private const HISTORY_OPTION = 'glc_speed_history';
	private const ERROR_OPTION   = 'glc_speed_last_error';

	private const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	public static function init(): void {
		add_filter( 'cron_schedules', [ __CLASS__, 'schedule' ] );
		add_action( 'init', [ __CLASS__, 'keep_scheduled' ], 20 );
		add_action( self::EVENT, [ __CLASS__, 'measure' ] );
		add_action( 'glc_settings_after_form', [ __CLASS__, 'render' ] );
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'measure_now' ] );
	}

	/* ------------------------------------------------------------- the clock */

	public static function schedule( $schedules ) {
		$hours = GLC_Settings::whole( 'speed_check_hours' ) ?: 24;
		$schedules[ self::SCHEDULE ] = [
			'interval' => $hours * HOUR_IN_SECONDS,
			'display'  => sprintf( glc_admin_t( 'speed_every_hours' ), $hours ),
		];
		return $schedules;
	}

	/**
	 * One scheduled event while there is a key, none when there is not, and a
	 * fresh one when the cadence has been changed. Called on every request, so
	 * it only ever writes when something is actually different.
	 */
	public static function keep_scheduled(): void {
		$wanted = '' !== self::key();
		$event  = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( self::EVENT ) : null;
		if ( ! $wanted ) {
			if ( $event ) {
				wp_clear_scheduled_hook( self::EVENT );
			}
			return;
		}
		if ( $event && self::SCHEDULE === ( $event->schedule ?? '' ) ) {
			$hours = GLC_Settings::whole( 'speed_check_hours' ) ?: 24;
			if ( (int) ( $event->interval ?? 0 ) === $hours * HOUR_IN_SECONDS ) {
				return;
			}
			wp_clear_scheduled_hook( self::EVENT );
		} elseif ( $event ) {
			wp_clear_scheduled_hook( self::EVENT );
		}
		wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::EVENT );
	}

	/* ---------------------------------------------------------- the measuring */

	private static function key(): string {
		return trim( (string) GLC_Settings::get( 'speed_api_key' ) );
	}

	public static function url(): string {
		$set = trim( (string) GLC_Settings::get( 'speed_check_url' ) );
		return '' !== $set ? $set : home_url( '/' );
	}

	private static function strategy(): string {
		$set = strtolower( trim( (string) GLC_Settings::get( 'speed_strategy' ) ) );
		return 'desktop' === $set ? 'desktop' : 'mobile';
	}

	/**
	 * One reading. Recorded only when Google answered with a score; otherwise
	 * the reason is recorded and the row of readings is left alone.
	 *
	 * @return array|WP_Error the reading
	 */
	public static function measure() {
		$key = self::key();
		if ( '' === $key ) {
			return self::failed( glc_admin_t( 'speed_no_key' ) );
		}
		$response = wp_remote_get(
			add_query_arg(
				[
					'url'      => self::url(),
					'strategy' => self::strategy(),
					'category' => 'performance',
					'key'      => $key,
				],
				self::ENDPOINT
			),
			[ 'timeout' => 90 ]
		);
		if ( is_wp_error( $response ) ) {
			return self::failed( $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status || ! is_array( $body ) ) {
			return self::failed( sprintf( glc_admin_t( 'speed_http' ), $status ) );
		}
		$audits = (array) ( $body['lighthouseResult']['audits'] ?? [] );
		$score  = $body['lighthouseResult']['categories']['performance']['score'] ?? null;
		if ( ! is_numeric( $score ) ) {
			return self::failed( glc_admin_t( 'speed_no_score' ) );
		}
		$reading = [
			'at'       => time(),
			'score'    => (int) round( (float) $score * 100 ),
			'lcp'      => (int) round( (float) ( $audits['largest-contentful-paint']['numericValue'] ?? 0 ) ),
			'cls'      => round( (float) ( $audits['cumulative-layout-shift']['numericValue'] ?? 0 ), 3 ),
			'images'   => (int) round( (float) ( $audits['modern-image-formats']['details']['overallSavingsBytes'] ?? 0 ) ),
			'strategy' => self::strategy(),
			'url'      => self::url(),
		];
		self::record( $reading );
		delete_option( self::ERROR_OPTION );
		return $reading;
	}

	private static function failed( string $why ) {
		update_option( self::ERROR_OPTION, [ 'at' => time(), 'why' => $why ], false );
		return new WP_Error( 'glc_speed', $why );
	}

	/** Keep the newest readings and no more: the row is a trend, not an archive. */
	private static function record( array $reading ): void {
		$history   = array_values( array_filter( (array) get_option( self::HISTORY_OPTION, [] ), 'is_array' ) );
		$history[] = $reading;
		$keep      = GLC_Settings::whole( 'speed_history_points' ) ?: 60;
		if ( count( $history ) > $keep ) {
			$history = array_slice( $history, -$keep );
		}
		update_option( self::HISTORY_OPTION, $history, false );
	}

	public static function history(): array {
		return array_values( array_filter( (array) get_option( self::HISTORY_OPTION, [] ), 'is_array' ) );
	}

	/* ------------------------------------------------------------- the screen */

	public static function measure_now(): void {
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), self::ACTION ) ) {
			wp_die( esc_html( glc_admin_t( 'speed_refused' ) ), '', [ 'response' => 403 ] );
		}
		self::measure();
		wp_safe_redirect( admin_url( 'options-general.php?page=geolander' ) );
		exit;
	}

	/**
	 * The answer first — today's score against the floor — and the readings
	 * behind it below, which is the only part anybody scrolls to.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$history = self::history();
		$latest  = $history ? end( $history ) : null;
		$floor   = GLC_Settings::whole( 'speed_min_score' ) ?: 0;
		$error   = get_option( self::ERROR_OPTION, [] );

		echo '<h2>' . esc_html( glc_admin_t( 'speed_heading' ) ) . '</h2>';

		if ( '' === self::key() ) {
			echo '<p class="notice notice-warning" style="padding:.6rem;">' . esc_html( glc_admin_t( 'speed_not_configured' ) ) . '</p>';
		} elseif ( $latest ) {
			printf(
				'<p style="font-size:1.1rem;"><strong>%s</strong> — %s</p>',
				esc_html( sprintf( glc_admin_t( 'speed_latest' ), (int) $latest['score'], $floor ) ),
				esc_html( (int) $latest['score'] < $floor ? glc_admin_t( 'speed_below' ) : glc_admin_t( 'speed_ok' ) )
			);
		} else {
			echo '<p>' . esc_html( glc_admin_t( 'speed_none_yet' ) ) . '</p>';
		}

		if ( is_array( $error ) && ! empty( $error['why'] ) ) {
			printf(
				'<p class="notice notice-error" style="padding:.6rem;">%s</p>',
				esc_html( sprintf( glc_admin_t( 'speed_last_error' ), GLC_Settings::local_time( (int) ( $error['at'] ?? 0 ) ), (string) $error['why'] ) )
			);
		}

		printf(
			'<form method="post" action="%s" style="margin:.6rem 0;">%s<input type="hidden" name="action" value="%s" /><button type="submit" class="button">%s</button> <span class="description">%s</span></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( self::ACTION, '_wpnonce', true, false ),
			esc_attr( self::ACTION ),
			esc_html( glc_admin_t( 'speed_measure_now' ) ),
			esc_html( sprintf( glc_admin_t( 'speed_measures' ), self::url(), self::strategy() ) )
		);

		if ( ! $history ) {
			return;
		}
		echo '<details><summary style="cursor:pointer;font-weight:600;">' . esc_html( sprintf( glc_admin_t( 'speed_readings' ), count( $history ) ) ) . '</summary>';
		echo '<table class="widefat striped" style="margin-top:.5rem;"><thead><tr>';
		foreach ( [ 'speed_col_when', 'speed_col_score', 'speed_col_lcp', 'speed_col_cls', 'speed_col_images' ] as $column ) {
			echo '<th>' . esc_html( glc_admin_t( $column ) ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( array_reverse( $history ) as $reading ) {
			printf(
				'<tr><td>%s</td><td%s>%d</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( GLC_Settings::local_time( (int) ( $reading['at'] ?? 0 ) ) ),
				(int) ( $reading['score'] ?? 0 ) < $floor ? ' style="font-weight:700;"' : '',
				(int) ( $reading['score'] ?? 0 ),
				esc_html( sprintf( glc_admin_t( 'speed_seconds' ), round( ( (int) ( $reading['lcp'] ?? 0 ) ) / 1000, 2 ) ) ),
				esc_html( (string) ( $reading['cls'] ?? '0' ) ),
				esc_html( size_format( (int) ( $reading['images'] ?? 0 ), 0 ) )
			);
		}
		echo '</tbody></table></details>';
	}
}
