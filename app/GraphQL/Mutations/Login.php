<?php

namespace App\GraphQL\Mutations;

use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Validation\ValidationException;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;

final class Login
{
    public function __construct(
        private readonly AuthorizationServer $server,
    ) {}

    /**
     * Exchange a username and password for a Passport access token.
     *
     * The token is issued in-process by handing a password-grant request
     * straight to the OAuth authorization server. This deliberately avoids a
     * loopback HTTP call to /oauth/token, which would deadlock under the
     * single-threaded `php artisan serve`.
     *
     * @param  array{username: string, password: string}  $args
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: ?string}
     */
    public function __invoke(null $_, array $args): array
    {
        $request = (new ServerRequest('POST', '/oauth/token'))->withParsedBody([
            'grant_type' => 'password',
            'client_id' => config('passport.password_client_id'),
            'client_secret' => config('passport.password_client_secret'),
            'username' => $args['username'],
            'password' => $args['password'],
            'scope' => '',
        ]);

        try {
            $response = $this->server->respondToAccessTokenRequest($request, new Psr7Response());
        } catch (OAuthServerException $e) {
            // Invalid credentials (or a misconfigured client) surface as a
            // validation error on the username field rather than a 500.
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getBody(), true);

        return [
            'access_token' => $payload['access_token'],
            'token_type' => $payload['token_type'],
            'expires_in' => $payload['expires_in'],
            'refresh_token' => $payload['refresh_token'] ?? null,
        ];
    }
}
