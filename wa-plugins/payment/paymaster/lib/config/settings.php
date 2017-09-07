<?php
return array(
    'merchantID'   => array(
        'value'        => '',
        'title'        => 'Идентификатор продавца',
        'description'  => 'Для того, чтобы узнать Идентификатор продавца (ID Merchant) вам необходимо зайти в личный кабинет на сайте PayMaster',
        'control_type' => 'input',
    ),
    'secret'       => array(
        'value'        => '',
        'title'        => 'Секретная фраза',
        'description'  => 'Это сочетание знаков должно быть одинаковым и совпадать с тем, что вы ввели в интерфейсе PayMaster',
        'control_type' => 'input',
    ),
    'signMethod'   => array(
        'value'        => 'sha256',
        'title'        => 'Метод шифрования',
        'description'  => 'Для формирования подписи, должно значение совпадать с тем, что выставлено в PayMaster',
        'control_type' => 'radiogroup',
        'options'      => array(
            'md5'    => 'md5',
            'sha1'   => 'sha1',
            'sha256' => 'sha256',
        ),
    ),
    'currency'     => array(
        'value'        => 'RUB',
        'title'        => 'Идентификатор валюты',
        'description'  => 'Введите Идентификатор валюты по ISO, для рублей это всегда RUB',
        'control_type' => 'input',
    ),
    'desc'         => array(
        'value'        => 'Заказ на сайте №',
        'title'        => 'Описание платежа',
        'description'  => 'Как будет выглядеть платеж в системе PayMaster',
        'control_type' => 'input',
    ),
    'vat_products' => array(
        'value'            => 'map',
        'title'            => 'Ставки НДС для продукта',
        'control_type'     => waHtmlControl::SELECT,
        'description'      => 'Если ваша организация работает по ОСН, выберите вариант «Передавать ставки НДС по каждой позиции».<br>
Ставка НДС может быть равна 0%, 10% или 18%. В настройках налогов в приложении выберите, чтобы НДС был включен в цену товара.<br>
Если вы работаете по другой системе налогообложения, выберите «НДС не облагается».',
        'options_callback' => array($this, 'vatProductsOptions'),
    ),
    'vat_delivery' => array(
        'value'            => 'map',
        'title'            => 'Ставки НДС для доставки',
        'control_type'     => waHtmlControl::SELECT,
        'description'      => 'Выберете ставку НДС для обложения доставки',
        'options_callback' => array($this, 'vatDeliveryOptions'),
    ),
);
