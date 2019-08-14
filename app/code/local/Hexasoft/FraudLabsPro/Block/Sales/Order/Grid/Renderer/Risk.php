<?php
class Hexasoft_FraudLabsPro_Block_Sales_Order_Grid_Renderer_Risk extends Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Abstract{
    public function render(Varien_Object $row){
		$out = '';

		$order = Mage::getModel('sales/order')->load($row->getId());
        $result = $order->getfraudlabspro_response();

		if(!$result){
			$out = Mage::helper('fraudlabspro')->__('-');
		}else{
			$data = unserialize($result);
			$out .= $data['fraudlabspro_status'];
		}
		return $out;
    }
}
