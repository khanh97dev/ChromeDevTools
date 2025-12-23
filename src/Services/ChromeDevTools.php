<?php

namespace ChromeDevTools\Services;

use BadMethodCallException;
use WebSocket\Client;

class ChromeDevTools
{
    protected Client $client;
    protected string $sessionId = '';
    protected int $nextId = 1;
    public string $targetId = '';
    public $log;

    public function __construct(string $url, $timeout = 15)
    {
        // Nếu là HTTP URL, tự động lấy WebSocket URL
        if (str_starts_with($url, 'http://')) {
            $wsUrl = $this->getWebSocketUrl($url);
        } else {
            $wsUrl = $url;
        }

        $this->client = new Client($wsUrl, [
            'timeout' => $timeout // 15s default
        ]);

        $this->log = function (string $msg) {
            fwrite(STDOUT, $msg);
        };
    }

    /**
     * Lấy WebSocket URL từ HTTP endpoint
     */
    protected function getWebSocketUrl(string $httpUrl): string
    {
        $httpUrl = rtrim($httpUrl, '/');

        // Sử dụng cURL để lấy JSON
        $ch = curl_init("$httpUrl/json/version");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            throw new \RuntimeException("Cannot connect to Chrome DevTools at $httpUrl: $error");
        }

        $data = json_decode($response, true);

        if (!isset($data['webSocketDebuggerUrl'])) {
            throw new \RuntimeException("WebSocket URL not found in response");
        }

        return $data['webSocketDebuggerUrl'];
    }

    public function __call($name, $arguments)
    {
        if (is_callable($this->$name)) {
            return call_user_func_array($this->$name, $arguments);
        }

        throw new BadMethodCallException("Method $name does not exist.");
    }



    /**
     * Send a CDP command and wait for the matching response.
     */
    protected function sendCommand(string $method, array $params = [], bool $needsSession = true): array
    {
        $id = $this->nextId++;

        $message = [
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ];

        if ($needsSession && $this->sessionId) {
            $message['sessionId'] = $this->sessionId;
        }

        $json = json_encode($message);
        // log to stdout
        $this->log(">>> Sending: {$json}\n");

        $this->client->send($json);

        while (true) {
            $raw = $this->client->receive();
            // log every incoming message
            $this->log("<<< Received: {$raw}\n");

            $msg = json_decode($raw, true);

            // Response to our command
            if (isset($msg['id']) && $msg['id'] === $id) {
                return $msg;
            }

            // Handle events
            if (isset($msg['method'])) {
                if ($msg['method'] === 'Target.attachedToTarget' && isset($msg['params']['sessionId'])) {
                    $this->sessionId = $msg['params']['sessionId'];
                    $this->log(">>> Updated sessionId: {$this->sessionId}\n");
                }
            }
        }
    }


    /**
     * Create a new tab and attach to it.
     */
    public function createTarget(string $url): string
    {
        $resp = $this->sendCommand('Target.createTarget', ['url' => $url], false);
        $this->targetId = $resp['result']['targetId'];

        $this->sendCommand('Target.attachToTarget', [
            'targetId' => $this->targetId,
            'flatten' => true
        ], false);

        $this->sendCommand('Page.enable');

        return $this->targetId;
    }

    /**
     * Navigate to a URL and wait for load.
     */
    public function navigate(string $url): void
    {
        $this->sendCommand('Page.navigate', ['url' => $url]);

        // Wait for load event
        while (true) {
            $msg = json_decode($this->client->receive(), true);
            if (isset($msg['method']) && $msg['method'] === 'Page.loadEventFired') {
                break;
            }
        }
    }

    /**
     * Evaluate arbitrary JavaScript in the page.
     */
    public function evaluate(string $expression, bool $returnByValue = true)
    {
        $resp = $this->sendCommand('Runtime.evaluate', [
            'expression' => $expression,
            'returnByValue' => $returnByValue
        ]);
        return $resp['result']['result']['value'] ?? null;
    }

    /**
     * Get the page title.
     */
    public function getTitle(): string
    {
        return $this->evaluate('document.title') ?? '(no title)';
    }

    /**
     * Get the current page URL.
     */
    public function getUrl(): ?string
    {
        $resp = $this->sendCommand('Runtime.evaluate', [
            'expression' => 'window.location.href'
        ]);

        return $resp['result']['result']['value'] ?? null;
    }

    /**
     * Click an element by CSS selector.
     */
    public function clickSelector(string $selector): void
    {
        $this->evaluate("document.querySelector(" . json_encode($selector) . ")?.click()");
    }

    public function typeIntoSelector(string $selector, string $text): void
    {
        $js = sprintf(
            "(() => { const el = document.querySelector(%s); if (el) { el.value = %s; el.dispatchEvent(new Event('input', { bubbles: true })); } })()",
            json_encode($selector),
            json_encode($text)
        );

        $this->evaluate($js);
    }

    public function typeText(string $text, int $delayMs = 70): void
    {
        // Loop through each character
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($chars as $char) {
            $this->sendCommand('Input.insertText', [
                'text' => $char
            ]);

            // Delay between keystrokes
            usleep($delayMs * 1000); // convert ms to microseconds
        }
    }

    public function pressEnter(): void
    {
        // KeyDown
        $this->sendCommand('Input.dispatchKeyEvent', [
            'type' => 'keyDown',
            'key' => 'Enter',
            'code' => 'Enter',
            'windowsVirtualKeyCode' => 13,
            'nativeVirtualKeyCode' => 13
        ]);

        // KeyUp
        $this->sendCommand('Input.dispatchKeyEvent', [
            'type' => 'keyUp',
            'key' => 'Enter',
            'code' => 'Enter',
            'windowsVirtualKeyCode' => 13,
            'nativeVirtualKeyCode' => 13
        ]);
    }

    /**
     * Find an element by CSS selector.
     * Returns null if not found, or a string (outerHTML) if found.
     */
    public function findBySelector(string $selector): ?string
    {
        $js = sprintf(
            "(() => {
            const el = document.querySelector(%s);
            return el ? el.outerHTML : null;
        })()",
            json_encode($selector)
        );

        $result = $this->evaluate($js);
        return $result !== null ? (string) $result : null;
    }

    /**
     * Wait until the page has finished loading.
     */
    public function waitLoading(): void
    {
        while (true) {
            $raw = $this->client->receive();
            $this->log("<<< Event while waiting: {$raw}\n");

            $msg = json_decode($raw, true);
            if (isset($msg['method']) && $msg['method'] === 'Page.loadEventFired') {
                break;
            }
        }
    }


    public function waitSelector(string $selector, int $timeoutMs = 30000, int $pollIntervalMs = 100): bool
    {
        $startTime = microtime(true) * 1000; // Convert to milliseconds

        $this->log(">>> Waiting for selector: {$selector} (timeout: {$timeoutMs}ms)\n");

        while (true) {
            $exists = $this->evaluate(
                "!!document.querySelector(" . json_encode($selector) . ")"
            );

            if ($exists) {
                $elapsed = (microtime(true) * 1000) - $startTime;
                $this->log(">>> Selector found after {$elapsed}ms\n");
                return true;
            }

            $elapsed = (microtime(true) * 1000) - $startTime;

            if ($elapsed >= $timeoutMs) {
                $this->log(">>> Timeout waiting for selector: {$selector}\n");
                throw new \RuntimeException("Timeout waiting for selector: {$selector} after {$timeoutMs}ms");
            }

            usleep($pollIntervalMs * 1000); // Convert ms to microseconds
        }
    }
    
    /**
     * Close the current tab.
     */
    public function closePage(): void
    {
        $this->sendCommand('Target.closeTarget', [
            'targetId' => $this->targetId
        ], false);
    }

    /**
     * Close the entire browser.
     */
    public function closeBrowser(): void
    {
        $this->sendCommand('Browser.close', [], false);
    }

    /**
     * Close the WebSocket connection.
     */
    public function close(): void
    {
        $this->client->close();
    }
}
