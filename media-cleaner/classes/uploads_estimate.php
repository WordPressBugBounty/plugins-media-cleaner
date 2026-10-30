<?php

// The free version does not scan the uploads folder, but it can tell people what such a scan
// would find on their own site: a real number is the only honest way to show what Pro adds when
// no page builder is involved. It is a lower bound by design. It only counts media files the
// Library does not know at all, skips the retina and WebP copies Pro matches to an original, and
// stops on a time budget, so whatever it says, Pro finds at least that much.
class Meow_WPMC_Uploads_Estimate {

	const TRANSIENT = 'wpmc_uploads_estimate';
	const BUDGET = 3;
	const MAX_KNOWN_PATHS = 300000;
	const MEDIA_EXTENSIONS = '/\.(jpe?g|jpe|png|gif|avif|bmp|tiff?|svg|pdf|mp4|m4v|mov|webm|mp3|m4a|wav|ogg)$/i';

	private $core;
	private $deadline = 0;

	public function __construct( $core ) {
		$this->core = $core;
	}

	// One estimate per published run: counting again on every dashboard load would walk the
	// uploads folder for nothing, and a new scan is the moment the number can change.
	public function get() {
		$run_id = $this->core->runs ? (int) $this->core->runs->get_active_id() : 0;
		if ( $run_id <= 0 ) return null;
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['run_id'] ) && (int) $cached['run_id'] === $run_id ) {
			return $cached;
		}
		$estimate = $this->compute();
		if ( $estimate === null ) return null;
		$estimate['run_id'] = $run_id;
		set_transient( self::TRANSIENT, $estimate, WEEK_IN_SECONDS );
		return $estimate;
	}

	private function compute() {
		$uploads = wp_upload_dir();
		if ( !empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || !is_dir( $uploads['basedir'] ) ) return null;
		$this->deadline = microtime( true ) + self::BUDGET;
		$known = $this->known_paths();
		// Without the complete list, any file could be one we simply have not loaded yet.
		if ( $known === null ) return null;
		return $this->count_unknown_files( $uploads['basedir'], $known );
	}

	private function known_paths() {
		global $wpdb;
		$known = array();
		$last_id = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT meta_id, meta_key, meta_value FROM $wpdb->postmeta
				WHERE meta_id > %d AND meta_key IN ('_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes')
				ORDER BY meta_id ASC LIMIT 2000",
				$last_id
			) );
			foreach ( $rows as $row ) {
				$last_id = (int) $row->meta_id;
				foreach ( $this->paths_from_meta( $row->meta_key, $row->meta_value ) as $path ) {
					$known[ $path ] = true;
				}
			}
			if ( count( $known ) > self::MAX_KNOWN_PATHS || microtime( true ) > $this->deadline ) return null;
		} while ( count( $rows ) === 2000 );
		return $known;
	}

	private function paths_from_meta( $key, $value ) {
		if ( $key === '_wp_attached_file' ) return array( ltrim( $value, '/' ) );
		$meta = maybe_unserialize( $value );
		if ( !is_array( $meta ) ) return array();
		$paths = array();
		if ( $key === '_wp_attachment_metadata' ) {
			if ( empty( $meta['file'] ) || !is_string( $meta['file'] ) ) return array();
			$file = ltrim( $meta['file'], '/' );
			$dir = dirname( $file ) === '.' ? '' : trailingslashit( dirname( $file ) );
			$paths[] = $file;
			if ( !empty( $meta['original_image'] ) ) $paths[] = $dir . $meta['original_image'];
			$sizes = isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array();
			foreach ( $sizes as $size ) {
				if ( !empty( $size['file'] ) ) $paths[] = $dir . $size['file'];
			}
			return $paths;
		}
		// Backup sizes keep the files an image had before it was edited, next to the current ones.
		// They have no directory of their own, so they are matched by name in any folder.
		foreach ( $meta as $size ) {
			if ( is_array( $size ) && !empty( $size['file'] ) ) $paths[] = '*/' . $size['file'];
		}
		return $paths;
	}

	private function count_unknown_files( $basedir, $known ) {
		$excluded = $this->core->get_excluded_upload_dirs();
		$directories = new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $basedir, FilesystemIterator::SKIP_DOTS ),
			function ( $file ) use ( $excluded ) {
				return !$file->isLink() && !in_array( $file->getFilename(), $excluded, true );
			}
		);
		$files = 0;
		$bytes = 0;
		$complete = true;
		$prefix = strlen( trailingslashit( $basedir ) );
		try {
			foreach ( new RecursiveIteratorIterator( $directories ) as $file ) {
				if ( microtime( true ) > $this->deadline ) {
					$complete = false;
					break;
				}
				$name = $file->getFilename();
				if ( !preg_match( self::MEDIA_EXTENSIONS, $name ) || strpos( $name, '@2x.' ) !== false ) continue;
				$relative = str_replace( '\\', '/', substr( $file->getPathname(), $prefix ) );
				if ( isset( $known[ $relative ] ) || isset( $known[ '*/' . $name ] ) ) continue;
				$files++;
				$bytes += (int) $file->getSize();
			}
		}
		catch ( UnexpectedValueException $e ) {
			$complete = false;
		}
		return array( 'files' => $files, 'bytes' => $bytes, 'complete' => $complete );
	}
}
