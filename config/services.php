<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'discord' => [
        // ID del server (Discord: Configuracion del servidor > Widget, necesita
        // "Comunidad" habilitada para que aparezca esa pantalla). No es un
        // secreto -- es publico, va en la URL de la Widget API. La invite
        // tampoco es secreta, pero se saca a config igual para no hardcodear
        // un link que puede cambiar sin tocar codigo.
        'guild_id' => env('DISCORD_GUILD_ID'),
        'invite_url' => env('DISCORD_INVITE_URL'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
