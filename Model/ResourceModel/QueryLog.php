<?php

namespace Gaurav\AiReports\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class QueryLog extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('gaurav_aireports_query_log', 'entity_id');
    }
}
