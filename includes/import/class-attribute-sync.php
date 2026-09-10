<?php
/**
 * Manages the global WooCommerce product attributes the importer assigns to plans,
 * so the shop's layered-navigation widgets can filter eSIMs by data amount and
 * validity. (Region/coverage is already a product category via Taxonomy_Sync.)
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Import;

defined( 'ABSPATH' ) || exit;

class Attribute_Sync {

	private const OPT_CREATED = 'nextsim_woo_attributes_created';

	// WooCommerce prefixes attribute taxonomies with `pa_`. Keep slugs short.
	public const ATTR_DATA     = 'nextsim_data';
	public const ATTR_VALIDITY = 'nextsim_valid';

	/** @var array<string, int> term-id cache, keyed "taxonomy|value". */
	private array $term_cache = array();

	public function register(): void {
		add_action( 'init', array( $this, 'ensure_attributes' ), 5 );
	}

	public static function taxonomy( string $attr ): string {
		return wc_attribute_taxonomy_name( $attr );
	}

	/**
	 * Create the two global attributes once, then make sure their taxonomies are
	 * registered for this request (WooCommerce auto-registers them on later requests).
	 */
	public function ensure_attributes(): void {
		if ( ! function_exists( 'wc_create_attribute' ) ) {
			return;
		}

		if ( ! get_option( self::OPT_CREATED ) ) {
			$this->create_attribute( self::ATTR_DATA, __( 'Data', 'nextsim-woo' ) );
			$this->create_attribute( self::ATTR_VALIDITY, __( 'Validity', 'nextsim-woo' ) );
			update_option( self::OPT_CREATED, 1, false );
		}

		foreach ( array( self::ATTR_DATA, self::ATTR_VALIDITY ) as $attr ) {
			$taxonomy = self::taxonomy( $attr );
			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy( $taxonomy, 'product', array( 'hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false ) );
			}
		}
	}

	private function create_attribute( string $slug, string $label ): void {
		if ( wc_attribute_taxonomy_id_by_name( $slug ) > 0 ) {
			return;
		}

		wc_create_attribute(
			array(
				'name'         => $label,
				'slug'         => $slug,
				'type'         => 'select',
				'order_by'     => 'name',
				'has_archives' => false,
			)
		);
	}

	/**
	 * Assign the plan's data-amount and validity terms to the product as global
	 * attributes. The relationships are written when the caller saves the product,
	 * so this works for a brand-new product too.
	 */
	public function assign( \WC_Product $product, Plan_Data $plan ): void {
		$attributes = array();

		$data = $this->build_attribute( self::ATTR_DATA, $this->data_label( $plan ) );
		if ( null !== $data ) {
			$attributes[ self::taxonomy( self::ATTR_DATA ) ] = $data;
		}

		$validity = $this->build_attribute( self::ATTR_VALIDITY, $this->validity_label( $plan ) );
		if ( null !== $validity ) {
			$attributes[ self::taxonomy( self::ATTR_VALIDITY ) ] = $validity;
		}

		$product->set_attributes( $attributes );
	}

	public function data_label( Plan_Data $plan ): string {
		if ( $plan->is_unlimited ) {
			return __( 'Unlimited', 'nextsim-woo' );
		}

		$gb = $plan->data_gb;

		return ( floor( $gb ) === $gb )
			/* translators: %d: whole gigabytes. */
			? sprintf( __( '%d GB', 'nextsim-woo' ), (int) $gb )
			/* translators: %s: gigabytes with decimals. */
			: sprintf( __( '%s GB', 'nextsim-woo' ), rtrim( rtrim( number_format( $gb, 2, '.', '' ), '0' ), '.' ) );
	}

	public function validity_label( Plan_Data $plan ): string {
		$days = max( 0, $plan->period_days );

		/* translators: %d: number of days. */
		return sprintf( _n( '%d day', '%d days', $days, 'nextsim-woo' ), $days );
	}

	private function build_attribute( string $attr, string $value ): ?\WC_Product_Attribute {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		$taxonomy = self::taxonomy( $attr );
		$term_id  = $this->term_id( $taxonomy, $value );
		if ( 0 === $term_id ) {
			return null;
		}

		$attribute = new \WC_Product_Attribute();
		$attribute->set_id( wc_attribute_taxonomy_id_by_name( $attr ) );
		$attribute->set_name( $taxonomy );
		$attribute->set_options( array( $term_id ) );
		$attribute->set_visible( true );
		$attribute->set_variation( false );

		return $attribute;
	}

	private function term_id( string $taxonomy, string $value ): int {
		$key = $taxonomy . '|' . $value;
		if ( isset( $this->term_cache[ $key ] ) ) {
			return $this->term_cache[ $key ];
		}

		$existing = get_term_by( 'name', $value, $taxonomy );
		if ( $existing instanceof \WP_Term ) {
			return $this->term_cache[ $key ] = (int) $existing->term_id;
		}

		$created = wp_insert_term( $value, $taxonomy );

		return $this->term_cache[ $key ] = is_wp_error( $created ) ? 0 : (int) $created['term_id'];
	}
}
