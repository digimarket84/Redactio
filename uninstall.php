<?php
/**
 * Redactio — Nettoyage à la désinstallation
 *
 * @package Redactio
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Supprimer toutes les options du plugin.
$options = [
	'redactio_api_key',
	'redactio_claude_model',
	'redactio_post_types',
	'redactio_debug_enabled',
];
foreach ( $options as $option ) {
	delete_option( $option );
}

// Supprimer les transients.
delete_transient( 'redactio_progress' );

// Note : les contenus des articles (post_content) sont conservés.
// Seuls les données de configuration du plugin sont supprimées.
