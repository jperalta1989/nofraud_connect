<?php

namespace NoFraud\Connect\Setup;

class InstallSchema implements \Magento\Framework\Setup\InstallSchemaInterface
{
    public function install(\Magento\Framework\Setup\SchemaSetupInterface $setup, \Magento\Framework\Setup\ModuleContextInterface $context)
    {
        $installer = $setup;
        $installer->startSetup();
        if ($installer->tableExists('sales_order')) {
            $installer->getConnection()
            ->addColumn(
                $installer->getTable('sales_order'),
                'nofraud_screened',
                [
                    'type' => \Magento\Framework\DB\Ddl\Table::TYPE_BOOLEAN,
                    'nullable' => false, 
                    'default' => 0,
                    'comment' => 'NoFraud screened order flag'
                ]
            );
        }
        if ($installer->tableExists('sales_order_grid')) {
            $installer->getConnection()
            ->addColumn(
                $installer->getTable('sales_order_grid'),
                'nofraud_screened',
                [
                    'type' => \Magento\Framework\DB\Ddl\Table::TYPE_BOOLEAN,
                    'nullable' => false, 
                    'default' => 0,
                    'comment' => 'NoFraud screened order flag'
                ]
            );
        }
        $installer->endSetup();
    }
}
