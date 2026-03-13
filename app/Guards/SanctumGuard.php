<?php

namespace App\Guards;

use Illuminate\Http\Request;
use Laravel\Sanctum\Guard;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\TransientToken;
use Illuminate\Support\Arr;
use Laravel\Sanctum\Events\TokenAuthenticated;

class SanctumGuard extends Guard
{
    public function __invoke(Request $request)
    {
        foreach (Arr::wrap(config('sanctum.guard', 'web')) as $guard) {
            if ($user = $this->auth->guard($guard)->user()) {
                return $this->supportsTokens($user)
                    ? $user->withAccessToken(new TransientToken)
                    : $user;
            }
        }

        if ($token = $this->getTokenFromRequest($request)) {
            $model = Sanctum::$personalAccessTokenModel;

            $accessToken = $model::findToken($token);

            if (! $this->isValidAccessToken($accessToken) ||
                ! $this->supportsTokens($accessToken->tokenable)) {
                return;
            }

            $tokenable = $accessToken->tokenable->withAccessToken($accessToken);

            event(new TokenAuthenticated($accessToken));

            try {
                if (method_exists($accessToken->getConnection(), 'hasModifiedRecords') &&
                    method_exists($accessToken->getConnection(), 'setRecordModificationState')) {
                    tap($accessToken->getConnection()->hasModifiedRecords(), function ($hasModifiedRecords) use ($accessToken) {
                        $accessToken->forceFill(['last_used_at' => now()])->save();
                        $accessToken->getConnection()->setRecordModificationState($hasModifiedRecords);
                    });
                } else {
                    $accessToken->forceFill(['last_used_at' => now()])->save();
                }
            } catch (\Illuminate\Database\QueryException $e) {
                // Ignore lock wait timeout on personal_access_tokens
            }

            return $tokenable;
        }
    }
}
