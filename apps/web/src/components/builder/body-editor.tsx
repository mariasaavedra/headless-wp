"use client";

import { useRef, useState, useTransition } from "react";
import {
  BoldIcon,
  Heading2Icon,
  Heading3Icon,
  ImageIcon,
  ItalicIcon,
  KeyRoundIcon,
  LinkIcon,
  ListIcon,
  Loader2Icon,
  PaperclipIcon,
  QuoteIcon,
  VideoIcon,
} from "lucide-react";

import { Button } from "@pcle/ui/components/button";
import {
  ButtonGroup,
  ButtonGroupSeparator,
} from "@pcle/ui/components/button-group";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@pcle/ui/components/tooltip";

import { uploadMediaAction } from "@/app/actions/authoring";
import type { PreservedRegion } from "@/lib/types";

/**
 * The body field, with the formatting applied for you.
 *
 * What is stored is still plain text, and the server still builds the block
 * markup from it — see authoring-content.php, which is emphatic that a client
 * never sends HTML, because instructors do not hold `unfiltered_html` and an
 * authoring API must not become the way around that. None of that changes
 * here.
 *
 * What changes is that the syntax stops being something to memorise. The page
 * used to carry a six-row legend of `## Heading`, `- item`, `> text` and so
 * on, which an author had to read before writing anything. The buttons insert
 * the same characters, and the tooltips still name them, so anyone who would
 * rather type it can — and anyone who would rather not, no longer has to.
 */

/**
 * Block markers the toggles recognise, so switching between them is clean.
 *
 * "! " is the model answer. The image marker "![" is deliberately not here:
 * it has no space after the bang, so it cannot be mistaken for one, and it is
 * not a line prefix anything toggles into.
 */
const BLOCK_MARKERS = /^(#{2,3} |- |> |! )/;

/**
 * Is this text already wrapped in the marker?
 *
 * The single "*" of italic has to not match the "**" of bold, or toggling
 * italic on bold text would quietly demote it.
 */
function isWrapped(text: string, marker: string) {
  if (text.length < marker.length * 2) return false;
  if (!text.startsWith(marker) || !text.endsWith(marker)) return false;
  if (marker === "*" && text.startsWith("**")) return false;
  return true;
}

/** One toolbar button. Declared here rather than inside the editor so it is
 * not a new component type on every render. */
function ToolButton({
  label,
  hint,
  onClick,
  children,
}: {
  label: string;
  hint: string;
  onClick: () => void;
  children: React.ReactNode;
}) {
  return (
    <Tooltip>
      <TooltipTrigger
        render={
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            onClick={onClick}
            aria-label={label}
          />
        }
      >
        {children}
      </TooltipTrigger>
      <TooltipContent>
        {label} <span className="font-mono opacity-70">{hint}</span>
      </TooltipContent>
    </Tooltip>
  );
}

export default function BodyEditor({
  name = "body",
  defaultValue,
  preserved = [],
  nodeId,
  rows = 18,
  id = "node-body",
}: {
  name?: string;
  defaultValue: string;
  /**
   * The node files are attached to. Without it the attach button is not
   * offered at all, because there is nothing to attach them to.
   */
  nodeId?: number;
  /**
   * Regions of the body the authored syntax cannot spell. They appear in the
   * text as tokens; the note below the field says what each one is, because a
   * bare `[[block:2:9f1c…]]` in the middle of a draft is otherwise unreadable.
   */
  preserved?: PreservedRegion[];
  rows?: number;
  id?: string;
}) {
  const ref = useRef<HTMLTextAreaElement>(null);
  const fileRef = useRef<HTMLInputElement>(null);
  const [uploading, startUpload] = useTransition();
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [attached, setAttached] = useState<{ token: string; name: string }[]>(
    []
  );

  /** Uncontrolled on purpose: the form posts the textarea, not React state. */
  function notify(textarea: HTMLTextAreaElement) {
    textarea.dispatchEvent(new Event("input", { bubbles: true }));
    textarea.focus();
  }

  /**
   * Prefixes every line the selection touches, or strips the prefix if they
   * all already have it. Any other block marker is replaced rather than
   * stacked, so a heading can become a list without becoming "- ## ".
   */
  function applyBlock(prefix: string) {
    const textarea = ref.current;
    if (!textarea) return;

    const { selectionStart, selectionEnd, value } = textarea;
    const start = value.lastIndexOf("\n", selectionStart - 1) + 1;
    const lineEnd = value.indexOf("\n", selectionEnd);
    const end = lineEnd === -1 ? value.length : lineEnd;

    const lines = value.slice(start, end).split("\n");
    const allPrefixed = lines.every((line) => line.startsWith(prefix));

    const next = lines
      .map((line) => {
        const bare = line.replace(BLOCK_MARKERS, "");
        return allPrefixed ? bare : prefix + bare;
      })
      .join("\n");

    textarea.setRangeText(next, start, end, "select");
    notify(textarea);
  }

  /** Wraps the selection, or unwraps it when it is already wrapped. */
  function applyInline(marker: string) {
    const textarea = ref.current;
    if (!textarea) return;

    const { selectionStart, selectionEnd, value } = textarea;
    const selected = value.slice(selectionStart, selectionEnd);
    const before = value.slice(selectionStart - marker.length, selectionStart);
    const after = value.slice(selectionEnd, selectionEnd + marker.length);

    if (isWrapped(selected, marker)) {
      /*
       * Applying a marker leaves the markers inside the selection, so this is
       * the case a second click actually hits — without it, pressing bold
       * twice produced ****text**** rather than undoing itself.
       */
      textarea.setRangeText(
        selected.slice(marker.length, selected.length - marker.length),
        selectionStart,
        selectionEnd,
        "select"
      );
    } else if (before === marker && after === marker) {
      // Markers outside the selection: the reader selected the inner text.
      textarea.setRangeText(
        selected,
        selectionStart - marker.length,
        selectionEnd + marker.length,
        "select"
      );
    } else if (selected) {
      textarea.setRangeText(
        marker + selected + marker,
        selectionStart,
        selectionEnd,
        "select"
      );
    } else {
      // Nothing selected: leave the cursor between the markers, ready to type.
      textarea.setRangeText(marker + marker, selectionStart, selectionEnd, "end");
      const caret = selectionStart + marker.length;
      textarea.setSelectionRange(caret, caret);
    }

    notify(textarea);
  }

  /** Inserts a link and selects the URL, which is the part still to be filled. */
  function applyLink() {
    const textarea = ref.current;
    if (!textarea) return;

    const { selectionStart, selectionEnd, value } = textarea;
    const label = value.slice(selectionStart, selectionEnd) || "link text";
    const placeholder = "https://";

    textarea.setRangeText(
      `[${label}](${placeholder})`,
      selectionStart,
      selectionEnd,
      "end"
    );

    const urlStart = selectionStart + label.length + 3;
    textarea.setSelectionRange(urlStart, urlStart + placeholder.length);
    notify(textarea);
  }

  /**
   * Inserts a marker that takes a URL, and selects the URL for typing over.
   *
   * Images and embeds are both "a line naming a location", so they differ only
   * in what surrounds the address.
   */
  function applyUrlBlock(before: string, after: string) {
    const textarea = ref.current;
    if (!textarea) return;

    const { selectionStart, selectionEnd, value } = textarea;
    const placeholder = "https://";

    // Start on a line of its own: both markers are whole-line constructs.
    const atLineStart =
      selectionStart === 0 || value[selectionStart - 1] === "\n";
    const lead = atLineStart ? "" : "\n";
    const snippet = `${lead}${before}${placeholder}${after}`;

    textarea.setRangeText(snippet, selectionStart, selectionEnd, "end");

    const urlStart = selectionStart + lead.length + before.length;
    textarea.setSelectionRange(urlStart, urlStart + placeholder.length);
    notify(textarea);
  }

  /**
   * Drops text at the caret as its own paragraph.
   *
   * Blank lines on both sides: the parser treats a marker as its own block
   * either way, but butted against the next sentence it reads as though it
   * belongs to it, and the author has to fix the spacing by hand.
   */
  function insertLine(text: string) {
    const textarea = ref.current;
    if (!textarea) return;

    const { selectionStart, selectionEnd, value } = textarea;
    const before = value.slice(0, selectionStart);
    const after = value.slice(selectionEnd);

    const lead = before === "" || before.endsWith("\n\n") ? "" : before.endsWith("\n") ? "\n" : "\n\n";
    const trail = after === "" ? "" : after.startsWith("\n\n") ? "" : after.startsWith("\n") ? "\n" : "\n\n";

    textarea.setRangeText(
      `${lead}${text}${trail}`,
      selectionStart,
      selectionEnd,
      "end"
    );
    notify(textarea);
  }

  /**
   * Uploads the chosen file and puts its token where the caret was.
   *
   * The input is cleared afterwards so that picking the same file twice still
   * fires a change event — otherwise a failed upload could not be retried
   * without choosing a different file first.
   */
  function onFileChosen(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    event.target.value = "";

    if (!file || !nodeId) return;

    setUploadError(null);

    startUpload(async () => {
      const body = new FormData();
      body.set("file", file);

      const result = await uploadMediaAction(nodeId, body);

      if (result.error || !result.media) {
        setUploadError(result.error ?? "The file could not be attached.");
        return;
      }

      insertLine(result.media.token);
      setAttached((current) => [
        ...current,
        { token: result.media!.token, name: result.media!.filename },
      ]);
    });
  }

  return (
    <div className="mt-1">
      <ButtonGroup className="mb-2">
        <ToolButton label="Heading" hint="##" onClick={() => applyBlock("## ")}>
          <Heading2Icon className="size-4" />
        </ToolButton>
        <ToolButton
          label="Smaller heading"
          hint="###"
          onClick={() => applyBlock("### ")}
        >
          <Heading3Icon className="size-4" />
        </ToolButton>

        <ButtonGroupSeparator />

        <ToolButton label="Bulleted list" hint="-" onClick={() => applyBlock("- ")}>
          <ListIcon className="size-4" />
        </ToolButton>
        <ToolButton label="Quotation" hint="&gt;" onClick={() => applyBlock("> ")}>
          <QuoteIcon className="size-4" />
        </ToolButton>
        <ToolButton
          label="Model answer"
          hint="!"
          onClick={() => applyBlock("! ")}
        >
          <KeyRoundIcon className="size-4" />
        </ToolButton>

        <ButtonGroupSeparator />

        <ToolButton
          label="Image"
          hint="![ ]( )"
          onClick={() => applyUrlBlock("![](", ")")}
        >
          <ImageIcon className="size-4" />
        </ToolButton>
        <ToolButton
          label="Video or embed"
          hint="@"
          onClick={() => applyUrlBlock("@ ", "")}
        >
          <VideoIcon className="size-4" />
        </ToolButton>

        {nodeId !== undefined && (
          <ToolButton
            label={uploading ? "Attaching…" : "Attach a document or image"}
            hint="PDF, Word, image"
            onClick={() => fileRef.current?.click()}
          >
            {uploading ? (
              <Loader2Icon className="size-4 animate-spin" />
            ) : (
              <PaperclipIcon className="size-4" />
            )}
          </ToolButton>
        )}

        <ButtonGroupSeparator />

        <ToolButton label="Bold" hint="**" onClick={() => applyInline("**")}>
          <BoldIcon className="size-4" />
        </ToolButton>
        <ToolButton label="Italic" hint="*" onClick={() => applyInline("*")}>
          <ItalicIcon className="size-4" />
        </ToolButton>
        <ToolButton label="Link" hint="[ ]( )" onClick={applyLink}>
          <LinkIcon className="size-4" />
        </ToolButton>
      </ButtonGroup>

      <textarea
        ref={ref}
        id={id}
        name={name}
        rows={rows}
        defaultValue={defaultValue}
        spellCheck
        className="w-full rounded-lg border border-input bg-transparent px-3 py-2 font-mono text-sm leading-relaxed outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
      />

      {nodeId !== undefined && (
        <input
          ref={fileRef}
          type="file"
          className="hidden"
          accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp"
          onChange={onFileChosen}
          disabled={uploading}
        />
      )}

      {uploadError && (
        <p className="mt-2 text-sm text-red-700" role="alert">
          {uploadError}
        </p>
      )}

      {attached.length > 0 && (
        <div className="mt-2 rounded-lg border border-zinc-200 bg-zinc-50 p-3">
          <p className="text-sm text-zinc-700">
            Attached to this page. The {attached.length === 1 ? "marker" : "markers"}{" "}
            below {attached.length === 1 ? "is" : "are"} already in the body —
            move {attached.length === 1 ? "it" : "them"} where you want{" "}
            {attached.length === 1 ? "it" : "them"}, then save.
          </p>

          <ul className="mt-2 space-y-1">
            {attached.map((item) => (
              <li key={item.token} className="text-sm text-zinc-600">
                <code className="rounded bg-white px-1 py-0.5 font-mono text-xs text-zinc-800">
                  {item.token}
                </code>{" "}
                — {item.name}
              </li>
            ))}
          </ul>
        </div>
      )}

      {preserved.length > 0 && (
        <div className="mt-2 rounded-lg border border-zinc-200 bg-zinc-50 p-3">
          <p className="text-sm text-zinc-700">
            This body has {preserved.length === 1 ? "a part" : "parts"} the
            builder keeps exactly as {preserved.length === 1 ? "it is" : "they are"}.
            Move the {preserved.length === 1 ? "marker" : "markers"} to move{" "}
            {preserved.length === 1 ? "it" : "them"}, or delete{" "}
            {preserved.length === 1 ? "it" : "them"} to remove{" "}
            {preserved.length === 1 ? "it" : "them"}. To change what{" "}
            {preserved.length === 1 ? "is" : "they are"} inside, open the page in
            WordPress.
          </p>

          <ul className="mt-2 space-y-1">
            {preserved.map((region) => (
              <li key={region.token} className="text-sm text-zinc-600">
                <code className="rounded bg-white px-1 py-0.5 font-mono text-xs text-zinc-800">
                  {region.token}
                </code>{" "}
                — {region.label}
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
