<?php
/**
 * The text that AI crawlers read, as formatted HTML for the Overview.
 *
 * @package GenReady
 */

namespace GenReady\Support;

use GenReady\Vendor\Parsedown;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the Markdown of a scanned page into safe HTML. The Markdown comes from
 * any public page, so it is untrusted: Parsedown runs in safe mode (HTML in the
 * text shows as text, unsafe link schemes are dropped) and wp_kses filters the
 * result. Images never load, because a remote image would call another site
 * from wp-admin; each one shows as "[Image: alt text]". Headings move down two
 * levels to sit under the card's h2. Links open in a new tab.
 */
final class Markdown {
	/** Tags and attributes the result may keep. */
	const ALLOWED = array(
		'p'          => array(),
		'br'         => array(),
		'hr'         => array(),
		'h3'         => array(),
		'h4'         => array(),
		'h5'         => array(),
		'h6'         => array(),
		'strong'     => array(),
		'em'         => array(),
		'del'        => array(),
		'code'       => array(),
		'pre'        => array(),
		'blockquote' => array(),
		'ul'         => array(),
		'ol'         => array( 'start' => true ),
		'li'         => array(),
		'table'      => array(),
		'thead'      => array(),
		'tbody'      => array(),
		'tr'         => array(),
		'th'         => array(),
		'td'         => array(),
		'a'          => array(
			'href'   => true,
			'title'  => true,
			'target' => true,
			'rel'    => true,
		),
	);

	/**
	 * Safe HTML for the Markdown, or '' when it has no text.
	 *
	 * @param string $markdown Markdown of the scanned page.
	 */
	public static function to_html( string $markdown ): string {
		if ( '' === trim( $markdown ) ) {
			return '';
		}
		$parser = new class() extends Parsedown {
			/**
			 * An image as text: "[Image: alt]", or "[Image]" without alt text.
			 *
			 * @param array $excerpt The text from the "!" on.
			 * @return array|null
			 */
			protected function inlineImage( $excerpt ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Parsedown's method name.
				$image = parent::inlineImage( $excerpt );
				if ( ! is_array( $image ) ) {
					return null;
				}
				$alt = trim( (string) ( $image['element']['attributes']['alt'] ?? '' ) );
				return array(
					'extent'  => $image['extent'],
					'element' => array( 'text' => '' === $alt ? '[Image]' : '[Image: ' . $alt . ']' ),
				);
			}
		};
		$parser->setSafeMode( true );
		$parser->setMarkupEscaped( true );
		$html = (string) $parser->text( $markdown );
		$html = (string) preg_replace_callback(
			'#<(/?)h([1-6])>#',
			static function ( array $tag ): string {
				return '<' . $tag[1] . 'h' . min( 6, (int) $tag[2] + 2 ) . '>';
			},
			$html
		);
		$html = str_replace( '<a href=', '<a target="_blank" rel="nofollow noopener noreferrer" href=', $html );
		return wp_kses( $html, self::ALLOWED );
	}
}
