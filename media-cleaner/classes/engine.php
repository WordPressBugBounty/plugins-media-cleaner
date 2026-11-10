<?php

class Meow_WPMC_Engine {
	private $core;
	private $admin;
	private $similar_index = null;

	function __construct( $core, $admin ) {
		$this->core = $core;
		$this->admin = $admin;
	}

	/*
		STEP 1: Parse the content, and look for references
	*/

	/**
	 * Returns the posts to check the references
	 * @param int $offset Negative number means no limit
	 * @param int $size   Negative number means no limit
	 * @return NULL|array
	 */
	function get_posts_to_check( $offset = -1, $size = -1 ) {
		global $wpdb;
		$r = null;

		// Maybe we could avoid to check more post_types.
		// SELECT post_type, COUNT(*) FROM `wp_posts` GROUP BY post_type
		$q = <<<SQL
SELECT p.ID FROM $wpdb->posts p
WHERE p.post_status NOT IN ('inherit', 'trash', 'auto-draft')
AND p.post_type NOT IN ('attachment', 'shop_order', 'shop_order_refund', 'nav_menu_item', 'revision', 'auto-draft', 'wphb_minify_group', 'customize_changeset', 'oembed_cache', 'nf_sub', 'jp_img_sitemap', 'jb_store_css')
AND p.post_type NOT LIKE 'dlssus_%'
AND p.post_type NOT LIKE 'ml-slide%'
AND p.post_type NOT LIKE '%acf-%'
AND p.post_type NOT LIKE '%edd_%'
SQL;
		$q .= " ORDER BY p.ID ASC";
		if ( $offset >= 0 && $size >= 0 ) {
			$q .= " LIMIT %d, %d";
			$r = $wpdb->get_col( $wpdb->prepare( $q, $offset, $size ) );

		} else // No limit
			$r = $wpdb->get_col( $q );

		return $r;
	}

	/**
	 * Returns the count of posts to check (memory-efficient alternative to count(get_posts_to_check()))
	 * @return int
	 */
	function count_posts_to_check() {
		global $wpdb;

		$q = <<<SQL
SELECT COUNT(p.ID) FROM $wpdb->posts p
WHERE p.post_status NOT IN ('inherit', 'trash', 'auto-draft')
AND p.post_type NOT IN ('attachment', 'shop_order', 'shop_order_refund', 'nav_menu_item', 'revision', 'auto-draft', 'wphb_minify_group', 'customize_changeset', 'oembed_cache', 'nf_sub', 'jp_img_sitemap', 'jb_store_css')
AND p.post_type NOT LIKE 'dlssus_%'
AND p.post_type NOT LIKE 'ml-slide%'
AND p.post_type NOT LIKE '%acf-%'
AND p.post_type NOT LIKE '%edd_%'
SQL;
		return (int) $wpdb->get_var( $q );
	}

	// Running out of time after real work is done is not a failure, it is a pause: the caller breaks,
	// keeps what it collected and resumes next request. But an item that cannot finish inside a whole
	// fresh request budget is a different thing entirely. The exception escapes the loop, so the
	// batch never reaches write_references() or save_progress(), the offset never advances, and the
	// next request starts on the same item and fails the same way — forever, with nothing in the log
	// to say which item is responsible. So the first item of a request is allowed to fail loudly.
	private function fail_if_first_item( Meow_WPMC_Transient_Exception $e, $message ) {
		if ( $this->core->is_first_request_item() ) {
			throw new RuntimeException( $message, 0, $e );
		}
	}

	// The once-phase can outlast a request. While it does, $limit stays 0 and the block that runs the
	// widgets is entered again on every batch. The scan_once parsers survive that because they are
	// journaled and skipped once complete; the widgets were not, so each batch re-read and re-wrote
	// the same widget references before it could get back to the parser that had not finished yet.
	// Journaled as one unit rather than per widget: a widget's position in sidebars_widgets is not
	// an identity stable enough to resume against halfway through the list.
	private function scan_widgets_once() {
		$runs = $this->core->runs;
		$run_id = $this->core->get_run_id();
		$journal = null;
		if ( $run_id > 0 && $runs ) {
			$journal = $runs->get_work( $run_id, 'scanWidgets', 'widgets' );
			if ( $journal && $journal->status === 'complete' ) return;
			if ( !$journal ) {
				$runs->enqueue_work( $run_id, 'scanWidgets', 'parser', 'widgets' );
				$journal = $runs->get_work( $run_id, 'scanWidgets', 'widgets' );
			}
		}
		global $wp_registered_widgets;
		$syswidgets = is_array( $wp_registered_widgets ) ? $wp_registered_widgets : array();
		$active_widgets = get_option( 'sidebars_widgets' );
		$active_widgets = is_array( $active_widgets ) ? $active_widgets : array();
		foreach ( $active_widgets as $sidebar_name => $widgets ) {
			if ( $sidebar_name != 'wp_inactive_widgets' && !empty( $widgets ) && is_array( $widgets ) ) {
				foreach ( $widgets as $key => $widget ) {
					if ( isset( $syswidgets[ $widget ] ) ) $this->core->safe_do_action( 'wpmc_scan_widget', $syswidgets[ $widget ] );
				}
			}
		}
		$this->core->safe_do_action( 'wpmc_scan_widgets' );
		// Only mark it done once its references are actually stored: a crash between the two would
		// otherwise leave the phase complete and its widgets unrecorded, which reads as unused.
		$this->core->write_references();
		if ( $journal ) $runs->update_work( $journal->id, 'complete', 0 );
	}

	// Parse the posts for references (based on $limit and $limitsize for paging the scan)
	// Runs one extractRefsFrom* step batch after batch until the step is done or the request has
	// spent its time target. A post parses (or a file hashes) in milliseconds while a request costs
	// a whole WordPress boot, so one batch of posts_buffer per request spent most of a scan on HTTP
	// (and an AI assistant on MCP needed thousands of calls). Each batch writes its references and
	// its checkpoint before the next one starts, and the engine still yields on the real time and
	// memory budget, so a slow host simply stops after the first. $chunk is the last batch's size.
	public function extract_in_batches( $extract, $limit, $limitsize, &$message, $post_id, &$processed, &$chunk = null ) {
		$processed = 0;
		$started = microtime( true );
		do {
			$finished = $this->$extract( $limit + $processed, $limitsize, $message, $post_id, $chunk );
			$processed += $chunk;
		} while ( !$finished && $post_id === null && $chunk === (int) $limitsize
			&& microtime( true ) - $started < $this->core->batch_time_target() && !$this->core->timeout_should_yield() );
		return $finished;
	}

	function extractRefsFromContent( $limit, $limitsize, &$message = '', $post_id = null, &$processed = null ) {
		$processed = 0;
		$method = $this->core->current_method;


		// Check content is a different option depending on the method
		$check_content = false;
		if ( $method === 'media' || $method === 'duplicates' ) {
			$check_content = $this->core->get_option( 'content' );
		}
		else if ( $method === 'files' ) {
			$check_content = $this->core->get_option( 'filesystem_content' );
		}

		if ( $method == 'media' && !$check_content ) {
			$message = __( "Skipped, as Content is not selected.", 'media-cleaner' );
			return true;
		}

		if ( $method == 'files' && !$check_content ) {
			$message = __( "Skipped, as Content is not selected.", 'media-cleaner' );
			return true;
		}

		// Initialize the parsers
		$this->core->timeout_check_start( $limitsize );
		$this->core->safe_do_action( 'wpmc_initialize_parsers' );

		$posts = $post_id !== null ? [ $post_id ] : $this->get_posts_to_check( $limit, $limitsize );

		// Only at the beginning, check the Widgets and the Scan Once in the Parsers
		if ( empty( $limit ) ) {
			$this->core->log( "🏁 Extracting refs from content..." );
			$this->scan_widgets_once();
			$this->core->safe_do_action( 'wpmc_scan_once' );
		}

		$is_debug = $this->core->is_debug();
		$yielded = false;

		foreach ( $posts as $post ) {
			if ( $this->core->timeout_should_yield() ) {
				$yielded = true;
				break;
			}
			$this->core->timeout_check();

			// Debug logging for timeout detection
			if ( $is_debug ) {
				$post_obj = get_post( $post );
				$post_type = $post_obj ? $post_obj->post_type : 'unknown';
				$post_title = $post_obj ? substr( $post_obj->post_title, 0, 50 ) : 'no title';
				$start_time = microtime( true );
				$this->core->log( "🔍 Processing post ID: $post | Type: $post_type | Title: $post_title" );
			}

			// Check content
			try {
				if ( $check_content ) {
					$this->core->safe_do_action( 'wpmc_scan_postmeta', $post );
					$html = get_post_field( 'post_content', $post );
					$this->core->safe_do_action( 'wpmc_scan_post', $html, $post );
				}
			}
			catch ( Meow_WPMC_Transient_Exception $e ) {
				$this->fail_if_first_item( $e, sprintf(
					__( 'Post #%d cannot be scanned within this server request budget. Turn on the debug logs to see which parser is spending the time.', 'media-cleaner' ), $post ) );
				$yielded = true;
				break;
			}

			// Extra scanning methods
			// do_action( 'wpmc_scan_extra', $post );

			if ( $is_debug ) {
				$elapsed_ms = round( ( microtime( true ) - $start_time ) * 1000, 2 );
				$this->core->log( "✓ Completed post ID: $post in {$elapsed_ms}ms" );
			}

			$this->core->timeout_check_additem();
			$processed++;
		}

		// Write the references found (and cached) by the parsers
		$this->core->write_references();
		$progress_phase = $post_id !== null ? 'extractReferencesFromContentPartial' : 'extractReferencesFromContent';
		$this->core->save_progress( $progress_phase, array(
			'type' => 'content',
			'limit' => $limit,
			'limitSize' => $limitsize,
			'next' => $limit + $processed,
			'processed' => $processed,
			'postId' => $post_id,
		) );

		$finished = !$yielded && $processed === count( $posts ) && ( $post_id !== null || count( $posts ) < $limitsize );
		if ( $finished && $post_id === null )
		{
			$this->core->log();
			$this->core->save_progress( 'extractReferencesFromContent_finished' );
		}

		$elapsed = $this->core->timeout_get_elapsed();
		$message = sprintf(
			// translators: %1$d is number of posts, %2$s is time in milliseconds
			__( "Extracted references from %1\$d posts in %2\$s.", 'media-cleaner' ), $processed, $elapsed
		);
		return $finished;
	}

	// WPML keeps one attachment per language. A URL reference already covers every duplicate that
	// shares the file, because check_media() tests an attachment's own paths against the URL
	// references — what it cannot cover is a translation stored under its own file, reachable only
	// by resolving the URL to an ID and expanding it.
	//
	// That resolution used to live in add_reference_url(), so every parser paid for it inline: a
	// postmeta lookup per parser call, and the same media resolved again on every batch because the
	// url->id cache only lives for one request. It is set-based work over the run's references, so
	// it runs once, here, after they have all been collected.
	function extractRefsFromTranslations( $limit, $limitsize, &$message = '', $post_id = null, &$processed = null ) {
		global $wpdb;
		$processed = 0;
		if ( !$this->core->is_multilingual() || $this->core->current_method !== 'media' ) {
			$message = __( "Skipped, as the site is not multilingual.", 'media-cleaner' );
			return true;
		}

		// Only the URL rows, and this phase only ever inserts ID rows, so the set it pages over
		// cannot move underneath it — which is what makes plain offset paging safe here.
		$table = $wpdb->prefix . 'mclean_refs';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT mediaUrl, originType, origin FROM $table
			WHERE run_id = %d AND mediaUrl IS NOT NULL
			ORDER BY id ASC LIMIT %d, %d",
			$this->core->get_run_id(), (int) $limit, (int) $limitsize
		) );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( sprintf( __( 'Media Cleaner could not read references: %s', 'media-cleaner' ), $wpdb->last_error ) );
		}
		if ( !is_array( $rows ) ) $rows = array();

		if ( empty( $limit ) ) {
			$this->core->log( "🏁 Resolving translated media..." );
		}

		$this->core->timeout_check_start( count( $rows ) );
		$yielded = false;
		// Resolving costs one postmeta pass per chunk however many URLs it carries, so the chunk is
		// the unit of work and the unit of resumption: $processed only counts rows already recorded.
		foreach ( array_chunk( $rows, 100 ) as $chunk ) {
			if ( $this->core->timeout_should_yield() ) {
				$yielded = true;
				break;
			}
			$this->core->timeout_check();
			try {
				$this->core->add_translated_references( $chunk );
				$this->core->write_references();
			}
			catch ( Meow_WPMC_Transient_Exception $e ) {
				$this->fail_if_first_item( $e, __( 'Translated media cannot be resolved within this server request budget.', 'media-cleaner' ) );
				$yielded = true;
				break;
			}
			$this->core->timeout_check_additem();
			$processed += count( $chunk );
		}

		$this->core->write_references();
		$this->core->save_progress( 'extractReferencesFromTranslations', array(
			'type' => 'translations',
			'limit' => $limit,
			'limitSize' => $limitsize,
			'next' => $limit + $processed,
			'processed' => $processed,
			'postId' => null,
		) );

		$finished = !$yielded && $processed === count( $rows ) && count( $rows ) < $limitsize;
		if ( $finished ) {
			$this->core->save_progress( 'extractReferencesFromTranslations_finished' );
			$this->core->log( "Finished resolving translated media." );
		}
		$elapsed = $this->core->timeout_get_elapsed();
		$message = sprintf( __( "Resolved translations for %d references in %s.", 'media-cleaner' ), $processed, $elapsed );
		return $finished;
	}

	function extractRefsFromThumbnails( $limit, $limitsize, &$message = '', $post_id = null, &$processed = null ) {
		$medias = $this->get_media_entries( $limit, $limitsize, false );
		$processed = 0;
		$yielded = false;
		$this->core->timeout_check_start( count( $medias ) );

		// Get the sizes that should be marked as issues
		$force_issue_sizes = $this->core->get_option( 'thumbnail_force_issues' );
		if ( !is_array( $force_issue_sizes ) ) {
			$force_issue_sizes = [];
		}
		// A size no longer registered can still be asked for by name, and WordPress then serves its
		// stored thumbnail: those are references too, or a shop or theme would lose its images.
		$requested_sizes = (array) apply_filters( 'wpmc_requested_image_sizes', array() );

		foreach ( $medias as $media_id ) {
			if ( $this->core->timeout_should_yield() ) {
				$yielded = true;
				break;
			}
			$this->core->timeout_check();
			try {
				$file = get_attached_file( $media_id );
				$meta = wp_get_attachment_metadata( $media_id );

				if ( ! is_array( $meta ) || ! isset( $meta['sizes'] ) ) {
					$meta = array( 'sizes' => array() );
				}

				// Get the current registered image sizes
				$needed_sizes = array_keys( wp_get_registered_image_subsizes() );
				foreach ( $requested_sizes as $requested ) {
					if ( isset( $meta['sizes'][ $requested ] ) && !in_array( $requested, $needed_sizes, true ) ) $needed_sizes[] = $requested;
				}

				foreach ( $needed_sizes as $size ) {
					$image_path = path_join( dirname( $file ), $meta['sizes'][ $size ]['file'] ?? '' );
					$file_exists = isset( $meta['sizes'][ $size ] ) && file_exists( $image_path ) && filesize( $image_path ) > 0;
					if ( !$file_exists ) {
						continue;
					}

					$image_path = $this->core->clean_uploaded_filename( $image_path );

					// Check if this size should be marked as an issue instead of a reference
					if ( in_array( $size, $force_issue_sizes ) ) {
						// Mark as issue instead of reference
						$this->core->add_issue( $image_path, 'FORCED_THUMBNAIL_ISSUE', $media_id );
					} else {
						// Add a reference for generated thumbnail
						$this->core->add_reference_url(
							$image_path,
							"{OG_THUMB}" . $size,
							$media_id, ['force_cache' => true ]
						);
					}
				}

				// The upload WordPress keeps next to a "-scaled" copy is never shown on pages, but it
				// is the copy thumbnails are regenerated from, so it only goes when it was chosen.
				if ( in_array( 'original_image', $force_issue_sizes, true ) && !empty( $meta['original_image'] ) ) {
					$original_path = path_join( dirname( $file ), $meta['original_image'] );
					if ( file_exists( $original_path ) ) {
						$this->core->add_issue( $this->core->clean_uploaded_filename( $original_path ), 'FORCED_THUMBNAIL_ISSUE', $media_id );
					}
				}
			}
			catch ( Meow_WPMC_Transient_Exception $e ) {
				$this->fail_if_first_item( $e, sprintf(
					__( 'Media #%d has too many sizes to check within this server request budget.', 'media-cleaner' ), $media_id ) );
				$yielded = true;
				break;
			}
			$this->core->timeout_check_additem();
			$processed++;
		}

		$this->core->write_references();
		$this->core->save_progress( 'extractReferencesFromThumbnails', array(
			'type' => 'thumbnails',
			'limit' => $limit,
			'limitSize' => $limitsize,
			'next' => $limit + $processed,
			'processed' => $processed,
		) );

		$finished = !$yielded && $processed === count( $medias ) && count( $medias ) < $limitsize;

		if ( $finished )
		{
			$this->core->save_progress( 'extractReferencesFromThumbnails_finished' );
			$this->core->log("Finished extracting refs from Thumbnails.");
		}
		$message = sprintf( __( 'Analyzed %d media thumbnail sets.', 'media-cleaner' ), $processed );

		return $finished;
	}


	// For each media, let's get a hash of the file and add it as a reference
	function extractRefsFromDuplicates( $limit, $limitsize, &$message = '', $post_id = null, &$processed = null ) {
		$medias = $this->get_media_entries( $limit, $limitsize, false );
		$processed = 0;
		$yielded = false;
		$this->core->timeout_check_start( count( $medias ) );
		foreach ( $medias as $media ) {
			if ( $this->core->timeout_should_yield() ) {
				$yielded = true;
				break;
			}
			$this->core->timeout_check();
			$full_path = get_attached_file( $media );

			if ( !$full_path || !is_file( $full_path ) || !is_readable( $full_path ) || is_link( $full_path ) ) {
				$this->core->log( sprintf( "Duplicates: skipped Media #%d, the original file cannot be read.", $media ) );
				$this->core->timeout_check_additem();
				$processed++;
				continue;
			}
			try {
				$hash = $this->hash_file_safely( $full_path, $media );
			}
			catch ( Meow_WPMC_Transient_Exception $e ) {
				$this->fail_if_first_item( $e, sprintf(
					__( 'Media #%d is too large or slow to hash within this server request budget.', 'media-cleaner' ), $media ) );
				$yielded = true;
				break;
			}
			catch ( Exception $e ) {
				// The file disappeared or failed mid-read. Same reasoning as above: skip it, keep scanning.
				$this->core->log( sprintf( "Duplicates: skipped Media #%d, %s", $media, $e->getMessage() ) );
				$this->core->timeout_check_additem();
				$processed++;
				continue;
			}
			$path = $this->core->clean_uploaded_filename( $full_path );
			$this->core->add_reference_url( $path, 'HASH:' . $hash, $media, array( 'force_cache' => true ) );
			// Kept under the HASH: prefix on purpose: every query that tells real references from the
			// duplicate analysis' bookkeeping already excludes HASH:, so this key can never make a file look used.
			// Similar images are part of every Duplicates scan; only seeing and acting on them is Pro
			// (see check_duplicates()).
			$similar_key = $this->similar_image_key( $media, $full_path );
			if ( $similar_key ) {
				$this->core->add_reference_url( $path, $similar_key, $media, array( 'force_cache' => true ) );
			}
			$this->core->timeout_check_additem();
			$processed++;
		}

		$this->core->write_references();
		$this->core->save_progress( 'extractReferencesFromDuplicates', array(
			'type' => 'duplicates',
			'limit' => $limit,
			'limitSize' => $limitsize,
			'next' => $limit + $processed,
			'processed' => $processed,
		) );

		$finished = !$yielded && $processed === count( $medias ) && count( $medias ) < $limitsize;
		if ( $finished )
		{
			$this->core->save_progress( 'extractReferencesFromDuplicates_finished' );
			$this->core->log("Finished extracting refs from Duplicates.");
		}
		$message = sprintf( __( 'Hashed %d media files.', 'media-cleaner' ), $processed );

		return $finished;
	}

	private function hash_file_safely( $path, $media_id ) {
		$handle = @fopen( $path, 'rb' );
		if ( !$handle ) throw new RuntimeException( sprintf( __( 'Duplicate analysis could not open the original file for Media #%d.', 'media-cleaner' ), $media_id ) );
		$context = hash_init( 'sha256' );
		try {
			while ( !feof( $handle ) ) {
				$this->core->timeout_check();
				$chunk = fread( $handle, 1024 * 1024 );
				if ( $chunk === false ) throw new RuntimeException( sprintf( __( 'Duplicate analysis could not read the original file for Media #%d.', 'media-cleaner' ), $media_id ) );
				if ( $chunk !== '' ) hash_update( $context, $chunk );
			}
		}
		finally {
			fclose( $handle );
		}
		return hash_final( $context );
	}

	// A perceptual key (dHash): the image shrunk to 9×8 pixels, one bit per pair of neighbours telling
	// whether brightness rises. Resizing, re-compressing or re-encoding barely moves it, so the same
	// picture uploaded at another size or quality gets the same key. The average colour is appended
	// because dHash only sees brightness: the same layout in clearly different colours would collide.
	// It only helps when the colour changes the whole image; a small recoloured detail still collides.
	// Two keys are compared by how many of their 64 bits differ (see similar_keys_near()).
	function similar_image_key( $media_id, $full_path ) {
		if ( !function_exists( 'imagecreatefromstring' ) || !wp_attachment_is_image( $media_id ) ) return null;

		// The medium size holds all the detail the key needs and is far cheaper to decode.
		$medium = image_get_intermediate_size( $media_id, 'medium' );
		$path = !empty( $medium['path'] ) ? trailingslashit( $this->core->upload_path ) . $medium['path'] : null;
		if ( !$path || !is_file( $path ) ) $path = $full_path;

		// Decoding takes about 5 bytes per pixel; a huge original without a medium size could exhaust memory.
		$size = @getimagesize( $path );
		if ( !$size || $size[0] * $size[1] > 25000000 ) return null;
		$data = @file_get_contents( $path );
		$image = $data !== false ? @imagecreatefromstring( $data ) : false;
		if ( !$image ) return null;

		// Transparent pixels are laid over white, so a transparent PNG and its flattened JPEG agree.
		$small = imagecreatetruecolor( 9, 8 );
		imagefill( $small, 0, 0, imagecolorallocate( $small, 255, 255, 255 ) );
		imagecopyresampled( $small, $image, 0, 0, 0, 0, 9, 8, imagesx( $image ), imagesy( $image ) );
		unset( $image );

		$bits = '';
		$sum = array( 0, 0, 0 );
		for ( $y = 0; $y < 8; $y++ ) {
			$previous = null;
			for ( $x = 0; $x < 9; $x++ ) {
				$rgb = imagecolorat( $small, $x, $y );
				$channels = array( ( $rgb >> 16 ) & 0xFF, ( $rgb >> 8 ) & 0xFF, $rgb & 0xFF );
				$sum[0] += $channels[0];
				$sum[1] += $channels[1];
				$sum[2] += $channels[2];
				$luma = 0.299 * $channels[0] + 0.587 * $channels[1] + 0.114 * $channels[2];
				if ( $previous !== null ) $bits .= $luma > $previous ? '1' : '0';
				$previous = $luma;
			}
		}

		// A flat or smoothly shaded image has almost no pattern, so every blank, solid or gradient
		// image would share one key and be listed as similar to each other.
		$ones = substr_count( $bits, '1' );
		if ( $ones < 4 || $ones > 60 ) return null;

		$key = '';
		foreach ( str_split( $bits, 4 ) as $nibble ) {
			$key .= dechex( bindec( $nibble ) );
		}
		foreach ( $sum as $total ) {
			$key .= (string) ( (int) ( $total / 72 ) >> 5 );
		}
		return 'HASH:SIMILAR:' . $key;
	}

	// The keys close enough to this one, itself included. Resizing or re-encoding moves a key by 0 to
	// 2 bits, a slight crop by 3 to 8; unrelated images are almost always 15 or more apart, which is
	// why the tolerance stops at 12. The colour may move one step of eight per channel, not more.
	// ponytail: compares the key with every key of the run, so the whole scan is quadratic (about
	// 400M comparisons for 20,000 images). Upgrade path: index bands of the key to skip far ones.
	function similar_keys_near( $key ) {
		$index = $this->similar_index();
		if ( !isset( $index[ $key ] ) ) return array( $key );
		$tolerance = $this->similar_tolerance();
		list( $high, $low, $red, $green, $blue ) = $index[ $key ];
		$near = array();
		foreach ( $index as $other => $parts ) {
			if ( abs( $parts[2] - $red ) > 1 || abs( $parts[3] - $green ) > 1 || abs( $parts[4] - $blue ) > 1 ) continue;
			if ( self::popcount32( $parts[0] ^ $high ) + self::popcount32( $parts[1] ^ $low ) <= $tolerance ) {
				$near[] = $other;
			}
		}
		return $near;
	}

	// The tolerance the results were scanned with, so the comparison modal draws the same groups as
	// the scan did even after the setting moved. Runs older than the setting fall back to it.
	function similar_tolerance() {
		$run = $this->core->runs ? $this->core->runs->get( $this->core->get_run_id() ) : null;
		$config = $run ? json_decode( (string) $run->config, true ) : null;
		return isset( $config['similar_tolerance'] ) ? (int) $config['similar_tolerance'] : (int) $this->core->get_option( 'similar_tolerance' );
	}

	// Every perceptual key of the run, split into two 32-bit halves and the three colour steps.
	// Loaded once per request: finding the neighbours of one key means looking at all of them.
	private function similar_index() {
		global $wpdb;
		$run_id = $this->core->get_run_id();
		if ( $this->similar_index === null || $this->similar_index['run_id'] !== $run_id ) {
			$table = $wpdb->prefix . 'mclean_refs';
			$keys = $this->get_col_or_fail( $wpdb->prepare(
				"SELECT DISTINCT originType FROM $table WHERE run_id = %d AND originType LIKE 'HASH:SIMILAR:%%'",
				$run_id
			) );
			$parsed = array();
			foreach ( $keys as $key ) {
				$hex = substr( $key, 13 );
				$parsed[ $key ] = array( hexdec( substr( $hex, 0, 8 ) ), hexdec( substr( $hex, 8, 8 ) ),
					(int) $hex[16], (int) $hex[17], (int) $hex[18] );
			}
			$this->similar_index = array( 'run_id' => $run_id, 'keys' => $parsed );
		}
		return $this->similar_index['keys'];
	}

	private static function popcount32( $x ) {
		$x -= ( $x >> 1 ) & 0x55555555;
		$x = ( $x & 0x33333333 ) + ( ( $x >> 2 ) & 0x33333333 );
		$x = ( $x + ( $x >> 4 ) ) & 0x0F0F0F0F;
		return ( ( $x * 0x01010101 ) & 0xFFFFFFFF ) >> 24;
	}

	// Parse the posts for references (based on $limit and $limitsize for paging the scan)
	function extractRefsFromLibrary( $limit, $limitsize, &$message = '', $post_id = null, &$processed = null ) {
		$processed = 0;
		$method = $this->core->current_method;
		if ( $method == 'media' ) {
			$message = __( "Skipped, as it is not needed for the Media Library method.", 'media-cleaner' );
			return true;
		}
		$check_library = $this->core->get_option( 'media_library' );
		if ( !$check_library ) {
			$message = __( "Skipped, as Media Library is not selected.", 'media-cleaner' );
			return true;
		}

		$medias = $this->get_media_entries( $limit, $limitsize, false, $post_id );

		// Only at the beginning
		if ( empty( $limit ) ) {
			$this->core->log( "🏁 Extracting refs from Media Library..." );
		}

		$this->core->timeout_check_start( count( $medias ) );
		$yielded = false;
		foreach ( $medias as $media ) {
			if ( $this->core->timeout_should_yield() ) {
				$yielded = true;
				break;
			}
			$this->core->timeout_check();
			// Check the media
			try {
				$paths = $this->core->get_paths_from_attachment( $media );
				$this->core->add_reference_url( $paths, 'MEDIA LIBRARY' );
			}
			catch ( Meow_WPMC_Transient_Exception $e ) {
				$this->fail_if_first_item( $e, sprintf(
					__( 'Media #%d cannot be read within this server request budget.', 'media-cleaner' ), $media ) );
				$yielded = true;
				break;
			}
			$this->core->timeout_check_additem();
			$processed++;
		}

		// Write the references found (and cached) by the parsers
		$this->core->write_references();
		$progress_phase = $post_id !== null ? 'extractReferencesFromLibraryPartial' : 'extractReferencesFromLibrary';
		$this->core->save_progress( $progress_phase, array(
			'type' => 'library',
			'limit' => $limit,
			'limitSize' => $limitsize,
			'next' => $limit + $processed,
			'processed' => $processed,
			'postId' => $post_id,
		) );

		$finished = !$yielded && $processed === count( $medias ) && ( $post_id !== null || count( $medias ) < $limitsize );
		if ( $finished && $post_id === null )
		{
			$this->core->save_progress( 'extractReferencesFromLibrary_finished' );
			$this->core->log("Finished extracting refs from Media Library.");
		}
		$elapsed = $this->core->timeout_get_elapsed();
		$message = sprintf( __( "Extracted references from %d medias in %s.", 'media-cleaner' ), $processed, $elapsed );
		return $finished;
	}

	/*
		STEP 2: List the media entries (or files)
	*/

	// Exact groups come before similar ones: check_duplicates() relies on it to leave a copy that is
	// already a DUPLICATE out of the similar images. A similar key is returned even when no other image
	// has it, since its neighbours carry keys of their own.
	function get_hash_duplicates( $offset = 0, $limit = 100 ) {
		// Get the hashes from the referenes ( unique ones )
		global $wpdb;
		$run_id = $this->core->get_run_id();
		$table = $wpdb->prefix . 'mclean_refs';
		$hashes = $wpdb->get_col( $wpdb->prepare(
			"SELECT originType FROM $table
			WHERE run_id = %d AND originType LIKE 'HASH:%%'
			GROUP BY originType HAVING COUNT(DISTINCT mediaUrl) > 1 OR originType LIKE 'HASH:SIMILAR:%%'
			ORDER BY ( originType LIKE 'HASH:SIMILAR:%%' ) ASC, originType ASC LIMIT %d, %d",
			$run_id,
			max( 0, (int) $offset ),
			max( 1, min( 500, (int) $limit ) )
		) );

		return $hashes;	
	}

	// Get files in /uploads (if path is null, the root of /uploads is returned)
	function get_files( $path = null, $offset = 0, $limit = -1 ) {
		$files = apply_filters( 'wpmc_list_uploaded_files', null, $path, $offset, $limit );
		return $files ? $files : array();
	}

	function get_file_page_info( $fallback_count = 0, $limit = -1 ) {
		$default = array(
			'scanned' => $fallback_count,
			'finished' => $limit < 1 || $fallback_count < $limit,
			'skipped' => array(),
		);
		return apply_filters( 'wpmc_file_page_info', $default );
	}

	/**
	 * Returns the media entries to check the references
	 * @param int $offset Negative number means no limit
	 * @param int $size   Negative number means no limit
	 * @param bool $unattachedOnly
	 * @param int|null $post_parent_id If this is set with $unattachedOnly, this is ignored. ($unattachedOnly is prioritized)
	 * @return NULL|array
	 */
	function get_media_entries( $offset = -1, $size = -1, $unattachedOnly = false, $post_parent_id = null ) {
		global $wpdb;
		$r = null;

		$extraAnd = $unattachedOnly
			? "AND p.post_parent = 0"
			: ( $post_parent_id !== null
				? $wpdb->prepare( "AND p.post_parent = %d", $post_parent_id )
				: '' );

		$q = <<<SQL
SELECT p.ID FROM $wpdb->posts p
WHERE p.post_status = 'inherit'
$extraAnd
AND p.post_type = 'attachment'
SQL;
		if ( $this->core->get_option( 'images_only' ) ) {
			// Get only media entries which are images
			$q .= " AND p.post_mime_type IN ( 'image/jpeg', 'image/gif', 'image/png', 'image/webp',
				'image/bmp', 'image/tiff', 'image/x-icon', 'image/svg' )";
		}
		$q .= " ORDER BY p.ID ASC";

		if ( $offset >= 0 && $size >= 0 ) {
			$q .= " LIMIT %d, %d";
			$r = $wpdb->get_col( $wpdb->prepare( $q, $offset, $size ) );

		} else // No limit
			$r = $wpdb->get_col( $q );

		return $r;
	}

	/**
	 * Returns the count of media entries (memory-efficient alternative to count(get_media_entries()))
	 * @param bool $unattachedOnly
	 * @return int
	 */
	function count_media_entries( $unattachedOnly = false ) {
		global $wpdb;

		$extraAnd = $unattachedOnly ? "AND p.post_parent = 0" : '';

		$q = <<<SQL
SELECT COUNT(p.ID) FROM $wpdb->posts p
WHERE p.post_status = 'inherit'
$extraAnd
AND p.post_type = 'attachment'
SQL;
		if ( $this->core->get_option( 'images_only' ) ) {
			$q .= " AND p.post_mime_type IN ( 'image/jpeg', 'image/gif', 'image/png', 'image/webp',
				'image/bmp', 'image/tiff', 'image/x-icon', 'image/svg' )";
		}

		return (int) $wpdb->get_var( $q );
	}

	/*
		STEP 3: Check the media entries (or files) against the references
	*/

	// Returns false only when $may_yield let it stop partway through the group, after storing at
	// least one new copy: the caller counts that as progress and asks for the same group again.
	function check_duplicates( $hash, $may_yield = false ) {
		// Check if the hash exists in the database
		global $wpdb;
		$table_name_refs = $wpdb->prefix . "mclean_refs";
		$run_id = $this->core->get_run_id();

		// The hash references are stored as URL references, so mediaId is always NULL on those rows:
		// extractRefsFromDuplicates() passes the media ID as the origin. Reading it back from origin
		// is what makes the "is this copy referenced by ID?" test below able to match anything.
		$request = $wpdb->prepare(
			"SELECT mediaUrl, MAX(origin) AS mediaId FROM $table_name_refs
			WHERE run_id = %d AND originType = %s AND mediaUrl IS NOT NULL
			GROUP BY mediaUrl
			ORDER BY mediaUrl ASC",
			$run_id,
			$hash
		);

		$medias = $wpdb->get_results( $request );

		// A similar image needs another image near its key, not one with the very same key. Only the
		// images holding this key are decided here; each neighbour is decided with its own key.
		$issue = strpos( $hash, 'HASH:SIMILAR:' ) === 0 ? 'SIMILAR' : 'DUPLICATE';
		if ( $issue === 'SIMILAR' ? !$this->has_similar_image( $hash ) : count( $medias ) <= 1 ) {
			return true;
		}

		// Which copies something else references, looked up in batches rather than one query per
		// copy: a placeholder uploaded a thousand times is a single group, and one query per copy
		// kept that group from ever fitting in a request, so the scan stalled on it for good.
		$table_name_scan = $wpdb->prefix . 'mclean_scan';
		$used_ids = array();
		$used_urls = array();
		$stored = array();
		foreach ( array_chunk( $medias, 500 ) as $chunk ) {
			$ids = array_values( array_filter( array_map( function ( $media ) { return (int) $media->mediaId; }, $chunk ) ) );
			if ( !empty( $ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$used_ids += array_flip( $this->get_col_or_fail( $wpdb->prepare(
					"SELECT DISTINCT mediaId FROM $table_name_refs
					WHERE run_id = %d AND originType NOT LIKE 'HASH:%%' AND mediaId IN ($placeholders)",
					array_merge( array( $run_id ), $ids )
				) ) );
			}
			$url_hashes = array_map( function ( $media ) { return hash( 'sha256', (string) $media->mediaUrl ); }, $chunk );
			$placeholders = implode( ',', array_fill( 0, count( $url_hashes ), '%s' ) );
			$used_urls += array_flip( $this->get_col_or_fail( $wpdb->prepare(
				"SELECT DISTINCT mediaUrl FROM $table_name_refs
				WHERE run_id = %d AND originType NOT LIKE 'HASH:%%' AND mediaUrl_hash IN ($placeholders)",
				array_merge( array( $run_id ), $url_hashes )
			) ) );
			// Copies already stored by an earlier request on the same group: skipped here, before
			// add_issue() reads their file again, so picking a huge group up stays cheap.
			$path_hashes = array_map( function ( $media ) {
				return hash( 'sha256', $this->core->clean_uploaded_filename( (string) $media->mediaUrl ) );
			}, $chunk );
			// Either issue counts: a copy listed as a DUPLICATE is not listed again as SIMILAR.
			$placeholders = implode( ',', array_fill( 0, count( $path_hashes ), '%s' ) );
			$stored += array_flip( $this->get_col_or_fail( $wpdb->prepare(
				"SELECT path FROM $table_name_scan WHERE run_id = %d AND issue IN ('DUPLICATE', 'SIMILAR') AND path_hash IN ($placeholders)",
				array_merge( array( $run_id ), $path_hashes )
			) ) );
		}

		// Without Pro, similar images are found by the same analysis but only counted: comparing and
		// cleaning them is Pro, so they never become issues that Delete All or the API could act on.
		$count_only = $issue === 'SIMILAR' && !$this->core->admin->is_pro_user();

		// Every copy in the group is reported, including the first one: the analysis describes what is
		// on the disk, it does not decide what survives. A huge group can outlast the request, so with
		// $may_yield it hands back after storing at least one copy, and the next request continues.
		$added = 0;
		foreach ( $medias as $media ) {
			$path = (string) $media->mediaUrl;
			if ( isset( $used_ids[ (int) $media->mediaId ] ) || isset( $used_urls[ $path ] ) ) {
				continue;
			}
			if ( isset( $stored[ $this->core->clean_uploaded_filename( $path ) ] ) ) {
				continue;
			}
			if ( $count_only ) {
				$added++;
				continue;
			}
			if ( $may_yield && $added > 0 && $this->core->timeout_should_yield() ) {
				return false;
			}
			$this->core->add_issue( $path, $issue );
			$added++;
		}

		if ( $count_only && $added > 0 && $this->core->runs ) {
			$this->core->runs->add_similar_found( $added );
		}

		return true;
	}

	private function has_similar_image( $key ) {
		global $wpdb;
		$near = $this->similar_keys_near( $key );
		$placeholders = implode( ',', array_fill( 0, count( $near ), '%s' ) );
		return count( $this->get_col_or_fail( $wpdb->prepare(
			"SELECT DISTINCT mediaUrl FROM {$wpdb->prefix}mclean_refs
			WHERE run_id = %d AND originType IN ($placeholders) AND mediaUrl IS NOT NULL LIMIT 2",
			array_merge( array( $this->core->get_run_id() ), $near )
		) ) ) > 1;
	}

	// A failed lookup must not read as "nothing references these copies": that would list used
	// files as duplicates to delete.
	private function get_col_or_fail( $query ) {
		global $wpdb;
		$col = $wpdb->get_col( $query );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( sprintf( __( 'Media Cleaner could not check the duplicates: %s', 'media-cleaner' ), $wpdb->last_error ) );
		}
		return $col;
	}

	function check_media( $media ) {
		return $this->core->check_media( $media );
	}

	function check_file( $file ) {
		// Basically, wpmc_check_file returns either true if it's used, or
		// the codename of the issue.
		$issue = apply_filters( 'wpmc_check_file', false, $file );
		$used = $issue === true;
		if ( !$used ) {
			$this->core->add_issue( $file, is_string( $issue ) ? $issue : 'NO_CONTENT' );
		}
		return $used;
	}

}

?>
