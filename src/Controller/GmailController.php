<?php
namespace App\Controller;

use App\Service\GmailService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class GmailController extends AbstractController
{
    private $gmailService;

    public function __construct(GmailService $gmailService)
    {
        $this->gmailService = $gmailService;
    }

    #[Route('/connect', name: 'gmail_connect')]
    public function connect(Request $request): Response
    {
        $session = $request->getSession();
        $this->addFlash('debug', 'Session Access Token: ' . json_encode($session->get('access_token')));


        if ($session->has('access_token')) {
            $accessToken = $session->get('access_token');
            $client = $this->gmailService->getClient();
            $client->setAccessToken($accessToken);

            if (!$client->isAccessTokenExpired()) {
                return $this->redirectToRoute('gmail_list');
            }
        }

        $authUrl = $this->gmailService->getClient()->createAuthUrl();
        return $this->redirect($authUrl);
    }


    #[Route('/callback', name: 'gmail_callback')]
    public function callback(Request $request): Response
    {
        $client = $this->gmailService->getClient();
        $session = $request->getSession();

        // Vérifiez si un token d'accès valide existe déjà
        if ($session->has('access_token')) {
            $accessToken = $session->get('access_token');
            $client->setAccessToken($accessToken);

            // Si le token est expiré, tentez de le rafraîchir
            if ($client->isAccessTokenExpired()) {
                if ($client->getRefreshToken()) {
                    $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                    $session->set('access_token', $client->getAccessToken());
                } else {
                    return $this->redirectToRoute('gmail_connect');
                }
            }
        } else {
            // Récupérer le code d'autorisation
            $authCode = $request->get('code');
            if (!$authCode) {
                return $this->json(['error' => 'Code d\'autorisation manquant.'], Response::HTTP_BAD_REQUEST);
            }

            // Récupérer le token d'accès
            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);

            if (isset($accessToken['error'])) {
                return $this->json(['error' => $accessToken['error'], 'details' => $accessToken], Response::HTTP_BAD_REQUEST);
            }

            // Sauvegarder le token dans la session
            $session->set('access_token', $accessToken);
            $client->setAccessToken($accessToken);
        }

        // Continuer avec le service Gmail
        $service = new \Google\Service\Gmail($client);

        try {
            $messagesList = $service->users_messages->listUsersMessages('me', [
                'q' => 'label:inbox',
                'maxResults' => 10,
            ]);

            $messages = [];
            if ($messagesList->getMessages()) {
                foreach ($messagesList->getMessages() as $message) {
                    $messageDetails = $service->users_messages->get('me', $message->getId());
                    $payload = $messageDetails->getPayload();
                    $headers = $payload->getHeaders();

                    $subject = '';
                    $from = '';
                    $date = '';

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

                    $messages[] = [
                        'id' => $message->getId(),
                        'subject' => $subject,
                        'from' => $from,
                        'date' => $date,
                    ];
                }
            }

            return $this->render('emails/list.html.twig', [
                'emails' => $messages,
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Erreur lors de la récupération des emails.', 'details' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/emails', name: 'gmail_list')]
    public function listEmails(Request $request): Response
    {
        $client = $this->gmailService->getClient();
        $session = $request->getSession();

        if (!$session->has('access_token')) {
            return $this->redirectToRoute('gmail_connect');
        }

        $accessToken = $session->get('access_token');
        $client->setAccessToken($accessToken);

        // Rafraîchir le token s'il est expiré
        if ($client->isAccessTokenExpired()) {
            if ($client->getRefreshToken()) {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                $session->set('access_token', $client->getAccessToken());
            } else {
                return $this->redirectToRoute('gmail_connect');
            }
        }

        // Continuer avec le service Gmail
        $service = new \Google\Service\Gmail($client);

        try {
            $messagesList = $service->users_messages->listUsersMessages('me', [
                'q' => 'label:inbox',
                'maxResults' => 10,
            ]);

            $messages = [];
            if ($messagesList->getMessages()) {
                foreach ($messagesList->getMessages() as $message) {
                    $messageDetails = $service->users_messages->get('me', $message->getId());
                    $payload = $messageDetails->getPayload();
                    $headers = $payload->getHeaders();

                    $subject = '';
                    $from = '';
                    $date = '';

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

                    $messages[] = [
                        'id' => $message->getId(),
                        'subject' => $subject,
                        'from' => $from,
                        'date' => $date,
                    ];
                }
            }

            return $this->render('emails/list.html.twig', [
                'emails' => $messages,
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Erreur lors de la récupération des emails.', 'details' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
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
            // Récupérer les détails du message
            $messageDetails = $service->users_messages->get('me', $id, ['format' => 'full']);
            $payload = $messageDetails->getPayload();
            $headers = $payload->getHeaders();

            $subject = '';
            $from = '';
            $date = '';

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

            // Récupérer le contenu (corps principal)
            $body = null;

            if ($payload->getBody() && $payload->getBody()->getSize() > 0) {
                $body = base64_decode(strtr($payload->getBody()->getData(), '-_', '+/'));
            } else {
                // Vérifiez les "parts" pour trouver le contenu
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
