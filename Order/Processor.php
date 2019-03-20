<?php

namespace NoFraud\Connect\Order;

use Magento\Sales\Model\Order;

class Processor
{
    private $logger;
    private $configHelper;
    private $dataHelper;
    private $orderStatusCollection;
    private $invoiceService;
    private $creditmemoFactory;
    private $creditmemoService;

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

    public function updateMagentoOrderStatusFromNoFraudResult($noFraudOrderStatus, $order) 
    {
        switch ($noFraudOrderStatus['decision']) {
            case 'pass':
                if (isset($this->configHelper->getOrderStatusPass())) {
                    $order->setstatus($this->confighelper->getorderstatuspass());
                    $order->save($order->getentityid());
                }
                break;
            case 'fail':
                $this->handleautocancel($noFraudOrderStatus,$order);
                break;
            case 'review':
                break;
        }
    }

    public function updateMagentoOrderStateFromNoFraudResult($noFraudOrderStatus, $order) 
    {
        if (!empty($noFraudOrderStatus)){
            $newState = $this->getStateFromStatus($noFraudOrderStatus);

            if ($newStatus == Order::STATE_HOLDED) {
                $order->hold();
            } else if ($newState) {
                $order->setStatus($noFraudOrderStatus)->setState($newState);
            }
        }
    }

    public function handleAutoCancel($responseBody, $order)
    {
        if ( isset($responseBody['decision']) && $responseBody['decision'] == 'fail' && $order->canInvoice() ){
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->register();
            $invoice->save();
            $creditmemo = $this->creditmemoFactory->createByOrder($order);
            $creditmemo->setInvoice($invoice);
            $this->creditmemoService->refund($creditmemo);
        }
    }

    public function getCustomOrderStatus($responseBody)
    {
        if ( isset($responseBody['decision']) ){
            $statusName = $responseBody['decision'];
        }

        if ( isset($responseBody['Errors']) ){
            $statusName = 'error';
        }

        if ( isset($statusName) ){
            $customOrderStatus = $this->configHelper->getCustomStatusConfig($statusName);
            return $customOrderStatus;
        }
    }

    public function getStateFromStatus($state)
    {
        $statuses = $this->orderStatusCollection->create()->joinStates();
        $stateIndex = [];

        foreach ($statuses as $status) {
            $stateIndex[$status->getStatus()] = $status->getState();
        }

        return $stateIndex[$state] ?? null;
    }
}
