<?php
/**
 * Plugin Name:       Avalanche Sync Guard
 * Plugin URI:        https://github.com/AvalancheCreativeGR/avalanche-sync-guard
 * Description:       Records when this site's database moves between environments (Local Connect push/pull) plus the structural and content changes worth knowing about — pages published, custom post types created, plugins activated, permalinks changed. Includes a "work in progress" lock notice so clients don't edit content that is about to be overwritten.
 * Version:           1.3.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Avalanche Creative
 * License:           GPL-2.0-or-later
 * Text Domain:       avalanche-sync-guard
 * GitHub Plugin URI:  AvalancheCreativeGR/avalanche-sync-guard
 *
 * WHAT THIS IS FOR
 * ----------------
 * These sites are not under version control, so there is no history to read when
 * two environments disagree. This plugin builds that history from inside WordPress:
 * every database move between environments, and every change big enough that you
 * would want to know whether it happened here or over there.
 *
 * HOW SYNC DETECTION WORKS
 * ------------------------
 * Local Connect is a desktop app; a plugin cannot hook into it. So this plugin
 * detects the *effect* of a sync instead. It keeps a stamp inside the database
 * recording which environment that database was last running in, and compares it
 * against the environment it is actually running in now. When those disagree, a
 * database has moved:
 *
 *   stamp says production, now running local  -> PULL  (production came down; normal)
 *   stamp says local, now running production  -> PUSH  (local went up; destructive)
 *
 * A push overwrites the database, and the log lives in the database, so the log is
 * mirrored to an append-only file under wp-content/uploads that survives it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Avalanche_Sync_Guard' ) ) :

final class Avalanche_Sync_Guard {

	const VERSION      = '1.3.0';

	const OPT_STAMP    = 'asg_db_stamp';
	const OPT_EVENTS   = 'asg_events';    // Sync events only. Kept separate so content noise can never crowd them out.
	const OPT_ACTIVITY = 'asg_activity';  // Everything else.
	const OPT_REGISTRY = 'asg_registry';  // Known post types + taxonomies, for diffing.
	const OPT_SETTINGS = 'asg_settings';

	const PAGE_OVERVIEW = 'avalanche-sync-guard';
	const PAGE_ACTIVITY = 'asg-activity';
	const PAGE_SETTINGS = 'asg-settings';

	const MAX_EVENTS    = 100;
	const MAX_ACTIVITY  = 500;
	const HEARTBEAT_SEC = 43200;          // Refresh the environment stamp at most twice a day.
	const ROTATE_BYTES  = 2097152;        // Rotate the file log at 2 MB.

	/** @var Avalanche_Sync_Guard|null */
	private static $instance = null;

	/** Guards against logging our own bulk writes during a registry rebuild. */
	private $suspend_logging = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Sync detection + admin UI.
		add_action( 'admin_init', array( $this, 'detect_sync' ) );
		add_action( 'admin_init', array( $this, 'detect_registry_changes' ), 999 );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_menu', array( $this, 'menu_badge' ), 99 );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
		add_action( 'admin_post_asg_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_asg_download', array( $this, 'handle_download' ) );
		add_action( 'admin_post_asg_backfill', array( $this, 'handle_backfill' ) );

		// Front-end lock banner.
		add_action( 'wp_body_open', array( $this, 'render_frontend_banner' ) );
		add_action( 'wp_head', array( $this, 'frontend_banner_styles' ) );

		$this->register_activity_hooks();
	}

	/**
	 * Everything we watch beyond syncs. Deliberately narrow: status changes and
	 * structural edits, not every save. Noisy per-keystroke events (revisions,
	 * autosaves, Beaver Builder history rows) are filtered out in the handlers.
	 */
	private function register_activity_hooks() {
		// Content.
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_before_delete_post' ) );

		// Themes and plugins.
		add_action( 'switch_theme', array( $this, 'on_switch_theme' ), 10, 2 );
		add_action( 'activated_plugin', array( $this, 'on_activated_plugin' ) );
		add_action( 'deactivated_plugin', array( $this, 'on_deactivated_plugin' ) );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrade' ), 10, 2 );

		// Settings that change how the whole site resolves.
		add_action( 'update_option_siteurl', array( $this, 'on_url_option_change' ), 10, 3 );
		add_action( 'update_option_home', array( $this, 'on_url_option_change' ), 10, 3 );
		add_action( 'update_option_permalink_structure', array( $this, 'on_url_option_change' ), 10, 3 );
		add_action( 'update_option_blogname', array( $this, 'on_url_option_change' ), 10, 3 );

		// Navigation and users.
		add_action( 'wp_update_nav_menu', array( $this, 'on_update_nav_menu' ) );
		add_action( 'user_register', array( $this, 'on_user_register' ) );
		add_action( 'set_user_role', array( $this, 'on_set_user_role' ), 10, 3 );

		// Beaver Builder: the real "this page was built" signal on these sites.
		add_action( 'fl_builder_after_save_layout', array( $this, 'on_bb_save_layout' ), 10, 4 );
	}

	/* =====================================================================
	 * Settings
	 * ================================================================== */

	public function settings() {
		$defaults = array(
			'lock_enabled' => 0,
			'lock_message' => __( 'This site is currently under construction locally. Please do not make any changes — edits made here will be overwritten.', 'avalanche-sync-guard' ),
			'lock_who'     => 'admin',   // admin | logged_in | everyone
			'locked_by'    => '',
			'locked_at'    => 0,
		);
		$saved = get_option( self::OPT_SETTINGS, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, $defaults );
	}

	/* =====================================================================
	 * Environment fingerprinting
	 * ================================================================== */

	/** A stable signature for the environment this code is running in. */
	public function environment_signature() {
		// WP Engine sets PWP_NAME to the install name, so production and staging differ.
		if ( defined( 'PWP_NAME' ) && PWP_NAME ) {
			return 'wpengine:' . PWP_NAME;
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = $host ? strtolower( $host ) : 'unknown-host';

		if ( substr( $host, -6 ) === '.local' ) {
			return 'local:' . $host;
		}
		return 'site:' . $host;
	}

	public function environment_label( $signature = null ) {
		$signature = ( null === $signature ) ? $this->environment_signature() : $signature;
		if ( 0 === strpos( $signature, 'local:' ) ) {
			return 'Local (' . substr( $signature, 6 ) . ')';
		}
		if ( 0 === strpos( $signature, 'wpengine:' ) ) {
			return 'WP Engine (' . substr( $signature, 9 ) . ')';
		}
		return substr( $signature, strpos( $signature, ':' ) + 1 );
	}

	public function is_local() {
		return 0 === strpos( $this->environment_signature(), 'local:' );
	}

	public function is_hosted() {
		return ! $this->is_local();
	}

	private function newest_content_edit() {
		global $wpdb;
		$value = $wpdb->get_var(
			"SELECT MAX(post_modified_gmt) FROM {$wpdb->posts}
			 WHERE post_status IN ('publish','draft','pending','private')
			   AND post_type NOT IN ('revision','fl-builder-history','acf-field','acf-field-group')"
		);
		return $value ? $value : '';
	}

	/* =====================================================================
	 * Sync detection
	 * ================================================================== */

	public function detect_sync() {
		$current = $this->environment_signature();
		$stamp   = get_option( self::OPT_STAMP, array() );

		if ( ! is_array( $stamp ) || empty( $stamp['env'] ) ) {
			$this->log(
				'sync',
				'baseline',
				__( 'Sync Guard activated; tracking starts here.', 'avalanche-sync-guard' ),
				array(
					'from' => '',
					'to'   => $current,
				)
			);
			$this->write_stamp( $current );
			return;
		}

		if ( $stamp['env'] === $current ) {
			if ( time() - (int) $stamp['stamped_at'] > self::HEARTBEAT_SEC ) {
				$this->write_stamp( $current );
			}
			return;
		}

		$from       = $stamp['env'];
		$from_local = ( 0 === strpos( $from, 'local:' ) );
		$to_local   = ( 0 === strpos( $current, 'local:' ) );

		if ( $to_local && ! $from_local ) {
			$type = 'pull';
			$note = __( 'Hosted database pulled down into Local. This is the safe direction.', 'avalanche-sync-guard' );
		} elseif ( ! $to_local && $from_local ) {
			$type = 'push';
			$note = __( 'A LOCAL database was pushed up over this hosted site. Any content created here since the last pull is gone.', 'avalanche-sync-guard' );
		} else {
			$type = 'move';
			$note = __( 'Database moved between two hosted environments.', 'avalanche-sync-guard' );
		}

		$age_days = ( ! empty( $stamp['stamped_at'] ) )
			? round( ( time() - (int) $stamp['stamped_at'] ) / DAY_IN_SECONDS, 1 )
			: null;

		$this->log(
			'sync',
			$type,
			$note,
			array(
				'from'                    => $from,
				'to'                      => $current,
				'arriving_db_age_days'    => $age_days,
				'arriving_db_newest_edit' => isset( $stamp['newest_edit'] ) ? $stamp['newest_edit'] : '',
			)
		);

		$this->write_stamp( $current );
	}

	private function write_stamp( $signature ) {
		update_option(
			self::OPT_STAMP,
			array(
				'env'         => $signature,
				'stamped_at'  => time(),
				'newest_edit' => $this->newest_content_edit(),
			),
			false
		);
	}

	/* =====================================================================
	 * Structure: post types and taxonomies
	 * ================================================================== */

	/** New post types are absorbed silently for this long after first activation. */
	const REGISTRY_SETTLE_SEC = 86400;

	/**
	 * Watches for post types and taxonomies that have never been seen on this
	 * install before. This catches a custom post type however it was created —
	 * ACF, CPT UI, or hand-written in functions.php — which no single hook can.
	 *
	 * Two things keep this quiet. First, the record is cumulative ("ever seen"),
	 * not a snapshot, because plenty of types register conditionally — Beaver
	 * Builder's fl_code only exists on some requests, and a snapshot diff would
	 * report it appearing and vanishing forever. Second, anything showing up in
	 * the first day after activation is absorbed without a log line, so the
	 * settling-in period does not bury the events that matter.
	 *
	 * Types going away are deliberately not logged here: the cause is always
	 * something already recorded with a better label — a plugin deactivated, or
	 * an ACF post-type record deleted.
	 */
	public function detect_registry_changes() {
		$current = array(
			'post_types' => $this->current_post_types(),
			'taxonomies' => $this->current_taxonomies(),
		);

		$known = get_option( self::OPT_REGISTRY, null );

		// First run: seed silently, or every existing CPT would log as new.
		if ( ! is_array( $known ) || ! isset( $known['post_types'] ) ) {
			$current['seeded_at'] = time();
			update_option( self::OPT_REGISTRY, $current, false );
			return;
		}

		$seeded_at = isset( $known['seeded_at'] ) ? (int) $known['seeded_at'] : 0;
		$settling  = ( $seeded_at && ( time() - $seeded_at ) < self::REGISTRY_SETTLE_SEC );

		$nouns = array(
			'post_types' => __( 'Custom post type', 'avalanche-sync-guard' ),
			'taxonomies' => __( 'Taxonomy', 'avalanche-sync-guard' ),
		);

		$updated = false;
		foreach ( $nouns as $key => $noun ) {
			$ever  = isset( $known[ $key ] ) ? (array) $known[ $key ] : array();
			$added = array_diff( $current[ $key ], $ever );

			if ( empty( $added ) ) {
				continue;
			}

			if ( ! $settling ) {
				foreach ( $added as $name ) {
					$this->log(
						'structure',
						'registry_added',
						sprintf(
							/* translators: 1: "Custom post type" or "Taxonomy", 2: its slug */
							__( '%1$s "%2$s" registered for the first time.', 'avalanche-sync-guard' ),
							$noun,
							$name
						)
					);
				}
			}

			// Remember it either way, so it is only ever reported once.
			$known[ $key ] = array_values( array_unique( array_merge( $ever, $added ) ) );
			sort( $known[ $key ] );
			$updated = true;
		}

		if ( $updated ) {
			$known['seeded_at'] = $seeded_at;
			update_option( self::OPT_REGISTRY, $known, false );
		}
	}

	private function current_post_types() {
		$types = get_post_types( array(), 'names' );
		$types = array_diff( $types, $this->ignored_post_types() );
		sort( $types );
		return array_values( $types );
	}

	private function current_taxonomies() {
		$taxes = get_taxonomies( array(), 'names' );
		$taxes = array_diff( $taxes, array( 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' ) );
		sort( $taxes );
		return array_values( $taxes );
	}

	/* =====================================================================
	 * Activity handlers
	 * ================================================================== */

	/** Post types whose churn is machinery, not work worth logging. */
	private function ignored_post_types() {
		return apply_filters(
			'asg_ignored_post_types',
			array(
				'revision',
				'attachment',
				'nav_menu_item',
				'customize_changeset',
				'custom_css',
				'oembed_cache',
				'user_request',
				'wp_global_styles',
				'wp_navigation',
				'wp_template',
				'wp_template_part',
				'wp_block',
				'fl-builder-history',
				'acf-field',
				'scheduled-action',
				'frm_styles',
				'frm_form_actions',
			)
		);
	}

	/** Friendlier names for the post types these sites actually use. */
	private function post_type_label( $post_type ) {
		$map = array(
			'acf-post-type'       => __( 'custom post type', 'avalanche-sync-guard' ),
			'acf-taxonomy'        => __( 'taxonomy', 'avalanche-sync-guard' ),
			'acf-field-group'     => __( 'ACF field group', 'avalanche-sync-guard' ),
			'acf-ui-options-page' => __( 'ACF options page', 'avalanche-sync-guard' ),
			'fl-theme-layout'     => __( 'Beaver Builder theme layout', 'avalanche-sync-guard' ),
			'fl-builder-template' => __( 'Beaver Builder template', 'avalanche-sync-guard' ),
		);
		if ( isset( $map[ $post_type ] ) ) {
			return $map[ $post_type ];
		}
		$object = get_post_type_object( $post_type );
		if ( $object && ! empty( $object->labels->singular_name ) ) {
			return strtolower( $object->labels->singular_name );
		}
		return $post_type;
	}

	public function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post || $new_status === $old_status ) {
			return;
		}
		if ( in_array( $post->post_type, $this->ignored_post_types(), true ) ) {
			return;
		}
		// Drafts being auto-created are not work; ignore until something real happens.
		if ( 'auto-draft' === $new_status || 'inherit' === $new_status ) {
			return;
		}

		$noun  = $this->post_type_label( $post->post_type );
		$title = $post->post_title ? $post->post_title : __( '(no title)', 'avalanche-sync-guard' );

		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			$type  = 'published';
			$label = sprintf(
				/* translators: 1: post type noun, 2: title */
				__( 'Published %1$s: "%2$s"', 'avalanche-sync-guard' ),
				$noun,
				$title
			);
		} elseif ( 'trash' === $new_status ) {
			$type  = 'trashed';
			$label = sprintf( __( 'Trashed %1$s: "%2$s"', 'avalanche-sync-guard' ), $noun, $title );
		} elseif ( 'trash' === $old_status ) {
			$type  = 'restored';
			$label = sprintf( __( 'Restored %1$s from trash: "%2$s"', 'avalanche-sync-guard' ), $noun, $title );
		} elseif ( 'publish' === $old_status ) {
			$type  = 'unpublished';
			$label = sprintf( __( 'Unpublished %1$s (now %3$s): "%2$s"', 'avalanche-sync-guard' ), $noun, $title, $new_status );
		} elseif ( in_array( $old_status, array( 'auto-draft', 'new' ), true ) && in_array( $new_status, array( 'draft', 'pending', 'private', 'future' ), true ) ) {
			// The editor goes auto-draft -> draft; wp_insert_post() goes new -> draft.
			$type  = 'created';
			$label = sprintf( __( 'Created %1$s (%3$s): "%2$s"', 'avalanche-sync-guard' ), $noun, $title, $new_status );
		} else {
			return; // draft -> pending and similar shuffling is not worth a line.
		}

		$this->log(
			'content',
			$type,
			$label,
			array(
				'post_id'   => $post->ID,
				'post_type' => $post->post_type,
				'link'      => get_permalink( $post ),
			)
		);
	}

	public function on_before_delete_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( in_array( $post->post_type, $this->ignored_post_types(), true ) ) {
			return;
		}
		$this->log(
			'content',
			'deleted',
			sprintf(
				/* translators: 1: post type noun, 2: title */
				__( 'Permanently deleted %1$s: "%2$s"', 'avalanche-sync-guard' ),
				$this->post_type_label( $post->post_type ),
				$post->post_title ? $post->post_title : __( '(no title)', 'avalanche-sync-guard' )
			),
			array(
				'post_id'   => $post->ID,
				'post_type' => $post->post_type,
			)
		);
	}

	public function on_switch_theme( $new_name, $new_theme = null ) {
		$this->log(
			'structure',
			'theme_switched',
			sprintf(
				/* translators: %s: theme name */
				__( 'Active theme switched to "%s".', 'avalanche-sync-guard' ),
				$new_name
			)
		);
	}

	public function on_activated_plugin( $plugin ) {
		$this->log(
			'structure',
			'plugin_activated',
			sprintf( __( 'Plugin activated: %s', 'avalanche-sync-guard' ), $this->plugin_name( $plugin ) )
		);
	}

	public function on_deactivated_plugin( $plugin ) {
		$this->log(
			'structure',
			'plugin_deactivated',
			sprintf( __( 'Plugin deactivated: %s', 'avalanche-sync-guard' ), $this->plugin_name( $plugin ) )
		);
	}

	private function plugin_name( $plugin_file ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( is_readable( $path ) ) {
			$data = get_plugin_data( $path, false, false );
			if ( ! empty( $data['Name'] ) ) {
				return $data['Name'];
			}
		}
		return $plugin_file;
	}

	public function on_upgrade( $upgrader, $extra ) {
		if ( empty( $extra['type'] ) ) {
			return;
		}
		$what = '';
		if ( 'plugin' === $extra['type'] && ! empty( $extra['plugins'] ) ) {
			$names = array_map( array( $this, 'plugin_name' ), (array) $extra['plugins'] );
			$what  = implode( ', ', $names );
		} elseif ( 'theme' === $extra['type'] && ! empty( $extra['themes'] ) ) {
			$what = implode( ', ', (array) $extra['themes'] );
		} elseif ( 'core' === $extra['type'] ) {
			$what = get_bloginfo( 'version' );
		}

		$action = isset( $extra['action'] ) ? $extra['action'] : 'update';

		$this->log(
			'structure',
			'upgrade',
			sprintf(
				/* translators: 1: install/update, 2: plugin/theme/core, 3: names */
				__( '%1$s %2$s: %3$s', 'avalanche-sync-guard' ),
				ucfirst( $action ),
				$extra['type'],
				$what ? $what : __( '(unnamed)', 'avalanche-sync-guard' )
			)
		);
	}

	public function on_url_option_change( $old_value, $value, $option ) {
		if ( $old_value === $value ) {
			return;
		}
		$this->log(
			'structure',
			'option_changed',
			sprintf(
				/* translators: 1: option name, 2: old value, 3: new value */
				__( 'Setting "%1$s" changed from "%2$s" to "%3$s".', 'avalanche-sync-guard' ),
				$option,
				is_scalar( $old_value ) ? (string) $old_value : '(complex)',
				is_scalar( $value ) ? (string) $value : '(complex)'
			)
		);
	}

	public function on_update_nav_menu( $menu_id ) {
		$menu = wp_get_nav_menu_object( $menu_id );
		$this->log(
			'structure',
			'menu_updated',
			sprintf(
				/* translators: %s: menu name */
				__( 'Navigation menu updated: "%s"', 'avalanche-sync-guard' ),
				$menu ? $menu->name : $menu_id
			)
		);
	}

	public function on_user_register( $user_id ) {
		$user = get_userdata( $user_id );
		$this->log(
			'structure',
			'user_created',
			sprintf(
				/* translators: 1: login, 2: role */
				__( 'User account created: %1$s (%2$s)', 'avalanche-sync-guard' ),
				$user ? $user->user_login : $user_id,
				( $user && ! empty( $user->roles ) ) ? implode( ', ', $user->roles ) : 'unknown role'
			)
		);
	}

	public function on_set_user_role( $user_id, $role, $old_roles ) {
		// Only shout about escalation to a role that can change the site.
		if ( ! in_array( $role, array( 'administrator', 'editor' ), true ) ) {
			return;
		}
		if ( is_array( $old_roles ) && in_array( $role, $old_roles, true ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		$this->log(
			'structure',
			'role_changed',
			sprintf(
				/* translators: 1: login, 2: new role */
				__( 'User %1$s granted the "%2$s" role.', 'avalanche-sync-guard' ),
				$user ? $user->user_login : $user_id,
				$role
			)
		);
	}

	public function on_bb_save_layout( $post_id = 0, $publish = false, $data = null, $settings = null ) {
		if ( ! $publish ) {
			return; // Drafts of a layout are not a milestone.
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$this->log(
			'content',
			'bb_layout_saved',
			sprintf(
				/* translators: 1: post type noun, 2: title */
				__( 'Beaver Builder layout published on %1$s: "%2$s"', 'avalanche-sync-guard' ),
				$this->post_type_label( $post->post_type ),
				$post->post_title ? $post->post_title : __( '(no title)', 'avalanche-sync-guard' )
			),
			array(
				'post_id' => $post->ID,
				'link'    => get_permalink( $post ),
			)
		);
	}

	/* =====================================================================
	 * Event log
	 * ================================================================== */

	/**
	 * @param string $kind   sync | content | structure | lock
	 * @param string $type   Machine slug, e.g. push, published, plugin_activated.
	 * @param string $label  Human sentence shown in the table.
	 * @param array  $extra  Additional structured fields.
	 */
	public function log( $kind, $type, $label, $extra = array() ) {
		if ( $this->suspend_logging ) {
			return null;
		}

		$event = array_merge(
			array(
				'ts'    => time(),
				'iso'   => gmdate( 'c' ),
				'kind'  => $kind,
				'type'  => $type,
				'label' => $label,
				'user'  => $this->current_user_label(),
				'env'   => $this->environment_signature(),
			),
			is_array( $extra ) ? $extra : array()
		);

		// Sync events live in their own option so a flood of content events can
		// never push them out of the window.
		$option = ( 'sync' === $kind ) ? self::OPT_EVENTS : self::OPT_ACTIVITY;
		$cap    = ( 'sync' === $kind ) ? self::MAX_EVENTS : self::MAX_ACTIVITY;

		$stored = get_option( $option, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		array_unshift( $stored, $event );
		update_option( $option, array_slice( $stored, 0, $cap ), false );

		// The file copy survives a database push, which is the whole point.
		$this->append_to_file_log( $event );

		return $event;
	}

	private function current_user_label() {
		if ( ! function_exists( 'wp_get_current_user' ) || ! did_action( 'set_current_user' ) ) {
			return '';
		}
		$user = wp_get_current_user();
		return ( $user && $user->exists() ) ? $user->user_login : '';
	}

	public function log_file_path() {
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}
		return trailingslashit( $uploads['basedir'] ) . 'avalanche-sync-guard/events.log';
	}

	private function append_to_file_log( $event ) {
		$path = $this->log_file_path();
		if ( ! $path ) {
			return;
		}
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( $dir . '/.htaccess', "Require all denied\n" );
			@file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		}
		// Rotate rather than grow without bound. One generation is kept.
		if ( file_exists( $path ) && filesize( $path ) > self::ROTATE_BYTES ) {
			@rename( $path, $path . '.1' );
		}
		@file_put_contents( $path, wp_json_encode( $event ) . PHP_EOL, FILE_APPEND | LOCK_EX );
	}

	/* =====================================================================
	 * Backfill
	 * ================================================================== */

	/**
	 * Seeds the activity log from content that already exists, so the log is not
	 * empty for a site that has been running for years before this was installed.
	 *
	 * These entries are reconstructed from post dates, not observed as they
	 * happened, and are flagged `backfilled` so they are never mistaken for a
	 * first-hand record. Publish dates are real; the user attributed is the post
	 * author, which is the best available guess and not necessarily who clicked
	 * publish.
	 *
	 * @param int $limit Most recent items to reconstruct.
	 * @return int Number of events added.
	 */
	public function backfill_history( $limit = 100 ) {
		global $wpdb;

		$types = array_diff(
			get_post_types( array( 'public' => true ), 'names' ),
			$this->ignored_post_types()
		);
		if ( empty( $types ) ) {
			return 0;
		}

		$already = get_option( 'asg_backfilled_ids', array() );
		if ( ! is_array( $already ) ) {
			$already = array();
		}

		// Deliberately a narrow column query rather than get_posts(). Hydrating
		// full post objects (and their meta) for a few hundred rows is what makes
		// a backfill run out of memory on a real site.
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_type, p.post_date_gmt, u.user_login
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->users} u ON u.ID = p.post_author
				 WHERE p.post_status = 'publish'
				   AND p.post_type IN ( $placeholders )
				 ORDER BY p.post_date_gmt DESC
				 LIMIT %d",
				array_merge( array_values( $types ), array( (int) $limit ) )
			)
		);

		if ( empty( $rows ) ) {
			return 0;
		}

		$events = array();
		foreach ( $rows as $row ) {
			if ( in_array( (int) $row->ID, array_map( 'intval', $already ), true ) ) {
				continue;
			}
			$ts = strtotime( $row->post_date_gmt . ' UTC' );
			if ( ! $ts ) {
				$ts = time();
			}

			$events[] = array(
				'ts'         => $ts,
				'iso'        => gmdate( 'c', $ts ),
				'kind'       => 'content',
				'type'       => 'published',
				'label'      => sprintf(
					/* translators: 1: post type noun, 2: title */
					__( 'Published %1$s: "%2$s"', 'avalanche-sync-guard' ),
					$this->post_type_label( $row->post_type ),
					$row->post_title ? $row->post_title : __( '(no title)', 'avalanche-sync-guard' )
				),
				'user'       => $row->user_login ? $row->user_login : '',
				'env'        => $this->environment_signature(),
				'post_id'    => (int) $row->ID,
				'post_type'  => $row->post_type,
				'backfilled' => true,
			);

			$already[] = (int) $row->ID;
		}

		if ( empty( $events ) ) {
			update_option( 'asg_backfilled_at', time(), false );
			return 0;
		}

		$this->log_many( $events );

		update_option( 'asg_backfilled_ids', array_values( array_unique( $already ) ), false );
		update_option( 'asg_backfilled_at', time(), false );

		return count( $events );
	}

	/**
	 * Writes many prepared events in one pass. log() reads and rewrites the whole
	 * option every call, which is fine for one event and quadratic for hundreds.
	 *
	 * @param array $events Fully-formed event arrays.
	 */
	private function log_many( array $events ) {
		if ( empty( $events ) ) {
			return;
		}

		// Newest first, to match how the log is stored.
		usort(
			$events,
			function ( $a, $b ) {
				return ( isset( $b['ts'] ) ? (int) $b['ts'] : 0 ) <=> ( isset( $a['ts'] ) ? (int) $a['ts'] : 0 );
			}
		);

		$stored = get_option( self::OPT_ACTIVITY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$stored = array_merge( $events, $stored );
		update_option( self::OPT_ACTIVITY, array_slice( $stored, 0, self::MAX_ACTIVITY ), false );

		// One file append rather than one per event.
		$path = $this->log_file_path();
		if ( ! $path ) {
			return;
		}
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( $dir . '/.htaccess', "Require all denied\n" );
			@file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		}
		$lines = '';
		foreach ( array_reverse( $events ) as $event ) {
			$lines .= wp_json_encode( $event ) . PHP_EOL;
		}
		@file_put_contents( $path, $lines, FILE_APPEND | LOCK_EX );
	}

	public function handle_backfill() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'avalanche-sync-guard' ) );
		}
		check_admin_referer( 'asg_backfill' );

		$added = $this->backfill_history( 100 );

		wp_safe_redirect(
$this->page_url( self::PAGE_ACTIVITY, array( 'asg_status' => 'backfilled', 'asg_count' => $added ) )
		);
		exit;
	}

	/** Sync events from the database, newest first. */
	public function get_events() {
		$events = get_option( self::OPT_EVENTS, array() );
		return is_array( $events ) ? $events : array();
	}

	/** Non-sync activity from the database, newest first. */
	public function get_activity() {
		$events = get_option( self::OPT_ACTIVITY, array() );
		return is_array( $events ) ? $events : array();
	}

	/**
	 * Events from the file log, newest first. Includes anything a push erased
	 * from the database.
	 *
	 * @param int         $limit Maximum events to return.
	 * @param string|null $kind  Restrict to one kind, or null for all.
	 */
	public function get_file_events( $limit = 100, $kind = null ) {
		$path = $this->log_file_path();
		if ( ! $path || ! is_readable( $path ) ) {
			return array();
		}
		$lines  = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$lines  = array_reverse( (array) $lines );
		$events = array();
		foreach ( $lines as $line ) {
			$decoded = json_decode( $line, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			// Events written before 1.1.0 have no "kind"; they were all syncs.
			if ( ! isset( $decoded['kind'] ) ) {
				$decoded['kind']  = 'sync';
				$decoded['type']  = isset( $decoded['direction'] ) ? $decoded['direction'] : 'move';
				$decoded['label'] = isset( $decoded['note'] ) ? $decoded['note'] : '';
			}
			if ( null !== $kind && $decoded['kind'] !== $kind ) {
				continue;
			}
			$events[] = $decoded;
			if ( count( $events ) >= $limit ) {
				break;
			}
		}
		return $events;
	}

	/** The most recent sync event of a given type, or null. */
	public function last_event( $type ) {
		foreach ( $this->get_file_events( 500, 'sync' ) as $event ) {
			if ( isset( $event['type'] ) && $event['type'] === $type ) {
				return $event;
			}
		}
		foreach ( $this->get_events() as $event ) {
			$candidate = isset( $event['type'] ) ? $event['type'] : ( isset( $event['direction'] ) ? $event['direction'] : '' );
			if ( $candidate === $type ) {
				return $event;
			}
		}
		return null;
	}

	/* =====================================================================
	 * Admin UI
	 * ================================================================== */

	/**
	 * Top-level admin section.
	 *
	 * This lives beside Posts and Tools rather than inside Tools because it is
	 * where you check whether a database moved and whether the site is safe to
	 * edit — questions you ask before touching anything, not a utility you go
	 * hunting for.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Sync Guard', 'avalanche-sync-guard' ),
			__( 'Sync Guard', 'avalanche-sync-guard' ),
			'manage_options',
			self::PAGE_OVERVIEW,
			array( $this, 'render_overview_page' ),
			'dashicons-update-alt',
			3 // Directly under Dashboard: a destructive push should be hard to miss.
		);

		add_submenu_page(
			self::PAGE_OVERVIEW,
			__( 'Overview', 'avalanche-sync-guard' ),
			__( 'Overview', 'avalanche-sync-guard' ),
			'manage_options',
			self::PAGE_OVERVIEW,
			array( $this, 'render_overview_page' )
		);

		add_submenu_page(
			self::PAGE_OVERVIEW,
			__( 'Activity Log', 'avalanche-sync-guard' ),
			__( 'Activity Log', 'avalanche-sync-guard' ),
			'manage_options',
			self::PAGE_ACTIVITY,
			array( $this, 'render_activity_page' )
		);

		add_submenu_page(
			self::PAGE_OVERVIEW,
			__( 'Settings', 'avalanche-sync-guard' ),
			__( 'Settings', 'avalanche-sync-guard' ),
			'manage_options',
			self::PAGE_SETTINGS,
			array( $this, 'render_settings_page' )
		);
	}

	/** URL for one of this section's pages. */
	public function page_url( $slug, $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => $slug ), $args ),
			admin_url( 'admin.php' )
		);
	}

	public function render_overview_page() {
		$this->render_page( 'overview' );
	}

	public function render_activity_page() {
		$this->render_page( 'activity' );
	}

	public function render_settings_page() {
		$this->render_page( 'settings' );
	}

	/**
	 * A menu bubble when a destructive push has been recorded here, so the
	 * warning is visible from any screen in the admin.
	 */
	public function menu_badge() {
		global $menu;
		if ( ! is_array( $menu ) || ! $this->is_hosted() ) {
			return;
		}
		$push = $this->last_event( 'push' );
		if ( ! $push || empty( $push['ts'] ) || ( time() - (int) $push['ts'] ) > 30 * DAY_IN_SECONDS ) {
			return;
		}
		foreach ( $menu as $i => $item ) {
			if ( isset( $item[2] ) && self::PAGE_OVERVIEW === $item[2] ) {
				$menu[ $i ][0] .= ' <span class="update-plugins count-1"><span class="update-count">!</span></span>';
				break;
			}
		}
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'avalanche-sync-guard' ) );
		}
		check_admin_referer( 'asg_save' );

		$settings = $this->settings();
		$was_on   = ! empty( $settings['lock_enabled'] );
		$now_on   = ! empty( $_POST['lock_enabled'] );

		$settings['lock_enabled'] = $now_on ? 1 : 0;

		$message = isset( $_POST['lock_message'] ) ? wp_kses_post( wp_unslash( $_POST['lock_message'] ) ) : '';
		if ( '' !== trim( wp_strip_all_tags( $message ) ) ) {
			$settings['lock_message'] = $message;
		}

		$who                  = isset( $_POST['lock_who'] ) ? sanitize_key( wp_unslash( $_POST['lock_who'] ) ) : 'admin';
		$settings['lock_who'] = in_array( $who, array( 'admin', 'logged_in', 'everyone' ), true ) ? $who : 'admin';

		if ( $now_on && ! $was_on ) {
			$settings['locked_by'] = $this->current_user_label();
			$settings['locked_at'] = time();
			$this->log( 'lock', 'lock_on', __( 'Work-in-progress lock switched ON.', 'avalanche-sync-guard' ) );
		} elseif ( ! $now_on && $was_on ) {
			$settings['locked_by'] = '';
			$settings['locked_at'] = 0;
			$this->log( 'lock', 'lock_off', __( 'Work-in-progress lock switched OFF.', 'avalanche-sync-guard' ) );
		}

		update_option( self::OPT_SETTINGS, $settings, false );

		wp_safe_redirect(
$this->page_url( self::PAGE_SETTINGS, array( 'asg_status' => 'saved' ) )
		);
		exit;
	}

	/** Streams the raw file log so it can be compared against another environment's. */
	public function handle_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download this log.', 'avalanche-sync-guard' ) );
		}
		check_admin_referer( 'asg_download' );

		$path = $this->log_file_path();
		if ( ! $path || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'No log file to download yet.', 'avalanche-sync-guard' ) );
		}

		$env      = preg_replace( '/[^a-z0-9]+/i', '-', $this->environment_signature() );
		$filename = 'sync-guard-' . $env . '-' . gmdate( 'Ymd-His' ) . '.log';

		nocache_headers();
		header( 'Content-Type: application/x-ndjson; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}

	/**
	 * Renders one screen of the section. The three pages share this template so
	 * the data-gathering above them stays in one place.
	 *
	 * @param string $screen overview | activity | settings
	 */
	private function render_page( $screen = 'overview' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->settings();
		$stamp    = get_option( self::OPT_STAMP, array() );

		$syncs = $this->get_file_events( 50, 'sync' );
		if ( empty( $syncs ) ) {
			$syncs = $this->get_events();
		}

		$filter   = isset( $_GET['asg_kind'] ) ? sanitize_key( wp_unslash( $_GET['asg_kind'] ) ) : 'all';
		$activity = $this->get_file_events( 1000 );
		if ( empty( $activity ) ) {
			$activity = array_merge( $this->get_activity(), $this->get_events() );
		}
		$activity = array_values(
			array_filter(
				$activity,
				function ( $event ) use ( $filter ) {
					if ( 'sync' === ( isset( $event['kind'] ) ? $event['kind'] : '' ) ) {
						return false; // Syncs have their own table above.
					}
					return ( 'all' === $filter ) || ( isset( $event['kind'] ) && $event['kind'] === $filter );
				}
			)
		);
		// The file log is append-ordered, but backfilled entries carry historic
		// timestamps, so order by the event time rather than by write order.
		usort(
			$activity,
			function ( $a, $b ) {
				return ( isset( $b['ts'] ) ? (int) $b['ts'] : 0 ) <=> ( isset( $a['ts'] ) ? (int) $a['ts'] : 0 );
			}
		);
		$activity = array_slice( $activity, 0, 150 );
		?>
		<div class="wrap">
			<h1>
				<?php
				$titles = array(
					'overview' => __( 'Sync Guard', 'avalanche-sync-guard' ),
					'activity' => __( 'Activity Log', 'avalanche-sync-guard' ),
					'settings' => __( 'Sync Guard Settings', 'avalanche-sync-guard' ),
				);
				echo esc_html( isset( $titles[ $screen ] ) ? $titles[ $screen ] : $titles['overview'] );
				?>
			</h1>

			<?php if ( isset( $_GET['asg_status'] ) && 'saved' === $_GET['asg_status'] ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'avalanche-sync-guard' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['asg_status'] ) && 'backfilled' === $_GET['asg_status'] ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						$count = isset( $_GET['asg_count'] ) ? (int) $_GET['asg_count'] : 0;
						printf(
							/* translators: %d: number of events */
							esc_html( _n( 'Reconstructed %d publish from existing content.', 'Reconstructed %d publishes from existing content.', $count, 'avalanche-sync-guard' ) ),
							$count
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( 'overview' === $screen ) : ?>
			<h2><?php esc_html_e( 'This environment', 'avalanche-sync-guard' ); ?></h2>
			<table class="widefat striped" style="max-width:860px">
				<tbody>
					<tr>
						<th style="width:240px"><?php esc_html_e( 'Running in', 'avalanche-sync-guard' ); ?></th>
						<td><strong><?php echo esc_html( $this->environment_label() ); ?></strong></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Database last stamped', 'avalanche-sync-guard' ); ?></th>
						<td><?php echo ! empty( $stamp['stamped_at'] ) ? esc_html( $this->local_datetime( (int) $stamp['stamped_at'] ) ) : esc_html__( 'never', 'avalanche-sync-guard' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Newest content edit', 'avalanche-sync-guard' ); ?></th>
						<td><?php echo esc_html( $this->newest_content_edit() ? $this->newest_content_edit() : '—' ); ?> <span class="description">GMT</span></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'File log', 'avalanche-sync-guard' ); ?></th>
						<td>
							<code><?php echo esc_html( $this->log_file_path() ); ?></code>
							<?php
							$path = $this->log_file_path();
							if ( $path && is_readable( $path ) ) :
								$download = wp_nonce_url(
									admin_url( 'admin-post.php?action=asg_download' ),
									'asg_download'
								);
								?>
								<a class="button button-secondary" style="margin-left:10px" href="<?php echo esc_url( $download ); ?>"><?php esc_html_e( 'Download', 'avalanche-sync-guard' ); ?></a>
								<span class="description"><?php echo esc_html( size_format( filesize( $path ) ) ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<?php endif; ?>

			<?php if ( 'settings' === $screen ) : ?>
			<h2><?php esc_html_e( 'Work-in-progress lock', 'avalanche-sync-guard' ); ?></h2>
			<p class="description" style="max-width:860px">
				<?php esc_html_e( 'Turn this on while you are rebuilding the site locally. It warns anyone in this admin that their edits are about to be overwritten. Turn it on in the environment you want the warning to appear in — usually production.', 'avalanche-sync-guard' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="asg_save" />
				<?php wp_nonce_field( 'asg_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Lock', 'avalanche-sync-guard' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="lock_enabled" value="1" <?php checked( ! empty( $settings['lock_enabled'] ) ); ?> />
								<?php esc_html_e( 'Show the "under construction" notice on this site', 'avalanche-sync-guard' ); ?>
							</label>
							<?php if ( ! empty( $settings['lock_enabled'] ) && ! empty( $settings['locked_at'] ) ) : ?>
								<p class="description">
									<?php
									printf(
										/* translators: 1: user login, 2: date */
										esc_html__( 'Locked by %1$s on %2$s.', 'avalanche-sync-guard' ),
										esc_html( $settings['locked_by'] ? $settings['locked_by'] : 'unknown' ),
										esc_html( $this->local_datetime( (int) $settings['locked_at'] ) )
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asg_lock_message"><?php esc_html_e( 'Notice text', 'avalanche-sync-guard' ); ?></label></th>
						<td><textarea id="asg_lock_message" name="lock_message" rows="3" class="large-text"><?php echo esc_textarea( $settings['lock_message'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Who sees it', 'avalanche-sync-guard' ); ?></th>
						<td>
							<fieldset>
								<label style="display:block;margin-bottom:6px">
									<input type="radio" name="lock_who" value="admin" <?php checked( $settings['lock_who'], 'admin' ); ?> />
									<?php esc_html_e( 'Admin screens only', 'avalanche-sync-guard' ); ?>
									<span class="description">— <?php esc_html_e( 'recommended; nothing is visible to the public', 'avalanche-sync-guard' ); ?></span>
								</label>
								<label style="display:block;margin-bottom:6px">
									<input type="radio" name="lock_who" value="logged_in" <?php checked( $settings['lock_who'], 'logged_in' ); ?> />
									<?php esc_html_e( 'Admin screens + a front-end banner for logged-in users', 'avalanche-sync-guard' ); ?>
								</label>
								<label style="display:block">
									<input type="radio" name="lock_who" value="everyone" <?php checked( $settings['lock_who'], 'everyone' ); ?> />
									<?php esc_html_e( 'Admin screens + a front-end banner for every visitor', 'avalanche-sync-guard' ); ?>
									<span class="description">— <?php esc_html_e( 'the public will see this on a live site', 'avalanche-sync-guard' ); ?></span>
								</label>
							</fieldset>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save changes', 'avalanche-sync-guard' ) ); ?>
			</form>

			<?php endif; ?>

			<?php if ( 'overview' === $screen ) : ?>
			<h2><?php esc_html_e( 'Sync history', 'avalanche-sync-guard' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Every time this database moved between environments.', 'avalanche-sync-guard' ); ?></p>
			<?php if ( empty( $syncs ) ) : ?>
				<p><?php esc_html_e( 'No sync events recorded yet.', 'avalanche-sync-guard' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width:170px"><?php esc_html_e( 'When', 'avalanche-sync-guard' ); ?></th>
							<th style="width:110px"><?php esc_html_e( 'Event', 'avalanche-sync-guard' ); ?></th>
							<th><?php esc_html_e( 'From', 'avalanche-sync-guard' ); ?></th>
							<th><?php esc_html_e( 'To', 'avalanche-sync-guard' ); ?></th>
							<th><?php esc_html_e( 'Detail', 'avalanche-sync-guard' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $syncs as $event ) : ?>
						<tr>
							<td><?php echo esc_html( $this->local_datetime( isset( $event['ts'] ) ? (int) $event['ts'] : 0 ) ); ?></td>
							<td><?php echo wp_kses_post( $this->badge( isset( $event['type'] ) ? $event['type'] : '' ) ); ?></td>
							<td><?php echo esc_html( ! empty( $event['from'] ) ? $this->environment_label( $event['from'] ) : '—' ); ?></td>
							<td><?php echo esc_html( ! empty( $event['to'] ) ? $this->environment_label( $event['to'] ) : '—' ); ?></td>
							<td>
								<?php echo esc_html( isset( $event['label'] ) ? $event['label'] : '' ); ?>
								<?php if ( isset( $event['arriving_db_age_days'] ) && null !== $event['arriving_db_age_days'] ) : ?>
									<br /><span class="description">
										<?php
										printf(
											/* translators: %s: number of days */
											esc_html__( 'The arriving database had been sitting in its previous environment for %s days.', 'avalanche-sync-guard' ),
											esc_html( $event['arriving_db_age_days'] )
										);
										?>
									</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php endif; ?>

			<?php if ( 'activity' === $screen ) : ?>
			<h2 style="margin-top:2em"><?php esc_html_e( 'Activity log', 'avalanche-sync-guard' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Content and structural changes made in this environment. Compare against another environment\'s log to see where the two diverged.', 'avalanche-sync-guard' ); ?></p>

			<?php
			$backfilled_at = (int) get_option( 'asg_backfilled_at', 0 );
			$backfill_url  = wp_nonce_url( admin_url( 'admin-post.php?action=asg_backfill' ), 'asg_backfill' );
			?>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( $backfill_url ); ?>"><?php esc_html_e( 'Backfill from existing content', 'avalanche-sync-guard' ); ?></a>
				<span class="description" style="margin-left:8px">
					<?php
					if ( $backfilled_at ) {
						printf(
							/* translators: %s: date */
							esc_html__( 'Last run %s. Re-running only adds items not already logged.', 'avalanche-sync-guard' ),
							esc_html( $this->local_datetime( $backfilled_at ) )
						);
					} else {
						esc_html_e( 'Reconstructs the 100 most recent publishes from their post dates, so the log is not empty on a site that pre-dates this plugin.', 'avalanche-sync-guard' );
					}
					?>
				</span>
			</p>

			<ul class="subsubsub">
				<?php
				$tabs = array(
					'all'       => __( 'All', 'avalanche-sync-guard' ),
					'content'   => __( 'Content', 'avalanche-sync-guard' ),
					'structure' => __( 'Structure', 'avalanche-sync-guard' ),
					'lock'      => __( 'Lock', 'avalanche-sync-guard' ),
				);
				$last = array_key_last( $tabs );
				foreach ( $tabs as $key => $tab_label ) :
					$url = $this->page_url( self::PAGE_ACTIVITY, array( 'asg_kind' => $key ) );
					?>
					<li>
						<a href="<?php echo esc_url( $url ); ?>" <?php echo ( $filter === $key ) ? 'class="current"' : ''; ?>><?php echo esc_html( $tab_label ); ?></a><?php echo ( $key !== $last ) ? ' |' : ''; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<div style="clear:both"></div>

			<?php if ( empty( $activity ) ) : ?>
				<p><?php esc_html_e( 'Nothing recorded yet.', 'avalanche-sync-guard' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width:170px"><?php esc_html_e( 'When', 'avalanche-sync-guard' ); ?></th>
							<th style="width:130px"><?php esc_html_e( 'Type', 'avalanche-sync-guard' ); ?></th>
							<th><?php esc_html_e( 'What happened', 'avalanche-sync-guard' ); ?></th>
							<th style="width:140px"><?php esc_html_e( 'By', 'avalanche-sync-guard' ); ?></th>
							<th style="width:150px"><?php esc_html_e( 'Where', 'avalanche-sync-guard' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $activity as $event ) : ?>
						<tr>
							<td><?php echo esc_html( $this->local_datetime( isset( $event['ts'] ) ? (int) $event['ts'] : 0 ) ); ?></td>
							<td><?php echo wp_kses_post( $this->badge( isset( $event['type'] ) ? $event['type'] : '' ) ); ?></td>
							<td>
								<?php
								$text = isset( $event['label'] ) ? $event['label'] : '';
								if ( ! empty( $event['link'] ) ) {
									printf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $event['link'] ), esc_html( $text ) );
								} else {
									echo esc_html( $text );
								}
								?>
							</td>
							<td>
								<?php echo esc_html( ! empty( $event['user'] ) ? $event['user'] : '—' ); ?>
								<?php if ( ! empty( $event['backfilled'] ) ) : ?>
									<br /><span class="description" title="<?php esc_attr_e( 'Reconstructed from the post date, not observed as it happened.', 'avalanche-sync-guard' ); ?>"><?php esc_html_e( '(backfilled)', 'avalanche-sync-guard' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( ! empty( $event['env'] ) ? $this->environment_label( $event['env'] ) : '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function badge( $type ) {
		$map = array(
			// Sync.
			'push'               => array( '#b32d2e', __( 'PUSH', 'avalanche-sync-guard' ) ),
			'pull'               => array( '#2271b1', __( 'PULL', 'avalanche-sync-guard' ) ),
			'move'               => array( '#8a6d1f', __( 'MOVE', 'avalanche-sync-guard' ) ),
			'baseline'           => array( '#646970', __( 'START', 'avalanche-sync-guard' ) ),
			// Content.
			'published'          => array( '#2a7d2e', __( 'PUBLISHED', 'avalanche-sync-guard' ) ),
			'created'            => array( '#557', __( 'CREATED', 'avalanche-sync-guard' ) ),
			'unpublished'        => array( '#8a6d1f', __( 'UNPUBLISHED', 'avalanche-sync-guard' ) ),
			'trashed'            => array( '#b32d2e', __( 'TRASHED', 'avalanche-sync-guard' ) ),
			'restored'           => array( '#2271b1', __( 'RESTORED', 'avalanche-sync-guard' ) ),
			'deleted'            => array( '#8b0000', __( 'DELETED', 'avalanche-sync-guard' ) ),
			'bb_layout_saved'    => array( '#2a7d2e', __( 'BUILT', 'avalanche-sync-guard' ) ),
			// Structure.
			'registry_added'     => array( '#6c2eb9', __( 'NEW TYPE', 'avalanche-sync-guard' ) ),
			'registry_removed'   => array( '#8a6d1f', __( 'TYPE GONE', 'avalanche-sync-guard' ) ),
			'theme_switched'     => array( '#6c2eb9', __( 'THEME', 'avalanche-sync-guard' ) ),
			'plugin_activated'   => array( '#2a7d2e', __( 'PLUGIN ON', 'avalanche-sync-guard' ) ),
			'plugin_deactivated' => array( '#8a6d1f', __( 'PLUGIN OFF', 'avalanche-sync-guard' ) ),
			'upgrade'            => array( '#2271b1', __( 'UPGRADE', 'avalanche-sync-guard' ) ),
			'option_changed'     => array( '#b32d2e', __( 'SETTING', 'avalanche-sync-guard' ) ),
			'menu_updated'       => array( '#557', __( 'MENU', 'avalanche-sync-guard' ) ),
			'user_created'       => array( '#6c2eb9', __( 'NEW USER', 'avalanche-sync-guard' ) ),
			'role_changed'       => array( '#b32d2e', __( 'ROLE', 'avalanche-sync-guard' ) ),
			// Lock.
			'lock_on'            => array( '#8a6d1f', __( 'LOCK ON', 'avalanche-sync-guard' ) ),
			'lock_off'           => array( '#646970', __( 'LOCK OFF', 'avalanche-sync-guard' ) ),
		);
		$item = isset( $map[ $type ] ) ? $map[ $type ] : array( '#646970', strtoupper( str_replace( '_', ' ', $type ) ) );
		return sprintf(
			'<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:%s;color:#fff;font-size:11px;font-weight:600;letter-spacing:.04em;white-space:nowrap">%s</span>',
			esc_attr( $item[0] ),
			esc_html( $item[1] )
		);
	}

	/**
	 * Formats a logged time in the site's own timezone.
	 *
	 * The timezone abbreviation is deliberately included. A sync log is read to
	 * work out what happened when, and a WordPress install whose timezone was
	 * never set reports UTC — which on this site is four hours ahead of the
	 * people reading it. Printing "7:34 pm UTC" rather than a bare "7:34 pm"
	 * makes that visible instead of quietly wrong.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private function local_datetime( $timestamp ) {
		if ( ! $timestamp ) {
			return '—';
		}
		return wp_date( 'M j, Y g:i a T', $timestamp );
	}

	/* =====================================================================
	 * Notices
	 * ================================================================== */

	public function render_admin_notices() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$settings = $this->settings();

		if ( ! empty( $settings['lock_enabled'] ) ) {
			printf(
				'<div class="notice notice-warning" style="border-left-width:4px"><p style="font-size:14px;margin:.6em 0"><strong>%s</strong> %s</p></div>',
				esc_html__( 'Site under construction:', 'avalanche-sync-guard' ),
				wp_kses_post( $settings['lock_message'] )
			);
		}

		if ( $this->is_hosted() && current_user_can( 'manage_options' ) ) {
			$push = $this->last_event( 'push' );
			if ( $push && ! empty( $push['ts'] ) && ( time() - (int) $push['ts'] ) < 30 * DAY_IN_SECONDS ) {
				printf(
					'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
					esc_html__( 'Sync Guard:', 'avalanche-sync-guard' ),
					sprintf(
						/* translators: %s: date of the push */
						esc_html__( 'a local database was pushed over this site on %s. Content edited here before that time may have been lost.', 'avalanche-sync-guard' ),
						esc_html( $this->local_datetime( (int) $push['ts'] ) )
					)
				);
			}
		}
	}

	public function frontend_banner_styles() {
		if ( ! $this->should_show_frontend_banner() ) {
			return;
		}
		echo '<style id="asg-banner-css">.asg-banner{position:relative;z-index:99999;background:#8a6d1f;color:#fff;padding:12px 18px;text-align:center;font-size:15px;line-height:1.45;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}.asg-banner strong{font-weight:700}</style>' . "\n";
	}

	public function render_frontend_banner() {
		if ( ! $this->should_show_frontend_banner() ) {
			return;
		}
		$settings = $this->settings();
		printf(
			'<div class="asg-banner" role="status"><strong>%s</strong> %s</div>',
			esc_html__( 'Site under construction:', 'avalanche-sync-guard' ),
			wp_kses_post( $settings['lock_message'] )
		);
	}

	private function should_show_frontend_banner() {
		if ( is_admin() ) {
			return false;
		}
		$settings = $this->settings();
		if ( empty( $settings['lock_enabled'] ) ) {
			return false;
		}
		if ( 'everyone' === $settings['lock_who'] ) {
			return true;
		}
		if ( 'logged_in' === $settings['lock_who'] && is_user_logged_in() ) {
			return true;
		}
		return false;
	}
}

endif;

Avalanche_Sync_Guard::instance();

/**
 * Self-updating from GitHub releases.
 *
 * One repository feeds every site: tag a release and each install offers the
 * update in Plugins, the same as any plugin from wordpress.org. This is what
 * makes a single edit reach all sixteen sites, including production, where a
 * symlink into a shared folder could never work.
 */
require_once __DIR__ . '/inc/class-asg-updater.php';

$asg_updater = new ASG_Updater(
	__FILE__,
	'AvalancheCreativeGR/avalanche-sync-guard',
	Avalanche_Sync_Guard::VERSION
);

// "Check for updates" link on the plugin row.
add_action(
	'admin_init',
	function () use ( $asg_updater ) {
		if ( empty( $_GET['asg_check_updates'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		check_admin_referer( 'asg_check_updates' );
		$asg_updater->flush();
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}
);

/**
 * On activation take a baseline reading so the first real sync is detected
 * correctly, and record the current post types so existing ones are not
 * reported as newly created.
 */
register_activation_hook(
	__FILE__,
	function () {
		$guard = Avalanche_Sync_Guard::instance();
		$guard->detect_sync();
		$guard->detect_registry_changes();
	}
);
