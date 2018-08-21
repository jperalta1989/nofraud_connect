<?php
 
namespace NoFraud\Connect\Helper;
 
class Config extends \Magento\Framework\App\Helper\AbstractHelper
{
    const GENERAL = 'nofraud_connect/general';
    const GENERAL_ENABLED = self::GENERAL . '/enabled';
    const GENERAL_API_TOKEN = self::GENERAL . '/api_token';
    const GENERAL_SANDBOX_MODE = self::GENERAL . '/sandbox_enabled';
    const GENERAL_SCREENED_ORDER_STATUS = self::GENERAL . '/screened_order_status';

    const ORDER_STATUSES = 'nofraud_connect/order_statuses';
    protected $orderStatusesKeys = [
        'pass',
        'review',
        'fail',
        'error',
    ];


    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \NoFraud\Connect\Logger\Logger $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    public function noFraudIsDisabled()
    {
        return !$this->getEnabled();
    }

    public function sandboxIsEnabled()
    {
        return $this->getSandboxMode();
    }

    public function orderStatusIsIgnored( $order )
    {
        $screenedOrderStatus = $this->getScreenedOrderStatus();
        $this->logger->info("Set to screen only '{$screenedOrderStatus}' orders..."); //DEBUG

        if ( empty($screenedOrderStatus) ){
            return false;
        }

        $orderStatus = $order->getStatus();
        $this->logger->info("Order current status is '{$orderStatus}'..."); //DEBUG
       
        return $orderStatus != $screenedOrderStatus;
    }

    public function getApiToken()
    {
        return $this->scopeConfig->getValue(self::GENERAL_API_TOKEN);
    }

    public function getSandboxMode()
    {
        return $this->scopeConfig->getValue(self::GENERAL_SANDBOX_MODE);
    }

    public function getEnabled()
    {
        return $this->scopeConfig->getValue(self::GENERAL_ENABLED);
    }

    public function getScreenedOrderStatus()
    {
        return $this->scopeConfig->getValue(self::GENERAL_SCREENED_ORDER_STATUS);
    }

    public function getCustomStatusConfig( $key )
    {
        $this->logger->info("hit configHelper->getCustomStatusConfig( {$key} )"); //DEBUG
        $this->logger->info( print_r($this->orderStatusesKeys,true) ); //DEBUG
        if ( !in_array($key, $this->orderStatusesKeys) ){
            return;
        }

        $path = self::ORDER_STATUSES . '/' . $key; 

        $this->logger->info("fetching value from {$path}..."); //DEBUG

        return $this->scopeConfig->getValue( $path );
    }
}
