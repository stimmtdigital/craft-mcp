<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/InMemoryTokenStore.php';
require_once __DIR__ . '/../../Fixtures/InMemoryOAuthStore.php';
require_once __DIR__ . '/../../Fixtures/ForwardingOAuthStore.php';

use stimmt\craft\Mcp\http\oauth\Authorizer;
use stimmt\craft\Mcp\http\oauth\Grants;
use stimmt\craft\Mcp\http\oauth\Metadata;
use stimmt\craft\Mcp\http\oauth\OAuthConfig;
use stimmt\craft\Mcp\http\oauth\OAuthError;
use stimmt\craft\Mcp\http\oauth\Pkce;
use stimmt\craft\Mcp\http\oauth\RedirectUris;
use stimmt\craft\Mcp\http\oauth\Registration;
use stimmt\craft\Mcp\http\oauth\TokenExchange;
use stimmt\craft\Mcp\http\Scope;
use stimmt\craft\Mcp\http\Tokens;
use stimmt\craft\Mcp\Tests\Fixtures\InMemoryOAuthStore;
use stimmt\craft\Mcp\Tests\Fixtures\InMemoryTokenStore;

/**
 * The OAuth connector end to end against in-memory stores and a clock the
 * test moves: register, authorize, exchange the code, refresh, and every way
 * each of those steps must refuse.
 */
const CLAUDE_CALLBACK = 'https://claude.ai/api/mcp/auth_callback';
const VERIFIER = 'dBjftJeZ4CVP-mJ92K9-wC5sJ4WkgfKLlxH5Mm4yCVkvt1Ns_QkcRV9';

function challenge(string $verifier = VERIFIER): string {
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
}

beforeEach(function () {
    $this->clock = new DateTimeImmutable('2026-10-14 12:00:00');
    $now = fn (): DateTimeImmutable => $this->clock;
    $this->store = new InMemoryOAuthStore();
    $this->tokenStore = new InMemoryTokenStore();
    $this->tokens = new Tokens($this->tokenStore, $now);
    $this->config = new OAuthConfig(
        'https://cms.example.com',
        'https://cms.example.com/mcp',
        'https://cms.example.com/admin/mcp-connect',
        new RedirectUris(RedirectUris::DEFAULTS),
        disabledScopes: [],
    );
    $this->registration = new Registration($this->store, $this->config->redirectUris, $now);
    $this->authorizer = new Authorizer($this->store, $this->config, $now);
    $this->grants = new Grants($this->store, $this->tokens, $now);
    $this->exchange = new TokenExchange($this->store, $this->tokens, $this->grants, $this->config, $now);

    $this->clientId = $this->registration->register(['client_name' => 'Claude', 'redirect_uris' => [CLAUDE_CALLBACK]])['client_id'];
    $this->params = [
        'client_id' => $this->clientId,
        'redirect_uri' => CLAUDE_CALLBACK,
        'response_type' => 'code',
        'code_challenge' => challenge(),
        'code_challenge_method' => 'S256',
        'state' => 'xyz',
        'scope' => 'readonly content full',
    ];
    $this->authorize = function (Scope $scope = Scope::Content, int $userId = 5): string {
        $client = $this->authorizer->identify($this->params);
        $redirect = $this->authorizer->approve($this->authorizer->validate($client, $this->params), $userId, $scope);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

        return $query['code'];
    };
    $this->redeem = fn (string $code, array $overrides = []): array => $this->exchange->exchange($overrides + [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'client_id' => $this->clientId,
        'redirect_uri' => CLAUDE_CALLBACK,
        'code_verifier' => VERIFIER,
    ]);
});

describe('registration', function () {
    it('registers a public client for an allowed callback', function () {
        $response = $this->registration->register(['client_name' => 'Claude', 'redirect_uris' => [CLAUDE_CALLBACK]]);

        expect($response['client_id'])->toStartWith('mcpc_')
            ->and($response['token_endpoint_auth_method'])->toBe('none')
            ->and($response['redirect_uris'])->toBe([CLAUDE_CALLBACK]);
    });

    // Open registration is what lets a Claude user connect without the site
    // owner's help, so the callback allowlist is all that stops a phishing app
    // registering its own server as the place consents are sent.
    it('refuses a redirect URI that is not on the allowlist', function () {
        expect(fn () => $this->registration->register(['redirect_uris' => ['https://evil.example/callback']]))
            ->toThrow(OAuthError::class, 'redirect URI');
    });

    it('accepts Claude Code\'s loopback callback on any port, and nothing else on loopback', function () {
        $uris = new RedirectUris(RedirectUris::DEFAULTS);

        expect($uris->allows('http://localhost:53682/callback'))->toBeTrue()
            ->and($uris->allows('http://127.0.0.1:3118/callback'))->toBeTrue()
            ->and($uris->allows('http://localhost:3118/elsewhere'))->toBeFalse()
            ->and($uris->allows('https://localhost:3118/callback'))->toBeFalse()
            ->and($uris->allows('http://localhost.evil.example/callback'))->toBeFalse()
            ->and($uris->allows('http://localhost:3118/callback?next=evil'))->toBeFalse();
    });

    it('refuses a confidential client, which this server has no secret for', function () {
        expect(fn () => $this->registration->register(['redirect_uris' => [CLAUDE_CALLBACK], 'token_endpoint_auth_method' => 'client_secret_basic']))
            ->toThrow(OAuthError::class, 'public clients');
    });
});

describe('authorization request', function () {
    it('refuses an unknown client without redirecting anywhere', function () {
        expect(fn () => $this->authorizer->identify(['client_id' => 'nope', 'redirect_uri' => CLAUDE_CALLBACK]))
            ->toThrow(OAuthError::class);
    });

    it('refuses a redirect URI the client did not register', function () {
        expect(fn () => $this->authorizer->identify(['redirect_uri' => 'https://claude.com/api/mcp/auth_callback'] + $this->params))
            ->toThrow(OAuthError::class, 'redirect URI');
    });

    it('requires PKCE with S256, and refuses plain', function () {
        $client = $this->authorizer->identify($this->params);

        expect(fn () => $this->authorizer->validate($client, ['code_challenge_method' => 'plain'] + $this->params))
            ->toThrow(OAuthError::class, 'S256')
            ->and(fn () => $this->authorizer->validate($client, array_diff_key($this->params, ['code_challenge' => 1])))
            ->toThrow(OAuthError::class);
    });

    it('refuses a token meant for another resource', function () {
        $client = $this->authorizer->identify($this->params);

        expect(fn () => $this->authorizer->validate($client, ['resource' => 'https://other.example/mcp'] + $this->params))
            ->toThrow(OAuthError::class, 'only issues tokens');
    });

    it('sends the code back with the state and the issuer', function () {
        $client = $this->authorizer->identify($this->params);
        $redirect = $this->authorizer->approve($this->authorizer->validate($client, $this->params), 5, Scope::Content);

        expect($redirect)->toStartWith(CLAUDE_CALLBACK . '?code=')
            ->toContain('state=xyz')
            ->toContain('iss=' . rawurlencode('https://cms.example.com'));
    });

    it('refuses to grant a scope the app did not ask for', function () {
        $client = $this->authorizer->identify($this->params);
        $request = $this->authorizer->validate($client, ['scope' => 'readonly'] + $this->params);

        expect(fn () => $this->authorizer->approve($request, 5, Scope::Full))->toThrow(OAuthError::class, 'did not ask');
    });

    it('keeps only the scopes this install issues', function () {
        $config = new OAuthConfig('https://cms.example.com', 'https://cms.example.com/mcp', 'x', new RedirectUris(RedirectUris::DEFAULTS), disabledScopes: ['full']);
        $authorizer = new Authorizer($this->store, $config);
        $client = $authorizer->identify($this->params);

        expect($authorizer->validate($client, $this->params)->requestedScopes)->toBe([Scope::ReadOnly, Scope::Content])
            ->and(fn () => $authorizer->approve($authorizer->validate($client, $this->params), 5, Scope::Full))
            ->toThrow(OAuthError::class, 'disabled');
    });
});

describe('token exchange', function () {
    it('turns a code into a short-lived access token tied to the grant, and a refresh token', function () {
        $response = ($this->redeem)(($this->authorize)(Scope::Content));
        $token = $this->tokens->authenticate($response['access_token']);

        expect($response)->toMatchArray(['token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => 'content'])
            ->and($response['access_token'])->toMatch('/^mcp_[A-Za-z0-9]{40}$/')
            ->and($token?->scope)->toBe(Scope::Content)
            ->and($token?->userId)->toBe(5)
            ->and($token?->isOAuth())->toBeTrue()
            ->and($token?->expiryDate)->toEqual(new DateTimeImmutable('2026-10-14 13:00:00'));
    });

    it('lets the access token expire after an hour', function () {
        $response = ($this->redeem)(($this->authorize)());
        $this->clock = $this->clock->modify('+3601 seconds');

        expect($this->tokens->authenticate($response['access_token']))->toBeNull();
    });

    it('refuses a wrong verifier', function () {
        expect(fn () => ($this->redeem)(($this->authorize)(), ['code_verifier' => str_repeat('a', 43)]))
            ->toThrow(OAuthError::class, 'code_verifier');
    });

    it('refuses a redirect URI that differs from the authorization request', function () {
        expect(fn () => ($this->redeem)(($this->authorize)(), ['redirect_uri' => 'https://claude.com/api/mcp/auth_callback']))
            ->toThrow(OAuthError::class, 'redirect_uri');
    });

    it('refuses a code presented by another client', function () {
        $other = $this->registration->register(['redirect_uris' => [CLAUDE_CALLBACK]])['client_id'];

        expect(fn () => ($this->redeem)(($this->authorize)(), ['client_id' => $other]))
            ->toThrow(OAuthError::class, 'different client');
    });

    it('refuses a code after a minute', function () {
        $code = ($this->authorize)();
        $this->clock = $this->clock->modify('+61 seconds');

        expect(fn () => ($this->redeem)($code))->toThrow(OAuthError::class, 'expired');
    });

    // A code seen twice has been copied. RFC 6749 section 4.1.2 says revoke
    // what was issued from it.
    it('treats a second use of a code as theft and revokes everything it issued', function () {
        $code = ($this->authorize)();
        $first = ($this->redeem)($code);

        expect(fn () => ($this->redeem)($code))->toThrow(OAuthError::class, 'already used')
            ->and($this->tokens->authenticate($first['access_token']))->toBeNull()
            ->and($this->store->liveGrants())->toBe([]);
    });
});

describe('refresh', function () {
    beforeEach(function () {
        $this->first = ($this->redeem)(($this->authorize)());
        $this->refresh = fn (string $token): array => $this->exchange->exchange(['grant_type' => 'refresh_token', 'refresh_token' => $token, 'client_id' => $this->clientId]);
    });

    it('rotates: a new access token and a new refresh token', function () {
        $second = ($this->refresh)($this->first['refresh_token']);

        expect($second['refresh_token'])->not->toBe($this->first['refresh_token'])
            ->and($this->tokens->authenticate($second['access_token']))->not->toBeNull();
    });

    it('revokes the whole grant when an old refresh token comes back', function () {
        $second = ($this->refresh)($this->first['refresh_token']);

        expect(fn () => ($this->refresh)($this->first['refresh_token']))->toThrow(OAuthError::class, 'already used')
            ->and($this->tokens->authenticate($second['access_token']))->toBeNull()
            ->and(fn () => ($this->refresh)($second['refresh_token']))->toThrow(OAuthError::class, 'disconnected');
    });

    it('stops working once the user disconnects the app', function () {
        $grantId = $this->store->liveGrants()[0]->id;
        $this->grants->revoke($grantId);

        expect($this->tokens->authenticate($this->first['access_token']))->toBeNull()
            ->and(fn () => ($this->refresh)($this->first['refresh_token']))->toThrow(OAuthError::class, 'disconnected');
    });

    it('refuses a refresh token after its lifetime', function () {
        $this->clock = $this->clock->modify('+31 days');

        expect(fn () => ($this->refresh)($this->first['refresh_token']))->toThrow(OAuthError::class, 'expired');
    });
});

// What a user may hold can change after they approve: losing the token
// permission, losing admin, or a scope being disabled. Every exchange asks again.
describe('the user\'s standing is checked on every exchange', function () {
    beforeEach(function () {
        $this->allowed = true;
        $policy = fn (int $userId, Scope $scope): bool => $this->allowed;
        $this->exchange = new TokenExchange($this->store, $this->tokens, $this->grants, $this->config, fn (): DateTimeImmutable => $this->clock, $policy);
        $this->first = ($this->redeem)(($this->authorize)(Scope::Full));
    });

    it('revokes the grant when the user may no longer hold its scope', function () {
        $this->allowed = false;

        expect(fn () => $this->exchange->exchange(['grant_type' => 'refresh_token', 'refresh_token' => $this->first['refresh_token'], 'client_id' => $this->clientId]))
            ->toThrow(OAuthError::class, 'no longer')
            ->and($this->tokens->authenticate($this->first['access_token']))->toBeNull()
            ->and($this->store->liveGrants())->toBe([]);
    });

    it('refuses to redeem a code for a user who lost the scope after approving', function () {
        $code = ($this->authorize)(Scope::Full);
        $this->allowed = false;

        expect(fn () => ($this->redeem)($code))->toThrow(OAuthError::class, 'no longer');
    });
});

// A Disconnect that lands after an exchange passed its checks but before the
// new token is written must still win: the token issued in between is removed.
describe('a disconnect racing a refresh', function () {
    it('leaves no working token behind', function () {
        $first = ($this->redeem)(($this->authorize)());
        $grantId = (int) $this->store->liveGrants()[0]->id;
        $store = new class ($this->store, $this->grants, $grantId) extends stimmt\craft\Mcp\Tests\Fixtures\ForwardingOAuthStore {
            public function __construct($inner, private $grants, private int $grantId) {
                parent::__construct($inner);
            }

            public function insertRefreshToken(stimmt\craft\Mcp\http\oauth\RefreshToken $token, string $hash): void {
                $this->grants->revoke($this->grantId);
                parent::insertRefreshToken($token, $hash);
            }
        };
        $exchange = new TokenExchange($store, $this->tokens, $this->grants, $this->config, fn (): DateTimeImmutable => $this->clock);

        expect(fn () => $exchange->exchange(['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'], 'client_id' => $this->clientId]))
            ->toThrow(OAuthError::class, 'disconnected')
            ->and(array_filter($this->tokenStore->all(), static fn ($token): bool => $token->isOAuth()))->toBe([]);
    });
});

describe('metadata', function () {
    it('names this server as the authorization server for exactly the MCP URL', function () {
        $metadata = new Metadata($this->config);

        expect($metadata->protectedResource())->toMatchArray([
            'resource' => 'https://cms.example.com/mcp',
            'authorization_servers' => ['https://cms.example.com'],
        ])->and($metadata->authorizationServer())->toMatchArray([
            'issuer' => 'https://cms.example.com',
            'authorization_endpoint' => 'https://cms.example.com/admin/mcp-connect',
            'token_endpoint' => 'https://cms.example.com/mcp/oauth/token',
            'registration_endpoint' => 'https://cms.example.com/mcp/oauth/register',
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
        ]);
    });

    it('points the 401 at the path-inserted metadata document', function () {
        expect($this->config->protectedResourceMetadataUrl())->toBe('https://cms.example.com/.well-known/oauth-protected-resource/mcp');
    });

    it('never advertises a disabled scope', function () {
        $config = new OAuthConfig('https://cms.example.com', 'https://cms.example.com/mcp', 'x', new RedirectUris([]), disabledScopes: ['full']);

        expect((new Metadata($config))->authorizationServer()['scopes_supported'])->toBe(['readonly', 'content']);
    });
});

it('verifies an S256 challenge and refuses a verifier RFC 7636 would not allow', function () {
    expect(Pkce::verify(VERIFIER, challenge()))->toBeTrue()
        ->and(Pkce::verify(VERIFIER . 'x', challenge()))->toBeFalse()
        ->and(Pkce::verify('short', challenge('short')))->toBeFalse()
        ->and(Pkce::verify(str_repeat('a', 129), challenge(str_repeat('a', 129))))->toBeFalse();
});
