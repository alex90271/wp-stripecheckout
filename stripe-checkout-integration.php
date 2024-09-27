<?php /**
 * Plugin Name: Stripe Checkout Integration
 * Description: Integrates Stripe Checkout Sessions with WordPress, including shipping
 * Version: 1.1
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Include Stripe PHP library
require_once(plugin_dir_path(__FILE__) . 'stripe-php/init.php');

class StripeCheckoutIntegration {
    private $stripe_secret_key;
    private $shipping_rate_id;

    public function __construct() {
        // Initialize settings
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

        // Initialize Stripe
        $this->init_stripe();
    }

    public function register_settings() {
        register_setting('stripe_checkout_options', 'stripe_secret_key');
        register_setting('stripe_checkout_options', 'stripe_shipping_rate_id');
    }

    public function add_settings_page() {
        add_options_page('Stripe Checkout Settings', 'Stripe Checkout', 'manage_options', 'stripe-checkout-settings', array($this, 'render_settings_page'));
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Stripe Checkout Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('stripe_checkout_options'); ?>
                <?php do_settings_sections('stripe_checkout_options'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Stripe Secret Key</th>
                        <td><input type="password" name="stripe_secret_key" value="<?php echo esc_attr(get_option('stripe_secret_key')); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Stripe Shipping Rate ID</th>
                        <td><input type="text" name="stripe_shipping_rate_id" value="<?php echo esc_attr(get_option('stripe_shipping_rate_id')); ?>" /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function stripe_checkout_shortcode() {
        ob_start();
        ?>
        <div id="stripe-checkout-container">
            <h2>Products</h2>
            <div id="product-list"></div>
            <h3>Cart</h3>
            <div id="cart"></div>
            <button id="checkout-button">Checkout</button>
        </div>
        <?php
        return ob_get_clean();
    }

    public function enqueue_scripts() {
        wp_enqueue_script('stripe-checkout', plugin_dir_url(__FILE__) . 'js/stripe-checkout.js', array('jquery'), '1.1', true);
        wp_enqueue_style('stripe-checkout-style', plugin_dir_url(__FILE__) . 'css/stripe-checkout.css');

        // Pass the AJAX URL and shipping rate ID to our script
        wp_localize_script('stripe-checkout', 'stripe_checkout_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'shipping_rate_id' => get_option('stripe_shipping_rate_id'),
        ));
    }

    private function init_stripe() {
        $this->stripe_secret_key = get_option('stripe_secret_key');
        $this->shipping_rate_id = get_option('stripe_shipping_rate_id');

        if (!empty($this->stripe_secret_key)) {
            \Stripe\Stripe::setApiKey($this->stripe_secret_key);
        }
    }
    public function fetch_stripe_products() {
        try {
            $products = \Stripe\Product::all([
                'active' => true,
                'limit' => 10,
                'expand' => ['data.default_price']
            ]);

            $formatted_products = array_map(function($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'price' => $product->default_price ? $product->default_price->unit_amount : null,
                    'currency' => $product->default_price ? $product->default_price->currency : null
                ];
            }, $products->data);

            wp_send_json_success($formatted_products);
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
            $line_items = array_map(function($item) {
                return [
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => $item['name'],
                        ],
                        'unit_amount' => $item['price'],
                    ],
                    'quantity' => 1,
                ];
            }, $cart);

            $session_params = [
                'payment_method_types' => ['card'],
                'line_items' => $line_items,
                'mode' => 'payment',
                'success_url' => home_url('?checkout=success'),
                'cancel_url' => home_url('?checkout=cancelled'),
            ];

            // Add shipping options if a shipping rate ID is set
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
}

$stripe_checkout_integration = new StripeCheckoutIntegration();