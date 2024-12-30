<?php

namespace App\Controller;

use App\Service\GmailService;
use App\Service\PhpMailerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class GmailController extends AbstractController
{
    private GmailService $gmailService;
    private PhpMailerService $mailerService;

    public function __construct(GmailService $gmailService, PhpMailerService $mailerService)
    {
        $this->gmailService = $gmailService;
        $this->mailerService = $mailerService;
    }

    #[Route('/connect', name: 'gmail_connect')]
    public function connect(Request $request): Response
    {
        $session = $request->getSession();

        if ($session->has('access_token')) {
            $accessToken = $session->get('access_token');
            $client = $this->gmailService->getClient();
            $client->setAccessToken($accessToken);

            if (!$client->isAccessTokenExpired()) {
                return $this->redirectToRoute('gmail_list');
            }
        }

        return $this->redirect($this->gmailService->getClient()->createAuthUrl());
    }

    #[Route('/callback', name: 'gmail_callback')]
    public function callback(Request $request): Response
    {
        $client = $this->gmailService->getClient();
        $session = $request->getSession();

        try {
            $accessToken = $session->has('access_token')
                ? $session->get('access_token')
                : $this->gmailService->fetchAccessToken($request->get('code'));

            $client->setAccessToken($accessToken);
            $session->set('access_token', $accessToken);

            if ($client->isAccessTokenExpired() && $client->getRefreshToken()) {
                $accessToken = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                $session->set('access_token', $accessToken);
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur de connexion : ' . $e->getMessage());
            return $this->redirectToRoute('gmail_connect');
        }

        return $this->redirectToRoute('gmail_list');
    }

    #[Route('/emails', name: 'gmail_list')]
    public function listEmails(Request $request): Response
    {
        $client = $this->initializeClient($request);

        try {
            $emails = $this->gmailService->listMessages($client);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Erreur lors de la récupération des emails.', 'details' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->render('emails/list.html.twig', [
            'emails' => $emails,
            'default_email' => $_ENV['DEFAULT_EMAIL'] ?? 'default@example.com',
        ]);
    }

    #[Route('/email/forward/{id}', name: 'gmail_forward_email', methods: ['POST'])]
    public function forwardEmail(string $id, Request $request): Response
    {
        $client = $this->initializeClient($request);

        try {
            $email = $this->gmailService->getMessageDetails($client, $id);
            $to = $_ENV['DEFAULT_EMAIL'] ?? 'default@example.com';

            $this->mailerService->sendEmail(
                $to,
                'Fwd: ' . $email['subject'],
                $email['body'] ?? 'Contenu introuvable'
            );

            $this->addFlash('success', 'L\'email a été renvoyé avec succès à ' . $to);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors du renvoi de l\'email : ' . $e->getMessage());
        }

        return $this->redirectToRoute('gmail_list');
    }

    private function initializeClient(Request $request): \Google\Client
    {
        $client = $this->gmailService->getClient();
        $session = $request->getSession();

        $accessToken = $session->get('access_token');
        $client->setAccessToken($accessToken);

        if ($client->isAccessTokenExpired() && $client->getRefreshToken()) {
            $accessToken = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            $session->set('access_token', $accessToken);
        }

        return $client;
    }
    #[Route('/email/{id}', name: 'gmail_email')]
    public function showEmail(string $id, Request $request): Response
    {
        $client = $this->gmailService->getClient();
        $session = $request->getSession();

        $accessToken = $session->get('access_token');
        $client->setAccessToken($accessToken);

        if ($client->isAccessTokenExpired()) {
            return $this->redirectToRoute('gmail_connect');
        }

        $service = new \Google\Service\Gmail($client);

        try {
            $messageDetails = $service->users_messages->get('me', $id, ['format' => 'full']);
            $payload = $messageDetails->getPayload();
            $headers = $payload->getHeaders();

            $subject = '';
            $from = '';
            $date = '';
            $body = null;

            foreach ($headers as $header) {
                if ($header->getName() === 'Subject') {
                    $subject = $header->getValue();
                }
                if ($header->getName() === 'From') {
                    $from = $header->getValue();
                }
                if ($header->getName() === 'Date') {
                    $date = $header->getValue();
                }
            }

            if ($payload->getBody() && $payload->getBody()->getSize() > 0) {
                $body = base64_decode(strtr($payload->getBody()->getData(), '-_', '+/'));
            } else {
                foreach ($payload->getParts() as $part) {
                    if ($part->getMimeType() === 'text/html' || $part->getMimeType() === 'text/plain') {
                        $body = base64_decode(strtr($part->getBody()->getData(), '-_', '+/'));
                        break;
                    }
                }
            }

            return $this->render('emails/show.html.twig', [
                'id' => $id,
                'subject' => $subject,
                'from' => $from,
                'date' => $date,
                'body' => $body ?? 'Aucun contenu disponible',
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Erreur lors de la récupération du mail.', 'details' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

}
