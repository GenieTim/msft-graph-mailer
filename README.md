# msft-graph-mailer

A mailer for Symfony to send mails using Microsoft Graph (i.e., send Office 365/Outlook/Exchange E-Mails)

## Installation

Simply install using Composer:

```bash
composer require bernhardwebstudio/msft-graph-mailer
```

## Configuration

You need to tell Symfony that this is a mail transport:

```yaml
# services.yaml
services:
  mailer.transport_factory.msftgraph:
    class: BernhardWebstudio\Mailer\Bridge\MsftGraphMailer\Transport\MsftGraphTransportFactory
    parent: mailer.transport_factory.abstract
    tags:
      - { name: "mailer.transport_factory" }
```

Finally, you need to configure the mailer bundle to use this transport,
e.g. using an ENV variable like

```bash
MAILER_DSN=msft+graph://{client-id}:{client-secret}@outlook.com?saveToSent=1&tenant={tenant-id}
```

where you replace all the values in `{}` with your own values from your active directory.

The `tenant` and `saveToSent` options are optional.

## Usage

If you set the configuration correctly, that's all, you can simply use the Symfony mailer
and you will be sending the E-Mails using the Microsoft Graph API.

Please note that this transport does not support all types of E-Mail messages in its best form.
Please contribute if you understand enough about MIME and Microsoft Graph to fix this.

Additionally, Microsoft imposes restrictions on the sender E-Mail you can use.
Simply be aware of that when setting the `From` of the E-Mail, please, if you want to prevent errors.
