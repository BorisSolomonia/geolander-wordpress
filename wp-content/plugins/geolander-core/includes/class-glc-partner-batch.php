<?php
/**
 * A change to several cars at once: kept after its preview, shown, applied.
 *
 * The bulk screen and the Excel upload both end the same way. Each works out
 * what every car would become, and from there the steps are one and the same,
 * so they live here once:
 *
 *  1. keep()            the change waits, under a random name only the person
 *                       who made it can use, for as long as the setting says;
 *  2. render_preview()  the store plans it again (so the screen shows today's
 *                       "before", and a car someone else changed meanwhile is
 *                       already refused here), and each car gets what will
 *                       change and its sample quotes, every figure twice;
 *  3. apply()           the store writes all of it as one batch, or none of it.
 *
 * Nothing in this class writes to a car. The plan that waits holds no more
 * than what the person typed or uploaded, the cars it is for, and the revision
 * each car had when it was previewed; that revision is the lock.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Partner_Batch {

	/** The field (and the address part) that names a waiting plan. */
	public const FIELD = 'plan';

	/** A plan's name: a version-4 UUID, nothing else. */
	private const TOKEN_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	/* --------------------------------------------------------------- checks */

	/**
	 * The first two checks of every handler here, in this order: the panel
	 * capability, then a nonce bound to the action (and, for an apply, to the
	 * one preview it applies). Ends the request with the panel's one refusal.
	 */
	public static function check( string $action, string $suffix = '' ): void {
		$nonce = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) ? sanitize_key( $_POST['_wpnonce'] ) : '';
		if ( ! current_user_can( GLC_Partners::CAP_PANEL ) || ! wp_verify_nonce( $nonce, $action . $suffix ) ) {
			GLC_Partner_Panel::refuse();
		}
	}

	/** The third check: every car named, or none of the request. One car that is not theirs refuses it all, and says nothing. */
	public static function cars_allowed( array $car_ids ): void {
		$user = get_current_user_id();
		foreach ( $car_ids as $car_id ) {
			if ( ! is_int( $car_id ) || ! GLC_Partners::can_manage( $user, $car_id ) ) {
				GLC_Partner_Panel::refuse();
			}
		}
	}

	/** The plan a request names, or '' when it names none or something that is not a plan's name. */
	public static function requested_token(): string {
		$raw = $_REQUEST[ self::FIELD ] ?? '';
		return is_string( $raw ) && preg_match( self::TOKEN_PATTERN, $raw ) ? $raw : '';
	}

	/* ------------------------------------------------------- a waiting plan */

	private static function key( string $token ): string {
		return 'glc_plan_' . get_current_user_id() . '_' . $token;
	}

	/**
	 * Keep a plan until it is applied or the time in Settings → Geolander runs out.
	 *
	 * @param array $plan source (bulk|excel), items ([ car id => [ changes, rev ] ], as the store takes them),
	 *                    stops (reasons nothing can be applied, already in the reader's language),
	 *                    and whatever the screen wants back: draft, cells, refs
	 * @return string the plan's name
	 */
	public static function keep( array $plan ): string {
		$token   = wp_generate_uuid4();
		$minutes = GLC_Settings::get( 'plan_keep_minutes' );
		set_transient( self::key( $token ), $plan + [ 'items' => [], 'stops' => [] ], MINUTE_IN_SECONDS * ( is_numeric( $minutes ) && (int) $minutes > 0 ? (int) $minutes : 1 ) );
		return $token;
	}

	/** A plan this person made on this screen; null for anything else, another person's plan included. */
	public static function get( string $token, string $source ): ?array {
		$plan = '' === $token ? false : get_transient( self::key( $token ) );
		return is_array( $plan ) && ( $plan['source'] ?? '' ) === $source ? $plan : null;
	}

	public static function drop( string $token ): void {
		delete_transient( self::key( $token ) );
	}

	/* ---------------------------------------------------------- the preview */

	/**
	 * What applying a plan would do, car by car, worked out now.
	 *
	 * @return array [ 'cars' => [ id => [ 'errors' => lines, 'lines' => what changes, 'now' => state, 'then' => state ] ], 'changing' => how many cars change, 'blocked' => bool ]
	 */
	public static function preview( array $plan ): array {
		$out     = [ 'cars' => [], 'changing' => 0, 'blocked' => false ];
		$planned = GLC_Price_Store::plan( $plan['items'], [ 'source' => $plan['source'] ] );
		if ( is_wp_error( $planned ) ) {
			$out['blocked'] = true;
			return $out;
		}
		foreach ( array_keys( $plan['items'] ) as $car_id ) {
			$car_id = (int) $car_id;
			$now    = GLC_Price_Store::state( $car_id );
			$car    = [ 'errors' => [], 'lines' => [], 'now' => $now, 'then' => $now ];
			if ( isset( $planned['errors'][ $car_id ] ) ) {
				$car['errors']  = self::error_lines( (array) $planned['errors'][ $car_id ], (array) ( $plan['refs'][ $car_id ] ?? [] ) );
				$out['blocked'] = true;
			} else {
				foreach ( (array) ( $planned['cars'][ $car_id ]['changes'] ?? [] ) as $kind => $change ) {
					$car['lines']         = array_merge( $car['lines'], GLC_Partner_Car::describe( $kind, $change['before'], $change['after'] ) );
					$car['then'][ $kind ] = $change['after'];
				}
				$out['changing'] += $car['lines'] ? 1 : 0;
			}
			$out['cars'][ $car_id ] = $car;
		}
		return $out;
	}

	/**
	 * The store's reasons for one car as lines. An uploaded file knows which cell
	 * each price and fee came from ($refs); the line then starts with that cell.
	 */
	public static function error_lines( array $errors, array $refs = [] ): array {
		$lines = [];
		foreach ( $errors as $error ) {
			$ref = '';
			if ( isset( $error['season'], $error['band'] ) ) {
				$ref = (string) ( $refs[ 'p:' . $error['season'] . ':' . $error['band'] ] ?? '' );
			} elseif ( isset( $error['place'] ) ) {
				$ref = (string) ( $refs[ 'f:' . $error['place'] ] ?? '' );
			}
			$message = (string) ( $error['message'] ?? '' );
			$lines[] = '' !== $ref ? sprintf( glc_admin_t( 'batch_cell_line' ), $ref, $message ) : $message;
		}
		return $lines ?: [ glc_admin_t( 'err_refused' ) ];
	}

	/** "Jeep Renegade 2017 · NN-545-KN": a car by the two things a partner knows it by. */
	public static function car_name( int $car_id ): string {
		$plate = trim( (string) get_post_meta( $car_id, 'glc_registration', true ) );
		return get_the_title( $car_id ) . ( '' !== $plate ? ' · ' . $plate : '' );
	}

	/**
	 * A plan on the screen: the reasons it cannot be applied, or each car with
	 * what will change and its sample quotes, and then the button that applies
	 * it. The button is there only when every car passed and something changes.
	 *
	 * @param string $action the admin-post action that applies this kind of plan
	 */
	public static function render_preview( string $token, array $plan, string $action ): void {
		if ( $plan['stops'] ) {
			?>
			<div class="notice notice-error inline glc-stops" role="alert">
				<p><strong><?php echo esc_html( glc_admin_t( 'batch_refused' ) ); ?></strong></p>
				<ul>
					<?php foreach ( $plan['stops'] as $line ) : ?>
						<li><?php echo esc_html( (string) $line ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
			return;
		}
		$preview = self::preview( $plan );
		if ( $preview['blocked'] ) {
			echo '<div class="notice notice-error inline glc-stops" role="alert"><p><strong>' . esc_html( glc_admin_t( 'batch_blocked' ) ) . '</strong></p></div>';
		} elseif ( ! $preview['changing'] ) {
			echo '<div class="notice notice-info inline glc-nothing"><p>' . esc_html( glc_admin_t( 'batch_nothing' ) ) . '</p></div>';
		}
		foreach ( $preview['cars'] as $car_id => $car ) {
			$state = $car['errors'] ? 'refused' : ( $car['lines'] ? 'changes' : 'same' );
			?>
			<section class="glc-batch-car glc-batch-car--<?php echo esc_attr( $state ); ?>" data-car="<?php echo (int) $car_id; ?>" data-state="<?php echo esc_attr( $state ); ?>">
				<h3><?php echo esc_html( self::car_name( $car_id ) ); ?></h3>
				<?php if ( $car['errors'] ) : ?>
					<p class="glc-fault"><?php echo esc_html( glc_admin_t( 'batch_car_refused' ) ); ?></p>
					<ul class="glc-change-list glc-fault">
						<?php foreach ( $car['errors'] as $line ) : ?>
							<li><?php echo esc_html( $line ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php elseif ( ! $car['lines'] ) : ?>
					<p class="description"><?php echo esc_html( glc_admin_t( 'batch_car_same' ) ); ?></p>
				<?php else : ?>
					<ul class="glc-change-list">
						<?php foreach ( $car['lines'] as $line ) : ?>
							<li><?php echo esc_html( $line ); ?></li>
						<?php endforeach; ?>
					</ul>
					<?php GLC_Partner_Car::render_samples( $car_id, [ 'now' => $car['now'], 'then' => $car['then'] ], glc_admin_t( 'samples_now' ), glc_admin_t( 'samples_then' ) ); ?>
				<?php endif; ?>
			</section>
			<?php
		}
		if ( $preview['blocked'] || ! $preview['changing'] ) {
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="glc-apply">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( self::FIELD ); ?>" value="<?php echo esc_attr( $token ); ?>" />
			<?php wp_nonce_field( $action . '_' . $token, '_wpnonce', false ); ?>
			<p>
				<button type="submit" class="button button-primary glc-apply-button"><?php echo esc_html( sprintf( glc_admin_t( 'batch_apply' ), $preview['changing'] ) ); ?></button>
				<span class="description"><?php echo esc_html( glc_admin_t( 'batch_apply_help' ) ); ?></span>
			</p>
		</form>
		<?php
	}

	/* ------------------------------------------------------------ the apply */

	/**
	 * Apply a waiting plan: the body of both apply handlers. Every car, or none.
	 *
	 * @param string $action the admin-post action being answered
	 * @param string $source bulk|excel: which screen's plans this handler may apply, and how the change is logged
	 * @param string $page   the screen to go back to
	 */
	public static function apply( string $action, string $source, string $page ): void {
		$token = self::requested_token();
		self::check( $action, '_' . $token );
		$plan = self::get( $token, $source );
		if ( ! $plan || $plan['stops'] || ! $plan['items'] ) {
			// The nonce was good, so this is the person's own preview: it has run out of time, or was already applied.
			GLC_Partner_Panel::flash( $source, [ 'ok' => false, 'lines' => [ glc_admin_t( 'batch_gone' ) ] ] );
			wp_safe_redirect( GLC_Partner_Panel::screen_url( $page ), 303 );
			exit;
		}
		// A car may have been taken from this person since the preview.
		self::cars_allowed( array_map( 'intval', array_keys( $plan['items'] ) ) );

		$result = GLC_Price_Store::write_batch( $plan['items'], [ 'source' => $source ] );
		if ( is_wp_error( $result ) ) {
			$lines = [];
			foreach ( (array) ( $result->get_error_data()['errors'] ?? [] ) as $car_id => $errors ) {
				foreach ( self::error_lines( (array) $errors, (array) ( $plan['refs'][ $car_id ] ?? [] ) ) as $line ) {
					$lines[] = sprintf( glc_admin_t( 'batch_car_line' ), self::car_name( (int) $car_id ), $line );
				}
			}
			GLC_Partner_Panel::flash( $source, [ 'ok' => false, 'lines' => array_merge( [ glc_admin_t( 'batch_none_written' ) ], $lines ?: [ $result->get_error_message() ] ) ] );
			// Back to the same preview: it now shows what stopped it.
			wp_safe_redirect( GLC_Partner_Panel::screen_url( $page, [ self::FIELD => $token ] ) . '#glc-preview', 303 );
			exit;
		}
		self::drop( $token );
		$changed = array_map( 'intval', array_keys( $result['cars'] ) );
		GLC_Partner_Panel::flash( $source, [
			'ok'    => true,
			'lines' => [ $changed ? sprintf( glc_admin_t( 'batch_applied' ), count( $changed ), $result['batch'] ) : glc_admin_t( 'batch_applied_nothing' ) ],
			'cars'  => $changed,
		] );
		wp_safe_redirect( GLC_Partner_Panel::screen_url( $page ), 303 );
		exit;
	}

	/** The answer to an apply (or to a refused upload), with a way to each changed car's history. */
	public static function render_result( array $flash ): void {
		GLC_Partner_Panel::render_flash( $flash );
		if ( empty( $flash['cars'] ) ) {
			return;
		}
		?>
		<ul class="glc-change-list glc-batch-result">
			<?php foreach ( (array) $flash['cars'] as $car_id ) : ?>
				<li><a href="<?php echo esc_url( GLC_Partner_Car::url( (int) $car_id ) . '#glc-history' ); ?>"><?php echo esc_html( sprintf( glc_admin_t( 'batch_car_history' ), self::car_name( (int) $car_id ) ) ); ?></a></li>
			<?php endforeach; ?>
		</ul>
		<?php
	}
}
