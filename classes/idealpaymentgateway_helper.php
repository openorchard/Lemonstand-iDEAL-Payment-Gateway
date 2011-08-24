<?
	class IdealPaymentGateway_Helper {
		protected static $last_response;
		
		public static $acquirer_urls = array(
			'ing' => array('ideal.secure-ing.com/ideal/iDeal','idealtest.secure-ing.com/ideal/iDeal'),
			'abn' => array('internetkassa.abnamro.nl/ncol/prod/orderstandard.asp','internetkassa.abnamro.nl/ncol/prod/orderstandard.asp'),
			'rabo' => array('ideal.rabobank.nl/ideal/iDeal','idealtest.rabobank.nl/ideal/iDeal'),
			'simulator' => array('www.ideal-simulator.nl/professional/','www.ideal-simulator.nl/professional/')
		);
		
		
		public static function directoryRequest($fields = array(), $host_obj) {
			$cache = Core_CacheBase::create();
			$cache_key = 'idealpaymentgateway:DirectoryReq:' . ($host_obj->test_mode?'testing':'live');
			if (!($result = $cache->get($cache_key))) {
				$result = self::doRequest('DirectoryReq', $fields, $host_obj);
				if (!$result->Error) {
					$result = $result->asXml();
					$cache->set($cache_key, $result, 7200);
				} else {
					$result = $result->asXml();
				}
			}
			return simplexml_load_string($result);
		}
		
		public static function transactionRequest($fields = array(), $host_obj) {
			return self::doRequest('AcquirerTrxReq', $fields, $host_obj);
		}
		
		public static function statusRequest($fields = array(), $host_obj) {
			$response = self::doRequest('AcquirerStatusReq', $fields, $host_obj);
			if ($response->Error)
				throw new Phpr_ApplicationException('Error retrieving status request: ' . $response->Error->errorCode);
			
			$certificate = self::get_certificate($host_obj, 'acquirer_certificate');
			if (!$certificate)
				throw new Phpr_ApplicationException('Unable to load acquirer certificate');
			
			$message = $response->createDateTimeStamp . $response->Transaction->transactionID .
				$response->Transaction->status . (string)$response->Transaction->consumerAccountNumber;
			
			if (!self::verify_message($certificate, $message, base64_decode((string)$response->Signature->signatureValue)))
				throw new Phpr_ApplicationException('Unable to securely verify returned status request');
			
			return $response;
		}
		
		public static function doRequest($type, &$fields, $host_obj) {
			$fields = array_merge_recursive(array(
				'createDateTimeStamp' => gmdate('Y-m-d\TH:i:s.000\Z'),
				'Merchant' => array(
					'merchantID' => $host_obj->merchantID,
					'subID' => (int)$host_obj->subID,
					'authentication' => 'SHA1_RSA',
				)
			), $fields);
			$fields['Merchant']['token'] = self::generateToken($host_obj);
			$fields['Merchant']['tokenCode'] = base64_encode(self::generateTokenCode($fields, $host_obj));
			
			
			$data = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"utf-8\" ?><{$type} xmlns=\"http://www.idealdesk.com/Message\" version=\"1.1.0\"></{$type}>");
			$f = create_function('$f,$c,$a',' 
								 foreach($a as $k=>$v) { 
										 if(is_array($v)) { 
												 $ch=$c->addChild($k); 
												 $f($f,$ch,$v); 
										 } else { 
												 $c->addChild($k,$v); 
										 } 
								 }');
			$f($f,$data,$fields);
			$data = $data->asXml();
			
			traceLog( "Request\n\n" . $data . "\n\n\n" );
			
			if ($response = Core_Http::post_data(self::$acquirer_urls[$host_obj->bank_name][($host_obj->test_mode?1:0)], $data)) {
				$response = preg_split('/^\r?$/m', $response, 2);
				$response = trim($response[1]);
				try {
					$xml = simplexml_load_string($response);
				} catch ( Exception $e ) {
					throw new Phpr_ApplicationException('Unable to retreive information from the payment gateway.');
				}
				traceLog( "Response\n\n" . $xml->asXml() . "\n\n\n" );
				return (self::$last_response = $xml);
			}
		}
		
		public static function generateTokenCode($fields, $host_obj) {
			$token_field_order = array(
				'createDateTimeStamp', 'issuerID', 'merchantID', 'subID', 'transactionID',
				'merchantReturnURL', 'purchaseID', 'amount', 'currency', 'language', 'description', 'entranceCode'
			);
			$token_fields = array();
			foreach ($token_field_order as $f) {
				$token_fields[] = find_value($f, $fields);
			}

			$data = preg_replace('/\s+/', '', join('', $token_fields));
			$private_key = self::get_private_key($host_obj);
			if (!$private_key)
				throw new Phpr_ApplicationException('Unable to read private key and thus unable to securely sign transaction.');
			
			$signature = false;
			if (!openssl_sign($data, $signature, $private_key))
				throw new Phpr_ApplicationException('Cound not generate token code to sign transaction.');

			openssl_free_key($private_key);
			return $signature;
		}
		
		public static function generateToken($host_obj) {
			$certificate = self::get_certificate($host_obj);
			if (!$certificate)
				throw new Phpr_ApplicationException('Could not generate token to securely sign transaction.');
				
			$data = base64_decode(str_replace(array('-----BEGIN CERTIFICATE-----','-----END CERTIFICATE-----'), '', $certificate));
			return strtoupper(sha1($data));
		}
		
		public static function getLastResponse() {
			return self::$last_response ? self::$last_response : new SimpleXMLElemnt();
		}
		
		public static function get_private_key($host_obj) {
			$private_key = $host_obj->private_key;
			$is_string = openssl_pkey_get_private($private_key, $host_obj->private_key_passphrase);
			if (!$is_string) {
				if(file_exists($private_key) && is_readable($private_key))
					$private_key = file_get_contents($private_key);
			}
			return openssl_pkey_get_private($private_key, $host_obj->private_key_passphrase);
		}
		
		public static function get_certificate($host_obj, $field = "certificate") {
			$certificate = $host_obj->{$field};
			try {
				$is_string = openssl_x509_read($certificate);
			} catch (Exception $e) {
				// Couldn't read it from the string, try a file next
				$is_string = null;
			}
			
			try {
				if (!$is_string) {
					if (file_exists($certificate) && is_readable($certificate)) {
						$certificate = openssl_x509_read(file_get_contents($certificate));
					}
				}
			} catch (Exception $e) {
				// Couldn't read from the file, bail
				return null;
			}

			$data = null;
			if(!openssl_x509_export($certificate, $data)) {
				return null;
			}
			
			return $data;
		}
		
		public static function verify_message($certificate, $data, $signature) {
			$pubkeyid = openssl_pkey_get_public($certificate);
			$ok = openssl_verify($data, $signature, $pubkeyid);
			openssl_pkey_free($pubkeyid);
			return $ok;
		}
		
		public static function check_statues() {
			$payment_methods = Shop_PaymentMethod::create()->where('class_name = ?', 'IdealPaymentGateway_iDEAL_Payment')->find_all();
			foreach ($payment_methods as $payment_method) {
				$transactions = Shop_PaymentTransaction::create()->find_by_sql("SELECT * FROM (
					SELECT (payment_method_calculated_join.name) as payment_method_calculated, (trim(ifnull((select trim(concat(ifnull(lastName,  ''),  ' ',  ifnull(concat(substring(firstName,  1,  1),  '. '),  ''),  ifnull(concat(substring(middleName,  1,  1),  '.'),  ''))) from users where users.id=shop_payment_transactions.created_user_id),  'system'))) as created_user_name, shop_payment_transactions.* FROM shop_payment_transactions LEFT JOIN shop_payment_methods as payment_method_calculated_join ON payment_method_calculated_join.id = shop_payment_transactions.payment_method_id 
				 WHERE payment_method_id = {$payment_method->id} AND 
					(
						(created_at > (UTC_TIMESTAMP() - INTERVAL 30 MINUTE) AND (user_note IS NULL OR CAST(user_note AS DATETIME) > (UTC_TIMESTAMP() - INTERVAL 60 MINUTE))) OR
						(created_at <= (UTC_TIMESTAMP() - INTERVAL 30 MINUTE) AND (user_note IS NULL OR CAST(user_note AS DATETIME) > (UTC_TIMESTAMP() - INTERVAL 60 SECOND)))
					) ORDER BY created_at DESC) a GROUP BY a.order_id HAVING transaction_status_name = 'Open'");
				foreach ($transactions as $transaction) {
					if ($transaction->transaction_status_name != 'Open')
						continue;
					$order = Shop_Order::create()->find($transaction->order_id);
					$order->payment_method->define_form_fields();
					$payment_method_obj = $order->payment_method->get_paymenttype_object();


					try {
						$response = self::statusRequest(array('Transaction' => array('transactionID' => $transaction->transaction_id)), $order->payment_method);
					
						if ($response->Error)
							continue;
					
						if ('Success' == $response->Transaction->status) {
							if ($order->set_payment_processed())
							{
								Shop_OrderStatusLog::create_record($order->payment_method->order_status, $order);
								$payment_method_obj->_log_payment_attempt($order, 'Successful payment', 1, array(), Phpr::$request->get_fields, $response->asXml());
							}
						} else if ('Open' != $response->Transaction->status) {
							// Open transactions will be retried automatically
							Shop_OrderStatusLog::create_record($order->payment_method->cancelled_order_status, $order);
							$payment_method_obj->update_transaction_status($order->payment_method, $order, $transaction->transaction_id, $response->Transaction->status, substr($response->Transaction->status, 0, 1));
						}
					} catch ( Exception $e ) {
						// Skip errors, and update the transaction
					}
					$transaction->user_note = gmdate('d-m-Y H:i:s');
					$transaction->save();
				}
			}
		}

		public static function render_select($host_obj) {
			$issuers = self::directoryRequest(array(), $host_obj);
			$short_list = $long_list = array();
			foreach ($issuers->Directory->Issuer as $issuer) {
				if ('Short' == (string)$issuer->issuerList) {
					$short_list[(int)$issuer->issuerID] = (string)$issuer->issuerName;
				} else {
					$long_list[(int)$issuer->issuerID] = (string)$issuer->issuerName;
				}
			}
			$str = '<select name="IdealPaymentGateway_issuerID">';
			$str .= '<option value="">Kies uw bank.</option>';
			foreach ($short_list as $id => $name)
				$str .= '<option value="' . $id . '">' . h($name) . '</option>';
			$str .= '<option value="">---Overige banken---</option>';
			foreach ($long_list as $id => $name)
				$str .= '<option value="' . $id . '">' . h($name) . '</option>';
			$str .= '</select>';
			return $str;
		}
	}
	
	if (!function_exists('array_flatten')) {
		function array_flatten($array) { 
			if (!is_array($array)) { 
				return FALSE; 
			} 
			$result = array(); 
			foreach ($array as $key => $value) { 
				if (is_array($value)) { 
					$result = array_merge($result, array_flatten($value)); 
				} 
				else { 
					$result[$key] = $value; 
				} 
			} 
			return $result; 
		}
	}
	
	function find_value($key = null, $arr = array()) {
		if (isset($arr[$key])) {
			return $arr[$key];
		}
		foreach ( $arr as $v ) {
			if (is_array($v)) {
				$val = find_value($key, $v);
				if ( $val !== NULL ) {
					return $val;
				}
			}
		}
		return null;
	}