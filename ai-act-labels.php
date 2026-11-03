<?php
/**
 * Plugin Name:       AI Act Image Labels
 * Plugin URI:        https://engenty.ai/wordpress-plugins/ai-act-image-labels
 * Description:       Marks AI-generated and AI-modified media as the EU AI Act asks – right in the media library, with the EU's official icons on the site, and an assistant that finds what needs a label.
 * Version:           0.2.1
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            engenty
 * Author URI:        https://engenty.ai
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-act-image-labels
 * Domain Path:       /languages
 *
 * @package AI_Act_Labels
 */

defined( 'ABSPATH' ) || exit;

define( 'AI_ACT_LABELS_VERSION', '0.2.1' );
define( 'AI_ACT_LABELS_FILE', __FILE__ );
define( 'AI_ACT_LABELS_DIR', plugin_dir_path( __FILE__ ) );
define( 'AI_ACT_LABELS_URL', plugin_dir_url( __FILE__ ) );

require_once AI_ACT_LABELS_DIR . 'includes/class-icons.php';
require_once AI_ACT_LABELS_DIR . 'includes/class-labels.php';
require_once AI_ACT_LABELS_DIR . 'includes/class-detect.php';
require_once AI_ACT_LABELS_DIR . 'includes/class-usage.php';
require_once AI_ACT_LABELS_DIR . 'includes/class-front.php';
require_once AI_ACT_LABELS_DIR . 'includes/class-media.php';
require_once AI_ACT_LABELS_DIR . 'includes/class-assistant.php';
require_once AI_ACT_LABELS_DIR . 'includes/class-analysis.php';
require_once AI_ACT_LABELS_DIR . 'includes/class-promo.php';
require_once AI_ACT_LABELS_DIR . 'includes/class-rest.php';
require_once AI_ACT_LABELS_DIR . 'includes/class-admin.php';
require_once AI_ACT_LABELS_DIR . 'includes/class-admin-columns.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once AI_ACT_LABELS_DIR . 'includes/class-cli.php';
}

/**
 * Whether a media file carries an AI label: `generated` or `modified`, false when not.
 * For themes that print images themselves, together with ai_act_labels_wrap().
 *
 * @param int $attachment_id Attachment ID.
 * @return string|false
 */
function ai_act_labels_kind( $attachment_id ) {
	$kind = AI_Act_Labels::kind( (int) $attachment_id );
	return $kind ? $kind : false;
}

/**
 * The notice of a labelled media file („Dieses Bild wurde mit KI erzeugt.“), empty when it has no label.
 *
 * @param int $attachment_id Attachment ID.
 * @return string
 */
function ai_act_labels_notice( $attachment_id ) {
	return AI_Act_Labels::kind( (int) $attachment_id ) ? AI_Act_Labels::notice( (int) $attachment_id ) : '';
}

/**
 * Puts the label on image markup a theme prints itself (a raw `<img>`, a picture element).
 * Images from wp_get_attachment_image() and the editor's blocks get it without this.
 *
 * @param string $html          The markup.
 * @param int    $attachment_id Attachment ID.
 * @param bool   $has_caption   True when a visible caption already carries the notice: the icon is then decorative for screen readers.
 * @return string
 */
function ai_act_labels_wrap( $html, $attachment_id, $has_caption = false ) {
	return AI_Act_Labels_Front::wrap( (string) $html, (int) $attachment_id, (bool) $has_caption );
}

/**
 * Adds the notice to a caption or credit line a theme prints itself: „Foto: Anna, Dieses Bild wurde mit KI erzeugt.“
 * An empty text stays empty – no new caption appears.
 *
 * @param string $text          The caption.
 * @param int    $attachment_id Attachment ID.
 * @return string
 */
function ai_act_labels_append_notice( $text, $attachment_id ) {
	return AI_Act_Labels_Front::append_notice( (string) $text, (int) $attachment_id );
}

register_activation_hook(
	__FILE__,
	static function () {
		// The tool opens once after activation.
		set_transient( 'ai_act_labels_activated', 1, 60 );
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		// A running image analysis ends with the plugin.
		AI_Act_Labels_Analysis::stop();
	}
);

add_action(
	'plugins_loaded',
	static function () {
		// The plugin's own translations (de_DE, en_GB); the scripts' come through wp_set_script_translations().
		$locale = determine_locale();
		load_textdomain( 'ai-act-image-labels', AI_ACT_LABELS_DIR . 'languages/ai-act-image-labels-' . $locale . '.mo', $locale );
		AI_Act_Labels_Front::init();
		AI_Act_Labels_Media::init();
		AI_Act_Labels_Rest::init();
		AI_Act_Labels_Analysis::init();
		AI_Act_Labels_Admin::init();
		AI_Act_Labels_Admin_Columns::init();
	}
);
