<?php
return [
    // Innehåller en mapp per kunskapsbank: content/<bank-id>/storage/.
    // Använd helst en absolut sökväg utanför webbserverns publika katalog.
    'content_root' => __DIR__ . '/content',
    'default_bank' => 'default',
    // Standardlösenord: admin123. Ändra värdet i config.php.
    'password' => 'admin123',
    'title' => 'Kunskapstratten',
    'max_upload_mb' => 40,
    'ai' => [
        'enabled' => true,
        'provider' => 'ollama',
        'base_url' => 'http://127.0.0.1:11434',
        'model' => 'gemma3:4b',
        // Ljud kräver att samma AI-server stöder /audio/transcriptions.
        'transcription_model' => 'whisper-1',
        // Reservnyckel på servern. En nyckel i webbläsarens localStorage har företräde.
        'api_key' => '',
        'temperature' => 0.3,
        'context_window' => 32768,
        'system_prompt' => '',
        'use_for_image_description' => true,
        'timeout' => 120,
    ],
];
