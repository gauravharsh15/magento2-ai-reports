<?php

namespace Gaurav\AiReports\Model;

use Magento\Framework\Model\AbstractModel;

class QueryLog extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(ResourceModel\QueryLog::class);
    }
}
