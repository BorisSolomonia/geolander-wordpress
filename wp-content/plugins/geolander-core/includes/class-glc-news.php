<?php
/**
 * Event-driven news section: the content type, its admin UI, its blocks,
 * its schema and its sitemap entry.
 *
 * WHY A CUSTOM TYPE AND NOT NATIVE POSTS. The site's permalink structure is
 * `/%postname%/`, so a native post lands at the site root and competes for slugs
 * with the city landing pages (`/car-rental-tbilisi/`) and the guides. A custom
 * type owns its own `blog/` prefix regardless of that setting, which is both
 * safer and the only way to keep articles in the one directory Google already
 * has history for: `/blog/geolander-vs-local-rent-vs-premium-auto-rent/` still
 * draws impressions months after being retired (GSC, 2026-09-15).
 *
 * WHY `blog` AND NOT A NEW NAMESPACE. Reusing the directory keeps that history.
 * The empty placeholder page that used to sit on the slug is moved aside by
 * `_migration/setup-news.php` — the URL itself is never lost, it just starts
 * serving the real archive.
 *
 * THE OFFER FIELD IS DELIBERATELY TRISTATE. `none` · `pending` · `active`.
 * An article may be finished and publishable while its commercial offer is still
 * unconfirmed; `pending` renders NOTHING on the front end and shows the editor a
 * notice. That is the mechanism which makes "never invent a discount" structural
 * rather than a matter of remembering. Prime directive 0.1.
 */

defined( 'ABSPATH' ) || exit;

class GLC_News {

	public const POST_TYPE = 'glc_news';
	/** The URL directory. One constant so the rewrite, the archive and the breadcrumb cannot drift. */
	public const SLUG = 'blog';

	/** Offer states. Only ACTIVE reaches the public page. */
	public const OFFER_NONE    = 'none';
	public const OFFER_PENDING = 'pending';
	public const OFFER_ACTIVE  = 'active';

	/**
	 * Every field the content model carries, with its sanitiser. Registered for
	 * REST so a future drafting agent can file a draft through the API under its
	 * own authenticated user without any new endpoint, and without any capability
	 * to publish — the draft→publish step stays a human action in wp-admin.
	 */
	public static function fields(): array {
		return [
			// The event itself.
			'glc_news_event_name'    => 'text',
			'glc_news_event_start'   => 'date',
			'glc_news_event_end'     => 'date',
			'glc_news_venue'         => 'text',
			'glc_news_venue_address' => 'text',
			'glc_news_city'          => 'text',
			'glc_news_event_url'     => 'url',
			// Provenance. Without these a future agent's draft cannot show its work.
			'glc_news_sources'       => 'lines',
			'glc_news_verified_on'   => 'date',
			// Answer-engine surface.
			'glc_news_answer'        => 'textarea',
			'glc_news_faq'           => 'lines',
			// The commercial offer.
			'glc_news_offer_status'  => 'text',
			'glc_news_offer_headline'=> 'text',
			'glc_news_offer_terms'   => 'textarea',
			'glc_news_offer_from'    => 'date',
			'glc_news_offer_to'      => 'date',
			// Internal linking.
			'glc_news_related_cars'  => 'text',
			'glc_news_related_pages' => 'text',
		];
	}

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'init', [ __CLASS__, 'register_blocks' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_boxes' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ __CLASS__, 'save' ] );
		add_action( 'admin_notices', [ __CLASS__, 'pending_offer_notice' ] );
		add_action( 'wp_head', [ __CLASS__, 'schema' ], 6 );
		add_filter( 'wp_robots', [ __CLASS__, 'robots' ] );
	}

	public static function register(): void {
		register_post_type( self::POST_TYPE, [
			'labels' => [
				'name'          => __( 'News & Events', 'geolander' ),
				'singular_name' => __( 'Article', 'geolander' ),
				'add_new_item'  => __( 'Add article', 'geolander' ),
				'edit_item'     => __( 'Edit article', 'geolander' ),
				'menu_name'     => __( 'News & Events', 'geolander' ),
			],
			'public'        => true,
			'show_in_rest'  => true,
			'menu_icon'     => 'dashicons-megaphone',
			'menu_position' => 21,
			'has_archive'   => self::SLUG,
			'rewrite'       => [ 'slug' => self::SLUG, 'with_front' => false ],
			'supports'      => [ 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields', 'author' ],
			// Revisions are on so an agent-filed draft can be diffed against the
			// human edit that followed it.
			'capability_type' => 'post',
		] );

		foreach ( self::fields() as $key => $type ) {
			register_post_meta( self::POST_TYPE, $key, [
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => fn( $v ) => self::sanitize( $type, $v ),
				'auth_callback'     => fn() => current_user_can( 'edit_posts' ),
			] );
		}
	}

	private static function sanitize( string $type, $value ) {
		$value = is_string( $value ) ? wp_unslash( $value ) : '';
		switch ( $type ) {
			case 'url':
				return esc_url_raw( trim( $value ) );
			case 'date':
				// Store ISO-8601 or nothing. A half-parsed date is worse than none.
				return preg_match( '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', trim( $value ) ) ? trim( $value ) : '';
			case 'textarea':
			case 'lines':
				return sanitize_textarea_field( $value );
			default:
				return sanitize_text_field( $value );
		}
	}

	/* ------------------------------------------------------------ accessors */

	public static function get( int $post_id, string $key ): string {
		return (string) get_post_meta( $post_id, $key, true );
	}

	/**
	 * `Label | https://url` per line. Kept as text rather than a repeater because
	 * an editor pasting three source URLs should not have to learn a widget.
	 */
	public static function sources( int $post_id ): array {
		$out = [];
		foreach ( preg_split( '/\R/', self::get( $post_id, 'glc_news_sources' ) ) ?: [] as $line ) {
			$line = trim( $line );
			if ( '' === $line ) { continue; }
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			$url   = count( $parts ) === 2 ? $parts[1] : $parts[0];
			$label = count( $parts ) === 2 ? $parts[0] : $parts[0];
			if ( ! preg_match( '#^https?://#i', $url ) ) { continue; }
			$out[] = [ 'label' => $label, 'url' => $url ];
		}
		return $out;
	}

	/** `Question | Answer` per line. */
	public static function faq( int $post_id ): array {
		$out = [];
		foreach ( preg_split( '/\R/', self::get( $post_id, 'glc_news_faq' ) ) ?: [] as $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, '|' ) ) { continue; }
			[ $q, $a ] = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( '' !== $q && '' !== $a ) { $out[] = [ 'q' => $q, 'a' => $a ]; }
		}
		return $out;
	}

	/**
	 * The one gate that decides whether any promotional text renders. An offer
	 * shows only when it is explicitly ACTIVE *and* carries a headline. Every
	 * other state renders nothing at all — no placeholder, no "coming soon",
	 * because an empty promise is still a promise.
	 */
	public static function offer_is_live( int $post_id ): bool {
		if ( self::OFFER_ACTIVE !== self::get( $post_id, 'glc_news_offer_status' ) ) { return false; }
		if ( '' === trim( self::get( $post_id, 'glc_news_offer_headline' ) ) ) { return false; }
		$to = self::get( $post_id, 'glc_news_offer_to' );
		// An expired offer is a false claim, so it stops rendering on its own date.
		return '' === $to || $to >= current_time( 'Y-m-d' );
	}

	/* --------------------------------------------------------------- admin */

	public static function add_meta_boxes(): void {
		add_meta_box( 'glc_news_event', __( 'Event facts (verified)', 'geolander' ), [ __CLASS__, 'render_event' ], self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'glc_news_answer_box', __( 'Answer summary & FAQ', 'geolander' ), [ __CLASS__, 'render_answer' ], self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'glc_news_offer', __( 'Special offer', 'geolander' ), [ __CLASS__, 'render_offer' ], self::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'glc_news_links', __( 'Internal links', 'geolander' ), [ __CLASS__, 'render_links' ], self::POST_TYPE, 'side', 'default' );
	}

	private static function field( int $id, string $key, string $label, string $type = 'text', string $help = '' ): void {
		$value = self::get( $id, $key );
		if ( 'textarea' === $type ) {
			printf(
				'<p><label for="%1$s"><strong>%2$s</strong></label><br /><textarea id="%1$s" name="%1$s" rows="4" class="widefat">%3$s</textarea>%4$s</p>',
				esc_attr( $key ), esc_html( $label ), esc_textarea( $value ),
				$help ? '<span class="description">' . esc_html( $help ) . '</span>' : ''
			);
			return;
		}
		printf(
			'<p><label for="%1$s"><strong>%2$s</strong></label><br /><input type="%3$s" id="%1$s" name="%1$s" value="%4$s" class="widefat" />%5$s</p>',
			esc_attr( $key ), esc_html( $label ), esc_attr( $type ), esc_attr( $value ),
			$help ? '<span class="description">' . esc_html( $help ) . '</span>' : ''
		);
	}

	public static function render_event( WP_Post $post ): void {
		wp_nonce_field( 'glc_news_meta', 'glc_news_nonce' );
		echo '<p class="description">' . esc_html__( 'Fill these from the organiser, venue or official ticket page — never from a listings aggregator. Leave a field empty rather than guessing; empty fields simply do not render.', 'geolander' ) . '</p>';
		self::field( $post->ID, 'glc_news_event_name', __( 'Event name, as the organiser writes it', 'geolander' ), 'text',
			__( 'Not the article headline: "TAEMIN 2026-27 World Tour: LiMiNaL", not "what to know about the concert". Angle brackets are stripped as markup, so write a tour styled <LiMiNaL> as plain text.', 'geolander' ) );
		self::field( $post->ID, 'glc_news_event_start', __( 'Event start (YYYY-MM-DD)', 'geolander' ) );
		self::field( $post->ID, 'glc_news_event_end', __( 'Event end (YYYY-MM-DD, only if multi-day)', 'geolander' ) );
		self::field( $post->ID, 'glc_news_venue', __( 'Venue name', 'geolander' ) );
		self::field( $post->ID, 'glc_news_venue_address', __( 'Venue address', 'geolander' ) );
		self::field( $post->ID, 'glc_news_city', __( 'City', 'geolander' ) );
		self::field( $post->ID, 'glc_news_event_url', __( 'Official event or ticket URL', 'geolander' ), 'url' );
		self::field( $post->ID, 'glc_news_verified_on', __( 'Facts verified on (YYYY-MM-DD)', 'geolander' ) );
		self::field( $post->ID, 'glc_news_sources', __( 'Sources — one per line, "Label | https://url"', 'geolander' ), 'textarea',
			__( 'Shown to readers under the article. This is what lets a future drafting agent prove where a fact came from.', 'geolander' ) );
	}

	public static function render_answer( WP_Post $post ): void {
		self::field( $post->ID, 'glc_news_answer', __( 'Answer summary', 'geolander' ), 'textarea',
			__( 'Two or three sentences stating the what, when and where plainly. This is the block answer engines quote, and the first thing a reader sees.', 'geolander' ) );
		self::field( $post->ID, 'glc_news_faq', __( 'Questions — one per line, "Question | Answer"', 'geolander' ), 'textarea',
			__( 'Rendered as readable Q&A. No FAQ schema is emitted: Google retired FAQ rich results for sites like this one, and the manual rules it out.', 'geolander' ) );
	}

	public static function render_offer( WP_Post $post ): void {
		$status = self::get( $post->ID, 'glc_news_offer_status' ) ?: self::OFFER_NONE;
		$states = [
			self::OFFER_NONE    => __( 'No offer — article is editorial only', 'geolander' ),
			self::OFFER_PENDING => __( 'Pending — terms not confirmed yet, renders NOTHING publicly', 'geolander' ),
			self::OFFER_ACTIVE  => __( 'Active — confirmed terms, renders on the page', 'geolander' ),
		];
		echo '<p><label for="glc_news_offer_status"><strong>' . esc_html__( 'Offer status', 'geolander' ) . '</strong></label><br /><select id="glc_news_offer_status" name="glc_news_offer_status" class="widefat">';
		foreach ( $states as $value => $text ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $status, $value, false ), esc_html( $text ) );
		}
		echo '</select></p>';
		self::field( $post->ID, 'glc_news_offer_headline', __( 'Offer headline', 'geolander' ) );
		self::field( $post->ID, 'glc_news_offer_terms', __( 'Offer terms', 'geolander' ), 'textarea',
			__( 'State the real conditions: minimum days, which cars, what is included. Never a percentage you have not agreed.', 'geolander' ) );
		self::field( $post->ID, 'glc_news_offer_from', __( 'Valid from (YYYY-MM-DD)', 'geolander' ) );
		self::field( $post->ID, 'glc_news_offer_to', __( 'Valid to (YYYY-MM-DD)', 'geolander' ) );
		echo '<p class="description">' . esc_html__( 'An offer past its "valid to" date stops rendering automatically.', 'geolander' ) . '</p>';
	}

	public static function render_links( WP_Post $post ): void {
		self::field( $post->ID, 'glc_news_related_cars', __( 'Related car IDs or slugs, comma separated', 'geolander' ) );
		self::field( $post->ID, 'glc_news_related_pages', __( 'Related page paths, comma separated', 'geolander' ), 'text',
			__( 'For example: /car-rental-tbilisi/, /fleet/', 'geolander' ) );
	}

	public static function save( int $post_id ): void {
		if ( ! isset( $_POST['glc_news_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['glc_news_nonce'] ), 'glc_news_meta' ) ) { return; }
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
		foreach ( self::fields() as $key => $type ) {
			if ( ! isset( $_POST[ $key ] ) ) { continue; }
			update_post_meta( $post_id, $key, self::sanitize( $type, $_POST[ $key ] ) );
		}
	}

	/** The editor is told, every time, that a pending offer is invisible. */
	public static function pending_offer_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || self::POST_TYPE !== $screen->post_type || 'post' !== $screen->base ) { return; }
		$id = (int) ( $_GET['post'] ?? 0 );
		if ( $id && self::OFFER_PENDING === self::get( $id, 'glc_news_offer_status' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__(
				'This article has an offer marked PENDING. Nothing promotional is being shown to readers. Set it to Active once the terms are confirmed.',
				'geolander'
			) . '</p></div>';
		}
	}

	/**
	 * An empty archive is a thin page and should not be indexed; an archive with
	 * articles should. That was previously a hand-maintained constant carrying the
	 * note "remove when the first article ships" — which is a reminder, not a
	 * mechanism, and reminders rot. The count decides instead, so the page becomes
	 * indexable the moment a real article is published and reverts on its own if
	 * every article is ever unpublished.
	 */
	public static function published_count(): int {
		$counts = wp_count_posts( self::POST_TYPE );
		return (int) ( $counts->publish ?? 0 );
	}

	public static function robots( array $robots ): array {
		if ( is_post_type_archive( self::POST_TYPE ) && 0 === self::published_count() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'] );
		}
		return $robots;
	}

	/* -------------------------------------------------------------- schema */

	/**
	 * BlogPosting, with the event attached through `about` rather than as the
	 * page's primary type.
	 *
	 * That distinction is the whole point. We are not the organiser, the venue or
	 * the ticket seller; the page is an article ABOUT someone else's event.
	 * Google's Event documentation requires a page to focus on a single event to
	 * be eligible, which this page does, but it says nothing that makes us the
	 * event's publisher — and putting a car-rental offer inside `Event.offers`,
	 * where the field means TICKET offers, would be a plain misstatement. So the
	 * event is described, the ticket URL is attributed to its real source, and our
	 * own offer is never marked up as an event offer.
	 */
	public static function schema(): void {
		if ( ! is_singular( self::POST_TYPE ) ) { return; }
		$id   = get_queried_object_id();
		$post = get_post( $id );
		if ( ! $post ) { return; }

		$graph = [];

		$article = [
			'@type'            => 'BlogPosting',
			'headline'         => wp_strip_all_tags( get_the_title( $post ) ),
			'mainEntityOfPage' => [ '@type' => 'WebPage', '@id' => get_permalink( $post ) ],
			'datePublished'    => get_the_date( 'c', $post ),
			'dateModified'     => get_the_modified_date( 'c', $post ),
			'inLanguage'       => class_exists( 'GLC_I18n' ) ? GLC_I18n::locale() : 'en',
			'publisher'        => [ '@type' => 'Organization', 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ],
		];
		$excerpt = trim( wp_strip_all_tags( self::get( $id, 'glc_news_answer' ) ?: (string) $post->post_excerpt ) );
		if ( '' !== $excerpt ) { $article['description'] = wp_html_excerpt( $excerpt, 250, '…' ); }
		if ( has_post_thumbnail( $post ) ) { $article['image'] = get_the_post_thumbnail_url( $post, 'full' ); }

		// Cite the sources on the article itself, not only in visible text.
		$citations = array_column( self::sources( $id ), 'url' );
		if ( $citations ) { $article['citation'] = $citations; }

		$event = self::event_node( $id );
		if ( $event ) { $article['about'] = $event; }
		$graph[] = $article;

		$graph[] = [
			'@type'           => 'BreadcrumbList',
			'itemListElement' => [
				[ '@type' => 'ListItem', 'position' => 1, 'name' => get_bloginfo( 'name' ), 'item' => home_url( '/' ) ],
				[ '@type' => 'ListItem', 'position' => 2, 'name' => __( 'News & Events', 'geolander' ), 'item' => get_post_type_archive_link( self::POST_TYPE ) ],
				[ '@type' => 'ListItem', 'position' => 3, 'name' => wp_strip_all_tags( get_the_title( $post ) ) ],
			],
		];

		printf(
			"<script type=\"application/ld+json\">%s</script>\n",
			wp_json_encode( [ '@context' => 'https://schema.org', '@graph' => $graph ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}

	/**
	 * An Event node built only from fields that are actually filled. A name and a
	 * start date are the minimum; without them there is no event to describe and
	 * we emit nothing rather than a hollow node.
	 */
	public static function event_node( int $id ): array {
		$start = self::get( $id, 'glc_news_event_start' );
		$venue = self::get( $id, 'glc_news_venue' );
		if ( '' === $start ) { return []; }

		/*
		 * The event's name is the ORGANISER's name for it, not our headline. An
		 * article titled "what to know, and whether you need a car" is a fine
		 * headline and a false event name, so the two are separate fields. The post
		 * title is only a fallback for an article whose editor left the field blank.
		 */
		$name  = trim( self::get( $id, 'glc_news_event_name' ) );
		$event = [
			'@type'     => 'Event',
			'name'      => '' !== $name ? $name : wp_strip_all_tags( get_the_title( $id ) ),
			'startDate' => $start,
		];
		$end = self::get( $id, 'glc_news_event_end' );
		if ( '' !== $end ) { $event['endDate'] = $end; }
		$event['eventAttendanceMode'] = 'https://schema.org/OfflineEventAttendanceMode';

		if ( '' !== $venue ) {
			$place = [ '@type' => 'Place', 'name' => $venue ];
			$addr  = array_filter( [
				'streetAddress'   => self::get( $id, 'glc_news_venue_address' ),
				'addressLocality' => self::get( $id, 'glc_news_city' ),
			] );
			if ( $addr ) { $place['address'] = [ '@type' => 'PostalAddress' ] + $addr + [ 'addressCountry' => 'GE' ]; }
			$event['location'] = $place;
		}
		// The ticket URL belongs to the organiser. We link it; we do not claim it.
		$url = self::get( $id, 'glc_news_event_url' );
		if ( '' !== $url ) { $event['url'] = $url; }
		return $event;
	}

	/* -------------------------------------------------------------- blocks */

	/**
	 * Two blocks, not twelve. An editor writing about a concert should type the
	 * article and fill the fields, not assemble a page out of parts — so the
	 * whole event apparatus renders from one block placed once in the template.
	 */
	public static function register_blocks(): void {
		register_block_type( 'geolander/news-article', [ 'render_callback' => [ __CLASS__, 'render_article' ] ] );
		register_block_type( 'geolander/news-list', [
			'render_callback' => [ __CLASS__, 'render_list' ],
			'attributes'      => [ 'count' => [ 'type' => 'integer', 'default' => 20 ] ],
		] );
	}

	/** Localised date, or the raw ISO string if the site has no format for it. */
	private static function human_date( string $iso ): string {
		if ( '' === $iso ) { return ''; }
		$ts = strtotime( $iso );
		return $ts ? date_i18n( (string) get_option( 'date_format' ), $ts ) : $iso;
	}

	public static function render_article(): string {
		if ( ! is_singular( self::POST_TYPE ) ) { return ''; }
		$id  = get_queried_object_id();
		$out = '';

		// 1 · The answer block. First thing a reader sees, first thing quoted.
		$answer = trim( self::get( $id, 'glc_news_answer' ) );
		if ( '' !== $answer ) {
			$out .= '<div class="glc-news-answer"><p>' . esc_html( $answer ) . '</p></div>';
		}

		// 2 · The verified facts, as a definition list so the entities are explicit.
		$facts = array_filter( [
			__( 'Date', 'geolander' )    => self::human_date( self::get( $id, 'glc_news_event_start' ) ),
			__( 'Venue', 'geolander' )   => self::get( $id, 'glc_news_venue' ),
			__( 'Address', 'geolander' ) => self::get( $id, 'glc_news_venue_address' ),
			__( 'City', 'geolander' )    => self::get( $id, 'glc_news_city' ),
		] );
		if ( $facts ) {
			$out .= '<dl class="glc-news-facts">';
			foreach ( $facts as $label => $value ) {
				$out .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
			}
			$url = self::get( $id, 'glc_news_event_url' );
			if ( '' !== $url ) {
				$out .= '<dt>' . esc_html__( 'Tickets', 'geolander' ) . '</dt><dd><a href="' . esc_url( $url )
					. '" rel="nofollow noopener" target="_blank">' . esc_html__( 'Official ticket page', 'geolander' ) . '</a></dd>';
			}
			$out .= '</dl>';
		}

		// 3 · The offer — only when genuinely live. See offer_is_live().
		if ( self::offer_is_live( $id ) ) {
			$out .= '<aside class="glc-news-offer"><h2>' . esc_html( self::get( $id, 'glc_news_offer_headline' ) ) . '</h2>';
			$terms = trim( self::get( $id, 'glc_news_offer_terms' ) );
			if ( '' !== $terms ) { $out .= '<p>' . nl2br( esc_html( $terms ) ) . '</p>'; }
			$from = self::human_date( self::get( $id, 'glc_news_offer_from' ) );
			$to   = self::human_date( self::get( $id, 'glc_news_offer_to' ) );
			if ( $from || $to ) {
				$out .= '<p class="glc-news-offer-dates">' . esc_html( trim( sprintf(
					/* translators: 1: start date, 2: end date */
					__( 'Valid %1$s to %2$s', 'geolander' ), $from, $to
				) ) ) . '</p>';
			}
			$out .= '</aside>';
		}

		// 4 · Q&A. Readable text, deliberately no FAQPage markup — see the manual.
		$faq = self::faq( $id );
		if ( $faq ) {
			$out .= '<section class="glc-news-faq"><h2>' . esc_html__( 'Questions', 'geolander' ) . '</h2>';
			foreach ( $faq as $row ) {
				$out .= '<h3>' . esc_html( $row['q'] ) . '</h3><p>' . esc_html( $row['a'] ) . '</p>';
			}
			$out .= '</section>';
		}

		// 5 · The booking CTA. The site's only call to action, unchanged.
		$wa = class_exists( 'GLC_Gateway_WhatsApp' )
			? GLC_Gateway_WhatsApp::url( sprintf( 'Hi, I read your article "%s" and would like a car.', wp_strip_all_tags( get_the_title( $id ) ) ) )
			: '';
		if ( $wa ) {
			$out .= '<p class="glc-news-cta"><a class="wp-block-button__link" href="' . esc_url( $wa )
				. '" rel="noopener" target="_blank">' . esc_html__( 'Ask us for a car on WhatsApp', 'geolander' ) . '</a></p>';
		}

		$out .= self::render_related( $id );
		$out .= self::render_sources( $id );
		return $out;
	}

	/** Internal links. Accepts car IDs or slugs, and plain site paths. */
	private static function render_related( int $id ): string {
		$links = [];
		foreach ( array_filter( array_map( 'trim', explode( ',', self::get( $id, 'glc_news_related_cars' ) ) ) ) as $ref ) {
			$car = ctype_digit( $ref ) ? get_post( (int) $ref ) : get_page_by_path( $ref, OBJECT, 'car' );
			if ( $car && 'publish' === $car->post_status ) {
				$links[ get_permalink( $car ) ] = get_the_title( $car );
			}
		}
		foreach ( array_filter( array_map( 'trim', explode( ',', self::get( $id, 'glc_news_related_pages' ) ) ) ) as $path ) {
			// Only ever an internal path: an editor cannot turn this into an outbound link farm.
			$path = '/' . ltrim( wp_parse_url( $path, PHP_URL_PATH ) ?? '', '/' );
			if ( '/' === $path ) { continue; }
			$links[ home_url( $path ) ] = trim( $path, '/' );
		}
		if ( ! $links ) { return ''; }
		$out = '<nav class="glc-news-related"><h2>' . esc_html__( 'Useful next', 'geolander' ) . '</h2><ul>';
		foreach ( $links as $url => $label ) {
			$out .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
		}
		return $out . '</ul></nav>';
	}

	/** Provenance, shown to the reader. An unsourced claim should look unsourced. */
	private static function render_sources( int $id ): string {
		$sources = self::sources( $id );
		if ( ! $sources ) { return ''; }
		$out = '<section class="glc-news-sources"><h2>' . esc_html__( 'Sources', 'geolander' ) . '</h2><ul>';
		foreach ( $sources as $s ) {
			$out .= '<li><a href="' . esc_url( $s['url'] ) . '" rel="nofollow noopener" target="_blank">' . esc_html( $s['label'] ) . '</a></li>';
		}
		$out .= '</ul>';
		$verified = self::human_date( self::get( $id, 'glc_news_verified_on' ) );
		if ( '' !== $verified ) {
			/* translators: %s: date the facts were checked */
			$out .= '<p class="glc-news-verified">' . esc_html( sprintf( __( 'Event details checked on %s.', 'geolander' ), $verified ) ) . '</p>';
		}
		return $out . '</section>';
	}

	/** The archive list. Drafts and scheduled posts never appear: this is a publish-only query. */
	public static function render_list( array $attributes = [] ): string {
		$q = new WP_Query( [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => (int) ( $attributes['count'] ?? 20 ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		] );
		if ( ! $q->have_posts() ) {
			return '<p class="glc-news-empty">' . esc_html__( 'No articles yet.', 'geolander' ) . '</p>';
		}
		$out = '<ul class="glc-news-list">';
		foreach ( $q->posts as $post ) {
			$when = self::human_date( self::get( $post->ID, 'glc_news_event_start' ) );
			$out .= '<li class="glc-news-item"><a href="' . esc_url( get_permalink( $post ) ) . '"><h2>' . esc_html( get_the_title( $post ) ) . '</h2></a>';
			if ( '' !== $when ) {
				$out .= '<p class="glc-news-item-when">' . esc_html( $when );
				$city = self::get( $post->ID, 'glc_news_city' );
				if ( '' !== $city ) { $out .= ' · ' . esc_html( $city ); }
				$out .= '</p>';
			}
			$summary = trim( self::get( $post->ID, 'glc_news_answer' ) ?: (string) $post->post_excerpt );
			if ( '' !== $summary ) { $out .= '<p>' . esc_html( wp_html_excerpt( $summary, 200, '…' ) ) . '</p>'; }
			$out .= '</li>';
		}
		return $out . '</ul>';
	}
}
