<?php
/**
 * The label in the media library: fields in the details of every image, video and recording (the
 * modal and the edit screen), the icon on the tiles, a filter, a column and bulk actions in the list,
 * a look at the file's own data on upload – and a way to the tool.
 *
 * @package AI_Act_Labels
 */

defined( 'ABSPATH' ) || exit;

/**
 * Media library hooks.
 */
class AI_Act_Labels_Media {

	const DISMISS = 'ai_act_labels_dismissed';

	/** Bulk action => state. */
	const BULK = array(
		'ai_act_generated' => 'generated',
		'ai_act_modified'  => 'modified',
		'ai_act_none'      => 'none',
		'ai_act_clear'     => '',
	);

	/** Hooks into the media library. */
	public static function init() {
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'fields' ), 20, 2 );
		add_filter( 'attachment_fields_to_save', array( __CLASS__, 'save' ), 20, 2 );
		add_filter( 'wp_prepare_attachment_for_js', array( __CLASS__, 'for_js' ), 20, 2 );
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'grid_query' ) );
		add_filter( 'manage_media_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'list_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'list_query' ) );
		add_filter( 'bulk_actions-upload', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( __CLASS__, 'bulk' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_ai_act_labels_dismiss', array( __CLASS__, 'dismiss' ) );
		add_action( 'add_attachment', array( __CLASS__, 'uploaded' ) );
		add_action( 'wp_enqueue_media', array( __CLASS__, 'assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'list_assets' ) );
		add_action( 'add_meta_boxes_attachment', array( __CLASS__, 'meta_box' ) );
		add_action( 'edit_attachment', array( __CLASS__, 'save_box' ) );
	}

	/**
	 * Whether this is the edit screen of an attachment: it gets a box of its own instead of fields.
	 *
	 * @return bool
	 */
	private static function edit_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && 'post' === $screen->base && 'attachment' === $screen->post_type;
	}

	/**
	 * The states in words.
	 *
	 * @return array
	 */
	public static function state_labels() {
		return array(
			''          => __( 'Not decided yet', 'ai-act-image-labels' ),
			'generated' => __( 'AI-generated', 'ai-act-image-labels' ),
			'modified'  => __( 'Modified with AI', 'ai-act-image-labels' ),
			'none'      => __( 'No label needed', 'ai-act-image-labels' ),
		);
	}

	/**
	 * The details of an image, a video or a recording in the media modal: the same box as on the edit screen.
	 *
	 * @param array   $fields Fields.
	 * @param WP_Post $post   The attachment.
	 * @return array
	 */
	public static function fields( $fields, $post ) {
		if ( ! $post instanceof WP_Post || 'file' === AI_Act_Labels::media_type( $post->ID ) || self::edit_screen() ) {
			return $fields;
		}
		$fields['ai_act'] = array(
			'label' => __( 'AI label (AI Act)', 'ai-act-image-labels' ),
			'input' => 'html',
			'html'  => self::box( (int) $post->ID, 'attachments[' . (int) $post->ID . ']' ),
		);
		return $fields;
	}

	/**
	 * The box on the edit screen of an image, a video or a recording.
	 *
	 * @param WP_Post $post The attachment.
	 */
	public static function meta_box( $post ) {
		if ( 'file' === AI_Act_Labels::media_type( $post->ID ) ) {
			return;
		}
		add_meta_box( 'ai-act-image-labels', __( 'AI label (AI Act)', 'ai-act-image-labels' ), array( __CLASS__, 'render_box' ), 'attachment', 'side', 'default' );
	}

	/**
	 * The box with its nonce.
	 *
	 * @param WP_Post $post The attachment.
	 */
	public static function render_box( $post ) {
		wp_nonce_field( 'ai_act_labels_box', 'ai_act_labels_box_nonce' );
		echo self::box( (int) $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
	}

	/**
	 * The decision as a choice, traces and assessment, tool and notice once there is a label, the rule
	 * of the AI Act and the way to the tool – on the edit screen and in the media modal.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $name Prefix of the field names: '' on the edit screen, attachments[ID] in the modal.
	 * @return string
	 */
	public static function box( $id, $name = '' ) {
		$state   = AI_Act_Labels::get( $id );
		$suggest = AI_Act_Labels::suggestion( $id );
		$field   = static fn( $key ) => '' === $name ? $key : $name . '[' . $key . ']';
		$html    = '<div class="ai-act-box"><fieldset><legend class="screen-reader-text">' . esc_html__( 'AI labels', 'ai-act-image-labels' ) . '</legend>';
		foreach ( self::state_labels() as $value => $label ) {
			$icon  = in_array( $value, AI_Act_Labels::KINDS, true ) ? AI_Act_Labels_Icons::svg( 'basic' ) : '';
			$html .= sprintf(
				'<label class="ai-act-box__opt is-%1$s"><input type="radio" name="%2$s" value="%3$s"%4$s> %5$s<span>%6$s</span>%7$s</label>',
				esc_attr( '' === $value ? 'open' : $value ),
				esc_attr( $field( 'ai_act_label' ) ),
				esc_attr( $value ),
				checked( $state, $value, false ),
				$icon,
				esc_html( $label ),
				'' === $state && $value === $suggest && '' !== $value ? '<em class="ai-act-box__sug">' . esc_html__( 'Suggestion', 'ai-act-image-labels' ) . '</em>' : ''
			);
		}
		$html   .= '</fieldset>';
		$signals = get_post_meta( $id, AI_Act_Labels::META_SIGNALS, true );
		if ( is_array( $signals ) && ! empty( $signals['items'] ) ) {
			$labels = array_slice( array_filter( array_column( $signals['items'], 'label' ) ), 0, 3 );
			$html  .= '<p class="ai-act-box__note"><b>' . esc_html__( 'In the file:', 'ai-act-image-labels' ) . '</b> ' . esc_html( implode( ', ', $labels ) ) . '</p>';
		}
		$hint = get_post_meta( $id, AI_Act_Labels::META_HINT, true );
		if ( is_array( $hint ) && ! empty( $hint['reason'] ) ) {
			$html .= '<p class="ai-act-box__note is-analysis"><b>' . esc_html__( 'Image analysis (AI):', 'ai-act-image-labels' ) . '</b> ' . esc_html( $hint['reason'] ) . '</p>';
		}
		if ( AI_Act_Labels::suggestion_conflict( $id ) ) {
			$html .= '<p class="ai-act-box__note">' . esc_html__( 'Strong file metadata conflicts with the AI assessment. Review before applying.', 'ai-act-labels' ) . '</p>';
		}
		$html       .= sprintf(
			'<p class="ai-act-box__analyze"><button type="button" class="button" data-ai-act-analyze="%1$d">%2$s</button><span class="ai-act-box__status" role="status"></span></p>',
			$id,
			esc_html__( 'Analyze image (AI)', 'ai-act-image-labels' )
		);
		$placeholder = AI_Act_Labels::default_notice( in_array( $state, AI_Act_Labels::KINDS, true ) ? $state : ( in_array( $suggest, AI_Act_Labels::KINDS, true ) ? $suggest : 'generated' ), AI_Act_Labels::media_type( $id ) );
		$html       .= sprintf(
			'<div class="ai-act-box__more"><label for="ai-act-tool-%1$d">%2$s</label><input type="text" class="widefat" id="ai-act-tool-%1$d" name="%3$s" value="%4$s" placeholder="%5$s"><label for="ai-act-notice-%1$d">%6$s</label><input type="text" class="widefat" id="ai-act-notice-%1$d" name="%7$s" value="%8$s" placeholder="%9$s"><p class="description">%10$s</p></div>',
			$id,
			esc_html__( 'AI tool', 'ai-act-image-labels' ),
			esc_attr( $field( 'ai_act_tool' ) ),
			esc_attr( (string) get_post_meta( $id, AI_Act_Labels::META_TOOL, true ) ),
			esc_attr__( 'optional, e.g. Midjourney', 'ai-act-image-labels' ),
			esc_html__( 'Own notice', 'ai-act-image-labels' ),
			esc_attr( $field( 'ai_act_notice' ) ),
			esc_attr( (string) get_post_meta( $id, AI_Act_Labels::META_NOTICE, true ) ),
			esc_attr( $placeholder ),
			esc_html__( 'Empty: the default. The tool then appears in the notice.', 'ai-act-image-labels' )
		);
		$html       .= sprintf(
			'<p class="description ai-act-box__rule">%1$s</p><p class="ai-act-box__link"><a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'AI Act, Article 50: the label is required when real or plausible people, places or events look real. Ordinary photo editing needs none.', 'ai-act-image-labels' ),
			esc_url( AI_Act_Labels_Admin::url() ),
			esc_html__( 'Review all media →', 'ai-act-image-labels' )
		);
		return $html;
	}

	/**
	 * Saves the box with the attachment.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function save_box( $id ) {
		if ( ! isset( $_POST['ai_act_labels_box_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ai_act_labels_box_nonce'] ) ), 'ai_act_labels_box' ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		$state = isset( $_POST['ai_act_label'] ) ? sanitize_key( wp_unslash( $_POST['ai_act_label'] ) ) : '';
		$state = AI_Act_Labels::one_of( $state, array_merge( AI_Act_Labels::STATES, array( '' ) ), '' );
		$extra = array(
			'tool'   => isset( $_POST['ai_act_tool'] ) ? sanitize_text_field( wp_unslash( $_POST['ai_act_tool'] ) ) : '',
			'notice' => isset( $_POST['ai_act_notice'] ) ? sanitize_text_field( wp_unslash( $_POST['ai_act_notice'] ) ) : '',
		);
		self::save(
			array( 'ID' => $id ),
			array(
				'ai_act_label'  => $state,
				'ai_act_tool'   => $extra['tool'],
				'ai_act_notice' => $extra['notice'],
			)
		);
	}

	/**
	 * Saves the fields.
	 *
	 * @param array $post       The attachment as an array.
	 * @param array $attachment The posted fields.
	 * @return array
	 */
	public static function save( $post, $attachment ) {
		$id = (int) ( $post['ID'] ?? 0 );
		if ( ! $id || ! array_key_exists( 'ai_act_label', $attachment ) || ! current_user_can( 'edit_post', $id ) ) {
			return $post;
		}
		$state = AI_Act_Labels::one_of( (string) $attachment['ai_act_label'], array_merge( AI_Act_Labels::STATES, array( '' ) ), '' );
		$extra = array();
		foreach ( array( 'tool', 'notice' ) as $key ) {
			if ( array_key_exists( 'ai_act_' . $key, $attachment ) ) {
				$extra[ $key ] = (string) $attachment[ 'ai_act_' . $key ];
			}
		}
		if ( AI_Act_Labels::get( $id ) !== $state ) {
			AI_Act_Labels::set( $id, $state, 'manual', $extra );
		} elseif ( $extra ) {
			foreach ( $extra as $key => $value ) {
				$meta  = 'tool' === $key ? AI_Act_Labels::META_TOOL : AI_Act_Labels::META_NOTICE;
				$value = sanitize_text_field( $value );
				if ( '' === $value ) {
					delete_post_meta( $id, $meta );
				} else {
					update_post_meta( $id, $meta, $value );
				}
			}
			AI_Act_Labels_Front::forget();
		}
		return $post;
	}

	/**
	 * The label in the data the media library's grid gets.
	 *
	 * @param array   $response Data.
	 * @param WP_Post $post     The attachment.
	 * @return array
	 */
	public static function for_js( $response, $post ) {
		if ( $post instanceof WP_Post && 'file' !== AI_Act_Labels::media_type( $post->ID ) ) {
			$response['aiAct'] = array(
				'state'    => AI_Act_Labels::get( $post->ID ),
				'suggests' => AI_Act_Labels::suggestion( $post->ID ),
			);
		}
		return $response;
	}

	/**
	 * The grid's filter.
	 *
	 * @param array $query Query arguments.
	 * @return array
	 */
	public static function grid_query( $query ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- WordPress checks the media query itself.
		$filter = isset( $_REQUEST['query']['ai_act'] ) ? sanitize_key( wp_unslash( $_REQUEST['query']['ai_act'] ) ) : '';
		$meta   = $filter ? AI_Act_Labels::meta_query( $filter ) : array();
		if ( $meta ) {
			$query['meta_query'] = isset( $query['meta_query'] ) ? array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'AND',
				$query['meta_query'],
				$meta,
			) : $meta; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		return $query;
	}

	/**
	 * The list's column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['ai_act'] = __( 'AI', 'ai-act-image-labels' );
			}
		}
		return isset( $out['ai_act'] ) ? $out : array_merge( $out, array( 'ai_act' => __( 'AI', 'ai-act-image-labels' ) ) );
	}

	/**
	 * One cell of the column.
	 *
	 * @param string $column Column.
	 * @param int    $id     Attachment ID.
	 */
	public static function column( $column, $id ) {
		if ( 'ai_act' === $column ) {
			echo self::cell( (int) $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
		}
	}

	/**
	 * What a list shows for a file: the EU icon of its label, „Kein Hinweis nötig“, the suggestion, or a dash.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	public static function cell( $id ) {
		if ( 'file' === AI_Act_Labels::media_type( $id ) ) {
			return '';
		}
		$state   = AI_Act_Labels::get( $id );
		$labels  = self::state_labels();
		$suggest = AI_Act_Labels::suggestion( $id );
		if ( in_array( $state, AI_Act_Labels::KINDS, true ) ) {
			return '<span class="ai-act-cell is-' . esc_attr( $state ) . '" title="' . esc_attr( AI_Act_Labels::notice( $id ) ) . '">' . AI_Act_Labels_Icons::svg( $state, null, $labels[ $state ] ) . '</span>';
		}
		if ( 'none' === $state ) {
			return '<span class="ai-act-cell is-none">' . esc_html( $labels['none'] ) . '</span>';
		}
		if ( $suggest && 'none' !== $suggest ) {
			/* translators: %s: KI-generiert or Mit KI verändert */
			return '<span class="ai-act-cell is-suggest">' . esc_html( sprintf( __( 'Suggestion: %s', 'ai-act-image-labels' ), $labels[ $suggest ] ?? '' ) ) . '</span>';
		}
		return '<span class="ai-act-cell is-open" aria-label="' . esc_attr( $labels[''] ) . '">–</span>';
	}

	/**
	 * The filters of the list view and the grid.
	 *
	 * @return array
	 */
	public static function filters() {
		return array(
			''          => __( 'AI: all', 'ai-act-image-labels' ),
			'labelled'  => __( 'Labeled', 'ai-act-image-labels' ),
			'generated' => __( 'AI-generated', 'ai-act-image-labels' ),
			'modified'  => __( 'Modified with AI', 'ai-act-image-labels' ),
			'suggested' => __( 'Suggestions', 'ai-act-image-labels' ),
			'unchecked' => __( 'Used, not decided', 'ai-act-image-labels' ),
			'undecided' => __( 'Not decided', 'ai-act-image-labels' ),
			'none'      => __( 'No label needed', 'ai-act-image-labels' ),
		);
	}

	/**
	 * The filter in the list view.
	 *
	 * @param string $post_type Post type.
	 */
	public static function list_filter( $post_type ) {
		if ( 'attachment' !== $post_type ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter.
		$current = isset( $_GET['ai_act'] ) ? sanitize_key( wp_unslash( $_GET['ai_act'] ) ) : '';
		echo '<label for="ai-act-filter" class="screen-reader-text">' . esc_html__( 'Filter by AI label', 'ai-act-image-labels' ) . '</label><select name="ai_act" id="ai-act-filter">';
		foreach ( self::filters() as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Applies the list view's filter.
	 *
	 * @param WP_Query $query The query.
	 */
	public static function list_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter.
		$filter = isset( $_GET['ai_act'] ) ? sanitize_key( wp_unslash( $_GET['ai_act'] ) ) : '';
		$meta   = $filter ? AI_Act_Labels::meta_query( $filter ) : array();
		if ( $meta ) {
			$existing = $query->get( 'meta_query' );
			$query->set(
				'meta_query',
				$existing ? array(
					'relation' => 'AND',
					$existing,
					$meta,
				) : $meta
			);
		}
	}

	/**
	 * Bulk actions of the list view.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function bulk_actions( $actions ) {
		$actions['ai_act_generated'] = __( 'Label as AI-generated', 'ai-act-image-labels' );
		$actions['ai_act_modified']  = __( 'Label as modified with AI', 'ai-act-image-labels' );
		$actions['ai_act_none']      = __( 'AI: no label needed', 'ai-act-image-labels' );
		$actions['ai_act_clear']     = __( 'Remove AI label', 'ai-act-image-labels' );
		return $actions;
	}

	/**
	 * Runs a bulk action.
	 *
	 * @param string $redirect Where to go.
	 * @param string $action   The action.
	 * @param int[]  $ids      Attachment IDs.
	 * @return string
	 */
	public static function bulk( $redirect, $action, $ids ) {
		if ( ! array_key_exists( $action, self::BULK ) ) {
			return $redirect;
		}
		$done = 0;
		foreach ( (array) $ids as $id ) {
			if ( current_user_can( 'edit_post', (int) $id ) && 'file' !== AI_Act_Labels::media_type( (int) $id ) && AI_Act_Labels::set( (int) $id, self::BULK[ $action ], 'bulk' ) ) {
				++$done;
			}
		}
		return add_query_arg(
			array(
				'ai_act_done'  => $done,
				'ai_act_state' => '' === self::BULK[ $action ] ? 'clear' : self::BULK[ $action ],
			),
			$redirect
		);
	}

	/** After a bulk action; and in the media library, what is still open. */
	public static function notices() {
		$screen = get_current_screen();
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a message after a redirect.
		if ( isset( $_GET['ai_act_done'] ) ) {
			$done  = absint( $_GET['ai_act_done'] );
			$state = isset( $_GET['ai_act_state'] ) ? sanitize_key( wp_unslash( $_GET['ai_act_state'] ) ) : '';
			$text  = 'clear' === $state
				/* translators: %d: number of files */
				? sprintf( _n( 'Removed the AI label from %d file.', 'Removed the AI label from %d files.', $done, 'ai-act-image-labels' ), $done )
				/* translators: 1: number of files, 2: KI-generiert, Mit KI verändert or Kein Hinweis nötig */
				: sprintf( _n( '%1$d file: %2$s.', '%1$d files: %2$s.', $done, 'ai-act-image-labels' ), $done, self::state_labels()[ $state ] ?? '' );
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $text ) );
		}
		// phpcs:enable
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$open = count( AI_Act_Labels::query_ids( 'suggested', 500 ) );
		if ( $open < 1 || $open <= (int) get_user_meta( get_current_user_id(), self::DISMISS, true ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info ai-act-notice"><p><strong>%1$s</strong> %2$s <a class="button button-primary" href="%3$s">%4$s</a> <a class="ai-act-notice__later" href="%5$s">%6$s</a></p></div>',
			esc_html__( 'AI labels:', 'ai-act-image-labels' ),
			/* translators: %d: number of files */
			esc_html( sprintf( _n( '%d file carries traces of AI and has no decision yet.', '%d files carry traces of AI and have no decision yet.', $open, 'ai-act-image-labels' ), $open ) ),
			esc_url( AI_Act_Labels_Admin::url( 'review', 'suggested' ) ),
			esc_html__( 'View', 'ai-act-image-labels' ),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ai_act_labels_dismiss&n=' . $open ), 'ai_act_labels_dismiss' ) ),
			esc_html__( 'Later', 'ai-act-image-labels' )
		);
	}

	/** Hides the notice until more files are open. */
	public static function dismiss() {
		check_admin_referer( 'ai_act_labels_dismiss' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above.
		update_user_meta( get_current_user_id(), self::DISMISS, isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0 );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' ) );
		exit;
	}

	/**
	 * A new upload: the file's own data is read right away.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function uploaded( $id ) {
		$settings = AI_Act_Labels::settings();
		if ( ! $settings['detect'] || 'file' === AI_Act_Labels::media_type( $id ) ) {
			return;
		}
		$signals = AI_Act_Labels_Detect::attachment( $id );
		if ( $settings['auto'] && 'strong' === $signals['strength'] ) {
			AI_Act_Labels::set( $id, $signals['kind'], 'metadata', $signals['tool'] ? array( 'tool' => $signals['tool'] ) : array() );
		}
	}

	/** The tiles' icons and the filter, wherever the media library opens. */
	public static function assets() {
		if ( ! is_admin() || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$asset = AI_ACT_LABELS_DIR . 'build/media.asset.php';
		$meta  = file_exists( $asset ) ? require $asset : array(
			'dependencies' => array( 'media-views', 'wp-i18n' ),
			'version'      => AI_ACT_LABELS_VERSION,
		);
		$deps  = array_unique( array_merge( $meta['dependencies'], array( 'media-views', 'wp-api-fetch' ) ) );
		wp_enqueue_script( 'ai-act-labels-media', AI_ACT_LABELS_URL . 'build/media.js', $deps, $meta['version'], true );
		wp_enqueue_style( 'ai-act-labels-media', AI_ACT_LABELS_URL . 'build/media.css', array(), $meta['version'] );
		wp_add_inline_script(
			'ai-act-labels-media',
			'window.aiActLabelsMedia = ' . wp_json_encode(
				array(
					'filters'    => self::filters(),
					'states'     => self::state_labels(),
					'icons'      => array(
						'generated' => AI_Act_Labels_Icons::svg( 'generated' ),
						'modified'  => AI_Act_Labels_Icons::svg( 'modified' ),
						'basic'     => AI_Act_Labels_Icons::svg( 'basic' ),
					),
					'tool'       => AI_Act_Labels_Admin::url(),
					'connectors' => admin_url( 'options-connectors.php' ),
					'label'      => __( 'AI labels', 'ai-act-image-labels' ),
				)
			) . ';',
			'before'
		);
		wp_set_script_translations( 'ai-act-labels-media', 'ai-act-image-labels', AI_ACT_LABELS_DIR . 'languages' );
	}

	/**
	 * The list view has no media modal: its column and the button need the styles too; so does the box on the edit screen.
	 *
	 * @param string $hook The screen.
	 */
	public static function list_assets( $hook ) {
		if ( 'upload.php' === $hook ) {
			wp_enqueue_media();
		}
		if ( 'post.php' === $hook && self::edit_screen() ) {
			// The box's button needs the script; the script needs the media views.
			wp_enqueue_media();
		}
	}
}
