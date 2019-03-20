<?php
 
namespace NoFraud\Connect\Api;
 
class ApiUrl
{
    const PRODUCTION_URL = 'https://api.nofraud.com/';
    const SANDBOX_URL    = 'https://apitest.nofraud.com/';

    private $configHelper;

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
     */
    public function buildOrderApiUrl($orderInfoRequest, $apiToken)
    {
        $apiBaseUrl = $this->whichEnvironmentUrl();
        $apiUrl = $apiBaseUrl.$orderRequest.'/'.$token;

        return $apiUrl;
    }

    public function whichEnvironmentUrl()
    {
        $this->configHelper->getSandboxMode() ?
            return self::SANDBOX_URL          :
            return self::PRODUCTION_URL       ;
    }
}
