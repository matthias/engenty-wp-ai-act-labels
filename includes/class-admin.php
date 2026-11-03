<?php
/**
 * The tool under Medien › KI-Kennzeichnung: one page the admin app (src/admin) draws into, with
 * the tabs Prüfen, Darstellung and Wissen.
 *
 * @package AI_Act_Labels
 */

defined( 'ABSPATH' ) || exit;

/**
 * The tool's page.
 */
class AI_Act_Labels_Admin {

	const SLUG = 'ai-act-labels';

	/** Hooks the page, its assets and the link beside „Deaktivieren“. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'after_activation' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AI_ACT_LABELS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * The tool's URL, optionally on a tab and a list.
	 *
	 * @param string $tab    review, look, knowledge.
	 * @param string $filter A list of the review tab.
	 * @return string
	 */
	public static function url( $tab = '', $filter = '' ) {
		return add_query_arg(
			array_filter(
				array(
					'page'   => self::SLUG,
					'tab'    => $tab,
					'filter' => $filter,
				)
			),
			admin_url( 'upload.php' )
		);
	}

	/** The page under Medien. */
	public static function menu() {
		add_media_page(
			__( 'AI labels', 'ai-act-image-labels' ),
			__( 'AI labels', 'ai-act-image-labels' ),
			'upload_files',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/** The page: the app draws into it. */
	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only picks the view.
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'review';
		$filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : '';
		// phpcs:enable
		printf(
			'<div class="wrap aal-wrap"><div id="ai-act-labels-app" data-tab="%s" data-filter="%s"><p class="aal-loading">%s</p></div></div>',
			esc_attr( $tab ),
			esc_attr( $filter ),
			esc_html__( 'AI labels are loading …', 'ai-act-image-labels' )
		);
	}

	/**
	 * The admin app on its page.
	 *
	 * @param string $hook The screen.
	 */
	public static function assets( $hook ) {
		if ( 'media_page_' . self::SLUG !== $hook ) {
			return;
		}
		$asset = AI_ACT_LABELS_DIR . 'build/tool.asset.php';
		$meta  = file_exists( $asset ) ? require $asset : array(
			'dependencies' => array( 'wp-element', 'wp-api-fetch', 'wp-i18n' ),
			'version'      => AI_ACT_LABELS_VERSION,
		);
		wp_enqueue_media();
		wp_enqueue_style( 'ai-act-labels-admin', AI_ACT_LABELS_URL . 'build/tool.css', array(), $meta['version'] );
		wp_enqueue_script( 'ai-act-labels-admin', AI_ACT_LABELS_URL . 'build/tool.js', $meta['dependencies'], $meta['version'], true );
		$boot = array(
			'tool'      => self::url(),
			'upload'    => admin_url( 'upload.php' ),
			// A file in the media library: its modal in the grid view, its edit screen in the list view.
			'item'      => 'list' === get_user_option( 'media_library_mode' ) ? admin_url( 'post.php?action=edit&post=' ) : admin_url( 'upload.php?item=' ),
			'version'   => AI_ACT_LABELS_VERSION,
			'home'      => home_url( '/' ),
			'locale'    => get_user_locale(),
			'wpVersion' => get_bloginfo( 'version' ),
			'icons'     => array(
				'basic'     => AI_Act_Labels_Icons::svg( 'basic' ),
				'generated' => AI_Act_Labels_Icons::svg( 'generated' ),
				'modified'  => AI_Act_Labels_Icons::svg( 'modified' ),
				'sparkle'   => AI_Act_Labels_Icons::sparkle(),
			),
			'skill'     => AI_ACT_LABELS_URL . 'skills/eu-ai-act-labels/SKILL.md',
		);
		wp_add_inline_script( 'ai-act-labels-admin', 'window.aiActLabelsAdmin = ' . wp_json_encode( $boot ) . ';', 'before' );
		wp_set_script_translations( 'ai-act-labels-admin', 'ai-act-image-labels', AI_ACT_LABELS_DIR . 'languages' );
	}

	/** After activation the tool opens once. */
	public static function after_activation() {
		if ( ! get_transient( 'ai_act_labels_activated' ) ) {
			return;
		}
		delete_transient( 'ai_act_labels_activated' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- several plugins activated at once: no redirect.
		if ( wp_doing_ajax() || isset( $_GET['activate-multi'] ) || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * „Öffnen“ beside „Deaktivieren“.
	 *
	 * @param array $links The links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Open', 'ai-act-image-labels' ) ) );
		return $links;
	}
}
