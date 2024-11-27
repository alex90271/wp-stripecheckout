<?php
/**
 * Stripe Webhook Handler
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class StripeWebhookHandler {
    private $stripe_checkout_integration;
    private $max_log_length = 100; // Maximum length for logged values
    private $allowed_events = ['checkout.session.completed']; // Whitelist of allowed event types

    public function __construct($stripe_checkout_integration) {
        $this->stripe_checkout_integration = $stripe_checkout_integration;
    }

    public function register_webhook_endpoint() {
        register_rest_route('stripe-checkout/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => array($this, 'verify_webhook_request')
        ));
    }

    /**
     * Verify the webhook request is valid
     */
    public function verify_webhook_request() {
        // Only allow POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return false;
        }

        // Verify content type
        if (!isset($_SERVER['CONTENT_TYPE']) || 
            strpos($_SERVER['CONTENT_TYPE'], 'application/json') === false) {
            return false;
        }

        // Verify Stripe signature header exists
        if (!isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
            return false;
        }

        return true;
    }

    /**
     * Safely truncate data for logging
     */
    private function safe_log_data($data, $length = null) {
        if ($length === null) {
            $length = $this->max_log_length;
        }
        return substr(sanitize_text_field($data), 0, $length);
    }

    /**
     * Sanitize and format customer data
     */
    private function sanitize_customer_data($customer) {
        return array(
            'name' => isset($customer->name) ? sanitize_text_field($customer->name) : '',
            'email' => isset($customer->email) ? sanitize_email($customer->email) : '',
            // Add only necessary fields and sanitize them
        );
    }

    private function prepare_email_content($session) {
        $customer = $this->sanitize_customer_data($session->customer_details);
        $payment_intent = sanitize_text_field($session->payment_intent);
        
        try {
            $timezone = new DateTimeZone(get_option('stripe_timezone', 'America/Denver'));
            $date = (new DateTime('@' . $session->created))
                ->setTimezone($timezone)
                ->format('m/d/Y g:ia');
        } catch (Exception $e) {
            $date = 'Date Error'; // Fallback if timezone is invalid
            error_log('Timezone error in webhook: ' . $e->getMessage());
        }

        $amount = isset($session->amount_total) ? 
            number_format(abs((float)$session->amount_total) / 100, 2) : '0.00';

        $content = sprintf(
            '<html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    table { border-collapse: collapse; width: 100%%; }
                    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                    th { background-color: #f2f2f2; }
                </style>
            </head>
            <body>
                <p><strong>Order Date:</strong> %s</p>
                <p><strong>Billed to:</strong> %s (%s)</p>
                <p><strong>Total Amount:</strong> $%s</p>
                <p><strong>Stripe ID:</strong> <a href="https://dashboard.stripe.com/payments/%s">%s</a></p>
            </body>
            </html>',
            esc_html($date),
            esc_html($customer['name']),
            esc_html($customer['email']),
            esc_html($amount),
            esc_attr($payment_intent),
            esc_html($payment_intent)
        );

        return $content;
    }

    function send_groupme_message($session) {
        $bot_id = get_option('groupme_bot_id');
        if (empty($bot_id)) {
            error_log('GroupMe Bot ID is not set');
            return false;
        }

        try {
            $timezone = new DateTimeZone(get_option('stripe_timezone', 'America/Denver'));
            $date = (new DateTime('@' . $session->created))
                ->setTimezone($timezone)
                ->format('m/d/Y g:ia');
        } catch (Exception $e) {
            $date = 'Date Error';
            error_log('Timezone error in GroupMe notification: ' . $e->getMessage());
        }

        $customer = $this->sanitize_customer_data($session->customer_details);

        $amount = isset($session->amount_total) ? 
            number_format(abs((float)$session->amount_total) / 100, 2) : '0.00';

        // Format message with only necessary information
        $message = sprintf(
            "New Order!\nDate: %s\nTotal Amount: $%s\nID: %s",
            $date,
            $customer['name'],
            $amount,
            sanitize_text_field($session->payment_intent)
        );

        $url = 'https://api.groupme.com/v3/bots/post';
        $data = array(
            'bot_id' => sanitize_text_field($bot_id),
            'text' => $message,
        );

        $response = wp_remote_post($url, array(
            'body' => json_encode($data),
            'headers' => array('Content-Type' => 'application/json'),
            'timeout' => 15,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            error_log('GroupMe API error: ' . $this->safe_log_data($response->get_error_message()));
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 202) {
            error_log('GroupMe API returned unexpected status: ' . $response_code);
            return false;
        }

        return true;
    }

    public function handle_webhook($request) {
        try {
            $payload = $request->get_body();
            $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
            $endpoint_secret = $this->stripe_checkout_integration->get_webhook_secret();

            if (empty($endpoint_secret)) {
                throw new Exception('Webhook secret is not configured');
            }

            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sig_header,
                $endpoint_secret
            );

            // Verify event type is allowed
            if (!in_array($event->type, $this->allowed_events, true)) {
                return new WP_REST_Response(
                    'Event type not supported', 
                    400
                );
            }

            if ($event->type === 'checkout.session.completed') {
                $session = $event->data->object;

                // Send email notification
                $to = get_option('admin_email');
                if (!empty($to)) {
                    $subject = 'New Successful Stripe Checkout';
                    $message = $this->prepare_email_content($session);
                    $headers = array('Content-Type: text/html; charset=UTF-8');
                    
                    $mail_result = wp_mail($to, $subject, $message, $headers);
                    if (!$mail_result) {
                        error_log('Failed to send webhook notification email');
                    }
                }

                // Send GroupMe notification if enabled
                if (get_option('enable_groupme_notifications') == 1) {
                    $this->send_groupme_message($session);
                }
            }

            return new WP_REST_Response('Webhook processed successfully', 200);

        } catch (\UnexpectedValueException $e) {
            error_log('Stripe webhook error - Invalid payload: ' . 
                $this->safe_log_data($e->getMessage()));
            return new WP_REST_Response('Invalid payload', 400);

        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            error_log('Stripe webhook error - Invalid signature: ' . 
                $this->safe_log_data($e->getMessage()));
            return new WP_REST_Response('Invalid signature', 400);

        } catch (Exception $e) {
            error_log('Stripe webhook error: ' . $this->safe_log_data($e->getMessage()));
            return new WP_REST_Response('Server error', 500);
        }
    }
}