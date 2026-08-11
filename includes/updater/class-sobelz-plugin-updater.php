<?php
/**
 * Reusable updater for plugins hosted outside WordPress.org.
 *
 * @package SobelzPluginUpdater
 */

namespace Sobelz\PluginUpdater\V1;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\\Updater', false ) ) {
	/**
	 * Connects a plugin to a JSON update manifest.
	 *
	 * Keep the V1 namespace when copying this file to other plugins. A future
	 * incompatible implementation can live in a different versioned namespace.
	 */
	final class Updater {

		/** @var string */
		private $plugin_file;

		/** @var string */
		private $plugin_basename;

		/** @var string */
		private $slug;

		/** @var string */
		private $update_uri;

		/** @var string */
		private $manifest_url;

		/** @var string */
		private $hostname;

		/** @var int */
		private $cache_ttl;

		/** @var string */
		private $cache_key;

		/** @var array<string, string> */
		private $headers;

		/** @var array<string, mixed>|\WP_Error|null */
		private $manifest;

		/** @var bool */
		private $manifest_loaded = false;

		/**
		 * Register an updater instance.
		 *
		 * Required configuration keys:
		 * - plugin_file: Absolute path to the plugin bootstrap file.
		 * - update_uri:  Public plugin page used in the Update URI header.
		 *
		 * Optional keys:
		 * - slug:         Plugin directory slug.
		 * - manifest_url: Full URL to update.json.
		 * - cache_ttl:    Manifest cache lifetime in seconds (default: 6 hours).
		 * - headers:      Extra HTTP headers, for example for a private feed.
		 *
		 * @param array<string, mixed> $config Updater configuration.
		 * @return self|\WP_Error
		 */
		public static function register( array $config ) {
			$updater = new self( $config );

			if ( is_wp_error( $updater->manifest ) ) {
				return $updater->manifest;
			}

			$updater->register_hooks();

			return $updater;
		}

		/**
		 * Constructor.
		 *
		 * @param array<string, mixed> $config Updater configuration.
		 */
		private function __construct( array $config ) {
			if ( empty( $config['plugin_file'] ) || empty( $config['update_uri'] ) ) {
				$this->manifest        = new \WP_Error( 'sobelz_updater_invalid_config', 'plugin_file and update_uri are required.' );
				$this->manifest_loaded = true;
				return;
			}

			$this->plugin_file     = (string) $config['plugin_file'];
			$this->plugin_basename = plugin_basename( $this->plugin_file );
			$this->update_uri      = untrailingslashit( esc_url_raw( (string) $config['update_uri'] ) );
			$this->hostname        = (string) wp_parse_url( $this->update_uri, PHP_URL_HOST );

			$default_slug = dirname( $this->plugin_basename );
			if ( '.' === $default_slug ) {
				$default_slug = basename( $this->plugin_basename, '.php' );
			}

			$this->slug = sanitize_key( isset( $config['slug'] ) ? (string) $config['slug'] : $default_slug );

			$default_manifest_url = trailingslashit( $this->update_uri ) . 'update.json';
			$this->manifest_url    = esc_url_raw(
				isset( $config['manifest_url'] ) ? (string) $config['manifest_url'] : $default_manifest_url
			);

			$this->cache_ttl = isset( $config['cache_ttl'] ) ? absint( $config['cache_ttl'] ) : 6 * HOUR_IN_SECONDS;
			$this->headers   = isset( $config['headers'] ) && is_array( $config['headers'] ) ? $config['headers'] : array();
			$this->cache_key = 'sobelz_plugin_updater_' . md5( $this->manifest_url );

			if ( empty( $this->hostname ) || empty( $this->slug ) || empty( $this->manifest_url ) ) {
				$this->manifest        = new \WP_Error( 'sobelz_updater_invalid_config', 'The updater URLs or slug are invalid.' );
				$this->manifest_loaded = true;
			}
		}

		/**
		 * Register WordPress hooks.
		 *
		 * @return void
		 */
		private function register_hooks() {
			add_filter( 'update_plugins_' . $this->hostname, array( $this, 'filter_update' ), 10, 4 );
			add_filter( 'plugins_api', array( $this, 'filter_plugin_information' ), 20, 3 );
			add_action( 'delete_site_transient_update_plugins', array( $this, 'clear_cache' ) );
			add_action( 'upgrader_process_complete', array( $this, 'clear_cache_after_update' ), 10, 2 );
		}

		/**
		 * Supply update data to WordPress core.
		 *
		 * @param array<string, mixed>|false $update      Existing response.
		 * @param array<string, mixed>       $plugin_data Plugin headers.
		 * @param string                     $plugin_file Plugin basename.
		 * @param array<int, string>         $locales     Requested locales.
		 * @return array<string, mixed>|false
		 */
		public function filter_update( $update, $plugin_data, $plugin_file, $locales ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			if ( $this->plugin_basename !== $plugin_file ) {
				return $update;
			}

			$manifest = $this->get_manifest();
			if ( is_wp_error( $manifest ) ) {
				return $update;
			}

			$response = array(
				'id'          => $this->update_uri,
				'slug'        => $this->slug,
				'plugin'      => $this->plugin_basename,
				'version'     => $manifest['version'],
				'new_version' => $manifest['version'],
				'url'         => $manifest['homepage'],
				'package'     => $manifest['download_url'],
			);

			foreach ( array( 'requires', 'requires_php', 'tested', 'icons', 'banners', 'banners_rtl', 'translations' ) as $field ) {
				if ( ! empty( $manifest[ $field ] ) ) {
					$response[ $field ] = $manifest[ $field ];
				}
			}

			if ( ! empty( $manifest['upgrade_notice'] ) ) {
				$response['upgrade_notice'] = $manifest['upgrade_notice'];
			}

			return $response;
		}

		/**
		 * Supply the modal shown by the "View details" link.
		 *
		 * @param false|object|array<string, mixed> $result Existing API result.
		 * @param string                           $action Requested action.
		 * @param object                           $args   API arguments.
		 * @return false|object|array<string, mixed>|\WP_Error
		 */
		public function filter_plugin_information( $result, $action, $args ) {
			if ( 'plugin_information' !== $action || empty( $args->slug ) || $this->slug !== $args->slug ) {
				return $result;
			}

			if ( false !== $result ) {
				return $result;
			}

			$manifest = $this->get_manifest();
			if ( is_wp_error( $manifest ) ) {
				return $manifest;
			}

			$information = (object) array(
				'name'          => $manifest['name'],
				'slug'          => $this->slug,
				'version'       => $manifest['version'],
				'homepage'      => $manifest['homepage'],
				'download_link' => $manifest['download_url'],
				'sections'      => $manifest['sections'],
				'external'      => true,
			);

			foreach ( array( 'author', 'author_homepage', 'requires', 'tested', 'requires_php', 'last_updated', 'icons', 'banners' ) as $field ) {
				if ( ! empty( $manifest[ $field ] ) ) {
					$property                 = 'author_homepage' === $field ? 'author_profile' : $field;
					$information->{$property} = $manifest[ $field ];
				}
			}

			return $information;
		}

		/**
		 * Clear cached metadata when WordPress performs a forced update check.
		 *
		 * @return void
		 */
		public function clear_cache() {
			$this->manifest        = null;
			$this->manifest_loaded = false;
			delete_site_transient( $this->cache_key );
		}

		/**
		 * Clear only this plugin's cache after an update finishes.
		 *
		 * @param \WP_Upgrader         $upgrader   Upgrader instance.
		 * @param array<string, mixed> $hook_extra Update context.
		 * @return void
		 */
		public function clear_cache_after_update( $upgrader, $hook_extra ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			if ( 'plugin' !== ( $hook_extra['type'] ?? '' ) || 'update' !== ( $hook_extra['action'] ?? '' ) ) {
				return;
			}

			$plugins = array();
			if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
				$plugins = $hook_extra['plugins'];
			} elseif ( ! empty( $hook_extra['plugin'] ) ) {
				$plugins = array( $hook_extra['plugin'] );
			}

			if ( in_array( $this->plugin_basename, $plugins, true ) ) {
				$this->clear_cache();
			}
		}

		/**
		 * Read, fetch, validate, and cache the manifest.
		 *
		 * @return array<string, mixed>|\WP_Error
		 */
		private function get_manifest() {
			if ( $this->manifest_loaded ) {
				return $this->manifest;
			}

			$this->manifest_loaded = true;

			if ( $this->cache_ttl > 0 ) {
				$cached = get_site_transient( $this->cache_key );
				if ( is_array( $cached ) ) {
					$this->manifest = $cached;
					return $this->manifest;
				}
			}

			$request_args = array(
				'timeout'             => 10,
				'redirection'         => 3,
				'sslverify'           => true,
				'limit_response_size' => 1024 * 1024,
				'headers'             => $this->headers,
				'user-agent'          => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
			);

			/**
			 * Filters HTTP arguments used to fetch an updater manifest.
			 *
			 * @param array<string, mixed> $request_args    WordPress HTTP arguments.
			 * @param string               $manifest_url   Manifest URL.
			 * @param string               $plugin_basename Plugin basename.
			 */
			$request_args = apply_filters(
				'sobelz_plugin_updater_request_args',
				$request_args,
				$this->manifest_url,
				$this->plugin_basename
			);
			if ( ! is_array( $request_args ) ) {
				$this->manifest = new \WP_Error( 'sobelz_updater_invalid_request_args', 'The updater request arguments must be an array.' );
				return $this->manifest;
			}

			$response = wp_remote_get( $this->manifest_url, $request_args );
			if ( is_wp_error( $response ) ) {
				$this->manifest = $response;
				return $this->manifest;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $status_code ) {
				$this->manifest = new \WP_Error(
					'sobelz_updater_http_error',
					sprintf( 'The update server returned HTTP %d.', $status_code )
				);
				return $this->manifest;
			}

			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $decoded ) ) {
				$this->manifest = new \WP_Error( 'sobelz_updater_invalid_json', 'The update manifest is not valid JSON.' );
				return $this->manifest;
			}

			/**
			 * Filters raw manifest data before validation.
			 *
			 * @param array<string, mixed> $decoded         Manifest data.
			 * @param string               $manifest_url    Manifest URL.
			 * @param string               $plugin_basename Plugin basename.
			 */
			$decoded = apply_filters(
				'sobelz_plugin_updater_manifest',
				$decoded,
				$this->manifest_url,
				$this->plugin_basename
			);
			if ( ! is_array( $decoded ) ) {
				$this->manifest = new \WP_Error( 'sobelz_updater_invalid_manifest', 'The filtered update manifest must be an array.' );
				return $this->manifest;
			}

			$this->manifest = $this->normalize_manifest( $decoded );

			if ( ! is_wp_error( $this->manifest ) && $this->cache_ttl > 0 ) {
				set_site_transient( $this->cache_key, $this->manifest, $this->cache_ttl );
			}

			return $this->manifest;
		}

		/**
		 * Validate and normalize manifest data.
		 *
		 * @param array<string, mixed> $manifest Raw manifest data.
		 * @return array<string, mixed>|\WP_Error
		 */
		private function normalize_manifest( array $manifest ) {
			$version      = isset( $manifest['version'] ) ? sanitize_text_field( (string) $manifest['version'] ) : '';
			$download_url = '';

			if ( ! empty( $manifest['download_url'] ) ) {
				$download_url = esc_url_raw( (string) $manifest['download_url'] );
			} elseif ( ! empty( $manifest['package'] ) ) {
				$download_url = esc_url_raw( (string) $manifest['package'] );
			}

			if ( '' === $version || '' === $download_url ) {
				return new \WP_Error( 'sobelz_updater_invalid_manifest', 'version and download_url are required in the update manifest.' );
			}

			if ( isset( $manifest['slug'] ) && $this->slug !== sanitize_key( (string) $manifest['slug'] ) ) {
				return new \WP_Error( 'sobelz_updater_slug_mismatch', 'The update manifest slug does not match this plugin.' );
			}

			if ( 'https' !== strtolower( (string) wp_parse_url( $this->manifest_url, PHP_URL_SCHEME ) ) || 'https' !== strtolower( (string) wp_parse_url( $download_url, PHP_URL_SCHEME ) ) ) {
				return new \WP_Error( 'sobelz_updater_insecure_url', 'The manifest and package URLs must use HTTPS.' );
			}

			$normalized = array(
				'name'            => isset( $manifest['name'] ) ? sanitize_text_field( (string) $manifest['name'] ) : $this->slug,
				'slug'            => $this->slug,
				'version'         => $version,
				'download_url'    => $download_url,
				'homepage'        => isset( $manifest['homepage'] ) ? esc_url_raw( (string) $manifest['homepage'] ) : $this->update_uri,
				'author'          => isset( $manifest['author'] ) ? wp_kses_post( (string) $manifest['author'] ) : '',
				'author_homepage' => isset( $manifest['author_homepage'] ) ? esc_url_raw( (string) $manifest['author_homepage'] ) : '',
				'requires'        => isset( $manifest['requires'] ) ? sanitize_text_field( (string) $manifest['requires'] ) : '',
				'requires_php'    => isset( $manifest['requires_php'] ) ? sanitize_text_field( (string) $manifest['requires_php'] ) : '',
				'tested'          => isset( $manifest['tested'] ) ? sanitize_text_field( (string) $manifest['tested'] ) : '',
				'last_updated'    => isset( $manifest['last_updated'] ) ? sanitize_text_field( (string) $manifest['last_updated'] ) : '',
				'upgrade_notice'  => isset( $manifest['upgrade_notice'] ) ? wp_kses_post( (string) $manifest['upgrade_notice'] ) : '',
				'sections'        => $this->sanitize_sections( isset( $manifest['sections'] ) ? $manifest['sections'] : array() ),
			);

			foreach ( array( 'icons', 'banners', 'banners_rtl' ) as $field ) {
				$normalized[ $field ] = $this->sanitize_url_map( isset( $manifest[ $field ] ) ? $manifest[ $field ] : array() );
			}

			$normalized['translations'] = $this->sanitize_translations( isset( $manifest['translations'] ) ? $manifest['translations'] : array() );

			return $normalized;
		}

		/**
		 * Sanitize plugin information sections while preserving safe markup.
		 *
		 * @param mixed $sections Raw sections.
		 * @return array<string, string>
		 */
		private function sanitize_sections( $sections ) {
			if ( ! is_array( $sections ) ) {
				return array();
			}

			$sanitized = array();
			foreach ( $sections as $key => $content ) {
				if ( ! is_scalar( $content ) ) {
					continue;
				}

				$sanitized[ sanitize_key( (string) $key ) ] = wp_kses_post( (string) $content );
			}

			return $sanitized;
		}

		/**
		 * Sanitize a keyed collection of asset URLs.
		 *
		 * @param mixed $urls Raw URLs.
		 * @return array<string, string>
		 */
		private function sanitize_url_map( $urls ) {
			if ( ! is_array( $urls ) ) {
				return array();
			}

			$sanitized = array();
			foreach ( $urls as $key => $url ) {
				$clean_url = esc_url_raw( (string) $url );
				if ( '' !== $clean_url ) {
					$sanitized[ sanitize_key( (string) $key ) ] = $clean_url;
				}
			}

			return $sanitized;
		}

		/**
		 * Sanitize optional WordPress translation update records.
		 *
		 * @param mixed $translations Raw translation records.
		 * @return array<int, array<string, mixed>>
		 */
		private function sanitize_translations( $translations ) {
			if ( ! is_array( $translations ) ) {
				return array();
			}

			$sanitized = array();
			foreach ( $translations as $translation ) {
				if ( ! is_array( $translation ) || empty( $translation['language'] ) || empty( $translation['package'] ) ) {
					continue;
				}

				$package = esc_url_raw( (string) $translation['package'] );
				if ( 'https' !== strtolower( (string) wp_parse_url( $package, PHP_URL_SCHEME ) ) ) {
					continue;
				}

				$sanitized[] = array(
					'language'   => sanitize_text_field( (string) $translation['language'] ),
					'version'    => isset( $translation['version'] ) ? sanitize_text_field( (string) $translation['version'] ) : '',
					'updated'    => isset( $translation['updated'] ) ? sanitize_text_field( (string) $translation['updated'] ) : '',
					'package'    => $package,
					'autoupdate' => ! empty( $translation['autoupdate'] ),
				);
			}

			return $sanitized;
		}
	}
}
