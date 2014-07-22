<?php
	ini_set('display_errors', 1);
	error_reporting(E_ALL);
	
	$nzshpcrt_gateways[$num]['name']                                  = __( 'MerchantPlus', 'wpsc' );
	$nzshpcrt_gateways[$num]['api_version']                           = 3.1;
	$nzshpcrt_gateways[$num]['class_name']                            = 'wpsc_merchant_merchantplus';
	$nzshpcrt_gateways[$num]['has_recurring_billing']                 = true;
	$nzshpcrt_gateways[$num]['wp_admin_cannot_cancel']                = true;
	$nzshpcrt_gateways[$num]['display_name']                          = __( 'MerchantPlus', 'wpsc' );
	$nzshpcrt_gateways[$num]['image']                                 = WPSC_URL . '/images/cc.gif';
	$nzshpcrt_gateways[$num]['requirements']['php_version']           = 4.3;
	$nzshpcrt_gateways[$num]['requirements']['extra_modules']         = array();
	$nzshpcrt_gateways[$num]['form']                                  = 'form_merchantplus';
	$nzshpcrt_gateways[$num]['submit_function']                       = 'submit_merchantplus';
	$nzshpcrt_gateways[$num]['internalname']                          = 'wpsc_merchant_merchantplus';
	$nzshpcrt_gateways[$num]['payment_type']                          = 'merchantplus';
	$nzshpcrt_gateways[$num]['supported_currencies']['currency_list'] = array( 'AUD', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'SGD', 'THB', 'TWD', 'USD' );
	$nzshpcrt_gateways[$num]['supported_currencies']['option_name']   = 'merchantplus_curcode';	

	class wpsc_merchant_merchantplus extends wpsc_merchant {
		
		var $name                    = '';
		var $merchantplus_ipn_values = array( );
		
		function __construct( $purchase_id = null, $is_receiving = false ) {
			$this->name        = __( 'MerchantPlus', 'wpsc' );
			$this->gateway_url = 'https://gateway.merchantplus.com/cgi-bin/PAWebClient.cgi';
			parent::__construct( $purchase_id, $is_receiving );
		}
		
		function get_local_currency_code() {
			if ( empty( $this->local_currency_code ) ) {
				global $wpdb;
				$this->local_currency_code = $wpdb->get_var( $wpdb->prepare( "SELECT `code` FROM `".WPSC_TABLE_CURRENCY_LIST."` WHERE `id`= %d LIMIT 1", get_option( 'currency_type' ) ) );
			}
			
			return $this->local_currency_code;
		}
		
		function get_merchantplus_currency_code() {
			if ( empty( $this->merchantplus_currency_code ) ) {
				global $wpsc_gateways;
				$this->merchantplus_currency_code = $this->get_local_currency_code();
				
				if ( ! in_array( $this->merchantplus_currency_code, $wpsc_gateways['wpsc_merchant_merchantplus']['supported_currencies']['currency_list'] ) )
				$this->merchantplus_currency_code = get_option( 'merchantplus_curcode', 'USD' );
			}
			
			return $this->merchantplus_currency_code;
		}
		
		/**
			* construct value array method, converts the data gathered by the base class code to something acceptable to the gateway
			* @access public
		*/
		function construct_value_array() {
			
			// Cart Item Data
			$i = $item_total = 0;
			$tax_total = wpsc_tax_isincluded() ? 0 : $this->cart_data['cart_tax'];			
			$shipping_total = $this->convert( $this->cart_data['base_shipping'] );
			
			foreach ( $this->cart_items as $cart_row ) {				
				$shipping_total += $this->convert( $cart_row['shipping'] );
				$item_total += $this->convert( $cart_row['price'] ) * $cart_row['quantity'];				
				$i++;
			}
			
			if ( $this->cart_data['has_discounts'] ) {
				$discount_value = $this->convert( $this->cart_data['cart_discount_value'] );				
				$coupon = new wpsc_coupons( $this->cart_data['cart_discount_data'] );				
				if ( $coupon->is_percentage == 2 ) {
					$shipping_total = 0;
					$discount_value = 0;
				} 
				elseif ( $discount_value >= $item_total ) {
					$discount_value = $item_total - 0.01;
					$shipping_total -= 0.01;
				}				
				$item_total -= $discount_value;
			}
			
			// Store settings to be sent to merchantplus			
			$params						   = array();
			
			// Merchant Info
			$params['x_login']             = get_option( 'merchantplus_loginid' );
			$params['x_tran_key']          = get_option( 'merchantplus_txn_key' );
			
			// TEST TRANSACTION
			$params['x_test_request']      = (get_option( 'merchantplus_testmode' ) == "on" ? 'TRUE' : 'FALSE');
			
			// AIM Head
			$params['x_version']           = '3.1';
			
			// TRUE Means that the Response is going to be delimited
			$params['x_delim_data']        = 'TRUE';
			$params['x_delim_char']        = '|';
			$params['x_relay_response']    = 'FALSE';
			
			// Transaction Info
			$params['x_method']            = 'CC';
			$params['x_type']              = get_option( 'merchantplus_txn_method' );
			$params['x_amount']            = $this->format_price( $item_total )+$this->format_price( $shipping_total )+$this->convert( $tax_total );
			
			// Test Card				
			$params['x_card_num']          = str_replace( array(' ', '-'), '', $_POST['card_number'] );
			$params['x_exp_date']          = $_POST['expiry']['month'] . $_POST['expiry']['year'];
			$params['x_card_code']         = $_POST['card_code'];
			$params['x_trans_id']          = '';
			
			// Order Info
			$params['x_invoice_num']       = $this->cart_data['session_id'];
			$params['x_description']       = '';
			
			// Customer Info
			$params['x_first_name']        = $this->cart_data['billing_address']['first_name'];
			$params['x_last_name']         = $this->cart_data['billing_address']['last_name'];
			$params['x_company']           = '';
			$params['x_address']           = $this->cart_data['billing_address']['address'];
			$params['x_city']              = $this->cart_data['billing_address']['city'];
			$params['x_state']             = $this->cart_data['billing_address']['state'];
			$params['x_zip']               = $this->cart_data['billing_address']['post_code'];
			$params['x_country']           = $this->cart_data['billing_address']['country'];
			$params['x_phone']             = '';
			$params['x_fax']               = '';
			$params['x_email']             = $this->cart_data['email_address'];
			$params['x_cust_id']           = '';
			$params['x_customer_ip']       = $_SERVER["REMOTE_ADDR"];
			
			// shipping info
			$params['x_ship_to_first_name']= $this->cart_data['shipping_address']['first_name'];
			$params['x_ship_to_last_name'] = $this->cart_data['shipping_address']['last_name'];
			$params['x_ship_to_company']   = '';
			$params['x_ship_to_address']   = $this->cart_data['shipping_address']['address'];
			$params['x_ship_to_city']      = $this->cart_data['shipping_address']['city'];
			$params['x_ship_to_state']     = $this->cart_data['shipping_address']['state'];
			$params['x_ship_to_zip']       = $this->cart_data['shipping_address']['post_code'];
			$params['x_ship_to_country']   = $this->cart_data['shipping_address']['country'];
			
			return $params;
		}
		
		/**
			* submit method, sends the received data to the payment gateway
			* @access public
		*/
		function submit() {
			$params = $this->construct_value_array();
			$options = array(
				'timeout'    => 10,
				'body'       => http_build_query($params, '', '&'),
				'user-agent' => $this->cart_data['software_name'] ." " . get_bloginfo( 'url' ),
				'sslverify'  => false
			);
			$response = wp_remote_post($this->gateway_url, $options);
			
			if(!is_wp_error($response) && $response['response']['code']>=200 && $response['response']['code']<300){
				$data = $response['body'];
				$delim = $data{1};					
				$data = explode($delim, $data);	
				
				if($data[0] == 1){
					$this->set_transaction_details( $data[6], 3 );
					$this->go_to_transaction_results( $this->cart_data['session_id'] );	
				}else{
					$error = $this->error_status();						
					$this->set_error_message( $error[$data[0]][$data[2]] );
				}
			}
			else{
				$this->set_error_message( 'Gateway Error. Please Notify the Store Owner about this error.' );				
			}			
		}
		
		function format_price( $price ) {
			$merchantplus_currency_code = get_option( 'merchantplus_curcode' );
			
			switch ( $merchantplus_currency_code ) {
				case "JPY":
					$decimal_places = 0;
				break;
				
				case "HUF":
					$decimal_places = 0;
				
				default:
					$decimal_places = 2;
				break;
			}			
			$price = number_format( sprintf( "%01.2f", $price ), $decimal_places, '.', '' );
			
			return $price;
		}
		
		function convert( $amt ){
			if ( empty( $this->rate ) ) {
				$this->rate = 1;
				$merchantplus_currency_code = $this->get_merchantplus_currency_code();
				$local_currency_code = $this->get_local_currency_code();
				if( $local_currency_code != $merchantplus_currency_code ) {
					$curr=new CURRENCYCONVERTER();
					$this->rate = $curr->convert( 1, $merchantplus_currency_code, $local_currency_code );
				}
			}			
			return $this->format_price( $amt * $this->rate );
		}
		
		function error_status() {
			$error[2][2] 	= 'This transaction has been declined.';
			$error[3][6] 	= 'The credit card number is invalid.';
			$error[3][7] 	= 'The credit card expiration date is invalid.';
			$error[3][8] 	= 'The credit card expiration date is invalid.';
			$error[3][13] 	= 'The merchant Login ID or Password or TransactionKey is invalid or the account is inactive.';
			$error[3][15] 	= 'The transaction ID is invalid.';
			$error[3][16] 	= 'The transaction was not found';
			$error[3][17] 	= 'The merchant does not accept this type of credit card.';
			$error[3][19] 	= 'An error occurred during processing. Please try again in 5 minutes.';
			$error[3][33] 	= 'A required field is missing.';
			$error[3][42] 	= 'There is missing or invalid information in a parameter field.';
			$error[3][47] 	= 'The amount requested for settlement may not be greater than the original amount authorized.';
			$error[3][49] 	= 'A transaction amount equal or greater than $100000 will not be accepted.';
			$error[3][50] 	= 'This transaction is awaiting settlement and cannot be refunded.';
			$error[3][51] 	= 'The sum of all credits against this transaction is greater than the original transaction amount.';
			$error[3][57] 	= 'A transaction amount less than $1 will not be accepted.';
			$error[3][64] 	= 'The referenced transaction was not approved.';
			$error[3][69] 	= 'The transaction type is invalid.';
			$error[3][70] 	= 'The transaction method is invalid.';
			$error[3][72] 	= 'The authorization code is invalid.';
			$error[3][73] 	= 'The driver\'s license date of birth is invalid.';
			$error[3][84] 	= 'The referenced transaction was already voided.';
			$error[3][85] 	= 'The referenced transaction has already been settled and cannot be voided.';
			$error[3][86] 	= 'Your settlements will occur in less than 5 minutes. It is too late to void any existing transactions.';
			$error[3][87] 	= 'The transaction submitted for settlement was not originally an AUTH_ONLY.';
			$error[3][88] 	= 'Your account does not have access to perform that action.';
			$error[3][89] 	= 'The referenced transaction was already refunded.';
			$error[3][90] 	= 'Data Base Error.';
			
			return $error;
		}
		
	}
	
	function submit_merchantplus() {
		if ( isset( $_POST['merchantplus']['merchantplus_loginid'] ) )
		update_option( 'merchantplus_loginid', $_POST['merchantplus']['merchantplus_loginid'] );
		
		if ( isset( $_POST['merchantplus']['merchantplus_txn_key'] ) )
		update_option( 'merchantplus_txn_key', $_POST['merchantplus']['merchantplus_txn_key'] );
		
		if ( isset( $_POST['merchantplus']['merchantplus_txn_method'] ) )
		update_option( 'merchantplus_txn_method', $_POST['merchantplus']['merchantplus_txn_method'] );
		
		if(isset($_POST['merchantplus_curcode']))
		update_option('merchantplus_curcode', $_POST['merchantplus_curcode']);
		
		if ( isset( $_POST['merchantplus']['testmode'] ) )
		update_option( 'merchantplus_testmode', $_POST['merchantplus']['testmode'] );
		
		return true;
	}
	
	function form_merchantplus() {
		global $wpsc_gateways, $wpdb;
		if ( get_option( 'merchantplus_testmode' ) == "on" ){
			$selected = 'checked="checked"';
		}
		else{
			$selected = '';
		}
		$output = '
		<tr>
			<td>
				<label for="merchantplus_username">' . __( 'Login ID:', 'wpsc' ) . '</label>
			</td>
			<td>
				<input type="text" name="merchantplus[merchantplus_loginid]" id="merchantplus_loginid" value="' . get_option( "merchantplus_loginid" ) . '" size="30" />
			</td>
		</tr>
		<tr>
			<td>
				<label for="merchantplus_password">' . __( 'Transaction Key:', 'wpsc' ) . '</label>
			</td>
			<td>
				<input type="password" name="merchantplus[merchantplus_txn_key]" id="merchantplus_txn_key" value="' . get_option( 'merchantplus_txn_key' ) . '" size="16" />
			</td>
		</tr>
		<tr>
			<td>
				<label for="merchantplus_password">' . __( 'Transaction Method:', 'wpsc' ) . '</label>
			</td>
			<td>
				<select name="merchantplus[merchantplus_txn_method]" id="merchantplus_txn_method">
					<option value="AUTH_CAPTURE" '.(get_option( 'merchantplus_txn_method' ) == "AUTH_CAPTURE" ? "selected":"").'> CAPTURE</option>
					<option value="AUTH_ONLY" '.(get_option( 'merchantplus_txn_method' ) == "AUTH_ONLY" ? "selected":"").'> AUTHORIZATION</option>
				</select>				
			</td>
		</tr>		
		<tr>
			<td>
				<label for="merchantplus_testmode">' . __( 'Test Mode Enabled:', 'wpsc' ) . '</label>
			</td>
			<td>
				<input type="hidden" name="merchantplus[testmode]" value="off" /><input type="checkbox" name="merchantplus[testmode]" id="merchantplus_testmode" value="on" ' . $selected . ' />
			</td>
		</tr>
		';
		
		$store_currency_code = $wpdb->get_var( $wpdb->prepare( "SELECT `code` FROM `".WPSC_TABLE_CURRENCY_LIST."` WHERE `id` IN (%d)", get_option( 'currency_type' ) ) );
		$current_currency = get_option('merchantplus_curcode');
		
		if(($current_currency == '') && in_array($store_currency_code, $wpsc_gateways['wpsc_merchant_merchantplus']['supported_currencies']['currency_list'])) {
			update_option('merchantplus_curcode', $store_currency_code);
			$current_currency = $store_currency_code;
		}
		if($current_currency != $store_currency_code) {
			$output .= 
			"<tr> 
				<td colspan='2'><strong class='form_group'>" . __( 'Currency Converter', 'wpsc' ) . "</td> 
			</tr>
			<tr>
				<td colspan='2'>".__('Your website is using a currency not accepted by merchantplus.net, select an accepted currency using the drop down menu below. Buyers on your site will still pay in your local currency however we will convert the currency and send the order through to merchantplus.net using the currency you choose below.', 'wpsc')."</td>
			</tr>\n";
			
			$output .= 
			"<tr>\n 
				<td>" . __('Convert to', 'wpsc' ) . " </td>\n ";
			$output .= 
				"<td>\n <select name='merchantplus_curcode'>\n";
			
			if (!isset($wpsc_gateways['wpsc_merchant_merchantplus']['supported_currencies']['currency_list']))
				$wpsc_gateways['wpsc_merchant_merchantplus']['supported_currencies']['currency_list'] = array();
			
			$merchantplus_currency_list = array_map( 'esc_sql', $wpsc_gateways['wpsc_merchant_merchantplus']['supported_currencies']['currency_list'] );
			
			$currency_list = $wpdb->get_results("SELECT DISTINCT `code`, `currency` FROM `".WPSC_TABLE_CURRENCY_LIST."` WHERE `code` IN ('".implode("','",$merchantplus_currency_list)."')", ARRAY_A);
			foreach($currency_list as $currency_item) {
				$selected_currency = '';
				if($current_currency == $currency_item['code']) {
					$selected_currency = "selected='selected'";
				}
				$output .= "<option ".$selected_currency." value='{$currency_item['code']}'>{$currency_item['currency']}</option>";
			}
			$output .= "            </select> \n";
			$output .= "          </td>\n";
			$output .= "       </tr>\n";
		}
		return $output;
	}
	
	$years = '';	
	if ( in_array( 'wpsc_merchant_merchantplus', (array)get_option( 'custom_gateway_options' ) ) ) {
		
		$curryear = date( 'Y' );		
		for ( $i = 0; $i < 10; $i++ ) {
			$years .= "<option value='" . $curryear . "'>" . $curryear . "</option>\r\n";
			$curryear++;
		}
		
		$output = "
		<tr>
			<td class='wpsc_CC_details'>" . __( 'Credit Card Number *', 'wpsc' ) . "</td>
			<td>
				<input type='text' value='' name='card_number' />
			</td>
		</tr>
		<tr>
			<td class='wpsc_CC_details'>" . __( 'Credit Card Expiry *', 'wpsc' ) . "</td>
			<td>
				<select class='wpsc_ccBox' name='expiry[month]'>
					<option value='01'>01</option>
					<option value='02'>02</option>
					<option value='03'>03</option>
					<option value='04'>04</option>
					<option value='05'>05</option>
					<option value='06'>06</option>
					<option value='07'>07</option>
					<option value='08'>08</option>
					<option value='09'>09</option>
					<option value='10'>10</option>
					<option value='11'>11</option>
					<option value='12'>12</option>
				</select>
				<select class='wpsc_ccBox' name='expiry[year]'>
					" . $years . "
				</select>
			</td>
		</tr>
		<tr>
			<td class='wpsc_CC_details'>" . __( 'CVV *', 'wpsc' ) . "</td>
			<td>
				<input type='text' size='4' value='' maxlength='4' name='card_code' />
			</td>
		</tr>
		<tr>
			<td class='wpsc_CC_details'>" . __( 'Card Type *', 'wpsc' ) . "</td>
			<td>
			<select class='wpsc_ccBox' name='cctype'>";			
				$card_types = array(
					'Visa'       => __( 'Visa', 'wpsc' ),
					'Mastercard' => __( 'MasterCard', 'wpsc' ),
					'Discover'   => __( 'Discover', 'wpsc' ),
					'Amex'       => __( 'Amex', 'wpsc' ),
				);
				$card_types = apply_filters( 'wpsc_merchantplus_accepted_card_types', $card_types );
				foreach ( $card_types as $type => $title ) {
					$output .= sprintf( '<option value="%1$s">%2$s</option>', $type, esc_html( $title ) );
				}
				$output .= "</select>
			</td>
		</tr>
		";		
		$gateway_checkout_form_fields[$nzshpcrt_gateways[$num]['internalname']] = $output;		
	}	
?>
