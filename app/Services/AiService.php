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
     * Kirim satu prompt ke AI, lengkap dengan fallback antar key & provider.
     *
     * @param  string  $systemPrompt  Instruksi peran/aturan untuk AI.
     * @param  string  $userMessage   Pesan/pertanyaan dari user.
     * @param  array<int, array{name: string, description: string, parameters: array}>  $tools
     *         Daftar tool dalam format kanonis (lihat App\Services\Ai\AiToolRegistry).
     *         Kosongkan kalau tidak butuh function calling.
     * @return array{type: 'text'|'function_call', content: ?string, function: ?array{name: string, arguments: array}, provider: string}
     *
     * @throws RuntimeException Kalau SEMUA key di SEMUA provider gagal, atau tidak ada key sama sekali.
     */
    public function askAi(string $systemPrompt, string $userMessage, array $tools = []): array
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
                $result = $callback($systemPrompt, $userMessage, $tools);
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
     * sesuai PROVIDER_CHAIN_ORDER. Return list of [label, callback].
     *
     * @return array<int, array{0: string, 1: callable(string, string, array): array}>
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
                    $chain[] = [$label, fn (string $system, string $user, array $tools) => $this->callGemini($key, $system, $user, $tools)];
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
                $chain[] = [$label, fn (string $system, string $user, array $tools) => $this->callOpenAiCompatible($url, $key, $model, $system, $user, $tools)];
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

    private function callGemini(string $apiKey, string $system, string $user, array $tools): array
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.self::GEMINI_MODEL.':generateContent';

        $payload = [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
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

    private function callOpenAiCompatible(string $url, string $apiKey, string $model, string $system, string $user, array $tools): array
    {
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
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

        $toolCall = $message['tool_calls'][0]['function'] ?? null;

        if ($toolCall !== null) {
            $arguments = json_decode($toolCall['arguments'] ?? '{}', true);

            return [
                'type' => 'function_call',
                'content' => null,
                'function' => [
                    'name' => $toolCall['name'],
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
