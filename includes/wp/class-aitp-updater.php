<?php
/**
 * Updates from GitHub releases (Update URI header → "update_plugins_github.com" filter).
 *
 * A release is used when its tag is a version number (v0.2.0 or 0.2.0) and it has an asset
 * named ai-translator-for-translatepress.zip. Disable with:
 *   add_filter( 'aitp_github_updates', '__return_false' );
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Updater {

	const REPO  = 'alankongx/ai-translator-for-translatepress';
	const SLUG  = 'ai-translator-for-translatepress';
	const ASSET = 'ai-translator-for-translatepress.zip';

	/** @var AITP_Updater|null */
	private static $instance = null;

	/** @return AITP_Updater */
	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Hooks. */
	public function boot() {
		add_filter( 'update_plugins_github.com', array( $this, 'check' ), 10, 4 );
		add_filter( 'plugins_api', array( $this, 'info' ), 20, 3 );
	}

	/**
	 * @param array|false $update      Update data.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin file.
	 * @param array       $locales     Locales.
	 * @return array|false
	 */
	public function check( $update, $plugin_data, $plugin_file, $locales ) {
		if ( $plugin_file !== plugin_basename( AITP_FILE ) || ! apply_filters( 'aitp_github_updates', true ) ) {
			return $update;
		}
		$rel = self::release();
		if ( ! $rel ) {
			return $update;
		}
		return array(
			'id'           => 'https://github.com/' . self::REPO,
			'slug'         => self::SLUG,
			'version'      => $rel['version'],
			'url'          => $rel['url'],
			'package'      => $rel['package'],
			'requires_php' => '7.4',
		);
	}

	/**
	 * Details popup ("View version x.y.z details").
	 *
	 * @param false|object|array $result Result.
	 * @param string             $action Action.
	 * @param object             $args   Args.
	 * @return false|object|array
	 */
	public function info( $result, $action, $args ) {
		if ( $action !== 'plugin_information' || ! isset( $args->slug ) || $args->slug !== self::SLUG ) {
			return $result;
		}
		$rel = self::release();
		if ( ! $rel ) {
			return $result;
		}
		return (object) array(
			'name'          => 'AI Translator for TranslatePress',
			'slug'          => self::SLUG,
			'version'       => $rel['version'],
			'author'        => '<a href="https://github.com/alankongx">alankongx</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'download_link' => $rel['package'],
			'last_updated'  => $rel['published'],
			'sections'      => array(
				'description' => esc_html__( 'Translation engine for TranslatePress using Google Gemini or any OpenAI-compatible API, with ID-based mapping and validation before saving.', 'ai-translator-for-translatepress' ),
				'changelog'   => wpautop( esc_html( $rel['notes'] ) ),
			),
		);
	}

	/**
	 * Latest usable release, cached for 6 hours (1 hour after an error).
	 *
	 * @return array|null
	 */
	public static function release() {
		$cached = get_site_transient( 'aitp_github_release' );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}
		$resp = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout'     => 8,
				'redirection' => 2,
				'headers'     => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'AI-Translator-for-TranslatePress/' . AITP_VERSION,
				),
			)
		);
		$data = is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ? null : json_decode( wp_remote_retrieve_body( $resp ), true );
		$rel  = null;
		if ( is_array( $data ) && empty( $data['draft'] ) && empty( $data['prerelease'] ) && isset( $data['tag_name'] ) ) {
			$version = ltrim( (string) $data['tag_name'], 'vV' );
			$prefix  = 'https://github.com/' . self::REPO . '/releases/download/';
			foreach ( isset( $data['assets'] ) && is_array( $data['assets'] ) ? $data['assets'] : array() as $a ) {
				if ( isset( $a['name'], $a['browser_download_url'] ) && $a['name'] === self::ASSET && strpos( $a['browser_download_url'], $prefix ) === 0 ) {
					$rel = array(
						'version'   => $version,
						'package'   => $a['browser_download_url'],
						'url'       => isset( $data['html_url'] ) ? (string) $data['html_url'] : 'https://github.com/' . self::REPO,
						'notes'     => isset( $data['body'] ) ? (string) $data['body'] : '',
						'published' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
					);
					break;
				}
			}
			if ( $rel && ! preg_match( '~^\d+\.\d+(\.\d+)?$~', $version ) ) {
				$rel = null;
			}
		}
		set_site_transient( 'aitp_github_release', $rel ? $rel : array( 'version' => '' ), $rel ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $rel;
	}
}
