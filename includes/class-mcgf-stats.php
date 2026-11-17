<?php
/**
 * Distribution statistics: daily counters.
 *
 * One row per day + kind + item, incremented with a single atomic UPSERT, so
 * concurrent submissions never lose a count. Nothing about the person who
 * submitted the form is stored (their data already lives in the entry).
 *
 * Item = "list_id|form_id|email".
 *
 * Kinds:
 *   assign   New slot taken from the bag (one per submission).
 *   sticky   Same person again within the sticky window (no slot spent).
 *   sent     Email handed to the sending server.
 *   fail     A sending attempt failed (will be retried).
 *   retried  Sent after one or more retries.
 *   lost     Gave up after every retry (needs manual action).
 *
 * Recording can NEVER break a submission: everything is wrapped and silenced.
 *
 * @package MulticorreoGF
 */

defined( 'WPINC' ) || exit;

/**
 * Stats class.
 */
class MCGF_Stats {

	const DB_VERSION = '1';
	const OPT_DB     = 'mcgf_stats_db';
	const RETRY      = 'mcgf_stats_install_retry';
	const KEEP_DAYS  = 400;

	// Primary key must fit the 767 bytes index limit of old MySQL with
	// utf8mb4: 3 + 10*4 + 170*4 = 723 bytes.
	const ITEM_MAX = 170;

	const KINDS = array( 'assign', 'sticky', 'sent', 'fail', 'retried', 'lost' );

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'mcgf_stats';
	}

	/**
	 * Enabled? (filter 'mcgf_stats' to switch them off).
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) apply_filters( 'mcgf_stats', true );
	}

	/**
	 * Today in the site time zone.
	 *
	 * @return string
	 */
	public static function today() {
		return current_time( 'Y-m-d' );
	}

	/**
	 * Item key.
	 *
	 * @param int    $list_id List.
	 * @param int    $form_id Form.
	 * @param string $email   Email.
	 * @return string
	 */
	public static function item( $list_id, $form_id, $email ) {
		return (int) $list_id . '|' . (int) $form_id . '|' . $email;
	}

	/**
	 * Split an item key.
	 *
	 * @param string $item Item.
	 * @return array [ list_id, form_id, email ]
	 */
	public static function split( $item ) {
		$parts = explode( '|', (string) $item, 3 );
		return array( (int) $parts[0], isset( $parts[1] ) ? (int) $parts[1] : 0, isset( $parts[2] ) ? $parts[2] : '' );
	}

	/**
	 * Create/upgrade the table when needed (a failed install retries hourly).
	 *
	 * @return bool
	 */
	public static function maybe_install() {

		if ( self::DB_VERSION === get_option( self::OPT_DB ) ) {
			return true;
		}

		if ( get_transient( self::RETRY ) ) {
			return false;
		}

		return self::install();
	}

	/**
	 * Create the table.
	 *
	 * @return bool
	 */
	public static function install() {

		global $wpdb;

		try {
			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}

			$table = self::table();
			$sql   = "CREATE TABLE {$table} (
  day date NOT NULL,
  kind varchar(10) NOT NULL,
  item varchar(" . self::ITEM_MAX . ") NOT NULL DEFAULT '',
  hits int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,kind,item)
) " . $wpdb->get_charset_collate() . ';';

			$suppress = $wpdb->suppress_errors( true );
			dbDelta( $sql );
			$wpdb->suppress_errors( $suppress );

			$ok = self::table_exists();
		} catch ( \Throwable $e ) {
			$ok = false;
		}

		if ( $ok ) {
			update_option( self::OPT_DB, self::DB_VERSION, true );
			delete_transient( self::RETRY );
		} else {
			set_transient( self::RETRY, 1, HOUR_IN_SECONDS );
		}

		return $ok;
	}

	/**
	 * Does the table exist?
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		$table = self::table();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Add one hit to a kind for an item, today.
	 *
	 * @param string $kind Kind.
	 * @param string $item Item.
	 * @return bool
	 */
	public static function record( $kind, $item ) {

		if ( ! self::enabled() || ! in_array( $kind, self::KINDS, true ) ) {
			return false;
		}

		global $wpdb;

		try {
			if ( ! self::maybe_install() ) {
				return false;
			}

			$table    = self::table();
			$suppress = $wpdb->suppress_errors( true );
			$result   = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (day, kind, item, hits) VALUES (%s, %s, %s, 1) ON DUPLICATE KEY UPDATE hits = hits + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::today(),
					$kind,
					self::clip( $item )
				)
			);
			$wpdb->suppress_errors( $suppress );

			if ( false === $result && ! self::table_exists() ) {
				delete_option( self::OPT_DB );
			}

			return false !== $result;

		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Cut to the column size (multibyte safe).
	 *
	 * @param string $item Item.
	 * @return string
	 */
	private static function clip( $item ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $item, 0, self::ITEM_MAX ) : substr( $item, 0, self::ITEM_MAX );
	}

	/**
	 * Totals by kind and item for a range.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array [ kind ][ item ] => hits
	 */
	public static function totals( $from, $to ) {

		global $wpdb;

		$out = array_fill_keys( self::KINDS, array() );

		if ( ! self::maybe_install() ) {
			return $out;
		}

		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT kind, item, SUM(hits) AS hits FROM {$table} WHERE day BETWEEN %s AND %s GROUP BY kind, item", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from,
				$to
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row['kind'] ] ) ) {
				$out[ $row['kind'] ][ (string) $row['item'] ] = (int) $row['hits'];
			}
		}

		return $out;
	}

	/**
	 * Daily rows of a range (kind, item, hits per day).
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array
	 */
	public static function rows( $from, $to ) {

		global $wpdb;

		if ( ! self::maybe_install() ) {
			return array();
		}

		$table = self::table();

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT day, kind, item, hits FROM {$table} WHERE day BETWEEN %s AND %s ORDER BY day, kind, item", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from,
				$to
			),
			ARRAY_A
		);
	}

	/**
	 * Delete data older than KEEP_DAYS.
	 *
	 * @return void
	 */
	public static function prune() {

		global $wpdb;

		if ( ! self::maybe_install() ) {
			return;
		}

		$limit = gmdate( 'Y-m-d', strtotime( self::today() . ' -' . self::KEEP_DAYS . ' days' ) );
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Delete every statistic.
	 *
	 * @return void
	 */
	public static function reset() {
		global $wpdb;
		if ( self::maybe_install() ) {
			$table = self::table();
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Drop the table (uninstall).
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		delete_option( self::OPT_DB );
		delete_transient( self::RETRY );
	}
}
