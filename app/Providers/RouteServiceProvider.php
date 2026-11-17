<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        // 60/menit terlalu ketat untuk SPA ini. Satu kali buka halaman Finance saja
        // memanggil 8 endpoint; sebelum perbaikan store-nya, 30. Jadi dua kali buka
        // halaman dalam satu menit sudah cukup untuk kena 429 Too Many Requests —
        // itulah error yang muncul sporadis di modul Finance.
        //
        // Yang membuatnya jauh lebih parah: semua request masuk lewat Cloudflare
        // Tunnel, jadi tanpa TrustProxies yang benar $request->ip() selalu
        // 127.0.0.1 (alamat cloudflared). Akibatnya SELURUH pengguna dan seluruh
        // tab berbagi SATU jatah 60/menit. Lihat app/Http/Middleware/TrustProxies.php
        // — di sana proxy lokal sekarang dipercaya supaya kuota ini per klien asli.
        //
        // Catatan: TransactionController::store() memasang throttle:5000,1 untuk
        // import massal, tapi limiter grup ini tetap ikut berjalan — selama masih
        // 60, override itu tidak pernah benar-benar berlaku.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(300)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
