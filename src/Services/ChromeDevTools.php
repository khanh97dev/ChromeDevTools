<?php

namespace ChromeDevTools\Services;

use BadMethodCallException;
use WebSocket\Client;
use WebSocket\ConnectionException;

class ChromeDevTools
{
    protected Client $client;
    protected string $sessionId = '';
    protected int $nextId = 1;
    public string $targetId = '';
    public $log;

    public function __construct(string $wsUrl, $timeout = 15)
    {
        // Define logging closure to use the package's internal Helper::stdout
        $this->log = function (string $msg) {
            fwrite(STDERR, $msg);
        };

        try {
            $this->client = new Client($wsUrl, [
                'timeout' => $timeout
            ]);
        } catch (ConnectionException $e) {
            ($this->log)("WebSocket connection failed: " . $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            ($this->log)("Unexpected error during WebSocket setup: " . $e->getMessage());
            throw $e;
        }
    }

    public function __call($name, $arguments)
    {
        if (is_callable($this->$name)) {
            return call_user_func_array($this->$name, $arguments);
        }

        throw new BadMethodCallException("Method $name does not exist.");
    }

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
        ($this->log)(">>> Sending: {$json}\n");

        try {
            $this->client->send($json);
        } catch (ConnectionException $e) {
            ($this->log)("Send failed: " . $e->getMessage());
            throw $e;
        }

        while (true) {
            try {
                $raw = $this->client->receive();
            } catch (ConnectionException $e) {
                ($this->log)("Receive failed: " . $e->getMessage());
                throw $e;
            }

            ($this->log)("<<< Received: {$raw}\n");
            $msg = json_decode($raw, true);

            if (isset($msg['id']) && $msg['id'] === $id) {
                return $msg;
            }

            // Handle incoming events that are not responses to the current command
            if (isset($msg['method'])) {
                if ($msg['method'] === 'Target.attachedToTarget' && isset($msg['params']['sessionId'])) {
                    $this->sessionId = $msg['params']['sessionId'];
                    ($this->log)(">>> Updated sessionId: {$this->sessionId}\n");
                }
            }
        }
    }

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

    public function navigate(string $url): void
    {
        $this->sendCommand('Page.navigate', ['url' => $url]);

        // Wait for the load event fired
        while (true) {
            $msg = json_decode($this->client->receive(), true);
            if (isset($msg['method']) && $msg['method'] === 'Page.loadEventFired') {
                break;
            }
        }
    }

    public function evaluate(string $expression)
    {
        $resp = $this->sendCommand('Runtime.evaluate', [
            'expression' => $expression
        ]);
        return $resp['result']['result']['value'] ?? null;
    }

    public function getTitle(): string
    {
        return $this->evaluate('document.title') ?? '(no title)';
    }

    public function getUrl(): ?string
    {
        $resp = $this->sendCommand('Runtime.evaluate', [
            'expression' => 'window.location.href'
        ]);

        return $resp['result']['result']['value'] ?? null;
    }

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
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($chars as $char) {
            $this->sendCommand('Input.insertText', [
                'text' => $char
            ]);
            usleep($delayMs * 1000);
        }
    }

    public function pressEnter(): void
    {
        $this->sendCommand('Input.dispatchKeyEvent', [
            'type' => 'keyDown',
            'key' => 'Enter',
            'code' => 'Enter',
            'windowsVirtualKeyCode' => 13,
            'nativeVirtualKeyCode' => 13
        ]);

        $this->sendCommand('Input.dispatchKeyEvent', [
            'type' => 'keyUp',
            'key' => 'Enter',
            'code' => 'Enter',
            'windowsVirtualKeyCode' => 13,
            'nativeVirtualKeyCode' => 13
        ]);
    }

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

    public function waitLoading(): void
    {
        while (true) {
            $raw = $this->client->receive();
            ($this->log)("<<< Event while waiting: {$raw}\n");

            $msg = json_decode($raw, true);
            if (isset($msg['method']) && $msg['method'] === 'Page.loadEventFired') {
                break;
            }
        }
    }

    public function closePage(): void
    {
        $this->sendCommand('Target.closeTarget', [
            'targetId' => $this->targetId
        ], false);
    }

    public function closeBrowser(): void
    {
        $this->sendCommand('Browser.close', [], false);
    }

    public function close(): void
    {
        $this->client->close();
    }
}
