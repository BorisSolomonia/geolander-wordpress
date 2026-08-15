<?php
/**
 * Public contact-form delivery.
 *
 * The form intentionally has no database persistence or container-email
 * dependency. It composes a structured message and hands the visitor to the
 * project's verified WhatsApp gateway.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Contact {

	private const ACTION = 'glc_contact';

	public static function init(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, [ __CLASS__, 'handle' ] );
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle' ] );
	}

	public static function handle(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			self::redirect( 'error' );
		}

		// Bots fill this visually hidden field; return success without sending.
		if ( '' !== trim( sanitize_text_field( wp_unslash( $_POST['website'] ?? '' ) ) ) ) {
			self::redirect( 'sent' );
		}

		$name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$email   = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone   = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );

		if ( '' === $name || ! is_email( $email ) || '' === $message ) {
			self::redirect( 'error' );
		}

		// HTTP redirects remove encoded CR/LF sequences for header-injection
		// safety, so use a visible separator that survives the Location header.
		$message_text = implode( ' | ', [
			'Website contact request',
			'Name: ' . $name,
			'Email: ' . $email,
			'Phone: ' . ( $phone ?: 'Not provided' ),
			'Message: ' . $message,
		] );
		$whatsapp = GLC_Gateway_WhatsApp::url( $message_text );
		if ( ! $whatsapp ) {
			self::redirect( 'error' );
		}

		// The verified builder owns this URL.
		wp_redirect( $whatsapp, 303, 'Geolander' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	private static function redirect( string $status ): void {
		$referer = wp_get_referer();
		$target  = wp_validate_redirect( $referer ?: '', home_url( '/contact/' ) );
		$target  = remove_query_arg( 'contact', $target );
		wp_safe_redirect( add_query_arg( 'contact', $status, $target ), 303 );
		exit;
	}
}
