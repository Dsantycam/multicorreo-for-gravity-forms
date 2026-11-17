<?php
/**
 * Retry queue: no slot is left unsent.
 *
 * When a Multicorreo notification fails, a row is kept here and the very same
 * notification is sent again, to the very same assigned address (the slot was
 * already given to that person), on this schedule after each failure:
 * 5 min, 15 min, 1 h, 3 h, 6 h and 12 h (about 22 hours in total). If it
 * still fails it is marked as "failed": the panel and an admin notice show
 * it, with buttons to retry it by hand.
 *
 * A failure almost always means the sending server (SMTP) is down, not that
 * the address is wrong, so moving the email to another address would only
 * spend more slots for nothing.
 *
 * @package MulticorreoGF
 */

defined( 'WPINC' ) || exit;

/**
 * Queue class.
 */
class MCGF_Queue {

	const DB_VERSION = '1';
	const OPT_DB     = 'mcgf_queue_db';
	const CRON       = 'mcgf_retry_queue';
	const SCHEDULE   = 'mcgf_five_minutes';
	const BATCH      = 20;
	const KEEP_SENT  = 30; // Days a solved row is kept (for the panel).

	/**
	 * Wait (seconds) after the 1st, 2nd... failure.
	 *
	 * @return array
	 */
	public static function delays() {
		return (array) apply_filters(
			'mcgf_retry_delays',
			array( 5 * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS, 3 * HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, 12 * HOUR_IN_SECONDS )
		);
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'mcgf_queue';
	}

	/**
	 * Cron hooks.
	 *
	 * @return void
	 */
	public static function hooks() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::CRON, array( __CLASS__, 'process' ) );
	}

	/**
	 * Five minutes schedule.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function schedules( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'Multicorreo: cada 5 minutos',
		);
		return $schedules;
	}

	/**
	 * Make sure the retry cron runs while something is pending.
	 *
	 * @return void
	 */
	public static function ensure_cron() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::CRON );
		}
	}

	/**
	 * Create/upgrade the table when needed.
	 *
	 * @return bool
	 */
	public static function maybe_install() {
		if ( self::DB_VERSION === get_option( self::OPT_DB ) ) {
			return true;
		}
		if ( get_transient( 'mcgf_queue_install_retry' ) ) {
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
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) unsigned NOT NULL,
  form_id int(10) unsigned NOT NULL,
  nid varchar(40) NOT NULL,
  list_id int(10) unsigned NOT NULL DEFAULT 0,
  email varchar(254) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT 'pending',
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  next_try datetime NOT NULL,
  last_error text NULL,
  created datetime NOT NULL,
  updated datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY entry_nid (entry_id,nid),
  KEY status_next (status,next_try)
) " . $wpdb->get_charset_collate() . ';';

			$suppress = $wpdb->suppress_errors( true );
			dbDelta( $sql );
			$wpdb->suppress_errors( $suppress );

			$ok = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		} catch ( \Throwable $e ) {
			$ok = false;
		}

		if ( $ok ) {
			update_option( self::OPT_DB, self::DB_VERSION, true );
			delete_transient( 'mcgf_queue_install_retry' );
		} else {
			set_transient( 'mcgf_queue_install_retry', 1, HOUR_IN_SECONDS );
		}

		return $ok;
	}

	/**
	 * GMT datetime.
	 *
	 * @param int $timestamp Timestamp (0 = now).
	 * @return string
	 */
	private static function gmt( $timestamp = 0 ) {
		return gmdate( 'Y-m-d H:i:s', $timestamp ? $timestamp : time() );
	}

	/**
	 * Row for an entry + notification.
	 *
	 * @param int    $entry_id Entry.
	 * @param string $nid      Notification id.
	 * @return array|null
	 */
	public static function find( $entry_id, $nid ) {
		global $wpdb;
		if ( ! self::maybe_install() ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE entry_id = %d AND nid = %s", $entry_id, $nid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $row : null;
	}

	/**
	 * Row by id.
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		if ( ! self::maybe_install() ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $row : null;
	}

	/**
	 * Register a failed attempt and schedule the next one.
	 *
	 * @param array  $ctx   [ entry_id, form_id, nid, list_id, email ].
	 * @param string $error Error message.
	 * @return string 'pending' (will retry) or 'failed' (gave up).
	 */
	public static function failure( $ctx, $error ) {

		global $wpdb;

		if ( ! self::maybe_install() ) {
			return 'failed';
		}

		$table  = self::table();
		$row    = self::find( $ctx['entry_id'], $ctx['nid'] );
		$delays = array_values( self::delays() );

		// A solved or abandoned row that fails again (e.g. manual resend)
		// starts a fresh retry cycle.
		$attempts = ( $row && 'pending' === $row['status'] ) ? (int) $row['attempts'] + 1 : 1;
		$status   = $attempts > count( $delays ) ? 'failed' : 'pending';
		$next     = 'pending' === $status ? time() + (int) $delays[ $attempts - 1 ] : time();
		$error    = function_exists( 'mb_substr' ) ? mb_substr( (string) $error, 0, 1000 ) : substr( (string) $error, 0, 1000 );

		$data = array(
			'form_id'    => (int) $ctx['form_id'],
			'list_id'    => (int) $ctx['list_id'],
			'email'      => (string) $ctx['email'],
			'status'     => $status,
			'attempts'   => $attempts,
			'next_try'   => self::gmt( $next ),
			'last_error' => $error,
			'updated'    => self::gmt(),
		);

		if ( $row ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $row['id'] ) );
		} else {
			$data['entry_id'] = (int) $ctx['entry_id'];
			$data['nid']      = (string) $ctx['nid'];
			$data['created']  = self::gmt();
			$wpdb->insert( $table, $data );
		}

		if ( 'pending' === $status ) {
			self::ensure_cron();
		}

		return $status;
	}

	/**
	 * Mark an entry + notification as sent.
	 *
	 * @param int    $entry_id Entry.
	 * @param string $nid      Notification id.
	 * @return bool True when it was waiting for a retry (it was recovered).
	 */
	public static function success( $entry_id, $nid ) {

		global $wpdb;

		$row = self::find( $entry_id, $nid );

		if ( ! $row || 'sent' === $row['status'] ) {
			return false;
		}

		$wpdb->update(
			self::table(),
			array(
				'status'  => 'sent',
				'updated' => self::gmt(),
			),
			array( 'id' => (int) $row['id'] )
		);

		return true;
	}

	/**
	 * Rows that are due.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function due( $limit = self::BATCH ) {
		global $wpdb;
		if ( ! self::maybe_install() ) {
			return array();
		}
		$table = self::table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'pending' AND next_try <= %s ORDER BY next_try LIMIT %d", self::gmt(), $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Rows by status (for the panel).
	 *
	 * @param array $statuses Statuses.
	 * @param int   $limit    Max rows.
	 * @return array
	 */
	public static function rows( $statuses = array( 'pending', 'failed' ), $limit = 200 ) {
		global $wpdb;
		if ( ! self::maybe_install() || ! $statuses ) {
			return array();
		}
		$table = self::table();
		$in    = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN ({$in}) ORDER BY status DESC, next_try LIMIT %d", array_merge( $statuses, array( $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Counts by status.
	 *
	 * @return array [ pending => n, failed => n ]
	 */
	public static function counts() {
		global $wpdb;
		$out = array(
			'pending' => 0,
			'failed'  => 0,
		);
		if ( ! self::maybe_install() ) {
			return $out;
		}
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} WHERE status IN ('pending','failed') GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Put a row back in the queue, due now, with a fresh retry cycle.
	 *
	 * @param int $id Row id.
	 * @return void
	 */
	public static function requeue( $id ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'status'   => 'pending',
				'attempts' => 0,
				'next_try' => self::gmt(),
				'updated'  => self::gmt(),
			),
			array( 'id' => (int) $id )
		);
		self::ensure_cron();
	}

	/**
	 * Remove a row (the admin decided not to send it).
	 *
	 * @param int $id Row id.
	 * @return void
	 */
	public static function discard( $id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * Mark a row as failed without retrying (entry or notification gone).
	 *
	 * @param array  $row    Row.
	 * @param string $reason Reason.
	 * @return void
	 */
	public static function abandon( $row, $reason ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'status'     => 'failed',
				'last_error' => $reason,
				'updated'    => self::gmt(),
			),
			array( 'id' => (int) $row['id'] )
		);
	}

	/**
	 * Cron: send what is due. Stops the cron when nothing is pending.
	 *
	 * @param int $only Process only this row id (manual "retry now").
	 * @return void
	 */
	public static function process( $only = 0 ) {

		global $wpdb;

		if ( ! self::maybe_install() || ! class_exists( 'MCGF_Notifications' ) ) {
			return;
		}

		// Never two runs at the same time (cron + manual click).
		$locked = 1 === (int) $wpdb->get_var( "SELECT GET_LOCK('mcgf_queue', 0)" );
		if ( ! $locked ) {
			return;
		}

		try {
			$rows = $only ? array_filter( array( self::get( $only ) ) ) : self::due();

			foreach ( $rows as $row ) {
				MCGF_Notifications::instance()->retry( $row );
			}

			$table = self::table();

			// Keep solved rows for a while, then forget them.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status = 'sent' AND updated < %s", self::gmt( time() - self::KEEP_SENT * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $pending ) {
				wp_clear_scheduled_hook( self::CRON );
			}

			MCGF_Stats::prune();

		} finally {
			$wpdb->query( "SELECT RELEASE_LOCK('mcgf_queue')" );
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
		delete_transient( 'mcgf_queue_install_retry' );
	}
}
