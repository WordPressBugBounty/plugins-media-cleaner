<?php

// Media Cleaner's card on the Meow Apps dashboard (common/admin.php reads it through
// 'wpmc_meowapps_status'). The dashboard has to open instantly, so this only reads what the
// last scan stored: it never scans, and never walks the uploads folder.
class Meow_WPMC_Status {

	const STALE_DAYS = 30;

	private $core;

	public function __construct( $core ) {
		$this->core = $core;
		add_filter( 'wpmc_meowapps_status', array( $this, 'get' ) );
	}

	public function get( $status ) {
		global $wpdb;
		// The runs only load on Media Cleaner's own screens, and the dashboard is not one of them.
		$runs = $this->core->runs ? $this->core->runs : new Meow_WPMC_Runs( $this->core );
		if ( (int) get_option( Meow_WPMC_Runs::SCHEMA_OPTION, 0 ) < Meow_WPMC_Runs::SCHEMA_VERSION || !$runs->tables_exist() ) {
			return $status;
		}
		$dashboard = admin_url( 'upload.php?page=wpmc_dashboard' );
		$todos = array();

		if ( $runs->get_resumable() ) {
			$todos[] = array(
				'text' => __( 'A scan was interrupted', 'media-cleaner' ),
				'severity' => 'warning',
				'action' => array( 'label' => __( 'Resume', 'media-cleaner' ), 'url' => $dashboard ),
			);
		}

		$run = $runs->get( $runs->get_active_id() );
		if ( !$run || $run->status !== 'completed' ) {
			if ( !$todos ) {
				$todos[] = array(
					'text' => __( 'No scan yet', 'media-cleaner' ),
					'severity' => 'info',
					'action' => array( 'label' => __( 'Scan', 'media-cleaner' ), 'url' => $dashboard ),
				);
			}
			return array( 'todos' => $todos );
		}

		$table = $runs->table( 'scan' );
		$issues = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS entries, COALESCE(SUM(size), 0) AS size FROM $table WHERE run_id = %d AND deleted = 0 AND ignored = 0",
			(int) $run->id
		) );
		$trash = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS entries, COALESCE(SUM(size), 0) AS size FROM $table WHERE run_id = %d AND deleted = 1",
			(int) $run->id
		) );
		$count = $issues ? (int) $issues->entries : 0;
		$size = $issues ? (int) $issues->size : 0;

		if ( $count > 0 ) {
			$todos[] = array(
				// Space to free is an opportunity, not a problem: as a warning, nearly every scanned
				// site would stay orange for good, and orange would stop meaning anything.
				'text' => sprintf( '%s (%s)', $this->describe( $run->method, $count ), size_format( $size, 1 ) ),
				'severity' => 'info',
				'action' => array( 'label' => __( 'Review', 'media-cleaner' ), 'url' => $dashboard ),
			);
		}

		$finished = strtotime( $run->finished_at . ' UTC' );
		$age = $finished ? human_time_diff( $finished ) : '';
		if ( !$runs->cleanup_allowed() ) {
			// Every release asks for a new scan before deleting (see the Safety Model in CLAUDE.md).
			$todos[] = array(
				'text' => __( 'This version needs a new scan', 'media-cleaner' ),
				'severity' => 'info',
				'action' => array( 'label' => __( 'Scan', 'media-cleaner' ), 'url' => $dashboard ),
			);
		}
		else if ( $finished && $finished < time() - self::STALE_DAYS * DAY_IN_SECONDS ) {
			$todos[] = array(
				/* translators: %s: how long ago, e.g. "2 months". */
				'text' => sprintf( __( 'Last scan %s ago', 'media-cleaner' ), $age ),
				'severity' => 'info',
				'action' => array( 'label' => __( 'Scan', 'media-cleaner' ), 'url' => $dashboard ),
			);
		}

		if ( $trash && (int) $trash->entries > 0 ) {
			$todos[] = array(
				/* translators: %s: a size, e.g. "12 MB". */
				'text' => sprintf( __( 'The trash holds %s', 'media-cleaner' ), size_format( (int) $trash->size, 1 ) ),
				'severity' => 'info',
				'action' => array( 'label' => __( 'Open', 'media-cleaner' ), 'url' => $dashboard ),
			);
		}

		$result = array(
			'metric' => array( 'value' => $size > 0 ? size_format( $size, 1 ) : '0 MB', 'label' => __( 'to free', 'media-cleaner' ) ),
			'todos' => $todos,
		);
		if ( $count === 0 && $age ) {
			/* translators: %s: how long ago, e.g. "3 days". */
			$result['note'] = sprintf( __( 'Last scan %s ago · nothing to clean', 'media-cleaner' ), $age );
		}
		return $result;
	}

	private function describe( $method, $count ) {
		$count_text = number_format_i18n( $count );
		switch ( $method ) {
			case 'files':
				/* translators: %s: a number of files. */
				return sprintf( _n( '%s file not in the Media Library', '%s files not in the Media Library', $count, 'media-cleaner' ), $count_text );
			case 'optimize_thumbnails':
				/* translators: %s: a number of files. */
				return sprintf( _n( '%s unneeded thumbnail', '%s unneeded thumbnails', $count, 'media-cleaner' ), $count_text );
			case 'duplicates':
				/* translators: %s: a number of files. */
				return sprintf( _n( '%s duplicate', '%s duplicates', $count, 'media-cleaner' ), $count_text );
			default:
				/* translators: %s: a number of files. */
				return sprintf( _n( '%s unused file', '%s unused files', $count, 'media-cleaner' ), $count_text );
		}
	}
}
