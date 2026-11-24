<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda halaman (bookmark) untuk modul Library.
 *
 * Beda dengan `book_notes`: catatan berisi tulisan panjang tentang sebuah
 * halaman, sedangkan bookmark cuma berkata "balik lagi ke sini". Karena itu
 * `label` boleh kosong -- menandai halaman tidak boleh menuntut user
 * mengarang judul dulu.
 *
 * `unique(book_id, page_number)` dipasang supaya satu halaman hanya punya
 * satu penanda. Dengan begitu tombolnya bisa jadi sakelar sederhana
 * (tandai / lepas) alih-alih menumpuk penanda ganda di halaman yang sama.
 *
 * Catatan: `migrations` di proyek ini cuma mencatat sebagian berkas, jadi
 * jalankan khusus berkas ini:
 *   php artisan migrate --path=database/migrations/2026_11_24_110000_create_book_bookmarks_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('book_bookmarks')) {
            return;
        }

        Schema::create('book_bookmarks', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('book_id');

            $table->unsignedInteger('page_number');

            // Judul singkat pilihan user, mis. "bab tentang aset vs liabilitas".
            $table->string('label', 255)->nullable();

            // Warna pita penanda. Disimpan sebagai nama kunci (bukan hex)
            // supaya ikut berganti nada di tema terang -- lihat
            // src/lib/bookmarkColors.ts di sisi web.
            $table->string('color', 32)->nullable();

            $table->timestamps();

            $table->unique(['book_id', 'page_number']);

            $table->foreign('book_id')
                ->references('id')
                ->on('books')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_bookmarks');
    }
};
