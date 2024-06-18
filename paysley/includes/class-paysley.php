<?php

/**
 * Paysley Class
 *
 * @package Paysley
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

require_once dirname(__FILE__) . '/class-paysley-api.php';

/**
 * Paysley class.
 *
 * @extends WC_Payment_Gateway
 */
class Paysley extends WC_Payment_Gateway
{
	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->id = 'paysley';
		// title for backend.
		$this->method_title       = __('Paysley', 'paysley');
		$this->method_description = __('Paysley redirects customers to Paysley to enter their payment information.', 'paysley');
		// title for frontend.
		$this->icon     = WP_PLUGIN_URL . '/' . plugin_basename(dirname(dirname(__FILE__))) . '/assets/img/py-logo.png';
		$this->supports = array('refunds');
		$this->has_fields = false;

		$this->supports = array(
			'products'
		);

		// setup backend configuration.
		$this->init_form_fields();
		$this->init_settings();



		$this->title          = $this->get_option('title');
		$this->description    = $this->get_option('description');
		$this->payment_type   = 'DB';
		$this->access_key     = $this->get_option('access_key');
		$this->enable_logging = 'yes' === $this->get_option('enable_logging');
		$this->is_test_mode   = 'py_live' !== substr($this->access_key, 0, 7);
		$this->init_api();


		// save woocomerce settings checkout tab section paysley.
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		// validate form fields when saved.
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'validate_admin_options'));
		// use hook to receive response url.
		add_action('woocommerce_before_thankyou', array($this, 'response_page'));
		// use hook to do full refund.
		add_action('woocommerce_order_edit_status', array($this, 'process_full_refund'), 10, 2);
		// use hook to add notes when payment amount greater than order amount.
		add_action('woocommerce_order_status_changed', array($this, 'add_full_refund_notes'), 10, 3);
	}


	/**
	 * Override function.
	 * Initialise settings form fields for paysley
	 * Add an array of fields to be displayed on the paysley settings screen.
	 */
	public function init_form_fields()
	{
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __('Enable/Disable', 'paysley'),
				'label'   => __('Enable Paysley', 'paysley'),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __('Title', 'paysley'),
				'type'        => 'text',
				'description' => __('This is the title which the user sees during checkout.', 'paysley'),
				'default'     => __('Paysley', 'paysley'),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'       => __('Description', 'paysley'),
				'type'        => 'text',
				'description' => __('This is the description which the user sees during checkout.', 'paysley'),
				'default'     => 'Pay with Paysley',
				'desc_tip'    => true,
			),
			'access_key'     => array(
				'title'       => __('Access Key', 'paysley'),
				'type'        => 'password',
				'description' => __('* This is the access key, received from Paysley developer portal. ( required )', 'paysley'),
				'default'     => '',
			),
			'enable_logging' => array(
				'title'   => __('Enable Logging', 'paysley'),
				'type'    => 'checkbox',
				'label'   => __('Enable transaction logging for paysley.', 'paysley'),
				'default' => 'no',
			),
		);
	}

	/**
	 * Show error notice if access key is empty.
	 */
	public function validate_admin_options()
	{
		$post_data  = $this->get_post_data();
		$access_key = $this->get_field_value('access_key', $this->form_fields, $post_data);
		if (empty($access_key)) {
			WC_Admin_Settings::add_error(__('Please enter an access key!', 'paysley'));
		}
	}

	/**
	 * Override function.
	 * Disable if access key is empty.
	 *
	 * @return bool
	 */
	public function is_available()
	{
		$is_available = parent::is_available();
		if (empty($this->access_key)) {
			$is_available = false;
		}
		return $is_available;
	}

	/**
	 * Get order property with compatibility check on order getter introduced
	 * in WC 3.0.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $prop  Property name.
	 *
	 * @return mixed Property value
	 */
	public static function get_order_prop($order, $prop)
	{
		switch ($prop) {
			case 'order_total':
				$getter = array($order, 'get_total');
				break;
			default:
				$getter = array($order, 'get_' . $prop);
				break;
		}

		return is_callable($getter) ? call_user_func($getter) : $order->{$prop};
	}

	/**
	 * Log system processes.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Log message.
	 * @param string $level Log level.
	 */
	public function log($message, $level = 'info')
	{
		if ($this->enable_logging) {
			if (empty($this->logger)) {
				$this->logger = new WC_Logger();
			}
			$this->logger->add('paysley-' . $level, $message);
		}
	}

	/**
	 * Init the API class and set the access key.
	 */
	protected function init_api()
	{
		Paysley_API::$access_key   = $this->access_key;
		Paysley_API::$is_test_mode = $this->is_test_mode;
	}

	/**
	 * Get payment url.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $transaction_id Transaction ID.
	 *
	 * @return string
	 * @throws \Exception Error.
	 */
	protected function get_payment_url($order_id, $transaction_id)
	{
		$order      = new WC_Order($order_id);
		$currency   = $order->get_currency();
		$amount     = $this->get_order_prop($order, 'order_total');
		$token      = $this->generate_token($order_id, $currency);
		$return_url = $this->get_return_url($order);

		$countryCodePhone = $this->getCountryCode($order->get_billing_country());
		$customerPhoneNumber = $order->get_billing_phone();

		if($countryCodePhone && strpos($customerPhoneNumber,$countryCodePhone) !== 0 && strlen($customerPhoneNumber) <= 10 ){
			$customerPhoneNumber = $countryCodePhone.$customerPhoneNumber;
		}
		$shippingCharges =  $order->get_shipping_total() +  $order->get_shipping_tax();
		$body = array(
			'reference_number' => $transaction_id,
			'payment_type' => $this->payment_type,
			'request_methods' => ["WEB"],
			'email' =>  $order->get_billing_email(),
			'mobile_number' => $customerPhoneNumber,
			'customer_first_name' => $order->get_billing_first_name(),
			'customer_last_name' => $order->get_billing_last_name(),
			'currency'     => $currency,
			'amount'       => (float) $amount,
			'shipping_charges' => $order->get_shipping_total(),
			'cart_items'   => $this->get_cart_items($order_id),
			'fixed_amount' => true,
			'cancel_url'   => wc_get_checkout_url(),
			'redirect_url' => $return_url,
			'response_url' => $return_url . '&py_token=' . $token,
		);

		$customer_paysley_id = self::updateCustomerOnPaysley($order);
		if ($customer_paysley_id) {
			// $body['customer_id'] =  $customer_paysley_id;
		}

		$log_body                 = $body;
		$log_body['response_url'] = $return_url . '&py_token=*****';
		$this->log('get_payment_url - body: ' . wp_json_encode($log_body));

		$results = Paysley_API::generate_pos_link($body);
		$this->log('get_payment_url - results: ' . wp_json_encode($results));

		if (200 === $results['response']['code'] && 'success' === $results['body']['result']) {
			return $results['body']['long_url'];
		}

		if (422 === $results['response']['code'] && 'currency' === $results['body']['error_field']) {
			throw new Exception(__('We are sorry, currency is not supported. Please contact us.', 'paysley'), 1);
		}

		if (isset($results['body']['error_message'])) {
			throw new Exception(__('Error while Processing Request: ' . $results['body']['error_message'], 'paysley'), 1);
		}
		if (isset($results['body']['message'])) {
			throw new Exception(__('Error while Processing Request: ' . $results['body']['message'], 'paysley'), 1);
		}

		throw new Exception(__('Error while Processing Request: please try again.', 'paysley'), 1);
	}

	/**
	 * Get cart items.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function get_cart_items($order_id)
	{
		$cart_items = array();
		$order      =  new WC_Order($order_id);
		foreach ($order->get_items() as $item_id => $item) {
			$product = $item->get_product();
			$price = $product->get_price();
			$tax_rates = WC_Tax::get_rates( $product->get_tax_class() );
			$taxes = WC_Tax::calc_tax( $price, $tax_rates, false );
			$total_tax = array_sum( $taxes );
			$sku = $product->get_sku();
			if (!$sku) {$sku = '-';}
			$item_total  = isset($item['recurring_line_total']) ? $item['recurring_line_total'] : $order->get_item_total($item);
			$paysley_product_id = get_post_meta($item['product_id'], 'paysley_product_id', true);
			if (!$paysley_product_id) {
				self::updateProductOnPaysley($item['product_id']);
				$paysley_product_id = get_post_meta($item['product_id'], 'paysley_product_id', true);
			}
			
			$cart_items[] = array(
				'sku'		         => $sku,
				'name' 		         => $item->get_name(),
				'qty'  		         => $item->get_quantity(),
				'sales_price'        => $item_total,
				'unit'               => 'pc',
				'product_service_id' => $paysley_product_id,
				"taxable"            => $item->get_tax_status() === "taxable" ? 1: 0,
				"tax_value"          => $item->get_tax_status() === "taxable" && !empty($total_tax)? $total_tax : 0,
				"tax_type"           => "fixed_amount"
			);
		}
		return $cart_items;
	}


	/**
	 * Override function.
	 *
	 * Send data to the API to get the payment url.
	 * Redirect user to the payment url.
	 * This should return the success and redirect in an array. e.g:
	 *
	 *        return array(
	 *            'result'   => 'success',
	 *            'redirect' => $this->get_return_url( $order )
	 *        );
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment($order_id)
	{
		global $woocommerce;
		$order          =  new WC_Order($order_id);
		$transaction_id = 'wc-' . $order->get_order_number();
		$secret_key     = wc_rand_hash();
		// * save transaction_id and secret_key first before call get_payment_url function.
		$order->update_meta_data('_paysley_transaction_id', $transaction_id);
		$order->update_meta_data('_paysley_secret_key', $secret_key);
		$payment_url = $this->get_payment_url($order_id, $transaction_id);
		return array(
			'result'   => 'success',
			'redirect' => $payment_url,
		);
	}

	/**
	 * Process partial refund.
	 *
	 * If the gateway declares 'refunds' support, this will allow it to refund.
	 * a passed in amount.
	 *
	 * @param  int    $order_id Order ID.
	 * @param  float  $amount Refund amount.
	 * @param  string $reason Refund reason.
	 * @return boolean True or false based on success, or a WP_Error object.
	 */
	public function process_refund($order_id, $amount = null, $reason = '')
	{
		$order =  new WC_Order($order_id);
		if ($order && 'paysley' === $order->get_payment_method()) {
			$payment_id = $order->get_meta('_paysley_payment_id', true);
			$body       = array(
				'email'  => $order->get_billing_email(),
				'amount' => (float) $amount,
			);
			$this->log('process_refund - request body ' . wp_json_encode($body));
			$results = Paysley_API::do_refund($payment_id, $body);
			$this->log('process_refund - results: ' . wp_json_encode($results));

			if (200 === $results['response']['code'] && 'refund' === $results['body']['status']) {
				$order->add_order_note(__('Paysley partial refund successfull.'));
				$this->log('process_refund: Success');
				return true;
			}
			$this->log('process_refund: Failed');
			return new WP_Error($results['response']['code'], __('Refund Failed', 'paysley') . ': ' . $results['body']['message']);
		}
	}

	/**
	 * Process full refund when order status change from processing / completed to refunded.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $status_to change status to.
	 */
	public function process_full_refund($order_id, $status_to)
	{
		// $order = wc_get_order( $order_id );
		$order =  new WC_Order($order_id);
		if ($order && 'paysley' === $order->get_payment_method()) {
			$status_from = $order->get_status();

			if (('processing' === $status_from || 'completed' === $status_from) && 'refunded' === $status_to) {
				$amount     = (float) $this->get_order_prop($order, 'order_total');
				$payment_id = $order->get_meta('_paysley_payment_id', true);
				$body       = array(
					'email'  => $order->get_billing_email(),
					'amount' => $amount,
				);
				$this->log('process_full_refund - request body ' . wp_json_encode($body));
				$results = Paysley_API::do_refund($payment_id, $body);
				$this->log('process_full_refund - do_refund results: ' . wp_json_encode($results));

				if (200 === $results['response']['code'] && 'refund' === $results['body']['status']) {
					$this->restock_refunded_items($order);
					$order->add_order_note(__('Paysley full refund successfull.'));
					$this->log('process_full_refund: Success');
				} else {
					$this->log('process_full_refund: Failed');
					$redirect = get_admin_url() . 'post.php?post=' . $order_id . '&action=edit';
					WC_Admin_Meta_Boxes::add_error(__('Refund Failed', 'paysley') . ':' . $results['body']['message']);
					wp_safe_redirect($redirect);
					exit;
				}
			}
		}
	}

	/**
	 * Add notes if payment amount greater than order amount when order status change from processing / completed to refunded.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $status_from change status from.
	 * @param string $status_to change status to.
	 */
	public function add_full_refund_notes($order_id, $status_from, $status_to)
	{
		$order =  new WC_Order($order_id);
		if ($order && 'paysley' === $order->get_payment_method()) {
			if (('processing' === $status_from || 'completed' === $status_from) && 'refunded' === $status_to) {
				$order_amount = (float) $this->get_order_prop($order, 'order_total');
				// $payment_id   = get_post_meta( $order->get_id(), '_paysley_payment_id', true );
				$payment_id = $order->get_meta('_paysley_payment_id', true);
				$results      = Paysley_API::get_payment($payment_id);
				$this->log('add_full_refund_notes - get_payment results: ' . wp_json_encode($results));
				if (200 === $results['response']['code']) {
					$payment_amount = (float) $results['body']['payment']['amount'];
					if ($payment_amount > $order_amount) {
						$order->add_order_note(__('Paysley notes: You still have amount to be refunded, because Merchant use tax/tip when customer paid. Please contact the merchant to refund the tax/tip amount.'));
					}
				}
			}
		}
	}

	/**
	 * Increase stock for refunded items.
	 *
	 * @param obj $order Order.
	 */
	public function restock_refunded_items($order)
	{
		$refunded_line_items = array();
		$line_items          = $order->get_items();

		foreach ($line_items as $item_id => $item) {
			$refunded_line_items[$item_id]['qty'] = $item->get_quantity();
		}
		wc_restock_refunded_items($order, $refunded_line_items);
	}

	/**
	 * Use this generated token to secure get payment status.
	 * Before call this function make sure _paysley_transaction_id and _paysley_secret_key already saved.
	 *
	 * @param int    $order_id - Order Id.
	 * @param string $currency - Currency.
	 *
	 * @return string
	 */
	protected function generate_token($order_id, $currency)
	{
		$order =  new WC_Order($order_id);
		$transaction_id = $order->get_meta('_paysley_transaction_id', true);
		$secret_key = $order->get_meta('_paysley_secret_key', true);

		return md5((string) $order_id . $currency . $transaction_id . $secret_key);
	}

	/**
	 * Page to handle response from the gateway.
	 * Get payment status and update order status.
	 *
	 * @param int $order_id - Order Id.
	 */
	public function response_page($order_id)
	{
		$token = get_query_var('py_token');

		if (!empty($token)) {
			$this->log('get response from the gateway reponse url');
			$response = get_query_var('response');
			$this->log('response_page - original response: ' . $response);
			$response = json_decode(wp_unslash($response), true);
			$this->log('response_page - formated response: ' . wp_json_encode($response));

			$payment_status = '';
			$payment_id     = '';
			$currency       = '';

			if (isset($response['status'])) {
				$payment_status = $response['status'];
			} elseif (isset($response['result'])) {
				$payment_status = $response['result'];
			}

			if (isset($response['payment_id'])) {
				$payment_id = $response['payment_id'];
			} elseif (isset($response['response']) && isset($response['response']['id'])) {
				$payment_id = $response['response']['id'];
			}

			if (isset($response['currency'])) {
				$currency = $response['currency'];
			} elseif (isset($response['response']) && isset($response['response']['currency'])) {
				$currency = $response['response']['currency'];
			}

			$generated_token = $this->generate_token($order_id, $currency);
			$order           =  new WC_Order($order_id);

			if ($order && 'paysley' === $order->get_payment_method()) {
				if ($token === $generated_token) {
					if ('ACK' === $payment_status) {

						$order_status = 'completed';

						foreach ($order->get_items() as $order_item) {

							$item = wc_get_product($order_item->get_product_id());

							if (!$item->is_virtual()) {
								$order_status = 'processing';;
							}
						}

						$this->log('response_page: update order status to ' . $order_status);

						$order_notes  = 'Paysley payment successfull:';
						$order->update_meta_data('_paysley_payment_id', $payment_id);
						$order->update_meta_data('_paysley_payment_result', 'succes');

						$order->update_status($order_status, $order_notes);
					} else {
						$this->log('response_page: update order status to failed');
						$order_status = 'failed';
						$order_notes  = 'Paysley payment failed:';
						$order->update_status($order_status, $order_notes);
						$order->update_meta_data('_paysley_payment_result', 'failed');
					}
				} else {
					$this->log('response_page: FRAUD detected, token is not same with the generated token');
				}
			}
		} else {
			$this->log('response_page: go to thank you page');
		}
	}

	/**
	 * Create/Update product on paysley
	 */
	public static function updateProductOnPaysley($product_id)
	{
		//Product Details
		$product = wc_get_product($product_id);
		//Product thumbnail
		$productImage = wp_get_attachment_image_src(get_post_thumbnail_id($product_id), 'single-post-thumbnail');
		// CategoryId of product
		$productCategory = self::checkAndCreateProductCategory($product_id);
		// Product data for paysley
		$data = [];
		$data['name'] = $product->name;
		$data['description'] = $product->description;
		$data['sku'] = $product->sku;
		$data['category_id'] = $productCategory;
		$data['type'] = 'product';
		$data['manage_inventory'] = $product->manage_stock;
		$data['unit_in_stock'] = $product->stock_quantity;
		$data['unit_low_stock'] = $product->low_stock_amount;
		$data['unit_type'] = 'flat-rate';
		$data['cost'] = $product->regular_price ? $product->regular_price : $product->price;
		$data['sales_price'] = $product->price;
		$data['image'] = $productImage ? $productImage[0] : null;
		//check if paysley_product_id is already exist
		$paysley_product_id = get_post_meta($product_id, 'paysley_product_id', true);
		//if paysley_product_id is already exist then it will update product on paysley else it will create new product on paysley.
		if ($paysley_product_id) {
			$data['id'] = $paysley_product_id;
			// (new self)->log('Updating Product on Payslay: '. wp_json_encode($data));
			$productResult = Paysley_API::update_product($data);
			//(new self)->log('Update Product Response: '. wp_json_encode($productResult));
		} else {
			// (new self)->log('Creating Product on Payslay: '. wp_json_encode($data));
			$productResult = Paysley_API::create_product($data);
			if (200 === $productResult['response']['code'] && 'success' === $productResult['body']['result']) {
				add_post_meta($product_id, 'paysley_product_id', $productResult['body']['id']);
			}
			// (new self)->log('Create Product Response: '. wp_json_encode($productResult));
		}
	}

	/**
	 * Check if category created on paysley.If category is not created then it will create category on paysley.
	 * It will return CategoryId 
	 */
	public static function checkAndCreateProductCategory($product_id)
	{
		$productCategory = wp_get_post_terms($product_id, 'product_cat');
		if (count($productCategory)) {
			$categoryResult = Paysley_API::category_list($productCategory[0]->name);
			if (200 === $categoryResult['response']['code'] && 'success' === $categoryResult['body']['result']) {
				if (count($categoryResult['body']['categories'])) {
					return $categoryResult['body']['categories'][0]['id'];
				}
				$categoryCreateResult = Paysley_API::create_category(['name' => $productCategory[0]->name]);
				if (200 === $categoryCreateResult['response']['code']) {
					return $categoryCreateResult['body']['id'];
				}
			}
		} else {
			$noCategory = 'No Category';
			$categoryResult = Paysley_API::category_list($noCategory);
			if (200 === $categoryResult['response']['code'] && 'success' === $categoryResult['body']['result']) {
				if (count($categoryResult['body']['categories'])) {
					return $categoryResult['body']['categories'][0]['id'];
				}
				$categoryCreateResult = Paysley_API::create_category(['name' => $noCategory]);
				if (200 === $categoryCreateResult['response']['code']) {
					return $categoryCreateResult['body']['id'];
				}
			}
		}
	}

	/**
	 * Create/Update Customer on paysley
	 */
	public static function updateCustomerOnPaysley($order)
	{
		$customerPaysleyId = null;
		$checkIfCustomerExistOnPaysleyResult = Paysley_API::customers($order->get_billing_email());
		if (200 === $checkIfCustomerExistOnPaysleyResult['response']['code'] && 'success' === $checkIfCustomerExistOnPaysleyResult['body']['result']) {
			$countryCodePhone = (new self)->getCountryCode($order->get_billing_country());
			$customerPhoneNumber = $order->get_billing_phone();
			if($countryCodePhone && strpos($customerPhoneNumber,$countryCodePhone) !== 0 && strlen($customerPhoneNumber) <= 10 ){
				$customerPhoneNumber = $countryCodePhone.$customerPhoneNumber;
			}
			$customerDataToUpdate = [];
			// Customer billing information details
			$customerDataToUpdate['email'] = $order->get_billing_email();
			// $customerDataToUpdate['mobile_no'] = $order->get_billing_phone();
			$customerDataToUpdate['mobile_no'] = $customerPhoneNumber;
			$customerDataToUpdate['first_name'] = $order->get_billing_first_name();
			$customerDataToUpdate['last_name'] = $order->get_billing_last_name();
			$customerDataToUpdate['company_name'] = $order->get_billing_company();
			$customerDataToUpdate['listing_type'] = 'individual';
			$customerDataToUpdate['address_line1'] = $order->get_billing_address_1();
			$customerDataToUpdate['address_line2'] = $order->get_billing_address_2();
			$customerDataToUpdate['city'] = $order->get_billing_city();
			$customerDataToUpdate['state'] = $order->get_billing_state();
			$customerDataToUpdate['postal_code'] = $order->get_billing_postcode();
			$customerDataToUpdate['country_iso'] = $order->get_billing_country();

			if (count($checkIfCustomerExistOnPaysleyResult['body']['customers'])) {
				$customer = $checkIfCustomerExistOnPaysleyResult['body']['customers'][0];
				$customerDataToUpdate['customer_id'] = $customerPaysleyId = $customer['customer_id'];
				$updateCustomerOnPaysleyResult = Paysley_API::update_customer($customerDataToUpdate);
				if (200 === $updateCustomerOnPaysleyResult['response']['code'] && 'success' === $updateCustomerOnPaysleyResult['body']['result']) {
				}
			} else {
				$createCustomerOnPaysleyResult = Paysley_API::create_customer($customerDataToUpdate);
				if (200 === $createCustomerOnPaysleyResult['response']['code'] && 'success' === $createCustomerOnPaysleyResult['body']['result']) {
					$customerPaysleyId = $createCustomerOnPaysleyResult['body']['customer_id'];
				}
			}
		}
		return $customerPaysleyId;
	}

	public function getCountryCode($countryCode)
	{
		$country_phone_codes = array(
			'AF' => '+93',
			'AL' => '+355',
			'DZ' => '+213',
			'AS' => '+1-684',
			'AD' => '+376',
			'AO' => '+244',
			'AI' => '+1-264',
			'AQ' => '+672',
			'AG' => '+1-268',
			'AR' => '+54',
			'AM' => '+374',
			'AW' => '+297',
			'AU' => '+61',
			'AT' => '+43',
			'AZ' => '+994',
			'BS' => '+1-242',
			'BH' => '+973',
			'BD' => '+880',
			'BB' => '+1-246',
			'BY' => '+375',
			'BE' => '+32',
			'BZ' => '+501',
			'BJ' => '+229',
			'BM' => '+1-441',
			'BT' => '+975',
			'BO' => '+591',
			'BA' => '+387',
			'BW' => '+267',
			'BR' => '+55',
			'IO' => '+246',
			'VG' => '+1-284',
			'BN' => '+673',
			'BG' => '+359',
			'BF' => '+226',
			'BI' => '+257',
			'KH' => '+855',
			'CM' => '+237',
			'CA' => '+1',
			'CV' => '+238',
			'KY' => '+1-345',
			'CF' => '+236',
			'TD' => '+235',
			'CL' => '+56',
			'CN' => '+86',
			'CX' => '+61',
			'CC' => '+61',
			'CO' => '+57',
			'KM' => '+269',
			'CK' => '+682',
			'CR' => '+506',
			'HR' => '+385',
			'CU' => '+53',
			'CW' => '+599',
			'CY' => '+357',
			'CZ' => '+420',
			'CD' => '+243',
			'DK' => '+45',
			'DJ' => '+253',
			'DM' => '+1-767',
			'DO' => '+1-809',
			'TL' => '+670',
			'EC' => '+593',
			'EG' => '+20',
			'SV' => '+503',
			'GQ' => '+240',
			'ER' => '+291',
			'EE' => '+372',
			'ET' => '+251',
			'FK' => '+500',
			'FO' => '+298',
			'FJ' => '+679',
			'FI' => '+358',
			'FR' => '+33',
			'PF' => '+689',
			'GA' => '+241',
			'GM' => '+220',
			'GE' => '+995',
			'DE' => '+49',
			'GH' => '+233',
			'GI' => '+350',
			'GR' => '+30',
			'GL' => '+299',
			'GD' => '+1-473',
			'GU' => '+1-671',
			'GT' => '+502',
			'GG' => '+44-1481',
			'GN' => '+224',
			'GW' => '+245',
			'GY' => '+592',
			'HT' => '+509',
			'HN' => '+504',
			'HK' => '+852',
			'HU' => '+36',
			'IS' => '+354',
			'IN' => '+91',
			'ID' => '+62',
			'IR' => '+98',
			'IQ' => '+964',
			'IE' => '+353',
			'IM' => '+44-1624',
			'IL' => '+972',
			'IT' => '+39',
			'CI' => '+225',
			'JM' => '+1-876',
			'JP' => '+81',
			'JE' => '+44-1534',
			'JO' => '+962',
			'KZ' => '+7',
			'KE' => '+254',
			'KI' => '+686',
			'XK' => '+383',
			'KW' => '+965',
			'KG' => '+996',
			'LA' => '+856',
			'LV' => '+371',
			'LB' => '+961',
			'LS' => '+266',
			'LR' => '+231',
			'LY' => '+218',
			'LI' => '+423',
			'LT' => '+370',
			'LU' => '+352',
			'MO' => '+853',
			'MK' => '+389',
			'MG' => '+261',
			'MW' => '+265',
			'MY' => '+60',
			'MV' => '+960',
			'ML' => '+223',
			'MT' => '+356',
			'MH' => '+692',
			'MR' => '+222',
			'MU' => '+230',
			'YT' => '+262',
			'MX' => '+52',
			'FM' => '+691',
			'MD' => '+373',
			'MC' => '+377',
			'MN' => '+976',
			'ME' => '+382',
			'MS' => '+1-664',
			'MA' => '+212',
			'MZ' => '+258',
			'MM' => '+95',
			'NA' => '+264',
			'NR' => '+674',
			'NP' => '+977',
			'NL' => '+31',
			'AN' => '+599',
			'NC' => '+687',
			'NZ' => '+64',
			'NI' => '+505',
			'NE' => '+227',
			'NG' => '+234',
			'NU' => '+683',
			'KP' => '+850',
			'MP' => '+1-670',
			'NO' => '+47',
			'OM' => '+968',
			'PK' => '+92',
			'PW' => '+680',
			'PS' => '+970',
			'PA' => '+507',
			'PG' => '+675',
			'PY' => '+595',
			'PE' => '+51',
			'PH' => '+63',
			'PN' => '+64',
			'PL' => '+48',
			'PT' => '+351',
			'PR' => '+1-787',
			'QA' => '+974',
			'CG' => '+242',
			'RE' => '+262',
			'RO' => '+40',
			'RU' => '+7',
			'RW' => '+250',
			'BL' => '+590',
			'SH' => '+290',
			'KN' => '+1-869',
			'LC' => '+1-758',
			'MF' => '+590',
			'PM' => '+508',
			'VC' => '+1-784',
			'WS' => '+685',
			'SM' => '+378',
			'ST' => '+239',
			'SA' => '+966',
			'SN' => '+221',
			'RS' => '+381',
			'SC' => '+248',
			'SL' => '+232',
			'SG' => '+65',
			'SX' => '+1-721',
			'SK' => '+421',
			'SI' => '+386',
			'SB' => '+677',
			'SO' => '+252',
			'ZA' => '+27',
			'KR' => '+82',
			'SS' => '+211',
			'ES' => '+34',
			'LK' => '+94',
			'SD' => '+249',
			'SR' => '+597',
			'SJ' => '+47',
			'SZ' => '+268',
			'SE' => '+46',
			'CH' => '+41',
			'SY' => '+963',
			'TW' => '+886',
			'TJ' => '+992',
			'TZ' => '+255',
			'TH' => '+66',
			'TG' => '+228',
			'TK' => '+690',
			'TO' => '+676',
			'TT' => '+1-868',
			'TN' => '+216',
			'TR' => '+90',
			'TM' => '+993',
			'TC' => '+1-649',
			'TV' => '+688',
			'VI' => '+1-340',
			'UG' => '+256',
			'UA' => '+380',
			'AE' => '+971',
			'GB' => '+44',
			'US' => '+1',
			'UY' => '+598',
			'UZ' => '+998',
			'VU' => '+678',
			'VA' => '+379',
			'VE' => '+58',
			'VN' => '+84',
			'WF' => '+681',
			'EH' => '+212',
			'YE' => '+967',
			'ZM' => '+260',
			'ZW' => '+263',
		);

		return isset($country_phone_codes[$countryCode]) ? $country_phone_codes[$countryCode] : null;

	}

	public function getProductTax($product){
		$totalTax = 0;
		if($product->get_tax_status() === "taxable"){
			$productTaxes = $product->get_taxes()['total'];
			foreach($productTaxes as $productTax){
				$totalTax += floatval($productTax);
			}
		}
		return $totalTax;
	}
}
