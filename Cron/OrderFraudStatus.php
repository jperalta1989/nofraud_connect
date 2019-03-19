<?php

namespace NoFraud\Connect\Cron;

class OrderFraudStatus
{
    const ORDER_REQUEST = 'status';
    const REQUEST_TYPE  = 'GET';

    private $orders;
    private $dataHelper;
    private $configHelper;

    public function __construct(
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orders,
        \NoFraud\Connect\Api\RequestHandler $requestHandler,
        \NoFraud\Connect\Helper\Config $configHelper,
        \NoFraud\Connect\Helper\Data $dataHelper
    ) {
        $this->orders = $orders;
        $this->requestHandler = $requestHandler;
        $this->configHelper = $configHelper;
        $this->dataHelper = $dataHelper;
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
        $apiUrl = $this->buildApiUrl();
        foreach ($magentoOrders as $order) {
            $orderSpecificApiUrl = $apiUrl.'/'.$order['increment_id'];
            $response = $this->requestHandler->send(null,$orderSpecificApiUrl,self::REQUEST_TYPE);
            $noFraudOrderStatus = $response['http']['response']['body'];

            switch ($noFraudOrderStatus['decision']) {
                case 'pass':
                    if (isset($this->configHelper->getOrderStatusPass())) {
                        $order->setStatus($this->configHelper->getOrderStatusPass());
                        $order->save($order->getEntityId());
                    }
                    break;
                case 'fail':
                    $this->dataHelper->handleAutoCancel($noFraudOrderStatus,$order);
                    break;
                case 'review':
                    break;
            }
        }
    }

    public function buildApiUrl()
    {
        $apiBaseUrl = $this->configHelper->getSandboxMode() ?
            $this->requestHandler::SANDBOX_URL          :
            $this->requestHandler::PRODUCTION_URL       ;

        $orderRequest = self::ORDER_REQUEST;
        $token = $this->configHelper->getApiToken();

        $apiUrl = $apiBaseUrl.$orderRequest.'/'.$token;

        return $apiUrl;
    }
}
