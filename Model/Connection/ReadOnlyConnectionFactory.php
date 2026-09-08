<?php

namespace Gaurav\AiReports\Model\Connection;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\Pdo\MysqlFactory;
use Psr\Log\LoggerInterface;

/**
 * Builds an isolated database connection for the reporting tool, entirely
 * separate from Magento's own "default" (read/write) connection.
 *
 * This connection should be backed by a MySQL user that only has SELECT
 * privileges (see Stores > Configuration > AI Reports > Read-Only Database
 * Connection). As a second, independent line of defense - in case that user
 * was ever misconfigured with write access - every session opened here is
 * also explicitly switched into MySQL's own read-only transaction mode.
 */
class ReadOnlyConnectionFactory
{
    private $mysqlFactory;
    private $logger;

    public function __construct(MysqlFactory $mysqlFactory, LoggerInterface $logger)
    {
        $this->mysqlFactory = $mysqlFactory;
        $this->logger = $logger;
    }

    public function create(array $dbConfig): AdapterInterface
    {
        $connection = $this->mysqlFactory->create([
            'host' => $dbConfig['host'],
            'dbname' => $dbConfig['dbname'],
            'username' => $dbConfig['username'],
            'password' => $dbConfig['password'],
            'model' => 'mysql4',
            'type' => 'pdo_mysql',
            'engine' => 'innodb',
            'active' => true,
            'persistent' => false,
            'driver_options' => [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ],
        ]);

        try {
            $connection->query('SET SESSION TRANSACTION READ ONLY');
        } catch (\Exception $e) {
            $this->logger->warning(
                '[AiReports] Could not enforce SET SESSION TRANSACTION READ ONLY on the reporting '
                . 'connection - continuing, but the read-only guarantee now rests entirely on the '
                . 'configured MySQL user\'s GRANTs. Driver message: ' . $e->getMessage()
            );
        }

        return $connection;
    }
}
