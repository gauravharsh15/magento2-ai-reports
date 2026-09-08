<?php

namespace Gaurav\AiReports\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Store\Model\ScopeInterface;

/**
 * Central reader for the module's guardrail and read-only connection settings.
 */
class Config
{
    const XML_PATH_TOOL_ENABLED = 'aireports/guardrails/enabled';
    const XML_PATH_MAX_ROWS = 'aireports/guardrails/max_rows';
    const XML_PATH_QUERY_TIMEOUT = 'aireports/guardrails/query_timeout_seconds';
    const XML_PATH_BLOCKED_TABLES = 'aireports/guardrails/blocked_tables';

    /**
     * The read-only connection's host/schema/username/password all come
     * from app/etc/env.php, never from admin config. This is deliberate:
     * env.php can't be edited from the admin UI at all, so the credential
     * stays out of reach even for an admin holding the Gaurav_AiReports::config
     * ACL resource - changing it requires server file access, the same bar
     * as Magento's own DB credentials.
     */
    const DEPLOYMENT_CONFIG_PATH_HOST = 'db/connection/default/host';
    const DEPLOYMENT_CONFIG_PATH_DBNAME = 'db/connection/default/dbname';
    const DEPLOYMENT_CONFIG_PATH_USERNAME = 'aireports/readonly_connection/username';
    const DEPLOYMENT_CONFIG_PATH_PASSWORD = 'aireports/readonly_connection/password';

    const DEFAULT_MAX_ROWS = 1000;
    const DEFAULT_QUERY_TIMEOUT = 5;

    /**
     * Tables that are always off-limits, regardless of admin configuration.
     * This is a floor, not a ceiling - store owners can add more via
     * XML_PATH_BLOCKED_TABLES, but they cannot remove these by clearing a field.
     */
    const MINIMUM_BLOCKED_TABLES = [
        'admin_user',
        'admin_passwords',
        'admin_system_messages',
        'oauth_token',
        'oauth_consumer',
        'oauth_nonce',
        'api_key',
        'integration',
        'authorization_rule',
        'admin_role',
        'vault_payment_token',
        'sales_payment_token',
        'core_config_data',
    ];

    private $scopeConfig;
    private $deploymentConfig;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        DeploymentConfig $deploymentConfig
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->deploymentConfig = $deploymentConfig;
    }

    public function isToolEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_TOOL_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function getMaxRows(): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_MAX_ROWS, ScopeInterface::SCOPE_STORE);
        return $value > 0 ? $value : self::DEFAULT_MAX_ROWS;
    }

    public function getQueryTimeout(): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_QUERY_TIMEOUT, ScopeInterface::SCOPE_STORE);
        return $value > 0 ? $value : self::DEFAULT_QUERY_TIMEOUT;
    }

    /**
     * @return string[] Lower-cased, deduplicated table names that must never be queried.
     */
    public function getBlockedTables(): array
    {
        $configured = (string) $this->scopeConfig->getValue(self::XML_PATH_BLOCKED_TABLES, ScopeInterface::SCOPE_STORE);

        $extra = array_filter(array_map('trim', explode(',', $configured)), static function ($table) {
            return $table !== '';
        });

        $all = array_merge(self::MINIMUM_BLOCKED_TABLES, $extra);

        return array_values(array_unique(array_map('strtolower', $all)));
    }

    /**
     * Returns the connection parameters for the dedicated read-only reporting
     * user, or null if it hasn't been configured yet. Everything here comes
     * from app/etc/env.php - nothing in this method touches admin config, by
     * design.
     */
    public function getReadOnlyDbConfig(): ?array
    {
        $host = trim((string) $this->deploymentConfig->get(self::DEPLOYMENT_CONFIG_PATH_HOST));
        $dbName = trim((string) $this->deploymentConfig->get(self::DEPLOYMENT_CONFIG_PATH_DBNAME));
        $username = trim((string) $this->deploymentConfig->get(self::DEPLOYMENT_CONFIG_PATH_USERNAME));
        $password = (string) $this->deploymentConfig->get(self::DEPLOYMENT_CONFIG_PATH_PASSWORD);

        if ($host === '' || $dbName === '' || $username === '') {
            return null;
        }

        return [
            'host' => $host,
            'dbname' => $dbName,
            'username' => $username,
            'password' => $password,
        ];
    }
}
