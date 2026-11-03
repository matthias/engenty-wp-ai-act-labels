<?php
/**
 * The label on the site: the EU icon in a corner of every labelled image and video, a line above
 * labelled audio, the notice behind captions that are shown anyway – never in the alt text.
 * It comes by itself wherever WordPress prints media through its own functions (featured images,
 * the editor's blocks, galleries, covers, [caption], [video], [audio]); a theme that prints images
 * itself calls ai_act_labels_wrap(). Images in HTML blocks and page builders are found by their file.
 *
 * @package AI_Act_Labels
 */

defined( 'ABSPATH' ) || exit;

/**
 * Markup and styles on the site.
 */
class AI_Act_Labels_Front {

	const INDEX = 'ai_act_labels_index';
	const TONE  = '_ai_act_tone';

	/** Hooks into WordPress' media output. */
	public static function init() {
		add_filter( 'wp_get_attachment_image', array( __CLASS__, 'attachment_image' ), 20, 5 );
		add_filter( 'wp_content_img_tag', array( __CLASS__, 'content_img' ), 20, 3 );
		add_filter( 'render_block_core/image', array( __CLASS__, 'image_block' ), 20, 2 );
		add_filter( 'render_block_core/cover', array( __CLASS__, 'cover_block' ), 20, 2 );
		add_filter( 'render_block_core/video', array( __CLASS__, 'video_block' ), 20, 2 );
		add_filter( 'render_block_core/audio', array( __CLASS__, 'audio_block' ), 20, 2 );
		add_filter( 'shortcode_atts_caption', array( __CLASS__, 'caption_shortcode' ), 20, 3 );
		add_filter( 'wp_video_shortcode', array( __CLASS__, 'video_shortcode' ), 20, 2 );
		add_filter( 'wp_audio_shortcode', array( __CLASS__, 'audio_shortcode' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ) );
		add_action( 'ai_act_labels_changed', array( __CLASS__, 'forget' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'meta_changed' ), 10, 3 );
		}
	}

	/** The index is built again when it is needed next. */
	public static function forget() {
		delete_option( self::INDEX );
	}

	/**
	 * A label written past this plugin (the theme class of the first version, an import): the index is built again.
	 *
	 * @param int|int[] $meta_id   Meta ID.
	 * @param int       $object_id Post ID.
	 * @param string    $key       Meta key.
	 */
	public static function meta_changed( $meta_id, $object_id, $key ) {
		unset( $meta_id, $object_id );
		if ( in_array( $key, array( AI_Act_Labels::META, '_wp_attached_file', '_wp_attachment_metadata' ), true ) ) {
			self::forget();
		}
	}

	/**
	 * Labelled media: ID => kind and exact normalized URLs. Kept as one option, so pages need no query per image.
	 *
	 * @return array
	 */
	public static function index() {
		$index = get_option( self::INDEX );
		if ( ! is_array( $index ) || 2 !== ( $index['version'] ?? null ) ) {
			return self::reindex();
		}
		return $index['items'];
	}

	/**
	 * Builds the index again.
	 *
	 * @return array
	 */
	public static function reindex() {
		$index = array();
		foreach ( array_merge( AI_Act_Labels::query_ids( 'generated', -1 ), AI_Act_Labels::query_ids( 'modified', -1 ) ) as $id ) {
			$file = (string) get_post_meta( $id, '_wp_attached_file', true );
			$kind = AI_Act_Labels::kind( $id );
			if ( $kind ) {
				$index[ $id ] = array(
					'k' => $kind,
					'u' => self::media_urls( $id, $file ),
				);
			}
		}
		update_option(
			self::INDEX,
			array(
				'version' => 2,
				'items'   => $index,
			),
			true
		);
		return $index;
	}

	/**
	 * Whether labels go on this request's output, for one place (content, featured, video, audio).
	 *
	 * @param string $where The place.
	 * @return bool
	 */
	private static function on( $where = 'content' ) {
		if ( ( is_admin() && ! wp_doing_ajax() ) || is_feed() ) {
			return false;
		}
		/**
		 * Whether the label is shown on this request.
		 *
		 * @param bool   $show  Shown.
		 * @param string $where content, featured, video, audio.
		 */
		return (bool) apply_filters( 'ai_act_labels_show', ! empty( AI_Act_Labels::settings()['where'][ $where ] ), $where );
	}

	/**
	 * The kind of a labelled ID from the index.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	private static function kind_of( $id ) {
		return (string) ( self::index()[ (int) $id ]['k'] ?? '' );
	}

	/**
	 * Exact media URLs, including only resized/original files recorded by WordPress.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $file Upload-relative path.
	 * @return string[]
	 */
	private static function media_urls( $id, $file ) {
		$uploads = wp_get_upload_dir();
		$roots   = array_filter( array( $file ? rtrim( $uploads['baseurl'], '/' ) . '/' . ltrim( $file, '/' ) : '', (string) wp_get_attachment_url( $id ) ) );
		$meta    = wp_get_attachment_metadata( $id );
		$names   = array();
		if ( is_array( $meta ) ) {
			$names[] = $meta['original_image'] ?? '';
			foreach ( $meta['sizes'] ?? array() as $size ) {
				$names[] = $size['file'] ?? '';
			}
		}
		$urls = array();
		foreach ( array_unique( $roots ) as $root ) {
			$key = self::url_identity( $root );
			if ( ! $key ) {
				continue;
			}
			$urls[] = $key;
			$root   = preg_replace( '/[?#].*$/', '', $root );
			$dir    = substr( $root, 0, strrpos( $root, '/' ) + 1 );
			foreach ( array_filter( $names ) as $name ) {
				if ( wp_basename( $name ) === $name ) {
					$urls[] = self::url_identity( $dir . $name );
				}
			}
		}
		return array_values( array_unique( array_filter( $urls ) ) );
	}

	/**
	 * Normalize encoding and query strings without discarding the origin, directory or extension.
	 * CDN URLs are supported when the site's attachment URL filter supplies their exact origin/path.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function url_identity( $url ) {
		$url = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
		if ( str_starts_with( $url, '//' ) ) {
			$url = wp_parse_url( home_url(), PHP_URL_SCHEME ) . ':' . $url;
		} elseif ( str_starts_with( $url, '/' ) ) {
			$url = home_url( $url );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		$path = rawurldecode( $parts['path'] ?? '' );
		if ( '' === $path || str_contains( $path, '\\' ) || str_contains( $path, "\0" ) || preg_match( '#(?:^|/)\.\.?(?:/|$)#', $path ) ) {
			return '';
		}
		$port = $parts['port'] ?? ( 'https' === $parts['scheme'] ? 443 : 80 );
		return strtolower( $parts['scheme'] . '://' . $parts['host'] ) . ':' . $port . $path;
	}

	/**
	 * The labelled attachment an exact supported URL points to.
	 *
	 * @param string $url URL.
	 * @return int
	 */
	private static function id_by_src( $url ) {
		$key = self::url_identity( $url );
		if ( ! $key ) {
			return 0;
		}
		$found = 0;
		foreach ( self::index() as $id => $entry ) {
			if ( in_array( $key, $entry['u'], true ) ) {
				// Duplicate identities are ambiguous: no attachment should be labelled by guesswork.
				if ( $found ) {
					return 0;
				}
				$found = (int) $id;
			}
		}
		return $found;
	}

	/**
	 * Images from wp_get_attachment_image(): featured images, logos, themes that use it.
	 *
	 * @param string       $html          Markup.
	 * @param int          $attachment_id Attachment ID.
	 * @param string|int[] $size          Size.
	 * @param bool         $icon          Icon.
	 * @param array        $attr          Attributes.
	 * @return string
	 */
	public static function attachment_image( $html, $attachment_id, $size = '', $icon = false, $attr = array() ) {
		unset( $size, $icon, $attr );
		if ( ! self::on( 'featured' ) ) {
			return $html;
		}
		return self::wrap( $html, (int) $attachment_id );
	}

	/**
	 * Images in content: blocks, the classic editor, HTML blocks, page builders.
	 *
	 * @param string $img           The img tag.
	 * @param string $context       Where.
	 * @param int    $attachment_id Attachment ID, 0 if WordPress did not find one.
	 * @return string
	 */
	public static function content_img( $img, $context, $attachment_id ) {
		unset( $context );
		if ( ! self::on( 'content' ) || str_contains( $img, 'data-ai-act=' ) || str_contains( $img, 'wp-block-cover__image-background' ) ) {
			return $img;
		}
		$id = (int) $attachment_id;
		if ( ! $id && preg_match( '/\ssrc=["\']([^"\']+)/', $img, $m ) ) {
			$id = self::id_by_src( $m[1] );
		}
		return $id ? self::wrap( $img, $id, str_contains( $img, 'data-ai-act-caption' ) ) : $img;
	}

	/**
	 * The image block: the notice behind its caption; the image remembers it, so the icon is not read twice.
	 *
	 * @param string $content Markup.
	 * @param array  $block   Block.
	 * @return string
	 */
	public static function image_block( $content, $block ) {
		$id = (int) ( $block['attrs']['id'] ?? 0 );
		if ( ! $id || ! self::on( 'content' ) || ! self::kind_of( $id ) || ! AI_Act_Labels::settings()['caption'] ) {
			return $content;
		}
		return self::caption_into( $content, $id );
	}

	/**
	 * The notice before `</figcaption>` of a figure, and the image marked as captioned.
	 *
	 * @param string $content Markup with a figcaption.
	 * @param int    $id      Attachment ID.
	 * @return string
	 */
	private static function caption_into( $content, $id ) {
		$end = strrpos( $content, '</figcaption>' );
		if ( false === $end ) {
			return $content;
		}
		$caption = trim( wp_strip_all_tags( substr( $content, 0, $end ) . '</figcaption>' ) );
		if ( '' === $caption ) {
			return $content;
		}
		$notice  = '<span class="ai-act-label__caption"> – ' . esc_html( AI_Act_Labels::notice( $id ) ) . '</span>';
		$content = substr_replace( $content, $notice, $end, 0 );
		return preg_replace( '/<img\b/', '<img data-ai-act-caption="1"', $content, 1 );
	}

	/**
	 * The [caption] shortcode of the classic editor: the notice behind the caption text.
	 *
	 * @param array $out   Attributes.
	 * @param array $pairs Defaults.
	 * @param array $atts  Given attributes.
	 * @return array
	 */
	public static function caption_shortcode( $out, $pairs, $atts ) {
		unset( $pairs, $atts );
		$id = (int) preg_replace( '/\D/', '', (string) ( $out['id'] ?? '' ) );
		if ( $id && self::on( 'content' ) && self::kind_of( $id ) && AI_Act_Labels::settings()['caption'] && '' !== trim( (string) $out['caption'] ) ) {
			$out['caption'] .= ' – ' . AI_Act_Labels::notice( $id );
		}
		return $out;
	}

	/**
	 * The cover block: the icon in its corner, above the overlay.
	 *
	 * @param string $content Markup.
	 * @param array  $block   Block.
	 * @return string
	 */
	public static function cover_block( $content, $block ) {
		$id = (int) ( $block['attrs']['id'] ?? 0 );
		if ( ! $id || ! self::on( 'video' === ( $block['attrs']['backgroundType'] ?? 'image' ) ? 'video' : 'content' ) || ! self::kind_of( $id ) ) {
			return $content;
		}
		return self::badge_inside( $content, $id, 'wp-block-cover' );
	}

	/**
	 * The video block: the icon in a top corner, where it does not cover the controls.
	 *
	 * @param string $content Markup.
	 * @param array  $block   Block.
	 * @return string
	 */
	public static function video_block( $content, $block ) {
		$id = (int) ( $block['attrs']['id'] ?? 0 );
		if ( ! $id || ! self::on( 'video' ) || ! self::kind_of( $id ) ) {
			return $content;
		}
		if ( AI_Act_Labels::settings()['caption'] && str_contains( $content, '</figcaption>' ) ) {
			$content = self::caption_into( $content, $id );
		}
		return self::badge_inside( $content, $id, 'wp-block-video', true );
	}

	/**
	 * The audio block: a line with the icon above the player.
	 *
	 * @param string $content Markup.
	 * @param array  $block   Block.
	 * @return string
	 */
	public static function audio_block( $content, $block ) {
		$id = (int) ( $block['attrs']['id'] ?? 0 );
		if ( ! $id || ! self::on( 'audio' ) || ! self::kind_of( $id ) || str_contains( $content, 'data-ai-act-id=' ) ) {
			return $content;
		}
		$pos = strpos( $content, '<audio' );
		return false === $pos ? $content : substr_replace( $content, self::line( $id ), $pos, 0 );
	}

	/**
	 * [video]: the icon over the player.
	 *
	 * @param string $output Markup.
	 * @param array  $atts   Attributes.
	 * @return string
	 */
	public static function video_shortcode( $output, $atts ) {
		if ( ! self::on( 'video' ) || str_contains( $output, 'data-ai-act-id=' ) ) {
			return $output;
		}
		$id = self::id_by_src( self::media_src( (array) $atts, array( 'src', 'mp4', 'webm', 'ogv', 'm4v', 'wmv', 'flv' ) ) );
		if ( ! $id ) {
			return $output;
		}
		return '<span class="ai-act-label ai-act-label--block ai-act-label--video" data-ai-act-id="' . $id . '">' . $output . self::badge( $id, false, true ) . '</span>';
	}

	/**
	 * [audio]: a line with the icon above the player.
	 *
	 * @param string $output Markup.
	 * @param array  $atts   Attributes.
	 * @return string
	 */
	public static function audio_shortcode( $output, $atts ) {
		if ( ! self::on( 'audio' ) || str_contains( $output, 'data-ai-act-id=' ) ) {
			return $output;
		}
		$id = self::id_by_src( self::media_src( (array) $atts, array( 'src', 'mp3', 'ogg', 'flac', 'm4a', 'wav' ) ) );
		return $id ? self::line( $id ) . $output : $output;
	}

	/**
	 * The first source of a [video] or [audio].
	 *
	 * @param array $atts Attributes.
	 * @param array $keys Keys with sources.
	 * @return string
	 */
	private static function media_src( array $atts, array $keys ) {
		foreach ( $keys as $key ) {
			if ( ! empty( $atts[ $key ] ) ) {
				return (string) $atts[ $key ];
			}
		}
		return '';
	}

	/**
	 * The icon as the first child of a block's wrapper, which is positioned.
	 *
	 * @param string $content Markup.
	 * @param int    $id      Attachment ID.
	 * @param string $wrapper The wrapper's class.
	 * @param bool   $top     Keep the icon in a top corner.
	 * @return string
	 */
	private static function badge_inside( $content, $id, $wrapper, $top = false ) {
		if ( str_contains( $content, 'data-ai-act-id=' ) || ! preg_match( '/<(div|figure)\b[^>]*\bclass="[^"]*\b' . preg_quote( $wrapper, '/' ) . '\b[^"]*"[^>]*>/', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			return $content;
		}
		$open = $m[0][0];
		$tag  = preg_replace( '/\bclass="/', 'data-ai-act-id="' . $id . '" class="ai-act-host ', $open, 1 );
		return substr_replace( $content, $tag . self::badge( $id, false, $top ), $m[0][1], strlen( $open ) );
	}

	/**
	 * Puts the label around image or video markup.
	 *
	 * @param string $html        Markup.
	 * @param int    $id          Attachment ID.
	 * @param bool   $has_caption A visible caption carries the notice already.
	 * @return string
	 */
	public static function wrap( $html, $id, $has_caption = false ) {
		$kind = self::kind_of( $id );
		if ( '' === $html || ! $kind || str_contains( $html, 'data-ai-act-id=' ) ) {
			return $html;
		}
		$small = preg_match( '/\swidth=["\']?(\d+)/', $html, $w ) && (int) $w[1] < 400;
		$html  = preg_replace( '/<(img|video|picture)\b/', '<$1 data-ai-act="' . $kind . '"', $html, 1 );
		$pos   = AI_Act_Labels::settings()['position'];
		$out   = '<span class="ai-act-label ai-act-label--' . esc_attr( $pos ) . '" data-ai-act-id="' . (int) $id . '">' . $html . self::badge( $id, $has_caption, false, $small ) . '</span>';
		/**
		 * The labelled markup of an image.
		 *
		 * @param string $out  Labelled markup.
		 * @param string $html Markup without the label.
		 * @param int    $id   Attachment ID.
		 * @param string $kind generated or modified.
		 */
		return (string) apply_filters( 'ai_act_labels_wrap', $out, $html, $id, $kind );
	}

	/**
	 * Adds the notice to a caption a theme prints: „Foto: Anna – Dieses Bild wurde mit KI erzeugt.“
	 *
	 * @param string $text Caption.
	 * @param int    $id   Attachment ID.
	 * @return string
	 */
	public static function append_notice( $text, $id ) {
		if ( '' === trim( $text ) || ! self::kind_of( $id ) ) {
			return $text;
		}
		return rtrim( $text, " \t\n.,;" ) . ' – ' . AI_Act_Labels::notice( $id );
	}

	/**
	 * The icon in the corner, with the notice as its name and, on hover, as a second layer.
	 *
	 * @param int    $id          Attachment ID.
	 * @param bool   $decorative  The notice is visible next to it already.
	 * @param bool   $top         Keep it in a top corner (video controls sit at the bottom).
	 * @param bool   $small       The image is small: the round icon instead of the word icon.
	 * @param string $kind        generated or modified, for a preview of a file without a label.
	 * @return string
	 */
	public static function badge( $id, $decorative = false, $top = false, $small = false, $kind = '' ) {
		$s    = AI_Act_Labels::settings();
		$own  = self::kind_of( $id );
		$own  = $own ? $own : AI_Act_Labels::kind( $id );
		$kind = $kind ? $kind : $own;
		if ( ! in_array( $kind, AI_Act_Labels::KINDS, true ) ) {
			$kind = 'generated';
		}
		$label  = AI_Act_Labels::label_text( $kind );
		$notice = $own === $kind ? AI_Act_Labels::notice( $id ) : AI_Act_Labels::default_notice( $kind, $id ? AI_Act_Labels::media_type( $id ) : 'image' );
		$pos    = $s['position'];
		if ( $top ) {
			$pos = str_replace( 'bottom', 'top', $pos );
		}
		$color = $s['color'];
		if ( 'auto' === $color ) {
			// By image: dark or light by how light the corner is.
			$color = 'white' === self::tone( $id, $pos ) ? 'light' : 'dark';
		}
		$paint = AI_Act_Labels_Icons::paint( 'custom' === $color ? $s['hex'] : $color, $s['alpha'] );
		$icon  = $s['icon'];
		if ( 'official' === $icon && $small ) {
			$icon = 'basic';
		}
		switch ( $icon ) {
			case 'basic':
				$inner = AI_Act_Labels_Icons::svg( 'basic', $paint );
				$ratio = 1;
				break;
			case 'basic-text':
				// The round icon; on hover it opens to the EU icon of its kind.
				$inner = AI_Act_Labels_Icons::svg( $kind, $paint ) . AI_Act_Labels_Icons::svg( 'basic', $paint );
				$ratio = AI_Act_Labels_Icons::ratio( $kind );
				break;
			case 'custom':
				$src   = $s['custom'] ? wp_get_attachment_image_url( $s['custom'], 'thumbnail' ) : '';
				$inner = $src ? '<img src="' . esc_url( $src ) . '" alt="" class="ai-act-icon ai-act-icon--custom" decoding="async">' : AI_Act_Labels_Icons::svg( 'basic', $paint );
				$ratio = 0;
				break;
			case 'sparkle':
				$inner = AI_Act_Labels_Icons::sparkle();
				$ratio = 1;
				break;
			default:
				$inner = AI_Act_Labels_Icons::svg( $kind, $paint );
				$ratio = AI_Act_Labels_Icons::ratio( $kind );
		}
		$a11y = $decorative ? ' aria-hidden="true"' : ' role="img" aria-label="' . esc_attr( $label . ': ' . $notice ) . '"';
		// No second layer where the notice shows already: in the caption, or as the icon that opens on hover.
		$layer = $s['layer'] && ! $decorative && 'basic-text' !== $icon ? '<span class="ai-act-label__layer" aria-hidden="true">' . esc_html( $notice ) . '</span>' : '';
		$style = '--ai-act-h:' . AI_Act_Labels::SIZES[ $s['size'] ] . 'px' . ( $ratio ? ';--ai-act-r:' . round( $ratio, 3 ) : '' );
		return sprintf(
			'<span class="ai-act-label__badge is-%1$s is-%2$s is-%3$s" style="%4$s"%5$s>%6$s%7$s</span>',
			esc_attr( $icon ),
			esc_attr( $color ),
			esc_attr( $pos ),
			esc_attr( $style ),
			$a11y,
			$inner,
			$layer
		);
	}

	/**
	 * A line with the icon and the notice, for audio.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	private static function line( $id ) {
		$kind  = self::kind_of( $id );
		$s     = AI_Act_Labels::settings();
		$paint = AI_Act_Labels_Icons::paint( 'custom' === $s['color'] ? $s['hex'] : ( 'light' === $s['color'] ? 'light' : 'dark' ) );
		return '<span class="ai-act-line" data-ai-act-id="' . (int) $id . '">' . AI_Act_Labels_Icons::svg( 'basic', $paint ) . '<span><b>' . esc_html( AI_Act_Labels::label_text( $kind ) ) . '</b> ' . esc_html( AI_Act_Labels::notice( $id ) ) . '</span></span>';
	}

	/**
	 * Black or white for the corner of an image: how light the image is there, measured once.
	 *
	 * @param int    $id  Attachment ID.
	 * @param string $pos Corner.
	 * @return string
	 */
	public static function tone( $id, $pos ) {
		$tones = get_post_meta( $id, self::TONE, true );
		$tones = is_array( $tones ) ? $tones : array();
		if ( isset( $tones[ $pos ] ) ) {
			return $tones[ $pos ];
		}
		$tone = 'black';
		$file = 'image' === AI_Act_Labels::media_type( $id ) ? image_get_intermediate_size( $id, 'thumbnail' ) : false;
		$path = $file && ! empty( $file['path'] ) ? path_join( wp_get_upload_dir()['basedir'], $file['path'] ) : get_attached_file( $id );
		if ( function_exists( 'imagecreatefromstring' ) && $path && file_exists( $path ) && filesize( $path ) < 8 * MB_IN_BYTES ) {
			$img = @imagecreatefromstring( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			if ( $img ) {
				$w   = imagesx( $img );
				$h   = imagesy( $img );
				$x0  = str_contains( $pos, 'right' ) ? (int) ( $w * 0.6 ) : 0;
				$y0  = str_contains( $pos, 'bottom' ) ? (int) ( $h * 0.75 ) : 0;
				$sum = 0;
				$n   = 0;
				for ( $x = $x0; $x < $x0 + (int) ( $w * 0.4 ); $x += max( 1, (int) ( $w / 60 ) ) ) {
					for ( $y = $y0; $y < $y0 + (int) ( $h * 0.25 ); $y += max( 1, (int) ( $h / 60 ) ) ) {
						$rgb  = imagecolorsforindex( $img, imagecolorat( $img, min( $x, $w - 1 ), min( $y, $h - 1 ) ) );
						$sum += 0.2126 * $rgb['red'] + 0.7152 * $rgb['green'] + 0.0722 * $rgb['blue'];
						++$n;
					}
				}
				// A dark corner gets the white icon, a light one the black icon.
				$tone = $n && $sum / $n < 110 ? 'white' : 'black';
			}
		}
		$tones[ $pos ] = $tone;
		update_post_meta( $id, self::TONE, $tones );
		return $tone;
	}

	/** The styles, when the site has labelled media. */
	public static function styles() {
		if ( ! self::index() ) {
			return;
		}
		wp_register_style( 'ai-act-labels', false, array(), AI_ACT_LABELS_VERSION );
		wp_enqueue_style( 'ai-act-labels' );
		wp_add_inline_style( 'ai-act-labels', self::css() );
	}

	/**
	 * The styles: low specificity (:where), so a theme can change them.
	 *
	 * @return string
	 */
	public static function css() {
		return ':where(.ai-act-label){position:relative;display:inline-block;max-width:100%;line-height:0;vertical-align:top}'
			. ':where(.ai-act-label)>img,:where(.ai-act-label)>picture{max-width:100%}'
			. ':where(.ai-act-label:has(>img[style*="width:100%"],>img[style*="width: 100%"],>img.w-full)){display:block;width:100%}'
			. ':where(.ai-act-label:has(>img[style*="height:100%"],>img[style*="height: 100%"])){height:100%}'
			. ':where(.ai-act-label--block){display:block}'
			. ':where(.wp-block-image .ai-act-label){vertical-align:bottom}'
			. ':where(.wp-block-post-featured-image) .ai-act-label{display:block;height:100%}'
			. ':where(.is-image-fill-element>.wp-block-media-text__media)>.ai-act-label{position:static;display:block;height:100%}'
			. ':where(.ai-act-host){position:relative}'
			. ':where(.ai-act-label__badge){position:absolute;z-index:3;display:flex;align-items:center;gap:.35em;height:var(--ai-act-h);width:min(calc(var(--ai-act-h) * var(--ai-act-r, 1)),45%);line-height:1;pointer-events:auto;font:600 calc(var(--ai-act-h) * .42)/1 system-ui,-apple-system,"Segoe UI",sans-serif}'
			. ':where(.ai-act-label__badge.is-custom){width:auto;max-width:calc(100% - 1em)}'
			. ':where(.ai-act-label__badge.is-bottom-right){right:.5em;bottom:.5em}'
			. ':where(.ai-act-label__badge.is-bottom-left){left:.5em;bottom:.5em}'
			. ':where(.ai-act-label__badge.is-top-right){right:.5em;top:.5em}'
			. ':where(.ai-act-label__badge.is-top-left){left:.5em;top:.5em}'
			. ':where(.ai-act-label__badge) .ai-act-icon{display:block;width:100%;height:auto;max-height:100%;flex:none}'
			. ':where(.ai-act-label__badge.is-custom) .ai-act-icon{width:var(--ai-act-h);height:var(--ai-act-h)}'
			. ':where(.ai-act-label__badge.is-basic-text){width:var(--ai-act-h);overflow:hidden;border-radius:999px;transition:width .2s ease}'
			. ':where(.ai-act-label__badge.is-basic-text) .ai-act-icon{width:calc(var(--ai-act-h) * var(--ai-act-r, 1));max-width:none;height:var(--ai-act-h);opacity:0;transition:opacity .2s ease}'
			. ':where(.ai-act-label__badge.is-basic-text) .ai-act-icon--basic{position:absolute;left:0;top:0;width:var(--ai-act-h);opacity:1}'
			. ':where(.ai-act-label__badge.is-basic-text):hover{width:calc(var(--ai-act-h) * var(--ai-act-r, 1))}'
			. ':where(.ai-act-label__badge.is-basic-text):hover .ai-act-icon{opacity:1}'
			. ':where(.ai-act-label__badge.is-basic-text):hover .ai-act-icon--basic{opacity:0}'
			. '@media (prefers-reduced-motion:reduce){:where(.ai-act-label__badge.is-basic-text),:where(.ai-act-label__badge.is-basic-text) .ai-act-icon{transition:none}}'
			. ':where(.ai-act-label__badge.is-sparkle) .ai-act-icon{width:60%;margin:auto}'
			. ':where(.ai-act-label__layer){position:absolute;bottom:calc(100% + 6px);right:0;display:none;width:max-content;max-width:min(260px,80vw);padding:.55em .75em;border-radius:8px;background:#1d1d1b;color:#fff;font:400 13px/1.4 system-ui,-apple-system,"Segoe UI",sans-serif;box-shadow:0 4px 16px rgba(0,0,0,.25);white-space:normal;text-align:left}'
			. ':where(.ai-act-label__badge.is-bottom-left,.ai-act-label__badge.is-top-left) .ai-act-label__layer{right:auto;left:0}'
			. ':where(.ai-act-label__badge.is-top-right,.ai-act-label__badge.is-top-left) .ai-act-label__layer{bottom:auto;top:calc(100% + 6px)}'
			. ':where(.ai-act-label__badge):hover .ai-act-label__layer{display:block}'
			. ':where(.ai-act-line){display:flex;align-items:center;gap:.5em;margin:0 0 .4em;font-size:.875em;line-height:1.35}'
			. ':where(.ai-act-line) .ai-act-icon{width:1.6em;height:1.6em;flex:none}'
			. '@media print{:where(.ai-act-label__layer){display:none!important}}';
	}
}
