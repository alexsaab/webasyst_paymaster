<?php

/**
 *
 * @author      Webasyst
 * @name                  Paymaster
 * @description Paymaster payment module
 * @property-read string  $integration_type
 * @property-read string  $account
 * @property-read string  $TESTMODE
 * @property-read string  $shopPassword
 * @property-read string  $ShopID
 * @property-read string  $scid
 * @property-read string  $payment_mode
 * @property-read array   $paymentType
 * @property-read boolean $receipt
 * @property-read int     $taxSystem
 * @property-read string  $taxes
 *
 * @see         http://info.paymaster.ru/api/
 */
class paymasterPayment extends waPayment implements waIPayment
{

	//Переадресация на систему оплаты PayMaster

	protected $endpointUrl = 'https://paymaster.ru/Payment/Init';

	protected $currency = array('RUB', 'UAH', 'USD', 'EUR', 'UZS', 'BYR');

	/**
	 * Возвращаем допустимые валюты
	 * @return array
	 */
	public function allowedCurrency()
	{
		return $this->currency;
	}

	public function payment($payment_form_data, $order_data, $auto_submit = false)
	{

		// заполняем обязательный элемент данных с описанием заказа
		if (empty($order_data['description']))
		{
			$order_data['description'] = $this->description . $order_data['order_id'];
		}
		// вызываем класс-обертку, чтобы гарантировать использование данных в правильном формате
		$order = waOrder::factory($order_data);


		$order_data = waOrder::factory($order_data);
		if ($order_data['currency_id'] != 'RUB')
		{
			return array(
				'type' => 'error',
				'data' => 'Оплата на сайте PayMaster производится в только в рублях (RUB) и в данный момент невозможна, так как эта валюта не определена в настройках.',
			);
		}
		// добавляем в платежную форму поля, требуемые платежной системой WebMoney
		$hidden_fields = array(
			'LMI_MERCHANT_ID'    => $this->merchantID,
			'LMI_PAYMENT_AMOUNT' => number_format($order->total, 2, '.', ''),
			'LMI_CURRENCY'       => strtoupper($order->currency),
			'LMI_PAYMENT_NO'     => $order_data['order_id'],
			'LMI_PAYMENT_DESC'   => $order->description,
			'LMI_NOTIFY_URL'     => $this->getRelayUrl(),
		);


		if ($this->TESTMODE)
		{
			$hidden_fields['LMI_SIM_MODE'] = $this->LMI_SIM_MODE;
		}
		if (!empty($order_data['customer_info']['email']))
		{
			$hidden_fields['LMI_PAYER_EMAIL'] = $order_data['customer_info']['email'];
		}


		$transaction_data = $this->formalizeData($hidden_fields);
		// добавляем служебные URL:
		// URL возврата покупателя после успешного завершения оплаты
		$hidden_fields['LMI_SUCCESS_URL'] = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
		// URL возврата покупателя после неудачной оплаты
		$hidden_fields['LMI_FAILURE_URL'] = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);

		foreach ($order->items as $key => $product)
		{
			$hidden_fields["LMI_SHOPPINGCART.ITEM[{$key}].NAME"]  = $product['name'];
			$hidden_fields["LMI_SHOPPINGCART.ITEM[{$key}].QTY"]   = $product['quantity'];
			$hidden_fields["LMI_SHOPPINGCART.ITEM[{$key}].PRICE"] = number_format($product['price'] - $product['discount'], 2, '.', '');

			if ($this->vatProducts == 'map')
			{
				$this->vatProducts = 'no_vat';
			}
			$hidden_fields["LMI_SHOPPINGCART.ITEM[{$key}].TAX"] = $this->vatProducts;
		}

		if ($order->shipping > 0) {
			$key++;
			$hidden_fields["LMI_SHOPPINGCART.ITEM[{$key}].NAME"]  = $order->shipping_name;
			$hidden_fields["LMI_SHOPPINGCART.ITEM[{$key}].QTY"]   = 1;
			$hidden_fields["LMI_SHOPPINGCART.ITEM[{$key}].PRICE"] = number_format($order->shipping, 2, '.', '');
			$hidden_fields["LMI_SHOPPINGCART.ITEM[{$key}].TAX"] = $this->vatDelivery;
		}

		$view = wa()->getView();
		$view->assign('url', wa()->getRootUrl());
		$view->assign('hidden_fields', $hidden_fields);
		$view->assign('form_url', $this->getEndpointUrl());
		$view->assign('form_options', $this->getFormOptions());
		$view->assign('auto_submit', $auto_submit);
		$view->assign('order', $order);

		// для отображения платежной формы используем собственный шаблон
		return $view->fetch($this->path . '/templates/payment.html');
	}

	/*
	 * Какая то непонятная функция связанная с webmoney
	 */
	private function getFormOptions()
	{
		$options = array();

		$options['accept-charset'] = 'windows-1251';

		return $options;
	}

	/**
	 * @see https://tech.yandex.ru/money/doc/payment-solution/payment-form/payment-form-receipt-docpage/
	 *
	 * @param waOrder $order
	 *
	 * @return array|null
	 */
	private function getReceiptData(waOrder $order)
	{
		$receipt = null;
		if ($this->receipt)
		{
			$contact = $order->getContactField('email');
			if (empty($contact))
			{
				if ($contact = $order->getContactField('phone'))
				{
					$contact = sprintf('+%s', preg_replace('@^8@', '7', $contact));
				}
			}

			if (!empty($contact))
			{
				$receipt = array(
					'customerContact' => $contact,
					'items'           => array(),
				);
				if ($this->taxSystem)
				{
					$receipt['taxSystem'] = $this->taxSystem;
				}

				foreach ($order->items as $item)
				{
					$item['amount']     = $item['price'] - ifset($item['discount'], 0.0);
					$receipt['items'][] = array(
						'quantity' => $item['quantity'],
						'price'    => array(
							'amount' => number_format($item['amount'], 2, '.', ''),
						),
						'tax'      => $this->getTaxId($item),
						'text'     => mb_substr($item['name'], 0, 128),
					);
				}

				#shipping
				$receipt['items'][] = array(
					'quantity' => 1,
					'price'    => array(
						'amount' => number_format($order->shipping, 2, '.', ''),
					),
					'tax'      => 1,
					'text'     => mb_substr($order->shipping_name, 0, 128),
				);
			}
		}

		return $receipt;
	}

	private function getTaxId($item)
	{
		$id = 1;
		switch ($this->taxes)
		{
			case 'no':
				# 1 — без НДС;
				$id = 1;
				break;
			case 'map':
				$rate = ifset($item['tax_rate']);
				if (in_array($rate, array(null, false, ''), true))
				{
					$rate = -1;
				}
				switch ($rate)
				{
					case 18: # 4 — НДС чека по ставке 18%;
						$id = 4;
						break;
					case 10: # 3 — НДС чека по ставке 10%;
						$id = 3;
						break;
					case 0: # 2 — НДС по ставке 0%;
						$id = 2;
						break;
					default: # 1 — без НДС;
						$id = 1;
						break;
				}
				break;
		}

		return $id;
	}

	protected function callbackInit($request)
	{
		$this->request    = $request;
		$pattern          = '/^([a-z]+)_(.+)_(.+)$/';
		$merchant_pattern = '/^([a-z]+)_([^_]+)_([^_]+)/';

		if (!empty($request['orderNumber']) && preg_match($pattern, $request['orderNumber'], $match))
		{
			$this->app_id      = $match[1];
			$this->merchant_id = $match[2];
			$this->order_id    = $match[3];
		}
		elseif (!empty($request['merchant_order_id']) && preg_match($merchant_pattern, $request['merchant_order_id'], $match))
		{
			$this->app_id      = $match[1];
			$this->merchant_id = $match[2];
			$this->order_id    = $match[3];
		}
		elseif (!empty($request['orderDetails']))
		{
			/**
			 * @see https://tech.yandex.ru/money/doc/payment-solution/payment-process/payments-mpos-docpage/
			 * mobile terminal — detect app automatically/parse string
			 * shop:100500 #order_id new
			 */
			if (preg_match('@^(\w+):(\d+)(\s+|$)@', $request['orderDetails'], $match))
			{
				$this->app_id      = $match[1];
				$this->merchant_id = $match[2];
				$comment           = trim($match[3]);
				if (preg_match('@^(\d+)(\s+|$)@', $comment, $match))
				{
					$this->order_id = $match[1];
				}
				else
				{
					$this->order_id = 'offline';
				}
			}
			elseif (preg_match('@^#?(\d+)(\s+|$)@', $request['orderDetails'], $match))
			{
				$this->order_id = $match[1];
			}
			else
			{
				$this->order_id = 'offline';
			}
			if (empty($this->merchant_id))
			{
				$this->merchant_id = array($this, 'callbackMatchSettings');
			}
		}
		elseif (isset($request['paymentType']) && ($request['paymentType'] == 'MP'))
		{
			$this->order_id    = 'offline';
			$this->merchant_id = array($this, 'callbackMatchSettings');
		}

		return parent::callbackInit($request);
	}

	public function callbackMatchSettings($settings)
	{
		return !empty($settings['ShopID'])
			&& ($settings['ShopID'] == ifset($this->request['shopId']))
			&& !empty($settings['scid'])
			&& ($settings['scid'] == ifset($this->request['scid']))
			&& ($settings['payment_mode'] == 'MP');
	}

	/**
	 *
	 * @param array $request - get from gateway
	 *
	 * @throws waPaymentException
	 * @return mixed
	 */
	protected function callbackHandler($request)
	{
		$transaction_data = $this->formalizeData($request);

		$code = ($transaction_data['type'] == self::OPERATION_CHECK) ? self::XML_PAYMENT_REFUSED : self::XML_TEMPORAL_PROBLEMS;

		if (!$this->order_id || !$this->app_id || !$this->merchant_id)
		{
			throw new waPaymentException('invalid invoice number', $code);
		}
		if (!$this->ShopID)
		{
			throw new waPaymentException('empty merchant data', $code);
		}
		if (waRequest::get('result') || (ifset($request['action']) == 'PaymentFail'))
		{
			if ((ifset($request['action']) == 'PaymentFail') || (waRequest::get('result') == 'fail'))
			{
				$type = waAppPayment::URL_FAIL;
			}
			else
			{
				$type = waAppPayment::URL_SUCCESS;
			}

			return array(
				'redirect' => $this->getAdapter()->getBackUrl($type, $transaction_data),
			);
		}

		$this->verifySign($request);

		if (!$this->TESTMODE)
		{
			if (ifset($request['orderSumCurrencyPaycash']) != 643)
			{
				throw new waPaymentException('Invalid currency code', self::XML_PAYMENT_REFUSED);
			}
		}

		if (($this->order_id === 'offline') || (ifset($request['paymentType']) == 'MP'))
		{
			$transaction_data['unsettled'] = true;
			$fields                        = array(
				'native_id' => $transaction_data['native_id'],
				'plugin'    => $this->id,
				'type'      => array(waPayment::OPERATION_CHECK, waPayment::OPERATION_AUTH_CAPTURE),
			);
			$tm                            = new waTransactionModel();
			$check                         = $tm->getByField($fields);
			if ($check && !empty($check['order_id']))
			{
				if ($transaction_data['order_id'] != $check['order_id'])
				{
					if (($transaction_data['order_id'] !== 'offline') && ($transaction_data['order_id'] != $check['order_id']))
					{
						$message                       = ' Внимание: номер переданного заказа %s не совпадает с сопоставленным';
						$transaction_data['view_data'] .= sprintf($message, htmlentities($transaction_data['order_id'], ENT_NOQUOTES, 'utf-8'));
					}
					$transaction_data['order_id'] = $check['order_id'];
				}
			}
		}

		switch ($transaction_data['type'])
		{
			case self::OPERATION_CHECK:
				$app_payment_method        = self::CALLBACK_CONFIRMATION;
				$transaction_data['state'] = '';
				break;

			case self::OPERATION_AUTH_CAPTURE:
				//XXX rebillingOn workaround needed
				if (empty($tm))
				{
					$tm = new waTransactionModel();
				}
				$fields = array(
					'native_id' => $transaction_data['native_id'],
					'plugin'    => $this->id,
					'type'      => waPayment::OPERATION_AUTH_CAPTURE,
				);
				if ($tm->getByFields($fields))
				{
					// exclude transactions duplicates
					throw new waPaymentException('already accepted', self::XML_SUCCESS);
				}

				$app_payment_method        = self::CALLBACK_PAYMENT;
				$transaction_data['state'] = self::STATE_CAPTURED;
				break;
			default:
				throw new waPaymentException('unsupported payment operation', self::XML_TEMPORAL_PROBLEMS);
		}

		$transaction_data = $this->saveTransaction($transaction_data, $request);
		$result           = $this->execAppCallback($app_payment_method, $transaction_data);

		return $this->getXMLResponse($request, !empty($result['result']) ? self::XML_SUCCESS : self::XML_PAYMENT_REFUSED, ifset($result['error']));
	}

	protected function callbackExceptionHandler(Exception $ex)
	{
		self::log($this->id, $ex->getMessage());
		$message = '';
		if ($ex instanceof waPaymentException)
		{
			$code    = $ex->getCode();
			$message = $ex->getMessage();
		}
		else
		{
			$code = self::XML_TEMPORAL_PROBLEMS;
		}

		return $this->getXMLResponse($this->request, $code, $message);
	}

	private function getEndpointUrl()
	{

		return $this->endpointUrl;
	}

	/**
	 * Check MD5 hash of transferred data
	 * @throws waPaymentException
	 *
	 * @param array $request
	 */
	private function verifySign($request)
	{
		$fields = array(
			'shopId'              => $this->ShopID,
			'scid'                => $this->scid,
			'orderSumBankPaycash' => ($this->TESTMODE) ? 1003 : 1001,
		);
		foreach ($fields as $field => $value)
		{
			if (empty($request[$field]) || ($request[$field] != $value))
			{
				throw new waPaymentException("Invalid value of field {$field}", self::XML_PAYMENT_REFUSED);
			}
		}

		$hash_chunks = array();
		switch ($this->version)
		{
			case '3.0':
				//action;orderSumAmount;orderSumCurrencyPaycash;orderSumBankPaycash;shopId;invoiceId;customerNumber;shopPassword
				$hash_params = array(
					'action',
					'orderSumAmount',
					'orderSumCurrencyPaycash',
					'orderSumBankPaycash',
					'shopId',
					'invoiceId',
					'CustomerNumber' => 'customerNumber',
				);
				break;
			default:
				//orderIsPaid;orderSumAmount;orderSumCurrencyPaycash;orderSumBankPaycash;shopId;invoiceId;customerNumber
				//В случае расчета криптографического хэша, в конце описанной выше строки добавляется «;shopPassword»
				$hash_params = array(
					'orderIsPaid',
					'orderSumAmount',
					'orderSumCurrencyPaycash',
					'orderSumBankPaycash',
					'shopId',
					'invoiceId',
					'CustomerNumber' => 'customerNumber',
				);
				break;
		}

		$missed_fields = array();
		foreach ($hash_params as $id => $field)
		{
			if (is_int($id))
			{
				if (!isset($request[$field]))
				{
					$missed_fields[] = $field;
				}
				else
				{
					$hash_chunks[] = $request[$field];
				}
			}
			else
			{
				if (!empty($request[$id]))
				{
					$hash_chunks[] = $request[$id];
				}
				elseif (!empty($request[$field]))
				{
					$hash_chunks[] = $request[$field];
				}
				else
				{
					$missed_fields[] = $field;
				}
			}

		}

		if ($missed_fields)
		{
			self::log(
				$this->id,
				array(
					'method'  => __METHOD__,
					'version' => $this->version,
					'error'   => 'empty required field(s): ' . implode(', ', $missed_fields),
				)
			);
			throw new waPaymentException('Empty required field', self::XML_BAD_REQUEST);
		}

		$hash_chunks[] = $this->shopPassword;

		$hash = strtoupper(md5(implode(';', $hash_chunks)));
		if (empty($request['md5']) || ($hash !== strtoupper($request['md5'])))
		{
			throw new waPaymentException('invalid hash', self::XML_AUTH_FAILED);
		}
	}

	/**
	 * Convert transaction raw data to formatted data
	 *
	 * @param array $transaction_raw_data
	 *
	 * @return array $transaction_data
	 * @throws waPaymentException
	 */
	protected function formalizeData($transaction_raw_data)
	{
		$transaction_data = parent::formalizeData($transaction_raw_data);

		$view_data = '';
		if (ifset($transaction_raw_data['paymentPayerCode']))
		{
			$view_data .= 'Account: ' . $transaction_raw_data['paymentPayerCode'];
		}

		if (!empty($transaction_raw_data['cps_provider']))
		{
			switch ($transaction_raw_data['cps_provider'])
			{
				case 'wm':
					$view_data .= 'Оплачено: WebMoney';
					break;
				default:
					$view_data .= 'Оплачено: ' . $transaction_raw_data['cps_provider'];
					break;
			}
		}

		if ($this->TESTMODE)
		{
			if (ifset($transaction_raw_data['orderSumCurrencyPaycash']) != 10643)
			{
				$view_data .= ' Реальная оплата в тестовом режиме;';
			}
			else
			{
				$view_data .= ' Тестовый режим;';
			}
		}

		if (!empty($transaction_raw_data['paymentType']))
		{
			$types = self::settingsPaymentOptions();
			if (isset($types[$transaction_raw_data['paymentType']]))
			{
				$view_data .= ' ' . $types[$transaction_raw_data['paymentType']];
			}
			switch ($transaction_raw_data['paymentType'])
			{
				case 'AC':
					if (!empty($transaction_raw_data['cdd_pan_mask']) && !empty($transaction_raw_data['cdd_exp_date']))
					{
						$number    = str_replace('|', str_repeat('*', 6), $transaction_raw_data['cdd_pan_mask']);
						$view_data .= preg_replace('@([\d*]{4})@', ' $1', $number);
						$view_data .= preg_replace('@(\d{2})(\d{2})@', ' $1/20$2', $transaction_raw_data['cdd_exp_date']);
					}
					break;
			}
		}

		$transaction_data = array_merge(
			$transaction_data,
			array(
				'type'        => null,
				'native_id'   => ifset($transaction_raw_data['invoiceId']),
				'amount'      => ifset($transaction_raw_data['orderSumAmount']),
				'currency_id' => 'RUB',
				'customer_id' => ifempty($transaction_raw_data['customerNumber'], ifset($transaction_raw_data['CustomerNumber'])),
				'result'      => 1,
				'order_id'    => $this->order_id,
				'view_data'   => trim($view_data),
			)
		);

		switch (ifset($transaction_raw_data['action']))
		{
			case 'checkOrder': //Проверка заказа
				$this->version            = '3.0';
				$transaction_data['type'] = self::OPERATION_CHECK;
				if ($this->order_id === 'offline')
				{

				}
				else
				{
					$transaction_data['view_data'] .= ' Проверка актуальности заказа;';
				}
				break;
			case 'paymentAviso': //Уведомления об оплате
				$this->version            = '3.0';
				$transaction_data['type'] = self::OPERATION_AUTH_CAPTURE;
				break;

			case 'Check': //Проверка заказа
				$transaction_data['type'] = self::OPERATION_CHECK;
				break;
			case 'PaymentSuccess': //Уведомления об оплате
				$transaction_data['type'] = self::OPERATION_AUTH_CAPTURE;
				break;
			case 'PaymentFail': //после неуспешного платежа.
				break;
		}

		return $transaction_data;
	}

	public function supportedOperations()
	{
		return array(
			self::OPERATION_CHECK,
			self::OPERATION_AUTH_CAPTURE,
		);
	}

	/**
	 * @param        $request
	 * @param        $code
	 * @param string $message
	 *
	 * @return array
	 */
	private function getXMLResponse($request, $code, $message = '')
	{
		$response                      = array();
		$response['action']            = ifempty($request['action'], 'dummy');
		$response['code']              = $code;
		$response['performedDatetime'] = date('c');

		$message = preg_replace('@[\s\n]+@', ' ', $message);
		$message = htmlentities($message, ENT_QUOTES, 'utf-8');
		if ($this->version == '1.3')
		{
			$message = iconv('utf-8', 'cp1251', $message);
		}
		if (strlen($message) > 64)
		{
			$message = substr($message, 0, 64);
		}
		$response['techMessage'] = $message;
		$response['shopId']      = $this->ShopID;
		$response['invoiceId']   = ifempty($request['invoiceId'], '');

		return array(
			'template' => $this->path . '/templates/response.' . $this->version . '.xml',
			'data'     => $response,
			'header'   => array(
				'Content-type' => ($this->version == '1.3') ? 'text/xml; charset=windows-1251;' : 'text/xml; charset=utf-8;',
			),
		);
	}

	/**
	 * @link https://tech.yandex.ru/money/doc/payment-solution/reference/payment-type-codes-docpage/
	 * @return array
	 */
	public static function settingsPaymentOptions()
	{
		return array(
			'PC' => 'Оплата со счета в Яндекс.Деньгах',
			'AC' => 'Оплата с банковской карты',
			'GP' => 'Оплата по коду через терминал',
			'MC' => 'Оплата со счета мобильного телефона',
			'WM' => 'Оплата со счета WebMoney',
			'SB' => 'Оплата через Сбербанк Онлайн',
			'AB' => 'Оплата в Альфа-Клик',
			'MP' => 'Оплата через мобильный терминал (mPOS)',
			'MA' => 'Оплата через MasterPass',
			'PB' => 'Оплата через интернет-банк Промсвязьбанка',
			'QW' => 'Оплата через QIWI Wallet',
			'KV' => 'Оплата через КупиВкредит (Тинькофф Банк)',
			'QP' => 'Оплата через Доверительный платеж («Куппи.ру»)',
		);
	}

	public static function settingsPaymentModeOptions()
	{
		return array(
				'customer' => 'На выбор покупателя после перехода на сайт Яндекса (рекомендуется)',
				''         => 'Не задан (определяется Яндексом)',
			) + self::settingsPaymentOptions();
	}


	/**
	 * Возвращаем список опций для продуктов
	 **/
	public function vatProductsOptions()
	{
		$disabled = !$this->getAdapter()->getAppProperties('taxes');

		return array(
			array(
				'value' => 'vat18',
				'title' => 'НДС 18%',
			),
			array(
				'value' => 'vat18',
				'title' => 'НДС 10%',
			),
			array(
				'value' => 'vat118',
				'title' => 'НДС по формуле 18/118%',
			),
			array(
				'value' => 'vat110',
				'title' => 'НДС по формуле 10/110%',
			),
			array(
				'value' => 'vat0',
				'title' => 'НДС 0%',
			),
			array(
				'value' => 'no_vat',
				'title' => 'без НДС',
			),
			array(
				'value'    => 'map',
				'title'    => 'Передавать ставки НДС по каждой позиции',
				'disabled' => $disabled,
			),
		);
	}

	/**
	 * Возвращаем ставку НДС для доставки
	 * по сути это просто список с выбором
	 **/
	public function vatDeliveryOptions()
	{
		return array(
			array(
				'value' => 'vat18',
				'title' => 'НДС 18%',
			),
			array(
				'value' => 'vat18',
				'title' => 'НДС 10%',
			),
			array(
				'value' => 'vat118',
				'title' => 'НДС по формуле 18/118%',
			),
			array(
				'value' => 'vat110',
				'title' => 'НДС по формуле 10/110%',
			),
			array(
				'value' => 'vat0',
				'title' => 'НДС 0%',
			),
			array(
				'value' => 'no_vat',
				'title' => 'без НДС',
			),

		);
	}
}
