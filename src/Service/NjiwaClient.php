<?php declare(strict_types=1);

/**
 * Talking to Njiwa. Transport only.
 *
 * Everything goes through Symfony's HTTP client, which is the one Shopware
 * already configures, so a shop behind a proxy or on a host with opinions
 * about outbound requests behaves here the way it does everywhere else.
 * Nothing in this class decides when to message anybody.
 */

namespace Upeo\Njiwa\Service;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Upeo\Njiwa\Exception\NjiwaException;

class NjiwaClient
{
    /**
     * The plugin version, sent as the User-Agent so that Njiwa's support can
     * tell what a shop is running. It has to match composer.json by hand;
     * there is no reliable way to read that file from inside a built plugin.
     */
    public const VERSION = '0.1.0';

    /** Long enough for a slow line, short enough that nothing holds a worker. */
    private const TIMEOUT = 20;

    private HttpClientInterface $httpClient;

    private Config $config;

    public function __construct(HttpClientInterface $httpClient, Config $config)
    {
        $this->httpClient = $httpClient;
        $this->config = $config;
    }

    /**
     * Send one text message.
     *
     * @param string $to             Recipient, digits only.
     * @param string $text           The message.
     * @param string $idempotencyKey Njiwa honours it for twenty-four hours, so
     *                               a worker that runs the same job twice
     *                               replays the first answer instead of
     *                               messaging the customer again.
     *
     * @return array<string, mixed> Njiwa's answer, including the message id.
     *
     * @throws NjiwaException
     */
    public function sendText(
        string $to,
        string $text,
        string $idempotencyKey = '',
        ?string $salesChannelId = null
    ): array {
        $body = [
            'to' => $to,
            'text' => $text,
        ];

        // Only when the shop named a number. Left out, Njiwa uses the
        // account's default, which is the right answer for the shops that have
        // one number and never think about this again.
        $from = $this->config->sendFrom($salesChannelId);
        if ($from !== '') {
            $body['from'] = $from;
        }

        $headers = [];
        if ($idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->request('POST', '/v1/messages', $body, $headers, $salesChannelId);
    }

    /**
     * The WhatsApp numbers on this account, linked or not.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws NjiwaException
     */
    public function numbers(?string $salesChannelId = null): array
    {
        $answer = $this->request('GET', '/v1/instances', null, [], $salesChannelId);

        return isset($answer['data']) && \is_array($answer['data']) ? $answer['data'] : [];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     *
     * @return array<string, mixed>
     *
     * @throws NjiwaException
     */
    private function request(
        string $method,
        string $path,
        ?array $body,
        array $headers,
        ?string $salesChannelId
    ): array {
        // The master switch has to fail here rather than shrug. Somebody who
        // turned it off and forgot needs to find a line in a log saying so,
        // not silence that looks exactly like a working shop.
        if (!$this->config->isEnabled($salesChannelId)) {
            throw new NjiwaException(
                'Send WhatsApp messages is switched off in the Njiwa WhatsApp settings, so nothing was sent.',
                'disabled'
            );
        }

        $key = $this->config->apiKey($salesChannelId);
        if ($key === '') {
            throw new NjiwaException(
                'There is no Njiwa API key saved, so nothing can be sent.',
                'not_configured'
            );
        }

        $baseUrl = $this->config->baseUrl($salesChannelId);

        $options = [
            'headers' => array_merge(
                [
                    'Authorization' => 'Bearer ' . $key,
                    'Accept' => 'application/json',
                    'User-Agent' => 'njiwa-shopware/' . self::VERSION,
                ],
                $headers
            ),
            // Two limits, because they mean different things. timeout is how
            // long the client will wait between pieces of an answer;
            // max_duration is the whole call. Without the second, a server
            // that trickles bytes for ever holds a queue worker for ever.
            'timeout' => self::TIMEOUT,
            'max_duration' => self::TIMEOUT,
        ];

        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, $baseUrl . $path, $options);

            // The client is lazy: nothing has actually left the machine until
            // one of these is asked for, and either can be where a connection
            // failure surfaces. Reading the body with false stops Symfony
            // throwing on a 4xx, because Njiwa explains itself in that body
            // and the explanation is what the merchant needs to read.
            $status = $response->getStatusCode();
            $raw = $response->getContent(false);
        } catch (HttpClientException $e) {
            // A network failure is not a send failure: the message was never
            // accepted, so nobody has been messaged and trying again is safe.
            throw new NjiwaException(
                sprintf('Could not reach Njiwa at %s. %s', $baseUrl, $e->getMessage()),
                'connection_failed',
                0,
                null,
                $e
            );
        }

        $decoded = $this->decode($raw);

        if ($status >= 400) {
            $error = isset($decoded['error']) && \is_array($decoded['error']) ? $decoded['error'] : [];

            throw new NjiwaException(
                isset($error['message'])
                    ? (string) $error['message']
                    : sprintf('Njiwa answered with HTTP %d.', $status),
                isset($error['code']) ? (string) $error['code'] : 'unknown',
                $status,
                isset($error['docs']) ? (string) $error['docs'] : null
            );
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Almost always a proxy or a firewall answering instead of Njiwa.
            // The status code says more than the HTML body would.
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }
}
