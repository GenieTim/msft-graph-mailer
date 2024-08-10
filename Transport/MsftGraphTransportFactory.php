<?php

namespace BernhardWebstudio\Mailer\Bridge\MsftGraphMailer\Transport;

use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * @author Tim Bernhard <tim@bernhard.dev>
 * @package BernhardWebstudio\Mailer\Bridge\MsftGraphMailer\Transport
 */
final class MsftGraphTransportFactory extends AbstractTransportFactory
{
	public function create(Dsn $dsn): TransportInterface
	{
		if (\in_array($dsn->getScheme(), $this->getSupportedSchemes())) {
			return new MsftGraphTransport($dsn->getOption('tenant'), $this->getUser($dsn), $this->getPassword($dsn), boolval($dsn->getOption('saveToSent', true)), $this->dispatcher, $this->logger);
		}

		throw new UnsupportedSchemeException($dsn, 'msft', $this->getSupportedSchemes());
	}

	protected function getSupportedSchemes(): array
	{
		return ['msft+graph'];
	}
}
