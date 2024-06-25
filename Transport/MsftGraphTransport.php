<?php

namespace BernhardWebstudio\Mailer\Bridge\MsftGraphMailer\Transport;

use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use UnexpectedValueException;
use Symfony\Component\Mime\Address;
use Microsoft\Graph\GraphServiceClient;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\TextPart;
use Symfony\Component\Mime\Header\Headers;
use Microsoft\Graph\Generated\Models\Message;
use Symfony\Component\Mime\Part\AbstractPart;
use Microsoft\Graph\Generated\Models\BodyType;
use Microsoft\Graph\Generated\Models\ItemBody;
use Microsoft\Graph\Generated\Models\Recipient;
use Microsoft\Graph\Generated\Models\Attachment;
use Psr\EventDispatcher\EventDispatcherInterface;
use Microsoft\Graph\Generated\Models\EmailAddress;
use Microsoft\Graph\Generated\Models\FileAttachment;
use Symfony\Component\Mime\Message as SymfonyMessage;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mime\Part\AbstractMultipartPart;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;
use Microsoft\Graph\Generated\Users\Item\SendMail\SendMailPostRequestBody;

final class MsftGraphTransport extends AbstractTransport
{
  private ?GraphServiceClient $client = null;
  private LoggerInterface $logger;
  private ?string $tenantId;
  private ?string $clientId;
  private ?string $clientSecret;
  private EventDispatcherInterface $dispatcher;

  public function __construct(?string $tenantId, string $clientId, string $clientSecret, EventDispatcherInterface $dispatcher, LoggerInterface $logger)
  {
    $this->logger = $logger;
    $this->clientId = $clientId;
    $this->tenantId = $tenantId;
    $this->clientSecret = $clientSecret;
    $this->dispatcher = $dispatcher;

    parent::__construct($dispatcher, $logger);
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

  protected function doSend(SentMessage $message): void
  {
    $symfonyMessage = $message->getOriginalMessage();
    if (!$symfonyMessage instanceof SymfonyMessage) {
      throw new InvalidArgumentException(
        "Cannot send messages that are not easily parsable anymore, got " .
          get_class($message) . " instead of " . SymfonyMessage::class . "."
      );
    }
    $headers = $symfonyMessage->getHeaders();
    // handle login if needed
    $this->getClient();

    // now, we can use the client
    $requestBody = new SendMailPostRequestBody();

    $gmessage = new Message();
    $gmessage->setSubject($headers->get('Subject'));

    $gmessage = $this->processRecipients($gmessage, $headers);
    $gmessage = $this->addParts($gmessage, $symfonyMessage->getBody());
    $gmessage->setHasAttachments(count($gmessage->getAttachments()) > 0);

    $requestBody->setMessage($gmessage);
    $requestBody->setSaveToSentItems(false);

    $this->client->users()->byUserId($message->getEnvelope()->getSender()->getAddress())->sendMail()->post($requestBody)->wait();
  }

  /**
   * Convert the actual content/body parts of the message from the symfony types to Microsoft Graph
   * 
   * @param Message $message the message to add the parts to
   * @param AbstractPart $part the part to add
   * @return Message 
   * @throws InvalidArgumentException 
   * @throws UnexpectedValueException 
   */
  protected function addParts(Message $message, AbstractPart $part): Message
  {
    if ($part instanceof DataPart) {
      $attachment = new FileAttachment();
      $attachment->setOdataType('#microsoft.graph.fileAttachment');
      $attachment->setName($part->getFilename());
      $attachment->setContentType($part->getContentType());
      $attachment->setContentBytes(
        \GuzzleHttp\Psr7\Utils::streamFor(base64_encode($part->bodyToString()))
      );
      $attachments = $message->getAttachments() ?? [];
      $attachments[] = $attachment;
      $message->setAttachments($attachments);
    } else if ($part instanceof TextPart) {
      $body = new ItemBody();
      $body->setContentType(new BodyType($part->getMediaSubtype()));
      $body->setContent($part->bodyToString());
      $message->setBody($body);
    } else if ($part instanceof AbstractMultipartPart) {
      $attachment = new FileAttachment();
      $attachment->setOdataType('#microsoft.graph.fileAttachment');
      $attachment->setIsInline(true);
      $attachment->setContentType('multipart/' . $part->getMediaSubtype());
      $attachment->setContentBytes(
        \GuzzleHttp\Psr7\Utils::streamFor(base64_encode($part->bodyToString()))
      );
      $attachments = $message->getAttachments() ?? [];
      $attachments[] = $attachment;
      $message->setAttachments($attachments);
    } else {
      throw new InvalidArgumentException(
        "MSFTGraphTransport does not support message part of class "
          . get_class($part) . ". Part is: " . $part->asDebugString()
      );
    }

    return $message;
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

  /**
   * Convert message headers to recipients
   * 
   * @param iterable $headers 
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
   * 
   * @param Address $address 
   * @return Recipient 
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

  public function __toString(): string
  {
    return "msft+graph://" . $this->clientId . ":" . $this->clientSecret . "@" . "outlook.com?tenant=" . $this->tenantId;
  }
}
