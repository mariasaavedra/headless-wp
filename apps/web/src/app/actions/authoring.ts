"use server";

import { revalidatePath } from "next/cache";

import type { NodeType } from "@/lib/types";
import {
  createNode,
  deleteNode,
  reorderChildren,
  updateNode,
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

export {
  createNodeAction,
  createProgramAction,
  saveBodyAction,
  saveCreditsAction,
  renameNodeAction,
  setStatusAction,
  deleteNodeAction,
  reorderAction,
};
export type { BuilderActionState };
