<?php
 
namespace NoFraud\Connect\Helper;
 
class Config extends \Magento\Framework\App\Helper\AbstractHelper
{
    const GENERAL = 'nofraud_connect/general';
    const ORDER_STATUSES = 'nofraud_connect/order_statuses';

    const ORDER_STATUSES_PASS = self::ORDER_STATUSES . '/pass';
    const ORDER_STATUSES_REVIEW = self::ORDER_STATUSES . '/review';
    const GENERAL_ENABLED = self::GENERAL . '/enabled';
    const GENERAL_API_TOKEN = self::GENERAL . '/api_token';
    const GENERAL_SANDBOX_MODE = self::GENERAL . '/sandbox_enabled';
    const GENERAL_SCREENED_ORDER_STATUS = self::GENERAL . '/screened_order_status';
    const GENERAL_SCREENED_PAYMENT_METHODS = self::GENERAL . '/screened_payment_methods';
    const GENERAL_AUTO_CANCEL = self::GENERAL . '/auto_cancel';

    protected $logger;

    protected $orderStatusesKeys = [
        'pass',
        'review',
        'fail',
        'error',
    ];

    public function __construct(
        \Magento\Framework\App\Helper\Context $context,        
        \NoFraud\Connect\Logger\Logger $logger
    ) {
        parent::__construct($context);
        $this->logger = $logger;
    }

    public function getEnabled($storeId = null)
    {
        if (is_null($storeId)) {
            return $this->scopeConfig->getValue(self::GENERAL_ENABLED);
        } else {
            return $this->scopeConfig->getValue(self::GENERAL_ENABLED, 'store', $storeId);
        }
    }

    public function getApiToken($storeId = null)
    {
        if (is_null($storeId)) {
            return $this->scopeConfig->getValue(self::GENERAL_API_TOKEN);
        } else {
            return $this->scopeConfig->getValue(self::GENERAL_API_TOKEN, 'store', $storeId);
        }
    }

    public function getSandboxMode($storeId = null)
    {
        if (is_null($storeId)) {
            return $this->scopeConfig->getValue(self::GENERAL_SANDBOX_MODE);
        } else {
            return $this->scopeConfig->getValue(self::GENERAL_SANDBOX_MODE, 'store', $storeId);
        }
    }

    public function getScreenedOrderStatus($storeId = null)
    {
        if (is_null($storeId)) {
             return $this->scopeConfig->getValue(self::GENERAL_SCREENED_ORDER_STATUS);
        } else {
            return $this->scopeConfig->getValue(self::GENERAL_SCREENED_ORDER_STATUS, 'store', $storeId);
        }
    }

    public function getAutoCancel($storeId = null)
    {
        if (is_null($storeId)) {
            return $this->scopeConfig->getValue(self::GENERAL_AUTO_CANCEL);
        } else {
            return $this->scopeConfig->getValue(self::GENERAL_AUTO_CANCEL, 'store', $storeId);
        }
    }

    public function getOrderStatusPass($storeId = null)
    {
        if (is_null($storeId)) {
            return $this->scopeConfig->getValue(self::ORDER_STATUSES_PASS);
        } else {
            return $this->scopeConfig->getValue(self::ORDER_STATUSES_PASS, 'store', $storeId);
        }
    }

    public function getOrderStatusReview($storeId = null)
    {
        if (is_null($storeId)) {
            return $this->scopeConfig->getValue(self::ORDER_STATUSES_REVIEW);
        } else {
            return $this->scopeConfig->getValue(self::ORDER_STATUSES_REVIEW, 'store', $storeId);
        }
    }

    public function getCustomStatusConfig($statusName, $storeId = null)
    {
        if (!in_array($statusName,$this->orderStatusesKeys)) {
            return;
        }

        $path = self::ORDER_STATUSES . '/' . $statusName; 

        if (is_null($storeId)) {
             return $this->scopeConfig->getValue($path);
        } else {
             return $this->scopeConfig->getValue($path, 'store', $storeId);
        }
    }

    public function paymentMethodIsIgnored($method, $storeId = null)
    {
        if (is_null($storeId)) {
             $methods = $this->scopeConfig->getValue(self::GENERAL_SCREENED_PAYMENT_METHODS);
        } else {
             $methods = $this->scopeConfig->getValue(self::GENERAL_SCREENED_PAYMENT_METHODS, 'store', $storeId);
        }

        if (empty($methods)) {
            return false;
        }
        $methods = explode(',',$methods);
        if (in_array($method,$methods)) {
            return false;
        }
        return true;
    }

    public function orderStatusIsIgnored($order, $storeId = null)
    {
        $storeId = $order->getStoreId();
        $screenedOrderStatus = $this->getScreenedOrderStatus($storeId);
        if (empty($screenedOrderStatus)) {
            return false;
        }

        $orderStatus = $order->getStatus();
        if ($orderStatus != $screenedOrderStatus) {
            $orderId = $order->getIncrementId();
            $this->logger->info("Ignoring Order $orderId: status is '$orderStatus;' only screening orders with status $screenedOrderStatus.");
            return true;
        }
        return false;
    }
}
