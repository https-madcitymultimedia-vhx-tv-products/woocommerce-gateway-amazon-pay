<?php
/**
 * Main class and core functions.
 *
 * @package WC_Gateway_Amazon_Pay
 */

/*
 * Plugin Name: WooCommerce Amazon Pay
 * Plugin URI: https://woocommerce.com/products/pay-with-amazon/
 * Description: Amazon Pay is embedded directly into your existing web site, and all the buyer interactions with Amazon Pay and Login with Amazon take place in embedded widgets so that the buyer never leaves your site. Buyers can log in using their Amazon account, select a shipping address and payment method, and then confirm their order. Requires an Amazon Pay seller account and supports USA, UK, Germany, France, Italy, Spain, Luxembourg, the Netherlands, Sweden, Portugal, Hungary, Denmark, and Japan.
 * Version: 2.0.0
 * Author: WooCommerce
 * Author URI: https://woocommerce.com
 *
 * Text Domain: woocommerce-gateway-amazon-payments-advanced
 * Domain Path: /languages/
 * Tested up to: 5.5
 * WC tested up to: 4.4
 * WC requires at least: 2.6
 *
 * Copyright: © 2020 WooCommerce
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

define( 'WC_AMAZON_PAY_VERSION', '2.0.0' );

/**
 * Amazon Pay main class
 */
class WC_Amazon_Payments_Advanced {

	/**
	 * Plugin's version.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public $version;

	/**
	 * Plugin's absolute path.
	 *
	 * @var string
	 */
	public $path;

	/**
	 * Plugin's includes path.
	 *
	 * @var string
	 */
	public $includes_path;
	/**
	 * Plugin's URL.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public $plugin_url;

	/**
	 * Plugin basename.
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	public $plugin_basename;

	/**
	 * Amazon Pay settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Reference ID
	 *
	 * @var string
	 */
	private $reference_id;


	/**
	 * Access token
	 *
	 * @var string
	 */
	private $access_token;

	/**
	 * Amazon Pay Gateway
	 *
	 * @var WC_Gateway_Amazon_Payments_Advanced
	 */
	private $gateway;

	/**
	 * WC logger instance.
	 *
	 * @var WC_Logger
	 */
	private $logger;

	/**
	 * Amazon Pay compat handler.
	 *
	 * @since 1.6.0
	 * @var WC_Amazon_Payments_Advanced_Compat
	 */
	private $compat;

	/**
	 * IPN handler.
	 *
	 * @since 1.8.0
	 * @var WC_Amazon_Payments_Advanced_IPN_Handler
	 */
	public $ipn_handler;

	/**
	 * Synchronous handler.
	 *
	 * @since 1.8.0
	 * @var WC_Amazon_Payments_Advanced_Synchronous_Handler
	 */
	public $synchro_handler;

	/**
	 * Simple Path handler.
	 *
	 * @var WC_Amazon_Payments_Advanced_Merchant_Onboarding_Handler
	 */
	public $onboarding_handler;

	/**
	 * API migration Status.
	 *
	 * @since 2.0.0
	 * @var bool
	 */
	public $api_migration;

	/**
	 * SDK config.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	public $amazonpay_sdk_config;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->version         = WC_AMAZON_PAY_VERSION;
		$this->path            = untrailingslashit( plugin_dir_path( __FILE__ ) );
		$this->plugin_url      = untrailingslashit( plugins_url( '/', __FILE__ ) );
		$this->plugin_basename = plugin_basename( __FILE__ );
		$this->get_migration_status();
		$this->includes_path   = $this->path . '/includes/';

		include_once $this->includes_path . 'class-wc-amazon-payments-advanced-merchant-onboarding-handler.php';
		include_once $this->includes_path . 'class-wc-amazon-payments-advanced-api-abstract.php';

		include_once $this->includes_path . 'legacy/class-wc-amazon-payments-advanced-api-legacy.php';
		include_once $this->includes_path . 'class-wc-amazon-payments-advanced-api.php';

		include_once( $this->includes_path . 'class-wc-amazon-payments-advanced-compat.php' );
		include_once( $this->includes_path . 'class-wc-amazon-payments-advanced-ipn-handler.php' );
		include_once( $this->includes_path . 'class-wc-amazon-payments-advanced-synchronous-handler.php' );

		// On install hook.
		include_once( $this->includes_path . 'class-wc-amazon-payments-install.php' );
		register_activation_hook( __FILE__, array( 'WC_Amazon_Payments_Advanced_Install', 'install' ) );

		add_action( 'init', array( $this, 'init' ) );
		add_action( 'woocommerce_init', array( $this, 'multicurrency_init' ), 0 );
		add_action( 'wp_loaded', array( $this, 'init_handlers' ), 11 );
		add_action( 'woocommerce_thankyou_amazon_payments_advanced', array( $this, 'logout_from_amazon' ) );
		add_filter( 'woocommerce_ajax_get_endpoint', array( $this, 'filter_ajax_endpoint' ), 10, 2 );

		// REST API support.
		add_action( 'rest_api_init', array( $this, 'rest_api_register_routes' ), 11 );
		add_filter( 'woocommerce_rest_prepare_shop_order', array( $this, 'rest_api_add_amazon_ref_info' ), 10, 2 );

		// IPN handler.
		$this->ipn_handler = new WC_Amazon_Payments_Advanced_IPN_Handler();
		// Synchronous handler.
		$this->synchro_handler = new WC_Amazon_Payments_Advanced_Synchronous_Handler();
		// Simple path registration endpoint.
		$this->onboarding_handler = new WC_Amazon_Payments_Advanced_Merchant_Onboarding_Handler();
	}

	/**
	 * Get API Migration status.
	 */
	public function get_migration_status( $fresh = false ) {
		if ( $fresh || empty( $this->api_migration ) ) {
			$status              = get_option( 'amazon_api_version' );
			$old_install         = version_compare( get_option( 'woocommerce_amazon_payments_new_install' ), '2.0.0', '>=' );
			$this->api_migration = 'V2' === $status || $old_install ? true : false;
		}
		return $this->api_migration;
	}

	/**
	 * Update migration status update
	 */
	public function update_migration_status() {
		update_option( 'amazon_api_version', 'V2' );
	}

	/**
	 * Downgrade migration status update
	 */
	public function delete_migration_status() {
		delete_option( 'amazon_api_version' );
	}

	public function get_settings( $fresh = false ) {
		if ( ! isset( $this->settings ) || $fresh ) {
			$this->settings = WC_Amazon_Payments_Advanced_API::get_settings();
		}
		return $this->settings;
	}

	/**
	 * Init.
	 *
	 * @since 1.6.0
	 */
	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$this->settings     = WC_Amazon_Payments_Advanced_API::get_settings();
		$this->reference_id = WC_Amazon_Payments_Advanced_API::get_reference_id();
		$this->access_token = WC_Amazon_Payments_Advanced_API::get_access_token();

		$this->maybe_display_declined_notice();
		$this->maybe_attempt_to_logout();

		$this->compat = new WC_Amazon_Payments_Advanced_Compat();
		$this->compat->load_compats();

		$this->load_plugin_textdomain();
		if ( is_admin() ) {
			include_once( $this->includes_path . 'admin/class-wc-amazon-payments-advanced-admin.php' );
			$this->admin = new WC_Amazon_Payments_Advanced_Admin();
		}
		$this->init_gateway();
	}

	/**
	 * Set up API V2 SDK.
	 */
	public function get_amazonpay_sdk_config( $fresh = false ) {
		if ( $fresh || empty( $this->amazonpay_sdk_config ) ) {
			$this->settings             = WC_Amazon_Payments_Advanced_API::get_settings();
			$this->amazonpay_sdk_config = array(
				'public_key_id' => $this->settings['public_key_id'],
				'private_key'   => get_option( WC_Amazon_Payments_Advanced_Merchant_Onboarding_Handler::KEYS_OPTION_PRIVATE_KEY, false ),
				'sandbox'       => 'yes' === $this->settings['sandbox'] ? true : false,
				'region'        => $this->settings['payment_region'],
			);
		}
		return $this->amazonpay_sdk_config;
	}


	/**
	 * Multi-currency Init.
	 */
	public function multicurrency_init() {
		$this->compat = new WC_Amazon_Payments_Advanced_Compat();
		$this->compat->load_multicurrency();
	}

	/**
	 * Maybe display declined notice.
	 *
	 * @since 1.7.1
	 * @version 1.7.1
	 */
	public function maybe_display_declined_notice() {
		if ( ! empty( $_GET['amazon_declined'] ) ) {
			wc_add_notice( __( 'There was a problem with previously declined transaction. Please try placing the order again.', 'woocommerce-gateway-amazon-payments-advanced' ), 'error' );
		}
	}

	/**
	 * Maybe the request to logout from Amazon.
	 *
	 * @since 1.6.0
	 */
	public function maybe_attempt_to_logout() {
		if ( ! empty( $_GET['amazon_payments_advanced'] ) && ! empty( $_GET['amazon_logout'] ) ) {
			$this->logout_from_amazon();
		}
	}

	/**
	 * Logout from Amazon by removing Amazon related session and logout too
	 * from app widget.
	 *
	 * @since 1.6.0
	 */
	public function logout_from_amazon() {
		unset( WC()->session->amazon_reference_id );
		unset( WC()->session->amazon_access_token );

		$this->reference_id = '';
		$this->access_token = '';

		if ( is_order_received_page() && 'yes' === $this->settings['enable_login_app'] ) {
			?>
			<script>
			( function( $ ) {
				$( document ).on( 'wc_amazon_pa_login_ready', function() {
					amazon.Login.logout();
				} );
			} )(jQuery)
			</script>
			<?php
		}
	}

	/**
	 * Load translations.
	 *
	 * @since 1.6.0
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain( 'woocommerce-gateway-amazon-payments-advanced', false, dirname( $this->plugin_basename ) . '/languages' );
	}

	/**
	 * Init gateway
	 */
	public function init_gateway() {

		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		include_once( $this->includes_path . 'class-wc-gateway-amazon-payments-advanced.php' );
		include_once( $this->includes_path . 'class-wc-gateway-amazon-payments-advanced-privacy.php' );

		$subscriptions_installed = class_exists( 'WC_Subscriptions_Order' ) && function_exists( 'wcs_create_renewal_order' );
		$subscriptions_enabled   = empty( $this->settings['subscriptions_enabled'] ) || 'yes' == $this->settings['subscriptions_enabled'];

		// Check for Subscriptions 2.0, and load support if found.
		if ( $subscriptions_installed && $subscriptions_enabled ) {

			include_once( $this->includes_path . 'class-wc-gateway-amazon-payments-advanced-subscriptions.php' );

			$this->gateway = new WC_Gateway_Amazon_Payments_Advanced_Subscriptions();

		} else {

			$this->gateway = new WC_Gateway_Amazon_Payments_Advanced();

		}

		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ) );
	}

	/**
	 * Load handlers for cart and orders after WC Cart is loaded.
	 */
	public function init_handlers() {
		// Disable if no seller ID.
		if ( ! apply_filters( 'woocommerce_amazon_payments_init', true ) || empty( $this->settings['seller_id'] ) || 'no' == $this->settings['enabled'] ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'scripts' ) );
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'update_amazon_widgets_fragment' ) );
		add_action( 'woocommerce_after_calculate_totals', array( $this, 'force_standard_mode_refresh_with_zero_order_total' ) );
	}

	/**
	 * Checkout Button
	 *
	 * Triggered from the 'woocommerce_proceed_to_checkout' action.
	 */
	public function checkout_button() {
		$subscriptions_installed = class_exists( 'WC_Subscriptions_Order' ) && function_exists( 'wcs_create_renewal_order' );
		$subscriptions_enabled   = empty( $this->settings['subscriptions_enabled'] ) || 'yes' == $this->settings['subscriptions_enabled'];
		$cart_contains_sub       = class_exists( 'WC_Subscriptions_Cart' ) ? WC_Subscriptions_Cart::cart_contains_subscription() : false;

		if ( $subscriptions_installed && ! $subscriptions_enabled && $cart_contains_sub ) {
			return;
		}

		echo '<div id="pay_with_amazon"></div>';
	}

	/**
	 * Checkout Message
	 */
	public function checkout_message() {
		$subscriptions_installed = class_exists( 'WC_Subscriptions_Order' ) && function_exists( 'wcs_create_renewal_order' );
		$subscriptions_enabled   = empty( $this->settings['subscriptions_enabled'] ) || 'yes' == $this->settings['subscriptions_enabled'];
		$cart_contains_sub       = class_exists( 'WC_Subscriptions_Cart' ) ? WC_Subscriptions_Cart::cart_contains_subscription() : false;

		if ( $subscriptions_installed && ! $subscriptions_enabled && $cart_contains_sub ) {
			return;
		}

		echo '<div class="wc-amazon-checkout-message wc-amazon-payments-advanced-populated">';

		if ( empty( $this->reference_id ) && empty( $this->access_token ) ) {
			echo '<div class="woocommerce-info info wc-amazon-payments-advanced-info"><div id="pay_with_amazon"></div> ' . apply_filters( 'woocommerce_amazon_pa_checkout_message', __( 'Have an Amazon account?', 'woocommerce-gateway-amazon-payments-advanced' ) ) . '</div>';
		} else {
			$logout_url = $this->get_amazon_logout_url();
			$logout_msg_html = '<div class="woocommerce-info info">' . apply_filters( 'woocommerce_amazon_pa_checkout_logout_message', __( 'You\'re logged in with your Amazon Account.', 'woocommerce-gateway-amazon-payments-advanced' ) ) . ' <a href="' . esc_url( $logout_url ) . '" id="amazon-logout">' . __( 'Log out &raquo;', 'woocommerce-gateway-amazon-payments-advanced' ) . '</a></div>';
			echo apply_filters( 'woocommerce_amazon_payments_logout_checkout_message_html', $logout_msg_html );
		}

		echo '</div>';

	}

	/**
	 * Add Amazon gateway to WC.
	 *
	 * @param array $methods List of payment methods.
	 *
	 * @return array List of payment methods.
	 */
	public function add_gateway( $methods ) {
		$methods[] = $this->gateway;

		return $methods;
	}

	/**
	 * Add scripts
	 */
	public function scripts() {

		$enqueue_scripts = is_cart() || is_checkout() || is_checkout_pay_page();

		if ( ! apply_filters( 'woocommerce_amazon_pa_enqueue_scripts', $enqueue_scripts ) ) {
			return;
		}

		$js_suffix = '.min.js';
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			$js_suffix = '.js';
		}

		$type = ( 'yes' == $this->settings['enable_login_app'] ) ? 'app' : 'standard';

		wp_enqueue_style( 'amazon_payments_advanced', plugins_url( 'assets/css/style.css', __FILE__ ), array(), $this->version );
		wp_enqueue_script( 'amazon_payments_advanced_widgets', WC_Amazon_Payments_Advanced_API::get_widgets_url(), array(), $this->version, true );
		wp_enqueue_script( 'amazon_payments_advanced', plugins_url( 'assets/js/amazon-' . $type . '-widgets' . $js_suffix, __FILE__ ), array(), $this->version, true );

		$redirect_page = is_cart() ? add_query_arg( 'amazon_payments_advanced', 'true', get_permalink( wc_get_page_id( 'checkout' ) ) ) : add_query_arg( array( 'amazon_payments_advanced' => 'true', 'amazon_logout' => false ) );

		$params = array(
			'ajax_url'              => admin_url( 'admin-ajax.php' ),
			'seller_id'             => $this->settings['seller_id'],
			'reference_id'          => $this->reference_id,
			'redirect'              => esc_url_raw( $redirect_page ),
			'is_checkout_pay_page'  => is_checkout_pay_page(),
			'is_checkout'           => is_checkout(),
			'access_token'          => $this->access_token,
			'logout_url'            => esc_url_raw( $this->get_amazon_logout_url() ),
			'render_address_widget' => apply_filters( 'woocommerce_amazon_show_address_widget', WC()->cart->needs_shipping() ),
			'order_reference_nonce' => wp_create_nonce( 'order_reference_nonce' ),
		);

		if ( 'yes' == $this->settings['enable_login_app'] ) {

			$params['button_type']     = $this->settings['button_type'];
			$params['button_color']    = $this->settings['button_color'];
			$params['button_size']     = $this->settings['button_size'];
			$params['button_language'] = $this->settings['button_language'];
			$params['checkout_url']    = esc_url_raw( get_permalink( wc_get_page_id( 'checkout' ) ) );

		}

		if ( WC()->session->amazon_declined_code ) {
			$params['declined_code'] = WC()->session->amazon_declined_code;
			unset( WC()->session->amazon_declined_code );
		}

		if ( WC()->session->amazon_declined_with_cancel_order ) {
			$order                           = wc_get_order( WC()->session->amazon_declined_order_id );
			$params['declined_redirect_url'] = add_query_arg(
				array(
					'amazon_payments_advanced' => 'true',
					'amazon_logout'            => 'true',
					'amazon_declined'          => 'true',
				),
				$order->get_cancel_order_url()
			);

			unset( WC()->session->amazon_declined_order_id );
			unset( WC()->session->amazon_declined_with_cancel_order );
		}

		if ( class_exists( 'WC_Subscriptions_Cart' ) ) {

			$cart_contains_subscription      = WC_Subscriptions_Cart::cart_contains_subscription() || wcs_cart_contains_renewal();
			$change_payment_for_subscription = isset( $_GET['change_payment_method'] ) && wcs_is_subscription( absint( $_GET['change_payment_method'] ) );
			$params['is_recurring']          = $cart_contains_subscription || $change_payment_for_subscription;

			// No need to make billing agreement if automatic payments is turned off.
			if ( 'yes' === get_option( 'woocommerce_subscriptions_turn_off_automatic_payments' ) ) {
				unset( $params['is_recurring'] );
			}
		}

		// SCA support. If Merchant is European Region and Order does not contain or is a subscriptions.
		$params['is_sca'] = ( WC_Amazon_Payments_Advanced_API::is_sca_region() );
		if ( $params['is_sca'] ) {
			$params['sca_nonce'] = wp_create_nonce( 'sca_nonce' );
		}

		// Multi-currency support.
		$multi_currency                         = WC_Amazon_Payments_Advanced_Multi_Currency::is_active();
		$params['multi_currency_supported']     = $multi_currency;
		$params['multi_currency_nonce']         = wp_create_nonce( 'multi_currency_nonce' );
		$params['multi_currency_reload_wallet'] = ( $multi_currency ) ? WC_Amazon_Payments_Advanced_Multi_Currency::reload_wallet_widget() : false;
		$params['current_currency']             = ( $multi_currency ) ? WC_Amazon_Payments_Advanced_Multi_Currency::get_selected_currency() : '';
		$params['shipping_title']               =  __( 'Shipping details', 'woocommerce' );
		$params['redirect_authentication']      = $this->settings['redirect_authentication'];

		$params = array_map( 'esc_js', apply_filters( 'woocommerce_amazon_pa_widgets_params', $params ) );

		wp_localize_script( 'amazon_payments_advanced', 'amazon_payments_advanced_params', $params );

		do_action( 'wc_amazon_pa_scripts_enqueued', $type, $params );
	}

	/**
	 * Output the address widget HTML
	 */
	public function address_widget() {
		// Skip showing address widget for carts with virtual products only
		$show_address_widget = apply_filters( 'woocommerce_amazon_show_address_widget', WC()->cart->needs_shipping() );
		$hide_css_style      = ( ! $show_address_widget ) ? 'display: none;' : '';
		?>
		<div id="amazon_customer_details" class="wc-amazon-payments-advanced-populated">
			<div class="col2-set">
				<div class="col-1" style="<?php echo esc_attr( $hide_css_style ); ?>">
					<?php if ( 'skip' !== WC()->session->get( 'amazon_billing_agreement_details' ) ) : ?>
						<h3><?php esc_html_e( 'Shipping Address', 'woocommerce-gateway-amazon-payments-advanced' ); ?></h3>
						<div id="amazon_addressbook_widget"></div>
					<?php endif ?>
					<?php if ( ! empty( $this->reference_id ) ) : ?>
						<input type="hidden" name="amazon_reference_id" value="<?php echo esc_attr( $this->reference_id ); ?>" />
					<?php endif; ?>
					<?php if ( ! empty( $this->access_token ) ) : ?>
						<input type="hidden" name="amazon_access_token" value="<?php echo esc_attr( $this->access_token ); ?>" />
					<?php endif; ?>
				</div>
		<?php
	}

	/**
	 * Output the payment method widget HTML
	 */
	public function payment_widget() {
		$checkout = WC_Checkout::instance();
		?>
				<div class="col-2">
					<h3><?php _e( 'Payment Method', 'woocommerce-gateway-amazon-payments-advanced' ); ?></h3>
					<div id="amazon_wallet_widget"></div>
					<?php if ( ! empty( $this->reference_id ) ) : ?>
						<input type="hidden" name="amazon_reference_id" value="<?php echo esc_attr( $this->reference_id ); ?>" />
					<?php endif; ?>
					<?php if ( ! empty( $this->access_token ) ) : ?>
						<input type="hidden" name="amazon_access_token" value="<?php echo esc_attr( $this->access_token ); ?>" />
					<?php endif; ?>
					<?php if ( 'skip' === WC()->session->get( 'amazon_billing_agreement_details' ) ) : ?>
						<input type="hidden" name="amazon_billing_agreement_details" value="skip" />
					<?php endif; ?>
				</div>
				<?php if ( 'skip' !== WC()->session->get( 'amazon_billing_agreement_details' ) ) : ?>
					<div id="amazon_consent_widget" style="display: none;"></div>
				<?php endif; ?>

		<?php if ( ! is_user_logged_in() && $checkout->enable_signup ) : ?>

			<?php if ( $checkout->enable_guest_checkout ) : ?>

				<p class="form-row form-row-wide create-account">
					<input class="input-checkbox" id="createaccount" <?php checked( ( true === $checkout->get_value( 'createaccount' ) || ( true === apply_filters( 'woocommerce_create_account_default_checked', false ) ) ), true ) ?> type="checkbox" name="createaccount" value="1" /> <label for="createaccount" class="checkbox"><?php _e( 'Create an account?', 'woocommerce-gateway-amazon-payments-advanced' ); ?></label>
				</p>

			<?php endif; ?>

			<?php do_action( 'woocommerce_before_checkout_registration_form', $checkout ); ?>

			<?php if ( ! empty( $checkout->checkout_fields['account'] ) ) : ?>

				<div class="create-account">

					<h3><?php _e( 'Create Account', 'woocommerce-gateway-amazon-payments-advanced' ); ?></h3>
					<p><?php _e( 'Create an account by entering the information below. If you are a returning customer please login at the top of the page.', 'woocommerce-gateway-amazon-payments-advanced' ); ?></p>

					<?php foreach ( $checkout->checkout_fields['account'] as $key => $field ) : ?>

						<?php woocommerce_form_field( $key, $field, $checkout->get_value( $key ) ); ?>

					<?php endforeach; ?>

					<div class="clear"></div>

				</div>

			<?php endif; ?>

			<?php do_action( 'woocommerce_after_checkout_registration_form', $checkout ); ?>

		<?php endif; ?>
			</div>
		</div>

		<?php
	}

	/**
	 * Render the Amazon Pay widgets when an order is updated to require
	 * payment, and the Amazon gateway is available.
	 *
	 * @param array $fragments Fragments.
	 *
	 * @return array
	 */
	public function update_amazon_widgets_fragment( $fragments ) {

		$available_gateways = WC()->payment_gateways()->get_available_payment_gateways();

		if ( WC()->cart->needs_payment() ) {

			ob_start();

			$this->checkout_message();

			$fragments['.wc-amazon-checkout-message:not(.wc-amazon-payments-advanced-populated)'] = ob_get_clean();

			if ( array_key_exists( 'amazon_payments_advanced', $available_gateways ) ) {

				ob_start();

				$this->address_widget();

				$this->payment_widget();

				$fragments['#amazon_customer_details:not(.wc-amazon-payments-advanced-populated)'] = ob_get_clean();
			}
		}

		return $fragments;

	}

	/**
	 * Helper method to get a sanitized version of the site name.
	 *
	 * @return string
	 */
	public static function get_site_name() {
		// Get site setting for blog name.
		$site_name = get_bloginfo( 'name' );
		return self::sanitize_string($site_name);
	}

	/**
	 * Helper method to get a sanitized version of the site description.
	 *
	 * @return string
	 */
	public static function get_site_description() {
		// Get site setting for blog name.
		$site_description = get_bloginfo( 'description' );
		return self::sanitize_string( $site_description);
    }

	/**
     * Helper method to get a sanitized version of a string.
     *
	 * @param $string
	 *
	 * @return string
	 */
    protected static function sanitize_string( $string ) {
	    // Decode HTML entities.
	    $string = wp_specialchars_decode( $string, ENT_QUOTES );

	    // ASCII-ify accented characters.
	    $string = remove_accents( $string );

	    // Remove non-printable characters.
	    $string = preg_replace( '/[[:^print:]]/', '', $string );

	    // Clean up leading/trailing whitespace.
	    $string = trim( $string );

	    return $string;
    }

	/**
	 * Force a page refresh when an order is updated to have a zero total and
	 * we're not using the "login app" mode.
	 *
	 * This ensures that the standard WC checkout form is rendered.
	 *
	 * @param WC_Cart $cart Cart object.
	 */
	public function force_standard_mode_refresh_with_zero_order_total( $cart ) {
		// Avoid constant reload loop in the event we've forced a checkout refresh.
		if ( ! is_ajax() ) {
			unset( WC()->session->reload_checkout );
		}

		// Login app mode can handle zero-total orders.
		if ( 'yes' === $this->settings['enable_login_app'] ) {
			return;
		}

		if ( ! $this->gateway->is_available() ) {
			return;
		}

		// Get the previous cart total.
		$previous_total = WC()->session->wc_amazon_previous_total;

		// Store the current total.
		WC()->session->wc_amazon_previous_total = $cart->total;

		// If the total is non-zero, and we don't know what the previous total was, bail.
		if ( is_null( $previous_total ) || $cart->needs_payment() ) {
			return;
		}

		// This *wasn't* as zero-total order, but is now.
		if ( $previous_total > 0 ) {
			// Force reload, re-rendering standard WC checkout form.
			WC()->session->reload_checkout = true;
		}
	}

	/**
	 * Write a message to log if we're in "debug" mode.
	 *
	 * @since 1.6.0
	 *
	 * @param string $context Context for the log.
	 * @param string $message Log message.
	 */
	public function log( $context, $message ) {
		if ( empty( $this->settings['debug'] ) ) {
			return;
		}

		if ( 'yes' !== $this->settings['debug'] ) {
			return;
		}

		if ( ! is_a( $this->logger, 'WC_Logger' ) ) {
			$this->logger = new WC_Logger();
		}

		$log_message = $context . ' - ' . $message;

		$this->logger->add( 'woocommerce-gateway-amazon-payments-advanced', $log_message );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( $log_message );
		}
	}

	/**
	 * Sanitize log message.
	 *
	 * Used to sanitize logged HTTP response message.
	 *
	 * @see https://github.com/woocommerce/woocommerce-gateway-amazon-payments-advanced/issues/133
	 * @since 1.6.0
	 *
	 * @param mixed $message Log message.
	 *
	 * @return string Sanitized log message.
	 */
	public function sanitize_remote_response_log( $message ) {
		if ( ! is_a( $message, 'SimpleXMLElement' ) ) {
			return (string) $message;
		}

		if ( ! is_callable( array( $message, 'asXML' ) ) ) {
			return '';
		}

		$message = $message->asXML();

		// Sanitize response message.
		$patterns    = array();
		$patterns[0] = '/(<Buyer>)(.+)(<\/Buyer>)/ms';
		$patterns[1] = '/(<PhysicalDestination>)(.+)(<\/PhysicalDestination>)/ms';
		$patterns[2] = '/(<BillingAddress>)(.+)(<\/BillingAddress>)/ms';
		$patterns[3] = '/(<SellerNote>)(.+)(<\/SellerNote>)/ms';
		$patterns[4] = '/(<AuthorizationBillingAddress>)(.+)(<\/AuthorizationBillingAddress>)/ms';
		$patterns[5] = '/(<SellerAuthorizationNote>)(.+)(<\/SellerAuthorizationNote>)/ms';
		$patterns[6] = '/(<SellerCaptureNote>)(.+)(<\/SellerCaptureNote>)/ms';
		$patterns[7] = '/(<SellerRefundNote>)(.+)(<\/SellerRefundNote>)/ms';

		$replacements    = array();
		$replacements[0] = '$1 REMOVED $3';
		$replacements[1] = '$1 REMOVED $3';
		$replacements[2] = '$1 REMOVED $3';
		$replacements[3] = '$1 REMOVED $3';
		$replacements[4] = '$1 REMOVED $3';
		$replacements[5] = '$1 REMOVED $3';
		$replacements[6] = '$1 REMOVED $3';
		$replacements[7] = '$1 REMOVED $3';

		return preg_replace( $patterns, $replacements, $message );
	}

	/**
	 * Sanitize logged request.
	 *
	 * Used to sanitize logged HTTP request message.
	 *
	 * @see https://github.com/woocommerce/woocommerce-gateway-amazon-payments-advanced/issues/133
	 * @since 1.6.0
	 *
	 * @param string $message Log message from stringified array structure.
	 *
	 * @return string Sanitized log message
	 */
	public function sanitize_remote_request_log( $message ) {
		$patterns    = array();
		$patterns[0] = '/(AWSAccessKeyId=)(.+)(&)/ms';
		$patterns[0] = '/(SellerNote=)(.+)(&)/ms';
		$patterns[1] = '/(SellerAuthorizationNote=)(.+)(&)/ms';
		$patterns[2] = '/(SellerCaptureNote=)(.+)(&)/ms';
		$patterns[3] = '/(SellerRefundNote=)(.+)(&)/ms';

		$replacements    = array();
		$replacements[0] = '$1REMOVED$3';
		$replacements[1] = '$1REMOVED$3';
		$replacements[2] = '$1REMOVED$3';
		$replacements[3] = '$1REMOVED$3';

		return preg_replace( $patterns, $replacements, $message );
	}

	/**
	 * Register REST API route for /orders/<order-id>/amazon-payments-advanced/.
	 *
	 * @since 1.6.0
	 */
	public function rest_api_register_routes() {
		// Check to make sure WC is activated and its REST API were loaded
		// first.
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		if ( ! isset( WC()->api ) ) {
			return;
		}
		if ( ! is_a( WC()->api, 'WC_API' ) ) {
			return;
		}

		require_once( $this->includes_path . 'class-wc-amazon-payments-advanced-rest-api-controller.php' );

		WC()->api->WC_Amazon_Payments_Advanced_REST_API_Controller = new WC_Amazon_Payments_Advanced_REST_API_Controller();
		WC()->api->WC_Amazon_Payments_Advanced_REST_API_Controller->register_routes();
	}

	/**
	 * Add Amazon reference information in order item response.
	 *
	 * @since 1.6.0
	 *
	 * @param WP_REST_Response $response Response object.
	 * @param WP_Post          $post     Post object.
	 *
	 * @return WP_REST_Response REST response
	 */
	public function rest_api_add_amazon_ref_info( $response, $post ) {
		if ( 'amazon_payments_advanced' === $response->data['payment_method'] ) {
			$response->data['amazon_reference'] = array(

				'amazon_reference_state'     => WC_Amazon_Payments_Advanced_API::get_order_ref_state( $post->ID, 'amazon_reference_state' ),
				'amazon_reference_id'        => get_post_meta( $post->ID, 'amazon_reference_id', true ),
				'amazon_authorization_state' => WC_Amazon_Payments_Advanced_API::get_order_ref_state( $post->ID, 'amazon_authorization_state' ),
				'amazon_authorization_id'    => get_post_meta( $post->ID, 'amazon_authorization_id', true ),
				'amazon_capture_state'       => WC_Amazon_Payments_Advanced_API::get_order_ref_state( $post->ID, 'amazon_capture_state' ),
				'amazon_capture_id'          => get_post_meta( $post->ID, 'amazon_capture_id', true ),
				'amazon_refund_ids'          => get_post_meta( $post->ID, 'amazon_refund_id', false ),
			);
		}

		return $response;
	}

	/**
	 * Get Amazon logout URL.
	 *
	 * @since 1.6.0
	 *
	 * @return string Amazon logout URL
	 */
	public function get_amazon_logout_url() {
		return add_query_arg(
			array(
				'amazon_payments_advanced' => 'true',
				'amazon_logout'            => 'true',
			),
			get_permalink( wc_get_page_id( 'checkout' ) )
		);
	}

	/**
	 * Filter Ajax endpoint so it carries the query string after buyer is
	 * redirected from Amazon.
	 *
	 * Commit 75cc4f91b534ce3114d19da80586bacd083bb5a8 from WC 3.2 replaces the
	 * REQUEST_URI with `/` so that query string from current URL is not carried.
	 * This plugin hooked into checkout related actions/filters that might be
	 * called from Ajax request and expecting some parameters from query string.
	 *
	 * @since 1.8.0
	 *
	 * @param string $url     Ajax URL.
	 * @param string $request Request type. Expecting only 'checkout'.
	 *
	 * @return string URL.
	 */
	public function filter_ajax_endpoint( $url, $request ) {
		if ( 'checkout' !== $request ) {
			return $url;
		}

		if ( ! empty( $_GET['amazon_payments_advanced'] ) ) {
			$url = add_query_arg( 'amazon_payments_advanced', $_GET['amazon_payments_advanced'], $url );
		}
		if ( ! empty( $_GET['access_token'] ) ) {
			$url = add_query_arg( 'access_token', $_GET['access_token'], $url );
		}

		return $url;
	}

	public function get_gateway() {
		return $this->gateway;
	}
}

/**
 * Return instance of WC_Amazon_Payments_Advanced.
 *
 * @since 1.6.0
 *
 * @return WC_Amazon_Payments_Advanced
 */
function wc_apa() {
	static $plugin;

	if ( ! isset( $plugin ) ) {
		$plugin = new WC_Amazon_Payments_Advanced();
	}

	return $plugin;
}

/**
 * Get order property with compatibility for WC lt 3.0.
 *
 * @since 1.7.0
 *
 * @param WC_Order $order Order object.
 * @param string   $key   Order property.
 *
 * @return mixed Value of order property.
 */
function wc_apa_get_order_prop( $order, $key ) {
	switch ( $key ) {
		case 'order_currency':
			return is_callable( array( $order, 'get_currency' ) ) ? $order->get_currency() : $order->get_order_currency();
			break;
		default:
			$getter = array( $order, 'get_' . $key );
			return is_callable( $getter ) ? call_user_func( $getter ) : $order->{ $key };
	}
}

// Provides backward compatibility.
$GLOBALS['wc_amazon_payments_advanced'] = wc_apa();
