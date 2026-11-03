<?php
/**
 * Traces of AI in a media file's own data: the IPTC „digital source type“ in XMP, a C2PA manifest
 * (Content Credentials, as ChatGPT, Gemini, Firefly, Photoshop and many cameras write them), the
 * generation data Stable Diffusion and ComfyUI keep in PNGs, the tool in EXIF, and file names AI
 * tools give their downloads. Nothing leaves the server; the file is read, not changed.
 *
 * A trace is `strong` when the file says so itself (C2PA, IPTC, generation data) and `weak` when only
 * a name or a tool hints at it. Many AI images carry no trace at all – after a screenshot, an export
 * or an upload elsewhere the data is gone. No trace is no proof.
 *
 * @package AI_Act_Labels
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads traces of AI from media files.
 */
class AI_Act_Labels_Detect {

	/** Bound both individual expansions and all retained PNG text, independent of compressed size. */
	const PNG_TEXT_CHUNK_LIMIT = 262144;
	const PNG_TEXT_TOTAL_LIMIT = 1048576;

	/** IPTC Digital Source Type (cv.iptc.org/newscodes/digitalsourcetype) → what it means here. */
	const SOURCE_TYPES = array(
		'trainedAlgorithmicMedia'              => 'generated',
		'compositeWithTrainedAlgorithmicMedia' => 'modified',
		'algorithmicMedia'                     => 'generated',
		'compositeSynthetic'                   => 'modified',
		'algorithmicallyEnhanced'              => 'enhanced',
		'digitalCapture'                       => 'human',
		'computationalCapture'                 => 'human',
		'negativeFilm'                         => 'human',
		'positiveFilm'                         => 'human',
		'print'                                => 'human',
		'minorHumanEdits'                      => 'human',
		'humanEdits'                           => 'human',
		'compositeCapture'                     => 'human',
		'digitalCreation'                      => 'human',
		'digitalArt'                           => 'human',
		'screenCapture'                        => 'human',
		'virtualRecording'                     => 'human',
		'dataDrivenMedia'                      => 'human',
		'composite'                            => 'human',
	);

	/** Names of AI tools as they show up in metadata and C2PA claims. */
	const TOOLS = array(
		'Adobe Firefly'      => '/firefly/i',
		'Adobe Photoshop'    => '/generative (fill|expand)/i',
		'DALL·E'             => '/dall[\x{00b7}._\- ]?e/iu',
		'ChatGPT'            => '/chatgpt|gpt-image|openai/i',
		'Midjourney'         => '/midjourney/i',
		'Stable Diffusion'   => '/stable.?diffusion|stability\.ai|sdxl/i',
		'ComfyUI'            => '/comfyui/i',
		'Google Gemini'      => '/gemini|imagen|google ai|nano.?banana/i',
		'Microsoft Designer' => '/bing image creator|microsoft designer|copilot designer/i',
		'Meta AI'            => '/meta ai|imagine with meta/i',
		'Leonardo.Ai'        => '/leonardo\.?ai/i',
		'Ideogram'           => '/ideogram/i',
		'FLUX'               => '/black forest labs|\bflux[ ._-]?(1|pro|dev|schnell|kontext)/i',
		'Runway'             => '/runwayml|runway gen/i',
		'Sora'               => '/\bsora\b/i',
		'Veo'                => '/\bveo ?[0-9]/i',
		'Kling'              => '/\bkling\b/i',
		'NovelAI'            => '/novelai/i',
		'InvokeAI'           => '/invokeai/i',
		'Canva AI'           => '/canva (magic|ai|dream)/i',
		'ElevenLabs'         => '/elevenlabs/i',
		'Suno'               => '/\bsuno\b/i',
		'Udio'               => '/\budio\b/i',
	);

	/** File names AI tools give their downloads. */
	const NAMES = array(
		'Google Gemini' => '/gemini_generated_image/i',
		'ChatGPT'       => '/^chatgpt[ _-]image/i',
		'DALL·E'        => '/^dall[\x{00b7}._\- ]?e[ _-]/iu',
		'Midjourney'    => '/^(mj|midjourney)[_-]|_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(_[0-3])?\.(png|webp)$/i',
		'Adobe Firefly' => '/^firefly[ _-]/i',
		'Leonardo.Ai'   => '/^leonardo_/i',
		'Ideogram'      => '/^ideogram/i',
		'Sora'          => '/^sora[ _-]/i',
		''              => '/(^|[_\- ])(ai[_\- ]generated|ki[_\- ]generiert|ai[_\- ]image)([_\- .]|$)/i',
	);

	/**
	 * Looks at one attachment and keeps what it found in its meta.
	 *
	 * @param int $id Attachment ID.
	 * @return array The traces: `kind`, `strength`, `tool`, `human`, `items`, `at`.
	 */
	public static function attachment( $id ) {
		$id     = (int) $id;
		$path   = self::untouched( $id );
		$here   = $path && file_exists( $path );
		$result = $here ? self::file( $path, (string) get_post_mime_type( $id ) ) : self::empty_result();
		// Traces read before stay: an image optimiser may have stripped them from the file since.
		$before = get_post_meta( $id, AI_Act_Labels::META_SIGNALS, true );
		$kept   = array_filter(
			is_array( $before ) ? (array) ( $before['items'] ?? array() ) : array(),
			static fn( $item ) => 'strong' === ( $item['strength'] ?? '' )
		);
		if ( $kept ) {
			$result = self::summarize( array_merge( $result['items'], $kept ) );
		}
		// The file's name when the file is not on this server (uploads on a CDN), and the name it was
		// uploaded with: WordPress may have renamed the file.
		$names = array( $path && ! $here ? wp_basename( $path ) : '', (string) get_the_title( $id ) );
		foreach ( array_filter( $names ) as $name ) {
			$by_name = '' === $result['kind'] ? self::by_name( $name ) : null;
			if ( $by_name ) {
				$result['items'][] = $by_name;
				$result            = self::summarize( $result['items'] );
			}
		}
		update_post_meta( $id, AI_Act_Labels::META_SIGNALS, $result );
		AI_Act_Labels::refresh_open( $id );
		return $result;
	}

	/**
	 * The file as it was uploaded: the original before WordPress scaled it, and of each the copy an
	 * image optimiser kept before it stripped the metadata.
	 *
	 * @param int $id Attachment ID.
	 * @return string The first of them that exists, else the attached file's path.
	 */
	private static function untouched( $id ) {
		$attached = (string) get_attached_file( $id );
		$files    = array_unique( array_filter( array( (string) wp_get_original_image_path( $id ), $attached ) ) );
		foreach ( $files as $file ) {
			foreach ( array( self::backup( $file ), $file ) as $path ) {
				if ( $path && file_exists( $path ) ) {
					return $path;
				}
			}
		}
		return $attached;
	}

	/**
	 * Where ShortPixel keeps a file's original: in its backup folder, at the path below the WordPress root.
	 *
	 * @param string $file File path.
	 * @return string Empty without ShortPixel.
	 */
	private static function backup( $file ) {
		$file = wp_normalize_path( $file );
		$root = wp_normalize_path( ABSPATH );
		if ( ! defined( 'SHORTPIXEL_BACKUP_FOLDER' ) || ! str_starts_with( $file, $root ) ) {
			return '';
		}
		return wp_normalize_path( SHORTPIXEL_BACKUP_FOLDER ) . '/' . substr( $file, strlen( $root ) );
	}

	/**
	 * Looks at a file.
	 *
	 * @param string $path File path.
	 * @param string $mime MIME type.
	 * @return array
	 */
	public static function file( $path, $mime = '' ) {
		$bytes = self::read( $path );
		$items = array();

		$xmp = self::xmp( $bytes );
		if ( $xmp ) {
			$items = array_merge( $items, self::from_xmp( $xmp ) );
		}
		$items = array_merge( $items, self::from_c2pa( $bytes ) );
		if ( 'image/png' === $mime || str_starts_with( $bytes, "\x89PNG" ) ) {
			$items = array_merge( $items, self::from_png( $bytes ) );
		}
		if ( function_exists( 'exif_read_data' ) && in_array( $mime, array( 'image/jpeg', 'image/tiff', 'image/webp' ), true ) ) {
			$items = array_merge( $items, self::from_exif( $path ) );
		}
		if ( str_starts_with( $mime, 'audio/' ) || str_starts_with( $mime, 'video/' ) ) {
			$items = array_merge( $items, self::from_text( $bytes ) );
		}
		$by_name = self::by_name( wp_basename( $path ) );
		if ( $by_name ) {
			$items[] = $by_name;
		}
		return self::summarize( $items );
	}

	/**
	 * An empty result.
	 *
	 * @return array
	 */
	public static function empty_result() {
		return array(
			'kind'     => '',
			'strength' => '',
			'tool'     => '',
			'human'    => false,
			'items'    => array(),
			'at'       => time(),
		);
	}

	/**
	 * One result from the single traces: the strongest says what it is.
	 *
	 * @param array $items Traces.
	 * @return array
	 */
	public static function summarize( array $items ) {
		$out   = self::empty_result();
		$rank  = array(
			''       => 0,
			'weak'   => 1,
			'strong' => 2,
		);
		$items = array_values( array_unique( $items, SORT_REGULAR ) );
		foreach ( $items as $item ) {
			$kind = $item['kind'] ?? '';
			if ( 'human' === $kind ) {
				$out['human'] = true;
				continue;
			}
			if ( ! in_array( $kind, AI_Act_Labels::KINDS, true ) ) {
				continue;
			}
			$strength = $item['strength'] ?? 'weak';
			// Changed beats made when both are strong: a composite still contains a real photo.
			if ( $rank[ $strength ] > $rank[ $out['strength'] ] || ( $rank[ $strength ] === $rank[ $out['strength'] ] && 'generated' === $out['kind'] && 'modified' === $kind && 'strong' === $strength ) ) {
				$out['kind']     = $kind;
				$out['strength'] = $strength;
			}
		}
		foreach ( $items as $item ) {
			if ( '' === $out['tool'] && ! empty( $item['tool'] ) ) {
				$out['tool'] = $item['tool'];
			}
		}
		$out['items'] = $items;
		return $out;
	}

	/**
	 * The start and the end of a file: XMP, C2PA and PNG text sit there.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private static function read( $path ) {
		$size = (int) filesize( $path );
		if ( $size < 1 ) {
			return '';
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions -- reading a local upload in parts.
		$fh = fopen( $path, 'rb' );
		if ( ! $fh ) {
			return '';
		}
		$head  = 3 * MB_IN_BYTES;
		$bytes = (string) fread( $fh, min( $size, $head ) );
		if ( $size > $head ) {
			fseek( $fh, max( $head, $size - MB_IN_BYTES ) );
			$bytes .= (string) fread( $fh, MB_IN_BYTES );
		}
		fclose( $fh );
		// phpcs:enable
		return $bytes;
	}

	/**
	 * The XMP packet, if there is one.
	 *
	 * @param string $bytes File bytes.
	 * @return string
	 */
	private static function xmp( $bytes ) {
		$start = strpos( $bytes, '<x:xmpmeta' );
		if ( false === $start ) {
			$start = strpos( $bytes, '<rdf:RDF' );
		}
		if ( false === $start ) {
			return '';
		}
		$end = strpos( $bytes, '</x:xmpmeta>', $start );
		if ( false === $end ) {
			$end = strpos( $bytes, '</rdf:RDF>', $start );
		}
		return false === $end ? substr( $bytes, $start, 65536 ) : substr( $bytes, $start, $end - $start + 12 );
	}

	/**
	 * Traces in XMP: the IPTC digital source type and the tool that made the file.
	 *
	 * @param string $xmp XMP.
	 * @return array
	 */
	private static function from_xmp( $xmp ) {
		$items = array();
		if ( preg_match_all( '#digitalsourcetype/([A-Za-z]+)#', $xmp, $m ) ) {
			foreach ( array_unique( $m[1] ) as $code ) {
				$meaning = self::SOURCE_TYPES[ $code ] ?? '';
				if ( '' === $meaning ) {
					continue;
				}
				$items[] = array(
					'source'   => 'iptc',
					'code'     => $code,
					'kind'     => 'enhanced' === $meaning ? '' : $meaning,
					'strength' => in_array( $meaning, AI_Act_Labels::KINDS, true ) ? 'strong' : '',
					'label'    => self::source_label( $code, $meaning ),
				);
			}
		}
		foreach ( array( 'CreatorTool', 'Software', 'Credit', 'creator', 'Description', 'description' ) as $field ) {
			if ( preg_match( '#(?:xmp|tiff|photoshop|dc|exif):' . $field . '(?:="([^"]+)"|>(.*?)</)#s', $xmp, $m ) ) {
				$value = wp_strip_all_tags( $m[1] ? $m[1] : $m[2] );
				$tool  = self::tool( $value );
				if ( $tool ) {
					$items[] = array(
						'source'   => 'xmp',
						'kind'     => 'generated',
						'strength' => 'weak',
						'tool'     => $tool,
						/* translators: %s: tool name */
						'label'    => sprintf( __( 'XMP names %s', 'ai-act-image-labels' ), $tool ),
					);
				}
			}
		}
		return $items;
	}

	/**
	 * What an IPTC code says, in words.
	 *
	 * @param string $code    The code.
	 * @param string $meaning generated, modified, enhanced, human.
	 * @return string
	 */
	private static function source_label( $code, $meaning ) {
		switch ( $meaning ) {
			case 'generated':
				return __( 'IPTC: created with AI', 'ai-act-image-labels' );
			case 'modified':
				return __( 'IPTC: photo with AI parts', 'ai-act-image-labels' );
			case 'enhanced':
				return __( 'IPTC: enhanced with AI (minor)', 'ai-act-image-labels' );
			default:
				/* translators: %s: IPTC code like digitalCapture */
				return sprintf( __( 'IPTC: no AI (%s)', 'ai-act-image-labels' ), $code );
		}
	}

	/**
	 * Traces of a C2PA manifest (JUMBF box labelled „c2pa“): what it says about AI, who made it.
	 *
	 * @param string $bytes File bytes.
	 * @return array
	 */
	private static function from_c2pa( $bytes ) {
		if ( false === strpos( $bytes, 'jumb' ) || false === stripos( $bytes, 'c2pa' ) ) {
			return array();
		}
		$items = array();
		$found = false;
		foreach ( array( 'compositeWithTrainedAlgorithmicMedia', 'trainedAlgorithmicMedia', 'compositeSynthetic', 'algorithmicMedia' ) as $code ) {
			if ( false !== strpos( $bytes, 'digitalsourcetype/' . $code ) ) {
				$meaning = self::SOURCE_TYPES[ $code ];
				$items[] = array(
					'source'   => 'c2pa',
					'code'     => $code,
					'kind'     => $meaning,
					'strength' => 'strong',
					'label'    => 'modified' === $meaning ? __( 'Content Credentials (C2PA): modified with AI', 'ai-act-image-labels' ) : __( 'Content Credentials (C2PA): created with AI', 'ai-act-image-labels' ),
				);
				$found   = true;
				break;
			}
		}
		$tool = '';
		if ( preg_match( '#claim_generator(?:_info)?.{1,40}?([A-Za-z][\w .\-/]{2,60})#s', $bytes, $m ) ) {
			$tool = self::tool( $m[1] );
		}
		if ( ! $tool ) {
			$tool = self::tool( substr( $bytes, (int) stripos( $bytes, 'c2pa' ), 200000 ) );
		}
		if ( $found && $tool ) {
			$items[0]['tool'] = $tool;
		}
		if ( ! $found ) {
			$capture = false !== strpos( $bytes, 'digitalsourcetype/digitalCapture' ) || false !== strpos( $bytes, 'digitalsourcetype/computationalCapture' );
			$items[] = array(
				'source'   => 'c2pa',
				'kind'     => $capture ? 'human' : ( $tool ? 'generated' : '' ),
				'strength' => $tool && ! $capture ? 'weak' : '',
				'tool'     => $tool,
				'label'    => $capture ? __( 'Content Credentials (C2PA): camera capture', 'ai-act-image-labels' ) : ( $tool
					/* translators: %s: tool name */
					? sprintf( __( 'Content Credentials (C2PA) from %s', 'ai-act-image-labels' ), $tool )
					: __( 'Content Credentials (C2PA) present', 'ai-act-image-labels' ) ),
			);
		}
		return $items;
	}

	/**
	 * The text chunks of a PNG: generation data of Stable Diffusion (A1111, Forge), ComfyUI, NovelAI, InvokeAI.
	 *
	 * @param string $bytes File bytes.
	 * @return array
	 */
	private static function from_png( $bytes ) {
		$items = array();
		$pos   = 8;
		$len   = strlen( $bytes );
		$texts = array();
		$used  = 0;
		while ( $pos + 12 <= $len ) {
			$size = unpack( 'N', substr( $bytes, $pos, 4 ) )[1];
			$type = substr( $bytes, $pos + 4, 4 );
			if ( $size < 0 || $pos + 12 + $size > $len ) {
				break;
			}
			if ( in_array( $type, array( 'tEXt', 'iTXt', 'zTXt' ), true ) ) {
				$data = substr( $bytes, $pos + 8, $size );
				$end  = strpos( $data, "\0" );
				if ( false === $end || $end < 1 || $end > 79 ) {
					break;
				}
				$key   = substr( $data, 0, $end );
				$rest  = substr( $data, $end + 1 );
				$limit = min( self::PNG_TEXT_CHUNK_LIMIT, self::PNG_TEXT_TOTAL_LIMIT - $used - $end );
				if ( $limit < 1 ) {
					break;
				}
				if ( 'zTXt' === $type ) {
					$rest = (string) @gzuncompress( substr( $rest, 1 ), $limit + 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				} elseif ( 'iTXt' === $type ) {
					// Compression flag, method, language and translated keyword come before the text.
					$flag  = ord( $rest[0] ?? "\0" );
					$parts = explode( "\0", substr( $rest, 2 ), 3 );
					$rest  = $parts[2] ?? '';
					if ( 1 === $flag ) {
						$rest = (string) @gzuncompress( $rest, $limit + 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					}
				}
				if ( strlen( $rest ) > $limit || '' === $rest ) {
					// Stop rather than repeatedly expanding hostile chunks after an exhausted budget.
					break;
				}
				$used         += $end + strlen( $rest );
				$texts[ $key ] = $rest;
			}
			if ( 'IEND' === $type ) {
				break;
			}
			$pos += 12 + $size;
		}
		$generated = static function ( $tool, $label ) {
			return array(
				'source'   => 'png',
				'kind'     => 'generated',
				'strength' => 'strong',
				'tool'     => $tool,
				'label'    => $label,
			);
		};
		if ( isset( $texts['parameters'] ) && preg_match( '/Steps:\s*\d+.*Sampler:/s', $texts['parameters'] ) ) {
			$items[] = $generated( 'Stable Diffusion', __( 'Stable Diffusion generation data', 'ai-act-image-labels' ) );
		}
		if ( isset( $texts['prompt'] ) && ( isset( $texts['workflow'] ) || str_contains( $texts['prompt'], 'class_type' ) ) ) {
			$items[] = $generated( 'ComfyUI', __( 'ComfyUI generation data', 'ai-act-image-labels' ) );
		}
		if ( isset( $texts['invokeai_metadata'] ) || isset( $texts['sd-metadata'] ) || isset( $texts['Dream'] ) ) {
			$items[] = $generated( 'InvokeAI', __( 'InvokeAI generation data', 'ai-act-image-labels' ) );
		}
		if ( isset( $texts['Software'] ) && str_contains( $texts['Software'], 'NovelAI' ) ) {
			$items[] = $generated( 'NovelAI', __( 'NovelAI generation data', 'ai-act-image-labels' ) );
		}
		foreach ( array( 'Software', 'Description', 'Comment', 'Author', 'Source' ) as $key ) {
			if ( isset( $texts[ $key ] ) && ! $items ) {
				$tool = self::tool( $texts[ $key ] );
				if ( $tool ) {
					$items[] = array(
						'source'   => 'png',
						'kind'     => 'generated',
						'strength' => 'weak',
						'tool'     => $tool,
						/* translators: %s: tool name */
						'label'    => sprintf( __( 'PNG text names %s', 'ai-act-image-labels' ), $tool ),
					);
				}
			}
		}
		if ( isset( $texts['XML:com.adobe.xmp'] ) ) {
			$items = array_merge( $items, self::from_xmp( $texts['XML:com.adobe.xmp'] ) );
		}
		return $items;
	}

	/**
	 * EXIF: the software and the comment (Stable Diffusion writes its parameters into JPEGs' UserComment).
	 *
	 * @param string $path File path.
	 * @return array
	 */
	private static function from_exif( $path ) {
		$exif = @exif_read_data( $path, null, false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_array( $exif ) ) {
			return array();
		}
		$items   = array();
		$comment = (string) ( $exif['UserComment'] ?? $exif['COMMENT'][0] ?? '' );
		if ( preg_match( '/Steps:\s*\d+.*Sampler:/s', $comment ) ) {
			$items[] = array(
				'source'   => 'exif',
				'kind'     => 'generated',
				'strength' => 'strong',
				'tool'     => 'Stable Diffusion',
				'label'    => __( 'Stable Diffusion generation data', 'ai-act-image-labels' ),
			);
		}
		foreach ( array( 'Software', 'ImageDescription', 'Artist', 'Make', 'Model' ) as $key ) {
			$value = is_string( $exif[ $key ] ?? null ) ? $exif[ $key ] : '';
			$tool  = $value ? self::tool( $value ) : '';
			if ( $tool && ! $items ) {
				$items[] = array(
					'source'   => 'exif',
					'kind'     => 'generated',
					'strength' => 'weak',
					'tool'     => $tool,
					/* translators: %s: tool name */
					'label'    => sprintf( __( 'EXIF names %s', 'ai-act-image-labels' ), $tool ),
				);
			}
		}
		return $items;
	}

	/**
	 * Names of AI tools in the tags of audio and video files (ID3, MP4 boxes).
	 *
	 * @param string $bytes File bytes.
	 * @return array
	 */
	private static function from_text( $bytes ) {
		foreach ( array( 'ElevenLabs', 'Suno', 'Udio', 'Sora', 'Runway', 'Veo', 'Kling' ) as $tool ) {
			if ( preg_match( self::TOOLS[ $tool ], substr( $bytes, 0, 262144 ) ) ) {
				return array(
					array(
						'source'   => 'tags',
						'kind'     => 'generated',
						'strength' => 'weak',
						'tool'     => $tool,
						/* translators: %s: tool name */
						'label'    => sprintf( __( 'Tags name %s', 'ai-act-image-labels' ), $tool ),
					),
				);
			}
		}
		return array();
	}

	/**
	 * A name AI tools give their files.
	 *
	 * @param string $name File name or title.
	 * @return array|null
	 */
	public static function by_name( $name ) {
		foreach ( self::NAMES as $tool => $pattern ) {
			if ( preg_match( $pattern, $name ) ) {
				return array(
					'source'   => 'name',
					'kind'     => 'generated',
					'strength' => 'weak',
					'tool'     => $tool,
					'label'    => $tool
						/* translators: %s: tool name */
						? sprintf( __( 'File name as %s gives it', 'ai-act-image-labels' ), $tool )
						: __( 'File name points to AI', 'ai-act-image-labels' ),
				);
			}
		}
		return null;
	}

	/**
	 * The AI tool a text names.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function tool( $text ) {
		foreach ( self::TOOLS as $tool => $pattern ) {
			if ( preg_match( $pattern, (string) $text ) ) {
				return $tool;
			}
		}
		return '';
	}
}
