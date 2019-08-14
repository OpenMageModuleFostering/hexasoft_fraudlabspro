<?php
class Hexasoft_FraudLabsPro_Block_Agent extends Mage_Core_Block_Template
{
    public function isActive()
    {
        if(!Mage::getStoreConfig('fraudlabspro/basic_settings/active')){
			return false;
		}

        return true;
    }
}