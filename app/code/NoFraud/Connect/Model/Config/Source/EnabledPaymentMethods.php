<?php

namespace NoFraud\Connect\Model\Config\Source;

class EnabledPaymentMethods implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Payment Config
     *
     * @var \Magento\Payment\Model\Config
     */
    protected $paymentConfig;

    /**
     * @param \Magento\Payment\Model\Config $paymentConfig
     */
    public function __construct(
        \Magento\Payment\Model\Config $paymentConfig
    )
    {
        $this->paymentConfig = $paymentConfig;
    }

    /**
     * {@inheritdoc}
     */
    public function toOptionArray()
    {
        $methodList = [];
        $activeMethods = $this->paymentConfig->getActiveMethods();

        foreach ( $activeMethods as $method ) {
            $methodCode = $method->getCode();
            $methodTitle = $method->getTitle();
            $methodList[$methodCode] = [
                'value' => $methodCode,
                'label' => $methodTitle,
            ];
        }

        ksort($methodList); // TODO: Sort methods alphabetically by title (not by method code, which results in non-alphabetical order)

        return $methodList;
    }
}
