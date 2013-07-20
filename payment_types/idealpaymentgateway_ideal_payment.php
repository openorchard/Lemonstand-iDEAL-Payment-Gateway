<?

	class IdealPaymentGateway_iDEAL_Payment extends Shop_PaymentType
	{
		/**
		 * Returns information about the payment type
		 * Must return array: array(
		 *		'name'=>'Authorize.net', 
		 *		'custom_payment_form'=>false,
		 *		'offline'=>false,
		 *		'pay_offline_message'=>null
		 * ).
		 * Use custom_paymen_form key to specify a name of a partial to use for building a back-end
		 * payment form. Usually it is needed for forms which ACTION refer outside web services, 
		 * like PayPal Standard. Otherwise override build_payment_form method to build back-end payment
		 * forms.
		 * If the payment type provides a front-end partial (containing the payment form), 
		 * it should be called in following way: payment:name, in lower case, e.g. payment:authorize.net
		 *
		 * Set index 'offline' to true to specify that the payments of this type cannot be processed online 
		 * and thus they have no payment form. You may specify a message to display on the payment page
		 * for offline payment type, using 'pay_offline_message' index.
		 *
		 * @return array
		 */
		public function get_info()
		{
			return array(
				'name'=>'iDEAL',
				'custom_payment_form'=>'backend_payment_form.htm',
				'description'=>'iDEAL payment method'
			);
		}

		/**
		 * Builds the payment type administration user interface 
		 * For drop-down and radio fields you should also add methods returning 
		 * options. For example, of you want to have Sizes drop-down:
		 * public function get_sizes_options();
		 * This method should return array with keys corresponding your option identifiers
		 * and values corresponding its titles.
		 * 
		 * @param $host_obj ActiveRecord object to add fields to
		 * @param string $context Form context. In preview mode its value is 'preview'
		 */
		public function build_config_ui($host_obj, $context = null)
		{
			$host_obj->add_field('old_version', 'Old Version')->tab('Configuration')->renderAs(frm_onoffswitcher)->comment('Set this to "true" if you haven\'t updated your certificates for iDEAL v 3.3.1 and still wish to use the old gateway.  This won\'t be supported past August 2013.', 'above');
			$host_obj->add_field('test_mode', 'Test Mode')->tab('Configuration')->renderAs(frm_onoffswitcher)->comment('Use the iDEAL Test Environment to try out Website Payments. This will use the test URL of the provider selected on the front end.', 'above');
			$host_obj->add_field('bank_name', 'Bank Name')->tab('Configuration')->renderAs(frm_dropdown)->comment('Please select the bank through whom you have the iDEAL service', 'above')->validation()->required('Please select your bank');
			if ($context !== 'preview')
			{
				$host_obj->add_field('merchantID', 'Merchant ID', 'left')->tab('Configuration')->renderAs(frm_text)->comment('Please provide your Merchant ID', 'above')->validation()->fn('trim')->required('Please provide merchant ID.');
				$host_obj->add_field('subID', 'Sub ID', 'right')->tab('Configuration')->renderAs(frm_text)->comment('Please provide your Sub ID, if used', 'above');
				$host_obj->add_field('certificate', 'Certificate')->tab('Configuration')->renderAs(frm_textarea)->comment('Please provide your certificate or the full path to its location', 'above')->validation()->fn('trim')->required('Please provide certificate.');
				$host_obj->add_field('private_key', 'Private Key')->tab('Configuration')->renderAs(frm_textarea)->comment('Please provide your private key or the full path to its location', 'above')->validation()->fn('trim')->required('Please provide private key.');
				$host_obj->add_field('private_key_passphrase', 'Private Key Passphrase')->tab('Configuration')->renderAs(frm_password)->comment('Please provide the passphrase to unlock your private key', 'above')->validation()->fn('trim')->required('Passphrase is required');
				$host_obj->add_field('acquirer_certificate', 'Acquirer Certificate')->tab('Configuration')->renderAs(frm_textarea)->comment('Please provide your acquirer\'s certificate or the full path to its location', 'above')->validation()->fn('trim')->required('Acquirer Certificate is required');
				$host_obj->add_field('order_status', 'Order Status', 'left')->tab('Configuration')->renderAs(frm_dropdown)->comment('Select status to assign the order in case of successful payment.', 'above');
				$host_obj->add_field('cancelled_order_status', 'Cancelled Order Status', 'right')->tab('Configuration')->renderAs(frm_dropdown)->comment('Select status to assign the order in case of unsuccessful payment.', 'above');
			}
			
			$host_obj->add_field('cancel_page', 'Cancel Page', 'full')->tab('Configuration')->renderAs(frm_dropdown)->comment('Page to which the customer’s browser is redirected upon unsuccessful payment.', 'above')->tab('General Parameters')->previewNoRelation()->referenceSort('title');
			
			$host_obj->add_field('ignore_euro_restriction', 'Ignore Euro restriction', 'full')->tab('Configuration')->renderAs(frm_onoffswitcher)->comment('Ignore Euro restriction.', 'above');
		}
		
		public function get_order_status_options($current_key_value = -1)
		{
			if (-1 == $current_key_value)
				return Shop_OrderStatus::create()->order('name')->find_all()->as_array('name', 'id');

			return Shop_OrderStatus::create()->find($current_key_value)->name;
		}
		
		public function get_cancelled_order_status_options($current_key_value = -1) {
			return $this->get_order_status_options($current_key_value);
		}
		
		public function get_bank_name_options($current_key_value = -1)
		{
			$possible = array('ing' => 'ING', 'abn' => 'ABN AMRO', 'rabo' => 'Rabobank', 'simulator' => 'Simulator');
			if (-1 == $current_key_value)
				return $possible;
			return $possible[$current_key_value];
		}

		/**
		 * Validates configuration data before it is saved to database
		 * Use host object field_error method to report about errors in data:
		 * $host_obj->field_error('max_weight', 'Max weight should not be less than Min weight');
		 * @param $host_obj ActiveRecord object containing configuration fields values
		 */
		public function validate_config_on_save($host_obj)
		{
			foreach (array('private_key_passphrase') as $field) {
				if (isset($host_obj->fetched_data[$field]) && $host_obj->fetched_data[$field] && '' == $host_obj->{$field}) {
					unset($host_obj->validation->errorFields[$field]);
					$host_obj->{$field} = $host_obj->fetched_data[$field];
				}
			}
			
			if ('simulator' == $host_obj->bank_name && !$host_obj->test_mode)
				$host_obj->validation->setError('You may only use the simulator when you are in test mode', null, true);
			if (!IdealPaymentGateway_Helper::get_private_key($host_obj))
				$host_obj->field_error('private_key', 'Your private key is not readable or is not in the proper format');
			if (!IdealPaymentGateway_Helper::get_certificate($host_obj))
				$host_obj->field_error('certificate', 'Your certificate is not readable or is not in the proper format');
			if (!$host_obj->ignore_euro_restriction && Shop_CurrencySettings::get()->code != 'EUR')
				$host_obj->validation->setError('iDEAL currently only supports Euro payments.  Ensure your currency settings are correct', null, true);
		}
		
		/**
		 * Validates configuration data after it is loaded from database
		 * Use host object to access fields previously added with build_config_ui method.
		 * You can alter field values if you need
		 * @param $host_obj ActiveRecord object containing configuration fields values
		 */
		public function validate_config_on_load($host_obj)
		{
			// Putting this here because it can't go anywhere else ...
			if (isset($host_obj->fetched_data['private_key_passphrase']) && $host_obj->fetched_data['private_key_passphrase'])
				unset($host_obj->validation->_fields['private_key_passphrase']);
		}

		/**
		 * Initializes configuration data when the payment method is first created
		 * Use host object to access and set fields previously added with build_config_ui method.
		 * @param $host_obj ActiveRecord object containing configuration fields values
		 */
		public function init_config_data($host_obj)
		{
			$host_obj->test_mode = 1;
			$host_obj->old_version = 1;
			$host_obj->ignore_euro_restriction = 1;
		}
		
		public function get_cancel_page_options($current_key_value = -1)
		{
			if ($current_key_value == -1)
				return Cms_Page::create()->order('title')->find_all()->as_array('title', 'id');

			return Cms_Page::create()->find($current_key_value)->title;
		}
		
		public function get_hidden_fields($host_obj, $order, $backend = false)
		{
			$result['order_id'] = $order->id;
			return $result;
		}
		
		/**
		 * Processes payment using passed data
		 * @param array $data Posted payment form data
		 * @param $host_obj ActiveRecord object containing configuration fields values
		 * @param $order Order object
		 */
		public function process_payment_form($data, $host_obj, $order, $back_end = false)
		{
			/*
			 * We do not need any code here since payments are processed by iDEAL server.
			 */
		}

		/**
		 * Registers a hidden page with specific URL. Use this method for cases when you 
		 * need to have a hidden landing page for a specific payment gateway. For example, 
		 * PayPal needs a landing page for the auto-return feature.
		 * Important! Payment module access point names should have the ls_ prefix.
		 * @return array Returns an array containing page URLs and methods to call for each URL:
		 * return array('ls_paypal_autoreturn'=>'process_paypal_autoreturn'). The processing methods must be declared 
		 * in the payment type class. Processing methods must accept one parameter - an array of URL segments 
		 * following the access point. For example, if URL is /ls_paypal_autoreturn/1234 an array with single
		 * value '1234' will be passed to process_paypal_autoreturn method 
		 */
		public function register_access_points()
		{
			return array(
				'ls_ideal_handle_response'=>'process_return',
			);
		}

		public function process_return($params)
		{
			try
			{
				$order = null;
				$response = null;
				$redirect_to_cancel = false;
				
				/*
				 * Find order and load paypal settings
				 */
			
				$order_hash = array_key_exists(0, $params) ? $params[0] : null;
				if (!$order_hash)
					throw new Phpr_ApplicationException('Order not found');

				$order = Shop_Order::create()->find_by_order_hash($order_hash);
				if (!$order)
					throw new Phpr_ApplicationException('Order not found.');

				if (!$order->payment_method)
					throw new Phpr_ApplicationException('Payment method not found.');

				$order->payment_method->define_form_fields();
				$payment_method_obj = $order->payment_method->get_paymenttype_object();
			
				if (!($payment_method_obj instanceof IdealPaymentGateway_iDEAL_Payment))
					throw new Phpr_ApplicationException('Invalid payment method.');

				$is_backend = array_key_exists(1, $params) ? $params[1] == 'backend' : false;

				/*
				 * Send PayPal PDT request
				 */

				if (!$order->payment_processed())
				{
					$transaction_id = Phpr::$request->getField('trxid');
					if (!$transaction_id)
						throw new Phpr_ApplicationException('Invalid transaction value');
					
					try {
						$response = IdealPaymentGateway_Helper::statusRequest(array('Transaction' => array('transactionID' => $transaction_id)), $order->payment_method);
					} catch ( Exception $e ) {
						$response = IdealPaymentGateway_Helper::getLastResponse();
					}
					
					/*
					 * Mark order as paid
					 */
			
					if ($response->Error) {
						$this->log_payment_attempt($order, 'Unsuccessful payment', 0, array(), Phpr::$request->get_fields, $response->asXML());
					}
						
					if ('Success' == $response->Transaction->status) {
						if ($order->set_payment_processed())
						{
							Shop_OrderStatusLog::create_record($order->payment_method->order_status, $order);
							$this->log_payment_attempt($order, 'Successful payment', 1, array(), Phpr::$request->get_fields, $response->asXml());
						}
					} else if ('Open' != $response->Transaction->status) {
						// Open transactions will be retried automatically
						if (!$response->Error) {
							$this->log_payment_attempt($order, 'Unsuccessful payment', 0, array(), Phpr::$request->get_fields, $response->asXML());
						}
						Shop_OrderStatusLog::create_record($order->payment_method->cancelled_order_status, $order);
						$payment_method_obj->update_transaction_status($order->payment_method, $order, $transaction_id, $response->Transaction->status, substr($response->Transaction->status, 0, 1));
						
						$redirect_to_cancel = true;
					}
				}
			
				if (!$is_backend)
				{
					$return_page = $redirect_to_cancel ? $order->payment_method->cancel_page : $order->payment_method->receipt_page;
					$return_page = is_numeric($return_page) ? Cms_Page::create()->find($return_page) : $return_page;
					if ($return_page)
						Phpr::$response->redirect(root_url($return_page->url.'/'.$order->order_hash).'?utm_nooverride=1');
					else 
						throw new Phpr_ApplicationException('iDEAL receipt page is not found.');
				} else 
				{
					Phpr::$response->redirect(url('/shop/orders/payment_accepted/'.$order->id.'?utm_nooverride=1&nocache'.uniqid()));
				}
			}
			catch (Exception $ex)
			{
				if ($order)
					$this->log_payment_attempt($order, $ex->getMessage(), 0, array(), Phpr::$request->get_fields, $response->asXml());

				throw new Phpr_ApplicationException($ex->getMessage());
			}
		}

		public function _log_payment_attempt() {
			call_user_func_array(array($this, 'log_payment_attempt'), func_get_args());
		}

		/**
		 * This function is called before a CMS page deletion.
		 * Use this method to check whether the payment method
		 * references a page. If so, throw Phpr_ApplicationException 
		 * with explanation why the page cannot be deleted.
		 * @param $host_obj ActiveRecord object containing configuration fields values
		 * @param Cms_Page $page Specifies a page to be deleted
		 */
		public function page_deletion_check($host_obj, $page)
		{
			if ($host_obj->cancel_page == $page->id)
				throw new Phpr_ApplicationException('Page cannot be deleted because it is used in iDEAL payment method as a cancel page.');
		}
		
		/**
		 * This function is called before an order status deletion.
		 * Use this method to check whether the payment method
		 * references an order status. If so, throw Phpr_ApplicationException 
		 * with explanation why the status cannot be deleted.
		 * @param $host_obj ActiveRecord object containing configuration fields values
		 * @param Shop_OrderStatus $status Specifies a status to be deleted
		 */
		public function status_deletion_check($host_obj, $status)
		{
			if ($host_obj->order_status == $status->id)
				throw new Phpr_ApplicationException('Status cannot be deleted because it is used in iDEAL payment method.');
		}
	}

?>