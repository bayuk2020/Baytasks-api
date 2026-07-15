<?php

namespace App\Services;

class TelegramService
{
    /**
     * Mengirim pesan ke Telegram (Mendukung Teks Formal & Tombol Interaktif)
     */
    public function sendMessage($chatId, $message, $options = [])
    {
        $token = env('TELEGRAM_BOT_TOKEN');
        $url = "https://api.telegram.org/bot{$token}/sendMessage";

        // Gabungkan array default dengan parameter options tambahan (seperti reply_markup)
        $post = array_merge([
            'chat_id'    => $chatId,
            'text'       => $message,
            'parse_mode' => 'HTML',
        ], $options);

        // Jika reply_markup dikirim dalam bentuk array, ubah ke json string otomatis
        if (isset($post['reply_markup']) && is_array($post['reply_markup'])) {
            $post['reply_markup'] = json_encode($post['reply_markup']);
        }

        return $this->executeCurl($url, $post);
    }

    /**
     * Mengubah/Mengedit pesan yang sudah terkirim (Buat ngilangin tombol setelah diklik)
     */
    public function editMessageText($chatId, $messageId, $newMessage, $options = [])
    {
        $token = env('TELEGRAM_BOT_TOKEN');
        $url = "https://api.telegram.org/bot{$token}/editMessageText";

        $post = array_merge([
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $newMessage,
            'parse_mode' => 'HTML',
        ], $options);

        if (isset($post['reply_markup']) && is_array($post['reply_markup'])) {
            $post['reply_markup'] = json_encode($post['reply_markup']);
        }

        return $this->executeCurl($url, $post);
    }

    /**
     * Helper internal curl biar ga nulis kode cURL berulang-ulang, hemat baris!
     */
    private function executeCurl($url, $postData)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        curl_close($ch);

        return $result;
    }
}