<?php

namespace NoFraud\Connect\Order;

use Magento\Sales\Model\Order;
use Magento\Framework\Serialize\Serializer\Json;

class Processor
{
    private $logger;
    private $configHelper;
    private $dataHelper;
    private $orderStatusCollection;
    private $invoiceService;
    private $creditmemoFactory;
    private $creditmemoService;
    private $stateIndex = [];

    public function __construct(
        \NoFraud\Connect\Logger\Logger $logger,
        \NoFraud\Connect\Helper\Data $dataHelper,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory,
        \Magento\Sales\Model\Service\CreditmemoService $creditmemoService,
        \Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory $orderStatusCollection,
        \NoFraud\Connect\Helper\Config $configHelper
    ) {
        $this->logger = $logger;
        $this->dataHelper = $dataHelper;
        $this->invoiceService = $invoiceService;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoService = $creditmemoService;
        $this->orderStatusCollection = $orderStatusCollection;
        $this->configHelper = $configHelper;
    }

    public function getCustomOrderStatus($response)
    {
        if ( isset($response['body']['decision']) ){
            $statusName = $response['body']['decision'];
        }

        if ( isset($response['code']) ){
            if ($response['code'] > 299) {
                $statusName = 'error';
            }
        }

        if ( isset($statusName) ){
            return $this->configHelper->getCustomStatusConfig($statusName);
        }
    }

    public function updateOrderStatusFromNoFraudResult($noFraudOrderStatus, $order) 
    {
        if (!empty($noFraudOrderStatus)){
            $newState = $this->getStateFromStatus($noFraudOrderStatus);

            if ($newState == Order::STATE_HOLDED) {
                $order->hold();
            } else if ($newState) {
                $order->setStatus($noFraudOrderStatus)->setState($newState);
            }
        }
    }

    public function getStateFromStatus($state)
    {
        $statuses = $this->orderStatusCollection->create()->joinStates();

        if (empty($this->stateIndex)) {
            foreach ($statuses as $status) {
                $this->stateIndex[$status->getStatus()] = $status->getState();
            }
        }

        return $this->stateIndex[$state] ?? null;
    }

    public function handleAutoCancel($order)
    {
        if ($order->canInvoice()){
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->register();
            $invoice->save();
            $creditmemo = $this->creditmemoFactory->createByOrder($order);
            $creditmemo->setInvoice($invoice);
            $this->creditmemoService->refund($creditmemo);
        }
    }
}
