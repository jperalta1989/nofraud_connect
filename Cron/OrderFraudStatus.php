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
        $this->_orders = $orders;
        $this->_requestHandler = $requestHandler;
        $this->_configHelper = $configHelper;
        $this->_dataHelper = $dataHelper;
    }

    public function execute() 
    {
        $magentoOrders = $this->readMagentoOrders();
        $this->askNoFraudForOrderStatusAndUpdateMagentoOrder($magentoOrders);
    }

    public function readMagentoOrders()
    {
        $magentoOrders = $this->_orders->create()
            ->addFieldToSelect('status')
            ->addFieldToSelect('increment_id')
            ->addFieldToSelect('entity_id')
            ->setOrder('status', 'desc');

        $select = $magentoOrders->getSelect()
            ->where('status = \''.$this->_configHelper->getOrderStatusReview.'\'');

        return $magentoOrders;
    }

    public function askNoFraudForOrderStatusAndUpdateMagentoOrder($magentoOrders) 
    {
        $apiUrl = $this->buildApiUrl();
        foreach ($magentoOrders as $order) {
            $orderSpecificApiUrl = $apiUrl.'/'.$order['increment_id'];
            $response = $this->_requestHandler->send(null,$orderSpecificApiUrl,self::REQUEST_TYPE);
            $noFraudOrderStatus = $response['http']['response']['body'];

            switch ($noFraudOrderStatus['decision']) {
                case 'pass':
                    if (isset($this->_configHelper->getPassOrderStatus())) {
                        $order->setStatus($this->_configHelper->getOrderStatusPass());
                        $order->save($order->getEntityId());
                    }
                    break;
                case 'fail':
                    $this->_dataHelper->handleAutoCancel($noFraudOrderStatus,$order);
                    break;
                case 'review':
                    break;
            }
            break;
        }
    }

    public function buildApiUrl()
    {
        $apiBaseUrl = $this->_configHelper->getSandboxMode() ?
            $this->requestHandler::SANDBOX_URL          :
            $this->requestHandler::PRODUCTION_URL       ;

        $orderRequest = self::ORDER_REQUEST;
        $token = $this->_configHelper->getApiToken();

        $apiUrl = $apiBaseUrl.$orderRequest.'/'.$token;

        return $apiUrl;
    }
}
