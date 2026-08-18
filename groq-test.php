<?php

$apiKey = getenv('GROQ_API_KEY');

$url = 'https://api.groq.com/openai/v1/chat/completions';

$data = [
    'model' => 'llama-3.1-8b-instant',
    'messages' => [
        [
            'role' => 'user',
            'content' => 'Merhaba! Türkçe cevap ver. PDF okuyucu projem için çalışıyor musun?'
        ]
    ],
    'temperature' => 0.2,
];

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($data),
]);

$response = curl_exec($ch);

if ($response === false) {
    die('cURL Hatası: ' . curl_error($ch));
}

curl_close($ch);

$result = json_decode($response, true);

echo "GROQ TESTİ\n";
echo "====================\n";

if (isset($result['choices'][0]['message']['content'])) {
    echo $result['choices'][0]['message']['content'];
} else {
    echo "API Hatası:\n";
    print_r($result);
}