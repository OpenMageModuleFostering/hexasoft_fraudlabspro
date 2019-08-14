<?php
$installer = $this;
$installer->startSetup();
$conn = $installer->getConnection();
$conn->addColumn($installer->getTable('sales_flat_order'), 'fraudlabspro_response', 'text');
$installer->endSetup();
?>