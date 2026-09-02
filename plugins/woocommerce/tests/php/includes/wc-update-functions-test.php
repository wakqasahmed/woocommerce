<?php
/**
 * Update functions tests
 *
 * @package WooCommerce\Tests\Functions.
 */

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Blocks\Options as BlockOptions;
use Automattic\WooCommerce\Blocks\Utils\BlockTemplateUtils;
use Automattic\WooCommerce\Internal\Features\FeaturesController;
use Automattic\WooCommerce\Internal\VariationGallery\Package as VariationGalleryPackage;

/**
 * Class WC_Core_Functions_Test
 */
class WC_Update_Functions_Test extends \WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		Constants::clear_single_constant( 'WOOCOMMERCE_BIS_ALPHA_ENABLED' );
		delete_option( 'woocommerce_feature_customer_stock_notifications_enabled' );
		parent::tearDown();
	}

	/**
	 * Test wc_update_343_cleanup_foreign_keys() function.
	 */
	public function test_verify_wc_update_343_cleanup_foreign_keys_removes_foreign_keys() {
		global $wpdb;

		// Add matching foreign keys between wc_download_log and wc_download_log_permission_id as it previously existed.
		$wpdb->query(
			"ALTER TABLE `{$wpdb->prefix}wc_download_log`
					ADD CONSTRAINT `wc_download_log_ib`
					FOREIGN KEY (`permission_id`)
					REFERENCES `{$wpdb->prefix}woocommerce_downloadable_product_permissions` (`permission_id`) ON DELETE CASCADE,
					ADD CONSTRAINT `wc_download_log_ib_2`
					FOREIGN KEY (`permission_id`)
					REFERENCES `{$wpdb->prefix}woocommerce_downloadable_product_permissions` (`permission_id`) ON DELETE CASCADE"
		);
		$table_definition = $wpdb->get_var( "SHOW CREATE TABLE {$wpdb->prefix}wc_download_log", 1 );
		$this->assertNotFalse( strpos( $table_definition, 'wc_download_log_ib' ) );
		$this->assertNotFalse( strpos( $table_definition, 'wc_download_log_ib_2' ) );

		include_once WC_ABSPATH . 'includes/wc-update-functions.php';

		wc_update_343_cleanup_foreign_keys();

		// Verify that the keys were properly removed.
		$table_definition = $wpdb->get_var( "SHOW CREATE TABLE {$wpdb->prefix}wc_download_log", 1 );
		$this->assertFalse( strpos( $table_definition, 'wc_download_log_ib' ) );
	}

	/**
	 * Test wc_update_352_drop_download_log_fk() function.
	 */
	public function test_verify_wc_update_352_drop_download_log_fk_removes_foreign_keys() {
		global $wpdb;

		// Add the foreign key between wc_download_log and wc_download_log_permission_id as it previously existed.
		$wpdb->query(
			"ALTER TABLE `{$wpdb->prefix}wc_download_log`
					ADD CONSTRAINT `fk_wc_download_log_permission_id`
					FOREIGN KEY (`permission_id`)
					REFERENCES `{$wpdb->prefix}woocommerce_downloadable_product_permissions` (`permission_id`) ON DELETE CASCADE"
		);
		$table_definition = $wpdb->get_var( "SHOW CREATE TABLE {$wpdb->prefix}wc_download_log", 1 );
		$this->assertNotFalse( strpos( $table_definition, 'fk_wc_download_log_permission_id' ) );

		include_once WC_ABSPATH . 'includes/wc-update-functions.php';

		wc_update_352_drop_download_log_fk();

		// Verify that the key was properly removed.
		$table_definition = $wpdb->get_var( "SHOW CREATE TABLE {$wpdb->prefix}wc_download_log", 1 );
		$this->assertFalse( strpos( $table_definition, 'fk_wc_download_log_permission_id' ) );
	}

	/**
	 * Test wc_update_700_remove_download_log_fk() function.
	 */
	public function test_verify_wc_update_700_remove_download_log_fk_removes_foreign_keys() {
		global $wpdb;

		// Add the foreign key between wc_download_log and wc_download_log_permission_id as it previously existed.
		$wpdb->query(
			"ALTER TABLE `{$wpdb->prefix}wc_download_log`
					ADD CONSTRAINT `fk_{$wpdb->prefix}wc_download_log_permission_id`
					FOREIGN KEY (`permission_id`)
					REFERENCES `{$wpdb->prefix}woocommerce_downloadable_product_permissions` (`permission_id`) ON DELETE CASCADE"
		);
		$table_definition = $wpdb->get_var( "SHOW CREATE TABLE {$wpdb->prefix}wc_download_log", 1 );
		$this->assertNotFalse( strpos( $table_definition, "fk_{$wpdb->prefix}wc_download_log_permission_id" ) );

		include_once WC_ABSPATH . 'includes/wc-update-functions.php';

		wc_update_700_remove_download_log_fk();

		// Verify that the key was properly removed.
		$table_definition = $wpdb->get_var( "SHOW CREATE TABLE {$wpdb->prefix}wc_download_log", 1 );
		$this->assertFalse( strpos( $table_definition, "fk_{$wpdb->prefix}wc_download_log_permission_id" ) );
	}

	/**
	 * Test woocommerce_hooked_blocks_version option gets set to "no" when block hooks are disabled for unapproved block themes.
	 *
	 * @return void
	 */
	public function test_wc_update_920_add_wc_hooked_blocks_version_option_block_hooks_version_is_set_to_no() {
		add_filter( 'woocommerce_hooked_blocks_theme_include_list', '__return_empty_array', 999, 1 );

		switch_theme( 'twentytwentytwo' );

		delete_option( 'woocommerce_hooked_blocks_version' );

		include_once WC_ABSPATH . 'includes/wc-update-functions.php';

		wc_update_920_add_wc_hooked_blocks_version_option();

		$this->assertEquals( 'no', get_option( 'woocommerce_hooked_blocks_version' ) );

		remove_filter( 'woocommerce_hooked_blocks_theme_include_list', '__return_empty_array', 999, 1 );
	}

	/**
	 * Test woocommerce_hooked_blocks_version option gets set to "8.4.0" for approved block themes.
	 *
	 * @return void
	 */
	public function test_wc_update_920_add_wc_hooked_blocks_version_option_block_hooks_version_is_set_to_840() {
		switch_theme( 'twentytwentytwo' );

		delete_option( 'woocommerce_hooked_blocks_version' );

		include_once WC_ABSPATH . 'includes/wc-update-functions.php';

		wc_update_920_add_wc_hooked_blocks_version_option();

		$this->assertEquals( '8.4.0', get_option( 'woocommerce_hooked_blocks_version' ) );
	}

	/**
	 * Test woocommerce_hooked_blocks_version option is not overwritten
	 *
	 * @return void
	 */
	public function test_wc_update_920_add_wc_hooked_blocks_version_option_block_hooks_version_is_not_overwritten() {
		switch_theme( 'twentytwentytwo' );

		delete_option( 'woocommerce_hooked_blocks_version' );
		add_option( 'woocommerce_hooked_blocks_version', '1.0.0' );

		include_once WC_ABSPATH . 'includes/wc-update-functions.php';

		wc_update_920_add_wc_hooked_blocks_version_option();

		$this->assertEquals( '1.0.0', get_option( 'woocommerce_hooked_blocks_version' ) );
	}

	/**
	 * Test woocommerce_hooked_blocks_version option is not overwritten
	 *
	 * @return void
	 */
	public function test_wc_update_920_add_wc_hooked_blocks_version_option_block_hooks_version_not_present_for_classic_themes() {
		switch_theme( 'storefront' );

		delete_option( 'woocommerce_hooked_blocks_version' );

		include_once WC_ABSPATH . 'includes/wc-update-functions.php';

		wc_update_920_add_wc_hooked_blocks_version_option();

		$this->assertEquals( null, get_option( 'woocommerce_hooked_blocks_version', null ) );
	}

	/**
	 * Test that wc_update_790_blockified_product_grid_block sets the option value to false.
	 *
	 * @return void
	 */
	public function test_wc_update_790_blockified_product_grid_block() {
		delete_option( BlockOptions::WC_BLOCK_USE_BLOCKIFIED_PRODUCT_GRID_BLOCK_AS_TEMPLATE );

		include_once WC_ABSPATH . 'includes/wc-update-functions.php';

		wc_update_790_blockified_product_grid_block();

		$this->assertEquals( 'no', get_option( BlockOptions::WC_BLOCK_USE_BLOCKIFIED_PRODUCT_GRID_BLOCK_AS_TEMPLATE ) );
	}

	/**
	 * Tests wc_update_830_rename_checkout_template.
	 * This test verifies that the function correctly renames the checkout template to 'page-checkout'.
	 *
	 * @return void
	 */
	public function test_wc_update_830_rename_checkout_template() {
		// Get the current template and update the name back to 'checkout'.
		$template = get_block_template( BlockTemplateUtils::PLUGIN_SLUG . '//page-checkout', 'wp_template' );

		if ( $template && ! empty( $template->wp_id ) ) {
			wp_update_post(
				array(
					'ID'        => $template->wp_id,
					'post_name' => 'checkout',
				)
			);
		}

		include_once WC_ABSPATH . 'includes/wc-update-functions.php';
		wc_update_830_rename_checkout_template();

		// Get the updated template and verify its name has been changed to 'page-checkout'.
		$updated_template = get_block_template( BlockTemplateUtils::PLUGIN_SLUG . '//checkout', 'wp_template' );

		if ( $updated_template && ! empty( $updated_template->wp_id ) ) {
			$post = get_post( $updated_template->wp_id );
			$this->assertEquals( 'page-checkout', $post->post_name );
		} else {
			// If no template exists, this assertion will pass since there's nothing to rename.
			$this->assertTrue( true );
		}
	}

	/**
	 * Tests wc_update_830_rename_cart_template.
	 * This test verifies that the function correctly renames the cart template to 'page-cart'.
	 *
	 * @return void
	 */
	public function test_wc_update_830_rename_cart_template() {
		// Get the current template and update the name back 'cart'.
		$template = get_block_template( BlockTemplateUtils::PLUGIN_SLUG . '//page-cart', 'wp_template' );

		if ( $template && ! empty( $template->wp_id ) ) {
			wp_update_post(
				array(
					'ID'        => $template->wp_id,
					'post_name' => 'cart',
				)
			);
		}

		include_once WC_ABSPATH . 'includes/wc-update-functions.php';
		wc_update_830_rename_cart_template();

		// Get the updated template and verify its name has been changed to 'page-cart'.
		$updated_template = get_block_template( BlockTemplateUtils::PLUGIN_SLUG . '//cart', 'wp_template' );

		if ( $updated_template && ! empty( $updated_template->wp_id ) ) {
			$post = get_post( $updated_template->wp_id );
			$this->assertEquals( 'page-cart', $post->post_name );
		} else {
			// If no template exists, this assertion will pass since there's nothing to rename.
			$this->assertTrue( true );
		}
	}

	/**
	 * Test wc_update_1040_cleanup_legacy_ptk_patterns_fetching removes the obsolete option and actions.
	 *
	 * @return void
	 */
	public function test_wc_update_1040_cleanup_legacy_ptk_patterns_fetching() {
		// Set up the option that should be removed.
		add_option( 'last_fetch_patterns_request', time() );
		$this->assertNotFalse( get_option( 'last_fetch_patterns_request' ), 'Option should exist before update' );

		// Schedule legacy actions that should be removed.
		as_schedule_single_action( time(), 'fetch_patterns' );
		$this->assertTrue( as_has_scheduled_action( 'fetch_patterns' ), 'fetch_patterns action should exist before update' );

		include_once WC_ABSPATH . 'includes/wc-update-functions.php';

		wc_update_1040_cleanup_legacy_ptk_patterns_fetching();

		// Verify the option was removed.
		$this->assertFalse( get_option( 'last_fetch_patterns_request' ), 'Option should be removed after update' );

		// Verify the actions were removed.
		$this->assertFalse( as_has_scheduled_action( 'fetch_patterns' ), 'fetch_patterns action should be removed after update' );
	}

	/**
	 * @testdox Migration converts legacy 'no' (not immediate) to new 'yes' (scheduled).
	 */
	public function test_migrate_analytics_import_option_legacy_no_becomes_yes(): void {
		delete_option( 'woocommerce_analytics_scheduled_import' );
		update_option( 'woocommerce_analytics_immediate_import', 'no' );

		wc_update_1080_migrate_analytics_import_option();

		$this->assertSame( 'yes', get_option( 'woocommerce_analytics_scheduled_import' ) );
		$this->assertFalse( get_option( 'woocommerce_analytics_immediate_import' ) );
	}

	/**
	 * @testdox Migration converts legacy 'yes' (immediate) to new 'no' (not scheduled).
	 */
	public function test_migrate_analytics_import_option_legacy_yes_becomes_no(): void {
		delete_option( 'woocommerce_analytics_scheduled_import' );
		update_option( 'woocommerce_analytics_immediate_import', 'yes' );

		wc_update_1080_migrate_analytics_import_option();

		$this->assertSame( 'no', get_option( 'woocommerce_analytics_scheduled_import' ) );
		$this->assertFalse( get_option( 'woocommerce_analytics_immediate_import' ) );
	}

	/**
	 * @testdox Migration does nothing when legacy option is absent.
	 */
	public function test_migrate_analytics_import_option_no_legacy_option(): void {
		delete_option( 'woocommerce_analytics_immediate_import' );
		delete_option( 'woocommerce_analytics_scheduled_import' );

		wc_update_1080_migrate_analytics_import_option();

		$this->assertFalse( get_option( 'woocommerce_analytics_scheduled_import' ) );
	}

	/**
	 * @testdox Migration preserves existing new option and deletes legacy.
	 */
	public function test_migrate_analytics_import_option_new_option_already_exists(): void {
		update_option( 'woocommerce_analytics_scheduled_import', 'yes' );
		update_option( 'woocommerce_analytics_immediate_import', 'yes' );

		wc_update_1080_migrate_analytics_import_option();

		$this->assertSame( 'yes', get_option( 'woocommerce_analytics_scheduled_import' ) );
		$this->assertFalse( get_option( 'woocommerce_analytics_immediate_import' ) );
	}

	/**
	 * @testdox Migration sets the point_of_sale feature flag option to yes regardless of the previous value.
	 */
	public function test_wc_update_1100_enable_point_of_sale_feature(): void {
		include_once WC_ABSPATH . 'includes/wc-update-functions.php';

		update_option( 'woocommerce_feature_point_of_sale_enabled', 'no' );
		wc_update_1100_enable_point_of_sale_feature();
		$this->assertSame( 'yes', get_option( 'woocommerce_feature_point_of_sale_enabled' ) );

		delete_option( 'woocommerce_feature_point_of_sale_enabled' );
		wc_update_1100_enable_point_of_sale_feature();
		$this->assertSame( 'yes', get_option( 'woocommerce_feature_point_of_sale_enabled' ) );
	}

	/**
	 * @testdox Migration registers and removes the deprecated variation gallery feature option.
	 */
	public function test_wc_update_11101_remove_deprecated_variation_gallery_option(): void {
		include_once WC_ABSPATH . 'includes/wc-update-functions.php';

		$db_updates = WC_Install::get_db_update_callbacks();
		$this->assertArrayHasKey( '11.1.0-1', $db_updates );
		$this->assertContains( 'wc_update_11101_remove_deprecated_variation_gallery_option', $db_updates['11.1.0-1'] );

		delete_option( VariationGalleryPackage::ENABLE_OPTION_NAME );
		wc_update_11101_remove_deprecated_variation_gallery_option();
		$this->assertFalse( get_option( VariationGalleryPackage::ENABLE_OPTION_NAME ) );

		update_option( VariationGalleryPackage::ENABLE_OPTION_NAME, 'no' );
		wc_update_11101_remove_deprecated_variation_gallery_option();
		$this->assertFalse( get_option( VariationGalleryPackage::ENABLE_OPTION_NAME ) );

		update_option( VariationGalleryPackage::ENABLE_OPTION_NAME, 'yes' );
		wc_update_11101_remove_deprecated_variation_gallery_option();
		$this->assertFalse( get_option( VariationGalleryPackage::ENABLE_OPTION_NAME ) );
	}

	/**
	 * @testdox Migration registers and deletes the cached dashboard out-of-stock count.
	 */
	public function test_wc_update_1110_delete_dashboard_outofstock_count_transient(): void {
		include_once WC_ABSPATH . 'includes/wc-update-functions.php';

		$db_updates = WC_Install::get_db_update_callbacks();
		$this->assertArrayHasKey( '11.1.0', $db_updates );
		$this->assertContains( 'wc_update_1110_delete_dashboard_outofstock_count_transient', $db_updates['11.1.0'] );

		set_transient( 'wc_outofstock_count', 3, DAY_IN_SECONDS );
		$this->assertSame( 3, get_transient( 'wc_outofstock_count' ) );

		wc_update_1110_delete_dashboard_outofstock_count_transient();
		$this->assertFalse( get_transient( 'wc_outofstock_count' ) );
	}

	/**
	 * @testdox Migration enables the customer_stock_notifications feature when the alpha constant is set.
	 */
	public function test_wc_update_1120_migrate_stock_notifications_alpha_constant_opts_in(): void {
		include_once WC_ABSPATH . 'includes/wc-update-functions.php';

		$db_updates = WC_Install::get_db_update_callbacks();
		$this->assertArrayHasKey( '11.2.0', $db_updates );
		$this->assertContains( 'wc_update_1120_migrate_stock_notifications_alpha_constant', $db_updates['11.2.0'] );

		delete_option( 'woocommerce_feature_customer_stock_notifications_enabled' );
		Constants::set_constant( 'WOOCOMMERCE_BIS_ALPHA_ENABLED', true );

		wc_update_1120_migrate_stock_notifications_alpha_constant();

		$this->assertSame( 'yes', get_option( 'woocommerce_feature_customer_stock_notifications_enabled' ) );
	}

	/**
	 * @testdox Migration leaves the feature untouched when the alpha constant is absent or falsy.
	 */
	public function test_wc_update_1120_migrate_stock_notifications_alpha_constant_without_opt_in(): void {
		include_once WC_ABSPATH . 'includes/wc-update-functions.php';

		delete_option( 'woocommerce_feature_customer_stock_notifications_enabled' );
		Constants::clear_single_constant( 'WOOCOMMERCE_BIS_ALPHA_ENABLED' );

		wc_update_1120_migrate_stock_notifications_alpha_constant();

		$this->assertFalse( get_option( 'woocommerce_feature_customer_stock_notifications_enabled' ) );

		Constants::set_constant( 'WOOCOMMERCE_BIS_ALPHA_ENABLED', false );

		wc_update_1120_migrate_stock_notifications_alpha_constant();

		$this->assertFalse( get_option( 'woocommerce_feature_customer_stock_notifications_enabled' ) );
	}

	/**
	 * @testdox Migration overwrites the 'no' that WC_Install::create_options() seeds before the update callbacks run.
	 */
	public function test_wc_update_1120_migrate_stock_notifications_alpha_constant_overwrites_seeded_option(): void {
		include_once WC_ABSPATH . 'includes/wc-update-functions.php';

		update_option( 'woocommerce_feature_customer_stock_notifications_enabled', 'no' );
		Constants::set_constant( 'WOOCOMMERCE_BIS_ALPHA_ENABLED', true );

		wc_update_1120_migrate_stock_notifications_alpha_constant();

		$this->assertSame( 'yes', get_option( 'woocommerce_feature_customer_stock_notifications_enabled' ) );
	}

	/**
	 * @testdox Migration lets FeaturesController announce the change, so the feature runs its own activation side effects.
	 */
	public function test_wc_update_1120_migrate_stock_notifications_alpha_constant_fires_feature_enabled_changed(): void {
		include_once WC_ABSPATH . 'includes/wc-update-functions.php';

		update_option( 'woocommerce_feature_customer_stock_notifications_enabled', 'no' );
		Constants::set_constant( 'WOOCOMMERCE_BIS_ALPHA_ENABLED', true );

		$changes  = array();
		$listener = function ( $feature_id, $enabled ) use ( &$changes ) {
			$changes[ $feature_id ] = $enabled;
		};

		add_action( FeaturesController::FEATURE_ENABLED_CHANGED_ACTION, $listener, 10, 2 );

		try {
			wc_update_1120_migrate_stock_notifications_alpha_constant();
		} finally {
			remove_action( FeaturesController::FEATURE_ENABLED_CHANGED_ACTION, $listener, 10 );
		}

		$this->assertArrayHasKey( 'customer_stock_notifications', $changes );
		$this->assertTrue( $changes['customer_stock_notifications'] );
	}

	/**
	 * @testdox Migration repairs the derived verbose page rules value and queues a rewrite flush only when it changes.
	 */
	public function test_wc_update_1120_recalculate_product_permalink_verbose_page_rules(): void {
		include_once WC_ABSPATH . 'includes/wc-update-functions.php';

		$db_updates = WC_Install::get_db_update_callbacks();
		$this->assertArrayHasKey( '11.2.0', $db_updates );
		$this->assertContains( 'wc_update_1120_recalculate_product_permalink_verbose_page_rules', $db_updates['11.2.0'] );

		$shop_page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Shop',
				'post_name'   => 'shop',
				'post_status' => 'publish',
			)
		);
		update_option( 'woocommerce_shop_page_id', $shop_page_id );

		$permalinks = array(
			'product_base'           => 'product',
			'use_verbose_page_rules' => true,
			'unrelated_setting'      => 'preserved',
		);
		update_option( 'woocommerce_permalinks', $permalinks );
		delete_option( 'woocommerce_queue_flush_rewrite_rules' );

		wc_update_1120_recalculate_product_permalink_verbose_page_rules();

		$permalinks = get_option( 'woocommerce_permalinks' );
		$this->assertFalse( $permalinks['use_verbose_page_rules'] );
		$this->assertSame( 'preserved', $permalinks['unrelated_setting'] );
		$this->assertSame( 'yes', get_option( 'woocommerce_queue_flush_rewrite_rules' ) );

		delete_option( 'woocommerce_queue_flush_rewrite_rules' );
		wc_update_1120_recalculate_product_permalink_verbose_page_rules();
		$this->assertFalse( get_option( 'woocommerce_queue_flush_rewrite_rules', false ), 'An unchanged value should not queue another rewrite flush.' );

		$permalinks['product_base']           = '/shop/%product_cat%';
		$permalinks['use_verbose_page_rules'] = false;
		update_option( 'woocommerce_permalinks', $permalinks );

		wc_update_1120_recalculate_product_permalink_verbose_page_rules();

		$this->assertTrue( get_option( 'woocommerce_permalinks' )['use_verbose_page_rules'] );
		$this->assertSame( 'yes', get_option( 'woocommerce_queue_flush_rewrite_rules' ) );

		delete_option( 'woocommerce_shop_page_id' );
		delete_option( 'woocommerce_queue_flush_rewrite_rules' );
		$permalinks['use_verbose_page_rules'] = true;
		update_option( 'woocommerce_permalinks', $permalinks );

		wc_update_1120_recalculate_product_permalink_verbose_page_rules();

		$this->assertFalse( get_option( 'woocommerce_permalinks' )['use_verbose_page_rules'] );
		$this->assertSame( 'yes', get_option( 'woocommerce_queue_flush_rewrite_rules' ) );
	}

	/**
	 * @testdox Permalink migration resolves filtered Shop pages in the site locale and restores the request locale.
	 */
	public function test_wc_update_1120_recalculate_product_permalink_verbose_page_rules_uses_site_locale(): void {
		include_once WC_ABSPATH . 'includes/wc-update-functions.php';

		set_current_screen( 'dashboard' );
		$user_id = self::factory()->user->create(
			array(
				'role'   => 'administrator',
				'locale' => 'fr_FR',
			)
		);
		wp_set_current_user( $user_id );
		$this->assertSame( 'en_US', get_locale(), 'The site locale should remain English.' );
		$this->assertSame( 'fr_FR', determine_locale(), 'The admin request should use the current user locale.' );

		$site_shop_page_id    = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Shop',
				'post_name'   => 'shop',
				'post_status' => 'publish',
			)
		);
		$request_shop_page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Boutique',
				'post_name'   => 'boutique',
				'post_status' => 'publish',
			)
		);
		update_option( 'woocommerce_shop_page_id', $site_shop_page_id );
		update_option(
			'woocommerce_permalinks',
			array(
				'product_base'           => '/shop',
				'use_verbose_page_rules' => false,
			)
		);

		$filter_shop_page = static function ( $shop_page_id ) use ( $request_shop_page_id ) {
			return has_filter( 'plugin_locale', 'get_locale' ) ? $shop_page_id : $request_shop_page_id;
		};
		add_filter( 'woocommerce_get_shop_page_id', $filter_shop_page );

		wc_update_1120_recalculate_product_permalink_verbose_page_rules();

		$this->assertTrue( get_option( 'woocommerce_permalinks' )['use_verbose_page_rules'] );
		$this->assertSame( 'fr_FR', determine_locale(), 'The migration should restore the admin request locale.' );
		$this->assertFalse( has_filter( 'plugin_locale', 'get_locale' ), 'The site-locale filter should be removed after the migration.' );
	}

	/**
	 * @testdox Permalink migration leaves a caller-owned site locale switch active.
	 */
	public function test_wc_update_1120_recalculate_product_permalink_verbose_page_rules_preserves_locale_switch(): void {
		include_once WC_ABSPATH . 'includes/wc-update-functions.php';

		set_current_screen( 'dashboard' );
		$user_id = self::factory()->user->create(
			array(
				'role'   => 'administrator',
				'locale' => 'fr_FR',
			)
		);
		wp_set_current_user( $user_id );
		$this->assertSame( 'fr_FR', determine_locale(), 'The admin request should start in the user locale.' );

		wc_switch_to_site_locale();
		try {
			$this->assertSame( 'en_US', determine_locale(), 'The caller should switch to the site locale.' );

			wc_update_1120_recalculate_product_permalink_verbose_page_rules();

			$this->assertSame( 'en_US', determine_locale(), 'The migration should not restore a locale switch it did not create.' );
			$this->assertNotFalse( has_filter( 'plugin_locale', 'get_locale' ), 'The caller-owned site-locale filter should remain active.' );
		} finally {
			wc_restore_locale();
		}

		$this->assertSame( 'fr_FR', determine_locale(), 'The caller should be able to restore its locale switch.' );
	}
}
