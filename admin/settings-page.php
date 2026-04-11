<?php
/**
 * Redactio — Template page d'administration
 *
 * @package   Redactio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$active_tab = sanitize_key( $_GET['tab'] ?? 'dashboard' );
$has_yoast  = defined( 'WPSEO_VERSION' );

// Sauvegarde réglages (onglet Réglages).
if ( 'settings' === $active_tab && isset( $_POST['redactio_save_settings'] ) ) {
	check_admin_referer( 'redactio_save_settings' );
	update_option( 'redactio_claude_model',   sanitize_text_field( $_POST['claude_model'] ?? 'claude-opus-4-5' ) );
	update_option( 'redactio_post_types',     array_map( 'sanitize_key', (array) ( $_POST['post_types'] ?? [] ) ) );
	update_option( 'redactio_debug_enabled',  ! empty( $_POST['debug_enabled'] ) );

	// Clé API : chiffrement si saisie.
	$api_key_input = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
	if ( '' !== $api_key_input && strpos( $api_key_input, '***' ) === false ) {
		update_option( 'redactio_api_key', Redactio_Improver::encrypt_api_key( $api_key_input ), false );
	}

	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Réglages enregistrés.', 'redactio' ) . '</p></div>';
}

// Paramètres du tableau de bord.
$paged      = max( 1, absint( $_GET['paged'] ?? 1 ) );
$per_page   = 20;
$filter_type = sanitize_key( $_GET['type'] ?? '' );
$sort_by    = in_array( $_GET['sort'] ?? '', [ 'seo', 'readability', 'date' ], true ) ? $_GET['sort'] : 'date';
$sort_order = 'asc' === ( $_GET['order'] ?? '' ) ? 'ASC' : 'DESC';

$post_types = (array) get_option( 'redactio_post_types', [ 'post', 'page' ] );
if ( ! empty( $filter_type ) && in_array( $filter_type, $post_types, true ) ) {
	$query_types = [ $filter_type ];
} else {
	$query_types = $post_types;
}

$query_args = [
	'post_type'      => $query_types,
	'post_status'    => 'publish',
	'posts_per_page' => $per_page,
	'paged'          => $paged,
	'orderby'        => 'date',
	'order'          => 'DESC',
];

if ( 'seo' === $sort_by ) {
	$query_args['meta_key'] = '_yoast_wpseo_linkdex';
	$query_args['orderby']  = 'meta_value_num';
	$query_args['order']    = $sort_order;
} elseif ( 'readability' === $sort_by ) {
	$query_args['meta_key'] = '_yoast_wpseo_content_score';
	$query_args['orderby']  = 'meta_value_num';
	$query_args['order']    = $sort_order;
}

$posts_query = new WP_Query( $query_args );
$total_posts = $posts_query->found_posts;
$total_pages = $posts_query->max_num_pages;
?>
<div class="wrap">
	<h1>
		<span style="font-size:22px;">✍️</span>
		Rédactio
		<span style="font-size:13px;font-weight:400;color:#999;margin-left:8px;">v<?php echo esc_html( REDACTIO_VERSION ); ?> #<?php echo esc_html( REDACTIO_BUILD ); ?></span>
	</h1>

	<!-- Barre de progression globale -->
	<div id="redactio-progress-notice" style="display:none;margin:8px 0 4px;">
		<div class="redactio-bar-track">
			<div class="redactio-bar-fill" id="redactio-bar-fill"></div>
		</div>
		<p id="redactio-bar-label" style="margin:4px 0 0;font-size:12px;color:#555;"></p>
	</div>

	<!-- Navigation onglets -->
	<nav class="nav-tab-wrapper" style="margin-bottom:0;">
		<a href="<?php echo esc_url( add_query_arg( 'tab', 'dashboard' ) ); ?>"
		   class="nav-tab <?php echo 'dashboard' === $active_tab ? 'nav-tab-active' : ''; ?>">
			📋 <?php esc_html_e( 'Tableau de bord', 'redactio' ); ?>
		</a>
		<a href="<?php echo esc_url( add_query_arg( 'tab', 'logs' ) ); ?>"
		   class="nav-tab <?php echo 'logs' === $active_tab ? 'nav-tab-active' : ''; ?>">
			📄 <?php esc_html_e( 'Logs', 'redactio' ); ?>
		</a>
		<a href="<?php echo esc_url( add_query_arg( 'tab', 'settings' ) ); ?>"
		   class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
			⚙️ <?php esc_html_e( 'Réglages', 'redactio' ); ?>
		</a>
		<a href="<?php echo esc_url( add_query_arg( 'tab', 'advanced' ) ); ?>"
		   class="nav-tab <?php echo 'advanced' === $active_tab ? 'nav-tab-active' : ''; ?>">
			🔄 <?php esc_html_e( 'Avancé', 'redactio' ); ?>
		</a>
	</nav>

	<!-- ── TABLEAU DE BORD ─────────────────────────────────────────────────── -->
	<?php if ( 'dashboard' === $active_tab ) : ?>
	<div class="redactio-panel">

		<?php if ( ! $has_yoast ) : ?>
		<div class="notice notice-warning inline" style="margin:12px 0;">
			<p><?php esc_html_e( 'Yoast SEO n\'est pas actif — les scores SEO et lisibilité ne sont pas disponibles.', 'redactio' ); ?></p>
		</div>
		<?php endif; ?>

		<!-- Filtres -->
		<div style="display:flex;align-items:center;gap:12px;margin:12px 0 8px;flex-wrap:wrap;">
			<span style="font-weight:600;"><?php echo esc_html( $total_posts ); ?> <?php esc_html_e( 'articles/pages publiés', 'redactio' ); ?></span>
			<span>|</span>
			<?php foreach ( $post_types as $type ) : ?>
			<a href="<?php echo esc_url( add_query_arg( [ 'tab' => 'dashboard', 'type' => $type, 'paged' => 1 ] ) ); ?>"
			   class="<?php echo $filter_type === $type ? 'button button-primary button-small' : 'button button-small'; ?>">
				<?php echo 'post' === $type ? esc_html__( 'Articles', 'redactio' ) : esc_html( ucfirst( $type ) ); ?>
			</a>
			<?php endforeach; ?>
			<?php if ( $filter_type ) : ?>
			<a href="<?php echo esc_url( add_query_arg( [ 'tab' => 'dashboard', 'type' => '', 'paged' => 1 ] ) ); ?>"
			   class="button button-small">✕ <?php esc_html_e( 'Tout', 'redactio' ); ?></a>
			<?php endif; ?>

			<span style="margin-left:auto;font-size:12px;color:#999;">
				<?php esc_html_e( 'Trier par :', 'redactio' ); ?>
				<?php
				$sort_links = [
					'date'        => __( 'Date', 'redactio' ),
					'seo'         => __( 'SEO', 'redactio' ),
					'readability' => __( 'Lisibilité', 'redactio' ),
				];
				foreach ( $sort_links as $key => $label ) :
					$is_active   = $sort_by === $key;
					$next_order  = ( $is_active && 'ASC' === $sort_order ) ? 'desc' : 'asc';
					$arrow       = $is_active ? ( 'ASC' === $sort_order ? ' ↑' : ' ↓' ) : '';
				?>
				<a href="<?php echo esc_url( add_query_arg( [ 'tab' => 'dashboard', 'sort' => $key, 'order' => $next_order, 'paged' => 1 ] ) ); ?>"
				   style="<?php echo $is_active ? 'font-weight:600;' : ''; ?>">
					<?php echo esc_html( $label . $arrow ); ?>
				</a>
				<?php endforeach; ?>
			</span>
		</div>

		<!-- Tableau -->
		<table class="wp-list-table widefat fixed striped redactio-table">
			<thead>
				<tr>
					<th style="width:70px;"><?php esc_html_e( 'Type', 'redactio' ); ?></th>
					<th><?php esc_html_e( 'Article / Page', 'redactio' ); ?></th>
					<th style="width:90px;text-align:center;"><?php esc_html_e( 'SEO', 'redactio' ); ?></th>
					<th style="width:100px;text-align:center;"><?php esc_html_e( 'Lisibilité', 'redactio' ); ?></th>
					<th style="width:200px;"><?php esc_html_e( 'Actions', 'redactio' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( $posts_query->have_posts() ) : ?>
				<?php while ( $posts_query->have_posts() ) : $posts_query->the_post(); ?>
				<?php
				$post_id       = get_the_ID();
				$post_type_obj = get_post_type_object( get_post_type() );
				$type_label    = $post_type_obj ? $post_type_obj->labels->singular_name : get_post_type();
				$seo_score     = (int) get_post_meta( $post_id, '_yoast_wpseo_linkdex', true );
				$read_score    = (int) get_post_meta( $post_id, '_yoast_wpseo_content_score', true );
				$focus_kw      = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
				$meta_desc     = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
				?>
				<tr>
					<td>
						<span class="redactio-type-badge"><?php echo esc_html( $type_label ); ?></span>
					</td>
					<td>
						<strong>
							<a href="<?php the_permalink(); ?>" target="_blank" rel="noopener">
								<?php the_title(); ?>
							</a>
						</strong>
						<div class="row-actions" style="position:static;visibility:visible;">
							<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>">
								<?php esc_html_e( 'Modifier', 'redactio' ); ?>
							</a>
							<?php if ( $focus_kw ) : ?>
							&nbsp;| <span style="color:#999;font-size:11px;"><?php echo esc_html( $focus_kw ); ?></span>
							<?php endif; ?>
						</div>
						<?php if ( $meta_desc ) : ?>
						<div style="color:#999;font-size:11px;margin-top:2px;"><?php echo esc_html( mb_substr( $meta_desc, 0, 100 ) ); ?>…</div>
						<?php endif; ?>
						<div class="redactio-action-result" id="result-<?php echo esc_attr( $post_id ); ?>" style="display:none;font-size:11px;margin-top:4px;"></div>
					</td>
					<td style="text-align:center;">
						<?php echo wp_kses_post( Redactio_Admin::score_badge( $seo_score, $has_yoast ) ); ?>
					</td>
					<td style="text-align:center;">
						<?php echo wp_kses_post( Redactio_Admin::score_badge( $read_score, $has_yoast ) ); ?>
					</td>
					<td>
						<div style="display:flex;flex-direction:column;gap:4px;">
							<button type="button"
								class="button button-small redactio-improve-btn"
								data-post-id="<?php echo esc_attr( $post_id ); ?>"
								data-title="<?php echo esc_attr( get_the_title() ); ?>">
								✨ <?php esc_html_e( 'Améliorer', 'redactio' ); ?>
							</button>
							<?php if ( $has_yoast ) : ?>
							<button type="button"
								class="button button-small redactio-seo-btn"
								data-post-id="<?php echo esc_attr( $post_id ); ?>"
								data-title="<?php echo esc_attr( get_the_title() ); ?>">
								🔍 <?php esc_html_e( 'Régénérer SEO', 'redactio' ); ?>
							</button>
							<?php endif; ?>
						</div>
					</td>
				</tr>
				<?php endwhile; ?>
				<?php wp_reset_postdata(); ?>
			<?php else : ?>
				<tr>
					<td colspan="5" style="text-align:center;padding:20px;color:#999;">
						<?php esc_html_e( 'Aucun article publié trouvé.', 'redactio' ); ?>
					</td>
				</tr>
			<?php endif; ?>
			</tbody>
		</table>

		<!-- Pagination -->
		<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav bottom" style="margin-top:8px;">
			<div class="tablenav-pages">
				<?php
				echo paginate_links( [
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $total_pages,
					'type'    => 'plain',
				] );
				?>
			</div>
		</div>
		<?php endif; ?>

	</div><!-- .redactio-panel -->

	<!-- ── LOGS ────────────────────────────────────────────────────────────── -->
	<?php elseif ( 'logs' === $active_tab ) : ?>
	<div class="redactio-panel">
		<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
			<h2 style="margin:0;"><?php esc_html_e( 'Logs de débogage', 'redactio' ); ?></h2>
			<button type="button" id="redactio-clear-logs" class="button button-secondary">
				🗑️ <?php esc_html_e( 'Vider les logs', 'redactio' ); ?>
			</button>
		</div>
		<div id="redactio-clear-logs-result" style="margin-bottom:8px;font-size:13px;"></div>
		<?php
		$log_lines = Redactio_Logger::get_last_lines( 150 );
		if ( empty( $log_lines ) ) :
		?>
		<p style="color:#999;"><?php esc_html_e( 'Aucun log disponible. Activez le débogage dans les Réglages.', 'redactio' ); ?></p>
		<?php else : ?>
		<div class="redactio-logs-wrap">
			<?php foreach ( $log_lines as $line ) : ?>
			<?php
			$cls = '';
			if ( strpos( $line, '[ERROR]' ) !== false )   $cls = 'redactio-log--error';
			elseif ( strpos( $line, '[WARNING]' ) !== false ) $cls = 'redactio-log--warning';
			elseif ( strpos( $line, '[DEBUG]' ) !== false )   $cls = 'redactio-log--debug';
			?>
			<div class="redactio-log-line <?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $line ); ?></div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>

	<!-- ── RÉGLAGES ────────────────────────────────────────────────────────── -->
	<?php elseif ( 'settings' === $active_tab ) : ?>
	<div class="redactio-panel">
		<form method="post" action="">
			<?php wp_nonce_field( 'redactio_save_settings' ); ?>
			<input type="hidden" name="redactio_save_settings" value="1">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Clé API Claude', 'redactio' ); ?></th>
					<td>
						<input type="password" name="api_key" value="<?php echo esc_attr( get_option( 'redactio_api_key' ) ? '***masquée***' : '' ); ?>"
						       class="regular-text" placeholder="sk-ant-...">
						<p class="description">
							<?php esc_html_e( 'Obtenez votre clé sur', 'redactio' ); ?>
							<a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a>.
							<?php esc_html_e( 'Recommandé : définir', 'redactio' ); ?>
							<code>define('REDACTIO_CLAUDE_API_KEY', 'sk-ant-...');</code>
							<?php esc_html_e( 'dans wp-config.php.', 'redactio' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Modèle Claude', 'redactio' ); ?></th>
					<td>
						<select name="claude_model">
							<?php
							$current_model = get_option( 'redactio_claude_model', 'claude-opus-4-5' );
							$models = [
								'claude-opus-4-5'   => 'claude-opus-4-5 (meilleure qualité)',
								'claude-sonnet-4-5' => 'claude-sonnet-4-5 (équilibré)',
								'claude-haiku-3-5'  => 'claude-haiku-3-5 (rapide / économique)',
							];
							foreach ( $models as $value => $label ) :
							?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_model, $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Types de contenu', 'redactio' ); ?></th>
					<td>
						<?php
						$saved_types = (array) get_option( 'redactio_post_types', [ 'post', 'page' ] );
						$all_types   = get_post_types( [ 'public' => true ], 'objects' );
						foreach ( $all_types as $type_obj ) :
							if ( 'attachment' === $type_obj->name ) continue;
						?>
						<label style="display:block;margin-bottom:4px;">
							<input type="checkbox" name="post_types[]"
							       value="<?php echo esc_attr( $type_obj->name ); ?>"
							       <?php checked( in_array( $type_obj->name, $saved_types, true ) ); ?>>
							<?php echo esc_html( $type_obj->labels->singular_name ); ?>
							<span style="color:#999;font-size:11px;">(<?php echo esc_html( $type_obj->name ); ?>)</span>
						</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Débogage', 'redactio' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="debug_enabled" value="1"
							       <?php checked( get_option( 'redactio_debug_enabled' ) ); ?>>
							<?php esc_html_e( 'Activer les logs détaillés', 'redactio' ); ?>
						</label>
						<p class="description">
							<?php
							printf(
								esc_html__( 'Fichier : %s', 'redactio' ),
								'<code>' . esc_html( Redactio_Logger::get_log_file() ) . '</code>'
							);
							?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Enregistrer les réglages', 'redactio' ) ); ?>
		</form>
	</div>

	<!-- ── AVANCÉ (Mises à jour) ───────────────────────────────────────────── -->
	<?php elseif ( 'advanced' === $active_tab ) : ?>
	<div class="redactio-panel">
		<h2><?php esc_html_e( 'Mises à jour', 'redactio' ); ?></h2>
		<?php $update_info = Redactio_Updater::get_update_info(); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Version installée', 'redactio' ); ?></th>
				<td>
					<strong>v<?php echo esc_html( REDACTIO_VERSION ); ?></strong>
					<span style="color:#999;"> — build #<?php echo esc_html( REDACTIO_BUILD ); ?></span>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Dernière release GitHub', 'redactio' ); ?></th>
				<td>
					<?php if ( $update_info['remote_error'] ) : ?>
						<span style="color:#d63638;">⚠️ <?php echo esc_html( $update_info['remote_error'] ); ?></span>
					<?php else : ?>
						<strong>v<?php echo esc_html( $update_info['remote_version'] ); ?></strong>
						<span style="color:#999;"> — build #<?php echo esc_html( $update_info['remote_build'] ); ?></span>
						<?php if ( $update_info['has_build_update'] ) : ?>
						<span style="color:#d63638;margin-left:8px;">
							⚠️ <?php esc_html_e( 'Nouveau build disponible', 'redactio' ); ?>
						</span>
						<?php elseif ( $update_info['has_update'] ) : ?>
						<span style="color:#d63638;margin-left:8px;">
							🆕 <?php esc_html_e( 'Nouvelle version disponible', 'redactio' ); ?>
						</span>
						<?php else : ?>
						<span style="color:#00a32a;margin-left:8px;">
							✅ <?php esc_html_e( 'À jour', 'redactio' ); ?>
						</span>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Forcer la mise à jour', 'redactio' ); ?></th>
				<td>
					<button type="button" id="redactio-force-install" class="button button-primary">
						⬇️ <?php esc_html_e( 'Installer la dernière version', 'redactio' ); ?>
					</button>
					<span id="redactio-force-install-result" style="margin-left:12px;font-size:13px;"></span>
					<p class="description">
						<?php esc_html_e( 'Télécharge et installe le ZIP depuis GitHub Releases. Rechargez la page après l\'installation.', 'redactio' ); ?>
					</p>
				</td>
			</tr>
		</table>
	</div>
	<?php endif; ?>

</div><!-- .wrap -->
