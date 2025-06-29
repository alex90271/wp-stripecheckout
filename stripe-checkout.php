<?php
/**
 * Plugin Name: Simple Stripe Checkout
 * Description: Integrates Stripe Checkout Sessions with WordPress, using products and shipping ID from Stripe.
 * Version: 3.1
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
    public $VER;


    public function __construct()
    {
        // Generate or retrieve the encryption key
        $this->encryption_key = $this->get_encryption_key();

        $this->VER = 3.1;

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
        add_action('admin_post_create_checkout_session', array($this, 'handle_checkout_submission'));
        add_action('admin_post_nopriv_create_checkout_session', array($this, 'handle_checkout_submission'));

        // Register cron event
        add_action('init', array($this, 'register_cron_refresh'));
        add_action('stripe_refresh_data', array($this, 'refresh_expired_data'));
        
        // Register deactivation hook to clean up cron
        register_deactivation_hook(__FILE__, array($this, 'deactivate_cron_refresh'));

        register_activation_hook(__FILE__, array($this, 'plugin_activation'));
        register_deactivation_hook(__FILE__, array($this, 'plugin_deactivation'));
        add_filter('the_content', array($this, 'modify_store_page_content'));

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

    private function format_amount($amount, $currency)
    {
        return number_format($amount / 100, 2) . ' ' . strtoupper($currency);
    }

    public function get_stripe_secret_key()
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
        register_setting('stripe_checkout_options', 'stripe_checkout_receipt_message');
        register_setting('stripe_checkout_options', 'stripe_checkout_terms_message');
        register_setting('stripe_checkout_options', 'stripe_checkout_shipping_message');
        register_setting('stripe_checkout_options', 'stripe_max_quantity_per_item', array(
            'default' => 10,
            'sanitize_callback' => array($this, 'sanitize_max_quantity')
        ));
        register_setting('stripe_checkout_options', 'stripe_store_title', array(
            'default' => 'Store',
            'sanitize_callback' => 'wp_kses_post'
        ));
        register_setting('stripe_checkout_options', 'stripe_store_description', array(
            'default' => '',
            'sanitize_callback' => 'wp_kses_post'
        ));

    }

    public function plugin_activation()
    {
        if (!$this->get_store_page_id()) {
            $page_data = array(
                'post_title' => 'Stripe Store',
                'post_name' => $this->page_slug,
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_content' => '<!-- wp:paragraph -->This page is managed by the Stripe Store plugin. To modify anything please go to Settings then Stripe Checkout<!-- /wp:paragraph -->'
            );

            $page_id = wp_insert_post($page_data);

            if (!is_wp_error($page_id)) {
                update_option('stripe_store_page_id', $page_id);
            }
        }
    }

    public function plugin_deactivation()
    {
        $page_id = $this->get_store_page_id();
        if ($page_id) {
            wp_delete_post($page_id, true);
            delete_option('stripe_store_page_id');
        }
    }

    private function get_store_page_id()
    {
        $page_id = get_option('stripe_store_page_id');
        if (!$page_id) {
            $page = get_page_by_path($this->page_slug);
            if ($page) {
                $page_id = $page->ID;
                update_option('stripe_store_page_id', $page_id);
            }
        }
        return $page_id;
    }

    public function modify_store_page_content($content)
    {
        if (is_page() && get_the_ID() == $this->get_store_page_id()) {
            if (get_option('stripe_disable_store', 0) == 1) {
                $disabled_message = get_option('stripe_store_disabled_message', 'The store is currently closed.');
                return '<div class="store-disabled-message">' . wp_kses_post($disabled_message) . '</div>';
            }

            if (isset($_GET['checkout']) && $_GET['checkout'] === 'success') {
                return '<p>Thank you for your purchase! If you do not receive a Stripe receipt via email, please let us know.</p><p><a href="/stripe-store">Return to store</a></p>';
            }

            $store_title = get_option('stripe_store_title', 'Store');
            $store_description = get_option('stripe_store_description', '');

            $output = '<div>';

            if (!empty($store_title)) {
                $output .= '<h1 class="store-title">' . wp_kses_post($store_title) . '</h1>';
            }

            if (!empty($store_description)) {
                $output .= '<div class="store-description">' . wp_kses_post($store_description) . '</div></div>';
            }

            $output .= '<div class="checkout-container" id="stripe-checkout-container">';

            $output .= '<div class="products">
                    <h2>Products</h2>
                    <div class="product-grid" id="product-list"></div>
                </div>
                <div class="cart">
                    <h3>Cart</h3>
                    <div id="cart"></div>
                    <button id="checkout-button" class="btn btn-filled">Checkout</button>
                </div>
            </div>';

            return $output;
        }
        return $content;
    }

    public function encrypt_api_key($value)
    {
        return $this->encrypt($value);
    }

    public function add_settings_page()
    {
        add_options_page('Stripe Checkout Settings', 'Stripe Checkout', 'manage_options', 'stripe-checkout-settings', array($this, 'render_settings_page'));
    }
    public function sanitize_max_quantity($value)
    {
        $value = absint($value); // Convert to positive integer
        if ($value < 1) {
            $value = 1;
        } elseif ($value > 99) {
            $value = 99;
        }
        return $value;
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

                <h2>Stripe API Configuration</h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Stripe Restricted Key</th>
                        <td><input type="password" name="stripe_secret_key_encrypted"
                                value="<?php echo esc_attr($decrypted_key); ?>" />
                            <p class="description">Please use a restricted API key<br><strong>Note: </strong>Key must have the
                                following permissions: <i>Read Products; Write Checkout Sessions; Invoices Write</i></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Stripe Webhook Secret</th>
                        <td>
                            <input type="password" name="stripe_webhook_secret_encrypted"
                                value="<?php echo esc_attr($this->decrypt(get_option('stripe_webhook_secret_encrypted'))); ?>" />
                            <p class="description">This is required to get admin notification emails and groupme
                                messages<br><strong>Note: </strong>This does not affect the send receipt setting in stripe
                                dashboard</p>
                        </td>
                    </tr>
                </table>

                <h2>Store Configuration</h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Store Products</th>
                        <td>
                            <a href="<?php echo admin_url('options-general.php?page=stripe-products'); ?>" class="button">
                                Manage Store Products
                            </a>
                            <p class="description">Click here to select which Stripe products to display in your store.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Stripe Shipping Rate ID</th>
                        <td><input type="text" name="stripe_shipping_rate_id"
                                value="<?php echo esc_attr(get_option('stripe_shipping_rate_id')); ?>" />
                            <p class="description">If left blank, shipping rate will display as $0.00
                            <p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Enable Invoice Creation</th>
                        <td>
                            <input type="checkbox" name="stripe_enable_invoice_creation" value="yes" <?php checked(get_option('stripe_enable_invoice_creation'), 'yes'); ?> />
                            <span class="description">Automatically create invoices for successful
                                payments (Stripe may charge additonal to generate invoices)</span>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Disable Store</th>
                        <td>
                            <input type="checkbox" name="stripe_disable_store" value="1" <?php checked(get_option('stripe_disable_store'), 1); ?> />
                            <span class="description">Disable the store and display the message entered below</span>
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
                        <th scope="row">Maximum Quantity Per Item</th>
                        <td>
                            <input type="number" name="stripe_max_quantity_per_item"
                                value="<?php echo esc_attr(get_option('stripe_max_quantity_per_item', 10)); ?>" min="1"
                                max="99" />
                            <p class="description">Maximum quantity allowed per item in cart (1-99)</p>
                        </td>
                    </tr>
                </table>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Store Page Title</th>
                        <td>
                            <input type="text" name="stripe_store_title"
                                value="<?php echo esc_attr(get_option('stripe_store_title', 'Store')); ?>"
                                class="regular-text" />
                            <p class="description">Enter the title to display at the top of your store page</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Store Description</th>
                        <td>
                            <?php
                            $description = get_option('stripe_store_description', '');
                            wp_editor($description, 'stripe_store_description', array(
                                'textarea_name' => 'stripe_store_description',
                                'textarea_rows' => 5,
                                'media_buttons' => false,
                                'teeny' => true,
                                'quicktags' => true
                            ));
                            ?>
                            <p class="description">Enter a description to display below the title (HTML tags allowed)</p>
                        </td>
                    </tr>
                </table>
                <h2>Checkout Messages</h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Receipt Email Message</th>
                        <td>
                            <textarea name="stripe_checkout_receipt_message" rows="2"
                                cols="50"><?php echo esc_textarea(get_option('stripe_checkout_receipt_message', 'A receipt will be sent to the email address listed above')); ?></textarea>
                            <p class="description">Message shown above the email field during checkout.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Terms of Service Message</th>
                        <td>
                            <textarea name="stripe_checkout_terms_message" rows="2"
                                cols="50"><?php echo esc_textarea(get_option('stripe_checkout_terms_message', 'I agree to the {site_name} terms of service located at {site_url}')); ?></textarea>
                            <p class="description">Terms of service acceptance message. Use {site_name} and {site_url} as
                                placeholders.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Shipping Information Message</th>
                        <td>
                            <textarea name="stripe_checkout_shipping_message" rows="2"
                                cols="50"><?php echo esc_textarea(get_option('stripe_checkout_shipping_message', 'Orders are shipped the next business day via USPS. Please allow 5-10 days')); ?></textarea>
                            <p class="description">Message shown on the submit button page.</p>
                        </td>
                    </tr>
                </table>
                <h2>Notification Settings</h2>
                <table class="form-table">
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
                </table>

                <h2>GroupMe Integration</h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Enable GroupMe Notifications</th>
                        <td>
                            <input type="checkbox" name="enable_groupme_notifications" value="1" <?php checked(get_option('enable_groupme_notifications'), 1); ?> />
                            <span class="description">Send notifications to GroupMe for successful
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
                    <span class="description">Clear products and image cache. You will need to clear the website hosting cache
                        (if applicable)
                        hours<br></span>
                    <input type="submit" name="clear_stripe_cache" class="button button-secondary" value="Clear Stripe Cache">
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
                    set_transient($cache_key, $this->shipping_rate_info, 21600);
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

    public function init_stripe()
    {
        $stripe_secret_key = $this->get_stripe_secret_key();
        if (!empty($stripe_secret_key)) {
            \Stripe\Stripe::setApiKey($stripe_secret_key);
        }
    }

    public function fetch_stripe_products()
    {
        check_ajax_referer('fetch_products_nonce');
        $cache_key = 'stripe_products_cache';

        // Try to get cached products
        $cached_products = get_transient($cache_key);

        if ($cached_products !== false) {
            wp_send_json_success($cached_products);
            return;
        }

        try {
            $this->init_stripe();
            // Get the enabled product IDs
            $product_ids = array_filter(
                explode("\n", get_option('stripe_product_ids', '')),
                'trim'
            );

            if (empty($product_ids)) {
                wp_send_json_success([]);
                return;
            }

            $formatted_products = [];

            foreach ($product_ids as $product_id) {
                try {
                    $product = \Stripe\Product::retrieve([
                        'id' => $product_id,
                        'expand' => ['default_price']
                    ]);

                    // Only add active products that have a default price
                    if ($product->active && $product->default_price) {
                        $image_url = $product->images[0] ?? null;
                        $cached_image_url = $this->cache_image($image_url);

                        $formatted_products[] = [
                            'id' => $product->id,
                            'name' => $product->name,
                            'description' => $product->description,
                            'price' => $product->default_price->unit_amount,
                            'currency' => $product->default_price->currency,
                            'image' => $cached_image_url
                        ];
                    }
                } catch (\Exception $e) {
                    // Log error but continue processing other products
                    error_log('Error fetching Stripe product ' . $product_id . ': ' . $e->getMessage());
                    continue;
                }
            }

            // Sort products by price
            usort($formatted_products, function ($a, $b) {
                return $a['price'] - $b['price'];
            });

            // Cache the formatted products for 6 hours
            set_transient($cache_key, $formatted_products, 21600);

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
        check_ajax_referer('get_product_nonce');

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
                set_transient($cache_key, $cached_products);
            }

            wp_send_json_success($product_data);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
        wp_die();
    }

    public function create_checkout_session()
    {
        check_ajax_referer('checkout_nonce');

        if (!isset($_POST['cart'])) {
            wp_send_json_error('No cart data provided');
        }

        $cart = json_decode(stripslashes($_POST['cart']), true);

        try {
            $this->init_stripe();
            $line_items = $this->group_cart_items($cart);

            // Get custom messages from options
            $receipt_message = get_option('stripe_checkout_receipt_message');
            if (!$receipt_message || $receipt_message == " ") {
                $receipt_message = 'You may receive communications about your order at the email address provided above';
            }
            
            $terms_message = get_option('stripe_checkout_terms_message');
            if (!$terms_message || $terms_message == " ") {
                $terms_message = 'I agree to the Terms, Privacy, and Return policies (linked below)';
            }
            
            $shipping_message = get_option('stripe_checkout_shipping_message');
            if (!$shipping_message || $shipping_message == " " ){
                $shipping_message = 'Orders are generally processed by the next business day';
            }

            // Replace placeholders in terms message
            $terms_message = str_replace(
                ['{site_name}', '{site_url}'],
                [get_bloginfo('name'), get_site_url()],
                $terms_message
            );

            $session_params = [
                'line_items' => $line_items,
                'mode' => 'payment',
                'success_url' => home_url('/stripe-store?checkout=success'),
                'cancel_url' => home_url('/stripe-store?checkout=cancelled'),
                'phone_number_collection' => [
                    'enabled' => true,
                ],
                'consent_collection' => [
                    'terms_of_service' => 'required',
                ],
                'custom_text' => [
                    'shipping_address' => [
                        'message' => $receipt_message
                    ],
                    'terms_of_service_acceptance' => [
                        'message' => $terms_message
                    ],
                    'submit' => [
                        'message' => $shipping_message
                    ],
                ],
                "submit_type" => 'pay',
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
        $max_quantity = get_option('stripe_max_quantity_per_item', 10);

        foreach ($cart as $item) {
            $key = $item['id'];

            // Validate individual item quantity
            if ($item['quantity'] > $max_quantity) {
                wp_send_json_error("Maximum quantity of {$max_quantity} per item exceeded");
                return;
            }

            if (isset($grouped_cart[$key])) {
                $grouped_cart[$key]['quantity'] += $item['quantity'];
                // Check combined quantity if same item appears multiple times
                if ($grouped_cart[$key]['quantity'] > $max_quantity) {
                    wp_send_json_error("Maximum quantity of {$max_quantity} per item exceeded");
                    return;
                }
            } else {
                $product = \Stripe\Product::retrieve([
                    'id' => $key,
                    'expand' => ['default_price']
                ]);

                $grouped_cart[$key] = [
                    'price' => $product->default_price->id,
                    'quantity' => $item['quantity'],
                    'adjustable_quantity' => [
                        'enabled' => true,
                        'minimum' => 1,
                        'maximum' => $max_quantity
                    ]
                ];
            }
        }
        return array_values($grouped_cart);
    }

    public function enqueue_scripts() {
        if (is_page() && get_the_ID() == $this->get_store_page_id()) {
            wp_enqueue_script('stripe-checkout', plugin_dir_url(__FILE__) . 'js/stripe-checkout.js', array('jquery'), $this->VER, true);
            wp_enqueue_style('stripe-checkout-style', plugin_dir_url(__FILE__) . 'css/stripe-checkout.css', '', $this->VER);
    
            $this->fetch_shipping_rate_info();
            
            // Fetch products and prepare them for JavaScript
            $products = $this->get_products_for_frontend();
    
            wp_localize_script('stripe-checkout', 'stripe_checkout_vars', array(
                'products' => $products,
                'shipping_rate_id' => $this->shipping_rate_id,
                'shipping_rate_info' => $this->shipping_rate_info,
                'store_disabled' => get_option('stripe_disable_store', 0),
                'max_quantity_per_item' => get_option('stripe_max_quantity_per_item', 10),
                'checkout_url' => esc_url(admin_url('admin-post.php?action=create_checkout_session'))
            ));
        }
    }

    public function handle_checkout_submission() {
        if (!isset($_POST['cart_data'])) {
            wp_redirect(home_url('/stripe-store?error=invalid_cart'));
            exit;
        }
    
        try {
            $cart = json_decode(stripslashes($_POST['cart_data']), true);
            $this->init_stripe();
            $line_items = $this->group_cart_items($cart);
    
            // Get custom messages from options
            $receipt_message = get_option('stripe_checkout_receipt_message', ' ');
            $terms_message = get_option('stripe_checkout_terms_message', 'I agree to any terms of service and refund policy');
            $shipping_message = get_option('stripe_checkout_shipping_message', 'Please allow 5-10 days for orders to arrive');
    
            // Replace placeholders in terms message
            $terms_message = str_replace(
                ['{site_name}', '{site_url}'],
                [get_bloginfo('name'), get_site_url()],
                $terms_message
            );
    
            $session_params = [
                'line_items' => $line_items,
                'mode' => 'payment',
                'success_url' => home_url('/stripe-store?checkout=success'),
                'cancel_url' => home_url('/stripe-store?checkout=cancelled'),
                'phone_number_collection' => [
                    'enabled' => true,
                ],
                'consent_collection' => [
                    'terms_of_service' => 'required',
                ],
                'custom_text' => [
                    'shipping_address' => [
                        'message' => $receipt_message
                    ],
                    'terms_of_service_acceptance' => [
                        'message' => $terms_message
                    ],
                    'submit' => [
                        'message' => $shipping_message
                    ],
                ],
                "submit_type" => 'pay',
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
    
            wp_redirect($session->url);
            exit;
    
        } catch (\Exception $e) {
            error_log('Checkout error: ' . $e->getMessage());
            wp_redirect(home_url('/stripe-store?error=checkout_failed'));
            exit;
        }
    }
    
    private function get_products_for_frontend() {
        $cache_key = 'stripe_products_cache';
        $cached_products = get_transient($cache_key);
    
        if ($cached_products !== false) {
            return $cached_products;
        }
    
        try {
            $this->init_stripe();
            $product_ids = array_filter(
                explode("\n", get_option('stripe_product_ids', '')),
                'trim'
            );
    
            if (empty($product_ids)) {
                return [];
            }
    
            $formatted_products = [];
    
            foreach ($product_ids as $product_id) {
                try {
                    $product = \Stripe\Product::retrieve([
                        'id' => $product_id,
                        'expand' => ['default_price']
                    ]);
    
                    if ($product->active && $product->default_price) {
                        $image_url = $product->images[0] ?? null;
                        $cached_image_url = $this->cache_image($image_url);
    
                        $formatted_products[] = [
                            'id' => $product->id,
                            'name' => $product->name,
                            'description' => $product->description,
                            'price' => $product->default_price->unit_amount,
                            'currency' => $product->default_price->currency,
                            'image' => $cached_image_url
                        ];
                    }
                } catch (\Exception $e) {
                    error_log('Error fetching Stripe product ' . $product_id . ': ' . $e->getMessage());
                    continue;
                }
            }
    
            // Sort products by price
            usort($formatted_products, function($a, $b) {
                return $a['price'] - $b['price'];
            });
    
            set_transient($cache_key, $formatted_products, 21600); // 6 hours cache
            return $formatted_products;
        } catch (\Exception $e) {
            error_log('Error fetching Stripe products: ' . $e->getMessage());
            return [];
        }
    }
    public function register_cron_refresh()
    {
        if (!wp_next_scheduled('stripe_refresh_data')) {
            wp_schedule_event(time(), 'hourly', 'stripe_refresh_data');
        }
    }

    public function deactivate_cron_refresh()
    {
        wp_clear_scheduled_hook('stripe_refresh_data');
    }

    public function refresh_expired_data()
    {
        // Check and refresh products cache if expired
        $products_cache = get_transient('stripe_products_cache');
        if ($products_cache === false) {
            try {
                $this->init_stripe();
                $product_ids = array_filter(
                    explode("\n", get_option('stripe_product_ids', '')),
                    'trim'
                );

                if (!empty($product_ids)) {
                    $formatted_products = [];

                    foreach ($product_ids as $product_id) {
                        try {
                            $product = \Stripe\Product::retrieve([
                                'id' => $product_id,
                                'expand' => ['default_price']
                            ]);

                            if ($product->active && $product->default_price) {
                                $image_url = $product->images[0] ?? null;
                                $cached_image_url = $this->cache_image($image_url);

                                $formatted_products[] = [
                                    'id' => $product->id,
                                    'name' => $product->name,
                                    'description' => $product->description,
                                    'price' => $product->default_price->unit_amount,
                                    'currency' => $product->default_price->currency,
                                    'image' => $cached_image_url
                                ];
                            }
                        } catch (\Exception $e) {
                            error_log('Error refreshing Stripe product ' . $product_id . ': ' . $e->getMessage());
                            continue;
                        }
                    }

                    // Sort products by price
                    usort($formatted_products, function ($a, $b) {
                        return $a['price'] - $b['price'];
                    });

                    set_transient('stripe_products_cache', $formatted_products, 21600); // 6 hours
                }
            } catch (\Exception $e) {
                error_log('Error in cron refresh of Stripe products: ' . $e->getMessage());
            }
        }

        // Check and refresh shipping rate info if expired
        $shipping_rate_info = get_transient('stripe_shipping_rate_info');
        if ($shipping_rate_info === false && !empty($this->shipping_rate_id)) {
            try {
                $this->init_stripe();
                $shipping_rate = \Stripe\ShippingRate::retrieve($this->shipping_rate_id);
                $shipping_rate_info = [
                    'amount' => $shipping_rate->fixed_amount->amount,
                    'currency' => $shipping_rate->fixed_amount->currency,
                    'display_name' => $shipping_rate->display_name
                ];
                set_transient('stripe_shipping_rate_info', $shipping_rate_info, 21600); // 6 hours
            } catch (\Exception $e) {
                error_log('Error in cron refresh of shipping rate: ' . $e->getMessage());
            }
        }
    }
}

// At the end of your file, after the closing bracket of StripeCheckoutIntegration class
// but before the final line that creates the instance

class StripeProductManager
{
    private $parent;

    public function __construct($parent)
    {
        $this->parent = $parent;
        add_action('admin_menu', array($this, 'add_product_manager_page'));
        add_action('wp_ajax_toggle_stripe_product', array($this, 'toggle_stripe_product'));
    }

    public function add_product_manager_page()
    {
        add_submenu_page(
            'options-general.php',
            'Stripe Products',
            'Stripe Products',
            'manage_options',
            'stripe-products',
            array($this, 'render_product_manager_page')
        );
    }

    public function render_product_manager_page()
    {
        // Check if API key is set
        if (empty($this->parent->get_stripe_secret_key())) {
            ?>
            <div class="wrap">
                <h1>Stripe Products</h1>
                <div class="notice notice-error">
                    <p>Please set up your Stripe API key in the <a
                            href="<?php echo admin_url('options-general.php?page=stripe-checkout-settings'); ?>">Stripe Checkout
                            Settings</a> before managing products.</p>
                </div>
            </div>
            <?php
            return;
        }

        // Get active product IDs
        $active_products = array_filter(array_map('trim', explode("\n", get_option('stripe_product_ids', ''))));

        try {
            $this->parent->init_stripe();
            $products = \Stripe\Product::all([
                'active' => true,
                'limit' => 100,
                'expand' => ['data.default_price']
            ]);
        } catch (\Exception $e) {
            ?>
            <div class="wrap">
                <h1>Stripe Products</h1>
                <div class="notice notice-error">
                    <p>Error fetching products: <?php echo esc_html($e->getMessage()); ?></p>
                </div>
            </div>
            <?php
            return;
        }

        ?>
        <div class="wrap">
            <h1>Stripe Products</h1>

            <div class="notice notice-info">
                <p>Select the products you want to display in your store. Changes are saved automatically.</p>
            </div>

            <div class="search-box" style="margin: 20px 0;">
                <input type="text" id="productSearch" class="regular-text" placeholder="Search products by name or ID..."
                    style="width: 300px; padding: 8px;">
            </div>

            <style>
                .stripe-products-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 20px;
                }

                .stripe-products-table th,
                .stripe-products-table td {
                    padding: 12px;
                    text-align: left;
                    border-bottom: 1px solid #ddd;
                }

                .stripe-products-table th {
                    background: #f5f5f5;
                }

                .product-image {
                    width: 50px;
                    height: 50px;
                    object-fit: cover;
                    border-radius: 4px;
                }

                .price-column {
                    width: 150px;
                }

                .toggle-column {
                    width: 100px;
                }

                .switch {
                    position: relative;
                    display: inline-block;
                    width: 60px;
                    height: 34px;
                }

                .switch input {
                    opacity: 0;
                    width: 0;
                    height: 0;
                }

                .slider {
                    position: absolute;
                    cursor: pointer;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background-color: #ccc;
                    -webkit-transition: .4s;
                    transition: .4s;
                    border-radius: 34px;
                }

                .slider:before {
                    position: absolute;
                    content: "";
                    height: 26px;
                    width: 26px;
                    left: 4px;
                    bottom: 4px;
                    background-color: white;
                    -webkit-transition: .4s;
                    transition: .4s;
                    border-radius: 50%;
                }

                input:checked+.slider {
                    background-color: #2196F3;
                }

                input:checked+.slider:before {
                    -webkit-transform: translateX(26px);
                    -ms-transform: translateX(26px);
                    transform: translateX(26px);
                }

                .loading {
                    opacity: 0.5;
                    pointer-events: none;
                }

                .no-results {
                    display: none;
                    padding: 20px;
                    text-align: center;
                    background: #f9f9f9;
                }

                tr.hidden {
                    display: none;
                }
            </style>

            <table class="stripe-products-table">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Product</th>
                        <th class="price-column">Price</th>
                        <th class="toggle-column">Show in Store</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product):
                        $price = 'No price set';
                        $currency = 'USD';
                        if ($product->default_price) {
                            $unit_amount = $product->default_price->unit_amount;
                            $currency = $product->default_price->currency;
                            $price = $unit_amount ? number_format($unit_amount / 100, 2) : 'No price set';
                        }
                        $image_url = !empty($product->images) ? $product->images[0] : 'https://placehold.co/50';
                        $is_active = in_array($product->id, $active_products);
                        ?>
                        <tr data-product-name="<?php echo esc_attr(strtolower($product->name)); ?>"
                            data-product-id="<?php echo esc_attr(strtolower($product->id)); ?>">
                            <td><img src="<?php echo esc_url($image_url); ?>" class="product-image" /></td>
                            <td>
                                <strong><?php echo esc_html($product->name); ?></strong>
                                <br>
                                <small><?php echo esc_html($product->id); ?></small>
                            </td>
                            <td>
                                <?php if ($price !== 'No price set'): ?>
                                    $<?php echo esc_html($price); ?>
                                <?php else: ?>
                                    <?php echo esc_html($price); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="product-toggle"
                                        data-product-id="<?php echo esc_attr($product->id); ?>" <?php checked($is_active); ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="no-results">
                No products found matching your search.
            </div>

            <script>
                jQuery(document).ready(function ($) {
                    // Toggle functionality
                    $('.product-toggle').on('change', function () {
                        const $checkbox = $(this);
                        const $row = $checkbox.closest('tr');
                        const productId = $checkbox.data('product-id');
                        const isActive = $checkbox.prop('checked');

                        $row.addClass('loading');

                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'toggle_stripe_product',
                                product_id: productId,
                                is_active: isActive,
                                nonce: '<?php echo wp_create_nonce("toggle_stripe_product"); ?>'
                            },
                            success: function (response) {
                                if (!response.success) {
                                    alert('Error updating product status');
                                    $checkbox.prop('checked', !isActive);
                                }
                            },
                            error: function () {
                                alert('Error updating product status');
                                $checkbox.prop('checked', !isActive);
                            },
                            complete: function () {
                                $row.removeClass('loading');
                            }
                        });
                    });

                    // Search functionality
                    $('#productSearch').on('input', function () {
                        const searchTerm = $(this).val().toLowerCase();
                        let hasResults = false;

                        $('.stripe-products-table tbody tr').each(function () {
                            const $row = $(this);
                            const productName = $row.data('product-name');
                            const productId = $row.data('product-id');

                            if (productName.includes(searchTerm) || productId.includes(searchTerm)) {
                                $row.removeClass('hidden');
                                hasResults = true;
                            } else {
                                $row.addClass('hidden');
                            }
                        });

                        $('.no-results').toggle(!hasResults);
                    });
                });
            </script>
        </div>
        <?php
    }

    public function toggle_stripe_product()
    {
        check_ajax_referer('toggle_stripe_product', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $product_id = sanitize_text_field($_POST['product_id']);
        $is_active = $_POST['is_active'] === 'true';

        // Get current product IDs as array
        $current_products = array_filter(
            explode("\n", get_option('stripe_product_ids', '')),
            'trim'
        );

        if ($is_active) {
            // Add product if not already in array
            if (!in_array($product_id, $current_products)) {
                $current_products[] = $product_id;
            }
        } else {
            // Remove product if in array
            $current_products = array_values(array_diff($current_products, [$product_id]));
        }

        // Convert back to string and update option
        $updated_products = implode("\n", array_filter($current_products));
        $success = update_option('stripe_product_ids', $updated_products);

        // Clear the products cache
        delete_transient('stripe_products_cache');

        if ($success) {
            wp_send_json_success(['message' => 'Products updated successfully']);
        } else {
            wp_send_json_error(['message' => 'Failed to update products']);
        }
    }
}
// Then modify your existing line at the bottom of the file that creates the instance:
$stripe_checkout_integration = new StripeCheckoutIntegration();
$stripe_product_manager = new StripeProductManager($stripe_checkout_integration);