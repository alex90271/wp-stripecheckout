<?php
/**
 * Plugin Name: Stripe Checkout Integration
 * Description: Integrates Stripe Checkout Sessions with WordPress, including shipping and encrypted API key
 * Version: 1.4
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Include Stripe PHP library
require_once(plugin_dir_path(__FILE__) . 'stripe-php/init.php');

class StripeCheckoutIntegration
{
    private $shipping_rate_id;
    private $shipping_rate_info;
    private $enable_invoice_creation;
    private $encryption_key;

    public function __construct() {
        // Generate or retrieve the encryption key
        $this->encryption_key = $this->get_encryption_key();

        // Initialize properties
        $this->shipping_rate_id = get_option('stripe_shipping_rate_id');
        $this->shipping_rate_info = null;
        $this->enable_invoice_creation = get_option('stripe_enable_invoice_creation', 'no');

        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'add_settings_page'));

        // Register shortcode
        add_shortcode('stripe-checkout', array($this, 'stripe_checkout_shortcode'));

        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // AJAX actions
        add_action('wp_ajax_fetch_stripe_products', array($this, 'fetch_stripe_products'));
        add_action('wp_ajax_nopriv_fetch_stripe_products', array($this, 'fetch_stripe_products'));
        add_action('wp_ajax_get_stripe_product', array($this, 'get_stripe_product'));
        add_action('wp_ajax_nopriv_get_stripe_product', array($this, 'get_stripe_product'));
        add_action('wp_ajax_create_checkout_session', array($this, 'create_checkout_session'));
        add_action('wp_ajax_nopriv_create_checkout_session', array($this, 'create_checkout_session'));

        // Fetch shipping rate info on init
        add_action('init', array($this, 'fetch_shipping_rate_info'));
    }

    private function get_encryption_key() {
        $key = get_option('stripe_encryption_key');
        if (!$key) {
            $key = wp_generate_password(32, true, true);
            update_option('stripe_encryption_key', $key);
        }
        return $key;
    }

    private function encrypt($value) {
        if (!extension_loaded('openssl')) {
            return $value; // Fallback if OpenSSL is not available
        }
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('AES-256-CBC'));
        $encrypted = openssl_encrypt($value, 'AES-256-CBC', $this->encryption_key, 0, $iv);
        return base64_encode($encrypted . '::' . $iv);
    }

    private function decrypt($value) {
        if (!extension_loaded('openssl')) {
            return $value; // Fallback if OpenSSL is not available
        }
        $decoded = base64_decode($value);
        if ($decoded === false) {
            return ''; // Return empty string if decoding fails
        }
        $parts = explode('::', $decoded, 2);
        if (count($parts) !== 2) {
            return ''; // Return empty string if the value is not in the expected format
        }
        list($encrypted_data, $iv) = $parts;
        return openssl_decrypt($encrypted_data, 'AES-256-CBC', $this->encryption_key, 0, $iv);
    }

    private function get_stripe_secret_key() {
        $encrypted_key = get_option('stripe_secret_key_encrypted');
        return $this->decrypt($encrypted_key);
    }

    public function register_settings() {
        register_setting('stripe_checkout_options', 'stripe_secret_key_encrypted', array($this, 'encrypt_api_key'));
        register_setting('stripe_checkout_options', 'stripe_shipping_rate_id');
        register_setting('stripe_checkout_options', 'stripe_enable_invoice_creation');
    }

    public function encrypt_api_key($value) {
        return $this->encrypt($value);
    }

    public function add_settings_page() {
        add_options_page('Stripe Checkout Settings', 'Stripe Checkout', 'manage_options', 'stripe-checkout-settings', array($this, 'render_settings_page'));
    }

    public function render_settings_page() {
        $decrypted_key = $this->get_stripe_secret_key();
        ?>
        <div class="wrap">
            <h1>Stripe Checkout Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('stripe_checkout_options'); ?>
                <?php do_settings_sections('stripe_checkout_options'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Stripe Secret Key</th>
                        <td><input type="password" name="stripe_secret_key_encrypted" value="<?php echo esc_attr($decrypted_key); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Stripe Shipping Rate ID</th>
                        <td><input type="text" name="stripe_shipping_rate_id" value="<?php echo esc_attr(get_option('stripe_shipping_rate_id')); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Enable Invoice Creation</th>
                        <td>
                            <input type="checkbox" name="stripe_enable_invoice_creation" value="yes" <?php checked(get_option('stripe_enable_invoice_creation'), 'yes'); ?> />
                            <span class="description">Check this box to automatically create invoices for successful payments</span>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function fetch_shipping_rate_info() {
        if (!empty($this->shipping_rate_id)) {
            try {
                $this->init_stripe();
                $shipping_rate = \Stripe\ShippingRate::retrieve($this->shipping_rate_id);
                $this->shipping_rate_info = [
                    'amount' => $shipping_rate->fixed_amount->amount,
                    'currency' => $shipping_rate->fixed_amount->currency,
                    'display_name' => $shipping_rate->display_name
                ];
            } catch (\Exception $e) {
                error_log('Error fetching shipping rate: ' . $e->getMessage());
            }
        }
    }

    private function init_stripe() {
        $stripe_secret_key = $this->get_stripe_secret_key();
        if (!empty($stripe_secret_key)) {
            \Stripe\Stripe::setApiKey($stripe_secret_key);
        }
    }

    public function fetch_stripe_products() {
        try {
            $this->init_stripe();
            $products = \Stripe\Product::all([
                'active' => true,
                'limit' => 100,
                'expand' => ['data.default_price']
            ]);

            $formatted_products = array_filter(array_map(function($product) {
                if ($product->name === 'Shipping') {
                    return null;
                }

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'price' => $product->default_price ? $product->default_price->unit_amount : null,
                    'currency' => $product->default_price ? $product->default_price->currency : null,
                    'image' => $product->images[0] ?? null
                ];
            }, $products->data));

            wp_send_json_success(array_values($formatted_products));
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
        wp_die();
    }

    public function get_stripe_product() {
        if (!isset($_POST['product_id'])) {
            wp_send_json_error('No product ID provided');
        }

        $product_id = sanitize_text_field($_POST['product_id']);

        try {
            $this->init_stripe();
            $product = \Stripe\Product::retrieve([
                'id' => $product_id,
                'expand' => ['default_price']
            ]);

            $product_data = [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'price' => $product->default_price->unit_amount,
            ];

            wp_send_json_success($product_data);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
        wp_die();
    }

    public function create_checkout_session() {
        if (!isset($_POST['cart'])) {
            wp_send_json_error('No cart data provided');
        }

        $cart = json_decode(stripslashes($_POST['cart']), true);

        try {
            $this->init_stripe();
            $line_items = $this->group_cart_items($cart);

            $session_params = [
                'payment_method_types' => ['card'],
                'line_items' => $line_items,
                'mode' => 'payment',
                'success_url' => home_url('?checkout=success'),
                'cancel_url' => home_url('?checkout=cancelled'),
                'phone_number_collection' => [
                    'enabled' => true,
                ],
                'shipping_address_collection' => [
                    'allowed_countries' => ['US', 'CA'],
                ],
            ];

            if ($this->enable_invoice_creation === 'yes') {
                $session_params['invoice_creation'] = [
                    'enabled' => true,
                ];
            }

            if (!empty($this->shipping_rate_id)) {
                $session_params['shipping_options'] = [
                    [
                        'shipping_rate' => $this->shipping_rate_id,
                    ],
                ];
            }

            $session = \Stripe\Checkout\Session::create($session_params);

            wp_send_json_success(['url' => $session->url]);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
        wp_die();
    }

    private function group_cart_items($cart) {
        $grouped_cart = [];
        foreach ($cart as $item) {
            $key = $item['id'];
            if (isset($grouped_cart[$key])) {
                $grouped_cart[$key]['quantity'] += 1;
            } else {
                $grouped_cart[$key] = [
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => $item['name'],
                        ],
                        'unit_amount' => $item['price'],
                    ],
                    'quantity' => 1,
                ];
            }
        }
        return array_values($grouped_cart);
    }

    public function stripe_checkout_shortcode() {
        ob_start();
        ?>
        <div id="stripe-checkout-container">
            <h2>Products</h2>
            <div id="product-list"></div>
            <h3>Cart</h3>
            <div id="cart"></div>
            <button id="checkout-button" class="wp-block-button__link wp-element-button">Checkout</button>
        </div>
        <?php
        return ob_get_clean();
    }

    public function enqueue_scripts() {
        wp_enqueue_script('stripe-checkout', plugin_dir_url(__FILE__) . 'js/stripe-checkout.js', array('jquery'), '1.4', true);
        wp_enqueue_style('stripe-checkout-style', plugin_dir_url(__FILE__) . 'css/stripe-checkout.css');

        wp_localize_script('stripe-checkout', 'stripe_checkout_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'shipping_rate_id' => $this->shipping_rate_id,
            'shipping_rate_info' => $this->shipping_rate_info
        ));
    }
}

$stripe_checkout_integration = new StripeCheckoutIntegration();