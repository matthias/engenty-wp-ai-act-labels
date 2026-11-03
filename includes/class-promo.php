<?php
/**
 * The card from engenty at the end of the tool: its words ship with the plugin and change with a release,
 * so the tool makes no request for it.
 *
 * @package AI_Act_Labels
 */

defined( 'ABSPATH' ) || exit;

/**
 * The card's words.
 */
class AI_Act_Labels_Promo {

	/**
	 * The card in the admin's language.
	 *
	 * @return array `kicker`, `title`, `text`, `engenty`, `button` (`label`, `url`).
	 */
	public static function card() {
		return array(
			'kicker'  => __( 'From engenty, the makers of this plugin', 'ai-act-image-labels' ),
			'title'   => __( 'engenty wizards: guided flows for your visitors', 'ai-act-image-labels' ),
			'text'    => __( 'Enquiries, quotes, appointments – step by step on your site, built with AI instead of code.', 'ai-act-image-labels' ),
			'engenty' => 'flame',
			'button'  => array(
				'label' => __( 'Visit engenty.ai', 'ai-act-image-labels' ),
				/* translators: engenty.ai in your language, if there is a page for it (https://engenty.ai/de/). */
				'url'   => __( 'https://engenty.ai/', 'ai-act-image-labels' ),
			),
		);
	}
}
