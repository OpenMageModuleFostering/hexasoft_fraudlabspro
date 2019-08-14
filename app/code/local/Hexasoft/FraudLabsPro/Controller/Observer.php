<?php
class Hexasoft_FraudLabsPro_Controller_Observer{

	public function sendRequestToFraudLabsProNonObserver($order_id){
		if(!Mage::getStoreConfig('fraudlabspro/basic_settings/active')){
			return true;
		}

		$order = Mage::getModel('sales/order')->load($order_id);

		if($order->getfraudlabspro_response()){
			Mage::getSingleton('adminhtml/session')->addError(Mage::helper('fraudlabspro')->__('Request already submitted to FraudLabs Pro.'));
			return true;
		}

		return $this->processSendRequestToFraudLabsPro($order);
	}

	public function sendRequestToFraudLabsPro($observer){

		if(!Mage::getStoreConfig('fraudlabspro/basic_settings/active')){
			return true;
		}

		$event = $observer->getEvent();
		$order = $event->getOrder();

		if($order->getfraudlabspro_response()){
			return true;
		}

		return $this->processSendRequestToFraudLabsPro($order);
	}

	public function processSendRequestToFraudLabsPro($order){
		$orderId = $order->getIncrementId();

		if(empty($orderId))
			return true;

		$data = unserialize($order->getfraudlabspro_response());

		if($data)
			return true;

		if(isset($_SERVER['DEV_MODE'])) $_SERVER['REMOTE_ADDR'] = '175.143.8.154';

		$apiKey = Mage::getStoreConfig('fraudlabspro/basic_settings/api_key');
		$reviewStatus = Mage::getStoreConfig('fraudlabspro/basic_settings/review_status');
		$rejectStatus = Mage::getStoreConfig('fraudlabspro/basic_settings/reject_status');

		$billingAddress = $order->getBillingAddress();

		$ip = $_SERVER['REMOTE_ADDR'];

		if(isset($_SERVER['HTTP_CF_CONNECTING_IP']) && filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)){
			$ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
		}

		if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$xip = trim(current(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])));

			if (filter_var($xip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
				$ip = $xip;
			}
		}

		$payment_mode = $order->getPayment()->getMethod();
		if($payment_mode === 'ccsave'){
			$paymentMode = 'creditcard';
		}elseif($payment_mode === 'cashondelivery'){
			$paymentMode = 'cod';
		}elseif($payment_mode === 'paypal_standard' || $payment_mode === 'paypal_express'){
			$paymentMode = 'paypal';
		}else{
			$paymentMode = $payment_mode;
		}

		$queries = array(
			'format'			=> 'json',
			'key'				=> $apiKey,
			'ip'				=> $ip,
			'first_name'		=> $order->getCustomerFirstname(),
			'last_name'			=> $order->getCustomerLastname(),
			'bill_addr'			=> trim($billingAddress->getStreet(1) . ' ' . $billingAddress->getStreet(2)),
			'bill_city'			=> $billingAddress->getCity(),
			'bill_state'		=> $billingAddress->getRegion(),
			'bill_country'		=> $billingAddress->getCountryId(),
			'bill_zip_code'		=> $billingAddress->getPostcode(),
			'email_domain'		=> substr($order->getCustomerEmail(), strpos($order->getCustomerEmail(), '@')+1),
			'email_hash'		=> $this->_hash($order->getCustomerEmail()),
			'email'				=> $order->getCustomerEmail(),
			'user_phone'		=> $billingAddress->getTelephone(),
			'amount'			=> $order->getBaseGrandTotal(),
			'quantity'			=> count($order->getAllItems()),
			'currency'			=> Mage::app()->getStore()->getCurrentCurrencyCode(),
			'user_order_id'		=> $orderId,
			'magento_order_id'	=> $order->getEntityId(),
			'payment_mode'		=> $paymentMode,
			'flp_checksum'		=> Mage::getModel('core/cookie')->get('flp_checksum'),
			'source'			=> 'magento',
			'source_version'	=> '1.2.4',
		);

		$shippingAddress = $order->getShippingAddress();

		if($shippingAddress){
			$queries['ship_first_name']	= $shippingAddress->getFirstname();
			$queries['ship_last_name']	= $shippingAddress->getLastname();
			$queries['ship_addr']		= trim($shippingAddress->getStreet(1) . ' ' . $shippingAddress->getStreet(2));
			$queries['ship_city']		= $shippingAddress->getCity();
			$queries['ship_state']		= $shippingAddress->getRegion();
			$queries['ship_zip_code']	= $shippingAddress->getPostcode();
			$queries['ship_country']	= $shippingAddress->getCountryId();
		}

		$response = $this->http('https://api.fraudlabspro.com/v1/order/screen?' . http_build_query($queries));

		if(is_null($result = json_decode($response, true)) === TRUE)
			return false;

		$result['ip_address'] = $queries['ip'];
		$result['api_key'] = $apiKey;

		$order->setfraudlabspro_response(serialize($result))->save();

		if($result['fraudlabspro_status'] == 'REVIEW'){
			switch($reviewStatus){
				case 'pending':
					$order->setState(Mage_Sales_Model_Order::STATE_NEW, true)->save();
					break;

				case 'processing':
					$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true)->save();
					break;

				case 'complete':
					$order->setState(Mage_Sales_Model_Order::STATE_COMPLETE, true)->save();
					break;

				case 'closed':
					$order->setState(Mage_Sales_Model_Order::STATE_CLOSED, true)->save();
					break;

				case 'canceled':
					if($order->canCancel()) {
						$order->cancel()->save();
					}
					break;

				case 'holded':
					$order->setHoldBeforeState($order->getState());
					$order->setHoldBeforeStatus($order->getStatus());
					$order->setState(Mage_Sales_Model_Order::STATE_HOLDED, true)->save();
					break;

			}
		}

		if($result['fraudlabspro_status'] == 'REJECT'){
			switch($rejectStatus){
				case 'pending':
					$order->setState(Mage_Sales_Model_Order::STATE_NEW, true)->save();
					break;

				case 'processing':
					$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true)->save();
					break;

				case 'complete':
					$order->setState(Mage_Sales_Model_Order::STATE_COMPLETE, true)->save();
					break;

				case 'closed':
					$order->setState(Mage_Sales_Model_Order::STATE_CLOSED, true)->save();
					break;

				case 'canceled':
					if($order->canCancel()) {
						$order->cancel()->save();
					}
					break;

				case 'holded':
					$order->setHoldBeforeState($order->getState());
					$order->setHoldBeforeStatus($order->getStatus());
					$order->setState(Mage_Sales_Model_Order::STATE_HOLDED, true)->save();
					break;

			}
		}

		Mage::getSingleton('adminhtml/session')->addSuccess(Mage::helper('fraudlabspro')->__('FraudLabs Pro Request sent.'));
		return true;
	}

	private function http($url){
		$ch = curl_init();

		curl_setopt($ch, CURLOPT_FAILONERROR, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_ENCODING , 'gzip, deflate');
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);

		$result = curl_exec($ch);

		if(!curl_errno($ch)) return $result;

		curl_close($ch);

		return false;
	}

	private function _hash($s, $prefix='fraudlabspro_'){
		$hash = $prefix . $s;
		for($i=0; $i<65536; $i++) $hash = sha1($prefix . $hash);

		return $hash;
	}
}