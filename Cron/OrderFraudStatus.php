<?php

namespace NoFraud\Connect\Cron;

class OrderFraudStatus
{
    const ORDER_REQUEST = 'status';
    const REQUEST_TYPE  = 'GET';

    private $orders;
    private $storeManager;
    private $requestHandler;
    private $configHelper;
    private $apiUrl;
    private $orderProcessor;

    public function __construct(
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orders,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \NoFraud\Connect\Api\RequestHandler $requestHandler,
        \NoFraud\Connect\Helper\Config $configHelper,
        \NoFraud\Connect\Api\ApiUrl $apiUrl,
        \NoFraud\Connect\Order\Processor $orderProcessor
    ) {
        $this->orders = $orders;
        $this->storeManager = $storeManager;
        $this->requestHandler = $requestHandler;
        $this->configHelper = $configHelper;
        $this->apiUrl = $apiUrl;
        $this->orderProcessor = $orderProcessor;
    }

    public function execute() 
    {
        $storeList = $this->storeManager->getStores();
        foreach ($storeList as $store) {
            $storeId = $store->getId();
            if (!$this->configHelper->getEnabled($storeId)) {
                return;
            }
            $orders = $this->readOrders($storeId);
            $this->updateOrdersFromNoFraudApiResult($orders, $storeId);
        }
    }

    public function readOrders($storeId)
    {
        $orders = $this->orders->create()
            ->addFieldToSelect('status')
            ->addFieldToSelect('increment_id')
            ->addFieldToSelect('entity_id')
            ->setOrder('status', 'desc');

        $select = $orders->getSelect()
            ->where('store_id = ' .$storeId)
            ->where('status = \'' . $this->configHelper->getOrderStatusReview($storeId) . '\'');

        return $orders;
    }

    public function updateOrdersFromNoFraudApiResult($orders, $storeId) 
    {
        $apiUrl = $this->apiUrl->buildOrderApiUrl(self::ORDER_REQUEST, $this->configHelper->getApiToken($storeId));
        foreach ($orders as $order) {
            try {
                $orderSpecificApiUrl = $apiUrl.'/'.$order['increment_id'];
                $response = $this->requestHandler->send(null, $orderSpecificApiUrl, self::REQUEST_TYPE);

                if (isset($response['http']['response']['body'])) {
                    $newStatus = $this->orderProcessor->getCustomOrderStatus($response['http']['response'], $storeId);
                    $this->orderProcessor->updateOrderStatusFromNoFraudResult($newStatus, $order);

	            $order->save();

                    if ($this->configHelper->getAutoCancel($storeId) && isset($resultMap['http']['response']['body']['decision'])) {
                        $this->orderProcessor->handleAutoCancel($order, $resultMap['http']['response']['body']['decision']);
                    }
                }
            } catch (\Exception $exception) {
                $this->logger->logFailure($order, $exception);
            }
        }
    }
}
