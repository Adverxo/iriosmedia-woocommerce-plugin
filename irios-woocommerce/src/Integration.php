<?php

namespace Irios\WooCommerce;

/**
 * Settings screen in WooCommerce → Settings → Integration → Irios Media. Only loaded when WooCommerce is active.
 */
final class Integration extends \WC_Integration {

	public function __construct() {
		$this->id                 = 'irios';
		$this->method_title       = 'IRIOS Media';
		$this->method_description = sprintf(
			/* translators: %s: plugin version */
			__( 'IRIOS Media visitor events and identity and, optionally, Sponsored Products, display ads and push notifications. Version %s.', 'irios-woocommerce' ),
			IRIOS_WC_VERSION
		);

		$this->init_form_fields();
		$this->init_settings();

		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/** Fields of the Sponsored Products section: hidden while the enable checkbox is unchecked. */
	private const SPONSORED_FIELDS = array(
		'sponsored_label',
		'keep_single_result',
	);

	/** Fields of the Display section: hidden while the enable checkbox is unchecked. */
	private const DISPLAY_FIELDS = array( 'display_in_grid_after' );

	/** Fields of the Push section: hidden while the enable checkbox is unchecked. */
	private const PUSH_FIELDS = array( 'push_ad_unit', 'push_auth', 'push_trigger', 'push_after_pages', 'push_repeat_days' );

	public function init_form_fields(): void {
		$defaults          = Settings::defaults();
		$this->form_fields = array(
			// ---- General: connection with IRIOS Media and consent ------------------------------------------------
			'section_general'          => array(
				'title' => __( 'General', 'irios-woocommerce' ),
				'type'  => 'title',
			),
			'enabled'                  => array(
				'title'       => __( 'Enable IRIOS Media', 'irios-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable IRIOS Media', 'irios-woocommerce' ),
				'description' => __( 'Sends the shopper events to IRIOS Media (only with consent) and shows its ads. Without it, or without the IRIOS Media domain and the Store ID, the store stays exactly as without the integration.', 'irios-woocommerce' ),
				'default'     => $defaults['enabled'],
			),
			'js_domain'                => array(
				'title'       => __( 'IRIOS Media domain', 'irios-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Without https:// or path, e.g. example.iriosmedia.online. irios-tracker.js and irios-loader.js are loaded from it.', 'irios-woocommerce' ),
				'default'     => $defaults['js_domain'],
			),
			'store_id'                 => array(
				'title'       => __( 'Store ID', 'irios-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Numeric ID of the store in IRIOS Media.', 'irios-woocommerce' ),
				'default'     => $defaults['store_id'],
			),
			'consent_mode'             => array(
				'title'       => __( 'Consent', 'irios-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					Settings::CONSENT_WP_CONSENT_API => __( 'WP Consent API (recommended)', 'irios-woocommerce' ),
					Settings::CONSENT_ASESOR_COOKIES => __( 'Asesor de Cookies RGPD (webartesanal.com banner)', 'irios-woocommerce' ),
					Settings::CONSENT_ALWAYS         => __( 'Always (the store handles consent another way)', 'irios-woocommerce' ),
				),
				'description' => __( 'Until the visitor accepts marketing cookies, only contextual ads are requested: no identifiers, no IRIOS Media cookies and no events.', 'irios-woocommerce' ),
				'default'     => $defaults['consent_mode'],
			),

			// ---- Sponsored Products (optional) ---------------------------------------------------------------------
			'section_sponsored'        => array(
				'title'       => __( 'Sponsored Products', 'irios-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Product ads in the search results, with the same card as the other products and the ad label.', 'irios-woocommerce' ),
			),
			'sponsored_enabled'        => array(
				'title'   => __( 'Enable', 'irios-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show Sponsored Products in the search results', 'irios-woocommerce' ),
				'default' => $defaults['sponsored_enabled'],
			),
			'sponsored_slots'          => array(
				'title'   => __( 'Search results positions', 'irios-woocommerce' ),
				'type'    => 'irios_slots',
				'default' => $defaults['sponsored_slots'],
			),
			'sponsored_label'          => array(
				'title'       => __( 'Sponsored label', 'irios-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Text added to the sponsored cards. For advertising transparency, do not leave it empty.', 'irios-woocommerce' ),
				'default'     => __( 'Sponsored', 'irios-woocommerce' ),
			),
			'keep_single_result'       => array(
				'title'       => __( 'Single-result search', 'irios-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Show the results page instead of going straight to the product page', 'irios-woocommerce' ),
				'description' => __( 'Without a results page, Sponsored Products cannot be shown.', 'irios-woocommerce' ),
				'default'     => $defaults['keep_single_result'],
			),

			// ---- Display: image and video (optional) -------------------------------------------------------------
			'section_banners'          => array(
				'title'       => __( 'Display (Image and Video)', 'irios-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Image and video ads in placements of the site: in an automatic position or with [irios_display tag="TAG"] wherever you want to show it (a page, the home page, a WPBakery element, a block, a widget or a category description). When disabled, the shortcodes show nothing.', 'irios-woocommerce' ),
			),
			'banners_enabled'          => array(
				'title'   => __( 'Enable', 'irios-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show IRIOS Media display ads', 'irios-woocommerce' ),
				'default' => $defaults['banners_enabled'],
			),
			'banners'                  => array(
				'title'   => __( 'Placements', 'irios-woocommerce' ),
				'type'    => 'irios_banners',
				'default' => $defaults['banners'],
			),
			'display_in_grid_after'    => array(
				'title'       => __( 'Grid: after product', 'irios-woocommerce' ),
				'type'        => 'number',
				'description' => __( 'For the "Category: in grid" position: the placement goes after this product of the listing, the full width of the grid.', 'irios-woocommerce' ),
				'default'     => $defaults['display_in_grid_after'],
				'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
			),

			// ---- Push notifications (optional) ---------------------------------------------------------------------
			'section_push'             => array(
				'title'       => __( 'Push Notifications', 'irios-woocommerce' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: service worker URL */
					__( 'Brand notifications for the shoppers who subscribe in their browser, only with consent. The service worker is served at %s: in IRIOS Media, the push ad unit must point to it.', 'irios-woocommerce' ),
					Push::url()
				),
			),
			'push_enabled'             => array(
				'title'   => __( 'Enable', 'irios-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Ask shoppers to subscribe to IRIOS Media push notifications', 'irios-woocommerce' ),
				'default' => $defaults['push_enabled'],
			),
			'push_ad_unit'             => array(
				'title'   => __( 'Ad unit ID', 'irios-woocommerce' ),
				'type'    => 'text',
				'default' => $defaults['push_ad_unit'],
			),
			'push_auth'                => array(
				'title'   => __( 'Ad unit auth', 'irios-woocommerce' ),
				'type'    => 'text',
				'default' => $defaults['push_auth'],
			),
			'push_trigger'             => array(
				'title'       => __( 'When to ask for permission', 'irios-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					Settings::PUSH_AFTER_PURCHASE => __( 'After a purchase', 'irios-woocommerce' ),
					Settings::PUSH_AFTER_PAGES    => __( 'After a number of pages', 'irios-woocommerce' ),
				),
				'description' => __( 'The browser permission prompt is shown only with consent: after a purchase (on the order received page) or after a number of pages of the visit.', 'irios-woocommerce' ),
				'default'     => $defaults['push_trigger'],
			),
			'push_after_pages'         => array(
				'title'             => __( 'Number of pages', 'irios-woocommerce' ),
				'type'              => 'number',
				'default'           => $defaults['push_after_pages'],
				'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
			),
			'push_repeat_days'         => array(
				'title'             => __( 'Ask again after (days)', 'irios-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'If the shopper did not answer the browser prompt, it is not shown again until these days have passed (0: on every visit).', 'irios-woocommerce' ),
				'default'           => $defaults['push_repeat_days'],
				'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
			),
		);

		foreach ( array( 'irios-sponsored-field' => self::SPONSORED_FIELDS, 'irios-banners-field' => self::DISPLAY_FIELDS, 'irios-push-field' => self::PUSH_FIELDS ) as $class => $keys ) {
			foreach ( $keys as $key ) {
				$this->form_fields[ $key ]['class'] = trim( ( $this->form_fields[ $key ]['class'] ?? '' ) . ' ' . $class );
			}
		}
	}

	/** Renders the settings and hides each optional section (Sponsored Products, Banners) until it is enabled. */
	public function admin_options(): void {
		echo Compatibility::warning_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in warning_html()
		parent::admin_options();
		wc_enqueue_js(
			"( function ( $ ) {
				[ [ '#woocommerce_irios_sponsored_enabled', '.irios-sponsored-field' ], [ '#woocommerce_irios_banners_enabled', '.irios-banners-field' ], [ '#woocommerce_irios_push_enabled', '.irios-push-field' ] ]
					.forEach( function ( pair ) {
						var toggle = $( pair[0] );
						var rows = $( pair[1] ).closest( 'tr' );
						var sync = function () { rows.toggle( toggle.is( ':checked' ) ); };
						toggle.on( 'change', sync );
						sync();
					} );
				// \"Number of pages\" only with \"After a number of pages\"
				var trigger = $( '#woocommerce_irios_push_trigger' );
				var pages = $( '#woocommerce_irios_push_after_pages' ).closest( 'tr' );
				var syncPages = function () {
					pages.toggle( $( '#woocommerce_irios_push_enabled' ).is( ':checked' ) && trigger.val() === 'after_pages' );
				};
				trigger.add( '#woocommerce_irios_push_enabled' ).on( 'change', syncPages );
				syncPages();
				$( document ).on( 'click', '.irios-rows-add', function ( e ) {
					e.preventDefault();
					var body = $( this ).closest( '.irios-rows' ).find( 'tbody' );
					var index = 'n' + Date.now() + Math.floor( Math.random() * 1000 );
					var row = body.find( 'tr.irios-row-template' ).clone().removeClass( 'irios-row-template' ).show();
					row.find( 'input, select' ).each( function () { this.name = this.name.replace( '[__i__]', '[' + index + ']' ); } );
					body.append( row );
				} );
				$( document ).on( 'click', '.irios-rows-remove', function ( e ) {
					e.preventDefault();
					var form = $( this ).closest( 'form' );
					$( this ).closest( 'tr' ).remove();
					// WooCommerce enables \"Save changes\" on a change event; removing a row does not fire one.
					form.find( ':input' ).first().trigger( 'change' );
				} );
			} )( jQuery );"
		);
	}

	/**
	 * Display placements table (tag, format, device, ad unit id and auth, position), with rows that can be added and
	 * removed. Submitted as woocommerce_irios_banners[n][tag|format|device|id|auth|position].
	 *
	 * @param string $key @param array<string,mixed> $data
	 */
	public function generate_irios_banners_html( $key, $data ): string {
		$field   = $this->get_field_key( $key );
		$banners = ( new Settings( array( 'banners' => (string) $this->get_option( $key, '[]' ) ) ) )->banners();
		$select  = static function ( string $index, string $name, array $options, string $value ) use ( $field ): string {
			$html = '';
			foreach ( $options as $option => $label ) {
				$html .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( (string) $option ), selected( $value, (string) $option, false ), esc_html( $label ) );
			}
			return sprintf( '<select name="%s[%s][%s]">%s</select>', esc_attr( $field ), esc_attr( $index ), esc_attr( $name ), $html );
		};
		$cells   = static function ( string $index, array $banner ) use ( $field, $select ): array {
			$positions = array(
				Settings::POSITION_NONE                       => __( 'Manual only', 'irios-woocommerce' ),
				Settings::POSITION_CATEGORY_SIDEBAR           => __( 'Category: sidebar', 'irios-woocommerce' ),
				Settings::POSITION_CATEGORY_IN_GRID           => __( 'Category: in grid', 'irios-woocommerce' ),
				Settings::POSITION_CATEGORY_AFTER_PRODUCTS    => __( 'Category: bottom', 'irios-woocommerce' ),
				Settings::POSITION_PRODUCT_BEFORE_DESCRIPTION => __( 'Product: description', 'irios-woocommerce' ),
				Settings::POSITION_PRODUCT_BEFORE_RELATED     => __( 'Product: related', 'irios-woocommerce' ),
			);
			return array(
				self::text_input( $field, $index, 'tag', $banner['tag'] ?? '', __( 'home', 'irios-woocommerce' ) ),
				$select( $index, 'format', array( 'image' => __( 'Image', 'irios-woocommerce' ), 'video' => __( 'Video', 'irios-woocommerce' ) ), (string) ( $banner['format'] ?? 'image' ) ),
				$select( $index, 'device', array( 'all' => __( 'All', 'irios-woocommerce' ), 'desktop' => __( 'Desktop', 'irios-woocommerce' ), 'mobile' => __( 'Mobile', 'irios-woocommerce' ) ), (string) ( $banner['device'] ?? 'all' ) ),
				self::text_input( $field, $index, 'id', isset( $banner['id'] ) ? (string) $banner['id'] : '', '1234' ),
				self::text_input( $field, $index, 'auth', $banner['auth'] ?? '', __( 'ad unit auth', 'irios-woocommerce' ) ),
				$select( $index, 'position', $positions, (string) ( $banner['position'] ?? '' ) ),
			);
		};
		return self::rows_table(
			$data['title'],
			'irios-banners irios-banners-field',
			array(
				array( __( 'Tag', 'irios-woocommerce' ), 14 ),
				array( __( 'Format', 'irios-woocommerce' ), 11 ),
				array( __( 'Device', 'irios-woocommerce' ), 12 ),
				array( __( 'Ad unit ID', 'irios-woocommerce' ), 11 ),
				array( __( 'Auth', 'irios-woocommerce' ), 17 ),
				array( __( 'Position', 'irios-woocommerce' ), 29 ),
			),
			$cells,
			$banners,
			__( 'Add placement', 'irios-woocommerce' ),
			__( 'Tag in lowercase, without spaces (e.g. home, category-sticks). Format: that of the ad unit in IRIOS Media. Device: the same spot can have one row per device (desktop from 768 px wide). Position: Manual only, with [irios_display tag="TAG"] anywhere on the site (also the home page), or automatic: category sidebar, inside the category grid, after the category products, before the product description or before the related products.', 'irios-woocommerce' )
		);
	}

	/**
	 * Sponsored Products slots table (position in the grid, ad unit id and auth). Submitted as
	 * woocommerce_irios_sponsored_slots[n][position|id|auth]. With no saved slots it shows those from the legacy
	 * configuration (one ad unit in the first positions), which are stored as slots on save.
	 *
	 * @param string $key @param array<string,mixed> $data
	 */
	public function generate_irios_slots_html( $key, $data ): string {
		$field = $this->get_field_key( $key );
		$slots = ( new Settings( $this->settings ) )->sponsored_slots();
		$cells = static function ( string $index, array $slot ) use ( $field ): array {
			return array(
				sprintf(
					'<input type="number" min="1" max="%d" step="1" name="%s[%s][position]" value="%s" placeholder="1">',
					Settings::MAX_SLOTS,
					esc_attr( $field ),
					esc_attr( $index ),
					esc_attr( isset( $slot['position'] ) ? (string) $slot['position'] : '' )
				),
				self::text_input( $field, $index, 'id', isset( $slot['id'] ) ? (string) $slot['id'] : '', '1234' ),
				self::text_input( $field, $index, 'auth', $slot['auth'] ?? '', __( 'ad unit auth', 'irios-woocommerce' ) ),
			);
		};
		return self::rows_table(
			$data['title'],
			'irios-slots irios-sponsored-field',
			array(
				array( __( 'Position', 'irios-woocommerce' ), 16 ),
				array( __( 'Ad unit ID', 'irios-woocommerce' ), 22 ),
				array( __( 'Auth', 'irios-woocommerce' ), 56 ),
			),
			$cells,
			$slots,
			__( 'Add position', 'irios-woocommerce' ),
			__( 'One ad per row. The position is the place in the results grid: 1 is top left, counted in reading order (left to right, row by row). The same ad unit can take several positions. In the live search dropdown there is at most one ad, at the top: the one of the lowest position.', 'irios-woocommerce' )
		);
	}

	private static function text_input( string $field, string $index, string $name, string $value, string $placeholder ): string {
		return sprintf(
			'<input type="text" name="%s[%s][%s]" value="%s" placeholder="%s">',
			esc_attr( $field ),
			esc_attr( $index ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * Table of rows that can be added and removed (banners, Sponsored Products slots). $columns: [header, % width];
	 * the last column (✕) takes the rest.
	 *
	 * @param array<int,array{0:string,1:int}> $columns
	 * @param callable(string,array):string[]   $cells
	 * @param array<int,array<string,mixed>>    $rows
	 */
	private static function rows_table( string $title, string $class, array $columns, callable $cells, array $rows, string $add_label, string $description ): string {
		$row = static function ( string $index, array $values, bool $template ) use ( $cells ): string {
			$html = '<tr' . ( $template ? ' class="irios-row-template" style="display:none"' : '' ) . '>';
			foreach ( $cells( $index, $values ) as $cell ) {
				$html .= '<td>' . $cell . '</td>';
			}
			return $html . '<td><a href="#" class="irios-rows-remove" aria-label="' . esc_attr__( 'Remove', 'irios-woocommerce' ) . '">✕</a></td></tr>';
		};
		$body = '';
		foreach ( array_values( $rows ) as $i => $values ) {
			$body .= $row( (string) $i, $values, false );
		}
		if ( '' === $body ) {
			$body = $row( '0', array(), false );
		}
		// Template for "Add": the JS replaces __i__ with a unique index in each new row.
		$body .= $row( '__i__', array(), true );

		$colgroup = '';
		$headers  = '';
		$used     = 0;
		foreach ( $columns as $column ) {
			$colgroup .= '<col style="width:' . (int) $column[1] . '%">';
			$headers  .= '<th>' . esc_html( $column[0] ) . '</th>';
			$used     += (int) $column[1];
		}
		$colgroup .= '<col style="width:' . max( 4, 100 - $used ) . '%">';
		$headers  .= '<th></th>';

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $title ); ?></th>
			<td class="forminp">
				<div class="irios-rows <?php echo esc_attr( $class ); ?>">
					<style>
						/* WooCommerce form-table styles (th and input widths) break the table layout: same selectors + .irios-rows */
						.woocommerce table.form-table .irios-rows-scroll { overflow-x: auto; max-width: 960px; }
						.woocommerce table.form-table .irios-rows table { table-layout: fixed; width: 100%; min-width: 720px; }
						.woocommerce table.form-table .irios-rows table th,
						.woocommerce table.form-table .irios-rows table td { width: auto; padding: 8px 10px; vertical-align: middle; }
						.woocommerce table.form-table .irios-rows table thead th { padding-top: 12px; padding-bottom: 12px; font-weight: 600; }
						.woocommerce table.form-table .irios-rows table input[type="text"],
						.woocommerce table.form-table .irios-rows table input[type="number"],
						.woocommerce table.form-table .irios-rows table select { box-sizing: border-box; width: 100%; min-width: 0; max-width: none; margin: 0; }
						.woocommerce table.form-table .irios-rows .irios-rows-remove { text-decoration: none; }
					</style>
					<div class="irios-rows-scroll">
					<table class="widefat striped">
						<colgroup><?php echo $colgroup; // phpcs:ignore ?></colgroup>
						<thead><tr><?php echo $headers; // phpcs:ignore ?></tr></thead>
						<tbody><?php echo $body; // phpcs:ignore ?></tbody>
					</table>
					</div>
					<p><a href="#" class="button irios-rows-add"><?php echo esc_html( $add_label ); ?></a></p>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				</div>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/** @param string $key @param mixed $value Rows of the Sponsored Products slots table. */
	public function validate_sponsored_slots_field( $key, $value ): string {
		$rows   = is_array( $value ) ? array_values( array_filter( $value, 'is_array' ) ) : array();
		$result = Settings::sanitize_sponsored_slots( wp_unslash( $rows ) );
		foreach ( $result['errors'] as $error ) {
			\WC_Admin_Settings::add_error( 'IRIOS Media: ' . $error );
		}
		return (string) wp_json_encode( $result['slots'] );
	}

	/** @param string $key @param mixed $value Rows of the banners table. */
	public function validate_banners_field( $key, $value ): string {
		$rows   = is_array( $value ) ? array_values( array_filter( $value, 'is_array' ) ) : array();
		$result = Settings::sanitize_banners( wp_unslash( $rows ) );
		foreach ( $result['errors'] as $error ) {
			\WC_Admin_Settings::add_error( 'IRIOS Media: ' . $error );
		}
		return (string) wp_json_encode( $result['banners'] );
	}

	/** On save: with Sponsored Products enabled, at least one slot (position, ad unit and auth) is required. */
	public function process_admin_options(): bool {
		$saved = parent::process_admin_options();
		$settings = new Settings( $this->settings );
		if ( $settings->sponsored_enabled() && ! $settings->search_ads_enabled() ) {
			\WC_Admin_Settings::add_error( __( 'IRIOS Media: to show Sponsored Products, add at least one position with its ad unit and auth. Until then, no ads are shown.', 'irios-woocommerce' ) );
		}
		if ( 'yes' === ( $this->settings['push_enabled'] ?? 'no' ) && null === $settings->push() ) {
			\WC_Admin_Settings::add_error( __( 'IRIOS Media: to ask for push notifications, add the push ad unit and its auth. Until then, nothing is asked.', 'irios-woocommerce' ) );
		}
		return $saved;
	}

	/** @param string $key @param mixed $value */
	public function validate_js_domain_field( $key, $value ): string {
		$value  = (string) $value;
		$domain = Settings::normalize_domain( $value );
		if ( '' !== trim( $value ) && '' === $domain ) {
			\WC_Admin_Settings::add_error( __( 'IRIOS Media: the IRIOS Media domain is not valid.', 'irios-woocommerce' ) );
			return (string) $this->get_option( $key );
		}
		return $domain;
	}

	/** @param string $key @param mixed $value */
	public function validate_push_ad_unit_field( $key, $value ): string {
		return $this->positive_int_or_keep( $key, (string) $value, __( 'IRIOS Media: the push Ad unit ID must be a number.', 'irios-woocommerce' ) );
	}

	/** @param string $key @param mixed $value */
	public function validate_display_in_grid_after_field( $key, $value ): string {
		$value = trim( (string) $value );
		return Settings::is_positive_int( $value ) ? $value : '6';
	}

	/** @param string $key @param mixed $value */
	public function validate_push_after_pages_field( $key, $value ): string {
		$value = trim( (string) $value );
		return Settings::is_positive_int( $value ) ? $value : '2';
	}

	/** @param string $key @param mixed $value */
	public function validate_push_repeat_days_field( $key, $value ): string {
		$value = trim( (string) $value );
		return ctype_digit( $value ) ? (string) (int) $value : '30';
	}

	/** @param string $key @param mixed $value */
	public function validate_store_id_field( $key, $value ): string {
		return $this->positive_int_or_keep( $key, (string) $value, __( 'IRIOS Media: the Store ID must be a number.', 'irios-woocommerce' ) );
	}

	private function positive_int_or_keep( string $key, string $value, string $error ): string {
		$value = trim( $value );
		if ( '' === $value || Settings::is_positive_int( $value ) ) {
			return $value;
		}
		\WC_Admin_Settings::add_error( $error );
		return (string) $this->get_option( $key );
	}
}
