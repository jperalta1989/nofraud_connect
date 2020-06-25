<?php
 
namespace NoFraud\Connect\Api\Portal;
 
class ApiUrl
{
    const PORTAL_URL = 'https://portal-api.nofraud.com/';
    const CANCEL_ORDER_ENDPOINT = 'api/v1/transaction-update/cancel-transaction';

    protected $configHelper;
    protected $logger;

    public function __construct(
        \NoFraud\Connect\Helper\Config $configHelper,
        \NoFraud\Connect\Logger\Logger $logger
    ) {
        $this->configHelper = $configHelper;
        $this->logger = $logger;
    }

    /**
     * @param string $orderInfoRequest | Info wanted from order e.x. 'status'
     * @param string $apiToken | API Token
     * @return string
     */
    public function buildOrderApiUrl($orderInfoRequest, $apiToken)
    {
        $apiBaseUrl = $this->getPortalUrl();
        $apiUrl = $apiBaseUrl . $orderInfoRequest . '/' . $apiToken;

        return $apiUrl;
    }

    public function getPortalOrderCancelUrl()
    {
        return $this->getPortalUrl() . self::CANCEL_ORDER_ENDPOINT;
    }

    protected function getPortalUrl()
    {
        return self::PORTAL_URL;
    }
}
