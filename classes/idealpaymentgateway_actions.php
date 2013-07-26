<?
	class IdealPaymentGateway_Actions extends Cms_ActionScope {
		public function transaction() {
			$this->on_transaction();
		}
		public function on_transaction() {
			if (!($order = Shop_Order::create()->find(post('order_id'))))
				throw new Phpr_ApplicationException('Fout: Kan uw bestelling te vinden. Gelieve te verversen en probeer het later opnieuw.');
			
			if (!($issuer_id = post('IdealPaymentGateway_issuerID')))
				throw new Phpr_ApplicationException('Fout: Kies uw bank');
			
			$order->payment_method->define_form_fields();
			
			if ($order->is_paid) {
				$return_page = $order->payment_method->receipt_page;
				if ($return_page)
					Phpr::$response->redirect(root_url($return_page->url.'/'.$order->order_hash));
				$this->data['is_paid'] = true;
				return;
			}
			
			$entrance_code = substr('ipgh' . sha1(time() . rand() . Shop_CompanyInformation::get()->name), 0, 40);

			$payment_type = $order->payment_method->get_paymenttype_object();
			
			// 'Internal Open' payment transactions should be superseded by a non Internal Open transaction within a few moments.	 If not,
			// something's gone wrong.
			$payment_type->update_transaction_status($order->payment_method, $order, $entrance_code, 'Internal Open', 'IO');
			
			// Amount is in eurocents if we're not using iDEAL v3
			$amount = $order->payment_method->old_version ? $order->total*100 : $order->total;
			
			$response = IdealPaymentGateway_Helper::transactionRequest(array(
				'Issuer' => array('issuerID' => $issuer_id),
				'Merchant' => array('merchantReturnURL' => root_url('/ls_ideal_handle_response/' . $order->order_hash, true, 'https')),
				'Transaction' => array(
					'purchaseID' => $order->id,
					'amount' => $amount,
					'currency' => Shop_CurrencySettings::get()->code,
					'expirationPeriod' => 'PT30M30S',
					'language' => 'nl',
					// Description contains no spaces to prevent discrepancies between gateways
					'description' => substr('Order_' . preg_replace('/\s+/', '_', Shop_CompanyInformation::get()->name) . '_', 0, 22) . $order->id,
					'entranceCode' => $entrance_code
				)
			), $order->payment_method);
			
			if (!$response->Error) {
				$payment_type->update_transaction_status($order->payment_method, $order, $response->Transaction->transactionID, 'Open', 'O');
				Phpr::$response->redirect($response->Issuer->issuerAuthenticationURL);
			} else {
				$error = $response->Error->errorCode . ':' . $response->Error->consumerMessage;
				$transaction = Shop_PaymentTransaction::create()->where('transaction_id = ?', $entrance_code)->find();
				if ($transaction)
					$transaction->update(array('user_note' => 'Exception from gateway: ' . $error, 'transaction_status_code' => 'IOE'));
				throw new Phpr_ApplicationException($error);
			}
		}
	}