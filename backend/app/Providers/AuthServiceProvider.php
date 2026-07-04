<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Gate;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        Gate::define('AnyAction' , function(User $user){
            return $user->type === 'SuperAdmin' ;
        });

        // Point the password-reset email link at the SPA reset page (which then
        // POSTs token+email+password to /api/auth/reset-password).
        ResetPassword::createUrlUsing(function (User $user, string $token) {
            $frontend = rtrim(config('services.frontend_url') ?: config('app.url'), '/');
            return $frontend . '/reset-password?token=' . $token
                . '&email=' . urlencode($user->getEmailForVerification());
        });

        // Build the verification link against OUR api route (unique name), not
        // Breeze's web route — avoids the shared-name ambiguity.
        VerifyEmail::createUrlUsing(function ($notifiable) {
            return URL::temporarySignedRoute(
                'api.verification.verify',
                Carbon::now()->addMinutes(60),
                [
                    'id'   => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ]
            );
        });
    }
}
