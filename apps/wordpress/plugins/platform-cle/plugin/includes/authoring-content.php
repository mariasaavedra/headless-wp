<?php
/**
 * Turning what an author types into what WordPress stores.
 *
 * The builder sends **plain text**, never HTML. The server escapes it and
 * constructs the tags itself, so there is no path by which markup from a
 * client reaches the database. That is not belt-and-braces: instructors do not
 * hold `unfiltered_html`, and an authoring API must not become the way around
 * that.
 *
 * What gets stored is Gutenberg block markup rather than bare HTML. Bare HTML
 * opens in wp-admin as a single "Classic" block, and re-saving it there
 * rewrites the paragraph structure — a quietly lossy loop between the two
 * editors. Block markup opens as native, editable blocks, which is what makes
 * the "pragmatic coexistence" promise true rather than aspirational.
 *
 * The authored form is a small, deliberate markup:
 *
 *   ## Heading            → h2          - item            → list
 *   ### Heading           → h3          > quoted          → quote
 *   anything else         → paragraph
 *   **bold**  *italic*  [text](url)     → inline
 *
 * Each marker applies to its own line, and a blank line ends a run. This is
 * not "markdown support" and should not grow into it: every construct here is
 * one the participant screens and the WordPress editor both already render.
 *
 * @package Platform_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the inline syntax to HTML.
 *
 * Escapes first, then builds tags from the escaped text — so a `<script>` an
 * author types is inert before this function decides anything.
 *
 * @param string $text Plain text with inline markers.
 * @return string HTML.
 */
function pcle_authoring_inline_to_html( $text ) {
	$html = esc_html( $text );

	// Links first: their label may itself contain emphasis markers.
	$html = preg_replace_callback(
		'/\[([^\]]+)\]\(([^)\s]+)\)/',
		function ( $match ) {
			$url = esc_url( $match[2], array( 'http', 'https', 'mailto' ) );

			// An unsupported scheme leaves the label as plain text rather than
			// producing a link that goes nowhere.
			if ( '' === $url ) {
				return $match[1];
			}

			return '<a href="' . $url . '">' . $match[1] . '</a>';
		},
		$html
	);

	$html = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html );
	$html = preg_replace( '/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $html );

	return $html;
}

/**
 * Recovers the inline syntax from HTML.
 *
 * The inverse of the above, for the editing round trip. Anything it does not
 * recognise is reported by the caller as not editable rather than mangled.
 *
 * @param string $html HTML.
 * @return string Plain text with inline markers.
 */
function pcle_authoring_html_to_inline( $html ) {
	$text = preg_replace( '#<a href="([^"]*)"[^>]*>(.*?)</a>#s', '[$2]($1)', $html );
	$text = preg_replace( '#<(strong|b)>(.*?)</\1>#s', '**$2**', $text );
	$text = preg_replace( '#<(em|i)>(.*?)</\1>#s', '*$2*', $text );

	return html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, get_bloginfo( 'charset' ) );
}

/**
 * What kind of block does this line begin?
 *
 * @param string $line One line of authored text.
 * @return string blank|heading|list|quote|paragraph
 */
function pcle_authoring_line_type( $line ) {
	if ( '' === trim( $line ) ) {
		return 'blank';
	}
	if ( 0 === strpos( $line, '## ' ) || 0 === strpos( $line, '### ' ) ) {
		return 'heading';
	}
	if ( 0 === strpos( $line, '- ' ) ) {
		return 'list';
	}
	if ( 0 === strpos( $line, '> ' ) ) {
		return 'quote';
	}

	return 'paragraph';
}

/**
 * Closes an open run of lines into a block.
 *
 * @param array    $blocks   Blocks so far.
 * @param string[] $run      Buffered lines.
 * @param string   $run_type Their type.
 * @return array Blocks, with the run appended.
 */
function pcle_authoring_flush_run( $blocks, $run, $run_type ) {
	if ( ! $run ) {
		return $blocks;
	}

	if ( 'list' === $run_type ) {
		$items = array();
		foreach ( $run as $line ) {
			$items[] = trim( substr( $line, 2 ) );
		}
		$blocks[] = array(
			'type'  => 'list',
			'items' => $items,
		);

		return $blocks;
	}

	if ( 'quote' === $run_type ) {
		$quoted = array();
		foreach ( $run as $line ) {
			$quoted[] = trim( substr( $line, 2 ) );
		}
		$blocks[] = array(
			'type' => 'quote',
			'text' => implode( ' ', $quoted ),
		);

		return $blocks;
	}

	$blocks[] = array(
		'type' => 'paragraph',
		// A single newline inside a paragraph is a soft break.
		'text' => implode( ' ', array_map( 'trim', $run ) ),
	);

	return $blocks;
}

/**
 * Splits authored text into typed blocks.
 *
 * Markers are read per LINE, not per blank-line-separated chunk. They used to
 * be per chunk: the first line decided the type and the rest of the chunk was
 * swallowed into it, so
 *
 *     ## Detention review
 *     Check the custody deadline.
 *     - First point
 *
 * became a single <h2> containing all three lines. Nothing was lost — it read
 * back out identically — but what a participant saw was wrong, and the only
 * way to avoid it was to know that blank lines were load-bearing.
 *
 * Runs still matter where they mean something: consecutive "- " lines are one
 * list, consecutive "> " lines are one quote, and consecutive plain lines are
 * one paragraph with soft breaks. A blank line still ends any run, so text
 * that was already written with blank lines parses exactly as it did before.
 *
 * @param string $text Authored text.
 * @return array<int,array{type:string, text:string, level?:int, items?:string[]}>
 */
function pcle_authoring_text_to_blocks( $text ) {
	$text  = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
	$lines = explode( "\n", trim( $text ) );

	$blocks   = array();
	$run      = array();
	$run_type = null;

	foreach ( $lines as $line ) {
		$type = pcle_authoring_line_type( $line );

		if ( 'blank' === $type ) {
			$blocks   = pcle_authoring_flush_run( $blocks, $run, $run_type );
			$run      = array();
			$run_type = null;
			continue;
		}

		// A heading is always one line, and any change of type ends the run.
		if ( 'heading' === $type || $type !== $run_type ) {
			$blocks   = pcle_authoring_flush_run( $blocks, $run, $run_type );
			$run      = array();
			$run_type = null;
		}

		if ( 'heading' === $type ) {
			$level    = 0 === strpos( $line, '### ' ) ? 3 : 2;
			$blocks[] = array(
				'type'  => 'heading',
				'level' => $level,
				'text'  => trim( substr( $line, 3 === $level ? 4 : 3 ) ),
			);
			continue;
		}

		$run_type = $type;
		$run[]    = $line;
	}

	return pcle_authoring_flush_run( $blocks, $run, $run_type );
}

/**
 * Serialises typed blocks as Gutenberg block markup.
 *
 * @param array $blocks Blocks from pcle_authoring_text_to_blocks().
 * @return string
 */
function pcle_authoring_blocks_to_content( $blocks ) {
	$out = array();

	foreach ( $blocks as $block ) {
		switch ( $block['type'] ) {
			case 'heading':
				$level = 3 === (int) $block['level'] ? 3 : 2;
				$out[] = sprintf(
					"<!-- wp:heading {\"level\":%d} -->\n<h%d>%s</h%d>\n<!-- /wp:heading -->",
					$level,
					$level,
					pcle_authoring_inline_to_html( $block['text'] ),
					$level
				);
				break;

			case 'list':
				$items = '';

				foreach ( $block['items'] as $item ) {
					$items .= sprintf(
						"<!-- wp:list-item -->\n<li>%s</li>\n<!-- /wp:list-item -->\n",
						pcle_authoring_inline_to_html( $item )
					);
				}

				$out[] = "<!-- wp:list -->\n<ul>\n" . $items . "</ul>\n<!-- /wp:list -->";
				break;

			case 'quote':
				$out[] = sprintf(
					"<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph --></blockquote>\n<!-- /wp:quote -->",
					pcle_authoring_inline_to_html( $block['text'] )
				);
				break;

			default:
				$out[] = sprintf(
					"<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->",
					pcle_authoring_inline_to_html( $block['text'] )
				);
		}
	}

	return implode( "\n\n", $out );
}

/**
 * Authored text straight to stored content.
 *
 * @param string $text Authored text.
 * @return string Block markup.
 */
function pcle_authoring_content_from_text( $text ) {
	return pcle_authoring_blocks_to_content( pcle_authoring_text_to_blocks( $text ) );
}

/**
 * Reads stored content back into authored text.
 *
 * Returns `editable => false` when the content contains anything this
 * converter did not produce — a block it does not know, or pre-block HTML from
 * before the builder existed. In that case the builder shows the content
 * read-only and points at WordPress, because **silently re-serialising
 * something we could not fully parse would destroy an author's work** while
 * looking like a successful save.
 *
 * @param string $content Stored post content.
 * @return array{text:string, editable:bool}
 */
function pcle_authoring_text_from_content( $content ) {
	$content = (string) $content;

	if ( '' === trim( $content ) ) {
		return array(
			'text'     => '',
			'editable' => true,
		);
	}

	$blocks = parse_blocks( $content );
	$parts  = array();

	foreach ( $blocks as $block ) {
		$name  = $block['blockName'];
		$inner = trim( $block['innerHTML'] );

		// parse_blocks() emits nameless blocks for whitespace between blocks.
		if ( null === $name ) {
			if ( '' !== $inner ) {
				return array(
					'text'     => '',
					'editable' => false,
				);
			}
			continue;
		}

		switch ( $name ) {
			case 'core/paragraph':
				$parts[] = pcle_authoring_html_to_inline( preg_replace( '#</?p[^>]*>#', '', $inner ) );
				break;

			case 'core/heading':
				$level    = ( isset( $block['attrs']['level'] ) && 3 === (int) $block['attrs']['level'] ) ? 3 : 2;
				$prefix   = 3 === $level ? '### ' : '## ';
				$parts[]  = $prefix . pcle_authoring_html_to_inline( preg_replace( '#</?h[1-6][^>]*>#', '', $inner ) );
				break;

			case 'core/list':
				$items = array();

				foreach ( $block['innerBlocks'] as $item ) {
					if ( 'core/list-item' !== $item['blockName'] ) {
						return array(
							'text'     => '',
							'editable' => false,
						);
					}

					$items[] = '- ' . pcle_authoring_html_to_inline( preg_replace( '#</?li[^>]*>#', '', trim( $item['innerHTML'] ) ) );
				}

				$parts[] = implode( "\n", $items );
				break;

			case 'core/quote':
				$quoted = '';

				foreach ( $block['innerBlocks'] as $item ) {
					$quoted .= pcle_authoring_html_to_inline( preg_replace( '#</?p[^>]*>#', '', trim( $item['innerHTML'] ) ) );
				}

				$parts[] = '> ' . $quoted;
				break;

			default:
				// A block the builder cannot express — an image, an embed,
				// anything from the block editor's own inserter.
				return array(
					'text'     => '',
					'editable' => false,
				);
		}
	}

	return array(
		'text'     => implode( "\n\n", $parts ),
		'editable' => true,
	);
}
