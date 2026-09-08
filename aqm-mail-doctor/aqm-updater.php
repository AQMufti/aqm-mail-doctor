<?php
/**
 * AQM_Updater - GitHub-release self-update for AQM plugins.
 *
 * Drop this file into a plugin folder and instantiate it from the main file:
 *
 *     require_once __DIR__ . '/aqm-updater.php';
 *     new AQM_Updater(
 *         __FILE__,                       // plugin file
 *         AQM_XX_VERSION,                 // installed version
 *         'AQMufti/aqm-xx',               // GitHub owner/repo
 *         'AQM Xx',                       // display name
 *         'One-line description.'         // shown on the details screen
 *     );
 *
 * Gives every AQM plugin the same behaviour as AQM Contact Form:
 *   - update notice on the Plugins screen when a newer release exists
 *   - "Enable auto-updates" support
 *   - a "Check for updates" link in the plugin's row, which clears the cache
 *     and re-reads GitHub immediately instead of waiting up to 12 hours
 *   - a View details screen carrying the release notes
 *
 * Each instance namespaces its own transient, admin-post action and nonce by
 * plugin slug, so any number of AQM plugins can run this side by side.
 *
 * @package AQM
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'AQM_Updater' ) ) {

	class AQM_Updater {

		private $file;
		private $version;
		private $repo;
		private $name;
		private $description;
		private $basename;
		private $slug;
		private $transient;
		private $action;

		public function __construct( $file, $version, $repo, $name, $description = '' ) {
			$this->file        = $file;
			$this->version     = $version;
			$this->repo        = $repo;
			$this->name        = $name;
			$this->description = $description;
			$this->basename    = plugin_basename( $file );
			$this->slug        = dirname( $this->basename );
			$this->transient   = 'aqm_upd_' . md5( $this->repo );
			$this->action      = 'aqm_check_update_' . substr( md5( $this->slug ), 0, 8 );

			add_filter( 'site_transient_update_plugins', array( $this, 'inject_update' ) );
			add_filter( 'plugins_api', array( $this, 'plugin_details' ), 20, 3 );
			add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
			add_action( 'admin_post_' . $this->action, array( $this, 'handle_check' ) );
			add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 0 );
		}

		/**
		 * Remember why a check failed, and do NOT sulk for an hour over it.
		 *
		 * The old code cached an empty result for HOUR_IN_SECONDS on any failure,
		 * so one rate-limited request blocked checks for the next sixty minutes.
		 * Five minutes is long enough to stop hammering and short enough that
		 * pressing the button again is useful.
		 */
		private function fail( $why ) {
			update_option( $this->transient . '_err', $why, false );
			set_transient( $this->transient, array(), 5 * MINUTE_IN_SECONDS );
		}


		/**
		 * Read the newest release tag from the public releases feed.
		 *
		 * github.com/<owner>/<repo>/releases.atom needs no authentication and is
		 * not counted against the API rate limit. Each entry links to
		 * .../releases/tag/<tag>, newest first, so the first match is the latest
		 * release. Pre-releases appear here too - we have never published one,
		 * and if that changes this needs revisiting.
		 */
		private function release_from_atom() {

			$response = wp_remote_get(
				'https://github.com/' . $this->repo . '/releases.atom',
				array(
					'timeout' => 10,
					'headers' => array( 'User-Agent' => $this->slug . '/' . $this->version ),
				)
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}

			$body = (string) wp_remote_retrieve_body( $response );

			if ( ! preg_match( '#/releases/tag/([^"\'<>\s]+)#', $body, $m ) ) {
				return null;
			}

			$tag = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );

			return array(
				'version'   => ltrim( $tag, 'vV' ),
				// release.ps1 always attaches the asset as <slug>.zip, so this
				// url is deterministic. Verified working while the API was 403.
				'package'   => 'https://github.com/' . $this->repo . '/releases/download/' . $tag . '/' . $this->slug . '.zip',
				'url'       => 'https://github.com/' . $this->repo . '/releases/tag/' . $tag,
				'changelog' => 'Read the release notes on GitHub - the API was rate limited, so this came from the releases feed.',
				'published' => '',
			);
		}

		/** Read the latest GitHub release, cached for 12 hours. */
		private function release( $force = false ) {
			if ( ! $force ) {
				$cached = get_transient( $this->transient );
				if ( is_array( $cached ) ) {
					return empty( $cached['version'] ) ? null : $cached;
				}
			}

			$headers = array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => $this->slug . '/' . $this->version,
			);

			/*
			 * Unauthenticated GitHub API calls are limited to 60 per hour PER IP,
			 * and that IP is shared with every other site on this host. Thirteen
			 * AQM plugins each polling their own repo can exhaust it on their own.
			 * Define AQM_GITHUB_TOKEN in wp-config.php - a fine-grained token with
			 * NO scopes is enough for public repos - and the ceiling becomes 5000.
			 */
			if ( defined( 'AQM_GITHUB_TOKEN' ) && AQM_GITHUB_TOKEN ) {
				$headers['Authorization'] = 'Bearer ' . AQM_GITHUB_TOKEN;
			}

			$response = wp_remote_get(
				'https://api.github.com/repos/' . $this->repo . '/releases/latest',
				array( 'timeout' => 10, 'headers' => $headers )
			);

			/*
			 * Record WHY a check failed. Until 8 Sep 2026 every failure produced
			 * the same "could not reach GitHub" line, which is a symptom, not a
			 * cause - a 404, a rate-limit 403 and a DNS failure all looked
			 * identical. Keep the real answer and show it.
			 */
			if ( is_wp_error( $response ) ) {
				$this->fail( 'network error: ' . $response->get_error_message() );
				return null;
			}

			$code      = (int) wp_remote_retrieve_response_code( $response );
			$remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
			$reset     = wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );

			/*
			 * RATE LIMITED? USE THE ATOM FEED INSTEAD.
			 *
			 * Proved on aqmuftirealty.com, 8 Sep 2026: api.github.com returned
			 * 403 with x-ratelimit-remaining: 0 ("rate limit exceeded for
			 * 46.202.182.101") while github.com itself answered 200 in half a
			 * second. The 60/hour cap is per IP and shared with every other site
			 * on this host, and thirteen AQM plugins can exhaust it alone.
			 *
			 * github.com/<repo>/releases.atom is NOT the API and is not subject
			 * to that cap. It carries the newest release tag, which is all this
			 * updater actually needs - the download url is predictable because
			 * release.ps1 always names the asset <slug>.zip.
			 *
			 * So the API is preferred (richer data, real changelog) and the feed
			 * is the fallback. The practical effect is that updates keep working
			 * with no token, no wp-config edit and no secret to look after.
			 */
			if ( in_array( $code, array( 403, 429 ), true ) ) {
				$feed = $this->release_from_atom();
				if ( $feed ) {
					set_transient( $this->transient, $feed, 12 * HOUR_IN_SECONDS );
					delete_option( $this->transient . '_err' );
					return $feed;
				}
			}

			if ( 200 !== $code ) {
				$why = 'HTTP ' . $code;
				if ( 403 === $code || 429 === $code ) {
					$why .= ' - GitHub API rate limit';
					if ( '' !== (string) $remaining ) {
						$why .= ', ' . (int) $remaining . ' requests left';
					}
					if ( $reset ) {
						$why .= ', resets ' . gmdate( 'H:i', (int) $reset ) . ' UTC';
					}
					$why .= '. The releases-feed fallback also failed, which is unusual - github.com was reachable when this was last tested.';
				} elseif ( 404 === $code ) {
					$why .= ' - no release found, or the repository is private. The updater reads GitHub anonymously, so the repo must be public.';
				}
				$this->fail( $why );
				return null;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
				$this->fail( 'GitHub replied 200 but the response had no tag_name.' );
				return null;
			}

			delete_option( $this->transient . '_err' );

			// Prefer an attached .zip. GitHub's automatic zipball names its top
			// folder after the commit, which installs a duplicate rather than
			// updating the plugin in place.
			$package = '';
			$assets  = isset( $body['assets'] ) ? (array) $body['assets'] : array();
			foreach ( $assets as $asset ) {
				$asset_name = isset( $asset['name'] ) ? $asset['name'] : '';
				if ( ! empty( $asset['browser_download_url'] ) && '.zip' === substr( $asset_name, -4 ) ) {
					$package = $asset['browser_download_url'];
					break;
				}
			}
			if ( ! $package ) {
				$package = isset( $body['zipball_url'] ) ? $body['zipball_url'] : '';
			}

			$release = array(
				'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
				'package'   => $package,
				'url'       => isset( $body['html_url'] ) ? $body['html_url'] : 'https://github.com/' . $this->repo,
				'changelog' => isset( $body['body'] ) ? (string) $body['body'] : '',
				'published' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
			);

			set_transient( $this->transient, $release, 12 * HOUR_IN_SECONDS );
			return $release;
		}

		public function inject_update( $transient ) {
			if ( ! is_object( $transient ) ) {
				return $transient;
			}
			$release = $this->release();
			if ( ! $release || ! $release['package'] ) {
				return $transient;
			}

			$item = (object) array(
				'id'           => 'github.com/' . $this->repo,
				'slug'         => $this->slug,
				'plugin'       => $this->basename,
				'new_version'  => $release['version'],
				'url'          => $release['url'],
				'package'      => $release['package'],
				'requires'     => '5.8',
				'requires_php' => '7.4',
				'icons'        => array(),
				'banners'      => array(),
				'tested'       => get_bloginfo( 'version' ),
			);

			if ( version_compare( $release['version'], $this->version, '>' ) ) {
				$transient->response[ $this->basename ] = $item;
			} else {
				// Listing it here is what makes "Enable auto-updates" appear.
				$transient->no_update[ $this->basename ] = $item;
			}
			return $transient;
		}

		public function plugin_details( $result, $action, $args ) {
			if ( 'plugin_information' !== $action ) {
				return $result;
			}
			if ( empty( $args->slug ) || $args->slug !== $this->slug ) {
				return $result;
			}
			$release = $this->release();
			if ( ! $release ) {
				return $result;
			}

			return (object) array(
				'name'          => $this->name,
				'slug'          => $this->slug,
				'version'       => $release['version'],
				'author'        => '<a href="https://github.com/AQMufti">A. Q. Mufti</a>',
				'homepage'      => $release['url'],
				'download_link' => $release['package'],
				'requires'      => '5.8',
				'requires_php'  => '7.4',
				'last_updated'  => $release['published'],
				'sections'      => array(
					'description' => '<p>' . esc_html( $this->description ) . '</p>',
					'changelog'   => $release['changelog']
						? wpautop( wp_kses_post( $release['changelog'] ) )
						: '<p>No release notes were provided.</p>',
				),
			);
		}

		/** The "Check for updates" link in the plugin's row. */
		public function row_meta( $links, $file ) {
			if ( $file !== $this->basename || ! current_user_can( 'update_plugins' ) ) {
				return $links;
			}
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=' . $this->action ),
				$this->action
			);
			$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates' ) . '</a>';
			return $links;
		}

		public function handle_check() {
			if ( ! current_user_can( 'update_plugins' ) ) {
				wp_die( esc_html__( 'You are not allowed to do that.' ) );
			}
			check_admin_referer( $this->action );

			delete_transient( $this->transient );
			delete_site_transient( 'update_plugins' );
			$release = $this->release( true );

			$status = 'error';
			if ( $release ) {
				$status = version_compare( $release['version'], $this->version, '>' ) ? 'available' : 'current';
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'aqm_upd'      => $status,
						'aqm_upd_name' => rawurlencode( $this->name ),
						'aqm_upd_ver'  => $release ? rawurlencode( $release['version'] ) : '',
						'aqm_upd_why'  => $release ? '' : rawurlencode( (string) get_option( $this->transient . '_err', '' ) ),
					),
					admin_url( 'plugins.php' )
				)
			);
			exit;
		}

		public function clear_cache() {
			delete_transient( $this->transient );
		}
	}

	/** One shared notice for whichever plugin was just checked. */
	add_action(
		'admin_notices',
		function () {
			if ( empty( $_GET['aqm_upd'] ) ) {   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}
			$status = sanitize_key( wp_unslash( $_GET['aqm_upd'] ) );               // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$name   = isset( $_GET['aqm_upd_name'] ) ? sanitize_text_field( wp_unslash( $_GET['aqm_upd_name'] ) ) : 'The plugin'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ver    = isset( $_GET['aqm_upd_ver'] ) ? sanitize_text_field( wp_unslash( $_GET['aqm_upd_ver'] ) ) : '';             // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( 'available' === $status ) {
				$class = 'notice-warning';
				$text  = sprintf( '%s: version %s is available. Reload this page to install it.', $name, $ver );
			} elseif ( 'current' === $status ) {
				$class = 'notice-success';
				$text  = sprintf( '%s is up to date (version %s).', $name, $ver );
			} else {
				$class = 'notice-error';
				$why   = isset( $_GET['aqm_upd_why'] ) ? sanitize_text_field( wp_unslash( $_GET['aqm_upd_why'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$text  = sprintf( '%s: the update check failed. %s', $name, $why ? $why : 'No reason was recorded.' );
			}
			printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $text ) );
		}
	);
}
