<?php

if (!class_exists('Conekta')) {
    require_once("lib/conekta-php/lib/Conekta.php");
}

/*
 * Title   : Conekta Payment extension for WooCommerce
 * Author  : Cristina Randall
 * Url     : https://wordpress.org/plugins/conekta-woocommerce
*/
class WC_Conekta_Card_Gateway extends WC_Conekta_Plugin
{
    protected $GATEWAY_NAME              = "WC_Conekta_Card_Gateway";
    protected $is_sandbox                = true;
    protected $order                     = null;
    protected $transaction_id            = null;
    protected $conekta_order_id          = null;
    protected $transaction_error_message = null;
    protected $currencies                = array('MXN', 'USD');

    public $id;
    public $method_title;
    public $has_fields;
    public $icon;
    public $title;
    public $use_sandbox_api;
    public $enable_meses;
    public $test_api_key;
    public $live_api_key;
    public $test_publishable_key;
    public $live_publishable_key;
    public $publishable_key;
    public $secret_key;
    public $lang_options;

    public function __construct() {
        $this->id = 'conektacard';
        $this->method_title = __('Conekta Card', 'conektacard');
        $this->has_fields = true;

        $this->ckpg_init_form_fields();
        $this->init_settings();

        $this->title       = $this->settings['title'];
        $this->description = '';
        $this->icon        = $this->settings['alternate_imageurl'] ?
                             $this->settings['alternate_imageurl'] :
                             WP_PLUGIN_URL . "/" . plugin_basename( dirname(__FILE__))
                             . '/images/credits.png';

        $this->use_sandbox_api      = strcmp($this->settings['debug'], 'yes') == 0;
        $this->enable_meses         = strcmp($this->settings['meses'], 'yes') == 0;
        $this->test_api_key         = $this->settings['test_api_key'];
        $this->live_api_key         = $this->settings['live_api_key'];
        $this->test_publishable_key = $this->settings['test_publishable_key'];
        $this->live_publishable_key = $this->settings['live_publishable_key'];
        $this->publishable_key      = $this->use_sandbox_api ?
                                      $this->test_publishable_key :
                                      $this->live_publishable_key;
        $this->secret_key           = $this->use_sandbox_api ?
                                      $this->test_api_key :
                                      $this->live_api_key;
        $this->lang_options         = parent::ckpg_set_locale_options()->
                                    ckpg_get_lang_options();

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
            'test_publishable_key' => $this->get_option( 'account_' . sprintf("%02d", $i) . '_test_publishable_key' ),
            'live_api_key'         => $this->get_option( 'account_' . sprintf("%02d", $i) . '_live_api_key' ),
            'live_publishable_key' => $this->get_option( 'account_' . sprintf("%02d", $i) . '_live_publishable_key' ),
            );
            $this->accounts[ $this->get_option( 'account_' . sprintf("%02d", $i) . '_catid' ) ] = $new_accounts;
        }

        add_action('wp_enqueue_scripts', array($this, 'ckpg_payment_fields'));
        add_action(
          'woocommerce_update_options_payment_gateways_'.$this->id,
          array($this, 'process_admin_options')
        );
        add_action('admin_notices', array(&$this, 'ckpg_perform_ssl_check'));

        if (!$this->ckpg_validate_currency()) {
            $this->enabled = false;
        }

        if(empty($this->secret_key)) {
          $this->enabled = false;
        }
    }

    /**
    * Checks to see if SSL is configured and if plugin is configured in production mode
    * Forces use of SSL if not in testing
    */
    public function ckpg_perform_ssl_check()
    {
        ///
        if (!$this->use_sandbox_api
          && get_option('woocommerce_force_ssl_checkout') == 'no'
          && $this->enabled == 'yes') {
            echo '<div class="error"><p>'
              .sprintf(
                __('%s sandbox testing is disabled and can performe live transactions'
                .' but the <a href="%s">force SSL option</a> is disabled; your checkout'
                .' is not secure! Please enable SSL and ensure your server has a valid SSL'
                .' certificate.', 'woothemes'),
                    esc_html($this->GATEWAY_NAME), esc_url(admin_url('admin.php?page=settings'))
              )
            .'</p></div>';
        }
    }

    public function ckpg_init_form_fields()
    {
        $this->form_fields = array(
         'enabled' => array(
          'type'        => 'checkbox',
          'title'       => __('Enable/Disable', 'woothemes'),
          'label'       => __('Enable Credit Card Payment', 'woothemes'),
          'default'     => 'yes'
          ),
         'meses' => array(
            'type'        => 'checkbox',
            'title'       => __('Meses sin Intereses', 'woothemes'),
            'label'       => __('Enable Meses sin Intereses', 'woothemes'),
            'default'     => 'no'
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
            'default'     => __('Pago con Tarjeta de Crédito o Débito', 'woothemes')
            ),
         'test_api_key' => array(
             'type'        => 'password',
             'title'       => __('Conekta API Test Private key', 'woothemes'),
             'default'     => __('', 'woothemes')
             ),
         'test_publishable_key' => array(
             'type'        => 'text',
             'title'       => __('Conekta API Test Public key', 'woothemes'),
             'default'     => __('', 'woothemes')
             ),
         'live_api_key' => array(
             'type'        => 'password',
             'title'       => __('Conekta API Live Private key', 'woothemes'),
             'default'     => __('', 'woothemes')
             ),
         'live_publishable_key' => array(
             'type'        => 'text',
             'title'       => __('Conekta API Live Public key', 'woothemes'),
             'default'     => __('', 'woothemes')
             ),
         'alternate_imageurl' => array(
           'type'        => 'text',
           'title'       => __('Alternate Image to display on checkout, use fullly qualified url, served via https', 'woothemes'),
           'default'     => __('', 'woothemes')
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
          'account_01_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '1. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_01_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '1. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_01_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '1. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_02_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '2. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_02_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '2. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_02_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '2. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_03_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '3. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_03_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '3. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_03_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '3. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_04_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '4. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_04_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '4. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_04_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '4. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_05_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '5. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_05_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '5. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_05_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '5. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_06_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '6. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_06_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '6. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_06_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '6. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_07_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '7. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_07_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '7. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_07_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '7. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_08_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '8. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_08_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '8. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_08_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '8. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_09_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '9. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_09_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '9. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_09_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '9. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_10_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '10. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_10_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '10. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_10_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '10. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_11_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '11. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_11_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '11. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_11_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '11. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_12_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '12. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_12_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '12. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_12_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '12. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_13_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '13. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_13_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '13. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_13_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '13. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_14_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '14. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_14_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '14. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_14_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '14. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_15_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '15. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_15_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '15. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_15_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '15. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_16_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '16. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_16_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '16. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_16_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '16. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_17_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '17. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_17_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '17. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_17_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '17. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_18_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '18. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_18_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '18. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_18_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '18. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_19_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '19. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_19_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '19. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_19_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '19. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_20_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '20. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_20_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '20. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_20_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '20. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_21_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '21. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_21_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '21. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_21_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '21. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_22_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '22. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_22_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '22. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_22_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '22. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_23_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '23. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_23_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '23. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_23_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '23. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_24_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '24. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_24_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '24. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_24_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '24. Conekta API Live Public key', 'wc-conekta-gw' ),
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
          'account_25_test_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '25. Conekta API Test Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_25_live_api_key' => array(
            'type'        => 'password',
            'title'       => __( '25. Conekta API Live Private key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          'account_25_live_publishable_key' => array(
            'type'        => 'text',
            'title'       => __( '25. Conekta API Live Public key', 'wc-conekta-gw' ),
            'default'     => __( '', 'wc-conekta-gw' ),
            ),
          // -------------------------------------------------
         );
    }

    public function admin_options() {
        include_once('templates/admin.php');
    }

    public function payment_fields() {
        include_once('templates/payment.php');
    }

    public function ckpg_payment_fields() {
        if (!is_checkout()) {
            return;
        }

        wp_enqueue_script('conekta_js', WP_PLUGIN_URL."/".plugin_basename(dirname(__FILE__)).'/assets/js/conekta.js', '', '', true);
        wp_enqueue_script('tokenize', WP_PLUGIN_URL."/".plugin_basename(dirname(__FILE__)).'/assets/js/tokenize.js', '', '1.0', true); //check import convention

        //PCI
        $params = array(
            'public_key' => $this->publishable_key
        );

        wp_localize_script('tokenize', 'wc_conekta_params', $params);
    }

    public function checkout_init( $checkout ) {
      add_action( 'woocommerce_before_checkout_form', array( $this, 'checkout_account_token' ) );
      //add_action( 'woocommerce_after_checkout_form', array( $this, 'checkout_account_token_script' ) );
    }

    public function checkout_account_token() {
      $checkout = WC()->checkout();
      $categories_ids = array();
      foreach ( wc()->cart->get_cart() as $cart_item_key => $cart_item ) {
        $product_id = $cart_item['product_id'];
        $variation_id = $cart_item['variation_id'];
        $categories_ids = array_merge( $categories_ids, $cart_item['data']->get_category_ids() );
        if ($variation_id != 0) {
          $product = wc_get_product( $product_id );
          $categories_ids = array_merge( $categories_ids, $product->get_category_ids() );
        }
      }
      if (!empty($categories_ids[0])){
        $current_account = $this->accounts[$categories_ids[0]];
        woocommerce_form_field('current_account_token', array(
          'type' => 'hidden',
          'required' => true,
          'default' => $current_account['debug'] ? $current_account['test_publishable_key'] : $current_account['live_publishable_key'],
        ), $checkout->get_value('current_account_token'));
      }
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
        $products_names = '';
        foreach ($items as $item ) {
            $product = $item->get_product(); // the WC_Product Object
            //$product_category_ids  = $product->get_category_ids(); // An array of terms Ids
            $categories_ids = array_merge( $categories_ids, $product->get_category_ids() );
            $products_names = empty($items_names) ? $item['name'] : $products_names + ', ' + $item['name'];
        }
        //wc_add_notice( 'Datos de unidades: ' . json_encode($this->accounts), 'error' );
        //wc_add_notice( 'ID de unidad: ' . json_encode($categories_ids[0]), 'error' );
        //wc_add_notice( 'Datos de unidad: ' . json_encode($this->accounts[$categories_ids[0]]), 'error' );
        if (!empty($categories_ids[0])) {
          //wc_add_notice( '1', 'error' );
          $this->account = $this->accounts[$categories_ids[0]];
          $this->test_api_key         = $this->account['test_api_key'];
          $this->live_api_key         = $this->account['live_api_key'];
          $this->test_publishable_key = $this->account['test_publishable_key'];
          $this->live_publishable_key = $this->account['live_publishable_key'];
          $this->publishable_key  = $this->account['debug'] ?
                                    $this->account['test_publishable_key'] :
                                    $this->account['live_publishable_key'];
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
            //ORDER ID IS GENERATED BY RESPONSE
            update_post_meta($this->order->get_id(), 'conekta-order-id', $order->id);

            $charge_details = array(
                'payment_method' => array(
                    'type'     => 'card',
                    'token_id' => $data['token']
                ),
                'amount' => $amount
            );

            $monthly_installments = $data['monthly_installments'];
            if ($monthly_installments > 1) {
                $charge_details['payment_method']['monthly_installments'] = $monthly_installments;
            }

            $charge = $order->createCharge($charge_details);

            $this->transaction_id = $charge->id;
            if ($data['monthly_installments'] > 1) {
                update_post_meta( $this->order->get_id(), 'meses-sin-intereses', $data['monthly_installments']);
            }
            update_post_meta( $this->order->get_id(), 'transaction_id', $this->transaction_id);
            update_post_meta( $this->order->get_id(), 'additional_branch_track', $this->account['track'] . '-' . $this->order->get_id()  . '-' . $order->id );
            $this->order->add_order_note( 'Realizando pago para: ' . $this->account['name'] );

            // Inicio - Correo admins
            if ( !empty( $this->account['email'] ) ) {
              //wc_add_notice( 'Enviando correo administrativo.', 'error' );
              $order_id = $this->order->get_id();
              $order_data = $this->order->get_data();
              $user_email = $this->order->get_billing_email();
              add_filter( 'wp_mail_content_type', create_function( '', 'return "text/html";' ) );
              $subject = 'Nuevo pedido con Conekta en ' . $this->account['name'] . ' - Estado del pago: Tarjeta aprobada.';
              $message = '
                <div>Orden: ' . $order_id . '</div>
                <div>Método de pago: ' . $this->order->get_payment_method_title() . '</div>
                <div>Código de seguimiento: ' . get_post_meta( $order_id, 'additional_branch_track', true ) . '</div>
                <div>Unidad: ' . $this->account['name'] . '</div>
                <div>Fecha: ' . get_post_meta( $order_id, 'billing_acuityscheduling_date', true ) . '</div>
                <div>Hora: ' . get_post_meta( $order_id, 'billing_acuityscheduling_time', true ) . '</div>
                <div>Nombre: ' . $order_data['billing']['first_name'] . '</div>
                <div>Apellidos: ' . $order_data['billing']['last_name'] . '</div>
                <div>Correo: ' . $order_data['billing']['email'] . '</div>
                <div>Teléfono: ' . $order_data['billing']['phone'] . '</div>
                <div>RFC: ' . get_post_meta( $order_id, 'billing_rfc', true ) . '</div>
                <div>Razón social: ' . $order_data['billing']['company'] . '</div>
                <div>País: ' . $order_data['billing']['country'] . '</div>
                <div>Estado: ' . $order_data['billing']['state'] . '</div>
                <div>Ciudad: ' . $order_data['billing']['city'] . '</div>
                <div>Código Postal: ' . $order_data['billing']['postcode'] . '</div>
                <div>Domicilio (calle y número): ' . $order_data['billing']['address_1'] . '</div>
                <div>Domicilio (Apartamento, habitación, etc): ' . $order_data['billing']['address_2'] . '</div>
                <div>Nombre del Paciente: ' . get_post_meta( $order_id, 'additional_px_first_name', true ) . '</div>
                <div>Apellido Paterno del Paciente: ' . get_post_meta( $order_id, 'additional_px_last_name', true ) . '</div>
                <div>Apellido Materno del Paciente: ' . get_post_meta( $order_id, 'additional_px_second_last_name', true ) . '</div>
                <div>Fecha de Nacimiento del Paciente: ' . get_post_meta( $order_id, 'additional_px_birthdate', true ) . '</div>
                <div>Domicilio del Paciente: ' . get_post_meta( $order_id, 'additional_px_address_1', true ) . '</div>
                <div>Médico Tratante: ' . get_post_meta( $order_id, 'additional_px_pmd', true ) . '</div>
                <div>Notas del pedido: ' . $this->order->get_customer_note() . '</div>
                <div>Productos: ' . $products_names . '</div>
                <div>Total: ' . $this->order->get_formatted_order_total() . '</div>
              ';

              $headers = array();
              $headers[] = 'Content-Type: text/html; charset=UTF-8';
              $headers[] = 'From: TiendaChristus <ventas@tiendachristus.com>';
              wp_mail( $this->account['email'], $subject, $message, $headers );
              //wc_add_notice( 'Correo enviado.', 'error' );
            }
            else {
              $this->order->add_order_note( 'Afiliación sin correo para notificar.');
            }
            // Fin - Correo admins

            return true;

        } catch(\Conekta\Handler $e) {
            $description = $e->getMessage();
            global $wp_version;
            if (version_compare($wp_version, '4.1', '>=')) {
                wc_add_notice(__('Error: ', 'woothemes') . $description , $notice_type = 'error');
            } else {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
                    error_log('Gateway Error:' . $description . "\n");
                }
                $woocommerce->add_error(__('Error: ', 'woothemes') . $description);
            }
            return false;
        }
    }

    protected function ckpg_mark_as_failed_payment()
    {
        $this->order->add_order_note(
         sprintf(
             "%s Credit Card Payment Failed : '%s'",
             $this->GATEWAY_NAME,
             $this->transaction_error_message
             )
         );
    }

    protected function ckpg_completeOrder()
    {
        global $woocommerce;

        if ($this->order->get_status() == 'completed')
            return;

            // adjust stock levels and change order status
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

    public function process_payment($order_id)
    {
        global $woocommerce;
        $this->order        = new WC_Order($order_id);
        if ($this->ckpg_send_to_conekta())
        {
            $this->ckpg_completeOrder();

            $result = array(
                'result' => 'success',
                'redirect' => $this->get_return_url($this->order)
                );
            return $result;
        }
        else
        {
            $this->ckpg_mark_as_failed_payment();
            WC()->session->reload_checkout = true;
        }
    }

    /**
     * Checks if woocommerce has enabled available currencies for plugin
     *
     * @access public
     * @return bool
     */
    public function ckpg_validate_currency() {
        return in_array(get_woocommerce_currency(), $this->currencies);
    }

    public function ckpg_is_null_or_empty_string($string) {
        return (!isset($string) || trim($string) === '');
    }
}

function ckpg_conekta_card_add_gateway($methods) {
    array_push($methods, 'WC_Conekta_Card_Gateway');
    return $methods;
}

add_filter('woocommerce_payment_gateways', 'ckpg_conekta_card_add_gateway');