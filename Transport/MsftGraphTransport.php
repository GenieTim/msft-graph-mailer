<?php

namespace BernhardWebstudio\Mailer\Bridge\MsftGraphMailer\Transport;

use InvalidArgumentException;
use Microsoft\Graph\Generated\Models\Attachment;
use Microsoft\Graph\Generated\Models\BodyType;
use Microsoft\Graph\Generated\Models\EmailAddress;
use Microsoft\Graph\Generated\Models\FileAttachment;
use Microsoft\Graph\Generated\Models\ItemBody;
use Microsoft\Graph\Generated\Models\Message;
use Microsoft\Graph\Generated\Models\ODataErrors\ODataError;
use Microsoft\Graph\Generated\Models\Recipient;
use Microsoft\Graph\Generated\Users\Item\SendMail\SendMailPostRequestBody;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\InvalidArgumentException as ExceptionInvalidArgumentException;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Message as SymfonyMessage;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;
use UnexpectedValueException;

final class MsftGraphTransport extends AbstractTransport
{
	private bool $saveToSent = true;

	private ?GraphServiceClient $client = null;

	private LoggerInterface $logger;

	private ?string $tenantId;

	private ?string $clientId;

	private ?string $clientSecret;

	private EventDispatcherInterface $dispatcher;

	public function __construct(?string $tenantId, string $clientId, string $clientSecret, bool $saveToSent, EventDispatcherInterface $dispatcher, LoggerInterface $logger)
	{
		$this->logger = $logger;
		$this->clientId = $clientId;
		$this->tenantId = $tenantId;
		$this->saveToSent = $saveToSent;
		$this->clientSecret = $clientSecret;
		$this->dispatcher = $dispatcher;

		parent::__construct($dispatcher, $logger);
	}

	public function __toString(): string
	{
		return 'msft+graph://' . $this->clientId . ':' . $this->clientSecret . '@' . 'outlook.com?tenant=' . $this->tenantId;
	}

	protected function doSend(SentMessage $message): void
	{
		$symfonyMessage = $message->getOriginalMessage();
		if (!$symfonyMessage instanceof SymfonyMessage) {
			throw new InvalidArgumentException(
				'Cannot send messages that are not easily parsable anymore, got ' .
					get_class($message) . ' instead of ' . SymfonyMessage::class . '.'
			);
		}
		$headers = $symfonyMessage->getHeaders();
		$email = MessageConverter::toEmail($symfonyMessage);

		// handle login if needed
		$this->getClient();

		// now, we can use the client
		$requestBody = new SendMailPostRequestBody();

		$msftMessage = new Message();
		$msftMessage->setSubject($email->getSubject() ?? $headers->get('Subject')->toString());

		$msftMessage = $this->processRecipients($msftMessage, $headers);
		$msftMessage = $this->addParts($msftMessage, $email);
		$msftMessage->setHasAttachments($msftMessage->getAttachments() !== null && count($msftMessage->getAttachments()) > 0);

		$requestBody->setMessage($msftMessage);
		$requestBody->setSaveToSentItems($this->saveToSent);

		try {
			$this->client->users()->byUserId($message->getEnvelope()->getSender()->getAddress())->sendMail()->post($requestBody)->wait();
		} catch (ODataError $e) {
			$this->logger->error('Failed to send E-Mail using Microsoft Graph API: ' . $e->getMessage() . '; ' . $e->getError()->getMessage(), [
				$e,
				$e->getError(),
			]);
			throw new TransportException("MSFT Graph API error: " . $e->getError()->getMessage(), $e->getCode(), $e);
		}
	}

	/**
	 * Convert the actual content/body parts of the message from the symfony types to Microsoft Graph
	 *
	 * @param Message $message the message to add the parts to
	 * @throws InvalidArgumentException
	 * @throws UnexpectedValueException
	 */
	protected function addParts(Message $message, Email $mail): Message
	{
		if (null !== $text = $mail->getTextBody()) {
			$body = new ItemBody();
			$body->setContent($text);
			$body->setContentType(new BodyType(BodyType::TEXT));
			$message->setBody($body);
		}
		if (null !== $text = $mail->getHtmlBody()) {
			$body = new ItemBody();
			$body->setContent($text);
			$body->setContentType(new BodyType(BodyType::HTML));
			$message->setBody($body);
		}

		$msftAttachments = [];
		foreach ($mail->getAttachments() as $attachment) {
			assert($attachment instanceof DataPart);
			$msftAttachment = $this->convertAttachment($attachment);
			$msftAttachments[] = $msftAttachment;
		}
		$message->setAttachments($msftAttachments);

		return $message;
	}

	/**
	 * Convert a Symonfy data part to a Microsoft Graph attachment
	 *
	 * @param DataPart $attachment the symfony attachment
	 * @return Attachment the Microsoft Graph attachment
	 * @throws ExceptionInvalidArgumentException
	 * @throws InvalidArgumentException
	 */
	protected function convertAttachment(DataPart $attachment): Attachment
	{
		$msftAttachment = new FileAttachment();
		$msftAttachment->setOdataType('#microsoft.graph.fileAttachment');
		$msftAttachment->setName($attachment->getFilename());
		$msftAttachment->setContentType($attachment->getContentType());
		$msftAttachment->setContentBytes(
			\GuzzleHttp\Psr7\Utils::streamFor(base64_encode($attachment->bodyToString()))
		);
		return $msftAttachment;
	}

	/**
	 * Add the recipients from the Symonfy message headers to the MSFT graph message
	 *
	 * @param Message $message the message to add recipients to
	 * @param Headers $headers the headers to "parse"
	 * @return Message
	 */
	protected function processRecipients(Message $message, Headers $headers)
	{
		if ($headers->has('to')) {
			$message->setToRecipients($this->getRecipientsForHeader($headers->all('to')));
		}
		if ($headers->has('cc')) {
			$message->setCcRecipients($this->getRecipientsForHeader($headers->all('cc')));
		}
		if ($headers->has('bcc')) {
			$message->setBccRecipients($this->getRecipientsForHeader($headers->all('bcc')));
		}
		return $message;
	}

	private function getClient(): GraphServiceClient
	{
		if ($this->client !== null) {
			return $this->client;
		}
		// handle login only here, deferred
		$tokenRequestContext = new ClientCredentialContext(
			$this->tenantId,
			$this->clientId,
			$this->clientSecret
		);

		$this->client = new GraphServiceClient($tokenRequestContext, []);
		return $this->client;
	}

	/**
	 * Convert message headers to recipients
	 *
	 * @return Recipient[]
	 */
	private function getRecipientsForHeader(iterable $headers)
	{
		$recipients = [];
		foreach ($headers as $header) {
			foreach ($header->getAddresses() as $address) {
				assert($address instanceof Address);
				$recipient = $this->addressToRecipient($address);
				$recipients[] = $recipient;
			}
		}
		return $recipients;
	}

	/**
	 * Convert the Symfony address to the MSFT Graph version
	 */
	private function addressToRecipient(Address $address): Recipient
	{
		$recipient = new Recipient();
		$emailAddress = new EmailAddress();
		$emailAddress->setAddress($address->getAddress());
		$emailAddress->setName($address->getName());
		$recipient->setEmailAddress($emailAddress);
		return $recipient;
	}
}
