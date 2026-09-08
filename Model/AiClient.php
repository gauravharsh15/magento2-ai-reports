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

    /** Max characters of a raw provider response to include in error messages. */
    private const ERROR_SNIPPET_LENGTH = 500;

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
        $systemInstruction = trim((string) $this->scopeConfig->getValue(self::XML_PATH_SYSTEM_PROMPT, ScopeInterface::SCOPE_STORE));

        if (empty($apiKey) || empty($apiUrl)) {
            throw new \Exception('API configurations are missing in Stores > Configuration.');
        }

        if (stripos($apiUrl, 'https://') !== 0) {
            throw new \Exception('API Endpoint URL must use HTTPS - the API key is sent in this request.');
        }

        $this->curl->addHeader('Content-Type', 'application/json');
        // Never let the API key be replayed against a redirect target, and
        // don't let a slow/unresponsive provider hang the admin request.
        $this->curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        $this->curl->setOption(CURLOPT_CONNECTTIMEOUT, 10);
        $this->curl->setOption(CURLOPT_TIMEOUT, 30);

        // Dynamically build payload and headers based on the provider.
        // 'openai' and 'custom_openai' share a schema - the "custom" option
        // exists so this module works against ANY endpoint that speaks the
        // OpenAI chat-completions format (Azure OpenAI, Ollama, LM Studio,
        // Groq, OpenRouter, Together, vLLM, etc.), not just openai.com.
        switch ($provider) {
            case 'openai':
            case 'custom_openai':
                $this->curl->addHeader('Authorization', 'Bearer ' . $apiKey);
                $messages = [];
                if ($systemInstruction !== '') {
                    $messages[] = ['role' => 'system', 'content' => $systemInstruction];
                }
                $messages[] = ['role' => 'user', 'content' => $userPrompt];
                $payload = [
                    'model' => $modelName,
                    'messages' => $messages,
                    'temperature' => 0.1 // Keep it low for coding tasks
                ];
                $this->curl->post($apiUrl, $this->jsonSerializer->serialize($payload));
                break;

            case 'anthropic':
                $this->curl->addHeader('x-api-key', $apiKey);
                $this->curl->addHeader('anthropic-version', '2023-06-01');
                $payload = [
                    'model' => $modelName,
                    'max_tokens' => 1024,
                    'messages' => [
                        ['role' => 'user', 'content' => $userPrompt]
                    ]
                ];
                if ($systemInstruction !== '') {
                    $payload['system'] = $systemInstruction;
                }
                $this->curl->post($apiUrl, $this->jsonSerializer->serialize($payload));
                break;

            case 'gemini':
                // Gemini usually expects the key in the URL string
                $requestUrl = rtrim($apiUrl, '?&') . '?key=' . $apiKey;
                $fullPrompt = $systemInstruction !== ''
                    ? $systemInstruction . "\n\nUser Request: " . $userPrompt
                    : $userPrompt;
                $payload = [
                    "contents" => [["parts" => [["text" => $fullPrompt]]]]
                ];
                $this->curl->post($requestUrl, $this->jsonSerializer->serialize($payload));
                break;

            default:
                throw new \Exception('Unsupported AI Provider selected.');
        }

        $this->assertSuccessfulHttpStatus($provider);

        return $this->parseResponse($this->curl->getBody(), $provider);
    }

    /**
     * Surfaces the provider's actual rejection reason (auth failure, unknown
     * model, malformed request, etc.) instead of letting execution continue
     * into response parsing, where the real cause gets lost and everything
     * looks like a generic "could not extract SQL" failure.
     */
    private function assertSuccessfulHttpStatus(string $provider): void
    {
        $status = (int) $this->curl->getStatus();
        if ($status >= 200 && $status < 300) {
            return;
        }

        $body = (string) $this->curl->getBody();
        $detail = $this->extractErrorMessage($body);
        if ($detail === null) {
            $detail = trim(strip_tags($body));
        }
        $detail = $detail !== '' ? substr($detail, 0, self::ERROR_SNIPPET_LENGTH) : 'no error details were returned.';

        throw new \Exception(ucfirst($provider) . " API request failed (HTTP {$status}): " . $detail);
    }

    private function extractErrorMessage(string $body): ?string
    {
        try {
            $decoded = $this->jsonSerializer->unserialize($body);
        } catch (\Exception $e) {
            return null;
        }

        if (!is_array($decoded) || !isset($decoded['error'])) {
            return null;
        }

        return is_array($decoded['error']) ? ($decoded['error']['message'] ?? null) : (string) $decoded['error'];
    }

    private function parseResponse($responseBody, $provider)
    {
        try {
            $response = $this->jsonSerializer->unserialize($responseBody);
        } catch (\Exception $e) {
            throw new \Exception(
                ucfirst($provider) . ' returned a response that was not valid JSON: '
                . substr((string) $responseBody, 0, self::ERROR_SNIPPET_LENGTH)
            );
        }

        if (isset($response['error'])) {
            $msg = is_array($response['error']) ? ($response['error']['message'] ?? 'Unknown Error') : $response['error'];
            throw new \Exception(ucfirst($provider) . ' API Error: ' . $msg);
        }

        $sql = '';

        switch ($provider) {
            case 'openai':
            case 'custom_openai':
                $sql = $response['choices'][0]['message']['content'] ?? '';
                break;

            case 'anthropic':
                // Claude's `content` array can contain more than a single
                // text block - e.g. a `thinking` block ahead of the actual
                // answer on models with extended thinking. Find the first
                // text block instead of assuming it's always at index 0.
                foreach ($response['content'] ?? [] as $block) {
                    if (($block['type'] ?? null) === 'text' && !empty($block['text'])) {
                        $sql = $block['text'];
                        break;
                    }
                }
                break;

            case 'gemini':
                $sql = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
                break;
        }

        if (empty($sql)) {
            throw new \Exception(
                'Could not extract SQL from the ' . ucfirst($provider) . ' response. Raw response: '
                . substr((string) $responseBody, 0, self::ERROR_SNIPPET_LENGTH)
            );
        }

        // Clean up any markdown
        $sql = trim($sql);
        $sql = preg_replace('/^```sql\s*|```\s*$/i', '', $sql);
        return trim($sql);
    }
}
