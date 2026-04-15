<?php
/**
 * Redactio — Appels API Claude : amélioration lisibilité + génération SEO
 *
 * @package   Redactio
 * @author    Guillaume JEUDY <digimarket84@gmail.com>
 * @copyright 2026 Guillaume JEUDY
 * @license   GNU General Public License v3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Redactio_Improver {

	const API_ENDPOINT     = 'https://api.anthropic.com/v1/messages';
	const API_VERSION      = '2023-06-01';
	const DEFAULT_MODEL    = 'claude-opus-4-5';
	const MAX_RETRIES      = 3;
	const RETRY_BASE_DELAY = 2;
	const CHUNK_THRESHOLD  = 25000;
	const MIN_CHUNK_SIZE   = 3000;
	const MAX_CHUNK_SIZE   = 20000;
	const CHUNK_CACHE_TTL  = 86400;
	const MAX_TOKENS_CEILING = 8192;
	const MAX_TOKENS_SEO   = 512;
	const CHARS_PER_TOKEN  = 3.5;

	// ─── API publique ──────────────────────────────────────────────────────────

	/**
	 * Améliore la lisibilité d'un article HTML (déjà en français).
	 *
	 * @param string $html  HTML de l'article.
	 * @param string $title Titre de l'article (contexte).
	 * @return string|WP_Error HTML amélioré ou erreur.
	 */
	public static function improve( string $html, string $title ) {
		Redactio_Logger::info( "Amélioration lisibilité : {$title}" );

		if ( strlen( $html ) > self::CHUNK_THRESHOLD ) {
			Redactio_Logger::info( 'Article volumineux (' . strlen( $html ) . ' chars) → mode chunked.' );
			return self::improve_chunked( $html, $title );
		}

		$max_tokens = min(
			(int) ceil( strlen( $html ) / self::CHARS_PER_TOKEN * 1.1 ),
			self::MAX_TOKENS_CEILING
		);
		$max_tokens = max( $max_tokens, 512 );

		$result = self::call_api( self::get_readability_prompt(), $html, $max_tokens );

		if ( is_wp_error( $result ) ) {
			Redactio_Logger::error( 'Amélioration échouée : ' . $result->get_error_message() );
			return $result;
		}

		Redactio_Logger::info( 'Lisibilité améliorée (' . ( $result['tokens'] ?? 0 ) . ' tokens).' );
		return $result['content'];
	}

	/**
	 * Génère les données SEO pour un article via Claude.
	 *
	 * @param string $title Titre de l'article.
	 * @param string $text  Texte brut de l'article (6000-8000 chars max).
	 * @return array|WP_Error Données SEO ou erreur.
	 */
	public static function generate_seo( string $title, string $text ) {
		Redactio_Logger::info( "Génération SEO : {$title}" );

		$clean_text   = wp_strip_all_tags( $text );
		$clean_text   = mb_substr( $clean_text, 0, 8000 );
		$user_message = "Titre: {$title}\nContenu: {$clean_text}";

		$result = self::call_api( self::get_seo_prompt(), $user_message, self::MAX_TOKENS_SEO );

		if ( is_wp_error( $result ) ) {
			Redactio_Logger::error( 'SEO échoué : ' . $result->get_error_message() );
			return $result;
		}

		return self::parse_seo_json( $result['content'] );
	}

	// ─── Mode chunked ─────────────────────────────────────────────────────────

	private static function improve_chunked( string $html, string $title ) {
		$chunks      = self::chunk_html( $html );
		$total       = count( $chunks );
		$results     = [];
		$article_key = md5( 'readability_' . $title );

		Redactio_Logger::info( "Chunked : {$total} chunk(s) pour \"{$title}\"." );

		foreach ( $chunks as $i => $chunk ) {
			$index     = $i + 1;
			$cache_key = 'redactio_chunk_' . $article_key . '_' . $index;
			$cached    = get_transient( $cache_key );

			$progress = [
				'current'     => $index,
				'total'       => $total,
				'percent'     => (int) round( $index / $total * 100 ),
				'article'     => mb_substr( $title, 0, 60 ),
				'status'      => 'running',
				'last_update' => time(),
			];

			if ( false !== $cached ) {
				Redactio_Logger::info( "Chunk {$index}/{$total} restauré depuis le cache." );
				$results[] = $cached;
				set_transient( 'redactio_progress', $progress, 600 );
				continue;
			}

			set_transient( 'redactio_progress', $progress, 600 );
			Redactio_Logger::info( "Chunk {$index}/{$total} (" . strlen( $chunk ) . ' chars).' );

			if ( $index > 1 ) {
				usleep( 500000 );
			}

			$system = self::get_chunk_readability_prompt( $index, $total );
			$result = self::call_api( $system, $chunk, self::MAX_TOKENS_CEILING );

			if ( is_wp_error( $result ) ) {
				set_transient( 'redactio_progress', [
					'status'      => 'error',
					'message'     => $result->get_error_message(),
					'last_update' => time(),
				], 300 );
				return $result;
			}

			$improved = $result['content'];
			set_transient( $cache_key, $improved, self::CHUNK_CACHE_TTL );
			$results[] = $improved;
		}

		set_transient( 'redactio_progress', [ 'status' => 'done', 'percent' => 100, 'last_update' => time() ], 300 );

		for ( $i = 1; $i <= $total; $i++ ) {
			delete_transient( 'redactio_chunk_' . $article_key . '_' . $i );
		}

		return implode( '', $results );
	}

	// ─── Chunking ─────────────────────────────────────────────────────────────

	private static function chunk_html( string $html ): array {
		$chunks = self::split_by_tag( $html, 'h2' );
		$result = [];

		foreach ( $chunks as $chunk ) {
			if ( strlen( $chunk ) > self::MAX_CHUNK_SIZE ) {
				$sub = self::split_by_tag( $chunk, 'h3' );
				foreach ( $sub as $s ) {
					if ( strlen( $s ) > self::MAX_CHUNK_SIZE ) {
						$result = array_merge( $result, self::split_by_paragraphs( $s ) );
					} else {
						$result[] = $s;
					}
				}
			} else {
				$result[] = $chunk;
			}
		}

		return self::merge_small_chunks( $result );
	}

	private static function split_by_tag( string $html, string $tag ): array {
		$parts = preg_split( '/(<' . preg_quote( $tag, '/' ) . '[\s>])/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( false === $parts || count( $parts ) <= 1 ) {
			return [ $html ];
		}

		$chunks  = [];
		$current = array_shift( $parts );

		while ( count( $parts ) >= 2 ) {
			$delimiter = array_shift( $parts );
			$content   = array_shift( $parts );
			if ( '' !== trim( $current ) ) {
				$chunks[] = $current;
			}
			$current = $delimiter . $content;
		}

		if ( '' !== trim( $current ) ) {
			$chunks[] = $current;
		}

		return $chunks ?: [ $html ];
	}

	private static function split_by_paragraphs( string $html ): array {
		$parts    = explode( '</p>', $html );
		$trailing = array_pop( $parts );
		$chunks   = [];
		$buffer   = '';

		foreach ( $parts as $part ) {
			$buffer .= $part . '</p>';
			if ( strlen( $buffer ) >= self::MIN_CHUNK_SIZE ) {
				$chunks[] = $buffer;
				$buffer   = '';
			}
		}

		$remaining = $buffer . $trailing;
		if ( '' !== trim( $remaining ) ) {
			if ( ! empty( $chunks ) ) {
				$chunks[ count( $chunks ) - 1 ] .= $remaining;
			} else {
				$chunks[] = $remaining;
			}
		}

		return $chunks ?: [ $html ];
	}

	private static function merge_small_chunks( array $chunks ): array {
		if ( count( $chunks ) <= 1 ) {
			return $chunks;
		}

		$result = [];
		$buffer = '';

		foreach ( $chunks as $chunk ) {
			$buffer .= $chunk;
			if ( strlen( $buffer ) >= self::MIN_CHUNK_SIZE ) {
				$result[] = $buffer;
				$buffer   = '';
			}
		}

		if ( '' !== $buffer ) {
			if ( ! empty( $result ) ) {
				$result[ count( $result ) - 1 ] .= $buffer;
			} else {
				$result[] = $buffer;
			}
		}

		return $result ?: $chunks;
	}

	// ─── Prompts ──────────────────────────────────────────────────────────────

	private static function get_readability_prompt(): string {
		return "Tu es un rédacteur expert pour le blog tech francophone GeekLabo.fr, spécialisé en domotique.\n"
			. "Réécris le texte HTML ci-dessous pour en améliorer significativement la lisibilité.\n\n"
			. "Règles ABSOLUES (ne jamais enfreindre) :\n"
			. "- Préserve INTÉGRALEMENT toutes les balises HTML et leurs attributs (href, src, class, id, data-*, style…)\n"
			. "- Préserve INTÉGRALEMENT tous les commentaires WordPress Gutenberg (<!-- wp:... --> et <!-- /wp:... -->) sans les modifier d'un seul caractère\n"
			. "- Ne supprime, n'ajoute et ne déplace aucune image (<img>), aucun lien (<a>), aucune balise structurelle (<h2>, <h3>, <ul>, <ol>…)\n"
			. "- Ne traduis rien — le texte est déjà en français\n"
			. "- Garde les noms propres et termes techniques exacts : Home Assistant, ESPHome, Zigbee, Matter, Thread, MQTT, Z-Wave, etc.\n"
			. "- Retourne UNIQUEMENT le HTML, sans balise <html>/<body>/<head>, sans markdown, sans explication\n\n"
			. "Améliorations OBLIGATOIRES (tu dois les appliquer partout où c'est possible) :\n"
			. "1. COUPER les phrases longues (>25 mots) en deux phrases distinctes et claires — c'est la priorité absolue\n"
			. "2. REMPLACER les tournures passives (« est utilisé par », « a été créé ») par des formulations actives directes\n"
			. "3. AJOUTER des mots de liaison (ainsi, cependant, en outre, par conséquent, c'est pourquoi, en pratique…)\n"
			. "4. VARIER le début des phrases — interdire deux phrases consécutives commençant par le même mot\n"
			. "5. RENDRE l'introduction de chaque section plus accrocheuse et directe\n"
			. "6. MAINTENIR un ton technique mais accessible pour des passionnés de domotique";
	}

	private static function get_chunk_readability_prompt( int $index, int $total ): string {
		return self::get_readability_prompt()
			. "\n\nContexte : ceci est la PARTIE {$index}/{$total} d'un article plus long — ne pas ajouter d'introduction ni de conclusion."
			. "\nRetourne UNIQUEMENT le HTML de cette section, sans aucune balise englobante (<html>, <body>, <head>).";
	}

	private static function get_seo_prompt(): string {
		return "Tu es un expert SEO spécialisé en domotique et technologie francophone.\n"
			. "Retourne UNIQUEMENT un objet JSON valide (pas de markdown, pas de commentaire, pas de balise ```json) avec exactement ces clés :\n"
			. "{\n"
			. "  \"meta_title\": \"...\",\n"
			. "  \"meta_description\": \"...\",\n"
			. "  \"slug\": \"...\",\n"
			. "  \"focus_keyword\": \"...\",\n"
			. "  \"tags\": [\"...\", \"...\"]\n"
			. "}\n\n"
			. "Contraintes :\n"
			. "- meta_title : 50-60 caractères, mot-clé principal en début de titre\n"
			. "- meta_description : 140-155 caractères, accrocheur avec appel à l'action\n"
			. "- slug : uniquement tirets et caractères ASCII, sans accents, 3-7 mots, ≤40 caractères\n"
			. "- focus_keyword : expression principale recherchée\n"
			. "- tags : 6 à 8 tags pertinents en français";
	}

	// ─── Appel API Claude ─────────────────────────────────────────────────────

	private static function call_api( string $system_prompt, string $user_message, int $max_tokens ) {
		$api_key = self::get_api_key();
		if ( is_wp_error( $api_key ) ) {
			return $api_key;
		}

		$model = defined( 'REDACTIO_CLAUDE_MODEL' )
			? REDACTIO_CLAUDE_MODEL
			: (string) get_option( 'redactio_claude_model', self::DEFAULT_MODEL );

		$body = wp_json_encode( [
			'model'      => $model,
			'max_tokens' => $max_tokens,
			'system'     => $system_prompt,
			'messages'   => [ [ 'role' => 'user', 'content' => $user_message ] ],
		] );

		$args = [
			'method'    => 'POST',
			'timeout'   => 180,
			'sslverify' => true,
			'headers'   => [
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => self::API_VERSION,
				'anthropic-beta'    => 'output-128k-2025-02-19',
			],
			'body' => $body,
		];

		$last_error = null;

		for ( $attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++ ) {
			$response = wp_remote_post( self::API_ENDPOINT, $args );

			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				Redactio_Logger::warning( "API — erreur réseau (tentative {$attempt}) : " . $response->get_error_message() );
				if ( $attempt < self::MAX_RETRIES ) {
					sleep( self::RETRY_BASE_DELAY ** $attempt );
				}
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( 200 !== $code ) {
				if ( 429 === $code || 529 === $code ) {
					$retry_after = ( 429 === $code )
						? (int) ( wp_remote_retrieve_header( $response, 'retry-after' ) ?: 60 )
						: 45;
					$label = ( 429 === $code ) ? 'Rate Limit (429)' : 'Overloaded (529)';
					Redactio_Logger::warning( "API — {$label}, attente {$retry_after}s." );
					$last_error = new WP_Error( 'api_throttled', "{$label} — Retry dans {$retry_after}s." );
					if ( $attempt < self::MAX_RETRIES ) {
						sleep( $retry_after );
					}
					continue;
				}

				$raw_body     = wp_remote_retrieve_body( $response );
				$error_body   = json_decode( $raw_body, true );
				$error_detail = is_array( $error_body )
					? ( $error_body['error']['message'] ?? $error_body['error']['type'] ?? mb_substr( $raw_body, 0, 300 ) )
					: mb_substr( $raw_body, 0, 300 );

				$last_error = new WP_Error( 'api_http_error', "HTTP {$code} : {$error_detail}" );
				Redactio_Logger::error( "API — HTTP {$code} : {$error_detail}" );

				if ( in_array( $code, [ 400, 401, 403 ], true ) ) {
					break;
				}
				if ( $attempt < self::MAX_RETRIES ) {
					sleep( self::RETRY_BASE_DELAY ** $attempt );
				}
				continue;
			}

			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! isset( $decoded['content'][0]['text'] ) ) {
				$last_error = new WP_Error( 'api_invalid_response', 'Réponse API invalide ou vide.' );
				if ( $attempt < self::MAX_RETRIES ) {
					sleep( self::RETRY_BASE_DELAY ** $attempt );
				}
				continue;
			}

			$tokens = (int) ( $decoded['usage']['output_tokens'] ?? 0 );

			if ( isset( $decoded['stop_reason'] ) && 'max_tokens' === $decoded['stop_reason'] ) {
				Redactio_Logger::warning( "API — stop_reason=max_tokens ({$tokens} tokens)." );
				return new WP_Error( 'max_tokens_reached', 'Réponse tronquée (max_tokens atteint).' );
			}

			return [ 'content' => $decoded['content'][0]['text'], 'tokens' => $tokens ];
		}

		return $last_error ?? new WP_Error( 'api_max_retries', 'Nombre maximum de tentatives atteint.' );
	}

	// ─── Clé API ──────────────────────────────────────────────────────────────

	private static function get_api_key() {
		if ( defined( 'REDACTIO_CLAUDE_API_KEY' ) && ! empty( REDACTIO_CLAUDE_API_KEY ) ) {
			return REDACTIO_CLAUDE_API_KEY;
		}

		$stored = get_option( 'redactio_api_key', '' );
		if ( ! empty( $stored ) ) {
			$decrypted = self::decrypt_api_key( $stored );
			if ( $decrypted ) {
				return $decrypted;
			}
		}

		return new WP_Error( 'no_api_key', __( 'Clé API Claude non configurée. Allez dans Réglages → Rédactio.', 'redactio' ) );
	}

	public static function encrypt_api_key( string $key ): string {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$secret = self::derive_secret_key();
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $key, $nonce, $secret );
			return base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		return base64_encode( $key ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function decrypt_api_key( string $stored ): string {
		$decoded = base64_decode( $stored ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $decoded ) {
			return '';
		}
		if ( function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$secret    = self::derive_secret_key();
			$nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
			if ( strlen( $decoded ) <= $nonce_len ) {
				return '';
			}
			$nonce  = substr( $decoded, 0, $nonce_len );
			$cipher = substr( $decoded, $nonce_len );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $secret );
			return false !== $plain ? $plain : '';
		}
		return $decoded;
	}

	private static function derive_secret_key(): string {
		return substr( hash( 'sha256', ABSPATH . wp_salt( 'auth' ), true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	// ─── Parsing SEO ──────────────────────────────────────────────────────────

	private static function parse_seo_json( string $raw ) {
		$clean = preg_replace( '/^```(?:json)?\s*/m', '', $raw );
		$clean = preg_replace( '/\s*```$/m', '', $clean );
		$data  = json_decode( trim( $clean ), true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_Error( 'json_parse_error', 'JSON SEO invalide : ' . json_last_error_msg() );
		}

		foreach ( [ 'meta_title', 'meta_description', 'slug', 'focus_keyword', 'tags' ] as $key ) {
			if ( ! isset( $data[ $key ] ) ) {
				return new WP_Error( 'json_missing_key', "Clé SEO manquante : {$key}" );
			}
		}

		return [
			'meta_title'       => sanitize_text_field( mb_substr( (string) $data['meta_title'], 0, 60 ) ),
			'meta_description' => sanitize_text_field( mb_substr( (string) $data['meta_description'], 0, 155 ) ),
			'slug'             => sanitize_title( (string) $data['slug'] ),
			'focus_keyword'    => sanitize_text_field( (string) $data['focus_keyword'] ),
			'tags'             => array_map( 'sanitize_text_field', (array) $data['tags'] ),
		];
	}
}
