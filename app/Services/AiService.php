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
        'GROQ'        => ['Groq',         'https://api.groq.com/openai/v1/chat/completions',          'llama-3.3-70b-versatile'],
        'OPENROUTER'  => ['OpenRouter',    'https://openrouter.ai/api/v1/chat/completions',            'meta-llama/llama-3.3-70b-instruct:free'],
        'NVIDIA'      => ['NVIDIA NIM',    'https://integrate.api.nvidia.com/v1/chat/completions',     'meta/llama-3.3-70b-instruct'],
        'CEREBRAS'    => ['Cerebras',      'https://api.cerebras.ai/v1/chat/completions',              'llama-3.3-70b'],
        'SAMBANOVA'   => ['SambaNova',     'https://api.sambanova.ai/v1/chat/completions',             'Meta-Llama-3.1-70B-Instruct'],
        'MISTRAL'     => ['Mistral',       'https://api.mistral.ai/v1/chat/completions',               'mistral-small-latest'],
        'TOGETHER'    => ['Together AI',   'https://api.together.xyz/v1/chat/completions',             'meta-llama/Llama-3.3-70B-Instruct-Turbo-Free'],
        'DEEPSEEK'    => ['DeepSeek',      'https://api.deepseek.com/chat/completions',                'deepseek-chat'],
        'FIREWORKS'   => ['Fireworks AI',  'https://api.fireworks.ai/inference/v1/chat/completions',   'accounts/fireworks/models/llama-v3p3-70b-instruct'],
        'HUGGINGFACE' => ['Hugging Face',  'https://router.huggingface.co/v1/chat/completions',        'deepseek-ai/DeepSeek-V3'],
        'COHERE'      => ['Cohere',        'https://api.cohere.ai/compatibility/v1/chat/completions',  'command-r-plus-08-2024'],
        'AI21'        => ['AI21',          'https://api.ai21.com/studio/v1/chat/completions',          'jamba-mini'],
    ];

    /**
     * Urutan fallback ANTAR PROVIDER. Ubah urutan ini kapan saja tanpa
     * menyentuh kode lain di bawah.
     */
    private const PROVIDER_CHAIN_ORDER = [
        'GEMINI', 'GROQ', 'OPENROUTER', 'NVIDIA',
        'CEREBRAS', 'SAMBANOVA', 'MISTRAL', 'TOGETHER',
        'DEEPSEEK', 'FIREWORKS', 'HUGGINGFACE', 'COHERE', 'AI21',
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
                                'arguments' => json_encode($tc['arguments'], JSON_UNESCAPED_UNICODE),
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
                        'parts' => [['functionCall' => ['name' => $tc['name'], 'args' => $tc['arguments']]]],
                    ];
                } else {
                    $contents[] = ['role' => 'model', 'parts' => [['text' => $m['content'] ?? '']]];
                }

                continue;
            }

            if ($m['role'] === 'tool') {
                $decoded = json_decode($m['content'] ?? '{}', true);
                $contents[] = [
                    'role' => 'user',
                    'parts' => [[
                        'functionResponse' => [
                            'name' => $m['name'] ?? 'unknown',
                            'response' => is_array($decoded) ? $decoded : ['result' => $decoded],
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
            'parameters' => $tool['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
        ], $tools);
    }

    private function shortenBody(string $body): string
    {
        return mb_strlen($body) > 300 ? mb_substr($body, 0, 300).'…' : $body;
    }
}
