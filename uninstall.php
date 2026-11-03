<?php
/**
 * Removes the plugin's settings and working data. The labels themselves stay on the media files
 * (post meta `_ai_act_label`, `_ai_act_tool`, `_ai_act_notice`): they document decisions about
 * content, and a later reinstall should find them.
 *
 * @package AI_Act_Labels
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'ai_act_labels', 'ai_act_labels_index', 'ai_act_labels_run', 'ai_act_labels_last_scan', 'ai_act_labels_ai_usage' ) as $ai_act_labels_option ) {
	delete_option( $ai_act_labels_option );
}
foreach ( array( '_ai_act_signals', '_ai_act_hint', '_ai_act_usage', '_ai_act_verify', '_ai_act_tone', '_ai_act_open_s', '_ai_act_open_u' ) as $ai_act_labels_key ) {
	delete_post_meta_by_key( $ai_act_labels_key );
}
delete_metadata( 'user', 0, 'ai_act_labels_dismissed', '', true );
