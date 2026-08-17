<?php

/**
 * Sizes the scan buffers from what the server can actually do.
 *
 * Every buffer answers one question: how many items can a single request handle before PHP,
 * the proxy or the memory limit cuts it off. Rather than asking the user to guess, the same
 * work each phase really does — parsing content, resolving media paths, listing uploads,
 * fingerprinting files, flushing references — is run for real and timed.
 *
 * It is measured the way it is used: as separate HTTP requests, at growing sample sizes. That
 * matters, because a batch costs a fixed amount (bootstrap, parser init, the round trip) plus
 * something per item, and those two only separate once the same work has been timed at two
 * different sizes. One sample size can only ever produce an average that hides the fixed part.
 *
 * The dashboard drives it: plan() says what can be measured here, measure() runs one rung of
 * one probe per request, and apply() fits a line through the collected rungs and stores the
 * result. Buffers only decide how work is split across requests, never what the analysis
 * finds, so getting one wrong costs time, not correctness. Settings that would change what the
 * analysis sees (the document limit) are left alone, and so is the delay, which paces requests
 * for the server's sake and cannot be derived from a benchmark.
 *
 * The probes are read-only. The only write is into a TEMPORARY copy of the references table,
 * which never touches scan results and disappears with the connection.
 */
class Meow_WPMC_Buffers {

	// Only part of the request budget is spent on a batch, so an item slower than the ones
	// sampled here still fits. The engine yields mid-batch anyway; this keeps that rare.
	const BUDGET_TARGET = 0.5;
	// A batch may hold at most this share of the PHP memory limit.
	const MEMORY_TARGET = 0.25;
	// A reference row is small; this is a deliberately generous estimate of its wire size,
	// used to keep a flush well inside MySQL's max_allowed_packet.
	const REF_ROW_BYTES = 512;

	private $core;
	private $deadline = 0;

	public function __construct( $core ) {
		$this->core = $core;
	}

	// Every probe, the setting it sizes, and the sample sizes it is measured at. A rung is only
	// offered when the install actually has that much to sample.
	private function probes() {
		return array(
			'content' => array(
				'option' => 'posts_buffer',
				'label' => __( 'Content', 'media-cleaner' ),
				'ladder' => array( 5, 25, 100 ),
			),
			'media' => array(
				'option' => 'medias_buffer',
				'label' => __( 'Media Library', 'media-cleaner' ),
				'ladder' => array( 5, 25, 100 ),
			),
			'analysis' => array(
				'option' => 'analysis_buffer',
				'label' => __( 'Analysis', 'media-cleaner' ),
				'ladder' => array( 5, 25, 100 ),
			),
			'files' => array(
				'option' => 'uploads_file_buffer',
				'label' => __( 'Filesystem', 'media-cleaner' ),
				'ladder' => array( 10, 50, 200 ),
			),
			'file_operation' => array(
				'option' => 'file_op_buffer',
				'label' => __( 'File Operations', 'media-cleaner' ),
				'ladder' => array( 5, 20 ),
			),
			'references' => array(
				'option' => 'refs_buffer',
				'label' => __( 'References', 'media-cleaner' ),
				'ladder' => array( 200, 1000 ),
			),
		);
	}

	/**
	 * What can be measured on this install, and at which sample sizes. A phase with nothing to
	 * sample (no posts, no media, no filesystem scan) is left out entirely: its setting will
	 * keep its current value, because a guess is not an improvement over what the user has.
	 */
	public function plan() {
		$blocked = $this->blocked();
		if ( is_wp_error( $blocked ) ) return $blocked;

		$available = $this->availability();
		$steps = array();
		foreach ( $this->probes() as $name => $probe ) {
			$rungs = $this->ladder( $probe['ladder'], $available[ $name ] );
			if ( empty( $rungs ) ) continue;
			$steps[] = array(
				'probe' => $name,
				'option' => $probe['option'],
				'label' => $probe['label'],
				'ladder' => $rungs,
			);
		}
		return array( 'environment' => $this->environment(), 'steps' => $steps );
	}

	/**
	 * One rung: run $items of one probe, in its own request, and report what it cost. The
	 * caller times the round trip; this reports the server side of it.
	 */
	public function measure( $name, $items ) {
		$blocked = $this->blocked();
		if ( is_wp_error( $blocked ) ) return $blocked;

		$probes = $this->probes();
		if ( !isset( $probes[ $name ] ) ) {
			return new WP_Error( 'wpmc_buffers_unknown_probe',
				__( 'Unknown benchmark step.', 'media-cleaner' ), array( 'status' => 400 ) );
		}
		// A rung must not outlast the request that carries it. Stopping early is not a failure:
		// the number of items actually done is reported, and that is what gets fitted.
		$this->deadline = microtime( true ) + $this->core->get_request_time_budget() * 0.8;
		$items = max( 1, (int) $items );
		$result = call_user_func( array( $this, 'probe_' . $name ), $items );
		$result['probe'] = $name;
		$result['requested'] = $items;
		return $result;
	}

	/**
	 * Fits the collected rungs and sizes every buffer it has evidence for. Nothing is stored,
	 * so this is what the dashboard shows before the user decides.
	 *
	 * $rounds is what the dashboard collected: probe name => list of rungs, each with the items
	 * done, the seconds the server spent, the bytes it used, and the round trip the browser saw.
	 */
	public function recommend( $rounds ) {
		$rounds = $this->sanitize_rounds( $rounds );
		$environment = $this->environment();
		$environment['request_overhead'] = $this->request_overhead( $rounds );
		$environment['longest_request'] = $this->longest_request( $rounds );
		$budget = $environment['request_budget'] * self::BUDGET_TARGET;

		$options = $this->core->get_all_options();
		$report = array(
			'environment' => $environment,
			'buffers' => array(),
			'warnings' => $this->warnings( $rounds, $environment ),
			'applied' => array(),
		);

		foreach ( $this->probes() as $name => $probe ) {
			$option = $probe['option'];
			$fit = $this->fit( isset( $rounds[ $name ] ) ? $rounds[ $name ] : array() );
			$value = $fit === null ? null : $this->size( $name, $fit, $budget, $environment );
			$report['buffers'][ $option ] = array(
				'probe' => $name,
				'label' => $probe['label'],
				'from' => (int) $options[ $option ],
				'to' => $value === null ? null : $this->clamp( $option, $value ),
				'fit' => $fit,
			);
		}
		return $report;
	}

	/**
	 * Stores what recommend() proposes. Buffers whose phase could not be measured keep their
	 * current value: a guess is not an improvement over what the user already has.
	 */
	public function apply( $rounds ) {
		$blocked = $this->blocked();
		if ( is_wp_error( $blocked ) ) return $blocked;

		$report = $this->recommend( $rounds );
		$options = $this->core->get_all_options();
		$changes = array();
		foreach ( $report['buffers'] as $option => $buffer ) {
			if ( $buffer['to'] === null ) continue;
			$options[ $option ] = $buffer['to'];
			$changes[] = $option;
		}

		if ( !empty( $changes ) ) {
			// update_options() clamps to option_ranges(), so the stored value is the truth.
			$options = $this->core->update_options( $options );
			foreach ( $changes as $option ) {
				$report['buffers'][ $option ]['to'] = (int) $options[ $option ];
			}
		}
		$report['applied'] = $changes;
		$this->core->log( sprintf( 'Auto Buffer: %s.', empty( $changes )
			? 'nothing could be measured, settings left as they are'
			: implode( ', ', array_map( function( $option ) use ( $report ) {
				return $option . ' ' . $report['buffers'][ $option ]['from'] . ' → ' . $report['buffers'][ $option ]['to'];
			}, $changes ) ) ) );
		return $report;
	}

	/**
	 * What the server allows. The request budget is the plugin's own: the time one REST call
	 * may spend before it has to hand control back, which is what a buffer is really sized by.
	 */
	public function environment() {
		global $wpdb;
		$packet = $wpdb->get_var( "SELECT @@max_allowed_packet" );
		return array(
			'max_execution_time' => $this->core->get_max_execution_time(),
			'memory_limit' => $this->core->parse_ini_bytes( ini_get( 'memory_limit' ) ),
			'request_budget' => $this->core->get_request_time_budget(),
			'max_allowed_packet' => $packet === null ? 0 : (int) $packet,
		);
	}

	// A scan in flight would both distort the timings and be distorted by them.
	private function blocked() {
		if ( $this->core->runs && $this->core->runs->get_resumable() ) {
			return new WP_Error( 'wpmc_buffers_scan_running',
				__( 'Publish or cancel the staged scan before measuring the server.', 'media-cleaner' ),
				array( 'status' => 409 ) );
		}
		return null;
	}

	#region Probes

	// Reading a post and pulling the URLs out of it is what every content parser triggers,
	// shortcode rendering included. The parsers themselves are not run: they write references,
	// and a benchmark must not leave any behind.
	private function probe_content( $items ) {
		return $this->run( $this->sample( 'posts', $items ), function( $post_id ) {
			$html = get_post_field( 'post_content', $post_id );
			get_post_meta( $post_id );
			$this->core->get_urls_from_html( $html );
		} );
	}

	// Listing a media entry means resolving every file it owns, thumbnails included.
	private function probe_media( $items ) {
		return $this->run( $this->sample( 'medias', $items ), function( $media_id ) {
			$this->core->get_paths_from_attachment( $media_id );
		} );
	}

	// Analysing one media is the same path resolution plus the reference lookups that decide
	// whether it is used — the queries that dominate the matching step.
	private function probe_analysis( $items ) {
		return $this->run( $this->sample( 'medias', $items ), function( $media_id ) {
			$paths = $this->core->get_paths_from_attachment( $media_id );
			foreach ( $paths as $path ) {
				$this->core->reference_exists( $path, $media_id );
			}
		} );
	}

	// The filesystem scan lists a directory page, then asks the same reference question about
	// every file in it. Both halves are timed: the listing through the real iterator, the
	// matching against real file paths.
	private function probe_files( $items ) {
		$listing = 0;
		$entries = 0;
		try {
			$started = microtime( true );
			$found = $this->core->engine ? $this->core->engine->get_files( '', 0, $items ) : array();
			$listing = microtime( true ) - $started;
			$entries = count( $found );
		}
		catch ( Throwable $e ) {
			return $this->unmeasured( __( 'The uploads directory could not be listed.', 'media-cleaner' ) );
		}
		if ( $entries < 1 ) return $this->unmeasured( __( 'The filesystem scan is not available.', 'media-cleaner' ) );
		$probe = $this->run( $this->sample( 'files', $items ), function( $path ) {
			$this->core->reference_exists( $path, null );
		} );
		if ( $probe['samples'] < 1 ) return $probe;
		// Charge the listing to the files it produced, then to the files actually matched.
		$probe['seconds'] += ( $listing / $entries ) * $probe['samples'];
		$probe['note'] = __( 'Listing and matching.', 'media-cleaner' );
		return $probe;
	}

	// Trashing or recovering an item verifies its fingerprint, then moves the file. The
	// fingerprint reads real files; the move is measured through the round-trip test that
	// already exists, and charged to every item, because every item is a real move.
	private function probe_file_operation( $items ) {
		$probe = $this->run( $this->sample( 'files', $items ), function( $path ) {
			$absolute = $this->core->resolve_upload_path( $path );
			if ( is_wp_error( $absolute ) ) return;
			$this->core->file_fingerprint( $absolute );
		} );
		if ( $probe['samples'] < 1 ) return $probe;
		$started = microtime( true );
		$roundtrip = $this->core->test_quarantine_roundtrip();
		if ( !is_wp_error( $roundtrip ) ) {
			$probe['seconds'] += ( microtime( true ) - $started ) * $probe['samples'];
			$probe['note'] = __( 'Includes a quarantine round-trip per item.', 'media-cleaner' );
		}
		return $probe;
	}

	// Flushing references is one multi-row INSERT. It is timed against a temporary copy of the
	// real table — same columns, same unique key, so the same insert cost — which is private to
	// this connection and cannot touch a scan's results.
	private function probe_references( $items ) {
		global $wpdb;
		$table = $wpdb->prefix . 'mclean_refs';
		$temporary = $table . '_benchmark';
		$suppressed = $wpdb->suppress_errors( true );
		$created = $wpdb->query( "CREATE TEMPORARY TABLE $temporary LIKE $table" );
		if ( $created === false ) {
			$wpdb->suppress_errors( $suppressed );
			return $this->unmeasured( __( 'The database user cannot create temporary tables.', 'media-cleaner' ) );
		}

		$values = array();
		$placeholders = array();
		for ( $i = 0; $i < $items; $i++ ) {
			$url = sprintf( '%d/%02d/media-cleaner-benchmark-%d.jpg', 2000 + ( $i % 25 ), 1 + ( $i % 12 ), $i );
			array_push( $values, 0, $url, hash( 'sha256', $url ), 'BENCHMARK', md5( $url ) );
			$placeholders[] = "('%d', NULL, '%s', '%s', '%s', NULL, NULL, '%s')";
		}
		$query = "INSERT IGNORE INTO $temporary (run_id, mediaId, mediaUrl, mediaUrl_hash, originType, origin, parentId, ref_hash) VALUES "
			. implode( ', ', $placeholders );

		$baseline = $this->memory_baseline();
		$started = microtime( true );
		$inserted = $wpdb->query( $wpdb->prepare( $query, $values ) );
		$elapsed = microtime( true ) - $started;
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS $temporary" );
		$wpdb->suppress_errors( $suppressed );
		if ( $inserted === false ) return $this->unmeasured( __( 'The reference flush could not be timed.', 'media-cleaner' ) );

		return array(
			'samples' => $items,
			'seconds' => $elapsed,
			'bytes' => $this->memory_cost( $baseline ),
			// One INSERT is one item here: it either fitted or it did not.
			'truncated' => false,
			'slowest' => null,
			'note' => '',
		);
	}

	#endregion

	#region Plumbing

	// How much of each kind there is to sample, so the plan never offers a rung the install
	// cannot fill. Everything here is a count or a single-entry probe.
	private function availability() {
		$engine = $this->core->engine;
		$posts = $engine ? $engine->count_posts_to_check() : 0;
		$medias = $engine ? $engine->count_media_entries() : 0;
		$files = 0;
		if ( $engine && $medias > 0 ) {
			try {
				$files = count( $engine->get_files( '', 0, 1 ) ) > 0 ? $medias : 0;
			}
			catch ( Throwable $e ) {
				$files = 0;
			}
		}
		return array(
			'content' => $posts,
			'media' => $medias,
			'analysis' => $medias,
			'files' => $files,
			'file_operation' => $medias,
			// A temporary table can hold any number of rows; the ladder decides.
			'references' => PHP_INT_MAX,
		);
	}

	private function ladder( $rungs, $available ) {
		if ( $available < 1 ) return array();
		$usable = array();
		foreach ( $rungs as $rung ) {
			if ( $rung <= $available ) $usable[] = $rung;
		}
		// Too small for even the first rung: measure what there is, once.
		return empty( $usable ) ? array( (int) $available ) : $usable;
	}

	// Sampled across the whole library, not one end of it. The heaviest content is usually not
	// the newest: it is the page some builder produced years ago and nobody has opened since.
	// Reading only recent posts reports a server faster than the one the scan will meet.
	private function sample( $kind, $items ) {
		$engine = $this->core->engine;
		if ( !$engine ) return array();
		if ( $kind === 'posts' ) {
			$total = $engine->count_posts_to_check();
			$posts = array();
			foreach ( $this->slices( $total, $items ) as $slice ) {
				$posts = array_merge( $posts, $engine->get_posts_to_check( $slice[0], $slice[1] ) );
			}
			return $posts;
		}
		$total = $engine->count_media_entries();
		$medias = array();
		foreach ( $this->slices( $total, $items ) as $slice ) {
			$medias = array_merge( $medias, $engine->get_media_entries( $slice[0], $slice[1] ) );
		}
		if ( $kind === 'medias' ) return $medias;

		// Files: the paths of those media, which are real files in the uploads directory.
		$files = array();
		foreach ( $medias as $media_id ) {
			foreach ( $this->core->get_paths_from_attachment( $media_id ) as $path ) {
				if ( $path !== '' ) $files[] = $path;
			}
			if ( count( $files ) >= $items ) break;
		}
		return array_slice( array_unique( $files ), 0, $items );
	}

	// Splits a sample into a few evenly spread windows over the whole range: the oldest, the
	// middle and the newest, rather than one block at one end.
	private function slices( $total, $items, $chunks = 3 ) {
		$items = (int) min( $items, $total );
		if ( $items < 1 ) return array();
		$chunks = max( 1, min( $chunks, $items ) );
		$per_chunk = (int) ceil( $items / $chunks );
		$slices = array();
		$taken = 0;
		for ( $i = 0; $i < $chunks && $taken < $items; $i++ ) {
			$size = min( $per_chunk, $items - $taken );
			$offset = $chunks === 1 ? 0 : (int) floor( $i * ( $total - $size ) / ( $chunks - 1 ) );
			$slices[] = array( max( 0, $offset ), $size );
			$taken += $size;
		}
		return $slices;
	}

	private function run( $items, $work ) {
		if ( empty( $items ) ) return $this->unmeasured();
		$total = count( $items );
		$baseline = $this->memory_baseline();
		$started = microtime( true );
		$done = 0;
		$truncated = false;
		// The slowest single item is worth keeping: when one of them is heavy enough to fill a
		// request on its own, that is the thing to tell the user about, and no buffer can fix it.
		$slowest = null;
		foreach ( $items as $item ) {
			$item_started = microtime( true );
			call_user_func( $work, $item );
			$now = microtime( true );
			$done++;
			if ( $slowest === null || $now - $item_started > $slowest['seconds'] ) {
				$slowest = array(
					'item' => is_scalar( $item ) ? $item : '',
					'seconds' => $now - $item_started,
				);
			}
			if ( $now > $this->deadline ) {
				$truncated = $done < $total;
				break;
			}
		}
		return array(
			'samples' => $done,
			'seconds' => microtime( true ) - $started,
			'bytes' => $this->memory_cost( $baseline ),
			'truncated' => $truncated,
			'slowest' => $slowest,
			'note' => '',
		);
	}

	private function unmeasured( $note = null ) {
		if ( $note === null ) $note = __( 'Nothing to sample here.', 'media-cleaner' );
		// Kept in the same shape as a real round so callers never special-case an empty probe.
		return array( 'samples' => 0, 'seconds' => 0, 'bytes' => 0,
			'truncated' => false, 'slowest' => null, 'note' => $note );
	}

	// Peak memory only ever grows during a request, so a probe can measure its own peak only
	// where PHP lets it be reset (8.2+). Below that no memory figure is produced and the
	// buffers are sized on time alone: an invented number would be worse than none.
	private function memory_baseline() {
		if ( !function_exists( 'memory_reset_peak_usage' ) ) return null;
		memory_reset_peak_usage();
		return memory_get_usage( true );
	}

	private function memory_cost( $baseline ) {
		return $baseline === null ? 0 : max( 0, memory_get_peak_usage( true ) - $baseline );
	}

	#endregion

	#region Sizing

	private function sanitize_rounds( $rounds ) {
		if ( !is_array( $rounds ) ) return array();
		$clean = array();
		foreach ( $this->probes() as $name => $probe ) {
			if ( empty( $rounds[ $name ] ) || !is_array( $rounds[ $name ] ) ) continue;
			foreach ( $rounds[ $name ] as $round ) {
				if ( !is_array( $round ) ) continue;
				$samples = isset( $round['samples'] ) ? (int) $round['samples'] : 0;
				$seconds = isset( $round['seconds'] ) ? (float) $round['seconds'] : 0;
				if ( $samples < 1 || $seconds <= 0 ) continue;
				$slowest = null;
				if ( isset( $round['slowest'] ) && is_array( $round['slowest'] ) ) {
					$slowest = array(
						'item' => isset( $round['slowest']['item'] ) && is_scalar( $round['slowest']['item'] )
							? sanitize_text_field( (string) $round['slowest']['item'] ) : '',
						'seconds' => isset( $round['slowest']['seconds'] ) ? max( 0, (float) $round['slowest']['seconds'] ) : 0,
					);
				}
				$clean[ $name ][] = array(
					'samples' => $samples,
					'seconds' => $seconds,
					'bytes' => isset( $round['bytes'] ) ? max( 0, (float) $round['bytes'] ) : 0,
					'roundtrip' => isset( $round['roundtrip'] ) ? max( 0, (float) $round['roundtrip'] ) : 0,
					'truncated' => !empty( $round['truncated'] ),
					'slowest' => $slowest,
				);
			}
		}
		return $clean;
	}

	/**
	 * Separates the fixed cost of a batch from the cost of one item, by least squares over the
	 * rungs. With a single rung there is nothing to separate, so all of it is charged per item —
	 * which is the pessimistic reading, and the safe one.
	 *
	 * Returns null when there is no usable evidence, meaning "leave this setting alone".
	 */
	private function fit( $rounds ) {
		if ( empty( $rounds ) ) return null;
		// A rung the server had to cut short measures the moment it ran out of time, not the
		// cost of the work: a single pathological item inside it would otherwise drag every
		// batch down to one item. It is reported as a warning instead — unless it is all the
		// evidence there is, in which case this really is what the phase costs here.
		$complete = array_values( array_filter( $rounds, function( $round ) {
			return empty( $round['truncated'] );
		} ) );
		$rounds = empty( $complete ) ? array_values( $rounds ) : $complete;
		$n = count( $rounds );
		$bytes = 0;
		foreach ( $rounds as $round ) {
			$bytes = max( $bytes, $round['bytes'] / $round['samples'] );
		}
		if ( $n === 1 ) {
			$round = $rounds[0];
			return array(
				'per_item' => $round['seconds'] / $round['samples'],
				'fixed' => 0.0,
				'bytes_per_item' => $bytes,
				'rungs' => $n,
			);
		}

		$sum_x = $sum_y = $sum_xy = $sum_xx = 0;
		foreach ( $rounds as $round ) {
			$sum_x += $round['samples'];
			$sum_y += $round['seconds'];
			$sum_xy += $round['samples'] * $round['seconds'];
			$sum_xx += $round['samples'] * $round['samples'];
		}
		$divisor = ( $n * $sum_xx ) - ( $sum_x * $sum_x );
		$slope = $divisor == 0 ? 0 : ( ( $n * $sum_xy ) - ( $sum_x * $sum_y ) ) / $divisor;
		$intercept = $divisor == 0 ? 0 : ( $sum_y - ( $slope * $sum_x ) ) / $n;

		// A flat or falling line means the noise was larger than the signal — the items are too
		// cheap to separate at these sizes. Fall back to the plain average, fixed cost included.
		if ( $slope <= 0 ) {
			return array(
				'per_item' => $sum_y / $sum_x,
				'fixed' => 0.0,
				'bytes_per_item' => $bytes,
				'rungs' => $n,
			);
		}
		return array(
			'per_item' => $slope,
			'fixed' => max( 0.0, $intercept ),
			'bytes_per_item' => $bytes,
			'rungs' => $n,
		);
	}

	// How many items fit in one request, by time and by memory — and for references, by the
	// packet MySQL accepts, since one INSERT carries every row at once and an oversized packet
	// is a failed scan rather than a slow one.
	private function size( $name, $fit, $budget, $environment ) {
		if ( empty( $fit['per_item'] ) ) return null;
		$for_items = $budget - $fit['fixed'];
		// The fixed cost alone already fills the budget: one item at a time is all this server
		// can promise. The engine will still yield if even that does not fit.
		if ( $for_items <= 0 ) return 1;

		$size = max( 1, (int) floor( $for_items / $fit['per_item'] ) );
		if ( $environment['memory_limit'] > 0 && $fit['bytes_per_item'] > 0 ) {
			$by_memory = (int) floor( ( $environment['memory_limit'] * self::MEMORY_TARGET ) / $fit['bytes_per_item'] );
			$size = max( 1, min( $size, $by_memory ) );
		}
		if ( $name === 'references' && $environment['max_allowed_packet'] > 0 ) {
			$by_packet = (int) floor( ( $environment['max_allowed_packet'] * self::MEMORY_TARGET ) / self::REF_ROW_BYTES );
			$size = max( 1, min( $size, $by_packet ) );
		}
		return $size;
	}

	private function clamp( $option, $value ) {
		$ranges = $this->core->option_ranges();
		if ( !isset( $ranges[ $option ] ) ) return (int) $value;
		return (int) max( $ranges[ $option ][0], min( $ranges[ $option ][1], (int) $value ) );
	}

	/**
	 * The things a buffer cannot fix.
	 *
	 * When a rung has to be cut short, the request ran out of time in the middle of it. If the
	 * slowest item in that rung fills a request on its own, no batch size will help: the scan
	 * will stop on that item every time it reaches it, whatever the buffers say. That is worth
	 * naming, with the item, because the fix is in the content or in the request budget.
	 */
	private function warnings( $rounds, $environment ) {
		$warnings = array();
		foreach ( $this->probes() as $name => $probe ) {
			if ( empty( $rounds[ $name ] ) ) continue;
			foreach ( $rounds[ $name ] as $round ) {
				if ( empty( $round['truncated'] ) ) continue;
				$slowest = $round['slowest'];
				$alone = $slowest && $slowest['seconds'] >= $environment['request_budget'] * self::BUDGET_TARGET;
				$warnings[] = array(
					'probe' => $name,
					'label' => $probe['label'],
					'critical' => $alone,
					'message' => $alone
						? sprintf(
							__( '%1$s: one item (#%2$s) took %3$s on its own, more than a single request can spend. A scan will stop there whatever the buffer is. That item needs looking at, or the request budget needs raising.', 'media-cleaner' ),
							$probe['label'], $slowest['item'], $this->readable_seconds( $slowest['seconds'] ) )
						: sprintf(
							__( '%1$s: %2$d items took %3$s, so the request ran out of time before that round finished. The buffer was sized from the rounds that did finish.', 'media-cleaner' ),
							$probe['label'], $round['samples'], $this->readable_seconds( $round['seconds'] ) ),
				);
				break;
			}
		}
		return $warnings;
	}

	private function readable_seconds( $seconds ) {
		return $seconds >= 1
			? sprintf( __( '%.1f s', 'media-cleaner' ), $seconds )
			: sprintf( __( '%d ms', 'media-cleaner' ), (int) round( $seconds * 1000 ) );
	}

	// What a request costs before any work happens: the round trip the browser saw, minus the
	// time the server spent working. Reported for the user, not used for sizing — it argues for
	// fewer, bigger batches, and the buffer ranges already decide how big those may get.
	private function request_overhead( $rounds ) {
		$overheads = array();
		foreach ( $rounds as $probe_rounds ) {
			foreach ( $probe_rounds as $round ) {
				if ( $round['roundtrip'] > 0 ) $overheads[] = max( 0, $round['roundtrip'] - $round['seconds'] );
			}
		}
		if ( empty( $overheads ) ) return null;
		sort( $overheads );
		return $overheads[ (int) floor( count( $overheads ) / 2 ) ];
	}

	// The longest request that actually came back. It does not prove where the proxy cuts off,
	// but it is a floor on it, and a useful thing to see next to the assumed budget.
	private function longest_request( $rounds ) {
		$longest = 0;
		foreach ( $rounds as $probe_rounds ) {
			foreach ( $probe_rounds as $round ) {
				$longest = max( $longest, $round['roundtrip'], $round['seconds'] );
			}
		}
		return $longest > 0 ? $longest : null;
	}

	#endregion
}
