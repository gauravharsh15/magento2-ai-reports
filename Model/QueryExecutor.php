<?php

namespace Gaurav\AiReports\Model;

use Gaurav\AiReports\Model\Connection\ReadOnlyConnectionFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Psr\Log\LoggerInterface;

class QueryExecutor
{
    /**
     * Keywords/functions that must never appear in an AI-generated report
     * query. This list is a defense-in-depth speed bump, not the security
     * boundary - the actual guarantee is the read-only MySQL user plus the
     * READ ONLY session set by ReadOnlyConnectionFactory. An LLM can be
     * prompt-injected into emitting adversarial SQL, so app-layer parsing
     * alone is never trusted here.
     */
    private const FORBIDDEN_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'DROP', 'ALTER', 'TRUNCATE',
        'CREATE', 'RENAME', 'GRANT', 'REVOKE', 'CALL', 'EXECUTE', 'PREPARE',
        'DEALLOCATE', 'LOCK', 'UNLOCK', 'SET', 'HANDLER', 'LOAD_FILE', 'LOAD',
        'OUTFILE', 'DUMPFILE', 'INFILE', 'BENCHMARK', 'SLEEP', 'GET_LOCK',
        'RELEASE_LOCK', 'SHUTDOWN', 'KILL', 'PROCEDURE', 'FUNCTION', 'TRIGGER',
        'USE', 'START', 'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'FLUSH', 'RESET',
    ];

    private $config;
    private $connectionFactory;
    private $logger;

    /** @var AdapterInterface|null Cached for the lifetime of a request so retries don't reconnect. */
    private $connection;

    public function __construct(
        Config $config,
        ReadOnlyConnectionFactory $connectionFactory,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->connectionFactory = $connectionFactory;
        $this->logger = $logger;
    }

    public function execute($sql)
    {
        $dbConfig = $this->config->getReadOnlyDbConfig();
        if ($dbConfig === null) {
            throw new \Exception(
                'A dedicated read-only database user has not been configured. Go to Stores > '
                . 'Configuration > AI Reports > Read-Only Database Connection and configure a MySQL '
                . 'user that only has SELECT privileges before using this tool.'
            );
        }

        $singleSql = $this->validate((string) $sql);
        $singleSql = $this->applyRowLimit($singleSql);

        $connection = $this->getConnection($dbConfig);

        try {
            return $connection->fetchAll($singleSql);
        } catch (\Exception $e) {
            // Message is intentionally preserved (incl. SQLSTATE) - the calling
            // controller feeds it back to the AI so it can self-correct.
            throw new \Exception('Database Error: ' . $e->getMessage());
        }
    }

    private function getConnection(array $dbConfig): AdapterInterface
    {
        if ($this->connection === null) {
            $this->connection = $this->connectionFactory->create($dbConfig);
            $this->applyStatementTimeout($this->connection);
        }

        return $this->connection;
    }

    private function validate(string $sql): string
    {
        $sql = trim($sql);

        if ($sql === '') {
            throw new \Exception('No valid SQL was generated.');
        }

        // Reject SQL comments outright. Comment markers are a well-known way to
        // split a forbidden keyword (e.g. "UPDA/**/TE") so that a naive
        // word-boundary check misses it while MySQL still parses it as the
        // full keyword. It's simpler and safer to disallow comments entirely -
        // a reporting SELECT never legitimately needs one.
        if (preg_match('/(--|#|\/\*)/', $sql)) {
            throw new \Exception('Security Exception: SQL comments are not permitted.');
        }

        // Reject multiple statements outright instead of silently truncating
        // at the first semicolon (silent truncation can mask a query that
        // doesn't do what the user/AI thought it would).
        $sql = rtrim($sql, "; \t\n\r");
        if (strpos($sql, ';') !== false) {
            throw new \Exception('Security Exception: Multiple SQL statements are not permitted.');
        }

        if (!preg_match('/^\s*(SELECT|SHOW|EXPLAIN|DESC|DESCRIBE)\b/i', $sql)) {
            throw new \Exception('Security Exception: Only SELECT/SHOW/EXPLAIN queries are permitted.');
        }

        $forbiddenPattern = '/\b(' . implode('|', self::FORBIDDEN_KEYWORDS) . ')\b/i';
        if (preg_match($forbiddenPattern, $sql, $matches)) {
            throw new \Exception('Security Exception: Forbidden keyword "' . strtoupper($matches[1]) . '" detected and blocked.');
        }

        $this->assertNoBlockedTables($sql);

        return $sql;
    }

    private function assertNoBlockedTables(string $sql): void
    {
        foreach ($this->config->getBlockedTables() as $table) {
            if ($table !== '' && preg_match('/\b' . preg_quote($table, '/') . '\b/i', $sql)) {
                throw new \Exception('Security Exception: Access to table "' . $table . '" is restricted.');
            }
        }
    }

    private function applyRowLimit(string $sql): string
    {
        if (preg_match('/\bLIMIT\s+\d+(\s*(,|OFFSET)\s*\d+)?\s*$/i', $sql)) {
            return $sql;
        }

        return $sql . ' LIMIT ' . $this->config->getMaxRows();
    }

    private function applyStatementTimeout(AdapterInterface $connection): void
    {
        $timeoutMs = $this->config->getQueryTimeout() * 1000;

        try {
            // MySQL 5.7.8+ only; MariaDB and older MySQL don't support this
            // and will throw, which is fine - the row LIMIT and the read-only
            // session remain in effect regardless.
            $connection->query('SET SESSION MAX_EXECUTION_TIME=' . (int) $timeoutMs);
        } catch (\Exception $e) {
            $this->logger->info('[AiReports] MAX_EXECUTION_TIME not supported on this server: ' . $e->getMessage());
        }
    }
}
