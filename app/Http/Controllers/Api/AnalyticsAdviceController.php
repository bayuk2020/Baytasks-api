<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiService;
use Illuminate\Http\Request;
use Throwable;

/**
 * Saran AI atas hasil Analytics: satu paragraf per modul + ringkasan
 * keseluruhan dan langkah konkret berikutnya.
 *
 * Memakai AiService yang sama dengan bot Telegram & widget chat (rantai
 * fallback antar provider dari .env), tapi TANPA tools -- di sini AI cuma
 * diminta menafsirkan angka yang sudah dihitung
 * App\Http\Controllers\Api\AnalyticsOverviewController, bukan mengambil data
 * sendiri. Itu penting supaya angka di saran tidak pernah berbeda dari angka
 * yang tampil di layar.
 */
class AnalyticsAdviceController extends Controller
{
    /** Modul yang boleh diminta sarannya. */
    private const MODULES = ['tasks', 'focus', 'habits', 'finance', 'journal', 'reading', 'goals'];

    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $days = $validated['days'] ?? 30;

        // Ambil ringkasannya langsung dari controller Analytics -- jangan
        // hitung ulang di sini, supaya tidak ada dua sumber angka.
        $overviewRequest = Request::create('/api/analytics/overview', 'GET', ['days' => $days]);
        $overview = json_decode(
            (new AnalyticsOverviewController())($overviewRequest)->getContent(),
            true
        );

        $payload = $this->compact($overview);

        try {
            $result = (new AiService())->chat(
                [
                    ['role' => 'system', 'content' => $this->systemPrompt($days)],
                    ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
                ],
                [],                       // tanpa tools -- murni analisis teks
                fn () => [],              // executor tidak akan pernah dipanggil
                1                         // satu iterasi saja
            );
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'AI sedang tidak bisa dihubungi: '.$e->getMessage(),
            ], 503);
        }

        $parsed = $this->parse($result['content'] ?? '');

        return response()->json([
            'success' => true,
            'provider' => $result['provider'] ?? null,
            'days' => $days,
            'modules' => $parsed['modules'],
            'summary' => $parsed['summary'],
            'nextSteps' => $parsed['nextSteps'],
            'generatedAt' => now()->toIso8601String(),
        ]);
    }

    /**
     * Rampingkan overview jadi angka-angka penting saja. Deret harian penuh
     * (bisa 365 baris) tidak perlu dikirim ke AI -- boros token dan tidak
     * menambah kualitas saran.
     */
    private function compact(array $o): array
    {
        $daily = $o['daily'] ?? [];
        $scores = array_column($daily, 'score');
        $activeScoreDays = array_filter($scores, fn ($s) => $s > 0);

        return [
            'rentang_hari' => $o['range']['days'] ?? null,
            'tasks' => [
                'dibuat' => $o['tasks']['createdInRange'] ?? 0,
                'selesai' => $o['tasks']['completedInRange'] ?? 0,
                'masih_terbuka' => $o['tasks']['openTotal'] ?? 0,
                'lewat_deadline' => $o['tasks']['overdue'] ?? 0,
                'persen_penyelesaian' => $o['tasks']['completionRate'] ?? 0,
                'rata_jam_penyelesaian' => $o['tasks']['avgCompletionHours'] ?? null,
                'per_prioritas' => $o['tasks']['byPriority'] ?? [],
            ],
            'focus' => [
                'total_menit' => (int) round(($o['focus']['focusSeconds'] ?? 0) / 60),
                'jumlah_sesi' => $o['focus']['sessionCount'] ?? 0,
                'hari_ada_fokus' => $o['focus']['activeDays'] ?? 0,
                'rata_menit_per_hari_aktif' => (int) round(($o['focus']['avgPerActiveDaySeconds'] ?? 0) / 60),
                'rasio_fokus_persen' => $o['focus']['focusRatio'] ?? 0,
            ],
            'habits' => [
                'jumlah_aktif' => $o['habits']['activeCount'] ?? 0,
                'total_penyelesaian' => $o['habits']['completionsInRange'] ?? 0,
                'konsistensi_persen' => $o['habits']['overallConsistency'] ?? 0,
                'terbaik' => array_slice($o['habits']['breakdown'] ?? [], 0, 3),
                'terlemah' => array_slice(array_reverse($o['habits']['breakdown'] ?? []), 0, 3),
            ],
            'finance' => [
                'pemasukan' => $o['finance']['income'] ?? 0,
                'pengeluaran' => $o['finance']['expense'] ?? 0,
                'arus_kas' => $o['finance']['cashflow'] ?? 0,
                'rasio_tabungan_persen' => $o['finance']['savingRate'] ?? 0,
                'kategori_boros' => $o['finance']['topExpenseCategories'] ?? [],
            ],
            'journal' => $o['journal'] ?? [],
            'reading' => $o['reading'] ?? [],
            'goals' => $o['goals'] ?? [],
            'score_harian' => [
                'rata_rata_hari_aktif' => count($activeScoreDays) > 0
                    ? (int) round(array_sum($activeScoreDays) / count($activeScoreDays))
                    : 0,
                'tertinggi' => count($scores) > 0 ? max($scores) : 0,
                'jumlah_hari_aktif' => count($activeScoreDays),
            ],
        ];
    }

    private function systemPrompt(int $days): string
    {
        $modules = implode('|', self::MODULES);

        return <<<PROMPT
            Kamu adalah analis produktivitas pribadi Bayu di aplikasi "BayTasks". Kamu
            menerima ringkasan data {$days} hari terakhir dalam bentuk JSON. Tugasmu
            MENAFSIRKAN angka itu -- bukan mengulang-ulang angkanya saja.

            Bahasa Indonesia, santai tapi tajam. Jujur: kalau datanya jelek, katakan apa
            adanya dengan sopan. Kalau sebuah modul datanya kosong/nol, jangan mengarang
            pujian -- bilang saja belum ada data dan sarankan langkah paling kecil untuk
            mulai.

            Balas PERSIS dalam format di bawah ini, tanpa markdown heading, tanpa basa-basi
            pembuka atau penutup:

            [tasks] satu paragraf (maksimal 3 kalimat) tentang manajemen tugas.
            [focus] satu paragraf tentang waktu fokus & Pomodoro.
            [habits] satu paragraf tentang konsistensi kebiasaan.
            [finance] satu paragraf tentang kondisi keuangan.
            [journal] satu paragraf tentang kebiasaan menulis jurnal.
            [reading] satu paragraf tentang aktivitas membaca.
            [goals] satu paragraf tentang progres goals.
            [summary] 2-4 kalimat merangkum kondisi keseluruhan: apa yang sudah bagus, apa
            yang paling menghambat.
            [next] tiga langkah konkret untuk ke depan, satu per baris, diawali "- ".
            Buat spesifik & terukur (mis. "- Naikkan fokus ke 2 jam/hari dengan 4 sesi
            Pomodoro 30 menit"), bukan nasihat umum seperti "tetap semangat".

            Nama tag di dalam kurung siku WAJIB persis salah satu dari: {$modules}|summary|next.
            PROMPT;
    }

    /**
     * Pecah balasan bertag `[modul] isi` menjadi struktur siap-render.
     *
     * @return array{modules: array<string, string>, summary: string, nextSteps: string[]}
     */
    private function parse(string $raw): array
    {
        $modules = [];
        $summary = '';
        $next = [];

        // Pisahkan di setiap tag pembuka di awal baris.
        $parts = preg_split(
            '/^\s*\[([a-z]+)\]\s*/mi',
            $raw,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        ) ?: [];

        for ($i = 0; $i < count($parts) - 1; $i += 2) {
            $tag = strtolower(trim($parts[$i]));
            $body = trim($parts[$i + 1]);

            if ($tag === 'summary') {
                $summary = $body;
            } elseif ($tag === 'next') {
                foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
                    $line = trim(ltrim(trim($line), '-•*'));
                    if ($line !== '') {
                        $next[] = $line;
                    }
                }
            } elseif (in_array($tag, self::MODULES, true)) {
                $modules[$tag] = $body;
            }
        }

        // Jaring pengaman: kalau AI mengabaikan format, jangan tampilkan kosong --
        // taruh apa adanya di ringkasan supaya user tetap dapat sesuatu.
        if (empty($modules) && $summary === '') {
            $summary = trim($raw);
        }

        return ['modules' => $modules, 'summary' => $summary, 'nextSteps' => $next];
    }
}
