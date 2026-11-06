<?php
// Quote form handler for www.denvick.be (one.com hosting, PHP mail()).
// Mails each request to DenVick and, when send-config.php holds a CallMeBot key, sends a short WhatsApp notice.
declare(strict_types=1);

$config = [
    'to' => 'info@denvick.be',
    'from' => 'info@denvick.be',
    'whatsapp_phone' => '',
    'whatsapp_apikey' => '',
];
if (is_file(__DIR__ . '/send-config.php')) {
    $config = array_merge($config, (array) (require __DIR__ . '/send-config.php'));
}

$lang = in_array($_POST['lang'] ?? '', ['nl', 'en', 'fr'], true) ? $_POST['lang'] : 'nl';
$viaScript = ($_POST['js'] ?? '') === '1';

/** Reply for visitors without JavaScript, who land on this page after posting. */
const PAGE_TEXT = [
    'nl' => ['ok' => 'Bedankt! Uw aanvraag is verstuurd. Wij nemen binnen één werkdag contact met u op.', 'error' => 'Versturen is niet gelukt. Probeer het opnieuw of bel ons op +32 484 136 748.', 'back' => 'Terug naar de website'],
    'en' => ['ok' => 'Thank you! Your request has been sent. We will contact you within one working day.', 'error' => 'Sending failed. Please try again or call us on +32 484 136 748.', 'back' => 'Back to the website'],
    'fr' => ['ok' => "Merci! Votre demande a bien été envoyée. Nous vous contactons dans un délai d'un jour ouvrable.", 'error' => "L'envoi a échoué. Réessayez ou téléphonez au +32 484 136 748.", 'back' => 'Retour au site'],
];

function finish(bool $ok, int $status, bool $viaScript, string $lang): never
{
    http_response_code($status);
    header('X-Robots-Tag: noindex');
    if ($viaScript) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok]);
        exit;
    }
    $t = PAGE_TEXT[$lang];
    $home = $lang === 'nl' ? '/' : "/$lang/";
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>DenVick</title></head>'
        . '<body style="font-family:system-ui,sans-serif;max-width:560px;margin:15vh auto;padding:0 20px;line-height:1.6;color:#14171c">'
        . '<p>' . htmlspecialchars($ok ? $t['ok'] : $t['error']) . '</p><p><a href="' . $home . '">' . htmlspecialchars($t['back']) . '</a></p></body></html>';
    exit;
}

function field(string $key, int $max): string
{
    return mb_substr(trim((string) ($_POST[$key] ?? '')), 0, $max);
}

/** Header values must stay on one line, or a visitor could inject extra mail headers. */
function oneLine(string $value): string
{
    return trim((string) preg_replace('/[\r\n\t]+/', ' ', $value));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    finish(false, 405, $viaScript, $lang);
}

// Spam bots fill the hidden field or post within seconds; they get a fake success and no mail is sent.
if (field('website', 200) !== '' || ($viaScript && (int) ($_POST['elapsed'] ?? 99) < 3)) {
    finish(true, 200, $viaScript, $lang);
}

$name = oneLine(field('naam', 120));
$phone = oneLine(field('gsm', 40));
$email = oneLine(field('email', 160));
$service = oneLine(field('dienst', 120));
$message = field('bericht', 5000);
$page = oneLine(field('page', 300));

if ($name === '' || $phone === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    finish(false, 422, $viaScript, $lang);
}

// At most 5 requests per visitor in 10 minutes.
$stampFile = sys_get_temp_dir() . '/denvick-form-' . md5((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
$recent = is_file($stampFile) ? (array) json_decode((string) file_get_contents($stampFile), true) : [];
$recent = array_values(array_filter($recent, fn($time) => (int) $time > time() - 600));
if (count($recent) >= 5) {
    finish(false, 429, $viaScript, $lang);
}
$recent[] = time();
@file_put_contents($stampFile, json_encode($recent));

$serviceLabel = $service !== '' ? $service : 'niet gekozen';
$subject = 'Nieuwe aanvraag via denvick.be: ' . $name;
$body = "Nieuwe aanvraag via het formulier op denvick.be\n\n"
    . "Naam: $name\nTelefoon: $phone\nEmail: $email\nDienst: $serviceLabel\nTaal: $lang\nPagina: $page\n\n"
    . "Bericht:\n" . ($message !== '' ? $message : '(geen bericht)') . "\n";
$headers = [
    'From' => 'DenVick website <' . $config['from'] . '>',
    'Reply-To' => $email,
    'Content-Type' => 'text/plain; charset=UTF-8',
];
$sent = mail($config['to'], mb_encode_mimeheader($subject, 'UTF-8'), $body, $headers, '-f' . $config['from']);

if ($sent && $config['whatsapp_phone'] !== '' && $config['whatsapp_apikey'] !== '') {
    $notice = "Nieuwe aanvraag via denvick.be\n$name\n$phone\n$serviceLabel";
    $url = 'https://api.callmebot.com/whatsapp.php?' . http_build_query([
        'phone' => $config['whatsapp_phone'],
        'text' => $notice,
        'apikey' => $config['whatsapp_apikey'],
    ]);
    // The notice is a bonus: a slow or failing WhatsApp service must not break the form.
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6]);
        curl_exec($curl);
    } else {
        @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 6]]));
    }
}

finish($sent, $sent ? 200 : 500, $viaScript, $lang);
