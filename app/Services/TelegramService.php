<?php

namespace App\Services;

class TelegramService
{
    public function sendMessage(
    $chatId,
    $message
) {

    $token =
        env(
            'TELEGRAM_BOT_TOKEN'
        );

    $url =
        "https://api.telegram.org/bot{$token}/sendMessage";

    $post = [
        'chat_id' =>
            $chatId,

        'text' =>
            $message,

        'parse_mode' =>
            'HTML',
    ];

    $ch =
        curl_init();

    curl_setopt(
        $ch,
        CURLOPT_URL,
        $url
    );

    curl_setopt(
        $ch,
        CURLOPT_POST,
        true
    );

    curl_setopt(
        $ch,
        CURLOPT_POSTFIELDS,
        $post
    );

    curl_setopt(
        $ch,
        CURLOPT_RETURNTRANSFER,
        true
    );

    $result =
        curl_exec($ch);

    curl_close($ch);

    return $result;
}
}