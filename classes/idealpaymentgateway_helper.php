<?
	if (!class_exists('XMLSecurityKey')) {
		require_once dirname(__FILE__) . '/../vendor/xmlseclibs.php';
	}
	
	class IdealPaymentGateway_Helper {
		protected static $last_response;
		
		public static $acquirer_urls = array(
			'ing' => array('ideal.secure-ing.com/ideal/iDeal','idealtest.secure-ing.com/ideal/iDeal'),
			'abn' => array('internetkassa.abnamro.nl/ncol/prod/orderstandard.asp','internetkassa.abnamro.nl/ncol/test/orderstandard.asp'),
			'rabo' => array('ideal.rabobank.nl/ideal/iDeal','idealtest.rabobank.nl/ideal/iDeal'),
			'simulator' => array('www.ideal-simulator.nl/professional/','www.ideal-simulator.nl/professional/')
		);
		
		public static $v3acquirer_urls = array(
			'ing' => array('ideal.secure-ing.com/ideal/iDEALv3', 'idealtest.secure-ing.com/ideal/iDEALv3'),
			'abn' => array('abnamro.ideal-payment.de/ideal/iDEALv3', 'abnamro-test.ideal-payment.de/ideal/iDEALv3'),
			'rabo' => array('ideal.rabobank.nl/ideal/iDEALv3','idealtest.rabobank.nl/ideal/iDEALv3'),
			'simulator' => array('www.ideal-simulator.nl/professional-v3/', 'www.ideal-simulator.nl/professional-v3/')
		);
		
		public static function directoryRequest($fields = array(), $host_obj) {
			$cache = Core_CacheBase::create();
			$cache_key = 'idealpaymentgateway:DirectoryReq:' . 
				($host_obj->test_mode?'testing':'live') . ($host_obj->old_version?'22':'331');
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
			if ($response->Error) {
				throw new Phpr_ApplicationException('Error retrieving status request: ' . $response->Error->errorCode);
			}
			
			if ($host_obj->old_version) {
				$certificate = self::get_certificate($host_obj, 'acquirer_certificate');
				if (!$certificate) {
					throw new Phpr_ApplicationException('Unable to load acquirer certificate');
				}
			
				$message = $response->createDateTimeStamp . $response->Transaction->transactionID .
					$response->Transaction->status . (string)$response->Transaction->consumerAccountNumber;
			
				if (!self::verify_message($certificate, $message, base64_decode((string)$response->Signature->signatureValue))) {
					throw new Phpr_ApplicationException('Unable to securely verify returned status request');
				}
			} else {
				$dom = dom_import_simplexml($response)->ownerDocument;
				
				$objXMLSecDSig = new XMLSecurityDSig();
				$objDSig = $objXMLSecDSig->locateSignature($dom);
				if (!$objDSig) {
					throw new Phpr_ApplicationException('Unable to locate signature node');
				}
				
				$objXMLSecDSig->canonicalizeSignedInfo();
				$objXMLSecDSig->idKeys = array('wsu:Id');
				$objXMLSecDSig->idNS = array('wsu'=>'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd');
				
				$retVal = $objXMLSecDSig->validateReference();
				
				if (!$retVal) {
					throw new Phpr_ApplicationException("Reference validation failed");
				}
	
				$objKey = $objXMLSecDSig->locateKey();
				if (!$objKey ) {
					throw new Phpr_ApplicationException("Unable to locate key");
				}
				$key = null;
				$objKey->loadKey(self::get_certificate_contents($host_obj, 'acquirer_certificate'));
				
				if (!$objXMLSecDSig->verify($objKey)) {
					throw new Phpr_ApplicationException('Unable to securly verify returned status request');
				}
			}
			return $response;
		}
		
		public static function getSignedXmlElement($host_obj, $doc) {
			$xml = new DOMDocument();
			$xml->loadXML( $doc->asXml() );

			// Decode the private key so we can use it to sign the request
			$privateKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, array('type' => 'private'));
			$privateKey->passphrase = $host_obj->private_key_passphrase;
			$privateKey->loadKey(self::get_private_key_contents($host_obj));

			// Create and configure the DSig helper and calculate the signature
			$xmlDSigHelper = new XMLSecurityDSig();
			$xmlDSigHelper->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
			$xmlDSigHelper->addReference($xml, XMLSecurityDSig::SHA256, array('http://www.w3.org/2000/09/xmldsig#enveloped-signature'), array('force_uri' => true));
			$xmlDSigHelper->sign($privateKey);

			// Append the signature to the XML and save it for modification
			$signature = $xmlDSigHelper->appendSignature($xml->documentElement);

			// Calculate the fingerprint of the certificate
			$thumbprint = XMLSecurityKey::getRawThumbprint(self::get_certificate_contents($host_obj));

			// Append the KeyInfo and KeyName elements to the signature
			$keyInfo = $signature->ownerDocument->createElementNS(XMLSecurityDSig::XMLDSIGNS, 'KeyInfo');
			$keyName = $keyInfo->ownerDocument->createElementNS(XMLSecurityDSig::XMLDSIGNS, 'KeyName', $thumbprint);
			$keyInfo->appendChild($keyName);
			$signature->appendChild($keyInfo);

			// Convert back to SimpleXMLElement and return
			return new \SimpleXMLElement( $xml->saveXML() );
		}
		
		public static function doRequest($type, &$fields, $host_obj) {
			$fields = array_merge_recursive(array(
				'createDateTimestamp' => gmdate('Y-m-d\TH:i:s.000\Z'),
				'Merchant' => array(
					'merchantID' => sprintf('%09d', $host_obj->merchantID),
					'subID' => (int)$host_obj->subID,
				)
			), $fields);
			
			if ($host_obj->old_version) {
				$fields['Merchant']['token'] = self::generateToken($host_obj);
				$fields['Merchant']['tokenCode'] = base64_encode(self::generateTokenCode($fields, $host_obj));
				$fields['Merchant']['authentication'] = 'SHA1_RSA';
			}
			
			uksort($fields, 'strcasecmp');
			
			$version = $host_obj->old_version ? '1.1.0' : '3.3.1';
			$xmlns = $host_obj->old_version ? 'http://www.idealdesk.com/Message' : ('http://www.idealdesk.com/ideal/messages/mer-acq/' . $version);
			
			$data = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"utf-8\" ?><{$type} xmlns=\"{$xmlns}\"  version=\"{$version}\"></{$type}>");
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
			
			if (!$host_obj->old_version) {
				$data = self::getSignedXmlElement($host_obj, $data);
			}
			
			$data = $data->asXml();
			
			if (Phpr::$config->get('DEV_MODE')) {
				traceLog( "Request\n\n" . $data . "\n\n\n" );
			}
			
			$base_url = $host_obj->old_version ? self::$acquirer_urls : self::$v3acquirer_urls;
			
			if ($response = Core_Http::post_data($base_url[$host_obj->bank_name][($host_obj->test_mode?1:0)], $data)) {
				$response = preg_split('/^\r?$/m', $response, 2);
				$response = trim($response[1]);
				try {
					$xml = simplexml_load_string($response);
				} catch ( Exception $e ) {
					throw new Phpr_ApplicationException('Unable to retreive information from the payment gateway. -- ' . $response);
				}
				if (Phpr::$config->get('DEV_MODE')) {
					traceLog( "Response\n\n" . $xml->asXml() . "\n\n\n" );
				}
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
		
		public static function get_private_key_contents($host_obj) {
			$private_key = $host_obj->private_key;
			if (file_exists($private_key) && is_readable($private_key)) {
				$private_key = file_get_contents($private_key);
			}
			return $private_key;
		}
		
		public static function get_private_key($host_obj) {
			return openssl_pkey_get_private(self::get_private_key_contents($host_obj), $host_obj->private_key_passphrase);
		}
		
		public static function get_certificate_contents($host_obj, $field = "certificate") {
			if ('acquirer_certificate' == $field) {
				return file_get_contents(dirname(__FILE__) . '/../certificates/' . ($host_obj->old_version ? 'v2' : 'v3') . '/' . $host_obj->bank_name . '.cer');
			}
			$certificate = $host_obj->{$field};
			if (file_exists($certificate) && is_readable($certificate)) {
				$certificate = file_get_contents($certificate);
			}
			return $certificate;
		}
		
		public static function get_certificate($host_obj, $field = "certificate") {
			$certificate = self::get_certificate_contents($host_obj, $field);
			try {
				openssl_x509_read($certificate);
			} catch (Exception $e) {
				return null;
			}

			$data = null;
			if (!openssl_x509_export($certificate, $data)) {
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
			$str = '<select name="IdealPaymentGateway_issuerID">';
			if ($host_obj->old_version) {
				$short_list = $long_list = array();
				foreach ($issuers->Directory->Issuer as $issuer) {
					if ('Short' == (string)$issuer->issuerList) {
						$short_list[(int)$issuer->issuerID] = (string)$issuer->issuerName;
					} else {
						$long_list[(int)$issuer->issuerID] = (string)$issuer->issuerName;
					}
				}
				$str .= '<option value="">Kies uw bank.</option>';
				foreach ($short_list as $id => $name)
					$str .= '<option value="' . $id . '">' . h($name) . '</option>';
				$str .= '<option value="">---Overige banken---</option>';
				foreach ($long_list as $id => $name)
					$str .= '<option value="' . $id . '">' . h($name) . '</option>';
			} else {
				foreach ($issuers->Directory->Country as $country) {
					$str .= '<optgroup label="' . h((string)$country->countryNames) . '">';
					foreach ($country->Issuer as $issuer) {
						$str .= '<option value="' . (string)$issuer->issuerID . '">' . h((string)$issuer->issuerName) . '</option>';
					}
					$str .= '</optgroup>';
				};
			}
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