<?php
 
namespace NoFraud\Connect\Helper;
 
class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    protected $invoiceService;
    protected $creditmemoFactory;
    protected $creditmemoService;

    public function __construct(
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory,
        \Magento\Sales\Model\Service\CreditmemoService $creditmemoService,
        \NoFraud\Connect\Logger\Logger $logger
    ) {
        $this->invoiceService = $invoiceService;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoService = $creditmemoService;
        $this->logger = $logger;
    }

    public function handleAutoCancel( $responseBody, $order )
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
