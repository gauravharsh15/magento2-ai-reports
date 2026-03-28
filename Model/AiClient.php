<?php
namespace Gaurav\AiReports\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Serialize\Serializer\Json;

class AiClient
{
    const XML_PATH_PROVIDER = 'aireports/general/provider';
    const XML_PATH_API_KEY = 'aireports/general/api_key';
    const XML_PATH_API_URL = 'aireports/general/api_url';
    const XML_PATH_MODEL_NAME = 'aireports/general/model_name';
    const XML_PATH_SYSTEM_PROMPT = 'aireports/general/system_prompt';

    protected $scopeConfig;
    protected $encryptor;
    protected $curl;
    protected $jsonSerializer;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        Curl $curl,
        Json $jsonSerializer
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->curl = $curl;
        $this->jsonSerializer = $jsonSerializer;
    }

    public function generateSql($userPrompt)
    {
        $provider = $this->scopeConfig->getValue(self::XML_PATH_PROVIDER, ScopeInterface::SCOPE_STORE);
        $apiKey = $this->encryptor->decrypt($this->scopeConfig->getValue(self::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE));
        $apiUrl = $this->scopeConfig->getValue(self::XML_PATH_API_URL, ScopeInterface::SCOPE_STORE);
        $modelName = $this->scopeConfig->getValue(self::XML_PATH_MODEL_NAME, ScopeInterface::SCOPE_STORE);
        $systemInstruction = $this->scopeConfig->getValue(self::XML_PATH_SYSTEM_PROMPT, ScopeInterface::SCOPE_STORE);

        if (empty($apiKey) || empty($apiUrl)) {
            throw new \Exception('API configurations are missing in Stores > Configuration.');
        }

        $this->curl->addHeader('Content-Type', 'application/json');

        // Dynamically build payload and headers based on the provider
        switch ($provider) {
            case 'openai':
                $this->curl->addHeader('Authorization', 'Bearer ' . $apiKey);
                $payload = [
                    'model' => $modelName,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemInstruction],
                        ['role' => 'user', 'content' => $userPrompt]
                    ],
                    'temperature' => 0.1 // Keep it low for coding tasks
                ];
                $this->curl->post($apiUrl, $this->jsonSerializer->serialize($payload));
                break;

            case 'anthropic':
                $this->curl->addHeader('x-api-key', $apiKey);
                $this->curl->addHeader('anthropic-version', '2023-06-01');
                $payload = [
                    'model' => $modelName,
                    'system' => $systemInstruction,
                    'max_tokens' => 1024,
                    'messages' => [
                        ['role' => 'user', 'content' => $userPrompt]
                    ]
                ];
                $this->curl->post($apiUrl, $this->jsonSerializer->serialize($payload));
                break;

            case 'gemini':
                // Gemini usually expects the key in the URL string
                $requestUrl = rtrim($apiUrl, '?&') . '?key=' . $apiKey;
                $fullPrompt = $systemInstruction . "\n\nUser Request: " . $userPrompt;
                $payload = [
                    "contents" => [["parts" => [["text" => $fullPrompt]]]]
                ];
                $this->curl->post($requestUrl, $this->jsonSerializer->serialize($payload));
                break;

            default:
                throw new \Exception('Unsupported AI Provider selected.');
        }

        return $this->parseResponse($this->curl->getBody(), $provider);
    }

    private function parseResponse($responseBody, $provider)
    {
        $response = $this->jsonSerializer->unserialize($responseBody);
        $sql = '';

        if (isset($response['error'])) {
            $msg = is_array($response['error']) ? ($response['error']['message'] ?? 'Unknown Error') : $response['error'];
            throw new \Exception(ucfirst($provider) . ' API Error: ' . $msg);
        }

        switch ($provider) {
            case 'openai':
                $sql = $response['choices'][0]['message']['content'] ?? '';
                break;
            case 'anthropic':
                $sql = $response['content'][0]['text'] ?? '';
                break;
            case 'gemini':
                $sql = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
                break;
        }

        if (empty($sql)) {
            throw new \Exception('Could not extract SQL from the ' . ucfirst($provider) . ' response.');
        }

        // Clean up any markdown
        $sql = trim($sql);
        $sql = preg_replace('/^```sql\s*|```\s*$/i', '', $sql);
        return trim($sql);
    }
}