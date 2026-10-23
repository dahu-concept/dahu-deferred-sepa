<?php
/**
 * The welcome screen and the one-time tour, shared by the whole family.
 *
 * WHY THIS FILE IS A COPY. Same reasoning as `assets/css/dahu-ui.css`: the
 * plugins are independent, so there is nowhere to put a shared library, and
 * the only way for fourteen of them to greet a shop the same way is for each
 * to carry the same bytes. `_harness/test-ui.php` compares them.
 *
 * WHY ONE CLASS NAME AND NOT A PREFIXED ONE. A shop with six of these
 * installed loads this file six times. PHP cannot declare a class twice, so
 * the first copy wins and the others reuse it — which is exactly what should
 * happen, since they are the same file. The guard below is what makes that
 * safe. The known hazard is version skew: a shop running an old plugin beside
 * a new one gets whichever loaded first. That is acceptable here and nowhere
 * else in this family — this file greets people, it does not move money, and
 * the worst outcome is a welcome screen a version behind.
 *
 * WHAT IT IS FOR. Somebody has just installed a plugin they have not used
 * before. They do not want documentation; they want to know what it does and
 * what to do first. So: one screen, shown once, that says what it is and
 * offers the two or three first steps — and a tour that points at the real
 * controls on the real screen rather than describing them.
 *
 * Single source of truth: /Dev/dahu-public/UI-ADMIN-DAHU.md
 *
 * @package DahuFamily
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Dahu_Welcome' ) ) {

	/**
	 * Greets a shop that has just installed one of the family.
	 */
	class Dahu_Welcome {

		/** Every registered plugin, by slug. */
		protected static $plugins = array();

		/** Whether the hooks are on. Registering twice must not hook twice. */
		protected static $booted = false;

		/**
		 * Declares a plugin to the welcome machinery.
		 *
		 * @param array $spec {
		 *     @type string $slug     Plugin slug, e.g. 'dahu-role-backup'.
		 *     @type string $name     Shown as the title.
		 *     @type string $tagline  ONE line. What it is for.
		 *     @type string $file     Main plugin file, for plugins_url().
		 *     @type string $version  Plugin version, for cache busting.
		 *     @type string $textdomain Text domain.
		 *     @type array  $points   Three at most: ['title' => , 'text' => ].
		 *     @type array  $actions  ['label' =>, 'url' =>, 'primary' => bool].
		 *     @type array  $tour     Steps: ['selector' =>, 'title' =>, 'text' =>].
		 *     @type string $tour_screen Page hook the tour plays on. Optional.
		 *     @type string $capability Who may see it. Default manage_options.
		 * }
		 * @return void
		 */
		public static function register( array $spec ) {

			if ( empty( $spec['slug'] ) ) {
				return;
			}

			$spec = wp_parse_args(
				$spec,
				array(
					'name'        => $spec['slug'],
					'tagline'     => '',
					'file'        => '',
					'version'     => '1.0.0',
					'textdomain'  => $spec['slug'],
					'points'      => array(),
					'actions'     => array(),
					'tour'        => array(),
					'tour_screen' => '',
					'capability'  => 'manage_options',

					/*
					 * The plugin's own mark, for the hero. Its shape is
					 * deliberately the narrowest one that can carry a drawing:
					 * a viewBox and a list of `d` strings, nothing else. This
					 * file is copied byte for byte into fourteen plugins, so
					 * the less it has to interpret, the less there is to go
					 * wrong in fourteen places at once -- and a renderer that
					 * only ever writes <path d="…"> through esc_attr() is one
					 * a reviewer can check at a glance.
					 *
					 * Left empty, the hero falls back to the initials, which
					 * is what every plugin showed before there was artwork.
					 */
					'mark'        => array(),

					/*
					 * Every word this file prints comes from the caller.
					 *
					 * It has to: the file is copied into fourteen plugins and
					 * a text domain is per-plugin, so a literal `__()` here
					 * would either name one plugin's domain in all fourteen
					 * or need a variable domain, which WordPress's string
					 * extraction cannot read at all. Either way the strings
					 * never reach a translation file. So the plugin passes
					 * them, translated with its own domain, and this file
					 * stays free of anything translatable.
					 */
					'strings'     => array(
						'next'   => 'Next',
						'done'   => 'Done',
						'skip'   => 'Skip',
						'of'     => 'of',
						'denied' => 'You are not allowed to view this page.',
					),
				)
			);

			$spec['strings'] = wp_parse_args(
				isset( $spec['strings'] ) ? (array) $spec['strings'] : array(),
				array(
					'next'   => 'Next',
					'done'   => 'Done',
					'skip'   => 'Skip',
					'of'     => 'of',
					'denied' => 'You are not allowed to view this page.',
				)
			);

			self::$plugins[ $spec['slug'] ] = $spec;

			if ( ! self::$booted ) {
				self::$booted = true;
				add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
				add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
				add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_tour' ) );
			}
		}

		/**
		 * Marks a plugin as freshly activated. Called from its activation hook.
		 *
		 * Deliberately an option and not a redirect: an activation hook that
		 * redirects breaks bulk activation and WP-CLI, both of which activate
		 * several plugins in one request. The flag is read on the next page
		 * load instead, where a redirect is safe.
		 *
		 * @param string $slug Plugin slug.
		 * @return void
		 */
		public static function mark_activated( $slug ) {

			if ( get_option( 'dahu_welcome_seen_' . $slug ) ) {
				return;
			}

			update_option( 'dahu_welcome_pending_' . $slug, 1, false );
		}

		/**
		 * The hidden page each plugin gets.
		 *
		 * Hidden on purpose: a permanent "Welcome" menu entry is clutter for
		 * the rest of the plugin's life, and the screen is reachable from the
		 * plugin's own settings for anyone who wants it again.
		 *
		 * @return void
		 */
		public static function menu() {

			foreach ( self::$plugins as $slug => $spec ) {
				add_submenu_page(
					'',
					$spec['name'],
					$spec['name'],
					$spec['capability'],
					$slug . '-welcome',
					function () use ( $slug ) {
						self::render( $slug );
					}
				);
			}
		}

		/**
		 * Sends a shop to the welcome screen once, just after activation.
		 *
		 * Never during a bulk activation, never over AJAX or WP-CLI, and
		 * never for somebody who cannot see the screen anyway.
		 *
		 * @return void
		 */
		public static function maybe_redirect() {

			if ( wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading WordPress's own bulk-activation marker, not acting on it.
			if ( isset( $_GET['activate-multi'] ) ) {
				foreach ( array_keys( self::$plugins ) as $slug ) {
					delete_option( 'dahu_welcome_pending_' . $slug );
				}

				return;
			}

			foreach ( self::$plugins as $slug => $spec ) {

				if ( ! get_option( 'dahu_welcome_pending_' . $slug ) ) {
					continue;
				}

				delete_option( 'dahu_welcome_pending_' . $slug );

				if ( ! current_user_can( $spec['capability'] ) ) {
					continue;
				}

				wp_safe_redirect( admin_url( 'admin.php?page=' . $slug . '-welcome' ) );
				exit;
			}
		}

		/**
		 * The tour, on the screen the plugin named, and once.
		 *
		 * @param string $hook Current admin page hook.
		 * @return void
		 */
		public static function enqueue_tour( $hook ) {

			foreach ( self::$plugins as $slug => $spec ) {

				if ( ! $spec['tour'] || ! $spec['tour_screen'] ) {
					continue;
				}

				if ( $hook !== $spec['tour_screen'] || get_option( 'dahu_welcome_tour_' . $slug ) ) {
					continue;
				}

				if ( ! current_user_can( $spec['capability'] ) ) {
					continue;
				}

				$base = $spec['file'] ? plugins_url( 'assets/', $spec['file'] ) : '';

				wp_enqueue_style( 'dahu-ui', $base . 'css/dahu-ui.css', array(), $spec['version'] );
				wp_enqueue_style( 'dahu-tour', $base . 'css/dahu-tour.css', array( 'dahu-ui' ), $spec['version'] );
				wp_enqueue_script( 'dahu-tour', $base . 'js/dahu-tour.js', array(), $spec['version'], true );

				wp_localize_script(
					'dahu-tour',
					'dahuTour',
					array(
						'steps' => array_values( $spec['tour'] ),
						'slug'  => $slug,
						'nonce' => wp_create_nonce( 'dahu_welcome_tour' ),
						'ajax'  => admin_url( 'admin-ajax.php' ),
						'i18n'  => $spec['strings'],
					)
				);

				add_action( 'wp_ajax_dahu_welcome_tour_done', array( __CLASS__, 'tour_done' ) );
			}
		}

		/**
		 * Records that the tour has played. A tour is a tour: it plays once.
		 *
		 * @return void
		 */
		public static function tour_done() {

			check_ajax_referer( 'dahu_welcome_tour' );

			$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';

			if ( ! isset( self::$plugins[ $slug ] ) || ! current_user_can( self::$plugins[ $slug ]['capability'] ) ) {
				wp_send_json_error( null, 403 );
			}

			update_option( 'dahu_welcome_tour_' . $slug, 1, false );

			wp_send_json_success();
		}

		/**
		 * Renders one plugin's welcome screen.
		 *
		 * @param string $slug Plugin slug.
		 * @return void
		 */
		public static function render( $slug ) {

			if ( ! isset( self::$plugins[ $slug ] ) ) {
				return;
			}

			$spec = self::$plugins[ $slug ];

			if ( ! current_user_can( $spec['capability'] ) ) {
				wp_die( esc_html( $spec['strings']['denied'] ), '', array( 'response' => 403 ) );
			}

			update_option( 'dahu_welcome_seen_' . $slug, 1, false );

			$base = $spec['file'] ? plugins_url( 'assets/', $spec['file'] ) : '';

			wp_enqueue_style( 'dahu-ui', $base . 'css/dahu-ui.css', array(), $spec['version'] );
			wp_enqueue_style( 'dahu-welcome', $base . 'css/dahu-welcome.css', array( 'dahu-ui' ), $spec['version'] );

			echo '<div class="wrap dahu-ui dahu-welcome">';

			printf(
				'<div class="dahu-welcome-hero">%s<div class="dahu-welcome-hero__mark" aria-hidden="true">%s</div>' .
				'<h1 class="dahu-welcome-hero__title">%s</h1><p class="dahu-welcome-hero__line">%s</p></div>',
				self::glyph( $spec, 'dahu-welcome-hero__wash' ),
				self::mark( $spec ),
				esc_html( $spec['name'] ),
				esc_html( $spec['tagline'] )
			);

			echo '<hr class="wp-header-end">';

			if ( $spec['points'] ) {

				echo '<div class="dahu-welcome-points">';

				foreach ( array_slice( $spec['points'], 0, 3 ) as $i => $point ) {
					printf(
						'<div class="dahu-welcome-point"><span class="dahu-welcome-point__n" aria-hidden="true">%d</span>' .
						'<h2 class="dahu-welcome-point__title">%s</h2><p class="dahu-welcome-point__text">%s</p></div>',
						(int) $i + 1,
						esc_html( isset( $point['title'] ) ? $point['title'] : '' ),
						esc_html( isset( $point['text'] ) ? $point['text'] : '' )
					);
				}

				echo '</div>';
			}

			if ( $spec['actions'] ) {

				echo '<div class="dahu-welcome-actions">';

				foreach ( $spec['actions'] as $action ) {

					// The whole class, not a format with a hole in it: the
					// family's own harness reads these out of the source to
					// check every class is defined somewhere, and it cannot
					// read one that is assembled at run time.
					$class = empty( $action['primary'] ) ? 'dahu-ui-btn' : 'dahu-ui-btn dahu-ui-btn--go';

					printf(
						'<a class="%s" href="%s">%s</a>',
						esc_attr( $class ),
						esc_url( isset( $action['url'] ) ? $action['url'] : '' ),
						esc_html( isset( $action['label'] ) ? $action['label'] : '' )
					);
				}

				echo '</div>';
			}

			echo '</div>';
		}

		/**
		 * Two letters for the hero mark, from the plugin's own name.
		 *
		 * An image would mean shipping one per plugin and keeping fourteen of
		 * them consistent; initials in the family's accent cost nothing, never
		 * go stale, and are legible at any size.
		 *
		 * @param string $name Plugin name.
		 * @return string
		 */
		/**
		 * The hero's mark: the plugin's own drawing, or its initials.
		 *
		 * Nothing from the spec is echoed as markup. The element name and the
		 * attribute names are written here, literally; only the viewBox and
		 * each `d` come from the caller, and both go through esc_attr(). So a
		 * plugin cannot inject anything through its own spec, which matters
		 * because this file is the one copy that runs in fourteen plugins.
		 *
		 * `aria-hidden` is already on the wrapper: the mark repeats the name
		 * printed right beside it, so a screen reader should not read it twice.
		 *
		 * @param array $spec The registered spec.
		 * @return string Ready-to-print HTML.
		 */
		protected static function mark( array $spec ) {

			$svg = self::glyph( $spec, 'dahu-welcome-hero__glyph' );

			return '' !== $svg ? $svg : esc_html( self::initials( $spec['name'] ) );
		}

		/**
		 * The plugin's drawing as an SVG, under a given class, or ''.
		 *
		 * Printed twice on the hero: once small inside the tile, once large and
		 * pale behind the words. The second is why the banner no longer ends in
		 * half a screen of nothing -- and it costs no new asset, because it is
		 * the same seven paths drawn again by the browser.
		 *
		 * @param array  $spec  The registered spec.
		 * @param string $class The class to carry.
		 * @return string HTML, or '' when the plugin passes no mark.
		 */
		protected static function glyph( array $spec, $class ) {

			$mark  = isset( $spec['mark'] ) && is_array( $spec['mark'] ) ? $spec['mark'] : array();
			$paths = isset( $mark['paths'] ) && is_array( $mark['paths'] ) ? $mark['paths'] : array();

			if ( ! $paths ) {
				return '';
			}

			$view = isset( $mark['view'] ) ? (string) $mark['view'] : '0 0 112 112';
			$out  = '<svg class="' . esc_attr( $class ) . '" viewBox="' . esc_attr( $view ) . '" fill="none" '
				. 'stroke="currentColor" stroke-width="8.5" stroke-linecap="round" stroke-linejoin="round" '
				. 'focusable="false" aria-hidden="true">';

			foreach ( $paths as $d ) {
				if ( ! is_string( $d ) || '' === $d ) {
					continue;
				}
				$out .= '<path d="' . esc_attr( $d ) . '"/>';
			}

			return $out . '</svg>';
		}

		protected static function initials( $name ) {

			$words = preg_split( '/\s+/', trim( wp_strip_all_tags( (string) $name ) ) );
			$out   = '';

			foreach ( (array) $words as $word ) {

				if ( '' === $word ) {
					continue;
				}

				$out .= mb_strtoupper( mb_substr( $word, 0, 1 ) );

				if ( mb_strlen( $out ) >= 2 ) {
					break;
				}
			}

			return '' === $out ? 'D' : $out;
		}
	}
}
