<?php
// THIS FILE SENDS PASSWORD RESET EMAILS THROUGH BREVO SO USERS CAN RECOVER THEIR ACCOUNTS.

function animeals_brevo_api_key(): string
{
    // PREFER THE SERVER ENVIRONMENT KEY SO THE REAL KEY DOES NOT NEED TO LIVE IN CODE.
    return getenv('BREVO_API_KEY') ?: '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';
}

function animeals_send_brevo_email(string $to, string $name, string $subject, string $htmlContent, string $textContent): bool
{
    // DO NOT TRY TO SEND IF BREVO IS NOT CONFIGURED OR CURL IS MISSING ON THE SERVER.
    $apiKey = animeals_brevo_api_key();
    if ($apiKey === '' || !function_exists('curl_version')) {
        return false;
    }

    // BUILD THE BREVO SMTP PAYLOAD WITH BOTH HTML AND PLAIN TEXT VERSIONS.
    $payload = [
        'sender' => ['name' => 'ANIMEALS', 'email' => getenv('BREVO_SENDER_EMAIL') ?: 'linianlunar@gmail.com'],
        'to' => [['email' => $to, 'name' => $name !== '' ? $name : $to]],
        'subject' => $subject,
        'htmlContent' => $htmlContent,
        'textContent' => $textContent,
    ];

    // CALL BREVO'S EMAIL API AND TREAT ANY 2XX RESPONSE AS A SUCCESS.
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json',
        'api-key: ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $response !== false && $status >= 200 && $status < 300;
}

function animeals_app_url(string $path): string
{
    // USE APP_URL ON HOSTING, OTHERWISE DETECT THE CURRENT DOMAIN FOR LOCAL/DEPLOYED LINKS.
    $base = getenv('APP_URL');
    if ($base) {
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'animeals.online';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host . '/' . ltrim($path, '/');
}
