<?php
if (!class_exists('Conekta')) {
    require_once("lib/conekta-php/lib/Conekta.php");
}
/*
 * Title   : Conekta Payment extension for WooCommerce
 * Author  : Conekta.io
 * Url     : https://wordpress.org/plugins/conekta-woocommerce
 */

class WC_Conekta_Cash_Gateway extends WC_Conekta_Plugin
{
    protected $GATEWAY_NAME               = "WC_Conekta_Cash_Gateway";
    protected $use_sandbox_api            = true;
    protected $order                      = null;
    protected $transaction_id             = null;
    protected $transaction_error_message  = null;
    protected $conekta_test_api_key       = '';
    protected $conekta_live_api_key       = '';
    protected $publishable_key            = '';
    /**
     * @var string
     */
    public $id;
    public $method_title;
    public $has_fields;
    public $title;
    public $icon;
    public $test_api_key;
    public $live_api_key;
    public $secret_key;
    public $lang_options;

    public function __construct()
    {
        $this->id              = 'conektaoxxopay';
        $this->method_title    = __( 'Conekta Oxxo Pay', 'woocommerce' );
        $this->has_fields      = true;
        $this->ckpg_init_form_fields();
        $this->init_settings();
        $this->title           = $this->settings['title'];
        $this->description     = '';
        $this->icon            = $this->settings['alternate_imageurl'] ?
                                 $this->settings['alternate_imageurl'] :
                                 WP_PLUGIN_URL . "/" . plugin_basename( dirname(__FILE__)) . '/images/oxxopay.png';
        $this->use_sandbox_api = strcmp($this->settings['debug'], 'yes') == 0;
        $this->test_api_key    = $this->settings['test_api_key'];
        $this->live_api_key    = $this->settings['live_api_key'];
        $this->secret_key      = $this->use_sandbox_api ?
                                 $this->test_api_key :
                                 $this->live_api_key;

        $this->lang_options = parent::ckpg_set_locale_options()->ckpg_get_lang_options();

        $no_accounts = 25;
        $this->account = array();
        $this->accounts = array();
        for ( $i = 1; $i <= $no_accounts; $i++ ) {
          $new_accounts = array(
            'name'                 => $this->get_option( 'account_' . sprintf("%02d", $i) . '_name' ),
            'catid'                => $this->get_option( 'account_' . sprintf("%02d", $i) . '_catid' ),
            'track'                => $this->get_option( 'account_' . sprintf("%02d", $i) . '_track' ),
            'email'                => $this->get_option( 'account_' . sprintf("%02d", $i) . '_email' ),
            'debug'                => strcmp( $this->get_option( 'account_' . sprintf("%02d", $i) . '_debug' ), 'yes') == 0,
            'test_api_key'         => $this->get_option( 'account_' . sprintf("%02d", $i) . '_test_api_key' ),
            'live_api_key'         => $this->get_option( 'account_' . sprintf("%02d", $i) . '_live_api_key' ),
          );
          $this->accounts[ $this->get_option( 'account_' . sprintf("%02d", $i) . '_catid' ) ] = $new_accounts;
        }

        if (empty($this->secret_key)){
            $this->enabled = false;
        }
        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id ,
            array($this, 'process_admin_options')
        );
        add_action(
            'woocommerce_thankyou_' . $this->id,
            array($this, 'ckpg_thankyou_page')
        );
        add_action(
            'woocommerce_email_before_order_table',
            array($this, 'ckpg_email_instructions')
        );
        add_action(
            'woocommerce_email_before_order_table',
            array($this, 'ckpg_email_reference')
        );
        add_action(
            'woocommerce_api_' . strtolower(get_class($this)),
            array($this, 'ckpg_webhook_handler')
        );
    }

    /**
     * Updates the status of the order.
     * Webhook needs to be added to Conekta account tusitio.com/wc-api/WC_Conekta_Cash_Gateway
     */
    public function ckpg_webhook_handler()
    {
        header('HTTP/1.1 200 OK');
        $body          = @file_get_contents('php://input');
        $event         = json_decode($body, true);
        $conekta_order = $event['data']['object'];
        $charge        = $conekta_order['charges']['data'][0];
        $order_id      = $conekta_order['metadata']['reference_id'];
        $paid_at       = date("Y-m-d", $charge['paid_at']);
        $order         = new WC_Order($order_id);

        if (strpos($event['type'], "order.paid") !== false
            && $charge['payment_method']['type'] === "oxxo")
            {
                update_post_meta($order->get_id(), 'conekta-paid-at', $paid_at);
                $order->payment_complete();
                $order->add_order_note(sprintf("Payment completed in Oxxo and notification of payment received"));

                parent::ckpg_offline_payment_notification($order_id, $conekta_order['customer_info']['name']);
            }
    }

    public function ckpg_init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'type'        => 'checkbox',
                'title'       => __('Enable/Disable', 'woothemes'),
                'label'       => __('Enable Conekta Oxxo Pay Payment', 'woothemes'),
                'default'     => 'yes'
            ),
            'debug' => array(
                'type'        => 'checkbox',
                'title'       => __('Testing', 'woothemes'),
                'label'       => __('Turn on testing', 'woothemes'),
                'default'     => 'no'
            ),
            'title' => array(
                'type'        => 'text',
                'title'       => __('Title', 'woothemes'),
                'description' => __('This controls the title which the user sees during checkout.', 'woothemes'),
                'default'     => __('Conekta PAgo en Efectivo en Oxxo Pay', 'woothemes')
            ),
            'test_api_key' => array(
                'type'        => 'password',
                'title'       => __('Conekta API Test Private key', 'woothemes'),
                'default'     => __('', 'woothemes')
            ),
            'live_api_key' => array(
                'type'        => 'password',
                'title'       => __('Conekta API Live Private key', 'woothemes'),
                'default'     => __('', 'woothemes')
            ),
            'expiration_days' => array(
                'type'        => 'text',
                'title'       => __('Expiration time (in days) for the reference', 'woothemes'),
                'default'     => __('30', 'woothemes')
            ),
            'alternate_imageurl' => array(
                'type'        => 'text',
                'title'       => __('Alternate Image to display on checkout, use fullly qualified url, served via https', 'woothemes'),
                'default'     => __('', 'woothemes')
            ),
            'description' => array(
                'title' => __( 'Description', 'woocommerce' ),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your checkout.', 'woocommerce'),
                'default' =>__('Por favor realiza el pago en el OXXO más cercano utilizando la referencia que se encuentra a continuación.', 'woocommerce' ),
                'desc_tip' => true,
            ),
            'instructions' => array(
                'title' => __( 'Instructions', 'woocommerce' ),
                'type' => 'textarea',
                'description' => __('Instructions that will be added to the thank you page and emails.', 'woocommerce'),
                'default' =>__('Por favor realiza el pago en el OXXO más cercano utilizando la referencia que se encuentra a continuación.', 'woocommerce'),
                'desc_tip' => true,
            ),
            // -------------------------------------------------
          'account_01_name' => array(
            'type'        => 'text',
            'title'       => __( '1. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_01_catid' => array(
            'type'        => 'text',
            'title'       => __( '1. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_01_track' => array(
            'type'        => 'text',
            'title'       => __( '1. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_01_email' => array(
            'type'        => 'text',
            'title'       => __( '1. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_01_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '1. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_01_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '1. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_01_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '1. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_02_name' => array(
            'type'        => 'text',
            'title'       => __( '2. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_02_catid' => array(
            'type'        => 'text',
            'title'       => __( '2. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_02_track' => array(
            'type'        => 'text',
            'title'       => __( '2. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_02_email' => array(
            'type'        => 'text',
            'title'       => __( '2. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_02_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '2. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_02_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '2. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_02_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '2. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_03_name' => array(
            'type'        => 'text',
            'title'       => __( '3. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_03_catid' => array(
            'type'        => 'text',
            'title'       => __( '3. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_03_track' => array(
            'type'        => 'text',
            'title'       => __( '3. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_03_email' => array(
            'type'        => 'text',
            'title'       => __( '3. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_03_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '3. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_03_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '3. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_03_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '3. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_04_name' => array(
            'type'        => 'text',
            'title'       => __( '4. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_04_catid' => array(
            'type'        => 'text',
            'title'       => __( '4. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_04_track' => array(
            'type'        => 'text',
            'title'       => __( '4. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_04_email' => array(
            'type'        => 'text',
            'title'       => __( '4. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_04_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '4. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_04_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '4. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_04_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '4. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_05_name' => array(
            'type'        => 'text',
            'title'       => __( '5. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_05_catid' => array(
            'type'        => 'text',
            'title'       => __( '5. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_05_track' => array(
            'type'        => 'text',
            'title'       => __( '5. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_05_email' => array(
            'type'        => 'text',
            'title'       => __( '5. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_05_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '5. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_05_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '5. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_05_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '5. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_06_name' => array(
            'type'        => 'text',
            'title'       => __( '6. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_06_catid' => array(
            'type'        => 'text',
            'title'       => __( '6. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_06_track' => array(
            'type'        => 'text',
            'title'       => __( '6. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_06_email' => array(
            'type'        => 'text',
            'title'       => __( '6. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_06_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '6. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_06_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '6. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_06_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '6. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_07_name' => array(
            'type'        => 'text',
            'title'       => __( '7. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_07_catid' => array(
            'type'        => 'text',
            'title'       => __( '7. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_07_track' => array(
            'type'        => 'text',
            'title'       => __( '7. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_07_email' => array(
            'type'        => 'text',
            'title'       => __( '7. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_07_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '7. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_07_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '7. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_07_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '7. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_08_name' => array(
            'type'        => 'text',
            'title'       => __( '8. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_08_catid' => array(
            'type'        => 'text',
            'title'       => __( '8. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_08_track' => array(
            'type'        => 'text',
            'title'       => __( '8. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_08_email' => array(
            'type'        => 'text',
            'title'       => __( '8. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_08_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '8. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_08_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '8. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_08_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '8. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_09_name' => array(
            'type'        => 'text',
            'title'       => __( '9. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_09_catid' => array(
            'type'        => 'text',
            'title'       => __( '9. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_09_track' => array(
            'type'        => 'text',
            'title'       => __( '9. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_09_email' => array(
            'type'        => 'text',
            'title'       => __( '9. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_09_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '9. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_09_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '9. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_09_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '9. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_10_name' => array(
            'type'        => 'text',
            'title'       => __( '10. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_10_catid' => array(
            'type'        => 'text',
            'title'       => __( '10. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_10_track' => array(
            'type'        => 'text',
            'title'       => __( '10. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_10_email' => array(
            'type'        => 'text',
            'title'       => __( '10. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_10_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '10. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_10_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '10. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_10_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '10. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_11_name' => array(
            'type'        => 'text',
            'title'       => __( '11. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_11_catid' => array(
            'type'        => 'text',
            'title'       => __( '11. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_11_track' => array(
            'type'        => 'text',
            'title'       => __( '11. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_11_email' => array(
            'type'        => 'text',
            'title'       => __( '11. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_11_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '11. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_11_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '11. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_11_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '11. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_12_name' => array(
            'type'        => 'text',
            'title'       => __( '12. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_12_catid' => array(
            'type'        => 'text',
            'title'       => __( '12. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_12_track' => array(
            'type'        => 'text',
            'title'       => __( '12. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_12_email' => array(
            'type'        => 'text',
            'title'       => __( '12. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_12_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '12. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_12_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '12. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_12_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '12. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_13_name' => array(
            'type'        => 'text',
            'title'       => __( '13. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_13_catid' => array(
            'type'        => 'text',
            'title'       => __( '13. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_13_track' => array(
            'type'        => 'text',
            'title'       => __( '13. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_13_email' => array(
            'type'        => 'text',
            'title'       => __( '13. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_13_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '13. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_13_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '13. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_13_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '13. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_14_name' => array(
            'type'        => 'text',
            'title'       => __( '14. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_14_catid' => array(
            'type'        => 'text',
            'title'       => __( '14. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_14_track' => array(
            'type'        => 'text',
            'title'       => __( '14. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_14_email' => array(
            'type'        => 'text',
            'title'       => __( '14. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_14_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '14. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_14_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '14. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_14_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '14. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_15_name' => array(
            'type'        => 'text',
            'title'       => __( '15. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_15_catid' => array(
            'type'        => 'text',
            'title'       => __( '15. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_15_track' => array(
            'type'        => 'text',
            'title'       => __( '15. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_15_email' => array(
            'type'        => 'text',
            'title'       => __( '15. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_15_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '15. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_15_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '15. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_15_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '15. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_16_name' => array(
            'type'        => 'text',
            'title'       => __( '16. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_16_catid' => array(
            'type'        => 'text',
            'title'       => __( '16. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_16_track' => array(
            'type'        => 'text',
            'title'       => __( '16. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_16_email' => array(
            'type'        => 'text',
            'title'       => __( '16. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_16_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '16. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_16_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '16. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_16_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '16. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_17_name' => array(
            'type'        => 'text',
            'title'       => __( '17. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_17_catid' => array(
            'type'        => 'text',
            'title'       => __( '17. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_17_track' => array(
            'type'        => 'text',
            'title'       => __( '17. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_17_email' => array(
            'type'        => 'text',
            'title'       => __( '17. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_17_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '17. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_17_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '17. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_17_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '17. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_18_name' => array(
            'type'        => 'text',
            'title'       => __( '18. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_18_catid' => array(
            'type'        => 'text',
            'title'       => __( '18. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_18_track' => array(
            'type'        => 'text',
            'title'       => __( '18. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_18_email' => array(
            'type'        => 'text',
            'title'       => __( '18. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_18_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '18. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_18_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '18. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_18_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '18. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_19_name' => array(
            'type'        => 'text',
            'title'       => __( '19. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_19_catid' => array(
            'type'        => 'text',
            'title'       => __( '19. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_19_track' => array(
            'type'        => 'text',
            'title'       => __( '19. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_19_email' => array(
            'type'        => 'text',
            'title'       => __( '19. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_19_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '19. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_19_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '19. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_19_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '19. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_20_name' => array(
            'type'        => 'text',
            'title'       => __( '20. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_20_catid' => array(
            'type'        => 'text',
            'title'       => __( '20. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_20_track' => array(
            'type'        => 'text',
            'title'       => __( '20. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_20_email' => array(
            'type'        => 'text',
            'title'       => __( '20. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_20_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '20. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_20_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '20. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_20_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '20. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_21_name' => array(
            'type'        => 'text',
            'title'       => __( '21. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_21_catid' => array(
            'type'        => 'text',
            'title'       => __( '21. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_21_track' => array(
            'type'        => 'text',
            'title'       => __( '21. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_21_email' => array(
            'type'        => 'text',
            'title'       => __( '21. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_21_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '21. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_21_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '21. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_21_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '21. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_22_name' => array(
            'type'        => 'text',
            'title'       => __( '22. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_22_catid' => array(
            'type'        => 'text',
            'title'       => __( '22. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_22_track' => array(
            'type'        => 'text',
            'title'       => __( '22. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_22_email' => array(
            'type'        => 'text',
            'title'       => __( '22. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_22_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '22. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_22_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '22. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_22_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '22. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_23_name' => array(
            'type'        => 'text',
            'title'       => __( '23. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_23_catid' => array(
            'type'        => 'text',
            'title'       => __( '23. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_23_track' => array(
            'type'        => 'text',
            'title'       => __( '23. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_23_email' => array(
            'type'        => 'text',
            'title'       => __( '23. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_23_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '23. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_23_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '23. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_23_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '23. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_24_name' => array(
            'type'        => 'text',
            'title'       => __( '24. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_24_catid' => array(
            'type'        => 'text',
            'title'       => __( '24. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_24_track' => array(
            'type'        => 'text',
            'title'       => __( '24. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_24_email' => array(
            'type'        => 'text',
            'title'       => __( '24. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_24_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '24. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_24_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '24. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_24_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '24. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
          'account_25_name' => array(
            'type'        => 'text',
            'title'       => __( '25. Unidad', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_25_catid' => array(
            'type'        => 'text',
            'title'       => __( '25. ID de categoría', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_25_track' => array(
            'type'        => 'text',
            'title'       => __( '25. Código de seguimiento', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_25_email' => array(
            'type'        => 'text',
            'title'       => __( '25. Correos para notificación', 'wc-banorte-gateway' ),
            'default'     => __( '', 'wc-banorte-gateway' ),
            ),
          'account_25_debug' => array(
            'type'        => 'checkbox',
            'title'       => __( '25. Testing', 'wc-conekta-gw' ),
            'label'       => __( 'Turn on testing', 'wc-conekta-gw' ),
            'default'     => 'no',
            ),
          'account_25_test_api_key' => array(
            'type'        => 'password',
            'title'       => __( '25. Conekta API Test Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_25_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '25. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
        );
    }

    /**
     * Output for the order received page.
     *
     * @param string $order_id
     */

    // this echo's may were safe of validation, because there are proveided by os
    function ckpg_thankyou_page($order_id) {
        $order = new WC_Order( $order_id );

        echo '<p style="font-size: 30px"><strong>'.__('Referencia').':</strong> ' . esc_html( get_post_meta( $order->get_id(), 'conekta-referencia', true ) ). '</p>';
        echo '<p>OXXO cobrará una comisión adicional al momento de realizar el pago.</p>';
        echo '<p>INSTRUCCIONES:'. esc_html($this->settings['instructions']) .'</p>';
    }

    /**
     * Add content to the WC emails.
     *
     * @access public
     * @param WC_Order $order
     */

    function ckpg_email_reference($order) {
        // Comentado por danielgc para ponerlo directamente en el template de Woo
        // if (get_post_meta( $order->get_id(), 'conekta-referencia', true ) != null)
        //     {
        //         echo '<p style="font-size: 30px"><strong>'.__('Referencia').':</strong> ' . esc_html(get_post_meta( $order->get_id(), 'conekta-referencia', true )). '</p>';
        //         echo '<p>OXXO cobrará una comisión adicional al momento de realizar el pago.</p>';
        //         echo '<p>INSTRUCCIONES:'. esc_html($this->settings['instructions']) .'</p>';
        //     }
    }

    /**
     * Add content to the WC emails.
     *
     * @access public
     * @param WC_Order $order
     * @param bool $sent_to_admin
     * @param bool $plain_text
     */
    public function ckpg_email_instructions( $order, $sent_to_admin = false, $plain_text = false ) {
        if (get_post_meta( $order->get_id(), '_payment_method', true ) === $this->id){
            $instructions = $this->form_fields['instructions'];
            if ( $instructions && 'on-hold' === $order->get_status() ) {
                echo wpautop( wptexturize( esc_html($instructions['default'] ) ) ). PHP_EOL;
            }
        }
    }

    public function ckpg_admin_options()
    {
        include_once('templates/cash_admin.php');
    }

    public function payment_fields()
    {
        include_once('templates/cash.php');
    }

    protected function ckpg_send_to_conekta()
    {
        global $woocommerce;
        //ALL $data VAR ASSIGNATION IS FREE OF VALIDATION
        $data             = ckpg_get_request_data($this->order);
        $amount           = (int) $data['amount'];
        $items            = $this->order->get_items();
        $taxes            = $this->order->get_taxes();
        $line_items       = ckpg_build_line_items($items, parent::ckpg_get_version());
        $discount_lines   = ckpg_build_discount_lines($data);
        $shipping_lines   = ckpg_build_shipping_lines($data);
        $shipping_contact = ckpg_build_shipping_contact($data);
        $tax_lines        = ckpg_build_tax_lines($taxes);
        $customer_info    = ckpg_build_customer_info($data);
        $order_metadata   = ckpg_build_order_metadata($data);

        $categories_ids = array();
        foreach ($items as $item ) {
            $product = $item->get_product(); // the WC_Product Object
            //$product_category_ids  = $product->get_category_ids(); // An array of terms Ids
            $categories_ids = array_merge( $categories_ids, $product->get_category_ids() );
        }
        //wc_add_notice( 'Datos de unidades: ' . json_encode($this->accounts), 'error' );
        //wc_add_notice( 'ID de unidad: ' . json_encode($categories_ids[0]), 'error' );
        //wc_add_notice( 'Datos de unidad: ' . json_encode($this->accounts[$categories_ids[0]]), 'error' );
        if (!empty($categories_ids[0])){
          //wc_add_notice( '1', 'error' );
          $this->account = $this->accounts[$categories_ids[0]];
          $this->test_api_key     = $this->account['test_api_key'];
          $this->live_api_key     = $this->account['live_api_key'];
          $this->secret_key       = $this->account['debug'] ?
                                    $this->account['test_api_key'] :
                                    $this->account['live_api_key'];
          include_once('conekta_gateway_helper.php');
          \Conekta\Conekta::setApiKey($this->secret_key);
          \Conekta\Conekta::setApiVersion('2.0.0');
          \Conekta\Conekta::setPlugin($this->name);
          \Conekta\Conekta::setPluginVersion($this->version);
          \Conekta\Conekta::setLocale('es');
        }
        else {
          wc_add_notice('No se encontraron credenciales para realizar el pago en esta unidad, favor de contactar a un administador.', 'error');
          return false;
        }

        $order_details    = array(
            'currency'         => $data['currency'],
            'line_items'       => $line_items,
            'customer_info'    => $customer_info,
            'shipping_lines'   => $shipping_lines,
            'discount_lines'   => $discount_lines,
            'tax_lines'        => $tax_lines
        );

        if (!empty($shipping_contact)) {
            $order_details = array_merge($order_details, array('shipping_contact' => $shipping_contact));
        }

        if (!empty($order_metadata)) {
            $order_details = array_merge($order_details, array('metadata' => $order_metadata));
        }

        $order_details = ckpg_check_balance($order_details, $amount);

        try {
            $conekta_order_id = esc_html(get_post_meta($this->order->get_id(), 'conekta-order-id', true));
            if (!empty($conekta_order_id)) {
                $order = \Conekta\Order::find($conekta_order_id);
                $order->update($order_details);
            } else {
                $order = \Conekta\Order::create($order_details);
            }

            update_post_meta($this->order->get_id(), 'conekta-order-id', $order->id);
            update_post_meta( $this->order->get_id(), 'additional_branch_track', $this->account['track'] . '-' . $this->order->get_id()  . '-' . $order->id );
            $this->order->add_order_note( 'Realizando pago para: ' . $this->account['name'] );

            $expires_at = time() + ($this->settings['expiration_days'] * 86400);
            $charge_details = array(
                'payment_method' => array(
                    'type'       => 'oxxo_cash',
                    'expires_at' => $expires_at
                ),
                'amount'         => $amount
            );

            $charge = $order->createCharge($charge_details);

            $this->transaction_id = $charge->id;
            update_post_meta($this->order->get_id(), 'conekta-id',         $charge->id);
            update_post_meta($this->order->get_id(), 'conekta-creado',     $charge->created_at);
            update_post_meta($this->order->get_id(), 'conekta-expira',     $charge->payment_method->expires_at);
            update_post_meta($this->order->get_id(), 'conekta-referencia', $charge->payment_method->reference);

            return true;
        } catch(\Conekta\Handler $e) {
            $description = $e->getMessage();

            global $wp_version;
            if (version_compare($wp_version, '4.1', '>=')) {
                wc_add_notice(__('Error: ', 'woothemes') . $description , $notice_type = 'error');
            } else {
                error_log('Gateway Error:' . $description . "\n");
                $woocommerce->add_error(__('Error: ', 'woothemes') . $description);
            }
            return false;
        }
    }

    public function process_payment($order_id)
    {
        global $woocommerce;
        $this->order        = new WC_Order($order_id);
        if ($this->ckpg_send_to_conekta())
            {
                // Mark as on-hold (we're awaiting the notification of payment)
                $this->order->update_status('on-hold', __( 'Awaiting the conekta OXXO payment', 'woocommerce' ));

                // Remove cart
                $woocommerce->cart->empty_cart();
                unset($_SESSION['order_awaiting_payment']);
                $result = array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($this->order)
                );

                return $result;
            }
        else
            {
                $this->ckpg_mark_as_failed_payment();
                global $wp_version;
                if (version_compare($wp_version, '4.1', '>=')) {
                    wc_add_notice(__('Transaction Error: Could not complete the payment', 'woothemes'), $notice_type = 'error');
                } else {
                    $woocommerce->add_error(__('Transaction Error: Could not complete the payment'), 'woothemes');
                }
            }
    }

    protected function ckpg_mark_as_failed_payment()
    {
        $this->order->add_order_note(
            sprintf(
                "%s Oxxo Pay Payment Failed : '%s'",
                $this->GATEWAY_NAME,
                $this->transaction_error_message
            )
        );
    }

    protected function ckpg_complete_order()
    {
        global $woocommerce;

        if ($this->order->get_status() == 'completed')
            return;

        $this->order->payment_complete();
        $woocommerce->cart->empty_cart();
        $this->order->add_order_note(
            sprintf(
                "%s payment completed with Transaction Id of '%s'",
                $this->GATEWAY_NAME,
                $this->transaction_id
            )
        );

        unset($_SESSION['order_awaiting_payment']);
    }

}

function ckpg_conekta_cash_order_status_completed($order_id = null)
{
    global $woocommerce;
    if (!$order_id){
        $order_id = sanitize_text_field((string) $_POST['order_id']);
    }

    $data = get_post_meta( $order_id );

    $total = $data['_order_total'][0] * 100;

    $amount = floatval($_POST['amount']);
    if(isset($amount))
    {
        $params['amount'] = round($amount);
    }
}

function ckpg_conektacheckout_add_cash_gateway($methods)
{
    array_push($methods, 'WC_Conekta_Cash_Gateway');
    return $methods;
}

add_filter('woocommerce_payment_gateways',                      'ckpg_conektacheckout_add_cash_gateway');
add_action('woocommerce_order_status_processing_to_completed',  'ckpg_conekta_cash_order_status_completed' );