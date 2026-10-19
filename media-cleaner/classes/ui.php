<?php

class Meow_WPMC_UI {

	private $core = null;
	private $schema_ready = null;

	function __construct( $core ) {
		$this->core = $core;
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
		add_filter( 'media_row_actions', array( $this, 'media_row_actions' ), 10, 2 );
	}

	function admin_menu() {
		if ( !$this->core->can_access_features() ) {
      return;
    }
		add_media_page( 'Media Cleaner Dashboard', __( 'Cleaner', 'media-cleaner' ), 'read', 
			'wpmc_dashboard', array( $this, 'cleaner_dashboard' ), 1 );
	}

	public function cleaner_dashboard() {
		echo '<div id="wpmc-dashboard"></div>';
	}

	/*******************************************************************************
	 * METABOX FOR USAGE
	 ******************************************************************************/

	function add_metabox() {
		add_meta_box( 'mfrh_media_usage_box', 'Media Cleaner', array( $this, 'display_metabox' ), 'attachment', 'side', 'default' );
	}

	private function ensure_schema() {
		if ( $this->schema_ready !== null ) return $this->schema_ready;
		if ( !$this->core->runs ) {
			$this->core->runs = new Meow_WPMC_Runs( $this->core );
		}
		$this->schema_ready = $this->core->runs->maybe_upgrade();
		return $this->schema_ready;
	}

	function display_metabox( $post ) {
		if ( !$this->ensure_schema() ) {
			echo esc_html__( 'Media Cleaner could not prepare its database tables. Open the Cleaner dashboard for details.', 'media-cleaner' );
			return;
		}
		$dashboard = esc_url( admin_url( 'upload.php?page=wpmc_dashboard' ) );

		$places = $this->find_usage( $post->ID );
		if ( !empty( $places ) ) {
			echo '<p>' . esc_html__( 'The last scan found this file in:', 'media-cleaner' ) . '</p><ul style="margin:0 0 0 1em;list-style:disc">';
			foreach ( $places as $place ) {
				// Parser types carry internal tags such as {SAFE}; the reader only needs the place.
				$type = trim( preg_replace( '/\s*\{[A-Z_]+\}/', '', stripslashes( (string) $place->originType ) ) );
				$used_in = is_numeric( $place->origin ) ? get_post( (int) $place->origin ) : null;
				echo '<li>' . esc_html( $type );
				if ( $used_in ) {
					$title = $used_in->post_title !== '' ? $used_in->post_title : __( '(no title)', 'media-cleaner' );
					echo ': <a href="' . esc_url( get_edit_post_link( $used_in->ID ) ) . '">' . esc_html( $title ) . '</a>';
				}
				echo '</li>';
			}
			echo '</ul>';
			return;
		}

		$issue = $this->core->get_issue_for_postId( $post->ID );
		if ( $issue ) {
			echo '<p>';
			$this->core->echo_issue( $issue->issue );
			echo '. <a href="' . $dashboard . '">' . esc_html__( 'Review it in the Cleaner', 'media-cleaner' ) . '</a></p>';
			return;
		}

		echo '<p>' . esc_html__( 'Media Cleaner has no information about this file yet.', 'media-cleaner' )
			. ' <a href="' . $dashboard . '">' . esc_html__( 'Run a scan', 'media-cleaner' ) . '</a></p>';
	}

	// Where the last scan saw this media, by ID or by any of its files, one line per place (the
	// duplicates analysis' own HASH rows are bookkeeping, not places). Each
	// lookup uses its own index; joining them with OR would scan the whole references table.
	private function find_usage( $media_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'mclean_refs';
		$run_id = $this->core->get_run_id();
		$parts = array( $wpdb->prepare(
			"SELECT id, originType, origin FROM $table WHERE run_id = %d AND originType NOT LIKE 'HASH:%%' AND mediaId = %d", $run_id, $media_id
		) );
		foreach ( $this->core->get_paths_from_attachment( $media_id ) as $path ) {
			$parts[] = $wpdb->prepare(
				"SELECT id, originType, origin FROM $table WHERE run_id = %d AND originType NOT LIKE 'HASH:%%' AND mediaUrl_hash = %s AND mediaUrl = %s",
				$run_id, hash( 'sha256', $path ), $path
			);
		}
		$union = implode( ' UNION ', $parts );
		return $wpdb->get_results( "SELECT originType, origin FROM ($union) AS refs
			GROUP BY originType, origin ORDER BY MIN(id) ASC LIMIT 5" );
	}

	function media_row_actions( $actions, $post ) {
		global $current_screen;
		if ( !$current_screen || 'upload' != $current_screen->id )
		    return $actions;
		if ( !$this->ensure_schema() ) return $actions;
		global $wpdb;
		$table_name = $wpdb->prefix . "mclean_scan";
		$run_id = $this->core->get_run_id();
		$res = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_name WHERE run_id = %d AND postId = %d LIMIT 1", $run_id, $post->ID ) );
		$file = get_post_meta( $post->ID, '_wp_attached_file', true );
		$dashboard_url = esc_url( add_query_arg( 'search', rawurlencode( wp_basename( (string) $file ) ), admin_url( 'upload.php?page=wpmc_dashboard' ) ) );
		if ( !empty( $res ) && isset( $actions['delete'] ) )
			$actions['delete'] = "<a href='{$dashboard_url}'>" .
				__( 'Delete with Media Cleaner', 'media-cleaner' ) . "</a>";
		if ( !empty( $res ) && isset( $actions['trash'] ) )
			$actions['trash'] = "<a href='{$dashboard_url}'>" .
				__( 'Trash with Media Cleaner', 'media-cleaner' ) . "</a>";
		if ( !empty( $res ) && isset( $actions['untrash'] ) ) {
			$actions['untrash'] = "<a href='{$dashboard_url}'>" .
				__( 'Restore with Media Cleaner', 'media-cleaner' ) . "</a>";
		}
		return $actions;
	}

}
