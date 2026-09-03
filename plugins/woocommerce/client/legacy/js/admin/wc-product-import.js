/*global ajaxurl, wc_product_import_params */
;(function ( $, window ) {

	/**
	 * productImportForm handles the import process.
	 */
	var productImportForm = function( $form ) {
		this.$form              = $form;
		this.xhr                = false;
		this.mapping            = wc_product_import_params.mapping;
		this.position           = 0;
		this.file               = wc_product_import_params.file;
		this.update_existing    = wc_product_import_params.update_existing;
		this.delimiter          = wc_product_import_params.delimiter;
		this.security           = wc_product_import_params.import_nonce;
		this.import_token       = wc_product_import_params.import_token;
		this.character_encoding = wc_product_import_params.character_encoding;
		this.cleanup_error_retries = 0;

		// Number of import successes/failures.
		this.imported = 0;
		this.imported_variations = 0;
		this.failed   = 0;
		this.updated  = 0;
		this.skipped  = 0;

		// Initial state.
		this.$form.find('.woocommerce-importer-progress').val( 0 );

		this.run_import = this.run_import.bind( this );

		// Start importing.
		this.run_import();
	};

	/**
	 * Stop the progress display and show an import error.
	 */
	productImportForm.prototype.show_error = function( message ) {
		this.$form.find( 'header .spinner' ).removeClass( 'is-active' );
		this.$form.find( 'header h2' ).text( message );
		this.$form.find( 'header p, section' ).hide();
	};

	/**
	 * Continue server-directed cleanup after an import or cleanup error.
	 */
	productImportForm.prototype.retry_cleanup = function( response_data ) {
		if (
			response_data &&
			'string' === typeof response_data.position &&
			'string' === typeof response_data.import_token &&
			response_data.position === 'cleanup:' + response_data.import_token &&
			this.cleanup_error_retries < 3
		) {
			this.cleanup_error_retries++;
			this.run_import();

			return true;
		}

		return false;
	};

	/**
	 * Run the import in batches until finished.
	 */
	productImportForm.prototype.run_import = function() {
		var $this = this;

		$.ajax( {
			type: 'POST',
			url: ajaxurl,
			data: {
				action            : 'woocommerce_do_ajax_product_import',
				position          : $this.position,
				mapping           : $this.mapping,
				file              : $this.file,
				update_existing   : $this.update_existing,
				delimiter         : $this.delimiter,
				security          : $this.security,
				import_token      : $this.import_token,
				character_encoding: $this.character_encoding
			},
			dataType: 'json',
			success: function( response ) {
				$this.import_token = response.data && response.data.import_token || $this.import_token;
				if ( response.success ) {
					$this.position  = response.data.position;
					$this.cleanup_error_retries = 0;
					$this.imported += response.data.imported;
					$this.imported_variations += response.data.imported_variations;
					$this.failed   += response.data.failed;
					$this.updated  += response.data.updated;
					$this.skipped  += response.data.skipped;
					$this.$form.find('.woocommerce-importer-progress').val( response.data.percentage );

					if ( 'done' === response.data.position ) {
						var file_name = wc_product_import_params.file.split( '/' ).pop();
						window.location = response.data.url +
							'&products-imported=' +
							parseInt( $this.imported, 10 ) +
							'&products-imported-variations=' +
							parseInt( $this.imported_variations, 10 ) +
							'&products-failed=' +
							parseInt( $this.failed, 10 ) +
							'&products-updated=' +
							parseInt( $this.updated, 10 ) +
							'&products-skipped=' +
							parseInt( $this.skipped, 10 ) +
							'&file-name=' +
							file_name;
					} else {
						$this.run_import();
					}
				} else {
					$this.position = response.data && response.data.position || $this.position;
					if ( $this.retry_cleanup( response.data ) ) {
						return;
					}
					$this.show_error(
						response.data && response.data.message ?
							response.data.message :
							wc_product_import_params.import_error
					);
				}
			}
		} ).fail( function( response ) {
			var response_data = response.responseJSON && response.responseJSON.data;
			$this.import_token = response_data && response_data.import_token || $this.import_token;
			$this.position = response_data && response_data.position || $this.position;
			if ( $this.retry_cleanup( response_data ) ) {
				return;
			}
			$this.show_error(
				response_data && response_data.message ?
					response_data.message :
					wc_product_import_params.import_error
			);
		} );
	};

	/**
	 * Function to call productImportForm on jQuery selector.
	 */
	$.fn.wc_product_importer = function() {
		new productImportForm( this );
		return this;
	};

	$( '.woocommerce-importer' ).wc_product_importer();

})( jQuery, window );
