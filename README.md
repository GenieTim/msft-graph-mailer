# msft-graph-mailer

A mailer for Symfony to send mails using Microsoft Graph (i.e., send Office 365/Outlook/Exchange E-Mails)

## Installation

Simply install using Composer:

```bash
composer require bernhardwebstudio/msft-graph-mailer
```

## Configuration

You need to tell Symofony that this is a mail transport:

```yaml
# services.yaml
services:
  mailer.transport_factory.custom:
    class: BernhardWebstudio\Mailer\Bridge\MsftGraphMailer\Transport\MsftGraphTransportFactory
    parent: mailer.transport_factory.abstract
    tags:
      - { name: "mailer.transport_factory" }
```

Finally, you need to configure the mailer bundle to use this transport,
e.g. using an ENV variable like

```bash
MAILER_DSN=msft+graph://{client-id}:{client-secret}@outlook.com?tenant={tenant-id}
```

where you replace all the values in `{}` with your own values from your active directory.

## Useage

If you set the configuration correctly, that's all.

Please note that this transport does not support all types of E-Mail messages (e.g. MultiPart messages are not supported yet).
