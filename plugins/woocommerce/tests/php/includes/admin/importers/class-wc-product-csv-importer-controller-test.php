<?php

declare( strict_types = 1 );

/**
 * Class WC_Product_CSV_Importer_Controller_Test
 *
 * Tests to ensure that the CSV product importer works as expected.
 */
class WC_Product_CSV_Importer_Controller_Test extends WC_Unit_Test_Case {

	/** Import run used by cleanup fixtures. */
	private const IMPORT_RUN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	/** A different import run used to verify cleanup isolation. */
	private const FOREIGN_IMPORT_RUN_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	/** Import ownership metadata key. */
	private const IMPORT_RUN_META_KEY = '_wc_product_csv_import_run_id';

	/** Import ownership expiration metadata key. */
	private const IMPORT_RUN_EXPIRATION_META_KEY = '_wc_product_csv_import_run_expires_at';

	/**
	 * Load up the importer classes since they aren't loaded by default.
	 */
	public function setUp(): void {
		parent::setUp();

		$bootstrap = WC_Unit_Tests_Bootstrap::instance();
		require_once $bootstrap->plugin_dir . '/includes/import/class-wc-product-csv-importer.php';
		require_once $bootstrap->plugin_dir . '/includes/admin/importers/class-wc-product-csv-importer-controller.php';
	}

	/**
	 * Tests that the automatic mapping is case insensitive so that columns can be matched more easily.
	 */
	public function test_that_auto_mapping_is_case_insensitive() {
		// Allow us to call the protected method.
		$class  = new ReflectionClass( WC_Product_CSV_Importer_Controller::class );
		$method = $class->getMethod( 'auto_map_columns' );
		$method->setAccessible( true );

		$controller = new WC_Product_CSV_Importer_Controller();

		// Test a few different casing formats first.
		$columns = $method->invoke( $controller, array( 'Name', 'Type' ) );
		$this->assertEquals(
			array(
				0 => 'name',
				1 => 'type',
			),
			$columns
		);
		$columns = $method->invoke( $controller, array( 'NAME', 'tYpE' ) );
		$this->assertEquals(
			array(
				0 => 'name',
				1 => 'type',
			),
			$columns
		);

		// Make sure that the case sensitivity doesn't squash the meta keys.
		$columns = $method->invoke( $controller, array( 'Meta: _TESTING', 'Meta: _testing' ) );
		$this->assertEquals(
			array(
				0 => 'meta:_TESTING',
				1 => 'meta:_testing',
			),
			$columns
		);
	}

	/**
	 * @testdox Should URL-encode request-derived values in the next step link so special characters like '+' survive the round trip.
	 */
	public function test_get_next_step_link_url_encodes_request_derived_params(): void {
		$file               = '/tmp/+dir with spaces/import.csv';
		$delimiter          = '+';
		$character_encoding = 'UTF-8+custom';

		$_REQUEST['step']               = 'upload';
		$_REQUEST['file']               = $file;
		$_REQUEST['delimiter']          = $delimiter;
		$_REQUEST['character_encoding'] = $character_encoding;

		try {
			$controller = new WC_Product_CSV_Importer_Controller();
			$url        = $controller->get_next_step_link();
		} finally {
			unset( $_REQUEST['step'], $_REQUEST['file'], $_REQUEST['delimiter'], $_REQUEST['character_encoding'] );
		}

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );

		$this->assertSame( $file, $params['file'], 'The file path should survive the query string round trip unchanged' );
		$this->assertSame( $delimiter, $params['delimiter'], 'The delimiter should survive the query string round trip unchanged' );
		$this->assertSame( $character_encoding, $params['character_encoding'], 'The character encoding should survive the query string round trip unchanged' );
	}

	/**
	 * @testdox Importer argument filters cannot replace server-owned run state.
	 */
	public function test_get_importer_preserves_reserved_run_arguments(): void {
		$expires_at = time() + DAY_IN_SECONDS;
		$filter     = static function (): array {
			return array( 'parse' => false );
		};
		add_filter( 'woocommerce_product_csv_importer_args', $filter );

		try {
			$importer = WC_Product_CSV_Importer_Controller::get_importer(
				__DIR__ . '/../../importer/sample.csv',
				array(
					'import_run_id'         => self::IMPORT_RUN_ID,
					'import_run_expires_at' => $expires_at,
				)
			);
			$params   = new ReflectionProperty( WC_Product_Importer::class, 'params' );
			$params->setAccessible( true );
			$arguments = $params->getValue( $importer );

			$this->assertSame( self::IMPORT_RUN_ID, $arguments['import_run_id'] );
			$this->assertSame( $expires_at, $arguments['import_run_expires_at'] );
		} finally {
			remove_filter( 'woocommerce_product_csv_importer_args', $filter );
		}
	}

	/**
	 * @testdox Import cleanup should delete placeholders through the product deletion lifecycle.
	 */
	public function test_cleanup_after_import_deletes_importing_products_and_related_data(): void {
		global $wpdb;

		$product = new WC_Product_Variable();
		$product->set_name( 'Import cleanup placeholder' );
		$product->set_sku( 'IMPORT-CLEANUP-PARENT' );
		$product->set_status( 'importing' );
		$product->save();
		$this->mark_for_import_cleanup( $product->get_id() );

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_sku( 'IMPORT-CLEANUP-VARIATION' );
		$variation->set_status( 'importing' );
		$variation->save();
		$this->mark_for_import_cleanup( $variation->get_id() );

		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Import cleanup category',
			)
		);
		wp_set_object_terms( $product->get_id(), array( $term_id ), 'product_cat' );

		$product_id   = $product->get_id();
		$variation_id = $variation->get_id();
		$deleted_ids  = array();
		$record_id    = static function ( $post_id ) use ( &$deleted_ids ): void {
			$deleted_ids[] = (int) $post_id;
		};
		add_action( 'delete_post', $record_id, 1 );

		$this->assertSame( 1, $this->get_product_lookup_row_count( $product_id ) );
		$this->assertSame( 1, $this->get_product_lookup_row_count( $variation_id ) );

		$this->invoke_cleanup_after_import();

		$this->assertNull( get_post( $product_id ) );
		$this->assertNull( get_post( $variation_id ) );
		$this->assertContains( $product_id, $deleted_ids );
		$this->assertContains( $variation_id, $deleted_ids );
		$this->assertSame( 0, $this->get_product_lookup_row_count( $product_id ) );
		$this->assertSame( 0, $this->get_product_lookup_row_count( $variation_id ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN ( %d, %d )", $product_id, $variation_id ) ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id = %d", $product_id ) ) );
		$this->assertSame( 0, wc_get_product_id_by_sku( 'IMPORT-CLEANUP-PARENT' ) );
		$this->assertSame( 0, wc_get_product_id_by_sku( 'IMPORT-CLEANUP-VARIATION' ) );
	}

	/**
	 * @testdox Import cleanup should delete importing variations whose parent remains.
	 */
	public function test_cleanup_after_import_deletes_remaining_importing_variations(): void {
		$parent = WC_Helper_Product::create_simple_product();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_sku( 'IMPORT-CLEANUP-REMAINING-VARIATION' );
		$variation->set_status( 'importing' );
		$variation->save();
		$this->mark_for_import_cleanup( $variation->get_id() );

		$parent_id    = $parent->get_id();
		$variation_id = $variation->get_id();

		$this->invoke_cleanup_after_import();

		$this->assertNotNull( get_post( $parent_id ) );
		$this->assertNull( get_post( $variation_id ) );
		$this->assertSame( 0, $this->get_product_lookup_row_count( $variation_id ) );
	}

	/**
	 * @testdox Import cleanup should leave unrelated orphaned data for explicit repair tools.
	 */
	public function test_cleanup_after_import_preserves_unrelated_orphans(): void {
		global $wpdb;

		$missing_post_id = 999999999;
		$variation_id    = wp_insert_post(
			array(
				'post_type'   => 'product_variation',
				'post_status' => 'publish',
				'post_parent' => $missing_post_id,
				'post_title'  => 'Unrelated orphan variation',
			)
		);
		add_post_meta( $variation_id, '_unrelated_orphan_marker', 'preserve' );

		$term_id          = self::factory()->term->create(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Unrelated orphan category',
			)
		);
		$term             = get_term( $term_id, 'product_cat' );
		$term_taxonomy_id = $term instanceof WP_Term ? $term->term_taxonomy_id : 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The test deliberately creates orphaned rows that WordPress APIs do not support.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $missing_post_id,
				'meta_key'   => '_unrelated_missing_post_marker',
				'meta_value' => 'preserve',
			)
		);
		$wpdb->insert(
			$wpdb->term_relationships,
			array(
				'object_id'        => $missing_post_id,
				'term_taxonomy_id' => $term_taxonomy_id,
				'term_order'       => 0,
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

		$this->invoke_cleanup_after_import();

		$this->assertNotNull( get_post( $variation_id ) );
		$this->assertSame( 'preserve', get_post_meta( $variation_id, '_unrelated_orphan_marker', true ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $missing_post_id ) ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id = %d", $missing_post_id ) ) );
	}

	/**
	 * @testdox Import cleanup should leave another import's placeholders and markers unchanged.
	 */
	public function test_cleanup_after_import_is_scoped_to_its_import_run(): void {
		$owned_id = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'importing',
				'post_title'  => 'Owned import placeholder',
			)
		);
		add_post_meta( $owned_id, '_original_id', '12345' );
		$this->mark_for_import_cleanup( $owned_id );

		$foreign_id = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'importing',
				'post_title'  => 'Foreign import placeholder',
			)
		);
		add_post_meta( $foreign_id, '_original_id', '67890' );
		$this->mark_for_import_cleanup( $foreign_id, self::FOREIGN_IMPORT_RUN_ID );

		$this->assertTrue( $this->invoke_cleanup_after_import() );

		$this->assertNull( get_post( $owned_id ) );
		$this->assertNotNull( get_post( $foreign_id ) );
		$this->assertSame( '67890', get_post_meta( $foreign_id, '_original_id', true ) );
		$this->assertSame( self::FOREIGN_IMPORT_RUN_ID, get_post_meta( $foreign_id, self::IMPORT_RUN_META_KEY, true ) );
	}

	/**
	 * @testdox Cleanup should fail closed when a placeholder has ambiguous ownership.
	 */
	public function test_cleanup_after_import_preserves_placeholder_with_duplicate_owners(): void {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'importing',
				'post_title'  => 'Ambiguously owned placeholder',
			)
		);
		add_post_meta( $post_id, '_original_id', '12345' );
		$this->mark_for_import_cleanup( $post_id );
		add_post_meta( $post_id, self::IMPORT_RUN_META_KEY, self::FOREIGN_IMPORT_RUN_ID );

		try {
			$this->invoke_cleanup_after_import();
			$this->fail( 'Cleanup should reject ambiguous ownership.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Import cleanup could not be completed.', $exception->getMessage() );
		}

		$this->assertNotNull( get_post( $post_id ) );
		$this->assertSame( array( self::IMPORT_RUN_ID, self::FOREIGN_IMPORT_RUN_ID ), get_post_meta( $post_id, self::IMPORT_RUN_META_KEY, false ) );
	}

	/**
	 * @testdox Cleanup should preserve a placeholder adopted before it can be claimed.
	 */
	public function test_cleanup_after_import_does_not_delete_a_concurrently_adopted_placeholder(): void {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'importing',
				'post_title'  => 'Concurrently adopted placeholder',
			)
		);
		add_post_meta( $post_id, '_original_id', '12345' );
		$this->mark_for_import_cleanup( $post_id );

		$ownership_changed  = false;
		$adopt_before_claim = static function ( $query ) use ( $post_id, &$ownership_changed ) {
			if ( ! $ownership_changed && false !== strpos( $query, 'SET expiration.meta_value = GREATEST' ) ) {
				$ownership_changed = true;
				update_post_meta( $post_id, self::IMPORT_RUN_META_KEY, self::FOREIGN_IMPORT_RUN_ID );
				update_post_meta( $post_id, self::IMPORT_RUN_EXPIRATION_META_KEY, time() + DAY_IN_SECONDS );
			}

			return $query;
		};
		add_filter( 'query', $adopt_before_claim );

		try {
			$this->assertTrue( $this->invoke_cleanup_after_import() );
		} finally {
			remove_filter( 'query', $adopt_before_claim );
		}

		$this->assertTrue( $ownership_changed );
		$this->assertNotNull( get_post( $post_id ) );
		$this->assertSame( '12345', get_post_meta( $post_id, '_original_id', true ) );
		$this->assertSame( self::FOREIGN_IMPORT_RUN_ID, get_post_meta( $post_id, self::IMPORT_RUN_META_KEY, true ) );
	}

	/**
	 * @testdox Cleanup should report a database failure while claiming a placeholder.
	 */
	public function test_cleanup_after_import_reports_a_failed_ownership_claim(): void {
		global $wpdb;

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'importing',
				'post_title'  => 'Unclaimed cleanup placeholder',
			)
		);
		$this->mark_for_import_cleanup( $post_id );

		$fail_claim = static function ( $query ) use ( $wpdb ) {
			return false !== strpos( $query, 'SET expiration.meta_value = GREATEST' ) ? "UPDATE {$wpdb->postmeta} SET missing_import_column = 1" : $query;
		};
		add_filter( 'query', $fail_claim );
		$previous_suppress_errors = $wpdb->suppress_errors();

		try {
			$this->invoke_cleanup_after_import();
			$this->fail( 'Cleanup should report an ownership lease database error.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Import cleanup could not be completed.', $exception->getMessage() );
		} finally {
			$wpdb->suppress_errors( $previous_suppress_errors );
			remove_filter( 'query', $fail_claim );
		}

		$this->assertNotNull( get_post( $post_id ) );
		$this->assertSame( self::IMPORT_RUN_ID, get_post_meta( $post_id, self::IMPORT_RUN_META_KEY, true ) );
	}

	/**
	 * @testdox Import cleanup should not cascade from an owned parent into another import's variation.
	 */
	public function test_cleanup_after_import_preserves_parent_with_foreign_variation(): void {
		$parent_id = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'importing',
				'post_title'  => 'Owned parent placeholder',
			)
		);
		add_post_meta( $parent_id, '_original_id', '12345' );
		$this->mark_for_import_cleanup( $parent_id );

		$variation_id = wp_insert_post(
			array(
				'post_parent' => $parent_id,
				'post_type'   => 'product_variation',
				'post_status' => 'importing',
				'post_title'  => 'Foreign variation placeholder',
			)
		);
		$this->mark_for_import_cleanup( $variation_id, self::FOREIGN_IMPORT_RUN_ID );

		try {
			$this->invoke_cleanup_after_import();
			$this->fail( 'Cleanup should stop before deleting a parent with a remaining variation.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Import cleanup could not be completed.', $exception->getMessage() );
		}

		$this->assertNotNull( get_post( $parent_id ) );
		$this->assertNotNull( get_post( $variation_id ) );
	}

	/**
	 * @testdox Import cleanup should clear original ID markers without deleting completed products.
	 */
	public function test_cleanup_after_import_clears_original_id_without_deleting_completed_product(): void {
		global $wpdb;

		$product    = WC_Helper_Product::create_simple_product();
		$product_id = $product->get_id();
		add_post_meta( $product_id, '_original_id', '12345' );
		$this->mark_for_import_cleanup( $product_id );

		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_original_id'", $product_id ) ) );

		$this->invoke_cleanup_after_import();

		$this->assertNotNull( get_post( $product_id ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_original_id'", $product_id ) ) );
	}

	/**
	 * @testdox Import cleanup should split large placeholder sets across requests.
	 */
	public function test_cleanup_after_import_processes_a_bounded_batch(): void {
		global $wpdb;

		$post_ids = array();
		for ( $index = 0; $index < 31; $index++ ) {
			$post_ids[] = wp_insert_post(
				array(
					'post_type'   => 'product',
					'post_status' => 'importing',
					'post_title'  => 'Import cleanup batch placeholder',
				)
			);
			$this->mark_for_import_cleanup( end( $post_ids ) );
		}
		$completed_product = WC_Helper_Product::create_simple_product();
		$completed_id      = $completed_product->get_id();
		add_post_meta( $completed_id, '_original_id', '12345' );
		$this->mark_for_import_cleanup( $completed_id );
		$post_id_limit = $this->get_import_cleanup_post_id_limit();

		$this->assertFalse( $this->invoke_cleanup_after_import( $post_id_limit ) );
		$this->assertCount( 1, array_filter( array_map( 'get_post', $post_ids ) ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_original_id'", $completed_id ) ), 'Mapping markers should survive until every cleanup batch finishes.' );

		$late_post_id = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'importing',
				'post_title'  => 'Later import placeholder',
			)
		);
		add_post_meta( $late_post_id, '_original_id', '67890' );
		$this->mark_for_import_cleanup( $late_post_id );

		$this->assertTrue( $this->invoke_cleanup_after_import( $post_id_limit ) );
		$this->assertCount( 0, array_filter( array_map( 'get_post', $post_ids ) ) );
		$this->assertNotNull( get_post( $late_post_id ), 'A placeholder created after cleanup started should remain.' );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_original_id'", $completed_id ) ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_original_id'", $late_post_id ) ), 'Mapping markers created after cleanup started should remain.' );
	}

	/**
	 * @testdox Import cleanup should preserve a placeholder published after batch selection.
	 */
	public function test_cleanup_after_import_rechecks_placeholder_status_before_deletion(): void {
		$post_ids = array(
			wp_insert_post(
				array(
					'post_type'   => 'product',
					'post_status' => 'importing',
					'post_title'  => 'First import cleanup placeholder',
				)
			),
			wp_insert_post(
				array(
					'post_type'   => 'product',
					'post_status' => 'importing',
					'post_title'  => 'Second import cleanup placeholder',
				)
			),
		);
		foreach ( $post_ids as $post_id ) {
			$this->mark_for_import_cleanup( $post_id );
		}

		$published_id              = 0;
		$publish_other_placeholder = static function ( $deleted_post_id ) use ( $post_ids, &$published_id ): void {
			if ( ! in_array( $deleted_post_id, $post_ids, true ) || $published_id ) {
				return;
			}

			$published_id = current( array_diff( $post_ids, array( $deleted_post_id ) ) );
			wp_update_post(
				array(
					'ID'          => $published_id,
					'post_status' => 'publish',
				)
			);
		};
		add_action( 'delete_post', $publish_other_placeholder, 1 );

		$this->assertTrue( $this->invoke_cleanup_after_import() );
		$this->assertNotSame( 0, $published_id );
		$this->assertNotNull( get_post( $published_id ) );
		$this->assertSame( 'publish', get_post_status( $published_id ) );
		$this->assertCount( 1, array_filter( array_map( 'get_post', $post_ids ) ) );
	}

	/**
	 * @testdox Import cleanup should fail when a placeholder cannot be deleted.
	 */
	public function test_cleanup_after_import_reports_a_vetoed_deletion(): void {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'importing',
				'post_title'  => 'Vetoed import cleanup placeholder',
			)
		);
		$this->mark_for_import_cleanup( $post_id );

		$prevent_deletion = static function ( $delete, $post ) use ( $post_id ) {
			return $post_id === $post->ID ? $post : $delete;
		};
		add_filter( 'pre_delete_post', $prevent_deletion, 10, 2 );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Import cleanup could not be completed.' );

		$this->invoke_cleanup_after_import();
	}

	/**
	 * @testdox Import tokens should reject unknown, expired, replayed, or mismatched requests.
	 */
	public function test_import_token_validation_and_single_use(): void {
		$original_user_id = get_current_user_id();
		$owner_id         = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other_user_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$context_hash     = hash( 'sha256', 'product import context' );
		$state            = array(
			'user_id'       => $owner_id,
			'context_hash'  => $context_hash,
			'run_id'        => self::IMPORT_RUN_ID,
			'phase'         => 'cleanup',
			'expires_at'    => time() + DAY_IN_SECONDS,
			'post_id_limit' => 123,
		);

		wp_set_current_user( $owner_id );
		$token = $this->create_import_token( $state );

		try {
			$this->assertSame( $state, $this->get_import_state( $token, $context_hash, 'cleanup' ) );
			$this->assert_import_token_rejected(
				fn() => $this->get_import_state( wc_rand_hash(), $context_hash, 'cleanup' )
			);
			$this->assert_import_token_rejected(
				fn() => $this->get_import_state( $token, hash( 'sha256', 'another import' ), 'cleanup' )
			);

			wp_set_current_user( $other_user_id );
			$this->assert_import_token_rejected(
				fn() => $this->get_import_state( $token, $context_hash, 'cleanup' )
			);
			wp_set_current_user( $owner_id );

			$expired_state               = $state;
			$expired_state['expires_at'] = time() - 1;
			$expired_token               = $this->create_import_token( $expired_state );
			$this->assert_import_token_rejected(
				fn() => $this->get_import_state( $expired_token, $context_hash, 'cleanup' )
			);

			$this->consume_import_token( $token, $state );
			$this->assert_import_token_rejected(
				fn() => $this->get_import_state( $token, $context_hash, 'cleanup' )
			);
		} finally {
			wp_set_current_user( $original_user_id );
		}
	}

	/**
	 * @testdox Creating a token should prune abandoned expired token state.
	 */
	public function test_create_import_token_prunes_expired_state(): void {
		$state         = array(
			'user_id'       => get_current_user_id(),
			'context_hash'  => hash( 'sha256', 'expired import context' ),
			'run_id'        => self::IMPORT_RUN_ID,
			'phase'         => 'cleanup',
			'expires_at'    => time() - ( 2 * HOUR_IN_SECONDS ),
			'post_id_limit' => 123,
		);
		$expired_token = $this->create_import_token( $state );

		$this->assertIsArray( get_option( $this->get_import_token_option_name( $expired_token ) ) );

		$state['expires_at'] = time() + DAY_IN_SECONDS;
		$active_token        = $this->create_import_token( $state );

		$this->assertFalse( get_option( $this->get_import_token_option_name( $expired_token ) ) );
		$this->consume_import_token( $active_token, $state );
	}

	/**
	 * @testdox Import requests should match the server-side file position.
	 */
	public function test_dispatch_ajax_rejects_mismatched_import_position(): void {
		$file         = __DIR__ . '/../../importer/sample.csv';
		$mapping      = array();
		$context_hash = $this->get_import_context_hash( $file, $mapping, ',', false, 'UTF-8' );
		$state        = array(
			'user_id'      => get_current_user_id(),
			'context_hash' => $context_hash,
			'run_id'       => self::IMPORT_RUN_ID,
			'phase'        => 'import',
			'expires_at'   => time() + DAY_IN_SECONDS,
			'position'     => 123,
		);
		$token        = $this->create_import_token( $state );
		$request      = array(
			'position'           => 0,
			'file'               => $file,
			'import_token'       => $token,
			'delimiter'          => ',',
			'mapping'            => $mapping,
			'update_existing'    => false,
			'character_encoding' => 'UTF-8',
			'security'           => wp_create_nonce( 'wc-product-import' ),
		);

		$response = $this->invoke_dispatch_ajax( $request );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Import could not be completed. Please start again.', $response['data']['message'] );
		$this->assertSame( $state, $this->get_import_state( $token, $context_hash, 'import' ) );
		$this->consume_import_token( $token, $state );
	}

	/**
	 * @testdox A failed import batch should return a cleanup token instead of replaying partial work.
	 */
	public function test_dispatch_ajax_returns_cleanup_token_after_import_failure(): void {
		$file           = __DIR__ . '/../../importer/import-token-rotation.csv';
		$mapping        = array(
			'from' => array( 'Name', 'SKU' ),
			'to'   => array( 'name', 'sku' ),
		);
		$context_hash   = $this->get_import_context_hash( $file, $mapping, ',', false, 'UTF-8' );
		$state          = array(
			'user_id'      => get_current_user_id(),
			'context_hash' => $context_hash,
			'run_id'       => self::IMPORT_RUN_ID,
			'phase'        => 'import',
			'expires_at'   => time() + DAY_IN_SECONDS,
			'position'     => 0,
		);
		$token          = $this->create_import_token( $state );
		$request        = array(
			'position'           => 0,
			'file'               => $file,
			'import_token'       => $token,
			'delimiter'          => ',',
			'mapping'            => $mapping,
			'update_existing'    => false,
			'character_encoding' => 'UTF-8',
			'security'           => wp_create_nonce( 'wc-product-import' ),
		);
		$placeholder_id = 0;
		$fail_import    = static function () use ( &$placeholder_id, $state ): void {
			$product = new WC_Product_Simple();
			$product->set_name( 'Partially created import placeholder' );
			$product->set_status( 'importing' );
			$product->add_meta_data( self::IMPORT_RUN_META_KEY, $state['run_id'], true );
			$product->add_meta_data( self::IMPORT_RUN_EXPIRATION_META_KEY, (string) $state['expires_at'], true );
			$placeholder_id = $product->save();

			throw new RuntimeException( 'Import batch failed.' );
		};
		add_action( 'woocommerce_product_import_before_import', $fail_import );

		try {
			$response = $this->invoke_dispatch_ajax( $request );
		} finally {
			remove_action( 'woocommerce_product_import_before_import', $fail_import );
		}

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Import batch failed.', $response['data']['message'] );
		$this->assertStringStartsWith( 'cleanup:', $response['data']['position'] );
		$this->assertNotSame( $token, $response['data']['import_token'] );
		$this->assertNotNull( get_post( $placeholder_id ) );
		$this->assert_import_token_rejected(
			fn() => $this->get_import_state( $token, $context_hash, 'import' )
		);

		$request['position']     = $response['data']['position'];
		$request['import_token'] = $response['data']['import_token'];
		$cleanup_response        = $this->invoke_dispatch_ajax( $request );

		$this->assertFalse( $cleanup_response['success'] );
		$this->assertSame( 'Import batch failed.', $cleanup_response['data']['message'] );
		$this->assertArrayNotHasKey( 'import_token', $cleanup_response['data'] );
		$this->assertNull( get_post( $placeholder_id ) );
	}

	/**
	 * @testdox Successful import batches should rotate tokens and advance server-side position.
	 */
	public function test_dispatch_ajax_rotates_token_for_each_import_batch(): void {
		$file          = __DIR__ . '/../../importer/import-token-rotation.csv';
		$mapping       = array(
			'from' => array( 'Name', 'SKU' ),
			'to'   => array( 'name', 'sku' ),
		);
		$context_hash  = $this->get_import_context_hash( $file, $mapping, ',', false, 'UTF-8' );
		$state         = array(
			'user_id'      => get_current_user_id(),
			'context_hash' => $context_hash,
			'run_id'       => self::IMPORT_RUN_ID,
			'phase'        => 'import',
			'expires_at'   => time() + DAY_IN_SECONDS,
			'position'     => 0,
		);
		$token         = $this->create_import_token( $state );
		$request       = array(
			'position'           => 0,
			'file'               => $file,
			'import_token'       => $token,
			'delimiter'          => ',',
			'mapping'            => $mapping,
			'update_existing'    => false,
			'character_encoding' => 'UTF-8',
			'security'           => wp_create_nonce( 'wc-product-import' ),
		);
		$one_row_batch = static function (): int {
			return 1;
		};
		add_filter( 'woocommerce_product_import_batch_size', $one_row_batch );

		try {
			$first_response = $this->invoke_dispatch_ajax( $request );

			$this->assertTrue( $first_response['success'] );
			$this->assertIsInt( $first_response['data']['position'] );
			$this->assertNotSame( $token, $first_response['data']['import_token'] );
			$first_state = $this->get_import_state( $first_response['data']['import_token'], $context_hash, 'import' );
			$this->assertSame( $first_response['data']['position'], $first_state['position'] );

			$replay = $this->invoke_dispatch_ajax( $request );
			$this->assertFalse( $replay['success'] );

			$request['position']     = $first_response['data']['position'];
			$request['import_token'] = $first_response['data']['import_token'];
			$second_response         = $this->invoke_dispatch_ajax( $request );

			$this->assertTrue( $second_response['success'] );
			$this->assertStringStartsWith( 'cleanup:', $second_response['data']['position'] );
			$this->assertNotSame( $first_response['data']['import_token'], $second_response['data']['import_token'] );
			$cleanup_state = $this->get_import_state( $second_response['data']['import_token'], $context_hash, 'cleanup' );
			$this->assertArrayHasKey( 'post_id_limit', $cleanup_state );

			$request['position']     = $second_response['data']['position'];
			$request['import_token'] = $second_response['data']['import_token'];
			$cleanup_response        = $this->invoke_dispatch_ajax( $request );
			$this->assertTrue( $cleanup_response['success'] );
			$this->assertSame( 'done', $cleanup_response['data']['position'] );
		} finally {
			remove_filter( 'woocommerce_product_import_batch_size', $one_row_batch );
			foreach ( array( 'TOKEN-ROTATION-ONE', 'TOKEN-ROTATION-TWO' ) as $sku ) {
				$product_id = wc_get_product_id_by_sku( $sku );
				if ( $product_id ) {
					WC_Helper_Product::delete_product( $product_id );
				}
			}
		}
	}

	/**
	 * @testdox Cleanup requests should use the server cutoff once and preserve other import runs.
	 */
	public function test_dispatch_ajax_consumes_cleanup_token_and_preserves_other_runs(): void {
		$file         = __DIR__ . '/../../importer/sample.csv';
		$mapping      = array();
		$context_hash = $this->get_import_context_hash( $file, $mapping, ',', false, 'UTF-8' );
		$owned_id     = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'importing',
				'post_title'  => 'Owned cleanup placeholder',
			)
		);
		$foreign_id   = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'importing',
				'post_title'  => 'Foreign cleanup placeholder',
			)
		);
		$this->mark_for_import_cleanup( $owned_id );
		$this->mark_for_import_cleanup( $foreign_id, self::FOREIGN_IMPORT_RUN_ID );

		$state = array(
			'user_id'       => get_current_user_id(),
			'context_hash'  => $context_hash,
			'run_id'        => self::IMPORT_RUN_ID,
			'phase'         => 'cleanup',
			'expires_at'    => time() + DAY_IN_SECONDS,
			'post_id_limit' => $this->get_import_cleanup_post_id_limit(),
		);
		$token = $this->create_import_token( $state );

		$late_id = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'importing',
				'post_title'  => 'Later cleanup placeholder',
			)
		);
		$this->mark_for_import_cleanup( $late_id );

		$request  = array(
			'position'           => 'cleanup:' . $token,
			'file'               => $file,
			'import_token'       => $token,
			'delimiter'          => ',',
			'mapping'            => $mapping,
			'update_existing'    => false,
			'character_encoding' => 'UTF-8',
			'security'           => wp_create_nonce( 'wc-product-import' ),
		);
		$response = $this->invoke_dispatch_ajax( $request );

		$this->assertTrue( $response['success'] );
		$this->assertSame( 'done', $response['data']['position'] );
		$this->assertNull( get_post( $owned_id ) );
		$this->assertNotNull( get_post( $foreign_id ) );
		$this->assertNotNull( get_post( $late_id ) );

		$replay = $this->invoke_dispatch_ajax( $request );
		$this->assertFalse( $replay['success'] );
		$this->assertSame( 'Import could not be completed. Please start again.', $replay['data']['message'] );
	}

	/**
	 * @testdox A failed cleanup request should return a fresh token that can resume cleanup.
	 */
	public function test_dispatch_ajax_returns_recovery_token_after_cleanup_failure(): void {
		$file         = __DIR__ . '/../../importer/sample.csv';
		$mapping      = array();
		$context_hash = $this->get_import_context_hash( $file, $mapping, ',', false, 'UTF-8' );
		$post_id      = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'importing',
				'post_title'  => 'Retryable cleanup placeholder',
			)
		);
		$this->mark_for_import_cleanup( $post_id );

		$state            = array(
			'user_id'       => get_current_user_id(),
			'context_hash'  => $context_hash,
			'run_id'        => self::IMPORT_RUN_ID,
			'phase'         => 'cleanup',
			'expires_at'    => time() + DAY_IN_SECONDS,
			'post_id_limit' => $this->get_import_cleanup_post_id_limit(),
		);
		$token            = $this->create_import_token( $state );
		$request          = array(
			'position'           => 'cleanup:' . $token,
			'file'               => $file,
			'import_token'       => $token,
			'delimiter'          => ',',
			'mapping'            => $mapping,
			'update_existing'    => false,
			'character_encoding' => 'UTF-8',
			'security'           => wp_create_nonce( 'wc-product-import' ),
		);
		$prevent_deletion = static function ( $delete, $post ) use ( $post_id ) {
			return $post_id === $post->ID ? $post : $delete;
		};
		add_filter( 'pre_delete_post', $prevent_deletion, 10, 2 );

		try {
			$failed_response = $this->invoke_dispatch_ajax( $request );
		} finally {
			remove_filter( 'pre_delete_post', $prevent_deletion );
		}

		$this->assertFalse( $failed_response['success'] );
		$this->assertNotSame( $token, $failed_response['data']['import_token'] );
		$this->assertSame( 'cleanup:' . $failed_response['data']['import_token'], $failed_response['data']['position'] );
		$this->assertNotNull( get_post( $post_id ) );

		$request['position']     = $failed_response['data']['position'];
		$request['import_token'] = $failed_response['data']['import_token'];
		$resumed_response        = $this->invoke_dispatch_ajax( $request );

		$this->assertTrue( $resumed_response['success'] );
		$this->assertSame( 'done', $resumed_response['data']['position'] );
		$this->assertNull( get_post( $post_id ) );
	}

	/**
	 * Invoke the import cleanup routine.
	 *
	 * @param int|null $post_id_limit Highest post ID eligible for cleanup, or null to capture it now.
	 * @param string   $import_run_id Import run identifier.
	 * @return bool Whether all importer placeholders have been removed.
	 */
	private function invoke_cleanup_after_import( ?int $post_id_limit = null, string $import_run_id = self::IMPORT_RUN_ID ): bool {
		$class  = new ReflectionClass( WC_Product_CSV_Importer_Controller::class );
		$method = $class->getMethod( 'cleanup_after_import' );
		$method->setAccessible( true );

		return (bool) $method->invoke( null, $import_run_id, $post_id_limit ?? $this->get_import_cleanup_post_id_limit(), time() + ( 2 * DAY_IN_SECONDS ) );
	}

	/**
	 * Mark a post as temporary data owned by an import run.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $run_id Import run identifier.
	 * @return void
	 */
	private function mark_for_import_cleanup( int $post_id, string $run_id = self::IMPORT_RUN_ID ): void {
		add_post_meta( $post_id, self::IMPORT_RUN_META_KEY, $run_id, true );
		add_post_meta( $post_id, self::IMPORT_RUN_EXPIRATION_META_KEY, time() + DAY_IN_SECONDS, true );
	}

	/**
	 * Create an import token through the controller's private API.
	 *
	 * @param array $state Import state.
	 * @return string Import token.
	 */
	private function create_import_token( array $state ): string {
		$method = new ReflectionMethod( WC_Product_CSV_Importer_Controller::class, 'create_import_token' );
		$method->setAccessible( true );

		return (string) $method->invoke( null, $state );
	}

	/**
	 * Read import state through the controller's private API.
	 *
	 * @param string $token Import token.
	 * @param string $context_hash Import context hash.
	 * @param string $phase Expected import phase.
	 * @return array Import state.
	 */
	private function get_import_state( string $token, string $context_hash, string $phase ): array {
		$method = new ReflectionMethod( WC_Product_CSV_Importer_Controller::class, 'get_import_state' );
		$method->setAccessible( true );

		return (array) $method->invoke( null, $token, $context_hash, $phase );
	}

	/**
	 * Consume an import token through the controller's private API.
	 *
	 * @param string $token Import token.
	 * @param array  $state Import state.
	 * @return void
	 */
	private function consume_import_token( string $token, array $state ): void {
		$method = new ReflectionMethod( WC_Product_CSV_Importer_Controller::class, 'consume_import_token' );
		$method->setAccessible( true );

		$method->invoke( null, $token, $state );
	}

	/**
	 * Get the option name for a token through the controller's private API.
	 *
	 * @param string $token Import token.
	 * @return string Option name.
	 */
	private function get_import_token_option_name( string $token ): string {
		$method = new ReflectionMethod( WC_Product_CSV_Importer_Controller::class, 'get_import_token_option_name' );
		$method->setAccessible( true );

		return (string) $method->invoke( null, $token );
	}

	/**
	 * Build an import context hash through the controller's private API.
	 *
	 * @param string $file Import file path.
	 * @param array  $mapping Column mapping.
	 * @param string $delimiter Field delimiter.
	 * @param bool   $update_existing Whether existing products may be updated.
	 * @param string $character_encoding Source character encoding.
	 * @return string Import context hash.
	 */
	private function get_import_context_hash( string $file, array $mapping, string $delimiter, bool $update_existing, string $character_encoding ): string {
		$method = new ReflectionMethod( WC_Product_CSV_Importer_Controller::class, 'get_import_context_hash' );
		$method->setAccessible( true );

		return (string) $method->invoke( null, $file, $mapping, $delimiter, $update_existing, $character_encoding );
	}

	/**
	 * Assert that import state validation rejects a request.
	 *
	 * @param callable $callback Validation call.
	 * @return void
	 */
	private function assert_import_token_rejected( callable $callback ): void {
		try {
			$callback();
			$this->fail( 'The import token should have been rejected.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Import could not be completed. Please start again.', $exception->getMessage() );
		}
	}

	/**
	 * Dispatch an importer AJAX request and decode its response.
	 *
	 * @param array $request Request values.
	 * @return array Decoded response.
	 */
	private function invoke_dispatch_ajax( array $request ): array {
		$doing_ajax  = static function (): bool {
			return true;
		};
		$die_handler = static function (): callable {
			return static function (): void {
				throw new RuntimeException( 'wp_die intercepted' );
			};
		};
		add_filter( 'wp_doing_ajax', $doing_ajax );
		add_filter( 'wp_die_ajax_handler', $die_handler );

		// phpcs:disable WordPress.Security.NonceVerification -- This helper supplies a valid nonce in the simulated request.
		$previous_post    = $_POST;
		$previous_request = $_REQUEST;
		$_POST            = $request;
		$_REQUEST         = $request;
		// phpcs:enable WordPress.Security.NonceVerification

		$buffer_level  = ob_get_level();
		$response_body = '';
		ob_start();

		try {
			WC_Product_CSV_Importer_Controller::dispatch_ajax();
			$this->fail( 'The AJAX response should terminate the request.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'wp_die intercepted', $exception->getMessage() );
			$response_body = (string) ob_get_clean();
		} finally {
			remove_filter( 'wp_doing_ajax', $doing_ajax );
			remove_filter( 'wp_die_ajax_handler', $die_handler );
			$_POST    = $previous_post;
			$_REQUEST = $previous_request;
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
		}

		$response = json_decode( $response_body, true );
		$this->assertIsArray( $response );

		return $response;
	}

	/**
	 * Capture the highest post ID eligible for an import cleanup run.
	 *
	 * @return int Post ID limit.
	 */
	private function get_import_cleanup_post_id_limit(): int {
		$class  = new ReflectionClass( WC_Product_CSV_Importer_Controller::class );
		$method = $class->getMethod( 'get_import_cleanup_post_id_limit' );
		$method->setAccessible( true );

		return (int) $method->invoke( null );
	}

	/**
	 * Get the number of product lookup rows for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return int
	 */
	private function get_product_lookup_row_count( int $product_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->wc_product_meta_lookup} WHERE product_id = %d", $product_id ) );
	}
}
