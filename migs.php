<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* MIGS Payment Gateway Class */

class MIGS extends WC_Payment_Gateway {

	// Setup our Gateway's id, description and other values
	function __construct() {

		// The global ID for this Payment method
		$this->id = "migs";

		// The Title shown on the top of the Payment Gateways Page next to all the other Payment Gateways
		$this->method_title = __( "MIGS", 'migs' );

		// The description for this Payment Gateway, shown on the actual Payment options page on the backend
		$this->method_description = __( "MIGS Payment Gateway Plug-in for WooCommerce", 'migs' );

		// The title to be used for the vertical tabs that can be ordered top to bottom
		$this->title = __( "MIGS", 'migs' );

		// If you want to show an image next to the gateway's name on the frontend, enter a URL to an image.
		$this->icon = MIGS_PLUGIN_URL . 'images/migs_icon.jpg';

		// Bool. Can be set to true if you want payment fields to show on the checkout
		// if doing a direct integration, which we are doing in this case
		$this->has_fields = false;

		// Supports the default credit card form
		//$this->supports = array('default_credit_card_form');
		// This basically defines your settings which are then loaded with init_settings()
		$this->init_form_fields();

		// After init_settings() is called, you can get the settings and load them into variables, e.g:
		// $this->title = $this->get_option( 'title' );
		$this->init_settings();

		// Turn these settings into variables we can use
		foreach ( $this->settings as $setting_key => $value ) {
			$this->$setting_key = $value;
		}
		add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'migs_response_handler' ) );

		// Lets check for SSL
		//add_action('admin_notices', array($this, 'do_ssl_check'));
		// Save settings
		if ( is_admin() ) {
			// Versions over 2.0
			// Save our administration options. Since we are not going to be doing anything special
			// we have not defined 'process_admin_options' in this class so the method in the parent
			// class will be used instead
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}
		//add_action('woocommerce_thankyou_migs', array($this, 'migs_response_handler'));
	}

// End __construct()
	// Build the administration fields for this specific Gateway
	public function init_form_fields() {
		$this->form_fields = array(
				'enabled'             => array(
						'title'   => __( 'Enable / Disable', 'migs' ),
						'label'   => __( 'Enable this payment gateway', 'migs' ),
						'type'    => 'checkbox',
						'default' => 'no',
				),
				'title'               => array(
						'title'    => __( 'Title', 'migs' ),
						'type'     => 'text',
						'desc_tip' => __( 'Payment title the customer will see during the checkout process.', 'migs' ),
						'default'  => __( 'Master card', 'migs' ),
				),
				'description'         => array(
						'title'    => __( 'Description', 'migs' ),
						'type'     => 'textarea',
						'desc_tip' => __( 'Payment description the customer will see during the checkout process.', 'migs' ),
						'default'  => __( 'Pay securely using your master card.', 'migs' ),
						'css'      => 'max-width:350px;'
				),
				'access_code'         => array(
						'title'    => __( 'MIGS Access Code', 'migs' ),
						'type'     => 'text',
						'desc_tip' => __( 'This is the Access Code MIGS when you signed up for an account.', 'migs' ),
				),
				'merchant_id'         => array(
						'title'    => __( 'MIGS Merchant ID', 'migs' ),
						'type'     => 'text',
						'desc_tip' => __( 'This is the Merchant ID when you signed up for an account.', 'migs' ),
				),
				'merchant_secret_key' => array(
						'title'    => __( 'MIGS Secret Key', 'migs' ),
						'type'     => 'password',
						'desc_tip' => __( 'This is Mertchant Secret Key when you signed up for an account.', 'migs' ),
				),
				'environment'         => array(
						'title'       => __( 'MIGS Test Mode', 'migs' ),
						'label'       => __( 'Enable Test Mode', 'migs' ),
						'type'        => 'checkbox',
						'description' => __( 'Place the payment gateway in test mode.', 'migs' ),
						'default'     => 'no',
				)
		);
	}


	/**
	 * Submit payment and handle response
	 *
	 * @param int $order_id
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		global $woocommerce;

		//Get this Order's information so that we know
		//who to charge and how much
		$customer_order = new WC_Order( $order_id );
		$protocol       = (!empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
		$payment_fields = array(
				'vpc_Currency'    => 'USD',//$customer_order->get_order_currency(),
				'vpc_AccessCode'  => trim( $this->access_code ),
				'vpc_Amount'      => $this->get_exact_amount( $customer_order->order_total ),
				'vpc_Command'     => 'pay',
				'vpc_Locale'      => 'en',
				'vpc_MerchTxnRef' => $order_id,
				'vpc_Merchant'    => trim( $this->merchant_id ),
				'vpc_OrderInfo'   => $customer_order->billing_email, // Always set user email in this field
				'vpc_ReturnURL'   => add_query_arg( 'wc-api', get_class( $this ), site_url() ),
				'vpc_Version'     => 1
		);
		ksort( $payment_fields );
		$payment_fields['vpc_SecureHash']     = $this->migs_generate_secure_hash( $this->merchant_secret_key, $payment_fields );
		$payment_fields['vpc_SecureHashType'] = 'SHA256';

		$url = sprintf( 'https://migs.mastercard.com.au/vpcpay?%s', http_build_query( $payment_fields ) );

		// Redirect to thank you page
		return array( 'result' => 'success', 'redirect' => $url );
	}

	// Validate fields
	public function validate_fields() {
		return true;
	}

	public function do_ssl_check() {
		if ( $this->enabled == "yes" ) {
			if ( get_option( 'woocommerce_force_ssl_checkout' ) == "no" ) {
				echo "<div class=\"error\"><p>" . sprintf( __( "<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>" ), $this->method_title, admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) . "</p></div>";
			}
		}
	}

	public function get_exact_amount( $amount ) {
		return ($amount * 100);
	}

	/**
	 * Handle response from MIGS
	 */
	public function migs_response_handler() {
		global $woocommerce;
		$response = $_REQUEST;

		// Validate response from MIGS is not tampered
		if ( !$this->migs_validate_response( $response ) ) {
			wc_add_notice( 'Message: Invalid MIGS response', 'error' );
			wp_redirect( $this->get_checkout_url() );
			exit;
		}

		$order_id       = $response['vpc_MerchTxnRef'];
		$customer_order = new WC_Order( $order_id );

		$txnResponseCode = $this->null2unknown( $_GET["vpc_TxnResponseCode"] );

		if ( $txnResponseCode == 0 ) {
			$customer_order->add_order_note( __( 'MIGS payment completed.', 'migs' ) );

			// Mark order as Paid
			$customer_order->payment_complete();
			wc_add_notice( 'Message: ' . $this->getResponseDescription( $txnResponseCode ) . '' );

			// Empty the cart (Very important step)
			$woocommerce->cart->empty_cart();
			update_post_meta( $order_id, 'migs_response_data', print_r($response ,true) );
			update_post_meta( $order_id, 'migs_response_unique_3d_transaction_identifier', $this->get_key_from_request( 'vpc_3DSXID' ) );
			update_post_meta( $order_id, 'migs_response_3d_authentication_value', $this->get_key_from_request( 'vpc_VerToken' ) );
			update_post_meta( $order_id, 'migs_response_3d_electronics_commerce', $this->get_key_from_request( 'vpc_3DSECI' ) );
			update_post_meta( $order_id, 'migs_response_3d_authentication_schema', $this->get_key_from_request( 'vpc_VerType' ) );
			update_post_meta( $order_id, 'migs_response_3d_security_level', $this->get_key_from_request( 'vpc_VerSecurityLevel' ) );
			update_post_meta( $order_id, 'migs_response_3d_enrolled', $this->get_key_from_request( 'vpc_3DSenrolled' ) );
			update_post_meta( $order_id, 'migs_response_3d_auth_status', $this->get_key_from_request( 'vpc_3DSstatus' ) );
			update_post_meta( $order_id, 'migs_response_message', $this->getResponseDescription( $txnResponseCode ) );
			update_post_meta( $order_id, 'migs_response_payment_amount', $this->get_key_from_request( 'vpc_Amount' ) / 100 );
			// Redirect to thank you page
			wp_redirect( $this->get_return_url( $customer_order ) );
			exit;

		} else {
			wc_add_notice( 'Message: ' . $this->getResponseDescription( $txnResponseCode ) . '', 'error' );
			// Add note to the order for your reference
			$customer_order->add_order_note( 'Error: ' . $this->getResponseDescription( $txnResponseCode ) );
			wp_redirect( $this->get_checkout_url() );
			exit;
		}


	}

	/**
	 * Return the default value if value is not exist
	 *
	 * @param $data
	 *
	 * @return string
	 */
	protected function null2unknown( $data ) {
		if ( $data == "" ) {
			return "No Value Returned";
		} else {
			return $data;
		}
	}

	protected function getStatusDescription( $statusResponse ) {
		if ( $statusResponse == "" || $statusResponse == "No Value Returned" ) {
			$result = "3DS not supported or there was no 3DS data provided";
		} else {
			switch ( $statusResponse ) {
				Case "Y" :
					$result = "The cardholder was successfully authenticated.";
					break;
				Case "E" :
					$result = "The cardholder is not enrolled.";
					break;
				Case "N" :
					$result = "The cardholder was not verified.";
					break;
				Case "U" :
					$result = "The cardholder's Issuer was unable to authenticate due to some system error at the Issuer.";
					break;
				Case "F" :
					$result = "There was an error in the format of the request from the merchant.";
					break;
				Case "A" :
					$result = "Authentication of your Merchant ID and Password to the ACS Directory Failed.";
					break;
				Case "D" :
					$result = "Error communicating with the Directory Server.";
					break;
				Case "C" :
					$result = "The card type is not supported for authentication.";
					break;
				Case "S" :
					$result = "The signature on the response received from the Issuer could not be validated.";
					break;
				Case "P" :
					$result = "Error parsing input from Issuer.";
					break;
				Case "I" :
					$result = "Internal Payment Server system error.";
					break;
				default :
					$result = "Unable to be determined";
					break;
			}
		}
		return $result;
	}

	protected function getResponseDescription( $responseCode ) {

		switch ( $responseCode ) {
			case "0" :
				$result = "Transaction Successful";
				break;
			case "?" :
				$result = "Transaction status is unknown";
				break;
			case "1" :
				$result = "Unknown Error";
				break;
			case "2" :
				$result = "Bank Declined Transaction";
				break;
			case "3" :
				$result = "No Reply from Bank";
				break;
			case "4" :
				$result = "Expired Card";
				break;
			case "5" :
				$result = "Insufficient funds";
				break;
			case "6" :
				$result = "Error Communicating with Bank";
				break;
			case "7" :
				$result = "Payment Server System Error";
				break;
			case "8" :
				$result = "Transaction Type Not Supported";
				break;
			case "9" :
				$result = "Bank declined transaction (Do not contact Bank)";
				break;
			case "A" :
				$result = "Transaction Aborted";
				break;
			case "C" :
				$result = "Transaction Cancelled";
				break;
			case "D" :
				$result = "Deferred transaction has been received and is awaiting processing";
				break;
			case "F" :
				$result = "3D Secure Authentication failed";
				break;
			case "I" :
				$result = "Card Security Code verification failed";
				break;
			case "L" :
				$result = "Shopping Transaction Locked (Please try the transaction again later)";
				break;
			case "N" :
				$result = "Cardholder is not enrolled in Authentication scheme";
				break;
			case "P" :
				$result = "Transaction has been received by the Payment Adaptor and is being processed";
				break;
			case "R" :
				$result = "Transaction was not processed - Reached limit of retry attempts allowed";
				break;
			case "S" :
				$result = "Duplicate SessionID (OrderInfo)";
				break;
			case "T" :
				$result = "Address Verification Failed";
				break;
			case "U" :
				$result = "Card Security Code Failed";
				break;
			case "V" :
				$result = "Address Verification and Card Security Code Failed";
				break;
			default :
				$result = "Unable to be determined";
		}
		return $result;
	}

	/**
	 * Get key form request if exist
	 *
	 * @param string $key
	 *
	 * @return string
	 */
	protected function get_key_from_request( $key = '' ) {
		return array_key_exists( $key, $_GET ) ? $_GET[$key] : $this->null2unknown( '' );
	}

	/**
	 * Generate secure hash from url params
	 *
	 * @param  array $params
	 *
	 * @return string
	 */
	protected function migs_generate_secure_hash( $secret, array $params ) {
		$secureHash = "";
		// Sorting params first based on the keys

		foreach ( $params as $key => $value ) {
			// Check if key equals to vpc_SecureHash or vpc_SecureHashType to discard it
			if ( in_array( $key, array( 'vpc_SecureHash', 'vpc_SecureHashType' ) ) || empty( $value ) ) {
				continue;
			}
			// If key either starts with vpc_ or user_
			if ( substr( $key, 0, 4 ) === "vpc_" || substr( $key, 0, 5 ) === "user_" ) {
				$secureHash .= $key . "=" . ($value) . "&";
			}
		}
		// Remove the last `&` character from string
		$secureHash = rtrim( $secureHash, "&" );
		//
		return strtoupper( hash_hmac( 'sha256', $secureHash, pack( 'H*', $secret ) ) );
	}

	/**
	 * Validate response to avoid request tampering
	 *
	 * @param $data
	 *
	 * @return bool
	 */
	protected function migs_validate_response( $data ) {
		$response_secure_hash = $data['vpc_SecureHash'];
		unset( $data['vpc_SecureHash'] );
		unset( $data['vpc_SecureHashType'] );
		foreach ( $data as $key => $value ) {
			// If key either starts with vpc_ or user_
			if ( substr( $key, 0, 4 ) === "vpc_" || substr( $key, 0, 5 ) === "user_" ) {
				$hashData[] = $key . "=" . $value;
			}
		}
		$concatenatedInputs = implode( '&', $hashData );
		$secure_hash        = strtoupper( hash_hmac( 'sha256', $concatenatedInputs, pack( 'H*', $this->merchant_secret_key ) ) );
		// validate response secure hash to make sure no thing alter the reponse
		if ( $response_secure_hash !== $secure_hash ) {
			return false;
		}
		return true;
	}

	/**
	 * Get the checkout URL
	 *
	 * @return false|string
	 */
	protected function get_checkout_url() {
		return get_permalink( get_option( 'woocommerce_checkout_page_id' ) );
	}
}
