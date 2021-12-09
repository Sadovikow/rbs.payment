<?php

namespace Sale\Handlers\PaySystem;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;
use Bitrix\Main\Text\Encoding;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web;
use Bitrix\Sale\BusinessValue;
use Bitrix\Sale\PaySystem;
use Bitrix\Sale\Payment;
use Bitrix\Sale\Order;
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Main;
IncludeModuleLangFile(__FILE__);
require_once dirname(dirname(__FILE__)) . '/config.php';
define('DEF_MODULE_ID', $RBS_CONFIG['MODULE_ID']);
define('DEF_FISCAL', $RBS_CONFIG['FISCAL']);
Loader::includeModule( 'rbs.payment' );

//define("LOG_FILENAME", $_SERVER["DOCUMENT_ROOT"]."/log_orders.txt");

\Bitrix\Main\Loader::includeModule('iblock');
/**
 * Class rbsPaymentHandler
 * @package Sale\Handlers\PaySystem
 */
class rbs_paymentHandler extends PaySystem\ServiceHandler implements PaySystem\IPrePayable
{
	/**
	 * @param Payment $payment
	 * @param Request|null $request
	 * @return PaySystem\ServiceResult
	 */
	public function initiatePay(Payment $payment, Request $request = null)
	{

		$moduleId = 'rbs.payment';

		$RBS_Gateway = new \Rbs\Payments\Gateway;


		// module settings
		$RBS_Gateway->setOptions(array(
			'module_id' => Option::get($moduleId, 'MODULE_ID'),
			'gate_url_prod' => Option::get($moduleId, 'RBS_PROD_URL'),
			'gate_url_test' => Option::get($moduleId, 'RBS_TEST_URL'),
			'module_version' => Option::get($moduleId, 'MODULE_VERSION'),
			'iso' => unserialize(Option::get($moduleId, 'ISO')),
			'cms_version' => 'Bitrix ' . SM_VERSION,
			'language' => 'ru',
		));

		// handler settings
		$RBS_Gateway->setOptions(array(
			'ofd_tax' => $this->getBusinessValue($payment, 'OFD_TAX_SYSTEM') == 0 ? 0 : $this->getBusinessValue($payment, 'OFD_TAX_SYSTEM'),
			'ofd_enabled' => $this->getBusinessValue($payment, 'OFD_RECIEPT')  == 'Y' && DEF_FISCAL ? 1 : 0,
			'ffd_version' => $this->getBusinessValue($payment, 'FFD_VERSION'),
			'ffd_payment_object' => $this->getBusinessValue($payment, 'FFD_PAYMENT_OBJECT'),
			'ffd_payment_object_delivery' => $this->getBusinessValue($payment, 'FFD_PAYMENT_OBJECT_DELIVER'),
			'ffd_payment_method' => $this->getBusinessValue($payment, 'FFD_PAYMENT_METHOD'),
			'test_mode' => $this->getBusinessValue($payment, 'API_TEST_MODE') == 'Y' ? 1 : 0,
			'handler_logging' => $this->getBusinessValue($payment, 'HANDLER_LOGGING') == 'Y' ? 1 : 0,
			'handler_two_stage' => $this->getBusinessValue($payment, 'HANDLER_TWO_STAGE') == 'Y' ? 1 : 0,
		));

		$RBS_Gateway->buildData(array(
			'orderNumber' => $this->getBusinessValue($payment, 'RBS_ORDER_NUMBER') . '_' . $payment->getField('ID'),
		    'amount' => $this->getBusinessValue($payment, 'RBS_ORDER_AMOUNT'),
		    'userName' => $this->getBusinessValue($payment, 'API_LOGIN'),
		    'password' => $this->getBusinessValue($payment, 'API_PASSWORD'),
		    'description' => $this->getOrderDescription($payment),
		));

		$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== "off" ? 'https://' : 'http://';
		$domain_name = $_SERVER['HTTP_HOST'];

		// if(SITE_DIR == '/' || strlen(SITE_DIR) == 0) {
		// 	$site_dir = '/';
		// } else {
		// 	$site_dir = SITE_DIR;
		// 	if(substr($site_dir, 0, 1) != '/') {
		// 	    $site_dir = '/' . $site_dir;
		// 	}
		// 	if(substr($site_dir, -1, 1) != '/') {
		// 	    $site_dir = $site_dir . '/';
		// 	}
		// }

		$RBS_Gateway->buildData(array(
		    'returnUrl' => $protocol . $domain_name . $site_dir  . '/bitrix/tools/sale_ps_result.php' . '?PAYMENT=RBS&ORDER_ID=' . $payment->getField('ORDER_ID') . '&PAYMENT_ID=' . $payment->getField('ID')
		));

		$Order = Order::load($payment->getOrderId());
		$propertyCollection = $Order->getPropertyCollection();

		$phone_key = strlen(Option::get($moduleId, 'OPTION_PHONE')) > 0 ? Option::get($moduleId, 'OPTION_PHONE') : 'PHONE';
		$email_key = strlen(Option::get($moduleId, 'OPTION_EMAIL')) > 0 ? Option::get($moduleId, 'OPTION_EMAIL') : 'EMAIL';
		$fio_key = strlen(Option::get($moduleId, 'OPTION_FIO')) > 0 ? Option::get($moduleId, 'OPTION_EMAIL') : 'FIO';

		$phone = preg_replace('/\D+/', '', $this->getPropertyValueByCode($propertyCollection, $phone_key));

		if(substr($phone, 0, 1) == '7') {
			$phone = '+'.$phone;
		}
		if(substr($phone, 0, 1) == '8') {
		    $phone[0] = '7';
			$phone = '+' . $phone;
		}

		$RBS_Gateway->setOptions(array(
			'customer_name' => $this->getPropertyValueByCode($propertyCollection, $fio_key),
			'customer_email' => $this->getPropertyValueByCode($propertyCollection, $email_key),
			'customer_phone' => $phone,
		));
		if ($RBS_Gateway->ofdEnable() && DEF_FISCAL) {
			$Basket = $Order->getBasket();
			$basketItems = $Basket->getBasketItems();


			$lastIndex = 0;

            /* -- SADOVIKOW correction -- */
            $basketItemsIds = Array();
            $arBasketItemsInfo = Array();
            //AddMessage2Log("--- init ---", "sadovikow");
            foreach ($basketItems as $key => $BasketItem) {
                $basketItemsIds[$key] = $BasketItem->getProductId();
            }

            if(count($basketItemsIds > 0)) {
                $arraySize = count($basketItemsIds);
                $arSort   = Array('DATE_CREATE' => 'DESC');
                $arFilter = Array("ID" => $basketItemsIds, "ACTIVE"=>"Y");
                $navParams = Array("nPageSize"=>$arraySize);
                $arSelect = Array("ID", "IBLOCK_ID", "NAME", "DATE_ACTIVE_FROM", "PROPERTY_KOMITENT", "PROPERTY_TELEFON_KOMITENTA", "PROPERTY_INN_KOMITENTA");
                $dbFields = \CIBlockElement::GetList($arSort, $arFilter, false, $navParams, $arSelect);
                while($dbElement = $dbFields->GetNextElement())
                {
                   $arFields = $dbElement->GetFields();
                   $arBasketItemsInfo[$arFields["ID"]] = $arFields;
                }
                //AddMessage2Log($arBasketItemsInfo, "sadovikow");

            }
            /* --  SADOVIKOW correction -- */

      /* --  SADOVIKOW correction -- */
			foreach ($basketItems as $key => $BasketItem) {
                $BasketItemId = $BasketItem->getProductId();

				$lastIndex = $key + 1;

                if($arBasketItemsInfo[$BasketItemId]["PROPERTY_KOMITENT_VALUE"] != '') {
                    //AddMessage2Log("Komitent order ".$arBasketItemsInfo[$BasketItemId]["PROPERTY_KOMITENT_VALUE"], "sadovikow");
                    // Проведение чека для комиссионых товаров
                    $RBS_Gateway->setPosition(array(
                        'positionId' => $key,
                        'itemCode' => $BasketItem->getProductId(),
                        'name' => $BasketItem->getField('NAME'),
                        'itemAmount' => $BasketItem->getFinalPrice(),
                        'itemPrice' => $BasketItem->getPrice(),
                        'quantity' => array(
                            'value' => $BasketItem->getQuantity(),
                            'measure' => $BasketItem->getField('MEASURE_NAME'),
                        ),
                        'tax' => array(
                            'taxType' =>  $RBS_Gateway->getTaxCode( $BasketItem->getField('VAT_RATE') * 100 ),
                        ),

                        // If need support suplier_info (VDNH Correction)
                        'supplier_info' => array(
                            array(
                                'name' => 'agent_info.type',
                                'value' => '6',
                            ),
                            array(
                                'name' => 'supplier_info.inn',
                                'value' => $arBasketItemsInfo[$BasketItemId]["PROPERTY_INN_KOMITENTA_VALUE"],
                            ),
                            array(
                                'name' => 'supplier_info.name',
                                'value' => $arBasketItemsInfo[$BasketItemId]["PROPERTY_KOMITENT_VALUE"],
                            ),
                            //array(
                            //    'name' => 'supplier_info.phone',
                            //    'value' => $arBasketItemsInfo[$BasketItemId]["PROPERTY_TELEFON_KOMITENTA_VALUE"],
                           // ),
                        )
                    ));
                }
                else {
                    // Обычное проведение товара
                    AddMessage2Log("Not komitent order", "my_module_id");
                    $RBS_Gateway->setPosition(array(
                        'positionId' => $key,
                        'itemCode' => $BasketItem->getProductId(),
                        'name' => $BasketItem->getField('NAME'),
                        'itemAmount' => $BasketItem->getFinalPrice(),
                        'itemPrice' => $BasketItem->getPrice(),
                        'quantity' => array(
                            'value' => $BasketItem->getQuantity(),
                            'measure' => $BasketItem->getField('MEASURE_NAME'),
                        ),
                        'tax' => array(
                            'taxType' =>  $RBS_Gateway->getTaxCode( $BasketItem->getField('VAT_RATE') * 100 ),
                        )
                    ));
                }
        
			}
      /* --  SADOVIKOW correction -- */

			if($Order->getField('PRICE_DELIVERY') > 0) {

				Loader::includeModule('catalog');
				$deliveryInfo = \Bitrix\Sale\Delivery\Services\Manager::getById($Order->getField('DELIVERY_ID'));

				$deliveryVatItem = \CCatalogVat::GetByID($deliveryInfo['VAT_ID'])->Fetch();
				$RBS_Gateway->setOptions(array(
				    'delivery' => true,
				));
				$RBS_Gateway->setPosition(array(
		            'positionId' => $lastIndex + 1,
		            'itemCode' => 'DELIVERY_' . $Order->getField('DELIVERY_ID'),
		            'name' => Loc::getMessage('RBS_FIRLD_DELIVERY'),
		            'itemAmount' => $Order->getField('PRICE_DELIVERY'),
		            'itemPrice' => $Order->getField('PRICE_DELIVERY'),
		            'quantity' => array(
		                'value' => 1,
		                'measure' => Loc::getMessage('RBS_FIELD_MEASURE'),
		            ),
		            'tax' => array(
		                'taxType' => $RBS_Gateway->getTaxCode($deliveryVatItem['RATE']),
		            ),
		        ));
			}
		}

		$gateResponse = $RBS_Gateway->registerOrder();

		$params = array(
	        'rbs_result' => $gateResponse,
	        'payment_link' => $RBS_Gateway->getPaymentLink(),
	        'currency' => $payment->getField('CURRENCY')
	    );
	    $this->setExtraParams($params);

	    return $this->showTemplate($payment, "payment");
	}

	public function processRequest(Payment $payment, Request $request)
	{
		global $APPLICATION;
		$moduleId = 'rbs.payment';
		$result = new PaySystem\ServiceResult();
		$RBS_Gateway = new \Rbs\Payments\Gateway;
		$RBS_Gateway->setOptions(array(
			// module settings
			'gate_url_prod' => Option::get($moduleId, 'RBS_PROD_URL'),
			'gate_url_test' => Option::get($moduleId, 'RBS_TEST_URL'),
			'test_mode' => $this->getBusinessValue($payment, 'API_TEST_MODE') == 'Y' ? 1 : 0,
			'callback_redirect' => $request->get('CALLBACK_REDIRECT') == 1 ? 1 : 0,
		));

		$RBS_Gateway->buildData(array(
		    'userName' => $this->getBusinessValue($payment, 'API_LOGIN'),
		    'password' => $this->getBusinessValue($payment, 'API_PASSWORD'),
		    'orderId' => $request->get('CALLBACK_REDIRECT') == 1 ? $request->get('mdOrder') : $request->get('orderId'),
		));

		$gateResponse = $RBS_Gateway->checkOrder();

		$resultId = explode("_", $gateResponse['orderNumber'] );
        array_pop($resultId);
        $res_payment_id = array_pop($resultId);
        $resultId = implode('_', $resultId);

        $successPayment = true;

        if($resultId != $this->getBusinessValue($payment, 'RBS_ORDER_NUMBER')) {
			$successPayment = false;
		}
        if( $gateResponse['errorCode'] != 0 || ($gateResponse['orderStatus'] != 1 && $gateResponse['orderStatus'] != 2) ) {
        	$successPayment = false;
        }


        if($successPayment && !$payment->isPaid()) {

			$inputJson = self::encode($request->toArray());
			 PaySystem\Logger::addDebugInfo('RBS: request: '.$inputJson);

			$fields = array(
				'PS_INVOICE_ID' => $request->get('orderId'),
				"PS_STATUS_CODE" => $gateResponse['orderStatus'],
				"PS_STATUS_DESCRIPTION" => $gateResponse["cardAuthInfo"]["pan"] . ";" . $gateResponse['cardAuthInfo']["cardholderName"],
				"PS_SUM" => $gateResponse["amount"] / 100,
				"PS_STATUS" => 'Y',
				"PS_CURRENCY" => $gateResponse["currency"],
				"PS_RESPONSE_DATE" => new DateTime()
			);

			$fields["PS_STATUS"] = 'Y';


			 PaySystem\Logger::addDebugInfo(
			 	'RBS: PS_CHANGE_STATUS_PAY='.$this->getBusinessValue($payment, 'PS_CHANGE_STATUS_PAY')
			 );

			$result->setOperationType(PaySystem\ServiceResult::MONEY_COMING);
			// if ($this->getBusinessValue($payment, 'PS_CHANGE_STATUS_PAY') === 'Y') {}

			$result->setPsData($fields);

        	$order = Order::load($payment->getOrderId());

        	// set order status
        	$option_order_status = Option::get($moduleId, 'RESULT_ORDER_STATUS');

        	if($option_order_status != 'FALSE') {
	        	$statuses = array();
				$dbStatus = \CSaleStatus::GetList(Array("SORT" => "ASC"), Array("LID" => LANGUAGE_ID), false, false, Array("ID", "NAME", "SORT"));
				while ($arStatus = $dbStatus->GetNext()) {
				    $statuses[$arStatus["ID"]] = "[" . $arStatus["ID"] . "] " . $arStatus["NAME"];
				}

				if(array_key_exists($option_order_status, $statuses)) {
					$order->setField('STATUS_ID', $option_order_status);
				} else {
					echo '<span style="display:block; font-size:16px; display:block; color:red;padding:20px 0;">ERROR! CANT CHANGE ORDER STATUS</span>';
				}
			}

			// set delivery status
			if($this->getBusinessValue($payment, 'HANDLER_SHIPMENT') == 'Y') {
				$shipmentCollection = $order->getShipmentCollection();
				foreach ($shipmentCollection as $shipment){
				    if (!$shipment->isSystem()) {
		        		$shipment->allowDelivery();
				    }
		    	}
	    	}

		    $order->save();
        } else if(!$successPayment) {
        	$error = Loc::getMessage('RBS_MESSAGE_PAYMENT_ERROR').': '.$gateResponse['orderStatus'];
			$result->addError(new Main\Error($error));
        }

        if($request->get('CALLBACK_REDIRECT') == 1) {
        	return true;
        }

        $returnPage = $this->getBusinessValue($payment, 'RETURN_URL');
        $failPage = $this->getBusinessValue($payment, 'FAIL_URL');

        if(strlen($returnPage) > 4 && $successPayment) {
        	echo "<script>window.location='" .$returnPage."'</script>";
        } else if(strlen($failPage) > 4 && !$successPayment) {
        	echo "<script>window.location='" .$failPage."'</script>";
        } else {
			self::printResultText($payment,$successPayment);
        }


        return $result;
	}

	public function getPaymentIdFromRequest(Request $request)
	{
	    $paymentId = $request->get('PAYMENT_ID');
	    return intval($paymentId);
	}

	public function getCurrencyList()
	{
		return array('RUB');
	}

	public static function getIndicativeFields()
	{
		return array('PAYMENT' => 'RBS');
	}

	static protected function isMyResponseExtended(Request $request, $paySystemId)
	{
		global $APPLICATION;

		$RBS_Gateway = new \Rbs\Payments\Gateway;

		if($request->get('OPERATION_CALLBACK') == 'RBS' && $request->get('CALLBACK_REDIRECT') != 1) {

			if(!$request->get('orderNumber')) {
				$RBS_Gateway->baseLogger('CALLBACK_RETURN', 'CALLBACK', $request->getQueryList(), [],'ERROR PROCESSING CALLBACK');
				return false;
			}

			$arrOrderIds = explode("_", $request->get('orderNumber') );
			array_pop($arrOrderIds);
			$R_PAYMENT_ID = array_pop($arrOrderIds);
			$R_ORDER_ID = implode('_', $arrOrderIds);


			$order = Order::load($R_ORDER_ID);
			if(!$order) {
				$order = Order::loadByAccountNumber($R_ORDER_ID);
			}
			if(!$order) {
				$RBS_Gateway->baseLogger('CALLBACK_RETURN', 'CALLBACK', $request->getQueryList(), [],'ERROR PROCESSING CALLBACK');
				return false;
			}

			LocalRedirect($APPLICATION->GetCurUri("ORDER_ID=" . $R_ORDER_ID . "&PAYMENT_ID=" . $R_PAYMENT_ID . "&CALLBACK_REDIRECT=1"));
	    	return true;
		}


		if(!$request->get('ORDER_ID')) {
			return false;
		}
		$order = Order::load($request->get('ORDER_ID'));
		if(!$order) {
			$order = Order::loadByAccountNumber($request->get('ORDER_ID'));
		}
		if(!$order) {
			echo Loc::getMessage('RBS_MESSAGE_ERROR_BAD_ORDER');
			return false;
		}

		$paymentIds = $order->getPaymentSystemId();
		return in_array($paySystemId, $paymentIds);
	}

	private function getPropertyValueByCode($propertyCollection, $code) {
		$property = '';
		foreach ($propertyCollection as $property)
	    {
	        if($property->getField('CODE') == $code)
	            return $property->getValue();
	    }
	}


	/**
	 * @return array
	 */
	protected function getUrlList()
	{
		return array(

		);
	}
	/**
	 * @return array
	 */
	public function getProps()
	{
		$data = array();

		return $data;
	}
	/**
	 * @param Payment $payment
	 * @param Request $request
	 * @return bool
	 */
	public function initPrePayment(Payment $payment = null, Request $request)
	{
		return true;
	}
	/**
	 * @param array $orderData
	 */
	public function payOrder($orderData = array())
	{

	}
	/**
	 * @param array $orderData
	 * @return bool|string
	 */
	public function BasketButtonAction($orderData = array())
	{
		return true;
	}
	/**
	 * @param array $orderData
	 */
	public function setOrderConfig($orderData = array())
	{
		if ($orderData)
			$this->prePaymentSetting = array_merge($this->prePaymentSetting, $orderData);
	}
	protected function getOrderDescription(Payment $payment)
	{
		/** @var PaymentCollection $collection */
		$collection = $payment->getCollection();
		$order = $collection->getOrder();
		$userEmail = $order->getPropertyCollection()->getUserEmail();

		$description =  str_replace(
			array(
				'#PAYMENT_NUMBER#',
				'#ORDER_NUMBER#',
				'#PAYMENT_ID#',
				'#ORDER_ID#',
				'#USER_EMAIL#'
			),
			array(
				$payment->getField('ACCOUNT_NUMBER'),
				$order->getField('ACCOUNT_NUMBER'),
				$payment->getId(),
				$order->getId(),
				($userEmail) ? $userEmail->getValue() : ''
			),
			$this->getBusinessValue($payment, 'RBS_ORDER_DESCRIPTION')
		);

		$description = Main\Text\Encoding::convertEncoding($description, LANG_CHARSET, "UTF-8");
		return $description;
	}
	private static function encode(array $data)
	{
		return Main\Web\Json::encode($data, JSON_UNESCAPED_UNICODE);
	}
	protected function printResultText($payment,$successPayment)
	{
		global $APPLICATION;
		echo '<div class="rbs-center" style="width: 100%;display: flex;align-items: center;align-content: center;justify-content: center;height: 100%;position: fixed;"><div style="display: block;background:#fff;padding: 10px 10px; margin-left:-10px;border-radius: 6px;max-width: 400px; border: 1px solid #e7e7e7;">';
		echo '<div class="rbs-result-message" style="margin:5px; text-align:center;padding:10px 20px; 0"><span style=" font-family: arial;font-size: 16px;">';

	        if($successPayment) {
	        	$APPLICATION->SetTitle(Loc::getMessage('RBS_PAYMENT_MESSAGE_THANKS'));
	        	echo Loc::getMessage('RBS_PAYMENT_MESSAGE_THANKS_DESCRIPTION') . $this->getBusinessValue($payment, 'RBS_ORDER_NUMBER');
	        } else {
	        	$APPLICATION->SetTitle(Loc::getMessage('RBS_PAYMENT_MESSAGE_ERROR'));
	        	echo Loc::getMessage('RBS_PAYMENT_MESSAGE_ERROR') . ' #' . $this->getBusinessValue($payment, 'RBS_ORDER_NUMBER');
	        }
		echo '<div style=" display: block; margin:10px 10px 0;"><a style="font-family: arial;font-size: 16px;color: #0e6ce0;" href="/">' .  Loc::getMessage('RBS_RETURN_LINK'). '</a></div>';
	        echo "</span></div>";
		echo "</div></div>";
	}
	public function isRefundableExtended(){}
	public function confirm(Payment $payment){}
	public function cancel(Payment $payment){}
	public function refund(Payment $payment, $refundableSum){}
	public function sendResponse(PaySystem\ServiceResult $result, Request $request){}

}
