<?php
/** Regression coverage for attachment authorization, bounded metadata and exact media identities. */
// phpcs:disable

class AuditRegressionTest extends AI_Act_Labels_TestCase {

	private function request( $params ) {
		$request = new WP_REST_Request( 'POST' );
		$request->set_body_params( $params );
		return $request;
	}

	private function lookup( $url ) {
		$method = new ReflectionMethod( AI_Act_Labels_Front::class, 'id_by_src' );
		return $method->invoke( null, $url );
	}

	private function png_chunk( $type, $data ) {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	}

	private function png_signals( $chunks ) {
		$method = new ReflectionMethod( AI_Act_Labels_Detect::class, 'from_png' );
		return $method->invoke( null, "\x89PNG\r\n\x1a\n" . implode( '', $chunks ) . $this->png_chunk( 'IEND', '' ) );
	}

	public function test_other_authors_cannot_review_verify_or_scan_sitewide() {
		$author = wp_insert_user( array( 'user_login' => 'labels-author-' . wp_generate_password( 8, false ), 'role' => 'author', 'user_pass' => wp_generate_password() ) );
		$second = wp_insert_user( array( 'user_login' => 'labels-other-' . wp_generate_password( 8, false ), 'role' => 'author', 'user_pass' => wp_generate_password() ) );
		$id = $this->image();
		$other = $this->image( 'other.jpg' );
		wp_update_post( array( 'ID' => $other, 'post_author' => $second ) );
		wp_update_post( array( 'ID' => $id, 'post_author' => $author ) );
		wp_set_current_user( $author );
		try {
			$this->assertTrue( current_user_can( 'edit_post', $id ) );
			$this->assertFalse( current_user_can( 'edit_post', $other ) );
			$before = get_post_meta( $other );
			foreach ( array( 'review', 'verify' ) as $callback ) {
				$result = AI_Act_Labels_Rest::$callback( $this->request( array( 'id' => $other ) ) );
				$this->assertInstanceOf( WP_Error::class, $result );
				$this->assertSame( 403, $result->get_error_data()['status'] );
			}
			$this->assertSame( $before, get_post_meta( $other ) );
			$result = AI_Act_Labels_Rest::review( $this->request( array( 'id' => $id ) ) );
			$this->assertSame( 'ai_act_labels_no_provider', $result->get_error_code() );
			$before = get_option( AI_Act_Labels_Usage::RUN );
			$result = AI_Act_Labels_Rest::scan( $this->request( array( 'restart' => true, 'files' => true ) ) );
			$this->assertSame( 403, $result->get_error_data()['status'] );
			$this->assertSame( $before, get_option( AI_Act_Labels_Usage::RUN ) );
		} finally {
			wp_set_current_user( 1 );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $author, 1 );
			wp_delete_user( $second, 1 );
		}
	}

	public function test_scan_skips_detection_and_auto_labels_for_a_denied_object() {
		$id = $this->attachment( 'scan.jpg', AI_Act_Labels_Test_Files::jpeg_with_xmp( AI_Act_Labels_Test_Files::scene( 'team' ), 'trainedAlgorithmicMedia' ) );
		delete_post_meta( $id, AI_Act_Labels::META_SIGNALS );
		AI_Act_Labels::save_settings( array( 'auto' => true ) );
		$deny = static function ( $caps, $cap, $user, $args ) use ( $id ) {
			return 'edit_post' === $cap && $id === (int) ( $args[0] ?? 0 ) ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 4 );
		try {
			$result = AI_Act_Labels_Rest::scan( $this->request( array( 'restart' => true, 'files' => true ) ) );
			while ( 'done' !== $result['phase'] ) {
				$result = AI_Act_Labels_Rest::scan( $this->request( array() ) );
			}
			$this->assertSame( '', AI_Act_Labels::get( $id ) );
			$this->assertSame( '', get_post_meta( $id, AI_Act_Labels::META_SIGNALS, true ) );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}
	}

	public function test_png_compressed_text_is_bounded_per_chunk_for_both_formats() {
		$small = 'Steps: 20, Sampler: Euler';
		$this->assertNotEmpty( $this->png_signals( array( $this->png_chunk( 'zTXt', "parameters\0\0" . gzcompress( $small ) ) ) ) );
		$this->assertNotEmpty( $this->png_signals( array( $this->png_chunk( 'iTXt', "parameters\0\1\0\0\0" . gzcompress( $small ) ) ) ) );
		$large = $small . str_repeat( 'x', AI_Act_Labels_Detect::PNG_TEXT_CHUNK_LIMIT );
		foreach ( array( 'zTXt' => "parameters\0\0", 'iTXt' => "parameters\0\1\0\0\0" ) as $type => $prefix ) {
			$this->assertSame( array(), $this->png_signals( array( $this->png_chunk( $type, $prefix . gzcompress( $large ) ) ) ) );
		}
	}

	public function test_png_total_budget_includes_repeated_keys_and_uncompressed_text() {
		foreach ( array( 'zTXt', 'tEXt' ) as $type ) {
			$chunks = array();
			for ( $i = 0; $i < 5; ++$i ) {
				$text = str_repeat( 'x', 250000 );
				$chunks[] = $this->png_chunk( $type, "padding\0" . ( 'zTXt' === $type ? "\0" . gzcompress( $text ) : $text ) );
			}
			$chunks[] = $this->png_chunk( 'tEXt', "parameters\0Steps: 20, Sampler: Euler" );
			$this->assertSame( array(), $this->png_signals( $chunks ) );
		}
	}

	public function test_exact_media_identity_preserves_directory_extension_host_and_known_sizes() {
		$id = $this->image( 'identity.jpg' );
		AI_Act_Labels::set( $id, 'generated' );
		$url = wp_get_attachment_url( $id );
		$this->assertSame( $id, $this->lookup( $url . '?version=1&amp;x=2' ) );
		$this->assertSame( $id, $this->lookup( wp_get_attachment_image_url( $id, 'medium' ) ) );
		$this->assertSame( 0, $this->lookup( str_replace( '.jpg', '.png', $url ) ) );
		$this->assertSame( 0, $this->lookup( 'https://unrelated.example/' . wp_basename( $url ) ) );
		$this->assertSame( 0, $this->lookup( dirname( dirname( $url ) ) . '/different/' . wp_basename( $url ) ) );
		$this->assertSame( 0, $this->lookup( str_replace( '.jpg', '-987x654.jpg', $url ) ) );
		$meta = wp_get_attachment_metadata( $id );
		$meta['original_image'] = 'identity-original.jpg';
		$meta['sizes']['alternate'] = array( 'file' => 'identity-explicit.webp', 'width' => 400, 'height' => 300, 'mime-type' => 'image/webp' );
		wp_update_attachment_metadata( $id, $meta );
		$this->assertSame( $id, $this->lookup( dirname( $url ) . '/identity-original.jpg' ) );
		$this->assertSame( $id, $this->lookup( dirname( $url ) . '/identity-explicit.webp' ) );
		$cdn = static fn( $source, $attachment ) => $id === $attachment ? 'https://media.example/assets/identity.jpg' : $source;
		add_filter( 'wp_get_attachment_url', $cdn, 10, 2 );
		try {
			AI_Act_Labels_Front::forget();
			$this->assertSame( $id, $this->lookup( 'https://media.example/assets/identity.jpg' ) );
			$this->assertSame( 0, $this->lookup( 'https://other.example/assets/identity.jpg' ) );
		} finally {
			remove_filter( 'wp_get_attachment_url', $cdn, 10 );
		}
	}

	public function test_same_basename_in_another_folder_does_not_resolve_to_labelled_media() {
		$id = $this->image( 'portrait.jpg' );
		$other = $this->image( 'another.jpg' );
		$file = get_post_meta( $id, '_wp_attached_file', true );
		update_post_meta( $other, '_wp_attached_file', 'different/' . wp_basename( $file ) );
		AI_Act_Labels::set( $id, 'generated' );
		$this->assertSame( 0, $this->lookup( wp_get_attachment_url( $other ) ) );
		AI_Act_Labels::set( $other, 'modified' );
		$this->assertSame( $other, $this->lookup( wp_get_attachment_url( $other ) ) );
		$this->assertSame( $id, $this->lookup( wp_get_attachment_url( $id ) ) );
	}

	public function test_oversized_label_batch_is_rejected_before_any_mutation() {
		$id = $this->image();
		$result = AI_Act_Labels_Rest::label( $this->request( array( 'ids' => array_fill( 0, 201, $id ), 'state' => 'generated' ) ) );
		$this->assertSame( 'ai_act_labels_limit', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( '', AI_Act_Labels::get( $id ) );
	}

	public function test_all_500_accepted_labels_can_be_undone_in_api_sized_batches() {
		$ids = array();
		for ( $i = 0; $i < 500; ++$i ) {
			$id = wp_insert_attachment( array( 'post_title' => 'Bulk ' . $i, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'post_date' => '2030-01-01 00:00:00' ) );
			$this->made[] = $id;
			$ids[] = $id;
			update_post_meta( $id, AI_Act_Labels::META_SIGNALS, array( 'kind' => 'generated', 'strength' => 'strong' ) );
			AI_Act_Labels::refresh_open( $id );
		}
		$result = AI_Act_Labels_Rest::accept();
		$this->assertCount( 500, $result['done'] );
		$this->assertEqualsCanonicalizing( $ids, array_column( $result['done'], 'id' ) );
		foreach ( array_chunk( array_column( $result['done'], 'id' ), 200 ) as $batch ) {
			$result = AI_Act_Labels_Rest::label( $this->request( array( 'ids' => $batch, 'state' => '', 'via' => 'bulk' ) ) );
			$this->assertCount( count( $batch ), $result['items'] );
			foreach ( $batch as $id ) {
				$this->assertSame( '', AI_Act_Labels::get( $id ) );
			}
		}
	}

	public function test_assessments_override_weak_signals_and_strong_conflicts_are_exposed() {
		$id = $this->image();
		foreach ( array( 'none' => 'none', 'modified' => 'modified', 'unsure' => '' ) as $hint => $expected ) {
			update_post_meta( $id, AI_Act_Labels::META_SIGNALS, array( 'kind' => 'generated', 'strength' => 'weak' ) );
			update_post_meta( $id, AI_Act_Labels::META_HINT, array( 'kind' => $hint ) );
			$this->assertSame( $expected, AI_Act_Labels::suggestion( $id ) );
			$this->assertFalse( AI_Act_Labels::suggestion_conflict( $id ) );
			update_post_meta( $id, AI_Act_Labels::META_SIGNALS, array( 'kind' => 'generated', 'strength' => 'strong' ) );
			$this->assertSame( 'generated', AI_Act_Labels::suggestion( $id ) );
			$this->assertTrue( AI_Act_Labels::describe( $id )['conflict'] );
		}
		update_post_meta( $id, AI_Act_Labels::META_HINT, array( 'kind' => 'generated' ) );
		$this->assertFalse( AI_Act_Labels::suggestion_conflict( $id ) );
		delete_post_meta( $id, AI_Act_Labels::META_HINT );
		update_post_meta( $id, AI_Act_Labels::META_SIGNALS, array( 'kind' => 'generated', 'strength' => 'weak' ) );
		$this->assertSame( 'generated', AI_Act_Labels::suggestion( $id ) );
	}
}
