"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";

import {
  changePassword,
  changeUsername,
  getAccount,
  login,
  logout,
  WordPressApiError,
} from "@/lib/wordpress";

type AccountActionState = {
  error?: string;
  /** What changed, said back to the person who changed it. */
  success?: string;
};

/**
 * The plugin's refusals, in the words the form shows.
 *
 * Keyed by code rather than read from the response: the client keeps the
 * code and status of a refusal but not its message, and these are the same
 * sentences the plugin uses.
 */
const REFUSALS: Record<string, string> = {
  pcle_wrong_password: "Your current password is not right.",
  pcle_username_length: "A username is 3 to 60 characters long.",
  pcle_username_characters:
    "A username may use letters, numbers, dots, dashes and underscores — nothing else.",
  pcle_username_taken: "That username is taken.",
  pcle_password_short: "A password is at least 12 characters.",
  pcle_password_unchanged: "That is your current password. Choose a new one.",
  pcle_password_guessable:
    "A password cannot be your username or email address.",
};

function describe(error: unknown, fallback: string): string {
  if (error instanceof WordPressApiError) {
    if (error.code && REFUSALS[error.code]) {
      return REFUSALS[error.code];
    }
    if (error.status === 401) {
      return "Your session expired. Sign in again and retry.";
    }
  }

  return fallback;
}

function field(formData: FormData, name: string): string {
  const value = formData.get(name);
  return typeof value === "string" ? value : "";
}

async function changeUsernameAction(
  _prev: AccountActionState,
  formData: FormData
): Promise<AccountActionState> {
  const username = field(formData, "username").trim();
  const currentPassword = field(formData, "current_password");

  if (!username || !currentPassword) {
    return { error: "Enter the new username and your current password." };
  }

  try {
    const account = await changeUsername(username, currentPassword);
    revalidatePath("/account");
    return { success: `Your username is now ${account.username}. Use it next time you sign in.` };
  } catch (error) {
    return { error: describe(error, "Your username could not be changed. Please try again.") };
  }
}

/**
 * Changes the password, then signs this session straight back in with it.
 *
 * The change ends every session, this one included — that is the point of
 * it — so without signing in again the person who just changed their password
 * would be thrown out for doing so.
 */
async function changePasswordAction(
  _prev: AccountActionState,
  formData: FormData
): Promise<AccountActionState> {
  const currentPassword = field(formData, "current_password");
  const newPassword = field(formData, "new_password");
  const confirmation = field(formData, "confirm_password");

  if (!currentPassword || !newPassword) {
    return { error: "Enter your current password and a new one." };
  }

  if (newPassword !== confirmation) {
    return { error: "The two new passwords do not match." };
  }

  let username: string;

  try {
    // Read before the change: the token asking for it stops working after.
    username = (await getAccount()).username;
    await changePassword(currentPassword, newPassword);
  } catch (error) {
    return { error: describe(error, "Your password could not be changed. Please try again.") };
  }

  try {
    await login(username, newPassword);
  } catch {
    // Changed, but this session is gone with the rest. Say so at the door.
    await logout();
    redirect("/login?changed=password");
  }

  return {
    success: "Your password is changed. Every other device you were signed in on has been signed out.",
  };
}

export { changeUsernameAction, changePasswordAction };
export type { AccountActionState };
