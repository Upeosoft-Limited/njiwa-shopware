<?php declare(strict_types=1);

/**
 * Paid, shipped, cancelled, refunded.
 *
 * All four are state machine transitions in Shopware, and they arrive here as
 * one event. StateMachineTransitionEvent is the reliable place to listen: it
 * is dispatched by the registry every transition goes through, whoever asked
 * for it — an administrator, a payment handler finalising a return from the
 * bank, the API, or a scheduled task — so none of those routes is a hole.
 *
 * StateMachineTransitionEvent lives in System\StateMachine\Event, and the
 * order-shaped classes below live under Checkout\Order. Those namespaces are
 * checked against 6.5 and 6.6; a listener registered against a class-string
 * Shopware does not dispatch fails silently, because getSubscribedEvents()
 * never autoloads what it returns.
 *
 * Which state machine a transition belongs to is read from the entity name,
 * because "cancelled" exists on three of them and means three different
 * things. The technical names below are Shopware's own constants rather than
 * strings, so a shop that has renamed a status in its own language still
 * matches, and a shop that has added a status of its own does not.
 */

namespace Upeo\Njiwa\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Upeo\Njiwa\Service\Config;
use Upeo\Njiwa\Service\Notifier;

class StateChangeSubscriber implements EventSubscriberInterface
{
    private Notifier $notifier;

    private EntityRepository $orderRepository;

    private EntityRepository $orderTransactionRepository;

    private EntityRepository $orderDeliveryRepository;

    private LoggerInterface $logger;

    public function __construct(
        Notifier $notifier,
        EntityRepository $orderRepository,
        EntityRepository $orderTransactionRepository,
        EntityRepository $orderDeliveryRepository,
        LoggerInterface $logger
    ) {
        $this->notifier = $notifier;
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->orderDeliveryRepository = $orderDeliveryRepository;
        $this->logger = $logger;
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => 'onTransition',
        ];
    }

    public function onTransition(StateMachineTransitionEvent $event): void
    {
        // Everything in here is inside somebody's state transition. The
        // registry calls its listeners in the middle of the work that marks a
        // payment paid or an order shipped, so a throw from this method does
        // not merely lose a WhatsApp message: it becomes an error in the
        // payment handler finalising a return from the bank, or in the
        // administrator's save. Reading the place and reading the order both
        // touch the database and both can fail on a row that has been deleted
        // or written again underneath us, so the whole body is guarded rather
        // than the send alone.
        try {
            $ours = $this->eventFor($event->getEntityName(), $event->getToPlace()->getTechnicalName());
            if ($ours === null) {
                return;
            }

            $order = $this->orderFor($event->getEntityName(), $event->getEntityId(), $event->getContext());
            if ($order === null) {
                return;
            }

            $this->notifier->orderEvent($order->getId(), $order->getSalesChannelId(), $ours);
        } catch (\Throwable $e) {
            // Only the two plain string getters are used here. Anything that
            // reads a related row could be the very thing that threw.
            $this->logger->error(sprintf(
                'Could not handle a state change on %s %s for WhatsApp: %s',
                $event->getEntityName(),
                $event->getEntityId(),
                $e->getMessage()
            ), ['exception' => $e]);
        }
    }

    /**
     * The four transitions worth telling a customer about, and nothing else.
     *
     * Partial states are deliberately not here. "Partly paid" and "partly
     * shipped" are bookkeeping the shop does; a customer told their order has
     * shipped when half of it has is a customer waiting at the door.
     */
    private function eventFor(string $entityName, string $toState): ?string
    {
        if ($entityName === OrderTransactionDefinition::ENTITY_NAME) {
            if ($toState === OrderTransactionStates::STATE_PAID) {
                return Config::EVENT_PAID;
            }
            if ($toState === OrderTransactionStates::STATE_REFUNDED) {
                return Config::EVENT_REFUNDED;
            }

            return null;
        }

        if ($entityName === OrderDeliveryDefinition::ENTITY_NAME && $toState === OrderDeliveryStates::STATE_SHIPPED) {
            return Config::EVENT_SHIPPED;
        }

        // Cancelling the order itself, rather than one of its payments or one
        // of its deliveries. That is the one the customer needs to hear about.
        if ($entityName === OrderDefinition::ENTITY_NAME && $toState === OrderStates::STATE_CANCELLED) {
            return Config::EVENT_CANCELLED;
        }

        return null;
    }

    /**
     * A transaction and a delivery both know which order they belong to, but
     * only by id, so one small read turns either into the order this is
     * actually about — and gives the sales channel, which is the scope every
     * setting is then read at.
     */
    private function orderFor(string $entityName, string $entityId, Context $context): ?OrderEntity
    {
        // Read as the system rather than as whoever clicked the button. An
        // administrator whose role cannot see orders can still be the one who
        // marks a payment paid, and the customer should hear about it either
        // way.
        return $context->scope(Context::SYSTEM_SCOPE, function (Context $scoped) use ($entityName, $entityId): ?OrderEntity {
            if ($entityName === OrderDefinition::ENTITY_NAME) {
                $order = $this->orderRepository->search(new Criteria([$entityId]), $scoped)->first();

                return $order instanceof OrderEntity ? $order : null;
            }

            $repository = $entityName === OrderTransactionDefinition::ENTITY_NAME
                ? $this->orderTransactionRepository
                : $this->orderDeliveryRepository;

            $criteria = new Criteria([$entityId]);
            $criteria->addAssociation('order');

            $entity = $repository->search($criteria, $scoped)->first();
            if ($entity === null || !method_exists($entity, 'getOrder')) {
                return null;
            }

            $order = $entity->getOrder();

            return $order instanceof OrderEntity ? $order : null;
        });
    }
}
