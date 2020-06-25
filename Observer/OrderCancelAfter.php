<?php

namespace NoFraud\Connect\Observer;

class OrderCancelAfter implements \Magento\Framework\Event\ObserverInterface
{
    protected $configHelper;
    protected $requestHandler;
    protected $responseHandler;
    protected $logger;
    protected $apiUrl;
    protected $portalApiUrl;
    protected $storeManager;

    public function __construct(
        \NoFraud\Connect\Helper\Config $configHelper,
        \NoFraud\Connect\Api\Portal\RequestHandler $requestHandler,
        \NoFraud\Connect\Api\Portal\ResponseHandler $responseHandler,
        \NoFraud\Connect\Api\Portal\ApiUrl $portalApiUrl,
        \NoFraud\Connect\Api\ApiUrl $apiUrl,
        \NoFraud\Connect\Logger\Logger $logger,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    ) {
        $this->configHelper = $configHelper;
        $this->requestHandler = $requestHandler;
        $this->responseHandler = $responseHandler;
        $this->logger = $logger;
        $this->portalApiUrl = $portalApiUrl;
        $this->apiUrl = $apiUrl;
        $this->storeManager = $storeManager;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        // If module is disabled in admin config, do nothing
        $storeId = $this->storeManager->getStore()->getId();
        if (!$this->configHelper->getEnabled($storeId)) {
            return;
        }

        // Get NoFraud Api Token
        $apiToken = $this->configHelper->getApiToken($storeId);

        // Use the NoFraud Sandbox URL if Sandbox Mode is enabled in admin config
        $portalApiUrl = $this->portalApiUrl->getPortalOrderCancelUrl();// Use the NoFraud Sandbox URL if Sandbox Mode is enabled in admin config
        $apiUrl = $this->apiUrl->getProductionUrl();

        // Get Order Id From Observer
        $order = $observer->getEvent()->getOrder();

        // Build the NoFraud API request JSON from the payment and order objects
        $request = $this->requestHandler->build(
            $apiUrl,
            $order->getIncrementId(),
            $apiToken
        );

        if(!$request){
            return;
        }

        // Send the request to the NoFraud API and get response
        $resultMap = $this->requestHandler->send($request, $portalApiUrl);

        // Log request results with associated invoice number
        $this->logger->logCancelTransactionResults($order, $resultMap);

        try {
            // For all API responses (official results from NoFraud, client errors, etc.),
            // add an informative comment to the order in Magento admin
            $comment = $this->responseHandler->buildComment($resultMap, 'cancel');
            if (!empty($comment)) {
                $order->addStatusHistoryComment($comment);
            }

            // Save order
            $order->save();
        } catch (\Exception $exception) {
            $this->logger->logFailure($order, $exception);
        }
    }
}