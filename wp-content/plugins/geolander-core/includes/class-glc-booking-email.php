<?php
/**
 * Customer booking emails and the staff confirmation generator.
 *
 * Cloudflare DNS does not provide outbound mail. The generator therefore
 * always works in wp-admin (including an "open email draft" fallback), while
 * automatic delivery activates when GLC_SMTP_* Railway variables are present.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Booking_Email {

	public static function init(): void {
		add_action( 'phpmailer_init', [ __CLASS__, 'configure_smtp' ] );
		add_action( 'add_meta_boxes_booking_request', [ __CLASS__, 'add_meta_box' ] );
		add_action( 'admin_post_glc_send_booking_confirmation', [ __CLASS__, 'handle_send' ] );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notice' ] );
		add_filter( 'manage_booking_request_posts_columns', [ __CLASS__, 'columns' ] );
		add_action( 'manage_booking_request_posts_custom_column', [ __CLASS__, 'column_value' ], 10, 2 );
	}

	private static function env( string $key ): string {
		$value = getenv( $key );
		return false === $value ? '' : trim( (string) $value );
	}

	public static function transport_configured(): bool {
		return '' !== self::env( 'GLC_SMTP_HOST' );
	}

	/** Configure WordPress' bundled PHPMailer only when Railway SMTP vars exist. */
	public static function configure_smtp( PHPMailer\PHPMailer\PHPMailer $mailer ): void {
		$host = self::env( 'GLC_SMTP_HOST' );
		if ( '' === $host ) {
			return;
		}

		$username = self::env( 'GLC_SMTP_USERNAME' );
		$password = self::env( 'GLC_SMTP_PASSWORD' );
		$mailer->isSMTP();
		$mailer->Host       = $host;
		$mailer->Port       = max( 1, (int) ( self::env( 'GLC_SMTP_PORT' ) ?: 587 ) );
		$mailer->SMTPAuth   = '' !== $username || '' !== $password;
		$mailer->Username   = $username;
		$mailer->Password   = $password;
		$mailer->SMTPSecure = self::env( 'GLC_SMTP_ENCRYPTION' ) ?: 'tls';

		$from = sanitize_email( self::env( 'GLC_SMTP_FROM' ) ?: GLC_Settings::get( 'email' ) );
		if ( $from ) {
			$mailer->setFrom( $from, GLC_Settings::get( 'business_name', 'Geolander' ), false );
		}
	}

	public static function send_request_received( int $post_id ): bool {
		if ( ! self::transport_configured() ) {
			update_post_meta( $post_id, 'glc_receipt_status', 'not_configured' );
			return false;
		}
		$sent = self::send( $post_id, false );
		update_post_meta( $post_id, 'glc_receipt_status', $sent ? 'sent' : 'failed' );
		if ( $sent ) {
			update_post_meta( $post_id, 'glc_receipt_sent_at', current_time( 'mysql', true ) );
		}
		return $sent;
	}

	public static function send_confirmation( int $post_id ): bool {
		if ( ! self::transport_configured() ) {
			return false;
		}
		$sent = self::send( $post_id, true );
		if ( $sent ) {
			update_post_meta( $post_id, 'glc_booking_status', 'confirmed' );
			update_post_meta( $post_id, 'glc_confirmation_sent_at', current_time( 'mysql', true ) );
		}
		return $sent;
	}

	private static function send( int $post_id, bool $confirmed ): bool {
		$email = sanitize_email( get_post_meta( $post_id, 'glc_email', true ) );
		if ( ! $email ) {
			return false;
		}
		$from = sanitize_email( self::env( 'GLC_SMTP_FROM' ) ?: GLC_Settings::get( 'email' ) );
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		if ( $from ) {
			$headers[] = sprintf( 'From: %s <%s>', GLC_Settings::get( 'business_name', 'Geolander' ), $from );
		}
		return wp_mail(
			$email,
			self::subject( $post_id, $confirmed ),
			self::html( $post_id, $confirmed ),
			$headers
		);
	}

	public static function subject( int $post_id, bool $confirmed = true ): string {
		$reference = get_post_meta( $post_id, 'glc_reference', true );
		return $confirmed
			? sprintf( 'Booking confirmed: %s | Geolander', $reference )
			: sprintf( 'Booking request received: %s | Geolander', $reference );
	}

	private static function data( int $post_id ): array {
		$keys = [
			'reference', 'name', 'email', 'from', 'to', 'days', 'pickup_label',
			'return_label', 'rental_total', 'pickup_fee', 'return_fee', 'total',
			'prepayment', 'balance', 'booking_status',
		];
		$data = [];
		foreach ( $keys as $key ) {
			$data[ $key ] = get_post_meta( $post_id, 'glc_' . $key, true );
		}
		$data['car'] = get_the_title( (int) get_post_meta( $post_id, 'glc_car_id', true ) );
		if ( '' === (string) $data['rental_total'] && (float) $data['total'] > 0 ) {
			$data['rental_total'] = $data['total'];
		}
		if ( '' === (string) $data['prepayment'] && (float) $data['total'] > 0 ) {
			$data['prepayment'] = round( (float) $data['total'] * ( (float) GLC_Settings::get( 'prepayment_percent', 10 ) / 100 ), 2 );
		}
		if ( '' === (string) $data['balance'] && (float) $data['total'] > 0 ) {
			$data['balance'] = round( (float) $data['total'] - (float) $data['prepayment'], 2 );
		}
		return $data;
	}

	private static function money( $amount ): string {
		return GLC_Format::money_exact( (float) $amount, 'en' );
	}

	/** Plain-text version used by the no-SMTP email-draft fallback. */
	public static function text( int $post_id, bool $confirmed = true ): string {
		$d = self::data( $post_id );
		$lines = [
			$confirmed ? 'Your Geolander booking is confirmed.' : 'We received your booking request. It is not confirmed yet.',
			'',
			'Booking reference: ' . $d['reference'],
			'Customer: ' . $d['name'],
			'Exact car: ' . $d['car'],
			sprintf( 'Dates: %s to %s (%s days)', $d['from'], $d['to'], $d['days'] ),
			'Pickup: ' . ( $d['pickup_label'] ?: 'Not recorded in the original request' ),
			'Return: ' . ( $d['return_label'] ?: 'Not recorded in the original request' ),
		];
		if ( (float) $d['rental_total'] > 0 ) {
			$lines[] = 'Rental: ' . self::money( $d['rental_total'] );
		}
		if ( (float) $d['pickup_fee'] > 0 ) {
			$lines[] = 'Pickup charge: ' . self::money( $d['pickup_fee'] );
		}
		if ( (float) $d['return_fee'] > 0 ) {
			$lines[] = 'Return charge: ' . self::money( $d['return_fee'] );
		}
		if ( (float) $d['total'] > 0 ) {
			$lines[] = 'Final total shown: ' . self::money( $d['total'] );
			$lines[] = '10% confirmation prepayment: ' . self::money( $d['prepayment'] );
			$lines[] = 'Balance at pickup: ' . self::money( $d['balance'] );
		}
		$lines = array_merge( $lines, [
			'',
			'Included: full insurance, no deductible/excess, wheels and windshield coverage, single-vehicle accident coverage, third-party liability up to 30,000 GEL, unlimited mileage within Georgia, and free winter tyres.',
			'Not covered: tyres. Insurance is also void for wrong-lane driving, running a red light, speeding, or failing to tell Geolander where an incident occurred.',
			'No security deposit or card preauthorization is required.',
			'',
			'Cancellation: 50% of the prepayment is refundable when cancelled at least 30 days before pickup. With fewer than 30 days remaining, the prepayment is non-refundable.',
			'',
			'Questions? WhatsApp +995 551 33 04 14 or email info@geo-lander.com.',
		] );
		return implode( "\n", $lines );
	}

	public static function html( int $post_id, bool $confirmed = true ): string {
		$text = self::text( $post_id, $confirmed );
		$title = $confirmed ? 'Booking confirmed' : 'Booking request received';
		return sprintf(
			'<div style="font-family:Arial,sans-serif;max-width:680px;margin:auto;color:#1e332c"><h1 style="color:#1e332c">%s</h1><div style="white-space:pre-line;line-height:1.6">%s</div><p style="margin-top:28px;color:#6b7280">Geolander car rental · Mtatsminda, Tbilisi</p></div>',
			esc_html( $title ),
			esc_html( $text )
		);
	}

	public static function add_meta_box(): void {
		add_meta_box(
			'glc_booking_confirmation',
			__( 'Customer confirmation', 'geolander' ),
			[ __CLASS__, 'render_meta_box' ],
			'booking_request',
			'normal',
			'high'
		);
	}

	public static function render_meta_box( WP_Post $post ): void {
		$d       = self::data( $post->ID );
		$subject = self::subject( $post->ID, true );
		$body    = self::text( $post->ID, true );
		$mailto  = $d['email'] ? 'mailto:' . rawurlencode( $d['email'] ) . '?subject=' . rawurlencode( $subject ) . '&body=' . rawurlencode( $body ) : '';
		$send_url = wp_nonce_url(
			add_query_arg( [ 'action' => 'glc_send_booking_confirmation', 'booking_id' => $post->ID ], admin_url( 'admin-post.php' ) ),
			'glc_send_booking_confirmation_' . $post->ID
		);
		?>
		<table class="widefat striped" style="margin-bottom:16px">
			<tbody>
				<tr><th><?php esc_html_e( 'Customer', 'geolander' ); ?></th><td><?php echo esc_html( $d['name'] . ' · ' . $d['email'] ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Exact car', 'geolander' ); ?></th><td><?php echo esc_html( $d['car'] ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Dates', 'geolander' ); ?></th><td><?php echo esc_html( $d['from'] . ' → ' . $d['to'] . ' · ' . $d['days'] . ' days' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Pickup / return', 'geolander' ); ?></th><td><?php echo esc_html( $d['pickup_label'] . ' → ' . $d['return_label'] ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Total / prepayment / balance', 'geolander' ); ?></th><td><?php echo (float) $d['total'] > 0 ? esc_html( self::money( $d['total'] ) . ' / ' . self::money( $d['prepayment'] ) . ' / ' . self::money( $d['balance'] ) ) : '—'; ?></td></tr>
			</tbody>
		</table>
		<label for="glc-confirmation-preview"><strong><?php esc_html_e( 'Generated confirmation email', 'geolander' ); ?></strong></label>
		<input class="widefat" type="text" readonly value="<?php echo esc_attr( $subject ); ?>" style="margin:6px 0" />
		<textarea id="glc-confirmation-preview" class="widefat" readonly rows="18"><?php echo esc_textarea( $body ); ?></textarea>
		<p>
			<?php if ( $mailto ) : ?>
				<a class="button button-secondary" href="<?php echo esc_url( $mailto ); ?>"><?php esc_html_e( 'Open email draft', 'geolander' ); ?></a>
			<?php endif; ?>
			<?php if ( self::transport_configured() && $d['email'] ) : ?>
				<a class="button button-primary" style="margin-left:8px" href="<?php echo esc_url( $send_url ); ?>"><?php esc_html_e( 'Send confirmation now', 'geolander' ); ?></a>
			<?php else : ?>
				<span class="description" style="margin-left:8px"><?php esc_html_e( 'Automatic sending is disabled until GLC_SMTP_HOST and the SMTP Railway variables are configured.', 'geolander' ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	public static function handle_send(): void {
		$post_id = absint( $_REQUEST['booking_id'] ?? 0 );
		if ( ! $post_id || 'booking_request' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You cannot send this confirmation.', 'geolander' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'glc_send_booking_confirmation_' . $post_id );
		$status = self::send_confirmation( $post_id ) ? 'sent' : 'failed';
		wp_safe_redirect( add_query_arg( 'glc_booking_mail', $status, get_edit_post_link( $post_id, 'url' ) ) );
		exit;
	}

	public static function admin_notice(): void {
		$status = sanitize_key( $_GET['glc_booking_mail'] ?? '' );
		if ( ! in_array( $status, [ 'sent', 'failed' ], true ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			'sent' === $status ? 'success' : 'error',
			esc_html( 'sent' === $status ? __( 'Booking confirmation sent.', 'geolander' ) : __( 'Confirmation could not be sent. Check the SMTP Railway variables or use Open email draft.', 'geolander' ) )
		);
	}

	public static function columns( array $columns ): array {
		$columns['glc_customer'] = __( 'Customer', 'geolander' );
		$columns['glc_total']    = __( 'Total', 'geolander' );
		$columns['glc_status']   = __( 'Status', 'geolander' );
		return $columns;
	}

	public static function column_value( string $column, int $post_id ): void {
		if ( 'glc_customer' === $column ) {
			echo esc_html( get_post_meta( $post_id, 'glc_name', true ) . ' · ' . get_post_meta( $post_id, 'glc_email', true ) );
		} elseif ( 'glc_total' === $column ) {
			$total = (float) get_post_meta( $post_id, 'glc_total', true );
			echo $total > 0 ? esc_html( self::money( $total ) ) : '—';
		} elseif ( 'glc_status' === $column ) {
			echo esc_html( get_post_meta( $post_id, 'glc_booking_status', true ) ?: 'requested' );
		}
	}
}
