<?php declare(strict_types=1);

/**
 * The two checks, as admin API endpoints.
 *
 * They are what the two buttons under Settings call
 * (src/Resources/app/administration), and they answer curl just as happily, so
 * a merchant who will never open a terminal and an installer checking a shop
 * over SSH are testing exactly the same thing.
 *
 * Both are POST only and both sit behind the administration's own session and
 * access control. The privilege asked for is the one that governs the
 * settings these endpoints check — somebody who can change the API key can
 * find out whether it works, and somebody who cannot, cannot.
 */

namespace Upeo\Njiwa\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Upeo\Njiwa\Exception\NjiwaException;
use Upeo\Njiwa\Service\Config;
use Upeo\Njiwa\Service\MessageLog;
use Upeo\Njiwa\Service\NjiwaClient;
use Upeo\Njiwa\Service\Numbers;

#[Route(defaults: ['_routeScope' => ['api']])]
class NjiwaController extends AbstractController
{
    /**
     * A test message is a real message that costs real money on a live key.
     * Five a minute is plenty for somebody checking their setup and few enough
     * that a stuck script cannot run up a bill.
     */
    private const TEST_MESSAGES_PER_MINUTE = 5;

    /** The event name test messages are recorded under in upeo_njiwa_message. */
    private const TEST_EVENT = 'test';

    private NjiwaClient $client;

    private Config $config;

    private Numbers $numbers;

    private MessageLog $log;

    public function __construct(NjiwaClient $client, Config $config, Numbers $numbers, MessageLog $log)
    {
        $this->client = $client;
        $this->config = $config;
        $this->numbers = $numbers;
        $this->log = $log;
    }

    /**
     * Who this key belongs to, and what it can send from. Sends nothing.
     */
    #[Route(
        path: '/api/_action/upeo-njiwa/test-connection',
        name: 'api.action.upeo_njiwa.test_connection',
        defaults: ['_acl' => ['system_config:read']],
        methods: ['POST']
    )]
    public function testConnection(Request $request): JsonResponse
    {
        $salesChannelId = $this->salesChannelId($request);

        try {
            $numbers = $this->client->numbers($salesChannelId);
        } catch (NjiwaException $e) {
            return $this->refused($e);
        }

        $lines = [];

        if ($this->config->isTestKey($salesChannelId)) {
            $lines[] = 'This is a test key. Every message is checked and stored, and nothing reaches WhatsApp. '
                . 'Swap it for a key beginning sk_live_ when you are ready.';
        }

        $linked = [];
        foreach ($numbers as $number) {
            $msisdn = isset($number['msisdn']) ? (string) $number['msisdn'] : '';
            if ($msisdn !== '') {
                $linked[] = $msisdn;
            }

            $lines[] = sprintf(
                '%s - %s (%s)',
                isset($number['label']) ? (string) $number['label'] : 'unnamed',
                $msisdn === '' ? 'not linked yet' : '+' . $msisdn,
                isset($number['status']) ? (string) $number['status'] : 'unknown'
            );
        }

        if ($numbers === []) {
            $lines[] = 'The key works, but this account has no numbers yet. '
                . 'Add one in the Njiwa console under Numbers and link it.';
        }

        // A "send from" that names a number this account does not have is the
        // one settings mistake that breaks every message while looking
        // perfectly correct on screen, so it is worth saying out loud here
        // rather than leaving to be discovered one failed order at a time.
        $from = $this->config->sendFrom($salesChannelId);
        if ($from !== '' && !\in_array($from, $linked, true)) {
            $lines[] = 'Send from is set to ' . $from . ', which is not a number on this account, '
                . 'so every message will be refused. Correct it, or clear it to use the default number.';
        }

        return new JsonResponse([
            'ok' => true,
            'testKey' => $this->config->isTestKey($salesChannelId),
            'numbers' => $numbers,
            'message' => implode("\n", $lines),
        ]);
    }

    /**
     * One fixed message, to one number the operator names.
     *
     * The wording is fixed in the code on purpose: the operator supplies the
     * recipient and nothing else, so this endpoint cannot be turned into a way
     * of sending arbitrary WhatsApp messages from the shop's own number.
     */
    #[Route(
        path: '/api/_action/upeo-njiwa/send-test-message',
        name: 'api.action.upeo_njiwa.send_test_message',
        defaults: ['_acl' => ['system_config:update']],
        methods: ['POST']
    )]
    public function sendTestMessage(Request $request): JsonResponse
    {
        $salesChannelId = $this->salesChannelId($request);
        $payload = $this->payload($request);

        $to = $this->numbers->toMsisdn(isset($payload['to']) ? (string) $payload['to'] : '');
        if ($to === '') {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Send a "to" with your own WhatsApp number in it, digits only, '
                    . 'in full international form such as 254712345678. '
                    . 'A group address is not a phone number and is refused.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $sinceAMinuteAgo = (new \DateTimeImmutable())->modify('-1 minute');
        if ($this->log->countSince(self::TEST_EVENT, $sinceAMinuteAgo) >= self::TEST_MESSAGES_PER_MINUTE) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'That is more test messages than this is willing to send in a minute. Wait and try again.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $claim = $this->log->claim(null, null, $salesChannelId, self::TEST_EVENT, $to);

        try {
            $answer = $this->client->sendText(
                $to,
                'Test message from your shop. If you can read this, Shopware can reach your customers on WhatsApp.',
                '',
                $salesChannelId
            );
        } catch (NjiwaException $e) {
            if ($claim !== null) {
                $this->log->markFailed($claim, $e->getMessage());
            }

            return $this->refused($e);
        }

        $njiwaId = isset($answer['id']) ? (string) $answer['id'] : null;
        if ($claim !== null) {
            $this->log->markSent($claim, $njiwaId);
        }

        $message = sprintf('Sent to +%s (%s).', $to, $njiwaId ?? '?');
        if ($this->config->isTestKey($salesChannelId)) {
            $message .= ' This is a test key, so nothing actually reached the phone.';
        }

        return new JsonResponse([
            'ok' => true,
            'njiwaId' => $njiwaId,
            'testKey' => $this->config->isTestKey($salesChannelId),
            'message' => $message,
        ]);
    }

    /**
     * Njiwa said no, or could not be asked. Either way the operator gets the
     * real reason and the stable code, not a generic failure.
     */
    private function refused(NjiwaException $e): JsonResponse
    {
        return new JsonResponse([
            'ok' => false,
            'code' => $e->getErrorCode(),
            'docs' => $e->getDocs(),
            'message' => $e->getMessage(),
        ], Response::HTTP_BAD_GATEWAY);
    }

    /**
     * Which sales channel's settings to check.
     *
     * Left out, it is the shop-wide settings, which is what a shop with one
     * sales channel means every time.
     */
    private function salesChannelId(Request $request): ?string
    {
        $payload = $this->payload($request);
        $id = isset($payload['salesChannelId']) ? trim((string) $payload['salesChannelId']) : '';

        return $id === '' ? null : $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $content = $request->getContent();
        if (\is_string($content) && trim($content) !== '') {
            try {
                $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
                if (\is_array($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException $e) {
                // Not JSON. Fall through to the ordinary request parameters,
                // which is what a form post or a query string leaves behind.
            }
        }

        return $request->request->all();
    }
}
