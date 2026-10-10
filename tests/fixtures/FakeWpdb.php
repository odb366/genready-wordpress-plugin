<?php
namespace GenReady\Tests\Fixtures;

/**
 * In-memory stand-in for $wpdb. It understands only the statements the
 * TokenStore lock issues (INSERT IGNORE and the conditional DELETE) and the
 * uninstaller's prefix DELETE with two LIKE patterns.
 */
class FakeWpdb {
	/** @var string */
	public $options = 'wp_options';
	/** @var array<string,string> option_name => option_value */
	public $rows = array();
	/** @var callable|null Runs right after get_var() reads, to simulate a racing request. */
	public $after_get_var = null;

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[sd]/',
			function ( $m ) use ( &$i, $args ) {
				$value = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	public function query( $sql ) {
		$str = "'((?:[^'\\\\]|\\\\.)*)'";
		if ( preg_match( "/^INSERT IGNORE INTO {$this->options} \\(option_name, option_value, autoload\\) VALUES \\($str, $str, 'off'\\)$/", $sql, $m ) ) {
			$name = stripslashes( $m[1] );
			if ( isset( $this->rows[ $name ] ) ) {
				return 0;
			}
			$this->rows[ $name ] = stripslashes( $m[2] );
			return 1;
		}
		if ( preg_match( "/^DELETE FROM {$this->options} WHERE option_name = $str AND option_value = $str$/", $sql, $m ) ) {
			$name = stripslashes( $m[1] );
			if ( isset( $this->rows[ $name ] ) && $this->rows[ $name ] === stripslashes( $m[2] ) ) {
				unset( $this->rows[ $name ] );
				return 1;
			}
			return 0;
		}
		if ( preg_match( "/^DELETE FROM {$this->options} WHERE option_name LIKE $str OR option_name LIKE $str$/", $sql, $m ) ) {
			$deleted = 0;
			foreach ( array( stripslashes( $m[1] ), stripslashes( $m[2] ) ) as $pattern ) {
				$regex = $this->like_to_regex( $pattern );
				foreach ( array_keys( $this->rows ) as $name ) {
					if ( preg_match( $regex, (string) $name ) ) {
						unset( $this->rows[ $name ] );
						++$deleted;
					}
				}
			}
			return $deleted;
		}
		throw new \RuntimeException( 'FakeWpdb does not understand: ' . $sql );
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/** Turn a LIKE pattern (backslash escapes, % wildcard, _ single char) into a regex. */
	private function like_to_regex( string $pattern ): string {
		$regex = '';
		$len   = strlen( $pattern );
		for ( $i = 0; $i < $len; $i++ ) {
			$char = $pattern[ $i ];
			if ( '\\' === $char && $i + 1 < $len ) {
				$regex .= preg_quote( $pattern[ ++$i ], '/' );
			} elseif ( '%' === $char ) {
				$regex .= '.*';
			} elseif ( '_' === $char ) {
				$regex .= '.';
			} else {
				$regex .= preg_quote( $char, '/' );
			}
		}
		return '/^' . $regex . '$/s';
	}

	public function get_var( $sql ) {
		if ( ! preg_match( "/^SELECT option_value FROM {$this->options} WHERE option_name = '((?:[^'\\\\]|\\\\.)*)'$/", $sql, $m ) ) {
			throw new \RuntimeException( 'FakeWpdb does not understand: ' . $sql );
		}
		$name  = stripslashes( $m[1] );
		$value = isset( $this->rows[ $name ] ) ? $this->rows[ $name ] : null;
		if ( null !== $this->after_get_var ) {
			call_user_func( $this->after_get_var, $this );
		}
		return $value;
	}

	public function delete( $table, $where ) {
		if ( $table !== $this->options || ! isset( $where['option_name'] ) ) {
			throw new \RuntimeException( 'FakeWpdb::delete supports only options by option_name.' );
		}
		if ( ! isset( $this->rows[ $where['option_name'] ] ) ) {
			return 0;
		}
		unset( $this->rows[ $where['option_name'] ] );
		return 1;
	}
}
