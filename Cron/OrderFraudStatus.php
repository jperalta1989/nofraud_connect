<?php

namespace NoFraud\Connect\Cron;

class OrderFraudStatus
{
    const ORDER_REQUEST = 'status';
    const REQUEST_TYPE  = 'GET';

    private $orders;
    private $configHelper;
    private $apiUrl;
    private $orderProcessor;

    public function __construct(
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orders,
        \NoFraud\Connect\Api\RequestHandler $requestHandler,
        \NoFraud\Connect\Helper\Config $configHelper,
        \NoFraud\Connect\Api $apiUrl,
        \NoFraud\Connect\Order\Processor $orderProcessor
    ) {
        $this->orders = $orders;
        $this->requestHandler = $requestHandler;
        $this->configHelper = $configHelper;
        $this->apiUrl = $apiUrl;
        $this->orderProcessor = $orderProcessor;
    }

    public function execute() 
    {
        $magentoOrders = $this->readMagentoOrders();
        $this->askNoFraudForOrderStatusAndUpdateMagentoOrder($magentoOrders);
    }

    public function readMagentoOrders()
    {
        $magentoOrders = $this->orders->create()
            ->addFieldToSelect('status')
            ->addFieldToSelect('increment_id')
            ->addFieldToSelect('entity_id')
            ->setOrder('status', 'desc');

        $select = $magentoOrders->getSelect()
            ->where('status = \''.$this->configHelper->getOrderStatusReview().'\'');

        return $magentoOrders;
    }

    public function askNoFraudForOrderStatusAndUpdateMagentoOrder($magentoOrders) 
    {
        $apiUrl = $this->apiUrl->buildOrderApiUrl(self::ORDER_REQUEST,$this->configHelper->getApiToken());
        foreach ($magentoOrders as $order) {
            $orderSpecificApiUrl = $apiUrl.'/'.$order['increment_id'];
            $response = $this->requestHandler->send(null,$orderSpecificApiUrl,self::REQUEST_TYPE);
            $noFraudOrderStatus = $response['http']['response']['body'];

            $this->orderProcessor->updateMagentoOrderStatusFromNoFraudResult($noFraudOrderStatus, $order);
        }
    }
}
