<?php

namespace NoFraud\Connect\Ui\Component\Listing\Column\Screened;

use Magento\Framework\Escaper;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Signifyd\Api\Data\CaseInterface;

/**
 * Class Options
 */
class Options implements OptionSourceInterface
{
    /**
     * @var Escaper
     */
    private $escaper;

    /**
     * Constructor
     *
     * @param Escaper $escaper
     */
    public function __construct(Escaper $escaper)
    {
        $this->escaper = $escaper;
    }

    /**
     * {@inheritdoc}
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => 0,
                'label' => $this->escaper->escapeHtml(__('No'))
            ],
            [
                'value' => 1,
                'label' => $this->escaper->escapeHtml(__('Yes'))
            ],
        ];
    }
}
