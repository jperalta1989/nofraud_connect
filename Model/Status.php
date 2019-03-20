<?php

namespace NoFraud\Connect\Model;

class Status
{
    private $logger;
    private $configHelper;
    private $dataHelper;
    protected $invoiceService;
    protected $creditmemoFactory;
    protected $creditmemoService;

    public function __construct(
        \NoFraud\Connect\Logger\Logger $logger,
        \NoFraud\Connect\Helper\Data $dataHelper,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory,
        \Magento\Sales\Model\Service\CreditmemoService $creditmemoService,
        \NoFraud\Connect\Helper\Config $configHelper
    ) {
        $this->logger = $logger;
        $this->dataHelper = $dataHelper;
        $this->invoiceService = $invoiceService;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoService = $creditmemoService;
        $this->configHelper = $configHelper;
    }

    public function updateMagentoOrderStatusFromNoFraudResult($noFraudOrderStatus,$order) 
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
}
