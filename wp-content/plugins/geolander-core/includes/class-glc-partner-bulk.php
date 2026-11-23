<?php
/**
 * The bulk screen: define a change once, pick the cars, preview, apply to all.
 *
 * Three kinds of change, each switched on by its own tick box:
 *  - seasons and prices: either one season table that REPLACES each picked
 *    car's table whole (dates and prices: the point of setting a model), or
 *    an adjustment of every price a car already has (times a factor, plus an
 *    amount), its seasons left alone;
 *  - delivery fees, place by place: leave, set, free, back to the standard;
 *  - the early/late fee, the same four choices.
 *
 * The screen writes nothing. "Preview" works out what each picked car would
 * become and hands that to GLC_Partner_Batch, which keeps it, shows it and,
 * on "Apply", gives it to the store as one batch: every car or none.
 *
 * The request stays small whatever the fleet's size: the picked cars travel
 * as one field and the season table as one field (see GLC_Partner_Car).
 */

defined( 'ABSPATH' ) || exit;

class GLC_Partner_Bulk {

	public const PAGE = 'glc-partner-bulk';

	private const PREVIEW = 'glc_bulk_preview';
	private const APPLY   = 'glc_bulk_apply';
	private const SOURCE  = 'bulk';

	/** The one field the picked cars travel in: their ids, comma-separated. */
	public const CARS_FIELD = 'glc_cars';

	/** What can be done to one fee on every picked car. */
	private const FEE_MODES = [ 'leave', 'set', 'free', 'standard' ];

	/** How a factor and an amount to add are written. */
	private const FACTOR_PATTERN = '/^\d+(?:\.\d+)?$/';
	private const DELTA_PATTERN  = '/^-?\d+(?:\.\d{1,2})?$/';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_post_' . self::PREVIEW, [ __CLASS__, 'preview' ] );
		add_action( 'admin_post_' . self::APPLY, [ __CLASS__, 'apply' ] );
		foreach ( [ self::PREVIEW, self::APPLY ] as $action ) {
			add_action( 'admin_post_nopriv_' . $action, [ 'GLC_Partner_Panel', 'refuse' ] );
		}
	}

	public static function menu(): void {
		$hook = add_submenu_page( GLC_Partner_Panel::parent_slug(), glc_admin_t( 'bulk_title' ), glc_admin_t( 'bulk_menu' ), GLC_Partners::CAP_PANEL, self::PAGE, [ __CLASS__, 'render' ], GLC_Partner_Panel::menu_position( 1 ) );
		add_action( 'load-' . $hook, [ __CLASS__, 'guard' ] );
	}

	/** Asked before WordPress prints anything, so a refusal is a real 403. */
	public static function guard(): void {
		$from = self::requested_from();
		if ( ! current_user_can( GLC_Partners::CAP_PANEL ) || ( null !== $from && ! GLC_Partners::can_manage( get_current_user_id(), $from ) ) ) {
			GLC_Partner_Panel::refuse();
		}
		add_action( 'admin_enqueue_scripts', [ 'GLC_Partner_Car', 'enqueue' ] );
	}

	/** The car whose table the form should start from: null when none is asked for, 0 when what is asked is not a number. */
	private static function requested_from(): ?int {
		if ( ! isset( $_GET['from'] ) ) {
			return null;
		}
		return is_string( $_GET['from'] ) && ctype_digit( $_GET['from'] ) ? (int) $_GET['from'] : 0;
	}

	/* ------------------------------------------------------ reading the form */

	/** The picked cars as whole numbers, each once; null when the field holds anything else. */
	private static function posted_cars(): ?array {
		$raw = $_POST[ self::CARS_FIELD ] ?? '';
		if ( ! is_string( $raw ) ) {
			return null;
		}
		if ( '' === $raw ) {
			return [];
		}
		return preg_match( '/^\d+(?:,\d+)*$/', $raw ) ? array_values( array_unique( array_map( 'intval', explode( ',', $raw ) ) ) ) : null;
	}

	private static function posted_text( string $field ): string {
		return isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
	}

	/** Everything typed on the form, as typed. Only the fields named here are read. */
	private static function posted_draft(): array {
		$modes   = isset( $_POST['glc_fee_mode'] ) && is_array( $_POST['glc_fee_mode'] ) ? wp_unslash( $_POST['glc_fee_mode'] ) : [];
		$amounts = isset( $_POST['glc_fee'] ) && is_array( $_POST['glc_fee'] ) ? wp_unslash( $_POST['glc_fee'] ) : [];
		$fees    = [];
		foreach ( array_merge( GLC_Rental::fee_place_keys(), [ GLC_Price_Store::OUT_OF_HOURS ] ) as $place ) {
			$mode           = isset( $modes[ $place ] ) && is_string( $modes[ $place ] ) && in_array( $modes[ $place ], self::FEE_MODES, true ) ? $modes[ $place ] : self::FEE_MODES[0];
			$fees[ $place ] = [ 'mode' => $mode, 'amount' => isset( $amounts[ $place ] ) && is_string( $amounts[ $place ] ) ? sanitize_text_field( $amounts[ $place ] ) : '' ];
		}
		return [
			'use_pricing'      => '1' === self::posted_text( 'glc_use_pricing' ),
			'mode'             => 'adjust' === self::posted_text( 'glc_mode' ) ? 'adjust' : 'replace',
			'table'            => GLC_Partner_Car::posted_table(),
			'factor'           => self::posted_text( 'glc_factor' ),
			'delta'            => self::posted_text( 'glc_delta' ),
			'round'            => '1' === self::posted_text( 'glc_round' ),
			'use_delivery'     => '1' === self::posted_text( 'glc_use_delivery' ),
			'use_out_of_hours' => '1' === self::posted_text( 'glc_use_out_of_hours' ),
			'fees'             => $fees,
		];
	}

	/** The form as it is first shown: nothing ticked, whole dollars, every fee left as it is. */
	private static function blank_draft(): array {
		$fees = [];
		foreach ( array_merge( GLC_Rental::fee_place_keys(), [ GLC_Price_Store::OUT_OF_HOURS ] ) as $place ) {
			$fees[ $place ] = [ 'mode' => self::FEE_MODES[0], 'amount' => '' ];
		}
		return [ 'use_pricing' => false, 'mode' => 'replace', 'table' => [], 'factor' => '', 'delta' => '', 'round' => true, 'use_delivery' => false, 'use_out_of_hours' => false, 'fees' => $fees, 'cars' => [] ];
	}

	/* ---------------------------------------------------- working the change */

	/**
	 * What each picked car would become.
	 *
	 * @return array [ items for the store, reasons the form itself cannot be previewed ]
	 */
	public static function build( array $car_ids, array $draft ): array {
		$stops = [];
		if ( ! $car_ids ) {
			$stops[] = glc_admin_t( 'bulk_err_no_cars' );
		}
		if ( ! $draft['use_pricing'] && ! $draft['use_delivery'] && ! $draft['use_out_of_hours'] ) {
			$stops[] = glc_admin_t( 'bulk_err_no_section' );
		}
		$adjust = $draft['use_pricing'] && 'adjust' === $draft['mode'];
		$factor = 1.0;
		$delta  = 0.0;
		if ( $adjust ) {
			if ( '' === $draft['factor'] && '' === $draft['delta'] ) {
				$stops[] = glc_admin_t( 'bulk_err_adjust_empty' );
			}
			if ( '' !== $draft['factor'] && ( ! preg_match( self::FACTOR_PATTERN, $draft['factor'] ) || (float) $draft['factor'] <= 0 ) ) {
				$stops[] = sprintf( glc_admin_t( 'bulk_err_factor' ), $draft['factor'] );
			}
			if ( '' !== $draft['delta'] && ! preg_match( self::DELTA_PATTERN, $draft['delta'] ) ) {
				$stops[] = sprintf( glc_admin_t( 'bulk_err_delta' ), $draft['delta'] );
			}
			$factor = '' === $draft['factor'] ? 1.0 : (float) $draft['factor'];
			$delta  = '' === $draft['delta'] ? 0.0 : (float) $draft['delta'];
		}
		foreach ( $draft['fees'] as $place => $fee ) {
			$used = GLC_Price_Store::OUT_OF_HOURS === $place ? $draft['use_out_of_hours'] : $draft['use_delivery'];
			if ( $used && 'set' === $fee['mode'] && '' === $fee['amount'] ) {
				$stops[] = sprintf( glc_admin_t( 'bulk_err_fee_empty' ), glc_admin_t( 'place_' . $place ) );
			}
		}
		if ( $stops ) {
			return [ [], $stops ];
		}

		$items = [];
		foreach ( $car_ids as $car_id ) {
			$state   = GLC_Price_Store::state( $car_id );
			$changes = [];
			if ( $draft['use_pricing'] ) {
				$changes['pricing'] = $adjust ? self::adjusted( $state['pricing'], $factor, $delta, $draft['round'] ) : $draft['table'];
			}
			$fees = [];
			if ( $draft['use_delivery'] ) {
				$fees['delivery'] = $state['fees']['delivery'];
				foreach ( GLC_Rental::fee_place_keys() as $place ) {
					$fees['delivery'] = self::fee_applied( $fees['delivery'], $place, $draft['fees'][ $place ] );
				}
			}
			if ( $draft['use_out_of_hours'] && 'leave' !== $draft['fees'][ GLC_Price_Store::OUT_OF_HOURS ]['mode'] ) {
				$one                               = self::fee_applied( [], GLC_Price_Store::OUT_OF_HOURS, $draft['fees'][ GLC_Price_Store::OUT_OF_HOURS ] );
				$fees[ GLC_Price_Store::OUT_OF_HOURS ] = $one[ GLC_Price_Store::OUT_OF_HOURS ] ?? '';
			}
			if ( $fees ) {
				$changes['fees'] = $fees;
			}
			// The revision the car has now is the lock: an apply after someone else's save is refused by the store.
			$items[ $car_id ] = [ 'changes' => $changes, 'rev' => GLC_Price_Store::rev( $car_id ) ];
		}
		return [ $items, [] ];
	}

	/** A car's own table with every price multiplied and shifted; its seasons stay. Whether the result is allowed is the store's question. */
	private static function adjusted( array $seasons, float $factor, float $delta, bool $whole ): array {
		$out = [];
		foreach ( $seasons as $season ) {
			$rates = [];
			foreach ( GLC_Pricing::TIERS as $band ) {
				$rate           = $season['rates'][ $band ] ?? '';
				$rates[ $band ] = is_numeric( $rate ) ? round( (float) $rate * $factor + $delta, $whole ? 0 : 2 ) : $rate;
			}
			$out[] = [ 'from' => (string) ( $season['from'] ?? '' ), 'to' => (string) ( $season['to'] ?? '' ), 'rates' => $rates ];
		}
		return $out;
	}

	/** A set of fee overrides after one choice for one place: left, set to the typed amount, free, or dropped back to the standard. */
	private static function fee_applied( array $overrides, string $place, array $fee ): array {
		if ( 'set' === $fee['mode'] ) {
			$overrides[ $place ] = $fee['amount'];
		} elseif ( 'free' === $fee['mode'] ) {
			$overrides[ $place ] = '0';
		} elseif ( 'standard' === $fee['mode'] ) {
			unset( $overrides[ $place ] );
		}
		return $overrides;
	}

	/* -------------------------------------------------------------- handlers */

	/** Work the change out for the picked cars and keep it for the preview. Writes to no car. */
	public static function preview(): void {
		GLC_Partner_Batch::check( self::PREVIEW );
		$car_ids = self::posted_cars();
		if ( null === $car_ids ) {
			GLC_Partner_Panel::refuse();
		}
		GLC_Partner_Batch::cars_allowed( $car_ids );
		$draft             = self::posted_draft();
		[ $items, $stops ] = self::build( $car_ids, $draft );
		$token             = GLC_Partner_Batch::keep( [ 'source' => self::SOURCE, 'items' => $items, 'stops' => $stops, 'draft' => $draft + [ 'cars' => $car_ids ] ] );
		wp_safe_redirect( GLC_Partner_Panel::screen_url( self::PAGE, [ GLC_Partner_Batch::FIELD => $token ] ) . '#glc-preview', 303 );
		exit;
	}

	public static function apply(): void {
		GLC_Partner_Batch::apply( self::APPLY, self::SOURCE, self::PAGE );
	}

	/* ------------------------------------------------------------- rendering */

	public static function render(): void {
		self::guard();
		$user    = get_current_user_id();
		$owner   = current_user_can( GLC_Partners::CAP_MANAGE );
		$cars    = GLC_Partners::cars_for( $user );
		$token   = GLC_Partner_Batch::requested_token();
		$plan    = GLC_Partner_Batch::get( $token, self::SOURCE );
		$flash   = GLC_Partner_Panel::take_flash( self::SOURCE );
		$draft   = ( $plan['draft'] ?? [] ) + self::blank_draft();
		$from    = self::requested_from();
		if ( ! $plan && $from ) {
			// "Start from this car's table": the form opens with that car's seasons and prices, to be changed and applied to others.
			$draft = [ 'use_pricing' => true, 'mode' => 'replace', 'table' => GLC_Price_Store::read_pricing( $from ) ] + $draft;
		}
		$picked   = array_map( 'intval', (array) $draft['cars'] );
		$partners = [];
		foreach ( $owner ? $cars : [] as $car_id ) {
			$partners[ GLC_Partners::partner_of( $car_id ) ] = true;
		}
		?>
		<div class="wrap glc-panel" lang="<?php echo esc_attr( GLC_Admin_Strings::lang() ); ?>">
			<h1><?php echo esc_html( glc_admin_t( 'bulk_title' ) ); ?></h1>
			<p class="description"><?php echo esc_html( glc_admin_t( 'bulk_intro' ) ); ?></p>
			<?php GLC_Partner_Batch::render_result( $flash ); ?>
			<?php if ( '' !== $token && ! $plan && ! $flash ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( glc_admin_t( 'batch_gone' ) ); ?></p></div>
			<?php endif; ?>
			<noscript><div class="notice notice-warning inline"><p><?php echo esc_html( glc_admin_t( 'car_needs_js' ) ); ?></p></div></noscript>
			<?php if ( ! $cars ) : ?>
				<p><?php echo esc_html( glc_admin_t( $owner ? 'cars_empty_all' : 'cars_empty' ) ); ?></p>
				</div>
				<?php
				return;
			endif;
			?>

			<form method="get" action="<?php echo esc_url( admin_url( $owner ? 'edit.php' : 'admin.php' ) ); ?>" class="glc-start-from">
				<?php if ( $owner ) : ?>
					<input type="hidden" name="post_type" value="car" />
				<?php endif; ?>
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<label for="glc-from"><?php echo esc_html( glc_admin_t( 'bulk_from_label' ) ); ?></label>
				<select id="glc-from" name="from">
					<?php foreach ( $cars as $car_id ) : ?>
						<option value="<?php echo (int) $car_id; ?>"<?php selected( $from, $car_id ); ?>><?php echo esc_html( GLC_Partner_Batch::car_name( $car_id ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php echo esc_html( glc_admin_t( 'bulk_from_button' ) ); ?></button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="glc-form glc-form--bulk" data-section="pricing" data-stales="#glc-preview">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::PREVIEW ); ?>" />
				<input type="hidden" name="<?php echo esc_attr( GLC_Partner_Car::TABLE_FIELD ); ?>" value="" />
				<input type="hidden" name="<?php echo esc_attr( self::CARS_FIELD ); ?>" value="<?php echo esc_attr( implode( ',', $picked ) ); ?>" />
				<?php wp_nonce_field( self::PREVIEW, '_wpnonce', false ); ?>

				<h2><label><input type="checkbox" name="glc_use_pricing" value="1"<?php checked( $draft['use_pricing'] ); ?> /> <?php echo esc_html( glc_admin_t( 'pricing_heading' ) ); ?></label></h2>
				<fieldset class="glc-bulk-mode">
					<p><label><input type="radio" name="glc_mode" value="replace"<?php checked( $draft['mode'], 'replace' ); ?> /> <strong><?php echo esc_html( glc_admin_t( 'bulk_mode_replace' ) ); ?></strong></label><br /><span class="description"><?php echo esc_html( glc_admin_t( 'bulk_mode_replace_help' ) ); ?></span></p>
					<?php GLC_Partner_Car::render_season_editor( (array) $draft['table'] ); ?>
					<p><label><input type="radio" name="glc_mode" value="adjust"<?php checked( $draft['mode'], 'adjust' ); ?> /> <strong><?php echo esc_html( glc_admin_t( 'bulk_mode_adjust' ) ); ?></strong></label><br /><span class="description"><?php echo esc_html( glc_admin_t( 'bulk_mode_adjust_help' ) ); ?></span></p>
					<p>
						<label for="glc-factor"><?php echo esc_html( glc_admin_t( 'bulk_factor' ) ); ?></label>
						<input type="text" inputmode="decimal" class="small-text" id="glc-factor" name="glc_factor" value="<?php echo esc_attr( (string) $draft['factor'] ); ?>" />
						<label for="glc-delta"><?php echo esc_html( glc_admin_t( 'bulk_delta' ) ); ?></label>
						<input type="text" inputmode="decimal" class="small-text" id="glc-delta" name="glc_delta" value="<?php echo esc_attr( (string) $draft['delta'] ); ?>" />
						<label><input type="checkbox" name="glc_round" value="1"<?php checked( $draft['round'] ); ?> /> <?php echo esc_html( glc_admin_t( 'bulk_round' ) ); ?></label>
					</p>
				</fieldset>

				<h2><?php echo esc_html( glc_admin_t( 'bulk_fees_heading' ) ); ?></h2>
				<p><label><input type="checkbox" name="glc_use_delivery" value="1"<?php checked( $draft['use_delivery'] ); ?> /> <?php echo esc_html( glc_admin_t( 'bulk_use_delivery' ) ); ?></label>
				&nbsp; <label><input type="checkbox" name="glc_use_out_of_hours" value="1"<?php checked( $draft['use_out_of_hours'] ); ?> /> <?php echo esc_html( glc_admin_t( 'bulk_use_out_of_hours' ) ); ?></label></p>
				<table class="widefat striped glc-fees">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'fees_col_what' ) ); ?></th>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'bulk_fee_do' ) ); ?></th>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'bulk_fee_amount' ) ); ?></th>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'fees_col_standard' ) ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $draft['fees'] as $place => $fee ) : ?>
							<tr>
								<th scope="row">
									<label for="glc-fee-mode-<?php echo esc_attr( $place ); ?>"><?php echo esc_html( glc_admin_t( 'place_' . $place ) ); ?></label>
									<?php if ( GLC_Price_Store::OUT_OF_HOURS === $place ) : ?>
										<br /><span class="description"><?php echo esc_html( GLC_Partner_Car::window_text() ); ?></span>
									<?php endif; ?>
								</th>
								<td>
									<select id="glc-fee-mode-<?php echo esc_attr( $place ); ?>" name="glc_fee_mode[<?php echo esc_attr( $place ); ?>]">
										<?php foreach ( self::FEE_MODES as $mode ) : ?>
											<option value="<?php echo esc_attr( $mode ); ?>"<?php selected( $fee['mode'], $mode ); ?>><?php echo esc_html( self::mode_text( $mode ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td><input type="number" step="0.01" min="0" inputmode="decimal" class="small-text glc-fee" name="glc_fee[<?php echo esc_attr( $place ); ?>]" value="<?php echo esc_attr( (string) $fee['amount'] ); ?>" aria-label="<?php echo esc_attr( glc_admin_t( 'bulk_fee_amount' ) . ' · ' . glc_admin_t( 'place_' . $place ) ); ?>" /></td>
								<td><?php echo esc_html( GLC_Partner_Car::standard_text( $place ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php echo esc_html( glc_admin_t( 'bulk_cars_heading' ) ); ?></h2>
				<?php if ( count( $partners ) > 1 ) : ?>
					<p>
						<label for="glc-pick-partner"><?php echo esc_html( glc_admin_t( 'bulk_partner_filter' ) ); ?></label>
						<select id="glc-pick-partner" class="glc-pick-partner">
							<option value=""><?php echo esc_html( glc_admin_t( 'bulk_partner_all' ) ); ?></option>
							<?php foreach ( array_keys( $partners ) as $partner_id ) : ?>
								<option value="<?php echo (int) $partner_id; ?>"><?php echo esc_html( $partner_id ? GLC_Partners::name( get_userdata( $partner_id ) ) : glc_admin_t( 'partner_none' ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
				<?php endif; ?>
				<table class="widefat striped glc-pick-table">
					<thead>
						<tr>
							<td><input type="checkbox" class="glc-pick-all" aria-label="<?php echo esc_attr( glc_admin_t( 'bulk_pick_all' ) ); ?>" /></td>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'col_car' ) ); ?></th>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'col_plate' ) ); ?></th>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'bulk_col_seasons' ) ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $cars as $car_id ) : ?>
							<?php $plate = trim( (string) get_post_meta( $car_id, 'glc_registration', true ) ); ?>
							<tr data-partner="<?php echo (int) GLC_Partners::partner_of( $car_id ); ?>">
								<td><input type="checkbox" class="glc-pick" id="glc-pick-<?php echo (int) $car_id; ?>" value="<?php echo (int) $car_id; ?>"<?php checked( in_array( $car_id, $picked, true ) ); ?> /></td>
								<th scope="row"><label for="glc-pick-<?php echo (int) $car_id; ?>"><?php echo esc_html( get_the_title( $car_id ) ); ?></label></th>
								<td><?php echo esc_html( '' !== $plate ? $plate : glc_admin_t( 'not_set' ) ); ?></td>
								<td><?php echo (int) count( GLC_Price_Store::read_pricing( $car_id ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<button type="submit" class="button button-primary glc-bulk-preview"><?php echo esc_html( glc_admin_t( 'preview_button' ) ); ?></button>
					<span class="description"><?php echo esc_html( glc_admin_t( 'bulk_preview_help' ) ); ?></span>
				</p>
			</form>

			<?php if ( $plan ) : ?>
				<div id="glc-preview" class="glc-batch-preview">
					<h2><?php echo esc_html( glc_admin_t( 'batch_preview_heading' ) ); ?></h2>
					<p class="glc-stale-note glc-fault" hidden><?php echo esc_html( glc_admin_t( 'batch_stale' ) ); ?></p>
					<?php GLC_Partner_Batch::render_preview( $token, $plan, self::APPLY ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** One of the four choices for a fee, in words. */
	private static function mode_text( string $mode ): string {
		return match ( $mode ) {
			'set'      => glc_admin_t( 'bulk_fee_set' ),
			'free'     => glc_admin_t( 'bulk_fee_free' ),
			'standard' => glc_admin_t( 'bulk_fee_standard' ),
			default    => glc_admin_t( 'bulk_fee_leave' ),
		};
	}
}
