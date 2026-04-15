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

		$info    = self::get_update_info();
		$version = $info['remote_version'];
		$zip_url = sprintf( self::RELEASE_ZIP_URL, $version, $version );

		Redactio_Logger::info( "Force install depuis : {$zip_url}" );

		// ── 1. Charger les helpers WP nécessaires ─────────────────────────────
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// ── 2. Initialiser WP_Filesystem en mode direct ───────────────────────
		add_filter( 'filesystem_method', static function () { return 'direct'; } );
		$fs_ok = WP_Filesystem();
		remove_all_filters( 'filesystem_method' );

		if ( ! $fs_ok ) {
			Redactio_Logger::error( 'WP_Filesystem : accès direct refusé (permissions insuffisantes ?).' );
			wp_send_json_error( __( 'Filesystem inaccessible — vérifiez les permissions du dossier wp-content/plugins.', 'redactio' ) );
		}

		global $wp_filesystem;

		// ── 3. Télécharger le ZIP ─────────────────────────────────────────────
		$tmp_zip = download_url( $zip_url, 120 );

		if ( is_wp_error( $tmp_zip ) ) {
			Redactio_Logger::error( 'Téléchargement échoué : ' . $tmp_zip->get_error_message() );
			wp_send_json_error( __( 'Téléchargement échoué : ', 'redactio' ) . $tmp_zip->get_error_message() );
		}

		Redactio_Logger::info( "ZIP téléchargé : {$tmp_zip}" );

		// ── 4. Décompresser dans un dossier temporaire ────────────────────────
		$upgrade_dir = WP_CONTENT_DIR . '/upgrade';
		if ( ! is_dir( $upgrade_dir ) ) {
			wp_mkdir_p( $upgrade_dir );
		}

		$tmp_dir = $upgrade_dir . '/redactio-update-' . time();
		$unzip   = unzip_file( $tmp_zip, $tmp_dir );
		@unlink( $tmp_zip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( is_wp_error( $unzip ) ) {
			$wp_filesystem->delete( $tmp_dir, true );
			Redactio_Logger::error( 'Décompression échouée : ' . $unzip->get_error_message() );
			wp_send_json_error( __( 'Décompression échouée : ', 'redactio' ) . $unzip->get_error_message() );
		}

		// ── 5. Localiser le dossier source dans l'archive ────────────────────
		$source = $tmp_dir . '/redactio';
		if ( ! is_dir( $source ) ) {
			// Chercher un sous-dossier unique (ex. redactio-1.0.0/).
			$dirs = glob( $tmp_dir . '/*', GLOB_ONLYDIR );
			$source = ! empty( $dirs ) ? $dirs[0] : $tmp_dir;
		}

		// ── 6. Copier vers le dossier du plugin ───────────────────────────────
		$dest   = WP_PLUGIN_DIR . '/redactio';
		$copied = copy_dir( $source, $dest );
		$wp_filesystem->delete( $tmp_dir, true );

		if ( is_wp_error( $copied ) ) {
			Redactio_Logger::error( 'Copie échouée : ' . $copied->get_error_message() );
			wp_send_json_error( __( 'Copie des fichiers échouée : ', 'redactio' ) . $copied->get_error_message() );
		}

		Redactio_Logger::info( "Force install réussie — v{$version} (build #{$info['remote_build']}) installée." );

		wp_send_json_success( [
			'message' => sprintf(
				/* translators: %s: numéro de version */
				__( '✅ Rédactio v%s installée avec succès. Rechargez la page pour activer la nouvelle version.', 'redactio' ),
				$version
			),
		] );
	}
}
