<?php
/**
 * Self-update from GitHub releases.
 *
 * This is how one edit reaches all sixteen sites. The plugin lives in a single
 * GitHub repository; tagging a release there makes every install — Local and
 * production alike — show "Update available" in Plugins and update with one
 * click, exactly like a plugin from wordpress.org.
 *
 * Deliberately hand-rolled rather than vendoring a library: the whole mechanism
 * is three WordPress filters, and keeping it to one readable file matches the
 * rest of this plugin. The one genuinely fiddly part is handled below — GitHub
 * zipballs extract to `repo-tagname/`, which WordPress would happily install as
 * a second, differently-named plugin unless the folder is renamed mid-upgrade.
 *
 * @package avalanche-sync-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASG_Updater {

	/** How long to cache the release lookup. GitHub allows 60 unauthenticated calls an hour per IP. */
	const CACHE_HOURS = 6;

	/** @var string Plugin basename, e.g. avalanche-sync-guard/avalanche-sync-guard.php */
	private $basename;

	/** @var string Folder name, e.g. avalanche-sync-guard */
	private $slug;

	/** @var string owner/repo */
	private $repo;

	/** @var string Currently installed version. */
	private $version;

	/**
	 * @param string $file    __FILE__ of the main plugin file.
	 * @param string $repo    GitHub "owner/repo".
	 * @param string $version Installed version.
	 */
	public function __construct( $file, $repo, $version ) {
		$this->basename = plugin_basename( $file );
		$this->slug     = dirname( $this->basename );
		$this->repo     = $repo;
		$this->version  = $version;

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_folder' ), 10, 4 );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
	}

	/**
	 * Latest published release, or null.
	 *
	 * @param bool $force Skip the cache.
	 * @return array|null
	 */
	private function latest_release( $force = false ) {
		$key = 'asg_gh_release_' . md5( $this->repo );

		if ( ! $force ) {
			$cached = get_site_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
			// A previous failure is cached as a scalar so a rate-limited or
			// offline site does not retry on every admin page load.
			if ( false !== $cached ) {
				return null;
			}
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $this->repo . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'avalanche-sync-guard',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( $key, 'error', HOUR_IN_SECONDS );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_site_transient( $key, 'error', HOUR_IN_SECONDS );
			return null;
		}

		$release = array(
			'version'   => ltrim( $body['tag_name'], 'vV' ),
			'zip'       => ! empty( $body['zipball_url'] ) ? $body['zipball_url'] : '',
			'notes'     => ! empty( $body['body'] ) ? $body['body'] : '',
			'published' => ! empty( $body['published_at'] ) ? $body['published_at'] : '',
			'url'       => ! empty( $body['html_url'] ) ? $body['html_url'] : '',
		);

		// A release asset (a proper plugin zip) is preferred over the zipball,
		// because it extracts with the right folder name already.
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( ! empty( $asset['browser_download_url'] ) && substr( $asset['browser_download_url'], -4 ) === '.zip' ) {
					$release['zip'] = $asset['browser_download_url'];
					break;
				}
			}
		}

		set_site_transient( $key, $release, self::CACHE_HOURS * HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * Tells WordPress an update is waiting.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		// A development install symlinked to the shared clone must never be
		// "updated": WordPress would delete the symlink, write real files in its
		// place, and quietly detach the site from the repo it is meant to track.
		// Those sites are already running the newest code by definition.
		if ( $this->is_symlinked() ) {
			return $transient;
		}

		$release = $this->latest_release();
		if ( ! $release || empty( $release['zip'] ) ) {
			return $transient;
		}

		if ( ! version_compare( $release['version'], $this->version, '>' ) ) {
			// Up to date. Recording it keeps the "last checked" note honest.
			if ( isset( $transient->no_update ) ) {
				$transient->no_update[ $this->basename ] = (object) array(
					'slug'        => $this->slug,
					'plugin'      => $this->basename,
					'new_version' => $this->version,
					'url'         => $release['url'],
					'package'     => '',
				);
			}
			return $transient;
		}

		$transient->response[ $this->basename ] = (object) array(
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['zip'],
			'tested'      => get_bloginfo( 'version' ),
		);

		return $transient;
	}

	/**
	 * Fills the "View details" panel.
	 *
	 * @param mixed  $result Existing result.
	 * @param string $action API action.
	 * @param object $args   Request args.
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Avalanche Sync Guard',
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => 'Avalanche Creative',
			'homepage'      => $release['url'],
			'download_link' => $release['zip'],
			'last_updated'  => $release['published'],
			'sections'      => array(
				'description' => 'Records database moves between environments, logs content and structural changes, and can lock the site while it is being rebuilt.',
				'changelog'   => $release['notes'] ? nl2br( esc_html( $release['notes'] ) ) : 'See the release on GitHub.',
			),
		);
	}

	/**
	 * Renames the extracted folder during an upgrade.
	 *
	 * GitHub's zipball extracts to `owner-repo-<sha>/`. Without this, WordPress
	 * installs that as a brand new plugin sitting alongside the old one, and the
	 * update silently appears to do nothing. A release asset built as a proper
	 * plugin zip already has the right name, in which case this is a no-op.
	 *
	 * @param string $source        Extracted folder.
	 * @param string $remote_source Parent temp folder.
	 * @param object $upgrader      Upgrader instance.
	 * @param array  $args          Upgrade args.
	 * @return string|WP_Error
	 */
	public function fix_source_folder( $source, $remote_source, $upgrader, $args = array() ) {
		global $wp_filesystem;

		if ( empty( $args['plugin'] ) || $args['plugin'] !== $this->basename ) {
			return $source;
		}
		if ( ! $wp_filesystem || basename( $source ) === $this->slug ) {
			return $source;
		}

		$corrected = trailingslashit( $remote_source ) . $this->slug;

		if ( $wp_filesystem->exists( $corrected ) ) {
			$wp_filesystem->delete( $corrected, true );
		}

		if ( ! $wp_filesystem->move( $source, $corrected ) ) {
			return new WP_Error(
				'asg_rename_failed',
				sprintf(
					/* translators: 1: source folder, 2: target folder */
					__( 'Sync Guard could not rename the downloaded folder from %1$s to %2$s.', 'avalanche-sync-guard' ),
					basename( $source ),
					$this->slug
				)
			);
		}

		return trailingslashit( $corrected );
	}

	/**
	 * Adds a "check for updates now" link to the plugin row.
	 *
	 * @param array  $links Row meta.
	 * @param string $file  Plugin file.
	 * @return array
	 */
	public function row_meta( $links, $file ) {
		if ( $file !== $this->basename || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}

		if ( $this->is_symlinked() ) {
			$links[] = '<span style="color:#646970">'
				. esc_html__( 'Symlinked to the shared repo — updates are managed in git, not here.', 'avalanche-sync-guard' )
				. '</span>';
			return $links;
		}

		$url = wp_nonce_url(
			add_query_arg( 'asg_check_updates', '1', admin_url( 'plugins.php' ) ),
			'asg_check_updates'
		);

		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'avalanche-sync-guard' ) . '</a>';
		return $links;
	}

	/**
	 * Is this install a symlink into the shared clone rather than a real copy?
	 *
	 * @return bool
	 */
	private function is_symlinked() {
		return is_link( untrailingslashit( WP_PLUGIN_DIR ) . '/' . $this->slug );
	}

	/** Clears the cached lookup so the next page load re-checks GitHub. */
	public function flush() {
		delete_site_transient( 'asg_gh_release_' . md5( $this->repo ) );
		delete_site_transient( 'update_plugins' );
	}
}
