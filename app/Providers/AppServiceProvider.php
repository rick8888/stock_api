<?php

namespace App\Providers;

use App\Billing\FakeStripeGateway;
use App\Billing\StripeGateway;
use App\Billing\StripeSdkGateway;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Eloquent\UserRepository;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(StripeGateway::class, function () {
            if (app()->environment('testing') || config('services.billing.stripe.secret') === 'fake') {
                return new FakeStripeGateway;
            }

            return new StripeSdkGateway;
        });
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();
    }
}
