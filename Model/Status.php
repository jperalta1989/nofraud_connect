<?php

namespace NoFraud\Connect\Model;

class Status
{
    private $logger;
    private $configHelper;
    private $dataHelper;

    public function __construct(
        \NoFraud\Connect\Logger\Logger $logger,
        \NoFraud\Connect\Helper\Data $dataHelper,
        \NoFraud\Connect\Helper\Config $configHelper
    ) {
        $this->logger = $logger;
        $this->dataHelper = $dataHelper;
        $this->configHelper = $configHelper;
    }

    public function updateMagentoOrderStatusFromNoFraudResult($noFraudDecision,$order) 
    {
        switch ($noFraudDecision) {
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
