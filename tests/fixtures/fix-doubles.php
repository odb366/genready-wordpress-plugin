<?php
namespace GenReady\Tests\Unit;

use GenReady\Compat\Caches;
use GenReady\Fixes\FileWriter;

/** In-memory files in the site root. */
class MemoryFiles extends FileWriter {
	/** @var array<string, string> */
	public $files = array();
	/** @var bool */
	public $writable = true;
	/** @var bool When true, a write leaves an empty file and fails. */
	public $broken_writes = false;
	/** @var int */
	public $writes = 0;
	/** @var bool When true, files exist but cannot be read. */
	public $unreadable = false;
	/** @var bool */
	public $root = true;
	/** @var bool False on a multisite sub-site, or for a user who is not a network admin. */
	public $may = true;

	public function root_known(): bool {
		return $this->root;
	}

	public function may_write(): bool {
		return $this->may;
	}

	public function put( string $name, string $content ): void {
		$this->files[ $name ] = $content;
	}

	public function exists( string $name ): bool {
		return isset( $this->files[ $name ] );
	}

	public function read( string $name ): ?string {
		return $this->unreadable ? null : ( $this->files[ $name ] ?? null );
	}

	public function can_write( string $name = '' ): bool {
		return $this->writable;
	}

	public function write( string $name, string $content ): bool {
		++$this->writes;
		if ( ! $this->writable || ! $this->may ) {
			return false;
		}
		if ( $this->broken_writes ) {
			$this->files[ $name ] = '';
			$this->broken_writes  = false;
			return false;
		}
		$this->files[ $name ] = $content;
		return true;
	}

	public function delete( string $name ): bool {
		if ( ! $this->may ) {
			return false;
		}
		unset( $this->files[ $name ] );
		return true;
	}
}

/** The real FileWriter with ABSPATH in a temp folder. */
class TempFileWriter extends FileWriter {
	/** @var string */
	public $abspath = '';

	protected function abspath(): string {
		return $this->abspath;
	}
}

/** Caches that only record the purge calls. */
class RecordingCaches extends Caches {
	/** @var array */
	public $calls = array();

	public function purge_all(): array {
		$this->calls[] = 'all';
		return array();
	}

	public function purge_url( string $url, int $post_id = 0 ): array {
		$this->calls[] = $url;
		return array();
	}
}

/** Stand-in for WP_Filesystem_Direct that does real file I/O. */
class TempFilesystem {
	/** @var bool When true, the next write stores other bytes than asked. */
	public $corrupt = false;
	/** @var array */
	public $modes = array();
	/** @var string[] Paths that put_contents() wrote. */
	public $paths = array();
	/** @var bool When true, put_contents() fails. */
	public $fail_put = false;
	/** @var bool When true, move() fails. */
	public $fail_move = false;
	/** @var array[] array( source, destination, overwrite ) of each move. */
	public $moves = array();

	public function put_contents( $path, $content, $mode = false ) {
		$this->modes[] = $mode;
		$this->paths[] = $path;
		if ( $this->fail_put ) {
			file_put_contents( $path, substr( $content, 0, 1 ) );
			return false;
		}
		if ( $this->corrupt ) {
			$content .= 'X';
		}
		return false !== file_put_contents( $path, $content );
	}

	/** Like WP_Filesystem_Direct::move(): delete the destination when overwriting, then rename. */
	public function move( $source, $destination, $overwrite = false ) {
		$this->moves[] = array( $source, $destination, $overwrite );
		if ( $this->fail_move || ( ! $overwrite && file_exists( $destination ) ) ) {
			return false;
		}
		return rename( $source, $destination );
	}

	public function delete( $path ) {
		return @unlink( $path );
	}
}
