<?php
namespace Gaurav\AiReports\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Provider implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'openai', 'label' => __('OpenAI (ChatGPT)')],
            ['value' => 'anthropic', 'label' => __('Anthropic (Claude)')],
            ['value' => 'gemini', 'label' => __('Google (Gemini)')]
        ];
    }
}