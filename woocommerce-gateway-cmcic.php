<?php
/**
 * Plugin Name: WooCommerce CM-CIC Gateway
 * Plugin URI: https://github.com/doctype-fr/woocommerce-gateway-cmcic
 * Description: Extends WooCommerce with an CM-CIC gateway.
 * Version: 0.9.0
 * Author: DOCTYPE
 * Author URI: https://github.com/doctype-fr
 * Text Domain: wccmcic
 * Domain Path: /languages/
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action('plugins_loaded', 'woocommerce_gateway_cmcic_init', 0);

function woocommerce_gateway_cmcic_init()
{
    if ( !class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }

    /**
     * Load textdomain.
     */
    load_plugin_textdomain( 'wccmcic', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

    /**
     * Gateway class
     */
    class WC_Gateway_CMCIC extends WC_Payment_Gateway
    {
		const VERSION	= '3.0';

		public $notify_url = '';

		public function __construct()
		{
		    global $woocommerce;

		    $this->id			= 'cmcic';
		    $this->has_fields		= false;
		    $this->method_title		= 'CM-CIC';
		    $this->method_description	= 'CM-CIC';
		    $this->notify_url		= add_query_arg('wc-api', 'WC_Gateway_CMCIC', home_url('/'));;

		    // Load the settings.
	            $this->init_form_fields();
		    $this->init_settings();

		    $this->title	= $this->get_option('title');
		    $this->description	= $this->get_option('description');
		    $this->icon		= $this->get_option('icon', plugins_url('images/logo-cb-visa-mastercard.png', __FILE__ ));

		    add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
	        add_action('woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		    add_action('woocommerce_api_wc_gateway_cmcic', array($this, 'check_ipn_response'));
		    add_action('valid_cmcic_ipn_request', array($this, 'valid_ipn_request'));
        }

		/**
         * Output for the order received page.
         */
        function receipt_page($order_id)
		{
	    	global $woocommerce;

	    	if ($this->get_option('auto_submit') == 'yes') {
				echo '<p>' . __( 'Thank you - your order is now pending payment. You should be automatically redirected to CM-CIC to make payment.', 'wccmcic' ) . '</p>';
	    	}

	    	echo $this->get_payment_html($order_id);

	    	if ($this->get_option('debug') == 'yes') {
				echo '<pre style="padding:5px;border:1px solid red;">';
				foreach ($this->_get_payment_params($order_id) as $k => $v) {
		    		echo $k . ' : ' . $v . PHP_EOL;
				}
				echo '</pre>';
	    	} else {
				if ($this->get_option('auto_submit') == 'yes') {
		    		$woocommerce->add_inline_js('jQuery("#cmcic_payment_form").submit();');
				}
	    	}
        }

		/**
	 	* Check for CM-CIC IPN Response
	 	*/
		public function check_ipn_response()
		{
            if (($this->get_option('testing', 'no')=='yes') && (!empty($_GET['order_id']))) {
                $data = array(
                    'reference'     => (int)$_REQUEST['order_id'],
                    'code-retour'   => 'paiement',
                );

                do_action( "valid_cmcic_ipn_request", $data);
				echo sprintf("version=2\ncdr=%d", 0);
                exit();
            }

	    	$data = array();
	    	$keys = array(
				'MAC',
				'date',
				'TPE',
				'montant',
				'reference',
				'texte-libre',
				'code-retour',
				'cvx',
				'vld',
				'brand',
				'status3ds',
				'numauto',
				'motifrefus',
				'originecb',
				'bincb',
				'hpancb',
				'ipclient',
				'originetr',
				'veres',
				'pares',
				'montantech',
				'filtragecause',
				'filtragevaleur',
				'cbenregistree',
				'cbmasquee',
			    );

	    	$data = array_intersect_key($_POST, array_flip($keys));

            $this->_log('ipn', array(
                'get'       => $_GET,
                'post'      => $_POST,
                'server'    => $_SERVER,
                'data'      => $data,
                'mac'       => $this->_compute_mac_retour($data),
            ));

	    	if ( ($this->_compute_mac_retour($data) == strtolower($data['MAC'])) && !empty($data['MAC']) ) {
				do_action( "valid_cmcic_ipn_request", $data);
				echo sprintf("version=2\ncdr=%d", 0);
	    	} else {
				echo sprintf("version=2\ncdr=%d", 1);
	    	}

	    	exit();
        }

		/**
		 * Process the payment and return the result
		 */
		public function process_payment( $order_id )
		{
		    $order = new WC_Order($order_id);

		    return array(
				'result' 	=> 'success',
				'redirect'	=> add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
		    );
		}

		/**
		 * Successful Payment!
		 */
		public function valid_ipn_request($data)
		{
		    $order = new WC_Order((int)$data['reference']);

		    if (($order->status != 'completed') && !empty($order->id)) {
				foreach ($data as $k => $v) {
			    	add_post_meta($order->id, '_cmcic_'.$k, $v, true);
				}

				switch ($data['code-retour']) {
			    	case 'payetest':
			    	case 'paiement':
						$order->reduce_order_stock();
						$order->add_order_note(__('Payment is successfully completed.', 'woocommerce'));
						$order->payment_complete();
						break;
				}
		    }
		}


		/**
		 * Get HTML payment form
		 */
		public function get_payment_html($order_id)
		{
		    $params = $this->_get_payment_params($order_id);

		    $form   = '<form action="'.$this->_get_payment_url().'" method="post" id="cmcic_payment_form" target="_top">';

		    foreach ($params as $k => $v) {
				$form .= '<input type="'.($k=='bouton' ? 'submit' : 'hidden').'" name="'.esc_attr($k).'" value="'.esc_attr($v).'" />';
		    }

		    $form .= '</form>';

		    return $form;
		}

		/**
		 * Get the form post URL
		 */
		protected function _get_payment_url()
		{
		    switch (strtoupper($this->get_option('banque'))) {
			case 'CIC':
			    return ($this->get_option('testing', 'no')=='yes' ? 'https://ssl.paiement.cic-banques.fr/test/paiement.cgi' : 'https://ssl.paiement.cic-banques.fr/paiement.cgi');
			case 'OBC':
			    return ($this->get_option('testing', 'no')=='yes' ? 'https://ssl.paiement.banque-obc.fr/test/paiement.cgi' : 'https://ssl.paiement.banque-obc.fr/paiement.cgi');
			case 'CM':
			default:
			    return ($this->get_option('testing', 'no')=='yes' ? 'https://paiement.creditmutuel.fr/test/paiement.cgi' : 'https://paiement.creditmutuel.fr/paiement.cgi');
		    }
		}

		/**
		 * Get the payment form parameters from the order
		 */
		protected function _get_payment_params($order_id)
		{
		    $order = new WC_Order($order_id);

		    $params = array(
				'version'	=> self::VERSION,
				'TPE'		=> $this->get_option('tpe'),
				'date'		=> date('d/m/Y:H:i:s'),
				'montant'	=> number_format( $order->get_total(), 2, '.', '' ).get_woocommerce_currency(),
				'reference'	=> $order_id,
				'texte-libre'	=> $order_id . '_' . $order->order_key,
				'mail'		=> $order->billing_email,
				'lgue'		=> $this->get_option('language'),
				'societe'	=> $this->get_option('codesociete'),
				'url_retour'	=> home_url(),
				'url_retour_ok'	=> $this->get_return_url($order),
				'url_retour_err'=> $order->get_cancel_order_url(),
				'options'	=> '',
				'bouton'	=> $this->get_option('text_button', __('Valider', 'wccmcic')),
		    );

		    $params = apply_filters('woocommerce_gateway_cmcic_params', $params);

		    $params['MAC']  = $this->_compute_mac_payment($params);

		    return $params;
		}

		/**
		 * Compute the message authentication code from payment parameters
		 */
		protected function _compute_mac_payment($params)
		{
		    $data   = $params['TPE'] . '*'
			    . $params['date'] . '*'
			    . $params['montant'] . '*'
			    . $params['reference'] . '*'
			    . $params['texte-libre'] . '*'
			    . $params['version'] . '*'
			    . $params['lgue'] . '*'
			    . $params['societe'] . '*'
			    . $params['mail'] . '*'
			    . '*********'
			    . $params['options'];

	            $key = $this->_get_mac_key();

	            return strtolower(hash_hmac('sha1', $data, $key));
		}

		/**
		 * Compute the message authentication code from IPN parameters
		 */
		protected function _compute_mac_retour($params)
		{
		    $data   = $params['TPE'] . '*'
			    . $params['date'] . '*'
			    . $params['montant'] . '*'
			    . $params['reference'] . '*'
			    . $params['texte-libre'] . '*'
			    . self::VERSION . '*'
			    . $params['code-retour'] . '*'
			    . $params['cvx'] . '*'
			    . $params['vld'] . '*'
			    . $params['brand'] . '*'
			    . $params['status3ds'] . '*'
			    . $params['numauto'] . '*'
			    . $params['motifrefus'] . '*'
			    . $params['originecb'] . '*'
			    . $params['bincb'] . '*'
			    . $params['hpancb'] . '*'
			    . $params['ipclient'] . '*'
			    . $params['originetr'] . '*'
			    . $params['veres'] . '*'
			    . $params['pares'] . '*'
			    ;

		    return strtolower(hash_hmac('sha1', $data, $this->_get_mac_key()));
		}

		/**
		 * Compute the HMAC usable key from bank key
		 */
		protected function _get_mac_key()
		{
		    $hex_key    = substr($this->get_option('cle'), 0, 38);
		    $hex_final  = substr($this->get_option('cle'), 38, 2) . '00';
		    $cca0	= ord($hex_final);

		    if (($cca0 > 70) && ($cca0 < 97)) {
			$hex_key .= chr($cca0 - 23) . substr($hex_final, 1, 1);
		    } else  {
			if (substr($hex_final, 1, 1)=='M')  {
			    $hex_key .= substr($hex_final, 0, 1) . '0';
			} else {
			    $hex_key .= substr($hex_final, 0, 2);
			}
		    }

		    return pack('H*', $hex_key);
		}

	/**
		* Start Gateway Settings Form Fields.
		*/
		public function init_form_fields()
		{

		    $this->form_fields = array(
			'enabled' => array(
			    'title'	    => __( 'Enable/Disable', 'woocommerce' ),
			    'type'	    => 'checkbox',
			    'label'	    => __( 'Enable CM-CIC', 'wccmcic' ),
			    'description'   => '',
			    'default'	    => 'yes'
			),
			'title' => array(
			    'title'       => __( 'Title', 'woocommerce' ),
			    'type'        => 'text',
			    'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
			    'default'     => __( 'CM-CIC', 'wccmcic' ),
			    'desc_tip'    => true,
			),
			'description' => array(
			    'title'       => __( 'Description', 'woocommerce' ),
			    'type'        => 'text',
			    'desc_tip'    => true,
			    'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
			    'default'     => __( 'Pay safely via CM-CIC with your credit card.', 'wccmcic' ),
			),
			'text_button' => array(
			    'title'	    => __('Text submit button', 'wccmcic'),
			    'type'	    => 'text',
			    'default'	    => __('Secure payment', 'wccmcic')
			),
			'auto_submit' => array(
			    'title'	    => __('Auto submit form', 'wccmcic'),
			    'type'	    => 'checkbox',
			    'label'	    => '',
			    'default'	    => 'no'
			),
			'language' => array(
			    'title'	    => __( 'Language', 'wccmcic'),
			    'type'	    => 'select',
			    'default'	=> 'FR',
			    'options'	=> array(
					'FR'	    => 'Francais',
					'EN'	    => 'English',
					'ES'	    => 'Español',
					'DE'	    => 'Deutsch',
					'NL'	    => 'Dutch',
					'PT'	    => 'Português',
					'IT'	    => 'Italiano',
					'SV'	    => 'Svenska',
			    ),
			),
			'banque' => array(
			    'title'	    => __('Bank code', 'wccmcic'),
			    'type'	    => 'select',
			    'default'	    => 'CM',
			    'options'	    => array(
					'CM'	    => 'Crédit-Mutuel',
					'CIC'	    => 'CIC',
					'OBC'	    => 'OBC',
			    ),
			),
			'cle' => array(
			    'title'	    => __('Key', 'wccmcic'),
			    'type'	    => 'text',
			    'default'	    => ''
			),
			'tpe' => array(
			    'title'	    => __('Credit card terminal number', 'wccmcic'),
			    'type'	    => 'text',
			    'default'	    => ''
			),
			'codesociete' => array(
			    'title'	    => __('Company code', 'wccmcic'),
			    'type'	    => 'text',
			    'default'	    => ''
			),
			'testing' => array(
			    'title'	    => __( 'Gateway Testing', 'wccmcic' ),
			    'type'	    => 'checkbox',
			    'description'   => __( 'Enable CM-CIC testing gateway', 'wccmcic' ),
			    'default'	    => 'yes',
			),
			'debug' => array(
			    'title'	    => __( 'Debug', 'wccmcic' ),
			    'type'	    => 'checkbox',
			    'description'   => __( 'Display form values', 'wccmcic' ),
			    'default'	    => 'no',
			),

		    );
		}

		public function admin_options()
		{
		    $errors = '';
		    if (!$this->get_option('cle')) $errors .= '<p>'.__('Missing Key', 'wccmcic').'</p>';
		    if (!$this->get_option('tpe')) $errors .= '<p>'.__('Missing terminal number', 'wccmcic').'</p>';
		    if (!$this->get_option('codesociete')) $errors .= '<p>'.__('Missing company code', 'wccmcic').'</p>';
		    ?>

	    	<?php if (!empty($errors)): ?>
	    	<div class='inline error'><?php echo $errors; ?></div>
	    	<?php endif; ?>

	        <table class="form-table">
	            <?php $this->generate_settings_html(); ?>
	        </table>

	    	<table class="form-table">
	    		<tr valign="top">
	    		    <th scope="row" class="titledesc">
	    			<label>Return URL</label>
	    		    </th>
	    		    <td class="forminp">
	    			<fieldset>
	    			    <legend class="screen-reader-text"><span>Return URL</span></legend>
	    			    <?php echo $this->get_return_url(); ?>
	    			</fieldset>
	    		    </td>
	    		</tr>
	    		<tr valign="top">
	    		    <th scope="row" class="titledesc">
	    			<label>IPN URL</label>
	    		    </th>
	    		    <td class="forminp">
	    			<fieldset>
	    			    <legend class="screen-reader-text"><span>IPN URL</span></legend>
	    			    <?php echo $this->notify_url; ?>
	    			</fieldset>
	    		    </td>
	    		</tr>
	    	</table>
	    	<?php
		}

        protected function _log($prefix, $data)
        {
            $logfile = dirname(__FILE__).'/logs/'.$prefix.'-'.date('YmdHis').'.txt';
            file_put_contents($logfile, json_encode($data));
        }

    }

    /**
    * Add the Gateway to WooCommerce
    **/
    function woocommerce_add_gateway_cmcic_gateway($methods)
    {
		$methods[] = 'WC_Gateway_CMCIC';
		return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_gateway_cmcic_gateway' );
}
