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
    public function connect(): Response
    {
        $authUrl = $this->gmailService->getClient()->createAuthUrl();
        return $this->redirect($authUrl);
    }

    #[Route('/callback', name: 'gmail_callback')]
    public function callback(Request $request): Response
    {
        $client = $this->gmailService->getClient();
        $accessToken = $client->fetchAccessTokenWithAuthCode($request->get('code'));

        if (isset($accessToken['error'])) {
            return $this->json(['error' => $accessToken['error']], Response::HTTP_BAD_REQUEST);
        }

        $client->setAccessToken($accessToken);

        // Initialiser le service Gmail
        $service = new \Google\Service\Gmail($client);

        // Récupérer la liste des messages
        $messagesList = $service->users_messages->listUsersMessages('me', [
            'q' => 'label:inbox', // Critères pour la recherche
            'maxResults' => 10
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

                // Parcourir les headers pour récupérer les informations nécessaires
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

//        return $this->json($messages);
        return $this->render('emails/list.html.twig', [
            'emails' => $messages,
        ]);

    }


}
