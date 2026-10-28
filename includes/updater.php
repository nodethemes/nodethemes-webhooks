<?php
/**
 * Updates for the free plugin from nodethemes.com (no license needed).
 * New versions appear in Dashboard → Updates and Plugins like any other plugin,
 * and can be installed with one click or by WordPress auto-updates.
 *
 * @package NodeThemes_Webhooks
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'NTWH_Free_Updater' ) ) :

	/**
	 * Free updater.
	 */
	final class NTWH_Free_Updater {

		/** Server package slug. */
		const SLUG = 'nodethemes-webhooks-free';

		/** Cache key. */
		const CACHE = 'ntwh_free_update';

		/** @var string Plugin basename. */
		private $basename;

		/** @var string Installed version. */
		private $version;

		/** @var string Product page. */
		private $homepage;

		/** @var bool */
		private static $booted = false;

		/**
		 * Boot once.
		 *
		 * @param string $file     Main plugin file.
		 * @param string $version  Installed version.
		 * @param string $homepage Product page URL.
		 */
		public static function boot( $file, $version, $homepage ) {
			if ( self::$booted ) {
				return;
			}
			self::$booted = true;
			new self( $file, $version, $homepage );
		}

		/**
		 * Constructor.
		 *
		 * @param string $file     Main plugin file.
		 * @param string $version  Installed version.
		 * @param string $homepage Product page URL.
		 */
		private function __construct( $file, $version, $homepage ) {
			$this->basename = plugin_basename( $file );
			$this->version  = $version;
			$this->homepage = $homepage;
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject' ) );
			add_filter( 'plugins_api', array( $this, 'info' ), 20, 3 );
			add_filter( 'upgrader_pre_download', array( $this, 'pre_download' ), 10, 3 );
			add_action( 'upgrader_process_complete', array( $this, 'flush' ), 10, 0 );
		}

		/**
		 * Update server base URL (define NODETHEMES_UPDATE_SERVER to override, e.g. on a staging server).
		 *
		 * @return string
		 */
		private static function server() {
			return untrailingslashit( defined( 'NODETHEMES_UPDATE_SERVER' ) ? NODETHEMES_UPDATE_SERVER : 'https://nodethemes.com' );
		}

		/**
		 * Latest release info from the server (cached 12 hours, refreshed on "Check again").
		 *
		 * @param bool $force Ignore the cache.
		 * @return array|null
		 */
		private function remote( $force = false ) {
			static $mem = null;
			if ( null !== $mem && ! $force ) {
				return $mem ? $mem : null;
			}
			$cached = get_site_transient( self::CACHE );
			$valid = is_array( $cached ) && ( array() === $cached || ( ! empty( $cached['version'] ) && ! empty( $cached['url'] ) ) );
			if ( ! $force && $valid ) {
				$mem = $cached;
				return $cached ? $cached : null;
			}
			if ( ! $valid ) {
				$cached = null;
			}
			$res = wp_remote_get(
				add_query_arg(
					array(
						'slug'    => self::SLUG,
						'version' => $this->version,
					),
					self::server() . '/wp-json/nodethemes/v1/free/info'
				),
				array(
					'timeout' => 10,
					'headers' => array( 'Accept' => 'application/json' ),
				)
			);
			$data = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( ! is_array( $data ) || empty( $data['success'] ) || empty( $data['version'] ) || empty( $data['url'] ) ) {
				// Server down or no release: remember for 1 hour, keep what we had before.
				$keep = is_array( $cached ) ? $cached : array();
				set_site_transient( self::CACHE, $keep, HOUR_IN_SECONDS );
				$mem = $keep;
				return $keep ? $keep : null;
			}
			$info = array(
				'version'      => sanitize_text_field( $data['version'] ),
				'url'          => esc_url_raw( $data['url'] ),
				'sha256'       => isset( $data['sha256'] ) ? preg_replace( '/[^a-f0-9]/', '', strtolower( $data['sha256'] ) ) : '',
				'changelog'    => isset( $data['changelog'] ) ? (string) $data['changelog'] : '',
				'name'         => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '',
				'requires'     => isset( $data['requires'] ) ? sanitize_text_field( $data['requires'] ) : '',
				'requires_php' => isset( $data['requires_php'] ) ? sanitize_text_field( $data['requires_php'] ) : '',
				'tested'       => isset( $data['tested'] ) ? sanitize_text_field( $data['tested'] ) : '',
				'updated'      => isset( $data['updated'] ) ? sanitize_text_field( $data['updated'] ) : '',
			);
			set_site_transient( self::CACHE, $info, 12 * HOUR_IN_SECONDS );
			$mem = $info;
			return $info;
		}

		/**
		 * Add the update (or the "up to date" entry) to WordPress' plugin update list.
		 *
		 * @param object $transient Transient.
		 * @return object
		 */
		public function inject( $transient ) {
			if ( ! is_object( $transient ) ) {
				return $transient;
			}
			$forced = is_admin() && isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ); // phpcs:ignore WordPress.Security.NonceVerification
			$info   = $this->remote( $forced );
			$item   = array(
				'id'           => $this->basename,
				'slug'         => dirname( $this->basename ),
				'plugin'       => $this->basename,
				'new_version'  => $this->version,
				'url'          => $this->homepage,
				'package'      => '',
				'requires'     => $info ? $info['requires'] : '',
				'requires_php' => $info ? $info['requires_php'] : '',
				'tested'       => $info ? $info['tested'] : '',
			);
			if ( $info && version_compare( $info['version'], $this->version, '>' ) ) {
				$item['new_version'] = $info['version'];
				$item['package']     = $info['url'];
				if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
					$transient->response = array();
				}
				if ( isset( $transient->no_update ) && is_array( $transient->no_update ) ) {
					unset( $transient->no_update[ $this->basename ] );
				}
				$transient->response[ $this->basename ] = (object) $item;
			} else {
				if ( isset( $transient->response ) && is_array( $transient->response ) ) {
					unset( $transient->response[ $this->basename ] );
				}
				if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
					$transient->no_update = array();
				}
				$transient->no_update[ $this->basename ] = (object) $item;
			}
			return $transient;
		}

		/**
		 * "View details" popup.
		 *
		 * @param false|object|array $result Result.
		 * @param string             $action Action.
		 * @param object             $args   Args.
		 * @return false|object
		 */
		public function info( $result, $action, $args ) {
			if ( 'plugin_information' !== $action || ! isset( $args->slug ) || dirname( $this->basename ) !== $args->slug ) {
				return $result;
			}
			$info = $this->remote();
			$name = $info && '' !== $info['name'] ? $info['name'] : 'NodeThemes Webhooks';
			return (object) array(
				'name'          => $name,
				'slug'          => dirname( $this->basename ),
				'version'       => $info ? $info['version'] : $this->version,
				'author'        => '<a href="https://nodethemes.com/">NodeThemes</a>',
				'homepage'      => $this->homepage,
				'requires'      => $info ? $info['requires'] : '',
				'requires_php'  => $info ? $info['requires_php'] : '',
				'tested'        => $info ? $info['tested'] : '',
				'last_updated'  => $info ? $info['updated'] : '',
				'download_link' => $info ? $info['url'] : '',
				'sections'      => array(
					'description' => esc_html__( 'Reliable WooCommerce webhooks for n8n, Make, Zapier and any URL: background delivery, automatic retries, HMAC signatures, delivery log and one-click resend.', 'nodethemes-webhooks' ),
					'changelog'   => $info && '' !== trim( (string) $info['changelog'] ) ? nl2br( esc_html( $info['changelog'] ) ) : esc_html__( 'No changelog provided.', 'nodethemes-webhooks' ),
				),
			);
		}

		/**
		 * Download our package ourselves so its SHA-256 checksum can be verified before install.
		 *
		 * @param bool|string|WP_Error $reply    Reply.
		 * @param string               $package  Package URL.
		 * @param WP_Upgrader          $upgrader Upgrader.
		 * @return bool|string|WP_Error
		 */
		public function pre_download( $reply, $package, $upgrader ) {
			if ( false !== $reply ) {
				return $reply;
			}
			// Only our own package URL; read the stored release so the checksum matches what was offered.
			if ( 0 !== strpos( $package, self::server() . '/wp-json/nodethemes/v1/free/download' ) || false === strpos( $package, 'slug=' . self::SLUG ) ) {
				return $reply;
			}
			$info = get_site_transient( self::CACHE );
			if ( ! is_array( $info ) || empty( $info['url'] ) || $package !== $info['url'] ) {
				$info = $this->remote( true );
			}
			if ( ! $info || $package !== $info['url'] ) {
				return new WP_Error( 'ntwh_stale', esc_html__( 'This update is no longer available. Please check for updates again.', 'nodethemes-webhooks' ) );
			}
			if ( isset( $upgrader->skin ) && is_object( $upgrader->skin ) ) {
				$upgrader->skin->feedback( 'downloading_package', esc_url( $package ) );
			}
			$file = download_url( $package, 300 );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			if ( 64 !== strlen( $info['sha256'] ) || ! hash_equals( $info['sha256'], (string) hash_file( 'sha256', $file ) ) ) {
				wp_delete_file( $file );
				delete_site_transient( self::CACHE );
				return new WP_Error( 'ntwh_checksum', esc_html__( 'The downloaded update failed its integrity check. Please try again.', 'nodethemes-webhooks' ) );
			}
			return $file;
		}

		/**
		 * Forget the cached release after any update.
		 */
		public function flush() {
			delete_site_transient( self::CACHE );
		}
	}

endif;
