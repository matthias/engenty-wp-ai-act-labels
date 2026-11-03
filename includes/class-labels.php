<?php
/**
 * The label of a media file and the plugin's settings. A media file is `generated` (made by AI),
 * `modified` (a real photo, video or recording changed with AI) or `none` (looked at: needs no label);
 * without a decision it has none of these. The label lives in post meta of the attachment, so it
 * travels with exports and stays when the theme changes.
 *
 * @package AI_Act_Labels
 */

defined( 'ABSPATH' ) || exit;

/**
 * Labels, notices, settings.
 */
class AI_Act_Labels {

	const OPTION = 'ai_act_labels';

	const META         = '_ai_act_label';
	const META_TOOL    = '_ai_act_tool';
	const META_NOTICE  = '_ai_act_notice';
	const META_DECIDED = '_ai_act_decided';
	const META_SIGNALS = '_ai_act_signals';
	const META_HINT    = '_ai_act_hint';
	const META_USAGE   = '_ai_act_usage';

	/**
	 * Markers kept beside the label, so lists and counts need one indexed lookup on big libraries:
	 * a suggestion without a decision, and used on the site without a decision.
	 */
	const OPEN_SUGGESTED = '_ai_act_open_s';
	const OPEN_USED      = '_ai_act_open_u';

	const KINDS = array( 'generated', 'modified' );

	/** What a decision can be. */
	const STATES = array( 'generated', 'modified', 'none' );

	const ICONS     = array( 'official', 'basic', 'basic-text', 'custom', 'sparkle' );
	const COLORS    = array( 'dark', 'light', 'auto', 'custom' );
	const ALPHAS    = array( 0, 25, 50, 75 );
	const SIZES     = array(
		's' => 18,
		'm' => 22,
		'l' => 28,
	);
	const POSITIONS = array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' );

	/**
	 * The settings as they start.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'icon'     => 'official',
			'color'    => 'dark',
			'hex'      => '#1d4ed8',
			'alpha'    => 0,
			'size'     => 'm',
			'position' => 'bottom-right',
			'custom'   => 0,
			'caption'  => true,
			'layer'    => true,
			'texts'    => array(
				'generated'       => '',
				'modified'        => '',
				'noticeGenerated' => '',
				'noticeModified'  => '',
			),
			'where'    => array(
				'content'  => true,
				'featured' => true,
				'video'    => true,
				'audio'    => true,
			),
			'detect'   => true,
			'auto'     => false,
		);
	}

	/**
	 * The settings, with defaults for what was never saved.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return self::sanitize_settings( is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Keeps what the settings allow.
	 *
	 * @param array $in Settings, complete or a part.
	 * @param array $base What is not in `$in` comes from here.
	 * @return array
	 */
	public static function sanitize_settings( array $in, ?array $base = null ) {
		$d   = $base ?? self::defaults();
		$out = array(
			'icon'     => self::one_of( $in['icon'] ?? $d['icon'], self::ICONS, 'official' ),
			'color'    => self::one_of( $in['color'] ?? $d['color'], self::COLORS, 'dark' ),
			'hex'      => sanitize_hex_color( (string) ( $in['hex'] ?? $d['hex'] ) ) ?? self::defaults()['hex'],
			'alpha'    => self::one_of( (int) ( $in['alpha'] ?? $d['alpha'] ), self::ALPHAS, 0 ),
			'size'     => self::one_of( $in['size'] ?? $d['size'], array_keys( self::SIZES ), 'm' ),
			'position' => self::one_of( $in['position'] ?? $d['position'], self::POSITIONS, 'bottom-right' ),
			'custom'   => absint( $in['custom'] ?? $d['custom'] ),
			'caption'  => (bool) ( $in['caption'] ?? $d['caption'] ),
			'layer'    => (bool) ( $in['layer'] ?? $d['layer'] ),
			'texts'    => array(),
			'where'    => array(),
			'detect'   => (bool) ( $in['detect'] ?? $d['detect'] ),
			'auto'     => (bool) ( $in['auto'] ?? $d['auto'] ),
		);
		foreach ( self::defaults()['texts'] as $key => $unused ) {
			$out['texts'][ $key ] = sanitize_text_field( (string) ( $in['texts'][ $key ] ?? $d['texts'][ $key ] ?? '' ) );
		}
		foreach ( self::defaults()['where'] as $key => $unused ) {
			$out['where'][ $key ] = (bool) ( $in['where'][ $key ] ?? $d['where'][ $key ] ?? true );
		}
		if ( 'custom' === $out['icon'] && ! $out['custom'] ) {
			$out['icon'] = 'official';
		}
		return $out;
	}

	/**
	 * Saves a part of the settings.
	 *
	 * @param array $patch What changes.
	 * @return array The settings.
	 */
	public static function save_settings( array $patch ) {
		$current = self::settings();
		if ( isset( $patch['texts'] ) && is_array( $patch['texts'] ) ) {
			$patch['texts'] = array_merge( $current['texts'], $patch['texts'] );
		}
		if ( isset( $patch['where'] ) && is_array( $patch['where'] ) ) {
			$patch['where'] = array_merge( $current['where'], $patch['where'] );
		}
		$next = self::sanitize_settings( $patch, $current );
		update_option( self::OPTION, $next );
		return $next;
	}

	/**
	 * One value of a list, or the fallback.
	 *
	 * @param mixed  $value    The value.
	 * @param array  $allowed  Allowed values.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	public static function one_of( $value, array $allowed, $fallback ) {
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * The decision on a media file: generated, modified, none – or '' when nobody decided yet.
	 * Without an own one, a label of the theme class of the first version counts as `generated`.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	public static function get( $id ) {
		$id = (int) $id;
		if ( $id <= 0 ) {
			return '';
		}
		$state = (string) get_post_meta( $id, self::META, true );
		return in_array( $state, self::STATES, true ) ? $state : '';
	}

	/**
	 * The label shown on the site: generated or modified, '' for none.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	public static function kind( $id ) {
		$state = self::get( $id );
		return in_array( $state, self::KINDS, true ) ? $state : '';
	}

	/**
	 * Decides on a media file.
	 *
	 * @param int    $id    Attachment ID.
	 * @param string $state generated, modified, none, or '' to undo the decision.
	 * @param string $via   manual, bulk, metadata, assistant.
	 * @param array  $extra `tool`, `notice` (both optional; given, they replace what is there).
	 * @return bool
	 */
	public static function set( $id, $state, $via = 'manual', array $extra = array() ) {
		$id = (int) $id;
		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			return false;
		}
		if ( '' === $state ) {
			delete_post_meta( $id, self::META );
			delete_post_meta( $id, self::META_DECIDED );
		} elseif ( in_array( $state, self::STATES, true ) ) {
			update_post_meta( $id, self::META, $state );
			update_post_meta(
				$id,
				self::META_DECIDED,
				array(
					'by'  => get_current_user_id(),
					'at'  => time(),
					'via' => sanitize_key( $via ),
				)
			);
		} else {
			return false;
		}
		foreach ( array(
			'tool'   => self::META_TOOL,
			'notice' => self::META_NOTICE,
		) as $key => $meta ) {
			if ( array_key_exists( $key, $extra ) ) {
				$value = sanitize_text_field( (string) $extra[ $key ] );
				if ( '' === $value ) {
					delete_post_meta( $id, $meta );
				} else {
					update_post_meta( $id, $meta, $value );
				}
			}
		}
		self::refresh_open( $id );
		/**
		 * After a media file's label changed.
		 *
		 * @param int    $id    Attachment ID.
		 * @param string $state generated, modified, none or ''.
		 * @param string $via   manual, bulk, metadata, assistant.
		 */
		do_action( 'ai_act_labels_changed', $id, $state, $via );
		return true;
	}

	/**
	 * The media type: image, video, audio or file.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	public static function media_type( $id ) {
		$mime = (string) get_post_mime_type( $id );
		$type = strtok( $mime, '/' );
		return in_array( $type, array( 'image', 'video', 'audio' ), true ) ? $type : 'file';
	}

	/**
	 * The short text beside or in the icon: „KI-generiert“, „Mit KI verändert“.
	 *
	 * @param string $kind generated or modified.
	 * @return string
	 */
	public static function label_text( $kind ) {
		$texts = self::settings()['texts'];
		if ( 'modified' === $kind ) {
			return '' !== $texts['modified'] ? $texts['modified'] : __( 'Modified with AI', 'ai-act-image-labels' );
		}
		return '' !== $texts['generated'] ? $texts['generated'] : __( 'AI-generated', 'ai-act-image-labels' );
	}

	/**
	 * The default notice for a kind and a media type.
	 *
	 * @param string $kind generated or modified.
	 * @param string $type image, video, audio, file.
	 * @return string
	 */
	public static function default_notice( $kind, $type = 'image' ) {
		$texts = self::settings()['texts'];
		$own   = 'modified' === $kind ? $texts['noticeModified'] : $texts['noticeGenerated'];
		if ( '' !== $own ) {
			return $own;
		}
		$notices = array(
			'generated' => array(
				'image' => __( 'This image was created with AI.', 'ai-act-image-labels' ),
				'video' => __( 'This video was created with AI.', 'ai-act-image-labels' ),
				'audio' => __( 'This recording was created with AI.', 'ai-act-image-labels' ),
				'file'  => __( 'This content was created with AI.', 'ai-act-image-labels' ),
			),
			'modified'  => array(
				'image' => __( 'This image was modified with AI.', 'ai-act-image-labels' ),
				'video' => __( 'This video was modified with AI.', 'ai-act-image-labels' ),
				'audio' => __( 'This recording was modified with AI.', 'ai-act-image-labels' ),
				'file'  => __( 'This content was modified with AI.', 'ai-act-image-labels' ),
			),
		);
		$kind    = 'modified' === $kind ? 'modified' : 'generated';
		return $notices[ $kind ][ $type ] ?? $notices[ $kind ]['file'];
	}

	/**
	 * The notice of a media file: its own or the default – with the tool.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	public static function notice( $id ) {
		$kind   = self::kind( $id );
		$notice = trim( (string) get_post_meta( $id, self::META_NOTICE, true ) );
		if ( '' === $notice ) {
			$notice = self::default_notice( $kind ? $kind : 'generated', self::media_type( $id ) );
		}
		$tool = trim( (string) get_post_meta( $id, self::META_TOOL, true ) );
		if ( '' !== $tool ) {
			/* translators: %s: the AI tool, e.g. Midjourney */
			$notice = rtrim( $notice ) . ' ' . sprintf( __( 'Tool: %s.', 'ai-act-image-labels' ), $tool );
		}
		return $notice;
	}

	/**
	 * Everything the admin shows about one media file.
	 *
	 * @param int  $id   Attachment ID.
	 * @param bool $full With usage and the details of signals.
	 * @return array
	 */
	public static function describe( $id, $full = true ) {
		$id      = (int) $id;
		$post    = get_post( $id );
		$type    = self::media_type( $id );
		$thumb   = 'image' === $type ? wp_get_attachment_image_url( $id, 'medium' ) : wp_mime_type_icon( $id );
		$file    = (string) get_attached_file( $id );
		$decided = get_post_meta( $id, self::META_DECIDED, true );
		$signals = get_post_meta( $id, self::META_SIGNALS, true );
		$hint    = get_post_meta( $id, self::META_HINT, true );
		$state   = self::get( $id );
		$out     = array(
			'id'       => $id,
			'title'    => $post ? get_the_title( $post ) : '',
			'file'     => wp_basename( $file ),
			'type'     => $type,
			'mime'     => (string) get_post_mime_type( $id ),
			'thumb'    => $thumb ? $thumb : '',
			'full'     => 'image' === $type ? (string) wp_get_attachment_image_url( $id, 'large' ) : (string) wp_get_attachment_url( $id ),
			'edit'     => (string) get_edit_post_link( $id, 'raw' ),
			'date'     => $post ? get_post_time( 'U', true, $post ) : 0,
			'alt'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'  => $post ? (string) $post->post_excerpt : '',
			'state'    => $state,
			'tool'     => (string) get_post_meta( $id, self::META_TOOL, true ),
			'notice'   => (string) get_post_meta( $id, self::META_NOTICE, true ),
			'shown'    => self::kind( $id ) ? self::notice( $id ) : '',
			'decided'  => is_array( $decided ) ? $decided : null,
			'signals'  => is_array( $signals ) ? $signals : null,
			'hint'     => is_array( $hint ) ? $hint : null,
			'suggests' => self::suggestion( $id ),
		);
		if ( $full ) {
			$out['usage'] = AI_Act_Labels_Usage::for_attachment( $id );
		} else {
			$usage        = get_post_meta( $id, self::META_USAGE, true );
			$out['usage'] = is_array( $usage ) ? $usage : array();
		}
		return $out;
	}

	/**
	 * What the plugin would suggest: from unambiguous metadata, else from the assistant's assessment.
	 *
	 * @param int $id Attachment ID.
	 * @return string generated, modified, none or ''.
	 */
	public static function suggestion( $id ) {
		$signals = get_post_meta( $id, self::META_SIGNALS, true );
		if ( is_array( $signals ) && ! empty( $signals['kind'] ) ) {
			return (string) $signals['kind'];
		}
		$hint = get_post_meta( $id, self::META_HINT, true );
		if ( is_array( $hint ) && in_array( $hint['kind'] ?? '', self::STATES, true ) ) {
			return (string) $hint['kind'];
		}
		return '';
	}

	/**
	 * Numbers for the tool and the media library's notice.
	 *
	 * @return array `generated`, `modified`, `none`, `suggested`, `unchecked` (used on the site, no decision), `open` (both, once each), `media`.
	 */
	public static function counts() {
		global $wpdb;
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value AS v, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value", self::META ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$counts = array(
			'generated' => 0,
			'modified'  => 0,
			'none'      => 0,
		);
		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row['v'] ] ) ) {
				$counts[ $row['v'] ] = (int) $row['n'];
			}
		}
		$counts['suggested'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::OPEN_SUGGESTED ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$counts['unchecked'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::OPEN_USED ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$counts['open']      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)", self::OPEN_SUGGESTED, self::OPEN_USED ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$attachments         = wp_count_attachments();
		$counts['media']     = (int) array_sum( (array) $attachments ) - (int) ( $attachments->trash ?? 0 );
		return $counts;
	}

	/**
	 * How many files a list has, without loading them.
	 *
	 * @param string $filter A list of meta_query().
	 * @return int
	 */
	public static function count_of( $filter ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => self::meta_query( $filter ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Sets or clears the two markers of one file from its label, its traces, the assessment and its usage.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function refresh_open( $id ) {
		$undecided = '' === self::get( $id );
		$flags     = array(
			self::OPEN_SUGGESTED => $undecided && in_array( self::suggestion( $id ), self::KINDS, true ),
			self::OPEN_USED      => $undecided && ! empty( get_post_meta( $id, self::META_USAGE, true ) ),
		);
		foreach ( $flags as $key => $on ) {
			if ( $on ) {
				update_post_meta( $id, $key, '1' );
			} else {
				delete_post_meta( $id, $key );
			}
		}
	}

	/** Builds the markers of all files again: two statements, no loop over the library. */
	public static function rebuild_open() {
		global $wpdb;
		$m = $wpdb->postmeta;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-off rebuild of the plugin's own meta.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$m} WHERE meta_key IN (%s, %s)", self::OPEN_SUGGESTED, self::OPEN_USED ) );
		$undecided = $wpdb->prepare( "NOT EXISTS (SELECT 1 FROM {$m} d WHERE d.post_id = x.post_id AND d.meta_key = %s)", self::META );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$m} (post_id, meta_key, meta_value) SELECT DISTINCT x.post_id, %s, '1' FROM {$m} x WHERE x.meta_key IN (%s, %s) AND (x.meta_value LIKE %s OR x.meta_value LIKE %s) AND {$undecided}",
				self::OPEN_SUGGESTED,
				self::META_SIGNALS,
				self::META_HINT,
				'%' . $wpdb->esc_like( '"kind";s:9:"generated"' ) . '%',
				'%' . $wpdb->esc_like( '"kind";s:8:"modified"' ) . '%'
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$m} (post_id, meta_key, meta_value) SELECT DISTINCT x.post_id, %s, '1' FROM {$m} x WHERE x.meta_key = %s AND {$undecided}",
				self::OPEN_USED,
				self::META_USAGE
			)
		);
		// phpcs:enable
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'post_meta' );
		} else {
			wp_cache_flush();
		}
		wp_cache_set_last_changed( 'posts' );
	}

	/**
	 * Attachment IDs of a list in the tool.
	 *
	 * @param string $filter suggested, unchecked, undecided, labelled, generated, modified, none, all.
	 * @param int    $limit  At most (-1: all).
	 * @param string $search Words in title or file name.
	 * @return int[]
	 */
	public static function query_ids( $filter, $limit = 200, $search = '' ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		$meta = self::meta_query( $filter );
		if ( $meta ) {
			$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		if ( 'all' === $filter ) {
			$args['post_mime_type'] = array( 'image', 'video', 'audio' );
		}
		return array_map( 'intval', get_posts( $args ) );
	}

	/**
	 * The meta query of a list: also used by the media library's filters.
	 *
	 * @param string $filter suggested, unchecked, open, undecided, labelled, generated, modified, none.
	 * @return array Empty for all.
	 */
	public static function meta_query( $filter ) {
		$undecided = array(
			array(
				'key'     => self::META,
				'compare' => 'NOT EXISTS',
			),
		);
		switch ( $filter ) {
			case 'undecided':
				return $undecided;
			case 'suggested':
				return array(
					array(
						'key'     => self::OPEN_SUGGESTED,
						'compare' => 'EXISTS',
					),
				);
			case 'unchecked':
				return array(
					array(
						'key'     => self::OPEN_USED,
						'compare' => 'EXISTS',
					),
				);
			case 'open':
				return array(
					'relation' => 'OR',
					array(
						'key'     => self::OPEN_SUGGESTED,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => self::OPEN_USED,
						'compare' => 'EXISTS',
					),
				);
			case 'generated':
			case 'modified':
			case 'none':
				$own = array(
					'key'   => self::META,
					'value' => $filter,
				);
				return array( $own );
			case 'labelled':
				$own = array(
					'key'     => self::META,
					'value'   => self::KINDS,
					'compare' => 'IN',
				);
				return array( $own );
		}
		return array();
	}
}
