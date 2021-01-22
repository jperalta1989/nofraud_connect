<?php

namespace NoFraud\Connect\Order;

use Magento\Sales\Model\Order;
use Magento\Framework\Serialize\Serializer\Json;

class Processor
{
    protected $logger;
    protected $configHelper;
    protected $dataHelper;
    protected $orderStatusCollection;
    protected $invoiceService;
    protected $creditmemoFactory;
    protected $creditmemoService;
    protected $stateIndex = [];
    protected $orderManagement;
    protected $orderStatusFactory;

    const CYBERSOURCE_METHOD_CODE = 'md_cybersource';

    public function __construct(
        \NoFraud\Connect\Logger\Logger $logger,
        \NoFraud\Connect\Helper\Data $dataHelper,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory,
        \Magento\Sales\Model\Service\CreditmemoService $creditmemoService,
        \Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory $orderStatusCollection,
        \NoFraud\Connect\Helper\Config $configHelper,
        \Magento\Sales\Api\OrderManagementInterface $orderManagement,
        \Magento\Sales\Model\Order\StatusFactory $orderStatusFactory
    ) {
        $this->logger = $logger;
        $this->dataHelper = $dataHelper;
        $this->invoiceService = $invoiceService;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoService = $creditmemoService;
        $this->orderStatusCollection = $orderStatusCollection;
        $this->configHelper = $configHelper;
        $this->orderManagement = $orderManagement;
        $this->orderStatusFactory = $orderStatusFactory;
    }

    public function getCustomOrderStatus($response, $storeId = null)
    {
        if (isset($response['body']['decision'])) {
            $statusName = $response['body']['decision'];
        }

        if (isset($response['code'])) {
            if ($response['code'] > 299) {
                $statusName = 'error';
            }
        }

        if (isset($statusName)) {
            return $this->configHelper->getCustomStatusConfig($statusName, $storeId);
        }
    }

    public function updateOrderStatusFromNoFraudResult($noFraudOrderStatus, $order) 
    {
        if (!empty($noFraudOrderStatus)) {
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

    public function handleAutoCancel($order, $decision)
    {
        // if order failed NoFraud check, try to refund
        if ($decision == 'fail' && $order->canInvoice()){

            // Handle custom cancel for Payment Method if needed
            if($this->_runCustomAutoCancel($order)){
                return;
            }

            // Run Default refund & cancel logic
            if($order->getBaseTotalPaid() > 0){
                $invoice = $this->invoiceService->prepareInvoice($order);
                $invoice->register();
                $invoice->save();
                $creditmemo = $this->creditmemoFactory->createByOrder($order);
                $creditmemo->setInvoice($invoice);
                $this->creditmemoService->refund($creditmemo);
                $order->setStatus(Order::STATE_CANCELED)->setState(Order::STATE_CANCELED);
            } else{
                $order->cancel();
            }

        }
    }

    private function _runCustomAutoCancel($order){
        $isCustom = true;
        $method = $order->getPayment()->getMethod();

        switch ($method){
            case (self::CYBERSOURCE_METHOD_CODE):
                $this->_handleCyberSourceAutoCanel($order);
                break;
            default:
                $isCustom = false;
                break;
        }

        return $isCustom;
    }

    private function _handleCyberSourceAutoCanel($order){
        $this->orderManagement->cancel($order->getEntityId());

        $status = $this->orderStatusFactory->create()->loadDefaultByState(Order::STATE_CANCELED)->getStatus();
        $order->setState(Order::STATE_CANCELED);
        $order->setStatus($status);

        $order->save();
    }
}
