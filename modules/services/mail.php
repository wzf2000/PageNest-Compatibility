<?php
/** Brevo transport; WordPress remains responsible for constructing mail and firing mail events. */
defined('ABSPATH') || exit();
function pagenest_companion_mail_prepare($return, $atts)
{
    if ($return !== null || !pagenest_companion_service_config('mail')['enabled']) {
        return $return;
    }
    require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
    require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
    require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
    require_once __DIR__ . '/mailer.php';
    global $phpmailer;
    if (!($phpmailer instanceof PageNest_Companion_Brevo_Mailer)) {
        $phpmailer = new PageNest_Companion_Brevo_Mailer(true);
        $phpmailer::$validator = static function ($address) {
            return (bool) is_email($address);
        };
    }
    // Core clears recipients, attachments, Reply-To, headers, Body and AltBody after this hook.
    // Return null: all core wp_mail_* filters and phpmailer_init run once, without recursive wp_mail.
    return null;
}
function pagenest_companion_mail_addresses($addresses)
{
    $result = [];
    foreach ($addresses as $address) {
        if (preg_match('/@fake\.com$/iD', $address[0])) {
            throw new PHPMailer\PHPMailer\Exception(
                'PageNest mail: placeholder recipients are not deliverable.',
            );
        }
        $result[] = ['email' => $address[0], 'name' => $address[1]];
    }
    return $result;
}
function pagenest_companion_mail_payload($mailer, $config)
{
    if (
        empty($config['api_key']) ||
        !is_email($config['from_email'] ?? '') ||
        empty($config['from_name'])
    ) {
        throw new PHPMailer\PHPMailer\Exception(
            'PageNest mail: server configuration is unavailable.',
        );
    }
    $mailer->setFrom($config['from_email'], $config['from_name'], false);
    if (!empty($config['reply_to_email'])) {
        $mailer->addReplyTo($config['reply_to_email'], $config['reply_to_name'] ?? '');
    }
    if ($mailer->Body === '' && !$mailer->AllowEmpty) {
        throw new PHPMailer\PHPMailer\Exception('PageNest mail: message body is empty.');
    }
    $payload = [
        'sender' => ['email' => $mailer->From, 'name' => $mailer->FromName],
        'subject' => $mailer->Subject,
    ];
    foreach (
        ['to' => 'getToAddresses', 'cc' => 'getCcAddresses', 'bcc' => 'getBccAddresses']
        as $key => $method
    ) {
        $addresses = pagenest_companion_mail_addresses($mailer->$method());
        if ($addresses) {
            $payload[$key] = $addresses;
        }
    }
    if (empty($payload['to']) && empty($payload['cc']) && empty($payload['bcc'])) {
        throw new PHPMailer\PHPMailer\Exception('PageNest mail: no valid recipients.');
    }
    $reply = array_values($mailer->getReplyToAddresses());
    // Brevo only supports one Reply-To, matching the previous provider adapter's first-address rule.
    if ($reply) {
        $payload['replyTo'] = ['email' => $reply[0][0], 'name' => $reply[0][1]];
    }
    if ($mailer->ContentType === 'text/html') {
        $payload['htmlContent'] = $mailer->Body;
        if ($mailer->AltBody !== '') {
            $payload['textContent'] = $mailer->AltBody;
        }
    } elseif ($mailer->ContentType === 'text/plain') {
        $payload['textContent'] = $mailer->Body;
    } else {
        throw new PHPMailer\PHPMailer\Exception('PageNest mail: unsupported content type.');
    }
    // The API accepts UTF-8 JSON. Convert a non-UTF-8 body/subject explicitly rather than corrupting it.
    if (strcasecmp($mailer->CharSet, 'UTF-8') !== 0) {
        foreach (['subject', 'htmlContent', 'textContent'] as $key) {
            if (isset($payload[$key])) {
                $converted = function_exists('iconv')
                    ? @iconv($mailer->CharSet, 'UTF-8', $payload[$key])
                    : false;
                if ($converted === false) {
                    throw new PHPMailer\PHPMailer\Exception(
                        'PageNest mail: unsupported character encoding.',
                    );
                }
                $payload[$key] = $converted;
            }
        }
    }
    foreach ($mailer->getCustomHeaders() as $header) {
        if (preg_match('/[\r\n]/', $header[0] . $header[1])) {
            throw new PHPMailer\PHPMailer\Exception('PageNest mail: invalid custom header.');
        }
        $payload['headers'][$header[0]] = $header[1];
    }
    foreach ($mailer->getAttachments() as $attachment) {
        if ($attachment[6] === 'inline') {
            throw new PHPMailer\PHPMailer\Exception(
                'PageNest mail: Brevo does not support CID embedded resources.',
            );
        }
        $bytes = $attachment[5] ? $attachment[0] : @file_get_contents($attachment[0]);
        if ($bytes === false) {
            throw new PHPMailer\PHPMailer\Exception('PageNest mail: attachment cannot be read.');
        }
        $payload['attachment'][] = ['name' => $attachment[2], 'content' => base64_encode($bytes)];
    }
    return $payload;
}
function pagenest_companion_mail_deliver($mailer)
{
    $config = pagenest_companion_service_config('mail');
    if (empty($config['enabled'])) {
        throw new PHPMailer\PHPMailer\Exception('PageNest mail: transport is disabled.');
    }
    $payload = pagenest_companion_mail_payload($mailer, $config);
    $body = wp_json_encode($payload);
    if (!is_string($body)) {
        throw new PHPMailer\PHPMailer\Exception('PageNest mail: invalid message encoding.');
    }
    $response = wp_remote_post('https://api.brevo.com/v3/smtp/email', [
        'timeout' => 20,
        'redirection' => 0,
        'sslverify' => true,
        'headers' => [
            'api-key' => $config['api_key'],
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ],
        'body' => $body,
    ]);
    // Do not retry: a timeout can mean that the provider accepted the mail already.
    if (is_wp_error($response)) {
        throw new PHPMailer\PHPMailer\Exception('PageNest mail: provider request failed.');
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (
        wp_remote_retrieve_response_code($response) !== 201 ||
        !is_array($data) ||
        empty($data['messageId']) ||
        !is_string($data['messageId'])
    ) {
        throw new PHPMailer\PHPMailer\Exception(
            'PageNest mail: provider did not confirm acceptance.',
        );
    }
    return true;
}
