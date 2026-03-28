<?php
namespace Gaurav\AiReports\Controller\Adminhtml\Query;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;

class Index extends Action
{
    /**
     * Authorization level of a basic admin session
     */
    const ADMIN_RESOURCE = 'Gaurav_AiReports::query';

    public function execute()
    {
        /** @var \Magento\Backend\Model\View\Result\Page $resultPage */
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->setActiveMenu('Gaurav_AiReports::query');
        $resultPage->getConfig()->getTitle()->prepend(__('AI SQL Query Generator'));

        // For testing, we'll just return a raw text response if the template isn't built yet
        // return $this->resultFactory->create(ResultFactory::TYPE_RAW)->setContents('Module is working!');
        
        return $resultPage;
    }
}