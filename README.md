# EmlBlock

Kanboard plugin that renders a raw e-mail (RFC 822 / `.eml`) pasted into a
fenced code block as formatted Markdown: subject heading, header table,
attachment list, the sender's own text, and the quoted history.

````markdown
```eml
From: Someone <someone@example.com>
Subject: Network issue
...
```
````

Info-string options:

| Fence            | Effect                                  |
|------------------|-----------------------------------------|
| ` ```eml `         | full rendering                          |
| ` ```eml noquote ` | drop the quoted history                 |
| ` ```eml sig `     | keep the first line of the signature    |

If the block cannot be parsed as an e-mail (no `From`/`Subject`) or parsing
fails, it is left as a normal code block.

## How it works

The plugin overrides the `text` helper and expands ```` ```eml ```` blocks into
Markdown *before* the regular renderer runs. If
[MarkdownPlus](https://github.com/creecros/MarkdownPlus) is installed, the
helper inherits from it, so checkboxes, emoji and ParsedownExtra keep working;
otherwise it extends the core `TextHelper`.

The override is registered in `onStartup()` (the `app.bootstrap` event), which
runs after every plugin's `initialize()`. Kanboard loads plugins in unsorted
directory order, so this is what guarantees the correct order.

The MIME parser needs only `mbstring` and `iconv`. It handles multipart
messages, base64 / quoted-printable, RFC 2047 encoded headers, RFC 2231
filenames, `format=flowed`, legacy charsets such as windows-1250, and falls
back to a simple HTML → Markdown conversion for HTML-only mails.

## Installation

```bash
cd /path/to/kanboard/plugins
git clone https://github.com/redaxhu/EmlBlock.git
```

The directory must be named `EmlBlock`. Restart PHP-FPM / the container if
opcache does not pick up the new files.

## Notes

- The raw e-mail, including base64-encoded attachments, is stored in the
  database. On MySQL, check the size limit of the comment/description columns
  before pasting large messages with attachments.
- Output labels are currently Hungarian.

## License

MIT
