<?php

namespace Gaurav\AiReports\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;

class Index extends Action
{
    /**
     * Deliberately a separate ACL resource from Gaurav_AiReports::query, so
     * "who can run queries" and "who can review everyone's query history"
     * can be granted to different admin roles.
     */
    const ADMIN_RESOURCE = 'Gaurav_AiReports::log';

    public function execute()
    {
        /** @var \Magento\Backend\Model\View\Result\Page $resultPage */
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->setActiveMenu('Gaurav_AiReports::log');
        $resultPage->getConfig()->getTitle()->prepend(__('AI Reports Query Log'));

        return $resultPage;
    }
}
