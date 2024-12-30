<?php

namespace App\Service;

use Google\Client;
use Google\Service\Gmail;

class GmailService
{
    private $client;

    public function __construct()
    {
        $this->client = new Client();
        $this->client->setAuthConfig(__DIR__ . '/../../config/credentials/gmail_credentials.json');
        $this->client->addScope(Gmail::GMAIL_READONLY);
        $this->client->setRedirectUri('http://localhost:8000/callback');
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Liste les messages Gmail.
     *
     * @param Client $client
     * @return array
     * @throws \Exception
     */
    public function listMessages(Client $client): array
    {
        $service = new Gmail($client);
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

        return $messages;
    }

    /**
     * Récupère les détails d'un message Gmail.
     *
     * @param Client $client
     * @param string $id
     * @return array
     * @throws \Exception
     */
    public function getMessageDetails(Client $client, string $id): array
    {
        $service = new Gmail($client);
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

        return [
            'id' => $id,
            'subject' => $subject,
            'from' => $from,
            'date' => $date,
            'body' => $body,
        ];
    }
}
