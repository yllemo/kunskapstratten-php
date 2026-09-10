<?php
return [
    // Innehåller en mapp per kunskapsbank: content/<bank-id>/storage/.
    // Använd helst en absolut sökväg utanför webbserverns publika katalog.
    'content_root' => __DIR__ . '/content',
    'default_bank' => 'default',
    // Standardlösenord: changeme. Byt hash i config.php för eget lösenord.
    // Tom hash tillåts endast när klienten är localhost.
    'password_hash' => '$2y$12$k.EbQtuzasgr.V1ZUHqkD.BipzUa9TjRfwdm1yUHwANuYw4r9y2uG',
    'title' => 'Kunskapstratten',
    'max_upload_mb' => 40,
    'ai' => [
        'enabled' => true,
        'provider' => 'ollama',
        'base_url' => 'http://127.0.0.1:11434',
        'model' => 'gemma3:4b',
        // Ljud kräver att samma AI-server stöder /audio/transcriptions.
        'transcription_model' => 'whisper-1',
        'api_key' => '',
        'temperature' => 0.3,
        'context_window' => 32768,
        'system_prompt' => '',
        'use_for_metadata_enrichment' => true,
        'use_for_image_description' => true,
        'timeout' => 120,
    ],
];
