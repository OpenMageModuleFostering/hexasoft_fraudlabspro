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
		if(isset($_SERVER['DEV_MODE'])) $_SERVER['REMOTE_ADDR'] = '175.143.8.154';

		$apiKey = Mage::getStoreConfig('fraudlabspro/basic_settings/api_key');

		$billingAddress = $order->getBillingAddress();

		$queries = array(
			'format'=>'json',
			'key'=>$apiKey,
			'ip'=>$_SERVER['REMOTE_ADDR'],
			'bill_city'=>$billingAddress->getCity(),
			'bill_state'=>$billingAddress->getRegion(),
			'bill_country'=>$billingAddress->getCountryId(),
			'bill_zip_code'=>$billingAddress->getPostcode(),
			'email_domain'=>substr($order->getCustomerEmail(), strpos($order->getCustomerEmail(), '@')+1),
			'email_hash'=>$this->_hash($order->getCustomerEmail()),
			'phone'=>$billingAddress->getTelephone(),
			'amount'=>$order->getBaseGrandTotal(),
			'quantity'=>count($order->getAllItems()),
			'currency'=>Mage::app()->getStore()->getCurrentCurrencyCode(),
			'user_order_id'=>$order->getIncrementId(),
			'magento_order_id'=>$order->getEntityId(),
		);

		$shippingAddress = $order->getShippingAddress();

		if($shippingAddress){
			$queries['ship_addr'] = trim($shippingAddress->getStreet(1) . ' ' . $shippingAddress->getStreet(2));
			$queries['ship_city'] = $shippingAddress->getCity();
			$queries['ship_state'] = $shippingAddress->getRegion();
			$queries['ship_zip_code'] = $shippingAddress->getPostcode();
			$queries['ship_country'] = $shippingAddress->getCountryId();
		}

		$query = '';
		foreach($queries as $key=>$value){
			$query .= $key . '=' . rawurlencode($value) . '&';
		}

		for($i=0; $i<3; $i++){
			$response = $this->_get('https://api.fraudlabspro.com/v1/order/screen?' . $query);

			if(is_null($result = json_decode($response, true)) === FALSE) break;
		}

		if(!$result) return false;

		$result['ip_address'] = $queries['ip'];
		$result['api_key'] = $apiKey;

		$order->setfraudlabspro_response(serialize($result))->save();

		Mage::getSingleton('adminhtml/session')->addSuccess(Mage::helper('fraudlabspro')->__('FraudLabs Pro Request sent.'));
		return true;
	}

	private function _get($url){
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