<?php
/**
 * Stripe Webhook Handler
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class StripeWebhookHandler {
    private $stripe_checkout_integration;

    public function __construct($stripe_checkout_integration) {
        $this->stripe_checkout_integration = $stripe_checkout_integration;
    }

    public function register_webhook_endpoint() {
        register_rest_route('stripe-checkout/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true'
        ));
    }

    private function prepare_email_content($session)
    {
        $customer = $session->customer_details;
        $payment_intent = $session->payment_intent;
        $date = date(format: 'm/d/Y g:ia', timestamp: $session->created);

        $content = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                table { border-collapse: collapse; width: 100%; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
            </style>
        </head>
        <body>
            <h2>New Successful Stripe Checkout</h2>
            <p><strong>Order Date:</strong> {$date} </p> {
            <p><strong>Billed to:</strong> {$customer->name} ({$customer->email})</p>
            <p><strong>Total Amount:</strong> " . number_format($session->amount_total / 100, 2) . "</p>
            <p><strong>Stripe ID:</strong> <a href='https://dashboard.stripe.com/payments/{$payment_intent}'>{$payment_intent}</a></p>
        </body>
        </html>";

        return $content;
    }

    function send_groupme_message($session)
    {
        $bot_id = get_option('groupme_bot_id');
        if (empty($bot_id)) {
            error_log('GroupMe Bot ID is not set');
            return false;
        }

        $date = date(format: 'm/d/Y g:ia', timestamp: $session->created);
        
        $message = "New Order!\n";
        $message .= "Date: {$date}\n";
        $message .= "Total Amount: " . number_format($session->amount_total / 100, 2) . " \n";
        $message .= "ID: {$session->payment_intent}\n";

        $url = 'https://api.groupme.com/v3/bots/post';
        $data = array(
            'bot_id' => $bot_id,
            'text' => $message,
        );
    
        $response = wp_remote_post($url, array(
            'body' => json_encode($data),
            'headers' => array('Content-Type' => 'application/json'),
        ));
    
        if (is_wp_error($response)) {
            error_log('Failed to send GroupMe message: ' . $response->get_error_message());
            return false;
        }
    
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 202) {
            error_log('GroupMe API returned unexpected status code: ' . $response_code);
            return false;
        }
    
        return true;
    }

    public function handle_webhook($request) {
        $payload = $request->get_body();
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];

        $endpoint_secret = $this->stripe_checkout_integration->get_webhook_secret();

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload, $sig_header, $endpoint_secret
            );
        } catch(\UnexpectedValueException $e) {
            return new WP_Error('invalid_payload', 'Invalid payload', array('status' => 400));
        } catch(\Stripe\Exception\SignatureVerificationException $e) {
            return new WP_Error('invalid_signature', 'Invalid signature', array('status' => 400));
        }
        if ($event->type == 'checkout.session.completed') {
            $session = $event->data->object;
            // Debug: Log the session data
            error_log('Received checkout.session.completed event: ' . print_r($session, true));
            
            // Send actual email
            $to = get_option('admin_email');
            $subject = 'New Successful Stripe Checkout';
            $message = $this->prepare_email_content($session);
            $headers = array('Content-Type: text/html; charset=UTF-8');
            $mail_result = wp_mail($to, $subject, $message, $headers);
            
            error_log('Email sending result: ' . ($mail_result ? 'Success' : 'Failure'));
    
            // Send GroupMe notification if enabled
            if (get_option('enable_groupme_notifications') == 1) {
                $groupme_result = $this->send_groupme_message($session);
                error_log('GroupMe notification result: ' . ($groupme_result ? 'Success' : 'Failure'));
            } else {
                error_log('GroupMe notifications are disabled');
            }
        }

        return new WP_REST_Response('Webhook received', 200);
    }
}