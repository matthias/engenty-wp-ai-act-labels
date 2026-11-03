<?php
/**
 * The image analysis of many pictures as a job on the server: WP-Cron runs it in steps, so it goes on
 * when the tool is closed, every window sees how far it is, and only one job runs at a time.
 *
 * @package AI_Act_Labels
 */

defined( 'ABSPATH' ) || exit;

/**
 * One analysis job: start, steps, stop.
 */
class AI_Act_Labels_Analysis {

	const OPTION = 'ai_act_labels_analysis';
	const HOOK   = 'ai_act_labels_analysis_step';
	const LOCK   = 'ai_act_labels_analysis_lock';

	/** The pictures it looks at: used on the site, no decision yet. */
	const FILTER = 'unchecked';

	/** Seconds one step starts new analyses: one more may take 15, a request often may take 30. */
	const SECONDS = 15;

	/** Failed analyses in a row after which the job stops. */
	const FAILS = 3;

	/** A running job that has not moved for this long no longer holds the next one back. */
	const STALE = 10 * MINUTE_IN_SECONDS;

	/** The step's hook. */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'step' ) );
	}

	/**
	 * The job: `status` (running, stopped, done, failed), `since`, `total`, `done`, `error`, `moved`, and
	 * `measured` – what one analysis took in the last test (`n`, `tokens`, `seconds`, `cost` or null).
	 *
	 * @return array|null
	 */
	public static function state() {
		$job = get_option( self::OPTION, null );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Whether a job is running and moving.
	 *
	 * @param array|null $job The job.
	 * @return bool
	 */
	public static function running( $job ) {
		return is_array( $job ) && 'running' === $job['status'] && time() - (int) $job['moved'] < self::STALE;
	}

	/**
	 * Starts a job.
	 *
	 * @param string $since First day (YYYY-MM-DD).
	 * @param string $until Last day (YYYY-MM-DD).
	 * @param int    $limit At most this many pictures.
	 * @param bool   $test  A test: what it takes is kept for the estimate.
	 * @return array|WP_Error The job.
	 */
	public static function start( $since, $until, $limit, $test ) {
		$job = self::state();
		if ( self::running( $job ) ) {
			return new WP_Error( 'ai_act_labels_running', __( 'An analysis is already running.', 'ai-act-image-labels' ), array( 'status' => 409 ) );
		}
		$job = array(
			'status'   => 'running',
			'since'    => $since,
			'until'    => $until,
			'total'    => min( max( 1, (int) $limit ), count( self::ids( $since, $until, -1 ) ) ),
			'done'     => 0,
			'fails'    => 0,
			'skip'     => array(),
			'error'    => '',
			'test'     => (bool) $test,
			'by'       => get_current_user_id(),
			'started'  => time(),
			'moved'    => time(),
			'before'   => self::calls(),
			'measured' => $job['measured'] ?? null,
		);
		update_option( self::OPTION, $job, false );
		self::schedule();
		return $job;
	}

	/**
	 * Stops the job after the picture it is at.
	 *
	 * @return array|null The job.
	 */
	public static function stop() {
		$job = self::state();
		if ( $job && 'running' === $job['status'] ) {
			$job['status'] = 'stopped';
			update_option( self::OPTION, $job, false );
		}
		wp_clear_scheduled_hook( self::HOOK );
		return $job;
	}

	/** Keeps a running job moving: the next step comes when none is due, and WP-Cron is asked now. */
	public static function nudge() {
		if ( ! self::running( self::state() ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) && ! get_transient( self::LOCK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
		spawn_cron();
	}

	/** One step from WP-Cron: pictures one after another until its time is up, then the next step. */
	public static function step() {
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );
		$start = microtime( true );
		while ( microtime( true ) - $start < self::SECONDS ) {
			$job = self::state();
			if ( ! $job || 'running' !== $job['status'] ) {
				break;
			}
			$ids = $job['done'] < $job['total'] ? self::ids( $job['since'], $job['until'], 1, $job['skip'] ) : array();
			if ( ! $ids ) {
				self::finish( $job );
				break;
			}
			$result = AI_Act_Labels_Assistant::review( $ids[0] );
			// Read again: a stop may have come while the provider answered.
			$job          = self::state();
			$job['moved'] = time();
			if ( is_wp_error( $result ) ) {
				// The picture is left out of this job; the next one comes, three failures in a row end it.
				$job['skip'][] = $ids[0];
				$job['error']  = $result->get_error_message();
				++$job['fails'];
				if ( $job['fails'] >= self::FAILS ) {
					$job['status'] = 'failed';
				}
			} else {
				++$job['done'];
				$job['fails'] = 0;
				$job['error'] = '';
			}
			update_option( self::OPTION, $job, false );
		}
		delete_transient( self::LOCK );
		if ( self::running( self::state() ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}

	/**
	 * The pictures of the job's list without a decision and without an assessment, newest first.
	 *
	 * @param string $since First day (YYYY-MM-DD); empty from the first.
	 * @param string $until Last day (YYYY-MM-DD); empty up to today.
	 * @param int    $limit At most (-1: all).
	 * @param int[]  $skip  Left out.
	 * @return int[]
	 */
	public static function ids( $since, $until, $limit, array $skip = array() ) {
		$args  = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'post__not_in'   => array_map( 'intval', $skip ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				AI_Act_Labels::meta_query( self::FILTER ),
				array(
					'key'     => AI_Act_Labels::META,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => AI_Act_Labels::META_HINT,
					'compare' => 'NOT EXISTS',
				),
			),
		);
		$dates = array( 'inclusive' => true );
		if ( self::is_day( $since ) ) {
			$dates['after'] = $since . ' 00:00:00';
		}
		if ( self::is_day( $until ) ) {
			$dates['before'] = $until . ' 23:59:59';
		}
		if ( count( $dates ) > 1 ) {
			$args['date_query'] = array( $dates );
		}
		return array_map( 'intval', get_posts( $args ) );
	}

	/**
	 * Whether a text is a day (YYYY-MM-DD).
	 *
	 * @param mixed $day Text.
	 * @return bool
	 */
	public static function is_day( $day ) {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $day );
	}

	/**
	 * The pictures of the job's list by the day they were uploaded: without an assessment, and assessed.
	 *
	 * @return array YYYY-MM-DD => [ open, analysed ], oldest first.
	 */
	public static function days() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one grouped count over the plugin's own markers.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(p.post_date) AS d, SUM(h.meta_id IS NULL) AS open, SUM(h.meta_id IS NOT NULL) AS analysed
				FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} h ON h.post_id = p.ID AND h.meta_key = %s
				WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s
				GROUP BY d ORDER BY d",
				AI_Act_Labels::OPEN_USED,
				AI_Act_Labels::META_HINT,
				'image/%'
			)
		);
		$days = array();
		foreach ( $rows as $row ) {
			$days[ $row->d ] = array( (int) $row->open, (int) $row->analysed );
		}
		return $days;
	}

	/**
	 * The analyses of this month so far.
	 *
	 * @return array `n`, `tokens`, `seconds`, `costed`, `cost` (US dollars).
	 */
	public static function calls() {
		$usage = get_option( AI_Act_Labels_Assistant::USAGE, array() );
		$calls = is_array( $usage ) && is_array( $usage['calls'] ?? null ) ? $usage['calls'] : array();
		return array(
			'n'       => (int) ( $calls['n'] ?? 0 ),
			'tokens'  => (int) ( $calls['tokens'] ?? 0 ),
			'seconds' => (float) ( $calls['seconds'] ?? 0 ),
			'costed'  => (int) ( $calls['costed'] ?? 0 ),
			'cost'    => (float) ( $calls['cost'] ?? 0 ),
		);
	}

	/**
	 * Ends a job; after a test, keeps what one analysis took.
	 *
	 * @param array $job The job.
	 */
	private static function finish( array $job ) {
		$job['status'] = 'done';
		$now           = self::calls();
		$n             = $now['n'] - (int) $job['before']['n'];
		if ( $job['test'] && $n > 0 ) {
			$costed          = $now['costed'] - (int) $job['before']['costed'];
			$job['measured'] = array(
				'n'       => $n,
				'tokens'  => ( $now['tokens'] - (int) $job['before']['tokens'] ) / $n,
				'seconds' => ( $now['seconds'] - (float) $job['before']['seconds'] ) / $n,
				'cost'    => $costed > 0 ? ( $now['cost'] - (float) $job['before']['cost'] ) / $costed : null,
			);
		}
		update_option( self::OPTION, $job, false );
	}

	/** The first step, now. */
	private static function schedule() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( time(), self::HOOK );
		spawn_cron();
	}
}
