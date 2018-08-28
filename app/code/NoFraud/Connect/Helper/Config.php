<?php
 
namespace NoFraud\Connect\Helper;
 
class Config extends \Magento\Framework\App\Helper\AbstractHelper
{
    const GENERAL = 'nofraud_connect/general';
    const GENERAL_ENABLED = self::GENERAL . '/enabled';
    const GENERAL_API_TOKEN = self::GENERAL . '/api_token';
    const GENERAL_SANDBOX_MODE = self::GENERAL . '/sandbox_enabled';
    const GENERAL_SCREENED_ORDER_STATUS = self::GENERAL . '/screened_order_status';
    const GENERAL_SCREENED_PAYMENT_METHODS = self::GENERAL . '/screened_payment_methods';

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

    public function paymentMethodIsIgnored( $payment )
    {
        $paymentMethod = $payment->getMethod();
        $screenedPaymentMethods = $this->getScreenedPaymentMethods();

        if ( !in_array( $paymentMethod, $screenedPaymentMethods ) ){
            $orderId = $payment->getOrder()->getIncrementId(); //LOGGING
            $this->logger->info(
                "Ignoring Order {$orderId}: payment method is '{$paymentMethod}'; " .
                "only screening orders with the following payment methods: " .
                implode(', ', $screenedPaymentMethods)
            ); //LOGGING
            return true;
        }

        return false;
    }

    public function getScreenedPaymentMethods()
    {
        $commaSeparatedMethodCodes = $this->scopeConfig->getValue(self::GENERAL_SCREENED_PAYMENT_METHODS);
        $arrayOfMethodCodes = explode(',', $commaSeparatedMethodCodes);

        return $arrayOfMethodCodes;
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
        if ( !in_array($key, $this->orderStatusesKeys) ){
            return;
        }

        $path = self::ORDER_STATUSES . '/' . $key; 

        return $this->scopeConfig->getValue( $path );
    }
}
