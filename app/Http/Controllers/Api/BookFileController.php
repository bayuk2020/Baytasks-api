<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Menyajikan berkas PDF sebuah buku LEWAT rute API.
 *
 * Kenapa tidak langsung memakai `books.file_path` yang sudah berupa URL ke
 * /storage/...? Karena berkas di /storage disajikan langsung oleh server
 * (artisan serve / Apache) tanpa melewati Laravel, jadi middleware CORS
 * tidak pernah jalan untuknya. Selama PDF dibuka lewat <iframe> itu tidak
 * masalah -- browser yang mengurus. Begitu pdf.js yang mengambilnya dengan
 * fetch dari domain berbeda (web di baytasks.kabyra.my.id, berkas di
 * api.kabyra.my.id), permintaannya ditolak aturan lintas domain.
 *
 * Rute ini ada di bawah `api/*`, jadi header CORS ikut terpasang.
 */
class BookFileController extends Controller
{
    public function show(Request $request, $bookId)
    {
        $book = Book::find($bookId);

        if (! $book || empty($book->file_path)) {
            return response()->json(['error' => 'Buku tidak punya berkas PDF.'], 404);
        }

        $jalur = $this->jalurDiDisk($book->file_path);

        if ($jalur === null || ! is_file($jalur)) {
            return response()->json([
                'error' => 'Berkas PDF tidak ditemukan di penyimpanan. Unggah ulang dari halaman buku.',
            ], 404);
        }

        $respons = new BinaryFileResponse($jalur, 200, [
            'Content-Type'  => 'application/pdf',
            // inline, bukan attachment: pdf.js membacanya sebagai data,
            // bukan sebagai unduhan.
            'Cache-Control' => 'private, max-age=3600',
        ]);

        // Mengizinkan permintaan sebagian (Range). pdf.js memakainya untuk
        // menggambar halaman pertama tanpa menunggu seluruh berkas -- penting
        // karena PDF di sini berukuran megabyte.
        $respons->setAutoEtag();
        $respons->setContentDisposition('inline', basename($jalur));

        return $respons;
    }

    /**
     * Mengubah nilai `file_path` menjadi jalur berkas di disk.
     *
     * Nilainya bisa berupa URL penuh (pola yang dipakai sekarang) atau jalur
     * relatif (data lama), jadi keduanya ditangani. Hasilnya dipastikan tetap
     * berada di dalam folder storage/app/public -- tanpa pemeriksaan itu,
     * nilai seperti "../../.env" bisa menyeret berkas di luar sana.
     */
    private function jalurDiDisk(string $filePath): ?string
    {
        $relatif = $filePath;

        if (str_starts_with($filePath, 'http://') || str_starts_with($filePath, 'https://')) {
            $relatif = (string) parse_url($filePath, PHP_URL_PATH);
        }

        $relatif = ltrim($relatif, '/');

        if (str_starts_with($relatif, 'storage/')) {
            $relatif = substr($relatif, strlen('storage/'));
        }

        $relatif = urldecode($relatif);

        $akar = realpath(storage_path('app/public'));
        $penuh = realpath($akar . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relatif));

        if ($akar === false || $penuh === false) {
            return null;
        }

        // Harus benar-benar berada di dalam akar penyimpanan.
        return str_starts_with($penuh, $akar . DIRECTORY_SEPARATOR) ? $penuh : null;
    }
}
