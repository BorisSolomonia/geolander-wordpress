<?php
/**
 * The owner's Partners screen (Cars → Partners): which partner has which car,
 * who the partners are, and how many booking requests went to each of them.
 *
 * It needs `glc_manage_partners`, which only the site's owner holds.
 *
 * The count shows quantities and nothing else. It never reads a customer's
 * name, email or message, so none of them can reach this screen or its source.
 * It is also never a stored number: it is counted from the booking requests
 * each time, and the total is counted a second time by a plain SQL count, so a
 * request that was missed would show as a difference.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Partner_Admin {

	public const PAGE = 'glc-partners';

	private const ASSIGN_ACTION = 'glc_partners_assign';

	/** The columns of the count that are not a month. */
	private const EARLIER = 'earlier';
	private const TOTAL   = 'total';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_post_' . self::ASSIGN_ACTION, [ __CLASS__, 'assign' ] );
		add_action( 'admin_post_nopriv_' . self::ASSIGN_ACTION, [ 'GLC_Partner_Panel', 'refuse' ] );
		add_filter( 'post_row_actions', [ __CLASS__, 'row_action' ], 10, 2 );
	}

	public static function url(): string {
		return add_query_arg( [ 'post_type' => 'car', 'page' => self::PAGE ], admin_url( 'edit.php' ) );
	}

	public static function menu(): void {
		$hook = add_submenu_page( 'edit.php?post_type=car', glc_admin_t( 'partners_title' ), glc_admin_t( 'partners_menu' ), GLC_Partners::CAP_MANAGE, self::PAGE, [ __CLASS__, 'render' ] );
		add_action( 'load-' . $hook, [ __CLASS__, 'assets' ] );
	}

	public static function assets(): void {
		wp_enqueue_style( 'glc-partner', GLC_URL . 'assets/partner.css', [], GLC_VERSION );
	}

	/** "Pricing & fees" under each car of the Cars list. */
	public static function row_action( $actions, $post ) {
		if ( $post instanceof WP_Post && 'car' === $post->post_type && 'trash' !== $post->post_status && GLC_Partners::can_manage( get_current_user_id(), (int) $post->ID ) ) {
			$actions['glc_pricing'] = sprintf( '<a href="%s">%s</a>', esc_url( GLC_Partner_Car::url( (int) $post->ID ) ), esc_html( glc_admin_t( 'pricing_menu' ) ) );
		}
		return $actions;
	}

	/* ------------------------------------------------------------ assignment */

	/** A posted list [ car id => partner id ]: whole numbers on both sides, or null when it is anything else. */
	private static function posted_map( string $field ): ?array {
		$raw = $_POST[ $field ] ?? [];
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$map = [];
		foreach ( $raw as $car_id => $partner_id ) {
			if ( ! ctype_digit( (string) $car_id ) || ! is_string( $partner_id ) || ! ctype_digit( $partner_id ) ) {
				return null;
			}
			$map[ (int) $car_id ] = (int) $partner_id;
		}
		return $map;
	}

	/**
	 * Save the assignment table. Only the rows the owner really changed are
	 * written: each row says what it showed when the page was opened, and a row
	 * left alone is not sent back over a change made elsewhere since. A changed
	 * row whose car has moved on in the meantime stops the whole save.
	 */
	public static function assign(): void {
		$nonce = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) ? sanitize_key( $_POST['_wpnonce'] ) : '';
		if ( ! current_user_can( GLC_Partners::CAP_MANAGE ) || ! wp_verify_nonce( $nonce, self::ASSIGN_ACTION ) ) {
			GLC_Partner_Panel::refuse();
		}
		$wanted = self::posted_map( 'glc_assign' );
		$shown  = self::posted_map( 'glc_was' );
		$lines  = [];
		$change = [];
		if ( null === $wanted || null === $shown || array_diff_key( $wanted, $shown ) ) {
			$lines[] = glc_admin_t( 'assign_unreadable' );
		} else {
			foreach ( $wanted as $car_id => $partner_id ) {
				if ( $partner_id === $shown[ $car_id ] ) {
					continue;
				}
				if ( GLC_Partners::partner_of( $car_id ) !== $shown[ $car_id ] ) {
					$lines[] = sprintf( glc_admin_t( 'assign_stale' ), get_the_title( $car_id ) );
				}
				$change[ $car_id ] = $partner_id;
			}
		}
		if ( $lines ) {
			GLC_Partner_Panel::flash( 'partners', [ 'ok' => false, 'lines' => $lines ] );
		} else {
			$done = GLC_Partners::assign( $change, '', $shown );
			if ( is_wp_error( $done ) ) {
				GLC_Partner_Panel::flash( 'partners', [ 'ok' => false, 'lines' => [ $done->get_error_message() ] ] );
			} else {
				$lines = [ $done ? sprintf( glc_admin_t( 'assign_saved' ), count( $done ) ) : glc_admin_t( 'saved_unchanged' ) ];
				foreach ( $done as $car_id ) {
					$partner = get_userdata( GLC_Partners::partner_of( $car_id ) );
					$lines[] = sprintf( glc_admin_t( 'assign_line' ), get_the_title( $car_id ), $partner ? GLC_Partners::name( $partner ) : glc_admin_t( 'partner_none' ) );
				}
				GLC_Partner_Panel::flash( 'partners', [ 'ok' => true, 'lines' => $lines ] );
			}
		}
		wp_safe_redirect( self::url(), 303 );
		exit;
	}

	/* ----------------------------------------------------------- the count */

	/** A phone as people read it: the digits with a plus. */
	private static function plus( string $digits ): string {
		return '' === $digits ? '' : '+' . ltrim( $digits, '+' );
	}

	/**
	 * Booking requests by who the customer's message was sent to, by car and by
	 * month. A request is counted where it was routed when it was made; a car
	 * that changed hands later keeps its old requests with the old recipient.
	 * Requests from before routing was recorded went to the site number and are
	 * counted with Geolander.
	 *
	 * @return array{columns: string[], rows: array, sum: int} columns: 'Y-m' keys, then EARLIER and TOTAL;
	 *               rows: [ recipient => [ label, numbers, cells, cars => [ car id => cells ] ] ]
	 */
	public static function message_counts(): array {
		$months = is_numeric( GLC_Settings::get( 'counter_months' ) ) ? max( 0, (int) GLC_Settings::get( 'counter_months' ) ) : 0;
		$first  = ( new DateTimeImmutable( 'now', GLC_Settings::timezone() ) )->modify( 'first day of this month' );
		$shown  = [];
		for ( $back = $months - 1; $back >= 0; $back-- ) {
			$shown[] = $first->modify( '-' . $back . ' months' )->format( 'Y-m' );
		}
		$columns = array_merge( $shown, [ self::EARLIER, self::TOTAL ] );
		$empty   = array_fill_keys( $columns, 0 );
		$rows    = [];
		$sum     = 0;
		$ids     = get_posts( [
			'post_type'      => 'booking_request',
			// Every status, the bin too: a request that was binned was still sent to someone.
			'post_status'    => array_values( array_diff( array_keys( get_post_stati() ), [ 'auto-draft' ] ) ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		foreach ( $ids as $id ) {
			$source = (string) get_post_meta( $id, 'glc_routing_source', true );
			$number = (string) get_post_meta( $id, 'glc_routed_number', true );
			$to     = (int) get_post_meta( $id, 'glc_partner_id', true );
			if ( 'partner' === $source && $to ) {
				$key = 'partner:' . $to;
			} elseif ( 'car' === $source && '' !== $number ) {
				$key = 'car:' . $number;
			} else {
				$key = 'site';
			}
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = [ 'label' => self::recipient( $key ), 'numbers' => [], 'cells' => $empty, 'cars' => [] ];
			}
			if ( '' !== $number ) {
				$rows[ $key ]['numbers'][ self::plus( $number ) ] = true;
			}
			$month  = GLC_Settings::local_time( (string) get_post_field( 'post_date_gmt', $id ), 'Y-m' );
			$column = in_array( $month, $shown, true ) ? $month : self::EARLIER;
			$car_id = (int) get_post_meta( $id, 'glc_car_id', true );
			$rows[ $key ]['cars'][ $car_id ] = $rows[ $key ]['cars'][ $car_id ] ?? $empty;
			foreach ( [ $column, self::TOTAL ] as $cell ) {
				$rows[ $key ]['cells'][ $cell ]++;
				$rows[ $key ]['cars'][ $car_id ][ $cell ]++;
			}
			$sum++;
		}
		ksort( $rows );
		return [ 'columns' => $columns, 'rows' => $rows, 'sum' => $sum ];
	}

	/** The same total by another road: one SQL count of the booking requests, no grouping, no meta. */
	public static function records_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> %s", 'booking_request', 'auto-draft' ) );
	}

	/** Who a row of the count is: a partner by name, a number kept on a car, or Geolander. */
	private static function recipient( string $key ): string {
		[ $kind, $id ] = explode( ':', $key . ':' );
		if ( 'partner' === $kind ) {
			$user = get_userdata( (int) $id );
			return $user ? GLC_Partners::name( $user ) : sprintf( glc_admin_t( 'count_partner_removed' ), (int) $id );
		}
		return 'car' === $kind ? glc_admin_t( 'count_car_number' ) : glc_admin_t( 'count_site' );
	}

	/** A column's heading: "Oct 2026", "Earlier" or "Total". */
	private static function column_text( string $column ): string {
		if ( self::EARLIER === $column ) {
			return glc_admin_t( 'count_earlier' );
		}
		if ( self::TOTAL === $column ) {
			return glc_admin_t( 'count_total' );
		}
		return sprintf( glc_admin_t( 'month_year' ), glc_admin_t( 'month_' . (int) substr( $column, 5, 2 ) ), substr( $column, 0, 4 ) );
	}

	/* ------------------------------------------------------------- rendering */

	public static function render(): void {
		if ( ! current_user_can( GLC_Partners::CAP_MANAGE ) ) {
			GLC_Partner_Panel::refuse();
		}
		$partners = GLC_Partners::partners();
		$cars     = GLC_Partners::cars_for( get_current_user_id() );
		$blank    = glc_admin_t( 'not_set' );
		$has      = [];
		foreach ( $cars as $car_id ) {
			$has[ GLC_Partners::partner_of( $car_id ) ][] = $car_id;
		}
		?>
		<div class="wrap glc-panel" lang="<?php echo esc_attr( GLC_Admin_Strings::lang() ); ?>">
			<h1><?php echo esc_html( glc_admin_t( 'partners_title' ) ); ?></h1>
			<?php GLC_Partner_Panel::render_flash( GLC_Partner_Panel::take_flash( 'partners' ) ); ?>

			<h2><?php echo esc_html( glc_admin_t( 'assign_heading' ) ); ?></h2>
			<p class="description"><?php echo esc_html( glc_admin_t( 'assign_help' ) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ASSIGN_ACTION ); ?>" />
				<?php wp_nonce_field( self::ASSIGN_ACTION, '_wpnonce', false ); ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'col_car' ) ); ?></th>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'col_plate' ) ); ?></th>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'col_status' ) ); ?></th>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'col_partner' ) ); ?></th>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'col_answers' ) ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $cars as $car_id ) : ?>
							<?php
							$plate  = trim( (string) get_post_meta( $car_id, 'glc_registration', true ) );
							$status = get_post_status_object( (string) get_post_status( $car_id ) );
							$now    = GLC_Partners::partner_of( $car_id );
							$route  = GLC_Gateway_WhatsApp::route_for( $car_id );
							$legacy = (string) preg_replace( '/[^0-9]/', '', (string) get_post_meta( $car_id, 'glc_whatsapp_number', true ) );
							?>
							<tr>
								<td><a href="<?php echo esc_url( GLC_Partner_Car::url( $car_id ) ); ?>"><?php echo esc_html( get_the_title( $car_id ) ); ?></a></td>
								<td><?php echo esc_html( '' !== $plate ? $plate : $blank ); ?></td>
								<td><?php echo esc_html( $status ? $status->label : $blank ); ?></td>
								<td>
									<input type="hidden" name="glc_was[<?php echo (int) $car_id; ?>]" value="<?php echo (int) $now; ?>" />
									<select name="glc_assign[<?php echo (int) $car_id; ?>]" aria-label="<?php echo esc_attr( sprintf( glc_admin_t( 'assign_label' ), get_the_title( $car_id ) ) ); ?>">
										<option value="0"<?php selected( $now, 0 ); ?>><?php echo esc_html( glc_admin_t( 'partner_none' ) ); ?></option>
										<?php foreach ( $partners as $partner ) : ?>
											<option value="<?php echo (int) $partner->ID; ?>"<?php selected( $now, (int) $partner->ID ); ?>><?php echo esc_html( GLC_Partners::name( $partner ) . ' · ' . ( GLC_Partners::phone( (int) $partner->ID ) ?: glc_admin_t( 'partner_no_phone' ) ) ); ?></option>
										<?php endforeach; ?>
										<?php if ( $now && ! GLC_Partners::is_partner( get_userdata( $now ) ) ) : ?>
											<option value="<?php echo (int) $now; ?>" selected><?php echo esc_html( sprintf( glc_admin_t( 'count_partner_removed' ), $now ) ); ?></option>
										<?php endif; ?>
									</select>
								</td>
								<td>
									<?php echo esc_html( sprintf( glc_admin_t( 'answers_' . $route['source'] ), self::plus( $route['number'] ) ?: $blank ) ); ?>
									<?php if ( $now && 'partner' !== $route['source'] ) : ?>
										<br /><strong class="glc-fault"><?php echo esc_html( glc_admin_t( 'answers_no_phone' ) ); ?></strong>
									<?php endif; ?>
									<?php if ( '' !== $legacy && 'car' !== $route['source'] ) : ?>
										<br /><span class="description"><?php echo esc_html( sprintf( glc_admin_t( 'answers_legacy_kept' ), self::plus( $legacy ) ) ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="submit" class="button button-primary"><?php echo esc_html( glc_admin_t( 'assign_save' ) ); ?></button></p>
			</form>

			<h2><?php echo esc_html( glc_admin_t( 'partners_heading' ) ); ?></h2>
			<?php if ( ! $partners ) : ?>
				<p><?php echo esc_html( glc_admin_t( 'partners_empty' ) ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'col_name' ) ); ?></th>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'profile_phone' ) ); ?></th>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'profile_email' ) ); ?></th>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'col_cars' ) ); ?></th>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'col_last_login' ) ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $partners as $partner ) : ?>
							<?php
							$phone = GLC_Partners::phone( (int) $partner->ID );
							$seen  = (int) get_user_meta( $partner->ID, GLC_Partners::META_LAST_LOGIN, true );
							?>
							<tr>
								<td><a href="<?php echo esc_url( get_edit_user_link( $partner->ID ) ); ?>"><?php echo esc_html( GLC_Partners::name( $partner ) ); ?></a></td>
								<td>
									<?php if ( '' !== $phone ) : ?>
										<?php echo esc_html( $phone ); ?>
									<?php else : ?>
										<strong class="glc-fault"><?php echo esc_html( glc_admin_t( 'partner_no_phone' ) ); ?></strong>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( (string) $partner->user_email ); ?></td>
								<td><?php echo (int) count( $has[ (int) $partner->ID ] ?? [] ); ?></td>
								<td><?php echo esc_html( $seen ? GLC_Settings::local_time( $seen ) : $blank ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<p class="description"><?php echo esc_html( glc_admin_t( 'partners_help' ) ); ?> <a href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>"><?php echo esc_html( glc_admin_t( 'partners_add' ) ); ?></a></p>

			<h2><?php echo esc_html( glc_admin_t( 'count_heading' ) ); ?></h2>
			<?php self::render_counts(); ?>
		</div>
		<?php
	}

	/** One line of the count: a heading cell and a number per column. */
	private static function render_count_row( string $heading, string $detail, array $cells, array $columns, string $tag = 'td' ): void {
		echo '<tr><th scope="row">' . esc_html( $heading );
		if ( '' !== $detail ) {
			echo '<br /><span class="description">' . esc_html( $detail ) . '</span>';
		}
		echo '</th>';
		foreach ( $columns as $column ) {
			printf( '<%1$s class="glc-number">%2$d</%1$s>', esc_attr( $tag ), (int) $cells[ $column ] );
		}
		echo '</tr>';
	}

	private static function render_counts(): void {
		$counts  = self::message_counts();
		$records = self::records_count();
		$columns = $counts['columns'];
		$totals  = array_fill_keys( $columns, 0 );
		foreach ( $counts['rows'] as $row ) {
			foreach ( $columns as $column ) {
				$totals[ $column ] += $row['cells'][ $column ];
			}
		}
		$agree = $totals[ self::TOTAL ] === $records && $counts['sum'] === $records;
		?>
		<p class="description"><?php echo esc_html( glc_admin_t( 'count_help' ) ); ?></p>
		<table class="widefat striped glc-counts">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html( glc_admin_t( 'count_to' ) ); ?></th>
					<?php foreach ( $columns as $column ) : ?>
						<th scope="col" class="glc-number"><?php echo esc_html( self::column_text( $column ) ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $counts['rows'] as $row ) : ?>
					<?php self::render_count_row( $row['label'], implode( glc_admin_t( 'summary_join' ), array_keys( $row['numbers'] ) ), $row['cells'], $columns ); ?>
				<?php endforeach; ?>
				<?php if ( ! $counts['rows'] ) : ?>
					<tr><td colspan="<?php echo count( $columns ) + 1; ?>"><?php echo esc_html( glc_admin_t( 'count_empty' ) ); ?></td></tr>
				<?php endif; ?>
			</tbody>
			<tfoot>
				<?php self::render_count_row( glc_admin_t( 'count_total' ), '', $totals, $columns, 'th' ); ?>
			</tfoot>
		</table>
		<p class="<?php echo $agree ? '' : 'glc-fault'; ?>" data-counted="<?php echo (int) $totals[ self::TOTAL ]; ?>" data-records="<?php echo (int) $records; ?>">
			<?php echo esc_html( sprintf( glc_admin_t( $agree ? 'count_check' : 'count_check_fault' ), $totals[ self::TOTAL ], $records ) ); ?>
		</p>
		<?php if ( $counts['rows'] ) : ?>
			<details class="glc-by-car">
				<summary><?php echo esc_html( glc_admin_t( 'count_by_car' ) ); ?></summary>
				<table class="widefat striped glc-counts">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'count_to_car' ) ); ?></th>
							<?php foreach ( $columns as $column ) : ?>
								<th scope="col" class="glc-number"><?php echo esc_html( self::column_text( $column ) ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $counts['rows'] as $row ) : ?>
							<?php foreach ( $row['cars'] as $car_id => $cells ) : ?>
								<?php self::render_count_row( $row['label'], 'car' === get_post_type( $car_id ) ? get_the_title( $car_id ) : sprintf( glc_admin_t( 'count_car_removed' ), $car_id ), $cells, $columns ); ?>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		<?php endif; ?>
		<p class="description"><?php echo esc_html( glc_admin_t( 'count_footnote' ) ); ?></p>
		<?php
	}
}
