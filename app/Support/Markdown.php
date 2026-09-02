<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Email bodies are authored in Markdown. HTML input is escaped (lead data and
 * template text can never inject markup) and unsafe links are dropped.
 */
class Markdown
{
    public static function toHtml(string $markdown): string
    {
        $html = (string) Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'renderer' => ['soft_break' => "<br />\n"],
        ]);

        return trim($html);
    }

    /** Wrap rendered body HTML in a minimal, personal-looking email frame. */
    public static function emailDocument(string $bodyHtml, ?string $signatureHtml = null): string
    {
        $sig = $signatureHtml ? "\n<div style=\"margin-top:16px;color:#374151\">{$signatureHtml}</div>" : '';

        return "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"></head>"
            ."<body style=\"margin:0;padding:0\">"
            ."<div style=\"font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.55;color:#111827\">"
            .$bodyHtml.$sig
            ."</div></body></html>";
    }

    /** Plain-text alternative: Markdown source with the obvious syntax removed. */
    public static function toText(string $markdown): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $markdown);
        // [label](url) → label (url); bare autolinks <https://x> → https://x
        $text = preg_replace('/\[([^\]]+)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/u', '$1 ($2)', $text) ?? $text;
        $text = preg_replace('/<(https?:\/\/[^>]+)>/u', '$1', $text) ?? $text;
        // Emphasis / code markers
        $text = preg_replace('/(\*\*|__)(.+?)\1/su', '$2', $text) ?? $text;
        $text = preg_replace('/(?<![\w*])(\*|_)(?!\s)(.+?)(?<!\s)\1(?![\w*])/su', '$2', $text) ?? $text;
        $text = preg_replace('/`([^`]+)`/u', '$1', $text) ?? $text;
        // Headings, blockquotes, list markers
        $text = preg_replace('/^#{1,6}\s+/mu', '', $text) ?? $text;
        $text = preg_replace('/^>\s?/mu', '', $text) ?? $text;
        $text = preg_replace('/^(\s*)[-*+]\s+/mu', '$1- ', $text) ?? $text;
        // Escaped punctuation
        $text = preg_replace('/\\\\([\\\\`*_{}\[\]()#+\-.!>])/u', '$1', $text) ?? $text;

        return trim($text);
    }
}
