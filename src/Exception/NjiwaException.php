<?php declare(strict_types=1);

/**
 * Anything Njiwa refused, or could not be asked.
 *
 * getErrorCode() is the stable, machine readable reason and is the thing to
 * branch on. The wording of the message can change; the code does not.
 */

namespace Upeo\Njiwa\Exception;

class NjiwaException extends \RuntimeException
{
    private string $errorCode;

    private int $status;

    private ?string $docs;

    public function __construct(
        string $message,
        string $errorCode = 'unknown',
        int $status = 0,
        ?string $docs = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);

        $this->errorCode = $errorCode;
        $this->status = $status;
        $this->docs = $docs;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getDocs(): ?string
    {
        return $this->docs;
    }

    /**
     * Whether asking again later could plausibly work.
     *
     * A network failure is not a send failure: the message was never accepted,
     * so nobody has been messaged and trying again is safe. The same goes for
     * a 429 and for anything in the 500s, which are Njiwa saying "not now"
     * rather than "no". Everything else is a refusal — a bad key, a number
     * that is not on WhatsApp, an account with no credit — and repeating it
     * only fills the queue with work that will fail again in the same way.
     */
    public function isWorthRetrying(): bool
    {
        if ($this->errorCode === 'connection_failed') {
            return true;
        }

        return $this->status === 429 || $this->status >= 500;
    }
}
