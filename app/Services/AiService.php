<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Client AI dengan rantai fallback berlapis dua, terjemahan dari core/llm.py
 * (proyek unimus-chatbot):
 *   1. Antar KEY dalam satu provider (key #1 gagal -> otomatis coba key #2, dst)
 *   2. Antar PROVIDER, urutan sesuai PROVIDER_CHAIN_ORDER di bawah
 *
 * Plus satu lapis di atasnya yang tidak ada di versi Python: RECURSIVE TOOL
 * CALLING (lihat chat()) -- kalau AI minta panggil tool, hasilnya dieksekusi,
 * disisipkan balik ke riwayat percakapan, lalu AI dipanggil lagi sampai dia
 * akhirnya membalas dengan teks biasa (atau sampai batas iterasi tercapai).
 *
 * CARA NAMBAH PROVIDER BARU:
 *   1. Kalau providernya OpenAI-compatible (mayoritas provider gratis begini),
 *      tambah satu baris di OPENAI_COMPATIBLE_PROVIDERS (display name, url, model default).
 *   2. Tambah nama env-nya ke PROVIDER_CHAIN_ORDER supaya urutan fallback-nya diatur.
 *   3. Isi env var `{NAMA}_API_KEYS` (boleh lebih dari satu, dipisah koma) di .env.
 *   Tidak perlu tulis method baru sama sekali kecuali providernya punya format
 *   request/response yang beda (seperti Gemini).
 */
class AiService
{
    /**
     * env_name => [display_name, endpoint_url, model_default]
     */
    private const OPENAI_COMPATIBLE_PROVIDERS = [
        // Nama model DIPERIKSA ULANG 2026-11-18 lewat endpoint /models masing-masing
        // provider, lalu diuji satu per satu DENGAN function calling. Jangan ganti
        // nama model dari ingatan -- model gratis sering dipensiunkan diam-diam, dan
        // kalau namanya salah provider-nya balas 404/410 lalu rantai fallback
        // membuang 10 detik per provider mati (lihat TIMEOUT_SECONDS).
        'GROQ'        => ['Groq',          'https://api.groq.com/openai/v1/chat/completions',          'openai/gpt-oss-120b'],
        'OPENROUTER'  => ['OpenRouter',    'https://openrouter.ai/api/v1/chat/completions',            'nvidia/nemotron-3.5-lightning:free'],
        'COHERE'      => ['Cohere',        'https://api.cohere.ai/compatibility/v1/chat/completions',  'command-a-03-2025'],
        'MISTRAL'     => ['Mistral',       'https://api.mistral.ai/v1/chat/completions',               'mistral-small-latest'],
        'NVIDIA'      => ['NVIDIA NIM',    'https://integrate.api.nvidia.com/v1/chat/completions',     'nvidia/nemotron-3.5-lightning-30b-a3b'],
        'CEREBRAS'    => ['Cerebras',      'https://api.cerebras.ai/v1/chat/completions',              'llama-3.3-70b'],
        'SAMBANOVA'   => ['SambaNova',     'https://api.sambanova.ai/v1/chat/completions',             'Meta-Llama-3.1-70B-Instruct'],
        'TOGETHER'    => ['Together AI',   'https://api.together.xyz/v1/chat/completions',             'meta-llama/Llama-3.3-70B-Instruct-Turbo-Free'],
        'DEEPSEEK'    => ['DeepSeek',      'https://api.deepseek.com/chat/completions',                'deepseek-chat'],
        'FIREWORKS'   => ['Fireworks AI',  'https://api.fireworks.ai/inference/v1/chat/completions',   'accounts/fireworks/models/llama-v3p3-70b-instruct'],
        'HUGGINGFACE' => ['Hugging Face',  'https://router.huggingface.co/v1/chat/completions',        'deepseek-ai/DeepSeek-V3'],
        'AI21'        => ['AI21',          'https://api.ai21.com/studio/v1/chat/completions',          'jamba-mini'],
    ];

    /**
     * Urutan fallback ANTAR PROVIDER. Ubah urutan ini kapan saja tanpa
     * menyentuh kode lain di bawah.
     *
     * Urutan ini bukan selera -- ini hasil pengukuran 2026-11-18, diuji dengan
     * function calling (yang wajib untuk BayTasks, karena AI harus bisa
     * memanggil create_task / record_transaction / dst):
     *
     *   Gemini  2716 ms  OK      <- setelah perbaikan enum, lihat toGeminiTools()
     *   Groq    1656 ms  OK      (openai/gpt-oss-120b)
     *   OpenRtr 2329 ms  OK      (nvidia/nemotron-3.5-lightning:free)
     *   Cohere  2492 ms  OK      (command-a-03-2025; model lama command-r-plus
     *                             masih hidup tapi 15349 ms -- terlalu lambat
     *                             untuk webhook Telegram)
     *
     * Empat provider di atas cukup. Sisanya SENGAJA tidak dimasukkan ke rantai
     * karena saat diuji semuanya gagal dan masing-masing memakan jatah timeout:
     *   Mistral      429 Rate limit exceeded (key valid, cuma kena limit)
     *   NVIDIA NIM   timeout >30 detik dari jaringan ini
     *   Cerebras     401 Wrong API Key
     *   SambaNova    401 Incorrect API key provided
     *   DeepSeek     402 Insufficient Balance
     *   Fireworks    412 Account suspended
     *   HuggingFace  402 butuh pre-paid credits
     *   Together/AI21 belum ada key di .env
     *
     * Kalau salah satunya sudah dibereskan (key baru / saldo diisi), cukup
     * tambahkan kembali namanya ke daftar ini -- implementasinya sudah ada.
     */
    private const PROVIDER_CHAIN_ORDER = [
        'GEMINI', 'GROQ', 'OPENROUTER', 'COHERE',
    ];

    private const GEMINI_MODEL = 'gemini-2.5-flash';

    /**
     * Timeout per panggilan HTTP (detik). Sengaja dibuat pendek karena ini
     * dipanggil sinkron dari dalam webhook Telegram — kalau kelamaan, Telegram
     * bisa retry webhook-nya dan bikin aksi (mis. record_transaction) kepanggil dobel.
     */
    private const TIMEOUT_SECONDS = 10;

    /**
     * Jalankan percakapan dengan AI, otomatis mengeksekusi tool calling secara
     * REKURSIF: kalau AI minta panggil tool, hasilnya dieksekusi lewat
     * $executeTool, disisipkan balik ke riwayat percakapan sebagai pesan
     * beroles "tool", lalu AI dipanggil lagi -- diulang sampai AI akhirnya
     * membalas dengan teks biasa (jawaban final untuk user), atau sampai
     * $maxIterations tercapai (pengaman supaya tidak bisa infinite loop).
     *
     * @param  array<int, array{role: string, content: ?string, tool_calls?: array, tool_call_id?: string, name?: string}>  $messages
     *         Riwayat percakapan kanonis, biasanya diawali [role=system, role=user].
     *         Role yang didukung: system, user, assistant, tool.
     * @param  array<int, array{name: string, description: string, parameters: array}>  $tools
     *         Daftar tool format kanonis (lihat App\Services\Ai\AiToolRegistry). Kosongkan
     *         kalau tidak butuh function calling sama sekali.
     * @param  callable(string, array): array  $executeTool
     *         Dipanggil setiap kali AI minta eksekusi tool: menerima (nama_tool, argumen),
     *         WAJIB me-return array DATA MENTAH (bukan string siap-kirim-user) karena
     *         hasilnya akan di-loop balik ke AI dulu untuk dirangkum jadi bahasa natural.
     * @param  int  $maxIterations  Batas keras jumlah tool call berturut-turut per
     *         request (default 5) -- begitu tercapai tanpa AI membalas teks, dianggap gagal.
     * @return array{type: 'text', content: string, provider: string}
     *
     * @throws RuntimeException Kalau semua provider gagal, tidak ada key sama sekali,
     *         atau AI masih minta tool call setelah $maxIterations kali berturut-turut.
     */
    public function chat(array $messages, array $tools, callable $executeTool, int $maxIterations = 5): array
    {
        $conversation = $messages;

        for ($i = 1; $i <= $maxIterations; $i++) {
            $result = $this->sendConversation($conversation, $tools);

            if ($result['type'] === 'text') {
                return $result;
            }

            $callId = $result['function']['id'] ?? "call_{$i}";
            $toolName = $result['function']['name'];
            $toolArgs = is_array($result['function']['arguments']) ? $result['function']['arguments'] : [];

            Log::info("[AiService] iterasi {$i}/{$maxIterations}: AI memanggil tool {$toolName}", $toolArgs);

            // Catat giliran assistant yang memanggil tool ini di riwayat percakapan.
            $conversation[] = [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [['id' => $callId, 'name' => $toolName, 'arguments' => $toolArgs]],
            ];

            try {
                $toolResult = $executeTool($toolName, $toolArgs);
            } catch (Throwable $e) {
                // Kegagalan eksekusi tool TIDAK menghentikan percakapan -- kirim
                // pesan error-nya balik ke AI, biar AI yang menjelaskan ke user.
                $toolResult = ['error' => $e->getMessage()];
            }

            // Sisipkan hasil tool balik ke riwayat sebagai pesan role "tool", lalu
            // lanjut ke iterasi berikutnya (panggil AI lagi dengan konteks baru ini).
            $conversation[] = [
                'role' => 'tool',
                'tool_call_id' => $callId,
                'name' => $toolName,
                'content' => json_encode($toolResult, JSON_UNESCAPED_UNICODE),
            ];
        }

        throw new RuntimeException(
            "AI masih minta panggil tool setelah {$maxIterations}x berturut-turut tanpa membalas teks -- dihentikan untuk mencegah infinite loop."
        );
    }

    /**
     * Kirim SATU snapshot percakapan ke AI (tanpa loop), lengkap dengan
     * fallback antar key & provider. Dipakai internal oleh chat() di setiap
     * iterasinya -- rantai fallback dicoba ulang dari awal tiap iterasi
     * karena tiap provider adalah API stateless (tidak ada sesi yang perlu
     * "dilanjutkan" di provider yang sama).
     *
     * @return array{type: 'text'|'function_call', content: ?string, function: ?array{id: ?string, name: string, arguments: array}, provider: string}
     */
    private function sendConversation(array $messages, array $tools): array
    {
        $chain = $this->buildProviderChain();

        if (empty($chain)) {
            throw new RuntimeException(
                'Tidak ada API key AI yang dikonfigurasi. Isi minimal satu di .env, contoh: GEMINI_API_KEYS=key_kamu'
            );
        }

        $errors = [];

        foreach ($chain as [$label, $callback]) {
            try {
                $result = $callback($messages, $tools);
                $result['provider'] = $label;

                return $result;
            } catch (Throwable $e) {
                $errors[] = "{$label}: {$e->getMessage()}";
                Log::warning("[AiService] {$label} gagal, coba key/provider berikutnya: {$e->getMessage()}");
            }
        }

        throw new RuntimeException(
            'Semua '.count($chain).' key/provider AI gagal dipanggil: '.implode(' | ', $errors)
        );
    }

    /**
     * Bangun daftar percobaan berurutan: SETIAP key dari SETIAP provider,
     * sesuai PROVIDER_CHAIN_ORDER. Return list of [label, callback(messages, tools)].
     *
     * @return array<int, array{0: string, 1: callable(array, array): array}>
     */
    private function buildProviderChain(): array
    {
        $chain = [];

        foreach (self::PROVIDER_CHAIN_ORDER as $providerKey) {
            $keys = $this->getApiKeys($providerKey);

            if (empty($keys)) {
                continue;
            }

            if ($providerKey === 'GEMINI') {
                foreach ($keys as $i => $key) {
                    $label = 'Gemini (key #'.($i + 1).')';
                    $chain[] = [$label, fn (array $messages, array $tools) => $this->callGemini($key, $messages, $tools)];
                }

                continue;
            }

            if (! isset(self::OPENAI_COMPATIBLE_PROVIDERS[$providerKey])) {
                // Provider terdaftar di urutan fallback tapi belum ada implementasinya
                // (mis. WATSONX di masa depan) -- dilewati dulu, bukan error.
                continue;
            }

            [$displayName, $url, $model] = self::OPENAI_COMPATIBLE_PROVIDERS[$providerKey];

            foreach ($keys as $i => $key) {
                $label = "{$displayName} (key #".($i + 1).')';
                $chain[] = [$label, fn (array $messages, array $tools) => $this->callOpenAiCompatible($url, $key, $model, $messages, $tools)];
            }
        }

        return $chain;
    }

    /**
     * Baca `{PROVIDER}_API_KEYS` (boleh banyak, dipisah koma) dari env, dengan
     * fallback ke bentuk tunggal `{PROVIDER}_API_KEY` untuk kompatibilitas lama.
     *
     * @return string[]
     */
    private function getApiKeys(string $providerKey): array
    {
        $plural = (string) env("{$providerKey}_API_KEYS", '');
        $singular = (string) env("{$providerKey}_API_KEY", '');
        $raw = $plural !== '' ? $plural : $singular;

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($k) => $k !== ''));
    }

    private function callGemini(string $apiKey, array $messages, array $tools): array
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.self::GEMINI_MODEL.':generateContent';

        [$systemText, $contents] = $this->toGeminiPayload($messages);

        $payload = [
            'system_instruction' => ['parts' => [['text' => $systemText]]],
            'contents' => $contents,
        ];

        if (! empty($tools)) {
            $payload['tools'] = [['functionDeclarations' => $this->toGeminiTools($tools)]];
        }

        $response = Http::timeout(self::TIMEOUT_SECONDS)->post("{$url}?key={$apiKey}", $payload);

        if ($response->failed()) {
            throw new RuntimeException("HTTP {$response->status()}: ".$this->shortenBody($response->body()));
        }

        $part = $response->json('candidates.0.content.parts.0');

        if ($part === null) {
            throw new RuntimeException('Response Gemini tidak berisi konten yang bisa dibaca: '.$this->shortenBody($response->body()));
        }

        if (isset($part['functionCall'])) {
            return [
                'type' => 'function_call',
                'content' => null,
                'function' => [
                    'id' => null, // Gemini tidak menerbitkan call-id, chat() yang generate sendiri.
                    'name' => $part['functionCall']['name'],
                    'arguments' => $part['functionCall']['args'] ?? [],
                ],
            ];
        }

        return [
            'type' => 'text',
            'content' => $part['text'] ?? '',
            'function' => null,
        ];
    }

    private function callOpenAiCompatible(string $url, string $apiKey, string $model, array $messages, array $tools): array
    {
        $payload = [
            'model' => $model,
            'messages' => $this->toOpenAiMessages($messages),
        ];

        if (! empty($tools)) {
            $payload['tools'] = $this->toOpenAiTools($tools);
        }

        $response = Http::withToken($apiKey)->timeout(self::TIMEOUT_SECONDS)->post($url, $payload);

        if ($response->failed()) {
            throw new RuntimeException("HTTP {$response->status()}: ".$this->shortenBody($response->body()));
        }

        $message = $response->json('choices.0.message');

        if ($message === null) {
            throw new RuntimeException('Response provider tidak berisi choices[0].message: '.$this->shortenBody($response->body()));
        }

        $toolCall = $message['tool_calls'][0] ?? null;

        if ($toolCall !== null) {
            $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true);

            return [
                'type' => 'function_call',
                'content' => null,
                'function' => [
                    'id' => $toolCall['id'] ?? null,
                    'name' => $toolCall['function']['name'],
                    'arguments' => is_array($arguments) ? $arguments : [],
                ],
            ];
        }

        return [
            'type' => 'text',
            'content' => $message['content'] ?? '',
            'function' => null,
        ];
    }

    /**
     * Ubah riwayat percakapan kanonis jadi `messages` OpenAI-compatible.
     */
    private function toOpenAiMessages(array $messages): array
    {
        $out = [];

        foreach ($messages as $m) {
            if ($m['role'] === 'system' || $m['role'] === 'user') {
                $out[] = ['role' => $m['role'], 'content' => $m['content'] ?? ''];

                continue;
            }

            if ($m['role'] === 'assistant') {
                if (! empty($m['tool_calls'])) {
                    $out[] = [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => array_map(fn (array $tc) => [
                            'id' => $tc['id'],
                            'type' => 'function',
                            'function' => [
                                'name' => $tc['name'],
                                // (object) supaya tool tanpa argumen terkirim
                                // sebagai "{}" dan bukan "[]" — sebagian provider
                                // OpenAI-compatible menolak bentuk list di sini.
                                'arguments' => json_encode((object) ($tc['arguments'] ?? []), JSON_UNESCAPED_UNICODE),
                            ],
                        ], $m['tool_calls']),
                    ];
                } else {
                    $out[] = ['role' => 'assistant', 'content' => $m['content'] ?? ''];
                }

                continue;
            }

            if ($m['role'] === 'tool') {
                $out[] = [
                    'role' => 'tool',
                    'tool_call_id' => $m['tool_call_id'],
                    'content' => $m['content'] ?? '',
                ];
            }
        }

        return $out;
    }

    /**
     * Ubah riwayat percakapan kanonis jadi [system_text, contents] ala Gemini.
     * Gemini memisahkan system_instruction dari contents, dan tidak punya role
     * "tool" -- functionResponse dikirim sebagai giliran role "user".
     *
     * @return array{0: string, 1: array}
     */
    private function toGeminiPayload(array $messages): array
    {
        $systemParts = [];
        $contents = [];

        foreach ($messages as $m) {
            if ($m['role'] === 'system') {
                $systemParts[] = $m['content'] ?? '';

                continue;
            }

            if ($m['role'] === 'user') {
                $contents[] = ['role' => 'user', 'parts' => [['text' => $m['content'] ?? '']]];

                continue;
            }

            if ($m['role'] === 'assistant') {
                if (! empty($m['tool_calls'])) {
                    // Desain kita: satu tool call per giliran assistant.
                    $tc = $m['tool_calls'][0];
                    $contents[] = [
                        'role' => 'model',
                        // (object) WAJIB di sini. `args` di sisi Gemini bertipe
                        // protobuf Struct, yaitu objek. Kalau tool dipanggil TANPA
                        // argumen (mis. get_finance_categories), $tc['arguments']
                        // berisi array PHP kosong, dan json_encode([]) menghasilkan
                        // `[]` — sebuah LIST. Gemini menolaknya dengan:
                        //   400 Unknown name "args" at 'contents[1].parts[0]
                        //       .function_call': Proto field is not repeating,
                        //       cannot start list.
                        // Efeknya persis seperti bug enum di sanitizeSchemaForGemini:
                        // percakapan yang butuh tool tanpa argumen SELALU gagal di
                        // Gemini lalu jatuh ke provider cadangan.
                        'parts' => [['functionCall' => [
                            'name' => $tc['name'],
                            'args' => (object) ($tc['arguments'] ?? []),
                        ]]],
                    ];
                } else {
                    $contents[] = ['role' => 'model', 'parts' => [['text' => $m['content'] ?? '']]];
                }

                continue;
            }

            if ($m['role'] === 'tool') {
                $decoded = json_decode($m['content'] ?? '{}', true);

                // `response` juga bertipe Struct di Gemini — harus objek, bukan
                // list. Hasil tool kita sering berupa array berindeks angka
                // (mis. daftar transaksi), jadi yang seperti itu dibungkus dulu
                // ke dalam key `result` supaya tetap jadi objek di JSON.
                $response = is_array($decoded) && $decoded !== [] && array_keys($decoded) !== range(0, count($decoded) - 1)
                    ? $decoded
                    : ['result' => $decoded];

                $contents[] = [
                    'role' => 'user',
                    'parts' => [[
                        'functionResponse' => [
                            'name' => $m['name'] ?? 'unknown',
                            'response' => (object) $response,
                        ],
                    ]],
                ];
            }
        }

        return [implode("\n\n", $systemParts), $contents];
    }

    /**
     * Ubah tool format kanonis (AiToolRegistry) jadi format `tools` OpenAI-compatible.
     */
    private function toOpenAiTools(array $tools): array
    {
        return array_map(fn (array $tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'parameters' => $tool['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            ],
        ], $tools);
    }

    /**
     * Ubah tool format kanonis (AiToolRegistry) jadi format `functionDeclarations` Gemini.
     */
    private function toGeminiTools(array $tools): array
    {
        return array_map(fn (array $tool) => [
            'name' => $tool['name'],
            'description' => $tool['description'] ?? '',
            'parameters' => $this->sanitizeSchemaForGemini(
                $tool['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()]
            ),
        ], $tools);
    }

    /**
     * Rapikan JSON Schema supaya diterima Gemini.
     *
     * MASALAH YANG DIPERBAIKI
     * Gemini memakai subset OpenAPI 3.0, dan di sana `enum` HANYA sah untuk
     * properti bertipe string. AiToolRegistry punya beberapa properti integer
     * dengan enum angka, contohnya:
     *
     *     'board_id' => ['type' => 'integer', 'enum' => [2, 4]]
     *
     * Gemini menolak seluruh request-nya dengan:
     *     400 Invalid value at 'tools[0].function_declarations[0]
     *         .parameters.properties[1].value.enum[0]' (TYPE_STRING), 2
     *
     * Karena Gemini ada di urutan PERTAMA rantai fallback, satu enum angka ini
     * membuat SELURUH fitur AI jatuh ke provider cadangan setiap kali dipanggil —
     * dan waktu itu provider cadangannya juga mati semua, jadi efeknya "AI tidak
     * jalan" total. Provider OpenAI-compatible tidak bermasalah dengan enum
     * angka, jadi perbaikannya dilakukan di sini saja, bukan dengan melemahkan
     * skema di AiToolRegistry untuk semua provider.
     *
     * Yang dilakukan: untuk properti non-string, `enum` dibuang tapi daftar
     * nilainya dipindah ke `description` supaya model tetap tahu pilihannya.
     * Berjalan rekursif karena skema bisa bersarang (object di dalam object,
     * dan `items` milik array).
     */
    private function sanitizeSchemaForGemini(mixed $schema): mixed
    {
        if (! is_array($schema)) {
            return $schema;
        }

        $type = isset($schema['type']) && is_string($schema['type'])
            ? strtolower($schema['type'])
            : null;

        if (isset($schema['enum']) && is_array($schema['enum']) && $type !== null && $type !== 'string') {
            $values = implode(', ', array_map(
                static fn ($v) => is_scalar($v) ? (string) $v : json_encode($v),
                $schema['enum']
            ));

            $description = trim((string) ($schema['description'] ?? ''));
            $schema['description'] = $description === ''
                ? "Nilai yang diizinkan: {$values}."
                : rtrim($description, ' .').". Nilai yang diizinkan: {$values}.";

            unset($schema['enum']);
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $name => $property) {
                $schema['properties'][$name] = $this->sanitizeSchemaForGemini($property);
            }
        }

        if (isset($schema['items'])) {
            $schema['items'] = $this->sanitizeSchemaForGemini($schema['items']);
        }

        return $schema;
    }

    private function shortenBody(string $body): string
    {
        return mb_strlen($body) > 300 ? mb_substr($body, 0, 300).'…' : $body;
    }
}
