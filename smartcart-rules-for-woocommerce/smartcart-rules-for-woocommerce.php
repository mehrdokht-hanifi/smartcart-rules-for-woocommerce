<?php
/**
 * Plugin Name: SmartCart Rules for WooCommerce
 * Description: Configurable payment-method discounts, tiered cart rewards, and shipping-rate rules for WooCommerce.
 * Version: 1.0.1
 * Author: Mehrdokht Hanifi
 * Requires Plugins: woocommerce
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Text Domain: smartcart-rules-for-woocommerce
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

final class SCRW_Plugin {
	const OPTION  = 'scrw_settings';
	const SLUG    = 'smartcart-rules';
	const VERSION = '1.0.1';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
	}

	public function declare_compatibility() {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, false );
		}
	}

	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'admin_menu' ), 60 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ), 30 );
		add_action( 'wp_footer', array( $this, 'add_to_cart_toast' ), 30 );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'add_to_cart_toast_fragment' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'save_gateway_to_session' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_discount' ), 30 );
		add_filter( 'woocommerce_package_rates', array( $this, 'free_shipping_rate' ), 100, 2 );
		add_action( 'woocommerce_before_cart_totals', array( $this, 'cart_messages' ) );
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'checkout_messages' ) );
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'checkout_notice_fragment' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'add_order_note_meta' ), 20, 2 );
	}

	private function defaults() {
		return array(
			'discount_enabled'       => 'no',
			'gateway_ids'            => array(),
			'minimum_amount'         => 0,
			'discount_label'         => 'Payment method discount',
			'coupon_policy'          => 'deny',
			'excluded_products'      => '',
			'excluded_categories'    => '',
			'thresholds'             => array( 100, 250, 500, 1000 ),
			'tier_types'             => array( 'percent', 'percent', 'percent', 'percent', 'percent' ),
			'tier_values'            => array( 2, 3, 5, 7, 10 ),
			'free_shipping_enabled'  => 'no',
			'shipping_amount_basis'  => 'before_discounts',
			'shipping_rules'         => array(
				array(
					'enabled'   => 'yes',
					'label'     => 'Standard shipping',
					'match'     => 'flat_rate',
					'threshold' => 100,
				),
				array(
					'enabled'   => 'no',
					'label'     => 'Express shipping',
					'match'     => 'express',
					'threshold' => 200,
				),
			),
		);
	}

	private function settings() {
		$s = get_option( self::OPTION, array() );
		return array_replace( $this->defaults(), is_array( $s ) ? $s : array() );
	}

	public function admin_menu() {
		add_submenu_page(
			'woocommerce',
			'SmartCart Rules',
			'SmartCart Rules',
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'settings_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'scrw_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->defaults(),
			)
		);
	}

	public function sanitize_settings( $input ) {
		$input    = is_array( $input ) ? wp_unslash( $input ) : array();
		$defaults = $this->defaults();
		$out      = array();

		$out['discount_enabled']      = ! empty( $input['discount_enabled'] ) ? 'yes' : 'no';
		$out['free_shipping_enabled'] = ! empty( $input['free_shipping_enabled'] ) ? 'yes' : 'no';
		$out['shipping_amount_basis'] = isset( $input['shipping_amount_basis'] ) && 'after_coupons' === $input['shipping_amount_basis'] ? 'after_coupons' : 'before_discounts';
		$out['coupon_policy']         = isset( $input['coupon_policy'] ) && 'allow' === $input['coupon_policy'] ? 'allow' : 'deny';
		$out['minimum_amount']        = max( 0, (float) ( $input['minimum_amount'] ?? 0 ) );
		$out['discount_label']        = sanitize_text_field( $input['discount_label'] ?? $defaults['discount_label'] );
		$out['excluded_products']     = $this->sanitize_id_list( $input['excluded_products'] ?? '' );
		$out['excluded_categories']   = $this->sanitize_id_list( $input['excluded_categories'] ?? '' );

		$gateways = isset( $input['gateway_ids'] ) ? (array) $input['gateway_ids'] : array();
		$out['gateway_ids'] = array_values( array_unique( array_filter( array_map( 'sanitize_key', $gateways ) ) ) );

		$shipping_rules        = isset( $input['shipping_rules'] ) ? (array) $input['shipping_rules'] : $defaults['shipping_rules'];
		$out['shipping_rules'] = array();
		foreach ( array_slice( $shipping_rules, 0, 10 ) as $rule ) {
			$rule      = is_array( $rule ) ? $rule : array();
			$label     = sanitize_text_field( $rule['label'] ?? '' );
			$match     = sanitize_text_field( $rule['match'] ?? '' );
			$threshold = max( 0, (float) ( $rule['threshold'] ?? 0 ) );
			if ( '' === $label && '' === $match && 0 === $threshold ) {
				continue;
			}
			$out['shipping_rules'][] = array(
				'enabled'   => ! empty( $rule['enabled'] ) ? 'yes' : 'no',
				'label'     => $label,
				'match'     => $match,
				'threshold' => $threshold,
			);
		}
		if ( empty( $out['shipping_rules'] ) ) {
			$out['shipping_rules'] = $defaults['shipping_rules'];
		}

		$thresholds = array_map( 'floatval', (array) ( $input['thresholds'] ?? $defaults['thresholds'] ) );
		$thresholds = array_slice( array_pad( $thresholds, 4, 0 ), 0, 4 );
		$valid      = true;
		$previous   = 0;
		foreach ( $thresholds as &$threshold ) {
			$threshold = max( 0, $threshold );
			if ( $threshold <= $previous ) {
				$valid = false;
			}
			$previous = $threshold;
		}
		unset( $threshold );

		if ( ! $valid ) {
			add_settings_error( self::OPTION, 'scrw_invalid_thresholds', __( 'Tier thresholds must be greater than zero and strictly increasing. Previous values were preserved.', 'smartcart-rules-for-woocommerce' ), 'error' );
			$old               = $this->settings();
			$out['thresholds'] = $old['thresholds'];
		} else {
			$out['thresholds'] = $thresholds;
		}

		$types  = (array) ( $input['tier_types'] ?? $defaults['tier_types'] );
		$values = (array) ( $input['tier_values'] ?? $defaults['tier_values'] );
		$out['tier_types']  = array();
		$out['tier_values'] = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$type  = isset( $types[ $i ] ) && 'fixed' === $types[ $i ] ? 'fixed' : 'percent';
			$value = max( 0, (float) ( $values[ $i ] ?? 0 ) );
			if ( 'percent' === $type ) {
				$value = min( 100, $value );
			}
			$out['tier_types'][]  = $type;
			$out['tier_values'][] = $value;
		}

		if ( empty( $out['discount_label'] ) ) {
			$out['discount_label'] = $defaults['discount_label'];
		}
		return $out;
	}

	private function sanitize_id_list( $value ) {
		$ids = preg_split( '/[\s,]+/', (string) $value );
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		return implode( ',', $ids );
	}

	public function settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$s        = $this->settings();
		$gateways = WC()->payment_gateways()->payment_gateways();
		$currency = get_woocommerce_currency();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SmartCart Rules for WooCommerce', 'smartcart-rules-for-woocommerce' ); ?></h1>
			<p><?php esc_html_e( 'Create payment-method discounts and free-shipping thresholds without editing theme files.', 'smartcart-rules-for-woocommerce' ); ?></p>
			<?php settings_errors( self::OPTION ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'scrw_group' ); ?>

				<h2><?php esc_html_e( 'Payment-method discount', 'smartcart-rules-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Enable', 'smartcart-rules-for-woocommerce' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[discount_enabled]" value="1" <?php checked( $s['discount_enabled'], 'yes' ); ?>> <?php esc_html_e( 'Enable payment-method discounts', 'smartcart-rules-for-woocommerce' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'Eligible gateways', 'smartcart-rules-for-woocommerce' ); ?></th><td>
						<?php foreach ( $gateways as $id => $gateway ) : ?>
							<label style="display:block;margin:7px 0"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[gateway_ids][]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, (array) $s['gateway_ids'], true ) ); ?>> <strong><?php echo esc_html( $gateway->get_title() ); ?></strong> <code><?php echo esc_html( $id ); ?></code></label>
						<?php endforeach; ?>
						<?php if ( empty( $gateways ) ) : ?><p class="description"><?php esc_html_e( 'Configure at least one WooCommerce payment gateway first.', 'smartcart-rules-for-woocommerce' ); ?></p><?php endif; ?>
					</td></tr>
					<tr><th><?php esc_html_e( 'Minimum eligible subtotal', 'smartcart-rules-for-woocommerce' ); ?></th><td><input type="number" min="0" step="0.01" name="<?php echo esc_attr( self::OPTION ); ?>[minimum_amount]" value="<?php echo esc_attr( $s['minimum_amount'] ); ?>"> <?php echo esc_html( $currency ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Discount label', 'smartcart-rules-for-woocommerce' ); ?></th><td><input class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[discount_label]" value="<?php echo esc_attr( $s['discount_label'] ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'Coupon stacking', 'smartcart-rules-for-woocommerce' ); ?></th><td><select name="<?php echo esc_attr( self::OPTION ); ?>[coupon_policy]"><option value="deny" <?php selected( $s['coupon_policy'], 'deny' ); ?>><?php esc_html_e( 'Do not combine', 'smartcart-rules-for-woocommerce' ); ?></option><option value="allow" <?php selected( $s['coupon_policy'], 'allow' ); ?>><?php esc_html_e( 'Allow combining', 'smartcart-rules-for-woocommerce' ); ?></option></select></td></tr>
					<tr><th><?php esc_html_e( 'Excluded product IDs', 'smartcart-rules-for-woocommerce' ); ?></th><td><input class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[excluded_products]" value="<?php echo esc_attr( $s['excluded_products'] ); ?>"><p class="description"><?php esc_html_e( 'Comma-separated product or variation IDs.', 'smartcart-rules-for-woocommerce' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Excluded category IDs', 'smartcart-rules-for-woocommerce' ); ?></th><td><input class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[excluded_categories]" value="<?php echo esc_attr( $s['excluded_categories'] ); ?>"><p class="description"><?php esc_html_e( 'Comma-separated product category IDs.', 'smartcart-rules-for-woocommerce' ); ?></p></td></tr>
				</table>

				<h3><?php esc_html_e( 'Discount tiers', 'smartcart-rules-for-woocommerce' ); ?></h3>
				<p><?php esc_html_e( 'Each threshold starts the next tier. Amounts use the active WooCommerce store currency.', 'smartcart-rules-for-woocommerce' ); ?></p>
				<table class="widefat striped" style="max-width:950px"><thead><tr><th><?php esc_html_e( 'Tier', 'smartcart-rules-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Range', 'smartcart-rules-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Type', 'smartcart-rules-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Value', 'smartcart-rules-for-woocommerce' ); ?></th></tr></thead><tbody>
				<?php for ( $i = 0; $i < 5; $i++ ) : ?>
					<tr>
						<td><?php echo esc_html( $i + 1 ); ?></td>
						<td>
						<?php if ( $i < 4 ) : ?><?php esc_html_e( 'Below', 'smartcart-rules-for-woocommerce' ); ?> <input type="number" min="0.01" step="0.01" name="<?php echo esc_attr( self::OPTION ); ?>[thresholds][]" value="<?php echo esc_attr( $s['thresholds'][ $i ] ); ?>"> <?php echo esc_html( $currency ); ?><?php else : ?><?php esc_html_e( 'At or above tier 4 threshold', 'smartcart-rules-for-woocommerce' ); ?><?php endif; ?>
						</td>
						<td><select name="<?php echo esc_attr( self::OPTION ); ?>[tier_types][]"><option value="percent" <?php selected( $s['tier_types'][ $i ], 'percent' ); ?>><?php esc_html_e( 'Percentage', 'smartcart-rules-for-woocommerce' ); ?></option><option value="fixed" <?php selected( $s['tier_types'][ $i ], 'fixed' ); ?>><?php esc_html_e( 'Fixed amount', 'smartcart-rules-for-woocommerce' ); ?></option></select></td>
						<td><input type="number" min="0" step="0.01" name="<?php echo esc_attr( self::OPTION ); ?>[tier_values][]" value="<?php echo esc_attr( $s['tier_values'][ $i ] ); ?>"></td>
					</tr>
				<?php endfor; ?>
				</tbody></table>

				<h2><?php esc_html_e( 'Free-shipping rules', 'smartcart-rules-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Enable', 'smartcart-rules-for-woocommerce' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[free_shipping_enabled]" value="1" <?php checked( $s['free_shipping_enabled'], 'yes' ); ?>> <?php esc_html_e( 'Enable free-shipping rules', 'smartcart-rules-for-woocommerce' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'Amount basis', 'smartcart-rules-for-woocommerce' ); ?></th><td><select name="<?php echo esc_attr( self::OPTION ); ?>[shipping_amount_basis]"><option value="before_discounts" <?php selected( $s['shipping_amount_basis'], 'before_discounts' ); ?>><?php esc_html_e( 'Products before discounts', 'smartcart-rules-for-woocommerce' ); ?></option><option value="after_coupons" <?php selected( $s['shipping_amount_basis'], 'after_coupons' ); ?>><?php esc_html_e( 'Products after coupons', 'smartcart-rules-for-woocommerce' ); ?></option></select></td></tr>
				</table>
				<p><?php esc_html_e( 'A rule matches existing shipping rates by method ID, rate ID, or label. Separate multiple match terms with commas.', 'smartcart-rules-for-woocommerce' ); ?></p>
				<table class="widefat striped" style="max-width:1050px"><thead><tr><th><?php esc_html_e( 'Enabled', 'smartcart-rules-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Public label', 'smartcart-rules-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Match terms', 'smartcart-rules-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Threshold', 'smartcart-rules-for-woocommerce' ); ?></th></tr></thead><tbody>
				<?php foreach ( array_pad( array_slice( (array) $s['shipping_rules'], 0, 5 ), 5, array( 'enabled' => 'no', 'label' => '', 'match' => '', 'threshold' => 0 ) ) as $i => $rule ) : ?>
					<tr>
						<td><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[shipping_rules][<?php echo esc_attr( $i ); ?>][enabled]" value="1" <?php checked( $rule['enabled'], 'yes' ); ?>></td>
						<td><input type="text" name="<?php echo esc_attr( self::OPTION ); ?>[shipping_rules][<?php echo esc_attr( $i ); ?>][label]" value="<?php echo esc_attr( $rule['label'] ); ?>"></td>
						<td><input class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION ); ?>[shipping_rules][<?php echo esc_attr( $i ); ?>][match]" value="<?php echo esc_attr( $rule['match'] ); ?>"></td>
						<td><input type="number" min="0" step="0.01" name="<?php echo esc_attr( self::OPTION ); ?>[shipping_rules][<?php echo esc_attr( $i ); ?>][threshold]" value="<?php echo esc_attr( $rule['threshold'] ); ?>"> <?php echo esc_html( $currency ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>

				<?php submit_button( __( 'Save rules', 'smartcart-rules-for-woocommerce' ) ); ?>
			</form>
		</div>
		<?php
	}

	private function to_store_amount( $amount, $s = null ) {
		return (float) $amount;
	}

	private function from_store_amount( $amount, $s = null ) {
		return (float) $amount;
	}

	public function enqueue_frontend_assets() {
		if ( is_order_received_page() ) {
			return;
		}

		wp_enqueue_style(
			'scrw-frontend',
			plugin_dir_url( __FILE__ ) . 'assets/css/frontend.css',
			array(),
			self::VERSION
		);

		if ( is_checkout() ) {
			wp_enqueue_script(
				'scrw-checkout',
				plugin_dir_url( __FILE__ ) . 'assets/js/checkout.js',
				array( 'jquery', 'wc-checkout' ),
				self::VERSION,
				true
			);
		}

		wp_enqueue_script(
			'scrw-add-to-cart',
			plugin_dir_url( __FILE__ ) . 'assets/js/add-to-cart.js',
			array( 'jquery' ),
			self::VERSION,
			true
		);
	}

	private function add_to_cart_toast_html() {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return '<div id="scrw-add-to-cart-toast" class="scrw-toast" role="status" aria-live="polite" aria-hidden="true"></div>';
		}

		$s       = $this->settings();
		$parts   = array( '<strong>' . esc_html__( 'Product added to your cart.', 'smartcart-rules-for-woocommerce' ) . '</strong>' );
		$amount  = $this->eligible_subtotal( WC()->cart, $s );
		$minimum = $this->to_store_amount( $s['minimum_amount'], $s );

		if ( 'yes' === $s['discount_enabled'] && ! $this->conflicting_coupon( WC()->cart, $s ) ) {
			if ( $amount < $minimum ) {
				$parts[] = $this->toast_progress_line( $minimum - $amount, __( 'more to unlock a payment-method discount.', 'smartcart-rules-for-woocommerce' ) );
			} else {
				$tier = $this->tier( $amount, $s );
				if ( null !== $tier['next_threshold'] && $amount < $tier['next_threshold'] ) {
					$parts[] = $this->toast_progress_line(
						$tier['next_threshold'] - $amount,
						sprintf(
							__( 'more to unlock %1$s with %2$s.', 'smartcart-rules-for-woocommerce' ),
							$this->benefit_text( $tier['next_type'], $tier['next_value'], $s ),
							$this->gateway_titles( $s )
						)
					);
				} else {
					$parts[] = sprintf(
						__( 'Your cart qualifies for the highest reward: %1$s with %2$s.', 'smartcart-rules-for-woocommerce' ),
						$this->benefit_text( $tier['type'], $tier['value'], $s ),
						$this->gateway_titles( $s )
					);
				}
			}
		}

		if ( 'yes' === $s['free_shipping_enabled'] ) {
			$shipping_progress = $this->shipping_progress( WC()->cart, $s );
			if ( $shipping_progress['active_labels'] ) {
				$parts[] = '<div class="scrw-toast-line scrw-toast-success">' . esc_html( sprintf( __( 'Free-shipping threshold reached for: %s.', 'smartcart-rules-for-woocommerce' ), implode( ', ', $shipping_progress['active_labels'] ) ) ) . '</div>';
			}
			if ( $shipping_progress['next_rule'] ) {
				$parts[] = $this->toast_progress_line(
					$shipping_progress['next_rule']['remaining'],
					sprintf( __( 'more to unlock free %s.', 'smartcart-rules-for-woocommerce' ), esc_html( $shipping_progress['next_rule']['label'] ) )
				);
			}
		}

		return '<div id="scrw-add-to-cart-toast" class="scrw-toast" role="status" aria-live="polite" aria-hidden="true"><button type="button" class="scrw-toast-close" aria-label="' . esc_attr__( 'Close message', 'smartcart-rules-for-woocommerce' ) . '">&times;</button><div class="scrw-toast-content">' . wp_kses_post( implode( '', $parts ) ) . '</div></div>';
	}

	private function toast_progress_line( $amount, $suffix ) {
		$number   = number_format_i18n( (float) $amount, wc_get_price_decimals() );
		$currency = wp_strip_all_tags( get_woocommerce_currency_symbol() );

		return sprintf(
			'<div class="scrw-toast-line"><span class="scrw-toast-only">' . esc_html__( 'Only', 'smartcart-rules-for-woocommerce' ) . '</span><span class="scrw-toast-number">%1$s</span><span class="scrw-toast-currency">%2$s</span><span class="scrw-toast-suffix">%3$s</span></div>',
			esc_html( $number ),
			esc_html( $currency ),
			wp_kses_post( $suffix )
		);
	}

	public function add_to_cart_toast() {
		if ( is_admin() || is_order_received_page() ) {
			return;
		}
		echo $this->add_to_cart_toast_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function add_to_cart_toast_fragment( $fragments ) {
		$fragments['#scrw-add-to-cart-toast'] = $this->add_to_cart_toast_html();
		return $fragments;
	}

	public function save_gateway_to_session( $posted_data ) {
		parse_str( wp_unslash( $posted_data ), $data );
		if ( isset( $data['payment_method'] ) && WC()->session ) {
			WC()->session->set( 'chosen_payment_method', wc_clean( $data['payment_method'] ) );
		}
	}

	private function selected_gateway() {
		if ( isset( $_POST['payment_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return wc_clean( wp_unslash( $_POST['payment_method'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( isset( $_POST['post_data'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			parse_str( wp_unslash( $_POST['post_data'] ), $posted ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return isset( $posted['payment_method'] ) ? wc_clean( $posted['payment_method'] ) : '';
		}
		return WC()->session ? (string) WC()->session->get( 'chosen_payment_method', '' ) : '';
	}

	private function eligible_gateway( $s ) {
		return in_array( $this->selected_gateway(), (array) $s['gateway_ids'], true );
	}

	private function conflicting_coupon( $cart, $s ) {
		$conflict = 'deny' === $s['coupon_policy'] && $cart->has_discount();
		return (bool) apply_filters( 'scrw_has_conflicting_discount', $conflict, $cart, $s );
	}

	private function excluded_ids( $value ) {
		return array_filter( array_map( 'absint', explode( ',', (string) $value ) ) );
	}

	private function eligible_subtotal( $cart, $s ) {
		if ( ! $cart ) {
			return 0;
		}
		$excluded_products   = $this->excluded_ids( $s['excluded_products'] );
		$excluded_categories = $this->excluded_ids( $s['excluded_categories'] );
		$total               = 0;

		foreach ( $cart->get_cart() as $item ) {
			$product_id = (int) $item['product_id'];
			$variation  = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
			if ( in_array( $product_id, $excluded_products, true ) || ( $variation && in_array( $variation, $excluded_products, true ) ) ) {
				continue;
			}
			if ( $excluded_categories && has_term( $excluded_categories, 'product_cat', $product_id ) ) {
				continue;
			}
			$total += isset( $item['line_total'] ) ? (float) $item['line_total'] : 0;
		}

		return max( 0, (float) apply_filters( 'scrw_eligible_subtotal', $total, $cart, $s ) );
	}

	private function tier( $store_amount, $s ) {
		$display_amount = $this->from_store_amount( $store_amount, $s );
		$index          = 4;
		foreach ( $s['thresholds'] as $i => $threshold ) {
			if ( $display_amount < (float) $threshold ) {
				$index = (int) $i;
				break;
			}
		}

		return array(
			'index'          => $index,
			'type'           => $s['tier_types'][ $index ],
			'value'          => (float) $s['tier_values'][ $index ],
			'next_threshold' => $index < 4 ? $this->to_store_amount( $s['thresholds'][ $index ], $s ) : null,
			'next_type'      => $index < 4 ? $s['tier_types'][ $index + 1 ] : null,
			'next_value'     => $index < 4 ? (float) $s['tier_values'][ $index + 1 ] : null,
		);
	}

	private function discount_data( $cart, $require_gateway = true ) {
		$s      = $this->settings();
		$amount = $this->eligible_subtotal( $cart, $s );
		$tier   = $this->tier( $amount, $s );
		$data   = array( 'eligible' => false, 'amount' => $amount, 'discount' => 0, 'tier' => $tier, 'settings' => $s );

		if ( 'yes' !== $s['discount_enabled'] || empty( $s['gateway_ids'] ) || $amount < $this->to_store_amount( $s['minimum_amount'], $s ) || $this->conflicting_coupon( $cart, $s ) ) {
			return $data;
		}
		if ( $require_gateway && ! $this->eligible_gateway( $s ) ) {
			return $data;
		}

		if ( 'fixed' === $tier['type'] ) {
			$discount = $this->to_store_amount( $tier['value'], $s );
		} else {
			$discount = $amount * $tier['value'] / 100;
		}
		$discount        = min( $amount, max( 0, round( $discount, wc_get_price_decimals() ) ) );
		$data['eligible'] = $discount > 0;
		$data['discount'] = $discount;
		return $data;
	}

	public function add_discount( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		$data = $this->discount_data( $cart, true );
		if ( $data['eligible'] ) {
			$cart->add_fee( $data['settings']['discount_label'], -1 * $data['discount'], false );
		}
	}

	private function shipping_subtotal( $cart, $s ) {
		if ( ! $cart ) {
			return 0;
		}
		$subtotal = 'after_coupons' === $s['shipping_amount_basis']
			? $cart->get_cart_contents_total()
			: $cart->get_subtotal();
		return max( 0, (float) apply_filters( 'scrw_shipping_subtotal', $subtotal, $cart, $s ) );
	}

	private function active_shipping_rules( $s ) {
		$rules = array();
		foreach ( (array) $s['shipping_rules'] as $rule ) {
			if ( ! is_array( $rule ) || 'yes' !== ( $rule['enabled'] ?? 'no' ) || empty( $rule['match'] ) || (float) ( $rule['threshold'] ?? 0 ) <= 0 ) {
				continue;
			}
			$rules[] = $rule;
		}
		usort(
			$rules,
			static function ( $a, $b ) {
				return (float) $a['threshold'] <=> (float) $b['threshold'];
			}
		);
		return $rules;
	}

	private function normalize_match_text( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = str_replace( array( '-', '_' ), ' ', $value );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	private function shipping_rule_matches_rate( $rule, $rate, $rate_key ) {
		$haystack = $this->normalize_match_text(
			(string) $rate_key . ' ' . $rate->get_id() . ' ' . $rate->get_method_id() . ' ' . $rate->get_label()
		);
		$terms = preg_split( '/[,\r\n]+/', (string) $rule['match'] );
		foreach ( (array) $terms as $term ) {
			$term = trim( $this->normalize_match_text( $term ) );
			if ( '' !== $term && false !== strpos( $haystack, $term ) ) {
				return true;
			}
		}
		return false;
	}

	private function display_shipping_label( $rate, $rule, $is_free ) {
		$base_label = ! empty( $rule['label'] ) ? $rule['label'] : $rate->get_label();
		return $is_free
			? sprintf( __( '%s (Free)', 'smartcart-rules-for-woocommerce' ), $base_label )
			: $base_label;
	}

	public function free_shipping_rate( $rates, $package ) {
		if ( ! WC()->cart || empty( $rates ) ) {
			return $rates;
		}
		$s = $this->settings();
		if ( 'yes' !== $s['free_shipping_enabled'] ) {
			return $rates;
		}

		$subtotal = $this->shipping_subtotal( WC()->cart, $s );
		$rules    = $this->active_shipping_rules( $s );
		foreach ( $rates as $rate_key => $rate ) {
			$matched_rule = null;
			foreach ( $rules as $rule ) {
				if ( $this->shipping_rule_matches_rate( $rule, $rate, $rate_key ) ) {
					$matched_rule = $rule;
					break;
				}
			}
			if ( ! $matched_rule ) {
				continue;
			}

			$threshold = $this->to_store_amount( $matched_rule['threshold'], $s );
			$is_free   = $subtotal >= $threshold;
			if ( $is_free ) {
				$rate->set_cost( 0 );
				$taxes = $rate->get_taxes();
				if ( is_array( $taxes ) ) {
					$rate->set_taxes( array_fill_keys( array_keys( $taxes ), 0 ) );
				}
			}
			$rate->set_label( $this->display_shipping_label( $rate, $matched_rule, $is_free ) );
			$rates[ $rate_key ] = $rate;
		}
		return $rates;
	}

	private function shipping_progress( $cart, $s ) {
		$subtotal     = $this->shipping_subtotal( $cart, $s );
		$active       = array();
		$next_rule    = null;
		foreach ( $this->active_shipping_rules( $s ) as $rule ) {
			$threshold = $this->to_store_amount( $rule['threshold'], $s );
			if ( $subtotal >= $threshold ) {
				$active[] = ! empty( $rule['label'] ) ? $rule['label'] : $rule['match'];
				continue;
			}
			$next_rule = array(
				'label'     => ! empty( $rule['label'] ) ? $rule['label'] : $rule['match'],
				'remaining' => $threshold - $subtotal,
			);
			break;
		}
		return array(
			'active_labels' => array_values( array_filter( $active ) ),
			'next_rule'     => $next_rule,
		);
	}

	private function benefit_text( $type, $value, $s ) {
		return 'fixed' === $type
			? sprintf( __( '%s discount', 'smartcart-rules-for-woocommerce' ), wc_price( $this->to_store_amount( $value, $s ) ) )
			: sprintf( __( '%s%% discount', 'smartcart-rules-for-woocommerce' ), wc_format_decimal( $value ) );
	}

	private function gateway_titles( $s ) {
		$available = WC()->payment_gateways()->payment_gateways();
		$titles    = array();

		foreach ( (array) $s['gateway_ids'] as $gateway_id ) {
			if ( isset( $available[ $gateway_id ] ) ) {
				$titles[] = wp_strip_all_tags( $available[ $gateway_id ]->get_title() );
			}
		}

		return $titles ? implode( ', ', array_unique( $titles ) ) : __( 'an eligible payment method', 'smartcart-rules-for-woocommerce' );
	}

	private function remove_coupon_links_html() {
		if ( ! WC()->cart ) {
			return '';
		}

		$links = array();
		foreach ( WC()->cart->get_applied_coupons() as $coupon_code ) {
			$url     = wc_get_cart_remove_url( $coupon_code );
			$links[] = sprintf(
				'<a href="%1$s" class="button woocommerce-remove-coupon scrw-remove-coupon" data-coupon="%2$s" aria-label="%3$s">%4$s</a>',
				esc_url( $url ),
				esc_attr( $coupon_code ),
				esc_attr( sprintf( __( 'Remove coupon %s and enable the payment-method discount', 'smartcart-rules-for-woocommerce' ), $coupon_code ) ),
				esc_html__( 'Remove coupon and use payment discount', 'smartcart-rules-for-woocommerce' )
			);
		}

		return $links ? '<div class="scrw-coupon-actions">' . implode( ' ', $links ) . '</div>' : '';
	}

	private function discount_message_html() {
		if ( ! WC()->cart ) {
			return '';
		}
		$s = $this->settings();
		if ( 'yes' !== $s['discount_enabled'] || empty( $s['gateway_ids'] ) ) {
			return '';
		}
		if ( $this->conflicting_coupon( WC()->cart, $s ) ) {
			return '<div class="woocommerce-info scrw-notice scrw-coupon-conflict"><div class="scrw-notice-text">' . esc_html__( 'The payment-method discount cannot be combined with an active coupon.', 'smartcart-rules-for-woocommerce' ) . '</div>' . $this->remove_coupon_links_html() . '</div>';
		}

		$amount  = $this->eligible_subtotal( WC()->cart, $s );
		$minimum = $this->to_store_amount( $s['minimum_amount'], $s );
		if ( $amount < $minimum ) {
			return '<div class="woocommerce-info scrw-notice">' . wp_kses_post( sprintf( __( 'Add %s more to unlock a payment-method discount.', 'smartcart-rules-for-woocommerce' ), wc_price( $minimum - $amount ) ) ) . '</div>';
		}

		$data          = $this->discount_data( WC()->cart, false );
		$tier          = $data['tier'];
		$gateway_ok    = $this->eligible_gateway( $s );
		$gateway_names = $this->gateway_titles( $s );
		$benefit       = 'percent' === $tier['type']
			? sprintf( __( '%1$s discount (%2$s%%)', 'smartcart-rules-for-woocommerce' ), wc_price( $data['discount'] ), wc_format_decimal( $tier['value'] ) )
			: sprintf( __( '%s discount', 'smartcart-rules-for-woocommerce' ), wc_price( $data['discount'] ) );
		$text          = $gateway_ok
			? sprintf( __( 'Pay with %1$s to receive %2$s.', 'smartcart-rules-for-woocommerce' ), $gateway_names, $benefit )
			: sprintf( __( 'Select %1$s to receive %2$s.', 'smartcart-rules-for-woocommerce' ), $gateway_names, $benefit );

		if ( null !== $tier['next_threshold'] && $amount < $tier['next_threshold'] ) {
			$text .= ' ' . sprintf( __( 'Only %1$s more to unlock %2$s.', 'smartcart-rules-for-woocommerce' ), wc_price( $tier['next_threshold'] - $amount ), $this->benefit_text( $tier['next_type'], $tier['next_value'], $s ) );
		}
		return '<div class="woocommerce-info scrw-notice">' . wp_kses_post( $text ) . '</div>';
	}

	private function shipping_message_html() {
		if ( ! WC()->cart ) {
			return '';
		}
		$s = $this->settings();
		if ( 'yes' !== $s['free_shipping_enabled'] ) {
			return '';
		}
		$progress = $this->shipping_progress( WC()->cart, $s );
		$parts    = array();
		if ( $progress['active_labels'] ) {
			$parts[] = sprintf( __( 'Free-shipping threshold reached for: %s.', 'smartcart-rules-for-woocommerce' ), esc_html( implode( ', ', $progress['active_labels'] ) ) );
		}
		if ( $progress['next_rule'] ) {
			$parts[] = sprintf( __( 'Only %1$s more to unlock free %2$s.', 'smartcart-rules-for-woocommerce' ), wc_price( $progress['next_rule']['remaining'] ), esc_html( $progress['next_rule']['label'] ) );
		}
		if ( ! $parts ) {
			return '';
		}
		$text = implode( ' ', $parts );
		return '<div class="woocommerce-info scrw-notice">' . wp_kses_post( $text ) . '</div>';
	}

	public function cart_messages() {
		echo $this->discount_message_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->shipping_message_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function checkout_messages() {
		echo $this->checkout_messages_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function checkout_messages_html() {
		return '<div id="scrw-checkout-notices">'
			. $this->discount_message_html()
			. $this->shipping_message_html()
			. '</div>';
	}

	public function checkout_notice_fragment( $fragments ) {
		$fragments['#scrw-checkout-notices'] = $this->checkout_messages_html();
		return $fragments;
	}

	public function add_order_note_meta( $order, $data ) {
		if ( ! WC()->cart ) {
			return;
		}
		$discount = $this->discount_data( WC()->cart, true );
		if ( $discount['eligible'] ) {
			$order->update_meta_data( '_scrw_payment_gateway', $this->selected_gateway() );
			$order->update_meta_data( '_scrw_discount_amount', $discount['discount'] );
			$order->update_meta_data( '_scrw_discount_tier', $discount['tier']['index'] + 1 );
		}
	}
}

SCRW_Plugin::instance();
