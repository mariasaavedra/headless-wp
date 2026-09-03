"use server";

import { revalidatePath } from "next/cache";

import type {
  NodeType,
  QuizQuestion,
  QuizQuestionType,
  UploadedMedia,
} from "@/lib/types";
import {
  createNode,
  deleteNode,
  reorderChildren,
  updateNode,
  uploadNodeMedia,
  WordPressApiError,
} from "@/lib/wordpress";

type BuilderActionState = {
  error?: string;
};

const NODE_TYPES: NodeType[] = [
  "pcle_program",
  "pcle_unit",
  "pcle_module",
  "pcle_scenario",
  "pcle_quiz",
  "pcle_template",
  "pcle_event",
];

function readNodeType(value: FormDataEntryValue | null): NodeType | null {
  const candidate = String(value ?? "");
  return NODE_TYPES.includes(candidate as NodeType)
    ? (candidate as NodeType)
    : null;
}

function readId(value: FormDataEntryValue | null): number {
  const id = Number(value);
  return Number.isInteger(id) && id > 0 ? id : 0;
}

/**
 * Turns a refused write into something the author can act on.
 *
 * WordPress is the authority; these messages only translate its answer. A
 * failed save must never look like a successful one, which is why every
 * action returns the error rather than swallowing it.
 */
function describe(error: unknown): string {
  if (error instanceof WordPressApiError) {
    if (error.status === 401) {
      return "Your session expired. Sign in again and retry — nothing was saved.";
    }
    if (error.status === 403) {
      return "You do not have permission to change this programme.";
    }
    if (error.status === 409) {
      return "That item still contains others. Confirm the deletion to remove them too.";
    }
  }

  return "That change could not be saved. Please try again.";
}

/**
 * Everything the builder shows is derived from the tree, and the tree appears
 * on more than one route, so a mutation revalidates the layout rather than
 * trying to name every page it touched.
 */
function refresh() {
  revalidatePath("/builder", "layout");
}

async function createNodeAction(
  _prev: BuilderActionState,
  formData: FormData
): Promise<BuilderActionState> {
  const type = readNodeType(formData.get("type"));
  const parentId = readId(formData.get("parent_id"));
  const title = String(formData.get("title") ?? "").trim();

  if (!type || !parentId) {
    return { error: "That item could not be placed." };
  }

  try {
    await createNode({ type, parentId, title });
  } catch (error) {
    return { error: describe(error) };
  }

  refresh();
  return {};
}

async function renameNodeAction(
  _prev: BuilderActionState,
  formData: FormData
): Promise<BuilderActionState> {
  const id = readId(formData.get("id"));
  const title = String(formData.get("title") ?? "").trim();

  if (!id) {
    return { error: "That item could not be identified." };
  }
  if (!title) {
    return { error: "A title is required." };
  }

  try {
    await updateNode(id, { title });
  } catch (error) {
    return { error: describe(error) };
  }

  refresh();
  return {};
}

async function setStatusAction(
  _prev: BuilderActionState,
  formData: FormData
): Promise<BuilderActionState> {
  const id = readId(formData.get("id"));
  const status = String(formData.get("status") ?? "");

  if (!id || !["draft", "publish"].includes(status)) {
    return { error: "That change could not be applied." };
  }

  try {
    await updateNode(id, { status });
  } catch (error) {
    return { error: describe(error) };
  }

  refresh();
  return {};
}

/**
 * Saves a node's title and body.
 *
 * The body is authored text, never HTML: the server escapes it and builds the
 * markup. A client that could send markup would be a way around the fact that
 * instructors do not hold unfiltered_html.
 */
async function saveBodyAction(
  _prev: BuilderActionState,
  formData: FormData
): Promise<BuilderActionState> {
  const id = readId(formData.get("id"));
  const title = String(formData.get("title") ?? "").trim();
  const body = String(formData.get("body") ?? "");

  if (!id) {
    return { error: "That item could not be identified." };
  }
  if (!title) {
    return { error: "A title is required." };
  }

  try {
    await updateNode(id, { title, body });
  } catch (error) {
    return { error: describe(error) };
  }

  refresh();
  return {};
}

/**
 * Creates a programme.
 *
 * A programme has no parent, so this is the one creation that is not made in
 * context — it is where a course starts.
 */
async function createProgramAction(
  _prev: BuilderActionState,
  formData: FormData
): Promise<BuilderActionState> {
  const title = String(formData.get("title") ?? "").trim();

  if (!title) {
    return { error: "A title is required." };
  }

  try {
    await createNode({ type: "pcle_program", parentId: 0, title });
  } catch (error) {
    return { error: describe(error) };
  }

  refresh();
  return {};
}

/**
 * Sets the approved credit hours for a programme.
 *
 * Not summable across jurisdictions, and the API rounds to the quarter hour —
 * both decisions live on the server, so this only forwards what was typed.
 */
async function saveCreditsAction(
  _prev: BuilderActionState,
  formData: FormData
): Promise<BuilderActionState> {
  const id = readId(formData.get("id"));

  if (!id) {
    return { error: "That programme could not be identified." };
  }

  const credits: Record<string, number> = {};

  for (const [key, value] of formData.entries()) {
    if (!key.startsWith("credit_")) {
      continue;
    }

    const jurisdiction = key.slice("credit_".length);
    const hours = Number(value);

    credits[jurisdiction] = Number.isFinite(hours) && hours > 0 ? hours : 0;
  }

  try {
    await updateNode(id, { credits });
  } catch (error) {
    return { error: describe(error) };
  }

  refresh();
  return {};
}

async function deleteNodeAction(
  _prev: BuilderActionState,
  formData: FormData
): Promise<BuilderActionState> {
  const id = readId(formData.get("id"));

  if (!id) {
    return { error: "That item could not be identified." };
  }

  // The server refuses a cascade that was not asked for; the confirmation
  // screen is what turns this on, so a stray click cannot take a unit's
  // contents with it.
  const cascade = formData.get("cascade") === "true";

  try {
    await deleteNode(id, cascade);
  } catch (error) {
    return { error: describe(error) };
  }

  refresh();
  return {};
}

/**
 * Moves one item within its siblings.
 *
 * The form carries the already-computed resulting order, so this is one round
 * trip and the endpoint still receives the whole list it expects. Nothing here
 * has to re-derive what the page already knew.
 */
async function reorderAction(
  _prev: BuilderActionState,
  formData: FormData
): Promise<BuilderActionState> {
  const parentId = readId(formData.get("parent_id"));
  const childType = readNodeType(formData.get("child_type"));
  const ids = String(formData.get("ids") ?? "")
    .split(",")
    .map((value) => Number(value))
    .filter((value) => Number.isInteger(value) && value > 0);

  if (!parentId || !childType || ids.length === 0) {
    return { error: "That order could not be applied." };
  }

  try {
    await reorderChildren({ parentId, childType, ids });
  } catch (error) {
    return { error: describe(error) };
  }

  refresh();
  return {};
}


/* ------------------------------------------------------------------ */
/* Quizzes                                                             */
/* ------------------------------------------------------------------ */

const QUESTION_TYPES: QuizQuestionType[] = ["single", "multiple", "text"];

function readQuestionType(value: FormDataEntryValue | null): QuizQuestionType {
  const candidate = String(value ?? "");
  return QUESTION_TYPES.includes(candidate as QuizQuestionType)
    ? (candidate as QuizQuestionType)
    : "single";
}

/**
 * Reads the whole quiz back out of one form.
 *
 * The fields are flat and indexed — `q.0.prompt`, `q.0.c.1.text` — rather than
 * a serialised blob in a hidden input, so the form degrades to something a
 * browser can submit on its own and every value stays inspectable.
 *
 * Indices are positions in this render, not identities. The `key` travels in
 * its own field precisely so that reordering or removing a question does not
 * silently reassign somebody's recorded answer to a different question.
 *
 * Nothing is validated here beyond shape. Which choice may be correct, how
 * many of them, what counts as a usable question — those rules live in the
 * plugin's sanitiser, and duplicating them here would create a second place
 * for them to drift.
 */
function readQuestions(formData: FormData): QuizQuestion[] {
  const positions = new Set<number>();

  for (const name of formData.keys()) {
    const match = /^q\.(\d+)\./.exec(name);
    if (match) {
      positions.add(Number(match[1]));
    }
  }

  return [...positions]
    .sort((a, b) => a - b)
    .map((i) => {
      const choicePositions = new Set<number>();

      for (const name of formData.keys()) {
        const match = new RegExp(`^q\\.${i}\\.c\\.(\\d+)\\.`).exec(name);
        if (match) {
          choicePositions.add(Number(match[1]));
        }
      }

      return {
        key: String(formData.get(`q.${i}.key`) ?? ""),
        type: readQuestionType(formData.get(`q.${i}.type`)),
        prompt: String(formData.get(`q.${i}.prompt`) ?? ""),
        help: String(formData.get(`q.${i}.help`) ?? ""),
        feedback: String(formData.get(`q.${i}.feedback`) ?? ""),
        // An unchecked box submits nothing, which is exactly "false".
        required: formData.get(`q.${i}.required`) !== null,
        choices: [...choicePositions]
          .sort((a, b) => a - b)
          .map((j) => ({
            key: String(formData.get(`q.${i}.c.${j}.key`) ?? ""),
            text: String(formData.get(`q.${i}.c.${j}.text`) ?? ""),
            correct: formData.get(`q.${i}.c.${j}.correct`) !== null,
          })),
      };
    });
}

/**
 * Placeholder text for something just added.
 *
 * Not decoration. The server drops a question with no prompt and a choice with
 * no text — correctly, since neither is answerable — so a genuinely blank
 * addition would be discarded on the very save that created it, and the button
 * would appear to do nothing. Adding something has to produce something.
 *
 * The same answer the authoring API already gives for a node created without a
 * name, which it stores as "(untitled)" rather than refusing.
 */
const NEW_QUESTION_PROMPT = "New question";
const NEW_CHOICE_TEXT = "New answer";

function blankChoice() {
  return { key: "", text: NEW_CHOICE_TEXT, correct: false };
}

function blankQuestion(): QuizQuestion {
  return {
    key: "",
    type: "single",
    prompt: NEW_QUESTION_PROMPT,
    help: "",
    feedback: "",
    required: false,
    // Two, because one option is not a question and the server enforces that.
    choices: [blankChoice(), blankChoice()],
  };
}

/**
 * Saves a quiz, and applies the one structural change the author asked for.
 *
 * Adding a question, removing a choice and saving are the same round trip:
 * the form carries the entire quiz, the button says what to do to it, and the
 * result is written back. That is why the editor needs no client-side state —
 * and why an author who has JavaScript disabled, or has not yet loaded it,
 * can still build a quiz.
 *
 * The cost is that a structural change is also a save. That is the right way
 * round: the alternative is holding unsaved edits in the page while adding a
 * question throws them away.
 */
async function saveQuizAction(
  _prev: BuilderActionState,
  formData: FormData
): Promise<BuilderActionState> {
  const id = readId(formData.get("id"));

  if (!id) {
    return { error: "That quiz could not be identified." };
  }

  const questions = readQuestions(formData);
  const intent = String(formData.get("intent") ?? "save");
  const [verb, first, second] = intent.split(".");

  if (verb === "add_question") {
    questions.push(blankQuestion());
  }

  if (verb === "remove_question") {
    questions.splice(Number(first), 1);
  }

  if (verb === "add_choice" && questions[Number(first)]) {
    questions[Number(first)].choices.push(blankChoice());
  }

  if (verb === "remove_choice" && questions[Number(first)]) {
    questions[Number(first)].choices.splice(Number(second), 1);
  }

  const title = String(formData.get("title") ?? "").trim();

  if (!title) {
    return { error: "A title is required." };
  }

  try {
    await updateNode(id, {
      title,
      questions,
      pass_mark: Number(formData.get("pass_mark") ?? 70),
      gates_completion: formData.get("gates_completion") !== null,
    });
  } catch (error) {
    return { error: describe(error) };
  }

  refresh();
  return {};
}

/**
 * Attaches a file to a node and hands back the token for its body.
 *
 * Called imperatively rather than as a form action: the editor is already
 * inside the save form, and a nested submit would post the body too — saving a
 * half-written draft as a side effect of picking a file.
 *
 * Nothing is revalidated. The upload changes no rendered page until the author
 * puts the token in the body and saves, and refreshing the route here would
 * throw away whatever they have typed so far.
 */
async function uploadMediaAction(
  id: number,
  formData: FormData
): Promise<{ media?: UploadedMedia; error?: string }> {
  const file = formData.get("file");

  if (!(file instanceof File) || file.size === 0) {
    return { error: "Choose a file to attach." };
  }

  try {
    return { media: await uploadNodeMedia(id, file) };
  } catch (error) {
    return { error: describe(error) };
  }
}

export {
  createNodeAction,
  uploadMediaAction,
  saveQuizAction,
  createProgramAction,
  saveBodyAction,
  saveCreditsAction,
  renameNodeAction,
  setStatusAction,
  deleteNodeAction,
  reorderAction,
};
export type { BuilderActionState };
