<?php
 
namespace NoFraud\Connect\Helper;
 
class Config extends \Magento\Framework\App\Helper\AbstractHelper
{
    const GENERAL = 'nofraud_connect/general';
    const ORDER_STATUSES = 'nofraud_connect/order_statuses';

    const ORDER_STATUSES_PASS = self::ORDER_STATUSES . '/pass';
    const ORDER_STATUSES_PASS = self::ORDER_STATUSES . '/review';
    const GENERAL_ENABLED = self::GENERAL . '/enabled';
    const GENERAL_API_TOKEN = self::GENERAL . '/api_token';
    const GENERAL_SANDBOX_MODE = self::GENERAL . '/sandbox_enabled';
    const GENERAL_SCREENED_ORDER_STATUS = self::GENERAL . '/screened_order_status';
    const GENERAL_AUTO_CANCEL = self::GENERAL . '/auto_cancel';

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

        if ( empty($screenedOrderStatus) ){
            return false;
        }

        $orderStatus = $order->getStatus();
       
        if ( $orderStatus != $screenedOrderStatus ){
            $orderId = $order->getIncrementId(); //LOGGING
            $this->logger->info("Ignoring Order {$orderId}: status is '{$orderStatus}'; only screening orders with status '{$screenedOrderStatus}'."); //LOGGING
            return true;
        }

        return false;
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

    public function getAutoCancel()
    {
        return $this->scopeConfig->getValue(self::GENERAL_AUTO_CANCEL);
    }

    public function getOrderStatusPass()
    {
        return $this->scopeConfig->getValue(self::ORDER_STATUSES_PASS);
    }

    public function getOrderStatusReview()
    {
        return $this->scopeConfig->getValue(self::ORDER_STATUSES_REVIEW);
    }

    public function getCustomStatusConfig($statusName)
    {
        if ( !in_array($statusName, $this->orderStatusesKeys) ){
            return;
        }

        $path = self::ORDER_STATUSES . '/' . $statusName; 

        return $this->scopeConfig->getValue($statusName);
    }
}
