<?php
/**
 * The column type for Admin Columns: the label as the media list shows it.
 *
 * @package AI_Act_Labels
 */

defined( 'ABSPATH' ) || exit;

/**
 * KI-Kennzeichnung (AI Act) in Admin Columns.
 */
class AI_Act_Labels_AC_Column extends \AC\Column {

	/** The type and its name in „Spalte hinzufügen“. */
	public function __construct() {
		$this->set_type( AI_Act_Labels_Admin_Columns::TYPE );
		$this->set_label( __( 'AI label (AI Act)', 'ai-act-image-labels' ) );
		$this->set_group( 'plugin' );
	}

	/**
	 * What the cell shows.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	public function get_value( $id ) {
		return AI_Act_Labels_Media::cell( (int) $id );
	}

	/**
	 * The decision: generated, modified, none or ''.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	public function get_raw_value( $id ) {
		return AI_Act_Labels::get( (int) $id );
	}
}
