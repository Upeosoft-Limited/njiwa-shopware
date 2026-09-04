<?php declare(strict_types=1);

/**
 * The worker. Runs after the customer has been sent on their way.
 *
 * Everything expensive happens here: reading the order, working out who to
 * message, writing the wording and talking to Njiwa. If Njiwa is slow, or
 * down, or the shop's outbound network is having a bad afternoon, this is
 * where that happens, and nobody is standing at a checkout waiting for it.
 *
 * The order is read again rather than carried through the queue, so a message
 * says what is true when it is sent rather than what was true when it was
 * queued. On a healthy shop those are a second apart.
 */

namespace Upeo\Njiwa\MessageQueue;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Upeo\Njiwa\Exception\NjiwaException;
use Upeo\Njiwa\Service\Config;
use Upeo\Njiwa\Service\MessageLog;
use Upeo\Njiwa\Service\NjiwaClient;
use Upeo\Njiwa\Service\Numbers;
use Upeo\Njiwa\Service\OrderValues;
use Upeo\Njiwa\Service\Templates;

class SendOrderMessageHandler
{
    private EntityRepository $orderRepository;

    private Config $config;

    private Templates $templates;

    private OrderValues $orderValues;

    private Numbers $numbers;

    private NjiwaClient $client;

    private MessageLog $log;

    private LoggerInterface $logger;

    public function __construct(
        EntityRepository $orderRepository,
        Config $config,
        Templates $templates,
        OrderValues $orderValues,
        Numbers $numbers,
        NjiwaClient $client,
        MessageLog $log,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->config = $config;
        $this->templates = $templates;
        $this->orderValues = $orderValues;
        $this->numbers = $numbers;
        $this->client = $client;
        $this->log = $log;
        $this->logger = $logger;
    }

    public function __invoke(SendOrderMessage $message): void
    {
        $event = $message->getEvent();
        $context = Context::createDefaultContext();

        $order = $this->loadOrder($message->getOrderId(), $context);
        if ($order === null) {
            // An order deleted between the queue and the worker. There is
            // nobody to message and nothing to fix.
            $this->logger->info(sprintf(
                'Order %s no longer exists, so its "%s" WhatsApp message was dropped.',
                $message->getOrderId(),
                $event
            ));

            return;
        }

        $salesChannelId = $order->getSalesChannelId();

        // Everything is checked again here rather than trusted from the
        // dispatch. A merchant who turns the plugin off, or one event off,
        // means it from that moment on, including for work already queued.
        if (!$this->config->isEnabled($salesChannelId) || !$this->config->isConfigured($salesChannelId)) {
            return;
        }

        if (!$this->config->isEventOn($event, $salesChannelId)) {
            return;
        }

        $template = $this->config->template($event, $salesChannelId);
        if ($template === '') {
            // Clearing the box is how a merchant turns one message off without
            // turning the event off.
            return;
        }

        $recipients = $this->recipients($order, $event, $salesChannelId);
        if ($recipients === []) {
            // A customer with no phone number is ordinary, not an error.
            // Nothing is sent and nothing is complained about.
            return;
        }

        $text = $this->templates->render(
            $template,
            $this->orderValues->forOrder($order, $context),
            (string) $order->getOrderNumber()
        );

        if ($text === '') {
            $this->logger->warning(sprintf(
                'The wording for "%s" came out empty, so order %s sent nothing.',
                $event,
                (string) $order->getOrderNumber()
            ));

            return;
        }

        foreach ($recipients as $recipient) {
            $this->deliver($order, $event, $recipient, $text);
        }
    }

    /**
     * @return string[]
     */
    private function recipients(OrderEntity $order, string $event, string $salesChannelId): array
    {
        if ($event === Config::EVENT_ALERT) {
            return $this->numbers->parseList($this->config->alertNumbers($salesChannelId));
        }

        $number = $this->orderValues->phoneNumber($order);

        return $number === '' ? [] : [$number];
    }

    /**
     * One message, to one number, once.
     *
     * @throws NjiwaException when it is worth trying again
     */
    private function deliver(OrderEntity $order, string $event, string $recipient, string $text): void
    {
        $orderNumber = (string) $order->getOrderNumber();

        $claim = $this->log->claim(
            $order->getId(),
            $orderNumber,
            $order->getSalesChannelId(),
            $event,
            $recipient
        );

        if ($claim === null) {
            // Somebody has already sent this. That is the ordinary case for a
            // delivery transitioned back and forth, or a queue message
            // delivered twice, and it is exactly what the marker is for.
            return;
        }

        try {
            $answer = $this->client->sendText(
                $recipient,
                $text,
                $this->idempotencyKey($order->getId(), $event, $recipient),
                $order->getSalesChannelId()
            );

            $njiwaId = isset($answer['id']) ? (string) $answer['id'] : null;
            $testKey = $this->config->isTestKey($order->getSalesChannelId());

            $this->log->markSent(
                $claim,
                $njiwaId,
                $testKey ? 'Test key, so nothing reached WhatsApp.' : null
            );

            $this->logger->info(sprintf(
                'Order %s, %s: WhatsApp sent to +%s (%s).%s',
                $orderNumber,
                $event,
                $recipient,
                $njiwaId ?? '?',
                $testKey ? ' Test key, so nothing reached WhatsApp.' : ''
            ));
        } catch (NjiwaException $e) {
            if ($e->isWorthRetrying()) {
                // The message was never accepted, so the claim is given back
                // and the queue is allowed to try again. Njiwa honours the
                // Idempotency-Key for twenty-four hours, so if it turns out
                // the request did land and only the answer was lost, the retry
                // replays that answer instead of messaging the customer twice.
                //
                // Throwing here retries the whole message, including any
                // recipient already dealt with. Those are held by their own
                // claims and quietly skipped.
                $this->log->release($claim);

                $this->logger->warning(sprintf(
                    'Order %s, %s: could not reach Njiwa for +%s, so it will be tried again. %s',
                    $orderNumber,
                    $event,
                    $recipient,
                    $e->getMessage()
                ));

                throw $e;
            }

            // A refusal. Repeating it only fills the queue with work that
            // fails the same way, so it is written down and left alone.
            $this->log->markFailed($claim, $e->getMessage());

            $this->logger->error(sprintf(
                'Order %s, %s: Njiwa would not message +%s. %s (%s)',
                $orderNumber,
                $event,
                $recipient,
                $e->getMessage(),
                $e->getErrorCode()
            ));
        }
    }

    /**
     * One key per order, event and recipient.
     *
     * The recipient is part of it because one alert can go to several of the
     * shop's own numbers, and those must not collapse into one another and
     * leave everybody but the first person unmessaged.
     */
    private function idempotencyKey(string $orderId, string $event, string $recipient): string
    {
        return 'sw-' . $orderId . '-' . $event . '-' . substr(sha1($recipient), 0, 8);
    }

    private function loadOrder(string $orderId, Context $context): ?OrderEntity
    {
        $criteria = new Criteria([$orderId]);

        // Everything the wording can ask for, in one read. A placeholder that
        // resolves to nothing because its association was not loaded is the
        // kind of bug that only shows up on a customer's phone.
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('billingAddress');
        $criteria->addAssociation('deliveries.shippingOrderAddress');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addAssociation('currency');
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('salesChannel.domains');

        $order = $this->orderRepository->search($criteria, $context)->first();

        return $order instanceof OrderEntity ? $order : null;
    }
}
