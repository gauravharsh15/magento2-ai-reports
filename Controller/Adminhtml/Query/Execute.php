<?php

namespace Gaurav\AiReports\Controller\Adminhtml\Query;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Gaurav\AiReports\Model\AiClient;
use Gaurav\AiReports\Model\QueryExecutor;

class Execute extends Action
{
    const ADMIN_RESOURCE = 'Gaurav_AiReports::query';

    protected $jsonFactory;
    protected $aiClient;
    protected $queryExecutor;

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        AiClient $aiClient,
        QueryExecutor $queryExecutor
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->aiClient = $aiClient;
        $this->queryExecutor = $queryExecutor;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $originalPrompt = $this->getRequest()->getPost('prompt');

        if (empty($originalPrompt)) {
            return $result->setData(['error' => true, 'message' => 'Prompt cannot be empty.']);
        }

        $maxRetries = 3;
        $attempt = 0;
        $success = false;
        $data = [];
        $sql = '';
        $lastError = '';

        // Start with the user's original request
        $currentPrompt = $originalPrompt;

        while ($attempt < $maxRetries && !$success) {
            $attempt++;
            try {
                // 1. Get SQL from the AI
                $sql = $this->aiClient->generateSql($currentPrompt);

                // 2. Try to execute it safely
                $data = $this->queryExecutor->execute($sql);

                // If we get here, the query succeeded!
                $success = true;
            } catch (\Exception $e) {
                $lastError = $e->getMessage();

                // If it's a database execution error, tell the AI exactly what it did wrong so it can fix it on the next loop
                if (strpos($lastError, 'Database Error') !== false || strpos($lastError, 'SQLSTATE') !== false) {
                    $currentPrompt = "My original request was: '" . $originalPrompt . "'.\n" .
                        "You generated this SQL: " . $sql . "\n" .
                        "However, running that SQL resulted in this database error: " . $lastError . "\n" .
                        "Please fix the SQL query and return ONLY the corrected query.";
                } else {
                    // If it's an API timeout or security validation error, just try the original prompt again
                    $currentPrompt = $originalPrompt;
                }
            }
        }

        // If we exhausted all retries and it still failed
        if (!$success) {
            return $result->setData([
                'error' => true,
                'message' => "Failed after {$maxRetries} attempts. Last Error: " . $lastError
            ]);
        }

        // Return the successful data
        return $result->setData([
            'error' => false,
            'sql_executed' => $sql,
            'attempts_taken' => $attempt,
            'data' => $data
        ]);
    }
}
