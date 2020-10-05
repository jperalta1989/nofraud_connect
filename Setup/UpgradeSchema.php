<?php
namespace NoFraud\Connect\Setup;

use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\ModuleContextInterface;

class UpgradeSchema implements UpgradeSchemaInterface
{
    public function upgrade( SchemaSetupInterface $setup, ModuleContextInterface $context ) {
        $installer = $setup;

        $installer->startSetup();

        if(version_compare($context->getVersion(), '0.3.0', '<')) {
            if ($installer->tableExists('sales_order')) {
                $installer->getConnection()
                    ->addColumn(
                        $installer->getTable('sales_order'),
                        'nofraud_status',
                        [
                            'type' => \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                            'length' => 255,
                            'nullable' => false,
                            'comment' => 'NoFraud screened order status'
                        ]
                    );
                $installer->getConnection()
                    ->addColumn(
                        $installer->getTable('sales_order'),
                        'nofraud_transaction_id',
                        [
                            'type' => \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                            'length' => 255,
                            'nullable' => false,
                            'comment' => 'NoFraud screened order transaction id'
                        ]
                    );
            }
            if ($installer->tableExists('sales_order_grid')) {
                $installer->getConnection()
                    ->addColumn(
                        $installer->getTable('sales_order_grid'),
                        'nofraud_status',
                        [
                            'type' => \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                            'length' => 255,
                            'nullable' => false,
                            'comment' => 'NoFraud screened order status'
                        ]
                    );
                $installer->getConnection()
                    ->addColumn(
                        $installer->getTable('sales_order_grid'),
                        'nofraud_transaction_id',
                        [
                            'type' => \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                            'length' => 255,
                            'nullable' => false,
                            'comment' => 'NoFraud screened order transaction id'
                        ]
                    );
            }
        }

        $installer->endSetup();
    }
}
