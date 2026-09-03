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
 *   ![alt](url)           → image       @ url             → embed
 *   ! Model answer        → the [pcle_model_answer] shortcode
 *   [[block:N:hash]]      → a preserved region (see the token constant below)
 *   anything else         → paragraph
 *   **bold**  *italic*  [text](url)     → inline
 *
 * Each marker applies to its own line, and a blank line ends a run. This is
 * not "markdown support" and should not grow into it: every construct here is
 * one the participant screens and the WordPress editor both already render.
 *
 * Between them these cover what an author writes. What they do not cover —
 * galleries, tables, third-party blocks, classic HTML from before the builder
 * — is not refused: it is preserved as a token whose content is copied from
 * the stored post on save and never round-tripped through the client. So the
 * builder can open anything, and the promise above still holds exactly.
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
 * The marker for a preserved region of content.
 *
 * Anything the authored syntax cannot spell — a gallery, a table, a
 * third-party block, classic HTML from before the builder existed — is handed
 * to the author as one of these tokens rather than withheld. The author can
 * move it, or delete it, but cannot retype it, because its content never
 * travels through the client at all: on save the token is resolved back
 * against the block still stored on the post.
 *
 * The hash is what makes that safe. It pins the token to the exact block it
 * was issued for, so a token cannot be pointed at a different block by editing
 * its index, and a save built against content that has since changed in
 * WordPress is refused instead of splicing the wrong region into place.
 */
const PCLE_AUTHORING_TOKEN_PATTERN = '/^\[\[block:(\d+):([0-9a-f]{8})\]\]$/';

/**
 * The token identifying one stored block.
 *
 * @param int    $index      Position among the stored top-level blocks.
 * @param string $serialized The block as stored.
 * @return string
 */
function pcle_authoring_block_token( $index, $serialized ) {
	return sprintf( '[[block:%d:%s]]', (int) $index, substr( md5( $serialized ), 0, 8 ) );
}

/**
 * A human name for a block, for the note shown beside the editor.
 *
 * @param string|null $name Block name, or null for classic HTML.
 * @return string
 */
function pcle_authoring_block_label( $name ) {
	if ( null === $name || '' === $name ) {
		return __( 'Content written in WordPress', 'platform-cle' );
	}

	$known = array(
		'core/gallery'   => __( 'Image gallery', 'platform-cle' ),
		'core/table'     => __( 'Table', 'platform-cle' ),
		'core/video'     => __( 'Video', 'platform-cle' ),
		'core/audio'     => __( 'Audio', 'platform-cle' ),
		'core/file'      => __( 'File download', 'platform-cle' ),
		'core/code'      => __( 'Code', 'platform-cle' ),
		'core/columns'   => __( 'Columns', 'platform-cle' ),
		'core/buttons'   => __( 'Buttons', 'platform-cle' ),
		'core/separator' => __( 'Separator', 'platform-cle' ),
		'core/shortcode' => __( 'Shortcode', 'platform-cle' ),
	);

	if ( isset( $known[ $name ] ) ) {
		return $known[ $name ];
	}

	// core/media-text → "Media text". Good enough, and it never lies about
	// what is there the way a fixed "unsupported block" would.
	$bare = false !== strpos( $name, '/' ) ? substr( $name, strpos( $name, '/' ) + 1 ) : $name;

	return ucfirst( str_replace( '-', ' ', $bare ) );
}

/**
 * The stored blocks a token may refer to, indexed as the reader indexed them.
 *
 * Whitespace between blocks is not a block anyone can point at, so it is
 * dropped here exactly as it is when the tokens are issued. Both sides must
 * count the same way or a token would resolve to its neighbour.
 *
 * @param string $content Stored post content.
 * @return array<int,array{name:string|null, serialized:string}>
 */
function pcle_authoring_indexed_blocks( $content ) {
	$indexed = array();

	foreach ( parse_blocks( (string) $content ) as $block ) {
		if ( null === $block['blockName'] && '' === trim( $block['innerHTML'] ) ) {
			continue;
		}

		$indexed[] = array(
			'name'       => $block['blockName'],
			'serialized' => serialize_block( $block ),
		);
	}

	return $indexed;
}

/**
 * What kind of block does this line begin?
 *
 * @param string $line One line of authored text.
 * @return string blank|token|image|embed|heading|list|quote|answer|paragraph
 */
function pcle_authoring_line_type( $line ) {
	$trimmed = trim( $line );

	if ( '' === $trimmed ) {
		return 'blank';
	}
	if ( preg_match( PCLE_AUTHORING_TOKEN_PATTERN, $trimmed ) ) {
		return 'token';
	}
	// Before the model answer test: an image opens "![", which has no space
	// after the bang, and a model answer opens "! ", which does.
	if ( preg_match( '/^!\[([^\]]*)\]\(([^)\s]+)\)$/', $trimmed ) ) {
		return 'image';
	}
	if ( preg_match( '/^@ \S+$/', $trimmed ) ) {
		return 'embed';
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
	if ( 0 === strpos( $line, '! ' ) ) {
		return 'answer';
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

	if ( 'quote' === $run_type || 'answer' === $run_type ) {
		$lines = array();
		foreach ( $run as $line ) {
			$lines[] = trim( substr( $line, 2 ) );
		}
		$blocks[] = array(
			'type' => 'quote' === $run_type ? 'quote' : 'answer',
			'text' => implode( ' ', $lines ),
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
 * list, consecutive "> " lines are one quote, consecutive "! " lines are one
 * model answer, and consecutive plain lines are one paragraph with soft
 * breaks. A blank line still ends any run, so text that was already written
 * with blank lines parses exactly as it did before.
 *
 * @param string $text Authored text.
 * @return array<int,array<string,mixed>>
 */
function pcle_authoring_text_to_blocks( $text ) {
	$text  = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
	$lines = explode( "\n", trim( $text ) );

	$blocks   = array();
	$run      = array();
	$run_type = null;

	// Types that are always exactly one line, and so never form a run.
	$singles = array( 'heading', 'image', 'embed', 'token' );

	foreach ( $lines as $line ) {
		$type = pcle_authoring_line_type( $line );

		if ( 'blank' === $type ) {
			$blocks   = pcle_authoring_flush_run( $blocks, $run, $run_type );
			$run      = array();
			$run_type = null;
			continue;
		}

		if ( in_array( $type, $singles, true ) || $type !== $run_type ) {
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

		if ( 'image' === $type ) {
			preg_match( '/^!\[([^\]]*)\]\(([^)\s]+)\)$/', trim( $line ), $m );
			$blocks[] = array(
				'type' => 'image',
				'alt'  => $m[1],
				'url'  => $m[2],
			);
			continue;
		}

		if ( 'embed' === $type ) {
			$blocks[] = array(
				'type' => 'embed',
				'url'  => trim( substr( trim( $line ), 2 ) ),
			);
			continue;
		}

		if ( 'token' === $type ) {
			preg_match( PCLE_AUTHORING_TOKEN_PATTERN, trim( $line ), $m );
			$blocks[] = array(
				'type'  => 'token',
				'index' => (int) $m[1],
				'hash'  => $m[2],
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
 * `token` blocks are NOT serialised here: they carry no content of their own,
 * and resolving them needs the stored post, which this function does not have.
 * pcle_authoring_content_from_text() does that.
 *
 * @param array $blocks Blocks from pcle_authoring_text_to_blocks().
 * @return array<int,array{type:string, markup:string, index?:int, hash?:string}>
 */
function pcle_authoring_blocks_to_parts( $blocks ) {
	$out = array();

	foreach ( $blocks as $block ) {
		switch ( $block['type'] ) {
			case 'token':
				$out[] = array(
					'type'  => 'token',
					'index' => $block['index'],
					'hash'  => $block['hash'],
				);
				break;

			case 'heading':
				$level = 3 === (int) $block['level'] ? 3 : 2;
				$out[] = array(
					'type'   => 'markup',
					'markup' => sprintf(
						"<!-- wp:heading {\"level\":%d} -->\n<h%d>%s</h%d>\n<!-- /wp:heading -->",
						$level,
						$level,
						pcle_authoring_inline_to_html( $block['text'] ),
						$level
					),
				);
				break;

			case 'image':
				/*
				 * The URL is escaped and the tag built here, like every other
				 * construct: an author types a location, never markup.
				 */
				$url = esc_url( $block['url'], array( 'http', 'https' ) );

				if ( '' === $url ) {
					// Nowhere to point: keep the words rather than emit a
					// broken image the author cannot see is broken.
					$out[] = array(
						'type'   => 'markup',
						'markup' => sprintf(
							"<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->",
							pcle_authoring_inline_to_html( $block['alt'] )
						),
					);
					break;
				}

				$out[] = array(
					'type'   => 'markup',
					'markup' => sprintf(
						"<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"%s\" alt=\"%s\"/></figure>\n<!-- /wp:image -->",
						$url,
						esc_attr( $block['alt'] )
					),
				);
				break;

			case 'embed':
				$url = esc_url( $block['url'], array( 'http', 'https' ) );

				if ( '' === $url ) {
					break;
				}

				$out[] = array(
					'type'   => 'markup',
					'markup' => sprintf(
						"<!-- wp:embed {\"url\":\"%s\"} -->\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">\n%s\n</div></figure>\n<!-- /wp:embed -->",
						$url,
						$url
					),
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

				$out[] = array(
					'type'   => 'markup',
					'markup' => "<!-- wp:list -->\n<ul>\n" . $items . "</ul>\n<!-- /wp:list -->",
				);
				break;

			case 'quote':
				$out[] = array(
					'type'   => 'markup',
					'markup' => sprintf(
						"<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph --></blockquote>\n<!-- /wp:quote -->",
						pcle_authoring_inline_to_html( $block['text'] )
					),
				);
				break;

			case 'answer':
				/*
				 * A shortcode rather than a block of its own, because
				 * access-control.php already owns what a model answer is and
				 * who may see it. Wrapping it in core/shortcode is what keeps
				 * it a first-class thing in wp-admin too, instead of the
				 * Classic block that bare HTML would open as.
				 */
				$out[] = array(
					'type'   => 'markup',
					'markup' => sprintf(
						"<!-- wp:shortcode -->\n[pcle_model_answer]<p>%s</p>[/pcle_model_answer]\n<!-- /wp:shortcode -->",
						pcle_authoring_inline_to_html( $block['text'] )
					),
				);
				break;

			default:
				$out[] = array(
					'type'   => 'markup',
					'markup' => sprintf(
						"<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->",
						pcle_authoring_inline_to_html( $block['text'] )
					),
				);
		}
	}

	return $out;
}

/**
 * Authored text straight to stored content.
 *
 * $stored is the content currently on the post. It is needed only to resolve
 * preserved-region tokens, and a token that does not match a block in it is an
 * error rather than something to drop: dropping it would delete an author's
 * image because their draft was stale, which is the failure this whole
 * mechanism exists to prevent.
 *
 * @param string $text   Authored text.
 * @param string $stored Content currently stored on the post.
 * @return string|WP_Error Block markup, or an error naming the stale token.
 */
function pcle_authoring_content_from_text( $text, $stored = '' ) {
	$parts   = pcle_authoring_blocks_to_parts( pcle_authoring_text_to_blocks( $text ) );
	$indexed = pcle_authoring_indexed_blocks( $stored );
	$out     = array();

	foreach ( $parts as $part ) {
		if ( 'token' !== $part['type'] ) {
			$out[] = $part['markup'];
			continue;
		}

		$index = $part['index'];

		if ( ! isset( $indexed[ $index ] )
			|| pcle_authoring_block_token( $index, $indexed[ $index ]['serialized'] ) !== sprintf( '[[block:%d:%s]]', $index, $part['hash'] )
		) {
			return new WP_Error(
				'pcle_stale_block_token',
				__( 'This content changed in WordPress while you were editing it. Reload the page to pick up the change, then save again.', 'platform-cle' ),
				array( 'status' => 409 )
			);
		}

		$out[] = trim( $indexed[ $index ]['serialized'] );
	}

	return implode( "\n\n", $out );
}

/**
 * Reads stored content back into authored text.
 *
 * Everything is representable. What the authored syntax can spell is spelled;
 * everything else — a gallery, a table, a third-party block, classic HTML from
 * before the builder existed — becomes a token standing in for the region,
 * listed in `preserved` so the editor can say what each one is.
 *
 * This used to answer `editable => false` for any of that, and the builder
 * showed the whole body read-only. That was the safe choice while a token
 * mechanism did not exist, but it meant one image made an entire module
 * uneditable. Now nothing is withheld: the parts we understand are editable,
 * the parts we do not are preserved exactly, and re-serialising something we
 * could not parse still never happens, because a token's content is copied
 * from the post rather than rebuilt from the client.
 *
 * `editable` is retained and always true, so existing clients keep working.
 *
 * @param string $content Stored post content.
 * @return array{text:string, editable:bool, preserved:array<int,array{token:string,label:string}>}
 */
function pcle_authoring_text_from_content( $content ) {
	$content = (string) $content;

	if ( '' === trim( $content ) ) {
		return array(
			'text'      => '',
			'editable'  => true,
			'preserved' => array(),
		);
	}

	$parts     = array();
	$preserved = array();

	foreach ( pcle_authoring_indexed_blocks( $content ) as $index => $block ) {
		$parsed = parse_blocks( $block['serialized'] );
		$parsed = $parsed[0];
		$name   = $block['name'];
		$inner  = trim( $parsed['innerHTML'] );

		switch ( $name ) {
			case 'core/paragraph':
				$parts[] = pcle_authoring_html_to_inline( preg_replace( '#</?p[^>]*>#', '', $inner ) );
				break;

			case 'core/heading':
				$level   = ( isset( $parsed['attrs']['level'] ) && 3 === (int) $parsed['attrs']['level'] ) ? 3 : 2;
				$prefix  = 3 === $level ? '### ' : '## ';
				$parts[] = $prefix . pcle_authoring_html_to_inline( preg_replace( '#</?h[1-6][^>]*>#', '', $inner ) );
				break;

			case 'core/image':
				if ( ! preg_match( '#<img[^>]*src="([^"]*)"#', $inner, $src ) ) {
					$parts[] = pcle_authoring_preserve( $index, $block, $preserved );
					break;
				}

				$alt     = preg_match( '#<img[^>]*alt="([^"]*)"#', $inner, $a ) ? $a[1] : '';
				$parts[] = sprintf( '![%s](%s)', html_entity_decode( $alt, ENT_QUOTES, get_bloginfo( 'charset' ) ), $src[1] );
				break;

			case 'core/embed':
				if ( empty( $parsed['attrs']['url'] ) ) {
					$parts[] = pcle_authoring_preserve( $index, $block, $preserved );
					break;
				}

				$parts[] = '@ ' . $parsed['attrs']['url'];
				break;

			case 'core/shortcode':
				// Only our own model answer is authored text; any other
				// shortcode is preserved whole, since we cannot know what its
				// body means.
				if ( ! preg_match( '#^\[pcle_model_answer\](.*)\[/pcle_model_answer\]$#s', trim( $inner ), $m ) ) {
					$parts[] = pcle_authoring_preserve( $index, $block, $preserved );
					break;
				}

				$parts[] = '! ' . pcle_authoring_html_to_inline( preg_replace( '#</?p[^>]*>#', '', trim( $m[1] ) ) );
				break;

			case 'core/list':
				$items = array();
				$ok    = true;

				foreach ( $parsed['innerBlocks'] as $item ) {
					if ( 'core/list-item' !== $item['blockName'] ) {
						$ok = false;
						break;
					}

					$items[] = '- ' . pcle_authoring_html_to_inline( preg_replace( '#</?li[^>]*>#', '', trim( $item['innerHTML'] ) ) );
				}

				// A nested list, or anything else inside: preserve it whole
				// rather than flatten away the nesting.
				$parts[] = $ok ? implode( "\n", $items ) : pcle_authoring_preserve( $index, $block, $preserved );
				break;

			case 'core/quote':
				$quoted = '';
				$ok     = true;

				foreach ( $parsed['innerBlocks'] as $item ) {
					if ( 'core/paragraph' !== $item['blockName'] ) {
						$ok = false;
						break;
					}

					$quoted .= pcle_authoring_html_to_inline( preg_replace( '#</?p[^>]*>#', '', trim( $item['innerHTML'] ) ) );
				}

				$parts[] = $ok ? '> ' . $quoted : pcle_authoring_preserve( $index, $block, $preserved );
				break;

			default:
				$parts[] = pcle_authoring_preserve( $index, $block, $preserved );
		}
	}

	return array(
		'text'      => implode( "\n\n", $parts ),
		'editable'  => true,
		'preserved' => $preserved,
	);
}

/**
 * Issues a token for a block, and records what it stands for.
 *
 * @param int                                                $index     Block index.
 * @param array{name:string|null, serialized:string}         $block     The block.
 * @param array<int,array{token:string,label:string}>        $preserved Collected, by reference.
 * @return string The token to place in the authored text.
 */
function pcle_authoring_preserve( $index, $block, &$preserved ) {
	$token = pcle_authoring_block_token( $index, $block['serialized'] );

	$preserved[] = array(
		'token' => $token,
		'label' => pcle_authoring_block_label( $block['name'] ),
	);

	return $token;
}
