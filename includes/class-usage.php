<?php
/**
 * Where media is used on the site: as a featured image, in the editor's blocks, in galleries, as the
 * logo, in page builders – and as a bare URL, where the label cannot come by itself. The check runs
 * in steps from the tool (posts, then traces in files) and keeps the result per attachment.
 * A second check opens the pages and looks whether the label really shows.
 *
 * @package AI_Act_Labels
 */

defined( 'ABSPATH' ) || exit;

/**
 * The usage index and the check on the pages.
 */
class AI_Act_Labels_Usage {

	const RUN    = 'ai_act_labels_run';
	const LAST   = 'ai_act_labels_last_scan';
	const VERIFY = '_ai_act_verify';

	/** Places where the label comes by itself: WordPress prints these images through its own functions. */
	const AUTO = array( 'featured', 'image', 'gallery', 'cover', 'media-text', 'video', 'audio', 'logo', 'icon', 'shop' );

	/** Posts and files per chunk; a step takes chunks until its time is up, so big sites need fewer requests. */
	const POSTS_PER_STEP = 40;
	const FILES_PER_STEP = 25;
	const SECONDS        = 2.5;

	/**
	 * One step of the check.
	 *
	 * @param bool $restart Start again from the beginning.
	 * @param bool $files   Look at traces in files that were looked at before, too.
	 * @return array `phase` (posts, files, done), `done`, `total`, `message`.
	 */
	public static function step( $restart = false, $files = false ) {
		$run = get_option( self::RUN );
		if ( $restart || ! is_array( $run ) ) {
			$run = array(
				'phase' => 'posts',
				'posts' => self::post_ids(),
				'done'  => 0,
				'map'   => array(),
				'files' => array(),
				'all'   => (bool) $files,
			);
		}
		if ( 'posts' === $run['phase'] ) {
			$start = microtime( true );
			$total = count( $run['posts'] );
			do {
				$batch = array_slice( $run['posts'], $run['done'], self::POSTS_PER_STEP );
				// One query for the meta of the whole chunk: featured images, ACF fields, builders.
				update_meta_cache( 'post', $batch );
				foreach ( $batch as $post_id ) {
					$post = get_post( $post_id );
					if ( $post ) {
						foreach ( self::in_post( $post ) as $attachment_id => $contexts ) {
							foreach ( $contexts as $context ) {
								$run['map'][ $attachment_id ][] = array(
									'p' => $post->ID,
									'c' => $context,
								);
							}
						}
					}
				}
				$run['done'] += count( $batch );
			} while ( $batch && $run['done'] < $total && microtime( true ) - $start < self::SECONDS );
			if ( $run['done'] >= count( $run['posts'] ) ) {
				foreach ( self::site_wide() as $attachment_id => $contexts ) {
					foreach ( $contexts as $context ) {
						$run['map'][ $attachment_id ][] = array(
							'p' => 0,
							'c' => $context,
						);
					}
				}
				$run['phase'] = 'files';
				$run['files'] = self::file_ids( $run['all'] );
				$run['done']  = 0;
			}
			update_option( self::RUN, $run, false );
			return self::progress( $run );
		}
		if ( 'files' === $run['phase'] ) {
			$auto  = AI_Act_Labels::settings()['auto'];
			$start = microtime( true );
			$total = count( $run['files'] );
			do {
				$batch = array_slice( $run['files'], $run['done'], self::FILES_PER_STEP );
				update_meta_cache( 'post', $batch );
				foreach ( $batch as $attachment_id ) {
					// CLI is an explicit site-wide operation; web callers must be allowed to edit each file.
					if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( 'edit_post', $attachment_id ) ) {
						continue;
					}
					$signals = AI_Act_Labels_Detect::attachment( $attachment_id );
					if ( $auto && 'strong' === $signals['strength'] && '' === AI_Act_Labels::get( $attachment_id ) ) {
						AI_Act_Labels::set( $attachment_id, $signals['kind'], 'metadata', $signals['tool'] ? array( 'tool' => $signals['tool'] ) : array() );
					}
				}
				$run['done'] += count( $batch );
			} while ( $batch && $run['done'] < $total && microtime( true ) - $start < self::SECONDS );
			if ( $run['done'] >= count( $run['files'] ) ) {
				self::finish( $run );
				$run['phase'] = 'done';
				delete_option( self::RUN );
				return self::progress( $run );
			}
			update_option( self::RUN, $run, false );
			return self::progress( $run );
		}
		delete_option( self::RUN );
		return array(
			'phase' => 'done',
			'done'  => 0,
			'total' => 0,
		);
	}

	/**
	 * Where the check is.
	 *
	 * @param array $run The run.
	 * @return array
	 */
	private static function progress( array $run ) {
		return array(
			'phase' => $run['phase'],
			'done'  => (int) $run['done'],
			'total' => 'posts' === $run['phase'] ? count( $run['posts'] ) : count( $run['files'] ),
			'used'  => count( $run['map'] ),
		);
	}

	/**
	 * Writes the usage of every attachment: the old index goes, the new one comes.
	 *
	 * @param array $run The run.
	 */
	private static function finish( array $run ) {
		global $wpdb;
		delete_post_meta_by_key( AI_Act_Labels::META_USAGE );
		// One statement per 500 files: thousands of single updates take longer than a request may.
		// rebuild_open() empties the meta cache afterwards.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own meta, IDs are integers, rows are prepared.
		foreach ( array_chunk( array_map( 'intval', array_keys( $run['map'] ) ), 500 ) as $chunk ) {
			$ids  = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID IN (" . implode( ',', $chunk ) . ')' );
			$rows = array();
			foreach ( array_map( 'intval', $ids ) as $id ) {
				$rows[] = $wpdb->prepare( '(%d, %s, %s)', $id, AI_Act_Labels::META_USAGE, maybe_serialize( array_values( array_unique( $run['map'][ $id ], SORT_REGULAR ) ) ) );
			}
			if ( $rows ) {
				$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $rows ) );
			}
		}
		// phpcs:enable
		AI_Act_Labels::rebuild_open();
		update_option(
			self::LAST,
			array(
				'at'    => time(),
				'posts' => count( $run['posts'] ),
				'files' => count( $run['files'] ),
				'used'  => count( $run['map'] ),
			),
			false
		);
	}

	/**
	 * Every post visitors can see, templates and reusable blocks included.
	 *
	 * @return int[]
	 */
	public static function post_ids() {
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		$types = array_merge( $types, array( 'wp_block', 'wp_template', 'wp_template_part' ) );
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => $types,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			)
		);
	}

	/**
	 * Attachments whose files are read in this check.
	 *
	 * @param bool $all Also those read before.
	 * @return int[]
	 */
	private static function file_ids( $all ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => array( 'image', 'video', 'audio' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);
		if ( ! $all ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => AI_Act_Labels::META_SIGNALS,
					'compare' => 'NOT EXISTS',
				),
			);
		}
		return array_map( 'intval', get_posts( $args ) );
	}

	/**
	 * Where one post uses media.
	 *
	 * @param WP_Post $post The post.
	 * @return array Attachment ID => contexts.
	 */
	public static function in_post( WP_Post $post ) {
		$found = array();
		$add   = static function ( $id, $context ) use ( &$found ) {
			$id = (int) $id;
			if ( $id > 0 && ! in_array( $context, $found[ $id ] ?? array(), true ) ) {
				$found[ $id ][] = $context;
			}
		};
		$thumb = (int) get_post_meta( $post->ID, '_thumbnail_id', true );
		if ( $thumb ) {
			$add( $thumb, 'featured' );
		}
		$content = (string) $post->post_content;
		if ( has_blocks( $content ) ) {
			self::walk( parse_blocks( $content ), $add );
		}
		if ( preg_match_all( '/\bwp-image-(\d+)\b/', $content, $m ) ) {
			foreach ( $m[1] as $id ) {
				if ( ! isset( $found[ (int) $id ] ) ) {
					$add( $id, 'image' );
				}
			}
		}
		if ( preg_match_all( '/\[gallery[^\]]*\bids=["\']?([\d,\s]+)/', $content, $m ) ) {
			foreach ( $m[1] as $ids ) {
				foreach ( wp_parse_id_list( $ids ) as $id ) {
					$add( $id, 'gallery' );
				}
			}
		}
		// Bare URLs to uploads: in HTML blocks, styles, shortcodes of other plugins.
		foreach ( self::upload_urls( $content ) as $url ) {
			$id = self::id_from_url( $url );
			if ( $id && ! isset( $found[ $id ] ) ) {
				$add( $id, 'url' );
			}
		}
		$elementor = (string) get_post_meta( $post->ID, '_elementor_data', true );
		if ( '' !== $elementor && preg_match_all( '/"id":\s*"?(\d+)"?[^{}]{0,200}?"url":|"url":\s*"[^"]*"\s*,\s*"id":\s*"?(\d+)/', $elementor, $m ) ) {
			foreach ( array_merge( $m[1], $m[2] ) as $id ) {
				if ( $id ) {
					$add( $id, 'builder' );
				}
			}
		}
		foreach ( self::in_acf( $post->ID ) as $id ) {
			if ( ! isset( $found[ $id ] ) ) {
				$add( $id, 'acf' );
			}
		}
		$gallery = (string) get_post_meta( $post->ID, '_product_image_gallery', true );
		foreach ( wp_parse_id_list( $gallery ) as $id ) {
			$add( $id, 'shop' );
		}
		/**
		 * Where a post uses media, for places this plugin does not know (fields of a theme, other builders).
		 *
		 * @param array   $found Attachment ID => list of contexts (featured, image, gallery, cover, media-text, video, audio, url, acf, builder, shop).
		 * @param WP_Post $post  The post.
		 */
		return (array) apply_filters( 'ai_act_labels_usage', $found, $post );
	}

	/**
	 * Media in Advanced Custom Fields – image, file and gallery fields, also inside flexible content,
	 * repeaters and groups. Read from the post's meta (ACF keeps the field key beside each value), so
	 * no field is loaded through ACF itself.
	 *
	 * @param int $post_id Post ID.
	 * @return int[] Attachment IDs.
	 */
	private static function in_acf( $post_id ) {
		if ( ! function_exists( 'acf_get_field' ) ) {
			return array();
		}
		static $types = array();
		$meta         = get_post_meta( $post_id );
		$ids          = array();
		foreach ( $meta as $key => $values ) {
			$ref = $meta[ '_' . $key ][0] ?? '';
			if ( '_' === $key[0] || ! is_string( $ref ) || ! str_starts_with( $ref, 'field_' ) ) {
				continue;
			}
			if ( ! isset( $types[ $ref ] ) ) {
				$field         = acf_get_field( $ref );
				$types[ $ref ] = is_array( $field ) ? (string) $field['type'] : '';
			}
			if ( ! in_array( $types[ $ref ], array( 'image', 'file', 'gallery' ), true ) ) {
				continue;
			}
			$value = maybe_unserialize( $values[0] ?? '' );
			foreach ( (array) $value as $id ) {
				if ( is_numeric( $id ) && (int) $id > 0 ) {
					$ids[] = (int) $id;
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Media in blocks, nested ones included.
	 *
	 * @param array    $blocks Parsed blocks.
	 * @param callable $add    Collects ID and context.
	 * @param string   $outer  The block around these.
	 */
	private static function walk( array $blocks, callable $add, $outer = '' ) {
		foreach ( $blocks as $block ) {
			$name  = (string) ( $block['blockName'] ?? '' );
			$attrs = (array) ( $block['attrs'] ?? array() );
			switch ( $name ) {
				case 'core/image':
					$add( $attrs['id'] ?? 0, 'core/gallery' === $outer ? 'gallery' : 'image' );
					break;
				case 'core/gallery':
					foreach ( (array) ( $attrs['ids'] ?? array() ) as $id ) {
						$add( $id, 'gallery' );
					}
					break;
				case 'core/cover':
					$add( $attrs['id'] ?? 0, 'cover' );
					break;
				case 'core/media-text':
					$add( $attrs['mediaId'] ?? 0, 'media-text' );
					break;
				case 'core/video':
					$add( $attrs['id'] ?? 0, 'video' );
					break;
				case 'core/audio':
					$add( $attrs['id'] ?? 0, 'audio' );
					break;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::walk( $block['innerBlocks'], $add, $name );
			}
		}
	}

	/**
	 * Logo, site icon, header image.
	 *
	 * @return array Attachment ID => contexts.
	 */
	public static function site_wide() {
		$found = array();
		$logo  = (int) get_theme_mod( 'custom_logo' );
		if ( ! $logo ) {
			$logo = (int) get_option( 'site_logo' );
		}
		if ( $logo ) {
			$found[ $logo ][] = 'logo';
		}
		$icon = (int) get_option( 'site_icon' );
		if ( $icon ) {
			$found[ $icon ][] = 'icon';
		}
		$header = get_theme_mod( 'header_image_data' );
		if ( is_object( $header ) && ! empty( $header->attachment_id ) ) {
			$found[ (int) $header->attachment_id ][] = 'header';
		}
		return $found;
	}

	/**
	 * URLs to the uploads folder in a text.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function upload_urls( $text ) {
		$uploads = wp_get_upload_dir();
		$base    = preg_quote( (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ), '#' );
		if ( '' === $base || ! preg_match_all( '#' . $base . '/[^\s"\'()<>]+\.(?:jpe?g|png|gif|webp|avif|svg|mp4|webm|mov|mp3|m4a|wav|ogg)#i', $text, $m ) ) {
			return array();
		}
		return array_unique( $m[0] );
	}

	/**
	 * The attachment of an upload URL, also of a size like -300x200 or of -scaled.
	 *
	 * @param string $url URL or path.
	 * @return int
	 */
	public static function id_from_url( $url ) {
		static $cache = array();
		$path         = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( isset( $cache[ $path ] ) ) {
			return $cache[ $path ];
		}
		$uploads = wp_get_upload_dir();
		$base    = (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		$file    = ltrim( substr( $path, strlen( $base ) ), '/' );
		$plain   = preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $file );
		$id      = 0;
		foreach ( array_unique( array( $file, $plain, preg_replace( '/(?=\.[a-z0-9]+$)/i', '-scaled', $plain, 1 ) ) ) as $candidate ) {
			$posts = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'key'   => '_wp_attached_file',
							'value' => $candidate,
						),
					),
				)
			);
			if ( $posts ) {
				$id = (int) $posts[0];
				break;
			}
		}
		$cache[ $path ] = $id;
		return $id;
	}

	/**
	 * The usage of one attachment, as the admin shows it.
	 *
	 * @param int $id Attachment ID.
	 * @return array[] `post`, `title`, `type`, `url`, `edit`, `context`, `auto`.
	 */
	public static function for_attachment( $id ) {
		$uses   = get_post_meta( $id, AI_Act_Labels::META_USAGE, true );
		$verify = get_post_meta( $id, self::VERIFY, true );
		$out    = array();
		foreach ( is_array( $uses ) ? $uses : array() as $use ) {
			$post_id = (int) ( $use['p'] ?? 0 );
			$context = (string) ( $use['c'] ?? '' );
			$post    = $post_id ? get_post( $post_id ) : null;
			if ( $post_id && ! $post ) {
				continue;
			}
			$type  = $post ? get_post_type_object( $post->post_type ) : null;
			$out[] = array(
				'post'    => $post_id,
				'title'   => $post ? html_entity_decode( get_the_title( $post ), ENT_QUOTES ) : self::site_label( $context ),
				'type'    => $type ? $type->labels->singular_name : __( 'Website', 'ai-act-image-labels' ),
				'url'     => $post ? ( in_array( $post->post_type, array( 'wp_block', 'wp_template', 'wp_template_part' ), true ) ? '' : (string) get_permalink( $post ) ) : home_url( '/' ),
				'edit'    => $post ? (string) get_edit_post_link( $post, 'raw' ) : admin_url( 'site-editor.php' ),
				'context' => $context,
				'auto'    => in_array( $context, self::AUTO, true ),
				'check'   => is_array( $verify ) ? ( $verify['pages'][ $post_id ] ?? '' ) : '',
			);
		}
		return $out;
	}

	/**
	 * A site-wide place in words.
	 *
	 * @param string $context logo, icon, header.
	 * @return string
	 */
	private static function site_label( $context ) {
		switch ( $context ) {
			case 'logo':
				return __( 'Site logo', 'ai-act-image-labels' );
			case 'icon':
				return __( 'Site icon', 'ai-act-image-labels' );
			case 'header':
				return __( 'Header image', 'ai-act-image-labels' );
		}
		return __( 'Website', 'ai-act-image-labels' );
	}

	/**
	 * Opens the pages that use a labelled file and looks whether the label shows there: `shown`, `missing`
	 * (the file is on the page, its label is not – the theme prints it in its own way) or `absent`.
	 *
	 * @param int $id Attachment ID.
	 * @return array `pages` (post ID => state), `at`.
	 */
	public static function verify( $id ) {
		$id     = (int) $id;
		$pages  = array();
		$file   = (string) get_post_meta( $id, '_wp_attached_file', true );
		$stem   = preg_replace( '/(-scaled)?\.[a-z0-9]+$/i', '', wp_basename( $file ) );
		$marker = 'data-ai-act-id="' . $id . '"';
		$seen   = 0;
		foreach ( self::for_attachment( $id ) as $use ) {
			if ( '' === $use['url'] || isset( $pages[ $use['post'] ] ) || $seen >= 6 ) {
				continue;
			}
			++$seen;
			$response = wp_remote_get(
				add_query_arg( 'ai-act-labels-check', time(), $use['url'] ),
				array(
					'timeout'   => 15,
					'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WordPress' own filter for requests to the own site.
				)
			);
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				$pages[ $use['post'] ] = 'error';
				continue;
			}
			$html = (string) wp_remote_retrieve_body( $response );
			if ( false !== strpos( $html, $marker ) ) {
				$pages[ $use['post'] ] = 'shown';
			} elseif ( '' !== $stem && false !== strpos( $html, $stem ) ) {
				$pages[ $use['post'] ] = 'missing';
			} else {
				$pages[ $use['post'] ] = 'absent';
			}
		}
		$result = array(
			'pages' => $pages,
			'at'    => time(),
		);
		update_post_meta( $id, self::VERIFY, $result );
		return $result;
	}
}
