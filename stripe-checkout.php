<?php
/**
 * Plugin Name: Simple Stripe Checkout
 * Description: Integrates Stripe Checkout Sessions with WordPress, using products and shipping ID from Stripe.
 * Version: 1.5
 * Author: Alex Alder
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Include Stripe PHP library
require_once(plugin_dir_path(__FILE__) . 'stripe-php/init.php');

//Start webhooks
require_once(plugin_dir_path(__FILE__) . 'stripe-webhook.php');

class StripeCheckoutIntegration
{
    private $shipping_rate_id;
    private $shipping_rate_info;
    private $enable_invoice_creation;
    private $encryption_key;
    private $product_ids;
    private $webhook_handler;


    public function __construct()
    {
        // Generate or retrieve the encryption key
        $this->encryption_key = $this->get_encryption_key();

        // Initialize properties
        $this->shipping_rate_id = get_option('stripe_shipping_rate_id');
        $this->shipping_rate_info = null;
        $this->enable_invoice_creation = get_option('stripe_enable_invoice_creation', 'no');
        $this->product_ids = get_option('stripe_product_ids', '');
        $this->webhook_handler = new StripeWebhookHandler($this);

        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('rest_api_init', array($this->webhook_handler, 'register_webhook_endpoint'));
        add_action('admin_init', array($this, 'handle_clear_cache_button'));


        // Register shortcode
        add_shortcode('stripe-checkout', array($this, 'stripe_checkout_shortcode'));
        add_shortcode('stripe-checkout-success', array($this, 'stripe_checkout_success_shortcode'));


        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // AJAX actions
        add_action('wp_ajax_fetch_stripe_products', array($this, 'fetch_stripe_products'));
        add_action('wp_ajax_nopriv_fetch_stripe_products', array($this, 'fetch_stripe_products'));
        add_action('wp_ajax_get_stripe_product', array($this, 'get_stripe_product'));
        add_action('wp_ajax_nopriv_get_stripe_product', array($this, 'get_stripe_product'));
        add_action('wp_ajax_create_checkout_session', array($this, 'create_checkout_session'));
        add_action('wp_ajax_nopriv_create_checkout_session', array($this, 'create_checkout_session'));
    }

    private function get_encryption_key()
    {
        $key = get_option('salted_key');
        if (!$key) {
            $key = date('Y-m-d H:i:s');
            update_option('salted_key', $key);
        }
        return $key;
    }

    private function encrypt($value)
    {
        if (!extension_loaded('openssl')) {
            return $value; // Fallback if OpenSSL is not available
        }
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('AES-256-CBC'));
        $encrypted = openssl_encrypt($value, 'AES-256-CBC', $this->encryption_key, 0, $iv);
        return base64_encode($encrypted . '::' . $iv);
    }

    private function decrypt($value)
    {
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

    public function stripe_checkout_success_shortcode()
    {
        // Check if the checkout was successful
        if (isset($_GET['checkout']) && $_GET['checkout'] === 'success') {
            return '<p>Thank you for your purchase! If you do not receive a Stripe receipt via email, please let us know. </p>';
        } else {
            return '<p>Invalid request. Please contact support.</p>';
        }
    }

    private function retrieve_line_items($session_id)
    {
        return \Stripe\Checkout\Session::allLineItems($session_id);
    }

    private function format_amount($amount, $currency)
    {
        return number_format($amount / 100, 2) . ' ' . strtoupper($currency);
    }

    private function get_stripe_secret_key()
    {
        $encrypted_key = get_option('stripe_secret_key_encrypted');
        return $this->decrypt($encrypted_key);
    }
    public function register_settings()
    {
        register_setting('stripe_checkout_options', 'stripe_secret_key_encrypted', array($this, 'encrypt_api_key'));
        register_setting('stripe_checkout_options', 'stripe_shipping_rate_id');
        register_setting('stripe_checkout_options', 'stripe_enable_invoice_creation');
        register_setting('stripe_checkout_options', 'groupme_bot_id');
        register_setting('stripe_checkout_options', 'groupme_group_id');
        register_setting('stripe_checkout_options', 'enable_groupme_notifications');
        register_setting('stripe_checkout_options', 'stripe_product_ids');
        register_setting('stripe_checkout_options', 'stripe_disable_store');
        register_setting('stripe_checkout_options', 'stripe_store_disabled_message');
        register_setting('stripe_checkout_options', 'stripe_webhook_secret_encrypted', array($this, 'encrypt_api_key'));
        register_setting('stripe_checkout_options', 'stripe_timezone');

    }

    public function encrypt_api_key($value)
    {
        return $this->encrypt($value);
    }

    public function add_settings_page()
    {
        add_options_page('Stripe Checkout Settings', 'Stripe Checkout', 'manage_options', 'stripe-checkout-settings', array($this, 'render_settings_page'));
    }

    public function render_settings_page()
    {
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
                        <td><input type="password" name="stripe_secret_key_encrypted"
                                value="<?php echo esc_attr($decrypted_key); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Stripe Webhook Secret</th>
                        <td>
                            <input type="password" name="stripe_webhook_secret_encrypted"
                                value="<?php echo esc_attr($this->decrypt(get_option('stripe_webhook_secret_encrypted'))); ?>" />
                            <p class="description">Enter your Stripe Webhook Secret here. This is required for secure webhook
                                processing.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Stripe Shipping Rate ID</th>
                        <td><input type="text" name="stripe_shipping_rate_id"
                                value="<?php echo esc_attr(get_option('stripe_shipping_rate_id')); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Email Timezone</th>
                        <td>
                            <select name="stripe_timezone">
                                <?php
                                $current_timezone = get_option('stripe_timezone', 'America/Denver');
                                $timezones = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
                                foreach ($timezones as $timezone) {
                                    printf(
                                        '<option value="%s" %s>%s</option>',
                                        esc_attr($timezone),
                                        selected($timezone, $current_timezone, false),
                                        esc_html($timezone)
                                    );
                                }
                                ?>
                            </select>
                            <p class="description">Select the timezone for email notifications and GroupMe messages.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Product IDs</th>
                        <td>
                            <textarea name="stripe_product_ids" rows="5"
                                cols="50"><?php echo esc_textarea(get_option('stripe_product_ids')); ?></textarea>
                            <p class="description">Enter Stripe product IDs, one per line, to be displayed on the frontend.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Disable Store</th>
                        <td>
                            <input type="checkbox" name="stripe_disable_store" value="1" <?php checked(get_option('stripe_disable_store'), 1); ?> />
                            <span class="description">Check this box to disable the store and display a custom message</span>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Store Disabled Message</th>
                        <td>
                            <textarea name="stripe_store_disabled_message" rows="5"
                                cols="50"><?php echo esc_textarea(get_option('stripe_store_disabled_message')); ?></textarea>
                            <p class="description">Enter the message to display when the store is disabled</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Enable Invoice Creation</th>
                        <td>
                            <input type="checkbox" name="stripe_enable_invoice_creation" value="yes" <?php checked(get_option('stripe_enable_invoice_creation'), 'yes'); ?> />
                            <span class="description">Check this box to automatically create invoices for successful
                                payments</span>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Enable GroupMe Notifications</th>
                        <td>
                            <input type="checkbox" name="enable_groupme_notifications" value="1" <?php checked(get_option('enable_groupme_notifications'), 1); ?> />
                            <span class="description">Check this box to send notifications to GroupMe for successful
                                payments</span>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">GroupMe Bot ID</th>
                        <td>
                            <input type="text" name="groupme_bot_id"
                                value="<?php echo esc_attr(get_option('groupme_bot_id')); ?>" />
                            <p class="description">Enter your GroupMe Bot ID here. This is required for GroupMe notifications to
                                work.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">GroupMe Group ID</th>
                        <td>
                            <input type="text" name="groupme_group_id"
                                value="<?php echo esc_attr(get_option('groupme_group_id')); ?>" />
                            <p class="description">Enter your GroupMe Group ID here. This is required to send messages to a
                                specific group.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <h2>Cache Management</h2>
            <form method="post" action="">
                <?php wp_nonce_field('clear_stripe_cache_nonce'); ?>
                <p>
                    <input type="submit" name="clear_stripe_cache" class="button button-secondary" value="Clear Stripe Cache">
                    <span class="description">Click this button to clear the Stripe products and image cache</span>
                </p>
            </form>
        </div>
        <?php
    }

    public function handle_clear_cache_button()
    {
        if (isset($_POST['clear_stripe_cache']) && check_admin_referer('clear_stripe_cache_nonce')) {
            $this->clear_stripe_cache();
            add_settings_error('stripe_checkout_options', 'cache_cleared', 'Stripe cache has been cleared successfully.', 'updated');
        }
    }

    public function fetch_shipping_rate_info()
    {
        if (!empty($this->shipping_rate_id)) {
            $cache_key = 'stripe_shipping_rate_info';
            $cached_info = get_transient($cache_key);

            if ($cached_info !== false) {
                $this->shipping_rate_info = $cached_info;
            } else {
                try {
                    $this->init_stripe();
                    $shipping_rate = \Stripe\ShippingRate::retrieve($this->shipping_rate_id);
                    $this->shipping_rate_info = [
                        'amount' => $shipping_rate->fixed_amount->amount,
                        'currency' => $shipping_rate->fixed_amount->currency,
                        'display_name' => $shipping_rate->display_name
                    ];
                    set_transient($cache_key, $this->shipping_rate_info, 259200); // Cache for 1 hour
                } catch (\Exception $e) {
                    error_log('Error fetching shipping rate: ' . $e->getMessage());
                    $this->shipping_rate_info = null;
                }
            }
        } else {
            $this->shipping_rate_info = null;
        }
    }

    public function get_webhook_secret()
    {
        $encrypted_secret = get_option('stripe_webhook_secret_encrypted');
        return $this->decrypt($encrypted_secret);
    }

    private function init_stripe()
    {
        $stripe_secret_key = $this->get_stripe_secret_key();
        if (!empty($stripe_secret_key)) {
            \Stripe\Stripe::setApiKey($stripe_secret_key);
        }
    }

    public function fetch_stripe_products()
    {
        $cache_key = 'stripe_products_cache';
        $cache_expiration = 259200; // Cache for 1 hour

        // Try to get cached products
        $cached_products = get_transient($cache_key);

        if ($cached_products !== false) {
            wp_send_json_success($cached_products);
            return;
        }

        try {
            $this->init_stripe();
            $product_ids = array_filter(array_map('trim', explode("\n", $this->product_ids)));

            if (empty($product_ids)) {
                wp_send_json_success([]);
                return;
            }

            $formatted_products = [];

            foreach ($product_ids as $product_id) {
                $product = \Stripe\Product::retrieve([
                    'id' => $product_id,
                    'expand' => ['default_price']
                ]);

                $image_url = $product->images[0] ?? null;
                $cached_image_url = $this->cache_image($image_url);

                $formatted_products[] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'price' => $product->default_price ? $product->default_price->unit_amount : null,
                    'currency' => $product->default_price ? $product->default_price->currency : null,
                    'image' => $cached_image_url
                ];
            }

            // Sort products by price (smallest to largest)
            usort($formatted_products, function ($a, $b) {
                return $a['price'] - $b['price'];
            });

            // Cache the formatted products
            set_transient($cache_key, $formatted_products, $cache_expiration);

            wp_send_json_success($formatted_products);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
        wp_die();
    }

    private function cache_image($image_url)
    {
        if (empty($image_url)) {
            return null;
        }

        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/stripe-product-images';
        wp_mkdir_p($cache_dir);

        $file_name = basename($image_url);
        $cached_file_path = $cache_dir . '/' . $file_name;
        $cached_file_url = $upload_dir['baseurl'] . '/stripe-product-images/' . $file_name;

        if (!file_exists($cached_file_path)) {
            $image_data = file_get_contents($image_url);
            if ($image_data !== false) {
                file_put_contents($cached_file_path, $image_data);
            } else {
                return $image_url; // Return original URL if download fails
            }
        }

        return $cached_file_url;
    }

    public function clear_stripe_cache()
    {
        delete_transient('stripe_products_cache');
        delete_transient('stripe_shipping_rate_info');

        // Clear image cache
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/stripe-product-images';

        if (is_dir($cache_dir)) {
            $files = glob($cache_dir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        return true;
    }
    public function get_stripe_product()
    {
        if (!isset($_POST['product_id'])) {
            wp_send_json_error('No product ID provided');
        }

        $product_id = sanitize_text_field($_POST['product_id']);

        // Try to get the product from cache
        $cache_key = 'stripe_products_cache';
        $cached_products = get_transient($cache_key);

        if ($cached_products !== false) {
            // If we have cached products, search for the requested product
            $cached_product = array_filter($cached_products, function ($product) use ($product_id) {
                return $product['id'] === $product_id;
            });

            if (!empty($cached_product)) {
                // Product found in cache, return it
                wp_send_json_success(reset($cached_product));
                return;
            }
        }

        // If not in cache, fetch from Stripe API
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

            // If we have a cached products array, update it with this product
            if ($cached_products !== false) {
                $cached_products = array_filter($cached_products, function ($p) use ($product_id) {
                    return $p['id'] !== $product_id;
                });
                $cached_products[] = $product_data;
                set_transient($cache_key, $cached_products, 86400); // Update cache for 24 hour
            }

            wp_send_json_success($product_data);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
        wp_die();
    }

    public function create_checkout_session()
    {
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
                'success_url' => home_url('/success?checkout=success'),
                'cancel_url' => home_url('/gcstore?checkout=cancelled'),
                'phone_number_collection' => [
                    'enabled' => true,
                ],
                'shipping_address_collection' => [
                    'allowed_countries' => ['US'],
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

    private function group_cart_items($cart)
    {
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

    public function stripe_checkout_shortcode()
    {
        if (get_option('stripe_disable_store', 0) == 1) {
            $disabled_message = get_option('stripe_store_disabled_message', 'The store is currently closed.');
            return '<div class="store-disabled-message">' . wp_kses_post($disabled_message) . '</div>';
        }

        ob_start();
        ?>
        <div id="stripe-checkout-container">
            <h2>Products</h2>
            <div id="product-list"></div>
            <h3>Cart</h3>
            <div id="cart"></div>
            <button id="checkout-button" class="btn btn-filled">Checkout</button>
        </div>
        <?php
        return ob_get_clean();
    }

    public function enqueue_scripts()
    {
        global $post;

        // Only enqueue scripts and localize data if the shortcode is present
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'stripe-checkout')) {
            wp_enqueue_script('stripe-checkout', plugin_dir_url(__FILE__) . 'js/stripe-checkout.js', array('jquery'), '1.6', true);
            wp_enqueue_style('stripe-checkout-style', plugin_dir_url(__FILE__) . 'css/stripe-checkout.css');

            // Fetch shipping rate info
            $this->fetch_shipping_rate_info();

            wp_localize_script('stripe-checkout', 'stripe_checkout_vars', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'shipping_rate_id' => $this->shipping_rate_id,
                'shipping_rate_info' => $this->shipping_rate_info,
                'store_disabled' => get_option('stripe_disable_store', 0)
            ));
        }
    }
}

$stripe_checkout_integration = new StripeCheckoutIntegration();