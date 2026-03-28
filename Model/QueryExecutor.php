<?php

namespace Gaurav\AiReports\Model;

use Magento\Framework\App\ResourceConnection;

class QueryExecutor
{
    protected $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    public function execute($sql)
    {
        // Isolates the first query to prevent "Multiple queries" errors
        $sqlParts = explode(';', trim($sql));
        $singleSql = trim($sqlParts[0]);

        if (empty($singleSql)) {
            throw new \Exception('No valid SQL was generated.');
        }

        // PRODUCTION SECURITY FIX: Strict regex to ensure it starts with SELECT
        if (!preg_match('/^\s*SELECT\b/i', $singleSql)) {
            throw new \Exception('Security Exception: Only SELECT queries are permitted.');
        }

        // PRODUCTION SECURITY FIX: Hard blocklist for destructive keywords anywhere in the string
        if (preg_match('/\b(UPDATE|DELETE|DROP|TRUNCATE|ALTER|INSERT|REPLACE|GRANT|REVOKE)\b/i', $singleSql)) {
            throw new \Exception('Security Exception: Destructive SQL commands detected and blocked.');
        }

        $connection = $this->resourceConnection->getConnection('default');
        return $connection->fetchAll($singleSql);
    }
}