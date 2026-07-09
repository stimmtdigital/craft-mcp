<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\controllers;

use Craft;
use craft\web\Controller;
use craft\web\Response;
use Mcp\Server\Session\FileSessionStore;
use stimmt\craft\Mcp\http\Bridge;
use stimmt\craft\Mcp\http\RecordStore;
use stimmt\craft\Mcp\http\Token;
use stimmt\craft\Mcp\http\Tokens;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\services\McpServerFactory;

/**
 * The HTTP MCP endpoint. Bearer-token auth, per-user identity, then a
 * request-scoped StreamableHttpTransport round trip. GET is refused (no SSE
 * under FPM in v1); DELETE ends the session; OPTIONS is CORS preflight.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class HttpController extends Controller {
    public $enableCsrfValidation = false;

    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE;

    public function actionHandle(): Response {
        $settings = Mcp::settings();
        if (!$settings->enabled || !$settings->httpTransport) {
            return $this->error(404, -32601, 'MCP HTTP transport is not enabled');
        }

        if ($this->request->getMethod() === 'GET') {
            $this->response->getHeaders()->set('Allow', 'POST, DELETE, OPTIONS');

            return $this->error(405, -32601, 'Streaming is not supported; use POST');
        }

        $token = $this->authenticate();
        if ($token === null) {
            $this->response->getHeaders()->set('WWW-Authenticate', 'Bearer');

            return $this->error(401, -32001, 'Invalid or missing bearer token');
        }

        return $this->serve($token);
    }

    private function authenticate(): ?Token {
        $bearer = $this->bearer();
        if ($bearer === null) {
            return null;
        }

        $token = (new Tokens(new RecordStore()))->authenticate($bearer);
        if ($token === null) {
            return null;
        }

        $user = Craft::$app->getUsers()->getUserById($token->userId);
        if ($user === null || $user->suspended || !$user->enabled) {
            return null;
        }

        Craft::$app->getUser()->setIdentity($user);

        return $token;
    }

    private function serve(Token $token): Response {
        $settings = Mcp::settings();
        $logger = McpServerFactory::createFileLogger(logLevel: $settings->logLevel);
        Craft::$container->setSingleton(\Psr\Log\LoggerInterface::class, fn () => $logger);

        $factory = new McpServerFactory(logger: $logger);
        $store = new FileSessionStore(
            Craft::$app->getPath()->getRuntimePath() . '/mcp-sessions',
            $settings->httpSessionTtl,
        );

        $bridge = new Bridge();
        $server = $factory->create(scope: $token->scope, sessionStore: $store);
        $transport = $factory->createHttpTransport($bridge->toPsr7($this->request), $this->request->getHostName());

        // Same discipline as bin/mcp-server: stray echo/print output would
        // corrupt the JSON body, so buffer it away to the log instead.
        ob_start();
        try {
            $psr7 = $server->run($transport);
        } finally {
            $stray = ob_get_clean();
            if (is_string($stray) && $stray !== '') {
                $logger->warning('Stray output during MCP HTTP handling', ['output' => $stray]);
            }
        }

        return $bridge->apply($psr7, $this->response);
    }

    private function bearer(): ?string {
        $header = (string) $this->request->getHeaders()->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $value = trim(substr($header, 7));

        return $value === '' ? null : $value;
    }

    private function error(int $status, int $code, string $message): Response {
        $this->response->format = Response::FORMAT_RAW;
        $this->response->setStatusCode($status);
        $this->response->getHeaders()->set('Content-Type', 'application/json');
        $this->response->content = json_encode([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => $code, 'message' => $message],
        ], JSON_THROW_ON_ERROR);

        return $this->response;
    }
}
