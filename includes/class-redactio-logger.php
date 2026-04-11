<?php
/**
 * Redactio — Système de logs
 *
 * @package   Redactio
 * @author    Guillaume JEUDY <digimarket84@gmail.com>
 * @copyright 2026 Guillaume JEUDY
 * @license   GNU General Public License v3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Redactio_Logger {

	const LEVEL_DEBUG   = 0;
	const LEVEL_INFO    = 1;
	const LEVEL_WARNING = 2;
	const LEVEL_ERROR   = 3;

	const MAX_LOG_SIZE  = 5242880; // 5 Mo

	public static function debug( string $message ): void {
		self::log( self::LEVEL_DEBUG, $message );
	}

	public static function info( string $message ): void {
		self::log( self::LEVEL_INFO, $message );
	}

	public static function warning( string $message ): void {
		self::log( self::LEVEL_WARNING, $message );
	}

	public static function error( string $message ): void {
		self::log( self::LEVEL_ERROR, $message );
	}

	private static function log( int $level, string $message ): void {
		$min_level = self::get_min_level();

		if ( $level < $min_level ) {
			return;
		}

		$labels = [ 'DEBUG', 'INFO', 'WARNING', 'ERROR' ];
		$label  = $labels[ $level ] ?? 'LOG';
		$line   = sprintf(
			'[%s] [%-7s] %s' . PHP_EOL,
			current_time( 'Y-m-d H:i:s' ),
			$label,
			$message
		);

		$file = self::get_log_file();

		// Rotation si > 5 Mo.
		if ( file_exists( $file ) && filesize( $file ) > self::MAX_LOG_SIZE ) {
			rename( $file, $file . '.1' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	}

	private static function get_min_level(): int {
		if ( defined( 'REDACTIO_LOG_LEVEL' ) ) {
			return (int) REDACTIO_LOG_LEVEL;
		}
		if ( get_option( 'redactio_debug_enabled' ) ) {
			return self::LEVEL_DEBUG;
		}
		return self::LEVEL_ERROR;
	}

	public static function get_log_file(): string {
		return defined( 'REDACTIO_LOG_FILE' )
			? REDACTIO_LOG_FILE
			: WP_CONTENT_DIR . '/redactio-debug.log';
	}

	public static function get_last_lines( int $count = 100 ): array {
		$file = self::get_log_file();
		if ( ! file_exists( $file ) ) {
			return [];
		}
		$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( false === $lines ) {
			return [];
		}
		return array_slice( array_reverse( $lines ), 0, $count );
	}

	public static function clear(): void {
		$file = self::get_log_file();
		if ( file_exists( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $file, '' );
		}
	}
}
