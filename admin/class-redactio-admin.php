<?php
/**
 * Redactio — Administration WordPress
 *
 * @package   Redactio
 * @author    Guillaume JEUDY <digimarket84@gmail.com>
 * @copyright 2026 Guillaume JEUDY
 * @license   GNU General Public License v3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Redactio_Admin {

	public static function init(): void {
		add_action( 'admin_menu',            [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_init',            [ __CLASS__, 'register_settings' ] );

		// Row actions sur les listes d'articles et pages.
		$post_types = (array) get_option( 'redactio_post_types', [ 'post', 'page' ] );
		foreach ( $post_types as $type ) {
			$filter = ( 'post' === $type ) ? 'post_row_actions' : $type . '_row_actions';
			add_filter( $filter, [ __CLASS__, 'add_row_action' ], 10, 2 );
		}

		// AJAX handlers.
		add_action( 'wp_ajax_redactio_improve',         [ __CLASS__, 'ajax_improve' ] );
		add_action( 'wp_ajax_redactio_get_progress',    [ __CLASS__, 'ajax_get_progress' ] );
		add_action( 'wp_ajax_redactio_regenerate_seo',  [ __CLASS__, 'ajax_regenerate_seo' ] );
		add_action( 'wp_ajax_redactio_force_install',   [ 'Redactio_Updater', 'ajax_force_install' ] );
		add_action( 'wp_ajax_redactio_clear_logs',      [ __CLASS__, 'ajax_clear_logs' ] );
	}

	// ─── Menu admin ───────────────────────────────────────────────────────────

	public static function add_menu(): void {
		add_options_page(
			__( 'Rédactio', 'redactio' ),
			__( 'Rédactio', 'redactio' ),
			'edit_posts',
			'redactio-settings',
			[ __CLASS__, 'render_settings_page' ]
		);
	}

	public static function render_settings_page(): void {
		require_once REDACTIO_DIR . 'admin/settings-page.php';
	}

	// ─── Assets ───────────────────────────────────────────────────────────────

	public static function enqueue_assets( string $hook ): void {
		// Listes d'articles/pages : charger uniquement pour les types configurés.
		$post_types  = (array) get_option( 'redactio_post_types', [ 'post', 'page' ] );
		$list_hooks  = [];
		foreach ( $post_types as $type ) {
			$list_hooks[] = ( 'post' === $type ) ? 'edit.php' : 'edit.php?post_type=' . $type;
		}
		// Correspondance WordPress : hook = 'edit.php' ou 'edit-{type}.php'.
		$is_list_page     = 'edit.php' === $hook;
		$is_settings_page = 'settings_page_redactio-settings' === $hook;

		if ( ! $is_list_page && ! $is_settings_page ) {
			return;
		}

		$ver = REDACTIO_VERSION . '.' . REDACTIO_BUILD;

		wp_enqueue_style(
			'redactio-admin',
			REDACTIO_URL . 'admin/assets/admin.css',
			[],
			$ver
		);

		wp_enqueue_script(
			'redactio-admin',
			REDACTIO_URL . 'admin/assets/admin.js',
			[ 'jquery' ],
			$ver,
			true
		);

		wp_localize_script( 'redactio-admin', 'redactioData', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'redactio_nonce' ),
			'i18n'    => [
				'error'   => __( 'Une erreur est survenue.', 'redactio' ),
				'confirm' => __( 'Améliorer la lisibilité de cet article ? Le contenu sera réécrit.', 'redactio' ),
			],
		] );
	}

	// ─── Réglages WordPress ───────────────────────────────────────────────────

	public static function register_settings(): void {
		register_setting( 'redactio_settings', 'redactio_claude_model', [
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'claude-opus-4-5',
		] );
		register_setting( 'redactio_settings', 'redactio_post_types', [
			'sanitize_callback' => function ( $v ) {
				return array_map( 'sanitize_key', (array) $v );
			},
			'default' => [ 'post', 'page' ],
		] );
		register_setting( 'redactio_settings', 'redactio_debug_enabled', [
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		] );
		// La clé API est sauvegardée manuellement dans ajax_save_api_key pour chiffrement.
	}

	// ─── Row action ───────────────────────────────────────────────────────────

	/**
	 * Ajoute les actions "✨ Améliorer" et "🔍 SEO" sous chaque titre d'article.
	 */
	public static function add_row_action( array $actions, WP_Post $post ): array {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$actions['redactio_improve'] = sprintf(
			'<a href="#" class="redactio-improve-btn" data-post-id="%d" data-title="%s">✨ %s</a>',
			$post->ID,
			esc_attr( $post->post_title ),
			esc_html__( 'Améliorer', 'redactio' )
		);

		if ( defined( 'WPSEO_VERSION' ) ) {
			$actions['redactio_seo'] = sprintf(
				'<a href="#" class="redactio-seo-btn" data-post-id="%d" data-title="%s">🔍 %s</a>',
				$post->ID,
				esc_attr( $post->post_title ),
				esc_html__( 'SEO', 'redactio' )
			);
		}

		return $actions;
	}

	// ─── AJAX : Amélioration lisibilité ──────────────────────────────────────

	public static function ajax_improve(): void {
		check_ajax_referer( 'redactio_nonce', 'security' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Permission insuffisante.', 'redactio' ), 403 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 600 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			wp_send_json_error( __( 'Post introuvable.', 'redactio' ) );
		}

		$title   = get_the_title( $post_id );
		$content = get_post_field( 'post_content', $post_id );

		if ( empty( trim( $content ) ) ) {
			wp_send_json_error( __( 'Le contenu du post est vide.', 'redactio' ) );
		}

		Redactio_Logger::info( "Amélioration post #{$post_id} : {$title} (" . strlen( $content ) . ' chars).' );

		$improved = Redactio_Improver::improve( $content, $title );

		if ( is_wp_error( $improved ) ) {
			wp_send_json_error( $improved->get_error_message() );
		}

		$update = wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_kses_post( $improved ),
		], true );

		if ( is_wp_error( $update ) ) {
			wp_send_json_error( $update->get_error_message() );
		}

		// Invalider le score Yoast périmé — il sera recalculé lors de la prochaine sauvegarde via l'éditeur.
		if ( defined( 'WPSEO_VERSION' ) ) {
			delete_post_meta( $post_id, '_yoast_wpseo_content_score' );
		}

		Redactio_Logger::info( "Post #{$post_id} mis à jour avec succès." );

		wp_send_json_success( [
			'message' => __( '✅ Lisibilité améliorée. Ouvre l\'article dans l\'éditeur et sauvegarde pour rafraîchir le score Yoast.', 'redactio' ),
		] );
	}

	// ─── AJAX : Progression ───────────────────────────────────────────────────

	public static function ajax_get_progress(): void {
		check_ajax_referer( 'redactio_nonce', 'security' );
		wp_send_json_success( get_transient( 'redactio_progress' ) ?: [ 'status' => 'idle' ] );
	}

	// ─── AJAX : Régénération SEO ──────────────────────────────────────────────

	public static function ajax_regenerate_seo(): void {
		check_ajax_referer( 'redactio_nonce', 'security' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Permission insuffisante.', 'redactio' ), 403 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			wp_send_json_error( __( 'Post introuvable.', 'redactio' ) );
		}

		$title   = get_the_title( $post_id );
		$content = get_post_field( 'post_content', $post_id );
		$text    = mb_substr( wp_strip_all_tags( $content ), 0, 8000 );

		Redactio_Logger::info( "Régénération SEO — post #{$post_id} : {$title}" );

		$seo = Redactio_Improver::generate_seo( $title, $text );

		if ( is_wp_error( $seo ) ) {
			wp_send_json_error( $seo->get_error_message() );
		}

		// Mettre à jour les meta Yoast.
		if ( defined( 'WPSEO_VERSION' ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_title',    sanitize_text_field( $seo['meta_title'] ) );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_text_field( $seo['meta_description'] ) );
			update_post_meta( $post_id, '_yoast_wpseo_focuskw',  sanitize_text_field( $seo['focus_keyword'] ) );
			// Invalider les scores périmés — recalculés par Yoast lors de la prochaine sauvegarde dans l'éditeur.
			delete_post_meta( $post_id, '_yoast_wpseo_linkdex' );
			delete_post_meta( $post_id, '_yoast_wpseo_content_score' );
		}

		// Mettre à jour les tags.
		$tags = (array) ( $seo['tags'] ?? [] );
		if ( ! empty( $tags ) ) {
			wp_set_post_tags( $post_id, $tags, false );
		}

		Redactio_Logger::info( sprintf(
			'SEO régénéré — post #%d : keyword="%s", tags=%s',
			$post_id,
			$seo['focus_keyword'] ?? '?',
			implode( ', ', $tags )
		) );

		wp_send_json_success( [
			'message'       => __( '✅ SEO régénéré. Ouvre l\'article dans l\'éditeur et sauvegarde pour rafraîchir le score Yoast.', 'redactio' ),
			'focus_keyword' => $seo['focus_keyword'] ?? '',
			'meta_title'    => $seo['meta_title'] ?? '',
			'tags'          => $tags,
		] );
	}

	// ─── AJAX : Sauvegarde clé API ────────────────────────────────────────────

	public static function ajax_save_api_key(): void {
		check_ajax_referer( 'redactio_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '', 403 );
		}

		$key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );

		if ( empty( $key ) ) {
			delete_option( 'redactio_api_key' );
			wp_send_json_success( [ 'message' => __( 'Clé API supprimée.', 'redactio' ) ] );
		}

		update_option( 'redactio_api_key', Redactio_Improver::encrypt_api_key( $key ), false );
		wp_send_json_success( [ 'message' => __( '✅ Clé API sauvegardée.', 'redactio' ) ] );
	}

	// ─── AJAX : Vider les logs ────────────────────────────────────────────────

	public static function ajax_clear_logs(): void {
		check_ajax_referer( 'redactio_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '', 403 );
		}

		Redactio_Logger::clear();
		wp_send_json_success( [ 'message' => __( 'Logs vidés.', 'redactio' ) ] );
	}

	// ─── Helper : score Yoast ─────────────────────────────────────────────────

	/**
	 * Retourne le badge HTML pour un score Yoast (0–100).
	 *
	 * @param int  $score     Score 0–100 (0 = non calculé).
	 * @param bool $has_yoast Yoast est-il actif ?
	 * @return string HTML badge.
	 */
	public static function score_badge( int $score, bool $has_yoast ): string {
		if ( ! $has_yoast ) {
			return '<span class="redactio-badge redactio-badge--grey">—</span>';
		}
		if ( 0 === $score ) {
			return '<span class="redactio-badge redactio-badge--grey">?</span>';
		}
		if ( $score >= 70 ) {
			return '<span class="redactio-badge redactio-badge--green">● ' . $score . '</span>';
		}
		if ( $score >= 40 ) {
			return '<span class="redactio-badge redactio-badge--orange">● ' . $score . '</span>';
		}
		return '<span class="redactio-badge redactio-badge--red">● ' . $score . '</span>';
	}
}
