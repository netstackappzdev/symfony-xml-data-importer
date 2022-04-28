<?php
namespace App\Service;

use Google_Client;

class GoogleSheetClient
{
    private $transformer;

    public function __construct(Google_Client $client)
    {
        $this->client = $client;
    }

    public function tweet(User $user, string $key, string $status): void
    {
        $transformedStatus = $this->transformer->transform($status);

        // ... connect to Twitter and send the encoded status
    }
}