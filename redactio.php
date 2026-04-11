<?php
/**
 * Rédactio — Amélioration de la lisibilité via l'API Claude
 *
 * @package   Redactio
 * @author    Guillaume JEUDY <digimarket84@gmail.com>
 * @copyright 2026 Guillaume JEUDY
 * @license   GNU General Public License v3.0
 * @link      https://geeklabo.fr
 *
 * Plugin Name:       Rédactio
 * Plugin URI:        https://github.com/digimarket84/Redactio
 * Description:       Améliorez la lisibilité et le SEO de vos articles et pages grâce à l'IA Claude (Anthropic). Tableau de bord avec scores Yoast, amélioration en un clic, mises à jour automatiques.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Guillaume JEUDY
 * Author URI:        https://geeklabo.fr
 * License:           GPLv3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       redactio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'REDACTIO_VERSION', '1.0.0' );
define( 'REDACTIO_BUILD',   1 );       // Incrémenté à chaque commit — identifie le build exact.
define( 'REDACTIO_DIR',     plugin_dir_path( __FILE__ ) );
define( 'REDACTIO_URL',     plugin_dir_url( __FILE__ ) );
define( 'REDACTIO_SLUG',    'redactio' );
define( 'REDACTIO_GITHUB_REPO', 'digimarket84/Redactio' );

// ── Autoload ──────────────────────────────────────────────────────────────────

spl_autoload_register( function ( string $class ): void {
	$map = [
		'Redactio_Improver' => REDACTIO_DIR . 'includes/class-redactio-improver.php',
		'Redactio_Updater'  => REDACTIO_DIR . 'includes/class-redactio-updater.php',
		'Redactio_Logger'   => REDACTIO_DIR . 'includes/class-redactio-logger.php',
		'Redactio_Admin'    => REDACTIO_DIR . 'admin/class-redactio-admin.php',
	];
	if ( isset( $map[ $class ] ) ) {
		require_once $map[ $class ];
	}
} );

// ── Activation / Désactivation ────────────────────────────────────────────────

register_activation_hook( __FILE__, function (): void {
	if ( false === get_option( 'redactio_post_types' ) ) {
		add_option( 'redactio_post_types', [ 'post', 'page' ] );
	}
} );

register_deactivation_hook( __FILE__, function (): void {
	delete_transient( 'redactio_progress' );
} );

// ── Initialisation admin ──────────────────────────────────────────────────────

if ( is_admin() ) {
	add_action( 'plugins_loaded', function (): void {
		load_plugin_textdomain( 'redactio', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		Redactio_Admin::init();
	} );
}
