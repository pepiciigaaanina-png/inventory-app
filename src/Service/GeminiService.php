<?php

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class GeminiService
{
    private Client $client;
    private string $apiKey;

    public function __construct()
    {
        $this->client = new Client();
        $this->apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
    }

    public function analyze(string $message): string
    {
        if (empty($this->apiKey)) {
            return 'Липсва API ключ за Gemini.';
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent';

        $body = json_encode([
            'contents' => [
                [
                    'parts' => [
                        ['text' => $message]
                    ]
                ]
            ]
        ]);

        try {
            $response = $this->client->post($url, [
                'headers' => [
                    'Content-Type'   => 'application/json',
                    'X-goog-api-key' => $this->apiKey,
                ],
                'body' => $body,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['candidates'][0]['content']['parts'][0]['text'] ?? 'Няма отговор от Gemini.';

        } catch (GuzzleException $e) {
            return 'Грешка при извикване на Gemini: ' . $e->getMessage();
        }
    }

    public function analyzeImage(string $prompt, string $base64Image, string $mimeType): string
    {
        if (empty($this->apiKey)) {
            return 'Липсва API ключ за Gemini.';
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent';

        $body = json_encode([
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data'      => $base64Image
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        try {
            $response = $this->client->post($url, [
                'headers' => [
                    'Content-Type'   => 'application/json',
                    'X-goog-api-key' => $this->apiKey,
                ],
                'body' => $body,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['candidates'][0]['content']['parts'][0]['text'] ?? 'Няма отговор от Gemini.';

        } catch (GuzzleException $e) {
            return 'Грешка при извикване на Gemini (Снимка): ' . $e->getMessage();
        }
    }
}
