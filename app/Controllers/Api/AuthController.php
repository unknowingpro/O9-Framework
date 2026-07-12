<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security\Jwt;
use App\Core\Security\RefreshTokenService;
use App\Models\UserModel;

/**
 * Email/password authentication: register, login, refresh, logout. Issues a
 * short-lived JWT access token (typ:access, see Core\Auth) plus a long-lived
 * rotating refresh token (RefreshTokenService) when the refresh_tokens
 * migration is present — gracefully omitted otherwise.
 *
 * Registered behind ThrottleAuth (register/login) so credential-stuffing and
 * enumeration attempts are rate-limited before they ever reach UserModel.
 */
final class AuthController extends BaseController
{
    public function register(Request $request): never
    {
        $data = $this->validate($request->all(), [
            'email'    => 'required|email|unique:users,email',
            'password' => 'required',
        ]);

        try {
            $id = (new UserModel())->register($data['email'], $data['password']);
        } catch (\InvalidArgumentException $e) {
            Response::error('weak_password', $e->getMessage(), 422);
        }

        Response::created($this->issueTokens($id));
    }

    public function login(Request $request): never
    {
        $data = $this->validate($request->all(), [
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $id = (new UserModel())->verifyPassword($data['email'], $data['password']);
        if ($id === null) {
            // Deliberately identical whether the email doesn't exist or the
            // password is wrong — see UserModel::verifyPassword()'s constant-
            // time dummy-hash comparison this relies on.
            Response::unauthorized('Invalid email or password.');
        }

        Response::ok($this->issueTokens($id));
    }

    public function refresh(Request $request): never
    {
        $token = (string) $request->bodyParam('refresh_token', '');
        $result = $token !== '' ? RefreshTokenService::rotate($token) : null;
        if ($result === null) {
            Response::unauthorized('Invalid or expired refresh token.');
        }

        Response::ok([
            'access_token'  => Jwt::encode(['sub' => $result['userId'], 'typ' => 'access'], $this->accessTtl()),
            'refresh_token' => $result['token'],
            'token_type'    => 'Bearer',
        ]);
    }

    public function logout(Request $request): never
    {
        // Best-effort and never fails: a client logging out with an already-
        // expired/stale token must still get a clean 200, not a 401 that
        // blocks it from clearing local state.
        $access = $request->bearerToken();
        if ($access !== null && $access !== '') {
            Jwt::revoke($access);
        }
        $refresh = (string) $request->bodyParam('refresh_token', '');
        if ($refresh !== '') {
            RefreshTokenService::revokeToken($refresh);
        }
        Response::ok(['logged_out' => true]);
    }

    /** @return array<string, mixed> */
    private function issueTokens(int $userId): array
    {
        $body = [
            'access_token' => Jwt::encode(['sub' => $userId, 'typ' => 'access'], $this->accessTtl()),
            'token_type'   => 'Bearer',
        ];
        // Omitted (rather than sent as null) when the app hasn't run the
        // refresh_tokens migration yet — matches RefreshTokenService's own
        // graceful-no-op-without-the-table contract.
        $refreshToken = RefreshTokenService::issue($userId);
        if ($refreshToken !== null) {
            $body['refresh_token'] = $refreshToken;
        }
        return $body;
    }

    private function accessTtl(): int
    {
        return (int) config('app.jwt.ttl', 86400);
    }
}
