<?php

namespace Gaurav\AiReports\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class SuccessStatus implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 1, 'label' => __('Success')],
            ['value' => 0, 'label' => __('Failed')],
        ];
    }
}
