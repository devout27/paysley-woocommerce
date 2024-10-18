<?php

/**
 * Paysley API Class
 *
 * @package Paysley
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Handles POS Link, Refunds and other API requests.
 *
 * @since 1.0.0
 */
class Paysley_API
{

	/**
	 * API Access Key
	 *
	 * @var string
	 */
	public static $access_key;

	/**
	 * Is use test server or not
	 *
	 * @var bool
	 */
	// public static $is_test_mode = true;
	public static $is_test_mode = false;

	/**
	 * API live url
	 *
	 * @var string
	 */
	public static $api_live_url = 'https://live.paysley.io/v2';

	/**
	 * API test url
	 *
	 * @var string
	 */
	public static $api_test_url = 'https://test.paysley.io/v2';

	/**
	 * Get API url
	 *
	 * @return string
	 */
	public static function get_api_url()
	{
		if (self::$is_test_mode) {
			return self::$api_test_url;
		}
		return self::$api_live_url;
	}

	/**
	 * Send request to the API
	 *
	 * @param string $url Url.
	 * @param array  $body Body.
	 * @param string $method Method.
	 *
	 * @return array
	 */
	public static function send_request($url, $body = '', $method = 'GET')
	{
		$api_args['headers'] = array('Authorization' => 'Bearer ' . self::$access_key);
		if ('POST' === $method || 'PUT' === $method) {
			$api_args['headers']['Content-Type'] = 'Application/json';
			$body                                = wp_json_encode($body);
		}
		$api_args['method']  = strtoupper($method);
		$api_args['body']    = $body;
		$api_args['timeout'] = 70;

		$results = wp_remote_request($url, $api_args);
		if (is_string($results['body'])) {
			$results['body'] = json_decode($results['body'], true);
		}

		return $results;
	}

	/**
	 * Get pos link url with the API.
	 *
	 * @param array $body Body.
	 *
	 * @return array
	 */
	public static function generate_pos_link($body)
	{
		$url = self::get_api_url() . '/payment-requests/';
		return self::send_request($url, $body, 'POST');
	}

	/**
	 * Get payment detail with the API.
	 *
	 * @param string $payment_id Payment ID.
	 *
	 * @return array
	 */
	public static function get_payment($payment_id)
	{
		$url = self::get_api_url() . '/payments/' . $payment_id;
		return self::send_request($url);
	}

	/**
	 * Do refund with the API.
	 *
	 * @param string $payment_id Payment ID.
	 * @param array  $body Body.
	 *
	 * @return array
	 */
	public static function do_refund($payment_id, $body)
	{
		$url = self::get_api_url() . '/refunds/' . $payment_id;
		return self::send_request($url, $body, 'POST');
	}


	/**
	 * Create new category.
	 */
	public static function create_category($body)
	{
		$url = self::get_api_url() . '/products-services/category';
		return self::send_request($url, $body, 'POST');
	}

	/**
	 * Get list of categories.
	 *
	 * @return array
	 */
	public static function category_list($category_name = null)
	{
		$url = self::get_api_url() . '/products-services/category/';
		if ($category_name)
			$url .= "?keywords=$category_name";
		return self::send_request($url);
	}

	/**
	 * Create new Product.
	 */
	public static function create_product($body)
	{
		$url = self::get_api_url() . '/products-services';
		return self::send_request($url, $body, 'POST');
	}

	/**
	 * Create new Product.
	 */
	public static function update_product($body)
	{
		$url = self::get_api_url() . '/products-services';
		return self::send_request($url, $body, 'PUT');
	}

	/**
	 * Customer List.
	 */
	public static function customers($searchKeyword = null)
	{
		$url = self::get_api_url() . '/customers';
		if ($searchKeyword)
			$url .= "?keywords=$searchKeyword";
		return self::send_request($url);
	}

	/**
	 * Create new Customer.
	 */
	public static function create_customer($body)
	{
		$url = self::get_api_url() . '/customers';
		return self::send_request($url, $body, 'POST');
	}

	/**
	 * Update Customer.
	 */
	public static function update_customer($body)
	{
		$url = self::get_api_url() . '/customers';
		return self::send_request($url, $body, 'PUT');
	}
}
