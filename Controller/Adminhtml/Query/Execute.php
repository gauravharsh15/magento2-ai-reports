<?php

namespace Gaurav\AiReports\Controller\Adminhtml\Query;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Gaurav\AiReports\Model\AiClient;
use Gaurav\AiReports\Model\QueryExecutor;
use Gaurav\AiReports\Model\Config;
use Gaurav\AiReports\Model\QueryLogFactory;
use Psr\Log\LoggerInterface;

class Execute extends Action
{
    const ADMIN_RESOURCE = 'Gaurav_AiReports::query';

    private const MAX_PROMPT_LENGTH = 2000;

    protected $jsonFactory;
    protected $aiClient;
    protected $queryExecutor;
    private $config;
    private $logger;
    private $queryLogFactory;

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        AiClient $aiClient,
        QueryExecutor $queryExecutor,
        Config $config,
        LoggerInterface $logger,
        QueryLogFactory $queryLogFactory
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->aiClient = $aiClient;
        $this->queryExecutor = $queryExecutor;
        $this->config = $config;
        $this->logger = $logger;
        $this->queryLogFactory = $queryLogFactory;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->config->isToolEnabled()) {
            return $result->setData([
                'error' => true,
                'message' => 'The AI Reports query tool is disabled. Configure a read-only database '
                    . 'user and enable it under Stores > Configuration > AI Reports > Security Guardrails.'
            ]);
        }

        $originalPrompt = trim((string) $this->getRequest()->getPost('prompt'));

        if (empty($originalPrompt)) {
            return $result->setData(['error' => true, 'message' => 'Prompt cannot be empty.']);
        }

        if (strlen($originalPrompt) > self::MAX_PROMPT_LENGTH) {
            return $result->setData([
                'error' => true,
                'message' => 'Prompt is too long (max ' . self::MAX_PROMPT_LENGTH . ' characters).'
            ]);
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

        $this->auditLog($originalPrompt, $sql, $success, $attempt, $success ? count($data) : null, $lastError);

        // If we exhausted all retries and it still failed - still hand back
        // whatever SQL was last attempted so it can be inspected, not just
        // the error message.
        if (!$success) {
            return $result->setData([
                'error' => true,
                'message' => "Failed after {$maxRetries} attempts. Last Error: " . $lastError,
                'sql_executed' => $sql
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

    /**
     * Every prompt/query executed through this tool touches production data,
     * so it's logged for accountability even though only admins can reach
     * it: once to the module's own log file (var/log/aireports.log) for
     * ops-style tailing/grepping, and once to a DB table so it can be
     * browsed/filtered from Reports > AI Reports Query Log in the admin.
     */
    private function auditLog(
        string $prompt,
        string $sql,
        bool $success,
        int $attempts,
        ?int $rowCount,
        string $lastError
    ): void {
        $adminUser = $this->_auth->getUser();
        $username = $adminUser ? $adminUser->getUsername() : 'unknown';
        $adminUserId = $adminUser ? $adminUser->getId() : null;
        $ip = $this->getRequest()->getClientIp();

        $context = [
            'admin_user' => $username,
            'ip' => $ip,
            'prompt' => $prompt,
            'sql_executed' => $sql,
            'attempts' => $attempts,
            'success' => $success,
        ];

        if ($success) {
            $context['row_count'] = $rowCount;
            $this->logger->info('[AiReports Audit] query executed', $context);
        } else {
            $context['error'] = $lastError;
            $this->logger->warning('[AiReports Audit] query failed', $context);
        }

        // A broken audit table must never block the tool itself from
        // returning results, so this is best-effort and fails silently
        // (beyond a log line) if persistence doesn't work.
        try {
            $this->queryLogFactory->create()->setData([
                'admin_user_id' => $adminUserId,
                'admin_username' => $username,
                'ip_address' => $ip,
                'prompt' => $prompt,
                'sql_executed' => $sql,
                'success' => $success ? 1 : 0,
                'row_count' => $rowCount,
                'error_message' => $success ? null : $lastError,
            ])->save();
        } catch (\Exception $e) {
            $this->logger->warning('[AiReports Audit] failed to persist query log row: ' . $e->getMessage());
        }
    }
}
