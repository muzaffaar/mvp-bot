<?php

namespace App\AI\Gemini;

use App\AI\Prompts\TaskManagementPrompt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiClient
{
    private string $apiKey;

    private string $model;

    public function __construct()
    {
        $this->apiKey = config(
            'services.gemini.api_key'
        );

        $this->model = config(
            'services.gemini.model'
        );

        if (! $this->apiKey) {
            throw new RuntimeException(
                'GEMINI_API_KEY is not configured.'
            );
        }
    }

    public function interpret(
        string $systemInstruction,
        string $input,
        array $schema,
    ): array {
        $response = Http::timeout(60)
            ->retry(3, 500)
            ->withHeaders([
                'x-goog-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post(
                'https://generativelanguage.googleapis.com/v1beta/interactions',
                [
                    'model' => $this->model,

                    'input' => $input,

                    'system_instruction' => $systemInstruction,

                    'response_format' => [
                        'type' => 'text',
                        'mime_type' => 'application/json',
                        'schema' => $schema,
                    ],

                    'generation_config' => [
                        // The interactions endpoint counts the model's internal
                        // reasoning steps against this budget, not just the final
                        // JSON, so it needs much more headroom than the JSON
                        // payload's own size would suggest.
                        'max_output_tokens' => 2048,

                        // This is a small, well-specified extraction task, not
                        // something that benefits from deep reasoning. Minimal
                        // thinking cuts most of the latency this endpoint's
                        // always-on reasoning otherwise adds, and skipping the
                        // summary avoids generating text we never read.
                        'thinking_level' => 'minimal',
                        'thinking_summaries' => 'none',
                    ],
                ]
            )
            ->throw()
            ->json();
            // dd($response);

        $output = null;

        foreach ($response['steps'] ?? [] as $step) {
            if (($step['type'] ?? null) !== 'model_output') {
                continue;
            }

            foreach ($step['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'text') {
                    $output = $content['text'] ?? null;
                    break 2;
                }
            }
        }

        if (! is_string($output)) {
            throw new RuntimeException(
                'Gemini did not return structured output.'
            );
        }

        $decoded = json_decode(
            $output,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (! is_array($decoded)) {
            throw new RuntimeException(
                'Gemini returned invalid JSON.'
            );
        }

        return $decoded;
    }

    public function transcribeAudio(
        string $audio,
        string $mimeType = 'audio/ogg',
    ): string {
        $response = Http::timeout(60)
            ->retry(3, 500)
            ->withHeaders([
                'x-goog-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post(
                'https://generativelanguage.googleapis.com/v1beta/models/'
                . $this->model
                . ':generateContent',
                [
                    'contents' => [
                        [
                            'parts' => [
                                [
                                    'text' => TaskManagementPrompt::audioTranscription(),
                                ],
                                [
                                    'inline_data' => [
                                        'mime_type' => $mimeType,
                                        'data' => base64_encode($audio),
                                    ],
                                ],
                            ],
                        ],
                    ],

                    'generationConfig' => [
                        // Transcription output length scales with the voice
                        // message's length, unlike interpret()'s compact JSON,
                        // so this cap is looser to avoid truncating speech.
                        'maxOutputTokens' => 1024,
                    ],
                ]
            )
            ->throw()
            ->json();

        $text = $response['candidates'][0]['content']['parts'][0]['text']
            ?? null;

        if (! is_string($text) || trim($text) === '') {
            throw new RuntimeException(
                'Gemini did not return an audio transcription.'
            );
        }

        return trim($text);
    }
}
