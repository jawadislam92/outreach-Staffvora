<?php

namespace App\Services\Sending;

use App\Models\Attachment;
use App\Models\EmailAccount;
use App\Services\Templates\TemplateRenderer;
use App\Support\Markdown;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Turns a SendRequest into a Symfony Email: template variables → Markdown →
 * HTML + plain-text alternative, signature, attachments and an explicit
 * Message-ID (so every connector logs the same id).
 */
class MimeBuilder
{
    /**
     * @return array{email: Email, subject: string, body_md: string, body_html: string, body_text: string}
     */
    public function build(SendRequest $request, EmailAccount $account, iterable $attachments = []): array
    {
        $subject = TemplateRenderer::render($request->subject, $request->variables);
        $bodyMd = TemplateRenderer::render($request->bodyMd, $request->variables);

        $bodyHtml = Markdown::toHtml($bodyMd);
        $bodyText = Markdown::toText($bodyMd);

        $signatureMd = trim((string) $account->signature_md);
        if ($signatureMd !== '') {
            $bodyText .= "\n\n-- \n".Markdown::toText($signatureMd);
        }
        $document = Markdown::emailDocument($bodyHtml, $signatureMd !== '' ? Markdown::toHtml($signatureMd) : null);

        $email = (new Email)
            ->from(new Address($account->from_email, $account->from_name))
            ->to(new Address($request->to, (string) $request->toName))
            ->subject($subject)
            ->text($bodyText)
            ->html($document);

        if (filled($account->reply_to)) {
            $email->replyTo(new Address($account->reply_to));
        }
        foreach ($request->cc as $cc) {
            $email->addCc(new Address($cc));
        }
        foreach ($request->bcc as $bcc) {
            $email->addBcc(new Address($bcc));
        }

        foreach ($attachments as $attachment) {
            /** @var Attachment $attachment */
            $path = Storage::disk(Attachment::DISK)->path($attachment->stored_path);
            $email->attachFromPath($path, $attachment->safeName(), $attachment->mime);
        }

        $email->getHeaders()->addIdHeader('Message-ID', $email->generateMessageId());

        return [
            'email' => $email,
            'subject' => $subject,
            'body_md' => $bodyMd,
            'body_html' => $document,
            'body_text' => $bodyText,
        ];
    }

    /**
     * RFC 2822 bytes for the Gmail API. Symfony's prepared headers strip Bcc
     * (correct for SMTP, where the envelope carries it) but for Gmail the raw
     * message IS the envelope, so Bcc is re-added here.
     */
    public static function toGmailRaw(Email $email): string
    {
        $headers = $email->getPreparedHeaders();
        if ($bcc = $email->getBcc()) {
            $headers->addMailboxListHeader('Bcc', $bcc);
        }

        $body = $email->getBody();

        return $headers->toString().$body->toString();
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
