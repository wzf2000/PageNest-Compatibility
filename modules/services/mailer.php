<?php
/** Loaded lazily only when the trusted mail profile is enabled and owns transport. */
defined('ABSPATH') || exit();
class PageNest_Companion_Brevo_Mailer extends PHPMailer\PHPMailer\PHPMailer
{
    public function send()
    {
        try {
            return pagenest_companion_mail_deliver($this);
        } catch (PHPMailer\PHPMailer\Exception $error) {
            throw $error;
        } catch (Throwable $error) {
            // HTTP filters and attachment handlers may throw; never leak their request/response data.
            throw new PHPMailer\PHPMailer\Exception('PageNest mail: transport failed.');
        }
    }
}
