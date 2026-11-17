<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * Aplikasi ini dipublikasikan lewat Cloudflare Tunnel: cloudflared berjalan di
     * mesin yang sama dan meneruskan request ke 127.0.0.1:8000, sambil mengisi
     * X-Forwarded-For dengan IP klien yang sebenarnya.
     *
     * Selama daftar ini null, Laravel tidak mempercayai header itu, sehingga
     * $request->ip() selalu mengembalikan 127.0.0.1 untuk SEMUA pengunjung. Efeknya:
     *   - rate limiter 'api' memakai satu jatah bersama untuk seluruh pengguna
     *   - log & throttle tidak bisa membedakan siapa pemanggilnya
     *
     * Hanya loopback yang dipercaya (bukan '*'), karena satu-satunya pihak yang bisa
     * mencapai origin memang cloudflared di localhost — port 8000 di-bind ke
     * 127.0.0.1 saja, jadi tidak ada klien luar yang bisa memalsukan header ini.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = [
        '127.0.0.1',
        '::1',
    ];

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
