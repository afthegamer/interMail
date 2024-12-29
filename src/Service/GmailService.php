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
    }

    public function getClient(): Client
    {
        return $this->client;
    }
}
