<?php
/** Trip-first planning: public advice and delivery costs from the booking settings. */
defined( 'ABSPATH' ) || exit;

class GLC_Trip_Planner {

	public const SLUG = 'georgia-road-trip-planner';
	// Editorial review date, deliberately not the request date or a road-status claim.
	public const REVIEWED = '2026-09-21';
	private const AIRPORTS = [
		'tbilisi_airport' => 'TBS',
		'kutaisi_airport' => 'KUT',
		'batumi_airport'  => 'BUS',
	];
	private const ROUTES = [
		'city'    => 'travel-info',
		'night'   => 'georgia-airport-rental-costs',
		'kazbegi' => 'driving-to-kazbegi-in-winter',
		'svaneti' => 'svaneti-4x4-road-trip-guide',
		'tusheti' => 'tusheti-4x4-rental-guide',
	];
	private const SOURCES = [
		'tbilisi' => 'https://www.tbilisiairport.com/en-EN/bus/page/bus',
		'kutaisi' => 'https://kutaisi.aero/en/transport/bus-transfer',
		'passes'  => 'https://georgia.travel/the-most-beautiful-road-passes-of-georgia',
		'roads'   => 'https://www.georoad.ge/?lang=eng',
	];

	public static function init(): void {
		add_action( 'init', static function () {
			register_block_type( 'geolander/trip-planner', [ 'render_callback' => [ __CLASS__, 'render' ] ] );
		} );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	public static function assets(): void {
		if ( ! is_page( self::SLUG ) && ! is_front_page() ) { return; }
		$version = wp_get_theme()->get( 'Version' );
		wp_enqueue_style( 'glc-trip-planner', get_theme_file_uri( 'assets/css/trip-planner.css' ), [ 'geolander-main' ], $version );
		if ( is_page( self::SLUG ) ) {
			wp_enqueue_script( 'glc-trip-planner', get_theme_file_uri( 'assets/js/trip-planner.js' ), [], $version, [ 'strategy' => 'defer', 'in_footer' => true ] );
		}
	}

	/** Don't turn an unpublished guide or a missing migration into a broken link. */
	public static function published_url( string $slug ): string {
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		return $page && 'publish' === $page->post_status ? get_permalink( $page ) : '';
	}

	/** Missing paid-location fees are unknown, never a promise of free delivery. */
	public static function delivery_total( string $pickup, string $return ): ?float {
		$total = 0.0;
		foreach ( [ $pickup, $return ] as $location ) {
			if ( ! isset( self::AIRPORTS[ $location ] ) ) { return null; }
			$fee = GLC_Rental::location_fee( $location );
			if ( ! is_finite( $fee ) || ( 'tbilisi_airport' !== $location && $fee <= 0 ) ) { return null; }
			$total += $fee;
		}
		return round( $total, 2 );
	}

	private static function price( ?float $total ): string {
		if ( null === $total ) { return glc_ui( 'planner_confirm_fee' ); }
		return 0.0 === $total ? glc_ui( 'arrival_included' ) : GLC_Format::money( $total );
	}

	public static function teaser(): string {
		$url = self::published_url( self::SLUG );
		if ( ! $url ) { return ''; }
		return '<section class="glc-trip-teaser" aria-labelledby="glc-trip-teaser-title"><div><p class="glc-kicker">'
			. esc_html( glc_ui( 'planner_kicker' ) ) . '</p><h2 id="glc-trip-teaser-title">' . esc_html( glc_ui( 'planner_home_title' ) )
			. '</h2><p>' . esc_html( glc_ui( 'planner_home_intro' ) ) . '</p></div><a class="wp-element-button glc-btn" href="'
			. esc_url( $url ) . '">' . esc_html( glc_ui( 'planner_cta' ) ) . ' <span aria-hidden="true">↗</span></a></section>';
	}

	public static function link(): string {
		$url = self::published_url( self::SLUG );
		return $url ? '<p class="glc-policy-note"><a href="' . esc_url( $url ) . '">' . esc_html( glc_ui( 'planner_title' ) ) . '</a></p>' : '';
	}

	public static function render(): string {
		ob_start();
		?>
		<div class="glc-trip-planner" data-trip-planner>
			<p class="glc-trip-lead"><?php echo esc_html( glc_ui( 'planner_intro' ) ); ?></p>
			<p class="glc-trip-review"><?php echo esc_html( glc_ui( 'planner_reviewed' ) ); ?> <time datetime="<?php echo esc_attr( self::REVIEWED ); ?>"><?php echo esc_html( self::REVIEWED ); ?></time> · Geolander</p>
			<form class="glc-trip-form" hidden data-trip-form>
				<h2><?php echo esc_html( glc_ui( 'planner_form_title' ) ); ?></h2>
				<div class="glc-trip-fields">
					<label for="trip-route"><?php echo esc_html( glc_ui( 'planner_route' ) ); ?><select id="trip-route" name="route">
						<?php foreach ( self::ROUTES as $route => $slug ) : ?>
							<option value="<?php echo esc_attr( $route ); ?>"><?php echo esc_html( glc_ui( 'planner_' . $route . '_label' ) ); ?></option>
						<?php endforeach; ?>
					</select></label>
					<?php foreach ( [ 'pickup', 'return' ] as $direction ) : ?>
						<label for="trip-<?php echo esc_attr( $direction ); ?>"><?php echo esc_html( glc_ui( 'planner_' . $direction ) ); ?><select id="trip-<?php echo esc_attr( $direction ); ?>" name="<?php echo esc_attr( $direction ); ?>">
							<?php foreach ( self::AIRPORTS as $location => $code ) : ?>
								<option value="<?php echo esc_attr( $location ); ?>"><?php echo esc_html( $code . ' · ' . GLC_Rental::location_label( $location ) ); ?></option>
							<?php endforeach; ?>
						</select></label>
					<?php endforeach; ?>
				</div>
				<button class="wp-element-button glc-btn" type="submit"><?php echo esc_html( glc_ui( 'planner_build' ) ); ?></button>
			</form>
			<section class="glc-trip-result" data-trip-result hidden tabindex="-1" aria-labelledby="trip-result-title">
				<h2 id="trip-result-title"><?php echo esc_html( glc_ui( 'planner_brief' ) ); ?></h2>
				<p data-trip-pair></p><p><span><?php echo esc_html( glc_ui( 'planner_delivery_only' ) ); ?></span> <strong data-trip-total></strong></p>
				<p><?php echo esc_html( glc_ui( 'planner_cost_note' ) ); ?></p>
				<p><a data-trip-route-link href="#trip-city"></a></p>
				<button class="glc-trip-copy" data-trip-copy type="button"><?php echo esc_html( glc_ui( 'planner_copy' ) ); ?></button>
				<label data-trip-copy-fallback hidden><?php echo esc_html( glc_ui( 'planner_copy_manual' ) ); ?><textarea rows="8" readonly></textarea></label>
				<p role="status" data-trip-feedback data-copied="<?php echo esc_attr( glc_ui( 'planner_copied' ) ); ?>"></p>
			</section>
			<nav class="glc-trip-nav" aria-label="<?php echo esc_attr( glc_ui( 'planner_route' ) ); ?>">
				<?php foreach ( self::ROUTES as $route => $slug ) : ?>
					<a href="#trip-<?php echo esc_attr( $route ); ?>"><?php echo esc_html( glc_ui( 'planner_' . $route . '_label' ) ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="glc-trip-decisions">
				<?php foreach ( self::ROUTES as $route => $slug ) : ?>
					<section class="glc-trip-decision" id="trip-<?php echo esc_attr( $route ); ?>">
						<p class="glc-kicker"><?php echo esc_html( glc_ui( 'planner_' . $route . '_label' ) ); ?></p>
						<h2><?php echo esc_html( glc_ui( 'planner_' . $route . '_title' ) ); ?></h2>
						<p data-trip-advice><?php echo esc_html( glc_ui( 'planner_' . $route . '_body' ) ); ?></p>
						<?php if ( $url = self::published_url( $slug ) ) : ?>
							<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( glc_ui( 'planner_' . $route . '_link' ) ); ?> →</a>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>
			</div>
			<section aria-labelledby="trip-airport-title">
				<h2 id="trip-airport-title"><?php echo esc_html( glc_ui( 'planner_airport_title' ) ); ?></h2>
				<p><?php echo esc_html( glc_ui( 'planner_airport_intro' ) ); ?></p>
				<div class="glc-trip-table"><table>
					<caption><?php echo esc_html( glc_ui( 'planner_delivery_only' ) ); ?></caption>
					<thead><tr><th scope="col"><?php echo esc_html( glc_ui( 'planner_pickup_return' ) ); ?></th>
						<?php foreach ( self::AIRPORTS as $location => $code ) : ?><th scope="col"><abbr title="<?php echo esc_attr( GLC_Rental::location_label( $location ) ); ?>"><?php echo esc_html( $code ); ?></abbr></th><?php endforeach; ?>
					</tr></thead><tbody>
					<?php foreach ( self::AIRPORTS as $pickup => $code ) : ?>
						<tr><th scope="row"><abbr title="<?php echo esc_attr( GLC_Rental::location_label( $pickup ) ); ?>"><?php echo esc_html( $code ); ?></abbr></th>
							<?php foreach ( self::AIRPORTS as $return => $return_code ) : ?>
								<td data-trip-fee="<?php echo esc_attr( $pickup . ':' . $return ); ?>"><?php echo esc_html( self::price( self::delivery_total( $pickup, $return ) ) ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
					</tbody></table></div>
				<p class="glc-trip-review"><?php foreach ( self::AIRPORTS as $location => $code ) { echo '<span>' . esc_html( $code . ' = ' . GLC_Rental::location_label( $location ) ) . '</span> '; } ?></p>
				<p><?php echo esc_html( glc_ui( 'planner_cost_note' ) ); ?></p>
				<?php echo GLC_Trip_Tools::link(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</section>
			<section class="glc-trip-checklist" aria-labelledby="trip-checklist-title">
				<h2 id="trip-checklist-title"><?php echo esc_html( glc_ui( 'planner_checklist_title' ) ); ?></h2>
				<ul data-trip-checklist><?php foreach ( [ 'dates', 'bags', 'roads', 'terms' ] as $item ) { echo '<li>' . esc_html( glc_ui( 'planner_check_' . $item ) ) . '</li>'; } ?></ul>
				<p><a class="wp-element-button glc-btn" href="<?php echo esc_url( home_url( '/fleet/' ) ); ?>"><?php echo esc_html( glc_ui( 'arrival_quote' ) ); ?></a></p>
				<p><a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><?php echo esc_html( glc_ui( 'planner_contact' ) ); ?></a> · <a href="<?php echo esc_url( home_url( '/terms/' ) ); ?>"><?php echo esc_html( glc_ui( 'nav_terms' ) ); ?></a></p>
			</section>
			<section class="glc-trip-sources" aria-labelledby="trip-sources-title">
				<h2 id="trip-sources-title"><?php echo esc_html( glc_ui( 'planner_sources_title' ) ); ?></h2>
				<p><?php echo esc_html( glc_ui( 'planner_sources_note' ) ); ?></p>
				<ul><?php foreach ( self::SOURCES as $key => $url ) { echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( glc_ui( 'planner_source_' . $key ) ) . '</a></li>'; } ?></ul>
			</section>
		</div>
		<?php
		return ob_get_clean();
	}

	/** Same advice and costs as HTML; no empty dynamic-block Markdown response. */
	public static function markdown(): string {
		$out = '# ' . glc_ui( 'planner_title' ) . "\n\n" . glc_ui( 'planner_intro' ) . "\n\n";
		$out .= glc_ui( 'planner_reviewed' ) . ' ' . self::REVIEWED . " · Geolander\n\n";
		foreach ( self::ROUTES as $route => $slug ) {
			$out .= '## ' . glc_ui( 'planner_' . $route . '_title' ) . "\n\n" . glc_ui( 'planner_' . $route . '_body' ) . "\n\n";
			if ( $url = self::published_url( $slug ) ) { $out .= '[' . glc_ui( 'planner_' . $route . '_link' ) . '](' . $url . ")\n\n"; }
		}
		$out .= '## ' . glc_ui( 'planner_airport_title' ) . "\n\n" . glc_ui( 'planner_airport_intro' ) . "\n\n" . glc_ui( 'planner_delivery_only' ) . "\n\n";
		foreach ( self::AIRPORTS as $pickup => $code ) {
			foreach ( self::AIRPORTS as $return => $return_code ) {
				$out .= '- ' . GLC_Rental::location_label( $pickup ) . ' → ' . GLC_Rental::location_label( $return ) . ': ' . self::price( self::delivery_total( $pickup, $return ) ) . "\n";
			}
		}
		$out .= "\n" . glc_ui( 'planner_cost_note' ) . "\n\n## " . glc_ui( 'planner_checklist_title' ) . "\n\n";
		foreach ( [ 'dates', 'bags', 'roads', 'terms' ] as $item ) { $out .= '- ' . glc_ui( 'planner_check_' . $item ) . "\n"; }
		$out .= "\n## " . glc_ui( 'planner_sources_title' ) . "\n\n" . glc_ui( 'planner_sources_note' ) . "\n\n";
		foreach ( self::SOURCES as $key => $url ) { $out .= '- [' . glc_ui( 'planner_source_' . $key ) . '](' . $url . ")\n"; }
		$out .= "\n[" . glc_ui( 'arrival_quote' ) . '](' . home_url( '/fleet/' ) . ")\n";
		$out .= '[' . glc_ui( 'planner_contact' ) . '](' . home_url( '/contact/' ) . ")\n";
		$out .= '[' . glc_ui( 'nav_terms' ) . '](' . home_url( '/terms/' ) . ")\n";
		return $out . "\nCanonical URL: " . get_permalink() . "\n";
	}

	public static function schema(): array {
		$url = get_permalink();
		$parts = [];
		foreach ( self::ROUTES as $route => $slug ) {
			$parts[] = [ '@type' => 'WebPageElement', '@id' => $url . '#trip-' . $route, 'name' => glc_ui( 'planner_' . $route . '_title' ), 'text' => glc_ui( 'planner_' . $route . '_body' ) ];
		}
		return [
			'@type' => 'CollectionPage', '@id' => $url . '#roadbook', 'url' => $url,
			'name' => glc_ui( 'planner_title' ), 'description' => glc_ui( 'planner_intro' ),
			'inLanguage' => GLC_I18n::locale(), 'lastReviewed' => self::REVIEWED,
			'hasPart' => $parts, 'citation' => array_values( self::SOURCES ),
		];
	}
}
