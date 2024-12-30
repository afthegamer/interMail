<?php

// src/Service/PhpMailerService.php
namespace App\Service;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class PhpMailerService
{
    public function sendEmail($to, $subject, $body): void
    {
        $mailer = new PHPMailer(true);

        try {
            // Configuration SMTP pour MailHog
            $mailer->isSMTP();
            $mailer->Host = 'localhost'; // MailHog
            $mailer->Port = 1025; // Port MailHog
            $mailer->SMTPAuth = false; // MailHog n'a pas besoin d'authentification

            // Configuration de l'email
            $mailer->setFrom('test@example.com', 'Test App');
            $mailer->addAddress($to);
            $mailer->Subject = $subject;
            $mailer->isHTML(true);
            $mailer->Body = $body;

            $mailer->send();
        } catch (\Exception $e) {
            throw new \RuntimeException('Erreur d\'envoi de l\'email : ' . $e->getMessage());
        }
    }
}
