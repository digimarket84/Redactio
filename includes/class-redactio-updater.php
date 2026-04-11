<?php
/**
 * Redactio — Vérification et installation des mises à jour depuis GitHub Releases
 *
 * @package   Redactio
 * @author    Guillaume JEUDY <digimarket84@gmail.com>
 * @copyright 2026 Guillaume JEUDY
 * @license   GNU General Public License v3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Redactio_Updater {

	const VERSION_JSON_URL = 'https://raw.githubusercontent.com/digimarket84/Redactio/main/version.json';
	const RELEASE_ZIP_URL  = 'https://github.com/digimarket84/Redactio/releases/download/v%s/redactio-%s.zip';

	/**
	 * Récupère le build distant depuis version.json sur GitHub.
	 *
	 * @return array{version: string, build: int}|WP_Error
	 */
	public static function get_remote_build() {
		$response = wp_remote_get( self::VERSION_JSON_URL, [
			'timeout'   => 10,
			'sslverify' => true,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'http_error', "HTTP {$code} lors de la récupération de version.json." );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $data['version'], $data['build'] ) ) {
			return new WP_Error( 'invalid_json', 'version.json invalide ou incomplet.' );
		}

		return [
			'version' => (string) $data['version'],
			'build'   => (int) $data['build'],
		];
	}

	/**
	 * Retourne les informations de mise à jour.
	 *
	 * @return array{local_version: string, local_build: int, remote_version: string, remote_build: int, has_update: bool, has_build_update: bool}
	 */
	public static function get_update_info(): array {
		$local_version = REDACTIO_VERSION;
		$local_build   = REDACTIO_BUILD;
		$remote        = self::get_remote_build();

		$remote_version = is_wp_error( $remote ) ? $local_version : $remote['version'];
		$remote_build   = is_wp_error( $remote ) ? $local_build : $remote['build'];

		return [
			'local_version'    => $local_version,
			'local_build'      => $local_build,
			'remote_version'   => $remote_version,
			'remote_build'     => $remote_build,
			'has_update'       => version_compare( $remote_version, $local_version, '>' ),
			'has_build_update' => $remote_build > $local_build && $remote_version === $local_version,
			'remote_error'     => is_wp_error( $remote ) ? $remote->get_error_message() : '',
		];
	}

	/**
	 * AJAX : Forcer l'installation de la dernière version depuis GitHub Releases.
	 */
	public static function ajax_force_install(): void {
		check_ajax_referer( 'redactio_nonce', 'security' );

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( __( 'Permission insuffisante.', 'redactio' ), 403 );
		}

		$info = self::get_update_info();
		$version = $info['remote_version'];
		$zip_url = sprintf( self::RELEASE_ZIP_URL, $version, $version );

		Redactio_Logger::info( "Force install depuis : {$zip_url}" );

		// Inclure les classes WordPress nécessaires.
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		WP_Filesystem();

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $zip_url );

		if ( is_null( $result ) ) {
			wp_send_json_error( __( 'Échec de l\'installation — résultat null (WP_Filesystem non initialisé ou ZIP inaccessible).', 'redactio' ) );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		if ( false === $result ) {
			$messages = $skin->get_upgrade_messages();
			wp_send_json_error( implode( ' | ', $messages ) ?: __( 'Échec inconnu.', 'redactio' ) );
		}

		Redactio_Logger::info( "Force install réussie — v{$version} installée." );

		wp_send_json_success( [
			'message' => sprintf(
				/* translators: %s: numéro de version */
				__( '✅ Rédactio v%s installée avec succès. Rechargez la page.', 'redactio' ),
				$version
			),
		] );
	}
}
