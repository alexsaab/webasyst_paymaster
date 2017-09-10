<?php

/**
 *
 * @author      Webasyst
 * @name                  Paymaster
 * @description Paymaster payment module
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
		$order = $order_data = waOrder::factory($order_data);

		// добавляем в платежную форму поля, требуемые платежной системой WebMoney
		$hidden_fields = array(
			'LMI_MERCHANT_ID'    => $this->merchantID,
			'LMI_PAYMENT_AMOUNT' => number_format($this->getOrderAmount($order), 2, '.', ''),
			'LMI_CURRENCY'       => strtoupper($order->currency),
			'LMI_PAYMENT_NO'     => $order_data['order_id'],
			'LMI_PAYMENT_DESC'   => $order->description,
			'LMI_NOTIFY_URL'     => $this->getRelayUrl(),
			'SIGN' => $this->getSign($this->merchantID, $order_data['order_id'],number_format($this->getOrderAmount($order), 2, '.', ''),strtoupper($order->currency),$this->secret,$this->signMethod)
		);


		if ($this->testMode)
		{
			$hidden_fields['LMI_SIM_MODE'] = $this->testMode;
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

		if ($order->shipping > 0)
		{
			$key++;
			$hidden_fields["LMI_SHOPPINGCART.ITEM[{$key}].NAME"]  = $order->shipping_name;
			$hidden_fields["LMI_SHOPPINGCART.ITEM[{$key}].QTY"]   = 1;
			$hidden_fields["LMI_SHOPPINGCART.ITEM[{$key}].PRICE"] = number_format($order->shipping, 2, '.', '');
			$hidden_fields["LMI_SHOPPINGCART.ITEM[{$key}].TAX"]   = $this->vatDelivery;
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
	 * Получение налогового кода
	 * @param $item
	 *
	 * @return int
	 */
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

	/**
	 * @param array $request
	 *
	 * @return waPayment
	 */
	protected function callbackInit($request)
	{
		$this->request = $request;

		return parent::callbackInit($request);
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
		$app_payment_method = $this->id;

		$order = new shopOrderModel();



		if ($_SERVER["REQUEST_METHOD"] == "POST")
		{

			$order_total = $request->LMI_PAID_AMOUNT;

			if ($request->LMI_PREREQUEST)
			{
				if (($request->LMI_MERCHANT_ID == $this->merchantID) && ($request->LMI_PAYMENT_AMOUNT == $order_total))
				{
					self::log($this->id, array('success' => 'Test finished with success'));
					echo 'YES';
					exit;
				}
				else
				{
					self::log($this->id, array('error' => 'Test finished with error'));
					echo 'FAIL';
					exit;
				}
			}
			else
			{
				$hash = $this->getHash($request->LMI_MERCHANT_ID, $request->LMI_PAYMENT_NO, $request->LMI_SYS_PAYMENT_ID, $request->LMI_SYS_PAYMENT_DATE, $order_total, $request->LMI_CURRENCY, $request->LMI_PAID_AMOUNT, $request->LMI_PAID_CURRENCY, $request->LMI_PAYMENT_SYSTEM, $request->LMI_SIM_MODE, $this->secret, $this->signMethod);
				if ($request->LMI_HASH == $hash)
				{
					$sign = $this->getSign($request->LMI_MERCHANT_ID, $request->LMI_PAYMENT_NO, $request->LMI_PAID_AMOUNT, $request->LMI_PAID_CURRENCY, $this->secret, $this->signMethod);
					if ($sign == $request->SIGN)
					{
						$transaction_data = $this->saveTransaction($transaction_data, $request);
						$result           = $this->execAppCallback($app_payment_method, $transaction_data);
						self::log($this->id, array('success' => 'Payment paymaster finished with success'));
						return $result;
					}
					else
					{
						self::log($this->id, array('error' => 'Invalid sign'));
						return;
					}
				} else {
					self::log($this->id, array('error' => 'Invalid hash'));
					return;
				}
			}
		}


		return $result;
	}


	/**
	 * Для вызова интерфейса PayMaster
	 * @return string
	 */
	private function getEndpointUrl()
	{

		return $this->endpointUrl;
	}

	/**
	 * Получение суммы заказа
	 */
	private function getOrderAmount($order)
	{
		$amount = 0;

		foreach ($order->items as $key => $product)
		{
			$amount += ($product['price'] - $product['discount']) * $product['quantity'];
		}

		if ($order->shipping > 0)
		{
			$amount += $order->shipping;
		}

		return $amount;
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

	/**
	 * Возвращаем HASH запроса
	 * @param $merchant_id
	 * @param $order_id
	 * @param $amount
	 * @param $lmi_currency
	 * @param $secret_key
	 * @param string $sign_method
	 * @return string
	 */
	public function getHash($LMI_MERCHANT_ID, $LMI_PAYMENT_NO, $LMI_SYS_PAYMENT_ID, $LMI_SYS_PAYMENT_DATE, $LMI_PAYMENT_AMOUNT, $LMI_CURRENCY, $LMI_PAID_AMOUNT, $LMI_PAID_CURRENCY, $LMI_PAYMENT_SYSTEM, $LMI_SIM_MODE, $SECRET, $hash_method = 'md5')
	{
		$string = $LMI_MERCHANT_ID . ";" . $LMI_PAYMENT_NO . ";" . $LMI_SYS_PAYMENT_ID . ";" . $LMI_SYS_PAYMENT_DATE . ";" . $LMI_PAYMENT_AMOUNT . ";" . $LMI_CURRENCY . ";" . $LMI_PAID_AMOUNT . ";" . $LMI_PAID_CURRENCY . ";" . $LMI_PAYMENT_SYSTEM . ";" . $LMI_SIM_MODE . ";" . $SECRET;

		$hash = base64_encode(hash($hash_method, $string, TRUE));

		return $hash;
	}


	/**
	 * Возвращаем подпись
	 * @param $merchant_id
	 * @param $order_id
	 * @param $amount
	 * @param $lmi_currency
	 * @param $secret_key
	 * @param string $sign_method
	 * @return string
	 */
	public function getSign($merchant_id, $order_id, $amount, $lmi_currency, $secret_key, $sign_method = 'md5')
	{

		$plain_sign = $merchant_id . $order_id . $amount . $lmi_currency . $secret_key;
		$sign = base64_encode(hash($sign_method, $plain_sign, TRUE));

		return $sign;
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
