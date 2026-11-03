<?php
/**
 * The card from engenty: its words come with the plugin, no request leaves the site.
 *
 * @package AI_Act_Labels
 */

class PromoTest extends AI_Act_Labels_TestCase {

	private $requests = array();

	public function note( $pre, $args, $url ) {
		$this->requests[] = $url;
		return $pre;
	}

	public function test_the_card_comes_without_a_request() {
		add_filter( 'pre_http_request', array( $this, 'note' ), 10, 3 );
		$card = AI_Act_Labels_Promo::card();
		remove_filter( 'pre_http_request', array( $this, 'note' ), 10 );
		$this->assertSame( array(), $this->requests );
		$this->assertNotEmpty( $card['title'] );
		$this->assertSame( 'engenty.ai', wp_parse_url( $card['button']['url'], PHP_URL_HOST ) );
	}
}
