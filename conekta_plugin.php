<?php

if (!class_exists('Conekta')) {
	require_once("lib/conekta-php/lib/Conekta.php");
}

/*
* Title   : Conekta Payment extension for WooCommerce and Muguerza
* Author  : Conekta.io
* Url     : https://wordpress.org/plugins/conekta-woocommerce
*/

class WC_Conekta_Plugin extends WC_Payment_Gateway
{
	public $version  = "3.7.5";
	public $name = "WooCommerce 2";
	public $description = "Payment Gateway through Conekta.io for Woocommerce for both credit and debit cards as well as cash payments in OXXO and monthly installments for Mexican credit cards.";
	public $plugin_name = "Conekta Payment Gateway for Woocommerce";
	public $plugin_URI = "https://wordpress.org/plugins/conekta-woocommerce/";
	public $author = "Conekta.io";
	public $author_URI = "https://www.conekta.io";

	protected $lang;
	protected $lang_messages;

	public function ckpg_get_version()
	{
		return $this->version;
	}

	public function ckpg_set_locale_options()
	{
		if (function_exists("get_locale") && get_locale() !== "") {
			$current_lang = explode("_", get_locale());
			$this->lang = $current_lang[0];
			$filename = "lang/" . $this->lang . ".php";
			if (!file_exists(plugin_dir_path(__FILE__) . $filename))
				$filename = "lang/en.php";
			$this->lang_messages = require($filename);
			\Conekta\Conekta::setLocale($this->lang);
		}

		return $this;
	}

	public function ckpg_get_lang_options()
	{
		return $this->lang_messages;
	}

	public function ckpg_offline_payment_notification($order_id, $customer)
	{
		global $woocommerce;
		$order = new WC_Order($order_id);

		$title = sprintf("Se ha efectuado el pago del pedido %s", $order->get_order_number());
		$body_message = "<p style=\"margin:0 0 16px\">Se ha detectado el pago del siguiente pedido:</p><br />" . $this->ckpg_assemble_email_payment($order);

		// Email for customer
		$customer = esc_html($customer);
		$customer = sanitize_text_field($customer);

		$mail_customer = $woocommerce->mailer();
		$message = $mail_customer->wrap_message(
			sprintf(__('Hola, %s'), $customer),
			$body_message
		);
		$mail_customer->send($order->get_billing_email(), $title, $message);
		unset($mail_customer);
		//Email for admin site
		$mail_admin = $woocommerce->mailer();
		$message = $mail_admin->wrap_message(
			sprintf(__('Pago realizado satisfactoriamente')),
			$body_message
		);
		$mail_admin->send(get_option("admin_email"), $title, $message);
		unset($mail_admin);




        // Inicio - Correo admins
        $items = $order->get_items();
        $categories_ids = array();
        $products_names = '';
        foreach ($items as $item ) {
            $product = $item->get_product(); // the WC_Product Object
            //$product_category_ids  = $product->get_category_ids(); // An array of terms Ids
            $categories_ids = array_merge( $categories_ids, $product->get_category_ids() );
            $products_names = empty($items_names) ? $item['name'] : $products_names + ', ' + $item['name'];
        }
        if (!empty($categories_ids[0])) {
            $this->account = $this->accounts[$categories_ids[0]];
	        if ( !empty( $this->account['email'] ) ) {
	          //wc_add_notice( 'Enviando correo administrativo.', 'error' );
	          $order_id = $order->get_id();
	          $order_data = $order->get_data();
	          $user_email = $order->get_billing_email();
	          add_filter( 'wp_mail_content_type', create_function( '', 'return "text/html";' ) );
	          $subject = 'Pago por ' . $order->get_payment_method_title() . ' en ' . $this->account['name'] . ' - Estado del pago: Pago recibido.';
	          $message = '
	            <div>Orden: ' . $order_id . '</div>
	            <div>Método de pago: ' . $order->get_payment_method_title() . '</div>
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
	            <div>Notas del pedido: ' . $order->get_customer_note() . '</div>
	            <div>Productos: ' . $products_names . '</div>
	            <div>Total: ' . $order->get_formatted_order_total() . '</div>
	          ';

	          $headers = array();
	          $headers[] = 'Content-Type: text/html; charset=UTF-8';
	          $headers[] = 'From: TiendaChristus <ventas@tiendachristus.com>';
	          wp_mail( $this->account['email'], $subject, $message, $headers );
	          //wc_add_notice( 'Correo enviado.', 'error' );
	        }
	        else {
                $order->add_order_note( 'Afiliación sin correo para notificar.' );
	        }
	    }
        else {
            $order->add_order_note( 'No se encontro afiliación para esta orden.' );
        }
        // Fin - Correo admins
	}

	public function ckpg_assemble_email_payment($order)
	{
		ob_start();

		wc_get_template('emails/email-order-details.php', array('order' => $order, 'sent_to_admin' => false, 'plain_text' => false, 'email' => ''));

		return ob_get_clean();
	}
}
