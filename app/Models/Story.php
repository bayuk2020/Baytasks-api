<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Story extends Model
{
    protected $table = 'stories';

    protected $fillable = [
        'image_path',
        'caption',
    ];

    /**
     * Domain publik WAJIB dipakai untuk membangun image_path -- sengaja
     * TIDAK pakai asset()/config('app.url'), karena server produksi (PC
     * kantor terpisah, diakses lewat Cloudflare Tunnel) pernah punya
     * APP_URL lokal (mis. http://baytasks-api.test) yang bocor ke URL
     * gambar tersimpan. Mengikuti pola tabel `books`: image_path SELALU
     * full absolute URL ke domain publik. Override lewat .env
     * PUBLIC_ASSET_URL kalau domainnya pernah berganti lagi.
     */
    public static function publicStorageUrl(string $relativePath): string
    {
        // Default WAJIB https. Dulu di sini tertulis http://, dan karena URL
        // ini ikut tersimpan ke kolom image_path, gambar lama jadi beralamat
        // http di halaman yang dibuka lewat https -- browser memblokirnya
        // sebagai mixed content, jadi gambarnya tidak muncul meski berkasnya
        // sehat. Nilai sebenarnya diambil dari PUBLIC_ASSET_URL di .env.
        $base = rtrim((string) env('PUBLIC_ASSET_URL', 'https://api.kabyra.my.id'), '/');

        return $base.'/storage/'.ltrim($relativePath, '/');
    }
}
