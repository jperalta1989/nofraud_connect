<?php
 
namespace NoFraud\Connect\Helper;
 
class Config extends \Magento\Framework\App\Helper\AbstractHelper
{
    const GENERAL_ENABLED = 'nofraud_connect/general/enabled';
    const GENERAL_API_TOKEN = 'nofraud_connect/general/api_token';
    const GENERAL_SANDBOX_MODE = 'nofraud_connect/general/sandbox_enabled';

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->scopeConfig = $scopeConfig;
    }

    public function noFraudIsDisabled()
    {
        return !$this->getEnabled();
    }

    public function sandboxIsEnabled()
    {
        return $this->getSandboxMode();
    }

    public function getApiToken()
    {
        return $this->scopeConfig->getValue(self::GENERAL_API_TOKEN);
    }

    public function getSandboxMode()
    {
        return $this->scopeConfig->getValue(self::GENERAL_SANDBOX_MODE);
    }

    public function getEnabled()
    {
        return $this->scopeConfig->getValue(self::GENERAL_ENABLED);
    }
}
