<?php

namespace Irios\WooCommerce;

/**
 * Settings screen in WooCommerce → Settings → Integration → Irios Media. Only loaded when WooCommerce is active.
 */
final class Integration extends \WC_Integration {

	public function __construct() {
		$this->id                 = 'irios';
		$this->method_title       = 'Irios Media';
		$this->method_description = sprintf(
			/* translators: %s: plugin version */
			__( 'Irios Media visitor events and identity and, optionally, Sponsored Products and banners. Version %s.', 'irios-woocommerce' ),
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

	public function init_form_fields(): void {
		$defaults          = Settings::defaults();
		$this->form_fields = array(
			// ---- Irios: events and identity (always shown, no title: it is the main section) ------------------------
			'js_domain'                => array(
				'title'       => __( 'Irios API domain', 'irios-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Without https:// or path, e.g. example.iriosmedia.online. irios-tracker.js and irios-loader.js are loaded from it.', 'irios-woocommerce' ),
				'default'     => $defaults['js_domain'],
			),
			'store_id'                 => array(
				'title'       => __( 'Store ID', 'irios-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Numeric id of the store in Irios.', 'irios-woocommerce' ),
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
				'description' => __( 'Until the visitor accepts marketing cookies, only contextual ads are requested: no identifiers, no Irios cookies and no events.', 'irios-woocommerce' ),
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
				'title'   => __( 'Positions', 'irios-woocommerce' ),
				'type'    => 'irios_slots',
				'default' => $defaults['sponsored_slots'],
			),
			'sponsored_label'          => array(
				'title'       => __( 'Ad label', 'irios-woocommerce' ),
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

			// ---- Banners (optional) --------------------------------------------------------------------------------
			'section_banners'          => array(
				'title'       => __( 'Banners', 'irios-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Irios banners anywhere on the site. Add each banner with a tag and its Irios ad unit details, and paste [irios_banner tag="TAG"] wherever you want to show it: a page, a WPBakery element, a block, a widget or a category description. When disabled, the shortcodes show nothing.', 'irios-woocommerce' ),
			),
			'banners_enabled'          => array(
				'title'   => __( 'Enable', 'irios-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show Irios banners', 'irios-woocommerce' ),
				'default' => $defaults['banners_enabled'],
			),
			'banners'                  => array(
				'title'   => __( 'Banners', 'irios-woocommerce' ),
				'type'    => 'irios_banners',
				'default' => $defaults['banners'],
			),
		);

		foreach ( self::SPONSORED_FIELDS as $key ) {
			$this->form_fields[ $key ]['class'] = trim( ( $this->form_fields[ $key ]['class'] ?? '' ) . ' irios-sponsored-field' );
		}
	}

	/** Renders the settings and hides each optional section (Sponsored Products, Banners) until it is enabled. */
	public function admin_options(): void {
		echo Compatibility::warning_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in warning_html()
		parent::admin_options();
		wc_enqueue_js(
			"( function ( $ ) {
				[ [ '#woocommerce_irios_sponsored_enabled', '.irios-sponsored-field' ], [ '#woocommerce_irios_banners_enabled', '.irios-banners-field' ] ]
					.forEach( function ( pair ) {
						var toggle = $( pair[0] );
						var rows = $( pair[1] ).closest( 'tr' );
						var sync = function () { rows.toggle( toggle.is( ':checked' ) ); };
						toggle.on( 'change', sync );
						sync();
					} );
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
	 * Banners table (tag, ad unit id and auth, position), with rows that can be added and removed. Submitted as
	 * woocommerce_irios_banners[n][tag|id|auth|position].
	 *
	 * @param string $key @param array<string,mixed> $data
	 */
	public function generate_irios_banners_html( $key, $data ): string {
		$field   = $this->get_field_key( $key );
		$banners = ( new Settings( array( 'banners' => (string) $this->get_option( $key, '[]' ) ) ) )->banners();
		$cells   = static function ( string $index, array $banner ) use ( $field ): array {
			$options = array(
				Settings::POSITION_NONE                       => __( 'Manual (shortcode)', 'irios-woocommerce' ),
				Settings::POSITION_CATEGORY_AFTER_PRODUCTS    => __( 'Category page: bottom', 'irios-woocommerce' ),
				Settings::POSITION_PRODUCT_BEFORE_DESCRIPTION => __( 'Product page: middle', 'irios-woocommerce' ),
			);
			$html = '';
			foreach ( $options as $option => $label ) {
				$html .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $option ), selected( $banner['position'] ?? '', $option, false ), esc_html( $label ) );
			}
			return array(
				self::text_input( $field, $index, 'tag', $banner['tag'] ?? '', __( 'home', 'irios-woocommerce' ) ),
				self::text_input( $field, $index, 'id', isset( $banner['id'] ) ? (string) $banner['id'] : '', '1234' ),
				self::text_input( $field, $index, 'auth', $banner['auth'] ?? '', __( 'ad unit auth', 'irios-woocommerce' ) ),
				sprintf( '<select name="%s[%s][position]">%s</select>', esc_attr( $field ), esc_attr( $index ), $html ),
			);
		};
		return self::rows_table(
			$data['title'],
			'irios-banners irios-banners-field',
			array(
				array( __( 'Tag', 'irios-woocommerce' ), 22 ),
				array( __( 'Ad unit (id)', 'irios-woocommerce' ), 13 ),
				array( __( 'Auth', 'irios-woocommerce' ), 27 ),
				array( __( 'Position', 'irios-woocommerce' ), 32 ),
			),
			$cells,
			$banners,
			__( 'Add banner', 'irios-woocommerce' ),
			__( 'On the site: [irios_banner tag="TAG"], or an automatic position. Tag in lowercase, without spaces (e.g. home, category-sticks).', 'irios-woocommerce' )
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
				array( __( 'Ad unit (id)', 'irios-woocommerce' ), 22 ),
				array( __( 'Auth', 'irios-woocommerce' ), 56 ),
			),
			$cells,
			$slots,
			__( 'Add position', 'irios-woocommerce' ),
			__( 'One ad per row. The position is the slot in the results grid: 1 is top left, counted in reading order (left to right, row by row). The same ad unit can take several positions. They are also used in the live search dropdown, each ad at its position in the list.', 'irios-woocommerce' )
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
			\WC_Admin_Settings::add_error( 'Irios: ' . $error );
		}
		return (string) wp_json_encode( $result['slots'] );
	}

	/** @param string $key @param mixed $value Rows of the banners table. */
	public function validate_banners_field( $key, $value ): string {
		$rows   = is_array( $value ) ? array_values( array_filter( $value, 'is_array' ) ) : array();
		$result = Settings::sanitize_banners( wp_unslash( $rows ) );
		foreach ( $result['errors'] as $error ) {
			\WC_Admin_Settings::add_error( 'Irios: ' . $error );
		}
		return (string) wp_json_encode( $result['banners'] );
	}

	/** On save: with Sponsored Products enabled, at least one slot (position, ad unit and auth) is required. */
	public function process_admin_options(): bool {
		$saved = parent::process_admin_options();
		$settings = new Settings( $this->settings );
		if ( $settings->sponsored_enabled() && ! $settings->search_ads_enabled() ) {
			\WC_Admin_Settings::add_error( __( 'Irios: to show Sponsored Products, add at least one position with its ad unit and auth. Until then, no ads are shown.', 'irios-woocommerce' ) );
		}
		return $saved;
	}

	/** @param string $key @param mixed $value */
	public function validate_js_domain_field( $key, $value ): string {
		$value  = (string) $value;
		$domain = Settings::normalize_domain( $value );
		if ( '' !== trim( $value ) && '' === $domain ) {
			\WC_Admin_Settings::add_error( __( 'Irios: the JS domain is not valid.', 'irios-woocommerce' ) );
			return (string) $this->get_option( $key );
		}
		return $domain;
	}

	/** @param string $key @param mixed $value */
	public function validate_store_id_field( $key, $value ): string {
		return $this->positive_int_or_keep( $key, (string) $value, __( 'Irios: the Store ID must be a number.', 'irios-woocommerce' ) );
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
